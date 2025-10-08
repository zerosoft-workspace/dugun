<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/db.php';

/*
config.php içine şunlar tanımlı olmalı:
const MAIL_FROM = 'no-reply@SIZINDOMAIN.com';
const MAIL_FROM_NAME = 'BİKARE';
const SMTP_HOST = 'smtp.sizindomain.com';
const SMTP_PORT = 587;          // 587 (TLS) ya da 465 (SSL)
const SMTP_USER = 'smtp-kullanici@SIZINDOMAIN.com';
const SMTP_PASS = 'smtp-sifre';
const SMTP_SECURE = 'tls';      // 'tls', 'ssl' veya '' (güvenliksiz)
*/

$GLOBALS['MAIL_LAST_ERROR'] = null;

function mailer_settings_overrides(): array {
  static $cached = null;
  if ($cached !== null) {
    return $cached;
  }

  $overrides = [
    'host' => '',
    'port' => null,
    'user' => '',
    'pass' => '',
    'secure' => '',
    'from_email' => '',
    'from_name' => '',
  ];

  try {
    if (!function_exists('table_exists') || !table_exists('site_settings')) {
      return $cached = $overrides;
    }

    $keys = ['smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_secure', 'smtp_from_email', 'smtp_from_name'];
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $st = pdo()->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($placeholders)");
    $st->execute($keys);

    while ($row = $st->fetch()) {
      $key = $row['setting_key'];
      $value = $row['setting_value'];
      if ($value === null) {
        $value = '';
      }
      $value = is_string($value) ? trim($value) : '';
      switch ($key) {
        case 'smtp_host':
          $overrides['host'] = $value;
          break;
        case 'smtp_port':
          $overrides['port'] = $value;
          break;
        case 'smtp_user':
          $overrides['user'] = $value;
          break;
        case 'smtp_pass':
          $overrides['pass'] = $value;
          break;
        case 'smtp_secure':
          $overrides['secure'] = strtolower($value);
          break;
        case 'smtp_from_email':
          $overrides['from_email'] = $value;
          break;
        case 'smtp_from_name':
          $overrides['from_name'] = $value;
          break;
      }
    }
  } catch (Throwable $e) {
    return $cached = $overrides;
  }

  if ($overrides['port'] !== null && $overrides['port'] !== '') {
    $port = (int)$overrides['port'];
    $overrides['port'] = $port > 0 ? $port : null;
  } else {
    $overrides['port'] = null;
  }

  if (!in_array($overrides['secure'], ['tls', 'ssl'], true)) {
    $overrides['secure'] = '';
  }

  return $cached = $overrides;
}

function mailer_log($line){
  @file_put_contents('/tmp/wshare_mail.log', '['.date('Y-m-d H:i:s')."] $line\n", FILE_APPEND);
}

function read_line($sock){
  $data = '';
  while (($str = fgets($sock, 515)) !== false){
    $data .= $str;
    if (strlen($str) < 4) break;
    // 3 haneli kod + boşluk -> son satır
    if (preg_match('/^\d{3}\s/', $str)) break;
    // 3 haneli kod + '-' -> multi-line, devam
    if (!preg_match('/^\d{3}\-/', $str)) break;
  }
  mailer_log("S: ".trim($data));
  return $data;
}

function write_line($sock, $cmd){
  mailer_log("C: $cmd");
  fputs($sock, $cmd."\r\n");
}

