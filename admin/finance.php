<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/dealers.php';
require_once __DIR__.'/../includes/representatives.php';
require_once __DIR__.'/../includes/finance.php';
require_once __DIR__.'/partials/ui.php';

require_admin();
install_schema();

$action = $_POST['do'] ?? '';
if ($action) {
  csrf_or_die();
  if (!is_superadmin()) {
    flash('err', 'Bu işlem için yetkiniz yok.');
    redirect($_SERVER['PHP_SELF']);
  }
}

if ($action === 'topup_complete') {
  $topupId = (int)($_POST['topup_id'] ?? 0);
  $reference = trim($_POST['reference'] ?? '');
  if ($topupId <= 0) {
    flash('err', 'Yükleme kaydı bulunamadı.');
    redirect($_SERVER['PHP_SELF'].'#approvals');
  }
  try {
    dealer_mark_topup_completed($topupId, $reference !== '' ? $reference : null);
    flash('ok', 'Bakiye yükleme talebi onaylandı.');
  } catch (Throwable $e) {
    flash('err', $e->getMessage());
  }
  redirect($_SERVER['PHP_SELF'].'#approvals');
}

if ($action === 'topup_cancel') {
  $topupId = (int)($_POST['topup_id'] ?? 0);
  if ($topupId <= 0) {
    flash('err', 'Yükleme kaydı bulunamadı.');
    redirect($_SERVER['PHP_SELF'].'#approvals');
  }
  try {
    dealer_cancel_topup($topupId);
    flash('ok', 'Yükleme talebi iptal edildi.');
  } catch (Throwable $e) {
    flash('err', $e->getMessage());
  }
  redirect($_SERVER['PHP_SELF'].'#approvals');
}

if ($action === 'commission_update') {
  $commissionId = (int)($_POST['commission_id'] ?? 0);
  $status = $_POST['status'] ?? REPRESENTATIVE_COMMISSION_STATUS_PENDING;
  $note = $_POST['note'] ?? null;
  if ($commissionId <= 0) {
    flash('err', 'Komisyon kaydı bulunamadı.');
    redirect($_SERVER['PHP_SELF'].'#approvals');
  }
  try {
    representative_commission_update_status($commissionId, $status, ['note' => $note]);
    flash('ok', 'Komisyon durumu güncellendi.');
  } catch (Throwable $e) {
    flash('err', $e->getMessage());
  }
  redirect($_SERVER['PHP_SELF'].'#approvals');
}

if ($action === 'cashback_pay') {
  $purchaseId = (int)($_POST['purchase_id'] ?? 0);
  $note = trim($_POST['note'] ?? '');
  if ($purchaseId <= 0) {
    flash('err', 'Cashback kaydı bulunamadı.');
    redirect($_SERVER['PHP_SELF'].'#cashbacks');
  }
  try {
    dealer_pay_cashback($purchaseId, $note);
    flash('ok', 'Cashback ödemesi tamamlandı.');
  } catch (Throwable $e) {
    flash('err', $e->getMessage());
  }
  redirect($_SERVER['PHP_SELF'].'#cashbacks');
}

$overview = finance_overview();
$totals = $overview['totals'] ?? ['revenue_total' => 0, 'revenue_last_30' => 0, 'payout_total' => 0, 'payout_last_30' => 0, 'net_last_30' => 0];
$topups = $overview['topups'] ?? [];
$orders = $overview['orders'] ?? [];
$commissions = $overview['commissions'] ?? [];
$cashbacks = $overview['cashbacks'] ?? [];

$pendingTopups = finance_recent_topups(10, [DEALER_TOPUP_STATUS_PENDING, DEALER_TOPUP_STATUS_AWAITING_REVIEW]);
$pendingCommissions = finance_recent_commissions(10, [REPRESENTATIVE_COMMISSION_STATUS_PENDING, REPRESENTATIVE_COMMISSION_STATUS_APPROVED]);
$pendingCashbacks = finance_pending_cashbacks(8);
$recentOrders = finance_recent_orders(8);
$monthlySummary = finance_monthly_summary(6);

