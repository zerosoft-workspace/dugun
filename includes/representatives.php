<?php
/**
 * includes/representatives.php — Bayi temsilcileri yardımcı fonksiyonları
 */
require_once __DIR__.'/../config.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';

const REPRESENTATIVE_STATUS_ACTIVE = 'active';
const REPRESENTATIVE_STATUS_INACTIVE = 'inactive';

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

function representative_get(int $id): ?array {
  if ($id <= 0) {
    return null;
  }
  $st = pdo()->prepare("SELECT * FROM dealer_representatives WHERE id=? LIMIT 1");
  $st->execute([$id]);
  $row = $st->fetch();
  return $row ? representative_normalize_row($row) : null;
}

function representative_find_by_email(string $email): ?array {
  $email = trim($email);
  if ($email === '') {
    return null;
  }
  $st = pdo()->prepare("SELECT * FROM dealer_representatives WHERE email=? LIMIT 1");
  $st->execute([$email]);
  $row = $st->fetch();
  return $row ? representative_normalize_row($row) : null;
}

function representative_for_dealer(int $dealer_id): ?array {
  if ($dealer_id <= 0) {
    return null;
  }
  $st = pdo()->prepare("SELECT * FROM dealer_representatives WHERE dealer_id=? LIMIT 1");
  $st->execute([$dealer_id]);
  $row = $st->fetch();
  return $row ? representative_normalize_row($row) : null;
}

function representative_detail(int $representative_id): ?array {
  if ($representative_id <= 0) {
    return null;
  }
  $sql = "SELECT r.*, d.name AS dealer_name, d.company AS dealer_company, d.status AS dealer_status, d.code AS dealer_code
          FROM dealer_representatives r
          LEFT JOIN dealers d ON d.id = r.dealer_id
          WHERE r.id=? LIMIT 1";
  $st = pdo()->prepare($sql);
  $st->execute([$representative_id]);
  $row = $st->fetch();
  if (!$row) {
    return null;
  }
  $rep = representative_normalize_row($row);
  $rep['dealer_name'] = $row['dealer_name'] ?? null;
  $rep['dealer_company'] = $row['dealer_company'] ?? null;
  $rep['dealer_status'] = $row['dealer_status'] ?? null;
  $rep['dealer_code'] = $row['dealer_code'] ?? null;
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
    $conditions[] = 'r.dealer_id IS NOT NULL';
  } elseif ($assignedFilter === 'unassigned') {
    $conditions[] = 'r.dealer_id IS NULL';
  }
  if ($dealerFilter > 0) {
    $conditions[] = 'r.dealer_id=?';
    $params[] = $dealerFilter;
  }
  if ($search !== '') {
    $conditions[] = '(r.name LIKE ? OR r.email LIKE ? OR r.phone LIKE ?)';
    $like = '%'.$search.'%';
    array_push($params, $like, $like, $like);
  }

  $sql = "SELECT r.*, d.name AS dealer_name, d.company AS dealer_company, d.status AS dealer_status, d.code AS dealer_code
          FROM dealer_representatives r
          LEFT JOIN dealers d ON d.id = r.dealer_id";
  if ($conditions) {
    $sql .= ' WHERE '.implode(' AND ', $conditions);
  }
  $sql .= ' ORDER BY r.created_at DESC';

  $st = pdo()->prepare($sql);
  $st->execute($params);
  $rows = [];
  foreach ($st as $row) {
    $rep = representative_normalize_row($row);
    $rep['dealer_name'] = $row['dealer_name'] ?? null;
    $rep['dealer_company'] = $row['dealer_company'] ?? null;
    $rep['dealer_status'] = $row['dealer_status'] ?? null;
    $rep['dealer_code'] = $row['dealer_code'] ?? null;
    $rows[] = $rep;
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
  $assigned = (int)pdo()->query("SELECT COUNT(*) FROM dealer_representatives WHERE dealer_id IS NOT NULL")->fetchColumn();
  $summary['assigned'] = $assigned;
  $summary['unassigned'] = max(0, $summary['total'] - $assigned);
  return $summary;
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

  $duplicateCheck = pdo()->prepare("SELECT id FROM dealer_representatives WHERE email=? LIMIT 1");
  $duplicateCheck->execute([$email]);
  if ($duplicateCheck->fetchColumn()) {
    throw new InvalidArgumentException('Bu e-posta adresi başka bir temsilcide kayıtlı.');
  }

  $hash = password_hash($password, PASSWORD_DEFAULT);

  $pdo = pdo();
  $pdo->prepare("INSERT INTO dealer_representatives (dealer_id, assigned_at, name, email, phone, password_hash, commission_rate, status, created_at, updated_at) VALUES (NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?)")
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
  $dealerId = isset($data['dealer_id']) ? (int)$data['dealer_id'] : 0;
  if ($dealerId > 0) {
    representative_assign_to_dealer($repId, $dealerId);
  }
  return $repId;
}

