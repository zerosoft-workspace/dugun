<?php
/**
 * includes/site.php — Genel web sitesi satış & sipariş yardımcıları
 */
require_once __DIR__.'/../config.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/dealers.php';

if (!defined('SITE_ORDER_STATUS_PENDING')) {
  define('SITE_ORDER_STATUS_PENDING', 'pending_payment');
  define('SITE_ORDER_STATUS_AWAITING', 'awaiting_payment');
  define('SITE_ORDER_STATUS_PAID', 'paid');
  define('SITE_ORDER_STATUS_COMPLETED', 'completed');
  define('SITE_ORDER_STATUS_FAILED', 'failed');
}

function site_seed_default_packages(): void {
  try {
    $count = (int)pdo()->query("SELECT COUNT(*) FROM dealer_packages")->fetchColumn();
  } catch (Throwable $e) {
    return;
  }
  if ($count > 0) {
    return;
  }
  $now = now();
  $defaults = [
    [
      'name' => 'Tek Etkinlik Paketi',
      'description' => '1 etkinlik için panel, limitsiz yükleme, standart tema desteği.',
      'price_cents' => 300000,
      'event_quota' => 1,
      'duration_days' => 365,
      'cashback_rate' => 0.20,
    ],
    [
      'name' => 'İki Etkinlik Paketi',
      'description' => 'Aynı sezon iki etkinlik için QR yönetimi ve misafir galerisi.',
      'price_cents' => 520000,
      'event_quota' => 2,
      'duration_days' => 365,
      'cashback_rate' => 0.20,
    ],
    [
      'name' => 'Sınırsız Aylık Paketi',
      'description' => '30 gün boyunca sınırsız etkinlik, premium destek ve kampanya modülleri.',
      'price_cents' => 890000,
      'event_quota' => null,
      'duration_days' => 30,
      'cashback_rate' => 0.20,
    ],
  ];
  $st = pdo()->prepare("INSERT INTO dealer_packages (name, description, price_cents, event_quota, duration_days, cashback_rate, is_active, is_public, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)");
  foreach ($defaults as $pkg) {
    $st->execute([
      $pkg['name'],
      $pkg['description'],
      $pkg['price_cents'],
      $pkg['event_quota'],
      $pkg['duration_days'],
      $pkg['cashback_rate'],
      1,
      1,
      $now,
      $now,
    ]);
  }
}

function site_public_packages(): array {
  site_seed_default_packages();
  return dealer_packages_public();
}

function site_default_sales_venue_id(): int {
  $slug = 'genel-satis';
  $st = pdo()->prepare("SELECT id FROM venues WHERE slug=? LIMIT 1");
  $st->execute([$slug]);
  $id = $st->fetchColumn();
  if ($id) {
    return (int)$id;
  }
  $name = 'Genel Satışlar';
  $now = now();
  pdo()->prepare("INSERT INTO venues (name, slug, created_at, is_active) VALUES (?,?,?,1)")
      ->execute([$name, $slug, $now]);
  return (int)pdo()->lastInsertId();
}

function site_normalize_event_date(?string $date): ?string {
  if (!$date) {
    return null;
  }
  $date = trim($date);
  if ($date === '') {
    return null;
  }
  $formats = ['Y-m-d', 'd.m.Y'];
  foreach ($formats as $fmt) {
    $dt = DateTime::createFromFormat($fmt, $date);
    if ($dt && $dt->format($fmt) === $date) {
      return $dt->format('Y-m-d');
    }
  }
  $ts = strtotime($date);
  if ($ts !== false) {
    return date('Y-m-d', $ts);
  }
  return null;
}

