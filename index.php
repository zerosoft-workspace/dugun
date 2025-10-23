<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/functions.php';
require_once __DIR__.'/includes/site.php';
require_once __DIR__.'/includes/theme.php';
require_once __DIR__.'/includes/public_header.php';
require_once __DIR__.'/includes/login_header.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

install_schema();

$packages = site_public_packages();
$content = site_public_content();
$defaults = site_content_defaults();
$faqItems = $content['faq_items'];
$footerNav = $content['footer_nav_links'];
$heroBadge = trim((string)($content['hero_badge'] ?? ''));
$heroTitle = trim((string)($content['hero_title'] ?? ''));
$heroText = trim((string)($content['hero_text'] ?? ''));
$heroPrimaryLabel = trim((string)($content['hero_primary_label'] ?? ''));
$heroPrimaryUrl = site_resolve_button_url($content['hero_primary_url'] ?? '') ?? '';
$heroSecondaryLabel = trim((string)($content['hero_secondary_label'] ?? ''));
$heroSecondaryUrl = site_resolve_button_url($content['hero_secondary_url'] ?? '') ?? '';
$heroMetrics = $content['hero_metrics'] ?? [];
if (!is_array($heroMetrics) || !$heroMetrics) {
  $heroMetrics = $defaults['hero_metrics'];
}
$heroImageMain = $content['hero_image_main'] ?? '';
$heroImageSecondary = $content['hero_image_secondary'] ?? '';
$aboutImage = $content['about_image'] ?? '';
$aboutBadge = trim((string)($content['about_badge'] ?? ''));
$aboutTitle = trim((string)($content['about_title'] ?? ''));
$aboutText = trim((string)($content['about_text'] ?? ''));
$aboutFeatures = $content['about_features'] ?? [];
if (!is_array($aboutFeatures) || !$aboutFeatures) {
  $aboutFeatures = $defaults['about_features'];
}
$featureBlocks = $content['feature_blocks'] ?? [];
if (!is_array($featureBlocks) || !$featureBlocks) {
  $featureBlocks = $defaults['feature_blocks'];
}
$timelineTitle = trim((string)($content['timeline_title'] ?? ''));
$timelineText = trim((string)($content['timeline_text'] ?? ''));
$timelineSteps = $content['timeline_steps'] ?? [];
if (!is_array($timelineSteps) || !$timelineSteps) {
  $timelineSteps = $defaults['timeline_steps'];
}
$packagesTitle = trim((string)($content['packages_title'] ?? ''));
$packagesText = trim((string)($content['packages_text'] ?? ''));
$packagesHighlights = $content['packages_highlights'] ?? [];
if (!is_array($packagesHighlights) || !$packagesHighlights) {
  $packagesHighlights = $defaults['packages_highlights'];
}
$galleryImages = $content['gallery_images'] ?? [];
$galleryImages = is_array($galleryImages) ? $galleryImages : [];
$dealerShowcaseImage = $content['dealer_showcase_image'] ?? '';
$dealerBadge = trim((string)($content['dealer_badge'] ?? ''));
$dealerTitle = trim((string)($content['dealer_title'] ?? ''));
$dealerText = trim((string)($content['dealer_text'] ?? ''));
$dealerHighlights = $content['dealer_highlights'] ?? [];
if (!is_array($dealerHighlights) || !$dealerHighlights) {
  $dealerHighlights = $defaults['dealer_highlights'];
}
$dealerButtonLabel = trim((string)($content['dealer_button_label'] ?? ''));
$dealerButtonUrl = site_resolve_button_url($content['dealer_button_url'] ?? '') ?? '';
$galleryTitle = trim((string)($content['gallery_title'] ?? ''));
$galleryText = trim((string)($content['gallery_text'] ?? ''));
$testimonials = $content['testimonials'] ?? [];
if (!is_array($testimonials) || !$testimonials) {
  $testimonials = $defaults['testimonials'];
}
$contactWebsiteUrl = site_normalize_url($content['contact_website'] ?? '') ?? '';
$contactWebsiteLabel = $content['contact_website_label'] ?? '';
$contactPhoneHref = site_phone_href($content['contact_phone'] ?? '') ?? '';
$contactPrimaryUrl = site_resolve_button_url($content['contact_primary_url'] ?? '') ?? '';
$contactSecondaryUrl = site_resolve_button_url($content['contact_secondary_url'] ?? '') ?? '';
$contactCtaButtonUrl = site_resolve_button_url($content['contact_cta_button_url'] ?? '') ?? '';
$ctaBannerTitle = trim((string)($content['cta_banner_title'] ?? ''));
$ctaBannerText = trim((string)($content['cta_banner_text'] ?? ''));
$ctaBannerButtonLabel = trim((string)($content['cta_banner_button_label'] ?? ''));
$ctaBannerButtonUrl = site_resolve_button_url($content['cta_banner_button_url'] ?? '') ?? '';
$leadFormTitle = trim((string)($content['lead_form_title'] ?? ''));
$leadFormText = trim((string)($content['lead_form_text'] ?? ''));
$leadFormBullets = $content['lead_form_bullets'] ?? [];
if (!is_array($leadFormBullets) || !$leadFormBullets) {
  $leadFormBullets = $defaults['lead_form_bullets'];
}
$leadFormNotice = trim((string)($content['lead_form_notice'] ?? ''));
$leadFormSubmitLabel = trim((string)($content['lead_form_submit_label'] ?? ''));
$formData = $_SESSION['lead_form'] ?? [
  'customer_name' => '',
  'customer_email' => '',
  'customer_phone' => '',
  'event_title' => '',
  'event_date' => '',
  'referral_code' => '',
  'notes' => '',
  'package_id' => $packages[0]['id'] ?? null,
];
unset($_SESSION['lead_form']);

