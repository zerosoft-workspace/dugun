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

$rawStatusOptions = representative_crm_status_options();
$statusOptions = array_merge(['all' => 'Tüm Durumlar'], $rawStatusOptions);

$status = $_GET['status'] ?? 'all';
if (!array_key_exists($status, $statusOptions)) {
  $status = 'all';
}
$repId = isset($_GET['representative_id']) ? (int)$_GET['representative_id'] : 0;
$search = trim($_GET['q'] ?? '');
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
if ($limit <= 0) {
  $limit = 50;
}

$representatives = [];
try {
  $representatives = pdo()->query('SELECT id, name, email FROM dealer_representatives ORDER BY name')->fetchAll();
} catch (Throwable $e) {
  $representatives = [];
}

$filters = [
  'status' => $status,
  'representative_id' => $repId,
  'q' => $search,
  'limit' => $limit,
];

$crmReady = representative_crm_tables_ready();

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
$leads = $crmReady ? representative_crm_all_leads($filters) : [];
$recentNotes = $crmReady ? representative_crm_admin_recent_notes(6) : [];
$upcomingActions = $crmReady ? representative_crm_admin_upcoming_actions(6) : [];

$totalLeads = (int)($statusCounts['total'] ?? 0);
$wonCount = (int)($statusCounts[REP_LEAD_STATUS_WON] ?? 0);
$lostCount = (int)($statusCounts[REP_LEAD_STATUS_LOST] ?? 0);
$activeCount = max(0, $totalLeads - $wonCount - $lostCount);

