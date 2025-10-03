<?php
// couple/pay_addons.php — Ek paket seçimi + PayTR token + iFrame
// DÜZELTMELER:
// - GET ve POST’tan event/key okur; yoksa çift oturumundan alır
// - Ödeme pasif etkinlikte de serbest (is_active şartı yok)
// - Çift oturumu zorunlu: _auth.php

require_once __DIR__.'/_auth.php';              // Çift oturumu
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';

install_schema();

$DEBUG = isset($_GET['debug']);

// 1) event & key'yi GET/POST/Session sırasıyla dene
$event_id = 0;
$key      = '';

if (isset($_GET['event']))     $event_id = (int)$_GET['event'];
if (isset($_POST['event_id'])) $event_id = (int)$_POST['event_id'];

if (isset($_GET['key']))  $key = trim($_GET['key']);
if (isset($_POST['key'])) $key = trim($_POST['key']);

// Çift oturumundan etkinliği çek
$ev_session = couple_event_row_current(); // _auth.php içinden: oturumdaki etkinlik
if (!$ev_session) { http_response_code(403); exit('Erişim yok.'); }

// Parametre yoksa oturumdakini kullan
if ($event_id <= 0) $event_id = (int)$ev_session['id'];
if ($key === '')    $key      = (string)($ev_session['couple_panel_key'] ?? '');

// 2) Erişim doğrulaması:
//    - Aynı etkinlik mi? Değilse açıkça key ile de doğrula.
if ((int)$ev_session['id'] !== $event_id) {
  $st = pdo()->prepare("SELECT * FROM events WHERE id=? AND couple_panel_key=? LIMIT 1");
  $st->execute([$event_id, $key]);
  $ev = $st->fetch();
  if (!$ev) { http_response_code(403); exit('Erişim yok veya parametre hatalı.'); }
} else {
  $ev = $ev_session; // oturumdaki etkinlik
}

// NOT: Ödeme, pasif etkinlikte de yapılabilsin diye is_active=1 şartı KALDIRILDI.
// Eğer yine de engellemek isterseniz, aşağıyı açın:
// if (empty($ev['is_active'])) { http_response_code(403); exit('Etkinlik pasif.'); }

$event_id = (int)$ev['id'];
$VID      = (int)$ev['venue_id'];

// Bu salondaki aktif kampanyalar (paketler)
$cs = pdo()->prepare("SELECT id,name,type,description,price FROM campaigns WHERE venue_id=? AND is_active=1 ORDER BY id DESC");
$cs->execute([$VID]);
$packages = $cs->fetchAll();

// FORM POST geldiyse ödeme adımına geçelim
$selectedIds = array_values(array_filter(array_map('intval', $_POST['addons'] ?? [])));
$step = $selectedIds ? 'pay' : 'select';