$success = $_SESSION['lead_success'] ?? null;
unset($_SESSION['lead_success']);
?>
<!doctype html><html lang="tr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h(APP_NAME)?> — Dijital Etkinlik Deneyiminiz</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?=theme_head_assets()?>
<style>
  <?=login_header_styles()?>
  :root {
    --ink:#0f172a;
    --muted:#6b7280;
    --brand:#0ea5b5;
    --brand-dark:#0c8d9a;
    --card:#ffffff;
    --bg:#f4f7fb;
    --border:rgba(14,165,181,0.14);
  }
  :root[data-theme="dark"] {
    --ink:#e2e8f0;
    --muted:#94a3b8;
    --brand:#38bdf8;
    --brand-dark:#0ea5b5;
    --card:rgba(15,23,42,0.92);
    --bg:#020617;
    --border:rgba(148,163,184,0.26);
  }
  body{background:linear-gradient(180deg,var(--bg),#fff);font-family:'Inter',sans-serif;color:var(--ink);}
  [data-theme="dark"] body{background:radial-gradient(circle at top,#0f172a 0%,#020617 46%,#010b17 100%);color:var(--ink);}
  .hero{position:relative;overflow:hidden;border-radius:36px;padding:96px 48px;background:linear-gradient(140deg,rgba(14,165,181,0.92),rgba(59,130,246,0.88));color:#fff;}
  [data-theme="dark"] .hero{background:linear-gradient(145deg,rgba(14,165,181,0.28),rgba(15,23,42,0.88));box-shadow:0 42px 110px -54px rgba(8,47,73,0.9);}
  .hero::after{content:"";position:absolute;inset:-120px -60px auto 40%;width:420px;height:420px;background:rgba(255,255,255,0.12);filter:blur(0);border-radius:50%;}
  [data-theme="dark"] .hero::after{background:radial-gradient(circle at center,rgba(56,189,248,0.22),transparent 68%);opacity:0.6;}
  .hero-visual{position:relative;z-index:1;}
  .hero-visual img{border-radius:24px;box-shadow:0 30px 90px rgba(15,118,110,0.35);}
  [data-theme="dark"] .hero-visual img{box-shadow:0 36px 110px -48px rgba(8,47,73,0.85);border:1px solid rgba(148,163,184,0.25);}
  .hero-visual img:nth-child(2){position:absolute;top:40%;left:50%;width:220px;border:6px solid rgba(255,255,255,0.8);transform:translate(-30%, -10%);}
  [data-theme="dark"] .hero-visual img:nth-child(2){border:6px solid rgba(15,23,42,0.82);background:rgba(15,23,42,0.92);}
  .metrics-card{border-radius:24px;background:rgba(255,255,255,0.16);padding:28px;backdrop-filter:blur(8px);}
  [data-theme="dark"] .metrics-card{background:rgba(15,23,42,0.68);border:1px solid rgba(148,163,184,0.22);box-shadow:0 30px 90px -60px rgba(8,47,73,0.8);}
  .feature-card{border-radius:24px;background:#fff;box-shadow:0 24px 60px rgba(148,163,184,0.18);padding:32px;transition:transform .25s ease,box-shadow .25s ease;}
  [data-theme="dark"] .feature-card{background:var(--card);border:1px solid var(--border);box-shadow:0 30px 70px -50px rgba(8,47,73,0.85);}
  .feature-card:hover{transform:translateY(-6px);box-shadow:0 36px 80px rgba(148,163,184,0.25);}
  [data-theme="dark"] .feature-card:hover{box-shadow:0 42px 90px -52px rgba(8,47,73,0.9);}
  .feature-icon{width:56px;height:56px;border-radius:18px;background:rgba(14,165,181,0.12);display:flex;align-items:center;justify-content:center;font-size:1.6rem;color:var(--brand);}
  [data-theme="dark"] .feature-icon{background:rgba(56,189,248,0.16);color:var(--brand);box-shadow:0 18px 40px -28px rgba(8,47,73,0.65);}
  .timeline-step{display:flex;gap:16px;padding:16px;border-radius:18px;background:#fff;box-shadow:0 16px 40px rgba(15,118,110,0.12);}
  [data-theme="dark"] .timeline-step{background:var(--card);border:1px solid var(--border);box-shadow:0 26px 70px -52px rgba(8,47,73,0.85);}
  .timeline-step span{width:44px;height:44px;border-radius:14px;background:rgba(14,165,181,0.12);color:var(--brand);display:flex;align-items:center;justify-content:center;font-weight:700;}
  [data-theme="dark"] .timeline-step span{background:rgba(56,189,248,0.12);color:var(--brand);}
  .package-card{border-radius:24px;border:1px solid rgba(14,165,181,0.12);background:#fff;height:100%;padding:32px;transition:transform .2s ease,box-shadow .2s ease;}
  [data-theme="dark"] .package-card{background:var(--card);border:1px solid var(--border);box-shadow:0 32px 80px -54px rgba(8,47,73,0.88);}
  .package-card:hover{transform:translateY(-6px);box-shadow:0 28px 70px rgba(15,118,110,0.18);}
  [data-theme="dark"] .package-card:hover{box-shadow:0 40px 90px -56px rgba(8,47,73,0.9);}
  .package-price{font-size:1.9rem;font-weight:800;color:var(--brand);}
  .gallery-grid{display:grid;gap:18px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));}
  .gallery-grid img{width:100%;height:220px;object-fit:cover;border-radius:24px;box-shadow:0 18px 50px rgba(15,118,110,0.18);}
  [data-theme="dark"] .gallery-grid img{box-shadow:0 26px 80px -60px rgba(8,47,73,0.82);border:1px solid rgba(148,163,184,0.22);}
  .testimonial{border-radius:24px;background:#fff;padding:32px;box-shadow:0 22px 60px rgba(15,118,110,0.16);position:relative;}
  [data-theme="dark"] .testimonial{background:var(--card);border:1px solid var(--border);box-shadow:0 34px 90px -58px rgba(8,47,73,0.88);}
  .testimonial::before{content:'“';position:absolute;top:-16px;left:24px;font-size:5rem;color:rgba(14,165,181,0.2);}
  [data-theme="dark"] .testimonial::before{color:rgba(56,189,248,0.24);}
  .cta-section{border-radius:32px;background:linear-gradient(135deg,#0ea5b5,#6366f1);color:#fff;padding:48px;}
  [data-theme="dark"] .cta-section{background:linear-gradient(135deg,rgba(56,189,248,0.18),rgba(14,116,198,0.42));backdrop-filter:blur(12px);border:1px solid rgba(148,163,184,0.2);}
  .form-section{border-radius:28px;background:#fff;box-shadow:0 32px 90px rgba(15,118,110,0.2);padding:48px;}
  [data-theme="dark"] .form-section{background:var(--card);border:1px solid var(--border);box-shadow:0 34px 100px -60px rgba(8,47,73,0.9);}
  .input-rounded{border-radius:16px;border:1px solid #d7e4eb;padding:12px 16px;}
  [data-theme="dark"] .input-rounded{background:rgba(15,23,42,0.82);border-color:rgba(148,163,184,0.26);color:var(--ink);}
  .btn-brand{background:var(--brand);color:#fff;border:none;border-radius:18px;padding:14px 32px;font-weight:700;}
  .btn-brand:hover{background:var(--brand-dark);color:#fff;}
  [data-theme="dark"] .hero .btn-light{background:rgba(226,232,240,0.08);color:#f8fafc;border:1px solid rgba(226,232,240,0.24);}
  [data-theme="dark"] .hero .btn-light:hover{background:rgba(226,232,240,0.16);color:#fff;}
  [data-theme="dark"] .hero .btn-outline-light{color:#f8fafc;border-color:rgba(226,232,240,0.35);}
  [data-theme="dark"] .hero .btn-outline-light:hover{background:rgba(226,232,240,0.16);color:#04121f;border-color:rgba(226,232,240,0.45);}
  .btn-guest{border-radius:999px;border:1px solid rgba(14,165,181,0.3);color:var(--brand);font-weight:600;padding:10px 22px;background:rgba(14,165,181,0.08);}
  .btn-guest:hover{color:#fff;background:var(--brand);border-color:var(--brand);}
  .muted{color:var(--muted);}
  [data-theme="dark"] .muted{color:var(--muted);}
  [data-theme="dark"] .badge.bg-light{background:rgba(56,189,248,0.16)!important;color:#38bdf8!important;}
  [data-theme="dark"] .badge.bg-light.text-dark{color:#38bdf8!important;}
  .nav-link{font-weight:600;color:var(--muted)!important;}
  .nav-link:hover,.nav-link:focus,.nav-link.active{color:var(--brand)!important;}
  .site-navbar{backdrop-filter:blur(10px);}
  [data-theme="dark"] .site-navbar{--nav-bg:rgba(15,23,42,0.88);background:var(--nav-bg) !important;border-bottom:1px solid rgba(148,163,184,0.18);box-shadow:0 26px 60px -44px rgba(8,47,73,0.85);}
  [data-theme="dark"] .site-navbar .navbar-brand{color:#f8fafc;}
  [data-theme="dark"] .site-navbar .nav-link{color:rgba(226,232,240,0.78)!important;}
  [data-theme="dark"] .site-navbar .nav-link:hover,[data-theme="dark"] .site-navbar .nav-link:focus,[data-theme="dark"] .site-navbar .nav-link.active{color:#38bdf8!important;}
  [data-theme="dark"] .site-navbar .btn-brand{background:linear-gradient(135deg,#38bdf8,#0ea5b5);color:#04121f;box-shadow:0 28px 70px -44px rgba(8,47,73,0.9);}
  [data-theme="dark"] .site-navbar .btn-brand:hover,[data-theme="dark"] .site-navbar .btn-brand:focus{color:#04121f;}
  .contact-card{border-radius:24px;background:#fff;box-shadow:0 24px 60px rgba(15,118,110,0.18);padding:32px;}
  [data-theme="dark"] .contact-card{background:var(--card);border:1px solid var(--border);box-shadow:0 34px 90px -58px rgba(8,47,73,0.88);}
  .contact-card ul{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:12px;}
  .contact-card ul li strong{color:var(--ink);min-width:84px;display:inline-block;}
  .contact-card ul li a{color:var(--brand);font-weight:600;text-decoration:none;}
  .contact-card ul li a:hover{color:var(--brand-dark);text-decoration:underline;}
  .contact-card .btn-outline-secondary{border-color:rgba(14,165,181,0.35);color:var(--brand);background:rgba(14,165,181,0.08);}
  .contact-card .btn-outline-secondary:hover{background:var(--brand);color:#fff;border-color:var(--brand);}
  [data-theme="dark"] .contact-card .btn-outline-secondary{color:#f8fafc;border-color:rgba(148,163,184,0.35);background:rgba(148,163,184,0.12);}
  [data-theme="dark"] .contact-card .btn-outline-secondary:hover{background:rgba(56,189,248,0.24);color:#04121f;border-color:rgba(56,189,248,0.45);}
  .btn-cta{border:none;border-radius:999px;padding:14px 34px;font-weight:700;background:linear-gradient(135deg,#38bdf8,#0ea5b5);color:#031525;box-shadow:0 20px 40px -18px rgba(14,165,181,0.65);transition:transform .18s ease,box-shadow .18s ease,opacity .18s ease;}
  .btn-cta:hover,.btn-cta:focus{color:#031525;transform:translateY(-2px);box-shadow:0 26px 55px -22px rgba(14,165,181,0.72);opacity:.95;}
  [data-theme="dark"] .btn-cta{background:linear-gradient(135deg,#38bdf8,#0ea5b5);color:#031525;box-shadow:0 28px 60px -30px rgba(8,47,73,0.85);}
  [data-theme="dark"] .btn-cta:hover,[data-theme="dark"] .btn-cta:focus{color:#031525;box-shadow:0 32px 70px -32px rgba(8,47,73,0.9);}
  .faq-surface{background:var(--card);border-radius:32px;padding:48px;box-shadow:0 42px 120px -68px rgba(15,118,110,0.35);position:relative;overflow:hidden;}
  .faq-surface::before{content:"";position:absolute;inset:-160px auto auto -120px;width:320px;height:320px;background:radial-gradient(circle at center,rgba(14,165,181,0.18),transparent 70%);}
  .faq-surface::after{content:"";position:absolute;inset:auto -140px -120px auto;width:260px;height:260px;background:radial-gradient(circle at center,rgba(59,130,246,0.18),transparent 70%);}
  [data-theme="dark"] .faq-surface{background:rgba(15,23,42,0.92);border:1px solid var(--border);box-shadow:0 60px 140px -80px rgba(8,47,73,0.85);}
  [data-theme="dark"] .faq-surface::before{background:radial-gradient(circle at center,rgba(56,189,248,0.16),transparent 70%);}
  [data-theme="dark"] .faq-surface::after{background:radial-gradient(circle at center,rgba(14,165,181,0.16),transparent 70%);}
  .faq-surface .accordion{position:relative;z-index:1;}
  .faq-surface .accordion-item{border:none;border-radius:20px;margin-bottom:12px;overflow:hidden;box-shadow:0 18px 50px -28px rgba(15,118,110,0.25);}
  .faq-surface .accordion-item:last-child{margin-bottom:0;}
  [data-theme="dark"] .faq-surface .accordion-item{background:rgba(15,23,42,0.82);box-shadow:0 28px 70px -42px rgba(8,47,73,0.8);}
  .faq-surface .accordion-button{font-weight:600;padding:18px 24px;border:none;box-shadow:none;}
  .faq-surface .accordion-button:not(.collapsed){background:rgba(14,165,181,0.12);color:var(--brand);box-shadow:none;}
  [data-theme="dark"] .faq-surface .accordion-button:not(.collapsed){background:rgba(56,189,248,0.18);color:#38bdf8;}
  .faq-surface .accordion-button:focus{box-shadow:none;border:none;}
  .faq-surface .accordion-body{padding:0 24px 18px;color:var(--muted);}
  .cta-bar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;}
  [data-theme="dark"] .site-navbar .navbar-brand img{filter:brightness(0) invert(1);}
  .navbar-toggler{border:none;box-shadow:none;}
  [data-theme="dark"] .navbar-toggler{filter:invert(1);}
  footer{background:var(--brand);color:#f0fdfa;padding:48px 0 40px;margin-top:48px;}
  [data-theme="dark"] footer{background:linear-gradient(180deg,#04121f,#020617);color:#e2e8f0;}
  footer h5, footer h6{color:#fff;}
  [data-theme="dark"] footer h5,[data-theme="dark"] footer h6{color:#f8fafc;}
  footer a{color:rgba(255,255,255,0.9);font-weight:600;text-decoration:none;}
  footer a:hover{color:#0f172a;text-decoration:underline;}
  [data-theme="dark"] footer a{color:rgba(226,232,240,0.82);}
  [data-theme="dark"] footer a:hover{color:#38bdf8;}
  .footer-payment-logo{height:28px;filter:brightness(0) invert(1);opacity:0.85;transition:opacity .2s ease;}
  .footer-payment-logo:hover{opacity:1;}
  [data-theme="dark"] .footer-payment-logo{opacity:0.65;}
  .footer-nav a{color:#fdfdfd;display:inline-block;margin-bottom:8px;}
  [data-theme="dark"] .footer-nav a{color:rgba(226,232,240,0.86);}
  .footer-nav a:hover{color:#0f172a;}
  [data-theme="dark"] .footer-nav a:hover{color:#38bdf8;}
  @media(max-width:992px){.hero{padding:72px 28px;}.hero-visual img:nth-child(2){display:none;}}
  @media(max-width:768px){.form-section{padding:32px;}.faq-surface{padding:32px 24px;}}
</style>
</head><body>
<?php site_public_header('home', $content); ?>

<main class="container py-5">
  <section class="hero mb-5">
    <div class="row align-items-center g-5 position-relative" style="z-index:2;">
      <div class="col-lg-6">
        <?php if ($heroBadge !== ''): ?>
          <span class="badge bg-light text-dark rounded-pill px-3 py-2 fw-semibold"><?=h($heroBadge)?></span>
        <?php endif; ?>
        <?php if ($heroTitle !== ''): ?>
          <h1 class="fw-bold display-5 mt-4 mb-3"><?=h($heroTitle)?></h1>
        <?php endif; ?>
        <?php if ($heroText !== ''): ?>
          <p class="lead mb-4"><?=nl2br(h($heroText))?></p>
        <?php endif; ?>
        <div class="d-flex flex-wrap gap-3">
          <?php if ($heroPrimaryUrl && $heroPrimaryLabel !== ''): ?>
            <a class="btn btn-light text-dark fw-semibold" href="<?=h($heroPrimaryUrl)?>"><?=h($heroPrimaryLabel)?></a>
          <?php endif; ?>
          <?php if ($heroSecondaryUrl && $heroSecondaryLabel !== ''): ?>
            <a class="btn btn-outline-light fw-semibold" href="<?=h($heroSecondaryUrl)?>"><?=h($heroSecondaryLabel)?></a>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-lg-6 hero-visual">
        <img src="<?=h($heroImageMain)?>" alt="Düğün kutlaması" class="img-fluid">
        <img src="<?=h($heroImageSecondary)?>" alt="Etkinlikten kare" class="img-fluid">
      </div>
    </div>
    <div class="row mt-5 g-4 position-relative" style="z-index:2;">
      <?php foreach ($heroMetrics as $metric):
        $metricValue = trim((string)($metric['value'] ?? ''));
        $metricLabel = trim((string)($metric['label'] ?? ''));
        if ($metricValue === '' && $metricLabel === '') {
          continue;
        }
      ?>
        <div class="col-md-4">
          <div class="metrics-card h-100">
            <?php if ($metricValue !== ''): ?><div class="h2 fw-bold mb-1"><?=h($metricValue)?></div><?php endif; ?>
            <?php if ($metricLabel !== ''): ?><div class="small"><?=h($metricLabel)?></div><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <?php if ($success): ?>
    <div class="alert alert-success shadow-sm"><?=h($success)?></div>
  <?php endif; ?>
  <?php flash_box(); ?>

  <section id="hakkimizda" class="mb-5">
    <div class="row align-items-center g-5">
      <div class="col-lg-6">
        <img class="img-fluid rounded-4 shadow-lg" src="<?=h($aboutImage)?>" alt="Mutlu etkinlik sahipleri">
      </div>
      <div class="col-lg-6">
        <?php if ($aboutBadge !== ''): ?>
          <span class="badge bg-light text-dark rounded-pill px-3 py-2 fw-semibold"><?=h($aboutBadge)?></span>
        <?php endif; ?>
        <?php if ($aboutTitle !== ''): ?>
          <h2 class="fw-bold mt-3"><?=h($aboutTitle)?></h2>
        <?php endif; ?>
        <?php if ($aboutText !== ''): ?>
          <p class="muted"><?=nl2br(h($aboutText))?></p>
        <?php endif; ?>
        <?php if ($aboutFeatures): ?>
          <div class="row g-3">
            <?php foreach ($aboutFeatures as $feature):
              $featureTitle = trim((string)($feature['title'] ?? ''));
              $featureText = trim((string)($feature['text'] ?? ''));
              if ($featureTitle === '' && $featureText === '') {
                continue;
              }
            ?>
              <div class="col-sm-6">
                <div class="feature-card h-100">
                  <?php if ($featureTitle !== ''): ?><h5 class="fw-semibold"><?=h($featureTitle)?></h5><?php endif; ?>
                  <?php if ($featureText !== ''): ?><p class="muted small mb-0"><?=nl2br(h($featureText))?></p><?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section id="ozellikler" class="mb-5">
    <div class="row g-4">
      <?php foreach ($featureBlocks as $block):
        $icon = trim((string)($block['icon'] ?? ''));
        $title = trim((string)($block['title'] ?? ''));
        $text = trim((string)($block['text'] ?? ''));
        if ($icon === '' && $title === '' && $text === '') {
          continue;
        }
      ?>
        <div class="col-md-4">
          <div class="feature-card h-100">
            <?php if ($icon !== ''): ?><div class="feature-icon mb-3"><?=h($icon)?></div><?php endif; ?>
            <?php if ($title !== ''): ?><h4 class="fw-semibold mb-2"><?=h($title)?></h4><?php endif; ?>
            <?php if ($text !== ''): ?><p class="muted mb-0"><?=nl2br(h($text))?></p><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section id="nasil" class="mb-5">
    <div class="row g-4 align-items-center">
      <div class="col-lg-6">
        <?php if ($timelineTitle !== ''): ?><h2 class="fw-bold mb-3"><?=h($timelineTitle)?></h2><?php endif; ?>
        <?php if ($timelineText !== ''): ?><p class="muted"><?=nl2br(h($timelineText))?></p><?php endif; ?>
      </div>
      <div class="col-lg-6 d-flex flex-column gap-3">
        <?php foreach ($timelineSteps as $idx => $step):
          $stepTitle = trim((string)($step['title'] ?? ''));
          $stepText = trim((string)($step['text'] ?? ''));
          if ($stepTitle === '' && $stepText === '') {
            continue;
          }
        ?>
          <div class="timeline-step"><span><?=h((string)($idx + 1))?></span><div><?php if ($stepTitle !== ''): ?><strong><?=h($stepTitle)?></strong><?php endif; ?><?php if ($stepText !== ''): ?><br><small class="text-muted"><?=nl2br(h($stepText))?></small><?php endif; ?></div></div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section id="paketler" class="mb-5">
    <div class="text-center mb-4">
      <?php if ($packagesTitle !== ''): ?><h2 class="fw-bold"><?=h($packagesTitle)?></h2><?php endif; ?>
      <?php if ($packagesText !== ''): ?><p class="muted"><?=nl2br(h($packagesText))?></p><?php endif; ?>
    </div>
    <div class="row g-4">
      <?php foreach ($packages as $pkg): ?>
        <div class="col-md-4">
          <div class="package-card">
            <div class="d-flex justify-content-between align-items-start mb-3">
              <h4 class="fw-semibold mb-0"><?=h($pkg['name'])?></h4>
              <?php if ($pkg['event_quota'] === null): ?>
                <span class="badge bg-info text-white">Sınırsız</span>
              <?php else: ?>
                <span class="badge bg-light text-dark"><?=$pkg['event_quota']?> etkinlik</span>
              <?php endif; ?>
            </div>
            <div class="package-price mb-3"><?=h(format_currency((int)$pkg['price_cents']))?></div>
            <?php if (!empty($pkg['description'])): ?>
              <p class="muted small mb-4"><?=nl2br(h($pkg['description']))?></p>
            <?php endif; ?>
            <?php
              $packageHighlights = [];
              if ($packagesHighlights) {
                foreach ($packagesHighlights as $highlight) {
                  $highlight = trim((string)$highlight);
                  if ($highlight === '') {
                    continue;
                  }
                  $packageHighlights[] = $highlight;
                }
              }
            ?>
            <?php if ($packageHighlights): ?>
              <ul class="small text-muted mb-0">
                <?php foreach ($packageHighlights as $highlight): ?>
                  <li><?=h($highlight)?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$packages): ?>
        <div class="col-12">
          <div class="alert alert-warning">Aktif müşteri paketleri henüz tanımlanmadı. Lütfen yönetim panelinden paket ekleyin.</div>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section id="bayi-avantaj" class="mb-5">
    <div class="row g-4 align-items-center">
      <div class="col-lg-6">
        <?php if ($dealerBadge !== ''): ?><span class="badge bg-info-subtle text-info-emphasis rounded-pill px-3 py-2 fw-semibold"><?=h($dealerBadge)?></span><?php endif; ?>
        <?php if ($dealerTitle !== ''): ?><h2 class="fw-bold mt-3"><?=h($dealerTitle)?></h2><?php endif; ?>
        <?php if ($dealerText !== ''): ?><p class="muted"><?=nl2br(h($dealerText))?></p><?php endif; ?>
        <?php if ($dealerHighlights): ?>
          <ul class="muted">
            <?php foreach ($dealerHighlights as $highlight):
              $highlight = trim((string)$highlight);
              if ($highlight === '') {
                continue;
              }
            ?>
              <li><?=h($highlight)?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if ($dealerButtonUrl && $dealerButtonLabel !== ''): ?>
          <a class="btn btn-brand" href="<?=h($dealerButtonUrl)?>"><?=h($dealerButtonLabel)?></a>
        <?php endif; ?>
      </div>
      <div class="col-lg-6 text-center">
        <img class="img-fluid rounded-4 shadow-lg" src="<?=h($dealerShowcaseImage)?>" alt="Bayi paneli">
      </div>
    </div>
  </section>

  <section class="mb-5">
    <div class="row g-4 align-items-center">
      <div class="col-lg-5">
        <?php if ($galleryTitle !== ''): ?><h2 class="fw-bold mb-3"><?=h($galleryTitle)?></h2><?php endif; ?>
        <?php if ($galleryText !== ''): ?><p class="muted"><?=nl2br(h($galleryText))?></p><?php endif; ?>
      </div>
      <div class="col-lg-7 gallery-grid">
        <?php foreach ($galleryImages as $galleryImage): ?>
          <?php $galleryImage = trim((string)$galleryImage); if ($galleryImage === '') { continue; } ?>
          <img src="<?=h($galleryImage)?>" alt="BİKARE galeri görseli">
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="mb-5">
    <div class="row g-4">
      <?php foreach ($testimonials as $testimonial):
        $quote = trim((string)($testimonial['quote'] ?? ''));
        $author = trim((string)($testimonial['author'] ?? ''));
        $role = trim((string)($testimonial['role'] ?? ''));
        if ($quote === '' && $author === '' && $role === '') {
          continue;
        }
      ?>
        <div class="col-md-6">
          <div class="testimonial h-100">
            <?php if ($quote !== ''): ?><p class="mb-3"><?=nl2br(h($quote))?></p><?php endif; ?>
            <?php if ($author !== ''): ?><div class="fw-semibold"><?=h($author)?></div><?php endif; ?>
            <?php if ($role !== ''): ?><div class="small text-muted"><?=h($role)?></div><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section id="sss" class="mb-5">
    <div class="faq-surface">
      <div class="row g-4 align-items-start">
        <div class="col-lg-5 position-relative" style="z-index:1;">
          <span class="badge bg-light text-dark rounded-pill px-3 py-2 fw-semibold">Yanınızdayız</span>
          <h2 class="fw-bold mt-3">Sıkça Sorulan Sorular</h2>
          <p class="muted mb-0">BİKARE ile ilgili merak ettiğiniz konuları sizin için derledik. Yanıt bulamadığınızda ekibimiz sadece bir mesaj uzağınızda.</p>
        </div>
        <div class="col-lg-7">
          <?php if ($faqItems): ?>
            <div class="accordion" id="faqAccordion">
            <?php foreach ($faqItems as $i => $faq):
              $headingId = 'faqHeading'.$i;
              $collapseId = 'faqCollapse'.$i;
              $isFirst = ($i === 0);
            ?>
              <div class="accordion-item">
                <h2 class="accordion-header" id="<?=h($headingId)?>">
                  <button class="accordion-button<?=$isFirst ? '' : ' collapsed'?>" type="button" data-bs-toggle="collapse" data-bs-target="#<?=h($collapseId)?>" aria-expanded="<?=$isFirst ? 'true' : 'false'?>" aria-controls="<?=h($collapseId)?>"><?=h($faq['question'])?></button>
                </h2>
                <div id="<?=h($collapseId)?>" class="accordion-collapse collapse<?=$isFirst ? ' show' : ''?>" aria-labelledby="<?=h($headingId)?>" data-bs-parent="#faqAccordion">
                  <div class="accordion-body"><?=nl2br(h($faq['answer']))?></div>
                </div>
              </div>
            <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="alert alert-info">Henüz sıkça sorulan soru eklenmedi.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <section id="iletisim" class="mb-5">
    <div class="row g-4 align-items-stretch">
      <div class="col-lg-6">
        <div class="contact-card h-100">
          <h3 class="fw-bold mb-3"><?=h($content['contact_title'])?></h3>
          <?php if (!empty($content['contact_text'])): ?>
            <p class="muted mb-4"><?=h($content['contact_text'])?></p>
          <?php endif; ?>
          <ul>
            <?php if (!empty($content['contact_phone'])): ?>
              <li><strong>Telefon:</strong> <?php if ($contactPhoneHref): ?><a href="<?=h($contactPhoneHref)?>"><?=h($content['contact_phone'])?></a><?php else: ?><?=h($content['contact_phone'])?><?php endif; ?></li>
            <?php endif; ?>
            <?php if (!empty($content['contact_email'])): ?>
              <li><strong>E-posta:</strong> <a href="mailto:<?=h($content['contact_email'])?>"><?=h($content['contact_email'])?></a></li>
            <?php endif; ?>
            <?php if (!empty($content['contact_address'])): ?>
              <li><strong>Adres:</strong> <?=h($content['contact_address'])?></li>
            <?php endif; ?>
            <?php if ($contactWebsiteUrl): ?>
              <li><strong>Web:</strong> <a href="<?=h($contactWebsiteUrl)?>" target="_blank" rel="noopener"><?=h($contactWebsiteLabel ?: $contactWebsiteUrl)?></a></li>
            <?php endif; ?>
          </ul>
          <div class="d-flex flex-wrap gap-3 pt-3">
            <?php if ($contactPrimaryUrl && !empty($content['contact_primary_label'])): ?>
              <a class="btn btn-outline-secondary rounded-pill" href="<?=h($contactPrimaryUrl)?>"><?=h($content['contact_primary_label'])?></a>
            <?php endif; ?>
            <?php if ($contactSecondaryUrl && !empty($content['contact_secondary_label'])): ?>
              <a class="btn btn-brand" href="<?=h($contactSecondaryUrl)?>"><?=h($content['contact_secondary_label'])?></a>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="hero position-relative text-white h-100" style="min-height:320px;">
          <div class="position-relative" style="z-index:2;">
            <?php if (!empty($content['contact_cta_badge'])): ?>
              <span class="badge bg-light text-dark rounded-pill px-3 py-2 fw-semibold"><?=h($content['contact_cta_badge'])?></span>
            <?php endif; ?>
            <?php if (!empty($content['contact_cta_title'])): ?>
              <h3 class="fw-bold mt-3"><?=h($content['contact_cta_title'])?></h3>
            <?php endif; ?>
            <?php if (!empty($content['contact_cta_text'])): ?>
              <p><?=nl2br(h($content['contact_cta_text']))?></p>
            <?php endif; ?>
            <?php if ($contactCtaButtonUrl && !empty($content['contact_cta_button_label'])): ?>
              <a class="btn btn-cta" href="<?=h($contactCtaButtonUrl)?>"><?=h($content['contact_cta_button_label'])?></a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="cta-section mb-5 text-center text-lg-start">
    <div class="row g-4 align-items-center">
      <div class="col-lg-8">
        <?php if ($ctaBannerTitle !== ''): ?><h2 class="fw-bold mb-2"><?=h($ctaBannerTitle)?></h2><?php endif; ?>
        <?php if ($ctaBannerText !== ''): ?><p class="mb-0"><?=nl2br(h($ctaBannerText))?></p><?php endif; ?>
      </div>
      <div class="col-lg-4 text-lg-end">
        <?php if ($ctaBannerButtonUrl && $ctaBannerButtonLabel !== ''): ?>
          <a class="btn btn-light text-dark fw-semibold px-4 py-3" href="<?=h($ctaBannerButtonUrl)?>"><?=h($ctaBannerButtonLabel)?></a>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section id="lead-form" class="form-section">
    <div class="row g-4 align-items-start">
      <div class="col-lg-5">
        <?php if ($leadFormTitle !== ''): ?><h3 class="fw-bold mb-3"><?=h($leadFormTitle)?></h3><?php endif; ?>
        <?php if ($leadFormText !== ''): ?><p class="muted"><?=nl2br(h($leadFormText))?></p><?php endif; ?>
        <?php if ($leadFormBullets): ?>
          <ul class="small text-muted ps-3">
            <?php foreach ($leadFormBullets as $bullet):
              $bullet = trim((string)$bullet);
              if ($bullet === '') {
                continue;
              }
            ?>
              <li><?=h($bullet)?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
      <div class="col-lg-7">
        <form method="post" action="order.php" class="row g-3">
          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
          <div class="col-12">
            <label class="form-label fw-semibold">Paket Seçimi</label>
            <div class="row g-3">
              <?php foreach ($packages as $pkg): ?>
                <div class="col-md-6">
                  <label class="border rounded-4 p-3 w-100 <?=(int)$formData['package_id'] === (int)$pkg['id'] ? 'border-2 border-info' : 'border-light'?>">
                    <input class="form-check-input me-2" type="radio" name="package_id" value="<?= (int)$pkg['id']?>" <?=(int)$formData['package_id'] === (int)$pkg['id'] ? 'checked' : ''?> required>
                    <span class="fw-semibold d-block"><?=h($pkg['name'])?></span>
                    <span class="small text-muted d-block"><?=h(format_currency((int)$pkg['price_cents']))?></span>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="col-md-6">
            <label class="form-label fw-semibold">Ad Soyad</label>
            <input type="text" name="customer_name" class="form-control input-rounded" value="<?=h($formData['customer_name'])?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">E-posta</label>
            <input type="email" name="customer_email" class="form-control input-rounded" value="<?=h($formData['customer_email'])?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Telefon</label>
            <input type="text" name="customer_phone" class="form-control input-rounded" value="<?=h($formData['customer_phone'])?>" placeholder="0 (5xx) xxx xx xx">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Etkinlik Başlığı</label>
            <input type="text" name="event_title" class="form-control input-rounded" value="<?=h($formData['event_title'])?>" placeholder="Örn. Deniz &amp; Efe Düğünü">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Etkinlik Tarihi</label>
            <input type="date" name="event_date" class="form-control input-rounded" value="<?=h($formData['event_date'])?>">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Referans Kodu (opsiyonel)</label>
            <input type="text" name="referral_code" class="form-control input-rounded" value="<?=h($formData['referral_code'])?>" placeholder="Bayi kodu">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Notunuz</label>
            <textarea name="notes" class="form-control input-rounded" rows="3" placeholder="Etkinlikle ilgili paylaşmak istediğiniz ek bilgiler"><?=h($formData['notes'])?></textarea>
          </div>
          <div class="col-12 d-flex flex-column flex-md-row gap-3 justify-content-between align-items-md-center pt-3">
            <?php if ($leadFormNotice !== ''): ?><span class="muted small"><?=h($leadFormNotice)?></span><?php endif; ?>
            <button class="btn btn-brand" type="submit"><?=h($leadFormSubmitLabel !== '' ? $leadFormSubmitLabel : 'Gönder')?></button>
          </div>
        </form>
      </div>
    </div>
  </section>
</main>

<footer>
  <div class="container">
    <div class="row gy-4 align-items-start">
      <div class="col-lg-4">
        <h5 class="fw-bold">BİKARE</h5>
        <p class="small mb-3"><?=nl2br(h($content['footer_about']))?></p>
        <div class="d-flex align-items-center gap-3 flex-wrap">
          <img src="https://upload.wikimedia.org/wikipedia/commons/2/2a/Mastercard-logo.svg" alt="Mastercard" class="footer-payment-logo" loading="lazy">
          <img src="https://upload.wikimedia.org/wikipedia/commons/5/5e/Visa_Inc._logo.svg" alt="Visa" class="footer-payment-logo" loading="lazy">
          <img src="https://www.paytr.com/img/paytr-logo.svg" alt="PayTR" class="footer-payment-logo" loading="lazy">
        </div>
      </div>
      <div class="col-lg-4">
        <h6 class="fw-semibold mb-2"><?=h($content['footer_company'])?></h6>
        <ul class="list-unstyled small mb-0">
          <?php if (!empty($content['contact_phone'])): ?>
            <li>Telefon: <?php if ($contactPhoneHref): ?><a href="<?=h($contactPhoneHref)?>"><?=h($content['contact_phone'])?></a><?php else: ?><?=h($content['contact_phone'])?><?php endif; ?></li>
          <?php endif; ?>
          <?php if (!empty($content['contact_email'])): ?>
            <li>E-posta: <a href="mailto:<?=h($content['contact_email'])?>"><?=h($content['contact_email'])?></a></li>
          <?php endif; ?>
          <?php if ($contactWebsiteUrl): ?>
            <li>Web: <a href="<?=h($contactWebsiteUrl)?>" target="_blank" rel="noopener"><?=h($contactWebsiteLabel ?: $contactWebsiteUrl)?></a></li>
          <?php endif; ?>
        </ul>
      </div>
      <div class="col-lg-4">
        <h6 class="fw-semibold mb-2">Navigasyon</h6>
        <div class="footer-nav">
          <?php foreach ($footerNav as $nav): ?>
            <a href="<?=h($nav['url'])?>"><?=h($nav['label'])?></a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="border-top border-light mt-4 pt-3 d-flex flex-column flex-md-row justify-content-between gap-2 small">
      <span><?=h($content['footer_disclaimer_left'])?></span>
      <span><?=h($content['footer_disclaimer_right'])?></span>
    </div>
  </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
