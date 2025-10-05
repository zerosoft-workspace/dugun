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

$campaignsStmt = pdo()->prepare("SELECT * FROM campaigns WHERE venue_id=? ORDER BY is_active DESC, id DESC");
$campaignsStmt->execute([$VID]);
$campaigns = $campaignsStmt->fetchAll();

$totals = [
  'all'    => count($campaigns),
  'active' => 0,
  'passive'=> 0,
];
foreach ($campaigns as $c) {
  if (!empty($c['is_active'])) {
    $totals['active']++;
  } else {
    $totals['passive']++;
  }
}

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
  .grid-compact .form-control, .grid-compact .form-select { height:46px; }
  .muted { color:var(--muted); }
  .btn-zs { background:var(--brand); border:none; color:#fff; border-radius:12px; font-weight:600; }
  .btn-zs:hover { background:var(--brand-dark); color:#fff; }
  .btn-zs-outline { background:#fff; border:1px solid rgba(14,165,181,.55); color:var(--brand); border-radius:12px; font-weight:600; }
  .btn-zs-outline:hover { background:rgba(14,165,181,.12); color:var(--brand-dark); }

  .stats-row .stat-card {
    border-radius:18px;
    background:#fff;
    border:1px solid rgba(14,165,181,.12);
    padding:20px 22px;
    box-shadow:0 24px 45px -34px rgba(14,165,181,.6);
    display:flex;
    flex-direction:column;
    gap:6px;
  }
  .stats-row .stat-label { font-size:.8rem; color:var(--admin-muted); text-transform:uppercase; letter-spacing:.08em; }
  .stats-row .stat-value { font-size:1.8rem; font-weight:700; color:var(--admin-ink); }
  .stats-row .stat-chip {
    align-self:flex-start;
    background:rgba(14,165,181,.12);
    color:var(--admin-brand-dark);
    border-radius:999px;
    padding:.25rem .8rem;
    font-size:.75rem;
    font-weight:600;
  }

  .campaign-table .form-control,
  .campaign-table textarea { background:rgba(15,23,42,.02); border-color:rgba(148,163,184,.35); }
  .campaign-table textarea { resize:vertical; }
  .campaign-table .badge-status { border-radius:999px; padding:.35rem .9rem; font-weight:600; font-size:.75rem; }
  .campaign-table .badge-active { background:rgba(34,197,94,.18); color:#15803d; }
  .campaign-table .badge-passive { background:rgba(248,113,113,.18); color:#b91c1c; }
  .campaign-table .action-group { display:flex; gap:10px; }
  .campaign-table .action-group .btn { border-radius:12px; font-weight:600; }
</style>
</head>
<body class="admin-body">
<?php admin_layout_start('campaigns', 'Kampanya Yönetimi', 'Salon: '.$VNAME.' • Kampanyalar tüm etkinlik panellerinde gösterilir.'); ?>
    <?php flash_box(); ?>

    <div class="row g-3 stats-row mb-4">
      <div class="col-md-4">
        <div class="stat-card">
          <span class="stat-label">Toplam Kampanya</span>
          <span class="stat-value"><?=$totals['all']?></span>
          <span class="stat-chip"><i class="bi bi-bullseye me-1"></i>Tümü</span>
        </div>
      </div>
      <div class="col-md-4">
        <div class="stat-card">
          <span class="stat-label">Aktif</span>
          <span class="stat-value text-success"><?=$totals['active']?></span>
          <span class="stat-chip"><i class="bi bi-broadcast-pin me-1"></i>Yayında</span>
        </div>
      </div>
      <div class="col-md-4">
        <div class="stat-card">
          <span class="stat-label">Pasif</span>
          <span class="stat-value text-danger"><?=$totals['passive']?></span>
          <span class="stat-chip"><i class="bi bi-pause-circle me-1"></i>Arşiv</span>
        </div>
      </div>
    </div>

    <div class="card-lite mb-4">
      <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mb-3">
        <div>
          <h5 class="m-0">Yeni Kampanya</h5>
          <span class="muted small">Eklediğiniz kampanyalar salonunuzdaki tüm etkinlik panellerine yansır.</span>
        </div>
        <a href="#campaign-list" class="btn btn-zs-outline"><i class="bi bi-layout-text-window me-1"></i>Listeyi Gör</a>
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
          <button class="btn btn-zs w-100"><i class="bi bi-plus-lg me-1"></i>Kampanyayı Kaydet</button>
        </div>
      </form>
    </div>

    <div class="card-lite" id="campaign-list">
      <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mb-3">
        <h5 class="mb-0">Kampanya Listesi</h5>
        <span class="muted small">Listeden düzenleyebilir, pasife alabilir veya hızlıca kopya oluşturabilirsiniz.</span>
      </div>
      <?php if (!$campaigns): ?>
        <div class="muted">Henüz kampanya eklenmedi.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table align-middle campaign-table">
            <thead>
              <tr>
                <th style="min-width:220px">Ad & Açıklama</th>
                <th style="width:160px">Tür</th>
                <th style="width:140px">Fiyat</th>
                <th style="width:110px">Durum</th>
                <th style="width:240px">İşlemler</th>
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
                  <td class="text-nowrap">
                    <span class="badge-status <?= $c['is_active'] ? 'badge-active' : 'badge-passive' ?>"><?= $c['is_active'] ? 'Aktif' : 'Pasif' ?></span>
                    <div class="form-check form-switch mt-2">
                      <input form="camp-form-<?=$c['id']?>" class="form-check-input" type="checkbox" value="1" name="is_active" id="camp-active-<?=$c['id']?>" <?= $c['is_active'] ? 'checked' : '' ?>>
                      <label class="form-check-label" for="camp-active-<?=$c['id']?>">Yayında</label>
                    </div>
                  </td>
                  <td>
                    <div class="action-group">
                      <button form="camp-form-<?=$c['id']?>" class="btn btn-zs" type="submit" name="do" value="camp_update"><i class="bi bi-save me-1"></i>Kaydet</button>
                      <button form="camp-form-<?=$c['id']?>" class="btn btn-zs-outline" type="submit" name="do" value="camp_toggle"><i class="bi bi-shuffle me-1"></i>Durumu Değiştir</button>
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
