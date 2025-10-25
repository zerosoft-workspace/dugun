<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/site.php';
require_once __DIR__.'/../includes/listings.php';
require_once __DIR__.'/../includes/theme.php';
require_once __DIR__.'/../includes/login_header.php';
require_once __DIR__.'/../includes/public_header.php';

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
  :root {
    --ink:#0f172a;
    --muted:#64748b;
    --brand:#0ea5b5;
    --brand-dark:#0b8b98;
    --surface:#ffffff;
    --surface-soft:#f8fafc;
    --bg:#f3f4f6;
    --border:rgba(148,163,184,.2);
  }
  :root[data-theme="dark"] {
    --ink:#e2e8f0;
    --muted:#94a3b8;
    --brand:#38bdf8;
    --brand-dark:#0ea5b5;
    --surface:rgba(15,23,42,.92);
    --surface-soft:rgba(15,23,42,.82);
    --bg:#020617;
    --border:rgba(56,189,248,.24);
  }
  body { margin:0; min-height:100vh; background:linear-gradient(180deg,var(--bg),#fff); font-family:'Inter',sans-serif; color:var(--ink); overflow-x:hidden; transition:background .25s ease,color .25s ease; }
  [data-theme="dark"] body { background:radial-gradient(circle at top,#0f172a 0%,#020617 52%,#010b17 100%); color:var(--ink); }
  .cta-bar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;}
  .btn-brand{background:linear-gradient(135deg,#0ea5b5,#0b8b98);color:#fff;border:none;border-radius:999px;padding:10px 24px;font-weight:700;transition:transform .18s ease,box-shadow .18s ease;}
  .btn-brand:hover{background:var(--brand-dark);color:#fff;transform:translateY(-1px);box-shadow:0 20px 46px -26px rgba(14,165,181,.5);}
  [data-theme="dark"] .btn-brand{background:linear-gradient(135deg,#38bdf8,#0ea5b5);color:#04121f;box-shadow:0 26px 60px -36px rgba(8,47,73,.78);}
  [data-theme="dark"] .btn-brand:hover{color:#04121f;}
  .btn-guest{border-radius:999px;border:1px solid rgba(14,165,181,0.3);color:var(--brand);font-weight:600;padding:10px 22px;background:rgba(14,165,181,0.08);transition:all .18s ease;}
  .btn-guest:hover{color:#fff;background:var(--brand);border-color:var(--brand);}
  [data-theme="dark"] .btn-guest{border-color:rgba(56,189,248,.4);color:#38bdf8;background:rgba(56,189,248,.12);}
  [data-theme="dark"] .btn-guest:hover{background:rgba(56,189,248,.32);color:#04121f;}
  .page-shell { max-width:1280px; margin:0 auto; }
  .hero { border-radius:32px; background:linear-gradient(135deg,rgba(14,165,181,.92),rgba(15,23,42,.85)); color:#fff; padding:72px 64px; position:relative; overflow:hidden; box-shadow:0 40px 110px -60px rgba(15,23,42,.55); }
  .hero::after { content:""; position:absolute; inset:auto -140px -180px -140px; height:320px; background:rgba(255,255,255,.16); filter:blur(90px); }
  [data-theme="dark"] .hero { background:linear-gradient(140deg,rgba(14,165,181,.32),rgba(15,23,42,.92)); box-shadow:0 48px 120px -64px rgba(8,47,73,.9); }
  [data-theme="dark"] .hero::after { background:radial-gradient(circle at center,rgba(56,189,248,.24),transparent 70%); }
  .hero h1 { font-size:2.8rem; font-weight:800; margin-bottom:.75rem; position:relative; z-index:1; }
  .hero p { max-width:620px; font-size:1.02rem; opacity:.92; position:relative; z-index:1; }
  [data-theme="dark"] .hero p { color:rgba(226,232,240,.82); }
  .hero .breadcrumb-link { position:relative; z-index:1; color:rgba(255,255,255,.9); text-decoration:none; font-weight:600; }
  .hero .breadcrumb-link:hover { text-decoration:underline; }
  .hero .badge { position:relative; z-index:1; font-size:.85rem; border-radius:999px; padding:.5rem 1.1rem; background:rgba(255,255,255,.18); backdrop-filter:blur(8px); font-weight:600; }
  [data-theme="dark"] .hero .badge { background:rgba(56,189,248,.18); }
  .filter-card { margin-top:-48px; border-radius:22px; background:var(--surface); box-shadow:0 28px 80px -50px rgba(15,23,42,.35); padding:28px; position:relative; z-index:2; border:1px solid var(--border); transition:background .25s ease,border-color .25s ease,box-shadow .25s ease; }
  [data-theme="dark"] .filter-card { box-shadow:0 34px 90px -58px rgba(8,47,73,.85); }
  .filter-card .form-label { font-weight:600; color:var(--ink); }
  .filter-card .form-select, .filter-card .form-control { border-radius:12px; padding:.6rem .85rem; border:1px solid rgba(148,163,184,.25); background:#fff; color:var(--ink); transition:background .25s ease,border-color .25s ease,color .25s ease; }
  [data-theme="dark"] .filter-card .form-select, [data-theme="dark"] .filter-card .form-control { background:var(--surface-soft); border-color:rgba(56,189,248,.24); color:var(--ink); }
  .filter-card button { border-radius:12px; padding:.65rem 1.5rem; font-weight:600; }
  .filter-reset { color:var(--brand); text-decoration:none; font-weight:600; }
  .filter-reset:hover { text-decoration:underline; color:var(--brand-dark); }
  [data-theme="dark"] .filter-reset { color:#38bdf8; }
  [data-theme="dark"] .filter-reset:hover { color:#7dd3fc; }
  .listing-feed { display:flex; flex-direction:column; gap:28px; }
  .listing-card { background:var(--surface); border:1px solid var(--border); border-radius:24px; box-shadow:0 26px 70px -48px rgba(15,23,42,.4); overflow:hidden; display:flex; gap:0; transition:box-shadow .25s ease, transform .25s ease, border-color .25s ease; }
  .listing-card:hover { transform:translateY(-6px); box-shadow:0 40px 110px -60px rgba(15,23,42,.45); }
  .listing-card.highlight { border-color:rgba(14,165,181,.45); box-shadow:0 48px 120px -60px rgba(14,165,181,.5); }
  [data-theme="dark"] .listing-card { box-shadow:0 32px 90px -58px rgba(8,47,73,.85); }
  [data-theme="dark"] .listing-card.highlight { border-color:rgba(56,189,248,.45); box-shadow:0 54px 130px -70px rgba(56,189,248,.55); }
  .listing-media { position:relative; width:280px; min-height:220px; background-size:cover; background-position:center; flex-shrink:0; }
  .listing-media.no-image { background:linear-gradient(135deg,rgba(14,165,181,.14),rgba(148,163,184,.18)); display:flex; align-items:center; justify-content:center; color:var(--brand-dark); font-weight:700; font-size:1.6rem; }
  .listing-media::after { content:""; position:absolute; inset:0; background:linear-gradient(180deg,rgba(15,23,42,.1),rgba(15,23,42,.55)); opacity:.55; transition:opacity .25s ease; }
  .listing-media.no-image::after { display:none; }
  [data-theme="dark"] .listing-media::after { opacity:.75; }
  .listing-media .category-badge { position:absolute; left:18px; top:18px; padding:.45rem 1rem; border-radius:999px; background:rgba(255,255,255,.85); color:var(--ink); font-weight:600; font-size:.85rem; }
  [data-theme="dark"] .listing-media .category-badge { background:rgba(15,23,42,.72); color:#38bdf8; border:1px solid rgba(56,189,248,.32); }
  .thumb-strip { position:absolute; left:18px; bottom:18px; display:flex; gap:8px; z-index:1; }
  .thumb-strip span { width:54px; height:54px; border-radius:14px; background-size:cover; background-position:center; border:2px solid rgba(255,255,255,.85); box-shadow:0 10px 22px -12px rgba(15,23,42,.45); }
  [data-theme="dark"] .thumb-strip span { border-color:rgba(15,23,42,.85); box-shadow:0 16px 34px -20px rgba(8,47,73,.8); }
  .listing-body { flex:1; padding:26px 28px; display:flex; flex-direction:column; gap:18px; }
  .listing-header { display:flex; justify-content:space-between; align-items:flex-start; gap:18px; flex-wrap:wrap; }
  .listing-title { font-size:1.4rem; font-weight:700; color:var(--ink); margin:0 0 .35rem; }
  .listing-location { display:flex; align-items:center; gap:.5rem; font-size:.95rem; color:var(--muted); font-weight:600; }
  [data-theme="dark"] .listing-location { color:rgba(148,163,184,.85); }
  .listing-summary { margin:0; color:#334155; font-size:.95rem; line-height:1.6; max-width:640px; }
  [data-theme="dark"] .listing-summary { color:rgba(226,232,240,.78); }
  .dealer-meta { display:flex; flex-direction:column; gap:.3rem; color:#475569; font-size:.9rem; min-width:180px; }
  .dealer-meta strong { font-size:1rem; color:var(--ink); }
  [data-theme="dark"] .dealer-meta { color:rgba(148,163,184,.8); }
  [data-theme="dark"] .dealer-meta strong { color:var(--ink); }
  .listing-meta { display:flex; flex-wrap:wrap; gap:12px; font-size:.85rem; color:#64748b; font-weight:600; }
  [data-theme="dark"] .listing-meta { color:rgba(148,163,184,.75); }
  .listing-meta span { display:inline-flex; align-items:center; gap:.45rem; }
  .package-list { display:grid; gap:12px; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); }
  .package-item { border:1px solid rgba(14,165,181,.25); border-radius:16px; padding:12px 14px; background:rgba(14,165,181,.07); }
  .package-item strong { display:block; font-weight:600; color:var(--ink); margin-bottom:2px; }
  .package-item span { font-weight:700; color:var(--brand-dark); }
  [data-theme="dark"] .package-item { background:rgba(56,189,248,.08); border-color:rgba(56,189,248,.28); }
  [data-theme="dark"] .package-item span { color:#38bdf8; }
  .listing-footer { display:flex; flex-wrap:wrap; align-items:center; gap:12px; }
  .contact-links { display:flex; gap:10px; flex-wrap:wrap; }
  .contact-chip { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:999px; background:rgba(14,165,181,.14); color:var(--brand-dark); font-weight:600; text-decoration:none; max-width:260px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; transition:background .2s ease,color .2s ease,border-color .2s ease; }
  .contact-chip span { display:inline-block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .contact-chip i { font-size:1rem; }
  .contact-chip:hover { background:rgba(14,165,181,.24); color:var(--ink); }
  [data-theme="dark"] .contact-chip { background:rgba(56,189,248,.12); color:#38bdf8; border:1px solid rgba(56,189,248,.24); }
  [data-theme="dark"] .contact-chip:hover { background:rgba(56,189,248,.22); color:#04121f; }
  .detail-link { margin-left:auto; display:inline-flex; align-items:center; gap:6px; padding:9px 18px; border-radius:999px; border:1px solid rgba(14,165,181,.45); color:var(--ink); font-weight:600; text-decoration:none; transition:background .2s ease,color .2s ease,border-color .2s ease; }
  .detail-link:hover { background:rgba(14,165,181,.08); }
  [data-theme="dark"] .detail-link { border-color:rgba(56,189,248,.4); color:#38bdf8; }
  [data-theme="dark"] .detail-link:hover { background:rgba(56,189,248,.16); color:#04121f; }
  .empty-state { border-radius:24px; border:2px dashed rgba(148,163,184,.35); padding:54px; text-align:center; background:rgba(255,255,255,.92); color:var(--muted); }
  [data-theme="dark"] .empty-state { background:rgba(15,23,42,.82); border-color:rgba(56,189,248,.28); color:rgba(148,163,184,.78); }
  .listing-title,
  .listing-summary,
  .dealer-meta,
  .dealer-meta strong,
  .listing-meta span,
  .package-item,
  .package-item strong,
  .package-item span,
  .contact-chip,
  .contact-chip span {
    word-break:break-word;
    overflow-wrap:anywhere;
  }
  @media (max-width: 992px) {
    .hero { padding:60px 28px; }
    .listing-card { flex-direction:column; }
    .listing-media { width:100%; min-height:220px; }
    .detail-link { margin-left:0; }
  }
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
  <?=theme_head_assets()?>
  <style>
    <?=login_header_styles() . PHP_EOL . $pageStyles?>
  </style>
</head>
<body>
<?php site_public_header('partners'); ?>
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
                      <a class="contact-chip" href="mailto:<?=h($contactEmail)?>" title="<?=h($contactEmail)?>"><i class="bi bi-envelope"></i> <span><?=h($contactEmail)?></span></a>
                    <?php endif; ?>
                    <?php if ($contactPhone && $dialPhone): ?>
                      <a class="contact-chip" href="tel:<?=h($dialPhone)?>" title="<?=h($contactPhone)?>"><i class="bi bi-telephone-outbound"></i> <span><?=h($contactPhone)?></span></a>
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