function site_create_event(int $venue_id, ?int $dealer_id, array $package, array $data): array {
  $pdo = pdo();
  $title = $data['event_title'];
  $slug = slugify($title);
  if ($slug === '') {
    $slug = 'etkinlik-'.bin2hex(random_bytes(3));
  }
  $base = $slug;
  $i = 1;
  while (true) {
    $chk = $pdo->prepare("SELECT id FROM events WHERE venue_id=? AND slug=? LIMIT 1");
    $chk->execute([$venue_id, $slug]);
    if (!$chk->fetch()) {
      break;
    }
    $slug = $base.'-'.$i++;
  }
  $key = bin2hex(random_bytes(16));
  $plainPassword = substr(bin2hex(random_bytes(8)), 0, 12);
  $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
  $now = now();
  $eventDate = site_normalize_event_date($data['event_date'] ?? null);
  $primary = defined('THEME_PRIMARY_DEFAULT') ? THEME_PRIMARY_DEFAULT : '#0ea5b5';
  $accent  = defined('THEME_ACCENT_DEFAULT') ? THEME_ACCENT_DEFAULT : '#e0f7fb';
  $licenseUntil = null;
  if (!empty($package['duration_days'])) {
    $dt = new DateTime($now);
    $dt->modify('+'.(int)$package['duration_days'].' days');
    $licenseUntil = $dt->format('Y-m-d H:i:s');
  }
  $dealerCreditAt = $dealer_id ? $now : null;
  $columns = "venue_id,dealer_id,dealer_credit_consumed_at,user_id,contact_email,title,slug,is_active,couple_panel_key,theme_primary,theme_accent,event_date,created_at,updated_at,couple_username,couple_password_hash,couple_force_reset";
  $placeholders = "?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?";
  $values = [
    $venue_id,
    $dealer_id,
    $dealerCreditAt,
    null,
    $data['customer_email'],
    $title,
    $slug,
    1,
    $key,
    $primary,
    $accent,
    $eventDate,
    $now,
    $now,
    $data['customer_email'],
    $hash,
    1,
  ];
  if (column_exists('events', 'license_expires_at')) {
    $columns .= ',license_expires_at';
    $placeholders .= ',?';
    $values[] = $licenseUntil;
  }
  $eventId = null;
  try {
    $sql = 'INSERT INTO events ('.$columns.') VALUES ('.$placeholders.')';
    $pdo->prepare($sql)->execute($values);
    $eventId = (int)$pdo->lastInsertId();
  } catch (Throwable $e) {
    // Eski şema desteği için dealer ve user alanlarını çıkarıp tekrar dene
    $columnsFallback = "venue_id,title,slug,is_active,couple_panel_key,theme_primary,theme_accent,event_date,created_at,couple_username,couple_password_hash,couple_force_reset";
    $placeholdersFallback = "?,?,?,?,?,?,?,?,?,?,?,?";
    $valuesFallback = [
      $venue_id,
      $title,
      $slug,
      1,
      $key,
      $primary,
      $accent,
      $eventDate,
      $now,
      $data['customer_email'],
      $hash,
      1,
    ];
    $pdo->prepare('INSERT INTO events ('.$columnsFallback.') VALUES ('.$placeholdersFallback.')')
        ->execute($valuesFallback);
    $eventId = (int)$pdo->lastInsertId();
    $updateSql = 'UPDATE events SET contact_email=?, dealer_id=?, dealer_credit_consumed_at=?, updated_at=?';
    $params = [
      $data['customer_email'],
      $dealer_id,
      $dealerCreditAt,
      $now,
    ];
    if (column_exists('events', 'license_expires_at')) {
      $updateSql .= ', license_expires_at=?';
      $params[] = $licenseUntil;
    }
    $updateSql .= ' WHERE id=?';
    $params[] = $eventId;
    $pdo->prepare($updateSql)->execute($params);
  }
  if (!$eventId) {
    $eventId = (int)$pdo->lastInsertId();
  }
  $qrCode = null;
  if ($eventId > 0) {
    for ($attempt = 0; $attempt < 5; $attempt++) {
      $candidate = dealer_generate_unique_code();
      try {
        $pdo->prepare("INSERT INTO qr_codes (venue_id, code, target_event_id, created_at, updated_at) VALUES (?,?,?,?,?)")
            ->execute([$venue_id, $candidate, $eventId, $now, $now]);
        $qrCode = $candidate;
        break;
      } catch (Throwable $e) {
        continue;
      }
    }
  }
  return [
    'id' => $eventId,
    'slug' => $slug,
    'plain_password' => $plainPassword,
    'qr_code' => $qrCode,
    'upload_url' => $eventId ? public_upload_url($eventId) : null,
    'license_expires_at' => $licenseUntil,
    'event_date' => $eventDate,
  ];
}

