<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/site.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/partials/ui.php';

require_admin();
require_superadmin();
install_schema();

$defaults = site_content_defaults();
$content = site_settings_all();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_or_die();

  $payload = [
    'contact_title' => trim($_POST['contact_title'] ?? ''),
    'contact_text' => trim($_POST['contact_text'] ?? ''),
    'contact_phone' => trim($_POST['contact_phone'] ?? ''),
    'contact_email' => trim($_POST['contact_email'] ?? ''),
    'contact_address' => trim($_POST['contact_address'] ?? ''),
    'contact_website' => trim($_POST['contact_website'] ?? ''),
    'contact_website_label' => trim($_POST['contact_website_label'] ?? ''),
    'contact_primary_label' => trim($_POST['contact_primary_label'] ?? ''),
    'contact_primary_url' => trim($_POST['contact_primary_url'] ?? ''),
    'contact_secondary_label' => trim($_POST['contact_secondary_label'] ?? ''),
    'contact_secondary_url' => trim($_POST['contact_secondary_url'] ?? ''),
    'contact_cta_badge' => trim($_POST['contact_cta_badge'] ?? ''),
    'contact_cta_title' => trim($_POST['contact_cta_title'] ?? ''),
    'contact_cta_text' => trim($_POST['contact_cta_text'] ?? ''),
    'contact_cta_button_label' => trim($_POST['contact_cta_button_label'] ?? ''),
    'contact_cta_button_url' => trim($_POST['contact_cta_button_url'] ?? ''),
    'footer_about' => trim($_POST['footer_about'] ?? ''),
    'footer_company' => trim($_POST['footer_company'] ?? ''),
    'footer_disclaimer_left' => trim($_POST['footer_disclaimer_left'] ?? ''),
    'footer_disclaimer_right' => trim($_POST['footer_disclaimer_right'] ?? ''),
  ];

  $smtpPort = trim($_POST['smtp_port'] ?? '');
  if ($smtpPort !== '' && !ctype_digit($smtpPort)) {
    $smtpPort = '';
  }
  $smtpSecure = strtolower(trim($_POST['smtp_secure'] ?? ''));
  if (!in_array($smtpSecure, ['tls', 'ssl', ''], true)) {
    $smtpSecure = 'tls';
  }

  $payload['smtp_host'] = trim($_POST['smtp_host'] ?? '');
  $payload['smtp_port'] = $smtpPort;
  $payload['smtp_user'] = trim($_POST['smtp_user'] ?? '');
  $payload['smtp_pass'] = trim($_POST['smtp_pass'] ?? '');
  $payload['smtp_secure'] = $smtpSecure;
  $payload['smtp_from_email'] = trim($_POST['smtp_from_email'] ?? '');
  $payload['smtp_from_name'] = trim($_POST['smtp_from_name'] ?? '');

  $faqItems = [];
  $faqQuestions = $_POST['faq_question'] ?? [];
  $faqAnswers = $_POST['faq_answer'] ?? [];
  foreach ($faqQuestions as $idx => $question) {
    $question = trim($question);
    $answer = trim($faqAnswers[$idx] ?? '');
    if ($question === '' && $answer === '') {
      continue;
    }
    if ($question === '' || $answer === '') {
      continue;
    }
    $faqItems[] = ['question' => $question, 'answer' => $answer];
  }
  if (!$faqItems) {
    $faqItems = $defaults['faq_items'];
  }
  $payload['faq_items'] = $faqItems;

  $navItems = [];
  $navLabels = $_POST['nav_label'] ?? [];
  $navUrls = $_POST['nav_url'] ?? [];
  foreach ($navLabels as $idx => $label) {
    $label = trim($label);
    $url = trim($navUrls[$idx] ?? '');
    if ($label === '' && $url === '') {
      continue;
    }
    if ($label === '' || $url === '') {
      continue;
    }
    $navItems[] = ['label' => $label, 'url' => $url];
  }
  if (!$navItems) {
    $navItems = $defaults['footer_nav_links'];
  }
  $payload['footer_nav_links'] = $navItems;

  $siteLogo = $content['site_logo'] ?? $defaults['site_logo'];
  if (!empty($_POST['site_logo_remove'])) {
    site_content_delete_asset($siteLogo);
    $siteLogo = '';
  }
  if (!empty($_FILES['site_logo'])) {
    $uploaded = site_content_store_upload($_FILES['site_logo'], $siteLogo ?: null);
    if ($uploaded) {
      $siteLogo = $uploaded;
    }
  }
  $payload['site_logo'] = $siteLogo;

  $heroMain = $content['hero_image_main'] ?? $defaults['hero_image_main'];
  if (!empty($_POST['hero_image_main_remove'])) {
    site_content_delete_asset($heroMain);
    $heroMain = '';
  }
  if (!empty($_FILES['hero_image_main'])) {
    $uploaded = site_content_store_upload($_FILES['hero_image_main'], $heroMain ?: null);
    if ($uploaded) {
      $heroMain = $uploaded;
    }
  }
  $payload['hero_image_main'] = $heroMain;

  $heroSecondary = $content['hero_image_secondary'] ?? $defaults['hero_image_secondary'];
  if (!empty($_POST['hero_image_secondary_remove'])) {
    site_content_delete_asset($heroSecondary);
    $heroSecondary = '';
  }
  if (!empty($_FILES['hero_image_secondary'])) {
    $uploaded = site_content_store_upload($_FILES['hero_image_secondary'], $heroSecondary ?: null);
    if ($uploaded) {
      $heroSecondary = $uploaded;
    }
  }
  $payload['hero_image_secondary'] = $heroSecondary;

  $aboutImage = $content['about_image'] ?? $defaults['about_image'];
  if (!empty($_POST['about_image_remove'])) {
    site_content_delete_asset($aboutImage);
    $aboutImage = '';
  }
  if (!empty($_FILES['about_image'])) {
    $uploaded = site_content_store_upload($_FILES['about_image'], $aboutImage ?: null);
    if ($uploaded) {
      $aboutImage = $uploaded;
    }
  }
  $payload['about_image'] = $aboutImage;

  $dealerImage = $content['dealer_showcase_image'] ?? $defaults['dealer_showcase_image'];
  if (!empty($_POST['dealer_showcase_image_remove'])) {
    site_content_delete_asset($dealerImage);
    $dealerImage = '';
  }
  if (!empty($_FILES['dealer_showcase_image'])) {
    $uploaded = site_content_store_upload($_FILES['dealer_showcase_image'], $dealerImage ?: null);
    if ($uploaded) {
      $dealerImage = $uploaded;
    }
  }
  $payload['dealer_showcase_image'] = $dealerImage;

  $galleryImages = $content['gallery_images'] ?? $defaults['gallery_images'];
  if (!is_array($galleryImages)) {
    $galleryImages = $defaults['gallery_images'];
  }
  $galleryRemove = $_POST['gallery_remove'] ?? [];
  if (!is_array($galleryRemove)) {
    $galleryRemove = [];
  }
  $galleryRemove = array_filter(array_map('strval', $galleryRemove));
  if ($galleryRemove) {
    $galleryImages = array_values(array_filter($galleryImages, function ($image) use ($galleryRemove) {
      if (!is_string($image)) {
        return false;
      }
      if (in_array($image, $galleryRemove, true)) {
        site_content_delete_asset($image);
        return false;
      }
      return trim($image) !== '';
    }));
  } else {
    $galleryImages = array_values(array_filter($galleryImages, function ($image) {
      return is_string($image) && trim($image) !== '';
    }));
  }

  if (!empty($_FILES['gallery_uploads']) && isset($_FILES['gallery_uploads']['name']) && is_array($_FILES['gallery_uploads']['name'])) {
    $names = $_FILES['gallery_uploads']['name'];
    $tmpNames = $_FILES['gallery_uploads']['tmp_name'];
    $errors = $_FILES['gallery_uploads']['error'];
    $types = $_FILES['gallery_uploads']['type'];
    $sizes = $_FILES['gallery_uploads']['size'];
    $count = count($names);
    for ($i = 0; $i < $count; $i++) {
      $file = [
        'name' => $names[$i] ?? '',
        'tmp_name' => $tmpNames[$i] ?? '',
        'error' => $errors[$i] ?? UPLOAD_ERR_NO_FILE,
        'type' => $types[$i] ?? '',
        'size' => $sizes[$i] ?? 0,
      ];
      $uploaded = site_content_store_upload($file);
      if ($uploaded) {
        $galleryImages[] = $uploaded;
      }
    }
  }

  $galleryImages = array_values(array_unique(array_filter($galleryImages, function ($image) {
    return is_string($image) && trim($image) !== '';
  })));
  $payload['gallery_images'] = array_slice($galleryImages, 0, 12);

  site_settings_update($payload);
  flash('ok', 'Site içerikleri güncellendi.');
  redirect(BASE_URL.'/admin/site_content.php');
}

