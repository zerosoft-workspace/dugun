<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/dealers.php';
require_once __DIR__.'/../includes/representatives.php';
require_once __DIR__.'/../includes/dealer_crm.php';
require_once __DIR__.'/../includes/dealer_auth.php';
require_once __DIR__.'/partials/ui.php';

install_schema();

dealer_require_login();
$sessionDealer = dealer_user();
$dealer = dealer_get((int)$sessionDealer['id']);
if (!$dealer) {
  dealer_logout();
  redirect('login.php');
}

$dealerId = (int)$dealer['id'];
dealer_refresh_session($dealerId);
$representative = representative_for_dealer($dealerId);

$action = $_POST['do'] ?? '';
if ($action) {
  csrf_or_die();
}

try {
  if ($action === 'create_lead') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $source = trim($_POST['source'] ?? '');
    $status = $_POST['status'] ?? DEALER_LEAD_STATUS_NEW;
    $notes = trim($_POST['notes'] ?? '');
    $nextAction = $_POST['next_action_at'] ?? null;
    $noteText = trim($_POST['note_text'] ?? '');
    $contactType = trim($_POST['note_contact_type'] ?? '');

    $leadId = dealer_lead_create($dealerId, [
      'name' => $name,
      'email' => $email,
      'phone' => $phone,
      'company' => $company,
      'source' => $source,
      'status' => $status,
      'notes' => $notes,
      'next_action_at' => $nextAction,
    ], $representative['id'] ?? null);

    if ($noteText !== '') {
      dealer_lead_add_note($leadId, $dealerId, $noteText, $contactType ?: null, $nextAction, $representative['id'] ?? null);
    }

    flash('ok', 'Potansiyel müşteri kaydedildi.');
    redirect('leads.php#lead-'.$leadId);
  }

  if ($action === 'update_lead') {
    $leadId = (int)($_POST['lead_id'] ?? 0);
    if ($leadId <= 0) {
      throw new RuntimeException('Potansiyel müşteri bulunamadı.');
    }
    dealer_lead_update($leadId, $dealerId, [
      'name' => $_POST['name'] ?? '',
      'email' => $_POST['email'] ?? '',
      'phone' => $_POST['phone'] ?? '',
      'company' => $_POST['company'] ?? '',
      'source' => $_POST['source'] ?? '',
      'status' => $_POST['status'] ?? '',
      'notes' => $_POST['notes'] ?? '',
      'next_action_at' => $_POST['next_action_at'] ?? null,
    ]);
    flash('ok', 'Potansiyel müşteri bilgileri güncellendi.');
    redirect('leads.php#lead-'.$leadId);
  }

  if ($action === 'add_note') {
    $leadId = (int)($_POST['lead_id'] ?? 0);
    if ($leadId <= 0) {
      throw new RuntimeException('Potansiyel müşteri bulunamadı.');
    }
    $note = trim($_POST['note'] ?? '');
    $contactType = trim($_POST['contact_type'] ?? '');
    $nextAction = $_POST['next_action_at'] ?? null;
    dealer_lead_add_note($leadId, $dealerId, $note, $contactType ?: null, $nextAction, $representative['id'] ?? null);
    flash('ok', 'Görüşme notu eklendi.');
    redirect('leads.php#lead-'.$leadId);
  }
} catch (Throwable $e) {
  flash('err', $e->getMessage());
  redirect('leads.php');
}

$balance = dealer_get_balance($dealerId);
$venuesNav = dealer_fetch_venues($dealerId);
$refCode = $dealer['code'] ?: dealer_ensure_identifier($dealerId);
$licenseLabel = dealer_license_label($dealer);

$leadStats = dealer_lead_status_counts($dealerId);
$leadStatusLabels = dealer_lead_status_options();
$leadTotal = array_sum($leadStats);
$leads = dealer_leads_list($dealerId);
$upcomingActions = dealer_lead_upcoming_actions($dealerId, 5);
$recentNotes = dealer_lead_recent_notes($dealerId, 5);

$leadNotesMap = [];
foreach ($leads as $lead) {
  $leadNotesMap[$lead['id']] = dealer_lead_notes($lead['id'], $dealerId);
}