function site_order_normalize_row(?array $row): ?array {
  if (!$row) {
    return null;
  }
  $row['id'] = (int)$row['id'];
  $row['package_id'] = (int)$row['package_id'];
  $row['dealer_id'] = $row['dealer_id'] !== null ? (int)$row['dealer_id'] : null;
  $row['event_id'] = $row['event_id'] !== null ? (int)$row['event_id'] : null;
  $row['price_cents'] = (int)$row['price_cents'];
  $row['cashback_cents'] = (int)$row['cashback_cents'];
  $row['meta'] = !empty($row['meta_json']) ? (safe_json_decode($row['meta_json']) ?: []) : [];
  $row['payload'] = !empty($row['payload_json']) ? (safe_json_decode($row['payload_json']) ?: []) : [];
  $row['created_at'] = $row['created_at'] ?? now();
  $row['paid_at'] = $row['paid_at'] ?? null;
  return $row;
}

function site_get_order(int $order_id): ?array {
  $st = pdo()->prepare("SELECT * FROM site_orders WHERE id=? LIMIT 1");
  $st->execute([$order_id]);
  return site_order_normalize_row($st->fetch());
}

function site_get_order_by_oid(string $oid): ?array {
  $oid = trim($oid);
  if ($oid === '') {
    return null;
  }
  $st = pdo()->prepare("SELECT * FROM site_orders WHERE merchant_oid=? LIMIT 1");
  $st->execute([$oid]);
  return site_order_normalize_row($st->fetch());
}

function site_generate_order_oid(int $order_id): string {
  do {
    $rand = strtoupper(bin2hex(random_bytes(6)));
    $oid = 'SO'.$order_id.'O'.$rand;
    $oid = substr(preg_replace('/[^A-Za-z0-9]/', '', $oid), 0, 64);
    $st = pdo()->prepare("SELECT 1 FROM site_orders WHERE merchant_oid=? LIMIT 1");
    $st->execute([$oid]);
    $exists = (bool)$st->fetchColumn();
  } while ($exists);
  return $oid;
}

function site_create_customer_order(array $input): array {
  $packageId = (int)($input['package_id'] ?? 0);
  $customerName = trim($input['customer_name'] ?? '');
  $customerEmail = trim($input['customer_email'] ?? '');
  $customerPhone = trim($input['customer_phone'] ?? '');
  $eventTitle = trim($input['event_title'] ?? '');
  $eventDate = trim($input['event_date'] ?? '');
  $notes = trim($input['notes'] ?? '');
  $referral = trim($input['referral_code'] ?? '');

  if ($packageId <= 0) {
    throw new RuntimeException('Lütfen bir paket seçin.');
  }
  if ($customerName === '') {
    throw new RuntimeException('Adınızı ve soyadınızı girin.');
  }
  if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
    throw new RuntimeException('Geçerli bir e-posta adresi girin.');
  }
  if ($eventTitle === '') {
    $eventTitle = $customerName.' Etkinliği';
  }
  $package = dealer_package_get($packageId);
  if (!$package || !$package['is_active'] || !$package['is_public']) {
    throw new RuntimeException('Seçtiğiniz paket şu anda satışta değil.');
  }
  $dealer = null;
  if ($referral !== '') {
    $dealer = dealer_find_by_code($referral);
    if (!$dealer) {
      throw new RuntimeException('Girilen referans kodu bulunamadı.');
    }
    if (!in_array($dealer['status'], [DEALER_STATUS_ACTIVE, DEALER_STATUS_PENDING], true)) {
      throw new RuntimeException('Bayi kodu şu anda pasif durumda.');
    }
  }

  $pdo = pdo();
  $now = now();
  $price = (int)$package['price_cents'];
  $cashbackRate = $dealer ? max(0, (float)$package['cashback_rate']) : 0.0;
  $cashbackCents = $dealer ? (int)round($price * $cashbackRate) : 0;
  $meta = array_filter([
    'notes' => $notes !== '' ? $notes : null,
  ]);
  $pdo->prepare(
    "INSERT INTO site_orders (package_id, dealer_id, customer_name, customer_email, customer_phone, event_title, event_date, referral_code, status, price_cents, cashback_cents, meta_json, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
  )->execute([
    $packageId,
    $dealer ? (int)$dealer['id'] : null,
    $customerName,
    $customerEmail,
    $customerPhone !== '' ? $customerPhone : null,
    $eventTitle,
    site_normalize_event_date($eventDate),
    $referral !== '' ? $referral : null,
    SITE_ORDER_STATUS_PENDING,
    $price,
    $cashbackCents,
    $meta ? safe_json_encode($meta) : null,
    $now,
    $now,
  ]);

  $orderId = (int)$pdo->lastInsertId();
  $order = site_get_order($orderId);

  return [
    'order' => $order,
    'package' => $package,
    'dealer' => $dealer,
    'customer' => [
      'name' => $customerName,
      'email' => $customerEmail,
      'phone' => $customerPhone,
    ],
  ];
}

