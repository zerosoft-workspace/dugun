<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/functions.php';
require_once __DIR__.'/includes/site.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

install_schema();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  redirect('index.php');
}

if (!csrf_check()) {
  flash('err', 'Oturum doğrulaması başarısız oldu. Lütfen formu yeniden gönderin.');
  $_SESSION['lead_form'] = $_POST;
  redirect('index.php#lead-form');
}

$formData = [
  'package_id' => (int)($_POST['package_id'] ?? 0),
  'customer_name' => trim($_POST['customer_name'] ?? ''),
  'customer_email' => trim($_POST['customer_email'] ?? ''),
  'customer_phone' => trim($_POST['customer_phone'] ?? ''),
  'event_title' => trim($_POST['event_title'] ?? ''),
  'event_date' => trim($_POST['event_date'] ?? ''),
  'referral_code' => trim($_POST['referral_code'] ?? ''),
  'notes' => trim($_POST['notes'] ?? ''),
];
$_SESSION['lead_form'] = $formData;

try {
  $result = site_process_customer_order($formData);
} catch (Throwable $e) {
  flash('err', $e->getMessage());
  redirect('index.php#lead-form');
}

unset($_SESSION['lead_form']);

$event = $result['event'];
$package = $result['package'];
$dealer = $result['dealer'];
$customer = $result['customer'];
$cashbackCents = (int)$result['cashback_cents'];

$uploadUrl = $event['upload_url'];
$dynamicQrUrl = $event['qr_code'] ? BASE_URL.'/qr.php?code='.$event['qr_code'] : null;
$qrImage = $dynamicQrUrl ?: $uploadUrl;
$qrImageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=500x500&data='.rawurlencode($qrImage);
$loginUrl = BASE_URL.'/couple/login.php?event='.$event['id'];

$customerHtml = '<h2>'.h(APP_NAME).' — Etkinliğiniz Hazır</h2>'
  .'<p>Merhaba '.h($customer['name']).',</p>'
  .'<p>Etkinlik paneliniz oluşturuldu. Aşağıdaki bilgilerle giriş yapabilirsiniz:</p>'
  .'<ul>'
  .'<li><strong>Giriş adresi:</strong> <a href="'.h($loginUrl).'">'.h($loginUrl).'</a></li>'
  .'<li><strong>Kullanıcı adı:</strong> '.h($customer['email']).'</li>'
  .'<li><strong>Geçici şifre:</strong> '.h($event['plain_password']).'</li>'
  .'</ul>'
  .'<p>Misafir yükleme bağlantınız: <a href="'.h($uploadUrl).'">'.h($uploadUrl).'</a></p>'
  .'<p>QR kodunuzu çıktı almak için aşağıdaki görseli kullanabilirsiniz:</p>'
  .'<p><img src="'.h($qrImageUrl).'" alt="QR Kod" width="220" height="220"></p>'
  .($dynamicQrUrl ? '<p>Kalıcı yönlendirme: <a href="'.h($dynamicQrUrl).'">'.h($dynamicQrUrl).'</a></p>' : '')
  .'<p>Paketiniz: '.h($package['name']).' — '.h(format_currency((int)$package['price_cents'])).'</p>'
  .'<p>İyi eğlenceler dileriz!<br>'.h(APP_NAME).' Ekibi</p>';

send_mail_simple($customer['email'], 'Wedding Share etkinliğiniz hazır', $customerHtml);

if ($dealer) {
  $dealerHtml = '<h2>Yeni Web Satışı</h2>'
    .'<p><strong>Müşteri:</strong> '.h($customer['name']).'<br>'
    .'<strong>E-posta:</strong> '.h($customer['email']).'<br>'
    .($customer['phone'] !== '' ? '<strong>Telefon:</strong> '.h($customer['phone']).'<br>' : '')
    .'<strong>Paket:</strong> '.h($package['name']).' — '.h(format_currency((int)$package['price_cents'])).'</p>'
    .'<p><strong>Etkinlik paneli:</strong> <a href="'.h($loginUrl).'">'.h($loginUrl).'</a><br>'
    .'<strong>Misafir yükleme:</strong> <a href="'.h($uploadUrl).'">'.h($uploadUrl).'</a></p>'
    .($dynamicQrUrl ? '<p><strong>QR 301 adresi:</strong> <a href="'.h($dynamicQrUrl).'">'.h($dynamicQrUrl).'</a></p>' : '')
    .($cashbackCents > 0 ? '<p><strong>Cashback:</strong> '.h(format_currency($cashbackCents)).' hesabınıza tanımlandı.</p>' : '')
    .'<p>Referans satışınız için teşekkür ederiz.</p>';
  send_mail_simple($dealer['email'], 'Referans satışınız tamamlandı', $dealerHtml);
}

$_SESSION['lead_success'] = 'Talebiniz alındı! Giriş bilgileri '.$customer['email'].' adresine gönderildi.';
$_SESSION['order_summary'] = [
  'event_title' => $formData['event_title'] ?: $customer['name'].' Etkinliği',
  'upload_url' => $uploadUrl,
  'qr_dynamic' => $dynamicQrUrl,
  'qr_image' => $qrImageUrl,
];

redirect('order_thanks.php');
