<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/representatives.php';
require_once __DIR__.'/../includes/representative_crm.php';
require_once __DIR__.'/partials/ui.php';

require_admin();
install_schema();

$crmReady = representative_crm_tables_ready();
$rawStatusOptions = representative_crm_status_options();

$emptyStatusCounts = array_fill_keys(array_keys($rawStatusOptions), 0);
$emptyStatusCounts['total'] = 0;
$statusCounts = $crmReady ? representative_crm_global_status_counts() : $emptyStatusCounts;
$pipeline = $crmReady ? representative_crm_pipeline_amounts() : [
  'total_value_cents' => 0,
  'active_value_cents' => 0,
  'won_value_cents' => 0,
  'lost_value_cents' => 0,
  'with_value_count' => 0,
];
$monthlyTrend = $crmReady ? representative_crm_leads_by_month(6) : [];
$sourceBreakdown = $crmReady ? representative_crm_source_breakdown(8) : [];
$recentNotes = $crmReady ? representative_crm_admin_recent_notes(5) : [];
$upcomingActions = $crmReady ? representative_crm_admin_upcoming_actions(5) : [];

$commissionSummary = representative_admin_commission_overview();
$commissionLeaders = representative_commission_leaderboard(6);

$totalLeads = (int)($statusCounts['total'] ?? 0);
$wonCount = (int)($statusCounts[REP_LEAD_STATUS_WON] ?? 0);
$lostCount = (int)($statusCounts[REP_LEAD_STATUS_LOST] ?? 0);
$activeCount = max(0, $totalLeads - $wonCount - $lostCount);

$conversionRate = $totalLeads > 0 ? round(($wonCount / $totalLeads) * 100, 1) : 0.0;
$lossRate = $totalLeads > 0 ? round(($lostCount / $totalLeads) * 100, 1) : 0.0;
$avgWonValue = $wonCount > 0 ? (int)round(($pipeline['won_value_cents'] ?? 0) / max(1, $wonCount)) : 0;
$activePipelineValue = $pipeline['active_value_cents'] ?? 0;
$sourceTotal = array_sum(array_map(fn($row) => (int)($row['total'] ?? 0), $sourceBreakdown));

function repan_format_datetime(?string $value): string {
  if (!$value) {
    return '-';
  }
  try {
    $dt = new DateTime($value);
    return $dt->format('d.m.Y H:i');
  } catch (Throwable $e) {
    return $value;
  }
}

