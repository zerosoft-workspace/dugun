<?php
// couple/pay_license.php — Lisans satın alma (PAYTR IFRAME)
require_once __DIR__.'/_auth.php';                     // tek URL giriş + aktif etkinlik
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/license.php';

// ---- Fiyat Planları (TL) ----
// İsterseniz settings tablosundan okuyacak şekilde uyarlayabiliriz.
$LICENSE_PLANS = [
  1 => 1000,   // 1 yıl
  2 => 1800,   // 2 yıl
  3 => 2500,   // 3 yıl
  4 => 3000,   // 4 yıl
  5 => 3500,   // 5 yıl
];

// ---- PAYTR Config ----
// Bunları config.php'ye eklemelisiniz:
//   define('PAYTR_MERCHANT_ID',  '...');
//   define('PAYTR_MERCHANT_KEY', '...');
//   define('PAYTR_MERCHANT_SALT','...');
// Opsiyonel:
//   define('PAYTR_TEST_MODE', 0);
$MERCHANT_ID  = defined('PAYTR_MERCHANT_ID')  ? PAYTR_MERCHANT_ID  : null;
$MERCHANT_KEY = defined('PAYTR_MERCHANT_KEY') ? PAYTR_MERCHANT_KEY : null;
$MERCHANT_SALT= defined('PAYTR_MERCHANT_SALT')? PAYTR_MERCHANT_SALT: null;
$TEST_MODE    = defined('PAYTR_TEST_MODE')    ? (int)PAYTR_TEST_MODE: 0;

if (!$MERCHANT_ID || !$MERCHANT_KEY || !$MERCHANT_SALT) {
  http_response_code(500);
  exit('PAYTR ayarları eksik. Lütfen config.php içine PAYTR_MERCHANT_ID / KEY / SALT ekleyin.');
}

// ---- Etkinlik / Müşteri Bilgileri ----
$EVENT_ID = couple_current_event_id();
$ev = couple_event_row_current();
if (!$ev) { http_response_code(404); exit('Etkinlik bulunamadı'); }
$VENUE_ID = (int)$ev['venue_id'];

// Kullanıcı (fatura/iletisim) bilgileri — boşsa emniyetli fallback verelim
$email   = trim($ev['contact_email'] ?? '');
if ($email === '') $email = 'misafir@example.com';

$user_name    = trim($ev['invoice_title'] ?? '');
if ($user_name === '') $user_name = $ev['title']; // örn: "Elif & Arda Düğünü"

$user_address = trim($ev['invoice_address'] ?? '');
if ($user_address === '') $user_address = 'Adres belirtilmedi';

$user_phone   = trim($ev['couple_phone'] ?? '');
if ($user_phone === '') $user_phone = '0000000000';

// ---- POST işlemi: years seçimi ----
$years = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_or_die();
  $years = (int)($_POST['years'] ?? 0);
}

