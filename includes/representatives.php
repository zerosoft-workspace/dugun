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

function representative_commission_status_label(string $status): string {
  return match ($status) {
    REPRESENTATIVE_COMMISSION_STATUS_PENDING  => 'Onay Bekliyor',
    REPRESENTATIVE_COMMISSION_STATUS_APPROVED => 'Ödeme Hazır',
    REPRESENTATIVE_COMMISSION_STATUS_PAID     => 'Ödendi',
    REPRESENTATIVE_COMMISSION_STATUS_REJECTED => 'Reddedildi',
    default                                   => ucfirst($status),
  };
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
  $topup_id = max(0, $topup_id);
  $dealer_id = max(0, $dealer_id);
  $amount_cents = max(0, $amount_cents);
  if ($topup_id <= 0 || $dealer_id <= 0 || $amount_cents <= 0) {
    return;
  }
  $rep = representative_for_dealer($dealer_id);
  if (!$rep || ($rep['status'] ?? REPRESENTATIVE_STATUS_ACTIVE) !== REPRESENTATIVE_STATUS_ACTIVE) {
    return;
  }
  $pdo = pdo();
  $check = $pdo->prepare('SELECT id FROM dealer_representative_commissions WHERE dealer_topup_id=? LIMIT 1');
  $check->execute([$topup_id]);
  if ($check->fetch()) {
    return;
  }
  $commission = (int)round($amount_cents * (($rep['commission_rate'] ?? 10.0) / 100));
  if ($commission <= 0) {
    return;
  }
  $pdo->prepare('INSERT INTO dealer_representative_commissions (representative_id, dealer_topup_id, amount_cents, commission_cents, status, created_at) VALUES (?,?,?,?,?,?)')
      ->execute([
        (int)$rep['id'],
        $topup_id,
        $amount_cents,
        $commission,
        REPRESENTATIVE_COMMISSION_STATUS_PENDING,
        now(),
      ]);
}

function representative_commission_totals(int $representative_id, ?int $dealer_id = null): array {
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
  $sql = 'SELECT c.status, COUNT(*) AS c, COALESCE(SUM(c.commission_cents),0) AS total FROM dealer_representative_commissions c';
  $params = [$representative_id];
  if ($dealer_id) {
    $sql .= ' INNER JOIN dealer_topups t ON t.id = c.dealer_topup_id';
  }
  $sql .= ' WHERE c.representative_id=?';
  if ($dealer_id) {
    $sql .= ' AND t.dealer_id=?';
    $params[] = $dealer_id;
  }
  $sql .= ' GROUP BY c.status';
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
  return $summary;
}

