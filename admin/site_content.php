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
    .repeater-item{border:1px dashed rgba(14,165,181,.35);border-radius:16px;padding:1rem 1.25rem;background:#fff;}
    .repeater-item + .repeater-item{margin-top:1rem;}
    .btn-add-row{border-radius:12px;}
  </style>
</head>
<body class="admin-body">
<?php admin_layout_start('site', 'Site İçerikleri', 'Landing sayfası metinlerini ve sıkça sorulan soruları yönetin.'); ?>
    <?php flash_box(); ?>
    <form method="post" class="card card-lite">
      <div class="card-section border-bottom">
        <h5 class="fw-bold mb-3">İletişim Alanı</h5>
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
        <hr class="my-4">
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

      <div class="card-section border-bottom">
        <h5 class="fw-bold mb-3">Sıkça Sorulan Sorular</h5>
        <div id="faqRepeater">
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

      <div class="card-section border-bottom">
        <h5 class="fw-bold mb-3">Footer İçeriği</h5>
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
        <div id="navRepeater">
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

      <div class="card-section d-flex justify-content-between align-items-center flex-wrap gap-3">
        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
        <span class="text-muted small">Girdiğiniz içerik anasayfada anında güncellenecektir.</span>
        <button type="submit" class="btn btn-brand">Değişiklikleri Kaydet</button>
      </div>
    </form>
<?php admin_layout_end(); ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
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
        document.getElementById('faqRepeater').appendChild(templateFaq());
      }
      if (target === 'nav') {
        document.getElementById('navRepeater').appendChild(templateNav());
      }
    });
  });
})();
</script>
</body>
</html>
