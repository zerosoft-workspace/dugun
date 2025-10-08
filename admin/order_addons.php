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
    $detail = trim($_POST['detail'] ?? '');
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
      'detail' => $detail,
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

  if ($action === 'variant_save') {
    $addonId = (int)($_POST['parent_addon_id'] ?? 0);
    if ($addonId <= 0) {
      throw new RuntimeException('Geçerli bir ek hizmet seçin.');
    }
    $variantId = isset($_POST['variant_id']) ? (int)$_POST['variant_id'] : null;
    $name = trim($_POST['variant_name'] ?? '');
    if ($name === '') {
      throw new RuntimeException('Varyant adı zorunludur.');
    }
    $priceInput = $_POST['variant_price'] ?? '';
    $description = trim($_POST['variant_description'] ?? '');
    $detail = trim($_POST['variant_detail'] ?? '');
    $displayOrder = isset($_POST['variant_display_order']) ? (int)$_POST['variant_display_order'] : 0;
    $isActive = isset($_POST['variant_is_active']);

    $existing = $variantId ? site_addon_variant_get($variantId) : null;
    if ($existing && (int)$existing['addon_id'] !== $addonId) {
      throw new RuntimeException('Varyant kaydı bu ek hizmete ait değil.');
    }

    $imagePath = $existing['image_path'] ?? null;
    if (!empty($_POST['variant_remove_image']) && $imagePath) {
      site_addon_delete_file($imagePath);
      $imagePath = null;
    }
    if (!empty($_FILES['variant_image']['tmp_name'] ?? null)) {
      $newPath = site_addon_store_upload($_FILES['variant_image']);
      if ($imagePath && $imagePath !== $newPath) {
        site_addon_delete_file($imagePath);
      }
      $imagePath = $newPath;
    }

    $data = [
      'name' => $name,
      'price' => $priceInput,
      'description' => $description,
      'detail' => $detail,
      'display_order' => $displayOrder,
      'is_active' => $isActive,
      'image_path' => $imagePath,
    ];

    $savedId = site_addon_variant_save($addonId, $data, $variantId ?: null);
    flash('ok', $variantId ? 'Varyant güncellendi.' : 'Yeni varyant oluşturuldu.');
    redirect($_SERVER['PHP_SELF'].'?id='.$addonId.'&variant='.(int)$savedId);
  }

  if ($action === 'variant_delete') {
    $variantId = (int)($_POST['variant_id'] ?? 0);
    $variant = site_addon_variant_get($variantId);
    if (!$variant) {
      throw new RuntimeException('Varyant bulunamadı.');
    }
    site_addon_variant_delete($variantId);
    flash('ok', 'Varyant silindi.');
    redirect($_SERVER['PHP_SELF'].'?id='.(int)$variant['addon_id']);
  }

  if ($action === 'variant_toggle') {
    $variantId = (int)($_POST['variant_id'] ?? 0);
    $variant = site_addon_variant_get($variantId);
    if (!$variant) {
      throw new RuntimeException('Varyant bulunamadı.');
    }
    $newStatus = $variant['is_active'] ? 0 : 1;
    pdo()->prepare('UPDATE site_addon_variants SET is_active=?, updated_at=? WHERE id=?')->execute([
      $newStatus,
      now(),
      $variantId,
    ]);
    flash('ok', 'Varyant durumu güncellendi.');
    redirect($_SERVER['PHP_SELF'].'?id='.(int)$variant['addon_id'].'&variant='.(int)$variantId);
  }
} catch (Throwable $e) {
  flash('err', $e->getMessage());
  $targetAddon = 0;
  if (!empty($_POST['addon_id'])) {
    $targetAddon = (int)$_POST['addon_id'];
  } elseif (!empty($_POST['parent_addon_id'])) {
    $targetAddon = (int)$_POST['parent_addon_id'];
  }
  $query = '';
  if ($targetAddon > 0) {
    $query = '?id='.$targetAddon;
    if (!empty($_POST['variant_id'])) {
      $query .= '&variant='.(int)$_POST['variant_id'];
    }
  }
  redirect($_SERVER['PHP_SELF'].$query);
}

$addons = site_addon_all(false);
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editAddon = $editId ? site_addon_get($editId) : null;

$editVariantId = isset($_GET['variant']) ? (int)$_GET['variant'] : 0;
$editVariant = null;
if ($editVariantId && $editAddon) {
  $candidate = site_addon_variant_get($editVariantId);
  if ($candidate && (int)$candidate['addon_id'] === (int)$editAddon['id']) {
    $editVariant = $candidate;
  } else {
    $editVariantId = 0;
  }
}

