<?php
require_once __DIR__.'/../config.php';

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

  // Eğer SMTP_HOST tanımlı değilse mail() ile dener
  if (!defined('SMTP_HOST') || SMTP_HOST===''){
    $headers = "From: ".$fromName." <".$from.">\r\n".
               "MIME-Version: 1.0\r\n".
               "Content-Type: text/html; charset=UTF-8\r\n";
    $ok = @mail($to, '=?UTF-8?B?'.base64_encode($subject).'?=', $html, $headers);
    if (!$ok) $GLOBALS['MAIL_LAST_ERROR'] = 'PHP mail() başarısız veya sunucuda kapalı.';
    return $ok;
  }

  $host = SMTP_HOST; $port = SMTP_PORT ?? 587; $secure = strtolower(SMTP_SECURE ?? 'tls');

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

  if (defined('SMTP_USER') && SMTP_USER!==''){
    write_line($sock, "AUTH LOGIN"); read_line($sock);
    write_line($sock, base64_encode(SMTP_USER)); read_line($sock);
    write_line($sock, base64_encode(SMTP_PASS)); $resp = read_line($sock);
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
