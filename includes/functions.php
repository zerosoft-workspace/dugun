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
function table_supports_force_password_reset(string $table): bool {
  static $cache = [];
  if (array_key_exists($table, $cache)) {
    return $cache[$table];
  }
  if (!function_exists('column_exists')) {
    return $cache[$table] = false;
  }
  try {
    return $cache[$table] = column_exists($table, 'force_password_reset');
  } catch (Throwable $e) {
    return $cache[$table] = false;
  }
}
function format_currency(int $cents, string $suffix = ' TL'): string {
  $value = $cents / 100;
  return number_format($value, 2, ',', '.').$suffix;
}
function guest_font_options(): array {
  return [
    'inter' => [
      'label'  => 'Inter',
      'stack'  => "'Inter','Segoe UI','Helvetica Neue',sans-serif",
      'import' => 'Inter:wght@400;500;600;700',
    ],
    'poppins' => [
      'label'  => 'Poppins',
      'stack'  => "'Poppins','Segoe UI','Helvetica Neue',sans-serif",
      'import' => 'Poppins:wght@400;500;600;700',
    ],
    'montserrat' => [
      'label'  => 'Montserrat',
      'stack'  => "'Montserrat','Segoe UI',sans-serif",
      'import' => 'Montserrat:wght@500;600;700',
    ],
    'raleway' => [
      'label'  => 'Raleway',
      'stack'  => "'Raleway','Segoe UI',sans-serif",
      'import' => 'Raleway:wght@400;500;600;700',
    ],
    'quicksand' => [
      'label'  => 'Quicksand',
      'stack'  => "'Quicksand','Segoe UI',sans-serif",
      'import' => 'Quicksand:wght@500;600;700',
    ],
    'playfair' => [
      'label'  => 'Playfair Display',
      'stack'  => "'Playfair Display','Times New Roman',serif",
      'import' => 'Playfair+Display:wght@500;600;700',
    ],
    'abril' => [
      'label'  => 'Abril Fatface',
      'stack'  => "'Abril Fatface','Times New Roman',serif",
      'import' => 'Abril+Fatface',
    ],
    'lora' => [
      'label'  => 'Lora',
      'stack'  => "'Lora','Georgia',serif",
      'import' => 'Lora:wght@500;600;700',
    ],
    'cormorant' => [
      'label'  => 'Cormorant Garamond',
      'stack'  => "'Cormorant Garamond','Georgia',serif",
      'import' => 'Cormorant+Garamond:wght@500;600;700',
    ],
    'cinzel' => [
      'label'  => 'Cinzel',
      'stack'  => "'Cinzel','Times New Roman',serif",
      'import' => 'Cinzel:wght@500;600;700',
    ],
    'dancing' => [
      'label'  => 'Dancing Script',
      'stack'  => "'Dancing Script','Brush Script MT',cursive",
      'import' => 'Dancing+Script:wght@500;600;700',
    ],
    'greatvibes' => [
      'label'  => 'Great Vibes',
      'stack'  => "'Great Vibes','Brush Script MT',cursive",
      'import' => 'Great+Vibes',
    ],
    'satisfy' => [
      'label'  => 'Satisfy',
      'stack'  => "'Satisfy','Brush Script MT',cursive",
      'import' => 'Satisfy',
    ],
  ];
}
function guest_font_stack(string $key): string {
  $options = guest_font_options();
  return $options[$key]['stack'] ?? $options['inter']['stack'];
}
function guest_font_imports(array $keys): array {
  $options = guest_font_options();
  $imports = [];
  foreach ($keys as $key) {
    if (!isset($options[$key])) {
      continue;
    }
    $import = (string)($options[$key]['import'] ?? '');
    if ($import !== '') {
      $imports[$import] = true;
    }
  }
  return array_keys($imports);
}
function site_payment_config(bool $refresh = false): array {
  static $cache = null;
  if ($cache !== null && !$refresh) {
    return $cache;
  }
  $config = [
    'enabled'      => false,
    'merchant_id'  => defined('PAYTR_MERCHANT_ID') ? (string)PAYTR_MERCHANT_ID : '',
    'merchant_key' => defined('PAYTR_MERCHANT_KEY') ? (string)PAYTR_MERCHANT_KEY : '',
    'merchant_salt'=> defined('PAYTR_MERCHANT_SALT') ? (string)PAYTR_MERCHANT_SALT : '',
    'test_mode'    => defined('PAYTR_TEST_MODE') ? (int)PAYTR_TEST_MODE : 1,
  ];
  $settings = [];
  try {
    if (function_exists('pdo') && table_exists('site_settings')) {
      $keys = ['paytr_enabled', 'paytr_merchant_id', 'paytr_merchant_key', 'paytr_merchant_salt', 'paytr_test_mode'];
      $placeholders = implode(',', array_fill(0, count($keys), '?'));
      $st = pdo()->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($placeholders)");
      $st->execute($keys);
      while ($row = $st->fetch()) {
        $settings[$row['setting_key']] = (string)$row['setting_value'];
      }
    }
  } catch (Throwable $e) {
    // Veritabanı erişimi başarısızsa ortam değişkenleri kullanılmaya devam eder.
  }
  if (!empty($settings['paytr_merchant_id'])) {
    $config['merchant_id'] = trim($settings['paytr_merchant_id']);
  }
  if (!empty($settings['paytr_merchant_key'])) {
    $config['merchant_key'] = trim($settings['paytr_merchant_key']);
  }
  if (!empty($settings['paytr_merchant_salt'])) {
    $config['merchant_salt'] = trim($settings['paytr_merchant_salt']);
  }
  if (array_key_exists('paytr_test_mode', $settings) && $settings['paytr_test_mode'] !== '') {
    $config['test_mode'] = (int)$settings['paytr_test_mode'] === 1 ? 1 : 0;
  }
  if (array_key_exists('paytr_enabled', $settings) && $settings['paytr_enabled'] !== '') {
    $config['enabled'] = (int)$settings['paytr_enabled'] === 1;
  } else {
    $config['enabled'] = ($config['merchant_id'] !== '' && $config['merchant_key'] !== '' && $config['merchant_salt'] !== '');
  }
  return $cache = $config;
}
function site_whatsapp_config(bool $refresh = false): array {
  static $cache = null;
  if ($cache !== null && !$refresh) {
    return $cache;
  }
  $config = [
    'enabled'  => false,
    'api_url'  => defined('WHATSAPP_API_URL') ? (string)WHATSAPP_API_URL : '',
    'api_token'=> defined('WHATSAPP_API_TOKEN') ? (string)WHATSAPP_API_TOKEN : '',
    'sender'   => defined('WHATSAPP_API_SENDER') ? (string)WHATSAPP_API_SENDER : '',
  ];
  $settings = [];
  try {
    if (function_exists('pdo') && table_exists('site_settings')) {
      $keys = ['whatsapp_api_enabled', 'whatsapp_api_url', 'whatsapp_api_token', 'whatsapp_api_sender'];
      $placeholders = implode(',', array_fill(0, count($keys), '?'));
      $st = pdo()->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($placeholders)");
      $st->execute($keys);
      while ($row = $st->fetch()) {
        $settings[$row['setting_key']] = (string)$row['setting_value'];
      }
    }
  } catch (Throwable $e) {
    // Varsayılan çevresel değerlerle devam edilir.
  }
  if (array_key_exists('whatsapp_api_url', $settings) && $settings['whatsapp_api_url'] !== '') {
    $config['api_url'] = trim($settings['whatsapp_api_url']);
  }
  if (array_key_exists('whatsapp_api_token', $settings) && $settings['whatsapp_api_token'] !== '') {
    $config['api_token'] = trim($settings['whatsapp_api_token']);
  }
  if (array_key_exists('whatsapp_api_sender', $settings)) {
    $config['sender'] = trim($settings['whatsapp_api_sender']);
  }
  if (array_key_exists('whatsapp_api_enabled', $settings) && $settings['whatsapp_api_enabled'] !== '') {
    $config['enabled'] = (int)$settings['whatsapp_api_enabled'] === 1;
  } else {
    $config['enabled'] = ($config['api_url'] !== '' && $config['api_token'] !== '');
  }
  return $cache = $config;
}
function whatsapp_is_enabled(): bool {
  $config = site_whatsapp_config();
  if (empty($config['enabled'])) {
    return false;
  }
  return trim((string)$config['api_url']) !== '' && trim((string)$config['api_token']) !== '';
}
function paytr_credentials(): array {
  $config = site_payment_config();
  return [
    'merchant_id'   => $config['merchant_id'],
    'merchant_key'  => $config['merchant_key'],
    'merchant_salt' => $config['merchant_salt'],
  ];
}
function paytr_is_enabled(): bool {
  $config = site_payment_config();
  return !empty($config['enabled']);
}
function paytr_is_test_mode(): bool {
  $config = site_payment_config();
  return (int)$config['test_mode'] === 1;
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
/* -------------------- Etkinlik paneli hesabı ve lisans -------------------- */
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
           <p>Etkinlik paneliniz hazır.</p>
           <p><b>Kullanıcı adı:</b> '.h($email).'<br><b>Şifre:</b> '.h($plain_pass).'</p>
           <p><a href="'.h($loginUrl).'">Panele giriş</a> — İlk girişte şifreyi değiştirmeniz istenecektir.</p>';
  send_mail_simple($email, 'Etkinlik Paneli Giriş Bilgileriniz', $html);
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
function public_invitation_url(string $token): string {
  $token = trim($token);
  return BASE_URL.'/public/invite.php?code='.rawurlencode($token);
}
function public_invitation_card_url(string $token): string {
  $token = trim($token);
  return BASE_URL.'/public/invite_card.php?code='.rawurlencode($token);
}
function public_invitation_card_share_url(string $shareToken): string {
  $shareToken = trim($shareToken);
  return BASE_URL.'/public/invite_card.php?share='.rawurlencode($shareToken);
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
function event_guest_asset_dir(int $eventId): string {
  $root = __DIR__.'/../storage/events';
  if (!is_dir($root)) {
    @mkdir($root, 0775, true);
  }
  $edir = $root.'/'.(int)$eventId;
  if (!is_dir($edir)) {
    @mkdir($edir, 0775, true);
  }
  return $edir;
}
function event_guest_background_exists(?string $path): bool {
  $path = trim((string)$path);
  if ($path === '') {
    return false;
  }
  $normalized = ltrim(str_replace('\\', '/', $path), '/');
  if ($normalized === '') {
    return false;
  }
  if (strpos($normalized, '..') !== false) {
    return false;
  }
  $root = realpath(__DIR__.'/..');
  if ($root === false) {
    return false;
  }
  $full = realpath($root.'/'.$normalized);
  if ($full !== false && strpos($full, $root) === 0 && is_file($full)) {
    return true;
  }
  $candidate = $root.'/'.$normalized;
  return is_file($candidate);
}
function event_guest_background_url(?string $path): ?string {
  $path = trim((string)$path);
  if ($path === '') {
    return null;
  }
  if (!event_guest_background_exists($path)) {
    return null;
  }
  return BASE_URL.'/'.ltrim($path, '/');
}
function event_guest_background_delete(?string $path): void {
  $path = trim((string)$path);
  if ($path === '') {
    return;
  }
  $normalized = ltrim(str_replace('\\', '/', $path), '/');
  if ($normalized === '' || strpos($normalized, '..') !== false) {
    return;
  }
  $root = realpath(__DIR__.'/..');
  if ($root === false) {
    return;
  }
  $full = realpath($root.'/'.$normalized);
  if ($full === false || strpos($full, $root) !== 0 || !is_file($full)) {
    $full = $root.'/'.$normalized;
    if (strpos(realpath(dirname($full)) ?: '', $root) !== 0) {
      return;
    }
  }
  if (is_file($full)) {
    @unlink($full);
  }
}
function event_guest_background_store(int $eventId, array $file, ?string $existingPath = null): ?string {
  $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
  if ($error === UPLOAD_ERR_NO_FILE) {
    return $existingPath;
  }
  if ($error !== UPLOAD_ERR_OK) {
    return $existingPath;
  }
  $tmp = $file['tmp_name'] ?? '';
  if ($tmp === '' || !is_uploaded_file($tmp)) {
    return $existingPath;
  }
  $mime = null;
  if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
      $mime = finfo_file($finfo, $tmp) ?: null;
      finfo_close($finfo);
    }
  }
  if ($mime === null && function_exists('mime_content_type')) {
    $mime = @mime_content_type($tmp) ?: null;
  }
  $allowed = [
    'image/jpeg' => '.jpg',
    'image/png'  => '.png',
    'image/webp' => '.webp',
    'image/gif'  => '.gif',
  ];
  if ($mime === null || !isset($allowed[$mime])) {
    return $existingPath;
  }
  $ext = $allowed[$mime];
  $dir = event_guest_asset_dir($eventId);
  $filename = 'guest-background-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).$ext;
  $target = rtrim($dir, '/').'/'.$filename;
  if (!move_uploaded_file($tmp, $target)) {
    return $existingPath;
  }
  @chmod($target, 0664);
  if ($existingPath && $existingPath !== '') {
    event_guest_background_delete($existingPath);
  }
  $relative = '';
  $root = realpath(__DIR__.'/..');
  if ($root && strpos($target, $root) === 0) {
    $relative = ltrim(str_replace('\\', '/', substr($target, strlen($root))), '/');
  }
  if ($relative === '') {
    $relative = 'storage/events/'.(int)$eventId.'/'.$filename;
  }
  return $relative;
}