function send_smtp_mail($to, $subject, $html, $from=MAIL_FROM, $fromName=MAIL_FROM_NAME){
  $GLOBALS['MAIL_LAST_ERROR'] = null;

  $overrides = mailer_settings_overrides();

  $defaultFrom = defined('MAIL_FROM') ? MAIL_FROM : 'no-reply@example.com';
  if ($from === $defaultFrom && $overrides['from_email'] !== '') {
    $from = $overrides['from_email'];
  }
  $defaultFromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : APP_NAME;
  if ($fromName === $defaultFromName && $overrides['from_name'] !== '') {
    $fromName = $overrides['from_name'];
  }

  // Eğer SMTP_HOST tanımlı değilse mail() ile dener
  $hostConstant = defined('SMTP_HOST') ? SMTP_HOST : '';
  $host = $overrides['host'] !== '' ? $overrides['host'] : $hostConstant;

  if ($host === ''){
    $headers = "From: ".$fromName." <".$from.">\r\n".
               "MIME-Version: 1.0\r\n".
               "Content-Type: text/html; charset=UTF-8\r\n";
    $ok = @mail($to, '=?UTF-8?B?'.base64_encode($subject).'?=', $html, $headers);
    if (!$ok) $GLOBALS['MAIL_LAST_ERROR'] = 'PHP mail() başarısız veya sunucuda kapalı.';
    return $ok;
  }

  $port = $overrides['port'] ?? (defined('SMTP_PORT') ? (int)SMTP_PORT : 587);
  if (!$port) {
    $port = 587;
  }
  $secure = $overrides['secure'] !== '' ? $overrides['secure'] : strtolower(defined('SMTP_SECURE') ? (string)SMTP_SECURE : 'tls');
  if (!in_array($secure, ['tls', 'ssl'], true)) {
    $secure = '';
  }
  $user = $overrides['user'] !== '' ? $overrides['user'] : (defined('SMTP_USER') ? SMTP_USER : '');
  $pass = $overrides['pass'] !== '' ? $overrides['pass'] : (defined('SMTP_PASS') ? SMTP_PASS : '');

  $target = ($secure==='ssl'?'ssl://':'').$host;
  $sock = @fsockopen($target, $port, $errno, $errstr, 15);
  if (!$sock){
    $GLOBALS['MAIL_LAST_ERROR'] = "SMTP bağlantı hatası: $errno $errstr ($target:$port)";
    mailer_log($GLOBALS['MAIL_LAST_ERROR']);
    return false;
  }

  $greet = read_line($sock);
  write_line($sock, "EHLO ".$host); read_line($sock);

  if ($secure==='tls'){
    write_line($sock, "STARTTLS"); $resp = read_line($sock);
    if (strpos($resp,'220')!==0){
      $GLOBALS['MAIL_LAST_ERROR'] = "STARTTLS başarısız: $resp";
      fclose($sock); return false;
    }
    if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)){
      $GLOBALS['MAIL_LAST_ERROR'] = "TLS el sıkışması başarısız.";
      fclose($sock); return false;
    }
    // TLS sonrası tekrar EHLO
    write_line($sock, "EHLO ".$host); read_line($sock);
  }

  if ($user !== ''){
    write_line($sock, "AUTH LOGIN"); read_line($sock);
    write_line($sock, base64_encode($user)); read_line($sock);
    write_line($sock, base64_encode($pass)); $resp = read_line($sock);
    if (strpos($resp,'235')!==0){
      $GLOBALS['MAIL_LAST_ERROR'] = "SMTP kimlik doğrulama hatası: $resp";
      fclose($sock); return false;
    }
  }

  write_line($sock, "MAIL FROM:<".$from.">"); $r=read_line($sock);
  if (strpos($r,'250')!==0){ $GLOBALS['MAIL_LAST_ERROR']="MAIL FROM reddi: $r"; fclose($sock); return false; }

  write_line($sock, "RCPT TO:<".$to.">"); $r=read_line($sock);
  if (strpos($r,'250')!==0 && strpos($r,'251')!==0){ $GLOBALS['MAIL_LAST_ERROR']="RCPT TO reddi: $r"; fclose($sock); return false; }

  write_line($sock, "DATA"); $r=read_line($sock);
  if (strpos($r,'354')!==0){ $GLOBALS['MAIL_LAST_ERROR']="DATA kabul edilmedi: $r"; fclose($sock); return false; }

  $headers  = "From: ".$fromName." <".$from.">\r\n";
  $headers .= "MIME-Version: 1.0\r\n";
  $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
  $headers .= "Subject: =?UTF-8?B?".base64_encode($subject)."?=\r\n";
  $headers .= "To: <".$to.">\r\n";

  $data = $headers."\r\n".$html."\r\n.";
  mailer_log("C: [DATA ".strlen($data)." bytes]");
  fputs($sock, $data."\r\n");
  $r=read_line($sock);
  if (strpos($r,'250')!==0){ $GLOBALS['MAIL_LAST_ERROR']="DATA sonrası hata: $r"; fclose($sock); return false; }

  write_line($sock, "QUIT"); read_line($sock);
  fclose($sock);
  return true;
}
