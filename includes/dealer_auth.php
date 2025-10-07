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

function dealer_send_password_reset(string $email): void {
  $email = trim($email);
  if ($email === '') {
    return;
  }

  $st = pdo()->prepare("SELECT id, email, name FROM dealers WHERE email=? AND status=? LIMIT 1");
  $st->execute([$email, DEALER_STATUS_ACTIVE]);
  $dealer = $st->fetch();
  if (!$dealer) {
    return;
  }

  $code = strtoupper(bin2hex(random_bytes(4)));
  $expires = date('Y-m-d H:i:s', time() + 3600);

  pdo()->prepare("UPDATE dealers SET reset_code=?, reset_expires=?, updated_at=? WHERE id=?")
      ->execute([$code, $expires, now(), (int)$dealer['id']]);

  $resetUrl = rtrim(BASE_URL, '/').'/dealer/reset.php?code='.urlencode($code).'&email='.urlencode($dealer['email']);
  $html = '<h2>'.h(APP_NAME).' Bayi Şifre Sıfırlama</h2>'
        . '<p>Merhaba '.h($dealer['name'] ?: $dealer['email']).',</p>'
        . '<p>Yeni bir bayi paneli şifresi belirlemek için aşağıdaki bağlantıya tıklayın.</p>'
        . '<p><a href="'.h($resetUrl).'">Şifremi sıfırla</a></p>'
        . '<p>Bağlantı 1 saat boyunca geçerlidir. Eğer bu isteği siz yapmadıysanız lütfen dikkate almayın.</p>';

  send_mail_simple($dealer['email'], APP_NAME.' Bayi Paneli Şifre Sıfırlama', $html);
}

function dealer_reset_request_valid(string $email, string $code): bool {
  $email = trim($email);
  $code = trim($code);
  if ($email === '' || $code === '') {
    return false;
  }

  $st = pdo()->prepare("SELECT 1 FROM dealers WHERE email=? AND reset_code=? AND reset_expires IS NOT NULL AND reset_expires >= ? LIMIT 1");
  $st->execute([$email, $code, now()]);
  return (bool)$st->fetchColumn();
}

function dealer_complete_password_reset(string $email, string $code, string $newPassword): bool {
  $email = trim($email);
  $code = trim($code);
  if ($email === '' || $code === '' || $newPassword === '') {
    return false;
  }

  $st = pdo()->prepare("SELECT id FROM dealers WHERE email=? AND reset_code=? AND reset_expires IS NOT NULL AND reset_expires >= ? LIMIT 1");
  $st->execute([$email, $code, now()]);
  $dealer = $st->fetch();
  if (!$dealer) {
    return false;
  }

  $hash = password_hash($newPassword, PASSWORD_DEFAULT);
  pdo()->prepare("UPDATE dealers SET password_hash=?, reset_code=NULL, reset_expires=NULL, updated_at=? WHERE id=?")
      ->execute([$hash, now(), (int)$dealer['id']]);

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
  $status = dealer_event_creation_status($dealer);
  return $status['allowed'];
}
