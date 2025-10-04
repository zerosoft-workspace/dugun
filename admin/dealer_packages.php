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
    $st = pdo()->prepare("UPDATE dealer_packages SET name=?, description=?, price_cents=?, event_quota=?, duration_days=?, cashback_rate=?, is_active=?, updated_at=? WHERE id=?");
    $st->execute([$name, $description ?: null, $priceCents, $eventQuota, $durationDays, $cashbackRate, $isActive, $now, $packageId]);
    flash('ok', 'Paket güncellendi.');
    redirect($_SERVER['PHP_SELF'].'?id='.$packageId);
  } else {
    $st = pdo()->prepare("INSERT INTO dealer_packages (name, description, price_cents, event_quota, duration_days, cashback_rate, is_active, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)");
    $st->execute([$name, $description ?: null, $priceCents, $eventQuota, $durationDays, $cashbackRate, $isActive, $now, $now]);
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
  .card-lite form .form-label{font-weight:600;}
  .table thead th{vertical-align:middle;}
  .badge-status{padding:.35rem .75rem;border-radius:999px;font-size:.75rem;font-weight:600;}
  .status-active{background:rgba(34,197,94,.15);color:#15803d;}
  .status-passive{background:rgba(248,113,113,.16);color:#b91c1c;}
</style>
</head>
<body class="admin-body">
<?php render_admin_topnav('packages', 'Paket Yönetimi', 'Bayi paketlerini oluşturun, fiyatlarını güncelleyin ve satış durumlarını yönetin.'); ?>
<main class="admin-main">
  <div class="container">
    <?php flash_box(); ?>
    <div class="row g-4">
      <div class="col-lg-5">
        <div class="card-lite p-4">
          <h5 class="mb-3"><?= $editPackage ? 'Paketi Güncelle' : 'Yeni Paket Oluştur' ?></h5>
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
              <input class="form-control" name="price" value="<?= $editPackage ? number_format($editPackage['price_cents']/100, 2, ',', '') : ''?>" placeholder="Örn. 3000" required>
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
            <div class="col-12 d-grid">
              <button class="btn btn-brand" type="submit"><?= $editPackage ? 'Paketi Güncelle' : 'Paketi Kaydet' ?></button>
            </div>
          </form>
        </div>
      </div>
      <div class="col-lg-7">
        <div class="card-lite p-4">
          <h5 class="mb-3">Paket Listesi</h5>
          <div class="table-responsive">
            <table class="table align-middle mb-0">
              <thead><tr><th>Ad</th><th>Fiyat</th><th>Etkinlik</th><th>Süre</th><th>Cashback</th><th>Durum</th><th></th></tr></thead>
              <tbody>
                <?php if (!$packages): ?>
                  <tr><td colspan="7" class="text-center text-muted">Tanımlı paket bulunmuyor.</td></tr>
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
                        <div class="fw-semibold"><?=h($pkg['name'])?></div>
                        <?php if (!empty($pkg['description'])): ?><div class="small text-muted"><?=h($pkg['description'])?></div><?php endif; ?>
                      </td>
                      <td><?=h(format_currency($pkg['price_cents']))?></td>
                      <td><?=h($quotaLabel)?></td>
                      <td><?=h($durationLabel)?></td>
                      <td><?=h($cashbackLabel)?></td>
                      <td><span class="badge-status <?=$statusClass?>"><?=h($statusLabel)?></span></td>
                      <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="?id=<?= (int)$pkg['id'] ?>">Düzenle</a>
                        <form method="post" class="d-inline">
                          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                          <input type="hidden" name="do" value="toggle">
                          <input type="hidden" name="package_id" value="<?= (int)$pkg['id'] ?>">
                          <button class="btn btn-sm btn-outline-secondary" type="submit"><?= $pkg['is_active'] ? 'Pasifleştir' : 'Aktifleştir' ?></button>
                        </form>
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
  </div>
</main>
</body>
</html>
