<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/dealers.php';
require_once __DIR__.'/../includes/listings.php';
require_once __DIR__.'/partials/ui.php';

require_admin();
install_schema();

$me = admin_user();
$action = $_POST['do'] ?? '';

if ($action !== '') {
  csrf_or_die();
  try {
    if ($action === 'save_category') {
      $categoryId = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
      $data = [
        'name' => $_POST['name'] ?? '',
        'description' => $_POST['description'] ?? '',
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
      ];
      $savedId = listing_category_save($data, $categoryId ?: null);
      flash('ok', $categoryId ? 'Kategori güncellendi.' : 'Yeni kategori eklendi.');
      $params = $categoryId ? ['category' => $savedId] : [];
      $redirect = $_SERVER['PHP_SELF'];
      if ($params) {
        $redirect .= '?'.http_build_query($params);
      }
      redirect($redirect.'#categories');
    }

    if ($action === 'handle_request') {
      $requestId = (int)($_POST['request_id'] ?? 0);
      $decision = $_POST['decision'] ?? '';
      if (!$requestId) {
        throw new InvalidArgumentException('Talep bulunamadı.');
      }
      if ($decision === 'approve') {
        listing_category_request_update($requestId, LISTING_CATEGORY_REQUEST_APPROVED, [
          'admin_id' => (int)$me['id'],
          'note' => $_POST['note'] ?? '',
          'create_category' => !empty($_POST['create_category']),
        ]);
        flash('ok', 'Kategori talebi onaylandı.');
      } elseif ($decision === 'reject') {
        listing_category_request_update($requestId, LISTING_CATEGORY_REQUEST_REJECTED, [
          'admin_id' => (int)$me['id'],
          'note' => $_POST['note'] ?? '',
        ]);
        flash('ok', 'Kategori talebi reddedildi.');
      } else {
        throw new InvalidArgumentException('Geçersiz işlem.');
      }
      redirect($_SERVER['PHP_SELF'].'#requests');
    }

    if ($action === 'set_status') {
      $listingId = (int)($_POST['listing_id'] ?? 0);
      $status = $_POST['status'] ?? '';
      if (!$listingId || $status === '') {
        throw new InvalidArgumentException('İlan durumu güncellenemedi.');
      }
      listing_admin_set_status($listingId, $status, (int)$me['id'], $_POST['note'] ?? '');
      flash('ok', 'İlan durumu güncellendi.');
      $anchor = !empty($_POST['anchor']) ? '#'.preg_replace('~[^a-z0-9_-]+~i', '', $_POST['anchor']) : '';
      redirect($_SERVER['PHP_SELF'].$anchor);
    }
  } catch (Throwable $e) {
    flash('err', $e->getMessage());
    redirect($_SERVER['PHP_SELF']);
  }
}

$statusFilter = $_GET['status'] ?? LISTING_STATUS_PENDING;
$categoryFilter = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$search = trim($_GET['q'] ?? '');
$requestStatus = $_GET['request_status'] ?? LISTING_CATEGORY_REQUEST_PENDING;

$listings = listing_admin_search([
  'status' => $statusFilter,
  'category_id' => $categoryFilter ?: null,
  'q' => $search !== '' ? $search : null,
]);
$counts = listing_admin_counts();
$categories = listing_category_all();
$categoryEditId = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$categoryEdit = $categoryEditId ? listing_category_get($categoryEditId) : null;
$requests = listing_category_requests($requestStatus === 'all' ? 'all' : $requestStatus);

$title = 'Anlaşmalı Şirketler';
$subtitle = 'Bayi ilanlarını onaylayın, kategorileri yönetin ve vitrinleri güncel tutun.';

