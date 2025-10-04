<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/guests.php';

install_schema();

$token = trim($_GET['token'] ?? '');
if ($token === '') {
  flash('err','Doğrulama bağlantısı geçersiz.');
  header('Location: '.BASE_URL);
  exit;
}

$result = guest_profile_verify_token($token);
if (!$result || empty($result['profile'])) {
  flash('err','Doğrulama bağlantısı geçersiz veya süresi dolmuş olabilir.');
  header('Location: '.BASE_URL);
  exit;
}

$profile = $result['profile'];
$eventId = (int)$profile['event_id'];
$eventStmt = pdo()->prepare('SELECT id, title FROM events WHERE id=? LIMIT 1');
$eventStmt->execute([$eventId]);
$event = $eventStmt->fetch();
if (!$event) {
  $event = ['id' => $eventId, 'title' => 'Etkinliğiniz'];
}

$shouldSendPanelMail = ($result['just_verified'] ?? false) || empty($profile['password_hash']);
if ($shouldSendPanelMail) {
  guest_profile_send_panel_access($profile, $event);
}

guest_profile_set_session($eventId, (int)$profile['id']);
$passwordToken = $result['password_token'] ?? ($profile['password_token'] ?? null);

if ($passwordToken) {
  flash('ok','E-posta adresiniz doğrulandı! Şifrenizi belirleyip misafir panelinize giriş yapabilirsiniz.');
  header('Location: '.BASE_URL.'/public/guest_password.php?token='.rawurlencode($passwordToken));
  exit;
}

flash('ok','E-posta adresiniz doğrulandı! Misafir alanına hoş geldiniz.');
header('Location: '.public_upload_url($eventId));
exit;
