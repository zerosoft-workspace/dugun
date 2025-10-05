<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/dealers.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/partials/ui.php';

require_admin();
require_superadmin();
install_schema();

$action = $_POST['do'] ?? '';
if ($action) {
  csrf_or_die();
}

if ($action === 'save') {
  $packageId = (int)($_POST['package_id'] ?? 0);
  $name = trim($_POST['name'] ?? '');
  $priceInput = $_POST['price'] ?? '';
  $quotaInput = trim($_POST['event_quota'] ?? '');
  $durationInput = trim($_POST['duration_days'] ?? '');
  $cashbackInput = trim($_POST['cashback_rate'] ?? '0');
  $description = trim($_POST['description'] ?? '');
  $isActive = isset($_POST['is_active']) ? 1 : 0;
  $isPublic = isset($_POST['is_public']) ? 1 : 0;

  if ($name === '') {
    flash('err', 'Paket adı zorunludur.');
    redirect($_SERVER['PHP_SELF'].($packageId ? '?id='.$packageId : ''));
  }

  $priceCents = money_to_cents($priceInput);
  if ($priceCents <= 0) {
    flash('err', 'Geçerli bir fiyat girin.');
    redirect($_SERVER['PHP_SELF'].($packageId ? '?id='.$packageId : ''));
  }

  $eventQuota = ($quotaInput === '' ? null : max(0, (int)$quotaInput));
  if ($eventQuota === 0) {
    $eventQuota = null;
  }
  $durationDays = ($durationInput === '' ? null : max(0, (int)$durationInput));
  if ($durationDays === 0) {
    $durationDays = null;
  }

  $cashbackPercent = (float)str_replace(',', '.', $cashbackInput);
  if ($cashbackPercent < 0) $cashbackPercent = 0;
  if ($cashbackPercent > 100) $cashbackPercent = 100;
  $cashbackRate = $cashbackPercent / 100;

  if ($eventQuota !== null && $eventQuota < 1) {
    flash('err', 'Etkinlik hakkı en az 1 olmalıdır.');
    redirect($_SERVER['PHP_SELF'].($packageId ? '?id='.$packageId : ''));
  }

  $now = now();
  if ($packageId > 0) {
    $st = pdo()->prepare("UPDATE dealer_packages SET name=?, description=?, price_cents=?, event_quota=?, duration_days=?, cashback_rate=?, is_active=?, is_public=?, updated_at=? WHERE id=?");
    $st->execute([$name, $description ?: null, $priceCents, $eventQuota, $durationDays, $cashbackRate, $isActive, $isPublic, $now, $packageId]);
    flash('ok', 'Paket güncellendi.');
    redirect($_SERVER['PHP_SELF'].'?id='.$packageId);
  } else {
    $st = pdo()->prepare("INSERT INTO dealer_packages (name, description, price_cents, event_quota, duration_days, cashback_rate, is_active, is_public, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $st->execute([$name, $description ?: null, $priceCents, $eventQuota, $durationDays, $cashbackRate, $isActive, $isPublic, $now, $now]);
    flash('ok', 'Yeni paket oluşturuldu.');
    redirect($_SERVER['PHP_SELF']);
  }
}

if ($action === 'toggle') {
  $packageId = (int)($_POST['package_id'] ?? 0);
  $pkg = dealer_package_get($packageId);
  if (!$pkg) {
    flash('err', 'Paket bulunamadı.');
    redirect($_SERVER['PHP_SELF']);
  }
  $newStatus = $pkg['is_active'] ? 0 : 1;
  pdo()->prepare("UPDATE dealer_packages SET is_active=?, updated_at=? WHERE id=?")
      ->execute([$newStatus, now(), $packageId]);
  flash('ok', 'Paket durumu güncellendi.');
  redirect($_SERVER['PHP_SELF']);
}

$packages = dealer_packages_all(false);
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editPackage = $editId ? dealer_package_get($editId) : null;

$packageTotals = [
  'all'    => count($packages),
  'active' => 0,
  'public' => 0,
];
foreach ($packages as $pkg) {
  if (!empty($pkg['is_active'])) {
    $packageTotals['active']++;
  }
  if (!empty($pkg['is_public'])) {
    $packageTotals['public']++;
  }
}

?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h(APP_NAME)?> — Bayi Paketleri</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?=admin_base_styles()?>
<style>
  .card-lite form .form-label { font-weight:600; }
  .stats-row .stat-card {
    border-radius:18px;
    background:#fff;
    border:1px solid rgba(14,165,181,.12);
    padding:22px 24px;
    box-shadow:0 28px 52px -38px rgba(14,165,181,.6);
    display:flex;
    flex-direction:column;
    gap:6px;
  }
  .stats-row .stat-label { font-size:.78rem; color:var(--admin-muted); letter-spacing:.08em; text-transform:uppercase; }
  .stats-row .stat-value { font-size:1.9rem; font-weight:700; color:var(--admin-ink); }
  .stats-row .stat-chip { display:inline-flex; align-items:center; gap:6px; padding:.28rem .9rem; border-radius:999px; background:rgba(14,165,181,.12); color:var(--admin-brand-dark); font-size:.75rem; font-weight:600; }

  .package-table thead th { vertical-align:middle; white-space:nowrap; }
  .package-table .badge-status { padding:.35rem .85rem; border-radius:999px; font-size:.75rem; font-weight:600; }
  .package-table .status-active { background:rgba(34,197,94,.18); color:#15803d; }
  .package-table .status-passive { background:rgba(248,113,113,.18); color:#b91c1c; }
  .package-table .status-public { background:rgba(14,165,181,.18); color:var(--admin-brand-dark); }
  .package-table .action-group { display:flex; gap:8px; flex-wrap:wrap; }

  .package-summary { border-radius:16px; background:rgba(14,165,181,.08); padding:18px; }
</style>
</head>
<body class="admin-body">
<?php admin_layout_start('packages', 'Paket Yönetimi', 'Bayi paketlerini oluşturun, fiyatlarını güncelleyin ve satış durumlarını yönetin.'); ?>
    <?php flash_box(); ?>

    <div class="row g-3 stats-row mb-4">
      <div class="col-md-4">
        <div class="stat-card">
          <span class="stat-label">Toplam Paket</span>
          <span class="stat-value"><?=$packageTotals['all']?></span>
          <span class="stat-chip"><i class="bi bi-boxes me-1"></i>Portföy</span>
        </div>
      </div>
      <div class="col-md-4">
        <div class="stat-card">
          <span class="stat-label">Satışta</span>
          <span class="stat-value text-success"><?=$packageTotals['active']?></span>
          <span class="stat-chip"><i class="bi bi-graph-up-arrow me-1"></i>Aktif</span>
        </div>
      </div>
      <div class="col-md-4">
        <div class="stat-card">
          <span class="stat-label">Web'de Yayında</span>
          <span class="stat-value text-primary"><?=$packageTotals['public']?></span>
          <span class="stat-chip"><i class="bi bi-globe2 me-1"></i>Site</span>
        </div>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-lg-5">
        <div class="card-lite p-4 h-100">
          <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
              <h5 class="mb-1"><?= $editPackage ? 'Paketi Güncelle' : 'Yeni Paket Oluştur' ?></h5>
              <p class="small text-muted mb-0">Bayi paketlerinizi fiyat, hak ve cashback kurallarına göre düzenleyin.</p>
            </div>
            <?php if ($editPackage): ?>
              <a href="<?=h($_SERVER['PHP_SELF'])?>" class="btn btn-light border btn-sm"><i class="bi bi-x-lg"></i></a>
            <?php endif; ?>
          </div>
          <form method="post" class="row g-3">
            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="do" value="save">
            <input type="hidden" name="package_id" value="<?= $editPackage ? (int)$editPackage['id'] : 0 ?>">
            <div class="col-12">
              <label class="form-label">Paket Adı</label>
              <input class="form-control" name="name" value="<?=h($editPackage['name'] ?? '')?>" required>
            </div>
            <div class="col-12">
              <label class="form-label">Fiyat (TL)</label>
              <input class="form-control" name="price" value="<?= $editPackage ? number_format($editPackage['price_cents']/100,2, ',', '') : ''?>" placeholder="Örn. 3000" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Etkinlik Hakkı</label>
              <input type="number" min="1" class="form-control" name="event_quota" value="<?= $editPackage && $editPackage['event_quota'] !== null ? (int)$editPackage['event_quota'] : '' ?>" placeholder="Sınırsız için boş bırakın">
            </div>
            <div class="col-md-6">
              <label class="form-label">Süre (gün)</label>
              <input type="number" min="0" class="form-control" name="duration_days" value="<?= $editPackage && $editPackage['duration_days'] !== null ? (int)$editPackage['duration_days'] : '' ?>" placeholder="Süre yoksa boş">
            </div>
            <div class="col-md-6">
              <label class="form-label">Cashback (%)</label>
              <input type="number" step="0.1" min="0" max="100" class="form-control" name="cashback_rate" value="<?= $editPackage ? number_format($editPackage['cashback_rate'] * 100, 1, ',', '') : '0' ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Açıklama</label>
              <textarea class="form-control" name="description" rows="3"><?=h($editPackage['description'] ?? '')?></textarea>
            </div>
            <div class="col-12 form-check ms-2">
              <input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?= !$editPackage || !empty($editPackage['is_active']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="is_active">Paket satışta</label>
            </div>
            <div class="col-12 form-check ms-2">
              <input class="form-check-input" type="checkbox" name="is_public" id="is_public" <?= $editPackage && empty($editPackage['is_public']) ? '' : 'checked' ?>>
              <label class="form-check-label" for="is_public">Web sitesinde göster</label>
            </div>
            <div class="col-12 d-grid">
              <button class="btn btn-brand" type="submit"><?= $editPackage ? 'Paketi Güncelle' : 'Paketi Kaydet' ?></button>
            </div>
          </form>
        </div>
      </div>
      <div class="col-lg-7">
        <div class="card-lite p-4 h-100">
          <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mb-3">
            <h5 class="mb-0">Paket Listesi</h5>
            <div class="package-summary small">
              <strong><?=$packageTotals['active']?> paket</strong> satışta • <?=$packageTotals['public']?> paket web sitesinde yayınlanıyor.
            </div>
          </div>
          <div class="table-responsive">
            <table class="table align-middle mb-0 package-table">
              <thead>
                <tr>
                  <th>Paket</th>
                  <th>Fiyat</th>
                  <th>Etkinlik</th>
                  <th>Süre</th>
                  <th>Cashback</th>
                  <th>Web</th>
                  <th>Durum</th>
                  <th style="width:200px">İşlemler</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$packages): ?>
                  <tr><td colspan="8" class="text-center text-muted">Tanımlı paket bulunmuyor.</td></tr>
                <?php else: ?>
                  <?php foreach ($packages as $pkg): ?>
                    <?php
                      $statusClass = $pkg['is_active'] ? 'status-active' : 'status-passive';
                      $statusLabel = $pkg['is_active'] ? 'Satışta' : 'Pasif';
                      $quotaLabel = $pkg['event_quota'] === null ? 'Sınırsız' : $pkg['event_quota'];
                      $durationLabel = $pkg['duration_days'] === null ? 'Süre yok' : $pkg['duration_days'].' gün';
                      $cashbackLabel = $pkg['cashback_rate'] > 0 ? number_format($pkg['cashback_rate'] * 100, 1).'%' : '—';
                    ?>
                    <tr>
                      <td>
                        <div class="fw-semibold mb-1"><?=h($pkg['name'])?></div>
                        <?php if (!empty($pkg['description'])): ?><div class="small text-muted"><?=h($pkg['description'])?></div><?php endif; ?>
                      </td>
                      <td><?=h(format_currency($pkg['price_cents']))?></td>
                      <td><?=h($quotaLabel)?></td>
                      <td><?=h($durationLabel)?></td>
                      <td><?=h($cashbackLabel)?></td>
                      <td>
                        <span class="badge-status <?= $pkg['is_public'] ? 'status-public' : 'status-passive' ?>"><?= $pkg['is_public'] ? 'Yayında' : 'Gizli' ?></span>
                      </td>
                      <td><span class="badge-status <?=$statusClass?>"><?=h($statusLabel)?></span></td>
                      <td>
                        <div class="action-group justify-content-end">
                          <a class="btn btn-sm btn-brand-outline" href="?id=<?= (int)$pkg['id'] ?>"><i class="bi bi-pencil me-1"></i>Düzenle</a>
                          <form method="post">
                            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                            <input type="hidden" name="do" value="toggle">
                            <input type="hidden" name="package_id" value="<?= (int)$pkg['id'] ?>">
                            <button class="btn btn-sm btn-light border" type="submit"><i class="bi bi-shuffle me-1"></i><?= $pkg['is_active'] ? 'Pasifleştir' : 'Aktifleştir' ?></button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
<?php admin_layout_end(); ?>
</body>
</html>