function representative_update(int $representative_id, array $data): void {
  $rep = representative_get($representative_id);
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

  $duplicateCheck = pdo()->prepare("SELECT id FROM dealer_representatives WHERE email=? AND id<>? LIMIT 1");
  $duplicateCheck->execute([$email, $representative_id]);
  if ($duplicateCheck->fetchColumn()) {
    throw new InvalidArgumentException('Bu e-posta adresi başka bir temsilcide kayıtlı.');
  }

  pdo()->prepare("UPDATE dealer_representatives SET name=?, email=?, phone=?, commission_rate=?, status=?, updated_at=? WHERE id=?")
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
  $rep = representative_get($representative_id);
  if (!$rep) {
    throw new InvalidArgumentException('Temsilci kaydı bulunamadı.');
  }

  $pdo = pdo();
  $pdo->beginTransaction();
  try {
    $newDealerId = $dealer_id && $dealer_id > 0 ? $dealer_id : null;
    if ($newDealerId) {
      $check = $pdo->prepare("SELECT id FROM dealers WHERE id=? LIMIT 1");
      $check->execute([$newDealerId]);
      if (!$check->fetch()) {
        throw new InvalidArgumentException('Seçilen bayi bulunamadı.');
      }

      $currentForDealer = representative_for_dealer($newDealerId);
      if ($currentForDealer && (int)$currentForDealer['id'] !== $representative_id) {
        $pdo->prepare("UPDATE dealer_representatives SET dealer_id=NULL, assigned_at=NULL, updated_at=? WHERE id=?")
            ->execute([now(), (int)$currentForDealer['id']]);
      }
    }

    if (!empty($rep['dealer_id']) && $rep['dealer_id'] !== $newDealerId) {
      $pdo->prepare("UPDATE dealer_representatives SET dealer_id=NULL, assigned_at=NULL, updated_at=? WHERE id=?")
          ->execute([now(), $representative_id]);
      $rep['dealer_id'] = 0;
    }

    if ($newDealerId) {
      $pdo->prepare("UPDATE dealer_representatives SET dealer_id=?, assigned_at=?, updated_at=? WHERE id=?")
          ->execute([$newDealerId, now(), now(), $representative_id]);
    }

    if (!$newDealerId) {
      $pdo->prepare("UPDATE dealer_representatives SET dealer_id=NULL, assigned_at=NULL, updated_at=? WHERE id=?")
          ->execute([now(), $representative_id]);
    }

    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}

function representative_unassign_dealer(int $dealer_id): void {
  if ($dealer_id <= 0) {
    return;
  }
  pdo()->prepare("UPDATE dealer_representatives SET dealer_id=NULL, assigned_at=NULL, updated_at=? WHERE dealer_id=?")
      ->execute([now(), $dealer_id]);
}

function representative_update_password(int $representative_id, string $plainPassword): void {
  if ($plainPassword === '') {
    throw new InvalidArgumentException('Şifre boş olamaz.');
  }
  $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
  pdo()->prepare("UPDATE dealer_representatives SET password_hash=?, updated_at=? WHERE id=?")
      ->execute([$hash, now(), (int)$representative_id]);
}

function representative_record_login(int $representative_id): void {
  pdo()->prepare("UPDATE dealer_representatives SET last_login_at=?, updated_at=? WHERE id=?")
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
  $check = $pdo->prepare("SELECT id FROM dealer_representative_commissions WHERE dealer_topup_id=? LIMIT 1");
  $check->execute([$topup_id]);
  if ($check->fetch()) {
    return;
  }
  $commission = (int)round($amount_cents * (($rep['commission_rate'] ?? 10.0) / 100));
  if ($commission <= 0) {
    return;
  }
  $pdo->prepare("INSERT INTO dealer_representative_commissions (representative_id, dealer_topup_id, amount_cents, commission_cents, status, created_at) VALUES (?,?,?,?,?,?)")
      ->execute([
        (int)$rep['id'],
        $topup_id,
        $amount_cents,
        $commission,
        'pending',
        now(),
      ]);
}

function representative_commission_totals(int $representative_id): array {
  $summary = [
    'pending_amount' => 0,
    'paid_amount' => 0,
    'pending_count' => 0,
    'paid_count' => 0,
    'total_amount' => 0,
  ];
  $st = pdo()->prepare("SELECT status, COUNT(*) AS c, COALESCE(SUM(commission_cents),0) AS total FROM dealer_representative_commissions WHERE representative_id=? GROUP BY status");
  $st->execute([$representative_id]);
  foreach ($st as $row) {
    $status = $row['status'] ?? '';
    $total = (int)($row['total'] ?? 0);
    $count = (int)($row['c'] ?? 0);
    if ($status === 'paid') {
      $summary['paid_amount'] = $total;
      $summary['paid_count'] = $count;
    } else {
      $summary['pending_amount'] += $total;
      $summary['pending_count'] += $count;
    }
    $summary['total_amount'] += $total;
  }
  return $summary;
}

function representative_recent_commissions(int $representative_id, int $limit = 20): array {
  $limit = max(1, $limit);
  $st = pdo()->prepare("SELECT c.*, t.amount_cents AS topup_amount, t.status AS topup_status, t.completed_at
    FROM dealer_representative_commissions c
    INNER JOIN dealer_topups t ON t.id = c.dealer_topup_id
    WHERE c.representative_id=?
    ORDER BY c.created_at DESC
    LIMIT ?");
  $st->bindValue(1, $representative_id, PDO::PARAM_INT);
  $st->bindValue(2, $limit, PDO::PARAM_INT);
  $st->execute();
  $rows = [];
  foreach ($st as $row) {
    $rows[] = [
      'id' => (int)$row['id'],
      'dealer_topup_id' => (int)$row['dealer_topup_id'],
      'commission_cents' => (int)$row['commission_cents'],
      'amount_cents' => (int)$row['amount_cents'],
      'topup_amount_cents' => (int)$row['topup_amount'],
      'status' => $row['status'] ?? 'pending',
      'notes' => $row['notes'] ?? null,
      'created_at' => $row['created_at'] ?? null,
      'paid_at' => $row['paid_at'] ?? null,
      'topup_status' => $row['topup_status'] ?? null,
      'topup_completed_at' => $row['completed_at'] ?? null,
    ];
  }
  return $rows;
}

function representative_completed_topups(int $representative_id, int $limit = 20): array {
  $limit = max(1, $limit);
  $sql = "SELECT t.id, t.amount_cents, t.status, t.completed_at, t.created_at, c.commission_cents, c.status AS commission_status
          FROM dealer_representatives r
          INNER JOIN dealer_topups t ON t.dealer_id = r.dealer_id
          LEFT JOIN dealer_representative_commissions c ON c.dealer_topup_id = t.id
          WHERE r.id=? AND t.status=?
          ORDER BY COALESCE(t.completed_at, t.created_at) DESC
          LIMIT ?";
  $st = pdo()->prepare($sql);
  $st->bindValue(1, $representative_id, PDO::PARAM_INT);
  $st->bindValue(2, 'completed', PDO::PARAM_STR);
  $st->bindValue(3, $limit, PDO::PARAM_INT);
  $st->execute();
  $rows = [];
  foreach ($st as $row) {
    $rows[] = [
      'id' => (int)$row['id'],
      'amount_cents' => (int)$row['amount_cents'],
      'status' => $row['status'] ?? 'completed',
      'completed_at' => $row['completed_at'] ?? null,
      'created_at' => $row['created_at'] ?? null,
      'commission_cents' => isset($row['commission_cents']) ? (int)$row['commission_cents'] : 0,
      'commission_status' => $row['commission_status'] ?? 'pending',
    ];
  }
  return $rows;
}

function representative_has_commission(int $representative_id, int $topup_id): bool {
  $st = pdo()->prepare("SELECT 1 FROM dealer_representative_commissions WHERE representative_id=? AND dealer_topup_id=? LIMIT 1");
  $st->execute([$representative_id, $topup_id]);
  return (bool)$st->fetchColumn();
}
