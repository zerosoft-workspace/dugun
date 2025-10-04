<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$orderId = isset($_SESSION['current_order_id']) ? (int)$_SESSION['current_order_id'] : 0;
flash('err', 'Ödeme işlemi iptal edildi veya başarısız oldu. Dilerseniz yeniden deneyebilirsiniz.');

if ($orderId > 0) {
  redirect('order_paytr.php?order_id='.$orderId);
}

redirect('index.php#lead-form');
