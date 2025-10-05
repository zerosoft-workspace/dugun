<?php
/**
 * includes/representative_auth.php — Temsilci paneli oturum yardımcıları
 */
require_once __DIR__.'/../config.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/representatives.php';

function representative_login(string $email, string $password): bool {
  $email = trim($email);
  if ($email === '' || $password === '') {
    return false;
  }
  $rep = representative_find_by_email($email);
  if (!$rep) {
    return false;
  }
  if (($rep['status'] ?? REPRESENTATIVE_STATUS_ACTIVE) !== REPRESENTATIVE_STATUS_ACTIVE) {
    return false;
  }
  if (empty($rep['password_hash']) || !password_verify($password, $rep['password_hash'])) {
    return false;
  }
  $_SESSION['representative'] = [
    'id' => (int)$rep['id'],
    'email' => $rep['email'],
    'name' => $rep['name'],
    'since' => time(),
  ];
  representative_record_login((int)$rep['id']);
  return true;
}

function representative_logout(): void {
  unset($_SESSION['representative']);
}

function representative_user(): ?array {
  return !empty($_SESSION['representative']) ? $_SESSION['representative'] : null;
}

function representative_require_login(string $login_url = '/representative/login.php'): void {
  if (!representative_user()) {
    $back = urlencode($_SERVER['REQUEST_URI'] ?? '/representative/dashboard.php');
    redirect($login_url.'?next='.$back);
  }
}

function representative_refresh_session(int $representative_id): void {
  $rep = representative_get($representative_id);
  if (!$rep) {
    representative_logout();
    return;
  }
  $_SESSION['representative'] = [
    'id' => (int)$rep['id'],
    'email' => $rep['email'],
    'name' => $rep['name'],
    'since' => time(),
  ];
}
