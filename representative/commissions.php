<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/representatives.php';
require_once __DIR__.'/../includes/representative_crm.php';
require_once __DIR__.'/../includes/representative_auth.php';
require_once __DIR__.'/partials/ui.php';

install_schema();

representative_require_login();
$user = representative_user();
$representative = representative_get((int)$user['id']);
if (!$representative) {
  representative_logout();
  redirect('login.php');
}

$representativeId = (int)$representative['id'];
$assignedDealers = $representative['dealers'] ?? [];
$dealerLookup = [];
foreach ($assignedDealers as $dealer) {
  $dealerLookup[(int)$dealer['id']] = $dealer;
}

$selectedDealerId = isset($_GET['dealer_id']) ? (int)$_GET['dealer_id'] : 0;
if ($selectedDealerId && !isset($dealerLookup[$selectedDealerId])) {
  $selectedDealerId = 0;
}

$totalsAll = representative_commission_totals($representativeId);
$totalsDealer = $selectedDealerId ? representative_commission_totals($representativeId, $selectedDealerId) : $totalsAll;

$recentTopups = representative_completed_topups($representativeId, 8, $selectedDealerId ?: null);
$recentCommissions = representative_recent_commissions($representativeId, 10, $selectedDealerId ?: null);

$pageStyles = <<<'CSS'
<style>
  .summary-grid {display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.2rem;margin-bottom:1.8rem;}
  .summary-card {border-radius:18px;padding:1.4rem;background:linear-gradient(135deg,rgba(14,165,181,.14),rgba(255,255,255,.95));border:1px solid rgba(148,163,184,.22);box-shadow:0 24px 56px -38px rgba(14,116,144,.44);display:flex;flex-direction:column;gap:.6rem;}
  .summary-card span {font-size:.78rem;text-transform:uppercase;letter-spacing:.12em;color:var(--rep-muted);font-weight:600;}
  .summary-card strong {font-size:1.9rem;font-weight:700;color:var(--rep-ink);}
  .summary-card small {font-size:.82rem;color:#475569;}
  .card-lite {border-radius:20px;background:var(--rep-surface);border:1px solid rgba(148,163,184,.18);box-shadow:0 26px 60px -42px rgba(15,23,42,.4);padding:1.9rem;}
  .card-lite + .card-lite {margin-top:1.5rem;}
  .section-heading {display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:1.2rem;flex-wrap:wrap;}
  .section-heading h5 {margin:0;font-weight:700;color:var(--rep-ink);}
  .section-heading small {color:var(--rep-muted);font-weight:500;}
  .dealer-selector {display:flex;gap:.75rem;flex-wrap:wrap;}
  .dealer-selector a {padding:.45rem .9rem;border-radius:999px;border:1px solid rgba(148,163,184,.3);font-size:.85rem;font-weight:600;color:#475569;text-decoration:none;}
  .dealer-selector a.active {background:var(--rep-brand);color:#fff;border-color:transparent;}
  .status-pill {display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .75rem;border-radius:999px;font-size:.78rem;font-weight:600;}
  .status-pill.paid {background:rgba(34,197,94,.18);color:#166534;}
  .status-pill.pending {background:rgba(250,204,21,.25);color:#854d0e;}
  .status-pill.approved {background:rgba(45,212,191,.22);color:#0f766e;}
  .status-pill.default {background:rgba(148,163,184,.22);color:#475569;}
  .status-pill.negative {background:rgba(248,113,113,.24);color:#b91c1c;}
  .dealers-table td:first-child {font-weight:600;color:var(--rep-ink);}
  @media (max-width: 768px) {
    .summary-card {padding:1.2rem;}
    .card-lite {padding:1.6rem;}
  }
</style>
CSS;

representative_layout_start([
  'page_title' => APP_NAME.' — Komisyonlar',
  'header_title' => 'Komisyon Yönetimi',
  'header_subtitle' => 'Bayi bazlı komisyon oranlarını ve ödemeleri takip edin.',
  'representative' => $representative,
  'active_nav' => 'commissions',
  'extra_head' => $pageStyles,
]);
?>

<?=flash_messages()?>

<div class="summary-grid">
  <div class="summary-card">
    <span>Toplam Komisyon</span>
    <strong><?=h(format_currency($totalsAll['total_amount']))?></strong>
    <small><?=h((int)($totalsAll['total_count'] ?? 0))?> işlem kaydedildi.</small>
  </div>
  <div class="summary-card">
    <span>Bekleyen</span>
    <strong><?=h(format_currency($totalsDealer['pending_amount'] ?? 0))?></strong>
    <small><?= $selectedDealerId ? 'Seçili bayi için bekleyen komisyon.' : 'Tüm bayiler için bekleyen komisyon toplamı.' ?></small>
  </div>
  <div class="summary-card">
    <span>Onaylanan</span>
    <strong><?=h(format_currency($totalsDealer['approved_amount'] ?? 0))?></strong>
    <small><?= $selectedDealerId ? 'Seçili bayi için onaylı ödemeler.' : 'Tüm bayiler için onaylanmış komisyonlar.' ?></small>
  </div>
  <div class="summary-card">
    <span>Ödenen</span>
    <strong><?=h(format_currency($totalsDealer['paid_amount'] ?? 0))?></strong>
    <small><?= $selectedDealerId ? 'Seçili bayi için tamamlanan ödemeler.' : 'Tüm bayiler için ödenen komisyon toplamı.' ?></small>
  </div>
</div>

<section class="card-lite mb-4">
  <div class="section-heading">
    <h5>Bayi Filtreleri</h5>
    <small><?= count($assignedDealers) ?> bayi</small>
  </div>
  <div class="dealer-selector">
    <a href="commissions.php" class="<?= $selectedDealerId === 0 ? 'active' : '' ?>">Tümü</a>
    <?php foreach ($assignedDealers as $dealer): ?>
      <?php $dealerId = (int)$dealer['id']; ?>
      <a class="<?= $selectedDealerId === $dealerId ? 'active' : '' ?>" href="commissions.php?dealer_id=<?=$dealerId?>"><?=h($dealer['name'])?></a>
    <?php endforeach; ?>
  </div>
</section>

<section class="card-lite mb-4">
  <div class="section-heading">
    <h5>Atandığım Bayiler</h5>
    <small>Komisyon oranları ve atama bilgileri</small>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle dealers-table mb-0">
      <thead><tr><th>Bayi</th><th>Komisyon (%)</th><th>Atama Tarihi</th></tr></thead>
      <tbody>
        <?php if (!$assignedDealers): ?>
          <tr><td colspan="3" class="text-center text-muted">Henüz size atanmış bir bayi bulunmuyor.</td></tr>
        <?php else: ?>
          <?php foreach ($assignedDealers as $dealer): ?>
            <tr>
              <td><?=h($dealer['name'])?></td>
              <td>%<?=h(number_format((float)($dealer['commission_rate'] ?? $representative['commission_rate']), 1))?></td>
              <td><?= !empty($dealer['assigned_at']) ? h(date('d.m.Y H:i', strtotime($dealer['assigned_at']))) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="card-lite mb-4">
  <div class="section-heading">
    <h5>Son Yüklemeler</h5>
    <small><?= $selectedDealerId ? 'Seçili bayi için' : 'Tüm bayiler için' ?> en son işlemler</small>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead><tr><th>#</th><th>Bayi</th><th>Yükleme</th><th>Komisyon</th><th>Durum</th><th>Tarih</th></tr></thead>
      <tbody>
        <?php if (!$recentTopups): ?>
          <tr><td colspan="6" class="text-center text-muted">Listelenecek yükleme bulunmuyor.</td></tr>
        <?php else: ?>
          <?php foreach ($recentTopups as $topup): ?>
            <?php
              $status = $topup['commission_status'] ?? REPRESENTATIVE_COMMISSION_STATUS_PENDING;
              $label = representative_commission_status_label($status);
              $pillClass = 'status-pill default';
              if ($status === REPRESENTATIVE_COMMISSION_STATUS_PENDING) {
                $pillClass = 'status-pill pending';
              } elseif ($status === REPRESENTATIVE_COMMISSION_STATUS_APPROVED) {
                $pillClass = 'status-pill approved';
              } elseif ($status === REPRESENTATIVE_COMMISSION_STATUS_PAID) {
                $pillClass = 'status-pill paid';
              } elseif ($status === REPRESENTATIVE_COMMISSION_STATUS_REJECTED) {
                $pillClass = 'status-pill negative';
              }
              $dealerName = isset($topup['dealer_id']) && isset($dealerLookup[$topup['dealer_id']]) ? $dealerLookup[$topup['dealer_id']]['name'] : '—';
            ?>
            <tr>
              <td>#<?=h($topup['id'])?></td>
              <td><?=h($dealerName)?></td>
              <td><?=h(format_currency($topup['amount_cents']))?></td>
              <td><?= $topup['commission_cents'] !== null ? h(format_currency($topup['commission_cents'])) : '—' ?></td>
              <td><span class="<?=$pillClass?>"><?=h($label)?></span></td>
              <td><?= $topup['completed_at'] ? h(date('d.m.Y H:i', strtotime($topup['completed_at']))) : h(date('d.m.Y H:i', strtotime($topup['created_at'] ?? 'now'))) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="card-lite">
  <div class="section-heading">
    <h5>Komisyon Hareketleri</h5>
    <small>İşlem bazlı komisyon özetleri</small>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead><tr><th>#</th><th>Bayi</th><th>Komisyon</th><th>Durum</th><th>Oluşturulma</th><th>Ödeme</th></tr></thead>
      <tbody>
        <?php if (!$recentCommissions): ?>
          <tr><td colspan="6" class="text-center text-muted">Komisyon kaydı bulunmuyor.</td></tr>
        <?php else: ?>
          <?php foreach ($recentCommissions as $row): ?>
            <?php
              $status = $row['status'] ?? REPRESENTATIVE_COMMISSION_STATUS_PENDING;
              $label = representative_commission_status_label($status);
              $pillClass = 'status-pill default';
              if ($status === REPRESENTATIVE_COMMISSION_STATUS_PENDING) {
                $pillClass = 'status-pill pending';
              } elseif ($status === REPRESENTATIVE_COMMISSION_STATUS_APPROVED) {
                $pillClass = 'status-pill approved';
              } elseif ($status === REPRESENTATIVE_COMMISSION_STATUS_PAID) {
                $pillClass = 'status-pill paid';
              } elseif ($status === REPRESENTATIVE_COMMISSION_STATUS_REJECTED) {
                $pillClass = 'status-pill negative';
              }
              $dealerName = isset($row['dealer_id']) && isset($dealerLookup[$row['dealer_id']]) ? $dealerLookup[$row['dealer_id']]['name'] : '—';
            ?>
            <tr>
              <td>#<?=h($row['dealer_topup_id'])?></td>
              <td><?=h($dealerName)?></td>
              <td><?=h(format_currency($row['commission_cents']))?></td>
              <td><span class="<?=$pillClass?>"><?=h($label)?></span></td>
              <td><?= $row['created_at'] ? h(date('d.m.Y H:i', strtotime($row['created_at']))) : '—' ?></td>
              <td><?= $row['paid_at'] ? h(date('d.m.Y H:i', strtotime($row['paid_at']))) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php representative_layout_end();
