<?php
// includes/couple_auth.php — Çift oturum yardımcıları (tek URL login destekli)
require_once __DIR__.'/../config.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';

/** Eski: event-bazlı oturum anahtarı (backward compatibility) */
function couple_session_key(int $event_id): string {
  return 'couple_'.$event_id;
}

/** Yeni: global çift oturumu (email doğrulandıktan sonra açılır) */
function couple_global_key(): string { return 'couple_global'; }
/** Aktif düğün (event) oturumu anahtarı */
function couple_current_event_key(): string { return 'couple_current_event'; }

/* --------- Global Login (tek URL) --------- */
/**
 * E-posta + şifre ile giriş yapar.
 * Başarılıysa, bu e-posta ile eşleşen AKTİF tüm event’leri döner (array).
 * Aynı zamanda global session’ı açar: $_SESSION['couple_global'] = ['email'=>..., 'since'=>...]
 */
function couple_login_global(string $email, string $password): array {
  $email_l = mb_strtolower(trim($email));
  if ($email_l === '' || $password === '') return [];

  // Bu email'e ait aktif event’leri (şifre eşleşenleri) topla
  $st = pdo()->prepare("
    SELECT id, title, event_date, couple_username, couple_password_hash, is_active
      FROM events
     WHERE is_active=1
       AND LOWER(couple_username) = ?
     ORDER BY id DESC
  ");
  $st->execute([$email_l]);
  $rows = $st->fetchAll();

  $matches = [];
  foreach ($rows as $r) {
    if (password_verify($password, $r['couple_password_hash'] ?? '')) {
      $matches[] = [
        'id'         => (int)$r['id'],
        'title'      => $r['title'],
        'event_date' => $r['event_date'],
      ];
    }
  }

  if (!empty($matches)) {
    $_SESSION[couple_global_key()] = [
      'email' => $email_l,
      'since' => time(),
    ];
  }
  return $matches; // 0, 1 veya birden çok olabilir
}

/** Global oturum var mı? */
function couple_is_global_logged_in(): bool {
  return !empty($_SESSION[couple_global_key()]['email']);
}

/** Global kullanıcı bilgisi (email) */
function couple_global_user(): ?array {
  return $_SESSION[couple_global_key()] ?? null;
}

/** Global çıkış */
function couple_global_logout(): void {
  unset($_SESSION[couple_global_key()], $_SESSION[couple_current_event_key()]);
  // Eski event-bazlı oturumları da temizleyelim (ihtiyaten)
  foreach ($_SESSION as $k=>$v) {
    if (is_string($k) && str_starts_with($k, 'couple_')) unset($_SESSION[$k]);
  }
}

/* --------- Aktif Düğün (event) Seçimi --------- */
/** Aktif düğünü belirle (global login zorunlu) */
function couple_set_current_event(int $event_id): bool {
  if (!couple_is_global_logged_in()) return false;
  // Event gerçekten var ve aktif mi kontrol edelim:
  $st = pdo()->prepare("SELECT id FROM events WHERE id=? AND is_active=1 LIMIT 1");
  $st->execute([$event_id]);
  if (!$st->fetch()) return false;

  $_SESSION[couple_current_event_key()] = (int)$event_id;

  // Backward compatibility: eski event-bazlı session’ı da set edelim
  $_SESSION[couple_session_key($event_id)] = [
    'event_id'    => (int)$event_id,
    'username'    => ($_SESSION[couple_global_key()]['email'] ?? ''),
    'force_reset' => (int)pdo()->query("SELECT couple_force_reset FROM events WHERE id={$event_id}")->fetchColumn(),
    'since'       => time(),
  ];
  return true;
}

/** Aktif düğün id’si (yoksa 0) */
function couple_current_event_id(): int {
  return (int)($_SESSION[couple_current_event_key()] ?? 0);
}

/** Aktif düğün için force_reset gerekiyorsa password sayfasına yönlendir */
function couple_require_password_reset_if_needed_for_current(): void {
  $eid = couple_current_event_id();
  if ($eid <= 0) return;
  $st = pdo()->prepare("SELECT couple_force_reset FROM events WHERE id=?");
  $st->execute([$eid]);
  $fr = (int)($st->fetchColumn() ?? 0);
  if ($fr === 1) {
    $self = $_SERVER['SCRIPT_NAME'] ?? '';
    if (strpos($self, '/couple/password.php') === false) {
      redirect(BASE_URL.'/couple/password.php');
    }
  }
}

/* --------- Eski API (event parametreli) – geri uyumluluk --------- */
function couple_is_logged_in(int $event_id): bool {
  $k = couple_session_key($event_id);
  return !empty($_SESSION[$k]['event_id']) && (int)$_SESSION[$k]['event_id'] === $event_id;
}
function couple_user(int $event_id): ?array { return $_SESSION[couple_session_key($event_id)] ?? null; }
function couple_login(int $event_id, string $email, string $password): bool {
  // Eskiden tek event’e özel login vardı; yine destekleyelim:
  $st = pdo()->prepare("SELECT id, is_active, couple_username, couple_password_hash, couple_force_reset
                        FROM events WHERE id=? LIMIT 1");
  $st->execute([$event_id]);
  $ev = $st->fetch();
  if (!$ev || (int)$ev['is_active'] !== 1) return false;
  if (!hash_equals(mb_strtolower($ev['couple_username'] ?? ''), mb_strtolower($email))) return false;
  if (!password_verify($password, $ev['couple_password_hash'] ?? '')) return false;

  // Global’ı da aç
  $_SESSION[couple_global_key()] = [
    'email' => mb_strtolower($email),
    'since' => time(),
  ];
  // Aktif düğün set et
  couple_set_current_event((int)$event_id);
  return true;
}
function couple_require(int $event_id): void {
  if (!couple_is_logged_in($event_id)) {
    redirect(BASE_URL.'/couple/login.php');
  }
}

/* --------- Şifre güncelleme (aktif düğün için) --------- */
function couple_update_password_current(string $current_plain, string $new_plain): bool {
  $event_id = couple_current_event_id();
  if ($event_id <= 0) return false;
  $st = pdo()->prepare("SELECT couple_password_hash FROM events WHERE id=? LIMIT 1");
  $st->execute([$event_id]);
  $row = $st->fetch();
  if (!$row) return false;
  if (!password_verify($current_plain, $row['couple_password_hash'] ?? '')) return false;

  $newHash = password_hash($new_plain, PASSWORD_DEFAULT);
  $up = pdo()->prepare("UPDATE events SET couple_password_hash=?, couple_force_reset=0, updated_at=NOW() WHERE id=?");
  $up->execute([$newHash, $event_id]);

  // Eski event-bazlı session’daki force_reset'i indir
  if (isset($_SESSION[couple_session_key($event_id)])) {
    $_SESSION[couple_session_key($event_id)]['force_reset'] = 0;
  }
  return true;
}
