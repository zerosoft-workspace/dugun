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
  $row['dealer_id'] = (int)($row['dealer_id'] ?? 0);
  $row['commission_rate'] = isset($row['commission_rate']) ? (float)$row['commission_rate'] : 0.0;
  $row['last_login_at'] = $row['last_login_at'] ?? null;
  $row['created_at'] = $row['created_at'] ?? null;
  $row['updated_at'] = $row['updated_at'] ?? null;
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

function representative_save_for_dealer(int $dealer_id, array $data, ?int $representative_id = null): int {
  $dealer_id = max(0, (int)$dealer_id);
  if ($dealer_id <= 0) {
    throw new InvalidArgumentException('Geçerli bir bayi seçin.');
  }
  $name = trim($data['name'] ?? '');
  $email = trim($data['email'] ?? '');
  $phone = trim($data['phone'] ?? '');
  $status = $data['status'] ?? REPRESENTATIVE_STATUS_ACTIVE;
  $commissionRate = isset($data['commission_rate']) ? (float)$data['commission_rate'] : 10.0;
  $passwordHash = $data['password_hash'] ?? null;

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
  $existing = null;
  if ($representative_id) {
    $existing = representative_get((int)$representative_id);
    if (!$existing || (int)$existing['dealer_id'] !== $dealer_id) {
      throw new InvalidArgumentException('Temsilci kaydı bulunamadı.');
    }
  } else {
    $existing = representative_for_dealer($dealer_id);
  }

  $excludeId = $existing ? (int)$existing['id'] : 0;
  $duplicateCheck = pdo()->prepare("SELECT id FROM dealer_representatives WHERE email=? AND id<>? LIMIT 1");
  $duplicateCheck->execute([$email, $excludeId]);
  if ($duplicateCheck->fetchColumn()) {
    throw new InvalidArgumentException('Bu e-posta adresi başka bir temsilcide kayıtlı.');
  }

  $pdo = pdo();
  if ($existing) {
    $pdo->prepare("UPDATE dealer_representatives SET name=?, email=?, phone=?, commission_rate=?, status=?, updated_at=? WHERE id=?")
        ->execute([
          $name,
          $email,
          $phone ?: null,
          number_format($commissionRate, 2, '.', ''),
          $status,
          now(),
          (int)$existing['id'],
        ]);
    $repId = (int)$existing['id'];
    if ($passwordHash) {
      $pdo->prepare("UPDATE dealer_representatives SET password_hash=?, updated_at=? WHERE id=?")
          ->execute([$passwordHash, now(), $repId]);
    }
    return $repId;
  }

  if (!$passwordHash) {
    throw new InvalidArgumentException('Yeni temsilci için bir şifre belirleyin.');
  }

  $pdo->prepare("INSERT INTO dealer_representatives (dealer_id, name, email, phone, password_hash, commission_rate, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)")
      ->execute([
        $dealer_id,
        $name,
        $email,
        $phone ?: null,
        $passwordHash,
        number_format($commissionRate, 2, '.', ''),
        $status,
        now(),
        now(),
      ]);
  return (int)$pdo->lastInsertId();
}

function representative_delete_for_dealer(int $dealer_id): void {
  $rep = representative_for_dealer($dealer_id);
  if ($rep) {
    pdo()->prepare("DELETE FROM dealer_representatives WHERE id=?")
        ->execute([(int)$rep['id']]);
  }
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