function site_ensure_order_paytr_token(int $order_id): array {
  $order = site_get_order($order_id);
  if (!$order) {
    throw new RuntimeException('Sipariş bulunamadı.');
  }
  $package = dealer_package_get($order['package_id']);
  if (!$package) {
    throw new RuntimeException('Paket bulunamadı.');
  }
  $dealer = $order['dealer_id'] ? dealer_get((int)$order['dealer_id']) : null;

  if ($order['status'] === SITE_ORDER_STATUS_COMPLETED && $order['event_id']) {
    return [
      'order' => $order,
      'package' => $package,
      'dealer' => $dealer,
      'token' => $order['paytr_token'] ?? null,
      'merchant_oid' => $order['merchant_oid'] ?? null,
    ];
  }

  $email = $order['customer_email'];
  $user_name = mb_substr($order['customer_name'], 0, 64, 'UTF-8');
  $user_address = 'Online Sipariş';
  $user_phone = $order['customer_phone'] ?: '—';

  $testMode = paytr_is_test_mode();

  $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
     ?? $_SERVER['HTTP_X_FORWARDED_FOR']
     ?? $_SERVER['REMOTE_ADDR']
     ?? '1.2.3.4';
  if (strpos($ip, ',') !== false) {
    $ip = trim(explode(',', $ip)[0]);
  }
  if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    $ip = '1.2.3.4';
  }

  $amount_cents = max(0, (int)$order['price_cents']);
  if ($amount_cents <= 0) {
    throw new RuntimeException('Ödeme tutarı geçersiz.');
  }

  $basket = [[
    $package['name'],
    number_format($amount_cents / 100, 2, '.', ''),
    1,
  ]];
  $user_basket = base64_encode(json_encode($basket, JSON_UNESCAPED_UNICODE));

  $merchantOid = $order['merchant_oid'] ?: site_generate_order_oid($order['id']);
  if ($testMode) {
    $payload = [
      'request' => [
        'amount_cents' => $amount_cents,
        'basket'       => $basket,
        'ip'           => $ip,
      ],
      'merchant_oid' => $merchantOid,
      'test_mode'    => true,
      'note'         => 'Ödeme test modunda simüle edildi.',
    ];
    $now = now();
    pdo()->prepare("UPDATE site_orders SET merchant_oid=?, paytr_token=?, status=?, payload_json=?, paid_at=?, updated_at=? WHERE id=?")
        ->execute([
          $merchantOid,
          null,
          SITE_ORDER_STATUS_PAID,
          safe_json_encode($payload),
          $now,
          $now,
          $order['id'],
        ]);
    $result = site_finalize_order($order['id'], ['payload' => ['status' => 'success', 'test_mode' => true]]);
    $order = site_get_order($order['id']);
    return [
      'order' => $order,
      'package' => $package,
      'dealer' => $dealer,
      'token' => null,
      'merchant_oid' => $merchantOid,
      'test_mode' => true,
      'result' => $result,
    ];
  }

  $no_installment = 0;
  $max_installment = 0;
  $currency = 'TL';
  $test = (int)PAYTR_TEST_MODE;
  $hash_str = PAYTR_MERCHANT_ID . $ip . $merchantOid . $email . $amount_cents . $user_basket . $no_installment . $max_installment . $currency . $test;
  $paytr_token = base64_encode(hash_hmac('sha256', $hash_str . PAYTR_MERCHANT_SALT, PAYTR_MERCHANT_KEY, true));

  $post = [
    'merchant_id'          => PAYTR_MERCHANT_ID,
    'user_ip'              => $ip,
    'merchant_oid'         => $merchantOid,
    'email'                => $email,
    'payment_amount'       => $amount_cents,
    'paytr_token'          => $paytr_token,
    'user_basket'          => $user_basket,
    'no_installment'       => $no_installment,
    'max_installment'      => $max_installment,
    'user_name'            => $user_name,
    'user_address'         => $user_address,
    'user_phone'           => $user_phone,
    'merchant_ok_url'      => PAYTR_SITE_OK_URL,
    'merchant_fail_url'    => PAYTR_SITE_FAIL_URL,
    'merchant_callback_url'=> PAYTR_CALLBACK_URL,
    'timeout_limit'        => 30,
    'currency'             => $currency,
    'test_mode'            => $test,
  ];

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL            => 'https://www.paytr.com/odeme/api/get-token',
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_POST           => 1,
    CURLOPT_POSTFIELDS     => $post,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => 1,
  ]);
  $res = curl_exec($ch);
  $curlErr = curl_errno($ch) ? curl_error($ch) : null;
  curl_close($ch);

  if ($curlErr) {
    throw new RuntimeException('PAYTR bağlantı hatası: '.$curlErr);
  }
  $data = json_decode((string)$res, true);
  if (!$data || ($data['status'] ?? '') !== 'success') {
    $reason = $data['reason'] ?? 'bilinmiyor';
    throw new RuntimeException('PAYTR token alınamadı: '.$reason);
  }

  $token = $data['token'];
  $payload = [
    'request' => [
      'amount_cents' => $amount_cents,
      'basket'       => $basket,
      'ip'           => $ip,
    ],
    'merchant_oid' => $merchantOid,
  ];

  pdo()->prepare("UPDATE site_orders SET merchant_oid=?, paytr_token=?, status=?, payload_json=?, updated_at=? WHERE id=?")
      ->execute([
        $merchantOid,
        $token,
        SITE_ORDER_STATUS_AWAITING,
        safe_json_encode($payload),
        now(),
        $order['id'],
      ]);

  $order = site_get_order($order['id']);

  return [
    'order' => $order,
    'package' => $package,
    'dealer' => $dealer,
    'token' => $token,
    'merchant_oid' => $merchantOid,
  ];
}

