<?php
/* ===================== CONFIG ====================== */
if (!function_exists('config_env')) {
  /**
   * Küçük bir yardımcı: .env veya ortam değişkenlerinden değer okur.
   *
   * getenv() boş string dönebildiği için yalnızca false olduğunda
   * varsayılanı kullanıyoruz.
   */
  function config_env(string $key, $default = null) {
    $value = getenv($key);
    return $value === false ? $default : $value;
  }
}

$GLOBALS['__appSetupConfig'] = [];
$__appSetupFile = __DIR__.'/storage/setup.json';
if (is_readable($__appSetupFile)) {
  $contents = file_get_contents($__appSetupFile);
  $decoded = json_decode($contents, true);
  if (is_array($decoded)) {
    $GLOBALS['__appSetupConfig'] = $decoded;
  }
}

if (!function_exists('config_value')) {
  function config_value(string $key, $default = null) {
    $config = $GLOBALS['__appSetupConfig'] ?? [];
    if (array_key_exists($key, $config)) {
      $value = $config[$key];
      if (is_string($value)) {
        $value = trim($value);
      }
      if ($value !== null && $value !== '') {
        return $value;
      }
    }

    return config_env($key, $default);
  }
}

define('DB_HOST', config_value('APP_DB_HOST', '127.0.0.1'));
define('DB_NAME', config_value('APP_DB_NAME', 'dugun'));
define('DB_USER', config_value('APP_DB_USER', 'dugun'));
define('DB_PASS', config_value('APP_DB_PASS', 'secret'));

$baseUrl = (string)config_value('APP_BASE_URL', 'http://localhost');
$baseUrl = $baseUrl !== '' ? rtrim($baseUrl, '/') : 'http://localhost';
define('BASE_URL', $baseUrl); // kök URL (sonda / yok)

define('APP_NAME', config_env('APP_NAME', 'BİKARE'));
define('SECRET_KEY', config_env('APP_SECRET_KEY', 'CHANGE_THIS_LONG_RANDOM_SECRET_32+CHARS'));

const MAX_UPLOAD_BYTES = 25 * 1024 * 1024; // 25 MB
const ALLOWED_MIMES = [
  'image/jpeg' => 'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif',
  'video/mp4'=>'mp4','video/quicktime'=>'mov','video/webm'=>'webm',
  
];
define('MAIL_FROM', config_env('MAIL_FROM', 'no-reply@example.com'));
define('MAIL_FROM_NAME', config_env('MAIL_FROM_NAME', APP_NAME));
define('SMTP_HOST', config_env('SMTP_HOST', ''));
$smtpPort = config_env('SMTP_PORT', null);
$smtpPort = is_numeric($smtpPort) ? (int)$smtpPort : 465;
define('SMTP_PORT', $smtpPort);
define('SMTP_USER', config_env('SMTP_USER', ''));
define('SMTP_PASS', config_env('SMTP_PASS', ''));
define('SMTP_SECURE', strtoupper((string)config_env('SMTP_SECURE', 'tls')));
/* ================= PAYTR ================== */
define('PAYTR_MERCHANT_ID', config_env('PAYTR_MERCHANT_ID', '')); 
define('PAYTR_MERCHANT_KEY', config_env('PAYTR_MERCHANT_KEY', ''));
define('PAYTR_MERCHANT_SALT', config_env('PAYTR_MERCHANT_SALT', ''));
define('PAYTR_OK_URL', BASE_URL.'/couple/paytr_ok.php');
define('PAYTR_FAIL_URL', BASE_URL.'/couple/paytr_fail.php');
define('PAYTR_CALLBACK_URL', BASE_URL.'/couple/paytr_callback.php');
define('PAYTR_SITE_OK_URL', BASE_URL.'/order_paytr_ok.php');
define('PAYTR_SITE_FAIL_URL', BASE_URL.'/order_paytr_fail.php');
define('PAYTR_DEALER_OK_URL', BASE_URL.'/dealer/paytr_ok.php');
define('PAYTR_DEALER_FAIL_URL', BASE_URL.'/dealer/paytr_fail.php');
$paytrTest = config_env('PAYTR_TEST_MODE', null);
$paytrTest = ($paytrTest === null || $paytrTest === '') ? 1 : (int)$paytrTest;
define('PAYTR_TEST_MODE', $paytrTest); // 1=test, 0=canlı
/* ========================================= */

/* =================================================== */

date_default_timezone_set('Europe/Istanbul');
session_start();