$stats = [
  'total' => count($addons),
  'active' => 0,
  'inactive' => 0,
];
$variantStats = [
  'total' => 0,
  'active' => 0,
];
foreach ($addons as $addon) {
  if (!empty($addon['is_active'])) {
    $stats['active']++;
  } else {
    $stats['inactive']++;
  }
  if (!empty($addon['variants']) && is_array($addon['variants'])) {
    foreach ($addon['variants'] as $variant) {
      $variantStats['total']++;
      if (!empty($variant['is_active'])) {
        $variantStats['active']++;
      }
    }
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
    <div class="stat-tile">
      <span>Varyant</span>
      <strong><?=$variantStats['total']?></strong>
      <div class="text-muted small">Aktif: <?=$variantStats['active']?></div>
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
            <label class="form-label">Detay</label>
            <textarea class="form-control" name="detail" rows="4" placeholder="Müşteriye gösterilecek ek bilgiler, içerik adımları..."><?=h($editAddon['detail'] ?? '')?></textarea>
            <div class="form-text">Bu alan uzun açıklamalar, teslimat içeriği veya teknik detaylar için kullanılabilir.</div>
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
      <?php if ($editAddon): ?>
        <div class="addon-card mt-4">
          <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
              <h5 class="mb-1"><?= $editVariant ? 'Varyantı Güncelle' : 'Yeni Varyant' ?></h5>
              <p class="small text-muted mb-0">Davetiye tasarımları gibi varyantlı hizmetleri burada yönetin. Her varyant için ayrı fiyat, açıklama ve görsel belirleyebilirsiniz.</p>
            </div>
            <?php if ($editVariant): ?>
              <a class="btn btn-light border btn-sm" href="<?=h($_SERVER['PHP_SELF'].'?id='.(int)$editAddon['id'])?>"><i class="bi bi-x-lg"></i></a>
            <?php endif; ?>
          </div>
          <form method="post" class="row g-3" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="do" value="variant_save">
            <input type="hidden" name="parent_addon_id" value="<?= (int)$editAddon['id'] ?>">
            <input type="hidden" name="variant_id" value="<?= $editVariant ? (int)$editVariant['id'] : 0 ?>">
            <div class="col-12">
              <label class="form-label">Varyant Adı</label>
              <input class="form-control" name="variant_name" value="<?=h($editVariant['name'] ?? '')?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Fiyat (TL)</label>
              <input class="form-control" name="variant_price" value="<?= $editVariant ? number_format($editVariant['price_cents']/100, 2, ',', '') : '' ?>" placeholder="Örn. 149" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Sıra</label>
              <input type="number" class="form-control" name="variant_display_order" value="<?= $editVariant ? (int)$editVariant['display_order'] : 0 ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Kısa Açıklama</label>
              <textarea class="form-control" name="variant_description" rows="2" placeholder="Örn. Altın yaldızlı davetiye seti"><?=h($editVariant['description'] ?? '')?></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Detay</label>
              <textarea class="form-control" name="variant_detail" rows="4" placeholder="Paket içeriği, teslimat süresi gibi bilgiler"><?=h($editVariant['detail'] ?? '')?></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Varyant Görseli</label>
              <input type="file" class="form-control" name="variant_image" accept="image/*">
              <?php if (!empty($editVariant['image_url'])): ?>
                <div class="d-flex align-items-center justify-content-between mt-2">
                  <div class="addon-thumb"><img src="<?=h($editVariant['image_url'])?>" alt="<?=h($editVariant['name'])?>"></div>
                  <div class="form-check ms-3">
                    <input class="form-check-input" type="checkbox" name="variant_remove_image" id="variant-remove-image">
                    <label class="form-check-label" for="variant-remove-image">Görseli kaldır</label>
                  </div>
                </div>
              <?php endif; ?>
            </div>
            <div class="col-12 form-check form-switch">
              <input class="form-check-input" type="checkbox" name="variant_is_active" id="variant-active" <?= (!$editVariant || !empty($editVariant['is_active'])) ? 'checked' : '' ?>>
              <label class="form-check-label" for="variant-active">Satışta olsun</label>
            </div>
            <div class="col-12">
              <button type="submit" class="btn btn-primary w-100"><?= $editVariant ? 'Varyantı Güncelle' : 'Varyantı Kaydet' ?></button>
            </div>
          </form>
        </div>
      <?php endif; ?>
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
                          <?php if (!empty($addon['detail'])): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" data-addon-detail-admin data-name="<?=h($addon['name'])?>" data-description="<?=h($addon['description'] ?? '')?>" data-detail="<?=h($addon['detail'])?>" data-image="<?=h($addon['image_url'] ?? '')?>"><i class="bi bi-info-circle"></i> Detayı Gör</button>
                          <?php endif; ?>
                          <?php if (!empty($addon['category'])): ?>
                            <span class="badge bg-light text-secondary mt-1"><?=h($addon['category'])?></span>
                          <?php endif; ?>
                          <?php $variantCount = !empty($addon['variants']) ? count($addon['variants']) : 0; ?>
                          <?php if ($variantCount): ?>
                            <span class="badge bg-info-subtle text-info-emphasis mt-1"><?=$variantCount?> varyant</span>
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
      <?php if ($editAddon): ?>
        <?php $variantList = $editAddon['variants'] ?? []; ?>
        <div class="card p-4 border-0 shadow-sm rounded-4 mt-4">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0"><?=h($editAddon['name'])?> için Varyantlar</h5>
            <span class="badge bg-info-subtle text-info-emphasis"><?=count($variantList)?> kayıt</span>
          </div>
          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>Varyant</th>
                  <th>Fiyat</th>
                  <th>Durum</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$variantList): ?>
                  <tr>
                    <td colspan="4" class="text-center text-muted py-4">Bu ek hizmet için henüz varyant eklenmemiş.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($variantList as $variant): ?>
                    <tr>
                      <td>
                        <div class="d-flex align-items-start gap-3">
                          <div class="addon-thumb">
                            <?php if (!empty($variant['image_url'])): ?>
                              <img src="<?=h($variant['image_url'])?>" alt="<?=h($variant['name'])?>">
                            <?php else: ?>
                              <span class="addon-thumb__placeholder"><i class="bi bi-image"></i></span>
                            <?php endif; ?>
                          </div>
                          <div>
                            <strong><?=h($variant['name'])?></strong>
                            <?php if (!empty($variant['description'])): ?>
                              <div class="small text-muted"><?=nl2br(h($variant['description']))?></div>
                            <?php endif; ?>
                            <?php if (!empty($variant['detail'])): ?>
                              <button type="button" class="btn btn-sm btn-outline-secondary mt-2" data-variant-detail-admin data-name="<?=h($variant['name'])?>" data-description="<?=h($variant['description'] ?? '')?>" data-detail="<?=h($variant['detail'])?>" data-image="<?=h($variant['image_url'] ?? '')?>"><i class="bi bi-info-circle"></i> Detayı Gör</button>
                            <?php endif; ?>
                          </div>
                        </div>
                      </td>
                      <td><?=h(format_currency((int)$variant['price_cents']))?></td>
                      <td>
                        <?php if (!empty($variant['is_active'])): ?>
                          <span class="badge-active">Aktif</span>
                        <?php else: ?>
                          <span class="badge-passive">Pasif</span>
                        <?php endif; ?>
                      </td>
                      <td class="text-end">
                        <div class="d-inline-flex gap-2">
                          <a class="btn btn-sm btn-outline-primary" href="<?=h($_SERVER['PHP_SELF'].'?id='.(int)$editAddon['id'].'&variant='.(int)$variant['id'])?>"><i class="bi bi-pencil"></i></a>
                          <form method="post" class="d-inline" onsubmit="return confirm('Bu varyantı silmek istediğinize emin misiniz?');">
                            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                            <input type="hidden" name="do" value="variant_delete">
                            <input type="hidden" name="variant_id" value="<?= (int)$variant['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                          </form>
                          <form method="post" class="d-inline">
                            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                            <input type="hidden" name="do" value="variant_toggle">
                            <input type="hidden" name="variant_id" value="<?= (int)$variant['id'] ?>">
                            <button class="btn btn-sm btn-outline-secondary" type="submit"><?=!empty($variant['is_active']) ? 'Pasifleştir' : 'Aktif Et'?></button>
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
      <?php endif; ?>
    </div>
  </div>
  <div class="modal fade" id="addonDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content border-0 rounded-4 shadow-lg">
        <div class="modal-header border-0 pb-0">
          <h5 class="modal-title fw-semibold" data-addon-modal-title>Ek Hizmet Detayı</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3 d-none" data-addon-modal-image></div>
          <p class="text-muted" data-addon-modal-description></p>
          <div data-addon-modal-detail class="small text-muted"></div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
        </div>
      </div>
    </div>
  </div>
  <div class="modal fade" id="variantDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content border-0 rounded-4 shadow-lg">
        <div class="modal-header border-0 pb-0">
          <h5 class="modal-title fw-semibold" data-variant-modal-title>Varyant Detayı</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3 d-none" data-variant-modal-image></div>
          <p class="text-muted d-none" data-variant-modal-description></p>
          <div data-variant-modal-detail class="small text-muted"></div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
        </div>
      </div>
    </div>
  </div>
<?php admin_layout_end(); ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('addonDetailModal');
    if (!modalEl) {
      return;
    }
    var modal = new bootstrap.Modal(modalEl);
    var titleEl = modalEl.querySelector('[data-addon-modal-title]');
    var descEl = modalEl.querySelector('[data-addon-modal-description]');
    var detailEl = modalEl.querySelector('[data-addon-modal-detail]');
    var imageEl = modalEl.querySelector('[data-addon-modal-image]');

    var escapeHtml = function (str) {
      var map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
      };
      return (str || '').replace(/[&<>"']/g, function (ch) {
        return map[ch] || ch;
      });
    };

    document.querySelectorAll('[data-addon-detail-admin]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var name = btn.getAttribute('data-name') || '';
        var description = btn.getAttribute('data-description') || '';
        var detail = btn.getAttribute('data-detail') || '';
        var image = btn.getAttribute('data-image') || '';

        if (titleEl) {
          titleEl.textContent = name;
        }
        if (descEl) {
          if (description.trim() !== '') {
            descEl.textContent = description;
            descEl.classList.remove('d-none');
          } else {
            descEl.textContent = '';
            descEl.classList.add('d-none');
          }
        }
        if (detailEl) {
          if (detail.trim() !== '') {
            var html = detail.split(/\r?\n/).map(function (line) {
              return escapeHtml(line);
            }).join('<br>');
            detailEl.innerHTML = html;
            detailEl.classList.remove('text-muted');
          } else {
            detailEl.innerHTML = '<span class="text-muted">Ek detay eklenmemiş.</span>';
          }
        }
        if (imageEl) {
          if (image) {
            imageEl.innerHTML = '<img src="' + escapeHtml(image) + '" alt="' + escapeHtml(name) + '" class="img-fluid rounded-4 shadow-sm">';
            imageEl.classList.remove('d-none');
          } else {
            imageEl.innerHTML = '';
            imageEl.classList.add('d-none');
          }
        }
        modal.show();
      });
    });

    var variantModalEl = document.getElementById('variantDetailModal');
    if (variantModalEl) {
      var variantModal = new bootstrap.Modal(variantModalEl);
      var variantTitleEl = variantModalEl.querySelector('[data-variant-modal-title]');
      var variantDescEl = variantModalEl.querySelector('[data-variant-modal-description]');
      var variantDetailEl = variantModalEl.querySelector('[data-variant-modal-detail]');
      var variantImageEl = variantModalEl.querySelector('[data-variant-modal-image]');

      document.querySelectorAll('[data-variant-detail-admin]').forEach(function (btn) {
        btn.addEventListener('click', function (event) {
          if (event) {
            event.preventDefault();
            event.stopPropagation();
          }
          var name = btn.getAttribute('data-name') || '';
          var description = btn.getAttribute('data-description') || '';
          var detail = btn.getAttribute('data-detail') || '';
          var image = btn.getAttribute('data-image') || '';

          if (variantTitleEl) {
            variantTitleEl.textContent = name;
          }
          if (variantDescEl) {
            if (description.trim() !== '') {
              variantDescEl.textContent = description;
              variantDescEl.classList.remove('d-none');
            } else {
              variantDescEl.textContent = '';
              variantDescEl.classList.add('d-none');
            }
          }
          if (variantDetailEl) {
            if (detail.trim() !== '') {
              var html = detail.split(/\r?\n/).map(function (line) {
                return escapeHtml(line);
              }).join('<br>');
              variantDetailEl.innerHTML = html;
              variantDetailEl.classList.remove('text-muted');
            } else {
              variantDetailEl.innerHTML = '<span class="text-muted">Ek detay eklenmemiş.</span>';
              variantDetailEl.classList.remove('text-muted');
            }
          }
          if (variantImageEl) {
            if (image) {
              variantImageEl.innerHTML = '<img src="' + escapeHtml(image) + '" alt="' + escapeHtml(name) + '" class="img-fluid rounded-4 shadow-sm">';
              variantImageEl.classList.remove('d-none');
            } else {
              variantImageEl.innerHTML = '';
              variantImageEl.classList.add('d-none');
            }
          }

          variantModal.show();
        });
      });
    }
  });
</script>
</body>
</html>
