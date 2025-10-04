<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/dealers.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/partials/ui.php';

require_admin();
install_schema();
dealer_backfill_codes();

function parse_license_input(?string $input): ?string {
  if (!$input) return null;
  try {
    $dt = new DateTime($input);
    return $dt->format('Y-m-d H:i:s');
  } catch (Throwable $e) {
    return null;
  }
}

$action = $_POST['do'] ?? '';
if ($action) {
  csrf_or_die();
}

if ($action === 'create') {
  $name  = trim($_POST['name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $phone = trim($_POST['phone'] ?? '');
  $company = trim($_POST['company'] ?? '');
  $notes = trim($_POST['notes'] ?? '');
  $status = $_POST['status'] ?? DEALER_STATUS_PENDING;
  $licenseInput = $_POST['license_expires_at'] ?? '';
  $license = parse_license_input($licenseInput);
  $code = dealer_generate_unique_identifier();

  if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('err', 'Ad ve geçerli e-posta gerekli.');
    redirect($_SERVER['PHP_SELF']);
  }
  if (dealer_find_by_email($email)) {
    flash('err', 'Bu e-posta zaten kayıtlı.');
    redirect($_SERVER['PHP_SELF']);
  }

  $pdo = pdo();
  $pdo->prepare("INSERT INTO dealers (code,name,email,phone,company,notes,status,license_expires_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
      ->execute([$code,$name,$email,$phone,$company,$notes,$status,$license, now(), now()]);
  $dealerId = (int)$pdo->lastInsertId();
  dealer_ensure_codes($dealerId);

  if ($status === DEALER_STATUS_ACTIVE) {
    $plain = dealer_random_password();
    $hash  = password_hash($plain, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE dealers SET password_hash=?, approved_at=?, updated_at=? WHERE id=?")
        ->execute([$hash, now(), now(), $dealerId]);
    $dealer = dealer_get($dealerId);
    dealer_send_welcome_mail($dealer, $plain);
  }

  flash('ok', 'Bayi kaydedildi.');
  redirect($_SERVER['PHP_SELF'].'?id='.$dealerId);
}

if ($action === 'update') {
  $dealerId = (int)($_POST['dealer_id'] ?? 0);
  $dealer = dealer_get($dealerId);
  if (!$dealer) { flash('err','Bayi bulunamadı.'); redirect($_SERVER['PHP_SELF']); }

  $name  = trim($_POST['name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $phone = trim($_POST['phone'] ?? '');
  $company = trim($_POST['company'] ?? '');
  $notes = trim($_POST['notes'] ?? '');
  $status = $_POST['status'] ?? DEALER_STATUS_PENDING;
  $licenseInput = $_POST['license_expires_at'] ?? '';
  $license = parse_license_input($licenseInput);

  if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('err','Ad ve geçerli e-posta gerekli.');
    redirect($_SERVER['PHP_SELF'].'?id='.$dealerId);
  }
  $st = pdo()->prepare("SELECT id FROM dealers WHERE email=? AND id<>? LIMIT 1");
  $st->execute([$email, $dealerId]);
  if ($st->fetch()) {
    flash('err','Bu e-posta başka bir bayide kayıtlı.');
    redirect($_SERVER['PHP_SELF'].'?id='.$dealerId);
  }

  pdo()->prepare("UPDATE dealers SET name=?, email=?, phone=?, company=?, notes=?, status=?, license_expires_at=?, updated_at=? WHERE id=?")
      ->execute([$name,$email,$phone,$company,$notes,$status,$license, now(), $dealerId]);

  if ($status === DEALER_STATUS_ACTIVE && empty($dealer['password_hash'])) {
    $plain = dealer_random_password();
    $hash  = password_hash($plain, PASSWORD_DEFAULT);
    pdo()->prepare("UPDATE dealers SET password_hash=?, approved_at=?, updated_at=? WHERE id=?")
        ->execute([$hash, now(), now(), $dealerId]);
    $dealer = dealer_get($dealerId);
    dealer_send_welcome_mail($dealer, $plain);
  }

  flash('ok','Bilgiler güncellendi.');
  redirect($_SERVER['PHP_SELF'].'?id='.$dealerId);
}

if ($action === 'assign_venues') {
  $dealerId = (int)($_POST['dealer_id'] ?? 0);
  $dealer = dealer_get($dealerId);
  if (!$dealer) { flash('err','Bayi bulunamadı.'); redirect($_SERVER['PHP_SELF']); }
  $venues = $_POST['venue_ids'] ?? [];
  dealer_assign_venues($dealerId, $venues);
  flash('ok','Salon atamaları güncellendi.');
  redirect($_SERVER['PHP_SELF'].'?id='.$dealerId);
}

if ($action === 'assign_venue_dealers') {
  $venueId = (int)($_POST['venue_id'] ?? 0);
  $dealerIds = $_POST['dealer_ids'] ?? [];
  $venueCheck = pdo()->prepare("SELECT id FROM venues WHERE id=? LIMIT 1");
  $venueCheck->execute([$venueId]);
  if (!$venueCheck->fetch()) {
    flash('err','Salon bulunamadı.');
    redirect($_SERVER['PHP_SELF']);
  }
  dealer_assign_dealers_to_venue($venueId, $dealerIds);
  $anchor = '#venue-'.$venueId;
  flash('ok','Salon için bayi atamaları kaydedildi.');
  redirect($_SERVER['PHP_SELF'].$anchor);
}

if ($action === 'send_password') {
  $dealerId = (int)($_POST['dealer_id'] ?? 0);
  $dealer = dealer_get($dealerId);
  if (!$dealer) { flash('err','Bayi bulunamadı.'); redirect($_SERVER['PHP_SELF']); }
  if ($dealer['status'] !== DEALER_STATUS_ACTIVE) {
    flash('err','Önce bayiyi aktif hale getirin.');
    redirect($_SERVER['PHP_SELF'].'?id='.$dealerId);
  }
  $plain = dealer_random_password();
  $hash = password_hash($plain, PASSWORD_DEFAULT);
  pdo()->prepare("UPDATE dealers SET password_hash=?, approved_at=COALESCE(approved_at,?), updated_at=? WHERE id=?")
      ->execute([$hash, now(), now(), $dealerId]);
  $dealer = dealer_get($dealerId);
  dealer_send_welcome_mail($dealer, $plain);
  flash('ok','Yeni şifre e-posta ile gönderildi.');
  redirect($_SERVER['PHP_SELF'].'?id='.$dealerId);
}

$dealers = pdo()->query("SELECT * FROM dealers ORDER BY name")->fetchAll();
$selectedId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$selectedDealer = $selectedId ? dealer_get($selectedId) : null;
$assignedVenues = $selectedDealer ? dealer_fetch_venues($selectedId) : [];
$assignedVenueIds = array_map(fn($v) => (int)$v['id'], $assignedVenues);
$allVenues = pdo()->query("SELECT * FROM venues ORDER BY name")->fetchAll();
$events = $selectedDealer ? dealer_allowed_events($selectedId) : [];
$venueAssignments = dealer_fetch_venue_assignments();
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h(APP_NAME)?> — Bayi Yönetimi</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css">
<?=admin_base_styles()?>
<style>
  .card-lite{ padding:1.5rem; }
  .badge-status{padding:.35rem .75rem;border-radius:999px;font-size:.75rem;font-weight:600;}
  .status-active{background:rgba(34,197,94,.15);color:#15803d;}
  .status-pending{background:rgba(250,204,21,.18);color:#854d0e;}
  .status-inactive{background:rgba(248,113,113,.16);color:#b91c1c;}
  .dealer-list .card-lite{padding:1.2rem 1.5rem;}
  .dealer-meta{color:var(--muted); font-size:.9rem;}
  .dealer-meta strong{color:var(--ink);}
  .dealer-code{font-family:"JetBrains Mono",monospace;font-size:.85rem;text-transform:uppercase;letter-spacing:.08em;color:var(--ink);}
  .dealer-list .list-group-item{padding:1rem 1.25rem;}
  .dealer-list .list-group-item.active{background:rgba(14,165,181,.08);border-color:rgba(14,165,181,.3);}
  .assigned-tags{display:flex;flex-wrap:wrap;gap:.4rem;}
  .assigned-tags .dealer-chip{background:rgba(14,165,181,.12);color:#0f172a;border-radius:999px;padding:.25rem .75rem;font-size:.75rem;font-weight:500;}
  .assigned-tags .dealer-chip span{font-weight:600;color:#0f172a;}
  .venue-card{border:1px solid rgba(148,163,184,.25);border-radius:14px;padding:1.25rem;margin-bottom:1.25rem;background:#fff;box-shadow:0 10px 28px -20px rgba(15,23,42,.4);}
  .venue-card:last-child{margin-bottom:0;}
  .venue-card h6{margin-bottom:.35rem;}
  .combo-helper{font-size:.8rem;color:var(--muted);}
  .ts-wrapper.form-select .ts-control{padding:.35rem .5rem;}
  .ts-wrapper.multi .ts-control>div{background:rgba(14,165,181,.12);color:#0f172a;border-radius:999px;padding:.25rem .5rem;font-weight:500;}
  .ts-wrapper.multi .ts-control>div .remove{color:rgba(15,23,42,.55);}
  .ts-wrapper.multi .ts-control>div .remove:hover{color:#0f172a;}
  .venue-chip-empty{color:var(--muted);font-size:.85rem;}
  .section-subtitle{font-size:.85rem;color:var(--muted);}
  .tab-card{border-radius:18px; background:#fff; border:1px solid rgba(148,163,184,.16); box-shadow:0 22px 45px -28px rgba(15,23,42,.45);}
</style>
</head>
<body class="admin-body">
<?php render_admin_topnav('dealers', 'Bayi Yönetimi', 'Bayileri yönetin, salon atayın ve lisans durumlarını takip edin.'); ?>

<main class="admin-main">
  <div class="container">
  <?php flash_box(); ?>
  <div class="row g-4">
    <div class="col-lg-5">
      <div class="card-lite p-4 mb-4">
        <h5 class="mb-3">Yeni Bayi Oluştur</h5>
        <form method="post" class="row g-2">
          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="do" value="create">
          <div class="col-12">
            <label class="form-label">Ad Soyad</label>
            <input class="form-control" name="name" required>
          </div>
          <div class="col-12">
            <label class="form-label">E-posta</label>
            <input type="email" class="form-control" name="email" required>
          </div>
          <div class="col-6">
            <label class="form-label">Telefon</label>
            <input class="form-control" name="phone">
          </div>
          <div class="col-6">
            <label class="form-label">Firma</label>
            <input class="form-control" name="company">
          </div>
          <div class="col-12">
            <label class="form-label">Not</label>
            <textarea class="form-control" name="notes" rows="2"></textarea>
          </div>
          <div class="col-6">
            <label class="form-label">Durum</label>
            <select class="form-select" name="status">
              <option value="pending">Onay Bekliyor</option>
              <option value="active">Aktif</option>
              <option value="inactive">Pasif</option>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Lisans Bitişi</label>
            <input type="datetime-local" class="form-control" name="license_expires_at">
          </div>
          <div class="col-12 d-grid">
            <button class="btn btn-brand" type="submit">Kaydet</button>
          </div>
        </form>
      </div>
      <div class="card-lite p-0">
        <div class="p-3 border-bottom"><h5 class="m-0">Bayiler</h5></div>
        <div class="list-group list-group-flush" style="max-height:420px;overflow:auto;">
          <?php foreach ($dealers as $d): ?>
            <?php
              $badge = dealer_status_badge($d['status']);
              $badgeClass = dealer_status_class($d['status']);
              $activeClass = ($selectedId === (int)$d['id']) ? 'active' : '';
              $license = $d['license_expires_at'] ? date('d.m.Y', strtotime($d['license_expires_at'])) : '—';
            ?>
            <a href="?id=<?= (int)$d['id'] ?>" class="list-group-item list-group-item-action <?= $activeClass ?>">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <div class="fw-semibold me-2"><?=h($d['name'])?></div>
                <span class="badge-status <?=$badgeClass?>"><?=h($badge)?></span>
              </div>
              <div class="d-flex justify-content-between align-items-center">
                <span class="dealer-code"><?=h($d['code'] ?? '—')?></span>
                <span class="dealer-meta">Lisans: <?=h($license)?></span>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="col-lg-7">
      <?php if (!$selectedDealer): ?>
        <div class="card-lite p-4">
          <h5 class="mb-2">Bayi seçin</h5>
          <p class="text-muted">Soldaki listeden bir bayi seçerek detaylarını görüntüleyin.</p>
        </div>
      <?php else: ?>
        <?php
          $licenseValue = $selectedDealer['license_expires_at'] ? date('Y-m-d\TH:i', strtotime($selectedDealer['license_expires_at'])) : '';
          $codes = $selectedCodes;
          $statusLabel = dealer_status_badge($selectedDealer['status']);
          $statusClass = dealer_status_class($selectedDealer['status']);
        ?>
        <div class="card-lite p-4 mb-4">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="m-0"><?=h($selectedDealer['name'])?></h5>
            <form method="post" class="m-0">
              <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="do" value="send_password">
              <input type="hidden" name="dealer_id" value="<?= (int)$selectedDealer['id'] ?>">
              <button class="btn btn-sm btn-outline-primary" type="submit">Yeni Şifre Gönder</button>
            </form>
          </div>
          <div class="d-flex flex-wrap gap-4 mb-3">
            <div>
              <div class="text-uppercase text-muted small fw-semibold">Bayi Kodu</div>
              <div class="dealer-code fs-5"><?=h($selectedDealer['code'])?></div>
            </div>
            <div>
              <div class="text-uppercase text-muted small fw-semibold">Durum</div>
              <span class="badge-status <?=$statusClass?>"><?=h($statusLabel)?></span>
            </div>
            <?php if (!empty($selectedDealer['license_expires_at'])): ?>
            <div>
              <div class="text-uppercase text-muted small fw-semibold">Lisans Bitişi</div>
              <div class="dealer-meta mb-0"><?=h(date('d.m.Y H:i', strtotime($selectedDealer['license_expires_at'])))?></div>
            </div>
            <?php endif; ?>
          </div>
          <form method="post" class="row g-2">
            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="do" value="update">
            <input type="hidden" name="dealer_id" value="<?= (int)$selectedDealer['id'] ?>">
            <div class="col-md-6">
              <label class="form-label">Ad Soyad</label>
              <input class="form-control" name="name" value="<?=h($selectedDealer['name'])?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">E-posta</label>
              <input type="email" class="form-control" name="email" value="<?=h($selectedDealer['email'])?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Telefon</label>
              <input class="form-control" name="phone" value="<?=h($selectedDealer['phone'])?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Firma</label>
              <input class="form-control" name="company" value="<?=h($selectedDealer['company'])?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Durum</label>
              <select class="form-select" name="status">
                <option value="pending" <?= $selectedDealer['status']==='pending'?'selected':'' ?>>Onay Bekliyor</option>
                <option value="active" <?= $selectedDealer['status']==='active'?'selected':'' ?>>Aktif</option>
                <option value="inactive" <?= $selectedDealer['status']==='inactive'?'selected':'' ?>>Pasif</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Lisans Bitiş</label>
              <input type="datetime-local" class="form-control" name="license_expires_at" value="<?=h($licenseValue)?>">
            </div>
            <div class="col-12">
              <label class="form-label">Not</label>
              <textarea class="form-control" name="notes" rows="2"><?=h($selectedDealer['notes'])?></textarea>
            </div>
            <div class="col-12 d-grid">
              <button class="btn btn-brand" type="submit">Bilgileri Güncelle</button>
            </div>
          </form>
        </div>

        <div class="card-lite p-4 mb-4">
          <h5 class="mb-1">Salon Atamaları</h5>
          <p class="combo-helper mb-3">Birden fazla salonu seçebilir, arama yaparak kolayca filtreleyebilirsiniz.</p>
          <?php if ($assignedVenues): ?>
            <div class="assigned-tags mb-3">
              <?php foreach ($assignedVenues as $v): ?>
                <span class="dealer-chip"><span><?=h($v['name'])?></span></span>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="venue-chip-empty mb-3">Bu bayiye henüz salon atanmadı.</div>
          <?php endif; ?>
          <form method="post" class="row g-3">
            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="do" value="assign_venues">
            <input type="hidden" name="dealer_id" value="<?= (int)$selectedDealer['id'] ?>">
            <div class="col-12">
              <select class="form-select js-combobox" name="venue_ids[]" multiple data-placeholder="Salon seçin">
                <?php foreach ($allVenues as $v): ?>
                  <option value="<?= (int)$v['id'] ?>" <?= in_array((int)$v['id'], $assignedVenueIds, true) ? 'selected' : '' ?>><?=h($v['name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 d-grid">
              <button class="btn btn-outline-primary" type="submit">Atamaları Kaydet</button>
            </div>
          </form>
        </div>

      <?php endif; ?>
    </div>
  </div>

  <div class="row mt-4">
    <div class="col-12">
      <div class="card-lite p-4">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <div>
            <h5 class="mb-1">Salon Bazlı Bayi Ataması</h5>
            <p class="section-subtitle mb-0">Salonlara atanmış bayileri görüntüleyin ve çoklu seçimle hızla güncelleyin.</p>
          </div>
        </div>
        <?php if (!$allVenues): ?>
          <p class="text-muted mb-0">Henüz tanımlanmış salon bulunmuyor.</p>
        <?php else: ?>
        <div class="row g-3">
          <?php foreach ($venueAssignments as $group): ?>
            <?php
              $venue = $group['venue'];
              $assigned = $group['dealers'];
              $assignedIds = array_map(fn($d) => (int)$d['id'], $assigned);
            ?>
            <div class="col-xl-6">
              <div class="venue-card" id="venue-<?= (int)$venue['id'] ?>">
                <h6 class="fw-semibold mb-1"><?=h($venue['name'])?></h6>
                <?php if ($assigned): ?>
                  <div class="assigned-tags mb-2">
                    <?php foreach ($assigned as $dealer): ?>
                      <span class="dealer-chip"><span><?=h($dealer['code'] ?? '—')?></span> • <?=h($dealer['name'])?></span>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <div class="venue-chip-empty mb-2">Bu salona henüz bayi atanmadı.</div>
                <?php endif; ?>
                <?php if ($dealers): ?>
                  <form method="post" class="vstack gap-2">
                    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="do" value="assign_venue_dealers">
                    <input type="hidden" name="venue_id" value="<?= (int)$venue['id'] ?>">
                    <select class="form-select js-combobox" name="dealer_ids[]" multiple data-placeholder="Bayi seçin">
                      <?php foreach ($dealers as $dealerOption): ?>
                        <option value="<?= (int)$dealerOption['id'] ?>" <?= in_array((int)$dealerOption['id'], $assignedIds, true) ? 'selected' : '' ?>><?=h(($dealerOption['code'] ?? '—').' • '.$dealerOption['name'])?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-brand align-self-start" type="submit">Kaydet</button>
                  </form>
                <?php else: ?>
                  <p class="venue-chip-empty mb-0">Bayi tanımlanmadan atama yapılamaz.</p>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
  document.querySelectorAll('.js-combobox').forEach(function(el){
    new TomSelect(el, {
      plugins: {
        remove_button: { title: 'Seçimi kaldır' }
      },
      persist: false,
      create: false,
      hideSelected: true,
      closeAfterSelect: false,
      placeholder: el.dataset.placeholder || '',
      sortField: { field: 'text', direction: 'asc' }
    });
  });
});
</script>
</body>
</html>
