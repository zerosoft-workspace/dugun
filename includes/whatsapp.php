<?php
/**
 * includes/whatsapp.php — WhatsApp toplu mesajlaşma yardımcıları
 */
require_once __DIR__.'/../config.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/sms.php';

if (!function_exists('whatsapp_normalize_number')) {
  function whatsapp_normalize_number(string $phone): string {
    if (function_exists('sms_normalize_number')) {
      return sms_normalize_number($phone);
    }
    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === null) {
      return '';
    }
    if (strlen($digits) === 10) {
      return '90'.$digits;
    }
    if (strlen($digits) === 11 && $digits[0] === '0') {
      return '90'.substr($digits, 1);
    }
    return $digits;
  }
}

if (!function_exists('whatsapp_config_error')) {
  function whatsapp_config_error(?array $config = null): ?string {
    $config = $config ?? site_whatsapp_config();
    if (empty($config['enabled'])) {
      return 'WhatsApp API devre dışı bırakılmış.';
    }
    if (trim((string)($config['api_url'] ?? '')) === '') {
      return 'WhatsApp API URL değeri girilmemiş.';
    }
    if (trim((string)($config['api_token'] ?? '')) === '') {
      return 'WhatsApp API anahtarı tanımlanmamış.';
    }
    return null;
  }
}

if (!function_exists('whatsapp_prepare_media_payload')) {
  function whatsapp_prepare_media_payload(array $attachments): array {
    $media = [];
    $errors = [];
    $baseDir = realpath(__DIR__.'/../uploads');
    if ($baseDir === false) {
      $baseDir = __DIR__.'/../uploads';
    }
    $maxSize = 16 * 1024 * 1024; // WhatsApp media limit ~16MB

    foreach ($attachments as $file) {
      if (!is_array($file)) {
        continue;
      }
      $relative = isset($file['path']) && is_string($file['path']) ? trim($file['path']) : '';
      if ($relative === '') {
        continue;
      }
      $normalized = ltrim(str_replace('\\', '/', $relative), '/');
      if ($normalized === '' || strpos($normalized, '..') !== false) {
        $errors[] = ($file['name'] ?? $relative).' dosya yolu doğrulanamadı.';
        continue;
      }
      $full = realpath($baseDir.'/'.$normalized);
      if ($full === false || !is_file($full)) {
        $errors[] = ($file['name'] ?? $relative).' sunucuda bulunamadı.';
        continue;
      }
      $size = @filesize($full);
      if ($size !== false && $size > $maxSize) {
        $errors[] = ($file['name'] ?? basename($full)).' 16MB sınırını aşıyor, gönderime eklenmedi.';
        continue;
      }
      $content = @file_get_contents($full);
      if ($content === false) {
        $errors[] = ($file['name'] ?? basename($full)).' okunamadı.';
        continue;
      }
      $mime = isset($file['mime']) && is_string($file['mime']) ? trim($file['mime']) : '';
      if ($mime === '') {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
          $detected = @finfo_file($finfo, $full);
          if ($detected) {
            $mime = $detected;
          }
          finfo_close($finfo);
        }
        if ($mime === '') {
          $mime = 'application/octet-stream';
        }
      }
      $media[] = [
        'filename' => isset($file['name']) && $file['name'] !== '' ? $file['name'] : basename($full),
        'mime_type'=> $mime,
        'content'  => base64_encode($content),
      ];
    }

    return ['media' => $media, 'errors' => $errors];
  }
}

if (!function_exists('whatsapp_api_request')) {
  function whatsapp_api_request(string $url, string $token, array $payload): array {
    $headers = ['Content-Type: application/json'];
    $token = trim($token);
    if ($token !== '') {
      $headers[] = 'Authorization: '.(stripos($token, 'Bearer ') === 0 ? $token : 'Bearer '.$token);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
      return [
        'success' => false,
        'status'  => 0,
        'error'   => 'WhatsApp API bağlantı hatası: '.$error,
        'body'    => null,
        'decoded' => null,
      ];
    }

    $decoded = json_decode((string)$body, true);
    $success = ($status >= 200 && $status < 300);
    $message = '';
    if (is_array($decoded)) {
      if (isset($decoded['error'])) {
        if (is_array($decoded['error'])) {
          $message = trim((string)($decoded['error']['message'] ?? 'WhatsApp API hatası'));
        } else {
          $message = trim((string)$decoded['error']);
        }
      } elseif (!$success && isset($decoded['message'])) {
        $message = trim((string)$decoded['message']);
      }
    }
    if (!$success && $message === '') {
      $message = 'WhatsApp API başarısız yanıt verdi (HTTP '.$status.').';
    }

    return [
      'success' => $success,
      'status'  => $status,
      'error'   => $success ? null : $message,
      'body'    => $body,
      'decoded' => $decoded,
    ];
  }
}

