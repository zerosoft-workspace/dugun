<?php
/**
 * includes/dealer_auth.php — Bayi paneli oturum yardımcıları
 */
require_once __DIR__.'/../config.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/dealers.php';

function dealer_login(string $email, string $password): bool {
  $email = trim($email);
  if ($email === '' || $password === '') return false;

  $st = pdo()->prepare("SELECT * FROM dealers WHERE email=? LIMIT 1");
  $st->execute([$email]);
  $dealer = $st->fetch();
  if (!$dealer) return false;
  if ($dealer['status'] !== DEALER_STATUS_ACTIVE) return false;
  if (empty($dealer['password_hash']) || !password_verify($password, $dealer['password_hash'])) {
    return false;
  }

  $_SESSION['dealer'] = [
    'id'    => (int)$dealer['id'],
    'email' => $dealer['email'],
    'name'  => $dealer['name'],
    'since' => time(),
  ];
  dealer_update_last_login((int)$dealer['id']);
  return true;
}

function dealer_logout(): void {
  unset($_SESSION['dealer']);
}

function dealer_user(): ?array {
  return !empty($_SESSION['dealer']) ? $_SESSION['dealer'] : null;
}

function dealer_require_login(string $login_url = '/dealer/login.php'): void {
  if (!dealer_user()) {
    $back = urlencode($_SERVER['REQUEST_URI'] ?? '/dealer/dashboard.php');
    redirect($login_url.'?next='.$back);
  }
}

function dealer_refresh_session(int $dealer_id): void {
  $dealer = dealer_get($dealer_id);
  if (!$dealer) {
    dealer_logout();
    return;
  }
  $_SESSION['dealer'] = [
    'id'    => (int)$dealer['id'],
    'email' => $dealer['email'],
    'name'  => $dealer['name'],
    'since' => time(),
  ];
}

function dealer_can_manage_events(array $dealer): bool {
  if ($dealer['status'] !== DEALER_STATUS_ACTIVE) return false;
  return dealer_has_valid_license($dealer);
}
