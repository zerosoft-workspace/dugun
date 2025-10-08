<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/addons.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/partials/ui.php';

require_admin();
require_superadmin();
install_schema();

$action = $_POST['do'] ?? '';
if ($action) {
  csrf_or_die();
}

try {
  if ($action === 'save') {
    $addonId = isset($_POST['addon_id']) ? (int)$_POST['addon_id'] : null;
    $name = trim($_POST['name'] ?? '');
    $priceInput = $_POST['price'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $displayOrder = isset($_POST['display_order']) ? (int)$_POST['display_order'] : 0;
    $isActive = isset($_POST['is_active']);

    if ($name === '') {
      throw new RuntimeException('Hizmet adı zorunludur.');
    }

    $priceCents = money_to_cents((string)$priceInput);
    if ($priceCents <= 0) {
      throw new RuntimeException('Geçerli bir fiyat girin.');
    }

    $existing = $addonId ? site_addon_get($addonId) : null;
    $meta = $existing['meta'] ?? [];
    if (!is_array($meta)) {
      $meta = [];
    }
    $imagePath = $existing['image_path'] ?? ($meta['image_path'] ?? null);

    if (!empty($_POST['remove_image']) && $imagePath) {
      site_addon_delete_file($imagePath);
      $imagePath = null;
    }

    if (!empty($_FILES['image']['tmp_name'] ?? null)) {
      $newPath = site_addon_store_upload($_FILES['image']);
      if ($imagePath && $imagePath !== $newPath) {
        site_addon_delete_file($imagePath);
      }
      $imagePath = $newPath;
    }

    $data = [
      'name' => $name,
      'price_cents' => $priceCents,
      'description' => $description,
      'category' => $category,
      'display_order' => $displayOrder,
      'is_active' => $isActive,
      'image_path' => $imagePath,
      'meta' => $meta,
    ];

    $id = site_addon_save($data, $addonId ?: null);
    flash('ok', $addonId ? 'Ek hizmet güncellendi.' : 'Yeni ek hizmet oluşturuldu.');
    redirect($_SERVER['PHP_SELF'].'?id='.(int)$id);
  }

  if ($action === 'toggle') {
    $addonId = (int)($_POST['addon_id'] ?? 0);
    $addon = site_addon_get($addonId);
    if (!$addon) {
      throw new RuntimeException('Ek hizmet bulunamadı.');
    }
    $newStatus = $addon['is_active'] ? 0 : 1;
    pdo()->prepare('UPDATE site_addons SET is_active=?, updated_at=? WHERE id=?')->execute([$newStatus, now(), $addonId]);
    flash('ok', 'Ek hizmet durumu güncellendi.');
    redirect($_SERVER['PHP_SELF']);
  }

  if ($action === 'delete') {
    $addonId = (int)($_POST['addon_id'] ?? 0);
    $addon = site_addon_get($addonId);
    if (!$addon) {
      throw new RuntimeException('Ek hizmet bulunamadı.');
    }
    try {
      site_addon_delete($addonId);
    } catch (Throwable $e) {
      throw new RuntimeException('Bu ek hizmet siparişlerde kullanıldığı için silinemedi. Pasif hale getirerek listeden kaldırabilirsiniz.');
    }
    flash('ok', 'Ek hizmet silindi.');
    redirect($_SERVER['PHP_SELF']);
  }
} catch (Throwable $e) {
  flash('err', $e->getMessage());
  redirect($_SERVER['PHP_SELF'].(!empty($_POST['addon_id']) ? '?id='.(int)$_POST['addon_id'] : ''));
}

$addons = site_addon_all(false);
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editAddon = $editId ? site_addon_get($editId) : null;

$stats = [
  'total' => count($addons),
  'active' => 0,
  'inactive' => 0,
];
foreach ($addons as $addon) {
  if (!empty($addon['is_active'])) {
    $stats['active']++;
  } else {
    $stats['inactive']++;
  }
}

?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h(APP_NAME)?> — Ek Hizmetler</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?=admin_base_styles()?>
<style>
  .addon-card{border-radius:18px;background:#fff;border:1px solid rgba(14,165,181,.12);padding:22px;box-shadow:0 26px 60px -36px rgba(14,165,181,.55);}
  .addon-card h5{font-weight:700;}
  .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;margin-bottom:28px;}
  .stat-tile{border-radius:16px;background:linear-gradient(140deg,rgba(14,165,181,.12),rgba(59,130,246,.08));padding:20px;display:flex;flex-direction:column;gap:6px;box-shadow:0 24px 60px -38px rgba(15,23,42,.25);}
  .stat-tile span{font-size:.82rem;color:var(--admin-muted);letter-spacing:.04em;text-transform:uppercase;}
  .stat-tile strong{font-size:1.6rem;}
  .table thead th{font-size:.78rem;text-transform:uppercase;letter-spacing:.08em;color:var(--admin-muted);}
  .badge-active{background:rgba(34,197,94,.18);color:#15803d;border-radius:999px;padding:.35rem .85rem;font-weight:600;}
  .badge-passive{background:rgba(248,113,113,.18);color:#b91c1c;border-radius:999px;padding:.35rem .85rem;font-weight:600;}
  .addon-thumb{width:72px;height:72px;border-radius:18px;overflow:hidden;background:rgba(14,165,181,.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
  .addon-thumb img{width:100%;height:100%;object-fit:cover;}
  .addon-thumb__placeholder{color:rgba(14,165,181,.6);font-size:1.4rem;}
</style>
</head>
<body class="admin-body">
<?php admin_layout_start('order_addons', 'Ek Hizmetler', 'Sipariş sonrası sunulan ek hizmetleri yönetin, fiyatlarını ve sıralamalarını düzenleyin.', 'bi-stars'); ?>
  <?php flash_box(); ?>

  <div class="stats-grid">
    <div class="stat-tile">
      <span>Toplam Hizmet</span>
      <strong><?=$stats['total']?></strong>
    </div>
    <div class="stat-tile">
      <span>Aktif</span>
      <strong class="text-success"><?=$stats['active']?></strong>
    </div>
    <div class="stat-tile">
      <span>Pasif</span>
      <strong class="text-danger"><?=$stats['inactive']?></strong>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-lg-5">
      <div class="addon-card">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <div>
            <h5 class="mb-1"><?= $editAddon ? 'Ek Hizmeti Güncelle' : 'Yeni Ek Hizmet' ?></h5>
            <p class="small text-muted mb-0">Ödeme öncesi sunulacak ek hizmetleri isim, açıklama ve fiyat bilgileriyle oluşturun.</p>
          </div>
          <?php if ($editAddon): ?>
            <a class="btn btn-light border btn-sm" href="<?=h($_SERVER['PHP_SELF'])?>"><i class="bi bi-x-lg"></i></a>
          <?php endif; ?>
        </div>
        <form method="post" class="row g-3" enctype="multipart/form-data">
          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="do" value="save">
          <input type="hidden" name="addon_id" value="<?= $editAddon ? (int)$editAddon['id'] : 0 ?>">
          <div class="col-12">
            <label class="form-label">Hizmet Adı</label>
            <input class="form-control" name="name" value="<?=h($editAddon['name'] ?? '')?>" required>
          </div>
          <div class="col-12">
            <label class="form-label">Fiyat (TL)</label>
            <input class="form-control" name="price" value="<?= $editAddon ? number_format($editAddon['price_cents']/100, 2, ',', '') : '' ?>" placeholder="Örn. 299" required>
          </div>
          <div class="col-12">
            <label class="form-label">Açıklama</label>
            <textarea class="form-control" name="description" rows="3" placeholder="Hizmet detayları..."><?=h($editAddon['description'] ?? '')?></textarea>
          </div>
          <div class="col-12">
            <label class="form-label">Görsel</label>
            <input type="file" class="form-control" name="image" accept="image/*">
            <?php if (!empty($editAddon['image_url'])): ?>
              <div class="d-flex align-items-center justify-content-between mt-2">
                <div class="addon-thumb"><img src="<?=h($editAddon['image_url'])?>" alt="<?=h($editAddon['name'])?>"></div>
                <div class="form-check ms-3">
                  <input class="form-check-input" type="checkbox" name="remove_image" id="remove-addon-image">
                  <label class="form-check-label" for="remove-addon-image">Görseli kaldır</label>
                </div>
              </div>
            <?php endif; ?>
          </div>
          <div class="col-md-6">
            <label class="form-label">Kategori</label>
            <input class="form-control" name="category" value="<?=h($editAddon['category'] ?? '')?>" placeholder="Opsiyonel">
          </div>
          <div class="col-md-6">
            <label class="form-label">Sıra</label>
            <input type="number" class="form-control" name="display_order" value="<?= $editAddon ? (int)$editAddon['display_order'] : 0 ?>">
          </div>
          <div class="col-12 form-check form-switch">
            <input class="form-check-input" type="checkbox" name="is_active" id="addon-active" <?= (!$editAddon || !empty($editAddon['is_active'])) ? 'checked' : '' ?>>
            <label class="form-check-label" for="addon-active">Satışta olsun</label>
          </div>
          <div class="col-12">
            <button type="submit" class="btn btn-primary w-100">Kaydet</button>
          </div>
        </form>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="card p-4 border-0 shadow-sm rounded-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h5 class="mb-0">Ek Hizmet Listesi</h5>
          <span class="badge bg-info-subtle text-info-emphasis"><?=count($addons)?> kayıt</span>
        </div>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>Hizmet</th>
                <th>Fiyat</th>
                <th>Durum</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$addons): ?>
                <tr>
                  <td colspan="4" class="text-center text-muted py-4">Henüz ek hizmet oluşturulmadı.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($addons as $addon): ?>
                  <tr>
                    <td>
                      <div class="d-flex align-items-start gap-3">
                        <div class="addon-thumb">
                          <?php if (!empty($addon['image_url'])): ?>
                            <img src="<?=h($addon['image_url'])?>" alt="<?=h($addon['name'])?>">
                          <?php else: ?>
                            <span class="addon-thumb__placeholder"><i class="bi bi-image"></i></span>
                          <?php endif; ?>
                        </div>
                        <div>
                          <strong><?=h($addon['name'])?></strong>
                          <?php if (!empty($addon['description'])): ?>
                            <div class="small text-muted"><?=nl2br(h($addon['description']))?></div>
                          <?php endif; ?>
                          <?php if (!empty($addon['category'])): ?>
                            <span class="badge bg-light text-secondary mt-1"><?=h($addon['category'])?></span>
                          <?php endif; ?>
                        </div>
                      </div>
                    </td>
                    <td><?=h(format_currency((int)$addon['price_cents']))?></td>
                    <td>
                      <?php if (!empty($addon['is_active'])): ?>
                        <span class="badge-active">Aktif</span>
                      <?php else: ?>
                        <span class="badge-passive">Pasif</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end">
                      <div class="d-inline-flex gap-2">
                        <a class="btn btn-sm btn-outline-primary" href="<?=h($_SERVER['PHP_SELF'].'?id='.(int)$addon['id'])?>"><i class="bi bi-pencil"></i></a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Bu ek hizmeti silmek istediğinize emin misiniz?');">
                          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                          <input type="hidden" name="do" value="delete">
                          <input type="hidden" name="addon_id" value="<?= (int)$addon['id'] ?>">
                          <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                        </form>
                        <form method="post" class="d-inline">
                          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                          <input type="hidden" name="do" value="toggle">
                          <input type="hidden" name="addon_id" value="<?= (int)$addon['id'] ?>">
                          <button class="btn btn-sm btn-outline-secondary" type="submit"><?=!empty($addon['is_active']) ? 'Pasifleştir' : 'Aktif Et'?></button>
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
