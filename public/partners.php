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
  }
  body { background:radial-gradient(circle at top,#f0f9ff 0%,#f5f3ff 35%,#f8fafc 100%); font-family:'Inter',sans-serif; color:var(--ink); }
  .page-shell { max-width:1260px; margin:0 auto; }
  .hero { border-radius:40px; background:linear-gradient(135deg,rgba(14,165,181,.95),rgba(99,102,241,.9)); color:#fff; padding:82px 72px; position:relative; overflow:hidden; box-shadow:0 60px 140px -80px rgba(15,23,42,.6); }
  .hero::before { content:""; position:absolute; inset:-140px 40% auto -80px; height:320px; background:radial-gradient(circle at top,#fff,transparent 70%); opacity:.35; }
  .hero::after { content:""; position:absolute; inset:auto -120px -160px -120px; height:340px; background:rgba(255,255,255,.16); filter:blur(90px); }
  .hero h1 { font-size:3rem; font-weight:800; margin-bottom:1rem; }
  .hero p { max-width:680px; font-size:1.05rem; margin-bottom:0; opacity:.9; }
  .hero .breadcrumb-link { color:#fff; text-decoration:none; font-weight:600; }
  .hero .breadcrumb-link:hover { text-decoration:underline; }
  .hero .badge { font-size:.85rem; border-radius:999px; padding:.55rem 1.2rem; backdrop-filter:blur(10px); background:rgba(255,255,255,.2); color:#fff; font-weight:600; }
  .filter-card { margin-top:-70px; border-radius:32px; background:rgba(255,255,255,.92); backdrop-filter:blur(14px); box-shadow:0 45px 120px -60px rgba(15,23,42,.45); padding:32px; position:relative; z-index:2; }
  .filter-card .form-label { font-weight:600; color:var(--ink); }
  .filter-card .form-select, .filter-card .form-control { border-radius:14px; padding:.65rem .85rem; }
  .filter-card button { border-radius:14px; padding:.7rem 1.6rem; font-weight:600; }
  .filter-reset { color:var(--brand); text-decoration:none; font-weight:600; }
  .filter-reset:hover { text-decoration:underline; }
  .listing-grid { display:grid; gap:32px; }
  @media (min-width: 992px) { .listing-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
  @media (min-width: 1400px) { .listing-grid { grid-template-columns:repeat(3,minmax(0,1fr)); } }
  .listing-card { border-radius:28px; overflow:hidden; background:linear-gradient(160deg,#fff,rgba(226,232,240,.65)); box-shadow:0 45px 110px -70px rgba(15,23,42,.6); display:flex; flex-direction:column; min-height:100%; position:relative; transition:transform .3s ease, box-shadow .3s ease; }
  .listing-card::after { content:""; position:absolute; inset:auto -40% -60% -40%; height:220px; background:radial-gradient(circle at top,rgba(14,165,181,.25),transparent 70%); opacity:.6; pointer-events:none; }
  .listing-card:hover { transform:translateY(-6px); box-shadow:0 65px 150px -70px rgba(15,23,42,.6); }
  .listing-card.highlight { border:1px solid rgba(14,165,181,.45); box-shadow:0 70px 160px -70px rgba(14,165,181,.65); }
  .listing-cover { position:relative; height:220px; background-size:cover; background-position:center; background-repeat:no-repeat; display:flex; align-items:flex-end; padding:1.6rem; color:#fff; }
  .listing-cover::before { content:""; position:absolute; inset:0; background:linear-gradient(180deg,rgba(15,23,42,.05),rgba(15,23,42,.78)); }
  .listing-cover .cover-content { position:relative; width:100%; display:flex; justify-content:space-between; align-items:flex-end; gap:1rem; }
  .listing-cover .category-chip { padding:.55rem 1.1rem; border-radius:999px; font-weight:600; font-size:.85rem; background:rgba(15,23,42,.68); backdrop-filter:blur(10px); }
  .listing-cover .location-chip { display:inline-flex; align-items:center; gap:.45rem; padding:.5rem 1rem; border-radius:999px; background:rgba(255,255,255,.2); font-weight:600; font-size:.85rem; backdrop-filter:blur(10px); }
  .listing-cover .location-chip i { color:#e0f2fe; }
  .listing-cover .cover-initial { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-size:3.2rem; font-weight:700; color:rgba(255,255,255,.9); text-shadow:0 18px 40px rgba(15,23,42,.55); }
  .listing-cover.tone-1 { background-image:linear-gradient(135deg,#bae6fd,#0ea5b5); }
  .listing-cover.tone-2 { background-image:linear-gradient(135deg,#ede9fe,#a855f7); }
  .listing-cover.tone-3 { background-image:linear-gradient(135deg,#fee2e2,#fb7185); }
  .listing-cover.tone-4 { background-image:linear-gradient(135deg,#dcfce7,#22c55e); }
  .listing-cover.tone-5 { background-image:linear-gradient(135deg,#fef3c7,#f97316); }
  .listing-body { position:relative; z-index:1; padding:1.9rem 2.1rem 2rem; display:flex; flex-direction:column; gap:1.2rem; }
  .listing-title { font-size:1.45rem; font-weight:700; margin-bottom:.35rem; color:var(--ink); }
  .listing-summary { font-size:.95rem; color:#475569; margin-bottom:0; }
  .listing-description { color:#334155; font-size:.95rem; line-height:1.65; max-height:6.5rem; overflow:hidden; display:-webkit-box; -webkit-line-clamp:4; -webkit-box-orient:vertical; }
  .meta-group { display:flex; flex-wrap:wrap; gap:.55rem; }
  .meta-chip { background:rgba(14,165,181,.12); color:#0f172a; border-radius:999px; padding:.45rem .9rem; font-weight:600; font-size:.8rem; display:inline-flex; align-items:center; gap:.45rem; }
  .meta-chip i { color:var(--brand); }
  .package-stack { display:flex; flex-direction:column; gap:.75rem; }
  .package-item { border-radius:18px; padding:1rem 1.2rem; background:linear-gradient(135deg,rgba(240,253,255,.92),#fff); border:1px solid rgba(14,165,181,.18); box-shadow:0 24px 60px -50px rgba(14,165,181,.4); }
  .package-item strong { display:block; font-size:1rem; color:var(--ink); }
  .package-item span { font-weight:700; color:var(--brand); }
  .package-item p { margin-bottom:0; font-size:.85rem; color:#475569; }
  .contact-actions { margin-top:auto; display:flex; flex-wrap:wrap; gap:.6rem; align-items:center; }
  .contact-chip { border-radius:999px; padding:.55rem 1.1rem; font-weight:600; display:inline-flex; align-items:center; gap:.45rem; background:rgba(99,102,241,.12); color:#312e81; text-decoration:none; }
  .contact-chip:hover { text-decoration:none; background:rgba(99,102,241,.2); }
  .cta-link { margin-left:auto; border-radius:999px; padding:.6rem 1.3rem; font-weight:600; border:1px solid rgba(14,165,181,.45); color:#0e7490; text-decoration:none; display:inline-flex; align-items:center; gap:.35rem; }
  .cta-link:hover { text-decoration:none; background:rgba(14,165,181,.08); }
  .empty-state { border-radius:32px; border:2px dashed rgba(148,163,184,.35); padding:60px; text-align:center; color:var(--muted); background:rgba(255,255,255,.7); backdrop-filter:blur(8px); }
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
        <div class="listing-grid">
          <?php foreach ($listings as $listing): ?>
            <?php
              $isHighlight = $highlightSlug && $highlightSlug === $listing['slug'];
              $heroImage = '';
              if (!empty($listing['hero_image'])) {
                $heroImage = $listing['hero_image'];
                if ($heroImage && !preg_match('~^https?://~', $heroImage)) {
                  $heroImage = rtrim(BASE_URL, '/').'/'.ltrim($heroImage, '/');
                }
              }
              $tone = (($listing['id'] ?? 0) % 5) + 1;
              $initialSource = trim((string)($listing['dealer_name'] ?: $listing['title']));
              if ($initialSource === '') {
                $initialSource = APP_NAME;
              }
              $initial = mb_strtoupper(mb_substr($initialSource, 0, 1, 'UTF-8'), 'UTF-8');
              $coverClasses = 'listing-cover '.($heroImage ? 'has-image' : 'tone-'.$tone);
              $coverStyleAttr = '';
              if ($heroImage) {
                $coverStyleAttr = sprintf(' style="background-image:url(\'%s\')"', h($heroImage));
              }
              $packages = $listing['packages'] ?? [];
              $packageCount = count($packages);
            ?>
            <article class="listing-card<?= $isHighlight ? ' highlight' : '' ?>" id="listing-<?=h($listing['id'])?>">
              <div class="<?=h($coverClasses)?>"<?=$coverStyleAttr?>>
                <?php if (!$heroImage): ?>
                  <div class="cover-initial"><?=h($initial)?></div>
                <?php endif; ?>
                <div class="cover-content">
                  <span class="category-chip"><?=h($listing['category_name'] ?? 'Kategori')?></span>
                  <span class="location-chip"><i class="bi bi-geo-alt-fill"></i> <?=h($listing['city'])?> / <?=h($listing['district'])?></span>
                </div>
              </div>
              <div class="listing-body">
                <div>
                  <h3 class="listing-title"><?=h($listing['title'])?></h3>
                  <p class="listing-summary mb-1">Bayi: <?=h($listing['dealer_name'])?><?php if (!empty($listing['dealer_company'])): ?> · <?=h($listing['dealer_company'])?><?php endif; ?></p>
                  <?php if (!empty($listing['summary'])): ?>
                    <p class="listing-summary"><?=h($listing['summary'])?></p>
                  <?php endif; ?>
                </div>
                <div class="meta-group">
                  <span class="meta-chip"><i class="bi bi-box-seam"></i> <?=h($packageCount)?> paket</span>
                  <?php if (!empty($listing['published_at'])): ?>
                    <span class="meta-chip"><i class="bi bi-broadcast-pin"></i> <?=h(date('d.m.Y', strtotime($listing['published_at'])))?> yayında</span>
                  <?php endif; ?>
                </div>
                <?php if (!empty($listing['description'])): ?>
                  <div class="listing-description"><?=nl2br(h($listing['description']))?></div>
                <?php endif; ?>
                <?php if ($packages): ?>
                  <div class="package-stack">
                    <?php foreach ($packages as $package): ?>
                      <div class="package-item">
                        <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                          <strong><?=h($package['name'])?></strong>
                          <span><?=format_currency((int)$package['price_cents'])?></span>
                        </div>
                        <?php if (!empty($package['description'])): ?>
                          <p><?=h($package['description'])?></p>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
                <div class="contact-actions">
                  <?php if (!empty($listing['dealer_email'])): ?>
                    <a class="contact-chip" href="mailto:<?=h($listing['dealer_email'])?>"><i class="bi bi-envelope-open"></i> E-posta</a>
                  <?php endif; ?>
                  <?php if (!empty($listing['dealer_phone'])): ?>
                    <a class="contact-chip" href="tel:<?=h(preg_replace('~[^0-9+]~', '', $listing['dealer_phone']))?>"><i class="bi bi-telephone-outbound"></i> Ara</a>
                  <?php endif; ?>
                  <a class="cta-link" href="<?=h(BASE_URL.'/dealer/login.php')?>"><i class="bi bi-person-plus"></i> Bayi Ol</a>
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
