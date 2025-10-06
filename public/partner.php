<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/site.php';
require_once __DIR__.'/../includes/listings.php';
require_once __DIR__.'/../includes/theme.php';

install_schema();

$slug = trim($_GET['listing'] ?? '');
$listing = $slug !== '' ? listing_find_by_slug($slug) : null;

if (!$listing) {
  http_response_code(404);
}

$gallery = $listing['media'] ?? [];
$packages = $listing['packages'] ?? [];
$contactEmail = $listing['contact_email'] ?? '';
$contactPhone = $listing['contact_phone'] ?? '';
$dialPhone = $contactPhone ? preg_replace('~[^0-9+]~', '', $contactPhone) : '';
$heroUrl = $listing['hero_url'] ?? null;
if (!$heroUrl && $gallery) {
  $heroUrl = $gallery[0]['url'] ?? null;
}
$related = [];
if ($listing) {
  $relatedFilters = [];
  if (!empty($listing['category_id'])) {
    $relatedFilters['category_id'] = (int)$listing['category_id'];
  }
  if (!empty($listing['city'])) {
    $relatedFilters['city'] = $listing['city'];
  }
  $candidateListings = listing_public_search($relatedFilters);
  foreach ($candidateListings as $candidate) {
    if ((int)$candidate['id'] === (int)$listing['id']) {
      continue;
    }
    $related[] = $candidate;
    if (count($related) >= 3) {
      break;
    }
  }
}

