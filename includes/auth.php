<?php
/**
 * includes/auth.php
 * Admin oturum yardımcıları
 *
 * Kullanım:
 *   require_once __DIR__.'/auth.php';
 *   // Giriş (login.php)
 *   if (admin_login($_POST['email'], $_POST['password'])) { redirect('dashboard.php'); }
 *   // Korunan sayfa
 *   require_admin();  // giriş yoksa login'e atar
 *   $me = admin_user(); // ['id'=>..., 'email'=>..., 'name'=>...]
 */

require_once __DIR__.'/../config.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php'; // <-- CSRF fonksiyonları burada

install_schema(); // tabloları garanti et

/* ============ OTURUM ============ */

/** Oturum aç: e-posta + parola. true/false döner. */
function admin_login(string $email, string $password): bool {
  $email = trim($email);
  if ($email === '' || $password === '') return false;

  $st = pdo()->prepare("SELECT id, email, password_hash, name, role FROM users WHERE email = ? LIMIT 1");
  $st->execute([$email]);
  $u = $st->fetch();
  if (!$u) return false;

  if (!password_verify($password, $u['password_hash'])) return false;

  pdo()->prepare("UPDATE users SET last_login_at=?, updated_at=? WHERE id=?")
      ->execute([now(), now(), (int)$u['id']]);

  // Oturumu yaz
  $_SESSION['admin'] = [
    'id'    => (int)$u['id'],
    'email' => $u['email'],
    'name'  => $u['name'],
    'role'  => $u['role'] ?? 'admin',
    'since' => time(),
  ];
  return true;
}

function admin_send_password_reset(string $email): void {
  $email = trim($email);
  if ($email === '') {
    return;
  }

  $st = pdo()->prepare("SELECT id, email, name FROM users WHERE email=? LIMIT 1");
  $st->execute([$email]);
  $user = $st->fetch();
  if (!$user) {
    return;
  }

  $code = strtoupper(bin2hex(random_bytes(4)));
  $expires = date('Y-m-d H:i:s', time() + 3600);

  pdo()->prepare("UPDATE users SET reset_code=?, reset_expires=?, updated_at=? WHERE id=?")
      ->execute([$code, $expires, now(), (int)$user['id']]);

  $resetUrl = rtrim(BASE_URL, '/').'/admin/reset.php?code='.urlencode($code).'&email='.urlencode($user['email']);
  $html = '<h2>'.h(APP_NAME).' Yönetici Şifre Sıfırlama</h2>'
        . '<p>Merhaba '.h($user['name'] ?: $user['email']).',</p>'
        . '<p>Yeni bir şifre oluşturmak için aşağıdaki bağlantıyı kullanabilirsiniz.</p>'
        . '<p><a href="'.h($resetUrl).'">Şifremi sıfırla</a></p>'
        . '<p>Bağlantı 1 saat boyunca geçerlidir. Eğer bu işlemi siz başlatmadıysanız lütfen bu e-postayı yok sayın.</p>';

  send_mail_simple($user['email'], APP_NAME.' Yönetici Paneli Şifre Sıfırlama', $html);
}

function admin_reset_request_valid(string $email, string $code): bool {
  $email = trim($email);
  $code = trim($code);
  if ($email === '' || $code === '') {
    return false;
  }

  $st = pdo()->prepare("SELECT 1 FROM users WHERE email=? AND reset_code=? AND reset_expires IS NOT NULL AND reset_expires >= ? LIMIT 1");
  $st->execute([$email, $code, now()]);
  return (bool)$st->fetchColumn();
}

