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

function admin_available_permissions(): array {
  return [
    'dashboard' => [
      'label' => 'Genel Bakış',
      'path' => '/admin/dashboard.php',
      'description' => 'Panel özetlerini ve son hareketleri görüntüle',
    ],
    'campaigns' => [
      'label' => 'Kampanyalar',
      'path' => '/admin/campaigns.php',
      'description' => 'Kampanya içeriklerini düzenle ve yayınla',
    ],
    'venues' => [
      'label' => 'Salon Yönetimi',
      'path' => '/admin/venues.php',
      'description' => 'Salon kayıtlarını yönet',
    ],
    'users' => [
      'label' => 'Etkinlikler',
      'path' => '/admin/users.php',
      'description' => 'Etkinlikleri ve ödemeleri takip et',
    ],
    'dealers' => [
      'label' => 'Bayiler',
      'path' => '/admin/dealers.php',
      'description' => 'Bayi hesaplarını yönet',
    ],
    'listings' => [
      'label' => 'Anlaşmalı Şirketler',
      'path' => '/admin/listings.php',
      'description' => 'Şirket kayıtlarını düzenle',
    ],
    'representatives' => [
      'label' => 'Temsilciler',
      'path' => '/admin/representatives.php',
      'description' => 'Temsilci ekiplerini yönet',
    ],
    'crm' => [
      'label' => 'Temsilci CRM',
      'path' => '/admin/representative_crm.php',
      'description' => 'Temsilci iletişimlerini takip et',
    ],
    'finance' => [
      'label' => 'Finans',
      'path' => '/admin/finance.php',
      'description' => 'Gelir ve gider raporlarını incele',
    ],
    'analytics' => [
      'label' => 'Analizler',
      'path' => '/admin/representative_analytics.php',
      'description' => 'Performans analizlerini gör',
    ],
    'marketing' => [
      'label' => 'Pazarlama',
      'path' => '/admin/marketing_contacts.php',
      'description' => 'Pazarlama listelerini yönet',
    ],
    'packages' => [
      'label' => 'Bayi Paketleri',
      'path' => '/admin/dealer_packages.php',
      'description' => 'Bayi paketlerini yapılandır',
    ],
    'site' => [
      'label' => 'Site İçerikleri',
      'path' => '/admin/site_content.php',
      'description' => 'Genel site içeriklerini güncelle',
    ],
    'order_campaigns' => [
      'label' => 'Sosyal Sorumluluk Kampanyaları',
      'path' => '/admin/order_campaigns.php',
      'description' => 'Sipariş kampanyalarını yönet',
    ],
    'order_addons' => [
      'label' => 'Ek Hizmetler',
      'path' => '/admin/order_addons.php',
      'description' => 'Sipariş eklentilerini düzenle',
    ],
  ];
}

function admin_assignable_permissions(): array {
  return admin_available_permissions();
}

function admin_roles_all(bool $refresh = false): array {
  static $cache = null;
  if ($cache !== null && !$refresh) {
    return $cache;
  }

  $cache = [];
  try {
    if (!function_exists('table_exists') || !table_exists('admin_roles')) {
      return $cache;
    }
    $st = pdo()->query("SELECT id, name, slug, permissions_json, is_default, created_at, updated_at FROM admin_roles ORDER BY is_default DESC, name ASC");
    $perms = admin_available_permissions();
    while ($row = $st->fetch()) {
      $id = (int)$row['id'];
      $decoded = json_decode($row['permissions_json'] ?? '[]', true);
      if (!is_array($decoded)) {
        $decoded = [];
      }
      $normalized = [];
      foreach ($decoded as $slug) {
        $slug = (string)$slug;
        if (isset($perms[$slug])) {
          $normalized[] = $slug;
        }
      }
      $cache[$id] = [
        'id' => $id,
        'name' => $row['name'],
        'slug' => $row['slug'],
        'permissions' => $normalized,
        'permissions_json' => $row['permissions_json'],
        'is_default' => (int)$row['is_default'] === 1,
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
      ];
    }
  } catch (Throwable $e) {
    $cache = [];
  }

  return $cache;
}

function admin_default_role_id(bool $refresh = false): ?int {
  $roles = admin_roles_all($refresh);
  foreach ($roles as $role) {
    if (!empty($role['is_default'])) {
      return (int)$role['id'];
    }
  }
  return null;
}