// ------------------------------------------------------------------
// STEP: SELECT — Paket seçimi (POST yoksa burası render edilir)
// ------------------------------------------------------------------
if ($step === 'select') {
  ?><!doctype html>
  <html lang="tr"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Ek Paket Satın Al</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
      :root{ --zs: <?=h($ev['theme_primary']?:'#0ea5b5')?>; }
      body{ background:linear-gradient(180deg,#f8fafc,#fff) }
      .btn-zs{ background:var(--zs); color:#fff; border:none; border-radius:12px; padding:.6rem 1rem; font-weight:600 }
      .card-lite{ border:1px solid #eef2f7; border-radius:18px; background:#fff; box-shadow:0 6px 20px rgba(17,24,39,.05) }
    </style>
  </head><body class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 class="m-0">Ek Paket Satın Al — <span class="text-muted"><?=h($ev['title'])?></span></h4>
      <a class="btn btn-outline-secondary" href="<?=h(BASE_URL)?>/couple/index.php">Panele Dön</a>
    </div>
    <div class="card-lite p-3">
    <?php if(!$packages): ?>
      <div class="alert alert-info m-0">Bu salona tanımlı aktif paket yok.</div>
    <?php else: ?>
      <form method="post" class="vstack gap-2">
        <input type="hidden" name="event_id" value="<?=$event_id?>">
        <input type="hidden" name="key" value="<?=h($key)?>">
        <?php foreach($packages as $p): ?>
          <label class="d-flex justify-content-between align-items-center border rounded p-2">
            <span>
              <strong><?=h($p['name'])?></strong>
              <span class="badge bg-light text-dark ms-2"><?=h($p['type'])?></span><br>
              <small class="text-muted"><?=h($p['description'])?></small>
            </span>
            <span class="d-flex align-items-center gap-3">
              <span class="fw-semibold"><?= (int)$p['price'] ?> TL</span>
              <input type="checkbox" class="form-check-input" name="addons[]" value="<?=$p['id']?>">
            </span>
          </label>
        <?php endforeach; ?>
        <div class="mt-2">
          <button class="btn btn-zs">Ödemeye Geç</button>
          <a class="btn btn-outline-secondary" href="<?=h(BASE_URL)?>/couple/index.php">Panele Dön</a>
        </div>
      </form>
    <?php endif; ?>
    </div>
  </body></html><?php
  exit;
}

// ------------------------------------------------------------------
// STEP: PAY — Seçilen paketler ile PayTR token al + iFrame göster
// ------------------------------------------------------------------

// Seçilen paketlerin doğrulanması (bu salon + aktif)
$qMarks = implode(',', array_fill(0, count($selectedIds), '?'));
$st = pdo()->prepare("SELECT id,name,price FROM campaigns WHERE venue_id=? AND is_active=1 AND id IN ($qMarks)");
$st->execute(array_merge([$VID], $selectedIds));
$items = $st->fetchAll();

if(!$items){
  exit("Seçili paket bulunamadı. (Salon=$VID, PaketID'ler: ".implode(',',$selectedIds).")");
}

// Sepet ve toplam (TL -> kuruş)
$basket = []; $totalKurus = 0;
foreach($items as $it){
  $tl = (int)$it['price'];
  if ($tl < 1) { exit('Fiyat 1 TL altında olamaz: PaketID='.$it['id']); }
  $basket[] = [ $it['name'], number_format($tl,2,'.',''), 1 ];
  $totalKurus += $tl * 100;
}
$user_basket = base64_encode(json_encode($basket, JSON_UNESCAPED_UNICODE));

// E-posta (eventten varsa onu kullan)
$email        = filter_var($ev['contact_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: 'test@example.com';
$user_name    = mb_substr($ev['title'], 0, 64, 'UTF-8');
$user_address = '—';
$user_phone   = '—';

// IP normalize (tek IPv4)
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '1.2.3.4';
if (strpos($ip, ',') !== false) { $ip = trim(explode(',', $ip)[0]); }
if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { $ip = '1.2.3.4'; }

// Alfanümerik OID (64 char sınırı)
$oid = 'EV'.$event_id.'PKT'.strtoupper(bin2hex(random_bytes(8)));
$oid = substr(preg_replace('/[^A-Za-z0-9]/','', $oid), 0, 64);

// PayTR parametreleri
$no_installment=0; $max_installment=0; $currency='TL'; $test=(int)PAYTR_TEST_MODE;

// HASH
$hash_str    = PAYTR_MERCHANT_ID . $ip . $oid . $email . $totalKurus . $user_basket . $no_installment . $max_installment . $currency . $test;
$paytr_token = base64_encode(hash_hmac('sha256', $hash_str . PAYTR_MERCHANT_SALT, PAYTR_MERCHANT_KEY, true));

if ($DEBUG) {
  header('Content-Type: text/plain; charset=utf-8');
  echo "DEBUG MODE\n";
  echo "merchant_oid: $oid\n";
  echo "payment_amount(kurus): $totalKurus\n";
  echo "user_ip: $ip\n";
  echo "user_basket(json): ".json_encode($basket, JSON_UNESCAPED_UNICODE)."\n";
  echo "user_basket(b64): $user_basket\n";
  echo "hash_str: $hash_str\n";
  echo "paytr_token: $paytr_token\n";
  echo "OK: ".PAYTR_OK_URL."\nFAIL: ".PAYTR_FAIL_URL."\n";
  exit;
}

// Token isteği
$post = [
  'merchant_id'       => PAYTR_MERCHANT_ID,
  'user_ip'           => $ip,
  'merchant_oid'      => $oid,
  'email'             => $email,
  'payment_amount'    => $totalKurus,
  'paytr_token'       => $paytr_token,
  'user_basket'       => $user_basket,
  'no_installment'    => $no_installment,
  'max_installment'   => $max_installment,
  'user_name'         => $user_name,
  'user_address'      => $user_address,
  'user_phone'        => $user_phone,
  'merchant_ok_url'   => PAYTR_OK_URL,
  'merchant_fail_url' => PAYTR_FAIL_URL,
  'timeout_limit'     => 30,
  'currency'          => $currency,
  'test_mode'         => $test,
];

$ch=curl_init();
curl_setopt_array($ch, [
  CURLOPT_URL=>"https://www.paytr.com/odeme/api/get-token",
  CURLOPT_RETURNTRANSFER=>1,
  CURLOPT_POST=>1,
  CURLOPT_POSTFIELDS=>$post,
  CURLOPT_TIMEOUT=>30,
  CURLOPT_SSL_VERIFYPEER=>1
]);
$res=@curl_exec($ch);
$curlErr = curl_errno($ch) ? curl_error($ch) : null;
curl_close($ch);

if ($curlErr) { exit('PAYTR bağlantı hatası: '.$curlErr); }

$data = json_decode($res, true);
if (!$data) { exit('PAYTR yanıtı çözümlenemedi: '.$res); }
if (($data['status'] ?? '') !== 'success') {
  exit('PAYTR token hatası: '.($data['reason'] ?? 'bilinmiyor').' | Ayrıntı: '.$res);
}
$token = $data['token'];

// purchases kaydı (çoklu)
pdo()->prepare("INSERT INTO purchases (venue_id,event_id,campaign_id,status,amount,currency,paytr_oid,items_json,created_at)
VALUES (?,?,?,?,?,?,?,?,NOW())")->execute([
  $VID, $event_id, null, 'pending', $totalKurus, 'TL', $oid, json_encode($items, JSON_UNESCAPED_UNICODE)
]);

// HTML: sepet özeti + iFrame
?><!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Ödeme — Ek Paketler</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://www.paytr.com/js/iframeResizer.min.js"></script>
  <style>
    :root{
      --zs: <?=h($ev['theme_primary'] ?: '#0ea5b5')?>;
      --ink:#0f172a; --muted:#64748b; --card:#fff; --soft:#f8fafc;
    }
    body{
      background:
        radial-gradient(1200px 600px at 10% -5%, rgba(14,165,181,.15), transparent 60%),
        radial-gradient(1000px 500px at 110% 10%, rgba(14,165,181,.10), transparent 50%),
        #f5f7fb;
      color:var(--ink);
    }
    .wrap{max-width:1100px; margin:auto; padding:28px 16px;}
    .brand{display:flex; align-items:center; gap:.75rem; font-weight:800; letter-spacing:.2px;}
    .chip{background:rgba(14,165,181,.12); color:var(--zs); font-weight:700; font-size:.8rem; padding:.35rem .6rem; border-radius:999px;}
    .title{ font-size:clamp(1.25rem,1.4rem + .5vw,1.7rem); font-weight:800; margin:0; }
    .lead-muted{ color:var(--muted); }
    .cardx{ background:var(--card); border:1px solid #e9eef5; border-radius:18px; box-shadow:0 10px 30px rgba(2,6,23,.05); }
    .summary-item{ display:flex; justify-content:space-between; align-items:center; gap:8px; }
    .price{ font-weight:800; color:var(--ink); }
    .btn-zs{ background:var(--zs); color:#fff; border:none; border-radius:12px; padding:.7rem 1.1rem; font-weight:700; }
    .btn-ghost{ border:1px solid #dbe2ea; border-radius:12px; font-weight:600; }
    .secure{ display:flex; gap:.6rem; flex-wrap:wrap; align-items:center; color:var(--muted); font-size:.85rem; }
    .iframe-wrap{ position:relative; overflow:hidden; border-radius:16px; border:1px solid #e6ecf3; background:#fff; }
    .skeleton{ position:absolute; inset:0; display:grid; place-items:center; background: linear-gradient(90deg,#f2f5f9 25%, #e8edf4 37%, #f2f5f9 63%); background-size: 400% 100%; animation: shimmer 1.2s infinite; }
    @keyframes shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }
    .badges img{ height:22px; opacity:.9 }
    .step{ display:flex; gap:10px; align-items:center; color:var(--muted); font-size:.9rem }
    .dot{ width:8px; height:8px; border-radius:50%; background:var(--zs); box-shadow:0 0 0 4px rgba(14,165,181,.12) }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="d-flex justify-content-between align-items-start mb-3">
      <div class="brand">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="11" stroke="var(--zs)" stroke-width="2"/><path d="M7 12h10M12 7v10" stroke="var(--zs)" stroke-width="2" stroke-linecap="round"/></svg>
        <span><?=h(APP_NAME)?></span>
        <span class="chip">Ödeme</span>
      </div>
      <a class="btn btn-ghost" href="<?=h(BASE_URL)?>/couple/index.php">Panele dön</a>
    </div>

    <div class="step mb-4">
      <span class="dot"></span>
      <span>Ek Paketler için güvenli ödeme — <strong><?=h($ev['title'])?></strong></span>
    </div>

    <div class="row g-4">
      <div class="col-lg-4">
        <div class="cardx p-3">
          <h6 class="mb-3">Sipariş Özeti</h6>
          <div class="vstack gap-2">
            <?php $sumTL=0; foreach($items as $it): $sumTL+=(int)$it['price']; ?>
              <div class="summary-item">
                <div>
                  <div class="fw-semibold"><?=h($it['name'])?></div>
                  <div class="small text-muted">Ek paket</div>
                </div>
                <div class="price"><?= (int)$it['price'] ?> TL</div>
              </div>
            <?php endforeach; ?>
            <hr class="my-2">
            <div class="summary-item">
              <div class="fw-bold">Toplam</div>
              <div class="price"><?= number_format($sumTL,0,'.','.') ?> TL</div>
            </div>
          </div>
          <div class="secure mt-3">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 3l7 4v5c0 5-3.5 9-7 9s-7-4-7-9V7l7-4z" stroke="var(--zs)" stroke-width="1.6"/><path d="M8.5 12l2.2 2.2L15.5 9.5" stroke="var(--zs)" stroke-width="1.8" stroke-linecap="round"/></svg>
            <span>256-bit SSL, 3D Secure ile korunur.</span>
          </div>
        </div>
      </div>

      <div class="col-lg-8">
        <div class="cardx p-0">
          <div class="p-3 border-bottom">
            <h6 class="m-0">Kart ile Ödeme</h6>
            <small class="lead-muted">Bilgiler PayTR üzerinden güvenle işlenir.</small>
          </div>
          <div class="p-3">
            <div class="iframe-wrap">
              <div class="skeleton" id="skeleton">
                <div class="text-center">
                  <div class="spinner-border text-primary" role="status" style="--bs-spinner-width:2.2rem; --bs-spinner-height:2.2rem"></div>
                  <div class="mt-2 lead-muted">Ödeme formu hazırlanıyor…</div>
                </div>
              </div>
              <iframe
                src="https://www.paytr.com/odeme/guvenli/<?=h($token)?>"
                id="paytriframe"
                frameborder="0" scrolling="no"
                style="width:100%; min-height:640px; border:0; border-radius:16px;">
              </iframe>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-3 badges">
              <div class="secure"><span>Kart destekleri:</span></div>
              <div class="d-flex gap-2 flex-wrap">
                <img src="https://upload.wikimedia.org/wikipedia/commons/0/04/Visa.svg" alt="Visa">
                <img src="https://upload.wikimedia.org/wikipedia/commons/2/2a/Mastercard-logo.svg" alt="Mastercard">
                <img src="https://upload.wikimedia.org/wikipedia/commons/3/30/Amex_logo.svg" alt="AmEx">
                <img src="https://upload.wikimedia.org/wikipedia/commons/6/6f/Troy-logo.svg" alt="TROY">
                <img src="https://upload.wikimedia.org/wikipedia/commons/4/49/3D_Secure_Logo.svg" alt="3D Secure">
              </div>
            </div>
          </div>
        </div>

        <div class="d-flex gap-2 mt-3">
          <a class="btn btn-ghost" href="<?=h(BASE_URL)?>/couple/index.php">Panele dön</a>
          <a class="btn btn-zs" href="<?=h(public_upload_url($event_id))?>" target="_blank">Misafir Sayfasını Gör</a>
        </div>
      </div>
    </div>
  </div>

  <script>
    const iframe = document.getElementById('paytriframe');
    const skel   = document.getElementById('skeleton');
    iframe.addEventListener('load', ()=>{ if(skel) skel.style.display='none'; });
    iFrameResize({}, '#paytriframe');
  </script>
</body>
</html>