function repcrm_status_label(string $status, array $options): string {
  return $options[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function repcrm_format_datetime(?string $value): string {
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

function repcrm_format_badge(string $status): string {
  $cls = representative_crm_status_badge_class($status);
  return 'badge bg-'.$cls;
}

function repcrm_format_potential(?int $cents): string {
  if ($cents === null) {
    return '<span class="text-muted">-</span>';
  }
  return '<strong>'.h(format_currency($cents)).'</strong>';
}

$title = 'Temsilci CRM Merkezi';
$subtitle = 'Temsilcilerin potansiyel müşteri akışlarını takip edin, aksiyonları planlayın.';

?><!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h(APP_NAME)?> — Temsilci CRM</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?=admin_base_styles()?>
<style>
  .crm-stats { display: grid; gap: 18px; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); }
  .crm-card {
    background: linear-gradient(135deg, rgba(14,165,181,.14), rgba(255,255,255,.92));
    border: 1px solid rgba(14,165,181,.18);
    border-radius: 18px;
    padding: 20px;
    box-shadow: 0 18px 42px -30px rgba(14,165,181,.55);
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .crm-card h4 { margin: 0; font-size: 1.9rem; font-weight: 700; }
  .crm-card span { color: var(--admin-muted); font-weight: 500; letter-spacing: .3px; }
  .crm-card small { color: var(--admin-muted); }
  .crm-filters .form-select,
  .crm-filters .form-control { border-radius: 14px; border: 1px solid rgba(15,23,42,.1); }
  .crm-table thead th { font-weight: 600; color: var(--admin-muted); text-transform: uppercase; font-size: .72rem; letter-spacing: .6px; }
  .crm-table tbody tr { border-radius: 12px; }
  .crm-table tbody td { vertical-align: middle; }
  .crm-pill {
    border-radius: 999px;
    background: rgba(14,165,181,.1);
    color: var(--admin-brand-dark);
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
  }
  .crm-side-card { border-radius: 18px; background: #fff; border: 1px solid rgba(15,23,42,.08); box-shadow: 0 22px 44px -36px rgba(15,23,42,.35); }
  .crm-side-card h5 { font-weight: 600; }
  .crm-side-card .list-group-item { border: none; padding: 14px 18px; }
  .crm-side-card .list-group-item + .list-group-item { border-top: 1px solid rgba(15,23,42,.05); }
  @media (max-width: 991px) {
    .crm-stats { grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); }
  }
</style>
</head>
<body class="admin-body">
<?php admin_layout_start('crm', $title, $subtitle); ?>
<?=flash_messages()?>
<?php if (!$crmReady): ?>
  <div class="alert alert-warning shadow-sm rounded-4">
    CRM tabloları henüz oluşturulmamış görünüyor. Lütfen sistem yöneticinizden kurulum scriptini çalıştırmasını isteyin.
  </div>
<?php else: ?>
  <section class="mb-4">
    <div class="crm-stats">
      <div class="crm-card">
        <span>Toplam Kayıt</span>
        <h4><?=number_format($totalLeads, 0, ',', '.')?></h4>
        <small>Son oluşturulan <?=!empty($leads) && $leads[0]['created_at'] ? h(repcrm_format_datetime($leads[0]['created_at'])) : '—'?></small>
      </div>
      <div class="crm-card">
        <span>Aktif Süreç</span>
        <h4><?=number_format($activeCount, 0, ',', '.')?></h4>
        <small>Görüşmesi devam eden kayıtlar.</small>
      </div>
      <div class="crm-card">
        <span>Kazanılan</span>
        <h4><?=number_format($wonCount, 0, ',', '.')?></h4>
        <small>Toplam pipeline değeri <?=format_currency($pipeline['won_value_cents'] ?? 0)?>.</small>
      </div>
      <div class="crm-card">
        <span>Potansiyel Büyüklük</span>
        <h4><?=format_currency($pipeline['total_value_cents'] ?? 0)?></h4>
        <small><?=$pipeline['with_value_count']?> kayıtta potansiyel değer bulunuyor.</small>
      </div>
    </div>
  </section>

  <section class="crm-filters mb-4">
    <form class="row g-3 align-items-end" method="get" action="">
      <div class="col-md-3">
        <label class="form-label fw-semibold">Durum</label>
        <select class="form-select" name="status">
          <?php foreach ($statusOptions as $key => $label): ?>
            <option value="<?=h($key)?>" <?=$key === $status ? 'selected' : ''?>><?=h($label)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Temsilci</label>
        <select class="form-select" name="representative_id">
          <option value="0">Tüm temsilciler</option>
          <?php foreach ($representatives as $rep): ?>
            <?php $rid = (int)$rep['id']; ?>
            <option value="<?=$rid?>" <?=$rid === $repId ? 'selected' : ''?>><?=h($rep['name'] ?: $rep['email'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Arama</label>
        <input type="text" class="form-control" name="q" value="<?=h($search)?>" placeholder="Ad, şirket veya iletişim">
      </div>
      <div class="col-md-2">
        <label class="form-label fw-semibold">Kayıt Limiti</label>
        <input type="number" min="10" max="200" class="form-control" name="limit" value="<?=h($limit)?>">
      </div>
      <div class="col-md-1 d-grid">
        <button type="submit" class="btn btn-primary rounded-4 fw-semibold">Filtrele</button>
      </div>
    </form>
  </section>

  <div class="row g-4">
    <div class="col-lg-8">
      <div class="crm-side-card p-0">
        <div class="p-4 border-bottom border-light">
          <h5 class="mb-0">Potansiyel Müşteri Listesi</h5>
          <small class="text-muted">Filtrelere göre en yeni <?=$limit?> kayıt listelenir.</small>
        </div>
        <div class="table-responsive">
          <table class="table align-middle mb-0 crm-table">
            <thead>
              <tr>
                <th>Potansiyel</th>
                <th>Temsilci</th>
                <th>Firma</th>
                <th>Durum</th>
                <th>Kaynak</th>
                <th>Potansiyel</th>
                <th>Son Temas</th>
                <th>Takip</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$leads): ?>
                <tr>
                  <td colspan="8" class="text-center py-5 text-muted">Kriterlere uygun kayıt bulunamadı.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($leads as $lead): ?>
                  <tr>
                    <td>
                      <div class="fw-semibold text-dark"><?=h($lead['name'])?></div>
                      <?php if (!empty($lead['email'])): ?>
                        <div class="text-muted small"><i class="bi bi-envelope me-1"></i><?=h($lead['email'])?></div>
                      <?php endif; ?>
                      <?php if (!empty($lead['phone'])): ?>
                        <div class="text-muted small"><i class="bi bi-telephone me-1"></i><?=h($lead['phone'])?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($lead['representative_id']): ?>
                        <div class="fw-semibold"><?=h($lead['representative_name'] ?: 'Temsilci #'.$lead['representative_id'])?></div>
                        <?php if (!empty($lead['representative_email'])): ?>
                          <div class="text-muted small"><?=h($lead['representative_email'])?></div>
                        <?php endif; ?>
                      <?php else: ?>
                        <span class="badge bg-secondary-subtle text-secondary">Atanmamış</span>
                      <?php endif; ?>
                    </td>
                    <td><?=h($lead['company'] ?? '-')?></td>
                    <td><span class="<?=repcrm_format_badge($lead['status'])?>"><?=h(repcrm_status_label($lead['status'], $statusOptions))?></span></td>
                    <td><?=h($lead['source'] ?: 'Bilinmiyor')?></td>
                    <td><?=repcrm_format_potential($lead['potential_value_cents'])?></td>
                    <td><?=h($lead['last_contact_at'] ? repcrm_format_datetime($lead['last_contact_at']) : '—')?></td>
                    <td><?=h($lead['next_action_at'] ? repcrm_format_datetime($lead['next_action_at']) : '—')?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="crm-side-card p-0 mb-4">
        <div class="p-4 border-bottom border-light">
          <h5 class="mb-0">Yaklaşan Aksiyonlar</h5>
          <small class="text-muted">Temsilcilerin planlanan görüşmeleri.</small>
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
                  <span class="crm-pill">
                    <i class="bi bi-clock"></i>
                    <?=h(repcrm_format_datetime($item['next_action_at']))?>
                  </span>
                </div>
              </li>
            <?php endforeach; ?>
          <?php endif; ?>
        </ul>
      </div>

      <div class="crm-side-card p-0">
        <div class="p-4 border-bottom border-light">
          <h5 class="mb-0">Son Görüşme Notları</h5>
          <small class="text-muted">Temsilci ekibinden gelen en güncel geri bildirimler.</small>
        </div>
        <ul class="list-group list-group-flush">
          <?php if (!$recentNotes): ?>
            <li class="list-group-item text-muted">Henüz görüşme notu bulunmuyor.</li>
          <?php else: ?>
            <?php foreach ($recentNotes as $note): ?>
              <li class="list-group-item">
                <div class="d-flex justify-content-between">
                  <div>
                    <strong><?=h($note['lead_name'])?></strong>
                    <?php if (!empty($note['lead_company'])): ?>
                      <div class="text-muted small"><?=h($note['lead_company'])?></div>
                    <?php endif; ?>
                    <div class="small text-muted">Durum: <?=h(repcrm_status_label($note['lead_status'], $statusOptions))?></div>
                    <div class="mt-2 text-body-secondary small">“<?=h(mb_strimwidth($note['note'], 0, 160, '…', 'UTF-8'))?>”</div>
                  </div>
                  <div class="text-end small text-muted">
                    <?=h(repcrm_format_datetime($note['created_at']))?><br>
                    <?php if (!empty($note['representative_name'])): ?>
                      <span class="badge bg-light text-dark border"><?=h($note['representative_name'])?></span>
                    <?php endif; ?>
                  </div>
                </div>
              </li>
            <?php endforeach; ?>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </div>
<?php endif; ?>
<?php admin_layout_end(); ?>
</body>
</html>
