<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/dealers.php';
require_once __DIR__.'/../includes/representatives.php';
require_once __DIR__.'/../includes/listings.php';
require_once __DIR__.'/../includes/dealer_auth.php';
require_once __DIR__.'/partials/ui.php';

install_schema();

dealer_require_login();
$sessionDealer = dealer_user();
$dealer = dealer_get((int)$sessionDealer['id']);
if (!$dealer) {
  dealer_logout();
  redirect('login.php');
}

dealer_refresh_session((int)$dealer['id']);
$refCode = $dealer['code'] ?: dealer_ensure_identifier((int)$dealer['id']);
$venues = dealer_fetch_venues((int)$dealer['id']);
$representative = representative_for_dealer((int)$dealer['id']);
$balance = dealer_get_balance((int)$dealer['id']);
$licenseLabel = dealer_license_label($dealer);

$action = $_POST['do'] ?? '';
if ($action !== '') {
  csrf_or_die();
  try {
    if ($action === 'save_listing') {
      $listingId = isset($_POST['listing_id']) ? (int)$_POST['listing_id'] : null;
      $packages = dealer_listing_extract_packages($_POST);
      $result = dealer_listing_save((int)$dealer['id'], [
        'title' => $_POST['title'] ?? '',
        'summary' => $_POST['summary'] ?? '',
        'description' => $_POST['description'] ?? '',
        'category_id' => isset($_POST['category_id']) ? (int)$_POST['category_id'] : null,
        'city' => $_POST['city'] ?? '',
        'district' => $_POST['district'] ?? '',
      ], $packages, $listingId ?: null);
      $message = $listingId ? 'İlan taslağınız güncellendi.' : 'Yeni ilan taslağınız oluşturuldu.';
      if (!empty($result['previous_status']) && $result['previous_status'] !== LISTING_STATUS_DRAFT && $result['status'] === LISTING_STATUS_DRAFT) {
        $message .= ' Onaylı veya yayındaki ilanınızı yeniden incelememiz gerekecek. Lütfen tekrar onaya gönderin.';
      }
      flash('ok', $message);
      $params = ['edit' => $result['id']];
      redirect($_SERVER['PHP_SELF'].'?'.http_build_query($params));
    }

    if ($action === 'submit_listing') {
      $listingId = (int)($_POST['listing_id'] ?? 0);
      dealer_listing_submit_for_review($listingId, (int)$dealer['id']);
      flash('ok', 'İlanınız onaya gönderildi.');
      redirect($_SERVER['PHP_SELF']);
    }

    if ($action === 'request_category') {
      $name = $_POST['category_name'] ?? '';
      $details = $_POST['category_details'] ?? '';
      listing_category_request_submit((int)$dealer['id'], $name, $details);
      flash('ok', 'Kategori talebiniz alınmıştır. Ekip kısa sürede inceleyecek.');
      redirect($_SERVER['PHP_SELF'].'#category-requests');
    }
  } catch (Throwable $e) {
    flash('err', $e->getMessage());
    $back = $_SERVER['PHP_SELF'];
    $params = [];
    if (!empty($_POST['listing_id'])) {
      $params['edit'] = (int)$_POST['listing_id'];
    }
    if ($params) {
      $back .= '?'.http_build_query($params);
    }
    redirect($back);
  }
}

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$currentListing = $editId ? dealer_listing_find_for_owner((int)$dealer['id'], $editId) : null;
if ($editId && !$currentListing) {
  flash('err', 'Düzenlemek istediğiniz ilan bulunamadı.');
  redirect($_SERVER['PHP_SELF']);
}

$listings = dealer_listings_for_dealer((int)$dealer['id']);
$counts = dealer_listing_status_counts((int)$dealer['id']);
$categoryRequests = listing_category_requests_for_dealer((int)$dealer['id']);
$categories = listing_category_all(true);

$formPackages = $currentListing ? $currentListing['packages'] : [];
while (count($formPackages) < LISTING_MIN_PACKAGES) {
  $formPackages[] = ['name' => '', 'description' => '', 'price_cents' => 0];
}
while (count($formPackages) < LISTING_MAX_PACKAGES) {
  $formPackages[] = ['name' => '', 'description' => '', 'price_cents' => 0, 'optional' => true];
}

