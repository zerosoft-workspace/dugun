<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/guests.php';

install_schema();

$eventId = (int)($_GET['event'] ?? 0);
if ($eventId > 0) {
  guest_profile_clear_session($eventId);
  if (isset($_SESSION['guest_host_preview']) && is_array($_SESSION['guest_host_preview'])) {
    unset($_SESSION['guest_host_preview'][$eventId]);
  }
}

flash('ok', 'Misafir oturumundan çıkış yapıldı.');
redirect(BASE_URL.'/public/guest_login.php');
