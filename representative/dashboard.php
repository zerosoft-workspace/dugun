<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/dealers.php';
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
$dealerCount = count($assignedDealers);

$commissionTotals = representative_commission_totals($representativeId);
$recentTopups = representative_completed_topups($representativeId, 5);
$recentCommissions = representative_recent_commissions($representativeId, 5);

$statusOptions = representative_crm_status_options();
$statusCounts = representative_crm_status_counts($representativeId);
$totalLeads = $statusCounts['total'] ?? 0;
$wonCount = $statusCounts[REP_LEAD_STATUS_WON] ?? 0;
$lostCount = $statusCounts[REP_LEAD_STATUS_LOST] ?? 0;
$activePipeline = max(0, $totalLeads - $wonCount - $lostCount);
$upcomingActions = representative_crm_upcoming_actions($representativeId, 6);
$recentNotes = representative_crm_recent_notes($representativeId, 6);

$openTasksCount = count($upcomingActions);

$pageStyles = <<<'CSS'
<style>
  .summary-grid {display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.2rem;}
  .summary-card {border-radius:18px;padding:1.35rem 1.45rem;background:linear-gradient(140deg,rgba(14,165,181,.12),rgba(255,255,255,.94));border:1px solid rgba(148,163,184,.22);box-shadow:0 24px 58px -38px rgba(14,116,144,.48);display:flex;flex-direction:column;gap:.65rem;min-height:158px;}
  .summary-card span {font-size:.78rem;text-transform:uppercase;letter-spacing:.12em;font-weight:600;color:var(--rep-muted);}
  .summary-card strong {font-size:2rem;font-weight:700;color:var(--rep-ink);}
  .summary-card small {font-size:.85rem;color:#475569;}
  .card-lite {border-radius:20px;background:var(--rep-surface);border:1px solid rgba(148,163,184,.18);box-shadow:0 26px 60px -42px rgba(15,23,42,.4);padding:1.9rem;}
  .card-lite + .card-lite {margin-top:1.5rem;}
  .section-heading {display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:1.2rem;flex-wrap:wrap;}
  .section-heading h5 {margin:0;font-weight:700;color:var(--rep-ink);}
  .section-heading small {color:var(--rep-muted);font-weight:500;}
  .pipeline-row {display:flex;flex-wrap:wrap;gap:.65rem;}
  .pipeline-chip {display:flex;align-items:center;gap:.45rem;padding:.45rem .95rem;border-radius:999px;background:rgba(148,163,184,.18);color:#334155;font-weight:600;font-size:.85rem;}
  .pipeline-chip .count {font-size:1rem;color:var(--rep-ink);}
  .pipeline-chip--positive {background:rgba(34,197,94,.15);color:#166534;}
  .pipeline-chip--negative {background:rgba(248,113,113,.18);color:#b91c1c;}
  .pipeline-chip--active {background:rgba(14,165,181,.16);color:var(--rep-brand-dark);}
  .two-column {display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1.2rem;}
  .timeline-list {list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:1rem;}
  .timeline-item {display:flex;gap:1rem;align-items:flex-start;}
  .timeline-item .icon {width:42px;height:42px;border-radius:14px;background:rgba(14,165,181,.16);display:flex;align-items:center;justify-content:center;color:var(--rep-brand-dark);font-size:1.1rem;flex-shrink:0;}
  .timeline-item strong {display:block;font-weight:600;color:var(--rep-ink);}
  .timeline-item span {display:block;font-size:.85rem;color:#475569;}
  .timeline-item time {display:block;font-size:.75rem;color:#64748b;margin-top:.2rem;}
  .table-sm td, .table-sm th {padding:.6rem .75rem;}
  .dealers-table td:first-child {font-weight:600;color:var(--rep-ink);}
  .status-pill {display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .7rem;border-radius:999px;font-size:.75rem;font-weight:600;}
  .status-pill.pending {background:rgba(250,204,21,.22);color:#854d0e;}
  .status-pill.paid {background:rgba(34,197,94,.16);color:#166534;}
  .status-pill.approved {background:rgba(45,212,191,.22);color:#0f766e;}
  .status-pill.default {background:rgba(148,163,184,.22);color:#475569;}
  .status-pill.negative {background:rgba(248,113,113,.2);color:#b91c1c;}
  @media (max-width: 768px) {
    .summary-card {min-height:120px;}
    .card-lite {padding:1.6rem;}
  }
</style>
CSS;

representative_layout_start([
  'page_title' => APP_NAME.' — Temsilci Ana Sayfası',
  'header_title' => 'Güncel Durum',
  'header_subtitle' => 'Atandığınız bayiler, CRM takibi ve komisyon özetlerini buradan yönetin.',
  'representative' => $representative,
  'active_nav' => 'dashboard',
  'extra_head' => $pageStyles,
]);
?>

<?=flash_messages()?>

<div class="summary-grid mb-4">
  <div class="summary-card">
    <span>Atandığınız Bayi</span>
    <strong><?=h($dealerCount)?></strong>
    <small><?= $dealerCount ? 'Aktif olarak eşleştiğiniz bayi listesi.' : 'Henüz herhangi bir bayi ataması yapılmadı.' ?></small>
  </div>
  <div class="summary-card">
    <span>CRM Kaydı</span>
    <strong><?=h($totalLeads)?></strong>
    <small><?= $totalLeads ? 'Toplam potansiyel müşteri kaydınız.' : 'CRM üzerinde kayıtlı potansiyel müşteri bulunmuyor.' ?></small>
  </div>
  <div class="summary-card">
    <span>Açık Fırsatlar</span>
    <strong><?=h($activePipeline)?></strong>
    <small><?= $activePipeline ? 'Takipte olan potansiyel müşteriler.' : 'Tüm potansiyel müşteriler kapatıldı veya henüz eklenmedi.' ?></small>
  </div>
  <div class="summary-card">
    <span>Bekleyen Komisyon</span>
    <strong><?=h(format_currency($commissionTotals['pending_amount']))?></strong>
    <small><?=h((int)$commissionTotals['pending_count'])?> işlem ödeme bekliyor.</small>
  </div>
  <div class="summary-card">
    <span>Onaylanan Komisyon</span>
    <strong><?=h(format_currency($commissionTotals['approved_amount'] ?? 0))?></strong>
    <small><?=h((int)($commissionTotals['approved_count'] ?? 0))?> ödeme transfer için hazır.</small>
  </div>
  <div class="summary-card">
    <span>Ödenen Komisyon</span>
    <strong><?=h(format_currency($commissionTotals['paid_amount'] ?? 0))?></strong>
    <small><?=h((int)($commissionTotals['paid_count'] ?? 0))?> ödeme tamamlandı.</small>
  </div>
</div>

<section class="card-lite mb-4">
  <div class="section-heading">
    <h5>CRM Durumu</h5>
    <small><?=h($totalLeads)?> kayıt · <?=h($openTasksCount)?> yaklaşan aksiyon</small>
  </div>
  <div class="pipeline-row mb-4">
    <?php foreach ($statusOptions as $key => $label): ?>
      <?php $count = $statusCounts[$key] ?? 0; ?>
      <?php
        $chipClass = 'pipeline-chip';
        if ($key === REP_LEAD_STATUS_WON) {
          $chipClass .= ' pipeline-chip--positive';
        } elseif ($key === REP_LEAD_STATUS_LOST) {
          $chipClass .= ' pipeline-chip--negative';
        } elseif ($count > 0) {
          $chipClass .= ' pipeline-chip--active';
        }
      ?>
      <span class="<?=$chipClass?>"><span class="count"><?=h($count)?></span><?=h($label)?></span>
    <?php endforeach; ?>
  </div>
  <div class="two-column">
    <div>
      <h6 class="fw-semibold text-muted mb-3">Yaklaşan Aksiyonlar</h6>
      <ul class="timeline-list">
        <?php if (!$upcomingActions): ?>
          <li class="text-muted">Planlanmış aksiyon bulunmuyor. CRM'den yeni takip tarihleri oluşturabilirsiniz.</li>
        <?php else: ?>
          <?php foreach ($upcomingActions as $action): ?>
            <li class="timeline-item">
              <div class="icon"><i class="bi bi-calendar-event"></i></div>
              <div>
                <strong><?=h($action['name'])?></strong>
                <?php if (!empty($action['company'])): ?><span><?=h($action['company'])?></span><?php endif; ?>
                <time><?= $action['next_action_at'] ? h(date('d.m.Y H:i', strtotime($action['next_action_at']))) : 'Takip tarihi yok' ?></time>
              </div>
            </li>
          <?php endforeach; ?>
        <?php endif; ?>
      </ul>
    </div>
    <div>
      <h6 class="fw-semibold text-muted mb-3">Son Görüşme Notları</h6>
      <ul class="timeline-list">
        <?php if (!$recentNotes): ?>
          <li class="text-muted">Henüz CRM notu eklenmemiş. Görüşmelerinizi CRM ekranından kaydedebilirsiniz.</li>
        <?php else: ?>
          <?php foreach ($recentNotes as $note): ?>
            <li class="timeline-item">
              <div class="icon"><i class="bi bi-chat-dots"></i></div>
              <div>
                <strong><?=h($note['lead_name'])?></strong>
                <?php if (!empty($note['lead_company'])): ?><span><?=h($note['lead_company'])?></span><?php endif; ?>
                <span><?=h(mb_strimwidth($note['note'], 0, 120, '...', 'UTF-8'))?></span>
                <time><?= $note['created_at'] ? h(date('d.m.Y H:i', strtotime($note['created_at']))) : '' ?></time>
              </div>
            </li>
          <?php endforeach; ?>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</section>

<section class="card-lite mb-4">
  <div class="section-heading">
    <h5>Komisyon Özeti</h5>
    <small><?=h((int)($commissionTotals['paid_count'] ?? 0))?> ödenen · <?=h((int)($commissionTotals['approved_count'] ?? 0))?> onaylı · <?=h((int)($commissionTotals['pending_count'] ?? 0))?> bekleyen</small>
  </div>
  <div class="two-column">
    <div>
      <h6 class="fw-semibold text-muted mb-3">Son Yüklemeler</h6>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr><th>İşlem</th><th>Tutar</th><th>Komisyon</th><th>Durum</th></tr></thead>
          <tbody>
            <?php if (!$recentTopups): ?>
              <tr><td colspan="4" class="text-muted text-center">Temsilciye ait tamamlanan yükleme bulunmuyor.</td></tr>
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
                ?>
                <tr>
                  <td>#<?=h($topup['id'])?></td>
                  <td><?=h(format_currency($topup['amount_cents']))?></td>
                  <td><?= $topup['commission_cents'] !== null ? h(format_currency($topup['commission_cents'])) : '—' ?></td>
                  <td><span class="<?=$pillClass?>"><?=h($label)?></span></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div>
      <h6 class="fw-semibold text-muted mb-3">Son Komisyon Kayıtları</h6>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead><tr><th>Kayıt</th><th>Komisyon</th><th>Durum</th><th>Tarih</th></tr></thead>
          <tbody>
            <?php if (!$recentCommissions): ?>
              <tr><td colspan="4" class="text-muted text-center">Komisyon kaydı bulunmuyor.</td></tr>
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
                ?>
                <tr>
                  <td>#<?=h($row['dealer_topup_id'])?></td>
                  <td><?=h(format_currency($row['commission_cents']))?></td>
                  <td><span class="<?=$pillClass?>"><?=h($label)?></span></td>
                  <td><?= $row['created_at'] ? h(date('d.m.Y H:i', strtotime($row['created_at']))) : '—' ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>

<section class="card-lite">
  <div class="section-heading">
    <h5>Atandığım Bayiler</h5>
    <small><?=h($dealerCount)?> bayi listelendi</small>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle dealers-table mb-0">
      <thead><tr><th>Bayi</th><th>Komisyon (%)</th><th>Durum</th><th>Atama Tarihi</th></tr></thead>
      <tbody>
        <?php if (!$assignedDealers): ?>
          <tr><td colspan="4" class="text-center text-muted">Henüz size atanmış bir bayi bulunmuyor.</td></tr>
        <?php else: ?>
          <?php foreach ($assignedDealers as $dealer): ?>
            <?php
              $status = $dealer['status'] ?? 'pending';
              switch ($status) {
                case 'active':
                case 'approved':
                  $badgeClass = 'status-pill paid';
                  $statusLabel = 'Aktif';
                  break;
                case 'pending':
                  $badgeClass = 'status-pill pending';
                  $statusLabel = 'Beklemede';
                  break;
                case 'inactive':
                  $badgeClass = 'status-pill default';
                  $statusLabel = 'Pasif';
                  break;
                case 'suspended':
                case 'blocked':
                  $badgeClass = 'status-pill negative';
                  $statusLabel = 'Askıda';
                  break;
                default:
                  $badgeClass = 'status-pill default';
                  $statusLabel = ucfirst($status);
                  break;
              }
            ?>
            <tr>
              <td><?=h($dealer['name'])?></td>
              <td>%<?=h(number_format((float)($dealer['commission_rate'] ?? $representative['commission_rate']), 1))?></td>
              <td><span class="<?=$badgeClass?>"><?=h($statusLabel)?></span></td>
              <td><?= !empty($dealer['assigned_at']) ? h(date('d.m.Y H:i', strtotime($dealer['assigned_at']))) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php representative_layout_end();