$pageStyles = <<<'CSS'
<style>
  .listing-card { border:1px solid rgba(15,23,42,.08); border-radius:22px; background:#fff; box-shadow:0 30px 60px -40px rgba(15,23,42,.45); padding:1.75rem; }
  .listing-card h5 { font-weight:700; }
  .listing-table { border-radius:22px; overflow:hidden; border:1px solid rgba(148,163,184,.25); box-shadow:0 32px 70px -46px rgba(15,23,42,.35); }
  .listing-table thead th { background:rgba(14,165,181,.08); font-size:.85rem; text-transform:uppercase; letter-spacing:.08em; color:#0f172a; }
  .listing-table tbody td { vertical-align:middle; }
  .badge-status { border-radius:999px; padding:.35rem .75rem; font-weight:600; font-size:.8rem; }
  .package-grid { display:flex; flex-direction:column; gap:1rem; }
  .package-row { border:1px dashed rgba(148,163,184,.45); border-radius:18px; padding:1rem; background:#f8fafc; }
  .package-row h6 { font-weight:600; font-size:.9rem; margin-bottom:.8rem; color:#0f172a; }
  .package-row.optional { background:#fff; border-style:solid; border-color:rgba(148,163,184,.25); }
  .package-row .form-control, .package-row .form-select { border-radius:14px; }
  .listing-actions { display:flex; gap:.5rem; flex-wrap:wrap; }
  .category-request-card { border:1px solid rgba(14,165,181,.18); border-radius:22px; background:linear-gradient(135deg,rgba(14,165,181,.12),#fff); padding:1.5rem; box-shadow:0 28px 58px -44px rgba(14,165,181,.45); }
  .category-request-card h5 { font-weight:700; }
  .status-note { border-radius:14px; background:rgba(248,113,113,.12); padding:.85rem 1rem; color:#b91c1c; font-weight:600; }
</style>
CSS;

dealer_layout_start('listings', [
  'page_title' => APP_NAME.' — Bayi İlanları',
  'title' => 'İlan Yönetimi',
  'subtitle' => 'Şirketinizi Anlaşmalı Şirketler vitrininde sergileyin, paketlerinizi yönetin ve onaya gönderin.',
  'dealer' => $dealer,
  'representative' => $representative,
  'venues' => $venues,
  'balance_text' => format_currency($balance),
  'license_text' => $licenseLabel,
  'ref_code' => $refCode,
  'extra_head' => $pageStyles,
]);
?>
<section class="mb-4">
  <div class="row g-3">
    <div class="col-md-3">
      <div class="listing-card">
        <h6 class="text-muted text-uppercase fs-6">Taslaklar</h6>
        <div class="display-6 fw-bold text-primary mb-0"><?=h($counts[LISTING_STATUS_DRAFT] ?? 0)?></div>
        <small class="text-muted">Hazırladığınız ancak onaya göndermediğiniz ilanlar</small>
      </div>
    </div>
    <div class="col-md-3">
      <div class="listing-card">
        <h6 class="text-muted text-uppercase fs-6">Onay Bekleyen</h6>
        <div class="display-6 fw-bold text-warning mb-0"><?=h($counts[LISTING_STATUS_PENDING] ?? 0)?></div>
        <small class="text-muted">Editör onayında olan ilanlarınız</small>
      </div>
    </div>
    <div class="col-md-3">
      <div class="listing-card">
        <h6 class="text-muted text-uppercase fs-6">Yayında</h6>
        <div class="display-6 fw-bold text-success mb-0"><?=h($counts[LISTING_STATUS_APPROVED] ?? 0)?></div>
        <small class="text-muted">Ziyaretçilere açık ilanlarınız</small>
      </div>
    </div>
    <div class="col-md-3">
      <div class="listing-card">
        <h6 class="text-muted text-uppercase fs-6">Reddedilen</h6>
        <div class="display-6 fw-bold text-danger mb-0"><?=h($counts[LISTING_STATUS_REJECTED] ?? 0)?></div>
        <small class="text-muted">Geri bildirim bekleyen ilanlar</small>
      </div>
    </div>
  </div>
</section>

<div class="row g-4">
  <div class="col-lg-6">
    <div class="listing-card h-100">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">İlanlarım</h5>
        <a class="btn btn-outline-secondary btn-sm rounded-pill" href="<?=h($_SERVER['PHP_SELF'])?>"><i class="bi bi-plus-circle me-1"></i>Yeni ilan</a>
      </div>
      <?php if (!$listings): ?>
        <p class="text-muted mb-0">Henüz bir ilan oluşturmadınız. Sağdaki formu kullanarak ilk ilanınızı hazırlayabilirsiniz.</p>
      <?php else: ?>
        <div class="table-responsive listing-table">
          <table class="table mb-0">
            <thead>
              <tr>
                <th>İlan Başlığı</th>
                <th>Kategori</th>
                <th>Durum</th>
                <th>Paket</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($listings as $listing): ?>
                <?php [$statusLabel, $statusColor] = listing_status_label($listing['status']); ?>
                <tr>
                  <td>
                    <div class="fw-semibold"><?=h($listing['title'])?></div>
                    <small class="text-muted"><?=date('d.m.Y', strtotime($listing['updated_at'] ?? $listing['created_at']))?></small>
                    <?php if ($listing['status'] === LISTING_STATUS_REJECTED && !empty($listing['status_note'])): ?>
                      <div class="status-note mt-2"><?=nl2br(h($listing['status_note']))?></div>
                    <?php endif; ?>
                  </td>
                  <td><?=h($listing['category_name'] ?? 'Kategori seçilmedi')?></td>
                  <td><span class="badge bg-<?=$statusColor?> badge-status"><?=$statusLabel?></span></td>
                  <td><?=count($listing['packages'] ?? [])?></td>
                  <td class="text-end">
                    <div class="listing-actions">
                      <a class="btn btn-sm btn-outline-primary" href="<?=h($_SERVER['PHP_SELF'].'?edit='.(int)$listing['id'])?>"><i class="bi bi-pencil"></i> Düzenle</a>
                      <?php if (in_array($listing['status'], [LISTING_STATUS_DRAFT, LISTING_STATUS_REJECTED], true)): ?>
                        <form method="post" class="d-inline">
                          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                          <input type="hidden" name="do" value="submit_listing">
                          <input type="hidden" name="listing_id" value="<?=h($listing['id'])?>">
                          <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-send"></i> Onaya Gönder</button>
                        </form>
                      <?php elseif ($listing['status'] === LISTING_STATUS_APPROVED): ?>
                        <a class="btn btn-sm btn-outline-success" target="_blank" href="<?=h(BASE_URL.'/public/partners.php?listing='.urlencode($listing['slug']))?>"><i class="bi bi-box-arrow-up-right"></i> Görüntüle</a>
                      <?php else: ?>
                        <span class="text-muted small">İncelemede</span>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="listing-card mb-4">
      <h5><?= $currentListing ? 'İlanı Düzenle' : 'Yeni İlan Oluştur' ?></h5>
      <p class="text-muted">İlanınızda en az <?=LISTING_MIN_PACKAGES?>, en fazla <?=LISTING_MAX_PACKAGES?> paket sunabilirsiniz. Paket fiyatlarını TL cinsinden girin.</p>
      <form method="post" class="mt-3">
        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="do" value="save_listing">
        <?php if ($currentListing): ?>
          <input type="hidden" name="listing_id" value="<?=h($currentListing['id'])?>">
        <?php endif; ?>
        <div class="mb-3">
          <label class="form-label fw-semibold">İlan Başlığı</label>
          <input type="text" class="form-control" name="title" value="<?=h($currentListing['title'] ?? '')?>" required>
        </div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold">Kategori</label>
            <select class="form-select" name="category_id" required>
              <option value="">Kategori seçin</option>
              <?php foreach ($categories as $category): ?>
                <option value="<?=h($category['id'])?>" <?=isset($currentListing['category_id']) && (int)$currentListing['category_id'] === (int)$category['id'] ? 'selected' : ''?>><?=h($category['name'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold">İl</label>
            <input type="text" class="form-control" name="city" value="<?=h($currentListing['city'] ?? '')?>" required>
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold">İlçe</label>
            <input type="text" class="form-control" name="district" value="<?=h($currentListing['district'] ?? '')?>" required>
          </div>
        </div>
        <div class="mt-3">
          <label class="form-label fw-semibold">Öne Çıkan Cümle</label>
          <input type="text" class="form-control" name="summary" maxlength="255" value="<?=h($currentListing['summary'] ?? '')?>" placeholder="Kısa bir açıklama">
        </div>
        <div class="mt-3">
          <label class="form-label fw-semibold">Detaylı Açıklama</label>
          <textarea class="form-control" name="description" rows="4" placeholder="Hizmetlerinizi, avantajlarınızı ve iletişim notlarınızı yazın."><?=h($currentListing['description'] ?? '')?></textarea>
        </div>

        <div class="mt-4">
          <h6 class="fw-semibold">Paketler</h6>
          <div class="package-grid">
            <?php foreach ($formPackages as $index => $package): ?>
              <?php $isOptional = $index >= LISTING_MIN_PACKAGES; ?>
              <div class="package-row<?= $isOptional ? ' optional' : '' ?>">
                <h6>Paket <?=($index + 1)?> <?=$isOptional ? '(Opsiyonel)' : ''?></h6>
                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label">Paket Adı<?=$isOptional ? '' : ' *'?></label>
                    <input type="text" class="form-control" name="package_name[]" value="<?=h($package['name'] ?? '')?>" <?=$isOptional ? '' : 'required'?>>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Paket Fiyatı<?=$isOptional ? '' : ' *'?></label>
                    <?php $priceValue = isset($package['price_cents']) ? number_format(((int)$package['price_cents'])/100, 2, ',', '.') : ''; ?>
                    <input type="text" class="form-control" name="package_price[]" value="<?=h($priceValue)?>" <?=$isOptional ? '' : 'required'?> placeholder="0,00">
                  </div>
                  <div class="col-md-12">
                    <label class="form-label">Paket Açıklaması</label>
                    <textarea class="form-control" name="package_description[]" rows="2" placeholder="Paket içeriği ve sunulan hizmetler."><?=h($package['description'] ?? '')?></textarea>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="d-flex gap-2 mt-4">
          <button type="submit" class="btn btn-brand fw-semibold flex-grow-1"><i class="bi bi-save me-1"></i> Taslağı Kaydet</button>
          <?php if ($currentListing): ?>
            <a class="btn btn-outline-secondary" href="<?=h($_SERVER['PHP_SELF'])?>">Formu Sıfırla</a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <div class="category-request-card" id="category-requests">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Kategori Talebi</h5>
        <span class="badge bg-info text-dark">Yeni kategori önerin</span>
      </div>
      <form method="post" class="mb-3">
        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="do" value="request_category">
        <div class="row g-2 align-items-end">
          <div class="col-md-5">
            <label class="form-label">Kategori Adı</label>
            <input type="text" class="form-control" name="category_name" required>
          </div>
          <div class="col-md-5">
            <label class="form-label">Detay</label>
            <input type="text" class="form-control" name="category_details" placeholder="Hangi hizmet için?">
          </div>
          <div class="col-md-2 d-grid">
            <button type="submit" class="btn btn-outline-primary"><i class="bi bi-send"></i> Gönder</button>
          </div>
        </div>
      </form>
      <?php if ($categoryRequests): ?>
        <h6 class="fw-semibold">Gönderilen talepler</h6>
        <ul class="list-unstyled mb-0 small">
          <?php foreach ($categoryRequests as $request): ?>
            <li class="mb-1">
              <strong><?=h($request['name'])?></strong> — <?=date('d.m.Y', strtotime($request['created_at']))?>
              <?php if ($request['status'] === LISTING_CATEGORY_REQUEST_APPROVED): ?>
                <span class="badge bg-success ms-1">Onaylandı</span>
              <?php elseif ($request['status'] === LISTING_CATEGORY_REQUEST_REJECTED): ?>
                <span class="badge bg-danger ms-1">Reddedildi</span>
              <?php else: ?>
                <span class="badge bg-warning text-dark ms-1">İncelemede</span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="text-muted mb-0">Henüz bir kategori talebinde bulunmadınız.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php dealer_layout_end(); ?>