function site_handle_paytr_callback(string $merchant_oid, string $status, array $payload = []): void {
  $merchant_oid = trim($merchant_oid);
  if ($merchant_oid === '') {
    return;
  }
  $pdo = pdo();
  $pdo->beginTransaction();
  $st = $pdo->prepare("SELECT * FROM site_orders WHERE merchant_oid=? FOR UPDATE");
  $st->execute([$merchant_oid]);
  $row = $st->fetch();
  if (!$row) {
    $pdo->commit();
    return;
  }
  $order = site_order_normalize_row($row);
  $now = now();
  $payloadData = $order['payload'];
  if (!is_array($payloadData)) {
    $payloadData = [];
  }
  if (!isset($payloadData['callbacks']) || !is_array($payloadData['callbacks'])) {
    $payloadData['callbacks'] = [];
  }
  $payloadData['callbacks'][] = [
    'status' => $status,
    'received_at' => $now,
    'body' => $payload,
  ];
  $reference = $payload['payment_id'] ?? ($payload['merchant_oid'] ?? null);

  $newStatus = $order['status'];
  $paidAt = $order['paid_at'];
  if ($status === 'success') {
    $newStatus = SITE_ORDER_STATUS_PAID;
    if (!$paidAt) {
      $paidAt = $now;
    }
  } elseif ($status === 'failed') {
    $newStatus = SITE_ORDER_STATUS_FAILED;
  }

  $pdo->prepare("UPDATE site_orders SET status=?, paytr_reference=?, paid_at=?, payload_json=?, updated_at=? WHERE id=?")
      ->execute([
        $newStatus,
        $reference ?: ($order['paytr_reference'] ?? null),
        $paidAt,
        safe_json_encode($payloadData),
        $now,
        $order['id'],
      ]);
  $pdo->commit();

  if ($status === 'success') {
    site_finalize_order($order['id'], ['payload' => $payload]);
  }
}