if (!function_exists('whatsapp_log_attempt')) {
  function whatsapp_log_attempt(array $numbers, string $message, bool $success, array $results = [], array $attachments = [], array $errors = []): void {
    $dir = __DIR__.'/../storage';
    if (!is_dir($dir)) {
      @mkdir($dir, 0775, true);
    }
    $payload = [
      'time'    => date('c'),
      'success' => $success,
      'numbers' => array_values($numbers),
    ];
    if ($message !== '') {
      $payload['message'] = $message;
    }
    if ($attachments !== []) {
      $payload['attachments'] = array_map(function ($item) {
        if (!is_array($item)) {
          return $item;
        }
        return [
          'filename' => $item['filename'] ?? ($item['name'] ?? null),
          'mime'     => $item['mime_type'] ?? ($item['mime'] ?? null),
          'size'     => $item['size'] ?? null,
        ];
      }, $attachments);
    }
    if ($errors !== []) {
      $payload['errors'] = $errors;
    }
    if ($results !== []) {
      $payload['results'] = array_map(function ($info) {
        if (!is_array($info)) {
          return $info;
        }
        return [
          'status' => $info['status'] ?? null,
          'detail' => isset($info['detail']) ? mb_substr((string)$info['detail'], 0, 300, 'UTF-8') : null,
          'status_code' => $info['status_code'] ?? null,
        ];
      }, $results);
    }
    @file_put_contents($dir.'/whatsapp.log', json_encode($payload, JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND);
  }
}

if (!function_exists('whatsapp_send_bulk')) {
  function whatsapp_send_bulk(array $phones, string $message, array $attachments = []): array {
    $phones = array_values(array_filter(array_map(function ($phone) {
      return whatsapp_normalize_number((string)$phone);
    }, $phones), function ($phone) {
      return $phone !== '';
    }));

    $result = [
      'results' => [],
      'sent' => 0,
      'failed' => 0,
      'errors' => [],
      'attachment_errors' => [],
    ];

    if ($phones === []) {
      return $result;
    }

    $config = site_whatsapp_config();
    $configError = whatsapp_config_error($config);
    if ($configError !== null) {
      $result['errors'][] = $configError;
      foreach ($phones as $phone) {
        $result['results'][$phone] = [
          'status' => 'failed',
          'detail' => $configError,
          'status_code' => null,
        ];
        $result['failed']++;
      }
      whatsapp_log_attempt($phones, $message, false, $result['results'], [], $result['errors']);
      return $result;
    }

    $media = whatsapp_prepare_media_payload($attachments);
    $mediaPayload = $media['media'];
    if ($media['errors'] !== []) {
      $result['attachment_errors'] = $media['errors'];
    }

    foreach ($phones as $phone) {
      $payload = ['to' => $phone];
      if ($message !== '') {
        $payload['message'] = $message;
      }
      if ($mediaPayload !== []) {
        $payload['media'] = $mediaPayload;
      }
      if (!empty($config['sender'])) {
        $payload['from'] = $config['sender'];
      }

      $response = whatsapp_api_request((string)$config['api_url'], (string)$config['api_token'], $payload);
      if (!empty($response['success'])) {
        $result['results'][$phone] = [
          'status' => 'sent',
          'detail' => 'WhatsApp mesajı gönderildi',
          'status_code' => $response['status'] ?? null,
        ];
        $result['sent']++;
      } else {
        $detail = isset($response['error']) && is_string($response['error']) ? $response['error'] : 'WhatsApp mesajı gönderilemedi';
        $result['results'][$phone] = [
          'status' => 'failed',
          'detail' => $detail,
          'status_code' => $response['status'] ?? null,
        ];
        $result['failed']++;
        if ($detail !== '') {
          $result['errors'][] = $detail;
        }
      }
    }

    $success = $result['sent'] > 0 && $result['failed'] === 0;
    whatsapp_log_attempt($phones, $message, $success, $result['results'], $mediaPayload, array_unique($result['errors']));

    return $result;
  }
}
