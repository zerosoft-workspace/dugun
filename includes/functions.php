<?php
/**
 * includes/functions.php — Genel yardımcılar (tam sürüm)
 * Bu dosya config.php ve db.php ile uyumludur.
 */
require_once __DIR__.'/../config.php';
require_once __DIR__.'/db.php';

/* -------------------- Basit yardımcılar -------------------- */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function redirect($url, $code=302){ header("Location: $url", true, $code); exit; }
function now(){ return date('Y-m-d H:i:s'); }

function format_currency(int $cents, string $suffix = ' TL'): string {
  $value = $cents / 100;
  return number_format($value, 2, ',', '.').$suffix;
}

function paytr_is_test_mode(): bool {
  return defined('PAYTR_TEST_MODE') && (int)PAYTR_TEST_MODE === 1;
}

function money_to_cents(string $input): int {
  $clean = trim($input);
  if ($clean === '') return 0;
  $clean = str_replace(['₺', 'TL', 'tl'], '', $clean);
  $clean = trim($clean);
  if ($clean === '') return 0;
  $clean = str_replace(' ', '', $clean);
  $comma = strrpos($clean, ',');
  $dot   = strrpos($clean, '.');
  if ($comma !== false && $dot !== false) {
    if ($comma > $dot) {
      $clean = str_replace('.', '', $clean);
      $clean = str_replace(',', '.', $clean);
    } else {
      $clean = str_replace(',', '', $clean);
    }
  } elseif ($comma !== false) {
    $clean = str_replace('.', '', $clean);
    $clean = str_replace(',', '.', $clean);
  }
  if (!is_numeric($clean)) {
    return 0;
  }
  $value = (float)$clean;
  return (int)round($value * 100);
}

function slugify($s){
  $s = (string)$s;
  $s = trim($s);
  $s = mb_strtolower($s, 'UTF-8');
  $s = strtr($s, ['ş'=>'s','ı'=>'i','ç'=>'c','ö'=>'o','ü'=>'u','ğ'=>'g']);
  $s = preg_replace('~[^a-z0-9]+~u','-',$s);
  $s = trim($s,'-');
  return $s !== '' ? $s : bin2hex(random_bytes(4));
}

/* -------------------- Flash mesajları -------------------- */
function flash($key, $msg=null){
  if ($msg===null){
    $m = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $m;
  }
  $_SESSION['flash'][$key] = $msg;
}
function flash_box(){
  if ($m = flash('ok'))  echo '<div class="alert alert-success">'.h($m).'</div>';
  if ($m = flash('err')) echo '<div class="alert alert-danger">'.h($m).'</div>';
  if (!empty($_SESSION['flash']) && is_array($_SESSION['flash'])){
    foreach($_SESSION['flash'] as $k=>$v){
      if(!in_array($k,['ok','err'],true)){
        echo '<div class="alert alert-info">'.h($v).'</div>';
        unset($_SESSION['flash'][$k]);
      }
    }
  }
}

if (!function_exists('flash_messages')) {
  function flash_messages(): string {
    if (!function_exists('flash_box')) {
      return '';
    }

    ob_start();
    flash_box();
    return trim(ob_get_clean());
  }
}

/* -------------------- CSRF -------------------- */
function csrf_token(): string {
  if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
  }
  return $_SESSION['csrf'];
}
/** _csrf veya csrf alanını kabul eder. */
function csrf_check(): bool {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') return true;
  $sent = $_POST['_csrf'] ?? $_POST['csrf'] ?? '';
  $real = $_SESSION['csrf'] ?? '';
  return is_string($sent) && is_string($real) && hash_equals($real, $sent);
}
function csrf_or_die(): void {
  if (!csrf_check()) {
    http_response_code(400);
    exit('CSRF doğrulaması başarısız.');
  }
}

