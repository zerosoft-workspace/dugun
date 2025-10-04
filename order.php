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
  $created = site_create_customer_order($formData);
  $order = $created['order'];
  $paytr = site_ensure_order_paytr_token($order['id']);
} catch (Throwable $e) {
  flash('err', $e->getMessage());
  redirect('index.php#lead-form');
}

$_SESSION['current_order_id'] = $paytr['order']['id'];
$_SESSION['current_order_oid'] = $paytr['merchant_oid'];

redirect('order_paytr.php?order_id='.(int)$paytr['order']['id']);
