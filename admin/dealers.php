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

$statusCounts = dealer_status_counts();
$dealerListSql = "SELECT * FROM dealers";
$dealerListParams = [];
if ($statusFilter !== 'all') {
  $dealerListSql .= " WHERE status=?";
  $dealerListParams[] = $statusFilter;
}
$dealerListSql .= " ORDER BY name";
$dealerListStmt = pdo()->prepare($dealerListSql);
$dealerListStmt->execute($dealerListParams);
$dealersList = $dealerListStmt->fetchAll();
$allDealers = pdo()->query("SELECT * FROM dealers ORDER BY name")->fetchAll();
$selectedId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$selectedDealer = $selectedId ? dealer_get($selectedId) : null;
$assignedVenues = $selectedDealer ? dealer_fetch_venues($selectedId) : [];
$assignedVenueIds = array_map(fn($v) => (int)$v['id'], $assignedVenues);
$allVenues = pdo()->query("SELECT * FROM venues ORDER BY name")->fetchAll();
$events = $selectedDealer ? dealer_allowed_events($selectedId) : [];
if ($selectedDealer) {
  dealer_refresh_purchase_states($selectedId);
  $walletBalance = dealer_get_balance($selectedId);
  $walletTransactions = dealer_wallet_transactions($selectedId, 10);
  $walletFlowTotals = dealer_wallet_flow_totals($selectedId);
  $quotaSummary = dealer_event_quota_summary($selectedId);
  $purchaseHistory = dealer_fetch_purchases($selectedId);
  $cashbackPending = dealer_cashback_candidates($selectedId, DEALER_CASHBACK_PENDING);
  $topupRequests = dealer_topups_for_dealer($selectedId);
} else {
  $walletBalance = 0;
  $walletTransactions = [];
  $walletFlowTotals = ['in' => 0, 'out' => 0];
  $quotaSummary = ['active' => [], 'has_credit' => false, 'remaining_events' => 0, 'has_unlimited' => false, 'cashback_waiting' => 0, 'cashback_pending_amount' => 0, 'cashback_awaiting_event' => 0];
  $purchaseHistory = [];
  $cashbackPending = [];
  $topupRequests = [];
}
$venueAssignments = dealer_fetch_venue_assignments();
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
  .badge-status{padding:.35rem .75rem;border-radius:999px;font-size:.75rem;font-weight:600;}
  .status-active{background:rgba(34,197,94,.15);color:#15803d;}
  .status-pending{background:rgba(250,204,21,.18);color:#854d0e;}
  .status-inactive{background:rgba(248,113,113,.16);color:#b91c1c;}
  .dealer-list .card-lite{padding:1.2rem 1.5rem;}
  .dealer-meta{color:var(--muted); font-size:.9rem;}
  .dealer-meta strong{color:var(--ink);}
  .dealer-code{font-family:"JetBrains Mono",monospace;font-size:.85rem;text-transform:uppercase;letter-spacing:.08em;color:var(--ink);}
  .dealer-list .list-group-item{padding:1rem 1.25rem;}
  .dealer-list .list-group-item.active{background:rgba(14,165,181,.08);border-color:rgba(14,165,181,.3);}
  .assigned-tags{display:flex;flex-wrap:wrap;gap:.4rem;}
  .assigned-tags .dealer-chip{background:rgba(14,165,181,.12);color:#0f172a;border-radius:999px;padding:.25rem .75rem;font-size:.75rem;font-weight:500;}
  .assigned-tags .dealer-chip span{font-weight:600;color:#0f172a;}
  .venue-card{border:1px solid rgba(148,163,184,.25);border-radius:14px;padding:1.25rem;margin-bottom:1.25rem;background:#fff;box-shadow:0 10px 28px -20px rgba(15,23,42,.4);}
  .venue-card:last-child{margin-bottom:0;}
  .venue-card h6{margin-bottom:.35rem;}
  .combo-helper{font-size:.8rem;color:var(--muted);}
  .ts-wrapper.form-select .ts-control{padding:.35rem .5rem;}
  .ts-wrapper.multi .ts-control>div{background:rgba(14,165,181,.12);color:#0f172a;border-radius:999px;padding:.25rem .5rem;font-weight:500;}
  .ts-wrapper.multi .ts-control>div .remove{color:rgba(15,23,42,.55);}
  .ts-wrapper.multi .ts-control>div .remove:hover{color:#0f172a;}
  .venue-chip-empty{color:var(--muted);font-size:.85rem;}
  .section-subtitle{font-size:.85rem;color:var(--muted);}
  .tab-card{border-radius:18px; background:#fff; border:1px solid rgba(148,163,184,.16); box-shadow:0 22px 45px -28px rgba(15,23,42,.45);}
  .filter-pills .nav-link{padding:.35rem .65rem;font-size:.75rem;border-radius:999px;color:var(--muted);background:rgba(148,163,184,.18);margin-left:.35rem;}
  .filter-pills .nav-link:first-child{margin-left:0;}
  .filter-pills .nav-link.active{background:#0ea5e9;color:#fff;}
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
<?php render_admin_topnav('dealers', 'Bayi Yönetimi', 'Bayileri yönetin, salon atayın ve lisans durumlarını takip edin.'); ?>

<main class="admin-main">
  <div class="container">
  <?php flash_box(); ?>
  <div class="row g-4">
    <div class="col-lg-5">
      <div class="card-lite p-4 mb-4">
        <h5 class="mb-3">Yeni Bayi Oluştur</h5>
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
            <h5 class="m-0">Bayiler</h5>
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
                <a class="nav-link <?= $isActive ? 'active' : '' ?>" href="?status=<?=$key?>">
                  <?=h($label)?>
                  <span class="fw-semibold ms-1">(<?= (int)($statusCounts[$key] ?? 0) ?>)</span>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="list-group list-group-flush" style="max-height:420px;overflow:auto;">
          <?php foreach ($dealersList as $d): ?>
            <?php
              $badge = dealer_status_badge($d['status']);
              $badgeClass = dealer_status_class($d['status']);
              $activeClass = ($selectedId === (int)$d['id']) ? 'active' : '';
              $license = $d['license_expires_at'] ? date('d.m.Y', strtotime($d['license_expires_at'])) : '—';
            ?>
            <?php
              $linkQuery = http_build_query(array_filter([
                'status' => $statusFilter !== 'all' ? $statusFilter : null,
                'id' => (int)$d['id'],
              ]));
            ?>
            <a href="?<?=$linkQuery?>" class="list-group-item list-group-item-action <?= $activeClass ?>">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <div class="fw-semibold me-2"><?=h($d['name'])?></div>
                <span class="badge-status <?=$badgeClass?>"><?=h($badge)?></span>
              </div>
              <div class="d-flex justify-content-between align-items-center">
                <span class="dealer-code"><?=h($d['code'] ?? '—')?></span>
                <span class="dealer-meta">Lisans: <?=h($license)?></span>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
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
          $codes = $selectedCodes;
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
                Kalan hak: <?=h($quotaSummary['has_unlimited'] ? 'Sınırsız' : (string)$quotaSummary['remaining_events'])?> · Cashback bekleyen paket: <?=h($quotaSummary['cashback_waiting'])?>
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
              <thead><tr><th>Paket</th><th>Etkinlik</th><th>Tutar</th><th></th></tr></thead>
              <tbody>
                <?php if (!$cashbackPending): ?>
                  <tr><td colspan="4" class="text-center text-muted">Bekleyen cashback bulunmuyor.</td></tr>
                <?php else: ?>
                  <?php foreach ($cashbackPending as $cb): ?>
                    <tr>
                      <td><?=h($cb['package_name'])?></td>
                      <td><?=h($cb['event_title'] ?? 'Etkinlik yok')?><?= !empty($cb['event_date']) ? ' • '.h(date('d.m.Y', strtotime($cb['event_date']))) : '' ?></td>
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
          <h5 class="mb-1">Salon Atamaları</h5>
          <p class="combo-helper mb-3">Birden fazla salonu seçebilir, arama yaparak kolayca filtreleyebilirsiniz.</p>
          <?php if ($assignedVenues): ?>
            <div class="assigned-tags mb-3">
              <?php foreach ($assignedVenues as $v): ?>
                <span class="dealer-chip"><span><?=h($v['name'])?></span></span>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="venue-chip-empty mb-3">Bu bayiye henüz salon atanmadı.</div>
          <?php endif; ?>
          <form method="post" class="row g-3">
            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="do" value="assign_venues">
            <input type="hidden" name="dealer_id" value="<?= (int)$selectedDealer['id'] ?>">
            <div class="col-12">
              <select class="form-select js-combobox" name="venue_ids[]" multiple data-placeholder="Salon seçin">
                <?php foreach ($allVenues as $v): ?>
                  <option value="<?= (int)$v['id'] ?>" <?= in_array((int)$v['id'], $assignedVenueIds, true) ? 'selected' : '' ?>><?=h($v['name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 d-grid">
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
        <div class="d-flex justify-content-between align-items-start mb-3">
          <div>
            <h5 class="mb-1">Salon Bazlı Bayi Ataması</h5>
            <p class="section-subtitle mb-0">Salonlara atanmış bayileri görüntüleyin ve çoklu seçimle hızla güncelleyin.</p>
          </div>
        </div>
        <?php if (!$allVenues): ?>
          <p class="text-muted mb-0">Henüz tanımlanmış salon bulunmuyor.</p>
        <?php else: ?>
        <div class="row g-3">
          <?php foreach ($venueAssignments as $group): ?>
            <?php
              $venue = $group['venue'];
              $assigned = $group['dealers'];
              $assignedIds = array_map(fn($d) => (int)$d['id'], $assigned);
            ?>
            <div class="col-xl-6">
              <div class="venue-card" id="venue-<?= (int)$venue['id'] ?>">
                <h6 class="fw-semibold mb-1"><?=h($venue['name'])?></h6>
                <?php if ($assigned): ?>
                  <div class="assigned-tags mb-2">
                    <?php foreach ($assigned as $dealer): ?>
                      <span class="dealer-chip"><span><?=h($dealer['code'] ?? '—')?></span> • <?=h($dealer['name'])?></span>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <div class="venue-chip-empty mb-2">Bu salona henüz bayi atanmadı.</div>
                <?php endif; ?>
                <?php if ($allDealers): ?>
                  <form method="post" class="vstack gap-2">
                    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="do" value="assign_venue_dealers">
                    <input type="hidden" name="venue_id" value="<?= (int)$venue['id'] ?>">
                    <select class="form-select js-combobox" name="dealer_ids[]" multiple data-placeholder="Bayi seçin">
                      <?php foreach ($allDealers as $dealerOption): ?>
                        <option value="<?= (int)$dealerOption['id'] ?>" <?= in_array((int)$dealerOption['id'], $assignedIds, true) ? 'selected' : '' ?>><?=h(($dealerOption['code'] ?? '—').' • '.$dealerOption['name'])?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-brand align-self-start" type="submit">Kaydet</button>
                  </form>
                <?php else: ?>
                  <p class="venue-chip-empty mb-0">Bayi tanımlanmadan atama yapılamaz.</p>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
  document.querySelectorAll('.js-combobox').forEach(function(el){
    new TomSelect(el, {
      plugins: {
        remove_button: { title: 'Seçimi kaldır' }
      },
      persist: false,
      create: false,
      hideSelected: true,
      closeAfterSelect: false,
      placeholder: el.dataset.placeholder || '',
      sortField: { field: 'text', direction: 'asc' }
    });
  });
});
</script>
</body>
</html>