/* -------------------- Mail -------------------- */
function send_mail_simple(string $to, string $subject, string $html){
  $fromHost = parse_url(BASE_URL, PHP_URL_HOST) ?: 'localhost';
  $fromName = defined('MAIL_FROM_NAME') && MAIL_FROM_NAME ? MAIL_FROM_NAME : APP_NAME;
  $fromAddr = defined('MAIL_FROM') && MAIL_FROM ? MAIL_FROM : 'no-reply@'.$fromHost;
  require_once __DIR__.'/mailer.php';

  $overrides = mailer_settings_overrides();
  if (!empty($overrides['from_email'])) {
    $fromAddr = $overrides['from_email'];
  }
  if (!empty($overrides['from_name'])) {
    $fromName = $overrides['from_name'];
  }

  $sent = send_smtp_mail($to, $subject, $html, $fromAddr, $fromName);

  if ($sent) {
    return true;
  }

  $headers  = "MIME-Version: 1.0\r\n";
  $headers .= "Content-type:text/html;charset=UTF-8\r\n";
  $headers .= "From: ".$fromName." <".$fromAddr.">\r\n";

  return @mail($to, '=?UTF-8?B?'.base64_encode($subject).'?=', $html, $headers);
}

/* -------------------- Çift hesabı ve lisans -------------------- */
function couple_set_account(int $event_id, string $email, string $plain_pass){
  $hash = password_hash($plain_pass, PASSWORD_DEFAULT);
  pdo()->prepare("UPDATE events
                    SET couple_username=?,
                        couple_password_hash=?,
                        couple_force_reset=1,
                        contact_email=COALESCE(contact_email,?)
                  WHERE id=?")
      ->execute([$email, $hash, $email, $event_id]);

  $loginUrl = BASE_URL.'/couple/login.php?event='.$event_id;
  $html = '<h3>'.h(APP_NAME).'</h3>
           <p>Çift paneliniz hazır.</p>
           <p><b>Kullanıcı adı:</b> '.h($email).'<br><b>Şifre:</b> '.h($plain_pass).'</p>
           <p><a href="'.h($loginUrl).'">Panele giriş</a> — İlk girişte şifreyi değiştirmeniz istenecektir.</p>';
  send_mail_simple($email, 'Çift Panel Giriş Bilgileriniz', $html);
}

function license_extend_years(int $event_id, int $years){
  $years = max(1, min(5, $years));
  $st = pdo()->prepare("SELECT COALESCE(license_expires_at, NOW()) AS exp FROM events WHERE id=?");
  $st->execute([$event_id]); $row = $st->fetch();
  $base = new DateTime($row['exp'] ?? 'now');
  $now  = new DateTime('now');
  if ($base < $now) $base = $now;
  $base->modify("+{$years} years");
  pdo()->prepare("UPDATE events SET license_expires_at=? WHERE id=?")
      ->execute([$base->format('Y-m-d H:i:s'), $event_id]);
}

/* -------------------- Upload token (5 dk slot) -------------------- */
function current_slot(): int { return (int)floor(time()/300); }

function make_token(int $eventId, int $slot): string {
  return substr(hash_hmac('sha256', $eventId.'|'.$slot, SECRET_KEY), 0, 10);
}

/** Hem mevcut hem bir önceki slottaki token’ı kabul eder. */
function token_valid(int $eventId, string $token): bool {
  $s = current_slot();
  foreach([$s, $s-1] as $k){
    if (hash_equals(make_token($eventId, $k), $token)) return true;
  }
  return false;
}

/** Misafir yükleme URL’si (kısa süreli token içerir). */
function public_upload_url(int $eventId): string {
  $t = make_token($eventId, current_slot());
  return BASE_URL.'/public/upload.php?event='.$eventId.'&t='.$t;
}

/* -------------------- Dosya sistemi -------------------- */
function ensure_upload_dir(int $venueId, int $eventId): string {
  $root = __DIR__.'/../uploads';
  if (!is_dir($root)) @mkdir($root, 0775);
  $vdir = $root.'/v'.$venueId;
  if (!is_dir($vdir)) @mkdir($vdir, 0775);
  $edir = $vdir.'/'.$eventId;
  if (!is_dir($edir)) @mkdir($edir, 0775);
  return $edir;
}

/** Dikkat: Kullanmadan önce iki kez düşünün (kalıcı siler). */
function rrmdir(string $dir): void {
  if (!is_dir($dir)) return;
  $it = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
  $ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
  foreach($ri as $f){
    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
  }
  @rmdir($dir);
}

/* -------------------- JSON güvenli -------------------- */
function safe_json_encode($data): string {
  return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
function safe_json_decode(string $json, bool $assoc=true){
  $val = json_decode($json, $assoc);
  return (json_last_error() === JSON_ERROR_NONE) ? $val : null;
}
