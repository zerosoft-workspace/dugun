<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/guests.php';

$token = trim($_GET['token'] ?? '');
if ($token === '') {
  flash('err','Doğrulama bağlantısı geçersiz.');
  header('Location: '.BASE_URL);
  exit;
}

$profile = guest_profile_verify_token($token);
if (!$profile) {
  flash('err','Doğrulama bağlantısı geçersiz veya süresi dolmuş olabilir.');
  header('Location: '.BASE_URL);
  exit;
}

guest_profile_set_session((int)$profile['event_id'], (int)$profile['id']);
flash('ok','E-posta adresiniz doğrulandı! Misafir alanına hoş geldiniz.');
header('Location: '.public_upload_url((int)$profile['event_id']));
exit;
