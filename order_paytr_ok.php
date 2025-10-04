<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/functions.php';
require_once __DIR__.'/includes/site.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

install_schema();

$merchantOid = $_GET['merchant_oid'] ?? ($_SESSION['current_order_oid'] ?? null);
if (!$merchantOid) {
  flash('err', 'Ödeme işlemi doğrulanamadı.');
  redirect('index.php#lead-form');
}

try {
  $order = site_get_order_by_oid($merchantOid);
  if (!$order) {
    throw new RuntimeException('Sipariş kaydı bulunamadı.');
  }
  $result = site_finalize_order($order['id']);
  $_SESSION['lead_success'] = 'Ödemeniz başarıyla alındı! Giriş bilgileri '.h($result['customer']['email']).' adresine gönderildi.';
  $_SESSION['order_summary'] = [
    'event_title'    => $result['event']['title'],
    'upload_url'     => $result['event']['upload_url'],
    'qr_image'       => $result['event']['qr_image_url'],
    'login_url'      => $result['event']['login_url'],
    'plain_password' => $result['event']['plain_password'],
    'customer_email' => $result['customer']['email'],
  ];
  $_SESSION['current_order_id'] = $result['order']['id'];
  $_SESSION['current_order_oid'] = $merchantOid;
} catch (Throwable $e) {
  flash('err', $e->getMessage());
  redirect('index.php#lead-form');
}

redirect('order_thanks.php');