function repan_status_label(string $status, array $options): string {
  return $options[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function repan_badge(string $status): string {
  return 'badge bg-'.representative_crm_status_badge_class($status);
}

$title = 'Temsilci Analizleri';
$subtitle = 'Temsilci ekibinin performansını, pipeline sağlığını ve komisyon gelirlerini izleyin.';

?><!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h(APP_NAME)?> — Analizler</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?=admin_base_styles()?>
<style>
  .analytics-grid { display: grid; gap: 18px; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); }
  .analytics-card {
    border-radius: 18px;
    padding: 20px;
    background: linear-gradient(145deg, rgba(14,165,181,.12), rgba(255,255,255,.95));
    border: 1px solid rgba(14,165,181,.16);
    box-shadow: 0 20px 46px -30px rgba(14,165,181,.45);
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .analytics-card h4 { font-size: 1.8rem; margin: 0; font-weight: 700; }
  .analytics-card span { color: var(--admin-muted); font-weight: 600; letter-spacing: .3px; }
  .analytics-card small { color: var(--admin-muted); }
  .analytics-section { border-radius: 20px; background: #fff; border: 1px solid rgba(15,23,42,.08); box-shadow: 0 25px 48px -40px rgba(15,23,42,.35); }
  .analytics-section h5 { font-weight: 600; }
  .analytics-section .table thead th { text-transform: uppercase; letter-spacing: .6px; font-size: .72rem; color: var(--admin-muted); }
  .analytics-progress { height: 10px; border-radius: 999px; background: rgba(15,23,42,.08); overflow: hidden; }
  .analytics-progress .bar { height: 100%; border-radius: 999px; background: linear-gradient(90deg, var(--admin-brand), var(--admin-brand-dark)); }
  .leader-avatar { width: 42px; height: 42px; border-radius: 12px; background: rgba(14,165,181,.12); display: inline-flex; align-items: center; justify-content: center; font-weight: 700; color: var(--admin-brand-dark); }
  .source-chip { border-radius: 14px; background: rgba(14,165,181,.08); padding: 8px 12px; display: flex; justify-content: space-between; font-weight: 600; }
  @media (max-width: 991px) {
    .analytics-grid { grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); }
  }
</style>
</head>
<body class="admin-body">
<?php admin_layout_start('analytics', $title, $subtitle); ?>
<?=flash_messages()?>
<?php if (!$crmReady): ?>
  <div class="alert alert-warning rounded-4 shadow-sm mb-4">CRM tabloları henüz oluşturulmamış. Analiz verileri sınırlı olabilir.</div>
<?php endif; ?>

<section class="mb-4">
  <div class="analytics-grid">
    <div class="analytics-card">
      <span>Toplam Potansiyel</span>
      <h4><?=number_format($totalLeads, 0, ',', '.')?></h4>
      <small>Aktif: <?=number_format($activeCount, 0, ',', '.')?> • Kazanılan: <?=number_format($wonCount, 0, ',', '.')?></small>
    </div>
    <div class="analytics-card">
      <span>Dönüşüm Oranı</span>
      <h4><?=$conversionRate?>%</h4>
      <small>Kayıp oranı <?=$lossRate?>% olarak ölçülüyor.</small>
    </div>
    <div class="analytics-card">
      <span>Aktif Pipeline</span>
      <h4><?=format_currency($activePipelineValue)?></h4>
      <small>Kazanılan değer <?=format_currency($pipeline['won_value_cents'] ?? 0)?>.</small>
    </div>
    <div class="analytics-card">
      <span>Ortalama Kazanç</span>
      <h4><?=format_currency($avgWonValue)?></h4>
      <small>Kazanılan kayıt başına ortalama değer.</small>
    </div>
    <div class="analytics-card">
      <span>Bekleyen Komisyon</span>
      <h4><?=format_currency($commissionSummary['pending_amount'] ?? 0)?></h4>
      <small><?=$commissionSummary['pending_count'] ?? 0?> ödeme bekliyor.</small>
    </div>
    <div class="analytics-card">
      <span>Onaylanan Komisyon</span>
      <h4><?=format_currency($commissionSummary['approved_amount'] ?? 0)?></h4>
      <small><?=$commissionSummary['approved_count'] ?? 0?> ödeme transfer için hazır.</small>
    </div>
    <div class="analytics-card">
      <span>Ödenen Komisyon</span>
      <h4><?=format_currency($commissionSummary['paid_amount'] ?? 0)?></h4>
      <small><?=$commissionSummary['paid_count'] ?? 0?> ödeme tamamlandı.</small>
    </div>
  </div>
</section>

<div class="row g-4 mb-4">
  <div class="col-lg-7">
    <div class="analytics-section p-0">
      <div class="p-4 border-bottom border-light">
        <h5 class="mb-0">Pipeline Sağlığı</h5>
        <small class="text-muted">Durumlara göre lead dağılımı ve değerleri.</small>
      </div>
      <div class="p-4">
        <?php if ($totalLeads === 0): ?>
          <div class="text-muted">Henüz CRM kayıtı bulunmuyor.</div>
        <?php else: ?>
          <?php foreach ($rawStatusOptions as $key => $label): ?>
            <?php $count = (int)($statusCounts[$key] ?? 0); ?>
            <?php if ($count === 0) continue; ?>
            <?php
              $percent = $totalLeads > 0 ? round(($count / $totalLeads) * 100) : 0;
              $valueLabel = '—';
              if ($key === REP_LEAD_STATUS_WON) {
                $valueLabel = format_currency((int)($pipeline['won_value_cents'] ?? 0));
              } elseif ($key === REP_LEAD_STATUS_LOST) {
                $valueLabel = format_currency((int)($pipeline['lost_value_cents'] ?? 0));
              } elseif ($activeCount > 0 && ($pipeline['active_value_cents'] ?? 0) > 0) {
                $share = (int)round(($pipeline['active_value_cents'] ?? 0) * ($count / max(1, $activeCount)));
                $valueLabel = format_currency($share);
              }
            ?>
            <div class="mb-3">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <div>
                  <span class="<?=repan_badge($key)?>"><?=h($label)?></span>
                  <span class="ms-2 fw-semibold text-dark"><?=number_format($count, 0, ',', '.')?></span>
                </div>
                <div class="text-muted small"><?=$valueLabel?> • <?=$percent?>%</div>
              </div>
              <div class="analytics-progress">
                <div class="bar" style="width: <?=$percent?>%;"></div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="analytics-section p-0">
      <div class="p-4 border-bottom border-light">
        <h5 class="mb-0">Kaynak Dağılımı</h5>
        <small class="text-muted">Potansiyel müşterilerin geldiği kanallar.</small>
      </div>
      <div class="p-4">
        <?php if (!$sourceBreakdown): ?>
          <div class="text-muted">Kaynak bilgisi bulunmuyor.</div>
        <?php else: ?>
          <div class="d-flex flex-column gap-2">
            <?php foreach ($sourceBreakdown as $row): ?>
              <?php $count = (int)($row['total'] ?? 0); ?>
              <?php $percent = $sourceTotal > 0 ? round(($count / $sourceTotal) * 100, 1) : 0; ?>
              <div class="source-chip">
                <span><?=h($row['label'])?></span>
                <span><?=$count?> • <?=$percent?>%</span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-4 mb-4">
  <div class="col-lg-7">
    <div class="analytics-section p-0">
      <div class="p-4 border-bottom border-light">
        <h5 class="mb-0">Aylık Trend</h5>
        <small class="text-muted">Son 6 ayda oluşturulan lead performansı.</small>
      </div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead>
            <tr>
              <th>Ay</th>
              <th>Toplam</th>
              <th>Kazanılan</th>
              <th>Kaybedilen</th>
              <th>Dönüşüm</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$monthlyTrend): ?>
              <tr>
                <td colspan="5" class="text-center text-muted py-4">Trend verisi bulunamadı.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($monthlyTrend as $month): ?>
                <?php $monthTotal = (int)$month['total']; ?>
                <?php $monthWon = (int)$month['won']; ?>
                <?php $conversion = $monthTotal > 0 ? round(($monthWon / $monthTotal) * 100, 1) : 0; ?>
                <tr>
                  <td><?=h($month['label'])?></td>
                  <td><?=number_format($monthTotal, 0, ',', '.')?></td>
                  <td><?=number_format($monthWon, 0, ',', '.')?></td>
                  <td><?=number_format((int)$month['lost'], 0, ',', '.')?></td>
                  <td><?=$conversion?>%</td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="analytics-section p-0">
      <div class="p-4 border-bottom border-light">
        <h5 class="mb-0">Temsilci Lider Tablosu</h5>
        <small class="text-muted">Komisyon üretimine göre ilk temsilciler.</small>
      </div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead>
            <tr>
              <th>Temsilci</th>
              <th>Bayiler</th>
              <th>Toplam</th>
              <th>Ödenen</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$commissionLeaders): ?>
              <tr>
                <td colspan="4" class="text-center text-muted py-4">Komisyon kaydı bulunamadı.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($commissionLeaders as $leader): ?>
                <?php $initial = mb_strtoupper(mb_substr($leader['name'] ?: ($leader['email'] ?? 'T'), 0, 1, 'UTF-8'), 'UTF-8'); ?>
                <tr>
                  <td>
                    <div class="d-flex align-items-center gap-3">
                      <div class="leader-avatar"><?=h($initial)?></div>
                      <div>
                        <div class="fw-semibold text-dark"><?=h($leader['name'] ?: ($leader['email'] ?? 'Temsilci #'.$leader['id']))?></div>
                        <?php if (!empty($leader['email'])): ?>
                          <div class="text-muted small"><?=h($leader['email'])?></div>
                        <?php endif; ?>
                        <div class="small text-muted">Son işlem: <?=h(repan_format_datetime($leader['latest_activity_at']))?></div>
                      </div>
                    </div>
                  </td>
                  <td><?=number_format((int)$leader['dealer_count'], 0, ',', '.')?></td>
                  <td><?=format_currency((int)$leader['total_commission_cents'])?></td>
                  <td><?=format_currency((int)$leader['paid_commission_cents'])?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="row g-4 mb-5">
  <div class="col-lg-6">
    <div class="analytics-section p-0">
      <div class="p-4 border-bottom border-light">
        <h5 class="mb-0">Son Görüşme Notları</h5>
        <small class="text-muted">Temsilcilerden gelen en güncel CRM aktiviteleri.</small>
      </div>
      <ul class="list-group list-group-flush">
        <?php if (!$recentNotes): ?>
          <li class="list-group-item text-muted">Henüz not eklenmemiş.</li>
        <?php else: ?>
          <?php foreach ($recentNotes as $note): ?>
            <li class="list-group-item">
              <div class="d-flex justify-content-between gap-3">
                <div>
                  <strong><?=h($note['lead_name'])?></strong>
                  <?php if (!empty($note['lead_company'])): ?>
                    <div class="text-muted small"><?=h($note['lead_company'])?></div>
                  <?php endif; ?>
                  <div class="badge bg-light text-dark border mt-2">Durum: <?=h(repan_status_label($note['lead_status'], $rawStatusOptions))?></div>
                  <div class="mt-2 text-body-secondary small">“<?=h(mb_strimwidth($note['note'], 0, 140, '…', 'UTF-8'))?>”</div>
                </div>
                <div class="text-end small text-muted">
                  <?=h(repan_format_datetime($note['created_at']))?><br>
                  <?php if (!empty($note['representative_name'])): ?>
                    <span class="badge bg-primary-subtle text-primary"><?=h($note['representative_name'])?></span>
                  <?php endif; ?>
                </div>
              </div>
            </li>
          <?php endforeach; ?>
        <?php endif; ?>
      </ul>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="analytics-section p-0">
      <div class="p-4 border-bottom border-light">
        <h5 class="mb-0">Planlanan Aksiyonlar</h5>
        <small class="text-muted">Temsilcilerin sıradaki görüşme planları.</small>
      </div>
      <ul class="list-group list-group-flush">
        <?php if (!$upcomingActions): ?>
          <li class="list-group-item text-muted">Planlanmış aksiyon bulunmuyor.</li>
        <?php else: ?>
          <?php foreach ($upcomingActions as $item): ?>
            <li class="list-group-item">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <strong><?=h($item['name'])?></strong>
                  <?php if (!empty($item['company'])): ?>
                    <div class="text-muted small"><?=h($item['company'])?></div>
                  <?php endif; ?>
                  <?php if (!empty($item['representative_name'])): ?>
                    <div class="small text-primary">Temsilci: <?=h($item['representative_name'])?></div>
                  <?php endif; ?>
                </div>
                <div class="text-end">
                  <span class="badge bg-info-subtle text-info-emphasis"><?=h(repan_format_datetime($item['next_action_at']))?></span>
                </div>
              </div>
            </li>
          <?php endforeach; ?>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</div>

<?php admin_layout_end(); ?>
</body>
</html>
