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

$action = $_POST['do'] ?? '';
if ($action === 'request_payout') {
  csrf_or_die();
  try {
    $invoicePath = representative_process_invoice_upload($_FILES['invoice'] ?? null, true);
    $note = trim($_POST['note'] ?? '');
    representative_payout_request_create($representativeId, $invoicePath, $note !== '' ? $note : null);
    flash('ok', 'Ödeme talebiniz alındı. Talepler finans ekibi tarafından incelenecektir.');
  } catch (Throwable $e) {
    flash('err', $e->getMessage());
  }
  redirect('commissions.php');
}

$totalsAll = representative_commission_totals($representativeId);
$totalsDealer = $selectedDealerId ? representative_commission_totals($representativeId, $selectedDealerId) : $totalsAll;

$recentSales = representative_recent_sales($representativeId, 8, $selectedDealerId ?: null);
$recentCommissions = representative_recent_commissions($representativeId, 10, $selectedDealerId ?: null);

$availableAmount = (int)($totalsAll['available_amount'] ?? 0);
$availableCount = (int)($totalsAll['available_count'] ?? 0);
$nextReleaseAt = $totalsAll['next_release_at'] ?? null;
$payoutRequests = representative_payout_requests($representativeId, 6);
$taxDocumentUrl = rtrim(BASE_URL, '/').'/public/docs/vergi-levhasi.pdf';

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
    <span>Çekilebilir</span>
    <strong><?=h(format_currency($availableAmount))?></strong>
    <small><?=h($availableCount)?> komisyon çekime hazır<?= $nextReleaseAt ? '. Sonraki tarih: '.h(date('d.m.Y', strtotime($nextReleaseAt))) : '' ?>.</small>
  </div>
  <div class="summary-card">
    <span>Bekleyen</span>
    <strong><?=h(format_currency($totalsDealer['pending_amount'] ?? 0))?></strong>
    <small><?= $selectedDealerId ? 'Seçili bayi için bekleyen komisyon.' : 'Tüm bayiler için bekleyen komisyon toplamı.' ?></small>
  </div>
  <div class="summary-card">
    <span>Onaylanan</span>
    <strong><?=h(format_currency($totalsDealer['approved_amount'] ?? 0))?></strong>
    <small><?= $selectedDealerId ? 'Seçili bayi için onaylanan ödemeler.' : 'Ödeme planına alınan komisyonlar.' ?></small>
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
    <h5>Ödeme Talebi</h5>
    <small>30 günlük bekleme süresini tamamlayan komisyonlar için fatura yükleyerek ödeme talep edin.</small>
  </div>
  <div class="row g-4 align-items-start">
    <div class="col-lg-6">
      <p class="mb-3">Komisyonlar paket satışı tamamlandıktan sonra 30 gün içinde itiraz süreci için bekletilir. Süre tamamlandığında ve müşteriniz hizmeti kullandıysa tutarı çekebilirsiniz.</p>
      <ul class="list-unstyled mb-3">
        <li class="mb-2"><strong><?=h(format_currency($availableAmount))?></strong> çekilebilir tutar.</li>
        <li class="mb-2"><strong><?=h($availableCount)?></strong> komisyon talebe dahil edilebilir.</li>
        <li class="mb-2"><?= $nextReleaseAt ? 'Sonraki komisyon serbest kalma tarihi: <strong>'.h(date('d.m.Y', strtotime($nextReleaseAt))).'</strong>' : 'Tüm uygun komisyonlar talep edilebilir durumda.' ?></li>
      </ul>
      <p class="small text-muted">Ödeme talebi sırasında kesilen faturayı yüklemeniz ve şirketimizin vergi levhasına göre düzenlemeniz gerekir.</p>
      <a class="btn btn-outline-secondary btn-sm" target="_blank" href="<?=h($taxDocumentUrl)?>">Vergi levhasını indir</a>
    </div>
    <div class="col-lg-6">
      <form method="post" enctype="multipart/form-data" class="row g-3">
        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="do" value="request_payout">
        <div class="col-12">
          <label class="form-label">Fatura<span class="text-danger">*</span></label>
          <input type="file" name="invoice" accept=".pdf,.jpg,.jpeg,.png" class="form-control" required <?= $availableCount ? '' : 'disabled' ?>>
          <div class="form-text">PDF veya görsel yükleyebilirsiniz.</div>
        </div>
        <div class="col-12">
          <label class="form-label">Not (opsiyonel)</label>
          <textarea name="note" class="form-control" rows="3" placeholder="Örn. ödeme için banka bilgileri" <?= $availableCount ? '' : 'disabled' ?>></textarea>
        </div>
        <div class="col-12">
          <button type="submit" class="btn btn-primary w-100" <?= $availableCount ? '' : 'disabled' ?>><?= $availableCount ? 'Ödeme talebi oluştur' : 'Çekilebilir komisyon yok' ?></button>
        </div>
      </form>
    </div>
  </div>