$content = site_settings_all();
$faqItems = $content['faq_items'];
$navItems = $content['footer_nav_links'];

while (count($faqItems) < 4) {
  $faqItems[] = ['question' => '', 'answer' => ''];
}
while (count($navItems) < 5) {
  $navItems[] = ['label' => '', 'url' => ''];
}

?><!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Site İçerikleri • <?=h(APP_NAME)?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <?=admin_base_styles()?>
  <style>
    .settings-shell{display:flex;flex-direction:column;gap:1.5rem;}
    .pane-nav{background:#fff;border-radius:18px;padding:1.5rem;box-shadow:0 24px 60px -45px rgba(15,23,42,.55);}
    .pane-nav h6{font-weight:700;font-size:1.05rem;margin-bottom:1rem;color:var(--admin-ink);}
    .pane-button{width:100%;display:flex;align-items:center;gap:.85rem;border:none;background:rgba(14,165,181,.08);color:var(--admin-ink);padding:.8rem 1rem;border-radius:14px;font-weight:600;transition:all .2s ease;}
    .pane-button i{font-size:1.05rem;color:var(--admin-brand);transition:inherit;}
    .pane-button:not(:last-child){margin-bottom:.65rem;}
    .pane-button:hover,.pane-button:focus{background:rgba(14,165,181,.16);color:var(--admin-brand);outline:none;}
    .pane-button.active{background:var(--admin-brand);color:#fff;box-shadow:0 12px 30px -20px rgba(14,165,181,.9);}
    .pane-button.active i{color:#fff;}
    .content-pane{display:none;}
    .content-pane.active{display:block;}
    .repeater-item{border:1px dashed rgba(14,165,181,.35);border-radius:16px;padding:1rem 1.25rem;background:#fff;}
    .repeater-item + .repeater-item{margin-top:1rem;}
    .btn-add-row{border-radius:12px;}
    .media-preview{border-radius:18px;background:#f8fafc;padding:1rem;border:1px solid rgba(148,163,184,.25);display:flex;flex-direction:column;gap:.75rem;}
    .media-preview img{border-radius:14px;width:100%;height:220px;object-fit:cover;box-shadow:0 14px 35px rgba(15,118,110,.18);}
    .media-gallery-grid{display:grid;gap:1rem;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));}
    .media-gallery-item{background:#f8fafc;border-radius:16px;padding:.75rem;border:1px solid rgba(148,163,184,.25);display:flex;flex-direction:column;gap:.5rem;}
    .media-gallery-item img{border-radius:12px;width:100%;height:120px;object-fit:cover;box-shadow:0 12px 28px rgba(15,118,110,.15);}
    @media (max-width: 991px){
      .settings-shell{gap:1rem;}
    }
  </style>
</head>
<body class="admin-body">
<?php admin_layout_start('site', 'Site İçerikleri', 'Landing sayfanızdaki blokları düzenleyin ve hızlıca yayınlayın.'); ?>
    <?php flash_box(); ?>
    <form method="post" class="settings-shell" novalidate enctype="multipart/form-data">
      <div class="row g-4 align-items-start">
        <div class="col-lg-4">
          <div class="pane-nav">
            <h6>İçerik Başlıkları</h6>
            <button type="button" class="pane-button active" data-pane-target="contact"><i class="bi bi-person-rolodex"></i>İletişim Bilgileri</button>
            <button type="button" class="pane-button" data-pane-target="media"><i class="bi bi-images"></i>Görsel İçerikler</button>
            <button type="button" class="pane-button" data-pane-target="cta"><i class="bi bi-bullseye"></i>Çağrı Alanı</button>
            <button type="button" class="pane-button" data-pane-target="smtp"><i class="bi bi-envelope-paper"></i>SMTP Ayarları</button>
            <button type="button" class="pane-button" data-pane-target="faq"><i class="bi bi-chat-dots"></i>Sıkça Sorulanlar</button>
            <button type="button" class="pane-button" data-pane-target="footer"><i class="bi bi-columns-gap"></i>Footer İçeriği</button>
          </div>
        </div>
        <div class="col-lg-8">
          <div class="card card-lite content-pane active" data-pane="contact">
            <div class="card-section border-bottom">
              <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
                <div>
                  <h5 class="fw-bold mb-1">İletişim Bilgileri</h5>
                  <p class="text-muted mb-0">Footer ve iletişim bloklarında yer alan temel bilgileri güncelleyin.</p>
                </div>
                <span class="badge rounded-pill text-bg-light text-uppercase" style="letter-spacing:.05em;color:var(--admin-brand);background:rgba(14,165,181,.15);">#0ea5b5</span>
              </div>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Başlık</label>
                  <input type="text" name="contact_title" class="form-control" value="<?=h($content['contact_title'] ?? '')?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Açıklama</label>
                  <input type="text" name="contact_text" class="form-control" value="<?=h($content['contact_text'] ?? '')?>">
                </div>
                <div class="col-md-4">
                  <label class="form-label">Telefon</label>
                  <input type="text" name="contact_phone" class="form-control" value="<?=h($content['contact_phone'] ?? '')?>">
                </div>
                <div class="col-md-4">
                  <label class="form-label">E-posta</label>
                  <input type="email" name="contact_email" class="form-control" value="<?=h($content['contact_email'] ?? '')?>">
                </div>
                <div class="col-md-4">
                  <label class="form-label">Adres</label>
                  <input type="text" name="contact_address" class="form-control" value="<?=h($content['contact_address'] ?? '')?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Web Adresi</label>
                  <input type="text" name="contact_website" class="form-control" value="<?=h($content['contact_website'] ?? '')?>" placeholder="https://...">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Web Etiketi</label>
                  <input type="text" name="contact_website_label" class="form-control" value="<?=h($content['contact_website_label'] ?? '')?>" placeholder="zerosoft.com.tr">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Birincil Buton Metni</label>
                  <input type="text" name="contact_primary_label" class="form-control" value="<?=h($content['contact_primary_label'] ?? '')?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Birincil Buton URL</label>
                  <input type="text" name="contact_primary_url" class="form-control" value="<?=h($content['contact_primary_url'] ?? '')?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">İkincil Buton Metni</label>
                  <input type="text" name="contact_secondary_label" class="form-control" value="<?=h($content['contact_secondary_label'] ?? '')?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">İkincil Buton URL</label>
                  <input type="text" name="contact_secondary_url" class="form-control" value="<?=h($content['contact_secondary_url'] ?? '')?>">
                </div>
              </div>
            </div>
          </div>

          <div class="card card-lite content-pane" data-pane="media">
            <?php
              $siteLogoCurrent = $content['site_logo'] ?? $defaults['site_logo'];
              $heroMainImage = $content['hero_image_main'] ?? $defaults['hero_image_main'];
              $heroSecondaryImage = $content['hero_image_secondary'] ?? $defaults['hero_image_secondary'];
              $aboutImageCurrent = $content['about_image'] ?? $defaults['about_image'];
              $dealerImageCurrent = $content['dealer_showcase_image'] ?? $defaults['dealer_showcase_image'];
              $galleryImages = $content['gallery_images'] ?? $defaults['gallery_images'];
              if (!is_array($galleryImages)) {
                $galleryImages = $defaults['gallery_images'];
              }
            ?>
            <div class="card-section">
              <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
                <div>
                  <h5 class="fw-bold mb-1">Görsel İçerikler</h5>
                  <p class="text-muted mb-0">Anasayfadaki görselleri güncelleyerek markanıza özel bir vitrin oluşturun.</p>
                </div>
                <i class="bi bi-camera-reels" style="font-size:1.6rem;color:var(--admin-brand);"></i>
              </div>
              <div class="row g-4">
                <div class="col-12">
                  <div class="media-preview">
                    <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
                      <div>
                        <strong>Site Logosu</strong>
                        <p class="text-muted small mb-0">Header bölümünde kullanılan logo. Şeffaf arka planlı SVG veya PNG önerilir.</p>
                      </div>
                      <span class="badge text-bg-light" style="color:var(--admin-brand);background:rgba(14,165,181,.15);">120x40px önerilir</span>
                    </div>
                    <?php if (!empty($siteLogoCurrent)): ?>
                      <img src="<?=h($siteLogoCurrent)?>" alt="Site logosu" style="object-fit:contain;background:#fff;padding:1.25rem;height:140px;width:auto;max-width:100%;display:block;margin:0 auto;">
                    <?php else: ?>
                      <div class="text-muted small">Şu anda varsayılan yazı tabanlı logo kullanılıyor.</div>
                    <?php endif; ?>
                    <input type="file" name="site_logo" class="form-control" accept="image/*">
                    <?php if (!empty($content['site_logo'])): ?>
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="site_logo_remove" value="1" id="removeSiteLogo">
                        <label class="form-check-label" for="removeSiteLogo">Yüklü logoyu kaldır</label>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="media-preview">
                    <div>
                      <strong>Hero Görseli 1</strong>
                      <p class="text-muted small mb-0">Önerilen boyut: 1200x900px. JPG veya WEBP formatında yükleyin.</p>
                    </div>
                    <img src="<?=h($heroMainImage)?>" alt="Hero görseli 1" loading="lazy">
                    <input type="file" name="hero_image_main" class="form-control" accept="image/*">
                    <?php if (!empty($content['hero_image_main'])): ?>
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="hero_image_main_remove" value="1" id="removeHeroMain">
                        <label class="form-check-label" for="removeHeroMain">Yüklü görseli kaldır</label>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="media-preview">
                    <div>
                      <strong>Hero Görseli 2</strong>
                      <p class="text-muted small mb-0">Hero bölümündeki ikinci görsel. Önerilen boyut: 900x900px.</p>
                    </div>
                    <img src="<?=h($heroSecondaryImage)?>" alt="Hero görseli 2" loading="lazy">
                    <input type="file" name="hero_image_secondary" class="form-control" accept="image/*">
                    <?php if (!empty($content['hero_image_secondary'])): ?>
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="hero_image_secondary_remove" value="1" id="removeHeroSecondary">
                        <label class="form-check-label" for="removeHeroSecondary">Yüklü görseli kaldır</label>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="col-12">
                  <div class="media-preview">
                    <div>
                      <strong>Hakkımızda Görseli</strong>
                      <p class="text-muted small mb-0">"Hakkımızda" bölümünde kullanılan görsel. Önerilen boyut: 1200x900px.</p>
                    </div>
                    <img src="<?=h($aboutImageCurrent)?>" alt="Hakkımızda görseli" loading="lazy">
                    <input type="file" name="about_image" class="form-control" accept="image/*">
                    <?php if (!empty($content['about_image'])): ?>
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="about_image_remove" value="1" id="removeAboutImage">
                        <label class="form-check-label" for="removeAboutImage">Yüklü görseli kaldır</label>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="col-12">
                  <div class="media-preview">
                    <div>
                      <strong>Bayi Paneli Görseli</strong>
                      <p class="text-muted small mb-0">"Bayi Ağı" bölümünde kullanılan tanıtım görseli. Önerilen boyut: 1200x900px.</p>
                    </div>
                    <img src="<?=h($dealerImageCurrent)?>" alt="Bayi paneli görseli" loading="lazy">
                    <input type="file" name="dealer_showcase_image" class="form-control" accept="image/*">
                    <?php if (!empty($content['dealer_showcase_image'])): ?>
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="dealer_showcase_image_remove" value="1" id="removeDealerImage">
                        <label class="form-check-label" for="removeDealerImage">Yüklü görseli kaldır</label>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="col-12">
                  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                    <strong class="me-2">Galeri Görselleri</strong>
                    <span class="badge text-bg-light" style="color:var(--admin-brand);background:rgba(14,165,181,.15);">En fazla 12 görsel</span>
                  </div>
                  <div class="media-gallery-grid mb-3">
                    <?php foreach ($galleryImages as $idx => $image): ?>
                      <?php if (!is_string($image) || trim($image) === '') { continue; } ?>
                      <div class="media-gallery-item">
                        <img src="<?=h($image)?>" alt="Galeri görseli <?=h((string)($idx + 1))?>" loading="lazy">
                        <div class="form-check">
                          <input class="form-check-input" type="checkbox" name="gallery_remove[]" value="<?=h($image)?>" id="galleryRemove<?=$idx?>">
                          <label class="form-check-label small" for="galleryRemove<?=$idx?>">Görseli kaldır</label>
                        </div>
                      </div>
                    <?php endforeach; ?>
                    <?php if (!$galleryImages): ?>
                      <p class="text-muted small mb-0">Henüz galeri görseli yok. Aşağıdan yeni görseller yükleyebilirsiniz.</p>
                    <?php endif; ?>
                  </div>
                  <input type="file" name="gallery_uploads[]" class="form-control" accept="image/*" multiple>
                  <div class="form-text">Birden fazla görsel seçebilirsiniz. JPG, PNG veya WEBP formatları desteklenir.</div>
                </div>
              </div>
            </div>
          </div>

          <div class="card card-lite content-pane" data-pane="cta">
            <div class="card-section">
              <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
                <div>
                  <h5 class="fw-bold mb-1">Çağrı Alanı</h5>
                  <p class="text-muted mb-0">Anasayfanın alt bölümünde yer alan harekete geçirici mesajı şekillendirin.</p>
                </div>
                <i class="bi bi-megaphone-fill" style="font-size:1.6rem;color:var(--admin-brand);"></i>
              </div>
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label">CTA Rozeti</label>
                  <input type="text" name="contact_cta_badge" class="form-control" value="<?=h($content['contact_cta_badge'] ?? '')?>">
                </div>
                <div class="col-md-4">
                  <label class="form-label">CTA Başlığı</label>
                  <input type="text" name="contact_cta_title" class="form-control" value="<?=h($content['contact_cta_title'] ?? '')?>">
                </div>
                <div class="col-md-4">
                  <label class="form-label">CTA Buton Metni</label>
                  <input type="text" name="contact_cta_button_label" class="form-control" value="<?=h($content['contact_cta_button_label'] ?? '')?>">
                </div>
                <div class="col-12">
                  <label class="form-label">CTA Açıklaması</label>
                  <textarea name="contact_cta_text" class="form-control" rows="3"><?=h($content['contact_cta_text'] ?? '')?></textarea>
                </div>
                <div class="col-12">
                  <label class="form-label">CTA Buton URL</label>
                  <input type="text" name="contact_cta_button_url" class="form-control" value="<?=h($content['contact_cta_button_url'] ?? '')?>">
                </div>
              </div>
            </div>
          </div>

          <div class="card card-lite content-pane" data-pane="smtp">
            <div class="card-section">
              <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
                <div>
                  <h5 class="fw-bold mb-1">SMTP Ayarları</h5>
                  <p class="text-muted mb-0">Gönderici bilgilerini ve SMTP sunucunuzu tanımlayın. Boş alanlar mevcut sunucu yapılandırmasına göre çalışır.</p>
                </div>
                <i class="bi bi-gear-wide-connected" style="font-size:1.6rem;color:var(--admin-brand);"></i>
              </div>
              <?php
                $smtpHostValue = $content['smtp_host'] ?? '';
                if ($smtpHostValue === '' && defined('SMTP_HOST')) {
                  $smtpHostValue = (string)SMTP_HOST;
                }
                $smtpPortValue = $content['smtp_port'] ?? '';
                if ($smtpPortValue === '' && defined('SMTP_PORT')) {
                  $smtpPortValue = (string)SMTP_PORT;
                }
                $smtpUserValue = $content['smtp_user'] ?? '';
                if ($smtpUserValue === '' && defined('SMTP_USER')) {
                  $smtpUserValue = (string)SMTP_USER;
                }
                $smtpPassValue = $content['smtp_pass'] ?? '';
                if ($smtpPassValue === '' && defined('SMTP_PASS')) {
                  $smtpPassValue = (string)SMTP_PASS;
                }
                $smtpSecureValue = strtolower(trim((string)($content['smtp_secure'] ?? '')));
                if (!in_array($smtpSecureValue, ['tls', 'ssl', ''], true)) {
                  $smtpSecureValue = defined('SMTP_SECURE') ? strtolower((string)SMTP_SECURE) : '';
                  if (!in_array($smtpSecureValue, ['tls', 'ssl', ''], true)) {
                    $smtpSecureValue = '';
                  }
                }
                $smtpFromEmail = $content['smtp_from_email'] ?? '';
                if ($smtpFromEmail === '' && defined('MAIL_FROM')) {
                  $smtpFromEmail = (string)MAIL_FROM;
                }
                $smtpFromName = $content['smtp_from_name'] ?? '';
                if ($smtpFromName === '' && defined('MAIL_FROM_NAME')) {
                  $smtpFromName = (string)MAIL_FROM_NAME;
                }
              ?>
              <div class="row g-3">
                <div class="col-md-8">
                  <label class="form-label">SMTP Sunucusu</label>
                  <input type="text" name="smtp_host" class="form-control" value="<?=h($smtpHostValue)?>" placeholder="smtp.ornek.com">
                </div>
                <div class="col-md-4">
                  <label class="form-label">Port</label>
                  <input type="text" name="smtp_port" class="form-control" value="<?=h($smtpPortValue)?>" placeholder="587">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Kullanıcı Adı</label>
                  <input type="text" name="smtp_user" class="form-control" value="<?=h($smtpUserValue)?>" placeholder="smtp@ornek.com">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Şifre</label>
                  <input type="password" name="smtp_pass" class="form-control" value="<?=h($smtpPassValue)?>" autocomplete="new-password">
                </div>
                <div class="col-md-4">
                  <label class="form-label">Bağlantı Türü</label>
                  <select name="smtp_secure" class="form-select">
                    <option value="" <?=$smtpSecureValue === '' ? 'selected' : ''?>>Güvenliksiz</option>
                    <option value="tls" <?=$smtpSecureValue === 'tls' ? 'selected' : ''?>>TLS (587)</option>
                    <option value="ssl" <?=$smtpSecureValue === 'ssl' ? 'selected' : ''?>>SSL (465)</option>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Gönderici E-posta</label>
                  <input type="email" name="smtp_from_email" class="form-control" value="<?=h($smtpFromEmail)?>" placeholder="no-reply@ornek.com">
                </div>
                <div class="col-md-4">
                  <label class="form-label">Gönderici Adı</label>
                  <input type="text" name="smtp_from_name" class="form-control" value="<?=h($smtpFromName)?>" placeholder="BİKARE">
                </div>
                <div class="col-12">
                  <div class="alert alert-info small mb-0">SMTP alanlarını boş bırakırsanız sistem <code>config.php</code> veya ortam değişkenlerindeki değerleri kullanmaya devam eder.</div>
                </div>
              </div>
            </div>
          </div>

          <div class="card card-lite content-pane" data-pane="faq">
            <div class="card-section">
              <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
                <div>
                  <h5 class="fw-bold mb-1">Sıkça Sorulan Sorular</h5>
                  <p class="text-muted mb-0">Ziyaretçilerin en çok merak ettiği başlıkları hızlıca düzenleyin.</p>
                </div>
                <span class="badge text-bg-light" style="color:var(--admin-brand);background:rgba(14,165,181,.15);">En az 3 önerilir</span>
              </div>
              <div data-repeater="faq">
                <?php foreach ($faqItems as $index => $faq): ?>
                  <div class="repeater-item" data-index="<?=$index?>">
                    <div class="row g-3 align-items-start">
                      <div class="col-md-6">
                        <label class="form-label">Soru</label>
                        <input type="text" class="form-control" name="faq_question[]" value="<?=h($faq['question'])?>" placeholder="Soru metni">
                      </div>
                      <div class="col-md-6">
                        <label class="form-label">Cevap</label>
                        <textarea class="form-control" name="faq_answer[]" rows="2" placeholder="Cevap metni"><?=h($faq['answer'])?></textarea>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              <button type="button" class="btn btn-sm btn-outline-secondary mt-3 btn-add-row" data-target="faq">+ Soru Ekle</button>
            </div>
          </div>

          <div class="card card-lite content-pane" data-pane="footer">
            <div class="card-section">
              <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
                <div>
                  <h5 class="fw-bold mb-1">Footer İçeriği</h5>
                  <p class="text-muted mb-0">Hakkımızda alanı, alt satırlar ve hızlı bağlantıları buradan yönetin.</p>
                </div>
                <i class="bi bi-layout-text-window-reverse" style="font-size:1.6rem;color:var(--admin-brand);"></i>
              </div>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Hakkımızda Metni</label>
                  <textarea name="footer_about" class="form-control" rows="4"><?=h($content['footer_about'] ?? '')?></textarea>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Firma Adı</label>
                  <input type="text" name="footer_company" class="form-control" value="<?=h($content['footer_company'] ?? '')?>">
                  <div class="mt-3">
                    <label class="form-label">Alt Satır (Sol)</label>
                    <input type="text" name="footer_disclaimer_left" class="form-control" value="<?=h($content['footer_disclaimer_left'] ?? '')?>">
                  </div>
                  <div class="mt-3">
                    <label class="form-label">Alt Satır (Sağ)</label>
                    <input type="text" name="footer_disclaimer_right" class="form-control" value="<?=h($content['footer_disclaimer_right'] ?? '')?>">
                  </div>
                </div>
              </div>
              <hr class="my-4">
              <h6 class="fw-semibold">Footer Navigasyonu</h6>
              <div data-repeater="nav">
                <?php foreach ($navItems as $item): ?>
                  <div class="repeater-item">
                    <div class="row g-3">
                      <div class="col-md-6">
                        <label class="form-label">Etiket</label>
                        <input type="text" class="form-control" name="nav_label[]" value="<?=h($item['label'])?>" placeholder="Örn. Paketler">
                      </div>
                      <div class="col-md-6">
                        <label class="form-label">Bağlantı</label>
                        <input type="text" class="form-control" name="nav_url[]" value="<?=h($item['url'])?>" placeholder="#paketler">
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              <button type="button" class="btn btn-sm btn-outline-secondary mt-3 btn-add-row" data-target="nav">+ Navigasyon Öğesi Ekle</button>
            </div>
          </div>
        </div>
      </div>

      <div class="card card-lite">
        <div class="card-section d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
          <div>
            <h6 class="fw-semibold mb-1">Kaydet ve Yayına Al</h6>
            <p class="text-muted small mb-0">Güncellemeleriniz kaydedildiğinde BİKARE anasayfasında hemen görüntülenir.</p>
          </div>
          <button type="submit" class="btn btn-brand px-4">Değişiklikleri Kaydet</button>
        </div>
      </div>
    </form>
<?php admin_layout_end(); ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  const panes = document.querySelectorAll('.content-pane');
  const buttons = document.querySelectorAll('[data-pane-target]');
  const activate = (id) => {
    panes.forEach(pane => {
      pane.classList.toggle('active', pane.dataset.pane === id);
    });
    buttons.forEach(btn => {
      btn.classList.toggle('active', btn.dataset.paneTarget === id);
    });
  };
  buttons.forEach(btn => {
    btn.addEventListener('click', () => activate(btn.dataset.paneTarget));
  });

  const templateFaq = () => {
    const wrapper = document.createElement('div');
    wrapper.className = 'repeater-item';
    wrapper.innerHTML = `
      <div class="row g-3 align-items-start">
        <div class="col-md-6">
          <label class="form-label">Soru</label>
          <input type="text" class="form-control" name="faq_question[]" placeholder="Soru metni">
        </div>
        <div class="col-md-6">
          <label class="form-label">Cevap</label>
          <textarea class="form-control" name="faq_answer[]" rows="2" placeholder="Cevap metni"></textarea>
        </div>
      </div>`;
    return wrapper;
  };

  const templateNav = () => {
    const wrapper = document.createElement('div');
    wrapper.className = 'repeater-item';
    wrapper.innerHTML = `
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Etiket</label>
          <input type="text" class="form-control" name="nav_label[]" placeholder="Örn. Paketler">
        </div>
        <div class="col-md-6">
          <label class="form-label">Bağlantı</label>
          <input type="text" class="form-control" name="nav_url[]" placeholder="#paketler">
        </div>
      </div>`;
    return wrapper;
  };

  document.querySelectorAll('.btn-add-row').forEach(btn => {
    btn.addEventListener('click', () => {
      const target = btn.dataset.target;
      if (target === 'faq') {
        document.querySelector('[data-repeater="faq"]').appendChild(templateFaq());
      }
      if (target === 'nav') {
        document.querySelector('[data-repeater="nav"]').appendChild(templateNav());
      }
    });
  });
})();
</script>
</body>
</html>