// GET ile geldiyse (güvenlik için) izin verme; yalnızca POST'la ilerleyelim.
if ($years <= 0 || !isset($LICENSE_PLANS[$years])) {
  // Güzel bir seçim ekranı gösterelim
  ?>
  <!doctype html>
  <html lang="tr">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Lisans Satın Al — <?=h(APP_NAME)?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
      :root{ --zs:#0ea5b5; --zs-soft:#e0f7fb; }
      body{ min-height:100vh; background:linear-gradient(180deg,var(--zs-soft),#fff) }
      .cardx{ max-width:720px; margin:40px auto; background:#fff; border:1px solid #e9eef5; border-radius:18px; box-shadow:0 10px 30px rgba(2,6,23,.06) }
      .btn-zs{ background:var(--zs); color:#fff; border:none; border-radius:10px; padding:.6rem 1rem; font-weight:700 }
    </style>
  </head>
  <body>
    <div class="cardx p-4">
      <h5 class="mb-3">Lisans Satın Al</h5>
      <form method="post" class="row g-3">
        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
        <div class="col-md-6">
          <label class="form-label">Süre</label>
          <select class="form-select" name="years" required>
            <?php foreach($LICENSE_PLANS as $y=>$price): ?>
              <option value="<?=$y?>"><?=$y?> yıl — <?= (int)$price ?> TL</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 d-flex gap-2">
          <button class="btn btn-zs">Ödemeye Geç</button>
          <a class="btn btn-outline-secondary" href="<?=h(BASE_URL)?>/couple/index.php">Panele Dön</a>
        </div>
      </form>
    </div>
  </body>
  </html>
  <?php
  exit;
}

$price_tl = (int)$LICENSE_PLANS[$years];     // TL
$amount_kurus = $price_tl * 100;             // PAYTR -> kuruş

// ---- IP tespiti ----
if (isset($_SERVER['HTTP_CLIENT_IP']))         $user_ip = $_SERVER['HTTP_CLIENT_IP'];
elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) $user_ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
else                                            $user_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// ---- Sepet içeriği (PAYTR formatı) ----
$basket = [
  ["Lisans {$years} Yıl", number_format($price_tl, 2, '.', ''), 1]
];
$user_basket = base64_encode(json_encode($basket, JSON_UNESCAPED_UNICODE));

// ---- merchant_oid (sadece alfanümerik) ----
// Örn: LIC20251003H120512E5A1C
$merchant_oid = 'LIC'.date('YmdHis').strtoupper(substr(bin2hex(random_bytes(5)),0,6));
$merchant_oid = preg_replace('/[^A-Za-z0-9]/', '', $merchant_oid);

// ---- purchases kaydı (pending) ----
// campaign_id NULL, items_json'ta tip=license, years=x
$items = json_encode(['type'=>'license','years'=>$years,'price_tl'=>$price_tl], JSON_UNESCAPED_UNICODE);
$isTest = paytr_is_test_mode();
$now = now();
$ins = pdo()->prepare("
  INSERT INTO purchases
    (venue_id, event_id, campaign_id, status, amount, currency, paytr_oid, items_json, created_at, updated_at, paid_at)
  VALUES
    (?,?,?,?,?,?,?,?,?,?,?)
");
$ins->execute([
  $VENUE_ID,
  $EVENT_ID,
  null,
  $isTest ? 'paid' : 'pending',
  $amount_kurus,
  'TL',
  $merchant_oid,
  $items,
  $now,
  $isTest ? $now : null,
  $isTest ? $now : null,
]);

if ($isTest) {
  echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><title>Test Ödemesi</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="p-4">'
      .'<div class="alert alert-info"><strong>Test modu:</strong> Lisans ödemesi simüle edilerek kaydınız tamamlandı.</div>'
      .'<a class="btn btn-primary" href="'.h(BASE_URL).'/couple/index.php">Panele dön</a>'
      .'</body></html>';
  exit;
}

// ---- PAYTR token al ----
$merchant_ok_url   = BASE_URL.'/couple/index.php'; // Bilgilendirme amaçlı dönüş
$merchant_fail_url = BASE_URL.'/couple/index.php';

$timeout_limit   = "30";    // dakika
$debug_on        = 1;       // entegrasyon/testte 1; canlıda 0 yapabilirsiniz
$no_installment  = 0;
$max_installment = 0;
$currency        = "TL";

$hash_str = $MERCHANT_ID
          . $user_ip
          . $merchant_oid
          . $email
          . $amount_kurus
          . $user_basket
          . $no_installment
          . $max_installment
          . $currency
          . $TEST_MODE;

$paytr_token = base64_encode(hash_hmac('sha256', $hash_str.$MERCHANT_SALT, $MERCHANT_KEY, true));

$post_vals = [
  'merchant_id'     => $MERCHANT_ID,
  'user_ip'         => $user_ip,
  'merchant_oid'    => $merchant_oid,
  'email'           => $email,
  'payment_amount'  => $amount_kurus,
  'paytr_token'     => $paytr_token,
  'user_basket'     => $user_basket,
  'debug_on'        => $debug_on,
  'no_installment'  => $no_installment,
  'max_installment' => $max_installment,
  'user_name'       => $user_name,
  'user_address'    => $user_address,
  'user_phone'      => $user_phone,
  'merchant_ok_url' => $merchant_ok_url,
  'merchant_fail_url'=> $merchant_fail_url,
  'timeout_limit'   => $timeout_limit,
  'currency'        => $currency,
  'test_mode'       => $TEST_MODE
];

// CURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://www.paytr.com/odeme/api/get-token");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $post_vals);
curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
// Lokal geliştirmede SSL sorunları için (canlıda KAPALI kalsın!)
// curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

$result = @curl_exec($ch);
if (curl_errno($ch)) {
  $err = "PAYTR IFRAME connection error: ".curl_error($ch);
  curl_close($ch);
  // Hata -> purchases durumunu failed yapalım
  pdo()->prepare("UPDATE purchases SET status='failed', updated_at=? WHERE paytr_oid=?")->execute([now(), $merchant_oid]);
  exit($err);
}
curl_close($ch);

$res = json_decode($result, true);
if (!is_array($res) || ($res['status'] ?? '')!=='success') {
  $reason = $res['reason'] ?? 'Bilinmeyen hata';
  // Hata -> purchases durumunu failed yapalım
  pdo()->prepare("UPDATE purchases SET status='failed', updated_at=? WHERE paytr_oid=?")->execute([now(), $merchant_oid]);
  http_response_code(400);
  exit("PAYTR IFRAME failed. reason: ".htmlspecialchars($reason));
}

$token = $res['token']; // Başarılı

?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Lisans Ödemesi — <?=h(APP_NAME)?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://www.paytr.com/js/iframeResizer.min.js"></script>
  <style>
    :root{ --zs:#0ea5b5; --zs-soft:#e0f7fb; --ink:#111827; --muted:#6b7280; }
    body{ min-height:100vh; background:linear-gradient(180deg,var(--zs-soft),#fff) }
    .wrap{ max-width:940px; margin:32px auto; }
    .cardx{ background:#fff; border:1px solid #e9eef5; border-radius:18px; box-shadow:0 10px 30px rgba(2,6,23,.06) }
    .btn-zs{ background:var(--zs); color:#fff; border:none; border-radius:10px; padding:.6rem 1rem; font-weight:700 }
    .muted{ color:var(--muted) }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="cardx p-3 mb-3">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <h5 class="m-0">Lisans Ödemesi</h5>
          <div class="muted small">Etkinlik: <b><?=h($ev['title'])?></b></div>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="<?=h(BASE_URL)?>/couple/index.php">Panele Dön</a>
      </div>
    </div>

    <div class="cardx p-3">
      <div class="row g-3">
        <div class="col-lg-5">
          <div class="p-3 border rounded-3">
            <div class="fw-semibold mb-2">Sipariş Özeti</div>
            <div class="d-flex justify-content-between"><span>Süre</span><span><?=$years?> yıl</span></div>
            <div class="d-flex justify-content-between"><span>Tutar</span><span><?=number_format($price_tl,2,',','.')?> TL</span></div>
            <hr>
            <div class="small muted">
              Ödeme formu güvenli PAYTR altyapısı ile açılacaktır.
              Ödeme tamamlandığında lisans süreniz onay bildiriminden sonra uzatılacaktır.
            </div>
          </div>
        </div>
        <div class="col-lg-7">
          <iframe src="https://www.paytr.com/odeme/guvenli/<?=h($token)?>" id="paytriframe" frameborder="0" scrolling="no" style="width: 100%;"></iframe>
          <script>iFrameResize({},'#paytriframe');</script>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
