<?php
// admin/campaigns.php — kampanyalar artık ayrı sayfada yönetilir
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/partials/ui.php';

require_admin();
install_schema();

$venue = require_current_venue_or_redirect();
$VID   = (int)$venue['id'];
$VNAME = $venue['name'];
$VSLUG = $venue['slug'];

try {
  pdo()->query("SELECT 1 FROM campaigns LIMIT 1");
} catch (Throwable $e) {
  try {
    pdo()->exec("
      CREATE TABLE campaigns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        venue_id INT NOT NULL,
        name VARCHAR(190) NOT NULL,
        type VARCHAR(120) NOT NULL,
        description TEXT NULL,
        price INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE CASCADE,
        INDEX (venue_id, is_active)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
  } catch (Throwable $e2) {}
}

if (($_POST['do'] ?? '') === 'camp_create') {
  csrf_or_die();
  $name = trim($_POST['name'] ?? '');
  $type = trim($_POST['type'] ?? '');
  $desc = trim($_POST['description'] ?? '');
  $price = max(0,(int)($_POST['price'] ?? 0));
  if (!$name || !$type) {
    flash('err','Kampanya adı ve türü gerekli.');
    redirect($_SERVER['PHP_SELF']);
  }
  pdo()->prepare("INSERT INTO campaigns (venue_id,name,type,description,price,is_active,created_at) VALUES (?,?,?,?,?,1,?)")
      ->execute([$VID,$name,$type,$desc,$price, now()]);
  flash('ok','Kampanya eklendi.');
  redirect($_SERVER['PHP_SELF']);
}

if (($_POST['do'] ?? '') === 'camp_update') {
  csrf_or_die();
  $id    = (int)($_POST['id'] ?? 0);
  $name  = trim($_POST['name'] ?? '');
  $type  = trim($_POST['type'] ?? '');
  $desc  = trim($_POST['description'] ?? '');
  $price = max(0,(int)($_POST['price'] ?? 0));
  $act   = isset($_POST['is_active']) ? 1 : 0;
  pdo()->prepare("UPDATE campaigns SET name=?, type=?, description=?, price=?, is_active=?, updated_at=? WHERE id=? AND venue_id=?")
      ->execute([$name,$type,$desc,$price,$act, now(), $id, $VID]);
  flash('ok','Kampanya güncellendi.');
  redirect($_SERVER['PHP_SELF']);
}

if (($_POST['do'] ?? '') === 'camp_toggle') {
  csrf_or_die();
  $id=(int)($_POST['id'] ?? 0);
  pdo()->prepare("UPDATE campaigns SET is_active=1-is_active, updated_at=? WHERE id=? AND venue_id=?")
      ->execute([now(), $id, $VID]);
  redirect($_SERVER['PHP_SELF']);
}

$campaigns = pdo()->prepare("SELECT * FROM campaigns WHERE venue_id=? ORDER BY is_active DESC, id DESC");
$campaigns->execute([$VID]);
$campaigns=$campaigns->fetchAll();

?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h(APP_NAME)?> — Kampanyalar (<?=h($VNAME)?>)</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?=admin_base_styles()?>
<style>
  .grid-compact .form-control, .grid-compact .form-select{ height:46px; }
  .muted{ color:var(--muted); }
  .btn-zs{ background:var(--brand); border:none; color:#fff; border-radius:12px; font-weight:600; }
  .btn-zs:hover{ background:var(--brand-dark); color:#fff; }
  .btn-zs-outline{ background:#fff; border:1px solid rgba(14,165,181,.55); color:var(--brand); border-radius:12px; font-weight:600; }
  .btn-zs-outline:hover{ background:rgba(14,165,181,.12); color:var(--brand-dark); }
</style>
</head>
<body class="admin-body">
<?php admin_layout_start('campaigns', 'Kampanya Yönetimi', 'Salon: '.$VNAME.' • Kampanyalar tüm etkinlik panellerinde gösterilir.'); ?>
    <?php flash_box(); ?>

    <div class="card-lite mb-4">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="m-0">Yeni Kampanya</h5>
        <span class="muted small">Eklediğiniz kampanyalar salonunuzdaki tüm etkinlik panellerine yansır.</span>
      </div>
      <form method="post" class="row g-3 grid-compact">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="do" value="camp_create">
        <div class="col-md-4">
          <label class="form-label">Kampanya Adı</label>
          <input class="form-control" name="name" placeholder="Örn: Gold Paket">
        </div>
        <div class="col-md-3">
          <label class="form-label">Tür / Etiket</label>
          <input class="form-control" name="type" placeholder="Örn: video, premium">
        </div>
        <div class="col-md-3">
          <label class="form-label">Fiyat (TL)</label>
          <input class="form-control" type="number" min="0" step="100" name="price" placeholder="0">
        </div>
        <div class="col-12">
          <label class="form-label">Açıklama</label>
          <textarea class="form-control" name="description" rows="3" placeholder="Kampanyanın detaylarını yazın."></textarea>
        </div>
        <div class="col-12 col-md-3">
          <button class="btn btn-zs w-100">Kampanyayı Kaydet</button>
        </div>
      </form>
    </div>

    <div class="card-lite">
      <h5 class="mb-3">Aktif Kampanyalar</h5>
      <?php if (!$campaigns): ?>
        <div class="muted">Henüz kampanya eklenmedi.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>Ad</th>
                <th>Tür</th>
                <th>Fiyat</th>
                <th>Durum</th>
                <th style="width:220px">İşlemler</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($campaigns as $c): ?>
                <form id="camp-form-<?=$c['id']?>" method="post" class="d-none">
                  <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="id" value="<?=$c['id']?>">
                </form>
                <tr>
                  <td>
                    <input form="camp-form-<?=$c['id']?>" class="form-control mb-2" name="name" value="<?=h($c['name'])?>">
                    <textarea form="camp-form-<?=$c['id']?>" class="form-control" name="description" rows="2" placeholder="Açıklama"><?=h($c['description'])?></textarea>
                  </td>
                  <td>
                    <input form="camp-form-<?=$c['id']?>" class="form-control" name="type" value="<?=h($c['type'])?>">
                  </td>
                  <td>
                    <div class="input-group">
                      <input form="camp-form-<?=$c['id']?>" type="number" class="form-control" name="price" min="0" step="100" value="<?= (int)$c['price'] ?>">
                      <span class="input-group-text">TL</span>
                    </div>
                  </td>
                  <td>
                    <div class="form-check form-switch">
                      <input form="camp-form-<?=$c['id']?>" class="form-check-input" type="checkbox" value="1" name="is_active" id="camp-active-<?=$c['id']?>" <?= $c['is_active'] ? 'checked' : '' ?>>
                      <label class="form-check-label" for="camp-active-<?=$c['id']?>">Aktif</label>
                    </div>
                  </td>
                  <td>
                    <div class="d-flex gap-2">
                      <button form="camp-form-<?=$c['id']?>" class="btn btn-zs" type="submit" name="do" value="camp_update">Güncelle</button>
                      <button form="camp-form-<?=$c['id']?>" class="btn btn-zs-outline" type="submit" name="do" value="camp_toggle">Durumu Değiştir</button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
<?php admin_layout_end(); ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