function site_fetch_event_summary(int $event_id): array {
  $st = pdo()->prepare("SELECT id, venue_id, title, couple_username, couple_panel_key, contact_email FROM events WHERE id=? LIMIT 1");
  $st->execute([$event_id]);
  $row = $st->fetch();
  if (!$row) {
    throw new RuntimeException('Etkinlik bulunamadı.');
  }
  $row['id'] = (int)$row['id'];
  $row['venue_id'] = (int)$row['venue_id'];
  return $row;
}

function site_event_dynamic_code(int $event_id): ?string {
  $st = pdo()->prepare("SELECT code FROM qr_codes WHERE target_event_id=? ORDER BY id DESC LIMIT 1");
  $st->execute([$event_id]);
  $code = $st->fetchColumn();
  return $code ? (string)$code : null;
}

function site_finalize_order(int $order_id, array $options = []): array {
  $pdo = pdo();
  $pdo->beginTransaction();
  $st = $pdo->prepare("SELECT * FROM site_orders WHERE id=? FOR UPDATE");
  $st->execute([$order_id]);
  $row = $st->fetch();
  if (!$row) {
    $pdo->rollBack();
    throw new RuntimeException('Sipariş bulunamadı.');
  }
  $order = site_order_normalize_row($row);
  $package = dealer_package_get($order['package_id']);
  if (!$package) {
    $pdo->rollBack();
    throw new RuntimeException('Paket bulunamadı.');
  }
  $dealer = $order['dealer_id'] ? dealer_get((int)$order['dealer_id']) : null;
  $meta = is_array($order['meta']) ? $order['meta'] : [];
  $payloadData = is_array($order['payload']) ? $order['payload'] : [];
  $now = now();

  if (!empty($options['payload'])) {
    if (!isset($payloadData['callbacks']) || !is_array($payloadData['callbacks'])) {
      $payloadData['callbacks'] = [];
    }
    $payloadData['callbacks'][] = [
      'status' => 'finalize',
      'received_at' => $now,
      'body' => $options['payload'],
    ];
  }

  $alreadyCompleted = $order['status'] === SITE_ORDER_STATUS_COMPLETED && $order['event_id'];
  $justCreated = false;
  $eventSummary = null;
  $eventData = null;

  if ($order['event_id']) {
    $eventSummary = site_fetch_event_summary($order['event_id']);
  }

  if (!$alreadyCompleted) {
    if (!$order['event_id']) {
      $venueId = $dealer ? (dealer_primary_venue_id((int)$dealer['id']) ?? site_default_sales_venue_id()) : site_default_sales_venue_id();
      $eventData = site_create_event($venueId, $dealer ? (int)$dealer['id'] : null, $package, [
        'event_title' => $order['event_title'],
        'event_date' => $order['event_date'],
        'customer_email' => $order['customer_email'],
      ]);
      $order['event_id'] = $eventData['id'];
      $eventSummary = site_fetch_event_summary($order['event_id']);
      $meta['plain_password'] = $eventData['plain_password'];
      $meta['qr_code'] = $eventData['qr_code'];
      $meta['upload_url'] = $eventData['upload_url'];
      $justCreated = true;
    } elseif (!$eventSummary) {
      $eventSummary = site_fetch_event_summary($order['event_id']);
    }

    $paidAt = $order['paid_at'] ?: $now;

    $pdo->prepare("UPDATE site_orders SET status=?, event_id=?, paid_at=?, meta_json=?, payload_json=?, updated_at=? WHERE id=?")
        ->execute([
          SITE_ORDER_STATUS_COMPLETED,
          $order['event_id'],
          $paidAt,
          $meta ? safe_json_encode($meta) : null,
          $payloadData ? safe_json_encode($payloadData) : null,
          $now,
          $order['id'],
        ]);

    $order['status'] = SITE_ORDER_STATUS_COMPLETED;
    $order['paid_at'] = $paidAt;
    $order['meta'] = $meta;
    $order['payload'] = $payloadData;

    if ($dealer && $order['cashback_cents'] > 0) {
      $existsSt = $pdo->prepare("SELECT id FROM dealer_package_purchases WHERE dealer_id=? AND lead_event_id=? AND source=? LIMIT 1");
      $existsSt->execute([(int)$dealer['id'], $order['event_id'], DEALER_PURCHASE_SOURCE_LEAD]);
      $exists = $existsSt->fetchColumn();
      if (!$exists) {
        dealer_wallet_adjust((int)$dealer['id'], (int)$order['cashback_cents'], DEALER_WALLET_TYPE_CASHBACK, 'Web satış cashback', [
          'order_id' => $order['id'],
          'package_id' => $order['package_id'],
        ]);
        $duration = $package['duration_days'];
        $startsAt = $now;
        $expiresAt = null;
        if ($duration) {
          $dt = new DateTime($now);
          $dt->modify('+'.(int)$duration.' days');
          $expiresAt = $dt->format('Y-m-d H:i:s');
        }
        $pdo->prepare(
          "INSERT INTO dealer_package_purchases (dealer_id, package_id, status, price_cents, event_quota, events_used, duration_days, starts_at, expires_at, cashback_rate, cashback_status, cashback_amount, cashback_paid_at, lead_event_id, source, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
          (int)$dealer['id'],
          $order['package_id'],
          DEALER_PURCHASE_STATUS_USED,
          $order['price_cents'],
          1,
          1,
          $package['duration_days'],
          $startsAt,
          $expiresAt,
          $package['cashback_rate'],
          DEALER_CASHBACK_PAID,
          $order['cashback_cents'],
          $now,
          $order['event_id'],
          DEALER_PURCHASE_SOURCE_LEAD,
          $now,
          $now,
        ]);
      }
    }
  }

  if (!$eventSummary) {
    $pdo->rollBack();
    throw new RuntimeException('Etkinlik bilgisi alınamadı.');
  }

  $pdo->commit();

  $eventMeta = [
    'id' => $eventSummary['id'],
    'title' => $order['event_title'],
    'upload_url' => $meta['upload_url'] ?? public_upload_url($eventSummary['id']),
    'qr_code' => $meta['qr_code'] ?? site_event_dynamic_code($eventSummary['id']),
    'plain_password' => $meta['plain_password'] ?? null,
    'login_url' => BASE_URL.'/couple/login.php?event='.$eventSummary['id'],
  ];
  $eventMeta['qr_dynamic_url'] = $eventMeta['qr_code'] ? BASE_URL.'/qr.php?code='.$eventMeta['qr_code'] : null;
  $qrTarget = $eventMeta['qr_dynamic_url'] ?: $eventMeta['upload_url'];
  $eventMeta['qr_image_url'] = $qrTarget ? 'https://api.qrserver.com/v1/create-qr-code/?size=500x500&data='.rawurlencode($qrTarget) : null;

  $result = [
    'order' => $order,
    'package' => $package,
    'dealer' => $dealer,
    'event' => $eventMeta,
    'customer' => [
      'name' => $order['customer_name'],
      'email' => $order['customer_email'],
      'phone' => $order['customer_phone'] ?? '',
    ],
    'cashback_cents' => (int)$order['cashback_cents'],
    'just_created' => $justCreated,
  ];

  if ($justCreated) {
    site_send_customer_order_mail($result);
    if ($dealer) {
      site_send_dealer_order_mail($result);
    }
  }

  return $result;
}

