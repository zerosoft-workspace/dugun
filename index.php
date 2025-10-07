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
$faqItems = $content['faq_items'];
$footerNav = $content['footer_nav_links'];
$contactWebsiteUrl = site_normalize_url($content['contact_website'] ?? '') ?? '';
$contactWebsiteLabel = $content['contact_website_label'] ?? '';
$contactPhoneHref = site_phone_href($content['contact_phone'] ?? '') ?? '';
$contactPrimaryUrl = site_resolve_button_url($content['contact_primary_url'] ?? '') ?? '';
$contactSecondaryUrl = site_resolve_button_url($content['contact_secondary_url'] ?? '') ?? '';
$contactCtaButtonUrl = site_resolve_button_url($content['contact_cta_button_url'] ?? '') ?? '';
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
  }
  body{background:linear-gradient(180deg,var(--bg),#fff);font-family:'Inter',sans-serif;color:var(--ink);}
  .hero{position:relative;overflow:hidden;border-radius:36px;padding:96px 48px;background:linear-gradient(140deg,rgba(14,165,181,0.92),rgba(59,130,246,0.88));color:#fff;}
  .hero::after{content:"";position:absolute;inset:-120px -60px auto 40%;width:420px;height:420px;background:rgba(255,255,255,0.12);filter:blur(0);border-radius:50%;}
  .hero-visual{position:relative;z-index:1;}
  .hero-visual img{border-radius:24px;box-shadow:0 30px 90px rgba(15,118,110,0.35);}
  .hero-visual img:nth-child(2){position:absolute;top:40%;left:50%;width:220px;border:6px solid rgba(255,255,255,0.8);transform:translate(-30%, -10%);}
  .metrics-card{border-radius:24px;background:rgba(255,255,255,0.16);padding:28px;backdrop-filter:blur(8px);}
  .feature-card{border-radius:24px;background:#fff;box-shadow:0 24px 60px rgba(148,163,184,0.18);padding:32px;transition:transform .25s ease,box-shadow .25s ease;}
  .feature-card:hover{transform:translateY(-6px);box-shadow:0 36px 80px rgba(148,163,184,0.25);}
  .feature-icon{width:56px;height:56px;border-radius:18px;background:rgba(14,165,181,0.12);display:flex;align-items:center;justify-content:center;font-size:1.6rem;color:var(--brand);}
  .timeline-step{display:flex;gap:16px;padding:16px;border-radius:18px;background:#fff;box-shadow:0 16px 40px rgba(15,118,110,0.12);}
  .timeline-step span{width:44px;height:44px;border-radius:14px;background:rgba(14,165,181,0.12);color:var(--brand);display:flex;align-items:center;justify-content:center;font-weight:700;}
  .package-card{border-radius:24px;border:1px solid rgba(14,165,181,0.12);background:#fff;height:100%;padding:32px;transition:transform .2s ease,box-shadow .2s ease;}
  .package-card:hover{transform:translateY(-6px);box-shadow:0 28px 70px rgba(15,118,110,0.18);}
  .package-price{font-size:1.9rem;font-weight:800;color:var(--brand);}
  .gallery-grid{display:grid;gap:18px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));}
  .gallery-grid img{width:100%;height:220px;object-fit:cover;border-radius:24px;box-shadow:0 18px 50px rgba(15,118,110,0.18);}
  .testimonial{border-radius:24px;background:#fff;padding:32px;box-shadow:0 22px 60px rgba(15,118,110,0.16);position:relative;}
  .testimonial::before{content:'“';position:absolute;top:-16px;left:24px;font-size:5rem;color:rgba(14,165,181,0.2);}
  .cta-section{border-radius:32px;background:linear-gradient(135deg,#0ea5b5,#6366f1);color:#fff;padding:48px;}
  .form-section{border-radius:28px;background:#fff;box-shadow:0 32px 90px rgba(15,118,110,0.2);padding:48px;}
  .input-rounded{border-radius:16px;border:1px solid #d7e4eb;padding:12px 16px;}
  .btn-brand{background:var(--brand);color:#fff;border:none;border-radius:18px;padding:14px 32px;font-weight:700;}
  .btn-brand:hover{background:var(--brand-dark);color:#fff;}
  .btn-guest{border-radius:999px;border:1px solid rgba(14,165,181,0.3);color:var(--brand);font-weight:600;padding:10px 22px;background:rgba(14,165,181,0.08);}
  .btn-guest:hover{color:#fff;background:var(--brand);border-color:var(--brand);}
  .muted{color:var(--muted);}
  .nav-link{font-weight:600;color:var(--muted)!important;}
  .nav-link:hover,.nav-link:focus,.nav-link.active{color:var(--brand)!important;}
  .site-navbar{backdrop-filter:blur(10px);}
  .contact-card{border-radius:24px;background:#fff;box-shadow:0 24px 60px rgba(15,118,110,0.18);padding:32px;}
  .contact-card ul{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:12px;}
  .contact-card ul li strong{color:var(--ink);min-width:84px;display:inline-block;}
  .contact-card ul li a{color:var(--brand);font-weight:600;text-decoration:none;}
  .contact-card ul li a:hover{color:var(--brand-dark);text-decoration:underline;}
  .contact-card .btn-outline-secondary{border-color:rgba(14,165,181,0.35);color:var(--brand);background:rgba(14,165,181,0.08);}
  .contact-card .btn-outline-secondary:hover{background:var(--brand);color:#fff;border-color:var(--brand);}
  .cta-bar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;}
  .navbar-toggler{border:none;box-shadow:none;}
  footer{background:var(--brand);color:#f0fdfa;padding:48px 0 40px;margin-top:48px;}
  footer h5, footer h6{color:#fff;}
  footer a{color:rgba(255,255,255,0.9);font-weight:600;text-decoration:none;}
  footer a:hover{color:#0f172a;text-decoration:underline;}
  .footer-payment-logo{height:28px;filter:brightness(0) invert(1);opacity:0.85;transition:opacity .2s ease;}
  .footer-payment-logo:hover{opacity:1;}
  .footer-nav a{color:#fdfdfd;display:inline-block;margin-bottom:8px;}
  .footer-nav a:hover{color:#0f172a;}
  @media(max-width:992px){.hero{padding:72px 28px;}.hero-visual img:nth-child(2){display:none;}}
  @media(max-width:768px){.form-section{padding:32px;}}
</style>
</head><body>
<?php site_public_header('home'); ?>

<main class="container py-5">
  <section class="hero mb-5">
    <div class="row align-items-center g-5 position-relative" style="z-index:2;">
      <div class="col-lg-6">
        <span class="badge bg-light text-dark rounded-pill px-3 py-2 fw-semibold">Yeni nesil misafir paylaşımı</span>
        <h1 class="fw-bold display-5 mt-4 mb-3">Tek QR kodla tüm fotoğraf ve videoları toplayın</h1>
        <p class="lead mb-4">BİKARE, davetlilerinizin çektikleri anıları saniyeler içinde toplayarak çift panelinizi, misafir galerilerini ve paylaşılabilir QR kodlarını otomatik olarak hazırlar.</p>
        <div class="d-flex flex-wrap gap-3">
          <a class="btn btn-light text-dark fw-semibold" href="#paketler">Paketleri İncele</a>
          <a class="btn btn-outline-light fw-semibold" href="#lead-form">Hemen Başlayın</a>
        </div>
      </div>
      <div class="col-lg-6 hero-visual">
        <img src="https://images.unsplash.com/photo-1520854221050-0f4caff449fb?auto=compress&cs=tinysrgb&fit=crop&w=820&q=80" alt="Düğün kutlaması" class="img-fluid">
        <img src="https://images.unsplash.com/photo-1524504388940-b1c1722653e1?auto=compress&cs=tinysrgb&fit=crop&w=520&q=80" alt="Etkinlikten kare" class="img-fluid">
      </div>
    </div>
    <div class="row mt-5 g-4 position-relative" style="z-index:2;">
      <div class="col-md-4">
        <div class="metrics-card h-100">
          <div class="h2 fw-bold mb-1">12.500+</div>
          <div class="small">Toplanan fotoğraf ve videolar</div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="metrics-card h-100">
          <div class="h2 fw-bold mb-1">%98</div>
          <div class="small">Misafir memnuniyeti</div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="metrics-card h-100">
          <div class="h2 fw-bold mb-1">5 dk</div>
          <div class="small">Ödeme sonrası panel hazır olma süresi</div>
        </div>
      </div>
    </div>
  </section>

  <?php if ($success): ?>
    <div class="alert alert-success shadow-sm"><?=h($success)?></div>
  <?php endif; ?>
  <?php flash_box(); ?>

  <section id="hakkimizda" class="mb-5">
    <div class="row align-items-center g-5">
      <div class="col-lg-6">
        <img class="img-fluid rounded-4 shadow-lg" src="https://images.unsplash.com/photo-1511288590-34b0471af9b4?auto=compress&cs=tinysrgb&fit=crop&w=900&q=80" alt="Mutlu çift">
      </div>
      <div class="col-lg-6">
        <span class="badge bg-light text-dark rounded-pill px-3 py-2 fw-semibold">BİKARE Hakkında</span>
        <h2 class="fw-bold mt-3">Her anınızı dijital sahneye taşıyan çözüm ortağınız</h2>
        <p class="muted">Zerosoft olarak düğün, nişan, kurumsal davet ve tüm özel etkinliklerinizde misafirlerinizle aynı anda nefes alan bir platform geliştirdik. BİKARE; yüksek yükleme kapasitesi, güçlü misafir etkileşim araçları ve otomatik QR kod altyapısıyla sizi teknik detaylardan kurtarır.</p>
        <div class="row g-3">
          <div class="col-sm-6">
            <div class="feature-card h-100">
              <h5 class="fw-semibold">Profesyonel destek</h5>
              <p class="muted small mb-0">Kurulumdan canlı yayına kadar deneyimli ekibimizle yanınızdayız.</p>
            </div>
          </div>
          <div class="col-sm-6">
            <div class="feature-card h-100">
              <h5 class="fw-semibold">Tamamen yerli altyapı</h5>
              <p class="muted small mb-0">Verileriniz Türkiye lokasyonlu sunucularda güvenle saklanır.</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section id="ozellikler" class="mb-5">
    <div class="row g-4">
      <div class="col-md-4">
        <div class="feature-card h-100">
          <div class="feature-icon mb-3">📸</div>
          <h4 class="fw-semibold mb-2">Anında QR Toplama</h4>
          <p class="muted mb-0">Misafirleriniz QR kodu okutup doğrudan galerinize fotoğraf ve videoları yükler. Her yükleme çift panelinizde otomatik görünür.</p>
        </div>
      </div>
      <div class="col-md-4">
        <div class="feature-card h-100">
          <div class="feature-icon mb-3">✨</div>
          <h4 class="fw-semibold mb-2">Sosyal Galeri Deneyimi</h4>
          <p class="muted mb-0">Beğeniler, yıldızlar ve yorumlarla misafir galerisi sosyal medya tadında. Albümünüzü dilediğiniz gibi düzenleyin.</p>
        </div>
      </div>
      <div class="col-md-4">
        <div class="feature-card h-100">
          <div class="feature-icon mb-3">🔒</div>
          <h4 class="fw-semibold mb-2">Güvenli Online Ödeme</h4>
          <p class="muted mb-0">PayTR altyapısıyla kart bilgileriniz güvende. Ödeme tamamlandığında paneliniz ve QR kodlarınız otomatik hazırlanır.</p>
        </div>
      </div>
    </div>
  </section>

  <section id="nasil" class="mb-5">
    <div class="row g-4 align-items-center">
      <div class="col-lg-6">
        <h2 class="fw-bold mb-3">BİKARE nasıl çalışır?</h2>
        <p class="muted">Basit 3 adımda etkinliğinizi dijitalleştiriyoruz. Kurulum ve teknik detaylarla vakit kaybetmenize gerek yok.</p>
      </div>
      <div class="col-lg-6 d-flex flex-column gap-3">
        <div class="timeline-step"><span>1</span><div><strong>Paketi seçin & ödeme yapın</strong><br><small class="text-muted">Formu doldurup güvenli ödeme adımında işlemi tamamlayın.</small></div></div>
        <div class="timeline-step"><span>2</span><div><strong>Panel otomatik kurulsun</strong><br><small class="text-muted">Çift paneliniz, QR kodlarınız ve misafir galeriniz dakikalar içinde hazırlanır.</small></div></div>
        <div class="timeline-step"><span>3</span><div><strong>Misafirlerinizi davet edin</strong><br><small class="text-muted">QR kodu paylaşın, fotoğraflar ve videolar gerçek zamanlı olarak panelinize düşsün.</small></div></div>
      </div>
    </div>
  </section>

  <section id="paketler" class="mb-5">
    <div class="text-center mb-4">
      <h2 class="fw-bold">İhtiyacınıza uygun paketleri seçin</h2>
      <p class="muted">Her paket güvenli online ödeme, otomatik panel kurulumu ve sınırsız misafir yüklemesi içerir.</p>
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
            <ul class="small text-muted mb-0">
              <li>Kalıcı ve etkinliğe özel QR kodlar</li>
              <li>Çift paneli otomatik kurulum ve e-posta bildirimi</li>
              <li>Sosyal medya tarzı misafir galerisi</li>
              <li>HD fotoğraf & video yükleme desteği</li>
              <?php if ($pkg['cashback_rate'] > 0): ?>
                <li>Referans koduyla %<?=number_format($pkg['cashback_rate'] * 100, 0)?> cashback</li>
              <?php endif; ?>
            </ul>
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
        <span class="badge bg-info-subtle text-info-emphasis rounded-pill px-3 py-2 fw-semibold">Bayi Ağı</span>
        <h2 class="fw-bold mt-3">Etkinlik sektöründeki iş ortaklarımız için kazandıran sistem</h2>
        <p class="muted">Bayi panelinizden bakiye yönetebilir, PayTR entegrasyonlu paketler satın alabilir, etkinliklerinizi tek ekrandan yönetebilirsiniz. Referans kodu ile gerçekleştirdiğiniz satışlardan onay sonrası cashback kazanırsınız.</p>
        <ul class="muted">
          <li>Salon bazlı etkinlik yönetimi ve QR kod üretimi</li>
          <li>Detaylı raporlama, bakiye ve cashback geçmişi</li>
          <li>PayTR ile güvenli tahsilat ve hızlı aktivasyon</li>
        </ul>
        <a class="btn btn-brand" href="<?=BASE_URL?>/dealer/apply.php">Bayi Ağına Katıl</a>
      </div>
      <div class="col-lg-6 text-center">
        <img class="img-fluid rounded-4 shadow-lg" src="https://images.unsplash.com/photo-1499951360447-b19be8fe80f5?auto=compress&cs=tinysrgb&fit=crop&w=900&q=80" alt="Bayi paneli">
      </div>
    </div>
  </section>

  <section class="mb-5">
    <div class="row g-4 align-items-center">
      <div class="col-lg-5">
        <h2 class="fw-bold mb-3">Gerçek hikayelerden ilham alın</h2>
        <p class="muted">Misafirleriniz sadece düğünlerde değil; nişan, kına, doğum günü ve kurumsal etkinliklerde de QR kodunuzla içerik paylaşabilir.</p>
      </div>
      <div class="col-lg-7 gallery-grid">
        <img src="https://images.unsplash.com/photo-1603015444030-0e4d0a568d2e?auto=compress&cs=tinysrgb&fit=crop&w=600&q=80" alt="Düğün davetlileri">
        <img src="https://images.unsplash.com/photo-1521737604893-d14cc237f11d?auto=compress&cs=tinysrgb&fit=crop&w=600&q=80" alt="Kurumsal etkinlik">
        <img src="https://images.unsplash.com/photo-1525253013412-55c1a69a5738?auto=compress&cs=tinysrgb&fit=crop&w=600&q=80" alt="Yemek organizasyonu">
      </div>
    </div>
  </section>

  <section class="mb-5">
    <div class="row g-4">
      <div class="col-md-6">
        <div class="testimonial h-100">
          <p class="mb-3">"Misafirlerimiz tüm fotoğrafları bir araya getirirken inanılmaz eğlendi. Panellerin otomatik kurulması bizim için büyük kolaylık sağladı."</p>
          <div class="fw-semibold">İpek &amp; Cem</div>
          <div class="small text-muted">İstanbul Boğazı Düğünü</div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="testimonial h-100">
          <p class="mb-3">"Kurumsal lansmanımızda katılımcıların videolarını toplamak bu kadar kolay olmamıştı. BİKARE ekibi her detayla ilgilendi."</p>
          <div class="fw-semibold">Berna U.</div>
          <div class="small text-muted">Etkinlik Ajansı Sahibi</div>
        </div>
      </div>
    </div>
  </section>

  <section id="sss" class="mb-5">
    <div class="row g-4">
      <div class="col-lg-5">
        <h2 class="fw-bold">Sıkça sorulan sorular</h2>
        <p class="muted">BİKARE ile ilgili merak ettiğiniz konuları sizin için derledik. Daha fazlası için bizimle iletişime geçebilirsiniz.</p>
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
              <a class="btn btn-light text-dark fw-semibold" href="<?=h($contactCtaButtonUrl)?>"><?=h($content['contact_cta_button_label'])?></a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="cta-section mb-5 text-center text-lg-start">
    <div class="row g-4 align-items-center">
      <div class="col-lg-8">
        <h2 class="fw-bold mb-2">Etkinliğiniz için hazırız</h2>
        <p class="mb-0">Formu doldurup güvenli ödeme adımını tamamlayın, paneliniz birkaç dakika içinde aktif olsun. Anılarınızı kaybetmeyin, değerini artırın.</p>
      </div>
      <div class="col-lg-4 text-lg-end">
        <a class="btn btn-light text-dark fw-semibold px-4 py-3" href="#lead-form">Paket Seç &amp; Ödeme Yap</a>
      </div>
    </div>
  </section>

  <section id="lead-form" class="form-section">
    <div class="row g-4 align-items-start">
      <div class="col-lg-5">
        <h3 class="fw-bold mb-3">Sipariş Formu</h3>
        <p class="muted">Paketinizi seçin, bilgilerinizi girin ve PayTR ile güvenli ödeme adımına yönlendirilin. Ödeme onaylandığında giriş bilgilerinizi otomatik olarak e-posta ile alacaksınız.</p>
        <ul class="small text-muted ps-3">
          <li>Misafir galerisi, QR kodlar ve çift paneli otomatik hazırlanır.</li>
          <li>Referans kodu alanı isteğe bağlıdır. Kod kullanırsanız ilgili bayi cashback kazanır.</li>
          <li>Dilediğiniz zaman destek ekibimizle iletişime geçebilirsiniz.</li>
        </ul>
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
            <span class="muted small">Formu gönderdiğinizde PayTR güvenli ödeme sayfasına yönlendirileceksiniz.</span>
            <button class="btn btn-brand" type="submit">Ödeme Adımına Geç</button>
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