$pageTitle = $listing ? $listing['title'].' — Anlaşmalı Şirketler' : 'İlan bulunamadı — Anlaşmalı Şirketler';
$pageStyles = <<<'CSS'
<style>
  :root {
    --ink:#0f172a;
    --muted:#64748b;
    --brand:#0ea5b5;
    --brand-dark:#0b8b98;
    --surface:#ffffff;
  }
  body { background:#f3f4f6; font-family:'Inter',sans-serif; color:var(--ink); overflow-x:hidden; }
  .page-shell { max-width:1240px; margin:0 auto; padding:40px 0 80px; }
  .breadcrumb-link { color:var(--brand-dark); text-decoration:none; font-weight:600; }
  .breadcrumb-link:hover { text-decoration:underline; }
  .hero-card { margin-top:16px; border-radius:28px; background:linear-gradient(135deg,rgba(14,165,181,.94),rgba(15,23,42,.88)); color:#fff; padding:60px 56px; box-shadow:0 38px 100px -60px rgba(15,23,42,.55); position:relative; overflow:hidden; }
  .hero-card::after { content:""; position:absolute; inset:auto -160px -220px -160px; height:360px; background:rgba(255,255,255,.16); filter:blur(110px); }
  .hero-card > * { position:relative; z-index:1; }
  .hero-badges { display:flex; flex-wrap:wrap; gap:12px; margin-bottom:18px; }
  .hero-badge { padding:.55rem 1.2rem; border-radius:999px; background:rgba(255,255,255,.18); backdrop-filter:blur(6px); font-weight:600; font-size:.85rem; }
  .hero-title { font-size:2.8rem; font-weight:800; margin-bottom:12px; }
  .hero-location { display:flex; align-items:center; gap:10px; font-size:1.05rem; font-weight:600; margin-bottom:12px; }
  .hero-summary { max-width:680px; font-size:1.05rem; opacity:.92; }
  .hero-title,
  .hero-summary,
  .description-card p,
  .info-list,
  .info-list span,
  .packages-table td,
  .packages-table th,
  .panel-card,
  .panel-card h3,
  .contact-chip,
  .contact-chip span {
    word-break:break-word;
    overflow-wrap:anywhere;
  }
  .content-grid { display:grid; gap:32px; grid-template-columns:minmax(0,2fr) minmax(280px,1fr); margin-top:40px; }
  @media (max-width: 1100px) { .content-grid { grid-template-columns:1fr; } }
  .gallery-card { border-radius:24px; background:var(--surface); border:1px solid rgba(148,163,184,.25); box-shadow:0 30px 80px -50px rgba(15,23,42,.4); padding:20px; }
  .gallery-main { border-radius:20px; overflow:hidden; position:relative; padding-top:56%; background-size:cover; background-position:center; background-color:#e2e8f0; display:flex; align-items:center; justify-content:center; color:var(--brand-dark); font-size:2.4rem; font-weight:700; }
  .thumb-list { margin-top:16px; display:grid; gap:12px; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); }
  .thumb-list span { display:block; padding-top:70%; border-radius:16px; background-size:cover; background-position:center; border:1px solid rgba(148,163,184,.3); }
  .panel-card { border-radius:24px; background:var(--surface); border:1px solid rgba(148,163,184,.25); box-shadow:0 30px 70px -48px rgba(15,23,42,.38); padding:24px; }
  .panel-title { font-size:1.15rem; font-weight:700; margin-bottom:18px; }
  .contact-stack { display:flex; flex-direction:column; gap:12px; }
  .contact-chip {
    display:flex;
    align-items:center;
    gap:10px;
    padding:12px 16px;
    border-radius:16px;
    background:rgba(14,165,181,.12);
    color:var(--brand-dark);
    font-weight:600;
    text-decoration:none;
    max-width:280px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
  }
  .contact-chip span {
    display:inline-block;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
  }
  .contact-chip:hover { background:rgba(14,165,181,.2); color:var(--ink); }
  .info-list { margin:0; padding:0; list-style:none; display:flex; flex-direction:column; gap:10px; font-size:.95rem; color:#475569; }
  .info-list span { font-weight:600; color:var(--ink); }
  .packages-table { width:100%; border-collapse:separate; border-spacing:0; overflow:hidden; border-radius:18px; border:1px solid rgba(148,163,184,.35); }
  .packages-table thead th { background:rgba(14,165,181,.12); padding:14px; font-size:.85rem; text-transform:uppercase; letter-spacing:.08em; color:var(--ink); }
  .packages-table tbody td { padding:16px; background:#fff; border-top:1px solid rgba(148,163,184,.2); font-size:.95rem; }
  .packages-table tbody tr:first-child td { border-top:none; }
  .description-card { margin-top:28px; border-radius:24px; background:var(--surface); border:1px solid rgba(148,163,184,.25); box-shadow:0 28px 70px -48px rgba(15,23,42,.38); padding:28px; }
  .description-card h3 { font-size:1.3rem; font-weight:700; margin-bottom:16px; }
  .description-card p { margin-bottom:0; white-space:pre-line; line-height:1.7; font-size:1.02rem; color:#334155; }
  .related-grid { margin-top:40px; display:grid; gap:24px; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); }
  .related-card { border-radius:20px; background:var(--surface); border:1px solid rgba(148,163,184,.25); box-shadow:0 22px 60px -46px rgba(15,23,42,.35); overflow:hidden; text-decoration:none; color:inherit; transition:transform .2s ease, box-shadow .2s ease; }
  .related-card:hover { transform:translateY(-4px); box-shadow:0 34px 90px -50px rgba(15,23,42,.4); }
  .related-cover { height:160px; background-size:cover; background-position:center; position:relative; }
  .related-body { padding:18px 20px; }
  .related-body h4 { font-size:1.05rem; font-weight:700; margin-bottom:8px; }
  .related-body p { margin:0; color:#475569; font-size:.9rem; }
  .empty-related { border-radius:20px; background:rgba(14,165,181,.08); padding:28px; text-align:center; color:#475569; font-weight:600; }
  .not-found { margin-top:60px; border-radius:24px; background:var(--surface); border:1px solid rgba(148,163,184,.3); box-shadow:0 22px 60px -40px rgba(15,23,42,.35); padding:40px; text-align:center; }
  .not-found h2 { font-size:2rem; font-weight:700; margin-bottom:16px; }
  .not-found p { color:#475569; margin-bottom:24px; font-size:1rem; }
  .back-link { display:inline-flex; align-items:center; gap:8px; border-radius:999px; border:1px solid rgba(14,165,181,.45); padding:10px 20px; font-weight:600; color:var(--brand-dark); text-decoration:none; }
  .back-link:hover { background:rgba(14,165,181,.08); }
</style>
CSS;
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h(APP_NAME)?> — <?=h($pageTitle)?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <?=theme_head_assets()?>
  <?=$pageStyles?>
</head>
<body>
  <div class="container page-shell">
    <a class="breadcrumb-link" href="<?=h(BASE_URL.'/public/partners.php')?>"><i class="bi bi-arrow-left"></i> Anlaşmalı şirketlere dön</a>

    <?php if (!$listing): ?>
      <div class="not-found">
        <h2>İlan bulunamadı</h2>
        <p>Aradığınız ilan yayında olmayabilir veya kaldırılmış olabilir. Diğer iş ortaklarımızı inceleyebilirsiniz.</p>
        <a class="back-link" href="<?=h(BASE_URL.'/public/partners.php')?>"><i class="bi bi-card-list"></i> İlanları Gör</a>
      </div>
    <?php else: ?>
      <section class="hero-card mt-4">
        <div class="hero-badges">
          <span class="hero-badge"><i class="bi bi-check2-circle me-1"></i> Onaylı bayi</span>
          <?php if (!empty($listing['category_name'])): ?><span class="hero-badge"><i class="bi bi-tag me-1"></i> <?=h($listing['category_name'])?></span><?php endif; ?>
          <?php if (!empty($listing['city'])): ?><span class="hero-badge"><i class="bi bi-geo-alt me-1"></i> <?=h($listing['city'])?> / <?=h($listing['district'])?></span><?php endif; ?>
        </div>
        <h1 class="hero-title"><?=h($listing['title'])?></h1>
        <div class="hero-location"><i class="bi bi-shop-window"></i> <?=h($listing['dealer_name'])?><?php if (!empty($listing['dealer_company'])): ?> · <?=h($listing['dealer_company'])?><?php endif; ?></div>
        <?php if (!empty($listing['summary'])): ?>
          <p class="hero-summary"><?=h($listing['summary'])?></p>
        <?php endif; ?>
      </section>

      <div class="content-grid">
        <div class="gallery-card">
          <div class="gallery-main"<?= $heroUrl ? ' style="background-image:url(\''.h($heroUrl).'\')"' : '' ?>>
            <?php if (!$heroUrl): ?><?=h(mb_strtoupper(mb_substr($listing['title'] ?: APP_NAME, 0, 1, 'UTF-8'), 'UTF-8'))?><?php endif; ?>
          </div>
          <?php if ($gallery): ?>
            <div class="thumb-list">
              <?php foreach ($gallery as $media): ?>
                <span style="background-image:url('<?=h($media['url'])?>')"></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ($packages): ?>
            <div class="description-card mt-4">
              <h3>Paketler</h3>
              <table class="packages-table">
                <thead>
                  <tr>
                    <th>Adı</th>
                    <th>Fiyat</th>
                    <th>Açıklama</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($packages as $package): ?>
                    <tr>
                      <td><?=h($package['name'])?></td>
                      <td><?=format_currency((int)$package['price_cents'])?></td>
                      <td><?=h($package['description'] ?? '')?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

          <?php if (!empty($listing['description'])): ?>
            <div class="description-card">
              <h3>Detaylı Açıklama</h3>
              <p><?=nl2br(h($listing['description']))?></p>
            </div>
          <?php endif; ?>
        </div>

        <div class="d-flex flex-column gap-4">
          <div class="panel-card">
            <h3 class="panel-title">İletişim</h3>
            <div class="contact-stack">
              <?php if ($contactEmail): ?>
                <a class="contact-chip" href="mailto:<?=h($contactEmail)?>" title="<?=h($contactEmail)?>"><i class="bi bi-envelope"></i> <span><?=h($contactEmail)?></span></a>
              <?php endif; ?>
              <?php if ($contactPhone && $dialPhone): ?>
                <a class="contact-chip" href="tel:<?=h($dialPhone)?>" title="<?=h($contactPhone)?>"><i class="bi bi-telephone"></i> <span><?=h($contactPhone)?></span></a>
              <?php endif; ?>
            </div>
            <p class="text-muted small mt-3 mb-0">Potansiyel müşteriler bu ilan üzerinden doğrudan bayiyle iletişime geçer.</p>
          </div>

          <div class="panel-card">
            <h3 class="panel-title">Bayi Bilgileri</h3>
            <ul class="info-list">
              <li><span>Firma:</span> <?=h($listing['dealer_name'])?><?php if (!empty($listing['dealer_company'])): ?> — <?=h($listing['dealer_company'])?><?php endif; ?></li>
              <li><span>Lokasyon:</span> <?=h($listing['city'])?> / <?=h($listing['district'])?></li>
              <?php if (!empty($listing['published_at'])): ?><li><span>Yayında:</span> <?=h(date('d.m.Y', strtotime($listing['published_at'])))?></li><?php endif; ?>
              <?php if ($packages): ?><li><span>Paket Sayısı:</span> <?=h(count($packages))?></li><?php endif; ?>
            </ul>
          </div>
        </div>
      </div>

      <section class="mt-5">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h2 class="h4 fw-bold">Benzer İlanlar</h2>
          <a class="back-link" href="<?=h(BASE_URL.'/public/partners.php')?>"><i class="bi bi-card-list"></i> Tüm ilanlar</a>
        </div>
        <?php if ($related): ?>
          <div class="related-grid">
            <?php foreach ($related as $item): ?>
              <?php
                $cover = $item['hero_url'] ?? null;
                if (!$cover && !empty($item['media'])) {
                  $first = $item['media'][0]['url'] ?? null;
                  if ($first) { $cover = $first; }
                }
              ?>
              <a class="related-card" href="<?=h(BASE_URL.'/public/partner.php?listing='.urlencode($item['slug']))?>">
                <div class="related-cover"<?= $cover ? ' style="background-image:url(\''.h($cover).'\')"' : '' ?>></div>
                <div class="related-body">
                  <h4><?=h($item['title'])?></h4>
                  <p><i class="bi bi-geo-alt"></i> <?=h($item['city'])?> / <?=h($item['district'])?></p>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="empty-related">Bu kategori için başka ilan bulunamadı. Yakında yeni iş ortaklarımızı burada görebilirsiniz.</div>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
