<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/dealers.php';
require_once __DIR__.'/../includes/representatives.php';
require_once __DIR__.'/../includes/dealer_crm.php';
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

$assignedDealers = $representative['dealers'] ?? [];
$assignedDealerIds = array_map(fn($dealer) => (int)$dealer['id'], $assignedDealers);
$dealerLookup = [];
foreach ($assignedDealers as $item) {
  $dealerLookup[(int)$item['id']] = $item;
}

$dealerId = isset($_POST['context_dealer_id']) ? (int)$_POST['context_dealer_id'] : 0;
if ($dealerId <= 0) {
  $dealerId = isset($_GET['dealer_id']) ? (int)$_GET['dealer_id'] : 0;
}
if ($dealerId > 0 && !isset($dealerLookup[$dealerId])) {
  $dealerId = 0;
}
if ($dealerId === 0 && $assignedDealerIds) {
  $dealerId = $assignedDealerIds[0];
}

$dealer = $dealerId ? dealer_get($dealerId) : null;
if ($dealerId && !$dealer) {
  $remaining = array_values(array_filter($assignedDealerIds, fn($id) => $id !== $dealerId));
  if (count($remaining) !== count($assignedDealerIds)) {
    try {
      representative_update_assignments((int)$representative['id'], $remaining);
    } catch (Throwable $e) {}
    $representative = representative_get((int)$user['id']);
    $assignedDealers = $representative['dealers'] ?? [];
    $assignedDealerIds = array_map(fn($dealer) => (int)$dealer['id'], $assignedDealers);
    $dealerLookup = [];
    foreach ($assignedDealers as $item) {
      $dealerLookup[(int)$item['id']] = $item;
    }
    $dealerId = $assignedDealerIds[0] ?? 0;
    $dealer = $dealerId ? dealer_get($dealerId) : null;
  }
}

$dealerQuery = $dealerId ? '?dealer_id='.$dealerId : '';

$commissionTotals = representative_commission_totals((int)$representative['id']);
$topups = representative_completed_topups((int)$representative['id'], 20);
$commissionHistory = representative_recent_commissions((int)$representative['id'], 10);
$leadStatusLabels = dealer_lead_status_options();
$leadStats = [];
$upcomingActions = [];
$recentNotes = [];
$leads = [];
$leadNotesMap = [];

if ($dealer) {
  $leadStats = dealer_lead_status_counts((int)$dealer['id']);
  $upcomingActions = dealer_lead_upcoming_actions((int)$dealer['id'], 5);
  $recentNotes = dealer_lead_recent_notes((int)$dealer['id'], 5);
  $leads = dealer_leads_list((int)$dealer['id']);
  foreach ($leads as $lead) {
    $notes = dealer_lead_notes((int)$lead['id'], (int)$dealer['id']);
    $leadNotesMap[(int)$lead['id']] = array_slice($notes, 0, 4);
  }
} else {
  foreach ($leadStatusLabels as $key => $_) {
    $leadStats[$key] = 0;
  }
}

