<?php
/**
 * includes/finance.php — Finansal özetler ve raporlama yardımcıları
 */
require_once __DIR__.'/../config.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/dealers.php';
require_once __DIR__.'/representatives.php';
require_once __DIR__.'/site.php';

function finance_overview(): array {
  $pdo = pdo();
  $thirtyDaysAgo = (new DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s');

  $topups = [
    'completed_amount' => 0,
    'completed_count' => 0,
    'last_30_amount' => 0,
    'last_30_count' => 0,
    'pending_amount' => 0,
    'pending_count' => 0,
    'review_amount' => 0,
    'review_count' => 0,
    'average_amount' => 0,
  ];

  try {
    $st = $pdo->prepare('SELECT COUNT(*) AS c, COALESCE(SUM(amount_cents),0) AS total, COALESCE(AVG(amount_cents),0) AS avg
                          FROM dealer_topups
                          WHERE status=?');
    $st->execute([DEALER_TOPUP_STATUS_COMPLETED]);
    if ($row = $st->fetch()) {
      $topups['completed_amount'] = (int)($row['total'] ?? 0);
      $topups['completed_count'] = (int)($row['c'] ?? 0);
      $topups['average_amount'] = (float)($row['avg'] ?? 0);
    }
  } catch (Throwable $e) {}

  try {
    $st = $pdo->prepare('SELECT COUNT(*) AS c, COALESCE(SUM(amount_cents),0) AS total
                          FROM dealer_topups
                          WHERE status=? AND COALESCE(completed_at, updated_at, created_at) >= ?');
    $st->execute([DEALER_TOPUP_STATUS_COMPLETED, $thirtyDaysAgo]);
    if ($row = $st->fetch()) {
      $topups['last_30_amount'] = (int)($row['total'] ?? 0);
      $topups['last_30_count'] = (int)($row['c'] ?? 0);
    }
  } catch (Throwable $e) {}

  try {
    $st = $pdo->prepare('SELECT status, COUNT(*) AS c, COALESCE(SUM(amount_cents),0) AS total
                          FROM dealer_topups
                          WHERE status IN (?, ?)
                          GROUP BY status');
    $st->execute([DEALER_TOPUP_STATUS_PENDING, DEALER_TOPUP_STATUS_AWAITING_REVIEW]);
    foreach ($st as $row) {
      $status = $row['status'] ?? '';
      $count = (int)($row['c'] ?? 0);
      $total = (int)($row['total'] ?? 0);
      if ($status === DEALER_TOPUP_STATUS_PENDING) {
        $topups['pending_amount'] = $total;
        $topups['pending_count'] = $count;
      } elseif ($status === DEALER_TOPUP_STATUS_AWAITING_REVIEW) {
        $topups['review_amount'] = $total;
        $topups['review_count'] = $count;
      }
    }
  } catch (Throwable $e) {}

  $orders = [
    'total_amount' => 0,
    'total_count' => 0,
    'last_30_amount' => 0,
    'last_30_count' => 0,
    'average_amount' => 0,
  ];

  try {
    $st = $pdo->prepare('SELECT COUNT(*) AS c, COALESCE(SUM(price_cents),0) AS total, COALESCE(AVG(price_cents),0) AS avg
                          FROM site_orders
                          WHERE status = ?');
    $st->execute([SITE_ORDER_STATUS_COMPLETED]);
    if ($row = $st->fetch()) {
      $orders['total_amount'] = (int)($row['total'] ?? 0);
      $orders['total_count'] = (int)($row['c'] ?? 0);
      $orders['average_amount'] = (float)($row['avg'] ?? 0);
    }
  } catch (Throwable $e) {}

  try {
    $st = $pdo->prepare('SELECT COUNT(*) AS c, COALESCE(SUM(price_cents),0) AS total
                          FROM site_orders
                          WHERE status = ? AND COALESCE(paid_at, updated_at, created_at) >= ?');
    $st->execute([SITE_ORDER_STATUS_COMPLETED, $thirtyDaysAgo]);
    if ($row = $st->fetch()) {
      $orders['last_30_amount'] = (int)($row['total'] ?? 0);
      $orders['last_30_count'] = (int)($row['c'] ?? 0);
    }
  } catch (Throwable $e) {}

  $commissionsSummary = representative_admin_commission_overview();
  $commissions = [
    'pending_amount' => $commissionsSummary['pending_amount'] ?? 0,
    'pending_count' => $commissionsSummary['pending_count'] ?? 0,
    'approved_amount' => $commissionsSummary['approved_amount'] ?? 0,
    'approved_count' => $commissionsSummary['approved_count'] ?? 0,
    'paid_amount' => $commissionsSummary['paid_amount'] ?? 0,
    'paid_count' => $commissionsSummary['paid_count'] ?? 0,
    'rejected_amount' => $commissionsSummary['rejected_amount'] ?? 0,
    'rejected_count' => $commissionsSummary['rejected_count'] ?? 0,
    'last_30_paid_amount' => 0,
    'last_30_paid_count' => 0,
    'average_paid_amount' => 0,
    'available_amount' => 0,
    'available_count' => 0,
    'next_release_at' => null,
  ];

  $availableSummary = representative_commission_global_available_summary();
  $commissions['available_amount'] = $availableSummary['amount'] ?? 0;
  $commissions['available_count'] = $availableSummary['count'] ?? 0;
  $commissions['next_release_at'] = $availableSummary['next_release_at'] ?? null;

  $payoutSummary = representative_payout_pending_summary();

  try {
    $st = $pdo->prepare('SELECT COUNT(*) AS c, COALESCE(SUM(commission_cents),0) AS total, COALESCE(AVG(commission_cents),0) AS avg
                          FROM dealer_representative_commissions
                          WHERE status = ?');
    $st->execute([REPRESENTATIVE_COMMISSION_STATUS_PAID]);
    if ($row = $st->fetch()) {
      $commissions['average_paid_amount'] = (float)($row['avg'] ?? 0);
    }
  } catch (Throwable $e) {}

  try {
    $st = $pdo->prepare('SELECT COUNT(*) AS c, COALESCE(SUM(commission_cents),0) AS total
                          FROM dealer_representative_commissions
                          WHERE status = ? AND COALESCE(paid_at, updated_at, created_at) >= ?');
    $st->execute([REPRESENTATIVE_COMMISSION_STATUS_PAID, $thirtyDaysAgo]);
    if ($row = $st->fetch()) {
      $commissions['last_30_paid_amount'] = (int)($row['total'] ?? 0);
      $commissions['last_30_paid_count'] = (int)($row['c'] ?? 0);
    }
  } catch (Throwable $e) {}

  $cashbacks = [
    'pending_amount' => 0,
    'pending_count' => 0,
    'paid_amount' => 0,
    'paid_count' => 0,
    'last_30_paid_amount' => 0,
    'last_30_paid_count' => 0,
    'average_paid_amount' => 0,
  ];

  try {
    $st = $pdo->prepare('SELECT COUNT(*) AS c, COALESCE(SUM(cashback_amount),0) AS total
                          FROM dealer_package_purchases
                          WHERE cashback_status = ?');
    $st->execute([DEALER_CASHBACK_PENDING]);
    if ($row = $st->fetch()) {
      $cashbacks['pending_amount'] = (int)($row['total'] ?? 0);
      $cashbacks['pending_count'] = (int)($row['c'] ?? 0);
    }
  } catch (Throwable $e) {}

  try {
    $st = $pdo->prepare('SELECT COUNT(*) AS c, COALESCE(SUM(cashback_amount),0) AS total, COALESCE(AVG(cashback_amount),0) AS avg
                          FROM dealer_package_purchases
                          WHERE cashback_status = ?');
    $st->execute([DEALER_CASHBACK_PAID]);
    if ($row = $st->fetch()) {
      $cashbacks['paid_amount'] = (int)($row['total'] ?? 0);
      $cashbacks['paid_count'] = (int)($row['c'] ?? 0);
      $cashbacks['average_paid_amount'] = (float)($row['avg'] ?? 0);
    }
  } catch (Throwable $e) {}

  try {
    $st = $pdo->prepare('SELECT COUNT(*) AS c, COALESCE(SUM(cashback_amount),0) AS total
                          FROM dealer_package_purchases
                          WHERE cashback_status = ? AND COALESCE(cashback_paid_at, updated_at, created_at) >= ?');
    $st->execute([DEALER_CASHBACK_PAID, $thirtyDaysAgo]);
    if ($row = $st->fetch()) {
      $cashbacks['last_30_paid_amount'] = (int)($row['total'] ?? 0);
      $cashbacks['last_30_paid_count'] = (int)($row['c'] ?? 0);
    }
  } catch (Throwable $e) {}

  $totals = [
    'revenue_total' => $topups['completed_amount'] + $orders['total_amount'],
    'revenue_last_30' => $topups['last_30_amount'] + $orders['last_30_amount'],
    'payout_total' => $cashbacks['paid_amount'] + $commissions['paid_amount'],
    'payout_last_30' => $cashbacks['last_30_paid_amount'] + $commissions['last_30_paid_amount'],
  ];
  $totals['net_last_30'] = $totals['revenue_last_30'] - $totals['payout_last_30'];

  return [
    'topups' => $topups,
    'orders' => $orders,
    'commissions' => $commissions,
    'cashbacks' => $cashbacks,
    'payout_requests' => $payoutSummary,
    'totals' => $totals,
  ];
}

function finance_recent_topups(int $limit = 10, ?array $statuses = null): array {
  $limit = max(1, min($limit, 100));
  $statuses = $statuses ? array_values(array_filter(array_map('strval', $statuses))) : [];

  $sql = 'SELECT dt.*, d.name AS dealer_name, d.code AS dealer_code, d.email AS dealer_email
          FROM dealer_topups dt
          INNER JOIN dealers d ON d.id = dt.dealer_id';
  $params = [];
  if ($statuses) {
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $sql .= ' WHERE dt.status IN ('.$placeholders.')';
    foreach ($statuses as $status) {
      $params[] = $status;
    }
  }
  $sql .= ' ORDER BY dt.created_at DESC LIMIT ?';

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
      'dealer_id' => (int)$row['dealer_id'],
      'dealer_name' => $row['dealer_name'] ?? null,
      'dealer_code' => $row['dealer_code'] ?? null,
      'dealer_email' => $row['dealer_email'] ?? null,
      'amount_cents' => (int)($row['amount_cents'] ?? 0),
      'status' => $row['status'] ?? DEALER_TOPUP_STATUS_PENDING,
      'created_at' => $row['created_at'] ?? null,
      'updated_at' => $row['updated_at'] ?? null,
      'completed_at' => $row['completed_at'] ?? null,
      'paytr_reference' => $row['paytr_reference'] ?? null,
    ];
  }
  return $rows;
}

function finance_recent_commissions(int $limit = 10, ?array $statuses = null): array {
  $filters = [
    'limit' => $limit,
  ];
  if ($statuses) {
    $filters['statuses'] = $statuses;
  }
  return representative_commission_admin_list($filters);
}

function finance_pending_cashbacks(int $limit = 10): array {
  $limit = max(1, min($limit, 100));
  $sql = 'SELECT pp.*, d.name AS dealer_name, d.code AS dealer_code, pkg.name AS package_name, pkg.price_cents,
                 e.title AS event_title, e.event_date, so.customer_name, so.customer_email
          FROM dealer_package_purchases pp
          INNER JOIN dealers d ON d.id = pp.dealer_id
          INNER JOIN dealer_packages pkg ON pkg.id = pp.package_id
          LEFT JOIN events e ON e.id = pp.lead_event_id
          LEFT JOIN site_orders so ON so.event_id = pp.lead_event_id
          WHERE pp.cashback_status = ?
          ORDER BY pp.created_at DESC
          LIMIT ?';
  $st = pdo()->prepare($sql);
  $st->bindValue(1, DEALER_CASHBACK_PENDING, PDO::PARAM_STR);
  $st->bindValue(2, $limit, PDO::PARAM_INT);
  $st->execute();

  $rows = [];
  foreach ($st as $row) {
    $rows[] = [
      'id' => (int)$row['id'],
      'dealer_id' => (int)$row['dealer_id'],
      'dealer_name' => $row['dealer_name'] ?? null,
      'dealer_code' => $row['dealer_code'] ?? null,
      'package_name' => $row['package_name'] ?? null,
      'cashback_amount' => (int)($row['cashback_amount'] ?? 0),
      'cashback_note' => $row['cashback_note'] ?? null,
      'created_at' => $row['created_at'] ?? null,
      'event_title' => $row['event_title'] ?? null,
      'event_date' => $row['event_date'] ?? null,
      'customer_name' => $row['customer_name'] ?? null,
      'customer_email' => $row['customer_email'] ?? null,
    ];
  }
  return $rows;
}

function finance_recent_orders(int $limit = 10): array {
  $limit = max(1, min($limit, 100));
  $sql = 'SELECT so.*, pkg.name AS package_name, d.name AS dealer_name, d.code AS dealer_code
          FROM site_orders so
          INNER JOIN dealer_packages pkg ON pkg.id = so.package_id
          LEFT JOIN dealers d ON d.id = so.dealer_id
          WHERE so.status = ?
          ORDER BY COALESCE(so.paid_at, so.updated_at, so.created_at) DESC
          LIMIT ?';
  $st = pdo()->prepare($sql);
  $st->bindValue(1, SITE_ORDER_STATUS_COMPLETED, PDO::PARAM_STR);
  $st->bindValue(2, $limit, PDO::PARAM_INT);
  $st->execute();

  $rows = [];
  foreach ($st as $row) {
    $rows[] = [
      'id' => (int)$row['id'],
      'package_name' => $row['package_name'] ?? null,
      'dealer_name' => $row['dealer_name'] ?? null,
      'dealer_code' => $row['dealer_code'] ?? null,
      'price_cents' => (int)($row['price_cents'] ?? 0),
      'customer_name' => $row['customer_name'] ?? null,
      'customer_email' => $row['customer_email'] ?? null,
      'event_title' => $row['event_title'] ?? null,
      'event_date' => $row['event_date'] ?? null,
      'paid_at' => $row['paid_at'] ?? null,
    ];
  }
  return $rows;
}

function finance_monthly_summary(int $months = 6): array {
  $months = max(1, min($months, 24));
  $end = new DateTimeImmutable('first day of next month');
  $start = $end->modify('-'.($months).' months');
  $startStr = $start->format('Y-m-01 00:00:00');

  $pdo = pdo();
  $topups = [];
  $orders = [];
  $commissions = [];
  $cashbacks = [];

  try {
    $st = $pdo->prepare('SELECT DATE_FORMAT(COALESCE(completed_at, updated_at, created_at), "%Y-%m") AS ym,
                                COUNT(*) AS c,
                                COALESCE(SUM(amount_cents),0) AS total
                         FROM dealer_topups
                         WHERE status = ? AND COALESCE(completed_at, updated_at, created_at) >= ?
                         GROUP BY ym');
    $st->execute([DEALER_TOPUP_STATUS_COMPLETED, $startStr]);
    foreach ($st as $row) {
      $ym = $row['ym'] ?? null;
      if ($ym) {
        $topups[$ym] = [
          'count' => (int)($row['c'] ?? 0),
          'amount' => (int)($row['total'] ?? 0),
        ];
      }
    }
  } catch (Throwable $e) {}

  try {
    $st = $pdo->prepare('SELECT DATE_FORMAT(COALESCE(paid_at, updated_at, created_at), "%Y-%m") AS ym,
                                COUNT(*) AS c,
                                COALESCE(SUM(price_cents),0) AS total
                         FROM site_orders
                         WHERE status = ? AND COALESCE(paid_at, updated_at, created_at) >= ?
                         GROUP BY ym');
    $st->execute([SITE_ORDER_STATUS_COMPLETED, $startStr]);
    foreach ($st as $row) {
      $ym = $row['ym'] ?? null;
      if ($ym) {
        $orders[$ym] = [
          'count' => (int)($row['c'] ?? 0),
          'amount' => (int)($row['total'] ?? 0),
        ];
      }
    }
  } catch (Throwable $e) {}

  try {
    $st = $pdo->prepare('SELECT DATE_FORMAT(COALESCE(paid_at, updated_at, created_at), "%Y-%m") AS ym,
                                COUNT(*) AS c,
                                COALESCE(SUM(commission_cents),0) AS total
                         FROM dealer_representative_commissions
                         WHERE status = ? AND COALESCE(paid_at, updated_at, created_at) >= ?
                         GROUP BY ym');
    $st->execute([REPRESENTATIVE_COMMISSION_STATUS_PAID, $startStr]);
    foreach ($st as $row) {
      $ym = $row['ym'] ?? null;
      if ($ym) {
        $commissions[$ym] = [
          'count' => (int)($row['c'] ?? 0),
          'amount' => (int)($row['total'] ?? 0),
        ];
      }
    }
  } catch (Throwable $e) {}

  try {
    $st = $pdo->prepare('SELECT DATE_FORMAT(COALESCE(cashback_paid_at, updated_at, created_at), "%Y-%m") AS ym,
                                COUNT(*) AS c,
                                COALESCE(SUM(cashback_amount),0) AS total
                         FROM dealer_package_purchases
                         WHERE cashback_status = ? AND COALESCE(cashback_paid_at, updated_at, created_at) >= ?
                         GROUP BY ym');
    $st->execute([DEALER_CASHBACK_PAID, $startStr]);
    foreach ($st as $row) {
      $ym = $row['ym'] ?? null;
      if ($ym) {
        $cashbacks[$ym] = [
          'count' => (int)($row['c'] ?? 0),
          'amount' => (int)($row['total'] ?? 0),
        ];
      }
    }
  } catch (Throwable $e) {}

  $monthsList = [];
  for ($i = $months - 1; $i >= 0; $i--) {
    $current = $end->modify('-'.$i.' months');
    $ym = $current->format('Y-m');
    $label = turkish_month_label($current);
    $topup = $topups[$ym] ?? ['count' => 0, 'amount' => 0];
    $order = $orders[$ym] ?? ['count' => 0, 'amount' => 0];
    $commission = $commissions[$ym] ?? ['count' => 0, 'amount' => 0];
    $cashback = $cashbacks[$ym] ?? ['count' => 0, 'amount' => 0];
    $revenue = $topup['amount'] + $order['amount'];
    $payout = $commission['amount'] + $cashback['amount'];

    $monthsList[] = [
      'month' => $ym,
      'label' => $label,
      'topup_amount' => $topup['amount'],
      'topup_count' => $topup['count'],
      'order_amount' => $order['amount'],
      'order_count' => $order['count'],
      'commission_amount' => $commission['amount'],
      'commission_count' => $commission['count'],
      'cashback_amount' => $cashback['amount'],
      'cashback_count' => $cashback['count'],
      'revenue_total' => $revenue,
      'payout_total' => $payout,
      'net' => $revenue - $payout,
    ];
  }

  return $monthsList;
}

function turkish_month_label(DateTimeInterface $dt): string {
  static $names = [
    1 => 'Ocak',
    2 => 'Şubat',
    3 => 'Mart',
    4 => 'Nisan',
    5 => 'Mayıs',
    6 => 'Haziran',
    7 => 'Temmuz',
    8 => 'Ağustos',
    9 => 'Eylül',
    10 => 'Ekim',
    11 => 'Kasım',
    12 => 'Aralık',
  ];
  $month = (int)$dt->format('n');
  $year = $dt->format('Y');
  $name = $names[$month] ?? $dt->format('M');
  return $name.' '.$year;
}

function finance_recent_payout_requests(int $limit = 10, ?string $status = null): array {
  $limit = max(1, min($limit, 100));
  $filters = ['limit' => $limit];
  if ($status !== null && $status !== '') {
    $filters['status'] = $status;
  }
  return representative_payout_request_admin_list($filters);
}
