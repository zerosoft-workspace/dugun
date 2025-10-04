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