$action = $_POST['do'] ?? '';
if ($action) {
  csrf_or_die();
  try {
    if (!$dealer || !$dealerId) {
      throw new RuntimeException('Önce bir bayi seçmeniz gerekiyor.');
    }
    if ($action === 'create_lead') {
      $payload = [
        'name' => $_POST['lead_name'] ?? '',
        'email' => $_POST['lead_email'] ?? '',
        'phone' => $_POST['lead_phone'] ?? '',
        'company' => $_POST['lead_company'] ?? '',
        'status' => $_POST['lead_status'] ?? DEALER_LEAD_STATUS_NEW,
        'source' => $_POST['lead_source'] ?? '',
        'notes' => $_POST['lead_notes'] ?? '',
        'next_action_at' => $_POST['lead_next_action'] ?? null,
      ];
      $leadId = dealer_lead_create((int)$dealer['id'], $payload, (int)$representative['id']);
      flash('ok', 'Potansiyel müşteri kaydedildi.');
      redirect('dashboard.php'.$dealerQuery.'#lead-'.$leadId);
    }
    if ($action === 'update_lead') {
      $leadId = (int)($_POST['lead_id'] ?? 0);
      if ($leadId <= 0) {
        throw new RuntimeException('Potansiyel müşteri bulunamadı.');
      }
      dealer_lead_update($leadId, (int)$dealer['id'], [
        'status' => $_POST['status'] ?? '',
        'next_action_at' => $_POST['next_action_at'] ?? null,
        'notes' => $_POST['notes'] ?? null,
      ], (int)$representative['id']);
      flash('ok', 'Potansiyel müşteri bilgileri güncellendi.');
      redirect('dashboard.php'.$dealerQuery.'#lead-'.$leadId);
    }
    if ($action === 'add_note') {
      $leadId = (int)($_POST['lead_id'] ?? 0);
      if ($leadId <= 0) {
        throw new RuntimeException('Potansiyel müşteri bulunamadı.');
      }
      $note = trim($_POST['note'] ?? '');
      $contactType = trim($_POST['contact_type'] ?? '');
      $nextAction = $_POST['next_action_at'] ?? null;
      dealer_lead_add_note(
        $leadId,
        (int)$dealer['id'],
        $note,
        $contactType !== '' ? $contactType : null,
        $nextAction,
        (int)$representative['id']
      );
      flash('ok', 'Görüşme notu kaydedildi.');
      redirect('dashboard.php'.$dealerQuery.'#lead-'.$leadId);
    }
  } catch (Throwable $e) {
    flash('err', $e->getMessage());
    redirect('dashboard.php'.$dealerQuery);
  }
}

$leadTotal = array_sum($leadStats);
$leadColumns = [];
foreach ($leadStatusLabels as $key => $label) {
  $leadColumns[$key] = ['label' => $label, 'leads' => []];
}
foreach ($leads as $lead) {
  $statusKey = $lead['status'] ?? DEALER_LEAD_STATUS_NEW;
  if (!isset($leadColumns[$statusKey])) {
    $leadColumns[$statusKey] = ['label' => $leadStatusLabels[$statusKey] ?? ucfirst($statusKey), 'leads' => []];
  }
  $leadColumns[$statusKey]['leads'][] = $lead;
}