</section>
<section class="card-lite mb-4">
  <div class="section-heading">
    <h5>Son Satışlar</h5>
    <small><?= $selectedDealerId ? 'Seçili bayi için' : 'Tüm bayiler için' ?> tamamlanan en son paket veya web satışları</small>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead><tr><th>Satış</th><th>Bayi</th><th>Tutar</th><th>Komisyon</th><th>Durum</th><th>Tarih</th></tr></thead>
      <tbody>
        <?php if (!$recentSales): ?>
          <tr><td colspan="6" class="text-center text-muted">Listelenecek satış bulunmuyor.</td></tr>
        <?php else: ?>
          <?php foreach ($recentSales as $sale): ?>
            <?php
              $status = $sale['commission_status'] ?? REPRESENTATIVE_COMMISSION_STATUS_PENDING;
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
              $dealerName = isset($sale['dealer_id']) && isset($dealerLookup[$sale['dealer_id']]) ? $dealerLookup[$sale['dealer_id']]['name'] : '—';
              $reference = $sale['site_order_id'] ? 'Sipariş #'.$sale['site_order_id'] : ($sale['package_purchase_id'] ? 'Paket #'.$sale['package_purchase_id'] : '#'.$sale['commission_id']);
              $title = $sale['package_name'] ?? ($sale['source_label'] ?? $reference);
              $saleAmount = $sale['amount_cents'] ?? 0;
              $saleDate = $sale['sale_at'] ?? $sale['created_at'] ?? null;
            ?>
            <tr>
              <td>
                <div class="fw-semibold mb-1"><?=h($title)?></div>
                <div class="small text-muted"><?=h($reference)?></div>
                <?php if (!empty($sale['customer_name'])): ?><div class="small text-muted">Müşteri: <?=h($sale['customer_name'])?></div><?php endif; ?>
              </td>
              <td><?=h($dealerName)?></td>
              <td><?=h(format_currency($saleAmount))?></td>
              <td><?=h(format_currency($sale['commission_cents'] ?? 0))?></td>
              <td><span class="<?=$pillClass?>"><?=h($label)?></span></td>
              <td><?= $saleDate ? h(date('d.m.Y H:i', strtotime($saleDate))) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="card-lite mb-4">
  <div class="section-heading">
    <h5>Ödeme Taleplerim</h5>
    <small>Son talep edilen ödemeler ve inceleme durumları</small>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead><tr><th>Tarih</th><th>Tutar</th><th>Komisyon</th><th>Durum</th><th>Fatura</th><th>Yanıt</th></tr></thead>
      <tbody>
        <?php if (!$payoutRequests): ?>
          <tr><td colspan="6" class="text-center text-muted">Henüz ödeme talebiniz bulunmuyor.</td></tr>
        <?php else: ?>
          <?php foreach ($payoutRequests as $request): ?>
            <?php
              $status = $request['status'] ?? REPRESENTATIVE_PAYOUT_STATUS_PENDING;
              $label = representative_payout_status_label($status);
              $badgeClass = 'status-pill pending';
              if ($status === REPRESENTATIVE_PAYOUT_STATUS_APPROVED) {
                $badgeClass = 'status-pill approved';
              } elseif ($status === REPRESENTATIVE_PAYOUT_STATUS_PAID) {
                $badgeClass = 'status-pill paid';
              } elseif ($status === REPRESENTATIVE_PAYOUT_STATUS_REJECTED) {
                $badgeClass = 'status-pill negative';
              }
              $invoiceUrl = $request['invoice_url'] ?? representative_payout_invoice_url($request['invoice_path'] ?? null);
            ?>
            <tr>
              <td><?= $request['requested_at'] ? h(date('d.m.Y H:i', strtotime($request['requested_at']))) : '—' ?></td>
              <td><?=h(format_currency($request['amount_cents'] ?? 0))?></td>
              <td><?=h((int)($request['commission_count'] ?? 0))?></td>
              <td><span class="<?=$badgeClass?>"><?=h($label)?></span></td>
              <td><?php if ($invoiceUrl): ?><a class="text-decoration-none" target="_blank" href="<?=h($invoiceUrl)?>">Faturayı görüntüle</a><?php else: ?>—<?php endif; ?></td>
              <td>
                <?php if (!empty($request['response_note'])): ?>
                  <div class="small text-muted"><?=h($request['response_note'])?></div>
                <?php endif; ?>
                <?php if (!empty($request['reviewed_at'])): ?>
                  <div class="small text-muted">Güncelleme: <?=h(date('d.m.Y H:i', strtotime($request['reviewed_at'])))?></div>
                <?php endif; ?>
              </td>
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
      <thead><tr><th>Satış</th><th>Bayi</th><th>Komisyon</th><th>Durum</th><th>Oluşturulma</th><th>Ödeme</th></tr></thead>
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
              $reference = $row['site_order_id'] ? 'Sipariş #'.$row['site_order_id'] : ($row['package_purchase_id'] ? 'Paket #'.$row['package_purchase_id'] : '#'.$row['id']);
              $title = $row['package_name'] ?? ($row['source_label'] ?? $reference);
            ?>
            <tr>
              <td>
                <div class="fw-semibold mb-1"><?=h($title)?></div>
                <div class="small text-muted"><?=h($reference)?></div>
              </td>
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