function admin_complete_password_reset(string $email, string $code, string $newPassword): bool {
  $email = trim($email);
  $code = trim($code);
  if ($email === '' || $code === '' || $newPassword === '') {
    return false;
  }

  $st = pdo()->prepare("SELECT id FROM users WHERE email=? AND reset_code=? AND reset_expires IS NOT NULL AND reset_expires >= ? LIMIT 1");
  $st->execute([$email, $code, now()]);
  $user = $st->fetch();
  if (!$user) {
    return false;
  }

  $hash = password_hash($newPassword, PASSWORD_DEFAULT);
  pdo()->prepare("UPDATE users SET password_hash=?, reset_code=NULL, reset_expires=NULL, updated_at=? WHERE id=?")
      ->execute([$hash, now(), (int)$user['id']]);

  return true;
}

/** Çıkış yap. */
function admin_logout(): void {
  unset($_SESSION['admin']);
}

/** Oturum açık mı? */
function is_admin_logged_in(): bool {
  return !empty($_SESSION['admin']['id']);
}

/** Mevcut admin bilgisi (yoksa null). */
function admin_user(): ?array {
  return is_admin_logged_in() ? $_SESSION['admin'] : null;
}

function is_superadmin(): bool {
  $u = admin_user();
  return $u && (($u['role'] ?? 'admin') === 'superadmin');
}

function require_superadmin(string $redirect = '/admin/dashboard.php'): void {
  if (!is_superadmin()) {
    flash('err', 'Bu alan için yetkiniz yok.');
    redirect($redirect);
  }
}

if (!function_exists('require_current_venue_or_redirect')) {
  /**
   * Aktif salon seçimini garanti eder.
   * Oturumda salon yoksa ilk aktif salonu otomatik seçer, hiç yoksa salon listesine yönlendirir.
   */
  function require_current_venue_or_redirect(string $redirect = '/admin/venues.php'): array {
    if (!empty($_SESSION['venue_id'])) {
      return [
        'id'   => (int)$_SESSION['venue_id'],
        'name' => $_SESSION['venue_name'] ?? 'Salon',
        'slug' => $_SESSION['venue_slug'] ?? '',
      ];
    }

    try {
      $st = pdo()->query("SELECT id, name, slug FROM venues WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
      $row = $st ? $st->fetch() : null;
    } catch (Throwable $e) {
      $row = null;
    }

    if ($row) {
      $_SESSION['venue_id']   = (int)$row['id'];
      $_SESSION['venue_name'] = $row['name'];
      $_SESSION['venue_slug'] = $row['slug'];

      return [
        'id'   => (int)$row['id'],
        'name' => $row['name'],
        'slug' => $row['slug'],
      ];
    }

    flash('err', 'Aktif salon bulunamadı. Lütfen bir salon ekleyin.');
    redirect($redirect);
  }
}

/**
 * Koruma: Giriş yoksa login sayfasına yollar.
 * Login URL'sini istersen değiştir.
 */
function require_admin(string $login_url = '/admin/login.php'): void {
  if (!is_admin_logged_in()) {
    $back = urlencode($_SERVER['REQUEST_URI'] ?? '/');
    redirect($login_url.'?next='.$back);
  }
}
// Geriye dönük uyumluluk: Eski kodlarda require_login() geçiyorsa destekle
if (!function_exists('require_login')) {
  function require_login(string $login_url = '/admin/login.php'): void {
    require_admin($login_url);
  }
}

/* ============ YARDIMCI: İlk admin oluşturma (opsiyonel) ============ */
/**
 * Eğer hiç kullanıcı yoksa hızlıca bir admin oluşturmak istersen:
 *   ensure_first_admin('admin@site.com', 'Sifre123', 'Yönetici');
 * (Bu fonksiyonu bir kere çağırıp sonra kaldırabilirsiniz.)
 */
function ensure_first_admin(string $email, string $password, string $name='Yönetici'): void {
  $cnt = (int)pdo()->query("SELECT COUNT(*) FROM users")->fetchColumn();
  if ($cnt === 0) {
    $st = pdo()->prepare("INSERT INTO users (email, password_hash, name, role, created_at, updated_at) VALUES (?,?,?,?,NOW(),NOW())");
    $st->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name, 'superadmin']);
  }
}