$pageStyles = <<<'CSS'
<style>
  .listing-status-badge { border-radius:999px; padding:.3rem .8rem; font-weight:600; font-size:.78rem; }
  .listing-card { border-radius:22px; border:1px solid rgba(148,163,184,.25); box-shadow:0 30px 70px -45px rgba(15,23,42,.35); margin-bottom:1.5rem; }
  .listing-card .card-body { padding:1.5rem 1.6rem; }
  .package-list { list-style:none; padding:0; margin:0; display:grid; gap:.4rem; }
  .package-list li { background:rgba(14,165,181,.08); border-radius:14px; padding:.55rem .75rem; font-weight:500; display:flex; justify-content:space-between; gap:.75rem; }
  .filter-form .form-select, .filter-form .form-control { border-radius:12px; }
  .category-card, .request-card { border-radius:22px; border:1px solid rgba(14,165,181,.18); background:linear-gradient(135deg,rgba(14,165,181,.09),#fff); box-shadow:0 26px 60px -42px rgba(14,165,181,.45); }
  .category-card .card-body, .request-card .card-body { padding:1.5rem; }
  .summary-card { border-radius:20px; background:#fff; border:1px solid rgba(148,163,184,.2); box-shadow:0 28px 60px -44px rgba(15,23,42,.35); padding:1.4rem 1.6rem; height:100%; }
  .summary-card .label { font-size:.75rem; text-transform:uppercase; letter-spacing:.08em; color:#64748b; }
  .summary-card .value { font-size:2rem; font-weight:700; }
  .request-actions { display:flex; gap:.5rem; flex-wrap:wrap; }
  .request-actions button { flex:1; min-width:120px; }
  .status-badge-approved { background:#dcfce7; color:#166534; }
  .status-badge-pending { background:#fef3c7; color:#92400e; }
  .status-badge-rejected { background:#fee2e2; color:#b91c1c; }
</style>
CSS;
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h(APP_NAME)?> — Anlaşmalı Şirketler</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <?=admin_base_styles()?>
  <?=$pageStyles?>
</head>
<body class="admin-body">
<?php admin_layout_start('listings', $title, $subtitle, 'bi-card-list'); ?>
<?=flash_messages()?>
<div class="container-xxl py-4">
  <div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
      <div class="summary-card">
        <div class="label">Onay Bekleyen</div>
        <div class="value text-warning"><?=h($counts[LISTING_STATUS_PENDING] ?? 0)?></div>
        <div class="small text-muted">Editör kontrolüne hazır</div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="summary-card">
        <div class="label">Yayında</div>
        <div class="value text-success"><?=h($counts[LISTING_STATUS_APPROVED] ?? 0)?></div>
        <div class="small text-muted">Ziyaretçilere açık ilanlar</div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="summary-card">
        <div class="label">Reddedilen</div>
        <div class="value text-danger"><?=h($counts[LISTING_STATUS_REJECTED] ?? 0)?></div>
        <div class="small text-muted">Geri bildirim bekleyen ilanlar</div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="summary-card">
        <div class="label">Taslaklar</div>
        <div class="value text-primary"><?=h($counts[LISTING_STATUS_DRAFT] ?? 0)?></div>
        <div class="small text-muted">Bayi tarafından hazırlanan taslaklar</div>
      </div>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-lg-8">
      <div class="card listing-card">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">İlan İnceleme</h5>
            <span class="badge bg-light text-dark"><?=count($listings)?> kayıt</span>
          </div>
          <form method="get" class="row g-2 align-items-end filter-form mb-4">
            <input type="hidden" name="request_status" value="<?=h($requestStatus)?>">
            <div class="col-md-4">
              <label class="form-label">Durum</label>
              <select class="form-select" name="status">
                <?php $statusOptions = [
                  'all' => 'Tümü',
                  LISTING_STATUS_PENDING => 'Onay Bekleyen',
                  LISTING_STATUS_APPROVED => 'Yayında',
                  LISTING_STATUS_REJECTED => 'Reddedilen',
                  LISTING_STATUS_DRAFT => 'Taslak',
                  LISTING_STATUS_ARCHIVED => 'Arşivlenen',
                ];
                foreach ($statusOptions as $value => $label): ?>
                  <option value="<?=h($value)?>" <?=$statusFilter === $value ? 'selected' : ''?>><?=h($label)?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Kategori</label>
              <select class="form-select" name="category_id">
                <option value="">Tümü</option>
                <?php foreach ($categories as $category): ?>
                  <option value="<?=h($category['id'])?>" <?=$categoryFilter === (int)$category['id'] ? 'selected' : ''?>><?=h($category['name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Arama</label>
              <div class="input-group">
                <input type="search" class="form-control" name="q" placeholder="İlan veya bayi ara" value="<?=h($search)?>">
                <button type="submit" class="btn btn-outline-primary"><i class="bi bi-search"></i></button>
              </div>
            </div>
          </form>

          <?php if (!$listings): ?>
            <p class="text-muted mb-0">Seçilen filtrelere uygun ilan bulunamadı.</p>
          <?php else: ?>
            <?php foreach ($listings as $listing): ?>
              <?php [$statusLabel, $statusColor] = listing_status_label($listing['status']); ?>
              <?php $anchorId = 'listing-'.$listing['id']; ?>
              <div class="card listing-card" id="<?=h($anchorId)?>">
                <div class="card-body">
                  <div class="d-flex justify-content-between align-items-start gap-3">
                    <div>
                      <h5 class="mb-1"><?=h($listing['title'])?></h5>
                      <div class="text-muted small">Bayi: <?=h($listing['dealer_name'])?><?php if (!empty($listing['dealer_company'])): ?> — <?=h($listing['dealer_company'])?><?php endif; ?></div>
                      <div class="text-muted small">Kategori: <?=h($listing['category_name'] ?? 'Belirtilmemiş')?> · Konum: <?=h($listing['city'])?> / <?=h($listing['district'])?></div>
                      <?php if (!empty($listing['summary'])): ?>
                        <div class="mt-2 text-muted"><?=h($listing['summary'])?></div>
                      <?php endif; ?>
                      <?php if (!empty($listing['status_note'])): ?>
                        <div class="mt-2 alert alert-warning py-2 px-3 small mb-0"><?=nl2br(h($listing['status_note']))?></div>
                      <?php endif; ?>
                    </div>
                    <span class="listing-status-badge bg-<?=$statusColor?> text-white"><?=$statusLabel?></span>
                  </div>

                  <?php if (!empty($listing['packages'])): ?>
                    <div class="mt-3">
                      <div class="fw-semibold mb-2">Paketler</div>
                      <ul class="package-list">
                        <?php foreach ($listing['packages'] as $package): ?>
                          <li>
                            <span><?=h($package['name'])?></span>
                            <span class="text-muted"><?=format_currency((int)$package['price_cents'])?></span>
                          </li>
                        <?php endforeach; ?>
                      </ul>
                    </div>
                  <?php endif; ?>

                  <div class="mt-4 d-flex flex-wrap gap-3 align-items-center">
                    <form method="post" class="d-flex flex-wrap gap-2 align-items-center">
                      <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                      <input type="hidden" name="do" value="set_status">
                      <input type="hidden" name="listing_id" value="<?=h($listing['id'])?>">
                      <input type="hidden" name="anchor" value="<?=h($anchorId)?>">
                      <select class="form-select form-select-sm" name="status" required>
                        <option value="">Durum seçin</option>
                        <option value="<?=LISTING_STATUS_APPROVED?>">Onayla</option>
                        <option value="<?=LISTING_STATUS_REJECTED?>">Reddet</option>
                        <option value="<?=LISTING_STATUS_ARCHIVED?>">Arşivle</option>
                      </select>
                      <input type="text" class="form-control form-control-sm" name="note" placeholder="Not (opsiyonel)">
                      <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2"></i> Güncelle</button>
                    </form>
                    <?php if ($listing['status'] === LISTING_STATUS_APPROVED): ?>
                      <a class="btn btn-sm btn-outline-success" target="_blank" href="<?=h(BASE_URL.'/public/partners.php?listing='.urlencode($listing['slug']))?>"><i class="bi bi-box-arrow-up-right"></i> Yayındaki ilanı aç</a>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card category-card mb-4" id="categories">
        <div class="card-body">
          <h5 class="mb-3">Kategori Yönetimi</h5>
          <form method="post" class="mb-4">
            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="do" value="save_category">
            <?php if ($categoryEdit): ?>
              <input type="hidden" name="category_id" value="<?=h($categoryEdit['id'])?>">
            <?php endif; ?>
            <div class="mb-2">
              <label class="form-label">Kategori Adı</label>
              <input type="text" class="form-control" name="name" value="<?=h($categoryEdit['name'] ?? '')?>" required>
            </div>
            <div class="mb-2">
              <label class="form-label">Açıklama</label>
              <textarea class="form-control" name="description" rows="2" placeholder="Kısa bilgi"><?=h($categoryEdit['description'] ?? '')?></textarea>
            </div>
            <div class="form-check form-switch mb-3">
              <input class="form-check-input" type="checkbox" id="category-active" name="is_active" <?=(!empty($categoryEdit) ? (int)$categoryEdit['is_active'] : 1) ? 'checked' : ''?>>
              <label class="form-check-label" for="category-active">Aktif olarak yayınla</label>
            </div>
            <div class="d-grid">
              <button type="submit" class="btn btn-primary"><?=$categoryEdit ? 'Kategoriyi Güncelle' : 'Kategori Ekle'?></button>
            </div>
          </form>

          <div class="list-group list-group-flush small">
            <?php foreach ($categories as $category): ?>
              <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="<?=h($_SERVER['PHP_SELF'].'?category='.(int)$category['id'].'#categories')?>">
                <span>
                  <strong><?=h($category['name'])?></strong>
                  <?php if (!empty($category['description'])): ?>
                    <div class="text-muted small"><?=h($category['description'])?></div>
                  <?php endif; ?>
                </span>
                <span class="badge <?=((int)$category['is_active']) ? 'status-badge-approved' : 'status-badge-rejected'?>"><?=((int)$category['is_active']) ? 'Aktif' : 'Pasif'?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="card request-card" id="requests">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Kategori Talepleri</h5>
            <form method="get" class="d-flex gap-2 align-items-center">
              <input type="hidden" name="status" value="<?=h($statusFilter)?>">
              <input type="hidden" name="category_id" value="<?=h($categoryFilter)?>">
              <input type="hidden" name="q" value="<?=h($search)?>">
              <select class="form-select form-select-sm" name="request_status" onchange="this.form.submit()">
                <?php $requestOptions = [
                  LISTING_CATEGORY_REQUEST_PENDING => 'Bekleyen',
                  LISTING_CATEGORY_REQUEST_APPROVED => 'Onaylanan',
                  LISTING_CATEGORY_REQUEST_REJECTED => 'Reddedilen',
                  'all' => 'Tümü',
                ];
                foreach ($requestOptions as $value => $label): ?>
                  <option value="<?=h($value)?>" <?=$requestStatus === $value ? 'selected' : ''?>><?=h($label)?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </div>

          <?php if (!$requests): ?>
            <p class="text-muted mb-0">Bu filtreyle eşleşen talep bulunamadı.</p>
          <?php else: ?>
            <?php foreach ($requests as $request): ?>
              <div class="border rounded-4 p-3 mb-3 bg-white">
                <div class="d-flex justify-content-between align-items-start">
                  <div>
                    <strong><?=h($request['name'])?></strong>
                    <div class="text-muted small">Bayi: <?=h($request['dealer_name'])?></div>
                    <?php if (!empty($request['details'])): ?>
                      <div class="text-muted small">Talep: <?=h($request['details'])?></div>
                    <?php endif; ?>
                  </div>
                  <?php $statusClass = $request['status'] === LISTING_CATEGORY_REQUEST_APPROVED ? 'status-badge-approved' : ($request['status'] === LISTING_CATEGORY_REQUEST_REJECTED ? 'status-badge-rejected' : 'status-badge-pending'); ?>
                  <span class="badge <?=$statusClass?>"><?=h(ucfirst($request['status']))?></span>
                </div>
                <div class="text-muted small mt-2">Gönderim: <?=date('d.m.Y H:i', strtotime($request['created_at']))?></div>
                <?php if ($request['status'] === LISTING_CATEGORY_REQUEST_PENDING): ?>
                  <form method="post" class="mt-3 request-actions">
                    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="do" value="handle_request">
                    <input type="hidden" name="request_id" value="<?=h($request['id'])?>">
                    <textarea class="form-control" name="note" rows="2" placeholder="Not (opsiyonel)"></textarea>
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" value="1" id="create-category-<?=$request['id']?>" name="create_category" checked>
                      <label class="form-check-label small" for="create-category-<?=$request['id']?>">Onaylandığında kategori oluştur</label>
                    </div>
                    <div class="d-flex gap-2 w-100">
                      <button type="submit" name="decision" value="approve" class="btn btn-success btn-sm flex-fill"><i class="bi bi-check2"></i> Onayla</button>
                      <button type="submit" name="decision" value="reject" class="btn btn-outline-danger btn-sm flex-fill"><i class="bi bi-x"></i> Reddet</button>
                    </div>
                  </form>
                <?php elseif (!empty($request['response_note'])): ?>
                  <div class="alert alert-light border mt-3 mb-0 small">Not: <?=h($request['response_note'])?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php admin_layout_end(); ?>
</body>
</html>