$pageStyles = <<<'CSS'
<style>
  .card-lite{border-radius:20px;background:var(--rep-surface);border:1px solid rgba(148,163,184,.18);box-shadow:0 26px 60px -42px rgba(15,23,42,.4);padding:1.9rem;}
  .card-lite + .card-lite{margin-top:1.6rem;}
  .card-lite[id]{scroll-margin-top:120px;}
  .section-heading{display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:1rem;flex-wrap:wrap;}
  .section-heading h5{font-weight:700;margin:0;color:var(--rep-ink);}
  .section-heading small{color:var(--rep-muted);font-weight:500;}
  .dealer-badge{display:inline-flex;align-items:center;gap:.35rem;padding:.35rem .75rem;border-radius:999px;background:rgba(14,165,181,.16);color:var(--rep-brand-dark);font-size:.78rem;font-weight:600;}
  .dealer-badge i{font-size:1rem;}
  .summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.1rem;}
  .summary-item{border-radius:18px;padding:1.05rem 1.15rem;background:linear-gradient(150deg,#fff,rgba(14,165,181,.08));border:1px solid rgba(148,163,184,.18);box-shadow:0 24px 54px -44px rgba(15,23,42,.45);}
  .summary-item span{display:block;font-size:.74rem;text-transform:uppercase;letter-spacing:.08em;color:var(--rep-muted);margin-bottom:.35rem;font-weight:600;}
  .summary-item strong{font-size:1.4rem;color:var(--rep-ink);display:block;}
  .summary-item small{display:block;font-size:.78rem;color:#475569;margin-top:.25rem;}
  .crm-status-row{display:flex;flex-wrap:wrap;gap:.6rem;margin-top:.5rem;}
  .status-chip{display:flex;align-items:center;gap:.45rem;padding:.35rem .85rem;border-radius:999px;font-size:.78rem;font-weight:600;background:rgba(148,163,184,.16);color:#475569;}
  .status-chip .count{font-size:.9rem;color:var(--rep-ink);}
  .status-chip--new{background:rgba(14,165,181,.18);color:var(--rep-brand-dark);}
  .status-chip--contacted{background:rgba(59,130,246,.16);color:#1d4ed8;}
  .status-chip--qualified{background:rgba(126,58,242,.16);color:#6d28d9;}
  .status-chip--follow-up{background:rgba(96,165,250,.16);color:#2563eb;}
  .status-chip--proposal-sent{background:rgba(45,212,191,.16);color:#0f766e;}
  .status-chip--negotiation{background:rgba(250,204,21,.22);color:#854d0e;}
  .status-chip--on-hold{background:rgba(148,163,184,.25);color:#475569;}
  .status-chip--won{background:rgba(34,197,94,.18);color:#166534;}
  .status-chip--lost{background:rgba(248,113,113,.18);color:#b91c1c;}
  .crm-panels{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1.05rem;margin-top:1.2rem;}
  .crm-panel{border:1px solid rgba(148,163,184,.2);border-radius:18px;padding:1.2rem;background:#f9fbfd;box-shadow:0 20px 48px -40px rgba(15,23,42,.3);}
  .crm-panel h6{font-size:.9rem;margin:0 0 .9rem;font-weight:600;color:var(--rep-ink);text-transform:uppercase;letter-spacing:.08em;}
  .crm-panel ul{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.9rem;}
  .crm-panel li{display:flex;gap:.75rem;align-items:flex-start;}
  .crm-panel .icon{width:36px;height:36px;border-radius:12px;background:rgba(14,165,181,.12);color:var(--rep-brand-dark);display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;}
  .crm-panel .content strong{display:block;font-weight:600;color:var(--rep-ink);}
  .crm-panel .content span{display:block;font-size:.82rem;color:#475569;}
  .crm-panel .content small{display:inline-flex;margin-top:.25rem;padding:.15rem .55rem;border-radius:999px;font-size:.7rem;background:#e2f4f7;color:var(--rep-brand-dark);letter-spacing:.06em;text-transform:uppercase;}
  .crm-panel .empty{font-size:.85rem;color:#94a3b8;}
  .create-lead-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;}
  .create-lead-form .full-span{grid-column:1 / -1;}
  .create-lead-form .form-control,.create-lead-form .form-select,.lead-update-form .form-control,.lead-update-form .form-select{border-radius:12px;font-size:.9rem;border-color:rgba(148,163,184,.35);}
  .create-lead-form textarea{min-height:110px;}
  .crm-board{display:grid;grid-auto-flow:column;grid-auto-columns:minmax(280px,1fr);gap:1.1rem;overflow-x:auto;padding-bottom:.5rem;margin-bottom:-.5rem;}
  .crm-board::-webkit-scrollbar{height:8px;}
  .crm-board::-webkit-scrollbar-thumb{background:rgba(148,163,184,.6);border-radius:999px;}
  .crm-column{background:#f8fafc;border:1px solid rgba(148,163,184,.18);border-radius:18px;padding:1.1rem;display:flex;flex-direction:column;gap:1rem;min-height:280px;box-shadow:0 22px 44px -34px rgba(15,23,42,.35);}
  .crm-column-header{display:flex;justify-content:space-between;align-items:center;}
  .crm-column-header h6{margin:0;font-size:.95rem;font-weight:700;color:var(--rep-ink);}
  .crm-column-header .count-badge{font-size:.8rem;font-weight:600;padding:.2rem .65rem;border-radius:999px;background:rgba(148,163,184,.24);color:#475569;}
  .crm-column.status-new{border-color:rgba(14,165,181,.35);}
  .crm-column.status-contacted{border-color:rgba(59,130,246,.35);}
  .crm-column.status-qualified{border-color:rgba(126,58,242,.35);}
  .crm-column.status-follow-up{border-color:rgba(96,165,250,.35);}
  .crm-column.status-proposal-sent{border-color:rgba(45,212,191,.35);}
  .crm-column.status-negotiation{border-color:rgba(250,204,21,.45);}
  .crm-column.status-on-hold{border-color:rgba(148,163,184,.45);}
  .crm-column.status-won{border-color:rgba(34,197,94,.45);}
  .crm-column.status-lost{border-color:rgba(248,113,113,.4);}
  .lead-card{background:#fff;border:1px solid rgba(148,163,184,.2);border-radius:16px;padding:1rem;display:flex;flex-direction:column;gap:.9rem;box-shadow:0 20px 44px -34px rgba(15,23,42,.35);}
  .lead-card h6{margin:0;font-weight:700;color:var(--rep-ink);}
  .lead-card .status-chip{margin-top:.3rem;}
  .lead-meta{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.35rem;font-size:.82rem;color:#475569;}
  .lead-meta li{display:flex;gap:.45rem;align-items:center;}
  .lead-meta i{color:var(--rep-brand);}
  .lead-timestamps{display:flex;flex-direction:column;gap:.3rem;font-size:.75rem;color:#64748b;}
  .lead-update-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.75rem;}
  .lead-update-form .full-span{grid-column:1 / -1;}
  .lead-update-form .form-label,.note-form .form-label{font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:var(--rep-muted);margin-bottom:.2rem;font-weight:600;}
  .note-timeline{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.65rem;}
  .note-timeline li{border:1px solid rgba(148,163,184,.22);border-radius:14px;padding:.65rem;background:#fff;font-size:.82rem;color:var(--rep-ink);}
  .note-timeline .meta{display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.35rem;font-size:.7rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;}
  .note-form textarea{min-height:90px;border-radius:12px;}
  .note-form .btn{font-size:.85rem;border-radius:12px;}
  .empty-state{padding:2.5rem;border:2px dashed rgba(148,163,184,.28);border-radius:16px;text-align:center;color:#94a3b8;font-size:.95rem;background:#fff;}
  .table thead{background:#f8fafc;}
  .table th{font-size:.78rem;text-transform:uppercase;letter-spacing:.08em;color:var(--rep-muted);border-bottom:none;}
  .badge-status{display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .7rem;border-radius:999px;font-size:.75rem;font-weight:600;}
  .status-paid{background:rgba(34,197,94,.2);color:#166534;}
  .status-pending{background:rgba(250,204,21,.2);color:#854d0e;}
  @media (max-width: 767px){
    .crm-board{grid-auto-columns:90%;}
    .summary-grid{grid-template-columns:1fr;}
  }
</style>
CSS;

$dealerSelectorHtml = '';
if (!empty($assignedDealers)) {
  ob_start();
  ?>
  <form method="get" class="rep-selector" id="dealer-switcher" autocomplete="off">
    <i class="bi bi-building"></i>
    <select name="dealer_id" aria-label="Bayi seçimi" onchange="this.form.submit()">
      <?php foreach ($assignedDealers as $item): ?>
        <option value="<?= (int)$item['id'] ?>" <?=$dealerId === (int)$item['id'] ? 'selected' : ''?>><?=h($item['name'])?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <?php
  $dealerSelectorHtml = ob_get_clean();
}

if ($dealer) {
  $headerSubtitle = $dealer['name'].' bayisinin yüklemelerini, CRM akışını ve komisyonlarını buradan yönetin.';
} elseif (!empty($assignedDealers)) {
  $headerSubtitle = 'Atandığınız bayileri görüntülemek için üstteki seçimden bir bayi seçin.';
} else {
  $headerSubtitle = 'Henüz bir bayi ataması yapılmadı. Yönetici ekibinizden atama talep edebilirsiniz.';
}

representative_layout_start([
  'page_title' => APP_NAME.' — Temsilci Paneli',
  'header_title' => 'Merhaba '.$representative['name'].' 👋',
  'header_subtitle' => $headerSubtitle,
  'representative' => $representative,
  'dealer' => $dealer,
  'dealer_selector' => $dealerSelectorHtml,
  'extra_head' => $pageStyles,
  'logout_url' => 'login.php?logout=1',
]);

flash_box();

if (!$dealer): ?>
  <div class="card-lite mb-4">
    <h5 class="fw-semibold mb-2">Bayi ataması bekleniyor</h5>
    <p class="mb-0 text-muted">CRM ekranlarını ve komisyon özetlerini görüntüleyebilmek için sistem yöneticinizden bir bayi ataması yapılmasını isteyin.</p>
  </div>
<?php else: ?>
  <section class="card-lite mb-4" id="commissions">
    <div class="section-heading">
      <h5 class="mb-0">Komisyon özeti</h5>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <small>Bayinizin gerçekleştirdiği yüklemeler üzerinden kazanımlarınızı takip edin.</small>
        <?php if ($dealer): ?>
          <span class="dealer-badge"><i class="bi bi-building"></i><?=h($dealer['name'])?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="summary-grid">
      <div class="summary-item">
        <span>Toplam Komisyon</span>
        <strong><?=h(format_currency($commissionTotals['total_amount']))?></strong>
        <small>Bayinizin gerçekleştirdiği yüklemeler</small>
      </div>
      <div class="summary-item">
        <span>Bekleyen</span>
        <strong><?=h(format_currency($commissionTotals['pending_amount']))?></strong>
        <small><?=h((int)$commissionTotals['pending_count'])?> bekleyen işlem</small>
      </div>
      <div class="summary-item">
        <span>Ödenen</span>
        <strong><?=h(format_currency($commissionTotals['paid_amount']))?></strong>
        <small><?=h((int)$commissionTotals['paid_count'])?> tamamlanan ödeme</small>
      </div>
      <div class="summary-item">
        <span>Komisyon Oranı</span>
        <strong>%<?=h(number_format($representative['commission_rate'], 1))?></strong>
        <small><?=h(count($assignedDealers))?> bayi ataması</small>
      </div>
    </div>
  </section>

  <section class="card-lite mb-4" id="crm">
    <div class="section-heading">
      <h5 class="mb-0">CRM özeti</h5>
      <span class="badge bg-light text-dark px-3 py-2 rounded-pill">Toplam Potansiyel: <?=$leadTotal?></span>
    </div>
    <div class="crm-status-row">
      <?php foreach ($leadStats as $statusKey => $count): ?>
        <?php $statusLabel = $leadStatusLabels[$statusKey] ?? ucfirst($statusKey); $chipClass = 'status-chip--'.preg_replace('/[^a-z0-9]+/', '-', $statusKey); ?>
        <div class="status-chip <?=$chipClass?>">
          <span><?=h($statusLabel)?></span>
          <span class="count"><?= (int)$count ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="crm-panels">
      <div class="crm-panel">
        <h6>Yaklaşan Aksiyonlar</h6>
        <?php if (!$upcomingActions): ?>
          <p class="empty mb-0">Planlanmış aksiyon bulunmuyor.</p>
        <?php else: ?>
          <ul>
            <?php foreach ($upcomingActions as $item): ?>
              <li>
                <div class="icon"><i class="bi bi-calendar-event"></i></div>
                <div class="content">
                  <strong><?=h($item['name'])?></strong>
                  <span><?=h(date('d.m.Y H:i', strtotime($item['next_action_at'])))?></span>
                  <small><?=h($leadStatusLabels[$item['status']] ?? ucfirst($item['status']))?></small>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
      <div class="crm-panel">
        <h6>Son Görüşme Notları</h6>
        <?php if (!$recentNotes): ?>
          <p class="empty mb-0">Henüz not eklenmedi.</p>
        <?php else: ?>
          <ul>
            <?php foreach ($recentNotes as $note): ?>
              <li>
                <div class="icon"><i class="bi bi-chat-dots"></i></div>
                <div class="content">
                  <strong><?=h($note['lead_name'])?></strong>
                  <span><?=nl2br(h($note['note']))?></span>
                  <small><?=h(date('d.m.Y H:i', strtotime($note['created_at'])))?></small>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="card-lite mb-4" id="crm-new">
    <div class="section-heading">
      <h5 class="mb-0">Yeni potansiyel kaydı</h5>
      <small class="text-muted">Yeni temasları hızlıca CRM'e ekleyin.</small>
    </div>
    <form method="post" class="create-lead-form">
      <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="do" value="create_lead">
      <input type="hidden" name="context_dealer_id" value="<?=$dealerId?>">
      <div>
        <label class="form-label">Ad Soyad</label>
        <input type="text" name="lead_name" class="form-control" required>
      </div>
      <div>
        <label class="form-label">E-posta</label>
        <input type="email" name="lead_email" class="form-control">
      </div>
      <div>
        <label class="form-label">Telefon</label>
        <input type="text" name="lead_phone" class="form-control">
      </div>
      <div>
        <label class="form-label">Firma / Kurum</label>
        <input type="text" name="lead_company" class="form-control">
      </div>
      <div>
        <label class="form-label">Durum</label>
        <select name="lead_status" class="form-select">
          <?php foreach ($leadStatusLabels as $key => $label): ?>
            <option value="<?=h($key)?>"><?=h($label)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Kaynak</label>
        <input type="text" name="lead_source" class="form-control" placeholder="Örn. Instagram, Telefon">
      </div>
      <div>
        <label class="form-label">Sonraki Aksiyon</label>
        <input type="datetime-local" name="lead_next_action" class="form-control">
      </div>
      <div class="full-span">
        <label class="form-label">Notlar</label>
        <textarea name="lead_notes" class="form-control" placeholder="Görüşmenin özetini yazın."></textarea>
      </div>
      <div class="full-span d-grid">
        <button type="submit" class="btn btn-primary">Potansiyeli Oluştur</button>
      </div>
    </form>
  </section>

  <section class="card-lite mb-4" id="crm-board">
    <div class="section-heading">
      <h5 class="mb-0">Potansiyel CRM panosu</h5>
      <small class="text-muted">Durumlara göre gruplanmış potansiyeller</small>
    </div>
    <?php if (!$leads): ?>
      <div class="empty-state">Bu bayi için henüz kayıtlı potansiyel müşteri bulunmuyor.</div>
    <?php else: ?>
      <div class="crm-board">
        <?php foreach ($leadColumns as $statusKey => $column): ?>
          <?php $columnClass = 'status-'.preg_replace('/[^a-z0-9]+/', '-', $statusKey); $statusLabel = $column['label']; $columnCount = count($column['leads']); ?>
          <div class="crm-column <?=$columnClass?>">
            <div class="crm-column-header">
              <h6><?=h($statusLabel)?></h6>
              <span class="count-badge"><?=$columnCount?></span>
            </div>
            <?php if (!$column['leads']): ?>
              <p class="text-muted small mb-0">Bu aşamada potansiyel yok.</p>
            <?php else: ?>
              <?php foreach ($column['leads'] as $lead): ?>
                <?php
                  $leadId = (int)$lead['id'];
                  $leadStatusKey = $lead['status'] ?? DEALER_LEAD_STATUS_NEW;
                  $leadStatusLabel = $leadStatusLabels[$leadStatusKey] ?? ucfirst($leadStatusKey);
                  $chipClass = 'status-chip--'.preg_replace('/[^a-z0-9]+/', '-', $leadStatusKey);
                  $nextActionValue = !empty($lead['next_action_at']) ? date('Y-m-d\\TH:i', strtotime($lead['next_action_at'])) : '';
                  $noteSlice = $leadNotesMap[$leadId] ?? [];
                ?>
                <div class="lead-card" id="lead-<?=$leadId?>">
                  <div>
                    <h6><?=h($lead['name'])?></h6>
                    <span class="status-chip <?=$chipClass?>"><?=h($leadStatusLabel)?></span>
                  </div>
                  <ul class="lead-meta">
                    <?php if (!empty($lead['email'])): ?><li><i class="bi bi-envelope"></i><span><?=h($lead['email'])?></span></li><?php endif; ?>
                    <?php if (!empty($lead['phone'])): ?><li><i class="bi bi-telephone"></i><span><?=h($lead['phone'])?></span></li><?php endif; ?>
                    <?php if (!empty($lead['company'])): ?><li><i class="bi bi-building"></i><span><?=h($lead['company'])?></span></li><?php endif; ?>
                    <?php if (!empty($lead['source'])): ?><li><i class="bi bi-bullseye"></i><span><?=h($lead['source'])?></span></li><?php endif; ?>
                  </ul>
                  <div class="lead-timestamps">
                    <?php if (!empty($lead['created_at'])): ?><span>Kaydedildi: <?=h(date('d.m.Y H:i', strtotime($lead['created_at'])))?></span><?php endif; ?>
                    <?php if (!empty($lead['last_contact_at'])): ?><span>Son görüşme: <?=h(date('d.m.Y H:i', strtotime($lead['last_contact_at'])))?></span><?php endif; ?>
                    <?php if (!empty($lead['next_action_at'])): ?><span>Sonraki aksiyon: <?=h(date('d.m.Y H:i', strtotime($lead['next_action_at'])))?></span><?php endif; ?>
                  </div>
                  <form method="post" class="lead-update-form">
                    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="do" value="update_lead">
                    <input type="hidden" name="lead_id" value="<?=$leadId?>">
                    <input type="hidden" name="context_dealer_id" value="<?=$dealerId?>">
                    <div>
                      <label class="form-label">Durum</label>
                      <select class="form-select" name="status">
                        <?php foreach ($leadStatusLabels as $key => $label): ?>
                          <option value="<?=h($key)?>" <?=$leadStatusKey === $key ? 'selected' : ''?>><?=h($label)?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div>
                      <label class="form-label">Sonraki Aksiyon</label>
                      <input type="datetime-local" class="form-control" name="next_action_at" value="<?=h($nextActionValue)?>">
                    </div>
                    <div class="full-span">
                      <label class="form-label">Genel Not</label>
                      <textarea class="form-control" name="notes" rows="2" placeholder="Potansiyel notunu güncelleyin."><?=h($lead['notes'] ?? '')?></textarea>
                    </div>
                    <div class="full-span d-grid">
                      <button class="btn btn-sm btn-outline-primary" type="submit">Kaydet</button>
                    </div>
                  </form>
                  <div>
                    <h6 class="fw-semibold fs-6 mb-2">Son Notlar</h6>
                    <?php if (!$noteSlice): ?>
                      <p class="text-muted small mb-2">Bu potansiyel için henüz not eklenmedi.</p>
                    <?php else: ?>
                      <ul class="note-timeline">
                        <?php foreach ($noteSlice as $note): ?>
                          <li>
                            <div class="fw-semibold mb-1"><?=h(date('d.m.Y H:i', strtotime($note['created_at'])))?></div>
                            <div><?=nl2br(h($note['note']))?></div>
                            <div class="meta">
                              <?php if (!empty($note['representative_name'])): ?><span><?=h($note['representative_name'])?></span><?php endif; ?>
                              <?php if (!empty($note['contact_type'])): ?><span><?=h($note['contact_type'])?></span><?php endif; ?>
                              <?php if (!empty($note['next_action_at'])): ?><span>Sonraki: <?=h(date('d.m.Y H:i', strtotime($note['next_action_at'])))?></span><?php endif; ?>
                            </div>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    <?php endif; ?>
                  </div>
                  <form method="post" class="note-form">
                    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="do" value="add_note">
                    <input type="hidden" name="lead_id" value="<?=$leadId?>">
                    <input type="hidden" name="context_dealer_id" value="<?=$dealerId?>">
                    <div class="mb-2">
                      <label class="form-label">Yeni Görüşme Notu</label>
                      <textarea class="form-control" name="note" required placeholder="Görüşmenin detaylarını yazın."></textarea>
                    </div>
                    <div class="row g-2">
                      <div class="col-sm-6">
                        <label class="form-label">Görüşme Tipi</label>
                        <input type="text" class="form-control" name="contact_type" placeholder="Örn. Telefon, Yüz yüze">
                      </div>
                      <div class="col-sm-6">
                        <label class="form-label">Sonraki Aksiyon</label>
                        <input type="datetime-local" class="form-control" name="next_action_at">
                      </div>
                    </div>
                    <div class="d-grid mt-3">
                      <button class="btn btn-sm btn-primary" type="submit">Not Ekle</button>
                    </div>
                  </form>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="card-lite mb-4" id="topups">
    <div class="section-heading">
      <h5 class="mb-0">Tamamlanan yüklemeler</h5>
      <small class="text-muted">Komisyon durumlarınızı takip edin.</small>
    </div>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead><tr><th>Tarih</th><th>Tutar</th><th>Komisyon</th><th>Durum</th></tr></thead>
        <tbody>
          <?php if (!$topups): ?>
            <tr><td colspan="4" class="text-center text-muted">Henüz tamamlanan yükleme bulunmuyor.</td></tr>
          <?php else: ?>
            <?php foreach ($topups as $topup): ?>
              <?php $isPaid = ($topup['commission_status'] ?? 'pending') === 'paid'; ?>
              <tr>
                <td><?=h($topup['completed_at'] ? date('d.m.Y H:i', strtotime($topup['completed_at'])) : date('d.m.Y H:i', strtotime($topup['created_at'])))?></td>
                <td><?=h(format_currency($topup['amount_cents']))?></td>
                <td><?=h(format_currency($topup['commission_cents']))?></td>
                <td><span class="badge-status <?=$isPaid ? 'status-paid' : 'status-pending'?>"><?=$isPaid ? 'Ödendi' : 'Bekliyor'?></span></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="card-lite" id="commissions-history">
    <div class="section-heading">
      <h5 class="mb-0">Komisyon hareketleri</h5>
    </div>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead><tr><th>Tarih</th><th>Yükleme Tutarı</th><th>Komisyon</th><th>Durum</th></tr></thead>
        <tbody>
          <?php if (!$commissionHistory): ?>
            <tr><td colspan="4" class="text-center text-muted">Henüz komisyon kaydı bulunmuyor.</td></tr>
          <?php else: ?>
            <?php foreach ($commissionHistory as $row): ?>
              <?php $isPaid = ($row['status'] ?? 'pending') === 'paid'; ?>
              <tr>
                <td><?=h(date('d.m.Y H:i', strtotime($row['created_at'])))?></td>
                <td><?=h(format_currency($row['topup_amount_cents']))?></td>
                <td><?=h(format_currency($row['commission_cents']))?></td>
                <td><span class="badge-status <?=$isPaid ? 'status-paid' : 'status-pending'?>"><?=$isPaid ? 'Ödendi' : 'Bekliyor'?></span></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endif; ?>

<?php representative_layout_end();
