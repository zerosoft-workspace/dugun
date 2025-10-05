<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/representatives.php';
require_once __DIR__.'/../includes/dealer_auth.php';

install_schema();

dealer_require_login();
$sessionDealer = dealer_user();
$dealerId = isset($sessionDealer['id']) ? (int)$sessionDealer['id'] : 0;
$representative = $dealerId > 0 ? representative_for_dealer($dealerId) : null;

if ($dealerId > 0) {
  dealer_refresh_session($dealerId);
}

if ($representative) {
  $message = sprintf(
    'Potansiyel müşteri yönetimi temsilciniz %s tarafından yürütülüyor. Güncel durumu öğrenmek için temsilcinizle iletişime geçebilirsiniz.',
    $representative['name']
  );
} else {
  $message = 'Potansiyel müşteri yönetimi bayi temsilcisi paneline taşındı. Temsilci ataması için yönetici ekibiyle iletişime geçebilirsiniz.';
}

flash('info', $message);
redirect('dashboard.php');
exit;
