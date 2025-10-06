<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/site.php';
require_once __DIR__.'/../includes/listings.php';

install_schema();

$categoryFilter = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$cityFilter = trim($_GET['city'] ?? '');
$districtFilter = trim($_GET['district'] ?? '');
$search = trim($_GET['q'] ?? '');
$highlightSlug = trim($_GET['listing'] ?? '');

$filters = [
  'category_id' => $categoryFilter ?: null,
  'city' => $cityFilter !== '' ? $cityFilter : null,
  'district' => $districtFilter !== '' ? $districtFilter : null,
  'q' => $search !== '' ? $search : null,
];

$listings = listing_public_search($filters);
$categories = listing_category_all(true);
$locations = listing_public_locations();
$selectedCity = $cityFilter !== '' ? $cityFilter : '';
$districtOptions = ($selectedCity && isset($locations[$selectedCity])) ? $locations[$selectedCity] : [];

$pageStyles = <<<'CSS'
<style>
  :root {
    --ink:#0f172a;
    --muted:#64748b;
    --brand:#0ea5b5;
    --brand-dark:#0b8b98;
    --surface:#ffffff;
    --bg:#f3f6fb;
  }
  body { background:var(--bg); font-family:'Inter',sans-serif; color:var(--ink); }
  .hero { border-radius:32px; background:linear-gradient(135deg,rgba(14,165,181,.95),rgba(99,102,241,.88)); color:#fff; padding:72px 48px; position:relative; overflow:hidden; }
  .hero::after { content:""; position:absolute; inset:auto -80px -160px -80px; height:360px; background:rgba(255,255,255,.12); filter:blur(60px); }
  .hero h1 { font-size:2.8rem; font-weight:800; }
  .hero p { max-width:640px; font-size:1.05rem; }
  .filter-card { margin-top:-60px; border-radius:28px; background:#fff; box-shadow:0 40px 90px -50px rgba(15,23,42,.5); padding:28px; position:relative; z-index:2; }
  .filter-card .form-select, .filter-card .form-control { border-radius:14px; }
  .listing-card { border-radius:26px; border:1px solid rgba(148,163,184,.2); background:#fff; box-shadow:0 36px 80px -46px rgba(15,23,42,.45); padding:28px; transition:transform .25s ease, box-shadow .25s ease; }
  .listing-card:hover { transform:translateY(-4px); box-shadow:0 46px 100px -40px rgba(15,23,42,.55); }
  .listing-card.highlight { border-color:var(--brand); box-shadow:0 50px 110px -40px rgba(14,165,181,.55); }
  .listing-meta { color:var(--muted); font-size:.9rem; display:flex; flex-wrap:wrap; gap:1rem; }
  .package-pill { border-radius:16px; background:rgba(14,165,181,.1); padding:.65rem .9rem; display:flex; justify-content:space-between; gap:1.5rem; font-weight:600; }
  .contact-chip { border-radius:16px; background:rgba(99,102,241,.12); color:#312e81; padding:.5rem .9rem; display:inline-flex; align-items:center; gap:.5rem; font-weight:600; }
  .empty-state { border-radius:26px; border:2px dashed rgba(148,163,184,.35); padding:48px; text-align:center; color:var(--muted); }
  .breadcrumb-link { color:#fff; text-decoration:none; font-weight:600; }
  .breadcrumb-link:hover { text-decoration:underline; }
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
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <?=$pageStyles?>
</head>
<body>
  <div class="container py-5">
    <header class="hero mb-5">
      <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 position-relative" style="z-index:2;">
        <div>
          <a class="breadcrumb-link d-inline-flex align-items-center gap-2 mb-3" href="<?=h(BASE_URL)?>"><i class="bi bi-arrow-left"></i> Ana sayfaya dön</a>
          <h1>Anlaşmalı Şirketler</h1>
          <p>BİKARE ekosistemine kayıtlı bayilerimizin sunduğu kampanya ve paketleri inceleyin. Şehir ve kategori filtreleriyle size en uygun çözüm ortağını bulun.</p>
        </div>
        <div class="text-end">
          <span class="badge bg-light text-dark rounded-pill px-3 py-2 fw-semibold"><?=count($listings)?> ilan</span>
        </div>
      </div>
    </header>

    <section class="filter-card">
      <form class="row g-3 align-items-end" method="get">
        <div class="col-md-4">
          <label class="form-label fw-semibold">Kategori</label>
          <select class="form-select" name="category">
            <option value="">Tüm kategoriler</option>
            <?php foreach ($categories as $category): ?>
              <option value="<?=h($category['id'])?>" <?=$categoryFilter === (int)$category['id'] ? 'selected' : ''?>><?=h($category['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold">İl</label>
          <select class="form-select" name="city" onchange="this.form.submit()">
            <option value="">Tüm iller</option>
            <?php foreach (array_keys($locations) as $city): ?>
              <option value="<?=h($city)?>" <?=$selectedCity === $city ? 'selected' : ''?>><?=h($city)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold">İlçe</label>
          <select class="form-select" name="district" <?php if (!$selectedCity) echo 'disabled'; ?>>
            <option value=""><?php echo $selectedCity ? 'Tüm ilçeler' : 'İl seçin'; ?></option>
            <?php foreach ($districtOptions as $district): ?>
              <option value="<?=h($district)?>" <?=$districtFilter === $district ? 'selected' : ''?>><?=h($district)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label fw-semibold">Arama</label>
          <input type="search" class="form-control" name="q" placeholder="Bayi veya ilan" value="<?=h($search)?>">
        </div>
        <div class="col-md-12 d-flex justify-content-between align-items-center">
          <div class="text-muted small">Filtreleri temizlemek için <a href="<?=h($_SERVER['PHP_SELF'])?>" class="link-primary text-decoration-none">tıklayın</a>.</div>
          <button type="submit" class="btn btn-primary px-4"><i class="bi bi-funnel me-1"></i> Filtrele</button>
        </div>
      </form>
    </section>

    <section class="mt-5">
      <?php if (!$listings): ?>
        <div class="empty-state">
          <h4 class="fw-bold mb-2">Uygun ilan bulunamadı</h4>
          <p>Filtrelerinizi değiştirerek yeniden deneyin. Aradığınız kategoriyi göremiyorsanız bizimle iletişime geçebilirsiniz.</p>
          <a class="btn btn-outline-primary rounded-pill px-4" href="mailto:info@zerosoft.com.tr"><i class="bi bi-envelope"></i> Bize yazın</a>
        </div>
      <?php else: ?>
        <div class="row g-4">
          <?php foreach ($listings as $listing): ?>
            <?php $isHighlight = $highlightSlug && $highlightSlug === $listing['slug']; ?>
            <div class="col-md-6" id="listing-<?=h($listing['id'])?>">
              <div class="listing-card<?= $isHighlight ? ' highlight' : '' ?>">
                <div class="d-flex justify-content-between align-items-start gap-3">
                  <div>
                    <h3 class="h4 fw-bold mb-1"><?=h($listing['title'])?></h3>
                    <?php if (!empty($listing['summary'])): ?>
                      <p class="mb-2 text-muted"><?=h($listing['summary'])?></p>
                    <?php endif; ?>
                  </div>
                  <span class="badge bg-light text-dark rounded-pill"><?=h($listing['category_name'] ?? 'Kategori')?></span>
                </div>
                <div class="listing-meta mt-2">
                  <span><i class="bi bi-geo-alt"></i> <?=h($listing['city'])?> / <?=h($listing['district'])?></span>
                  <span><i class="bi bi-building"></i> <?=h($listing['dealer_name'])?></span>
                </div>
                <?php if (!empty($listing['packages'])): ?>
                  <div class="mt-3">
                    <h6 class="fw-semibold text-uppercase text-muted small">Paketler</h6>
                    <div class="d-flex flex-column gap-2">
                      <?php foreach ($listing['packages'] as $package): ?>
                        <div class="package-pill">
                          <span><?=h($package['name'])?></span>
                          <span><?=format_currency((int)$package['price_cents'])?></span>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endif; ?>
                <div class="mt-4 d-flex flex-wrap gap-2 align-items-center">
                  <?php if (!empty($listing['dealer_email'])): ?>
                    <a class="contact-chip" href="mailto:<?=h($listing['dealer_email'])?>"><i class="bi bi-envelope"></i> E-posta Gönder</a>
                  <?php endif; ?>
                  <?php if (!empty($listing['dealer_phone'])): ?>
                    <a class="contact-chip" href="tel:<?=h(preg_replace('~[^0-9+]~', '', $listing['dealer_phone']))?>"><i class="bi bi-telephone"></i> Ara</a>
                  <?php endif; ?>
                  <a class="btn btn-outline-primary rounded-pill ms-auto" href="<?=h(BASE_URL.'/dealer/login.php')?>"><i class="bi bi-person-plus"></i> Bayi Ol</a>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
