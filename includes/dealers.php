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

const DEALER_PURCHASE_STATUS_ACTIVE   = 'active';
const DEALER_PURCHASE_STATUS_USED     = 'used';
const DEALER_PURCHASE_STATUS_EXPIRED  = 'expired';
const DEALER_PURCHASE_STATUS_PENDING  = 'pending';
const DEALER_PURCHASE_STATUS_CANCELLED= 'cancelled';

const DEALER_CASHBACK_NONE      = 'none';
const DEALER_CASHBACK_AWAITING  = 'awaiting_event';
const DEALER_CASHBACK_PENDING   = 'pending';
const DEALER_CASHBACK_PAID      = 'paid';

const DEALER_TOPUP_STATUS_PENDING         = 'pending';
const DEALER_TOPUP_STATUS_AWAITING_REVIEW = 'awaiting_review';
const DEALER_TOPUP_STATUS_COMPLETED       = 'completed';
const DEALER_TOPUP_STATUS_CANCELLED       = 'cancelled';

const DEALER_WALLET_TYPE_TOPUP      = 'topup';
const DEALER_WALLET_TYPE_PURCHASE   = 'purchase';
const DEALER_WALLET_TYPE_CASHBACK   = 'cashback';
const DEALER_WALLET_TYPE_ADJUSTMENT = 'adjustment';
const DEALER_WALLET_TYPE_REFUND     = 'refund';
const DEALER_WALLET_TYPE_TOPUP_REQ  = 'topup_request';

const DEALER_PURCHASE_SOURCE_DEALER = 'dealer';
const DEALER_PURCHASE_SOURCE_LEAD   = 'lead';

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

function dealer_find_by_code(string $code): ?array {
  $code = trim($code);
  if ($code === '') {
    return null;
  }
  $st = pdo()->prepare("SELECT * FROM dealers WHERE code=? LIMIT 1");
  $st->execute([$code]);
  $row = $st->fetch();
  if ($row) {
    return $row;
  }
  $alt = pdo()->prepare("SELECT dealer_id FROM dealer_codes WHERE code=? LIMIT 1");
  $alt->execute([$code]);
  $dealerId = $alt->fetchColumn();
  if ($dealerId) {
    $st2 = pdo()->prepare("SELECT * FROM dealers WHERE id=? LIMIT 1");
    $st2->execute([(int)$dealerId]);
    $row = $st2->fetch();
    if ($row) {
      return $row;
    }
  }
  return null;
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

function dealer_primary_venue_id(int $dealer_id): ?int {
  $st = pdo()->prepare("SELECT v.id FROM venues v INNER JOIN dealer_venues dv ON dv.venue_id=v.id WHERE dv.dealer_id=? AND v.is_active=1 ORDER BY v.name LIMIT 1");
  $st->execute([$dealer_id]);
  $id = $st->fetchColumn();
  if ($id) {
    return (int)$id;
  }
  $fallback = pdo()->prepare("SELECT v.id FROM venues v INNER JOIN dealer_venues dv ON dv.venue_id=v.id WHERE dv.dealer_id=? ORDER BY v.name LIMIT 1");
  $fallback->execute([$dealer_id]);
  $alt = $fallback->fetchColumn();
  return $alt ? (int)$alt : null;
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

function dealer_get_balance(int $dealer_id): int {
  $st = pdo()->prepare("SELECT balance_cents FROM dealers WHERE id=? LIMIT 1");
  $st->execute([$dealer_id]);
  $value = $st->fetchColumn();
  return $value !== false ? (int)$value : 0;
}

function dealer_wallet_flow_totals(int $dealer_id): array {
  $st = pdo()->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN amount_cents > 0 THEN amount_cents ELSE 0 END), 0) AS total_in,
        COALESCE(SUM(CASE WHEN amount_cents < 0 THEN amount_cents ELSE 0 END), 0) AS total_out
     FROM dealer_wallet_transactions WHERE dealer_id=?"
  );
  $st->execute([$dealer_id]);
  $row = $st->fetch();
  $totalIn = (int)($row['total_in'] ?? 0);
  $totalOut = (int)($row['total_out'] ?? 0);
  return [
    'in' => $totalIn,
    'out' => abs($totalOut),
  ];
}