function event_guest_overlay_exists(?string $path): bool {
  return event_guest_background_exists($path);
}

function event_guest_overlay_url(?string $path): ?string {
  return event_guest_background_url($path);
}

function event_guest_overlay_delete(?string $path): void {
  event_guest_background_delete($path);
}

function event_guest_overlay_store(int $eventId, array $file): ?string {
  $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
  if ($error === UPLOAD_ERR_NO_FILE) {
    return null;
  }
  if ($error !== UPLOAD_ERR_OK) {
    return null;
  }
  $tmp = $file['tmp_name'] ?? '';
  if ($tmp === '' || !is_uploaded_file($tmp)) {
    return null;
  }
  $mime = null;
  if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
      $mime = finfo_file($finfo, $tmp) ?: null;
      finfo_close($finfo);
    }
  }
  if ($mime === null && function_exists('mime_content_type')) {
    $mime = @mime_content_type($tmp) ?: null;
  }
  $allowed = [
    'image/jpeg' => '.jpg',
    'image/png'  => '.png',
    'image/webp' => '.webp',
    'image/svg+xml' => '.svg',
    'image/gif'  => '.gif',
  ];
  if ($mime === null || !isset($allowed[$mime])) {
    return null;
  }
  $ext = $allowed[$mime];
  $dir = event_guest_asset_dir($eventId);
  $filename = 'guest-overlay-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).$ext;
  $target = rtrim($dir, '/').'/'.$filename;
  if (!move_uploaded_file($tmp, $target)) {
    return null;
  }
  @chmod($target, 0664);
  $relative = '';
  $root = realpath(__DIR__.'/..');
  if ($root && strpos($target, $root) === 0) {
    $relative = ltrim(str_replace('\\', '/', substr($target, strlen($root))), '/');
  }
  if ($relative === '') {
    $relative = 'storage/events/'.(int)$eventId.'/'.$filename;
  }
  return $relative;
}

