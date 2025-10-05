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
  $noteProvided = array_key_exists('note', $_POST);
  $note = $noteProvided ? $_POST['note'] : null;
  if ($commissionId <= 0) {
    flash('err', 'Komisyon kaydı bulunamadı.');
    redirect($_SERVER['PHP_SELF'].'#approvals');
  }
  try {
    $options = $noteProvided ? ['note' => $note] : [];
    representative_commission_update_status($commissionId, $status, $options);
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

$queueCommissionAmount = (int)($commissions['pending_amount'] ?? 0) + (int)($commissions['approved_amount'] ?? 0);
$queueCommissionCount = (int)($commissions['pending_count'] ?? 0) + (int)($commissions['approved_count'] ?? 0);
$pendingPayoutAmount = $pendingTopupAmount + $queueCommissionAmount;
$pendingPayoutCount = $pendingTopupCount + $queueCommissionCount;

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
  .finance-summary .card {
    border: 0;
    border-radius: 18px;
    box-shadow: 0 18px 40px -28px rgba(15, 23, 42, .35);
    background: linear-gradient(135deg, rgba(14,165,181,.12), #ffffff);
  }
  .finance-summary .card-title {
    text-transform: uppercase;
    font-weight: 600;
    font-size: .75rem;
    letter-spacing: .1em;
    color: var(--admin-muted);
  }
  .finance-summary .value {
    font-size: 1.85rem;
    font-weight: 700;
    color: var(--admin-ink);
  }
  .finance-summary .meta {
    color: var(--admin-muted);
    font-size: .85rem;
  }
  .finance-card {
    border: 1px solid rgba(15,23,42,.08);
    border-radius: 18px;
    box-shadow: 0 16px 42px -30px rgba(15,23,42,.35);
  }
  .finance-card .card-header {
    background: #f8fafc;
    border-bottom: 1px solid rgba(15,23,42,.08);
  }
  .status-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    font-size: .75rem;
    font-weight: 600;
    padding: .3rem .7rem;
  }
  .status-badge.pending { background: #fef3c7; color: #92400e; }
  .status-badge.review { background: #dbeafe; color: #1d4ed8; }
  .status-badge.approved { background: #ccfbf1; color: #0f766e; }
  .status-badge.paid { background: #dcfce7; color: #166534; }
  .status-badge.rejected { background: #fee2e2; color: #b91c1c; }
  .action-stack {
    display: flex;
    flex-direction: column;
    gap: .6rem;
    align-items: flex-end;
  }
  .action-form {
    display: flex;
    flex-wrap: wrap;
    gap: .5rem;
    justify-content: flex-end;
  }
  .action-form .form-control {
    width: 160px;
  }
  .quick-stats li + li {
    border-top: 1px solid rgba(15,23,42,.08);
  }
  @media (max-width: 767px) {
    .action-stack { align-items: stretch; }
    .action-form { justify-content: stretch; }
    .action-form .form-control { width: 100%; }
  }
</style>
</head>
<body class="admin-body">
<?php admin_layout_start('finance', $title, $subtitle, 'bi-wallet2'); ?>
<?=flash_messages()?>

<div class="container-xxl py-4">
  <div class="row g-3 finance-summary">
    <div class="col-sm-6 col-xl-3">
      <div class="card h-100">
        <div class="card-body">
          <div class="card-title mb-1">Toplam Gelir</div>
          <div class="value mb-1"><?=format_currency($totals['revenue_total'] ?? 0)?></div>
          <div class="meta">Bayi yüklemeleri ve satışlardan elde edilen toplam tahsilat.</div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="card h-100">
        <div class="card-body">
          <div class="card-title mb-1">Son 30 Gün Geliri</div>
          <div class="value mb-1"><?=format_currency($totals['revenue_last_30'] ?? 0)?></div>
          <div class="meta">Son 30 günde tamamlanan yükleme ve satışların toplamı.</div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="card h-100">
        <div class="card-body">
          <div class="card-title mb-1">Bekleyen Ödemeler</div>
          <div class="value mb-1"><?=format_currency($pendingPayoutAmount)?></div>
          <div class="meta"><?=$pendingPayoutCount?> kayıt inceleme veya ödeme bekliyor.</div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="card h-100">
        <div class="card-body">
          <div class="card-title mb-1">Son 30 Gün Net</div>
          <div class="value mb-1 <?= ($totals['net_last_30'] ?? 0) >= 0 ? 'text-success' : 'text-danger' ?>"><?=format_currency($totals['net_last_30'] ?? 0)?></div>
          <div class="meta">Gelirlerden ödemeler düşüldükten sonraki net kazanç.</div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-4 mt-1">
    <div class="col-lg-6">
      <div id="approvals" class="card finance-card h-100">
        <div class="card-header d-flex justify-content-between align-items-start">
          <div>
            <h5 class="mb-0">Bayi Yükleme Onayı</h5>
            <small class="text-muted"><?=$pendingTopupCount?> talep beklemede.</small>
          </div>
          <span class="badge bg-light text-muted"><?=format_currency($pendingTopupAmount)?></span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead class="table-light">
                <tr><th scope="col">Bayi</th><th scope="col">Tutar</th><th scope="col">Durum</th><th scope="col">Talep</th><th scope="col" class="text-end">İşlem</th></tr>
              </thead>
              <tbody>
                <?php if (!$pendingTopups): ?>
                  <tr><td colspan="5" class="text-center text-muted py-4">Onay bekleyen yükleme bulunmuyor.</td></tr>
                <?php else: ?>
                  <?php foreach ($pendingTopups as $row): ?>
                    <?php
                      $status = $row['status'] ?? DEALER_TOPUP_STATUS_PENDING;
                      $label = dealer_topup_status_label($status);
                      $chipClass = 'status-badge pending';
                      if ($status === DEALER_TOPUP_STATUS_AWAITING_REVIEW) {
                        $chipClass = 'status-badge review';
                      }
                    ?>
                    <tr>
                      <td>
                        <div class="fw-semibold mb-1"><?=h($row['dealer_name'] ?? '—')?></div>
                        <?php if (!empty($row['dealer_code'])): ?><div class="small text-muted">Kod: <?=h($row['dealer_code'])?></div><?php endif; ?>
                      </td>
                      <td class="fw-semibold"><?=format_currency($row['amount_cents'] ?? 0)?></td>
                      <td><span class="<?=$chipClass?>"><?=h($label)?></span></td>
                      <td><?= $row['created_at'] ? h(date('d.m.Y H:i', strtotime($row['created_at']))) : '—' ?></td>
                      <td class="text-end">
                        <div class="action-stack">
                          <form method="post" class="action-form">
                            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                            <input type="hidden" name="do" value="topup_complete">
                            <input type="hidden" name="topup_id" value="<?= (int)$row['id'] ?>">
                            <input type="text" name="reference" class="form-control form-control-sm" placeholder="Referans" value="<?=h($row['paytr_reference'] ?? '')?>">
                            <button class="btn btn-sm btn-primary" type="submit">Onayla</button>
                          </form>
                          <form method="post">
                            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                            <input type="hidden" name="do" value="topup_cancel">
                            <input type="hidden" name="topup_id" value="<?= (int)$row['id'] ?>">
                            <button class="btn btn-sm btn-outline-secondary w-100" type="submit">İptal</button>
                          </form>
                        </div>
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
    <div class="col-lg-6">
      <div class="card finance-card h-100">
        <div class="card-header d-flex justify-content-between align-items-start">
          <div>
            <h5 class="mb-0">Temsilci Komisyonları</h5>
            <small class="text-muted"><?=$queueCommissionCount?> kayıt süreçte.</small>
          </div>
          <span class="badge bg-light text-muted"><?=format_currency($queueCommissionAmount)?></span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead class="table-light">
                <tr><th scope="col">Temsilci</th><th scope="col">Bayi</th><th scope="col">Komisyon</th><th scope="col">Durum</th><th scope="col" class="text-end">İşlem</th></tr>
              </thead>
              <tbody>
                <?php if (!$pendingCommissions): ?>
                  <tr><td colspan="5" class="text-center text-muted py-4">İşlem gerektiren komisyon bulunmuyor.</td></tr>
                <?php else: ?>
                  <?php foreach ($pendingCommissions as $row): ?>
                    <?php
                      $status = $row['status'] ?? REPRESENTATIVE_COMMISSION_STATUS_PENDING;
                      $chipClass = 'status-badge pending';
                      if ($status === REPRESENTATIVE_COMMISSION_STATUS_APPROVED) {
                        $chipClass = 'status-badge approved';
                      } elseif ($status === REPRESENTATIVE_COMMISSION_STATUS_PAID) {
                        $chipClass = 'status-badge paid';
                      } elseif ($status === REPRESENTATIVE_COMMISSION_STATUS_REJECTED) {
                        $chipClass = 'status-badge rejected';
                      }
                      $isPending = $status === REPRESENTATIVE_COMMISSION_STATUS_PENDING;
                      $isApproved = $status === REPRESENTATIVE_COMMISSION_STATUS_APPROVED;
                      $isPaid = $status === REPRESENTATIVE_COMMISSION_STATUS_PAID;
                      $isRejected = $status === REPRESENTATIVE_COMMISSION_STATUS_REJECTED;
                    ?>
                    <tr>
                      <td>
                        <div class="fw-semibold mb-1"><?=h($row['representative_name'] ?? '—')?></div>
                        <?php if (!empty($row['representative_email'])): ?><div class="small text-muted"><?=h($row['representative_email'])?></div><?php endif; ?>
                      </td>
                      <td>
                        <div><?=h($row['dealer_name'] ?? '—')?></div>
                        <?php if (!empty($row['dealer_code'])): ?><div class="small text-muted">Kod: <?=h($row['dealer_code'])?></div><?php endif; ?>
                      </td>
                      <td>
                        <div class="fw-semibold text-success mb-1"><?=format_currency($row['commission_cents'] ?? 0)?></div>
                        <div class="small text-muted">Yükleme: <?=format_currency($row['topup_amount_cents'] ?? 0)?></div>
                      </td>
                      <td>
                        <span class="<?=$chipClass?>"><?=h(representative_commission_status_label($status))?></span>
                        <div class="small text-muted">Oluşturma: <?= $row['created_at'] ? h(date('d.m.Y H:i', strtotime($row['created_at']))) : '—' ?></div>
                      </td>
                      <td class="text-end">
                        <div class="action-stack">
                          <form method="post" class="action-form">
                            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                            <input type="hidden" name="do" value="commission_update">
                            <input type="hidden" name="commission_id" value="<?= (int)$row['id'] ?>">
                            <div class="btn-group btn-group-sm" role="group">
                              <?php if ($isPending): ?>
                                <button class="btn btn-outline-success" name="status" value="<?=REPRESENTATIVE_COMMISSION_STATUS_APPROVED?>" type="submit">Onayla</button>
                                <button class="btn btn-outline-danger" name="status" value="<?=REPRESENTATIVE_COMMISSION_STATUS_REJECTED?>" type="submit">Reddet</button>
                              <?php elseif ($isApproved): ?>
                                <button class="btn btn-outline-primary" name="status" value="<?=REPRESENTATIVE_COMMISSION_STATUS_PAID?>" type="submit">Ödendi</button>
                                <button class="btn btn-outline-secondary" name="status" value="<?=REPRESENTATIVE_COMMISSION_STATUS_PENDING?>" type="submit">İnceleme</button>
                              <?php elseif ($isPaid): ?>
                                <button class="btn btn-outline-primary" name="status" value="<?=REPRESENTATIVE_COMMISSION_STATUS_APPROVED?>" type="submit">Onaya Al</button>
                              <?php elseif ($isRejected): ?>
                                <button class="btn btn-outline-secondary" name="status" value="<?=REPRESENTATIVE_COMMISSION_STATUS_PENDING?>" type="submit">Tekrar İncele</button>
                              <?php endif; ?>
                            </div>
                          </form>
                          <form method="post" class="action-form">
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
                            <button class="btn btn-sm btn-primary" type="submit">Kaydet</button>
                          </form>
                        </div>
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
  </div>

  <div class="row g-4 mt-1">
    <div class="col-lg-4">
      <div class="card finance-card h-100">
        <div class="card-header">
          <h5 class="mb-0">Hızlı Göstergeler</h5>
        </div>
        <div class="card-body">
          <ul class="list-unstyled mb-0 quick-stats">
            <li class="py-2 d-flex justify-content-between">
              <span>Ortalama Yükleme</span>
              <strong><?=format_currency($avgTopup)?></strong>
            </li>
            <li class="py-2 d-flex justify-content-between">
              <span>Ortalama Satış</span>
              <strong><?=format_currency($avgOrder)?></strong>
            </li>
            <li class="py-2 d-flex justify-content-between">
              <span>Ortalama Komisyon</span>
              <strong><?=format_currency($avgCommission)?></strong>
            </li>
            <li class="py-2 d-flex justify-content-between">
              <span>Ortalama Cashback</span>
              <strong><?=format_currency($avgCashback)?></strong>
            </li>
          </ul>
          <div class="small text-muted pt-3">Toplam proje: <?=number_format($projectsTotal, 0, ',', '.') ?> • Son 30 gün: <?=number_format($projectsLast30, 0, ',', '.')?></div>
        </div>
      </div>
    </div>
    <div class="col-lg-8">
      <div id="cashbacks" class="card finance-card h-100">
        <div class="card-header d-flex justify-content-between align-items-start">
          <div>
            <h5 class="mb-0">Cashback Ödemeleri</h5>
            <small class="text-muted"><?= (int)($cashbacks['pending_count'] ?? 0) ?> kayıt bekliyor.</small>
          </div>
          <span class="badge bg-light text-muted"><?=format_currency($cashbacks['pending_amount'] ?? 0)?></span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead class="table-light">
                <tr><th scope="col">Bayi</th><th scope="col">Paket</th><th scope="col">Tutar</th><th scope="col">Müşteri</th><th scope="col" class="text-end">İşlem</th></tr>
              </thead>
              <tbody>
                <?php if (!$pendingCashbacks): ?>
                  <tr><td colspan="5" class="text-center text-muted py-4">Bekleyen cashback kaydı bulunmuyor.</td></tr>
                <?php else: ?>
                  <?php foreach ($pendingCashbacks as $row): ?>
                    <tr>
                      <td>
                        <div class="fw-semibold mb-1"><?=h($row['dealer_name'] ?? '—')?></div>
                        <?php if (!empty($row['dealer_code'])): ?><div class="small text-muted">Kod: <?=h($row['dealer_code'])?></div><?php endif; ?>
                      </td>
                      <td>
                        <div><?=h($row['package_name'] ?? '—')?></div>
                        <div class="small text-muted">Talep: <?= $row['created_at'] ? h(date('d.m.Y', strtotime($row['created_at']))) : '—' ?></div>
                      </td>
                      <td class="fw-semibold"><?=format_currency($row['cashback_amount'] ?? 0)?></td>
                      <td>
                        <div><?=h($row['customer_name'] ?? '—')?></div>
                        <?php if (!empty($row['customer_email'])): ?><div class="small text-muted"><?=h($row['customer_email'])?></div><?php endif; ?>
                      </td>
                      <td class="text-end">
                        <form method="post" class="action-form">
                          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                          <input type="hidden" name="do" value="cashback_pay">
                          <input type="hidden" name="purchase_id" value="<?= (int)$row['id'] ?>">
                          <input type="text" name="note" class="form-control form-control-sm" placeholder="Not (opsiyonel)">
                          <button class="btn btn-sm btn-primary" type="submit">Ödeme Yap</button>
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
  </div>

  <div class="card finance-card mt-4">
    <div class="card-header d-flex justify-content-between align-items-start">
      <div>
        <h5 class="mb-0">Son Satışlar</h5>
        <small class="text-muted">Site üzerinden tamamlanan siparişler.</small>
      </div>
      <div class="small text-muted">Son 30 günde <?=number_format($orders['last_30_count'] ?? 0, 0, ',', '.')?> satış • Toplam <?=number_format($orders['total_count'] ?? 0, 0, ',', '.')?> proje</div>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead class="table-light">
            <tr><th scope="col">#</th><th scope="col">Paket</th><th scope="col">Bayi</th><th scope="col">Tutar</th><th scope="col">Müşteri</th><th scope="col">Ödeme</th></tr>
          </thead>
          <tbody>
            <?php if (!$recentOrders): ?>
              <tr><td colspan="6" class="text-center text-muted py-4">Henüz tamamlanan bir satış bulunmuyor.</td></tr>
            <?php else: ?>
              <?php foreach ($recentOrders as $row): ?>
                <tr>
                  <td>#<?= (int)$row['id'] ?></td>
                  <td>
                    <div class="fw-semibold mb-1"><?=h($row['package_name'] ?? '—')?></div>
                    <?php if (!empty($row['event_title'])): ?><div class="small text-muted"><?=h($row['event_title'])?></div><?php endif; ?>
                  </td>
                  <td>
                    <div><?=h($row['dealer_name'] ?? 'Doğrudan Satış')?></div>
                    <?php if (!empty($row['dealer_code'])): ?><div class="small text-muted">Kod: <?=h($row['dealer_code'])?></div><?php endif; ?>
                  </td>
                  <td class="fw-semibold"><?=format_currency($row['price_cents'] ?? 0)?></td>
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
    </div>
  </div>

  <div class="card finance-card mt-4">
    <div class="card-header">
      <h5 class="mb-0">Aylık Finansal Trend</h5>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead class="table-light">
            <tr><th scope="col">Ay</th><th scope="col">Topup</th><th scope="col">Satış</th><th scope="col">Gelir</th><th scope="col">Komisyon</th><th scope="col">Cashback</th><th scope="col">Ödeme</th><th scope="col">Net</th></tr>
          </thead>
          <tbody>
            <?php if (!$monthlySummary): ?>
              <tr><td colspan="8" class="text-center text-muted py-4">Yeterli veri bulunmuyor.</td></tr>
            <?php else: ?>
              <?php foreach ($monthlySummary as $row): ?>
                <?php $netClass = ($row['net'] ?? 0) >= 0 ? 'text-success' : 'text-danger'; ?>
                <tr>
                  <td><?=h($row['label'] ?? '—')?></td>
                  <td><?=format_currency($row['topup_amount'] ?? 0)?></td>
                  <td><?=format_currency($row['order_amount'] ?? 0)?></td>
                  <td><?=format_currency($row['revenue_total'] ?? 0)?></td>
                  <td><?=format_currency($row['commission_amount'] ?? 0)?></td>
                  <td><?=format_currency($row['cashback_amount'] ?? 0)?></td>
                  <td><?=format_currency($row['payout_total'] ?? 0)?></td>
                  <td class="<?=$netClass?>"><?=format_currency($row['net'] ?? 0)?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php admin_layout_end(); ?>
</body>
</html>
