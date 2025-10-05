<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/dealers.php';
require_once __DIR__.'/../includes/representatives.php';
require_once __DIR__.'/../includes/dealer_crm.php';
require_once __DIR__.'/../includes/representative_auth.php';

install_schema();

representative_require_login();
$user = representative_user();
$representative = representative_get((int)$user['id']);
if (!$representative) {
  representative_logout();
  redirect('login.php');
}

$dealer = dealer_get((int)$representative['dealer_id']);
if (!$dealer) {
  representative_logout();
  flash('err', 'Atandığınız bayi bulunamadı.');
  redirect('login.php');
}

$commissionTotals = representative_commission_totals((int)$representative['id']);
$topups = representative_completed_topups((int)$representative['id'], 20);
$commissionHistory = representative_recent_commissions((int)$representative['id'], 10);
$leadStats = dealer_lead_status_counts((int)$dealer['id']);
$leadStatusLabels = dealer_lead_status_options();
$upcomingActions = dealer_lead_upcoming_actions((int)$dealer['id'], 5);
$recentNotes = dealer_lead_recent_notes((int)$dealer['id'], 5);
$leads = dealer_leads_list((int)$dealer['id']);
$leadNotesMap = [];
foreach ($leads as $lead) {
  $notes = dealer_lead_notes((int)$lead['id'], (int)$dealer['id']);
  $leadNotesMap[(int)$lead['id']] = array_slice($notes, 0, 3);
}

$action = $_POST['do'] ?? '';
if ($action) {
  csrf_or_die();
  try {
    if ($action === 'update_lead') {
      $leadId = (int)($_POST['lead_id'] ?? 0);
      if ($leadId <= 0) {
        throw new RuntimeException('Potansiyel müşteri bulunamadı.');
      }
      dealer_lead_update($leadId, (int)$dealer['id'], [
        'status' => $_POST['status'] ?? '',
        'next_action_at' => $_POST['next_action_at'] ?? null,
        'notes' => $_POST['notes'] ?? null,
      ]);
      flash('ok', 'Potansiyel müşteri bilgileri güncellendi.');
      redirect('dashboard.php#lead-'.$leadId);
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
      redirect('dashboard.php#lead-'.$leadId);
    }
  } catch (Throwable $e) {
    flash('err', $e->getMessage());
    redirect('dashboard.php');
  }
}

