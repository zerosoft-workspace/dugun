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
  }
  body { background:#f3f4f6; font-family:'Inter',sans-serif; color:var(--ink); }
  .page-shell { max-width:1280px; margin:0 auto; }
  .hero { border-radius:32px; background:linear-gradient(135deg,rgba(14,165,181,.92),rgba(15,23,42,.85)); color:#fff; padding:72px 64px; position:relative; overflow:hidden; box-shadow:0 40px 110px -60px rgba(15,23,42,.55); }
  .hero::after { content:""; position:absolute; inset:auto -140px -180px -140px; height:320px; background:rgba(255,255,255,.16); filter:blur(90px); }
  .hero h1 { font-size:2.8rem; font-weight:800; margin-bottom:.75rem; position:relative; z-index:1; }
  .hero p { max-width:620px; font-size:1.02rem; opacity:.92; position:relative; z-index:1; }
  .hero .breadcrumb-link { position:relative; z-index:1; color:rgba(255,255,255,.9); text-decoration:none; font-weight:600; }
  .hero .breadcrumb-link:hover { text-decoration:underline; }
  .hero .badge { position:relative; z-index:1; font-size:.85rem; border-radius:999px; padding:.5rem 1.1rem; background:rgba(255,255,255,.18); backdrop-filter:blur(8px); font-weight:600; }
  .filter-card { margin-top:-48px; border-radius:22px; background:var(--surface); box-shadow:0 28px 80px -50px rgba(15,23,42,.35); padding:28px; position:relative; z-index:2; }
  .filter-card .form-label { font-weight:600; color:var(--ink); }
  .filter-card .form-select, .filter-card .form-control { border-radius:12px; padding:.6rem .85rem; }
  .filter-card button { border-radius:12px; padding:.65rem 1.5rem; font-weight:600; }
  .filter-reset { color:var(--brand); text-decoration:none; font-weight:600; }
  .filter-reset:hover { text-decoration:underline; }
  .listing-feed { display:flex; flex-direction:column; gap:28px; }
  .listing-card { background:var(--surface); border:1px solid rgba(148,163,184,.25); border-radius:24px; box-shadow:0 26px 70px -48px rgba(15,23,42,.4); overflow:hidden; display:flex; gap:0; transition:box-shadow .25s ease, transform .25s ease; }
  .listing-card:hover { transform:translateY(-6px); box-shadow:0 40px 110px -60px rgba(15,23,42,.45); }
  .listing-card.highlight { border-color:rgba(14,165,181,.45); box-shadow:0 48px 120px -60px rgba(14,165,181,.5); }
  .listing-media { position:relative; width:280px; min-height:220px; background-size:cover; background-position:center; flex-shrink:0; }
  .listing-media.no-image { background:linear-gradient(135deg,rgba(14,165,181,.14),rgba(148,163,184,.18)); display:flex; align-items:center; justify-content:center; color:var(--brand-dark); font-weight:700; font-size:1.6rem; }
  .listing-media::after { content:""; position:absolute; inset:0; background:linear-gradient(180deg,rgba(15,23,42,.1),rgba(15,23,42,.55)); opacity:.55; }
  .listing-media.no-image::after { display:none; }
  .listing-media .category-badge { position:absolute; left:18px; top:18px; padding:.45rem 1rem; border-radius:999px; background:rgba(255,255,255,.85); color:var(--ink); font-weight:600; font-size:.85rem; }
  .thumb-strip { position:absolute; left:18px; bottom:18px; display:flex; gap:8px; z-index:1; }
  .thumb-strip span { width:54px; height:54px; border-radius:14px; background-size:cover; background-position:center; border:2px solid rgba(255,255,255,.85); box-shadow:0 10px 22px -12px rgba(15,23,42,.45); }
  .listing-body { flex:1; padding:26px 28px; display:flex; flex-direction:column; gap:18px; }
  .listing-header { display:flex; justify-content:space-between; align-items:flex-start; gap:18px; flex-wrap:wrap; }
  .listing-title { font-size:1.4rem; font-weight:700; color:var(--ink); margin:0 0 .35rem; }
  .listing-location { display:flex; align-items:center; gap:.5rem; font-size:.95rem; color:#475569; font-weight:600; }
  .listing-summary { margin:0; color:#334155; font-size:.95rem; line-height:1.6; max-width:640px; }
  .dealer-meta { display:flex; flex-direction:column; gap:.3rem; color:#475569; font-size:.9rem; min-width:180px; }
  .dealer-meta strong { font-size:1rem; color:var(--ink); }
  .listing-meta { display:flex; flex-wrap:wrap; gap:12px; font-size:.85rem; color:#64748b; font-weight:600; }
  .listing-meta span { display:inline-flex; align-items:center; gap:.45rem; }
  .package-list { display:grid; gap:12px; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); }
  .package-item { border:1px solid rgba(14,165,181,.25); border-radius:16px; padding:12px 14px; background:rgba(14,165,181,.07); }
  .package-item strong { display:block; font-weight:600; color:var(--ink); margin-bottom:2px; }
  .package-item span { font-weight:700; color:var(--brand-dark); }
  .listing-footer { display:flex; flex-wrap:wrap; align-items:center; gap:12px; }
  .contact-links { display:flex; gap:10px; flex-wrap:wrap; }
  .contact-chip { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:999px; background:rgba(14,165,181,.14); color:var(--brand-dark); font-weight:600; text-decoration:none; }
  .contact-chip i { font-size:1rem; }
  .contact-chip:hover { background:rgba(14,165,181,.24); color:var(--ink); }
  .detail-link { margin-left:auto; display:inline-flex; align-items:center; gap:6px; padding:9px 18px; border-radius:999px; border:1px solid rgba(14,165,181,.45); color:var(--ink); font-weight:600; text-decoration:none; }
  .detail-link:hover { background:rgba(14,165,181,.08); }
  .empty-state { border-radius:24px; border:2px dashed rgba(148,163,184,.35); padding:54px; text-align:center; background:rgba(255,255,255,.92); color:var(--muted); }
  @media (max-width: 992px) {
    .hero { padding:60px 28px; }
    .listing-card { flex-direction:column; }
    .listing-media { width:100%; min-height:220px; }
    .detail-link { margin-left:0; }
  }
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
  <div class="container py-5 page-shell">
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
          <div class="text-muted small">Filtreleri temizlemek için <a href="<?=h($_SERVER['PHP_SELF'])?>" class="filter-reset">tıklayın</a>.</div>
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
        <div class="listing-feed">
          <?php foreach ($listings as $listing): ?>
            <?php
              $isHighlight = $highlightSlug && $highlightSlug === $listing['slug'];
              $heroUrl = $listing['hero_url'] ?? null;
              $mediaItems = $listing['media'] ?? [];
              if (!$heroUrl && $mediaItems) {
                $heroUrl = $mediaItems[0]['url'] ?? null;
              }
              $thumbs = $mediaItems ? array_slice($mediaItems, 1, 3) : [];
              $initialSource = trim((string)($listing['dealer_name'] ?: $listing['title'] ?: APP_NAME));
              $initial = mb_strtoupper(mb_substr($initialSource, 0, 1, 'UTF-8'), 'UTF-8');
              $packages = $listing['packages'] ?? [];
              $packagePreview = array_slice($packages, 0, 3);
              $packageCount = count($packages);
              $publishedAt = !empty($listing['published_at']) ? date('d.m.Y', strtotime($listing['published_at'])) : null;
              $contactEmail = $listing['contact_email'] ?? '';
              $contactPhone = $listing['contact_phone'] ?? '';
              $dialPhone = $contactPhone ? preg_replace('~[^0-9+]~', '', $contactPhone) : '';
              $detailUrl = BASE_URL.'/public/partner.php?listing='.urlencode($listing['slug']);
            ?>
            <article class="listing-card<?= $isHighlight ? ' highlight' : '' ?>" id="listing-<?=h($listing['id'])?>">
              <div class="listing-media<?= $heroUrl ? '' : ' no-image'?>"<?= $heroUrl ? ' style="background-image:url(\''.h($heroUrl).'\')"' : '' ?>>
                <span class="category-badge"><?=h($listing['category_name'] ?? 'Kategori')?></span>
                <?php if ($heroUrl && $thumbs): ?>
                  <div class="thumb-strip">
                    <?php foreach ($thumbs as $thumb): ?>
                      <span style="background-image:url('<?=h($thumb['url'])?>')"></span>
                    <?php endforeach; ?>
                  </div>
                <?php elseif (!$heroUrl): ?>
                  <?=h($initial)?>
                <?php endif; ?>
              </div>
              <div class="listing-body">
                <div class="listing-header">
                  <div>
                    <h3 class="listing-title"><?=h($listing['title'])?></h3>
                    <div class="listing-location"><i class="bi bi-geo-alt-fill text-primary"></i> <?=h($listing['city'])?> / <?=h($listing['district'])?></div>
                    <?php if (!empty($listing['summary'])): ?>
                      <p class="listing-summary mt-2 mb-0"><?=h($listing['summary'])?></p>
                    <?php endif; ?>
                  </div>
                  <div class="dealer-meta text-end text-md-start">
                    <strong><?=h($listing['dealer_name'])?></strong>
                    <?php if (!empty($listing['dealer_company'])): ?><span><?=h($listing['dealer_company'])?></span><?php endif; ?>
                    <?php if ($publishedAt): ?><span>Yayında: <?=h($publishedAt)?></span><?php endif; ?>
                    <?php if ($packageCount): ?><span><?=h($packageCount)?> paket</span><?php endif; ?>
                  </div>
                </div>
                <div class="listing-meta">
                  <span><i class="bi bi-box-seam"></i><?=h($packageCount)?> paket</span>
                  <span><i class="bi bi-tag"></i><?=h($listing['category_name'] ?? 'Kategori')?></span>
                  <?php if ($publishedAt): ?><span><i class="bi bi-broadcast-pin"></i><?=h($publishedAt)?> yayında</span><?php endif; ?>
                </div>
                <?php if ($packagePreview): ?>
                  <div class="package-list">
                    <?php foreach ($packagePreview as $package): ?>
                      <div class="package-item">
                        <strong><?=h($package['name'])?></strong>
                        <span><?=format_currency((int)$package['price_cents'])?></span>
                        <?php if (!empty($package['description'])): ?>
                          <div class="small text-muted mt-2"><?=h($package['description'])?></div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  <?php if ($packageCount > count($packagePreview)): ?>
                    <div class="small text-muted">+<?=h($packageCount - count($packagePreview))?> paket daha detay sayfasında.</div>
                  <?php endif; ?>
                <?php endif; ?>
                <div class="listing-footer">
                  <div class="contact-links">
                    <?php if ($contactEmail): ?>
                      <a class="contact-chip" href="mailto:<?=h($contactEmail)?>"><i class="bi bi-envelope"></i> <?=h($contactEmail)?></a>
                    <?php endif; ?>
                    <?php if ($contactPhone && $dialPhone): ?>
                      <a class="contact-chip" href="tel:<?=h($dialPhone)?>"><i class="bi bi-telephone-outbound"></i> <?=h($contactPhone)?></a>
                    <?php endif; ?>
                  </div>
                  <a class="detail-link" href="<?=h($detailUrl)?>"><i class="bi bi-box-arrow-up-right"></i> Detayı Gör</a>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
