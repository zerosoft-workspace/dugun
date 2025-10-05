<?php
/**
 * includes/representatives.php — Bayi temsilcileri yardımcı fonksiyonları
 */
require_once __DIR__.'/../config.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';

const REPRESENTATIVE_STATUS_ACTIVE = 'active';
const REPRESENTATIVE_STATUS_INACTIVE = 'inactive';

const REPRESENTATIVE_COMMISSION_STATUS_PENDING   = 'pending';
const REPRESENTATIVE_COMMISSION_STATUS_APPROVED  = 'approved';
const REPRESENTATIVE_COMMISSION_STATUS_PAID      = 'paid';
const REPRESENTATIVE_COMMISSION_STATUS_REJECTED  = 'rejected';

const REPRESENTATIVE_PAYOUT_STATUS_PENDING  = 'pending';
const REPRESENTATIVE_PAYOUT_STATUS_APPROVED = 'approved';
const REPRESENTATIVE_PAYOUT_STATUS_PAID     = 'paid';
const REPRESENTATIVE_PAYOUT_STATUS_REJECTED = 'rejected';

function representative_commission_status_label(string $status): string {
  return match ($status) {
    REPRESENTATIVE_COMMISSION_STATUS_PENDING  => 'Onay Bekliyor',
    REPRESENTATIVE_COMMISSION_STATUS_APPROVED => 'Ödeme Hazır',
    REPRESENTATIVE_COMMISSION_STATUS_PAID     => 'Ödendi',
    REPRESENTATIVE_COMMISSION_STATUS_REJECTED => 'Reddedildi',
    default                                   => ucfirst($status),
  };
}

function representative_payout_status_label(string $status): string {
  return match ($status) {
    REPRESENTATIVE_PAYOUT_STATUS_PENDING  => 'Bekliyor',
    REPRESENTATIVE_PAYOUT_STATUS_APPROVED => 'Onaylandı',
    REPRESENTATIVE_PAYOUT_STATUS_PAID     => 'Ödendi',
    REPRESENTATIVE_PAYOUT_STATUS_REJECTED => 'Reddedildi',
    default                               => ucfirst($status),
  };
}

function representative_invoice_storage_dir(): string {
  $dir = __DIR__.'/../uploads/representatives/invoices';
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
  return $dir;
}

function representative_process_invoice_upload(?array $file, bool $required = true): ?string {
  $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
  if ($error === UPLOAD_ERR_NO_FILE) {
    if ($required) {
      throw new InvalidArgumentException('Ödeme talebi için fatura yüklemeniz gerekir.');
    }
    return null;
  }
  if ($error !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Fatura yüklenirken bir hata oluştu.');
  }
  if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
    throw new RuntimeException('Geçersiz fatura yüklemesi.');
  }
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo ? $finfo->file($file['tmp_name']) : null;
  $allowed = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
  ];
  if (!isset($allowed[$mime])) {
    throw new InvalidArgumentException('Fatura yalnızca PDF, JPG veya PNG formatında yüklenebilir.');
  }
  $dir = representative_invoice_storage_dir();
  $name = 'rep_invoice_'.date('Ymd_His').'_' . bin2hex(random_bytes(4)).'.'.$allowed[$mime];
  $path = $dir.'/'.$name;
  if (!@move_uploaded_file($file['tmp_name'], $path)) {
    throw new RuntimeException('Fatura kaydedilemedi.');
  }
  return 'representatives/invoices/'.$name;
}

function representative_payout_invoice_url(?string $path): ?string {
  if (!$path) {
    return null;
  }
  if (preg_match('~^https?://~i', $path)) {
    return $path;
  }
  return rtrim(BASE_URL, '/').'/uploads/'.ltrim($path, '/');
}

function representative_assignments_support_commission_rate(): bool {
  static $hasColumn = null;
  if ($hasColumn === null) {
    try {
      $hasColumn = column_exists('dealer_representative_assignments', 'commission_rate');
    } catch (Throwable $e) {
      $hasColumn = false;
    }
  }
  return (bool)$hasColumn;
}

function representative_normalize_row(array $row): array {
  $row['id'] = (int)($row['id'] ?? 0);
  $row['dealer_id'] = isset($row['dealer_id']) ? (int)$row['dealer_id'] : 0;
  $row['commission_rate'] = isset($row['commission_rate']) ? (float)$row['commission_rate'] : 0.0;
  $row['last_login_at'] = $row['last_login_at'] ?? null;
  $row['created_at'] = $row['created_at'] ?? null;
  $row['updated_at'] = $row['updated_at'] ?? null;
  $row['assigned_at'] = $row['assigned_at'] ?? null;
  return $row;
}

function representative_sanitize_commission_rate($value, ?float $fallback = null): float {
  if ($value === null || $value === '') {
    return $fallback !== null ? (float)$fallback : 0.0;
  }
  if (!is_numeric($value)) {
    $rate = $fallback !== null ? (float)$fallback : 0.0;
  } else {
    $rate = (float)$value;
  }
  if ($rate < 0) {
    $rate = 0.0;
  }
  if ($rate > 100) {
    $rate = 100.0;
  }
  return (float)$rate;
}

function representative_hydrate_assignments(array $rep): array {
  if (($rep['id'] ?? 0) <= 0) {
    return $rep;
  }
  $dealers = representative_assigned_dealers((int)$rep['id']);
  $defaultRate = isset($rep['commission_rate']) ? (float)$rep['commission_rate'] : null;
  foreach ($dealers as &$dealer) {
    if (!isset($dealer['commission_rate']) || $dealer['commission_rate'] === null) {
      $dealer['commission_rate'] = $defaultRate;
    }
  }
  unset($dealer);
  $rep['dealers'] = $dealers;
  $rep['dealer_ids'] = array_map(fn($dealer) => (int)$dealer['id'], $dealers);
  if (!empty($dealers)) {
    $primary = $dealers[0];
    $rep['dealer_id'] = (int)$primary['id'];
    $rep['assigned_at'] = $primary['assigned_at'] ?? ($rep['assigned_at'] ?? null);
    $rep['dealer_name'] = $primary['name'] ?? null;
    $rep['dealer_company'] = $primary['company'] ?? null;
    $rep['dealer_status'] = $primary['status'] ?? null;
    $rep['dealer_code'] = $primary['code'] ?? null;
  } else {
    $rep['dealer_ids'] = [];
    $rep['dealer_id'] = 0;
  }
  return $rep;
}

function representative_fetch_raw(int $id): ?array {
  if ($id <= 0) {
    return null;
  }
  $st = pdo()->prepare('SELECT * FROM dealer_representatives WHERE id=? LIMIT 1');
  $st->execute([$id]);
  $row = $st->fetch();
  return $row ? representative_normalize_row($row) : null;
}

function representative_get(int $id): ?array {
  $row = representative_fetch_raw($id);
  if (!$row) {
    return null;
  }
  return representative_hydrate_assignments($row);
}

function representative_find_by_email(string $email): ?array {
  $email = trim($email);
  if ($email === '') {
    return null;
  }
  $st = pdo()->prepare('SELECT * FROM dealer_representatives WHERE email=? LIMIT 1');
  $st->execute([$email]);
  $row = $st->fetch();
  if (!$row) {
    return null;
  }
  return representative_hydrate_assignments(representative_normalize_row($row));
}