function representative_recent_commissions(int $representative_id, int $limit = 20, ?int $dealer_id = null): array {
  $limit = max(1, $limit);
  $sql = "SELECT c.*, t.amount_cents AS topup_amount, t.status AS topup_status, t.completed_at, t.dealer_id
    FROM dealer_representative_commissions c
    INNER JOIN dealer_topups t ON t.id = c.dealer_topup_id
    WHERE c.representative_id=?";
  $st = null;
  if ($dealer_id) {
    $sql .= ' AND t.dealer_id=?';
  }
  $sql .= ' ORDER BY c.created_at DESC LIMIT ?';
  $st = pdo()->prepare($sql);
  $st->bindValue(1, $representative_id, PDO::PARAM_INT);
  $position = 2;
  if ($dealer_id) {
    $st->bindValue($position, $dealer_id, PDO::PARAM_INT);
    $position++;
  }
  $st->bindValue($position, $limit, PDO::PARAM_INT);
  $st->execute();
  $rows = [];
  foreach ($st as $row) {
    $rows[] = [
      'id' => (int)$row['id'],
      'dealer_topup_id' => (int)$row['dealer_topup_id'],
      'commission_cents' => (int)$row['commission_cents'],
      'amount_cents' => (int)$row['amount_cents'],
      'topup_amount_cents' => (int)$row['topup_amount'],
      'status' => $row['status'] ?? REPRESENTATIVE_COMMISSION_STATUS_PENDING,
      'notes' => $row['notes'] ?? null,
      'created_at' => $row['created_at'] ?? null,
      'paid_at' => $row['paid_at'] ?? null,
      'topup_status' => $row['topup_status'] ?? null,
      'topup_completed_at' => $row['completed_at'] ?? null,
      'dealer_id' => isset($row['dealer_id']) ? (int)$row['dealer_id'] : null,
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
    $conditions[] = 't.dealer_id=?';
    $params[] = $dealerId;
  }

  $sql = "SELECT c.*, r.name AS representative_name, r.email AS representative_email, r.phone AS representative_phone,
                 d.id AS dealer_id, d.name AS dealer_name, d.code AS dealer_code,
                 t.amount_cents AS topup_amount_cents, t.status AS topup_status, t.created_at AS topup_created_at, t.completed_at AS topup_completed_at
          FROM dealer_representative_commissions c
          INNER JOIN dealer_representatives r ON r.id = c.representative_id
          LEFT JOIN dealer_topups t ON t.id = c.dealer_topup_id
          LEFT JOIN dealers d ON d.id = t.dealer_id";
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
      'dealer_topup_id' => isset($row['dealer_topup_id']) ? (int)$row['dealer_topup_id'] : null,
      'amount_cents' => isset($row['amount_cents']) ? (int)$row['amount_cents'] : 0,
      'commission_cents' => isset($row['commission_cents']) ? (int)$row['commission_cents'] : 0,
      'status' => $row['status'] ?? REPRESENTATIVE_COMMISSION_STATUS_PENDING,
      'notes' => $row['notes'] ?? null,
      'created_at' => $row['created_at'] ?? null,
      'approved_at' => $row['approved_at'] ?? null,
      'paid_at' => $row['paid_at'] ?? null,
      'topup_amount_cents' => isset($row['topup_amount_cents']) ? (int)$row['topup_amount_cents'] : null,
      'topup_status' => $row['topup_status'] ?? null,
      'topup_created_at' => $row['topup_created_at'] ?? null,
      'topup_completed_at' => $row['topup_completed_at'] ?? null,
    ];
  }
  return $rows;
}

function representative_completed_topups(int $representative_id, int $limit = 20, ?int $dealer_id = null): array {
  $limit = max(1, $limit);
  $commissionSelect = representative_assignments_support_commission_rate()
    ? 'a.commission_rate'
    : 'NULL AS commission_rate';
  $sql = "SELECT t.id, t.amount_cents, t.status, t.completed_at, t.created_at, t.dealer_id, {$commissionSelect},
                 c.commission_cents, c.status AS commission_status
          FROM dealer_representative_assignments a
          INNER JOIN dealer_topups t ON t.dealer_id = a.dealer_id
          LEFT JOIN dealer_representative_commissions c ON c.dealer_topup_id = t.id AND c.representative_id = a.representative_id
          WHERE a.representative_id=? AND t.status=?";
  if ($dealer_id) {
    $sql .= ' AND t.dealer_id=?';
  }
  $sql .= ' ORDER BY COALESCE(t.completed_at, t.created_at) DESC LIMIT ?';
  $st = pdo()->prepare($sql);
  $st->bindValue(1, $representative_id, PDO::PARAM_INT);
  $st->bindValue(2, 'completed', PDO::PARAM_STR);
  $position = 3;
  if ($dealer_id) {
    $st->bindValue($position, $dealer_id, PDO::PARAM_INT);
    $position++;
  }
  $st->bindValue($position, $limit, PDO::PARAM_INT);
  $st->execute();
  $rows = [];
  foreach ($st as $row) {
    $rows[] = [
      'id' => (int)$row['id'],
      'amount_cents' => (int)$row['amount_cents'],
      'status' => $row['status'] ?? 'completed',
      'completed_at' => $row['completed_at'] ?? null,
      'created_at' => $row['created_at'] ?? null,
      'commission_cents' => isset($row['commission_cents']) ? (int)$row['commission_cents'] : null,
      'commission_status' => $row['commission_status'] ?? null,
      'dealer_id' => isset($row['dealer_id']) ? (int)$row['dealer_id'] : null,
      'assignment_commission_rate' => isset($row['commission_rate']) ? (float)$row['commission_rate'] : null,
    ];
  }
  return $rows;
}

function representative_has_commission(int $representative_id, int $topup_id): bool {
  $st = pdo()->prepare('SELECT 1 FROM dealer_representative_commissions WHERE representative_id=? AND dealer_topup_id=? LIMIT 1');
  $st->execute([$representative_id, $topup_id]);
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
