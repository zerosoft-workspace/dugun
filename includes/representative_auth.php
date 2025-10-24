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
  if (password_needs_rehash($rep['password_hash'], PASSWORD_DEFAULT)) {
    try {
      $rehash = password_hash($password, PASSWORD_DEFAULT);
      pdo()->prepare('UPDATE dealer_representatives SET password_hash=?, updated_at=? WHERE id=?')
          ->execute([$rehash, now(), (int)$rep['id']]);
    } catch (Throwable $e) {
      // ignore rehash issues
    }
  }

  $_SESSION['representative'] = [
    'id' => (int)$rep['id'],
    'email' => $rep['email'],
    'name' => $rep['name'],
    'since' => time(),
    'force_reset' => (int)($rep['force_password_reset'] ?? 0),
  ];
  representative_record_login((int)$rep['id']);
  return true;
}

function representative_send_password_reset(string $email): void {
  $email = trim($email);
  if ($email === '') {
    return;
  }

  $st = pdo()->prepare("SELECT id, email, name FROM dealer_representatives WHERE email=? AND status=? LIMIT 1");
  $st->execute([$email, REPRESENTATIVE_STATUS_ACTIVE]);
  $rep = $st->fetch();
  if (!$rep) {
    return;
  }

  $code = strtoupper(bin2hex(random_bytes(4)));
  $expires = date('Y-m-d H:i:s', time() + 3600);

  pdo()->prepare("UPDATE dealer_representatives SET reset_code=?, reset_expires=?, updated_at=? WHERE id=?")
      ->execute([$code, $expires, now(), (int)$rep['id']]);

  $resetUrl = rtrim(BASE_URL, '/').'/representative/reset.php?code='.urlencode($code).'&email='.urlencode($rep['email']);
  $html = '<h2>'.h(APP_NAME).' Temsilci Şifre Sıfırlama</h2>'
        . '<p>Merhaba '.h($rep['name'] ?: $rep['email']).',</p>'
        . '<p>Temsilci paneli şifrenizi yenilemek için aşağıdaki bağlantıyı kullanabilirsiniz.</p>'
        . '<p><a href="'.h($resetUrl).'">Şifremi sıfırla</a></p>'
        . '<p>Bağlantı 1 saat boyunca geçerlidir. Eğer bu işlemi siz başlatmadıysanız lütfen bu mesajı önemsemeyin.</p>';

  send_mail_simple($rep['email'], APP_NAME.' Temsilci Paneli Şifre Sıfırlama', $html);
}

function representative_reset_request_valid(string $email, string $code): bool {
  $email = trim($email);
  $code = trim($code);
  if ($email === '' || $code === '') {
    return false;
  }

  $st = pdo()->prepare("SELECT 1 FROM dealer_representatives WHERE email=? AND reset_code=? AND reset_expires IS NOT NULL AND reset_expires >= ? LIMIT 1");
  $st->execute([$email, $code, now()]);
  return (bool)$st->fetchColumn();
}

function representative_complete_password_reset(string $email, string $code, string $newPassword): bool {
  $email = trim($email);
  $code = trim($code);
  if ($email === '' || $code === '' || $newPassword === '') {
    return false;
  }

  $st = pdo()->prepare("SELECT id FROM dealer_representatives WHERE email=? AND reset_code=? AND reset_expires IS NOT NULL AND reset_expires >= ? LIMIT 1");
  $st->execute([$email, $code, now()]);
  $rep = $st->fetch();
  if (!$rep) {
    return false;
  }

  $hash = password_hash($newPassword, PASSWORD_DEFAULT);
  $now = now();
  pdo()->prepare("UPDATE dealer_representatives SET password_hash=?, reset_code=NULL, reset_expires=NULL, updated_at=? WHERE id=?")
      ->execute([$hash, $now, (int)$rep['id']]);
  representative_mark_password_changed((int)$rep['id']);

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
  representative_require_password_change_if_needed();
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
    'force_reset' => (int)($rep['force_password_reset'] ?? 0),
  ];
}

function representative_session_requires_password_change(): bool {
  if (!representative_user()) {
    return false;
  }
  if (!isset($_SESSION['representative']['force_reset'])) {
    $id = (int)($_SESSION['representative']['id'] ?? 0);
    if ($id > 0) {
      $st = pdo()->prepare('SELECT force_password_reset FROM dealer_representatives WHERE id=? LIMIT 1');
      $st->execute([$id]);
      $_SESSION['representative']['force_reset'] = (int)$st->fetchColumn();
    } else {
      $_SESSION['representative']['force_reset'] = 0;
    }
  }
  return ((int)($_SESSION['representative']['force_reset'] ?? 0)) === 1;
}

function representative_require_password_change_if_needed(): void {
  if (!representative_session_requires_password_change()) {
    return;
  }
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  if (strpos($script, '/representative/password.php') !== false) {
    return;
  }
  redirect('password.php');
}
