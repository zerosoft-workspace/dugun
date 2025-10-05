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
$statusOptions = representative_crm_status_options();
$statusCounts = representative_crm_status_counts($representativeId);
$totalLeads = $statusCounts['total'] ?? 0;

$action = $_POST['do'] ?? '';
if ($action) {
  csrf_or_die();
  try {
    if ($action === 'create_lead') {
      $payload = [
        'name' => $_POST['lead_name'] ?? '',
        'email' => $_POST['lead_email'] ?? '',
        'phone' => $_POST['lead_phone'] ?? '',
        'company' => $_POST['lead_company'] ?? '',
        'status' => $_POST['lead_status'] ?? REP_LEAD_STATUS_NEW,
        'source' => $_POST['lead_source'] ?? '',
        'notes' => $_POST['lead_notes'] ?? '',
        'next_action_at' => $_POST['lead_next_action'] ?? null,
        'potential_value_cents' => $_POST['lead_value'] ?? null,
      ];
      $leadId = representative_crm_lead_create($representativeId, $payload);
      flash('ok', 'Potansiyel müşteri kaydedildi.');
      redirect('crm.php#lead-'.$leadId);
    }
    if ($action === 'update_lead') {
      $leadId = (int)($_POST['lead_id'] ?? 0);
      if ($leadId <= 0) {
        throw new RuntimeException('Potansiyel müşteri bulunamadı.');
      }
      $updatePayload = [
        'status' => $_POST['status'] ?? REP_LEAD_STATUS_NEW,
        'next_action_at' => $_POST['next_action_at'] ?? null,
      ];
      if (array_key_exists('potential_value', $_POST)) {
        $rawValue = trim((string)($_POST['potential_value'] ?? ''));
        $updatePayload['potential_value_cents'] = $rawValue === '' ? null : $rawValue;
      }
      representative_crm_lead_update($leadId, $representativeId, $updatePayload);
      flash('ok', 'Potansiyel müşteri güncellendi.');
      redirect('crm.php#lead-'.$leadId);
    }
    if ($action === 'add_note') {
      $leadId = (int)($_POST['lead_id'] ?? 0);
      if ($leadId <= 0) {
        throw new RuntimeException('Potansiyel müşteri bulunamadı.');
      }
      $note = trim($_POST['note'] ?? '');
      $contactType = trim($_POST['contact_type'] ?? '');
      $nextAction = $_POST['next_action_at'] ?? null;
      representative_crm_lead_add_note($leadId, $representativeId, $note, $contactType !== '' ? $contactType : null, $nextAction);
      flash('ok', 'Görüşme notu kaydedildi.');
      redirect('crm.php#lead-'.$leadId);
    }
  } catch (Throwable $e) {
    flash('err', $e->getMessage());
    redirect('crm.php');
  }
}

$leads = representative_crm_leads($representativeId);
$leadNotesMap = [];
foreach ($leads as $lead) {
  $leadNotesMap[(int)$lead['id']] = array_slice(representative_crm_lead_notes((int)$lead['id'], $representativeId), 0, 4);
}

$leadColumns = [];
foreach ($statusOptions as $key => $label) {
  $leadColumns[$key] = ['label' => $label, 'leads' => []];
}
foreach ($leads as $lead) {
  $statusKey = $lead['status'] ?? REP_LEAD_STATUS_NEW;
  if (!isset($leadColumns[$statusKey])) {
    $leadColumns[$statusKey] = ['label' => $statusOptions[$statusKey] ?? ucfirst($statusKey), 'leads' => []];
  }
  $leadColumns[$statusKey]['leads'][] = $lead;
}

