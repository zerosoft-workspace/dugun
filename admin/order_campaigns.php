<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/campaigns.php';
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
    $campaignId = isset($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : null;
    $name = trim($_POST['name'] ?? '');
    $priceInput = $_POST['price'] ?? '';
    $summary = trim($_POST['summary'] ?? '');
    $detail = trim($_POST['detail'] ?? '');
    $displayOrder = isset($_POST['display_order']) ? (int)$_POST['display_order'] : 0;
    $isActive = isset($_POST['is_active']);

    if ($name === '') {
      throw new RuntimeException('Kampanya adı zorunludur.');
    }

    $priceCents = money_to_cents((string)$priceInput);
    if ($priceCents <= 0) {
      throw new RuntimeException('Geçerli bir bağış tutarı girin.');
    }

    $existing = $campaignId ? site_campaign_get($campaignId) : null;
    $imagePath = $existing['image_path'] ?? null;

    if (!empty($_POST['remove_image']) && $imagePath) {
      site_campaign_delete_file($imagePath);
      $imagePath = null;
    }

    if (!empty($_FILES['image']['tmp_name'])) {
      $newPath = site_campaign_store_upload($_FILES['image']);
      if ($imagePath && $imagePath !== $newPath) {
        site_campaign_delete_file($imagePath);
      }
      $imagePath = $newPath;
    }

    $data = [
      'name' => $name,
      'price_cents' => $priceCents,
      'summary' => $summary,
      'detail' => $detail,
      'display_order' => $displayOrder,
      'is_active' => $isActive,
      'image_path' => $imagePath,
    ];

    $id = site_campaign_save($data, $campaignId ?: null);
    flash('ok', $campaignId ? 'Kampanya güncellendi.' : 'Yeni kampanya oluşturuldu.');
    redirect($_SERVER['PHP_SELF'].'?id='.(int)$id);
  }

  if ($action === 'toggle') {
    $campaignId = (int)($_POST['campaign_id'] ?? 0);
    $campaign = site_campaign_get($campaignId);
    if (!$campaign) {
      throw new RuntimeException('Kampanya kaydı bulunamadı.');
    }
    $newStatus = $campaign['is_active'] ? 0 : 1;
    pdo()->prepare('UPDATE site_campaigns SET is_active=?, updated_at=? WHERE id=?')->execute([$newStatus, now(), $campaignId]);
    flash('ok', 'Kampanya durumu güncellendi.');
    redirect($_SERVER['PHP_SELF']);
  }

  if ($action === 'delete') {
    $campaignId = (int)($_POST['campaign_id'] ?? 0);
    site_campaign_delete($campaignId);
    flash('ok', 'Kampanya silindi.');
    redirect($_SERVER['PHP_SELF']);
  }
} catch (Throwable $e) {
  flash('err', $e->getMessage());
  redirect($_SERVER['PHP_SELF'].(!empty($_POST['campaign_id']) ? '?id='.(int)$_POST['campaign_id'] : ''));
}

$campaigns = site_campaign_all(false);
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editCampaign = $editId ? site_campaign_get($editId) : null;