function representative_assigned_dealer_ids(int $representative_id): array {
  $st = pdo()->prepare('SELECT dealer_id FROM dealer_representative_assignments WHERE representative_id=? ORDER BY assigned_at ASC, dealer_id ASC');
  $st->execute([$representative_id]);
  return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function representative_assigned_dealers(int $representative_id): array {
  if ($representative_id <= 0) {
    return [];
  }
  $commissionSelect = representative_assignments_support_commission_rate()
    ? 'a.commission_rate AS commission_rate'
    : 'NULL AS commission_rate';
  $sql = "SELECT d.id, d.name, d.company, d.status, d.code, a.assigned_at, {$commissionSelect}
          FROM dealer_representative_assignments a
          INNER JOIN dealers d ON d.id = a.dealer_id
          WHERE a.representative_id=?
          ORDER BY a.assigned_at ASC, d.name ASC";
  $st = pdo()->prepare($sql);
  $st->execute([$representative_id]);
  $rows = [];
  foreach ($st as $row) {
    $rows[] = [
      'id' => (int)$row['id'],
      'name' => $row['name'] ?? '',
      'company' => $row['company'] ?? null,
      'status' => $row['status'] ?? null,
      'code' => $row['code'] ?? null,
      'assigned_at' => $row['assigned_at'] ?? null,
      'commission_rate' => isset($row['commission_rate']) ? (float)$row['commission_rate'] : null,
    ];
  }
  return $rows;
}

function representative_detail(int $representative_id): ?array {
  $rep = representative_get($representative_id);
  if (!$rep) {
    return null;
  }
  return $rep;
}

function representative_list(array $filters = []): array {
  $statusFilter = $filters['status'] ?? 'all';
  $assignedFilter = $filters['assigned'] ?? 'all';
  $search = trim($filters['q'] ?? '');
  $dealerFilter = isset($filters['dealer_id']) ? (int)$filters['dealer_id'] : 0;

  $conditions = [];
  $params = [];
  if ($statusFilter !== 'all') {
    $conditions[] = 'r.status=?';
    $params[] = $statusFilter;
  }
  if ($assignedFilter === 'assigned') {
    $conditions[] = 'EXISTS (SELECT 1 FROM dealer_representative_assignments a WHERE a.representative_id = r.id)';
  } elseif ($assignedFilter === 'unassigned') {
    $conditions[] = 'NOT EXISTS (SELECT 1 FROM dealer_representative_assignments a WHERE a.representative_id = r.id)';
  }
  if ($dealerFilter > 0) {
    $conditions[] = 'EXISTS (SELECT 1 FROM dealer_representative_assignments a WHERE a.representative_id = r.id AND a.dealer_id=?)';
    $params[] = $dealerFilter;
  }
  if ($search !== '') {
    $conditions[] = '(r.name LIKE ? OR r.email LIKE ? OR r.phone LIKE ?)';
    $like = '%'.$search.'%';
    array_push($params, $like, $like, $like);
  }

  $sql = 'SELECT r.* FROM dealer_representatives r';
  if ($conditions) {
    $sql .= ' WHERE '.implode(' AND ', $conditions);
  }
  $sql .= ' ORDER BY r.created_at DESC';

  $st = pdo()->prepare($sql);
  $st->execute($params);
  $rows = [];
  foreach ($st as $row) {
    $rep = representative_normalize_row($row);
    $rows[] = representative_hydrate_assignments($rep);
  }
  return $rows;
}

function representative_status_counts(): array {
  $summary = [
    'total' => 0,
    REPRESENTATIVE_STATUS_ACTIVE => 0,
    REPRESENTATIVE_STATUS_INACTIVE => 0,
    'assigned' => 0,
    'unassigned' => 0,
  ];
  $st = pdo()->query("SELECT status, COUNT(*) AS c FROM dealer_representatives GROUP BY status");
  foreach ($st as $row) {
    $status = $row['status'] ?? REPRESENTATIVE_STATUS_ACTIVE;
    $summary[$status] = (int)($row['c'] ?? 0);
    $summary['total'] += (int)($row['c'] ?? 0);
  }
  $assigned = (int)pdo()->query('SELECT COUNT(DISTINCT representative_id) FROM dealer_representative_assignments')->fetchColumn();
  $summary['assigned'] = $assigned;
  $summary['unassigned'] = max(0, $summary['total'] - $assigned);
  return $summary;
}

function representative_for_dealer(int $dealer_id): ?array {
  if ($dealer_id <= 0) {
    return null;
  }
  $sql = "SELECT r.*, a.assigned_at AS assignment_date
          FROM dealer_representative_assignments a
          INNER JOIN dealer_representatives r ON r.id = a.representative_id
          WHERE a.dealer_id=? LIMIT 1";
  $st = pdo()->prepare($sql);
  $st->execute([$dealer_id]);
  $row = $st->fetch();
  if (!$row) {
    return null;
  }
  $rep = representative_hydrate_assignments(representative_normalize_row($row));
  foreach ($rep['dealers'] as $dealer) {
    if ((int)$dealer['id'] === $dealer_id) {
      $rep['dealer_id'] = (int)$dealer['id'];
      $rep['dealer_name'] = $dealer['name'] ?? null;
      $rep['dealer_company'] = $dealer['company'] ?? null;
      $rep['dealer_status'] = $dealer['status'] ?? null;
      $rep['dealer_code'] = $dealer['code'] ?? null;
      if (isset($dealer['commission_rate']) && $dealer['commission_rate'] !== null) {
        $rep['commission_rate'] = (float)$dealer['commission_rate'];
        $rep['dealer_commission_rate'] = (float)$dealer['commission_rate'];
      }
      $rep['assigned_at'] = $dealer['assigned_at'] ?? ($row['assignment_date'] ?? null);
      break;
    }
  }
  return $rep;
}

function representative_update_assignments(int $representative_id, array $dealer_ids, ?array $commission_rates = null): void {
  $rep = representative_fetch_raw($representative_id);
  if (!$rep) {
    throw new InvalidArgumentException('Temsilci kaydı bulunamadı.');
  }
  $dealer_ids = array_values(array_unique(array_filter(array_map('intval', $dealer_ids), fn($id) => $id > 0)));
  $rateMap = [];
  if ($commission_rates) {
    foreach ($commission_rates as $dealerId => $rate) {
      $dealerId = (int)$dealerId;
      if ($dealerId <= 0) {
        continue;
      }
      $rateMap[$dealerId] = representative_sanitize_commission_rate($rate, $rep['commission_rate'] ?? null);
    }
  }
  $pdo = pdo();
  $pdo->beginTransaction();
  try {
    $hasAssignmentRate = representative_assignments_support_commission_rate();
    if ($dealer_ids) {
      $placeholders = implode(',', array_fill(0, count($dealer_ids), '?'));
      $check = $pdo->prepare("SELECT id FROM dealers WHERE id IN ($placeholders)");
      $check->execute($dealer_ids);
      $found = array_map('intval', $check->fetchAll(PDO::FETCH_COLUMN));
      sort($found);
      $sortedInput = $dealer_ids;
      sort($sortedInput);
      if ($found !== $sortedInput) {
        throw new InvalidArgumentException('Seçilen bayilerden bazıları bulunamadı.');
      }

      $conflictSql = "SELECT dealer_id FROM dealer_representative_assignments WHERE dealer_id IN ($placeholders) AND representative_id <> ?";
      $conflictStmt = $pdo->prepare($conflictSql);
      $conflictParams = $dealer_ids;
      $conflictParams[] = $representative_id;
      $conflictStmt->execute($conflictParams);
      $conflicts = $conflictStmt->fetchAll(PDO::FETCH_COLUMN);
      if ($conflicts) {
        throw new InvalidArgumentException('Seçilen bayilerden bazıları başka bir temsilciye atanmış.');
      }
    }

    $currentIds = representative_assigned_dealer_ids($representative_id);
    $toRemove = array_diff($currentIds, $dealer_ids);
    $toAdd = array_diff($dealer_ids, $currentIds);

    if ($toRemove) {
      $placeholders = implode(',', array_fill(0, count($toRemove), '?'));
      $delete = $pdo->prepare("DELETE FROM dealer_representative_assignments WHERE representative_id=? AND dealer_id IN ($placeholders)");
      $delete->execute(array_merge([$representative_id], array_values($toRemove)));
    }

    $insertSql = $hasAssignmentRate
      ? 'INSERT INTO dealer_representative_assignments (representative_id, dealer_id, assigned_at, commission_rate) VALUES (?,?,?,?)'
      : 'INSERT INTO dealer_representative_assignments (representative_id, dealer_id, assigned_at) VALUES (?,?,?)';
    $insertStmt = $pdo->prepare($insertSql);
    foreach ($toAdd as $dealer_id) {
      $rate = $rateMap[$dealer_id] ?? ($rep['commission_rate'] ?? 0.0);
      $params = [$representative_id, $dealer_id, now()];
      if ($hasAssignmentRate) {
        $params[] = number_format($rate, 2, '.', '');
      }
      $insertStmt->execute($params);
    }

    if ($hasAssignmentRate && $rateMap) {
      $update = $pdo->prepare('UPDATE dealer_representative_assignments SET commission_rate=? WHERE representative_id=? AND dealer_id=?');
      foreach ($dealer_ids as $dealer_id) {
        if (!array_key_exists($dealer_id, $rateMap)) {
          continue;
        }
        $rate = number_format($rateMap[$dealer_id], 2, '.', '');
        $update->execute([$rate, $representative_id, $dealer_id]);
      }
    } elseif (!$hasAssignmentRate && $rateMap) {
      $firstRate = reset($rateMap);
      $normalizedRate = representative_sanitize_commission_rate($firstRate, $rep['commission_rate'] ?? null);
      try {
        $pdo->prepare('UPDATE dealer_representatives SET commission_rate=?, updated_at=? WHERE id=?')
            ->execute([number_format($normalizedRate, 2, '.', ''), now(), $representative_id]);
      } catch (Throwable $ignored) {}
    }

    representative_refresh_primary_assignment($representative_id, $pdo);

    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}

function representative_refresh_primary_assignment(int $representative_id, ?PDO $pdo = null): void {
  $pdo = $pdo ?: pdo();
  $st = $pdo->prepare('SELECT dealer_id, assigned_at FROM dealer_representative_assignments WHERE representative_id=? ORDER BY assigned_at ASC, dealer_id ASC LIMIT 1');
  $st->execute([$representative_id]);
  $row = $st->fetch();
  $primaryDealerId = $row ? (int)$row['dealer_id'] : null;
  $assignedAt = $row['assigned_at'] ?? null;
  try {
    $pdo->prepare('UPDATE dealer_representatives SET dealer_id=?, assigned_at=?, updated_at=? WHERE id=?')
        ->execute([$primaryDealerId, $assignedAt, now(), $representative_id]);
  } catch (Throwable $e) {
    try {
      $pdo->prepare('UPDATE dealer_representatives SET dealer_id=?, updated_at=? WHERE id=?')
          ->execute([$primaryDealerId, now(), $representative_id]);
    } catch (Throwable $ignored) {}
  }
}

function representative_update_assignment_rates(int $representative_id, array $rates): void {
  $rep = representative_fetch_raw($representative_id);
  if (!$rep) {
    throw new InvalidArgumentException('Temsilci kaydı bulunamadı.');
  }
  if (!$rates) {
    return;
  }
  $pdo = pdo();
  $pdo->beginTransaction();
  try {
    if (!representative_assignments_support_commission_rate()) {
      $first = reset($rates);
      if ($first !== false) {
        $normalized = representative_sanitize_commission_rate($first, $rep['commission_rate'] ?? null);
        try {
          $pdo->prepare('UPDATE dealer_representatives SET commission_rate=?, updated_at=? WHERE id=?')
              ->execute([number_format($normalized, 2, '.', ''), now(), $representative_id]);
        } catch (Throwable $ignored) {}
      }
      $pdo->commit();
      return;
    }
    $check = $pdo->prepare('SELECT 1 FROM dealer_representative_assignments WHERE representative_id=? AND dealer_id=? LIMIT 1');
    $update = $pdo->prepare('UPDATE dealer_representative_assignments SET commission_rate=? WHERE representative_id=? AND dealer_id=?');
    foreach ($rates as $dealerId => $rate) {
      $dealerId = (int)$dealerId;
      if ($dealerId <= 0) {
        continue;
      }
      $check->execute([$representative_id, $dealerId]);
      if (!$check->fetchColumn()) {
        continue;
      }
      $normalized = representative_sanitize_commission_rate($rate, $rep['commission_rate'] ?? null);
      $update->execute([number_format($normalized, 2, '.', ''), $representative_id, $dealerId]);
    }
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}

function representative_create(array $data): int {
  $name = trim($data['name'] ?? '');
  $email = trim($data['email'] ?? '');
  $phone = trim($data['phone'] ?? '');
  $status = $data['status'] ?? REPRESENTATIVE_STATUS_ACTIVE;
  $commissionRate = isset($data['commission_rate']) ? (float)$data['commission_rate'] : 10.0;
  $password = $data['password'] ?? '';

  if ($name === '' || $email === '') {
    throw new InvalidArgumentException('Temsilci adı ve e-posta adresi zorunludur.');
  }
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    throw new InvalidArgumentException('Geçerli bir e-posta adresi belirtin.');
  }
  if ($password === '') {
    throw new InvalidArgumentException('Yeni temsilci için bir şifre belirleyin.');
  }
  if (!in_array($status, [REPRESENTATIVE_STATUS_ACTIVE, REPRESENTATIVE_STATUS_INACTIVE], true)) {
    $status = REPRESENTATIVE_STATUS_ACTIVE;
  }

  $commissionRate = max(0.0, min(100.0, $commissionRate));

  $duplicateCheck = pdo()->prepare('SELECT id FROM dealer_representatives WHERE email=? LIMIT 1');
  $duplicateCheck->execute([$email]);
  if ($duplicateCheck->fetchColumn()) {
    throw new InvalidArgumentException('Bu e-posta adresi başka bir temsilcide kayıtlı.');
  }

  $hash = password_hash($password, PASSWORD_DEFAULT);

  $pdo = pdo();
  $pdo->prepare('INSERT INTO dealer_representatives (dealer_id, assigned_at, name, email, phone, password_hash, commission_rate, status, created_at, updated_at)
                 VALUES (NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?)')
      ->execute([
        $name,
        $email,
        $phone !== '' ? $phone : null,
        $hash,
        number_format($commissionRate, 2, '.', ''),
        $status,
        now(),
        now(),
      ]);

  $repId = (int)$pdo->lastInsertId();
  $dealerIds = [];
  if (!empty($data['dealer_ids']) && is_array($data['dealer_ids'])) {
    $dealerIds = array_map('intval', $data['dealer_ids']);
  }
  if (!empty($data['dealer_id'])) {
    $dealerIds[] = (int)$data['dealer_id'];
  }
  $dealerIds = array_values(array_unique(array_filter($dealerIds, fn($id) => $id > 0)));
  if ($dealerIds) {
    representative_update_assignments($repId, $dealerIds);
  }
  return $repId;
}

function representative_update(int $representative_id, array $data): void {
  $rep = representative_fetch_raw($representative_id);
  if (!$rep) {
    throw new InvalidArgumentException('Temsilci kaydı bulunamadı.');
  }
  $name = trim($data['name'] ?? $rep['name']);
  $email = trim($data['email'] ?? $rep['email']);
  $phone = trim($data['phone'] ?? ($rep['phone'] ?? ''));
  $status = $data['status'] ?? ($rep['status'] ?? REPRESENTATIVE_STATUS_ACTIVE);
  $commissionRate = isset($data['commission_rate']) ? (float)$data['commission_rate'] : ($rep['commission_rate'] ?? 10.0);

  if ($name === '' || $email === '') {
    throw new InvalidArgumentException('Temsilci adı ve e-posta adresi zorunludur.');
  }
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    throw new InvalidArgumentException('Geçerli bir e-posta adresi belirtin.');
  }
  if (!in_array($status, [REPRESENTATIVE_STATUS_ACTIVE, REPRESENTATIVE_STATUS_INACTIVE], true)) {
    $status = REPRESENTATIVE_STATUS_ACTIVE;
  }
  $commissionRate = max(0.0, min(100.0, $commissionRate));

  $duplicateCheck = pdo()->prepare('SELECT id FROM dealer_representatives WHERE email=? AND id<>? LIMIT 1');
  $duplicateCheck->execute([$email, $representative_id]);
  if ($duplicateCheck->fetchColumn()) {
    throw new InvalidArgumentException('Bu e-posta adresi başka bir temsilcide kayıtlı.');
  }

  pdo()->prepare('UPDATE dealer_representatives SET name=?, email=?, phone=?, commission_rate=?, status=?, updated_at=? WHERE id=?')
      ->execute([
        $name,
        $email,
        $phone !== '' ? $phone : null,
        number_format($commissionRate, 2, '.', ''),
        $status,
        now(),
        $representative_id,
      ]);

  if (!empty($data['password'])) {
    representative_update_password($representative_id, $data['password']);
  }
}

function representative_assign_to_dealer(int $representative_id, ?int $dealer_id): void {
  $dealerIds = ($dealer_id && $dealer_id > 0) ? [$dealer_id] : [];
  representative_update_assignments($representative_id, $dealerIds);
}

function representative_unassign_dealer(int $dealer_id): void {
  if ($dealer_id <= 0) {
    return;
  }
  $pdo = pdo();
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare('SELECT representative_id FROM dealer_representative_assignments WHERE dealer_id=?');
    $st->execute([$dealer_id]);
    $repIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $pdo->prepare('DELETE FROM dealer_representative_assignments WHERE dealer_id=?')
        ->execute([$dealer_id]);
    foreach ($repIds as $repId) {
      representative_refresh_primary_assignment($repId, $pdo);
    }
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}

function representative_update_password(int $representative_id, string $plainPassword): void {
  if ($plainPassword === '') {
    throw new InvalidArgumentException('Şifre boş olamaz.');
  }
  $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
  pdo()->prepare('UPDATE dealer_representatives SET password_hash=?, updated_at=? WHERE id=?')
      ->execute([$hash, now(), (int)$representative_id]);
}

function representative_record_login(int $representative_id): void {
  pdo()->prepare('UPDATE dealer_representatives SET last_login_at=?, updated_at=? WHERE id=?')
      ->execute([now(), now(), (int)$representative_id]);
}

function representative_create_commission_for_topup(int $topup_id, int $dealer_id, int $amount_cents): void {
  // Top-up işlemleri temsilci komisyonu oluşturmaz.
  return;
}

function representative_fetch_package_purchase(int $purchase_id): ?array {
  $purchase_id = (int)$purchase_id;
  if ($purchase_id <= 0) {
    return null;
  }
  $sql = "SELECT pp.*, pkg.name AS package_name, pkg.price_cents AS package_price"
       . " FROM dealer_package_purchases pp"
       . " INNER JOIN dealer_packages pkg ON pkg.id = pp.package_id"
       . " WHERE pp.id=? LIMIT 1";
  $st = pdo()->prepare($sql);
  $st->execute([$purchase_id]);
  $row = $st->fetch();
  if (!$row) {
    return null;
  }
  $row['id'] = (int)$row['id'];
  $row['dealer_id'] = (int)$row['dealer_id'];
  $row['package_id'] = (int)$row['package_id'];
  $row['price_cents'] = (int)$row['price_cents'];
  $row['package_price'] = (int)$row['package_price'];
  $row['created_at'] = $row['created_at'] ?? now();
  $row['source'] = $row['source'] ?? 'dealer';
  $row['lead_event_id'] = $row['lead_event_id'] !== null ? (int)$row['lead_event_id'] : null;
  return $row;
}

function representative_package_purchase_site_order_id(array $purchase): ?int {
  if (empty($purchase['lead_event_id'])) {
    return null;
  }
  try {
    $st = pdo()->prepare('SELECT id FROM site_orders WHERE event_id=? ORDER BY id DESC LIMIT 1');
    $st->execute([(int)$purchase['lead_event_id']]);
    $orderId = $st->fetchColumn();
    return $orderId ? (int)$orderId : null;
  } catch (Throwable $e) {
    return null;
  }
}

function representative_create_commission_for_package_purchase(int $purchase_id): void {
  $purchase = representative_fetch_package_purchase($purchase_id);
  if (!$purchase) {
    return;
  }
  $dealerId = (int)$purchase['dealer_id'];
  if ($dealerId <= 0) {
    return;
  }
  $rep = representative_for_dealer($dealerId);
  if (!$rep || ($rep['status'] ?? REPRESENTATIVE_STATUS_ACTIVE) !== REPRESENTATIVE_STATUS_ACTIVE) {
    return;
  }
  $pdo = pdo();
  $check = $pdo->prepare('SELECT id FROM dealer_representative_commissions WHERE package_purchase_id=? LIMIT 1');
  $check->execute([$purchase['id']]);
  if ($check->fetch()) {
    return;
  }

  $rate = $rep['dealer_commission_rate'] ?? ($rep['commission_rate'] ?? 0);
  $rate = representative_sanitize_commission_rate($rate, 0.0);
  if ($rate <= 0) {
    return;
  }

  $amount = (int)$purchase['price_cents'];
  if ($amount <= 0) {
    $amount = (int)$purchase['package_price'];
  }
  if ($amount <= 0) {
    return;
  }

  $commission = (int)round($amount * ($rate / 100));
  if ($commission <= 0) {
    return;
  }

  $createdAt = $purchase['created_at'] ?? now();
  try {
    $availableAt = (new DateTime($createdAt))->modify('+30 days')->format('Y-m-d H:i:s');
  } catch (Throwable $e) {
    $availableAt = (new DateTime())->modify('+30 days')->format('Y-m-d H:i:s');
  }

  $source = strtolower((string)$purchase['source']);
  $sourceType = 'package';
  if ($source === (defined('DEALER_PURCHASE_SOURCE_LEAD') ? DEALER_PURCHASE_SOURCE_LEAD : 'lead')) {
    $sourceType = 'site_order';
  }
  $sourceLabel = $sourceType === 'site_order'
    ? 'Web Satışı'
    : 'Paket Satışı';
  if (!empty($purchase['package_name'])) {
    $sourceLabel .= ': '.$purchase['package_name'];
  }

  $siteOrderId = representative_package_purchase_site_order_id($purchase);

  $pdo->prepare('INSERT INTO dealer_representative_commissions (representative_id, dealer_id, package_purchase_id, site_order_id, source_type, source_label, commission_rate, amount_cents, commission_cents, status, created_at, available_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([
        (int)$rep['id'],
        $dealerId,
        $purchase['id'],
        $siteOrderId,
        $sourceType,
        $sourceLabel,
        number_format($rate, 4, '.', ''),
        $amount,
        $commission,
        REPRESENTATIVE_COMMISSION_STATUS_PENDING,
        $createdAt,
        $availableAt,
      ]);
}

function representative_commission_totals(int $representative_id, ?int $dealer_id = null): array {
  $summary = [
    'pending_amount'    => 0,
    'pending_count'     => 0,
    'approved_amount'   => 0,
    'approved_count'    => 0,
    'paid_amount'       => 0,
    'paid_count'        => 0,
    'rejected_amount'   => 0,
    'rejected_count'    => 0,
    'total_amount'      => 0,
    'total_count'       => 0,
    'available_amount'  => 0,
    'available_count'   => 0,
    'next_release_at'   => null,
  ];

  $sql = 'SELECT status, COUNT(*) AS c, COALESCE(SUM(commission_cents),0) AS total'
       . ' FROM dealer_representative_commissions'
       . ' WHERE representative_id=?';
  $params = [$representative_id];
  if ($dealer_id) {
    $sql .= ' AND dealer_id=?';
    $params[] = $dealer_id;
  }
  $sql .= ' GROUP BY status';

  $st = pdo()->prepare($sql);
  $st->execute($params);
  foreach ($st as $row) {
    $status = (string)($row['status'] ?? '');
    $total = (int)($row['total'] ?? 0);
    $count = (int)($row['c'] ?? 0);
    switch ($status) {
      case REPRESENTATIVE_COMMISSION_STATUS_PAID:
        $summary['paid_amount'] += $total;
        $summary['paid_count']  += $count;
        break;
      case REPRESENTATIVE_COMMISSION_STATUS_APPROVED:
        $summary['approved_amount'] += $total;
        $summary['approved_count']  += $count;
        break;
      case REPRESENTATIVE_COMMISSION_STATUS_REJECTED:
        $summary['rejected_amount'] += $total;
        $summary['rejected_count']  += $count;
        break;
      default:
        $summary['pending_amount'] += $total;
        $summary['pending_count']  += $count;
        break;
    }
    $summary['total_amount'] += $total;
    $summary['total_count']  += $count;
  }

  $available = representative_commission_available_summary($representative_id, $dealer_id);
  $summary['available_amount'] = $available['amount'] ?? 0;
  $summary['available_count'] = $available['count'] ?? 0;
  $summary['next_release_at'] = $available['next_release_at'] ?? null;

  return $summary;
}

function representative_recent_commissions(int $representative_id, int $limit = 20, ?int $dealer_id = null): array {
  $limit = max(1, $limit);
  $sql = "SELECT c.*, pp.created_at AS purchase_created_at, pp.id AS purchase_id, pp.price_cents AS purchase_price_cents,"
       . " pkg.name AS package_name, d.name AS dealer_name,"
       . " so.id AS site_order_id, so.price_cents AS order_price_cents, so.paid_at AS order_paid_at, so.customer_name, so.customer_email,"
       . " COALESCE(pp.created_at, so.paid_at, so.created_at, c.created_at) AS activity_at"
       . " FROM dealer_representative_commissions c"
       . " LEFT JOIN dealer_package_purchases pp ON pp.id = c.package_purchase_id"
       . " LEFT JOIN site_orders so ON so.id = c.site_order_id"
       . " LEFT JOIN dealer_packages pkg ON pkg.id = COALESCE(pp.package_id, so.package_id)"
       . " LEFT JOIN dealers d ON d.id = c.dealer_id"
       . " WHERE c.representative_id=?";
  $params = [$representative_id];
  if ($dealer_id) {
    $sql .= ' AND c.dealer_id=?';
    $params[] = $dealer_id;
  }
  $sql .= ' ORDER BY COALESCE(pp.created_at, so.paid_at, so.created_at, c.created_at) DESC, c.id DESC LIMIT ?';
  $params[] = $limit;

  $st = pdo()->prepare($sql);
  foreach ($params as $idx => $value) {
    $st->bindValue($idx + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
  }
  $st->execute();
  $rows = [];
  foreach ($st as $row) {
    $saleAmount = isset($row['purchase_price_cents']) ? (int)$row['purchase_price_cents'] : null;
    if ($saleAmount === null || $saleAmount <= 0) {
      $saleAmount = isset($row['order_price_cents']) ? (int)$row['order_price_cents'] : null;
    }
    if ($saleAmount === null || $saleAmount <= 0) {
      $saleAmount = isset($row['amount_cents']) ? (int)$row['amount_cents'] : 0;
    }
    $rows[] = [
      'id' => (int)$row['id'],
      'package_purchase_id' => isset($row['package_purchase_id']) ? (int)$row['package_purchase_id'] : null,
      'site_order_id' => isset($row['site_order_id']) ? (int)$row['site_order_id'] : null,
      'commission_cents' => (int)$row['commission_cents'],
      'amount_cents' => $saleAmount,
      'status' => $row['status'] ?? REPRESENTATIVE_COMMISSION_STATUS_PENDING,
      'notes' => $row['notes'] ?? null,
      'created_at' => $row['created_at'] ?? null,
      'available_at' => $row['available_at'] ?? null,
      'paid_at' => $row['paid_at'] ?? null,
      'approved_at' => $row['approved_at'] ?? null,
      'dealer_id' => isset($row['dealer_id']) ? (int)$row['dealer_id'] : null,
      'dealer_name' => $row['dealer_name'] ?? null,
      'source_type' => $row['source_type'] ?? 'package',
      'source_label' => $row['source_label'] ?? null,
      'commission_rate' => isset($row['commission_rate']) ? (float)$row['commission_rate'] : null,
      'purchase_created_at' => $row['purchase_created_at'] ?? null,
      'package_name' => $row['package_name'] ?? null,
      'order_paid_at' => $row['order_paid_at'] ?? null,
      'customer_name' => $row['customer_name'] ?? null,
      'customer_email' => $row['customer_email'] ?? null,
      'activity_at' => $row['activity_at'] ?? null,
    ];
  }
  return $rows;
}

function representative_commission_update_status(int $commission_id, string $status, array $options = []): void {
  $commission_id = (int)$commission_id;
  if ($commission_id <= 0) {
    throw new InvalidArgumentException('Komisyon kaydı bulunamadı.');
  }
  $status = strtolower(trim($status));
  $allowed = [
    REPRESENTATIVE_COMMISSION_STATUS_PENDING,
    REPRESENTATIVE_COMMISSION_STATUS_APPROVED,
    REPRESENTATIVE_COMMISSION_STATUS_PAID,
    REPRESENTATIVE_COMMISSION_STATUS_REJECTED,
  ];
  if (!in_array($status, $allowed, true)) {
    throw new InvalidArgumentException('Geçersiz durum seçildi.');
  }
  $noteProvided = array_key_exists('note', $options);
  $note = $noteProvided ? trim((string)$options['note']) : null;

  $pdo = pdo();
  $ownTxn = !$pdo->inTransaction();
  if ($ownTxn) {
    $pdo->beginTransaction();
  }
  try {
    $st = $pdo->prepare('SELECT * FROM dealer_representative_commissions WHERE id=? FOR UPDATE');
    $st->execute([$commission_id]);
    $row = $st->fetch();
    if (!$row) {
      throw new RuntimeException('Komisyon kaydı bulunamadı.');
    }

    $currentStatus = $row['status'] ?? REPRESENTATIVE_COMMISSION_STATUS_PENDING;
    $approvedAt = $row['approved_at'] ?? null;
    $paidAt = $row['paid_at'] ?? null;

    if ($status === $currentStatus && !$noteProvided) {
      if ($ownTxn) {
        $pdo->commit();
      }
      return;
    }

    $now = now();
    switch ($status) {
      case REPRESENTATIVE_COMMISSION_STATUS_PENDING:
        $approvedAt = null;
        $paidAt = null;
        break;
      case REPRESENTATIVE_COMMISSION_STATUS_APPROVED:
        $approvedAt = $approvedAt ?: $now;
        $paidAt = null;
        break;
      case REPRESENTATIVE_COMMISSION_STATUS_PAID:
        $approvedAt = $approvedAt ?: $now;
        $paidAt = $now;
        break;
      case REPRESENTATIVE_COMMISSION_STATUS_REJECTED:
        $paidAt = null;
        $approvedAt = null;
        break;
    }

    $notesValue = $noteProvided ? ($note !== '' ? $note : null) : ($row['notes'] ?? null);

    $update = $pdo->prepare('UPDATE dealer_representative_commissions SET status=?, notes=?, approved_at=?, paid_at=? WHERE id=?');
    $update->execute([$status, $notesValue, $approvedAt, $paidAt, $commission_id]);

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

function representative_commission_admin_list(array $filters = []): array {
  $statuses = $filters['statuses'] ?? [];
  $representativeId = isset($filters['representative_id']) ? (int)$filters['representative_id'] : 0;
  $dealerId = isset($filters['dealer_id']) ? (int)$filters['dealer_id'] : 0;
  $limit = isset($filters['limit']) ? (int)$filters['limit'] : 20;
  $limit = max(1, min($limit, 200));

  $conditions = [];
  $params = [];

  if (!empty($statuses)) {
    $statuses = array_values(array_filter(array_map('strval', $statuses)));
    if ($statuses) {
      $placeholders = implode(',', array_fill(0, count($statuses), '?'));
      $conditions[] = 'c.status IN ('.$placeholders.')';
      foreach ($statuses as $st) {
        $params[] = $st;
      }
    }
  }

  if ($representativeId > 0) {
    $conditions[] = 'c.representative_id=?';
    $params[] = $representativeId;
  }

  if ($dealerId > 0) {
    $conditions[] = 'c.dealer_id=?';
    $params[] = $dealerId;
  }

  $sql = "SELECT c.*, r.name AS representative_name, r.email AS representative_email, r.phone AS representative_phone,"
       . " d.id AS dealer_id, d.name AS dealer_name, d.code AS dealer_code,"
       . " pp.price_cents AS purchase_price_cents, pp.status AS purchase_status, pp.created_at AS purchase_created_at,"
       . " pkg.name AS package_name,"
       . " so.id AS site_order_id, so.price_cents AS order_price_cents, so.paid_at AS order_paid_at,"
       . " so.customer_name, so.customer_email"
       . " FROM dealer_representative_commissions c"
       . " INNER JOIN dealer_representatives r ON r.id = c.representative_id"
       . " LEFT JOIN dealers d ON d.id = c.dealer_id"
       . " LEFT JOIN dealer_package_purchases pp ON pp.id = c.package_purchase_id"
       . " LEFT JOIN site_orders so ON so.id = c.site_order_id"
       . " LEFT JOIN dealer_packages pkg ON pkg.id = COALESCE(pp.package_id, so.package_id)";
  if ($conditions) {
    $sql .= ' WHERE '.implode(' AND ', $conditions);
  }
  $sql .= ' ORDER BY c.created_at DESC LIMIT ?';

  $st = pdo()->prepare($sql);
  $position = 1;
  foreach ($params as $param) {
    $st->bindValue($position++, $param);
  }
  $st->bindValue($position, $limit, PDO::PARAM_INT);
  $st->execute();

  $rows = [];
  foreach ($st as $row) {
    $rows[] = [
      'id' => (int)$row['id'],
      'representative_id' => (int)$row['representative_id'],
      'representative_name' => $row['representative_name'] ?? null,
      'representative_email' => $row['representative_email'] ?? null,
      'representative_phone' => $row['representative_phone'] ?? null,
      'dealer_id' => isset($row['dealer_id']) ? (int)$row['dealer_id'] : null,
      'dealer_name' => $row['dealer_name'] ?? null,
      'dealer_code' => $row['dealer_code'] ?? null,
      'package_purchase_id' => isset($row['package_purchase_id']) ? (int)$row['package_purchase_id'] : null,
      'site_order_id' => isset($row['site_order_id']) ? (int)$row['site_order_id'] : null,
      'amount_cents' => isset($row['amount_cents']) ? (int)$row['amount_cents'] : 0,
      'commission_cents' => isset($row['commission_cents']) ? (int)$row['commission_cents'] : 0,
      'amount_cents' => $saleAmount,
      'status' => $row['status'] ?? REPRESENTATIVE_COMMISSION_STATUS_PENDING,
      'notes' => $row['notes'] ?? null,
      'created_at' => $row['created_at'] ?? null,
      'available_at' => $row['available_at'] ?? null,
      'approved_at' => $row['approved_at'] ?? null,
      'paid_at' => $row['paid_at'] ?? null,
      'source_type' => $row['source_type'] ?? 'package',
      'source_label' => $row['source_label'] ?? null,
      'commission_rate' => isset($row['commission_rate']) ? (float)$row['commission_rate'] : null,
      'purchase_price_cents' => isset($row['purchase_price_cents']) ? (int)$row['purchase_price_cents'] : null,
      'purchase_status' => $row['purchase_status'] ?? null,
      'purchase_created_at' => $row['purchase_created_at'] ?? null,
      'package_name' => $row['package_name'] ?? null,
      'order_price_cents' => isset($row['order_price_cents']) ? (int)$row['order_price_cents'] : null,
      'order_paid_at' => $row['order_paid_at'] ?? null,
      'customer_name' => $row['customer_name'] ?? null,
      'customer_email' => $row['customer_email'] ?? null,
    ];
  }
  return $rows;
}
function representative_recent_sales(int $representative_id, int $limit = 20, ?int $dealer_id = null): array {
  $limit = max(1, $limit);
  $sql = "SELECT c.id AS commission_id, c.status AS commission_status, c.commission_cents, c.available_at, c.approved_at, c.paid_at,"
       . " c.created_at AS commission_created_at, c.source_type, c.source_label, c.notes, c.dealer_id,"
       . " pp.id AS package_purchase_id, pp.price_cents AS package_price_cents, pp.created_at AS package_created_at,"
       . " pp.status AS package_status, pp.source AS package_source,"
       . " so.id AS site_order_id, so.price_cents AS order_price_cents, so.paid_at AS order_paid_at, so.customer_name, so.customer_email,"
       . " pkg.name AS package_name, c.amount_cents AS commission_amount_cents,"
       . " COALESCE(pp.created_at, so.paid_at, so.created_at, c.created_at) AS activity_at"
       . " FROM dealer_representative_commissions c"
       . " LEFT JOIN dealer_package_purchases pp ON pp.id = c.package_purchase_id"
       . " LEFT JOIN site_orders so ON so.id = c.site_order_id"
       . " LEFT JOIN dealer_packages pkg ON pkg.id = COALESCE(pp.package_id, so.package_id)"
       . " WHERE c.representative_id=?";
  $params = [$representative_id];
  if ($dealer_id) {
    $sql .= ' AND c.dealer_id=?';
    $params[] = $dealer_id;
  }
  $sql .= ' ORDER BY COALESCE(pp.created_at, so.paid_at, so.created_at, c.created_at) DESC, c.id DESC LIMIT ?';
  $params[] = $limit;

  $st = pdo()->prepare($sql);
  foreach ($params as $idx => $value) {
    $st->bindValue($idx + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
  }
  $st->bindValue(count($params), $limit, PDO::PARAM_INT);
  $st->execute();

  $rows = [];
  foreach ($st as $row) {
    $saleAmount = isset($row['package_price_cents']) ? (int)$row['package_price_cents'] : null;
    if ($saleAmount === null || $saleAmount <= 0) {
      $saleAmount = isset($row['order_price_cents']) ? (int)$row['order_price_cents'] : null;
    }
    if ($saleAmount === null || $saleAmount <= 0) {
      $saleAmount = isset($row['commission_amount_cents']) ? (int)$row['commission_amount_cents'] : 0;
    }
    $rows[] = [
      'commission_id' => isset($row['commission_id']) ? (int)$row['commission_id'] : 0,
      'commission_status' => $row['commission_status'] ?? null,
      'commission_cents' => isset($row['commission_cents']) ? (int)$row['commission_cents'] : 0,
      'available_at' => $row['available_at'] ?? null,
      'approved_at' => $row['approved_at'] ?? null,
      'paid_at' => $row['paid_at'] ?? null,
      'created_at' => $row['commission_created_at'] ?? null,
      'package_purchase_id' => isset($row['package_purchase_id']) ? (int)$row['package_purchase_id'] : null,
      'package_status' => $row['package_status'] ?? null,
      'package_name' => $row['package_name'] ?? null,
      'package_source' => $row['package_source'] ?? null,
      'site_order_id' => isset($row['site_order_id']) ? (int)$row['site_order_id'] : null,
      'order_paid_at' => $row['order_paid_at'] ?? null,
      'customer_name' => $row['customer_name'] ?? null,
      'customer_email' => $row['customer_email'] ?? null,
      'dealer_id' => isset($row['dealer_id']) ? (int)$row['dealer_id'] : null,
      'source_type' => $row['source_type'] ?? 'package',
      'source_label' => $row['source_label'] ?? null,
      'notes' => $row['notes'] ?? null,
      'sale_amount_cents' => $saleAmount,
      'sale_at' => $row['activity_at'] ?? ($row['commission_created_at'] ?? null),
    ];
  }
  return $rows;
}

function representative_completed_topups(int $representative_id, int $limit = 20, ?int $dealer_id = null): array {
  return representative_recent_sales($representative_id, $limit, $dealer_id);
}
function representative_has_commission(int $representative_id, int $package_purchase_id): bool {
  $st = pdo()->prepare('SELECT 1 FROM dealer_representative_commissions WHERE representative_id=? AND package_purchase_id=? LIMIT 1');
  $st->execute([$representative_id, $package_purchase_id]);
  return (bool)$st->fetchColumn();
}

function representative_admin_commission_overview(): array {
  $summary = [
    'pending_amount'  => 0,
    'pending_count'   => 0,
    'approved_amount' => 0,
    'approved_count'  => 0,
    'paid_amount'     => 0,
    'paid_count'      => 0,
    'rejected_amount' => 0,
    'rejected_count'  => 0,
    'total_amount'    => 0,
    'total_count'     => 0,
  ];
  try {
    $sql = 'SELECT status, COUNT(*) AS c, COALESCE(SUM(commission_cents),0) AS total
            FROM dealer_representative_commissions
            GROUP BY status';
    foreach (pdo()->query($sql) as $row) {
      $status = $row['status'] ?? '';
      $count = (int)($row['c'] ?? 0);
      $total = (int)($row['total'] ?? 0);
      switch ($status) {
        case REPRESENTATIVE_COMMISSION_STATUS_PAID:
          $summary['paid_amount'] += $total;
          $summary['paid_count']  += $count;
          break;
        case REPRESENTATIVE_COMMISSION_STATUS_APPROVED:
          $summary['approved_amount'] += $total;
          $summary['approved_count']  += $count;
          break;
        case REPRESENTATIVE_COMMISSION_STATUS_REJECTED:
          $summary['rejected_amount'] += $total;
          $summary['rejected_count']  += $count;
          break;
        default:
          $summary['pending_amount'] += $total;
          $summary['pending_count']  += $count;
          break;
      }
      $summary['total_amount'] += $total;
      $summary['total_count']  += $count;
    }
  } catch (Throwable $e) {
    // sessizce yok say
  }
  return $summary;
}

function representative_commission_leaderboard(int $limit = 5): array {
  $limit = max(1, min($limit, 50));
  try {
    $sql = 'SELECT r.id, r.name, r.email, r.phone, r.status,
                   COUNT(*) AS total_commission_count,
                   SUM(CASE WHEN c.status = "paid" THEN 1 ELSE 0 END) AS paid_commission_count,
                   SUM(CASE WHEN c.status = "approved" THEN 1 ELSE 0 END) AS approved_commission_count,
                   SUM(CASE WHEN c.status = "pending" THEN 1 ELSE 0 END) AS pending_commission_count,
                   SUM(CASE WHEN c.status = "rejected" THEN 1 ELSE 0 END) AS rejected_commission_count,
                   COALESCE(SUM(c.commission_cents),0) AS total_commission_cents,
                   COALESCE(SUM(CASE WHEN c.status = "paid" THEN c.commission_cents ELSE 0 END),0) AS paid_commission_cents,
                   COALESCE(SUM(CASE WHEN c.status = "approved" THEN c.commission_cents ELSE 0 END),0) AS approved_commission_cents,
                   COALESCE(SUM(CASE WHEN c.status = "pending" THEN c.commission_cents ELSE 0 END),0) AS pending_commission_cents,
                   COALESCE(SUM(CASE WHEN c.status = "rejected" THEN c.commission_cents ELSE 0 END),0) AS rejected_commission_cents,
                   COUNT(DISTINCT a.dealer_id) AS dealer_count,
                   MAX(c.created_at) AS latest_activity_at
            FROM dealer_representative_commissions c
            INNER JOIN dealer_representatives r ON r.id = c.representative_id
            LEFT JOIN dealer_representative_assignments a ON a.representative_id = r.id
            GROUP BY r.id, r.name, r.email, r.phone, r.status
            ORDER BY total_commission_cents DESC
            LIMIT ?';
    $st = pdo()->prepare($sql);
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    $rows = [];
    foreach ($st as $row) {
      $rows[] = [
        'id' => (int)$row['id'],
        'name' => $row['name'] ?? '',
        'email' => $row['email'] ?? null,
        'phone' => $row['phone'] ?? null,
        'status' => $row['status'] ?? REPRESENTATIVE_STATUS_ACTIVE,
        'dealer_count' => (int)($row['dealer_count'] ?? 0),
        'total_commission_cents' => (int)($row['total_commission_cents'] ?? 0),
        'paid_commission_cents' => (int)($row['paid_commission_cents'] ?? 0),
        'approved_commission_cents' => (int)($row['approved_commission_cents'] ?? 0),
        'pending_commission_cents' => (int)($row['pending_commission_cents'] ?? 0),
        'rejected_commission_cents' => (int)($row['rejected_commission_cents'] ?? 0),
        'total_commission_count' => (int)($row['total_commission_count'] ?? 0),
        'paid_commission_count' => (int)($row['paid_commission_count'] ?? 0),
        'approved_commission_count' => (int)($row['approved_commission_count'] ?? 0),
        'pending_commission_count' => (int)($row['pending_commission_count'] ?? 0),
        'rejected_commission_count' => (int)($row['rejected_commission_count'] ?? 0),
        'latest_activity_at' => $row['latest_activity_at'] ?? null,
      ];
    }
    return $rows;
  } catch (Throwable $e) {
    return [];
  }
}


function representative_commission_available_commissions(int $representative_id, ?int $dealer_id = null): array {
  $representative_id = max(0, $representative_id);
  if ($representative_id <= 0) {
    return [];
  }
  $now = now();
  $sql = "SELECT c.id, c.commission_cents, c.available_at, c.package_purchase_id"
       . " FROM dealer_representative_commissions c"
       . " LEFT JOIN representative_payout_request_commissions rpc ON rpc.commission_id = c.id"
       . " LEFT JOIN representative_payout_requests pr ON pr.id = rpc.request_id AND pr.status IN ('pending','approved')"
       . " WHERE c.representative_id=? AND c.status=? AND c.available_at IS NOT NULL AND c.available_at <= ? AND pr.id IS NULL";
  $params = [$representative_id, REPRESENTATIVE_COMMISSION_STATUS_PENDING, $now];
  if ($dealer_id) {
    $sql .= ' AND c.dealer_id=?';
    $params[] = $dealer_id;
  }
  $sql .= ' ORDER BY c.available_at ASC, c.id ASC';
  $st = pdo()->prepare($sql);
  foreach ($params as $idx => $value) {
    $st->bindValue($idx + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
  }
  $st->execute();
  $rows = [];
  foreach ($st as $row) {
    $rows[] = [
      'id' => (int)$row['id'],
      'commission_cents' => (int)($row['commission_cents'] ?? 0),
      'available_at' => $row['available_at'] ?? null,
      'package_purchase_id' => isset($row['package_purchase_id']) ? (int)$row['package_purchase_id'] : null,
    ];
  }
  return $rows;
}

function representative_commission_available_summary(int $representative_id, ?int $dealer_id = null): array {
  $eligible = representative_commission_available_commissions($representative_id, $dealer_id);
  $total = 0;
  foreach ($eligible as $row) {
    $total += (int)($row['commission_cents'] ?? 0);
  }
  $count = count($eligible);
  $nextRelease = null;
  $now = now();
  try {
    $sql = "SELECT MIN(c.available_at) AS next_release"
         . " FROM dealer_representative_commissions c"
         . " LEFT JOIN representative_payout_request_commissions rpc ON rpc.commission_id = c.id"
         . " LEFT JOIN representative_payout_requests pr ON pr.id = rpc.request_id AND pr.status IN ('pending','approved')"
         . " WHERE c.representative_id=? AND c.status=? AND c.available_at IS NOT NULL AND c.available_at > ? AND pr.id IS NULL";
    $params = [$representative_id, REPRESENTATIVE_COMMISSION_STATUS_PENDING, $now];
    if ($dealer_id) {
      $sql .= ' AND c.dealer_id=?';
      $params[] = $dealer_id;
    }
    $st = pdo()->prepare($sql);
    foreach ($params as $idx => $value) {
      $st->bindValue($idx + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $st->execute();
    $nextRelease = $st->fetchColumn() ?: null;
  } catch (Throwable $e) {
    $nextRelease = null;
  }
  return [
    'amount' => $total,
    'count' => $count,
    'next_release_at' => $nextRelease,
  ];
}

function representative_commission_global_available_summary(): array {
  $summary = [
    'amount' => 0,
    'count' => 0,
    'next_release_at' => null,
  ];
  $now = now();
  try {
    $sql = "SELECT COUNT(*) AS c, COALESCE(SUM(c.commission_cents),0) AS total"
         . " FROM dealer_representative_commissions c"
         . " LEFT JOIN representative_payout_request_commissions rpc ON rpc.commission_id = c.id"
         . " LEFT JOIN representative_payout_requests pr ON pr.id = rpc.request_id AND pr.status IN ('pending','approved')"
         . " WHERE c.status=? AND c.available_at IS NOT NULL AND c.available_at <= ? AND pr.id IS NULL";
    $st = pdo()->prepare($sql);
    $st->execute([REPRESENTATIVE_COMMISSION_STATUS_PENDING, $now]);
    if ($row = $st->fetch()) {
      $summary['amount'] = (int)($row['total'] ?? 0);
      $summary['count'] = (int)($row['c'] ?? 0);
    }
  } catch (Throwable $e) {
    // ignore
  }

  try {
    $sql = "SELECT MIN(c.available_at) AS next_release"
         . " FROM dealer_representative_commissions c"
         . " LEFT JOIN representative_payout_request_commissions rpc ON rpc.commission_id = c.id"
         . " LEFT JOIN representative_payout_requests pr ON pr.id = rpc.request_id AND pr.status IN ('pending','approved')"
         . " WHERE c.status=? AND c.available_at IS NOT NULL AND c.available_at > ? AND pr.id IS NULL";
    $st = pdo()->prepare($sql);
    $st->execute([REPRESENTATIVE_COMMISSION_STATUS_PENDING, $now]);
    $summary['next_release_at'] = $st->fetchColumn() ?: null;
  } catch (Throwable $e) {
    // ignore
  }

  return $summary;
}

function representative_payout_requests(int $representative_id, ?int $limit = null): array {
  $representative_id = max(0, $representative_id);
  if ($representative_id <= 0) {
    return [];
  }
  $sql = "SELECT r.*,
                 COALESCE(SUM(c.commission_cents),0) AS commission_total,
                 COUNT(c.id) AS commission_count"
       . " FROM representative_payout_requests r"
       . " LEFT JOIN representative_payout_request_commissions rpc ON rpc.request_id = r.id"
       . " LEFT JOIN dealer_representative_commissions c ON c.id = rpc.commission_id"
       . " WHERE r.representative_id=?"
       . " GROUP BY r.id"
       . " ORDER BY r.requested_at DESC";
  if ($limit !== null) {
    $limit = max(1, (int)$limit);
    $sql .= ' LIMIT '.(int)$limit;
  }
  $st = pdo()->prepare($sql);
  $st->bindValue(1, $representative_id, PDO::PARAM_INT);
  $st->execute();
  $rows = [];
  foreach ($st as $row) {
    $rows[] = [
      'id' => (int)$row['id'],
      'amount_cents' => (int)$row['amount_cents'],
      'status' => $row['status'] ?? REPRESENTATIVE_PAYOUT_STATUS_PENDING,
      'invoice_path' => $row['invoice_path'] ?? null,
      'invoice_url' => representative_payout_invoice_url($row['invoice_path'] ?? null),
      'note' => $row['note'] ?? null,
      'requested_at' => $row['requested_at'] ?? null,
      'reviewed_at' => $row['reviewed_at'] ?? null,
      'reviewed_by' => isset($row['reviewed_by']) ? (int)$row['reviewed_by'] : null,
      'response_note' => $row['response_note'] ?? null,
      'commission_total_cents' => (int)($row['commission_total'] ?? 0),
      'commission_count' => (int)($row['commission_count'] ?? 0),
    ];
  }
  return $rows;
}

function representative_payout_request_commission_ids(int $request_id): array {
  $st = pdo()->prepare('SELECT commission_id FROM representative_payout_request_commissions WHERE request_id=?');
  $st->execute([(int)$request_id]);
  return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function representative_payout_request_create(int $representative_id, string $invoice_path, ?string $note = null): array {
  if (trim($invoice_path) === '') {
    throw new InvalidArgumentException('Ödeme talebi için fatura yüklemeniz gerekir.');
  }
  $eligible = representative_commission_available_commissions($representative_id);
  if (!$eligible) {
    throw new RuntimeException('Çekilebilir komisyon bulunmuyor.');
  }
  $total = 0;
  $ids = [];
  foreach ($eligible as $item) {
    $total += (int)($item['commission_cents'] ?? 0);
    $ids[] = (int)$item['id'];
  }
  if ($total <= 0) {
    throw new RuntimeException('Çekilebilir tutar bulunamadı.');
  }
  $pdo = pdo();
  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare('INSERT INTO representative_payout_requests (representative_id, amount_cents, status, invoice_path, note, requested_at) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$representative_id, $total, REPRESENTATIVE_PAYOUT_STATUS_PENDING, $invoice_path !== '' ? $invoice_path : null, $note !== '' ? $note : null, now()]);
    $requestId = (int)$pdo->lastInsertId();
    $link = $pdo->prepare('INSERT INTO representative_payout_request_commissions (request_id, commission_id) VALUES (?,?)');
    foreach ($ids as $cid) {
      $link->execute([$requestId, $cid]);
    }
    $pdo->commit();
    return [
      'id' => $requestId,
      'amount_cents' => $total,
      'status' => REPRESENTATIVE_PAYOUT_STATUS_PENDING,
    ];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}

function representative_payout_request_admin_list(array $filters = []): array {
  $statusFilter = $filters['status'] ?? null;
  $limit = isset($filters['limit']) ? (int)$filters['limit'] : 50;
  $limit = max(1, min($limit, 200));
  $sql = "SELECT r.*, rep.name AS representative_name, rep.email AS representative_email, rep.phone AS representative_phone,
                 COALESCE(SUM(c.commission_cents),0) AS commission_total,
                 COUNT(c.id) AS commission_count"
       . " FROM representative_payout_requests r"
       . " INNER JOIN dealer_representatives rep ON rep.id = r.representative_id"
       . " LEFT JOIN representative_payout_request_commissions rpc ON rpc.request_id = r.id"
       . " LEFT JOIN dealer_representative_commissions c ON c.id = rpc.commission_id";
  $params = [];
  if ($statusFilter) {
    $sql .= ' WHERE r.status=?';
    $params[] = $statusFilter;
  }
  $sql .= ' GROUP BY r.id ORDER BY r.requested_at DESC LIMIT ?';
  $params[] = $limit;
  $st = pdo()->prepare($sql);
  foreach ($params as $idx => $value) {
    $st->bindValue($idx + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
  }
  $st->execute();
  $rows = [];
  foreach ($st as $row) {
    $rows[] = [
      'id' => (int)$row['id'],
      'representative_id' => (int)$row['representative_id'],
      'representative_name' => $row['representative_name'] ?? null,
      'representative_email' => $row['representative_email'] ?? null,
      'representative_phone' => $row['representative_phone'] ?? null,
      'amount_cents' => (int)$row['amount_cents'],
      'status' => $row['status'] ?? REPRESENTATIVE_PAYOUT_STATUS_PENDING,
      'invoice_path' => $row['invoice_path'] ?? null,
      'invoice_url' => representative_payout_invoice_url($row['invoice_path'] ?? null),
      'note' => $row['note'] ?? null,
      'requested_at' => $row['requested_at'] ?? null,
      'reviewed_at' => $row['reviewed_at'] ?? null,
      'reviewed_by' => isset($row['reviewed_by']) ? (int)$row['reviewed_by'] : null,
      'response_note' => $row['response_note'] ?? null,
      'commission_total_cents' => (int)($row['commission_total'] ?? 0),
      'commission_count' => (int)($row['commission_count'] ?? 0),
    ];
  }
  return $rows;
}

function representative_payout_pending_summary(): array {
  $summary = ['count' => 0, 'amount_cents' => 0];
  try {
    $st = pdo()->prepare('SELECT COUNT(*) AS c, COALESCE(SUM(amount_cents),0) AS total FROM representative_payout_requests WHERE status IN (?, ?)');
    $st->execute([REPRESENTATIVE_PAYOUT_STATUS_PENDING, REPRESENTATIVE_PAYOUT_STATUS_APPROVED]);
    if ($row = $st->fetch()) {
      $summary['count'] = (int)($row['c'] ?? 0);
      $summary['amount_cents'] = (int)($row['total'] ?? 0);
    }
  } catch (Throwable $e) {
    // ignore
  }
  return $summary;
}

function representative_payout_request_update(int $request_id, string $status, array $options = []): void {
  $request_id = (int)$request_id;
  if ($request_id <= 0) {
    throw new InvalidArgumentException('Ödeme talebi bulunamadı.');
  }
  $status = strtolower(trim($status));
  $allowed = [REPRESENTATIVE_PAYOUT_STATUS_PENDING, REPRESENTATIVE_PAYOUT_STATUS_APPROVED, REPRESENTATIVE_PAYOUT_STATUS_PAID, REPRESENTATIVE_PAYOUT_STATUS_REJECTED];
  if (!in_array($status, $allowed, true)) {
    throw new InvalidArgumentException('Geçersiz ödeme talebi durumu.');
  }
  $pdo = pdo();
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare('SELECT * FROM representative_payout_requests WHERE id=? FOR UPDATE');
    $st->execute([$request_id]);
    $request = $st->fetch();
    if (!$request) {
      throw new RuntimeException('Ödeme talebi bulunamadı.');
    }
    $currentStatus = $request['status'] ?? REPRESENTATIVE_PAYOUT_STATUS_PENDING;
    $reviewerId = isset($options['reviewed_by']) ? (int)$options['reviewed_by'] : null;
    $responseNote = array_key_exists('response_note', $options) ? trim((string)$options['response_note']) : ($request['response_note'] ?? null);
    $now = now();
    if ($status === REPRESENTATIVE_PAYOUT_STATUS_PENDING) {
      $reviewedAt = null;
      $reviewedBy = null;
    } else {
      $reviewedAt = $now;
      $reviewedBy = $reviewerId;
    }
    $pdo->prepare('UPDATE representative_payout_requests SET status=?, reviewed_at=?, reviewed_by=?, response_note=? WHERE id=?')
        ->execute([$status, $reviewedAt, $reviewedBy, $responseNote !== '' ? $responseNote : null, $request_id]);

    $commissionIds = representative_payout_request_commission_ids($request_id);
    foreach ($commissionIds as $cid) {
      if ($status === REPRESENTATIVE_PAYOUT_STATUS_APPROVED) {
        representative_commission_update_status($cid, REPRESENTATIVE_COMMISSION_STATUS_APPROVED);
      } elseif ($status === REPRESENTATIVE_PAYOUT_STATUS_PAID) {
        representative_commission_update_status($cid, REPRESENTATIVE_COMMISSION_STATUS_PAID);
      } elseif ($status === REPRESENTATIVE_PAYOUT_STATUS_REJECTED || $status === REPRESENTATIVE_PAYOUT_STATUS_PENDING) {
        representative_commission_update_status($cid, REPRESENTATIVE_COMMISSION_STATUS_PENDING);
      }
    }
    if ($status === REPRESENTATIVE_PAYOUT_STATUS_REJECTED) {
      $pdo->prepare('DELETE FROM representative_payout_request_commissions WHERE request_id=?')->execute([$request_id]);
    }
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}
