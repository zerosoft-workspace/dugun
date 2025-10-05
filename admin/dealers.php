<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/dealers.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/partials/ui.php';

require_admin();
install_schema();
dealer_backfill_codes();

function parse_license_input(?string $input): ?string {
  if (!$input) return null;
  try {
    $dt = new DateTime($input);
    return $dt->format('Y-m-d H:i:s');
  } catch (Throwable $e) {
    return null;
  }
}

$action = $_POST['do'] ?? '';
if ($action) {
  csrf_or_die();
}

if ($action && in_array($action, ['wallet_adjust','cashback_pay','topup_complete','topup_cancel_admin'], true) && !is_superadmin()) {
  flash('err', 'Bu işlem için yetkiniz yok.');
  $redirectId = isset($_POST['dealer_id']) ? (int)$_POST['dealer_id'] : 0;
  $target = $_SERVER['PHP_SELF'];
  if ($redirectId) {
    $target .= '?id='.$redirectId;
  }
  redirect($target);
}

if ($action === 'create') {
  $name  = trim($_POST['name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $phone = trim($_POST['phone'] ?? '');
  $company = trim($_POST['company'] ?? '');
  $notes = trim($_POST['notes'] ?? '');
  $status = $_POST['status'] ?? DEALER_STATUS_PENDING;
  $licenseInput = $_POST['license_expires_at'] ?? '';
  $license = parse_license_input($licenseInput);
  $code = dealer_generate_unique_identifier();

  if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('err', 'Ad ve geçerli e-posta gerekli.');
    redirect($_SERVER['PHP_SELF']);
  }
  if (dealer_find_by_email($email)) {
    flash('err', 'Bu e-posta zaten kayıtlı.');
    redirect($_SERVER['PHP_SELF']);
  }

  $pdo = pdo();
  $pdo->prepare("INSERT INTO dealers (code,name,email,phone,company,notes,status,license_expires_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
      ->execute([$code,$name,$email,$phone,$company,$notes,$status,$license, now(), now()]);
  $dealerId = (int)$pdo->lastInsertId();
  dealer_ensure_codes($dealerId);

  if ($status === DEALER_STATUS_ACTIVE) {
    $plain = dealer_random_password();
    $hash  = password_hash($plain, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE dealers SET password_hash=?, approved_at=?, updated_at=? WHERE id=?")
        ->execute([$hash, now(), now(), $dealerId]);
    $dealer = dealer_get($dealerId);
    dealer_send_welcome_mail($dealer, $plain);
  }

  flash('ok', 'Bayi kaydedildi.');
  redirect($_SERVER['PHP_SELF'].'?id='.$dealerId);
}

if ($action === 'update') {
  $dealerId = (int)($_POST['dealer_id'] ?? 0);
  $dealer = dealer_get($dealerId);
  if (!$dealer) { flash('err','Bayi bulunamadı.'); redirect($_SERVER['PHP_SELF']); }

  $name  = trim($_POST['name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $phone = trim($_POST['phone'] ?? '');
  $company = trim($_POST['company'] ?? '');
  $notes = trim($_POST['notes'] ?? '');
  $status = $_POST['status'] ?? DEALER_STATUS_PENDING;
  $licenseInput = $_POST['license_expires_at'] ?? '';
  $license = parse_license_input($licenseInput);

  if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('err','Ad ve geçerli e-posta gerekli.');
    redirect($_SERVER['PHP_SELF'].'?id='.$dealerId);
  }
  $st = pdo()->prepare("SELECT id FROM dealers WHERE email=? AND id<>? LIMIT 1");
  $st->execute([$email, $dealerId]);
  if ($st->fetch()) {
    flash('err','Bu e-posta başka bir bayide kayıtlı.');
    redirect($_SERVER['PHP_SELF'].'?id='.$dealerId);
  }

  pdo()->prepare("UPDATE dealers SET name=?, email=?, phone=?, company=?, notes=?, status=?, license_expires_at=?, updated_at=? WHERE id=?")
      ->execute([$name,$email,$phone,$company,$notes,$status,$license, now(), $dealerId]);

  if ($status === DEALER_STATUS_ACTIVE && empty($dealer['password_hash'])) {
    $plain = dealer_random_password();
    $hash  = password_hash($plain, PASSWORD_DEFAULT);
    pdo()->prepare("UPDATE dealers SET password_hash=?, approved_at=?, updated_at=? WHERE id=?")
        ->execute([$hash, now(), now(), $dealerId]);
    $dealer = dealer_get($dealerId);
    dealer_send_welcome_mail($dealer, $plain);
  }

  flash('ok','Bilgiler güncellendi.');
  redirect($_SERVER['PHP_SELF'].'?id='.$dealerId);
}

if ($action === 'assign_venues') {
  $dealerId = (int)($_POST['dealer_id'] ?? 0);
  $dealer = dealer_get($dealerId);
  if (!$dealer) { flash('err','Bayi bulunamadı.'); redirect($_SERVER['PHP_SELF']); }
  $venues = $_POST['venue_ids'] ?? [];
  dealer_assign_venues($dealerId, $venues);
  flash('ok','Salon atamaları güncellendi.');
  redirect($_SERVER['PHP_SELF'].'?id='.$dealerId);
}

if ($action === 'assign_venue_dealers') {
  $venueId = (int)($_POST['venue_id'] ?? 0);
  $dealerIds = $_POST['dealer_ids'] ?? [];
  $venueCheck = pdo()->prepare("SELECT id FROM venues WHERE id=? LIMIT 1");
  $venueCheck->execute([$venueId]);
  if (!$venueCheck->fetch()) {
    flash('err','Salon bulunamadı.');
    redirect($_SERVER['PHP_SELF']);
  }
  dealer_assign_dealers_to_venue($venueId, $dealerIds);
  $anchor = '#venue-'.$venueId;
  flash('ok','Salon için bayi atamaları kaydedildi.');
  redirect($_SERVER['PHP_SELF'].$anchor);
}

if ($action === 'send_password') {
  $dealerId = (int)($_POST['dealer_id'] ?? 0);
  $dealer = dealer_get($dealerId);
  if (!$dealer) { flash('err','Bayi bulunamadı.'); redirect($_SERVER['PHP_SELF']); }
  if ($dealer['status'] !== DEALER_STATUS_ACTIVE) {
    flash('err','Önce bayiyi aktif hale getirin.');
    redirect($_SERVER['PHP_SELF'].'?id='.$dealerId);
  }
  $plain = dealer_random_password();
  $hash = password_hash($plain, PASSWORD_DEFAULT);
  pdo()->prepare("UPDATE dealers SET password_hash=?, approved_at=COALESCE(approved_at,?), updated_at=? WHERE id=?")
      ->execute([$hash, now(), now(), $dealerId]);
  $dealer = dealer_get($dealerId);
  dealer_send_welcome_mail($dealer, $plain);
  flash('ok','Yeni şifre e-posta ile gönderildi.');
  redirect($_SERVER['PHP_SELF'].'?id='.$dealerId);
}

if ($action === 'wallet_adjust') {
  $dealerId = (int)($_POST['dealer_id'] ?? 0);
  $amountInput = $_POST['amount'] ?? '';
  $direction = $_POST['direction'] ?? 'credit';
  $description = trim($_POST['description'] ?? '');
  $amountCents = money_to_cents($amountInput);
  if ($dealerId <= 0 || $amountCents <= 0) {
    flash('err', 'Geçerli bir tutar belirtin.');
    redirect($_SERVER['PHP_SELF'].($dealerId ? '?id='.$dealerId : ''));
  }
  if ($direction === 'debit') {
    $amountCents = -$amountCents;
  }
  try {
    dealer_wallet_adjust($dealerId, $amountCents, DEALER_WALLET_TYPE_ADJUSTMENT, $description ?: 'Manuel düzenleme', [
      'admin_id' => admin_user()['id'] ?? null,
    ]);
    flash('ok', 'Bayi bakiyesi güncellendi.');
  } catch (Throwable $e) {
    flash('err', $e->getMessage());
  }
  redirect($_SERVER['PHP_SELF'].'?id='.$dealerId.'#finance');
}

if ($action === 'cashback_pay') {
  $dealerId = (int)($_POST['dealer_id'] ?? 0);
  $purchaseId = (int)($_POST['purchase_id'] ?? 0);
  $note = trim($_POST['note'] ?? '');
  if ($dealerId <= 0 || $purchaseId <= 0) {
    flash('err', 'Cashback kaydı bulunamadı.');
    redirect($_SERVER['PHP_SELF'].($dealerId ? '?id='.$dealerId : ''));
  }
  try {
    dealer_pay_cashback($purchaseId, $note, ['admin_id' => admin_user()['id'] ?? null]);
    flash('ok', 'Cashback ödemesi tamamlandı.');
  } catch (Throwable $e) {
    flash('err', $e->getMessage());
  }
  redirect($_SERVER['PHP_SELF'].'?id='.$dealerId.'#finance');
}

if ($action === 'topup_complete') {
  $dealerId = (int)($_POST['dealer_id'] ?? 0);
  $topupId = (int)($_POST['topup_id'] ?? 0);
  $reference = trim($_POST['reference'] ?? '');
  if ($dealerId <= 0 || $topupId <= 0) {
    flash('err', 'Yükleme kaydı bulunamadı.');
    redirect($_SERVER['PHP_SELF']);
  }
  try {
    dealer_mark_topup_completed($topupId, $reference ?: null);
    flash('ok', 'Bakiye yükleme talebi onaylandı.');
  } catch (Throwable $e) {
    flash('err', $e->getMessage());
  }
  redirect($_SERVER['PHP_SELF'].'?id='.$dealerId.'#finance');
}

if ($action === 'topup_cancel_admin') {
  $dealerId = (int)($_POST['dealer_id'] ?? 0);
  $topupId = (int)($_POST['topup_id'] ?? 0);
  if ($dealerId <= 0 || $topupId <= 0) {
    flash('err', 'Yükleme kaydı bulunamadı.');
    redirect($_SERVER['PHP_SELF']);
  }
  try {
    dealer_cancel_topup($topupId);
    flash('ok', 'Yükleme talebi iptal edildi.');
  } catch (Throwable $e) {
    flash('err', $e->getMessage());
  }
  redirect($_SERVER['PHP_SELF'].'?id='.$dealerId.'#finance');
}

$statusFilter = $_GET['status'] ?? 'all';
$validStatusFilters = ['all', 'active', 'pending', 'inactive'];
if (!in_array($statusFilter, $validStatusFilters, true)) {
  $statusFilter = 'all';
}

$searchTerm = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;

$statusCounts = dealer_status_counts();
$activeCount = (int)($statusCounts['active'] ?? 0);
$pendingCount = (int)($statusCounts['pending'] ?? 0);
$inactiveCount = (int)($statusCounts['inactive'] ?? 0);
$totalDealers = (int)($statusCounts['total'] ?? ($activeCount + $pendingCount + $inactiveCount));

$dealerListConditions = [];
$dealerListParams = [];
if ($statusFilter !== 'all') {
  $dealerListConditions[] = 'status=?';
  $dealerListParams[] = $statusFilter;
}
if ($searchTerm !== '') {
  $dealerListConditions[] = '(name LIKE ? OR email LIKE ? OR phone LIKE ? OR company LIKE ? OR code LIKE ?)';
  $like = '%'.$searchTerm.'%';
  array_push($dealerListParams, $like, $like, $like, $like, $like);
}

$dealerBaseSql = 'FROM dealers';
if ($dealerListConditions) {
  $dealerBaseSql .= ' WHERE '.implode(' AND ', $dealerListConditions);
}

$countStmt = pdo()->prepare('SELECT COUNT(*) '.$dealerBaseSql);
$countStmt->execute($dealerListParams);
$totalMatches = (int)$countStmt->fetchColumn();
$totalMatches = max(0, $totalMatches);
$totalPages = max(1, (int)ceil($totalMatches / $perPage));
if ($page > $totalPages) {
  $page = $totalPages;
}
$offset = max(0, ($page - 1) * $perPage);

$dealerListSql = 'SELECT * '.$dealerBaseSql.' ORDER BY name LIMIT ? OFFSET ?';
$dealerListStmt = pdo()->prepare($dealerListSql);
$paramIndex = 1;
foreach ($dealerListParams as $param) {
  $dealerListStmt->bindValue($paramIndex++, $param);
}
$dealerListStmt->bindValue($paramIndex++, $perPage, PDO::PARAM_INT);
$dealerListStmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);
$dealerListStmt->execute();
$dealersList = $dealerListStmt->fetchAll();

$listStart = $totalMatches ? ($offset + 1) : 0;
$listEnd = $totalMatches ? min($offset + count($dealersList), $totalMatches) : 0;

$allDealers = pdo()->query('SELECT id, code, name, email, status FROM dealers ORDER BY name')->fetchAll();
$selectedId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$selectedDealer = $selectedId ? dealer_get($selectedId) : null;
$assignedVenues = $selectedDealer ? dealer_fetch_venues($selectedId) : [];
$assignedVenueIds = array_map(fn($v) => (int)$v['id'], $assignedVenues);
$allVenues = pdo()->query("SELECT * FROM venues ORDER BY name")->fetchAll();
$events = $selectedDealer ? dealer_allowed_events($selectedId) : [];
$buildDealerUrl = function(array $overrides = []) use ($statusFilter, $searchTerm, $page, $selectedId) {
  $query = [
    'status' => $statusFilter !== 'all' ? $statusFilter : null,
    'q' => $searchTerm !== '' ? $searchTerm : null,
    'page' => $page > 1 ? $page : null,
    'id' => $selectedId ?: null,
  ];
  foreach ($overrides as $key => $value) {
    if ($value === null || $value === '') {
      unset($query[$key]);
    } else {
      $query[$key] = $value;
    }
  }
  $query = array_filter($query, fn($value) => $value !== null && $value !== '');
  $queryString = http_build_query($query);
  return $queryString ? ('?'.$queryString) : ($_SERVER['PHP_SELF'] ?? '#');
};
if ($selectedDealer) {
  dealer_refresh_purchase_states($selectedId);
  $walletBalance = dealer_get_balance($selectedId);
  $walletTransactions = dealer_wallet_transactions($selectedId, 10);
  $walletFlowTotals = dealer_wallet_flow_totals($selectedId);
  $quotaSummary = dealer_event_quota_summary($selectedId);
  $purchaseHistory = dealer_fetch_purchases($selectedId);
  $cashbackPending = dealer_cashback_candidates($selectedId, DEALER_CASHBACK_PENDING);
  $cashbackPendingCount = count($cashbackPending);
  $cashbackPendingAmount = 0;
  foreach ($cashbackPending as $row) {
    $cashbackPendingAmount += max(0, (int)$row['cashback_amount']);
  }
  $topupRequests = dealer_topups_for_dealer($selectedId);
} else {
  $walletBalance = 0;
  $walletTransactions = [];
  $walletFlowTotals = ['in' => 0, 'out' => 0];
  $quotaSummary = ['active' => [], 'has_credit' => false, 'remaining_events' => 0, 'has_unlimited' => false, 'cashback_waiting' => 0, 'cashback_pending_amount' => 0, 'cashback_awaiting_event' => 0];
  $purchaseHistory = [];
  $cashbackPending = [];
  $cashbackPendingCount = 0;
  $cashbackPendingAmount = 0;
  $topupRequests = [];
}
$venueAssignments = dealer_fetch_venue_assignments();
$unassignedVenueCount = 0;
$totalVenueAssignments = 0;
foreach ($venueAssignments as $group) {
  $assignedCount = count($group['dealers']);
  $totalVenueAssignments += $assignedCount;
  if ($assignedCount === 0) {
    $unassignedVenueCount++;
  }
}
$assignedVenueCount = max(0, count($venueAssignments) - $unassignedVenueCount);

$dealerOptionPayload = array_map(function($dealer) {
  return [
    'value' => (int)$dealer['id'],
    'label' => $dealer['name'],
    'code' => $dealer['code'] ?? '',
    'email' => $dealer['email'] ?? '',
    'status' => $dealer['status'] ?? '',
  ];
}, $allDealers);

$venueOptionPayload = array_map(function($venue) {
  return [
    'value' => (int)$venue['id'],
    'label' => $venue['name'],
  ];
}, $allVenues);

if ($selectedDealer && !array_filter($dealersList, fn($row) => (int)$row['id'] === (int)$selectedDealer['id'])) {
  $selectedDealer['_virtual'] = true;
  array_unshift($dealersList, $selectedDealer);
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h(APP_NAME)?> — Bayi Yönetimi</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css">
<?=admin_base_styles()?>
<style>
  .card-lite{ padding:1.5rem; }
  .stats-row .stat-card{
    border-radius:18px;
    background:#fff;
    border:1px solid rgba(14,165,181,.12);
    padding:22px 24px;
    box-shadow:0 32px 60px -42px rgba(14,165,181,.55);
  }
  .stats-row .stat-label{font-size:.78rem;color:var(--admin-muted);letter-spacing:.08em;text-transform:uppercase;}
  .stats-row .stat-value{font-size:1.9rem;font-weight:700;color:var(--admin-ink);}
  .stats-row .stat-chip{display:inline-flex;align-items:center;gap:6px;padding:.28rem .85rem;border-radius:999px;background:rgba(14,165,181,.12);color:#0ea5b5;font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;}
  .badge-status{padding:.35rem .75rem;border-radius:999px;font-size:.75rem;font-weight:600;}
  .status-active{background:rgba(34,197,94,.15);color:#15803d;}
  .status-pending{background:rgba(250,204,21,.18);color:#854d0e;}
  .status-inactive{background:rgba(248,113,113,.16);color:#b91c1c;}
  .dealer-list .card-lite{padding:1.2rem 1.5rem;}
  .dealer-meta{color:var(--muted); font-size:.9rem;}
  .dealer-meta strong{color:var(--ink);}
  .dealer-code{font-family:"JetBrains Mono",monospace;font-size:.85rem;text-transform:uppercase;letter-spacing:.08em;color:var(--ink);}
  .dealer-list .list-group-item{padding:1rem 1.25rem;border:none;border-bottom:1px solid rgba(148,163,184,.15);transition:all .2s ease;}
  .dealer-list .list-group-item:hover{background:rgba(14,165,181,.08);}
  .dealer-list .list-group-item.active{background:rgba(14,165,181,.12);border-color:rgba(14,165,181,.28);box-shadow:0 18px 30px -26px rgba(14,165,181,.6);}
  .combo-helper{font-size:.8rem;color:var(--muted);}
  .ts-wrapper.form-select .ts-control{padding:.35rem .5rem;}
  .ts-wrapper.multi .ts-control>div{background:rgba(14,165,181,.12);color:#0f172a;border-radius:999px;padding:.25rem .5rem;font-weight:500;}
  .ts-wrapper.multi .ts-control>div .remove{color:rgba(15,23,42,.55);}
  .ts-wrapper.multi .ts-control>div .remove:hover{color:#0f172a;}
  .venue-chip-empty{color:var(--muted);font-size:.85rem;}
  .section-subtitle{font-size:.85rem;color:var(--muted);}
  .tab-card{border-radius:18px; background:#fff; border:1px solid rgba(148,163,184,.16); box-shadow:0 22px 45px -28px rgba(15,23,42,.45);}
  .dealer-search-form .btn{font-size:.8rem;padding-inline:1rem;}
  .dealer-virtual-note{margin-top:.6rem;background:rgba(14,165,181,.08);border:1px solid rgba(14,165,181,.18);border-radius:12px;padding:.5rem .75rem;font-size:.78rem;color:#055160;}
  .assignment-pill{display:inline-flex;align-items:center;gap:.45rem;padding:.4rem .85rem;border-radius:999px;background:rgba(14,165,181,.12);color:#0f172a;font-weight:600;font-size:.85rem;}
  .assignment-pill span{text-transform:uppercase;font-size:.68rem;letter-spacing:.08em;color:#0ea5b5;}
  .assignment-pill-group{display:flex;flex-wrap:wrap;gap:.5rem;justify-content:flex-end;}
  .assignment-summary{display:flex;flex-wrap:wrap;gap:.45rem;padding:.25rem 0;min-height:42px;}
  .assignment-summary.is-empty::before{content:attr(data-empty-text);color:var(--muted);font-size:.85rem;}
  .assignment-chip{display:inline-flex;align-items:center;gap:.4rem;padding:.35rem .75rem;border-radius:14px;background:#fff;border:1px solid rgba(14,165,181,.28);box-shadow:0 18px 34px -28px rgba(14,165,181,.55);font-size:.85rem;color:#0f172a;}
  .assignment-chip-code{font-family:"JetBrains Mono",monospace;font-size:.72rem;background:rgba(14,165,181,.16);color:#0ea5b5;padding:.1rem .45rem;border-radius:999px;text-transform:uppercase;letter-spacing:.05em;}
  .assignment-chip-email{font-size:.72rem;color:#64748b;}
  .assignment-chip-status{font-size:.7rem;padding:.1rem .45rem;border-radius:999px;text-transform:uppercase;letter-spacing:.05em;}
  .assignment-chip-status.status-active{background:rgba(34,197,94,.16);color:#166534;}
  .assignment-chip-status.status-pending{background:rgba(250,204,21,.18);color:#854d0e;}
  .assignment-chip-status.status-inactive{background:rgba(248,113,113,.16);color:#b91c1c;}
  .assignment-chip-remove{border:none;background:none;color:#0ea5b5;font-weight:700;font-size:1.1rem;line-height:1;padding:0 .1rem;cursor:pointer;}
  .assignment-chip-remove:hover{color:#0b8fa1;}
  .assignment-filter{background:rgba(14,165,181,.08);border:1px solid rgba(14,165,181,.22);border-radius:18px;}
  .assignment-filter .form-text{font-size:.8rem;color:var(--muted);}
  .assignment-inline-stats{display:flex;flex-wrap:wrap;gap:1.1rem;font-size:.9rem;color:var(--muted);}
  .assignment-inline-stats strong{color:#0f172a;}
  .assignment-accordion .accordion-item{border:none;border-radius:18px;margin-bottom:1rem;box-shadow:0 24px 45px -36px rgba(15,23,42,.45);overflow:hidden;}
  .assignment-accordion .accordion-item:last-child{margin-bottom:0;}
  .assignment-accordion .accordion-button{background:#f8fafc;font-weight:600;color:#0f172a;}
  .assignment-accordion .accordion-button:not(.collapsed){background:#e0f7fb;color:#055160;}
  .assignment-accordion .accordion-button:focus{box-shadow:none;}
  .assignment-accordion .accordion-body{background:#fff;border-top:1px solid rgba(14,165,181,.2);}
  .assignment-count{background:rgba(14,165,181,.18);color:#0a7281;font-weight:600;}
  .assignment-empty{border:1px dashed rgba(148,163,184,.35);border-radius:16px;background:#f8fafc;}
  .ts-dropdown .ts-code{font-family:"JetBrains Mono",monospace;font-size:.7rem;margin-right:.4rem;color:#0ea5b5;}
  .ts-dropdown .ts-option-line{display:flex;flex-direction:column;}
  .ts-dropdown .ts-option-line .ts-email{font-size:.72rem;color:#64748b;}
  .filter-pills .nav-link{padding:.35rem .65rem;font-size:.75rem;border-radius:999px;color:var(--muted);background:rgba(148,163,184,.18);margin-left:.35rem;transition:all .2s ease;}
  .filter-pills .nav-link:first-child{margin-left:0;}
  .filter-pills .nav-link:hover{background:rgba(14,165,181,.18);color:var(--admin-brand-dark);}
  .filter-pills .nav-link.active{background:var(--admin-brand);color:#fff;}
  .balance-stat{border:1px solid rgba(148,163,184,.2);border-radius:14px;padding:1rem 1.25rem;background:#f8fafc;}
  .balance-stat h6{font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:.35rem;}
  .balance-stat .value{font-size:1.35rem;font-weight:700;color:#0f172a;}
  .balance-stat.income .value{color:#15803d;}
  .balance-stat.expense .value{color:#b91c1c;}
  .badge-soft-lg{display:inline-flex;align-items:center;gap:.4rem;padding:.4rem .8rem;border-radius:999px;font-size:.85rem;font-weight:600;background:rgba(14,165,181,.12);color:#0f172a;}
  .wallet-direction{font-size:.75rem;font-weight:600;padding:.2rem .55rem;border-radius:999px;}
  .wallet-direction.in{background:rgba(34,197,94,.16);color:#166534;}
  .wallet-direction.out{background:rgba(248,113,113,.18);color:#b91c1c;}
</style>
</head>
<body class="admin-body">
<?php admin_layout_start('dealers', 'Bayi Yönetimi', 'Bayileri yönetin, salon atayın ve lisans durumlarını takip edin.'); ?>

  <?php flash_box(); ?>

  <div class="row g-3 stats-row mb-4">
    <div class="col-md-4">
      <div class="stat-card">
        <span class="stat-label">Toplam Bayi</span>
        <span class="stat-value"><?=$totalDealers?></span>
        <span class="stat-chip">Portföy</span>
      </div>
    </div>
    <div class="col-md-4">
      <div class="stat-card">
        <span class="stat-label">Aktif</span>
        <span class="stat-value text-success"><?=$activeCount?></span>
        <span class="stat-chip">Canlı</span>
      </div>
    </div>
    <div class="col-md-4">
      <div class="stat-card">
        <span class="stat-label">Onay Bekliyor</span>
        <span class="stat-value text-warning"><?=$pendingCount?></span>
        <span class="stat-chip">Sırada</span>
      </div>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-lg-5">
      <div class="card-lite p-4 mb-4">
        <h5 class="mb-1">Yeni Bayi Oluştur</h5>
        <p class="small text-muted mb-3">Bayi kaydı açıldığında benzersiz bayi kodu otomatik oluşturulur ve aktifleştirildiğinde giriş bilgileri e-posta ile iletilir.</p>
        <form method="post" class="row g-2">
          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="do" value="create">
          <div class="col-12">
            <label class="form-label">Ad Soyad</label>
            <input class="form-control" name="name" required>
          </div>
          <div class="col-12">
            <label class="form-label">E-posta</label>
            <input type="email" class="form-control" name="email" required>
          </div>
          <div class="col-6">
            <label class="form-label">Telefon</label>
            <input class="form-control" name="phone">
          </div>
          <div class="col-6">
            <label class="form-label">Firma</label>
            <input class="form-control" name="company">
          </div>
          <div class="col-12">
            <label class="form-label">Not</label>
            <textarea class="form-control" name="notes" rows="2"></textarea>
          </div>
          <div class="col-6">
            <label class="form-label">Durum</label>
            <select class="form-select" name="status">
              <option value="pending">Onay Bekliyor</option>
              <option value="active">Aktif</option>
              <option value="inactive">Pasif</option>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Lisans Bitişi</label>
            <input type="datetime-local" class="form-control" name="license_expires_at">
          </div>
          <div class="col-12 d-grid">
            <button class="btn btn-brand" type="submit">Kaydet</button>
          </div>
        </form>
      </div>
      <div class="card-lite p-0">
        <div class="p-3 border-bottom">
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
              <h5 class="m-0">Bayiler</h5>
              <span class="small text-muted">Filtreleyerek portföyünüzdeki bayileri yönetin.</span>
            </div>
            <div class="filter-pills nav nav-pills">
              <?php
                $statusLabels = [
                  'all' => 'Tümü',
                  'active' => 'Aktif',
                  'pending' => 'Onay Bekliyor',
                  'inactive' => 'Pasif',
                ];
              ?>
              <?php foreach ($statusLabels as $key => $label): ?>
                <?php $isActive = $statusFilter === $key; ?>
                <?php $statusUrl = $buildDealerUrl(['status' => $key === 'all' ? null : $key, 'page' => null]); ?>
                <a class="nav-link <?= $isActive ? 'active' : '' ?>" href="<?=h($statusUrl)?>">
                  <?=h($label)?>
                  <span class="fw-semibold ms-1">(<?= (int)($statusCounts[$key] ?? 0) ?>)</span>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <form method="get" class="dealer-search-form p-3 border-bottom">
          <input type="hidden" name="status" value="<?=h($statusFilter)?>">
          <?php if ($selectedId && empty($selectedDealer['_virtual'] ?? false)): ?>
            <input type="hidden" name="id" value="<?= (int)$selectedId ?>">
          <?php endif; ?>
          <div class="input-group input-group-sm">
            <input type="text" class="form-control" name="q" value="<?=h($searchTerm)?>" placeholder="Ad, e-posta, kod veya telefon ile ara">
            <?php if ($searchTerm !== ''): ?>
              <?php $clearUrl = $buildDealerUrl(['q' => null, 'page' => null]); ?>
              <a class="btn btn-outline-secondary" href="<?=h($clearUrl)?>">Temizle</a>
            <?php endif; ?>
            <button class="btn btn-brand" type="submit">Ara</button>
          </div>
          <div class="form-text mt-2">
            <?php if ($totalMatches): ?>
              <?=h($listStart)?>–<?=h($listEnd)?> / <?=h($totalMatches)?> sonuç gösteriliyor
            <?php else: ?>
              Aramanızla eşleşen bayi bulunamadı.
            <?php endif; ?>
          </div>
        </form>
        <div class="list-group list-group-flush dealer-list" style="max-height:420px;overflow:auto;">
          <?php if (!$dealersList): ?>
            <div class="p-4 text-center text-muted small">Liste boş.</div>
          <?php else: ?>
            <?php foreach ($dealersList as $d): ?>
              <?php
                $badge = dealer_status_badge($d['status']);
                $badgeClass = dealer_status_class($d['status']);
                $activeClass = ($selectedId === (int)$d['id']) ? 'active' : '';
                $license = $d['license_expires_at'] ? date('d.m.Y', strtotime($d['license_expires_at'])) : '—';
                $rowUrl = $buildDealerUrl(['id' => (int)$d['id']]);
                $isVirtual = !empty($d['_virtual']);
              ?>
              <a href="<?=h($rowUrl)?>" class="list-group-item list-group-item-action <?= $activeClass ?>">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <div>
                    <div class="fw-semibold me-2"><?=h($d['name'])?></div>
                    <div class="dealer-meta"><?=h($d['email'])?></div>
                  </div>
                  <span class="badge-status <?=$badgeClass?>"><?=h($badge)?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                  <span class="dealer-code"><?=h($d['code'] ?? '—')?></span>
                  <span class="dealer-meta">Lisans: <?=h($license)?></span>
                </div>
                <?php if ($isVirtual): ?>
                  <div class="dealer-virtual-note">Bu bayi mevcut filtre sonuçlarında yer almıyor ancak detaylarını görüntüleyebilirsiniz.</div>
                <?php endif; ?>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <?php if ($totalPages > 1): ?>
          <div class="px-3 py-2 border-top">
            <nav>
              <ul class="pagination pagination-sm mb-0">
                <?php $prevUrl = $buildDealerUrl(['page' => $page > 1 ? $page - 1 : null]); ?>
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                  <a class="page-link" href="<?=h($prevUrl)?>" tabindex="-1">Önceki</a>
                </li>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                  <?php
                    $pageUrl = $buildDealerUrl(['page' => $i === 1 ? null : $i]);
                    $isCurrent = $page === $i;
                  ?>
                  <li class="page-item <?= $isCurrent ? 'active' : '' ?>">
                    <a class="page-link" href="<?=h($pageUrl)?>"><?= $i ?></a>
                  </li>
                <?php endfor; ?>
                <?php $nextUrl = $buildDealerUrl(['page' => $page < $totalPages ? $page + 1 : $totalPages]); ?>
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                  <a class="page-link" href="<?=h($nextUrl)?>">Sonraki</a>
                </li>
              </ul>
            </nav>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-lg-7">
      <?php if (!$selectedDealer): ?>
        <div class="card-lite p-4">
          <h5 class="mb-2">Bayi seçin</h5>
          <p class="text-muted">Soldaki listeden bir bayi seçerek detaylarını görüntüleyin.</p>
        </div>
      <?php else: ?>
        <?php
          $licenseValue = $selectedDealer['license_expires_at'] ? date('Y-m-d\TH:i', strtotime($selectedDealer['license_expires_at'])) : '';
          $statusLabel = dealer_status_badge($selectedDealer['status']);
          $statusClass = dealer_status_class($selectedDealer['status']);
        ?>
        <div class="card-lite p-4 mb-4">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="m-0"><?=h($selectedDealer['name'])?></h5>
            <form method="post" class="m-0">
              <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="do" value="send_password">
              <input type="hidden" name="dealer_id" value="<?= (int)$selectedDealer['id'] ?>">
              <button class="btn btn-sm btn-outline-primary" type="submit">Yeni Şifre Gönder</button>
            </form>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <div class="balance-stat">
                <h6>Güncel Bakiye</h6>
                <div class="value"><?=h(format_currency($walletBalance))?></div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="balance-stat income">
                <h6>Toplam Giriş</h6>
                <div class="value"><?=h(format_currency($walletFlowTotals['in']))?></div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="balance-stat expense">
                <h6>Toplam Çıkış</h6>
                <div class="value"><?=h(format_currency($walletFlowTotals['out']))?></div>
              </div>
            </div>
          </div>
          <div class="d-flex flex-wrap gap-4 mb-3">
            <div>
              <div class="text-uppercase text-muted small fw-semibold">Bayi Kodu</div>
              <div class="dealer-code fs-5"><?=h($selectedDealer['code'])?></div>
            </div>
            <div>
              <div class="text-uppercase text-muted small fw-semibold">Durum</div>
              <span class="badge-status <?=$statusClass?>"><?=h($statusLabel)?></span>
            </div>
            <?php if (!empty($selectedDealer['license_expires_at'])): ?>
            <div>
              <div class="text-uppercase text-muted small fw-semibold">Lisans Bitişi</div>
              <div class="dealer-meta mb-0"><?=h(date('d.m.Y H:i', strtotime($selectedDealer['license_expires_at'])))?></div>
            </div>
            <?php endif; ?>
          </div>
          <form method="post" class="row g-2">
            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="do" value="update">
            <input type="hidden" name="dealer_id" value="<?= (int)$selectedDealer['id'] ?>">
            <div class="col-md-6">
              <label class="form-label">Ad Soyad</label>
              <input class="form-control" name="name" value="<?=h($selectedDealer['name'])?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">E-posta</label>
              <input type="email" class="form-control" name="email" value="<?=h($selectedDealer['email'])?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Telefon</label>
              <input class="form-control" name="phone" value="<?=h($selectedDealer['phone'])?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Firma</label>
              <input class="form-control" name="company" value="<?=h($selectedDealer['company'])?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Durum</label>
              <select class="form-select" name="status">
                <option value="pending" <?= $selectedDealer['status']==='pending'?'selected':'' ?>>Onay Bekliyor</option>
                <option value="active" <?= $selectedDealer['status']==='active'?'selected':'' ?>>Aktif</option>
                <option value="inactive" <?= $selectedDealer['status']==='inactive'?'selected':'' ?>>Pasif</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Lisans Bitiş</label>
              <input type="datetime-local" class="form-control" name="license_expires_at" value="<?=h($licenseValue)?>">
            </div>
            <div class="col-12">
              <label class="form-label">Not</label>
              <textarea class="form-control" name="notes" rows="2"><?=h($selectedDealer['notes'])?></textarea>
            </div>
            <div class="col-12 d-grid">
              <button class="btn btn-brand" type="submit">Bilgileri Güncelle</button>
            </div>
          </form>
        </div>

        <div class="card-lite p-4 mb-4" id="finance">
          <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
            <div>
              <h5 class="mb-1">Finansal Durum</h5>
              <p class="text-muted mb-0">Bakiye hareketlerini, paket haklarını ve cashback ödemelerini yönetin.</p>
            </div>
            <div class="text-end">
              <span class="badge-soft-lg"><span>Bakiye</span> <?=h(format_currency($walletBalance))?></span>
            </div>
          </div>
          <?php if (is_superadmin()): ?>
            <form method="post" class="row g-2 align-items-end mb-4">
              <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="do" value="wallet_adjust">
              <input type="hidden" name="dealer_id" value="<?= (int)$selectedDealer['id'] ?>">
              <div class="col-sm-4 col-md-3">
                <label class="form-label">Tutar (TL)</label>
                <input class="form-control" name="amount" placeholder="Örn. 500" required>
              </div>
              <div class="col-sm-3 col-md-2">
                <label class="form-label">İşlem</label>
                <select class="form-select" name="direction">
                  <option value="credit">Yükle</option>
                  <option value="debit">Düş</option>
                </select>
              </div>
              <div class="col-sm-5 col-md-4">
                <label class="form-label">Açıklama</label>
                <input class="form-control" name="description" placeholder="Opsiyonel not">
              </div>
              <div class="col-md-3 d-grid">
                <button class="btn btn-outline-primary" type="submit">Bakiye Güncelle</button>
              </div>
            </form>
          <?php endif; ?>
          <div class="row g-4 mb-4">
            <div class="col-lg-6">
              <h6 class="fw-semibold mb-2">Aktif Paketler</h6>
              <?php if (empty($quotaSummary['active'])): ?>
                <p class="text-muted small mb-0">Aktif paket bulunmuyor.</p>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Paket</th><th>Kalan</th><th>Bitiş</th><th>Cashback</th></tr></thead>
                    <tbody>
                      <?php foreach ($quotaSummary['active'] as $pkg): ?>
                        <?php
                          $quota = $pkg['event_quota'];
                          $used = $pkg['events_used'];
                          $remaining = $quota === null ? 'Sınırsız' : max(0, $quota - $used).' / '.$quota;
                          $expiry = $pkg['expires_at'] ? date('d.m.Y', strtotime($pkg['expires_at'])) : 'Süresiz';
                          $cashbackText = $pkg['cashback_status'] === DEALER_CASHBACK_PENDING ? format_currency($pkg['cashback_amount']) : ($pkg['cashback_rate'] > 0 ? number_format($pkg['cashback_rate'] * 100, 0).'%' : '—');
                        ?>
                        <tr>
                          <td><?=h($pkg['package_name'])?></td>
                          <td><?=h($remaining)?></td>
                          <td><?=h($expiry)?></td>
                          <td><?=h($cashbackText)?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
              <div class="text-muted small mt-2">
                Kalan hak: <?=h($quotaSummary['has_unlimited'] ? 'Sınırsız' : (string)$quotaSummary['remaining_events'])?> · Bekleyen cashback: <?=h($cashbackPendingCount)?><?= $cashbackPendingAmount ? ' • '.h(format_currency($cashbackPendingAmount)) : '' ?>
              </div>
            </div>
            <div class="col-lg-6">
              <h6 class="fw-semibold mb-2">Son Cari Hareketler</h6>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead><tr><th>Tarih</th><th>İşlem</th><th>Tip</th><th>Tutar</th><th>Bakiye</th></tr></thead>
                  <tbody>
                    <?php if (!$walletTransactions): ?>
                      <tr><td colspan="5" class="text-center text-muted">Henüz hareket kaydı yok.</td></tr>
                    <?php else: ?>
                      <?php foreach ($walletTransactions as $mov): ?>
                        <?php
                          $direction = $mov['amount_cents'] >= 0 ? 'in' : 'out';
                          $directionLabel = $direction === 'in' ? 'Giriş' : 'Çıkış';
                          $amountClass = $direction === 'in' ? 'text-success' : 'text-danger';
                        ?>
                        <tr>
                          <td><?=h(date('d.m.Y H:i', strtotime($mov['created_at'] ?? 'now')))?></td>
                          <td>
                            <div class="fw-semibold"><?=h(dealer_wallet_type_label($mov['type']))?></div>
                            <?php if (!empty($mov['description'])): ?><div class="small text-muted"><?=h($mov['description'])?></div><?php endif; ?>
                          </td>
                          <td>
                            <span class="wallet-direction <?=$direction?>"><?=h($directionLabel)?></span>
                          </td>
                          <td class="<?=$amountClass?> fw-semibold"><?=h(format_currency($mov['amount_cents']))?></td>
                          <td><?=h(format_currency($mov['balance_after']))?></td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <h6 class="fw-semibold mb-2">Bekleyen Cashback</h6>
          <div class="table-responsive mb-4">
            <table class="table table-sm align-middle mb-0">
              <thead><tr><th>Paket</th><th>Kaynak</th><th>Etkinlik</th><th>Tutar</th><th></th></tr></thead>
              <tbody>
                <?php if (!$cashbackPending): ?>
                  <tr><td colspan="5" class="text-center text-muted">Bekleyen cashback bulunmuyor.</td></tr>
                <?php else: ?>
                  <?php foreach ($cashbackPending as $cb): ?>
                    <tr>
                      <td><?=h($cb['package_name'])?></td>
                      <td>
                        <?php if (($cb['source'] ?? '') === DEALER_PURCHASE_SOURCE_LEAD): ?>
                          <span class="badge bg-info-subtle text-info-emphasis">Web siparişi<?php if (!empty($cb['order_id'])): ?> #<?= (int)$cb['order_id'] ?><?php endif; ?></span>
                        <?php else: ?>
                          <span class="badge bg-secondary-subtle text-secondary-emphasis">Bayi paketi</span>
                        <?php endif; ?>
                      </td>
                      <td><?=h($cb['event_title'] ?? 'Etkinlik yok')?><?= !empty($cb['event_date']) ? ' • '.h(date('d.m.Y', strtotime($cb['event_date']))) : '' ?><?php if (!empty($cb['customer_name'])): ?><div class="text-muted small"><?=h($cb['customer_name'])?><?= !empty($cb['customer_email']) ? ' · '.h($cb['customer_email']) : '' ?></div><?php endif; ?></td>
                      <td><?=h(format_currency($cb['cashback_amount']))?></td>
                      <td class="text-end">
                        <form method="post" class="d-inline">
                          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                          <input type="hidden" name="do" value="cashback_pay">
                          <input type="hidden" name="dealer_id" value="<?= (int)$selectedDealer['id'] ?>">
                          <input type="hidden" name="purchase_id" value="<?= (int)$cb['id'] ?>">
                          <button class="btn btn-sm btn-outline-primary" type="submit">Ödemeyi Onayla</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <h6 class="fw-semibold mb-2">Bakiye Yükleme Talepleri</h6>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead><tr><th>Tarih</th><th>Tutar</th><th>Durum</th><th></th></tr></thead>
              <tbody>
                <?php if (!$topupRequests): ?>
                  <tr><td colspan="4" class="text-center text-muted">Bekleyen yükleme talebi yok.</td></tr>
                <?php else: ?>
                  <?php foreach ($topupRequests as $req): ?>
                    <tr>
                      <td><?=h(date('d.m.Y H:i', strtotime($req['created_at'] ?? 'now')))?></td>
                      <td><?=h(format_currency($req['amount_cents']))?></td>
                      <td><?=h(dealer_topup_status_label($req['status']))?></td>
                      <td class="text-end">
                        <?php if (in_array($req['status'], [DEALER_TOPUP_STATUS_PENDING, DEALER_TOPUP_STATUS_AWAITING_REVIEW], true)): ?>
                          <div class="d-flex flex-column flex-lg-row gap-2 justify-content-end align-items-stretch">
                            <form method="post" class="d-flex gap-2">
                              <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                              <input type="hidden" name="do" value="topup_complete">
                              <input type="hidden" name="dealer_id" value="<?= (int)$selectedDealer['id'] ?>">
                              <input type="hidden" name="topup_id" value="<?= (int)$req['id'] ?>">
                              <input type="text" name="reference" class="form-control form-control-sm" placeholder="Referans (opsiyonel)" value="<?=h($req['paytr_reference'] ?? '')?>">
                              <button class="btn btn-sm btn-outline-primary" type="submit">Onayla</button>
                            </form>
                            <form method="post">
                              <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                              <input type="hidden" name="do" value="topup_cancel_admin">
                              <input type="hidden" name="dealer_id" value="<?= (int)$selectedDealer['id'] ?>">
                              <input type="hidden" name="topup_id" value="<?= (int)$req['id'] ?>">
                              <button class="btn btn-sm btn-outline-secondary" type="submit">İptal</button>
                            </form>
                          </div>
                          <?php if ($req['status'] === DEALER_TOPUP_STATUS_AWAITING_REVIEW): ?>
                            <div class="text-warning small mt-1">PayTR tarafından ödeme alındı, onay bekliyor.</div>
                          <?php endif; ?>
                        <?php else: ?>
                          <span class="text-muted small">—</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card-lite p-4 mb-4">
          <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
            <div>
              <h5 class="mb-1">Salon Atamaları</h5>
              <p class="combo-helper mb-0">Bayinin erişebileceği salonları arama destekli kombinasyonla yönetin.</p>
            </div>
            <span class="assignment-pill"><span>Atanmış salon</span><strong><?=h(count($assignedVenues))?></strong></span>
          </div>
          <div id="dealer-venue-summary" class="assignment-summary" data-empty-text="Bu bayiye henüz salon atanmadı."></div>
          <form method="post" class="row g-3 assignment-form mt-1">
            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="do" value="assign_venues">
            <input type="hidden" name="dealer_id" value="<?= (int)$selectedDealer['id'] ?>">
            <div class="col-12">
              <select class="form-select js-assignment-select" id="dealer-venues-select" name="venue_ids[]" multiple data-selected="<?=h(implode(',', $assignedVenueIds))?>" data-options-key="venues" data-summary="#dealer-venue-summary" data-empty-text="Bu bayiye henüz salon atanmadı." data-placeholder="Salon arayın"></select>
            </div>
            <div class="col-12 d-flex flex-wrap gap-2 justify-content-end">
              <button class="btn btn-outline-secondary" type="button" data-ts-clear="dealer-venues-select">Temizle</button>
              <button class="btn btn-outline-primary" type="submit">Atamaları Kaydet</button>
            </div>
          </form>
        </div>

      <?php endif; ?>
    </div>
  </div>

  <div class="row mt-4">
    <div class="col-12">
      <div class="card-lite p-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
          <div>
            <h5 class="mb-1">Salon Bazlı Bayi Atama Merkezi</h5>
            <p class="section-subtitle mb-0">Yüzlerce bayiyle çalışırken bile salon eşleştirmelerini filtreleyin, düzenleyin ve tek tıkla kaydedin.</p>
          </div>
          <div class="assignment-pill-group">
            <span class="assignment-pill"><span>Salon</span><strong><?=h(count($venueAssignments))?></strong></span>
            <span class="assignment-pill"><span>Ataması yapılmış</span><strong><?=h($assignedVenueCount)?></strong></span>
            <span class="assignment-pill"><span>Boşta</span><strong><?=h($unassignedVenueCount)?></strong></span>
          </div>
        </div>
        <?php if (!$allVenues): ?>
          <p class="text-muted mb-0">Henüz tanımlanmış salon bulunmuyor.</p>
        <?php else: ?>
          <div class="row g-3 align-items-stretch mb-3">
            <div class="col-lg-4">
              <div class="assignment-filter p-3 h-100">
                <label for="venueFilterInput" class="form-label">Salon veya bayi ara</label>
                <input type="text" id="venueFilterInput" class="form-control" placeholder="Salon adı, bayi kodu ya da e-posta">
                <div class="form-text">Yazdıkça sonuçlar filtrelenir.</div>
              </div>
            </div>
            <div class="col-lg-8 d-flex flex-column justify-content-center">
              <div class="assignment-inline-stats">
                <span>Toplam atama: <strong><?=h($totalVenueAssignments)?></strong></span>
                <span>Ortalama bayi/salon: <strong><?= $assignedVenueCount ? number_format($totalVenueAssignments / max(1, $assignedVenueCount), 1) : '0.0' ?></strong></span>
              </div>
            </div>
          </div>
          <div class="accordion assignment-accordion" id="venueAssignAccordion">
            <?php foreach ($venueAssignments as $group): ?>
              <?php
                $venue = $group['venue'];
                $assigned = $group['dealers'];
                $assignedIds = array_map(fn($d) => (int)$d['id'], $assigned);
                $searchTokens = [$venue['name'] ?? '', $venue['city'] ?? '', $venue['district'] ?? ''];
                foreach ($assigned as $dealer) {
                  $searchTokens[] = $dealer['name'] ?? '';
                  $searchTokens[] = $dealer['code'] ?? '';
                  $searchTokens[] = $dealer['email'] ?? '';
                }
                $searchText = trim(implode(' ', $searchTokens));
                $searchValue = $searchText !== '' ? mb_strtolower($searchText, 'UTF-8') : '';
              ?>
              <div class="accordion-item assignment-item" data-search="<?=h($searchValue)?>">
                <h2 class="accordion-header" id="heading-<?= (int)$venue['id'] ?>">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-<?= (int)$venue['id'] ?>" aria-expanded="false" aria-controls="collapse-<?= (int)$venue['id'] ?>">
                    <div class="d-flex justify-content-between align-items-center w-100 gap-3">
                      <div>
                        <span class="fw-semibold"><?=h($venue['name'])?></span>
                        <?php if (!empty($venue['city'])): ?>
                          <span class="text-muted ms-2"><?=h($venue['city'])?><?= !empty($venue['district']) ? ' • '.h($venue['district']) : '' ?></span>
                        <?php endif; ?>
                      </div>
                      <span class="assignment-count badge rounded-pill"><?=count($assigned)?> bayi</span>
                    </div>
                  </button>
                </h2>
                <div id="collapse-<?= (int)$venue['id'] ?>" class="accordion-collapse collapse" data-bs-parent="#venueAssignAccordion">
                  <div class="accordion-body">
                    <div id="venue-summary-<?= (int)$venue['id'] ?>" class="assignment-summary mb-3" data-empty-text="Bu salona henüz bayi atanmadı."></div>
                    <?php if ($allDealers): ?>
                      <form method="post" class="assignment-form">
                        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                        <input type="hidden" name="do" value="assign_venue_dealers">
                        <input type="hidden" name="venue_id" value="<?= (int)$venue['id'] ?>">
                        <select class="form-select js-assignment-select" id="venue-select-<?= (int)$venue['id'] ?>" name="dealer_ids[]" multiple data-selected="<?=h(implode(',', $assignedIds))?>" data-options-key="dealers" data-summary="#venue-summary-<?= (int)$venue['id'] ?>" data-empty-text="Bu salona henüz bayi atanmadı." data-placeholder="Bayi arayın"></select>
                        <div class="d-flex flex-wrap gap-2 justify-content-end mt-3">
                          <button class="btn btn-outline-secondary btn-sm" type="button" data-ts-clear="venue-select-<?= (int)$venue['id'] ?>">Temizle</button>
                          <button class="btn btn-brand btn-sm" type="submit">Kaydet</button>
                        </div>
                      </form>
                    <?php else: ?>
                      <p class="venue-chip-empty mb-0">Bayi tanımlanmadan atama yapılamaz.</p>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="assignment-empty d-none" id="assignmentEmpty">
            <div class="text-center text-muted py-4">Aramanızla eşleşen salon bulunamadı.</div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php admin_layout_end(); ?>
<script type="application/json" id="dealer-options-data"><?=json_encode($dealerOptionPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)?></script>
<script type="application/json" id="venue-options-data"><?=json_encode($venueOptionPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)?></script>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
  const statusLabels = { active: 'Aktif', pending: 'Onay Bekliyor', inactive: 'Pasif' };
  const optionData = {
    dealers: [],
    venues: []
  };
  const dealerOptionsEl = document.getElementById('dealer-options-data');
  const venueOptionsEl = document.getElementById('venue-options-data');
  try {
    optionData.dealers = dealerOptionsEl ? JSON.parse(dealerOptionsEl.textContent || '[]') : [];
  } catch (err) {
    optionData.dealers = [];
  }
  try {
    optionData.venues = venueOptionsEl ? JSON.parse(venueOptionsEl.textContent || '[]') : [];
  } catch (err) {
    optionData.venues = [];
  }

  const escapeMap = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'};
  const escapeHtml = function(value) {
    return String(value ?? '').replace(/[&<>"']/g, function(ch) {
      return escapeMap[ch] || ch;
    });
  };

  const refreshSummary = function(ts, summaryEl) {
    if (!summaryEl) return;
    const values = ts.getValue();
    summaryEl.innerHTML = '';
    if (!values.length) {
      summaryEl.classList.add('is-empty');
      return;
    }
    summaryEl.classList.remove('is-empty');
    values.forEach(function(val){
      const option = ts.options[val];
      if (!option) return;
      const chip = document.createElement('div');
      chip.className = 'assignment-chip';
      const parts = [];
      if (option.code) {
        parts.push('<span class="assignment-chip-code">'+escapeHtml(option.code)+'</span>');
      }
      parts.push('<span>'+escapeHtml(option.label)+'</span>');
      if (option.email) {
        parts.push('<span class="assignment-chip-email">'+escapeHtml(option.email)+'</span>');
      }
      if (option.status) {
        const statusLabel = statusLabels[option.status] || option.status;
        parts.push('<span class="assignment-chip-status status-'+escapeHtml(option.status)+'">'+escapeHtml(statusLabel)+'</span>');
      }
      const optionValue = option.value !== undefined ? option.value : val;
      parts.push('<button type="button" class="assignment-chip-remove" data-remove-item="'+escapeHtml(optionValue)+'">&times;</button>');
      chip.innerHTML = parts.join('');
      summaryEl.appendChild(chip);
    });
  };

  document.querySelectorAll('.js-assignment-select').forEach(function(selectEl){
    const key = selectEl.dataset.optionsKey || '';
    const options = optionData[key] || [];
    const selected = (selectEl.dataset.selected || '').split(',').map(function(v){ return v.trim(); }).filter(Boolean);
    const placeholder = selectEl.dataset.placeholder || '';
    const ts = new TomSelect(selectEl, {
      valueField: 'value',
      labelField: 'label',
      searchField: ['label','code','email'],
      options: options,
      items: selected,
      persist: false,
      create: false,
      hideSelected: true,
      closeAfterSelect: false,
      maxOptions: 1000,
      placeholder: placeholder,
      plugins: {
        remove_button: { title: 'Kaldır' }
      },
      render: {
        option: function(data, escape) {
          const code = data.code ? '<span class="ts-code">'+escape(data.code)+'</span>' : '';
          const email = data.email ? '<span class="ts-email">'+escape(data.email)+'</span>' : '';
          return '<div class="ts-option-line">'+code+'<span class="ts-label">'+escape(data.label)+'</span>'+email+'</div>';
        },
        item: function(data, escape) {
          const code = data.code ? '<span class="ts-code">'+escape(data.code)+'</span>' : '';
          return '<div>'+code+'<span>'+escape(data.label)+'</span></div>';
        }
      }
    });
    const summaryEl = selectEl.dataset.summary ? document.querySelector(selectEl.dataset.summary) : null;
    if (summaryEl) {
      summaryEl.classList.add('is-empty');
      summaryEl.addEventListener('click', function(ev){
        const btn = ev.target.closest('[data-remove-item]');
        if (btn) {
          ev.preventDefault();
          const value = btn.getAttribute('data-remove-item');
          if (value !== null) {
            ts.removeItem(value);
          }
        }
      });
      ts.on('change', function(){ refreshSummary(ts, summaryEl); });
      refreshSummary(ts, summaryEl);
    }
    selectEl.tomselect = ts;
  });

  document.querySelectorAll('[data-ts-clear]').forEach(function(btn){
    btn.addEventListener('click', function(){
      const targetId = btn.getAttribute('data-ts-clear');
      if (!targetId) return;
      const selectEl = document.getElementById(targetId);
      if (selectEl && selectEl.tomselect) {
        selectEl.tomselect.clear();
      }
    });
  });

  const filterInput = document.getElementById('venueFilterInput');
  if (filterInput) {
    const items = Array.from(document.querySelectorAll('.assignment-item'));
    const emptyState = document.getElementById('assignmentEmpty');
    const applyFilter = function(){
      const query = filterInput.value.trim().toLowerCase();
      let visible = 0;
      items.forEach(function(item){
        const haystack = (item.dataset.search || '').toLowerCase();
        const matches = !query || haystack.includes(query);
        item.classList.toggle('d-none', !matches);
        if (matches) visible++;
      });
      if (emptyState) {
        emptyState.classList.toggle('d-none', visible !== 0);
      }
    };
    filterInput.addEventListener('input', applyFilter);
    applyFilter();
  }
});
</script>
</body>
</html>