function dealer_wallet_adjust(int $dealer_id, int $amount_cents, string $type, string $description = '', array $meta = []): int {
  $pdo = pdo();
  $ownTxn = !$pdo->inTransaction();
  if ($ownTxn) {
    $pdo->beginTransaction();
  }
  try {
    $lock = $pdo->prepare("SELECT balance_cents FROM dealers WHERE id=? FOR UPDATE");
    $lock->execute([$dealer_id]);
    $row = $lock->fetch();
    if (!$row) {
      throw new RuntimeException('Bayi bulunamadı.');
    }
    $current = (int)$row['balance_cents'];
    $newBalance = $current + $amount_cents;
    if ($newBalance < 0) {
      throw new RuntimeException('Bayi bakiyesi yetersiz.');
    }
    $pdo->prepare("UPDATE dealers SET balance_cents=?, updated_at=? WHERE id=?")
        ->execute([$newBalance, now(), $dealer_id]);
    $metaJson = $meta ? safe_json_encode($meta) : null;
    $pdo->prepare("INSERT INTO dealer_wallet_transactions (dealer_id,type,amount_cents,balance_after,description,meta_json,created_at) VALUES (?,?,?,?,?,?,?)")
        ->execute([
          $dealer_id,
          $type,
          $amount_cents,
          $newBalance,
          $description !== '' ? $description : null,
          $metaJson,
          now(),
        ]);
    if ($ownTxn) {
      $pdo->commit();
    }
    return $newBalance;
  } catch (Throwable $e) {
    if ($ownTxn && $pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}

function dealer_wallet_transactions(int $dealer_id, int $limit = 20): array {
  $limit = max(1, (int)$limit);
  $sql = "SELECT * FROM dealer_wallet_transactions WHERE dealer_id=? ORDER BY id DESC LIMIT $limit";
  $st = pdo()->prepare($sql);
  $st->execute([$dealer_id]);
  $rows = $st->fetchAll();
  foreach ($rows as &$row) {
    $row['amount_cents'] = (int)$row['amount_cents'];
    $row['balance_after'] = (int)$row['balance_after'];
    $row['meta'] = !empty($row['meta_json']) ? safe_json_decode($row['meta_json']) : null;
  }
  return $rows;
}

function dealer_total_cashback(int $dealer_id): int {
  $st = pdo()->prepare("SELECT COALESCE(SUM(amount_cents),0) FROM dealer_wallet_transactions WHERE dealer_id=? AND type=?");
  $st->execute([$dealer_id, DEALER_WALLET_TYPE_CASHBACK]);
  return (int)$st->fetchColumn();
}

function dealer_wallet_type_label(string $type): string {
  return match ($type) {
    DEALER_WALLET_TYPE_TOPUP      => 'Bakiye Yükleme',
    DEALER_WALLET_TYPE_PURCHASE   => 'Paket Satın Alımı',
    DEALER_WALLET_TYPE_CASHBACK   => 'Cashback',
    DEALER_WALLET_TYPE_ADJUSTMENT => 'Düzenleme',
    DEALER_WALLET_TYPE_REFUND     => 'İade',
    DEALER_WALLET_TYPE_TOPUP_REQ  => 'Yükleme Talebi',
    default                       => ucfirst($type),
  };
}

function dealer_status_counts(): array {
  $rows = pdo()->query("SELECT status, COUNT(*) AS c FROM dealers GROUP BY status")->fetchAll();
  $counts = [
    'active' => 0,
    'pending' => 0,
    'inactive' => 0,
  ];
  foreach ($rows as $row) {
    $status = $row['status'] ?? '';
    if (isset($counts[$status])) {
      $counts[$status] = (int)$row['c'];
    }
  }
  $counts['all'] = array_sum($counts);
  return $counts;
}

function dealer_packages_all(bool $onlyActive = false): array {
  $sql = "SELECT * FROM dealer_packages";
  if ($onlyActive) {
    $sql .= " WHERE is_active=1";
  }
  $sql .= " ORDER BY price_cents ASC, id ASC";
  $rows = pdo()->query($sql)->fetchAll();
  foreach ($rows as &$row) {
    $row['price_cents'] = (int)$row['price_cents'];
    $row['event_quota'] = $row['event_quota'] !== null ? (int)$row['event_quota'] : null;
    $row['duration_days'] = $row['duration_days'] !== null ? (int)$row['duration_days'] : null;
    $row['cashback_rate'] = (float)$row['cashback_rate'];
    $row['is_active'] = (int)$row['is_active'];
    $row['is_public'] = (int)($row['is_public'] ?? 0);
  }
  return $rows;
}

function dealer_packages_available(): array {
  return dealer_packages_all(true);
}

function dealer_packages_public(): array {
  $sql = "SELECT * FROM dealer_packages WHERE is_active=1 AND is_public=1 ORDER BY price_cents ASC, id ASC";
  $rows = pdo()->query($sql)->fetchAll();
  foreach ($rows as &$row) {
    $row['price_cents'] = (int)$row['price_cents'];
    $row['event_quota'] = $row['event_quota'] !== null ? (int)$row['event_quota'] : null;
    $row['duration_days'] = $row['duration_days'] !== null ? (int)$row['duration_days'] : null;
    $row['cashback_rate'] = (float)$row['cashback_rate'];
    $row['is_active'] = (int)$row['is_active'];
    $row['is_public'] = (int)($row['is_public'] ?? 0);
  }
  return $rows;
}

function dealer_package_get(int $package_id): ?array {
  $st = pdo()->prepare("SELECT * FROM dealer_packages WHERE id=? LIMIT 1");
  $st->execute([$package_id]);
  $row = $st->fetch();
  if (!$row) return null;
  $row['price_cents'] = (int)$row['price_cents'];
  $row['event_quota'] = $row['event_quota'] !== null ? (int)$row['event_quota'] : null;
  $row['duration_days'] = $row['duration_days'] !== null ? (int)$row['duration_days'] : null;
  $row['cashback_rate'] = (float)$row['cashback_rate'];
  $row['is_active'] = (int)$row['is_active'];
  $row['is_public'] = (int)($row['is_public'] ?? 0);
  return $row;
}

function dealer_purchase_get(int $purchase_id): ?array {
  $sql = "SELECT pp.*, pkg.name AS package_name, pkg.description AS package_description"
       . " FROM dealer_package_purchases pp"
       . " INNER JOIN dealer_packages pkg ON pkg.id=pp.package_id"
       . " WHERE pp.id=? LIMIT 1";
  $st = pdo()->prepare($sql);
  $st->execute([$purchase_id]);
  $row = $st->fetch();
  if (!$row) return null;
  $row['price_cents'] = (int)$row['price_cents'];
  $row['event_quota'] = $row['event_quota'] !== null ? (int)$row['event_quota'] : null;
  $row['events_used'] = (int)$row['events_used'];
  $row['duration_days'] = $row['duration_days'] !== null ? (int)$row['duration_days'] : null;
  $row['cashback_rate'] = (float)$row['cashback_rate'];
  $row['cashback_amount'] = (int)$row['cashback_amount'];
  $row['dealer_id'] = (int)$row['dealer_id'];
  $row['package_id'] = (int)$row['package_id'];
  $row['lead_event_id'] = $row['lead_event_id'] !== null ? (int)$row['lead_event_id'] : null;
  $row['source'] = $row['source'] ?? DEALER_PURCHASE_SOURCE_DEALER;
  return $row;
}

function dealer_fetch_purchases(int $dealer_id, ?string $status = null): array {
  $sql = "SELECT pp.*, pkg.name AS package_name, pkg.description AS package_description"
       . " FROM dealer_package_purchases pp"
       . " INNER JOIN dealer_packages pkg ON pkg.id=pp.package_id"
       . " WHERE pp.dealer_id=?";
  $params = [$dealer_id];
  if ($status !== null) {
    $sql .= " AND pp.status=?";
    $params[] = $status;
  }
  $sql .= " ORDER BY pp.created_at DESC";
  $st = pdo()->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll();
  foreach ($rows as &$row) {
    $row['price_cents'] = (int)$row['price_cents'];
    $row['event_quota'] = $row['event_quota'] !== null ? (int)$row['event_quota'] : null;
    $row['events_used'] = (int)$row['events_used'];
    $row['duration_days'] = $row['duration_days'] !== null ? (int)$row['duration_days'] : null;
    $row['cashback_rate'] = (float)$row['cashback_rate'];
    $row['cashback_amount'] = (int)$row['cashback_amount'];
    $row['dealer_id'] = (int)$row['dealer_id'];
    $row['package_id'] = (int)$row['package_id'];
    $row['lead_event_id'] = $row['lead_event_id'] !== null ? (int)$row['lead_event_id'] : null;
    $row['source'] = $row['source'] ?? DEALER_PURCHASE_SOURCE_DEALER;
  }
  return $rows;
}

function dealer_purchase_status_label(string $status): string {
  return match ($status) {
    DEALER_PURCHASE_STATUS_ACTIVE    => 'Aktif',
    DEALER_PURCHASE_STATUS_USED      => 'Tüketildi',
    DEALER_PURCHASE_STATUS_EXPIRED   => 'Süresi Doldu',
    DEALER_PURCHASE_STATUS_PENDING   => 'Beklemede',
    DEALER_PURCHASE_STATUS_CANCELLED => 'İptal',
    default                          => ucfirst($status),
  };
}

function dealer_cashback_status_label(string $status): string {
  return match ($status) {
    DEALER_CASHBACK_PENDING   => 'Ödeme Bekliyor',
    DEALER_CASHBACK_PAID      => 'Ödendi',
    DEALER_CASHBACK_AWAITING  => 'Etkinlik Bekleniyor',
    DEALER_CASHBACK_NONE      => 'N/A',
    default                   => ucfirst($status),
  };
}

function dealer_refresh_purchase_states(int $dealer_id): void {
  $pdo = pdo();
  $now = now();
  $pdo->prepare("UPDATE dealer_package_purchases SET status=?, updated_at=? WHERE dealer_id=? AND status=? AND expires_at IS NOT NULL AND expires_at < ?")
      ->execute([DEALER_PURCHASE_STATUS_EXPIRED, $now, $dealer_id, DEALER_PURCHASE_STATUS_ACTIVE, $now]);
  $pdo->prepare("UPDATE dealer_package_purchases SET status=?, updated_at=? WHERE dealer_id=? AND status=? AND event_quota IS NOT NULL AND events_used >= event_quota")
      ->execute([DEALER_PURCHASE_STATUS_USED, $now, $dealer_id, DEALER_PURCHASE_STATUS_ACTIVE]);
}

function dealer_event_quota_summary(int $dealer_id): array {
  dealer_refresh_purchase_states($dealer_id);
  $active = dealer_fetch_purchases($dealer_id, DEALER_PURCHASE_STATUS_ACTIVE);
  $summary = [
    'has_credit' => false,
    'remaining_events' => 0,
    'has_unlimited' => false,
    'unlimited_until' => null,
    'next_expiry' => null,
    'active' => $active,
    'cashback_waiting' => 0,
    'cashback_pending_amount' => 0,
    'cashback_awaiting_event' => 0,
  ];
  foreach ($active as &$purchase) {
    if (($purchase['source'] ?? DEALER_PURCHASE_SOURCE_DEALER) !== DEALER_PURCHASE_SOURCE_DEALER) {
      continue;
    }
    $quota = $purchase['event_quota'];
    $used  = $purchase['events_used'];
    if (!empty($purchase['expires_at'])) {
      $expTs = strtotime($purchase['expires_at']);
      if ($expTs !== false) {
        if ($summary['next_expiry'] === null || $expTs < strtotime($summary['next_expiry'])) {
          $summary['next_expiry'] = $purchase['expires_at'];
        }
      }
    }
    if ($quota === null) {
      $summary['has_credit'] = true;
      $summary['has_unlimited'] = true;
      if (!empty($purchase['expires_at'])) {
        $expTs = strtotime($purchase['expires_at']);
        if ($expTs !== false) {
          if ($summary['unlimited_until'] === null || $expTs < strtotime($summary['unlimited_until'])) {
            $summary['unlimited_until'] = $purchase['expires_at'];
          }
        }
      }
    } else {
      $remaining = max(0, $quota - $used);
      if ($remaining > 0) {
        $summary['has_credit'] = true;
        $summary['remaining_events'] += $remaining;
      }
    }
    if ($purchase['cashback_status'] === DEALER_CASHBACK_PENDING) {
      $summary['cashback_waiting']++;
      $summary['cashback_pending_amount'] += max(0, $purchase['cashback_amount']);
    }
    if ($purchase['cashback_status'] === DEALER_CASHBACK_AWAITING) {
      $summary['cashback_awaiting_event']++;
    }
  }
  return $summary;
}

function dealer_has_event_credit(int $dealer_id): bool {
  $summary = dealer_event_quota_summary($dealer_id);
  return $summary['has_credit'];
}

function dealer_event_creation_status(array $dealer): array {
  $summary = dealer_event_quota_summary((int)$dealer['id']);
  if ($dealer['status'] !== DEALER_STATUS_ACTIVE) {
    return ['allowed' => false, 'reason' => 'Bayiniz aktif durumda değil.', 'summary' => $summary];
  }
  if (!dealer_has_valid_license($dealer)) {
    return ['allowed' => false, 'reason' => 'Lisans süresi geçerli olmadığı için yeni etkinlik oluşturamazsınız.', 'summary' => $summary];
  }
  if (!$summary['has_credit']) {
    return ['allowed' => false, 'reason' => 'Yeni etkinlik oluşturmak için paket satın alın veya bakiyenizi artırın.', 'summary' => $summary];
  }
  return ['allowed' => true, 'reason' => null, 'summary' => $summary];
}

function dealer_purchase_package(int $dealer_id, int $package_id): array {
  $package = dealer_package_get($package_id);
  if (!$package) {
    throw new RuntimeException('Paket bulunamadı.');
  }
  if (empty($package['is_active'])) {
    throw new RuntimeException('Bu paket şu anda satışta değil.');
  }
  $price = (int)$package['price_cents'];
  if ($price <= 0) {
    throw new RuntimeException('Paket fiyatı geçersiz.');
  }
  $pdo = pdo();
  $ownTxn = !$pdo->inTransaction();
  if ($ownTxn) {
    $pdo->beginTransaction();
  }
  try {
    dealer_refresh_purchase_states($dealer_id);
    dealer_wallet_adjust($dealer_id, -$price, DEALER_WALLET_TYPE_PURCHASE, 'Paket: '.$package['name'], [
      'package_id' => $package_id,
    ]);
    $now = now();
    $expiresAt = null;
    $duration = $package['duration_days'];
    if ($duration !== null && $duration > 0) {
      $dt = new DateTime($now);
      $dt->modify('+'.$duration.' days');
      $expiresAt = $dt->format('Y-m-d H:i:s');
    }
    $eventQuota = $package['event_quota'];
    if ($eventQuota !== null && $eventQuota <= 0) {
      $eventQuota = null;
    }
    $cashbackRate = 0.0;
    $cashbackStatus = DEALER_CASHBACK_NONE;
    $pdo->prepare("INSERT INTO dealer_package_purchases (dealer_id,package_id,status,price_cents,event_quota,events_used,duration_days,starts_at,expires_at,cashback_rate,cashback_status,source,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([
          $dealer_id,
          $package_id,
          DEALER_PURCHASE_STATUS_ACTIVE,
          $price,
          $eventQuota,
          0,
          $duration,
          $now,
          $expiresAt,
          $cashbackRate,
          $cashbackStatus,
          DEALER_PURCHASE_SOURCE_DEALER,
          $now,
          $now,
        ]);
    $purchaseId = (int)$pdo->lastInsertId();
    dealer_refresh_purchase_states($dealer_id);
    if ($ownTxn) {
      $pdo->commit();
    }
    $purchase = dealer_purchase_get($purchaseId);
    return $purchase ?? [];
  } catch (Throwable $e) {
    if ($ownTxn && $pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}

function dealer_consume_event_credit(int $dealer_id, int $event_id): void {
  $pdo = pdo();
  $ownTxn = !$pdo->inTransaction();
  if ($ownTxn) {
    $pdo->beginTransaction();
  }
  try {
    dealer_refresh_purchase_states($dealer_id);
    $now = now();
    $sql = "SELECT pp.*, pkg.name AS package_name FROM dealer_package_purchases pp"
         . " INNER JOIN dealer_packages pkg ON pkg.id=pp.package_id"
         . " WHERE pp.dealer_id=? AND pp.status=? AND (pp.expires_at IS NULL OR pp.expires_at >= ?)"
         . " ORDER BY (pp.event_quota IS NULL) ASC, pp.expires_at IS NULL, pp.expires_at ASC, pp.id ASC FOR UPDATE";
    $st = $pdo->prepare($sql);
    $st->execute([$dealer_id, DEALER_PURCHASE_STATUS_ACTIVE, $now]);
    $rows = $st->fetchAll();
    $target = null;
    foreach ($rows as $row) {
      $quota = $row['event_quota'] !== null ? (int)$row['event_quota'] : null;
      $used = (int)$row['events_used'];
      if ($quota === null || $used < $quota) {
        $target = $row;
        break;
      }
    }
    if (!$target) {
      throw new RuntimeException('Kullanılabilir paket bulunamadı.');
    }
    $quota = $target['event_quota'] !== null ? (int)$target['event_quota'] : null;
    $used = (int)$target['events_used'];
    $used++;
    $fields = ['events_used=?', 'updated_at=?'];
    $params = [$used, $now];
    $newStatus = $target['status'];
    if ($quota !== null && $used >= $quota) {
      $newStatus = DEALER_PURCHASE_STATUS_USED;
      $fields[] = 'status=?';
      $params[] = $newStatus;
    }
    $setLead = empty($target['lead_event_id']);
    $cashbackRate = (float)$target['cashback_rate'];
    if ($cashbackRate > 0 && $quota === 1 && $target['cashback_status'] !== DEALER_CASHBACK_PENDING && $target['cashback_status'] !== DEALER_CASHBACK_PAID) {
      $amount = (int)round(((int)$target['price_cents']) * $cashbackRate);
      $fields[] = 'cashback_status=?';
      $params[] = DEALER_CASHBACK_PENDING;
      $fields[] = 'cashback_amount=?';
      $params[] = $amount;
      $fields[] = 'lead_event_id=?';
      $params[] = $event_id;
      $setLead = false;
    }
    if ($setLead) {
      $fields[] = 'lead_event_id=?';
      $params[] = $event_id;
    }
    $params[] = (int)$target['id'];
    $pdo->prepare('UPDATE dealer_package_purchases SET '.implode(', ', $fields).' WHERE id=?')
        ->execute($params);
    dealer_refresh_purchase_states($dealer_id);
    if ($ownTxn) {
      $pdo->commit();
    }
  } catch (Throwable $e) {
    if ($ownTxn && $pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}

function dealer_cashback_candidates(int $dealer_id, ?string $status = DEALER_CASHBACK_PENDING): array {
  $sql = "SELECT pp.*, pkg.name AS package_name, e.title AS event_title, e.event_date"
       . " FROM dealer_package_purchases pp"
       . " INNER JOIN dealer_packages pkg ON pkg.id=pp.package_id"
       . " LEFT JOIN events e ON e.id=pp.lead_event_id"
       . " WHERE pp.dealer_id=? AND pp.source=?";
  $params = [$dealer_id, DEALER_PURCHASE_SOURCE_DEALER];
  if ($status !== null) {
    $sql .= " AND pp.cashback_status=?";
    $params[] = $status;
  }
  $sql .= " ORDER BY pp.created_at DESC";
  $st = pdo()->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll();
  foreach ($rows as &$row) {
    $row['cashback_amount'] = (int)$row['cashback_amount'];
    $row['price_cents'] = (int)$row['price_cents'];
  }
  return $rows;
}

function dealer_pay_cashback(int $purchase_id, string $note = '', array $meta = []): void {
  $pdo = pdo();
  $ownTxn = !$pdo->inTransaction();
  if ($ownTxn) {
    $pdo->beginTransaction();
  }
  try {
    $st = $pdo->prepare("SELECT * FROM dealer_package_purchases WHERE id=? FOR UPDATE");
    $st->execute([$purchase_id]);
    $purchase = $st->fetch();
    if (!$purchase) {
      throw new RuntimeException('Satın alma kaydı bulunamadı.');
    }
    if ($purchase['cashback_status'] !== DEALER_CASHBACK_PENDING) {
      throw new RuntimeException('Bu satın alma için bekleyen cashback yok.');
    }
    $amount = (int)$purchase['cashback_amount'];
    if ($amount <= 0) {
      throw new RuntimeException('Cashback tutarı tanımlı değil.');
    }
    $meta['purchase_id'] = $purchase_id;
    if (!empty($purchase['lead_event_id'])) {
      $meta['event_id'] = (int)$purchase['lead_event_id'];
    }
    dealer_wallet_adjust((int)$purchase['dealer_id'], $amount, DEALER_WALLET_TYPE_CASHBACK, 'Cashback ödemesi', $meta);
    $pdo->prepare("UPDATE dealer_package_purchases SET cashback_status=?, cashback_paid_at=?, cashback_note=?, updated_at=? WHERE id=?")
        ->execute([
          DEALER_CASHBACK_PAID,
          now(),
          $note !== '' ? $note : null,
          now(),
          $purchase_id,
        ]);
    if ($ownTxn) {
      $pdo->commit();
    }
  } catch (Throwable $e) {
    if ($ownTxn && $pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}

function dealer_topup_get(int $topup_id): ?array {
  $st = pdo()->prepare("SELECT * FROM dealer_topups WHERE id=? LIMIT 1");
  $st->execute([$topup_id]);
  $row = $st->fetch();
  if (!$row) {
    return null;
  }
  $row['id'] = (int)$row['id'];
  $row['dealer_id'] = (int)$row['dealer_id'];
  $row['amount_cents'] = (int)$row['amount_cents'];
  $row['paytr_token'] = $row['paytr_token'] ?? null;
  $row['merchant_oid'] = $row['merchant_oid'] ?? null;
  $row['payload'] = !empty($row['payload_json']) ? safe_json_decode($row['payload_json']) : null;
  return $row;
}

function dealer_topup_find_by_oid(string $merchant_oid): ?array {
  $oid = trim($merchant_oid);
  if ($oid === '') {
    return null;
  }
  $st = pdo()->prepare("SELECT * FROM dealer_topups WHERE merchant_oid=? LIMIT 1");
  $st->execute([$oid]);
  $row = $st->fetch();
  if (!$row) {
    return null;
  }
  $row['id'] = (int)$row['id'];
  $row['dealer_id'] = (int)$row['dealer_id'];
  $row['amount_cents'] = (int)$row['amount_cents'];
  $row['paytr_token'] = $row['paytr_token'] ?? null;
  $row['merchant_oid'] = $row['merchant_oid'] ?? null;
  $row['payload'] = !empty($row['payload_json']) ? safe_json_decode($row['payload_json']) : null;
  return $row;
}

function dealer_generate_topup_oid(int $dealer_id): string {
  $dealer_id = max(0, (int)$dealer_id);
  do {
    $rand = strtoupper(bin2hex(random_bytes(6)));
    $oid = 'DT'.$dealer_id.'T'.$rand;
    $oid = substr(preg_replace('/[^A-Za-z0-9]/', '', $oid), 0, 64);
    $st = pdo()->prepare("SELECT 1 FROM dealer_topups WHERE merchant_oid=? LIMIT 1");
    $st->execute([$oid]);
    $exists = (bool)$st->fetchColumn();
  } while ($exists);
  return $oid;
}

function dealer_create_topup_request(int $dealer_id, int $amount_cents): array {
  if ($amount_cents <= 0) {
    throw new RuntimeException('Geçerli bir tutar girin.');
  }
  $dealer = dealer_get($dealer_id);
  if (!$dealer) {
    throw new RuntimeException('Bayi bulunamadı.');
  }
  $email = filter_var($dealer['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: 'test@example.com';
  $user_name = mb_substr($dealer['name'] ?? 'Bayi', 0, 64, 'UTF-8');
  $user_address = $dealer['company'] ?: '—';
  $user_phone = $dealer['phone'] ?: '—';

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

  $basket = [[
    'Bakiye Yükleme',
    number_format($amount_cents / 100, 2, '.', ''),
    1,
  ]];
  $user_basket = base64_encode(json_encode($basket, JSON_UNESCAPED_UNICODE));

  $merchantOid = dealer_generate_topup_oid($dealer_id);
  $no_installment = 0;
  $max_installment = 0;
  $currency = 'TL';
  $testMode = paytr_is_test_mode();
  $status = $testMode ? DEALER_TOPUP_STATUS_AWAITING_REVIEW : DEALER_TOPUP_STATUS_PENDING;
  $token = null;
  $reference = null;
  $payload = [
    'request' => [
      'amount_cents' => $amount_cents,
      'basket'       => $basket,
      'ip'           => $ip,
    ],
    'merchant_oid' => $merchantOid,
  ];

  if ($testMode) {
    $payload['test_mode'] = true;
    $payload['note'] = 'Ödeme test modunda otomatik onaylandı.';
    $reference = 'TEST-'.$merchantOid;
  } else {
    $test = (int)PAYTR_TEST_MODE;
    $hash_str = PAYTR_MERCHANT_ID . $ip . $merchantOid . $email . $amount_cents . $user_basket . $no_installment . $max_installment . $currency . $test;
    $paytr_token = base64_encode(hash_hmac('sha256', $hash_str . PAYTR_MERCHANT_SALT, PAYTR_MERCHANT_KEY, true));

    $post = [
      'merchant_id'         => PAYTR_MERCHANT_ID,
      'user_ip'             => $ip,
      'merchant_oid'        => $merchantOid,
      'email'               => $email,
      'payment_amount'      => $amount_cents,
      'paytr_token'         => $paytr_token,
      'user_basket'         => $user_basket,
      'no_installment'      => $no_installment,
      'max_installment'     => $max_installment,
      'user_name'           => $user_name,
      'user_address'        => $user_address,
      'user_phone'          => $user_phone,
      'merchant_ok_url'     => PAYTR_DEALER_OK_URL,
      'merchant_fail_url'   => PAYTR_DEALER_FAIL_URL,
      'merchant_callback_url'=> PAYTR_CALLBACK_URL,
      'timeout_limit'       => 30,
      'currency'            => $currency,
      'test_mode'           => $test,
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
  }

  $now = now();
  pdo()->prepare("INSERT INTO dealer_topups (dealer_id, amount_cents, status, paytr_token, merchant_oid, paytr_reference, payload_json, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)")
      ->execute([
        $dealer_id,
        $amount_cents,
        $status,
        $token,
        $merchantOid,
        $reference,
        safe_json_encode($payload),
        $now,
        $now,
      ]);

  $topupId = (int)pdo()->lastInsertId();

  return [
    'id'           => $topupId,
    'token'        => $token,
    'merchant_oid' => $merchantOid,
    'test_mode'    => $testMode,
  ];
}

function dealer_topups_for_dealer(int $dealer_id, ?string $status = null): array {
  $sql = "SELECT * FROM dealer_topups WHERE dealer_id=?";
  $params = [$dealer_id];
  if ($status !== null) {
    $sql .= " AND status=?";
    $params[] = $status;
  }
  $sql .= " ORDER BY id DESC";
  $st = pdo()->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll();
  foreach ($rows as &$row) {
    $row['amount_cents'] = (int)$row['amount_cents'];
    $row['dealer_id'] = (int)$row['dealer_id'];
    $row['merchant_oid'] = $row['merchant_oid'] ?? null;
    $row['paytr_token'] = $row['paytr_token'] ?? null;
    $row['payload'] = !empty($row['payload_json']) ? safe_json_decode($row['payload_json']) : null;
  }
  return $rows;
}

function dealer_topup_status_label(string $status): string {
  return match ($status) {
    DEALER_TOPUP_STATUS_PENDING         => 'Ödeme Bekleniyor',
    DEALER_TOPUP_STATUS_AWAITING_REVIEW => 'Onay Bekliyor',
    DEALER_TOPUP_STATUS_COMPLETED       => 'Tamamlandı',
    DEALER_TOPUP_STATUS_CANCELLED       => 'İptal',
    default                       => ucfirst($status),
  };
}

function dealer_mark_topup_completed(int $topup_id, ?string $reference = null, array $payload = []): void {
  $pdo = pdo();
  $ownTxn = !$pdo->inTransaction();
  if ($ownTxn) {
    $pdo->beginTransaction();
  }
  try {
    $st = $pdo->prepare("SELECT * FROM dealer_topups WHERE id=? FOR UPDATE");
    $st->execute([$topup_id]);
    $row = $st->fetch();
    if (!$row) {
      throw new RuntimeException('Yükleme isteği bulunamadı.');
    }
    if ($row['status'] === DEALER_TOPUP_STATUS_COMPLETED) {
      if ($ownTxn) {
        $pdo->commit();
      }
      return;
    }
    if (!in_array($row['status'], [DEALER_TOPUP_STATUS_PENDING, DEALER_TOPUP_STATUS_AWAITING_REVIEW], true)) {
      throw new RuntimeException('Bu kayıt işleme kapalı.');
    }
    $amount = (int)$row['amount_cents'];
    if ($amount <= 0) {
      throw new RuntimeException('Tutar tanımlı değil.');
    }
    $meta = ['topup_id' => $topup_id];
    if ($reference) {
      $meta['reference'] = $reference;
    }
    dealer_wallet_adjust((int)$row['dealer_id'], $amount, DEALER_WALLET_TYPE_TOPUP, 'Bakiye yükleme', $meta);
    $payloadData = !empty($row['payload_json']) ? safe_json_decode($row['payload_json']) : [];
    if (!is_array($payloadData)) {
      $payloadData = [];
    }
    if ($payload) {
      $payloadData['admin'] = $payload;
    }
    $payloadJson = $payloadData ? safe_json_encode($payloadData) : null;
    $pdo->prepare("UPDATE dealer_topups SET status=?, paytr_reference=?, payload_json=?, completed_at=?, updated_at=? WHERE id=?")
        ->execute([
          DEALER_TOPUP_STATUS_COMPLETED,
          $reference ?: ($row['paytr_reference'] ?: null),
          $payloadJson,
          now(),
          now(),
          $topup_id,
        ]);
    if ($ownTxn) {
      $pdo->commit();
    }
  } catch (Throwable $e) {
    if ($ownTxn && $pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}

function dealer_cancel_topup(int $topup_id, ?int $dealer_id = null): void {
  if ($dealer_id !== null) {
    $st = pdo()->prepare("UPDATE dealer_topups SET status=?, updated_at=? WHERE id=? AND dealer_id=? AND status=?");
    $st->execute([DEALER_TOPUP_STATUS_CANCELLED, now(), $topup_id, $dealer_id, DEALER_TOPUP_STATUS_PENDING]);
  } else {
    $st = pdo()->prepare("UPDATE dealer_topups SET status=?, updated_at=? WHERE id=? AND status IN (?, ?)");
    $st->execute([DEALER_TOPUP_STATUS_CANCELLED, now(), $topup_id, DEALER_TOPUP_STATUS_PENDING, DEALER_TOPUP_STATUS_AWAITING_REVIEW]);
  }
  if ($st->rowCount() === 0) {
    throw new RuntimeException('İptal edilecek bekleyen yükleme bulunamadı.');
  }
}

function dealer_handle_topup_callback(string $merchant_oid, string $status, array $payload = []): void {
  $oid = trim($merchant_oid);
  if ($oid === '') {
    return;
  }
  $pdo = pdo();
  $ownTxn = !$pdo->inTransaction();
  if ($ownTxn) {
    $pdo->beginTransaction();
  }
  try {
    $st = $pdo->prepare("SELECT * FROM dealer_topups WHERE merchant_oid=? FOR UPDATE");
    $st->execute([$oid]);
    $row = $st->fetch();
    if (!$row) {
      if ($ownTxn) {
        $pdo->commit();
      }
      return;
    }
    $payloadData = !empty($row['payload_json']) ? safe_json_decode($row['payload_json']) : [];
    if (!is_array($payloadData)) {
      $payloadData = [];
    }
    if (!isset($payloadData['paytr_callbacks']) || !is_array($payloadData['paytr_callbacks'])) {
      $payloadData['paytr_callbacks'] = [];
    }
    $payloadData['paytr_callbacks'][] = [
      'status'      => $status,
      'received_at' => now(),
      'data'        => array_intersect_key($payload, array_flip(['payment_amount','currency','payment_id','merchant_oid','status','hash'])),
    ];
    $payloadJson = safe_json_encode($payloadData);

    if ($status === 'success') {
      if ($row['status'] !== DEALER_TOPUP_STATUS_COMPLETED) {
        $reference = $payload['payment_id'] ?? ($payload['merchant_oid'] ?? $row['paytr_reference']);
        $pdo->prepare("UPDATE dealer_topups SET status=?, paytr_reference=?, payload_json=?, updated_at=? WHERE id=?")
            ->execute([
              DEALER_TOPUP_STATUS_AWAITING_REVIEW,
              $reference,
              $payloadJson,
              now(),
              (int)$row['id'],
            ]);
      } else {
        $pdo->prepare("UPDATE dealer_topups SET payload_json=?, updated_at=? WHERE id=?")
            ->execute([$payloadJson, now(), (int)$row['id']]);
      }
    } else {
      if (in_array($row['status'], [DEALER_TOPUP_STATUS_PENDING, DEALER_TOPUP_STATUS_AWAITING_REVIEW], true)) {
        $pdo->prepare("UPDATE dealer_topups SET status=?, payload_json=?, updated_at=? WHERE id=?")
            ->execute([
              DEALER_TOPUP_STATUS_CANCELLED,
              $payloadJson,
              now(),
              (int)$row['id'],
            ]);
      } else {
        $pdo->prepare("UPDATE dealer_topups SET payload_json=?, updated_at=? WHERE id=?")
            ->execute([$payloadJson, now(), (int)$row['id']]);
      }
    }

    if ($ownTxn) {
      $pdo->commit();
    }
  } catch (Throwable $e) {
    if ($ownTxn && $pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}