function site_send_customer_order_mail(array $result): void {
  $event = $result['event'];
  $customer = $result['customer'];
  $package = $result['package'];
  $plainPassword = $event['plain_password'] ?? null;
  $uploadUrl = $event['upload_url'];
  $dynamicUrl = $event['qr_dynamic_url'];
  $qrImage = $event['qr_image_url'];
  $loginUrl = $event['login_url'];

  $html = '<h2>'.h(APP_NAME).' — Etkinliğiniz Hazır</h2>'
    .'<p>Merhaba '.h($customer['name']).',</p>'
    .'<p>Ödemeniz onaylandı ve etkinlik paneliniz oluşturuldu.</p>'
    .'<ul>'
    .'<li><strong>Giriş adresi:</strong> <a href="'.h($loginUrl).'">'.h($loginUrl).'</a></li>'
    .'<li><strong>Kullanıcı adı:</strong> '.h($customer['email']).'</li>';
  if ($plainPassword) {
    $html .= '<li><strong>Geçici şifre:</strong> '.h($plainPassword).'</li>';
  }
  $html .= '</ul>'
    .'<p>Misafir yükleme bağlantınız: <a href="'.h($uploadUrl).'">'.h($uploadUrl).'</a></p>';
  if ($dynamicUrl) {
    $html .= '<p>Kalıcı QR yönlendirme adresiniz: <a href="'.h($dynamicUrl).'">'.h($dynamicUrl).'</a></p>';
  }
  if ($qrImage) {
    $html .= '<p>QR kodu yazdırmak için aşağıdaki görseli kullanabilirsiniz:</p>'
          . '<p><img src="'.h($qrImage).'" alt="QR Kod" width="220" height="220"></p>';
  }
  $html .= '<p>Paketiniz: '.h($package['name']).' — '.h(format_currency((int)$package['price_cents'])) .'</p>'
         . '<p>Keyifli bir etkinlik dileriz!<br>'.h(APP_NAME).' Ekibi</p>';

  send_mail_simple($customer['email'], 'Wedding Share etkinliğiniz hazır', $html);
}