function normalize_event_layout($layout): array {
  $defaults = [
    'title'    => ['x' => 24, 'y' => 24],
    'subtitle' => ['x' => 24, 'y' => 60],
    'prompt'   => ['x' => 24, 'y' => 396],
  ];
  if (!is_array($layout)) {
    $layout = [];
  }
  $normalized = [];
  foreach ($defaults as $key => $def) {
    $candidate = $layout[$key] ?? [];
    $x = is_array($candidate) && isset($candidate['x']) ? (int)$candidate['x'] : $def['x'];
    $y = is_array($candidate) && isset($candidate['y']) ? (int)$candidate['y'] : $def['y'];
    $normalized[$key] = [
      'x' => max(0, min(940, $x)),
      'y' => max(0, min(520, $y)),
    ];
  }
  return $normalized;
}

function normalize_event_stickers($stickers, int $eventId, ?array &$assetUrls = null): array {
  if (!is_array($stickers)) {
    return [];
  }
  if ($assetUrls === null) {
    $assetUrls = [];
  }
  $normalized = [];
  foreach ($stickers as $sticker) {
    if (!is_array($sticker)) {
      continue;
    }
    $type = strtolower((string)($sticker['type'] ?? 'emoji'));
    $x = isset($sticker['x']) ? (int)$sticker['x'] : 20;
    $y = isset($sticker['y']) ? (int)$sticker['y'] : 90;
    $x = max(0, min(940, $x));
    $y = max(0, min(520, $y));
    if ($type === 'image') {
      $path = trim((string)($sticker['path'] ?? ''));
      if ($path === '' || !event_guest_overlay_exists($path)) {
        continue;
      }
      $url = event_guest_overlay_url($path);
      if ($url === null) {
        continue;
      }
      $width = isset($sticker['width']) ? (int)$sticker['width'] : (isset($sticker['size']) ? (int)$sticker['size'] : 220);
      $width = max(80, min(560, $width));
      $assetUrls[$path] = $url;
      $normalized[] = [
        'type'  => 'image',
        'path'  => $path,
        'x'     => $x,
        'y'     => $y,
        'width' => $width,
      ];
      continue;
    }
    $txt = isset($sticker['txt']) ? (string)$sticker['txt'] : '';
    if ($txt === '') {
      $txt = '💍';
    }
    if (function_exists('mb_substr')) {
      $txt = mb_substr($txt, 0, 8, 'UTF-8');
    } else {
      $txt = substr($txt, 0, 8);
    }
    if ($txt === '') {
      $txt = '💍';
    }
    $size = isset($sticker['size']) ? (int)$sticker['size'] : 32;
    $size = max(20, min(200, $size));
    $normalized[] = [
      'type' => 'emoji',
      'txt'  => $txt,
      'x'    => $x,
      'y'    => $y,
      'size' => $size,
    ];
  }
  return $normalized;
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