$pageStyles = <<<'CSS'
<style>
  .crm-header {display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1.2rem;margin-bottom:1.8rem;}
  .crm-card {border-radius:18px;padding:1.4rem 1.5rem;background:linear-gradient(145deg,rgba(14,165,181,.14),rgba(255,255,255,.95));border:1px solid rgba(148,163,184,.2);box-shadow:0 24px 56px -40px rgba(14,116,144,.42);}
  .crm-card span {display:block;font-size:.78rem;text-transform:uppercase;letter-spacing:.1em;color:var(--rep-muted);font-weight:600;}
  .crm-card strong {font-size:1.8rem;font-weight:700;color:var(--rep-ink);}
  .crm-card small {display:block;font-size:.82rem;color:#475569;margin-top:.3rem;}
  .card-lite {border-radius:20px;background:var(--rep-surface);border:1px solid rgba(148,163,184,.18);box-shadow:0 26px 60px -42px rgba(15,23,42,.4);padding:1.9rem;}
  .card-lite + .card-lite {margin-top:1.5rem;}
  .create-lead-form {display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;}
  .create-lead-form .full-span {grid-column:1 / -1;}
  .create-lead-form .form-control,.create-lead-form .form-select {border-radius:12px;font-size:.9rem;border-color:rgba(148,163,184,.35);}
  .crm-board {display:grid;grid-auto-flow:column;grid-auto-columns:minmax(280px,1fr);gap:1.2rem;overflow-x:auto;padding-bottom:.5rem;margin-bottom:-.5rem;}
  .crm-column {border-radius:18px;background:#f8fafc;border:1px solid rgba(148,163,184,.2);padding:1.2rem;display:flex;flex-direction:column;gap:.85rem;min-height:320px;}
  .crm-column h6 {font-size:.85rem;font-weight:700;margin:0;color:#334155;letter-spacing:.08em;text-transform:uppercase;}
  .lead-card {border-radius:16px;background:#fff;border:1px solid rgba(148,163,184,.24);box-shadow:0 20px 48px -36px rgba(15,23,42,.35);padding:1rem;display:flex;flex-direction:column;gap:.75rem;}
  .lead-card .lead-meta {display:flex;justify-content:space-between;align-items:center;gap:.75rem;}
  .lead-card .lead-meta strong {font-size:1.05rem;color:var(--rep-ink);}
  .lead-card .lead-meta small {color:#64748b;font-size:.78rem;}
  .lead-card .lead-info {display:flex;flex-direction:column;gap:.25rem;font-size:.85rem;color:#475569;}
  .lead-card .lead-info span i {margin-right:.35rem;color:var(--rep-brand-dark);}
  .lead-card .lead-actions form {display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem;}
  .lead-card .lead-actions .form-control,.lead-card .lead-actions .form-select {border-radius:10px;font-size:.82rem;}
  .lead-card .lead-notes {border-top:1px solid rgba(148,163,184,.2);padding-top:.75rem;display:flex;flex-direction:column;gap:.6rem;}
  .lead-card .lead-notes li {list-style:none;font-size:.82rem;color:#475569;}
  .lead-card .lead-notes time {display:block;font-size:.75rem;color:#94a3b8;margin-top:.2rem;}
  .lead-card .note-form textarea {border-radius:10px;font-size:.82rem;min-height:100px;border-color:rgba(148,163,184,.35);}
  .lead-card .note-form .form-select {border-radius:10px;font-size:.82rem;}
  .lead-card .note-form .btn {border-radius:10px;}
  @media (max-width: 768px) {
    .crm-board {grid-auto-columns:minmax(260px,1fr);}
    .card-lite {padding:1.6rem;}
  }
</style>
CSS;

representative_layout_start([
  'page_title' => APP_NAME.' — Temsilci CRM',
  'header_title' => 'CRM Yönetimi',
  'header_subtitle' => 'Potansiyel müşteri kayıtlarınızı yönetin, aksiyonları planlayın ve görüşme notları tutun.',
  'representative' => $representative,
  'active_nav' => 'crm',
  'extra_head' => $pageStyles,
]);
?>

<?=flash_messages()?>

<div class="crm-header">
  <div class="crm-card">
    <span>Toplam Kayıt</span>
    <strong><?=h($totalLeads)?></strong>
    <small>CRM üzerinde toplam potansiyel müşteri sayısı.</small>
  </div>
  <div class="crm-card">
    <span>Yeni Oluştur</span>
    <strong>CRM Girişi</strong>
    <small>Yeni bir potansiyel müşteri eklemek için formu kullanın.</small>
  </div>
</div>

<section class="card-lite mb-4">
  <div class="section-heading">
    <h5>Yeni Potansiyel Müşteri</h5>
    <small>Temel bilgileri doldurun, dilerseniz ilk görüşme notunu ekleyin.</small>
  </div>
  <form method="post" class="create-lead-form">
    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="do" value="create_lead">
    <div>
      <label class="form-label">Ad Soyad*</label>
      <input class="form-control" name="lead_name" required>
    </div>
    <div>
      <label class="form-label">E-posta</label>
      <input type="email" class="form-control" name="lead_email">
    </div>
    <div>
      <label class="form-label">Telefon</label>
      <input class="form-control" name="lead_phone">
    </div>
    <div>
      <label class="form-label">Şirket / Proje</label>
      <input class="form-control" name="lead_company">
    </div>
    <div>
      <label class="form-label">Durum</label>
      <select class="form-select" name="lead_status">
        <?php foreach ($statusOptions as $value => $label): ?>
          <option value="<?=h($value)?>" <?=$value === REP_LEAD_STATUS_NEW ? 'selected' : ''?>><?=h($label)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label">Kaynak</label>
      <input class="form-control" name="lead_source" placeholder="Örn. referans, etkinlik, web sitesi">
    </div>
    <div>
      <label class="form-label">Tahmini Değer (₺)</label>
      <input type="number" min="0" step="100" class="form-control" name="lead_value" placeholder="Opsiyonel">
    </div>
    <div>
      <label class="form-label">Takip Tarihi</label>
      <input type="datetime-local" class="form-control" name="lead_next_action">
    </div>
    <div class="full-span">
      <label class="form-label">Notlar</label>
      <textarea class="form-control" name="lead_notes" placeholder="İlk görüşme veya ihtiyaç bilgilerini ekleyin."></textarea>
    </div>
    <div class="full-span d-grid">
      <button class="btn btn-brand" type="submit">Potansiyel Müşteri Ekle</button>
    </div>
  </form>
</section>

<section class="card-lite">
  <div class="section-heading">
    <h5>CRM Panosu</h5>
    <small><?=h($totalLeads)?> kayıt görüntüleniyor</small>
  </div>
  <div class="crm-board">
    <?php foreach ($leadColumns as $statusKey => $column): ?>
      <div class="crm-column" id="column-<?=h($statusKey)?>">
        <h6><?=h($column['label'])?> · <?=h(count($column['leads']))?></h6>
        <?php if (empty($column['leads'])): ?>
          <p class="text-muted mb-0">Bu aşamada potansiyel müşteri bulunmuyor.</p>
        <?php else: ?>
          <?php foreach ($column['leads'] as $lead): ?>
            <?php $leadId = (int)$lead['id']; ?>
            <article class="lead-card" id="lead-<?=$leadId?>">
              <div class="lead-meta">
                <strong><?=h($lead['name'])?></strong>
                <small><?= $lead['created_at'] ? h(date('d.m.Y', strtotime($lead['created_at']))) : '—' ?></small>
              </div>
              <div class="lead-info">
                <?php if (!empty($lead['company'])): ?><span><i class="bi bi-building"></i><?=h($lead['company'])?></span><?php endif; ?>
                <?php if (!empty($lead['email'])): ?><span><i class="bi bi-envelope"></i><a href="mailto:<?=h($lead['email'])?>"><?=h($lead['email'])?></a></span><?php endif; ?>
                <?php if (!empty($lead['phone'])): ?><span><i class="bi bi-telephone"></i><a href="tel:<?=h($lead['phone'])?>"><?=h($lead['phone'])?></a></span><?php endif; ?>
                <?php if (!empty($lead['next_action_at'])): ?><span><i class="bi bi-clock-history"></i><?=h(date('d.m.Y H:i', strtotime($lead['next_action_at'])))?> · Sonraki adım</span><?php endif; ?>
                <?php if (!empty($lead['potential_value_cents'])): ?><span><i class="bi bi-cash-coin"></i><?=h(format_currency($lead['potential_value_cents']))?> potansiyel</span><?php endif; ?>
              </div>
              <div class="lead-actions">
                <form method="post">
                  <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="do" value="update_lead">
                  <input type="hidden" name="lead_id" value="<?=$leadId?>">
                  <div>
                    <label class="form-label">Durum</label>
                    <select class="form-select" name="status">
                      <?php foreach ($statusOptions as $value => $label): ?>
                        <option value="<?=h($value)?>" <?=$value === ($lead['status'] ?? REP_LEAD_STATUS_NEW) ? 'selected' : ''?>><?=h($label)?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div>
                    <label class="form-label">Takip Tarihi</label>
                    <input type="datetime-local" class="form-control" name="next_action_at" value="<?= $lead['next_action_at'] ? h(date('Y-m-d\TH:i', strtotime($lead['next_action_at']))) : '' ?>">
                  </div>
                  <div>
                    <label class="form-label">Tahmini Değer</label>
                    <input type="number" min="0" step="100" class="form-control" name="potential_value" value="<?= $lead['potential_value_cents'] ? h((int)$lead['potential_value_cents']) : '' ?>">
                  </div>
                  <div class="d-grid align-items-end">
                    <button class="btn btn-outline-brand" type="submit">Kaydet</button>
                  </div>
                </form>
              </div>
              <div class="lead-notes">
                <h6 class="fw-semibold text-muted mb-2">Son Notlar</h6>
                <?php if (empty($leadNotesMap[$leadId])): ?>
                  <p class="text-muted mb-2">Henüz not eklenmemiş.</p>
                <?php else: ?>
                  <?php foreach ($leadNotesMap[$leadId] as $note): ?>
                    <li>
                      <?=h(mb_strimwidth($note['note'], 0, 160, '...', 'UTF-8'))?>
                      <?php if (!empty($note['contact_type'])): ?> · <span class="text-muted"><?=h($note['contact_type'])?></span><?php endif; ?>
                      <time><?= $note['created_at'] ? h(date('d.m.Y H:i', strtotime($note['created_at']))) : '' ?></time>
                    </li>
                  <?php endforeach; ?>
                <?php endif; ?>
                <form method="post" class="note-form mt-3">
                  <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="do" value="add_note">
                  <input type="hidden" name="lead_id" value="<?=$leadId?>">
                  <div class="mb-2">
                    <label class="form-label">Görüşme Notu</label>
                    <textarea class="form-control" name="note" required></textarea>
                  </div>
                  <div class="row g-2 align-items-end">
                    <div class="col-md-5">
                      <label class="form-label">İletişim Türü</label>
                      <select class="form-select" name="contact_type">
                        <option value="">Seçiniz</option>
                        <option value="Telefon">Telefon</option>
                        <option value="E-posta">E-posta</option>
                        <option value="Toplantı">Toplantı</option>
                        <option value="Demo">Demo</option>
                      </select>
                    </div>
                    <div class="col-md-5">
                      <label class="form-label">Sonraki Aksiyon</label>
                      <input type="datetime-local" class="form-control" name="next_action_at">
                    </div>
                    <div class="col-md-2 d-grid">
                      <button class="btn btn-brand" type="submit">Ekle</button>
                    </div>
                  </div>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<?php representative_layout_end();
