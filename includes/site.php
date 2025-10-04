<?php
/**
 * includes/site.php — Genel web sitesi satış & sipariş yardımcıları
 */
require_once __DIR__.'/../config.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/dealers.php';

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
    $placeholdersFallback = "?,?,?,?,?,?,?,?,?,?,?,?,?";
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

function site_process_customer_order(array $input): array {
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
  $pdo->beginTransaction();
  try {
    $now = now();
    $price = (int)$package['price_cents'];
    $cashbackRate = $dealer ? max(0, (float)$package['cashback_rate']) : 0.0;
    $cashbackCents = $dealer ? (int)round($price * $cashbackRate) : 0;
    $meta = array_filter([
      'notes' => $notes !== '' ? $notes : null,
    ]);
    $metaJson = $meta ? safe_json_encode($meta) : null;
    $pdo->prepare("INSERT INTO site_orders (package_id, dealer_id, customer_name, customer_email, customer_phone, event_title, event_date, referral_code, status, price_cents, cashback_cents, meta_json, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([
          $packageId,
          $dealer ? (int)$dealer['id'] : null,
          $customerName,
          $customerEmail,
          $customerPhone !== '' ? $customerPhone : null,
          $eventTitle,
          site_normalize_event_date($eventDate),
          $referral !== '' ? $referral : null,
          'processing',
          $price,
          $cashbackCents,
          $metaJson,
          $now,
          $now,
        ]);
    $orderId = (int)$pdo->lastInsertId();
    $venueId = $dealer ? (dealer_primary_venue_id((int)$dealer['id']) ?? site_default_sales_venue_id()) : site_default_sales_venue_id();
    $event = site_create_event($venueId, $dealer ? (int)$dealer['id'] : null, $package, [
      'event_title' => $eventTitle,
      'event_date' => $eventDate,
      'customer_email' => $customerEmail,
    ]);
    if ($orderId && $event['id']) {
      $pdo->prepare("UPDATE site_orders SET status=?, event_id=?, updated_at=? WHERE id=?")
          ->execute(['completed', $event['id'], now(), $orderId]);
    }
    if ($dealer && $cashbackCents > 0) {
      dealer_wallet_adjust((int)$dealer['id'], $cashbackCents, DEALER_WALLET_TYPE_CASHBACK, 'Web satış cashback', [
        'order_id' => $orderId,
        'package_id' => $packageId,
      ]);
      $duration = $package['duration_days'];
      $startsAt = $now;
      $expiresAt = null;
      if ($duration) {
        $dt = new DateTime($now);
        $dt->modify('+'.(int)$duration.' days');
        $expiresAt = $dt->format('Y-m-d H:i:s');
      }
      $pdo->prepare("INSERT INTO dealer_package_purchases (dealer_id, package_id, status, price_cents, event_quota, events_used, duration_days, starts_at, expires_at, cashback_rate, cashback_status, cashback_amount, cashback_paid_at, lead_event_id, source, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
          ->execute([
            (int)$dealer['id'],
            $packageId,
            DEALER_PURCHASE_STATUS_USED,
            $price,
            1,
            1,
            $duration,
            $startsAt,
            $expiresAt,
            $cashbackRate,
            DEALER_CASHBACK_PAID,
            $cashbackCents,
            $now,
            $event['id'],
            DEALER_PURCHASE_SOURCE_LEAD,
            $now,
            $now,
          ]);
    }
    $pdo->commit();
    return [
      'order_id' => $orderId,
      'package' => $package,
      'dealer' => $dealer,
      'event' => $event,
      'customer' => [
        'name' => $customerName,
        'email' => $customerEmail,
        'phone' => $customerPhone,
      ],
      'cashback_cents' => $dealer ? $cashbackCents : 0,
      'referral_code' => $referral,
    ];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}