$pageStyles = <<<'CSS'
<style>
  body{background:#f4f6fb;font-family:'Inter','Segoe UI',system-ui,sans-serif;color:#0f172a;}
  .rep-shell{max-width:1100px;margin:0 auto;padding:2.5rem 1.5rem;}
  .card-lite{border-radius:22px;background:#fff;border:1px solid rgba(148,163,184,.18);box-shadow:0 24px 50px -36px rgba(15,23,42,.42);padding:1.8rem;}
  .card-lite + .card-lite{margin-top:1.6rem;}
  .summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;}
  .summary-item{border-radius:18px;padding:1.1rem;background:linear-gradient(150deg,#fff,rgba(14,165,181,.08));border:1px solid rgba(148,163,184,.22);box-shadow:0 18px 36px -32px rgba(15,23,42,.4);}
  .summary-item span{display:block;font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;color:#64748b;margin-bottom:.35rem;font-weight:600;}
  .summary-item strong{font-size:1.4rem;color:#0f172a;display:block;}
  .table thead{background:#f8fafc;}
  .table th{font-size:.78rem;text-transform:uppercase;letter-spacing:.08em;color:#64748b;border-bottom:none;}
  .badge-status{display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .7rem;border-radius:999px;font-size:.75rem;font-weight:600;}
  .status-pending{background:rgba(250,204,21,.16);color:#854d0e;}
  .status-paid{background:rgba(34,197,94,.18);color:#166534;}
  .crm-overview{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-top:1.5rem;}
  .crm-block{border:1px solid rgba(148,163,184,.22);border-radius:18px;padding:1.2rem;background:#f9fbfd;}
  .crm-block ul{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:1rem;}
  .crm-block li{display:flex;gap:.9rem;align-items:flex-start;}
  .crm-block .dot{width:36px;height:36px;border-radius:12px;background:rgba(14,165,181,.12);color:#0b8b98;display:flex;align-items:center;justify-content:center;font-size:1rem;}
  .crm-block .content strong{display:block;font-weight:600;color:#0f172a;}
  .crm-block .content span{display:block;font-size:.82rem;color:#64748b;}
  .crm-block .content small{display:inline-block;margin-top:.3rem;font-size:.72rem;color:#475569;background:#e2f4f7;border-radius:999px;padding:.2rem .6rem;letter-spacing:.05em;text-transform:uppercase;}
  .header{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;}
  .header h1{font-size:1.6rem;font-weight:700;margin:0;}
  .dealer-tag{display:inline-flex;align-items:center;gap:.45rem;padding:.4rem .85rem;border-radius:999px;background:rgba(14,165,181,.12);color:#0b8b98;font-weight:600;}
  .lead-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1.2rem;}
  .lead-card{border:1px solid rgba(148,163,184,.24);border-radius:18px;padding:1.2rem;background:#f9fbfd;box-shadow:0 18px 40px -34px rgba(15,23,42,.4);display:flex;flex-direction:column;gap:1rem;}
  .lead-card h6{margin:0;font-weight:700;color:#0f172a;}
  .lead-meta{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:.35rem;font-size:.82rem;color:#475569;}
  .lead-meta li{display:flex;gap:.5rem;align-items:center;}
  .lead-meta i{color:#0ea5b5;}
  .lead-status{display:inline-flex;align-items:center;gap:.4rem;padding:.3rem .7rem;border-radius:999px;font-size:.75rem;font-weight:600;}
  .lead-update-form .form-control,.lead-update-form .form-select{border-radius:12px;font-size:.85rem;}
  .lead-update-form .form-label{font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin-bottom:.25rem;}
  .lead-notes{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:.8rem;}
  .lead-notes li{border-radius:14px;padding:.75rem;background:#fff;border:1px solid rgba(148,163,184,.2);font-size:.85rem;color:#0f172a;}
  .lead-notes .meta{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.4rem;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:#64748b;}
  .note-form textarea{min-height:90px;border-radius:12px;}
  .note-form .form-label{font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin-bottom:.25rem;}
  @media (max-width: 767px){
    .lead-list{grid-template-columns:1fr;}
  }
</style>
CSS;
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h(APP_NAME)?> — Temsilci Paneli</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <?=$pageStyles?>
</head>
<body>
  <div class="rep-shell">
    <div class="header">
      <div>
        <h1>Merhaba <?=h($representative['name'])?> 👋</h1>
        <p class="text-muted mb-0">Atandığınız bayi: <span class="fw-semibold text-primary"><?=h($dealer['name'])?></span></p>
      </div>
      <div class="text-end">
        <span class="dealer-tag"><i class="bi bi-building"></i><?=h($dealer['company'] ?: $dealer['name'])?></span><br>
        <a class="btn btn-sm btn-outline-danger mt-3" href="login.php?logout=1">Çıkış Yap</a>
      </div>
    </div>

    <?php flash_box(); ?>

    <div class="card-lite mb-4">
      <h5 class="fw-semibold mb-3">Komisyon Özeti</h5>
      <div class="summary-grid">
        <div class="summary-item">
          <span>Toplam Komisyon</span>
          <strong><?=h(format_currency($commissionTotals['total_amount']))?></strong>
        </div>
        <div class="summary-item">
          <span>Bekleyen</span>
          <strong><?=h(format_currency($commissionTotals['pending_amount']))?></strong>
        </div>
        <div class="summary-item">
          <span>Ödenen</span>
          <strong><?=h(format_currency($commissionTotals['paid_amount']))?></strong>
        </div>
        <div class="summary-item">
          <span>Yükleme Komisyonu</span>
          <strong>%<?=h(number_format($representative['commission_rate'], 1))?></strong>
        </div>
      </div>
    </div>

    <div class="card-lite mb-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-semibold mb-0">Tamamlanan Yüklemeler</h5>
        <small class="text-muted">Temsilci komisyonu otomatik hesaplanır.</small>
      </div>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr><th>Tarih</th><th>Tutar</th><th>Komisyon</th><th>Durum</th></tr></thead>
          <tbody>
            <?php if (!$topups): ?>
              <tr><td colspan="4" class="text-center text-muted">Henüz tamamlanan yükleme bulunmuyor.</td></tr>
            <?php else: ?>
              <?php foreach ($topups as $topup): ?>
                <tr>
                  <td><?=h($topup['completed_at'] ? date('d.m.Y H:i', strtotime($topup['completed_at'])) : date('d.m.Y H:i', strtotime($topup['created_at'])))?></td>
                  <td><?=h(format_currency($topup['amount_cents']))?></td>
                  <td><?=h(format_currency($topup['commission_cents']))?></td>
                  <td>
                    <?php $statusClass = ($topup['commission_status'] ?? 'pending') === 'paid' ? 'status-paid' : 'status-pending'; ?>
                    <span class="badge-status <?=$statusClass?>"><?= ($topup['commission_status'] ?? 'pending') === 'paid' ? 'Ödendi' : 'Bekliyor' ?></span>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card-lite mb-4">
      <h5 class="fw-semibold mb-3">Potansiyel Müşteri Özeti</h5>
      <div class="summary-grid mb-3">
        <div class="summary-item">
          <span>Toplam Potansiyel</span>
          <strong><?=array_sum($leadStats)?></strong>
        </div>
        <?php foreach ($leadStats as $statusKey => $count): ?>
          <div class="summary-item">
            <span><?=h($leadStatusLabels[$statusKey] ?? ucfirst($statusKey))?></span>
            <strong><?= (int)$count ?></strong>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="crm-overview">
        <div class="crm-block">
          <h6 class="fw-semibold mb-3">Yaklaşan Aksiyonlar</h6>
          <?php if (!$upcomingActions): ?>
            <p class="text-muted small mb-0">Planlanmış aksiyon bulunmuyor.</p>
          <?php else: ?>
            <ul>
              <?php foreach ($upcomingActions as $item): ?>
                <li>
                  <div class="dot"><i class="bi bi-calendar-event"></i></div>
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
        <div class="crm-block">
          <h6 class="fw-semibold mb-3">Son Görüşme Notları</h6>
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
                    <small><?=h(date('d.m.Y H:i', strtotime($note['created_at'])))?></small>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
    </div>
  </div>

  <div class="card-lite mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="fw-semibold mb-0">Potansiyel Müşteri Yönetimi</h5>
      <small class="text-muted">Bayinin eklediği potansiyelleri görüntüleyin ve görüşme notu bırakın.</small>
    </div>
    <?php if (!$leads): ?>
      <p class="text-muted mb-0">Bu bayi için henüz kayıtlı potansiyel müşteri bulunmuyor.</p>
    <?php else: ?>
      <div class="lead-list">
        <?php foreach ($leads as $lead): ?>
          <?php
            $leadId = (int)$lead['id'];
            $statusKey = $lead['status'] ?? DEALER_LEAD_STATUS_NEW;
            $statusLabel = $leadStatusLabels[$statusKey] ?? ucfirst($statusKey);
            $statusTone = dealer_lead_status_badge_class($statusKey);
            $statusClass = 'bg-'.$statusTone;
            $statusTextClass = in_array($statusTone, ['warning','secondary','light'], true) ? 'text-dark' : 'text-white';
            $nextActionValue = !empty($lead['next_action_at']) ? date('Y-m-d\TH:i', strtotime($lead['next_action_at'])) : '';
            $noteSlice = $leadNotesMap[$leadId] ?? [];
          ?>
          <div class="lead-card" id="lead-<?=$leadId?>">
            <div>
              <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                <div>
                  <h6><?=h($lead['name'])?></h6>
                  <span class="lead-status badge <?=$statusClass?> <?=$statusTextClass?>"><?=h($statusLabel)?></span>
                </div>
                <?php if (!empty($lead['created_at'])): ?>
                  <small class="text-muted">Kaydedildi: <?=h(date('d.m.Y H:i', strtotime($lead['created_at'])))?></small>
                <?php endif; ?>
              </div>
              <ul class="lead-meta">
                <?php if (!empty($lead['email'])): ?><li><i class="bi bi-envelope"></i><span><?=h($lead['email'])?></span></li><?php endif; ?>
                <?php if (!empty($lead['phone'])): ?><li><i class="bi bi-telephone"></i><span><?=h($lead['phone'])?></span></li><?php endif; ?>
                <?php if (!empty($lead['company'])): ?><li><i class="bi bi-building"></i><span><?=h($lead['company'])?></span></li><?php endif; ?>
                <?php if (!empty($lead['source'])): ?><li><i class="bi bi-bullseye"></i><span><?=h($lead['source'])?></span></li><?php endif; ?>
                <?php if (!empty($lead['last_contact_at'])): ?><li><i class="bi bi-clock-history"></i><span>Son görüşme: <?=h(date('d.m.Y H:i', strtotime($lead['last_contact_at'])))?></span></li><?php endif; ?>
                <?php if (!empty($lead['next_action_at'])): ?><li><i class="bi bi-calendar-event"></i><span>Sonraki aksiyon: <?=h(date('d.m.Y H:i', strtotime($lead['next_action_at'])))?></span></li><?php endif; ?>
              </ul>
            </div>
            <form method="post" class="row g-2 lead-update-form">
              <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="do" value="update_lead">
              <input type="hidden" name="lead_id" value="<?=$leadId?>">
              <div class="col-sm-4">
                <label class="form-label">Durum</label>
                <select class="form-select" name="status">
                  <?php foreach ($leadStatusLabels as $key => $label): ?>
                    <option value="<?=h($key)?>" <?=$statusKey === $key ? 'selected' : ''?>><?=h($label)?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-sm-5">
                <label class="form-label">Sonraki Aksiyon</label>
                <input type="datetime-local" class="form-control" name="next_action_at" value="<?=h($nextActionValue)?>">
              </div>
              <div class="col-sm-12">
                <label class="form-label">Genel Not</label>
                <textarea class="form-control" name="notes" rows="2" placeholder="Potansiyel notu güncelleyin."><?=h($lead['notes'] ?? '')?></textarea>
              </div>
              <div class="col-sm-12 d-grid">
                <button class="btn btn-sm btn-outline-primary" type="submit">Kaydet</button>
              </div>
            </form>
            <div>
              <h6 class="fw-semibold fs-6">Son Notlar</h6>
              <?php if (!$noteSlice): ?>
                <p class="text-muted small mb-0">Bu potansiyel için henüz not eklenmedi.</p>
              <?php else: ?>
                <ul class="lead-notes">
                  <?php foreach ($noteSlice as $note): ?>
                    <li>
                      <div class="fw-semibold mb-1"><?=h(date('d.m.Y H:i', strtotime($note['created_at'])))?></div>
                      <div class="mb-1"><?=nl2br(h($note['note']))?></div>
                      <div class="meta">
                        <?php if (!empty($note['representative_name'])): ?><span><?=h($note['representative_name'])?></span><?php endif; ?>
                        <?php if (!empty($note['contact_type'])): ?><span><?=h($note['contact_type'])?></span><?php endif; ?>
                        <?php if (!empty($note['next_action_at'])): ?><span>Sonraki: <?=h(date('d.m.Y H:i', strtotime($note['next_action_at'])))?></span><?php endif; ?>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
              <form method="post" class="note-form mt-3">
                <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                <input type="hidden" name="do" value="add_note">
                <input type="hidden" name="lead_id" value="<?=$leadId?>">
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
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card-lite">
    <h5 class="fw-semibold mb-3">Komisyon Hareketleri</h5>
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
                  <td>
                    <span class="badge-status <?=$isPaid ? 'status-paid' : 'status-pending'?>"><?=$isPaid ? 'Ödendi' : 'Bekliyor'?></span>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