function site_send_dealer_order_mail(array $result): void {
  $dealer = $result['dealer'];
  if (!$dealer) {
    return;
  }
  $customer = $result['customer'];
  $package = $result['package'];
  $event = $result['event'];
  $dynamicUrl = $event['qr_dynamic_url'];
  $uploadUrl = $event['upload_url'];
  $loginUrl = $event['login_url'];
  $cashback = (int)$result['cashback_cents'];

  $html = '<h2>Yeni Web Satışı</h2>'
    .'<p><strong>Müşteri:</strong> '.h($customer['name']).'<br>'
    .'<strong>E-posta:</strong> '.h($customer['email']).'<br>';
  if (!empty($customer['phone'])) {
    $html .= '<strong>Telefon:</strong> '.h($customer['phone']).'<br>';
  }
  $html .= '<strong>Paket:</strong> '.h($package['name']).' — '.h(format_currency((int)$package['price_cents'])).'</p>'
    .'<p><strong>Etkinlik paneli:</strong> <a href="'.h($loginUrl).'">'.h($loginUrl).'</a><br>'
    .'<strong>Misafir yükleme:</strong> <a href="'.h($uploadUrl).'">'.h($uploadUrl).'</a></p>';
  if ($dynamicUrl) {
    $html .= '<p><strong>QR 301 adresi:</strong> <a href="'.h($dynamicUrl).'">'.h($dynamicUrl).'</a></p>';
  }
  if ($cashback > 0) {
    $html .= '<p><strong>Cashback:</strong> '.h(format_currency($cashback)).' hesabınıza tanımlandı.</p>';
  }
  $html .= '<p>Referans satışınız için teşekkür ederiz.</p>';

  send_mail_simple($dealer['email'], 'Referans satışınız tamamlandı', $html);
}