$pendingTopupAmount = ($topups['pending_amount'] ?? 0) + ($topups['review_amount'] ?? 0);
$pendingTopupCount = ($topups['pending_count'] ?? 0) + ($topups['review_count'] ?? 0);
$avgTopup = (int)round($topups['average_amount'] ?? 0);
$avgOrder = (int)round($orders['average_amount'] ?? 0);
$avgCommission = (int)round($commissions['average_paid_amount'] ?? 0);
$avgCashback = (int)round($cashbacks['average_paid_amount'] ?? 0);

$projectsTotal = $orders['total_count'] ?? 0;
$projectsLast30 = $orders['last_30_count'] ?? 0;

$title = 'Finans Merkezi';
$subtitle = 'Gelirleri, giderleri ve ödeme onay süreçlerini tek ekrandan yönetin.';

?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h(APP_NAME)?> — Finans</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?=admin_base_styles()?>
<style>
  .finance-grid { display:grid; gap:18px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
  .finance-card { border-radius:18px; padding:20px; background:linear-gradient(140deg, rgba(14,165,181,.12), rgba(255,255,255,.95)); border:1px solid rgba(14,165,181,.18); box-shadow:0 24px 60px -40px rgba(14,165,181,.45); display:flex; flex-direction:column; gap:6px; }
  .finance-card span { text-transform:uppercase; font-weight:600; font-size:.75rem; letter-spacing:.12em; color:var(--admin-muted); }
  .finance-card h4 { margin:0; font-size:1.8rem; font-weight:700; color:var(--admin-ink); }
  .finance-card small { color:var(--admin-muted); font-weight:500; }
  .finance-card.positive h4 { color:#166534; }
  .finance-card.negative h4 { color:#b91c1c; }
  .finance-card.neutral h4 { color:#0f172a; }
  .finance-section { border-radius:20px; background:#fff; border:1px solid rgba(15,23,42,.08); box-shadow:0 25px 48px -40px rgba(15,23,42,.35); }
  .finance-section h5 { font-weight:600; }
  .finance-section .table thead th { text-transform:uppercase; letter-spacing:.6px; font-size:.72rem; color:var(--admin-muted); }
  .stat-strip { display:flex; flex-wrap:wrap; gap:18px; }
  .stat-pill { flex:1; min-width:180px; border-radius:16px; background:rgba(148,163,184,.12); padding:16px; display:flex; flex-direction:column; gap:4px; }
  .stat-pill strong { font-size:1.2rem; color:var(--admin-ink); }
  .stat-pill span { font-size:.8rem; font-weight:600; color:var(--admin-muted); text-transform:uppercase; letter-spacing:.08em; }
  .status-chip { border-radius:12px; padding:.25rem .6rem; font-weight:600; font-size:.75rem; display:inline-flex; align-items:center; gap:.3rem; }
  .status-chip.pending { background:rgba(250,204,21,.22); color:#854d0e; }
  .status-chip.review { background:rgba(59,130,246,.18); color:#1d4ed8; }
  .status-chip.approved { background:rgba(45,212,191,.2); color:#0f766e; }
  .status-chip.paid { background:rgba(34,197,94,.2); color:#166534; }
  .status-chip.rejected { background:rgba(248,113,113,.25); color:#b91c1c; }
  .commission-actions { display:flex; flex-wrap:wrap; gap:8px; }
  .commission-actions form { display:inline-flex; }
  .commission-actions .btn { display:inline-flex; align-items:center; gap:6px; }
  .commission-actions .btn i { font-size:1rem; }
  @media (min-width: 992px) {
    .commission-actions { justify-content:flex-start; }
  }
  @media (max-width: 991px) {
    .commission-actions { justify-content:flex-end; }
  }
  @media (max-width: 991px) {
    .finance-grid { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }
  }
</style>
</head>
<body class="admin-body">
<?php admin_layout_start('finance', $title, $subtitle, 'bi-wallet2'); ?>
<?=flash_messages()?>

<section class="mb-4">
  <div class="finance-grid">
    <div class="finance-card">
      <span>Toplam Gelir</span>
      <h4><?=format_currency($totals['revenue_total'] ?? 0)?></h4>
      <small>Bayi yüklemeleri ve site satışlarından elde edilen toplam tahsilat.</small>
    </div>
    <div class="finance-card">
      <span>Son 30 Gün Geliri</span>
      <h4><?=format_currency($totals['revenue_last_30'] ?? 0)?></h4>
      <small>Son 30 gün içerisinde tamamlanan yükleme ve satışların toplamı.</small>
    </div>
    <div class="finance-card">
      <span>Bekleyen Bakiye Yükleme</span>
      <h4><?=format_currency($pendingTopupAmount)?></h4>
      <small><?=$pendingTopupCount?> talep onay bekliyor.</small>
    </div>
    <div class="finance-card">
      <span>Bekleyen Komisyon</span>
      <h4><?=format_currency($commissions['pending_amount'] ?? 0)?></h4>
      <small><?= (int)($commissions['pending_count'] ?? 0) ?> kayıt onay sürecinde.</small>
    </div>
    <div class="finance-card">
      <span>Onaylanan Komisyon</span>
      <h4><?=format_currency($commissions['approved_amount'] ?? 0)?></h4>
      <small><?= (int)($commissions['approved_count'] ?? 0) ?> ödeme transfer için hazır.</small>
    </div>
    <div class="finance-card">
      <span>Ödenen Komisyon</span>
      <h4><?=format_currency($commissions['paid_amount'] ?? 0)?></h4>
      <small><?= (int)($commissions['paid_count'] ?? 0) ?> ödeme tamamlandı.</small>
    </div>
    <div class="finance-card">
      <span>Bekleyen Cashback</span>
      <h4><?=format_currency($cashbacks['pending_amount'] ?? 0)?></h4>
      <small><?= (int)($cashbacks['pending_count'] ?? 0) ?> cashback ödemesi sırada.</small>
    </div>
    <div class="finance-card <?= ($totals['net_last_30'] ?? 0) >= 0 ? 'positive' : 'negative' ?>">
      <span>Son 30 Gün Net</span>
      <h4><?=format_currency($totals['net_last_30'] ?? 0)?></h4>
      <small>Gelir ve ödemeler sonrası net kazanç.</small>
    </div>
  </div>
</section>

<section class="finance-section p-4 mb-4">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
    <div>
      <h5>Ortalama Performans</h5>
      <small class="text-muted">Gelir ve ödemelerin ortalama tutarları.</small>
    </div>
    <div class="text-muted small">Toplam proje: <?=number_format($projectsTotal, 0, ',', '.') ?> • Son 30 gün: <?=number_format($projectsLast30, 0, ',', '.')?></div>
  </div>
  <div class="stat-strip">
    <div class="stat-pill">
      <span>Ortalama Yükleme</span>
      <strong><?=format_currency($avgTopup)?></strong>
      <small>Bayi başına ortalama bakiye yükleme tutarı.</small>
    </div>
    <div class="stat-pill">
      <span>Ortalama Satış</span>
      <strong><?=format_currency($avgOrder)?></strong>
      <small>Site üzerinden satılan paketlerin ortalama tutarı.</small>
    </div>
    <div class="stat-pill">
      <span>Ortalama Komisyon</span>
      <strong><?=format_currency($avgCommission)?></strong>
      <small>Temsilci başına ödenen komisyon ortalaması.</small>
    </div>
    <div class="stat-pill">
      <span>Ortalama Cashback</span>
      <strong><?=format_currency($avgCashback)?></strong>
      <small>Bayilere yapılan cashback ödemelerinin ortalaması.</small>
    </div>
  </div>
</section>

<section id="approvals" class="finance-section p-0 mb-4">
  <div class="p-4 border-bottom border-light">
    <h5 class="mb-0">Ödeme Onay Merkezi</h5>
    <small class="text-muted">Bayi yüklemelerini ve temsilci komisyonlarını onaylayın.</small>
  </div>
  <div class="row g-0">
    <div class="col-lg-6 border-end border-light">
      <div class="p-4">
        <h6 class="fw-semibold mb-3">Bayi Yüklemeleri</h6>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Bayi</th><th>Tutar</th><th>Durum</th><th>Tarih</th><th></th></tr></thead>
            <tbody>
              <?php if (!$pendingTopups): ?>
                <tr><td colspan="5" class="text-center text-muted">Onay bekleyen yükleme bulunmuyor.</td></tr>
              <?php else: ?>
                <?php foreach ($pendingTopups as $row): ?>
                  <?php
                    $status = $row['status'] ?? DEALER_TOPUP_STATUS_PENDING;
                    $chipClass = 'status-chip pending';
                    $label = dealer_topup_status_label($status);
                    if ($status === DEALER_TOPUP_STATUS_AWAITING_REVIEW) {
                      $chipClass = 'status-chip review';
                    }
                  ?>
                  <tr>
                    <td>
                      <div class="fw-semibold"><?=h($row['dealer_name'] ?? '—')?></div>
                      <?php if (!empty($row['dealer_code'])): ?><div class="small text-muted">Kod: <?=h($row['dealer_code'])?></div><?php endif; ?>
                    </td>
                    <td><?=format_currency($row['amount_cents'] ?? 0)?></td>
                    <td><span class="<?=$chipClass?>"><?=h($label)?></span></td>
                    <td><?= $row['created_at'] ? h(date('d.m.Y H:i', strtotime($row['created_at']))) : '—' ?></td>
                    <td class="text-end">
                      <form method="post" class="d-flex flex-column flex-lg-row gap-2 align-items-stretch">
                        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                        <input type="hidden" name="do" value="topup_complete">
                        <input type="hidden" name="topup_id" value="<?= (int)$row['id'] ?>">
                        <input type="text" name="reference" class="form-control form-control-sm" placeholder="Referans" value="<?=h($row['paytr_reference'] ?? '')?>">
                        <button class="btn btn-sm btn-outline-primary" type="submit">Onayla</button>
                      </form>
                      <form method="post" class="mt-2">
                        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                        <input type="hidden" name="do" value="topup_cancel">
                        <input type="hidden" name="topup_id" value="<?= (int)$row['id'] ?>">
                        <button class="btn btn-sm btn-outline-secondary w-100" type="submit">İptal</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="p-4">
        <h6 class="fw-semibold mb-3">Temsilci Komisyonları</h6>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Temsilci</th><th>Bayi</th><th>Komisyon</th><th>Durum</th><th></th></tr></thead>
            <tbody>
              <?php if (!$pendingCommissions): ?>
                <tr><td colspan="5" class="text-center text-muted">Onay bekleyen komisyon bulunmuyor.</td></tr>
              <?php else: ?>
                <?php foreach ($pendingCommissions as $row): ?>
                  <?php
                    $status = $row['status'] ?? REPRESENTATIVE_COMMISSION_STATUS_PENDING;
                    $chipClass = 'status-chip pending';
                    if ($status === REPRESENTATIVE_COMMISSION_STATUS_APPROVED) {
                      $chipClass = 'status-chip approved';
                    } elseif ($status === REPRESENTATIVE_COMMISSION_STATUS_PAID) {
                      $chipClass = 'status-chip paid';
                    } elseif ($status === REPRESENTATIVE_COMMISSION_STATUS_REJECTED) {
                      $chipClass = 'status-chip rejected';
                    }
                    $isPending = $status === REPRESENTATIVE_COMMISSION_STATUS_PENDING;
                    $isApproved = $status === REPRESENTATIVE_COMMISSION_STATUS_APPROVED;
                    $isPaid = $status === REPRESENTATIVE_COMMISSION_STATUS_PAID;
                    $isRejected = $status === REPRESENTATIVE_COMMISSION_STATUS_REJECTED;
                  ?>
                  <tr>
                    <td>
                      <div class="fw-semibold"><?=h($row['representative_name'] ?? '—')?></div>
                      <?php if (!empty($row['representative_email'])): ?><div class="small text-muted"><?=h($row['representative_email'])?></div><?php endif; ?>
                    </td>
                    <td>
                      <div><?=h($row['dealer_name'] ?? '—')?></div>
                      <?php if (!empty($row['dealer_code'])): ?><div class="small text-muted">Kod: <?=h($row['dealer_code'])?></div><?php endif; ?>
                    </td>
                    <td>
                      <div class="fw-semibold text-success"><?=format_currency($row['commission_cents'] ?? 0)?></div>
                      <div class="small text-muted">Yükleme: <?=format_currency($row['topup_amount_cents'] ?? 0)?></div>
                    </td>
                    <td>
                      <span class="<?=$chipClass?>"><?=h(representative_commission_status_label($status))?></span>
                      <div class="small text-muted">Oluşturma: <?= $row['created_at'] ? h(date('d.m.Y H:i', strtotime($row['created_at']))) : '—' ?></div>
                    </td>
                    <td class="text-end text-lg-start">
                      <?php if ($isPending || $isApproved || $isPaid || $isRejected): ?>
                        <div class="commission-actions mb-2">
                          <?php if ($isPending): ?>
                            <form method="post">
                              <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                              <input type="hidden" name="do" value="commission_update">
                              <input type="hidden" name="commission_id" value="<?= (int)$row['id'] ?>">
                              <input type="hidden" name="status" value="<?=REPRESENTATIVE_COMMISSION_STATUS_APPROVED?>">
                              <input type="hidden" name="note" value="Finans panelinden onaylandı.">
                              <button class="btn btn-sm btn-success" type="submit"><i class="bi bi-check-circle"></i><span>Onayla</span></button>
                            </form>
                            <form method="post">
                              <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                              <input type="hidden" name="do" value="commission_update">
                              <input type="hidden" name="commission_id" value="<?= (int)$row['id'] ?>">
                              <input type="hidden" name="status" value="<?=REPRESENTATIVE_COMMISSION_STATUS_REJECTED?>">
                              <input type="hidden" name="note" value="Finans panelinden reddedildi.">
                              <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-x-circle"></i><span>Reddet</span></button>
                            </form>
                          <?php elseif ($isApproved): ?>
                            <form method="post">
                              <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                              <input type="hidden" name="do" value="commission_update">
                              <input type="hidden" name="commission_id" value="<?= (int)$row['id'] ?>">
                              <input type="hidden" name="status" value="<?=REPRESENTATIVE_COMMISSION_STATUS_PAID?>">
                              <input type="hidden" name="note" value="Ödeme transferi tamamlandı.">
                              <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-cash-coin"></i><span>Ödendi</span></button>
                            </form>
                            <form method="post">
                              <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                              <input type="hidden" name="do" value="commission_update">
                              <input type="hidden" name="commission_id" value="<?= (int)$row['id'] ?>">
                              <input type="hidden" name="status" value="<?=REPRESENTATIVE_COMMISSION_STATUS_PENDING?>">
                              <input type="hidden" name="note" value="Tekrar incelemeye alındı.">
                              <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-arrow-counterclockwise"></i><span>İncelemeye Al</span></button>
                            </form>
                          <?php elseif ($isPaid): ?>
                            <form method="post">
                              <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                              <input type="hidden" name="do" value="commission_update">
                              <input type="hidden" name="commission_id" value="<?= (int)$row['id'] ?>">
                              <input type="hidden" name="status" value="<?=REPRESENTATIVE_COMMISSION_STATUS_APPROVED?>">
                              <input type="hidden" name="note" value="Ödeme sonrası tekrar onaya alındı.">
                              <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-arrow-repeat"></i><span>Onaya Al</span></button>
                            </form>
                          <?php elseif ($isRejected): ?>
                            <form method="post">
                              <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                              <input type="hidden" name="do" value="commission_update">
                              <input type="hidden" name="commission_id" value="<?= (int)$row['id'] ?>">
                              <input type="hidden" name="status" value="<?=REPRESENTATIVE_COMMISSION_STATUS_PENDING?>">
                              <input type="hidden" name="note" value="Reddedilen komisyon tekrar incelenecek.">
                              <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-arrow-clockwise"></i><span>Tekrar İncele</span></button>
                            </form>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                      <form method="post" class="commission-edit-form d-flex flex-column flex-lg-row gap-2">
                        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                        <input type="hidden" name="do" value="commission_update">
                        <input type="hidden" name="commission_id" value="<?= (int)$row['id'] ?>">
                        <select name="status" class="form-select form-select-sm">
                          <option value="<?=REPRESENTATIVE_COMMISSION_STATUS_PENDING?>" <?= $isPending ? 'selected' : '' ?>>Onay Bekliyor</option>
                          <option value="<?=REPRESENTATIVE_COMMISSION_STATUS_APPROVED?>" <?= $isApproved ? 'selected' : '' ?>>Ödeme Hazır</option>
                          <option value="<?=REPRESENTATIVE_COMMISSION_STATUS_PAID?>" <?= $isPaid ? 'selected' : '' ?>>Ödendi</option>
                          <option value="<?=REPRESENTATIVE_COMMISSION_STATUS_REJECTED?>" <?= $isRejected ? 'selected' : '' ?>>Reddedildi</option>
                        </select>
                        <input type="text" name="note" class="form-control form-control-sm" placeholder="Not (opsiyonel)" value="<?=h($row['notes'] ?? '')?>">
                        <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-save"></i><span>Kaydet</span></button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</section>

<section id="cashbacks" class="finance-section p-4 mb-4">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
    <div>
      <h5>Bekleyen Cashback Ödemeleri</h5>
      <small class="text-muted">Bayilere ödenmesi gereken cashback kayıtları.</small>
    </div>
    <div class="text-muted small">Toplam: <?=format_currency($cashbacks['pending_amount'] ?? 0)?> • <?= (int)($cashbacks['pending_count'] ?? 0) ?> kayıt</div>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead><tr><th>Bayi</th><th>Paket</th><th>Tutar</th><th>Müşteri</th><th></th></tr></thead>
      <tbody>
        <?php if (!$pendingCashbacks): ?>
          <tr><td colspan="5" class="text-center text-muted">Bekleyen cashback kaydı bulunmuyor.</td></tr>
        <?php else: ?>
          <?php foreach ($pendingCashbacks as $row): ?>
            <tr>
              <td>
                <div class="fw-semibold"><?=h($row['dealer_name'] ?? '—')?></div>
                <?php if (!empty($row['dealer_code'])): ?><div class="small text-muted">Kod: <?=h($row['dealer_code'])?></div><?php endif; ?>
              </td>
              <td>
                <div><?=h($row['package_name'] ?? '—')?></div>
                <div class="small text-muted">Talep: <?= $row['created_at'] ? h(date('d.m.Y', strtotime($row['created_at']))) : '—' ?></div>
              </td>
              <td><?=format_currency($row['cashback_amount'] ?? 0)?></td>
              <td>
                <div><?=h($row['customer_name'] ?? '—')?></div>
                <?php if (!empty($row['customer_email'])): ?><div class="small text-muted"><?=h($row['customer_email'])?></div><?php endif; ?>
              </td>
              <td class="text-end">
                <form method="post" class="d-flex flex-column flex-lg-row gap-2 align-items-stretch">
                  <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="do" value="cashback_pay">
                  <input type="hidden" name="purchase_id" value="<?= (int)$row['id'] ?>">
                  <input type="text" name="note" class="form-control form-control-sm" placeholder="Not (opsiyonel)">
                  <button class="btn btn-sm btn-outline-primary" type="submit">Ödeme Yap</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="finance-section p-4 mb-4">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
    <div>
      <h5>Son Satışlar</h5>
      <small class="text-muted">Site üzerinden tamamlanan siparişler.</small>
    </div>
    <div class="text-muted small">Son 30 günde <?=number_format($orders['last_30_count'] ?? 0, 0, ',', '.')?> satış • Toplam <?=number_format($orders['total_count'] ?? 0, 0, ',', '.')?> proje</div>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead><tr><th>#</th><th>Paket</th><th>Bayi</th><th>Tutar</th><th>Müşteri</th><th>Ödeme</th></tr></thead>
      <tbody>
        <?php if (!$recentOrders): ?>
          <tr><td colspan="6" class="text-center text-muted">Henüz tamamlanan bir satış bulunmuyor.</td></tr>
        <?php else: ?>
          <?php foreach ($recentOrders as $row): ?>
            <tr>
              <td>#<?= (int)$row['id'] ?></td>
              <td>
                <div class="fw-semibold"><?=h($row['package_name'] ?? '—')?></div>
                <?php if (!empty($row['event_title'])): ?><div class="small text-muted"><?=h($row['event_title'])?></div><?php endif; ?>
              </td>
              <td>
                <div><?=h($row['dealer_name'] ?? 'Doğrudan Satış')?></div>
                <?php if (!empty($row['dealer_code'])): ?><div class="small text-muted">Kod: <?=h($row['dealer_code'])?></div><?php endif; ?>
              </td>
              <td><?=format_currency($row['price_cents'] ?? 0)?></td>
              <td>
                <div><?=h($row['customer_name'] ?? '—')?></div>
                <?php if (!empty($row['customer_email'])): ?><div class="small text-muted"><?=h($row['customer_email'])?></div><?php endif; ?>
              </td>
              <td><?= $row['paid_at'] ? h(date('d.m.Y H:i', strtotime($row['paid_at']))) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="finance-section p-4">
  <h5 class="fw-semibold mb-3">Aylık Finansal Trend</h5>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead><tr><th>Ay</th><th>Topup</th><th>Satış</th><th>Gelir</th><th>Komisyon</th><th>Cashback</th><th>Ödeme</th><th>Net</th></tr></thead>
      <tbody>
        <?php if (!$monthlySummary): ?>
          <tr><td colspan="8" class="text-center text-muted">Yeterli veri bulunmuyor.</td></tr>
        <?php else: ?>
          <?php foreach ($monthlySummary as $row): ?>
            <?php $netClass = ($row['net'] ?? 0) >= 0 ? 'text-success' : 'text-danger'; ?>
            <tr>
              <td class="fw-semibold"><?=h($row['label'] ?? $row['month'])?></td>
              <td><?=format_currency($row['topup_amount'] ?? 0)?> <div class="small text-muted"><?= (int)($row['topup_count'] ?? 0) ?> talep</div></td>
              <td><?=format_currency($row['order_amount'] ?? 0)?> <div class="small text-muted"><?= (int)($row['order_count'] ?? 0) ?> satış</div></td>
              <td class="fw-semibold"><?=format_currency($row['revenue_total'] ?? 0)?></td>
              <td><?=format_currency($row['commission_amount'] ?? 0)?> <div class="small text-muted"><?= (int)($row['commission_count'] ?? 0) ?> ödeme</div></td>
              <td><?=format_currency($row['cashback_amount'] ?? 0)?> <div class="small text-muted"><?= (int)($row['cashback_count'] ?? 0) ?> ödeme</div></td>
              <td><?=format_currency($row['payout_total'] ?? 0)?></td>
              <td class="fw-semibold <?=$netClass?>"><?=format_currency($row['net'] ?? 0)?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php admin_layout_end(); ?>
</body>
</html>
