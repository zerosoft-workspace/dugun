<?php
/**
 * includes/sms.php — Toplu SMS entegrasyonu yardımcıları
 */
require_once __DIR__.'/../config.php';

if (!function_exists('sms_normalize_number')) {
  function sms_normalize_number(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === null) {
      return '';
    }
    $digits = ltrim($digits);
    if ($digits === '') {
      return '';
    }
    if (strpos($digits, '00') === 0) {
      $digits = substr($digits, 2) ?: '';
    }
    if ($digits === '') {
      return '';
    }
    if (strlen($digits) === 10) {
      $digits = '90'.$digits;
    } elseif (strlen($digits) === 11 && $digits[0] === '0') {
      $digits = '90'.substr($digits, 1);
    }
    if (strlen($digits) >= 12 && strpos($digits, '90') !== 0) {
      $digits = '90'.substr($digits, -10);
    }
    return $digits;
  }
}

if (!function_exists('sms_is_configured')) {
  function sms_is_configured(): bool {
    return SMS_API_URL !== '';
  }
}

if (!function_exists('sms_log_attempt')) {
  function sms_log_attempt(array $numbers, string $message, bool $success, ?string $error = null, array $failed = [], $response = null): void {
    $dir = __DIR__.'/../storage';
    if (!is_dir($dir)) {
      @mkdir($dir, 0775, true);
    }
    $payload = [
      'time'     => date('c'),
      'success'  => $success,
      'numbers'  => array_values($numbers),
      'message'  => $message,
    ];
    if ($error) {
      $payload['error'] = $error;
    }
    if ($failed !== []) {
      $payload['failed'] = $failed;
    }
    if ($response !== null) {
      $payload['response'] = $response;
    }
    $line = json_encode($payload, JSON_UNESCAPED_UNICODE).PHP_EOL;
    @file_put_contents($dir.'/sms.log', $line, FILE_APPEND);
  }
}

if (!function_exists('sms_send_bulk')) {
  function sms_send_bulk(array $phones, string $message): array {
    $normalized = [];
    foreach ($phones as $phone) {
      if (!is_string($phone)) {
        continue;
      }
      $normalizedNumber = sms_normalize_number($phone);
      if ($normalizedNumber !== '') {
        $normalized[$normalizedNumber] = $normalizedNumber;
      }
    }
    $numbers = array_values($normalized);
    $result = [
      'sent'    => 0,
      'failed'  => [],
      'error'   => null,
      'response'=> null,
    ];

    if ($numbers === []) {
      return $result;
    }

    if (!sms_is_configured()) {
      $result['error'] = 'SMS servisi yapılandırılmamış.';
      foreach ($numbers as $num) {
        $result['failed'][] = ['number' => $num, 'error' => 'Konfigürasyon eksik'];
      }
      sms_log_attempt($numbers, $message, false, $result['error'], $result['failed']);
      return $result;
    }

    $payload = [
      'message'     => $message,
      'recipients'  => $numbers,
    ];
    if (SMS_SENDER !== '') {
      $payload['sender'] = SMS_SENDER;
    }
    if (SMS_API_KEY !== '' && SMS_API_SECRET === '') {
      $payload['apiKey'] = SMS_API_KEY;
    }
    if (SMS_API_SECRET !== '') {
      $payload['apiSecret'] = SMS_API_SECRET;
    }

    $headers = ['Content-Type: application/json'];
    if (SMS_API_KEY !== '' || SMS_API_SECRET !== '') {
      $headers[] = 'Authorization: Basic '.base64_encode(SMS_API_KEY.':'.SMS_API_SECRET);
    }

    $ch = curl_init(SMS_API_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_values(array_filter($headers)));
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));

    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
      $result['error'] = 'SMS servisine bağlanılamadı: '.$error;
      foreach ($numbers as $num) {
        $result['failed'][] = ['number' => $num, 'error' => $result['error']];
      }
      sms_log_attempt($numbers, $message, false, $result['error']);
      return $result;
    }

    $result['response'] = $response;
    $decoded = json_decode((string)$response, true);

    if ($status >= 200 && $status < 300) {
      $failed = [];
      if (is_array($decoded)) {
        if (!empty($decoded['failed']) && is_array($decoded['failed'])) {
          foreach ($decoded['failed'] as $item) {
            if (is_string($item)) {
              $failed[] = ['number' => sms_normalize_number($item), 'error' => 'Servis reddetti'];
            } elseif (is_array($item)) {
              $failed[] = [
                'number' => sms_normalize_number((string)($item['number'] ?? '')),
                'error'  => trim((string)($item['error'] ?? 'Servis hatası')),
              ];
            }
          }
        }
        if (!empty($decoded['error']) && is_string($decoded['error'])) {
          $result['error'] = trim($decoded['error']);
        } elseif (!empty($decoded['message']) && empty($decoded['success'])) {
          $result['error'] = trim((string)$decoded['message']);
        }
        if (isset($decoded['accepted']) && is_numeric($decoded['accepted'])) {
          $result['sent'] = (int)$decoded['accepted'];
        } elseif (isset($decoded['sent']) && is_numeric($decoded['sent'])) {
          $result['sent'] = (int)$decoded['sent'];
        } else {
          $result['sent'] = count($numbers) - count($failed);
        }
      } else {
        $result['sent'] = count($numbers);
      }

      $result['failed'] = array_values(array_filter($failed, function ($row) {
        return !empty($row['number']);
      }));
      $success = $result['sent'] > 0 && count($result['failed']) < count($numbers);
      sms_log_attempt($numbers, $message, $success, $result['error'], $result['failed'], $response);
      return $result;
    }

    $result['error'] = 'SMS servisi başarısız yanıt verdi (HTTP '.$status.')';
    foreach ($numbers as $num) {
      $result['failed'][] = ['number' => $num, 'error' => $result['error']];
    }
    sms_log_attempt($numbers, $message, false, $result['error'], $result['failed'], $response);
    return $result;
  }
}
