<?php
/**
 * includes/dealers.php — Bayi (franchise) yardımcı fonksiyonları
 */
require_once __DIR__.'/../config.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';

const DEALER_STATUS_PENDING = 'pending';
const DEALER_STATUS_ACTIVE  = 'active';
const DEALER_STATUS_INACTIVE= 'inactive';

const DEALER_CODE_STATIC = 'static';
const DEALER_CODE_TRIAL  = 'trial';

function dealer_generate_identifier_candidate(): string {
  return 'B'.str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function dealer_identifier_exists(string $code, ?int $ignoreId = null): bool {
  $sql = "SELECT 1 FROM dealers WHERE code=?";
  $params = [$code];
  if ($ignoreId) {
    $sql .= " AND id<>?";
    $params[] = $ignoreId;
  }
  $sql .= " LIMIT 1";
  $st = pdo()->prepare($sql);
  $st->execute($params);
  return (bool)$st->fetchColumn();
}

function dealer_generate_unique_identifier(?int $ignoreId = null): string {
  do {
    $candidate = dealer_generate_identifier_candidate();
  } while (dealer_identifier_exists($candidate, $ignoreId));
  return $candidate;
}

function dealer_ensure_identifier(int $dealer_id): string {
  $st = pdo()->prepare("SELECT code FROM dealers WHERE id=? LIMIT 1");
  $st->execute([$dealer_id]);
  $code = $st->fetchColumn();
  if ($code) {
    return $code;
  }
  $code = dealer_generate_unique_identifier();
  pdo()->prepare("UPDATE dealers SET code=?, updated_at=COALESCE(updated_at, now()) WHERE id=?")
      ->execute([$code, $dealer_id]);
  return $code;
}

function dealer_backfill_codes(): void {
  $rows = pdo()->query("SELECT id FROM dealers WHERE code IS NULL OR code=''" )->fetchAll();
  foreach ($rows as $row) {
    dealer_ensure_identifier((int)$row['id']);
  }
}

/** Benzersiz kod üret (harf + rakam, karışık). */
function dealer_generate_code_string(int $length = 8): string {
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  $out = '';
  $max = strlen($alphabet) - 1;
  for ($i = 0; $i < $length; $i++) {
    $out .= $alphabet[random_int(0, $max)];
  }
  return $out;
}

function dealer_generate_unique_code(): string {
  while (true) {
    $code = dealer_generate_code_string(8);
    $st = pdo()->prepare("SELECT 1 FROM dealer_codes WHERE code=? LIMIT 1");
    $st->execute([$code]);
    if ($st->fetch()) continue;
    // qr_codes ile çakışmasın
    $st2 = pdo()->prepare("SELECT 1 FROM qr_codes WHERE code=? LIMIT 1");
    $st2->execute([$code]);
    if ($st2->fetch()) continue;
    return $code;
  }
}

function dealer_ensure_codes(int $dealer_id): array {
  $types = [DEALER_CODE_STATIC, DEALER_CODE_TRIAL];
  foreach ($types as $type) {
    $st = pdo()->prepare("SELECT id FROM dealer_codes WHERE dealer_id=? AND type=? LIMIT 1");
    $st->execute([$dealer_id, $type]);
    if (!$st->fetch()) {
      $code = dealer_generate_unique_code();
      pdo()->prepare("INSERT INTO dealer_codes (dealer_id,type,code,created_at) VALUES (?,?,?,?)")
          ->execute([$dealer_id, $type, $code, now()]);
    }
  }
  return dealer_get_codes($dealer_id);
}

function dealer_get_codes(int $dealer_id): array {
  $st = pdo()->prepare("SELECT * FROM dealer_codes WHERE dealer_id=? ORDER BY type");
  $st->execute([$dealer_id]);
  $rows = [];
  foreach ($st->fetchAll() as $row) {
    $rows[$row['type']] = $row;
  }
  return $rows;
}

function dealer_regenerate_code(int $dealer_id, string $type): string {
  $type = $type === DEALER_CODE_TRIAL ? DEALER_CODE_TRIAL : DEALER_CODE_STATIC;
  $code = dealer_generate_unique_code();
  pdo()->prepare("UPDATE dealer_codes SET code=?, updated_at=?, target_event_id=NULL WHERE dealer_id=? AND type=?")
      ->execute([$code, now(), $dealer_id, $type]);
  return $code;
}

function dealer_set_code_target(int $dealer_id, string $type, ?int $event_id): void {
  $type = $type === DEALER_CODE_TRIAL ? DEALER_CODE_TRIAL : DEALER_CODE_STATIC;
  pdo()->prepare("UPDATE dealer_codes SET target_event_id=?, updated_at=? WHERE dealer_id=? AND type=?")
      ->execute([$event_id ?: null, now(), $dealer_id, $type]);
}

function dealer_get(int $dealer_id): ?array {
  $st = pdo()->prepare("SELECT * FROM dealers WHERE id=? LIMIT 1");
  $st->execute([$dealer_id]);
  $row = $st->fetch();
  if (!$row) return null;
  if (empty($row['code'])) {
    $row['code'] = dealer_ensure_identifier((int)$row['id']);
  }
  return $row;
}

function dealer_find_by_email(string $email): ?array {
  $st = pdo()->prepare("SELECT * FROM dealers WHERE email=? LIMIT 1");
  $st->execute([$email]);
  $row = $st->fetch();
  return $row ?: null;
}

function dealer_status_badge(string $status): string {
  return match ($status) {
    DEALER_STATUS_ACTIVE   => 'Aktif',
    DEALER_STATUS_INACTIVE => 'Pasif',
    default                => 'Onay Bekliyor',
  };
}

function dealer_has_valid_license(array $dealer): bool {
  if (empty($dealer['license_expires_at'])) return false;
  $exp = new DateTime($dealer['license_expires_at']);
  $now = new DateTime('now');
  return $exp >= $now;
}

function dealer_license_label(array $dealer): string {
  if (empty($dealer['license_expires_at'])) {
    return 'Tanımlı değil';
  }
  $exp = new DateTime($dealer['license_expires_at']);
  return $exp->format('d.m.Y H:i');
}

function dealer_assign_venues(int $dealer_id, array $venue_ids): void {
  $venue_ids = array_map('intval', $venue_ids);
  $venue_ids = array_values(array_unique(array_filter($venue_ids)));
  $pdo = pdo();
  $pdo->beginTransaction();
  try {
    if ($venue_ids) {
      $ph = implode(',', array_fill(0, count($venue_ids), '?'));
      $params = array_merge([$dealer_id], $venue_ids);
      $pdo->prepare("DELETE FROM dealer_venues WHERE dealer_id=? AND venue_id NOT IN ($ph)")
          ->execute($params);
    } else {
      $pdo->prepare("DELETE FROM dealer_venues WHERE dealer_id=?")->execute([$dealer_id]);
    }

    $existing = $pdo->prepare("SELECT venue_id FROM dealer_venues WHERE dealer_id=?");
    $existing->execute([$dealer_id]);
    $current = array_map('intval', array_column($existing->fetchAll(), 'venue_id'));
    foreach ($venue_ids as $vid) {
      if (!in_array($vid, $current, true)) {
        $pdo->prepare("INSERT INTO dealer_venues (dealer_id, venue_id, created_at) VALUES (?,?,?)")
            ->execute([$dealer_id, $vid, now()]);
      }
    }
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}

function dealer_assign_dealers_to_venue(int $venue_id, array $dealer_ids): void {
  $dealer_ids = array_map('intval', $dealer_ids);
  $dealer_ids = array_values(array_unique(array_filter($dealer_ids)));
  $pdo = pdo();
  $pdo->beginTransaction();
  try {
    if ($dealer_ids) {
      $ph = implode(',', array_fill(0, count($dealer_ids), '?'));
      $params = array_merge([$venue_id], $dealer_ids);
      $pdo->prepare("DELETE FROM dealer_venues WHERE venue_id=? AND dealer_id NOT IN ($ph)")
          ->execute($params);
    } else {
      $pdo->prepare("DELETE FROM dealer_venues WHERE venue_id=?")->execute([$venue_id]);
    }

    $existing = $pdo->prepare("SELECT dealer_id FROM dealer_venues WHERE venue_id=?");
    $existing->execute([$venue_id]);
    $current = array_map('intval', array_column($existing->fetchAll(), 'dealer_id'));
    foreach ($dealer_ids as $did) {
      if (!in_array($did, $current, true)) {
        $pdo->prepare("INSERT INTO dealer_venues (dealer_id, venue_id, created_at) VALUES (?,?,?)")
            ->execute([$did, $venue_id, now()]);
      }
    }
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}

function dealer_fetch_venue_assignments(): array {
  $venues = pdo()->query("SELECT * FROM venues ORDER BY name")->fetchAll();
  $map = [];
  foreach ($venues as $venue) {
    $map[$venue['id']] = [
      'venue' => $venue,
      'dealers' => [],
    ];
  }

  $sql = "SELECT dv.venue_id, d.id AS dealer_id, d.name, d.code, d.status"
       . " FROM dealer_venues dv"
       . " INNER JOIN dealers d ON d.id=dv.dealer_id"
       . " ORDER BY dv.venue_id, d.name";
  foreach (pdo()->query($sql) as $row) {
    if (!isset($map[$row['venue_id']])) continue;
    $map[$row['venue_id']]['dealers'][] = [
      'id' => (int)$row['dealer_id'],
      'name' => $row['name'],
      'code' => $row['code'],
      'status' => $row['status'],
    ];
  }
  return array_values($map);
}

function dealer_status_class(string $status): string {
  return match ($status) {
    DEALER_STATUS_ACTIVE => 'status-active',
    DEALER_STATUS_INACTIVE => 'status-inactive',
    default => 'status-pending',
  };
}

function dealer_fetch_venues(int $dealer_id, bool $onlyActive = false): array {
  $sql = "SELECT v.* FROM venues v INNER JOIN dealer_venues dv ON dv.venue_id=v.id WHERE dv.dealer_id=?";
  if ($onlyActive) {
    $sql .= " AND v.is_active=1";
  }
  $sql .= " ORDER BY v.name";
  $st = pdo()->prepare($sql);
  $st->execute([$dealer_id]);
  return $st->fetchAll();
}

function dealer_event_belongs_to_dealer(int $dealer_id, int $event_id): bool {
  $st = pdo()->prepare("SELECT 1 FROM events e INNER JOIN dealer_venues dv ON dv.venue_id=e.venue_id WHERE e.id=? AND dv.dealer_id=? LIMIT 1");
  $st->execute([$event_id, $dealer_id]);
  return (bool)$st->fetchColumn();
}

function dealer_allowed_events(int $dealer_id): array {
  $st = pdo()->prepare("SELECT e.* FROM events e INNER JOIN dealer_venues dv ON dv.venue_id=e.venue_id WHERE dv.dealer_id=? ORDER BY e.event_date DESC, e.id DESC");
  $st->execute([$dealer_id]);
  return $st->fetchAll();
}

function dealer_sync_codes(int $dealer_id): array {
  dealer_ensure_codes($dealer_id);
  return dealer_get_codes($dealer_id);
}

function dealer_notify_new_application(array $dealer): void {
  $to = defined('MAIL_FROM') && MAIL_FROM ? MAIL_FROM : 'info@localhost';
  $subject = 'Yeni bayi başvurusu';
  $html = '<h3>'.h(APP_NAME).' — Yeni Bayi Başvurusu</h3>'
        . '<p><strong>Ad:</strong> '.h($dealer['name']).'</p>'
        . '<p><strong>E-posta:</strong> '.h($dealer['email']).'</p>';
  if (!empty($dealer['phone'])) {
    $html .= '<p><strong>Telefon:</strong> '.h($dealer['phone']).'</p>';
  }
  if (!empty($dealer['company'])) {
    $html .= '<p><strong>Firma:</strong> '.h($dealer['company']).'</p>';
  }
  if (!empty($dealer['notes'])) {
    $html .= '<p><strong>Not:</strong><br>'.nl2br(h($dealer['notes'])).'</p>';
  }
  send_mail_simple($to, $subject, $html);
}

function dealer_send_application_receipt(array $dealer): void {
  $html = '<h3>'.h(APP_NAME).' Bayi Başvurusu</h3>'
        . '<p>Başvurunuz alınmıştır. İnceleme sonrasında size dönüş yapılacaktır.</p>';
  send_mail_simple($dealer['email'], 'Başvurunuz alındı', $html);
}

function dealer_random_password(int $length = 10): string {
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
  $out = '';
  $max = strlen($alphabet) - 1;
  for ($i=0; $i<$length; $i++) {
    $out .= $alphabet[random_int(0, $max)];
  }
  return $out;
}

function dealer_send_welcome_mail(array $dealer, string $plain_password): void {
  $loginUrl = BASE_URL.'/dealer/login.php';
  $html = '<h3>'.h(APP_NAME).' Bayi Paneli</h3>'
        . '<p>Bayilik başvurunuz onaylandı.</p>'
        . '<p><strong>Giriş E-postası:</strong> '.h($dealer['email']).'<br>'
        . '<strong>Geçici Şifre:</strong> '.h($plain_password).'</p>'
        . '<p><a href="'.h($loginUrl).'">Panele giriş yapın</a> ve ilk girişte şifrenizi değiştirin.</p>';
  send_mail_simple($dealer['email'], 'Bayilik paneliniz hazır', $html);
}

function dealer_update_last_login(int $dealer_id): void {
  pdo()->prepare("UPDATE dealers SET last_login_at=?, updated_at=? WHERE id=?")
      ->execute([now(), now(), $dealer_id]);
}

function dealer_license_warning(array $dealer): ?string {
  if (empty($dealer['license_expires_at'])) {
    return 'Lisans süresi tanımlı değil.';
  }
  $exp = new DateTime($dealer['license_expires_at']);
  $now = new DateTime('now');
  if ($exp < $now) {
    return 'Lisans süresi dolmuştur.';
  }
  $diff = $now->diff($exp);
  if ($diff->days !== false && $diff->days <= 14) {
    return 'Lisans süresi yakında ('.max(0, $diff->days).' gün) dolacak.';
  }
  return null;
}

function dealer_fetch_assigned_event_ids(int $dealer_id): array {
  $st = pdo()->prepare("SELECT e.id FROM events e INNER JOIN dealer_venues dv ON dv.venue_id=e.venue_id WHERE dv.dealer_id=?");
  $st->execute([$dealer_id]);
  return array_map('intval', array_column($st->fetchAll(), 'id'));
}