$stats = [
  'total' => count($campaigns),
  'active' => 0,
  'inactive' => 0,
];
foreach ($campaigns as $campaign) {
  if (!empty($campaign['is_active'])) {
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
<title><?=h(APP_NAME)?> — Hayır Kampanyaları</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?=admin_base_styles()?>
<style>
  .campaign-card{border-radius:18px;background:#fff;border:1px solid rgba(14,165,181,.12);padding:22px;box-shadow:0 24px 60px -38px rgba(14,165,181,.45);}
  .campaign-card h5{font-weight:700;}
  .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;margin-bottom:28px;}
  .stat-tile{border-radius:16px;background:linear-gradient(140deg,rgba(14,165,181,.12),rgba(59,130,246,.08));padding:20px;display:flex;flex-direction:column;gap:6px;box-shadow:0 24px 60px -38px rgba(15,23,42,.25);}
  .stat-tile span{font-size:.82rem;color:var(--admin-muted);letter-spacing:.04em;text-transform:uppercase;}
  .stat-tile strong{font-size:1.6rem;}
  .table thead th{font-size:.78rem;text-transform:uppercase;letter-spacing:.08em;color:var(--admin-muted);}
  .badge-active{background:rgba(34,197,94,.18);color:#166534;border-radius:999px;padding:.35rem .85rem;font-weight:600;}
  .badge-passive{background:rgba(248,113,113,.18);color:#b91c1c;border-radius:999px;padding:.35rem .85rem;font-weight:600;}
  .image-thumb{border-radius:14px;overflow:hidden;background:rgba(14,165,181,.08);display:inline-flex;align-items:center;justify-content:center;width:120px;height:80px;}
  .image-thumb img{width:100%;height:100%;object-fit:cover;}
</style>
</head>
<body class="admin-body">
<?php admin_layout_start('order_campaigns', 'Hayır Kampanyaları', 'Sipariş sonrası bağışlanabilecek kampanyaları yönetin, görseller ve açıklamalar ekleyin.', 'bi-heart-fill'); ?>
  <?php flash_box(); ?>

  <div class="stats-grid">
    <div class="stat-tile">
      <span>Toplam Kampanya</span>
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
      <div class="campaign-card">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <div>
            <h5 class="mb-1"><?= $editCampaign ? 'Kampanyayı Güncelle' : 'Yeni Kampanya' ?></h5>
            <p class="small text-muted mb-0">Bağış kampanyasını ad, görsel, özet ve detay bilgileriyle oluşturun. Bu kampanyalar ek hizmet adımında listelenecektir.</p>
          </div>
          <?php if ($editCampaign): ?>
            <a class="btn btn-light border btn-sm" href="<?=h($_SERVER['PHP_SELF'])?>"><i class="bi bi-x-lg"></i></a>
          <?php endif; ?>
        </div>
        <form method="post" class="row g-3" enctype="multipart/form-data">
          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="do" value="save">
          <input type="hidden" name="campaign_id" value="<?= $editCampaign ? (int)$editCampaign['id'] : 0 ?>">
          <div class="col-12">
            <label class="form-label">Kampanya Adı</label>
            <input class="form-control" name="name" value="<?=h($editCampaign['name'] ?? '')?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Bağış Tutarı (TL)</label>
            <input class="form-control" name="price" value="<?= $editCampaign ? number_format($editCampaign['price_cents']/100, 2, ',', '') : '' ?>" placeholder="Örn. 500" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Sıra</label>
            <input type="number" class="form-control" name="display_order" value="<?= $editCampaign ? (int)$editCampaign['display_order'] : 0 ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Kısa Özet</label>
            <textarea class="form-control" name="summary" rows="2" placeholder="Kampanyanın kısa açıklaması..."><?=h($editCampaign['summary'] ?? '')?></textarea>
          </div>
          <div class="col-12">
            <label class="form-label">Detay</label>
            <textarea class="form-control" name="detail" rows="4" placeholder="Kampanya hakkında detaylı bilgi..."><?=h($editCampaign['detail'] ?? '')?></textarea>
          </div>
          <div class="col-12">
            <label class="form-label">Görsel</label>
            <input type="file" class="form-control" name="image" accept="image/*">
            <?php if (!empty($editCampaign['image_url'])): ?>
              <div class="d-flex align-items-center justify-content-between mt-2">
                <div class="image-thumb"><img src="<?=h($editCampaign['image_url'])?>" alt="<?=h($editCampaign['name'])?>"></div>
                <div class="form-check ms-3">
                  <input class="form-check-input" type="checkbox" name="remove_image" id="remove-image">
                  <label class="form-check-label" for="remove-image">Görseli kaldır</label>
                </div>
              </div>
            <?php endif; ?>
          </div>
          <div class="col-12 form-check form-switch">
            <input class="form-check-input" type="checkbox" name="is_active" id="campaign-active" <?= (!$editCampaign || !empty($editCampaign['is_active'])) ? 'checked' : '' ?>>
            <label class="form-check-label" for="campaign-active">Listede görünsün</label>
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
          <h5 class="mb-0">Kampanya Listesi</h5>
          <span class="badge bg-info-subtle text-info-emphasis"><?=count($campaigns)?> kayıt</span>
        </div>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>Kampanya</th>
                <th>Bağış Tutarı</th>
                <th>Durum</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$campaigns): ?>
                <tr>
                  <td colspan="4" class="text-center text-muted py-4">Henüz hayır kampanyası oluşturulmadı.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($campaigns as $campaign): ?>
                  <tr>
                    <td>
                      <div class="d-flex align-items-start gap-3">
                        <div class="image-thumb">
                          <?php if (!empty($campaign['image_url'])): ?>
                            <img src="<?=h($campaign['image_url'])?>" alt="<?=h($campaign['name'])?>">
                          <?php else: ?>
                            <span class="small text-muted">Görsel yok</span>
                          <?php endif; ?>
                        </div>
                        <div>
                          <strong><?=h($campaign['name'])?></strong>
                          <?php if (!empty($campaign['summary'])): ?>
                            <div class="small text-muted mt-1"><?=nl2br(h($campaign['summary']))?></div>
                          <?php endif; ?>
                        </div>
                      </div>
                    </td>
                    <td><?=h(format_currency((int)$campaign['price_cents']))?></td>
                    <td>
                      <?php if (!empty($campaign['is_active'])): ?>
                        <span class="badge-active">Aktif</span>
                      <?php else: ?>
                        <span class="badge-passive">Pasif</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end">
                      <div class="d-inline-flex gap-2">
                        <a class="btn btn-sm btn-outline-primary" href="<?=h($_SERVER['PHP_SELF'].'?id='.(int)$campaign['id'])?>"><i class="bi bi-pencil"></i></a>
                        <form method="post" class="d-inline" onsubmit="return confirm('Bu kampanyayı silmek istediğinize emin misiniz?');">
                          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                          <input type="hidden" name="do" value="delete">
                          <input type="hidden" name="campaign_id" value="<?= (int)$campaign['id'] ?>">
                          <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                        </form>
                        <form method="post" class="d-inline">
                          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                          <input type="hidden" name="do" value="toggle">
                          <input type="hidden" name="campaign_id" value="<?= (int)$campaign['id'] ?>">
                          <button class="btn btn-sm btn-outline-secondary" type="submit"><?=!empty($campaign['is_active']) ? 'Pasifleştir' : 'Aktif Et'?></button>
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
