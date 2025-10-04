<?php
// couple/pay.php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';

$event_id = (int)($_GET['event'] ?? 0);
$key      = trim($_GET['key'] ?? '');
$st = pdo()->prepare("SELECT * FROM events WHERE id=? AND couple_panel_key=? AND is_active=1");
$st->execute([$event_id,$key]); 
$ev = $st->fetch();
if(!$ev){ http_response_code(403); exit('Erişim yok'); }
$VID=(int)$ev['venue_id'];

$cid = (int)($_POST['campaign_id'] ?? 0);
if ($cid<=0) { exit('Kampanya ID gelmedi (campaign_id).'); }
$cs = pdo()->prepare("SELECT * FROM campaigns WHERE id=? AND venue_id=? AND is_active=1");
$cs->execute([$cid,$VID]); 
$camp=$cs->fetch();
if(!$camp){ exit("Kampanya bulunamadı. (Salon=$VID, Kampanya ID=$cid)"); }

$tl = (int)$camp['price']; if ($tl < 1) exit('Fiyat 1 TL altında olamaz.');
$amount = $tl * 100;
$basket = [[ $camp['name'], number_format($tl,2,'.',''), 1 ]];
$user_basket = base64_encode(json_encode($basket, JSON_UNESCAPED_UNICODE));

$email       = filter_var($ev['contact_email'] ?? 'test@example.com', FILTER_VALIDATE_EMAIL) ?: 'test@example.com';
$user_name   = mb_substr($ev['title'], 0, 64, 'UTF-8');
$user_address= '—';
$user_phone  = '—';

$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '1.2.3.4';
if (strpos($ip, ',') !== false) { $ip = trim(explode(',', $ip)[0]); }
if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { $ip = '1.2.3.4'; }

$oid = 'EV'.$event_id.'C'.$cid.strtoupper(bin2hex(random_bytes(6)));
$oid = substr(preg_replace('/[^A-Za-z0-9]/','', $oid), 0, 64);

if (paytr_is_test_mode()) {
  $merchant_oid = 'TEST-'.$oid;
  $now = now();
  pdo()->prepare("INSERT INTO purchases (venue_id,event_id,campaign_id,status,amount,currency,paytr_oid,items_json,created_at,updated_at,paid_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([
        $VID,
        $event_id,
        $cid,
        'paid',
        $amount,
        'TL',
        $merchant_oid,
        null,
        $now,
        $now,
        $now,
      ]);
  echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><title>Test Ödemesi</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="p-4">'
      .'<div class="alert alert-info"><strong>Test modu:</strong> Ödeme simüle edildi ve kaydınız "paid" olarak işaretlendi.</div>'
      .'<a class="btn btn-primary" href="'.h(BASE_URL).'/couple/index.php">Panele dön</a>'
      .'</body></html>';
  exit;
}

$no_installment=0; $max_installment=0; $currency='TL'; $test=(int)PAYTR_TEST_MODE;
$hash_str = PAYTR_MERCHANT_ID . $ip . $oid . $email . $amount . $user_basket . $no_installment . $max_installment . $currency . $test;
$paytr_token = base64_encode(hash_hmac('sha256', $hash_str . PAYTR_MERCHANT_SALT, PAYTR_MERCHANT_KEY, true));

$post = [
  'merchant_id'=>PAYTR_MERCHANT_ID,'user_ip'=>$ip,'merchant_oid'=>$oid,'email'=>$email,
  'payment_amount'=>$amount,'paytr_token'=>$paytr_token,'user_basket'=>$user_basket,
  'no_installment'=>$no_installment,'max_installment'=>$max_installment,
  'user_name'=>$user_name,'user_address'=>$user_address,'user_phone'=>$user_phone,
  'merchant_ok_url'=>PAYTR_OK_URL,'merchant_fail_url'=>PAYTR_FAIL_URL,
  'timeout_limit'=>30,'currency'=>$currency,'test_mode'=>$test,
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
if (($data['status'] ?? '') !== 'success') { exit('PAYTR token hatası: '.($data['reason'] ?? 'bilinmiyor').' | Ayrıntı: '.$res); }
$token = $data['token'];

pdo()->prepare("INSERT INTO purchases (venue_id,event_id,campaign_id,status,amount,currency,paytr_oid,created_at)
VALUES (?,?,?,?,?,?,?,?)")->execute([$VID,$event_id,$cid,'pending',$amount,'TL',$oid,now()]);
?>
<!doctype html><html lang="tr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ödeme — <?=h($camp['name'])?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://www.paytr.com/js/iframeResizer.min.js"></script>
</head><body class="p-3">
<h5 class="mb-3"><?=h($camp['name'])?> — <?= (int)$camp['price'] ?> TL</h5>
<iframe src="https://www.paytr.com/odeme/guvenli/<?=h($token)?>" id="paytriframe" frameborder="0" scrolling="no" style="width:100%;"></iframe>
<script>iFrameResize({},'#paytriframe');</script>
</body></html>