$pageStyles = <<<'CSS'
<style>
  .card-lite{border-radius:22px;background:#fff;border:1px solid rgba(148,163,184,.14);box-shadow:0 24px 50px -34px rgba(15,23,42,.45);}
  .lead-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-bottom:2rem;}
  .lead-stat{border:1px solid rgba(148,163,184,.25);border-radius:18px;padding:1rem;background:linear-gradient(150deg,#fff,rgba(14,165,181,.08));box-shadow:0 18px 35px -30px rgba(15,23,42,.4);}
  .lead-stat.highlight{background:linear-gradient(160deg,#0ea5b5,#6366f1);color:#fff;border:none;}
  .lead-stat span{display:block;font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;color:rgba(15,23,42,.6);margin-bottom:.35rem;font-weight:600;}
  .lead-stat.highlight span{color:rgba(255,255,255,.82);}
  .lead-stat strong{font-size:1.5rem;color:#0f172a;}
  .lead-stat.highlight strong{color:#fff;}
  .lead-card{border-radius:22px;background:#fff;border:1px solid rgba(148,163,184,.18);box-shadow:0 24px 50px -34px rgba(15,23,42,.45);padding:1.8rem;}
  .lead-card + .lead-card{margin-top:1.5rem;}
  .lead-header{display:flex;flex-wrap:wrap;gap:1rem;justify-content:space-between;align-items:flex-start;margin-bottom:1.2rem;}
  .lead-header h5{margin:0;font-weight:700;}
  .lead-tags{display:flex;flex-wrap:wrap;gap:.5rem;font-size:.85rem;color:#64748b;}
  .lead-tags span{display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .6rem;border-radius:999px;background:rgba(14,165,181,.12);color:#0b8b98;font-weight:600;}
  .lead-notes{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:1rem;}
  .lead-notes li{border:1px solid rgba(148,163,184,.22);border-radius:16px;padding:1rem;background:#f8fafc;}
  .lead-notes .meta{display:flex;flex-wrap:wrap;gap:.6rem;font-size:.75rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;}
  .note-form textarea{min-height:120px;}
  .lead-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:2rem;}
  .crm-overview ul{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:.75rem;}
  .crm-overview li{display:flex;gap:.75rem;align-items:flex-start;}
  .crm-overview .dot{width:36px;height:36px;border-radius:12px;background:rgba(14,165,181,.12);color:#0b8b98;display:flex;align-items:center;justify-content:center;font-size:1rem;}
  .crm-overview .content strong{display:block;font-weight:600;color:#0f172a;}
  .crm-overview .content span{display:block;font-size:.82rem;color:#64748b;}
  .lead-actions .btn{font-size:.85rem;}
  .lead-meta-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-bottom:1.2rem;}
  .lead-meta-grid .item{border:1px solid rgba(148,163,184,.22);border-radius:16px;padding:1rem;background:#f8fafc;}
  .lead-meta-grid .item span{display:block;font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;color:#64748b;margin-bottom:.3rem;}
  .lead-meta-grid .item strong{font-size:1rem;color:#0f172a;}
  @media (max-width: 767px){
    .lead-header{flex-direction:column;align-items:flex-start;}
  }
</style>
CSS;

dealer_layout_start('leads', [
  'page_title'   => APP_NAME.' — Potansiyel Müşteriler',
  'title'        => 'Potansiyel Müşteriler & CRM',
  'subtitle'     => 'Yeni potansiyeller ekleyin, görüşme notları tutun ve sonraki adımlarınızı planlayın.',
  'dealer'       => $dealer,
  'representative' => $representative,
  'venues'       => $venuesNav,
  'balance_text' => format_currency($balance),
  'license_text' => $licenseLabel,
  'ref_code'     => $refCode,
  'extra_head'   => $pageStyles,
]);
?>
<section class="mb-4">
  <div class="card-lite p-4">
    <div class="lead-stats">
      <div class="lead-stat highlight">
        <span>Toplam Potansiyel</span>
        <strong><?=$leadTotal?></strong>
      </div>
      <?php foreach ($leadStats as $statusKey => $count): ?>
        <?php $label = $leadStatusLabels[$statusKey] ?? ucfirst($statusKey); ?>
        <div class="lead-stat">
          <span><?=h($label)?></span>
          <strong><?= (int)$count ?></strong>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="row g-4 crm-overview">
      <div class="col-md-6">
        <h6 class="fw-semibold mb-2">Yaklaşan Aksiyonlar</h6>
        <?php if (!$upcomingActions): ?>
          <p class="text-muted small mb-0">Yaklaşan aksiyon bulunmuyor.</p>
        <?php else: ?>
          <ul>
            <?php foreach ($upcomingActions as $item): ?>
              <li>
                <div class="dot"><i class="bi bi-calendar-event"></i></div>
                <div class="content">
                  <strong><?=h($item['name'])?></strong>
                  <span><?=h(date('d.m.Y H:i', strtotime($item['next_action_at'])))?></span>
                  <span class="badge bg-light text-dark mt-2"><?=h($leadStatusLabels[$item['status']] ?? ucfirst($item['status']))?></span>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
      <div class="col-md-6">
        <h6 class="fw-semibold mb-2">Son Görüşme Notları</h6>
        <?php if (!$recentNotes): ?>
          <p class="text-muted small mb-0">Henüz not eklenmedi.</p>
        <?php else: ?>
          <ul>
            <?php foreach ($recentNotes as $note): ?>
              <li>
                <div class="dot"><i class="bi bi-chat-dots"></i></div>
                <div class="content">
                  <strong><?=h($note['lead_name'])?></strong>
                  <span><?=nl2br(h($note['note']))?></span>
                  <span class="badge bg-light text-dark mt-2"><?=h(date('d.m.Y H:i', strtotime($note['created_at'])))?></span>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<section class="card-lite p-4 mb-4" id="new">
  <div class="mb-3">
    <h5 class="mb-1">Yeni Potansiyel Müşteri</h5>
    <p class="text-muted mb-0">Temsilciniz bu kaydı görecek ve yüklemelerinizden %10 komisyon kazanmaya devam edecek.</p>
  </div>
  <form method="post" class="row g-3">
    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="do" value="create_lead">
    <div class="col-md-4">
      <label class="form-label">Ad Soyad</label>
      <input type="text" class="form-control" name="name" required>
    </div>
    <div class="col-md-4">
      <label class="form-label">E-posta</label>
      <input type="email" class="form-control" name="email" placeholder="ornek@musteri.com">
    </div>
    <div class="col-md-4">
      <label class="form-label">Telefon</label>
      <input type="text" class="form-control" name="phone" placeholder="5xx xxx xx xx">
    </div>
    <div class="col-md-4">
      <label class="form-label">Firma / Etkinlik</label>
      <input type="text" class="form-control" name="company" placeholder="Etkinlik veya şirket adı">
    </div>
    <div class="col-md-4">
      <label class="form-label">Kaynak</label>
      <input type="text" class="form-control" name="source" placeholder="Örn. Fuar, Referans">
    </div>
    <div class="col-md-4">
      <label class="form-label">Durum</label>
      <select class="form-select" name="status">
        <?php foreach ($leadStatusLabels as $key => $label): ?>
          <option value="<?=h($key)?>"><?=h($label)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-6">
      <label class="form-label">Genel Not</label>
      <textarea class="form-control" name="notes" rows="3" placeholder="Ön bilgiler"></textarea>
    </div>
    <div class="col-md-6">
      <label class="form-label">Sonraki Aksiyon Tarihi</label>
      <input type="datetime-local" class="form-control" name="next_action_at">
    </div>
    <div class="col-12">
      <label class="form-label">İlk Görüşme Notu</label>
      <textarea class="form-control" name="note_text" rows="3" placeholder="İlk görüşme notunu yazın"></textarea>
    </div>
    <div class="col-md-6">
      <label class="form-label">Görüşme Tipi</label>
      <input type="text" class="form-control" name="note_contact_type" placeholder="Örn. Telefon, Yüz yüze">
    </div>
    <div class="col-12 d-flex justify-content-end">
      <button class="btn btn-brand" type="submit">Potansiyeli Kaydet</button>
    </div>
  </form>
</section>

<?php flash_box(); ?>

<?php if (!$leads): ?>
  <div class="card-lite p-4 text-center text-muted">Henüz kayıtlı potansiyel müşteriniz bulunmuyor.</div>
<?php else: ?>
  <?php foreach ($leads as $lead): ?>
    <?php
      $leadId = (int)$lead['id'];
      $statusLabel = $leadStatusLabels[$lead['status']] ?? ucfirst($lead['status']);
      $nextActionValue = $lead['next_action_at'] ? date('Y-m-d\TH:i', strtotime($lead['next_action_at'])) : '';
      $leadNotes = $leadNotesMap[$leadId] ?? [];
    ?>
    <div class="lead-card" id="lead-<?=$leadId?>">
      <div class="lead-header">
        <div>
          <h5 class="mb-1"><?=h($lead['name'])?></h5>
          <div class="lead-tags">
            <span><i class="bi bi-tag"></i><?=h($statusLabel)?></span>
            <?php if (!empty($lead['source'])): ?><span><i class="bi bi-bullseye"></i><?=h($lead['source'])?></span><?php endif; ?>
            <?php if (!empty($lead['created_at'])): ?><span><i class="bi bi-clock-history"></i><?=h(date('d.m.Y H:i', strtotime($lead['created_at'])))?></span><?php endif; ?>
          </div>
        </div>
        <div class="lead-actions d-flex flex-column align-items-end gap-2">
          <a class="btn btn-sm btn-outline-brand" href="#new">Yeni Potansiyel Ekle</a>
          <a class="btn btn-sm btn-outline-brand" href="leads.php#new">CRM Özeti</a>
        </div>
      </div>
      <form method="post" class="row g-3 mb-4">
        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="do" value="update_lead">
        <input type="hidden" name="lead_id" value="<?=$leadId?>">
        <div class="col-md-3">
          <label class="form-label">Ad Soyad</label>
          <input type="text" class="form-control" name="name" value="<?=h($lead['name'])?>" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">E-posta</label>
          <input type="email" class="form-control" name="email" value="<?=h($lead['email'] ?? '')?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Telefon</label>
          <input type="text" class="form-control" name="phone" value="<?=h($lead['phone'] ?? '')?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Firma / Etkinlik</label>
          <input type="text" class="form-control" name="company" value="<?=h($lead['company'] ?? '')?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Kaynak</label>
          <input type="text" class="form-control" name="source" value="<?=h($lead['source'] ?? '')?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Durum</label>
          <select class="form-select" name="status">
            <?php foreach ($leadStatusLabels as $key => $label): ?>
              <option value="<?=h($key)?>" <?= $lead['status'] === $key ? 'selected' : '' ?>><?=h($label)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Sonraki Aksiyon</label>
          <input type="datetime-local" class="form-control" name="next_action_at" value="<?=h($nextActionValue)?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Genel Not</label>
          <textarea class="form-control" name="notes" rows="2"><?=h($lead['notes'] ?? '')?></textarea>
        </div>
        <div class="col-12 d-flex justify-content-end">
          <button class="btn btn-brand" type="submit">Kaydet</button>
        </div>
      </form>
      <div class="row g-4">
        <div class="col-lg-5">
          <div class="lead-meta-grid">
            <div class="item">
              <span>E-posta</span>
              <strong><?= $lead['email'] ? h($lead['email']) : '—' ?></strong>
            </div>
            <div class="item">
              <span>Telefon</span>
              <strong><?= $lead['phone'] ? h($lead['phone']) : '—' ?></strong>
            </div>
            <div class="item">
              <span>Firma / Etkinlik</span>
              <strong><?= $lead['company'] ? h($lead['company']) : '—' ?></strong>
            </div>
            <div class="item">
              <span>Son Görüşme</span>
              <strong><?= $lead['last_contact_at'] ? h(date('d.m.Y H:i', strtotime($lead['last_contact_at']))) : '—' ?></strong>
            </div>
          </div>
        </div>
        <div class="col-lg-7">
          <div class="note-form mb-3">
            <form method="post" class="row g-3">
              <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="do" value="add_note">
              <input type="hidden" name="lead_id" value="<?=$leadId?>">
              <div class="col-12">
                <label class="form-label">Yeni Görüşme Notu</label>
                <textarea class="form-control" name="note" required placeholder="Görüşme detaylarını yazın"></textarea>
              </div>
              <div class="col-md-6">
                <label class="form-label">Görüşme Tipi</label>
                <input type="text" class="form-control" name="contact_type" placeholder="Örn. Telefon, Yüz yüze">
              </div>
              <div class="col-md-6">
                <label class="form-label">Sonraki Aksiyon</label>
                <input type="datetime-local" class="form-control" name="next_action_at">
              </div>
              <div class="col-12 d-flex justify-content-end">
                <button class="btn btn-outline-brand" type="submit">Not Ekle</button>
              </div>
            </form>
          </div>
          <?php if (!$leadNotes): ?>
            <p class="text-muted small mb-0">Bu potansiyel için henüz görüşme notu eklenmedi.</p>
          <?php else: ?>
            <ul class="lead-notes">
              <?php foreach ($leadNotes as $note): ?>
                <li>
                  <div class="fw-semibold mb-1"><?=h(date('d.m.Y H:i', strtotime($note['created_at'])))?></div>
                  <div class="mb-2"><?=nl2br(h($note['note']))?></div>
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
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php dealer_layout_end(); ?>