function ensure_admin_roles_seeded(bool $force = false): void {
  static $ensured = false;
  if ($force) {
    $ensured = false;
  }
  if ($ensured) {
    return;
  }

  try {
    if (!function_exists('table_exists') || !table_exists('admin_roles')) {
      return;
    }

    $roles = admin_roles_all(true);
    if (!$roles) {
      $perms = array_keys(admin_assignable_permissions());
      $slug = 'tam-erisim';
      $insert = pdo()->prepare("INSERT INTO admin_roles (name, slug, permissions_json, is_default, created_at, updated_at) VALUES (?,?,?,?,?,?)");
      $insert->execute([
        'Tam Yetkili Admin',
        $slug,
        json_encode($perms, JSON_UNESCAPED_UNICODE),
        1,
        now(),
        now(),
      ]);
      $roles = admin_roles_all(true);
    }

    $defaultId = admin_default_role_id();
    if (!$defaultId) {
      $first = reset($roles);
      if ($first) {
        pdo()->prepare("UPDATE admin_roles SET is_default=1, updated_at=? WHERE id=?")
            ->execute([now(), (int)$first['id']]);
        $defaultId = (int)$first['id'];
        admin_roles_all(true);
      }
    }

    if ($defaultId) {
      pdo()->prepare("UPDATE users SET admin_role_id=? WHERE role='admin' AND (admin_role_id IS NULL OR admin_role_id=0)")
          ->execute([$defaultId]);
    }
  } catch (Throwable $e) {
    // yok say
  }

  $ensured = true;
}

function admin_permissions_for_role_id(?int $roleId): array {
  if (!$roleId) {
    return [];
  }
  $roles = admin_roles_all();
  return $roles[$roleId]['permissions'] ?? [];
}

function admin_refresh_session(bool $force = false): void {
  static $refreshed = false;
  if (!is_admin_logged_in()) {
    return;
  }
  if ($refreshed && !$force) {
    return;
  }

  try {
    ensure_admin_roles_seeded();
    $id = (int)($_SESSION['admin']['id'] ?? 0);
    if ($id <= 0) {
      return;
    }
    $st = pdo()->prepare("SELECT id, email, name, role, admin_role_id FROM users WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $user = $st->fetch();
    if (!$user) {
      admin_logout();
      return;
    }

    if ($user['role'] !== 'superadmin' && empty($user['admin_role_id'])) {
      $defaultId = admin_default_role_id();
      if ($defaultId) {
        pdo()->prepare("UPDATE users SET admin_role_id=?, updated_at=? WHERE id=?")
            ->execute([$defaultId, now(), $id]);
        $user['admin_role_id'] = $defaultId;
      }
    }

    $permissions = [];
    if ($user['role'] === 'superadmin') {
      $permissions = array_keys(admin_available_permissions());
    } else {
      $permissions = admin_permissions_for_role_id((int)$user['admin_role_id']);
    }

    $_SESSION['admin']['email'] = $user['email'];
    $_SESSION['admin']['name'] = $user['name'];
    $_SESSION['admin']['role'] = $user['role'];
    $_SESSION['admin']['role_id'] = $user['admin_role_id'] ? (int)$user['admin_role_id'] : null;
    $_SESSION['admin']['permissions'] = $permissions;
  } catch (Throwable $e) {
    // oturum güncellenemedi, sessizce devam et
  }

  $refreshed = true;
}

function admin_has_permission(string $permission): bool {
  if (!is_admin_logged_in()) {
    return false;
  }
  if (is_superadmin()) {
    return true;
  }
  admin_refresh_session();
  $perms = $_SESSION['admin']['permissions'] ?? [];
  return in_array($permission, $perms, true);
}

function require_admin_permission(string $permission, string $redirect = '/admin/dashboard.php'): void {
  require_admin();
  if (is_superadmin()) {
    return;
  }
  if (!admin_has_permission($permission)) {
    flash('err', 'Bu alan için yetkiniz yok.');
    redirect($redirect);
  }
}

/* ============ OTURUM ============ */

/** Oturum aç: e-posta + parola. true/false döner. */
function admin_login(string $email, string $password): bool {
  $email = trim($email);
  if ($email === '' || $password === '') return false;

  $st = pdo()->prepare("SELECT id, email, password_hash, name, role, admin_role_id FROM users WHERE email = ? LIMIT 1");
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
    'role_id' => !empty($u['admin_role_id']) ? (int)$u['admin_role_id'] : null,
    'permissions' => [],
    'since' => time(),
  ];
  ensure_admin_roles_seeded();
  admin_refresh_session(true);
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
  if (!is_admin_logged_in()) {
    return null;
  }
  admin_refresh_session();
  return $_SESSION['admin'];
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
  ensure_admin_roles_seeded();
  admin_refresh_session();
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
