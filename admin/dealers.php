<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/dealers.php';
require_once __DIR__.'/../includes/auth.php';

require_admin();
install_schema();

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

  if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('err', 'Ad ve geçerli e-posta gerekli.');
    redirect($_SERVER['PHP_SELF']);
  }
  if (dealer_find_by_email($email)) {
    flash('err', 'Bu e-posta zaten kayıtlı.');
    redirect($_SERVER['PHP_SELF']);
  }

  $pdo = pdo();
  $pdo->prepare("INSERT INTO dealers (name,email,phone,company,notes,status,license_expires_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)")
      ->execute([$name,$email,$phone,$company,$notes,$status,$license, now(), now()]);
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

if ($action === 'regenerate_code') {
  $dealerId = (int)($_POST['dealer_id'] ?? 0);
  $type = $_POST['type'] ?? DEALER_CODE_TRIAL;
  $dealer = dealer_get($dealerId);
  if (!$dealer) { flash('err','Bayi bulunamadı.'); redirect($_SERVER['PHP_SELF']); }
  dealer_regenerate_code($dealerId, $type);
  flash('ok','Kod güncellendi.');
  redirect($_SERVER['PHP_SELF'].'?id='.$dealerId);
}

if ($action === 'set_code_event') {
  $dealerId = (int)($_POST['dealer_id'] ?? 0);
  $type = $_POST['type'] ?? DEALER_CODE_STATIC;
  $eventId = (int)($_POST['event_id'] ?? 0);
  $dealer = dealer_get($dealerId);
  if (!$dealer) { flash('err','Bayi bulunamadı.'); redirect($_SERVER['PHP_SELF']); }
  if ($eventId && !dealer_event_belongs_to_dealer($dealerId, $eventId)) {
    flash('err','Seçilen düğün bu bayiye ait değil.');
  } else {
    dealer_set_code_target($dealerId, $type, $eventId ?: null);
    flash('ok','Kod bağlantısı kaydedildi.');
  }
  redirect($_SERVER['PHP_SELF'].'?id='.$dealerId);
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

$dealers = pdo()->query("SELECT * FROM dealers ORDER BY created_at DESC")->fetchAll();
$selectedId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$selectedDealer = $selectedId ? dealer_get($selectedId) : null;
$selectedCodes = $selectedDealer ? dealer_sync_codes($selectedId) : [];
$assignedVenues = $selectedDealer ? dealer_fetch_venues($selectedId) : [];
$assignedVenueIds = array_map(fn($v) => (int)$v['id'], $assignedVenues);
$allVenues = pdo()->query("SELECT * FROM venues ORDER BY name")->fetchAll();
$events = $selectedDealer ? dealer_allowed_events($selectedId) : [];
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h(APP_NAME)?> — Bayi Yönetimi</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  :root{ --zs:#0ea5b5; }
  body{background:#f4f6fb;}
  .card-lite{border:1px solid #e5e7eb;border-radius:16px;background:#fff;box-shadow:0 10px 30px rgba(15,23,42,.08);}
  .badge-status{padding:.35rem .65rem;border-radius:999px;font-size:.75rem;}
</style>
</head>
<body>
<nav class="navbar bg-white border-bottom">
  <div class="container d-flex justify-content-between align-items-center py-2">
    <div class="d-flex align-items-center gap-2">
      <a class="navbar-brand fw-semibold" href="<?=h(BASE_URL)?>"><?=h(APP_NAME)?></a>
      <span class="text-muted">/ Bayi Yönetimi</span>
    </div>
    <div class="d-flex gap-2">
      <a href="<?=h(BASE_URL)?>/admin/dashboard.php" class="btn btn-sm btn-outline-secondary">Panel</a>
      <a href="<?=h(BASE_URL)?>/admin/venues.php" class="btn btn-sm btn-outline-secondary">Salonlar</a>
      <a href="<?=h(BASE_URL)?>/admin/users.php" class="btn btn-sm btn-outline-secondary">Kullanıcılar</a>
      <a href="<?=h(BASE_URL)?>/admin/login.php?logout=1" class="btn btn-sm btn-danger">Çıkış</a>
    </div>
  </div>
</nav>
<div class="container py-4">
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
            <button class="btn btn-primary" type="submit">Kaydet</button>
          </div>
        </form>
      </div>
      <div class="card-lite p-0">
        <div class="p-3 border-bottom"><h5 class="m-0">Bayiler</h5></div>
        <div class="list-group list-group-flush" style="max-height:420px;overflow:auto;">
          <?php foreach ($dealers as $d): ?>
            <?php
              $badge = dealer_status_badge($d['status']);
              $activeClass = ($selectedId === (int)$d['id']) ? 'active' : '';
              $license = $d['license_expires_at'] ? date('d.m.Y', strtotime($d['license_expires_at'])) : '—';
            ?>
            <a href="?id=<?= (int)$d['id'] ?>" class="list-group-item list-group-item-action <?= $activeClass ?>">
              <div class="fw-semibold"><?=h($d['name'])?></div>
              <div class="d-flex justify-content-between small text-muted">
                <span><?=h($badge)?></span>
                <span>Lisans: <?=h($license)?></span>
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
              <button class="btn btn-primary" type="submit">Bilgileri Güncelle</button>
            </div>
          </form>
        </div>

        <div class="card-lite p-4 mb-4">
          <h5 class="mb-3">Salon Atamaları</h5>
          <form method="post" class="row g-3">
            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="do" value="assign_venues">
            <input type="hidden" name="dealer_id" value="<?= (int)$selectedDealer['id'] ?>">
            <div class="col-12">
              <select class="form-select" name="venue_ids[]" multiple size="8">
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

        <div class="card-lite p-4">
          <h5 class="mb-3">Kodlar</h5>
          <div class="row g-3">
            <?php foreach ([DEALER_CODE_STATIC=>'Kalıcı Kod', DEALER_CODE_TRIAL=>'Deneme Kodu'] as $type=>$label):
              $code = $codes[$type] ?? null;
              $url = $code ? BASE_URL.'/qr.php?code='.urlencode($code['code']) : '';
            ?>
            <div class="col-md-6">
              <div class="border rounded-3 p-3 h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <h6 class="mb-0"><?=h($label)?></h6>
                  <form method="post" class="m-0">
                    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="do" value="regenerate_code">
                    <input type="hidden" name="dealer_id" value="<?= (int)$selectedDealer['id'] ?>">
                    <input type="hidden" name="type" value="<?=h($type)?>">
                    <button class="btn btn-sm btn-outline-secondary" type="submit">Yenile</button>
                  </form>
                </div>
                <?php if ($code): ?>
                  <p class="fw-semibold">Kod: <?=h($code['code'])?></p>
                  <p class="small text-muted"><a href="<?=h($url)?>" target="_blank">QR bağlantısı</a></p>
                  <form method="post" class="vstack gap-2">
                    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="do" value="set_code_event">
                    <input type="hidden" name="dealer_id" value="<?= (int)$selectedDealer['id'] ?>">
                    <input type="hidden" name="type" value="<?=h($type)?>">
                    <label class="form-label small">Bağlı düğün</label>
                    <select class="form-select form-select-sm" name="event_id">
                      <option value="0">— Seçili değil —</option>
                      <?php foreach ($events as $ev): ?>
                        <?php
                          $sel = ($code['target_event_id'] ?? null) == $ev['id'] ? 'selected' : '';
                          $dateLabel = $ev['event_date'] ? date('d.m.Y', strtotime($ev['event_date'])) : 'Tarihsiz';
                        ?>
                        <option value="<?= (int)$ev['id'] ?>" <?=$sel?>><?=h($dateLabel.' • '.$ev['title'])?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-primary" type="submit">Kaydet</button>
                  </form>
                <?php else: ?>
                  <p class="text-muted">Kod oluşturulamadı.</p>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
