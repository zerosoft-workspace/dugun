<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/site.php';
require_once __DIR__.'/../includes/dealers.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/partials/ui.php';

require_admin();
require_admin_permission('site');
install_schema();

$defaults = site_content_defaults();
$content = site_settings_all();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_or_die();

  if (isset($_POST['generate_sitemap'])) {
    [$ok, $message] = site_generate_sitemap();
    if ($ok) {
      flash('ok', $message);
    } else {
      flash('err', $message);
    }
    redirect(BASE_URL.'/admin/site_content.php#seo');
  }

  $payload = [
    'hero_badge' => trim($_POST['hero_badge'] ?? ''),
    'hero_title' => trim($_POST['hero_title'] ?? ''),
    'hero_text' => trim($_POST['hero_text'] ?? ''),
    'hero_primary_label' => trim($_POST['hero_primary_label'] ?? ''),
    'hero_primary_url' => trim($_POST['hero_primary_url'] ?? ''),
    'hero_secondary_label' => trim($_POST['hero_secondary_label'] ?? ''),
    'hero_secondary_url' => trim($_POST['hero_secondary_url'] ?? ''),
    'about_badge' => trim($_POST['about_badge'] ?? ''),
    'about_title' => trim($_POST['about_title'] ?? ''),
    'about_text' => trim($_POST['about_text'] ?? ''),
    'packages_title' => trim($_POST['packages_title'] ?? ''),
    'packages_text' => trim($_POST['packages_text'] ?? ''),
    'dealer_badge' => trim($_POST['dealer_badge'] ?? ''),
    'dealer_title' => trim($_POST['dealer_title'] ?? ''),
    'dealer_text' => trim($_POST['dealer_text'] ?? ''),
    'dealer_button_label' => trim($_POST['dealer_button_label'] ?? ''),
    'dealer_button_url' => trim($_POST['dealer_button_url'] ?? ''),
    'gallery_title' => trim($_POST['gallery_title'] ?? ''),
    'gallery_text' => trim($_POST['gallery_text'] ?? ''),
    'timeline_title' => trim($_POST['timeline_title'] ?? ''),
    'timeline_text' => trim($_POST['timeline_text'] ?? ''),
    'cta_banner_title' => trim($_POST['cta_banner_title'] ?? ''),
    'cta_banner_text' => trim($_POST['cta_banner_text'] ?? ''),
    'cta_banner_button_label' => trim($_POST['cta_banner_button_label'] ?? ''),
    'cta_banner_button_url' => trim($_POST['cta_banner_button_url'] ?? ''),
    'lead_form_title' => trim($_POST['lead_form_title'] ?? ''),
    'lead_form_text' => trim($_POST['lead_form_text'] ?? ''),
    'lead_form_notice' => trim($_POST['lead_form_notice'] ?? ''),
    'lead_form_submit_label' => trim($_POST['lead_form_submit_label'] ?? ''),
    'default_dealer_referral_code' => trim($_POST['default_dealer_referral_code'] ?? ''),
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
    'seo_meta_title' => trim($_POST['seo_meta_title'] ?? ''),
    'seo_meta_description' => trim($_POST['seo_meta_description'] ?? ''),
    'seo_meta_keywords' => trim($_POST['seo_meta_keywords'] ?? ''),
    'blog_section_badge' => trim($_POST['blog_section_badge'] ?? ''),
    'blog_section_title' => trim($_POST['blog_section_title'] ?? ''),
    'blog_section_text' => trim($_POST['blog_section_text'] ?? ''),
  ];

  $payload['paytr_enabled'] = !empty($_POST['paytr_enabled']) ? '1' : '0';
  $payload['paytr_merchant_id'] = trim($_POST['paytr_merchant_id'] ?? '');
  $payload['paytr_merchant_key'] = trim($_POST['paytr_merchant_key'] ?? '');
  $payload['paytr_merchant_salt'] = trim($_POST['paytr_merchant_salt'] ?? '');
  $testModeInput = $_POST['paytr_test_mode'] ?? '';
  $payload['paytr_test_mode'] = $testModeInput === '0' ? '0' : '1';
  $payload['whatsapp_api_enabled'] = !empty($_POST['whatsapp_api_enabled']) ? '1' : '0';
  $payload['whatsapp_api_url'] = trim($_POST['whatsapp_api_url'] ?? '');
  $payload['whatsapp_api_token'] = trim($_POST['whatsapp_api_token'] ?? '');
  $payload['whatsapp_api_sender'] = trim($_POST['whatsapp_api_sender'] ?? '');

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

  $defaultReferral = $payload['default_dealer_referral_code'];
  if ($defaultReferral !== '') {
    $defaultDealer = dealer_find_by_code($defaultReferral);
    if (!$defaultDealer || !in_array($defaultDealer['status'], [DEALER_STATUS_ACTIVE, DEALER_STATUS_PENDING], true)) {
      flash('fail', 'Varsayılan bayi referans kodu bulunamadı veya pasif durumda. Kod alanı temizlendi.');
      $payload['default_dealer_referral_code'] = '';
    }
  }

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

  $heroMetrics = [];
  $metricValues = $_POST['hero_metric_value'] ?? [];
  $metricLabels = $_POST['hero_metric_label'] ?? [];
  foreach ($metricValues as $idx => $value) {
    $value = trim((string)$value);
    $label = trim((string)($metricLabels[$idx] ?? ''));
    if ($value === '' && $label === '') {
      continue;
    }
    $heroMetrics[] = ['value' => $value, 'label' => $label];
  }
  if (!$heroMetrics) {
    $heroMetrics = $defaults['hero_metrics'];
  }
  $payload['hero_metrics'] = $heroMetrics;

  $blogPosts = [];
  $blogTitles = $_POST['blog_title'] ?? [];
  $blogDescriptions = $_POST['blog_description'] ?? [];
  $blogSlugs = $_POST['blog_slug'] ?? [];
  $blogImages = $_POST['blog_image'] ?? [];
  $blogBodies = $_POST['blog_content'] ?? [];
  $blogPublishedAt = $_POST['blog_published_at'] ?? [];
  $blogGalleryRaw = $_POST['blog_gallery'] ?? [];
  $blogImageUploads = $_FILES['blog_image_upload'] ?? null;
  $blogGalleryUploads = $_FILES['blog_gallery_upload'] ?? null;
  $blogImageDeleteExisting = $_POST['blog_image_delete_existing'] ?? [];
  if (!is_array($blogImageDeleteExisting)) {
    $blogImageDeleteExisting = [];
  }
  $blogImageDeleteExisting = array_filter(array_map('strval', $blogImageDeleteExisting));
  if ($blogImageDeleteExisting) {
    foreach ($blogImageDeleteExisting as $deletedPath) {
      site_content_delete_asset($deletedPath);
    }
  }

  $extractUpload = static function ($files, $index) {
    if (!is_array($files) || !isset($files['name'][$index])) {
      return null;
    }
    $keys = ['name', 'type', 'tmp_name', 'error', 'size'];
    $upload = [];
    foreach ($keys as $key) {
      $value = $files[$key][$index] ?? null;
      if (is_array($value)) {
        return null;
      }
      $upload[$key] = $value;
    }
    return $upload;
  };

  $collectGalleryUploads = static function ($files, $index): array {
    $uploads = [];
    if (!is_array($files) || !isset($files['name'][$index]) || !is_array($files['name'][$index])) {
      return $uploads;
    }
    $names = $files['name'][$index];
    $types = $files['type'][$index] ?? [];
    $tmpNames = $files['tmp_name'][$index] ?? [];
    $errors = $files['error'][$index] ?? [];
    $sizes = $files['size'][$index] ?? [];
    foreach ($names as $idx => $name) {
      $uploads[] = [
        'name' => $name,
        'type' => $types[$idx] ?? '',
        'tmp_name' => $tmpNames[$idx] ?? '',
        'error' => $errors[$idx] ?? UPLOAD_ERR_NO_FILE,
        'size' => $sizes[$idx] ?? 0,
      ];
    }
    return $uploads;
  };

  foreach ($blogTitles as $idx => $title) {
    $title = trim((string)$title);
    $description = trim((string)($blogDescriptions[$idx] ?? ''));
    $slug = trim((string)($blogSlugs[$idx] ?? ''));
    $image = trim((string)($blogImages[$idx] ?? ''));
    $body = trim((string)($blogBodies[$idx] ?? ''));
    $published = trim((string)($blogPublishedAt[$idx] ?? ''));
    $galleryField = $blogGalleryRaw[$idx] ?? '';
    if (is_array($galleryField)) {
      $galleryField = implode("\n", array_filter(array_map('trim', $galleryField)));
    }
    $galleryLines = preg_split('~[\r\n]+~', (string)$galleryField) ?: [];
    $gallery = [];
    foreach ($galleryLines as $line) {
      $line = trim((string)$line);
      if ($line !== '') {
        $gallery[] = $line;
      }
    }

    $imageUpload = $extractUpload($blogImageUploads, $idx);
    if ($imageUpload && (int)($imageUpload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
      $uploaded = site_content_store_upload($imageUpload, $image ?: null);
      if ($uploaded) {
        $image = $uploaded;
      }
    }

    $galleryUploadFiles = $collectGalleryUploads($blogGalleryUploads, $idx);
    if ($galleryUploadFiles) {
      foreach ($galleryUploadFiles as $uploadFile) {
        if ((int)($uploadFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
          continue;
        }
        $uploaded = site_content_store_upload($uploadFile);
        if ($uploaded) {
          $gallery[] = $uploaded;
        }
      }
    }

    $gallery = array_values(array_unique(array_filter($gallery, static function ($item) {
      return trim((string)$item) !== '';
    })));

    if ($slug === '') {
      $slug = slugify_allow_empty($title);
    } else {
      $slug = slugify_allow_empty($slug);
    }

    $normalizedDate = site_normalize_blog_date($published);

    if ($title === '' && $description === '' && $slug === '' && $image === '' && $body === '' && $normalizedDate === null && !$gallery) {
      continue;
    }

    $blogPosts[] = [
      'title' => $title,
      'description' => $description,
      'slug' => $slug,
      'image' => $image,
      'content' => $body,
      'published_at' => $normalizedDate ?? '',
      'gallery' => $gallery,
    ];
  }
  if (!$blogPosts) {
    $blogPosts = $defaults['blog_posts'];
  }
  $payload['blog_posts'] = array_slice($blogPosts, 0, 9);

  $aboutFeatures = [];
  $aboutFeatureTitles = $_POST['about_feature_title'] ?? [];
  $aboutFeatureTexts = $_POST['about_feature_text'] ?? [];
  foreach ($aboutFeatureTitles as $idx => $title) {
    $title = trim((string)$title);
    $text = trim((string)($aboutFeatureTexts[$idx] ?? ''));
    if ($title === '' && $text === '') {
      continue;
    }
    $aboutFeatures[] = ['title' => $title, 'text' => $text];
  }
  if (!$aboutFeatures) {
    $aboutFeatures = $defaults['about_features'];
  }
  $payload['about_features'] = $aboutFeatures;

  $featureBlocks = [];
  $featureIcons = $_POST['feature_icon'] ?? [];
  $featureTitles = $_POST['feature_title'] ?? [];
  $featureTexts = $_POST['feature_text'] ?? [];
  foreach ($featureTitles as $idx => $title) {
    $icon = trim((string)($featureIcons[$idx] ?? ''));
    $title = trim((string)$title);
    $text = trim((string)($featureTexts[$idx] ?? ''));
    if ($icon === '' && $title === '' && $text === '') {
      continue;
    }
    $featureBlocks[] = ['icon' => $icon, 'title' => $title, 'text' => $text];
  }
  if (!$featureBlocks) {
    $featureBlocks = $defaults['feature_blocks'];
  }
  $payload['feature_blocks'] = $featureBlocks;

  $timelineSteps = [];
  $timelineTitles = $_POST['timeline_step_title'] ?? [];
  $timelineTexts = $_POST['timeline_step_text'] ?? [];
  foreach ($timelineTitles as $idx => $title) {
    $title = trim((string)$title);
    $text = trim((string)($timelineTexts[$idx] ?? ''));
    if ($title === '' && $text === '') {
      continue;
    }
    $timelineSteps[] = ['title' => $title, 'text' => $text];
  }
  if (!$timelineSteps) {
    $timelineSteps = $defaults['timeline_steps'];
  }
  $payload['timeline_steps'] = $timelineSteps;

  $packagesHighlights = [];
  $packageHighlightsInput = $_POST['packages_highlight'] ?? [];
  foreach ($packageHighlightsInput as $highlight) {
    $highlight = trim((string)$highlight);
    if ($highlight === '') {
      continue;
    }
    $packagesHighlights[] = $highlight;
  }
  if (!$packagesHighlights) {
    $packagesHighlights = $defaults['packages_highlights'];
  }
  $payload['packages_highlights'] = $packagesHighlights;

  $dealerHighlights = [];
  $dealerHighlightsInput = $_POST['dealer_highlight'] ?? [];
  foreach ($dealerHighlightsInput as $highlight) {
    $highlight = trim((string)$highlight);
    if ($highlight === '') {
      continue;
    }
    $dealerHighlights[] = $highlight;
  }
  if (!$dealerHighlights) {
    $dealerHighlights = $defaults['dealer_highlights'];
  }
  $payload['dealer_highlights'] = $dealerHighlights;

  $testimonials = [];
  $testimonialQuotes = $_POST['testimonial_quote'] ?? [];
  $testimonialAuthors = $_POST['testimonial_author'] ?? [];
  $testimonialRoles = $_POST['testimonial_role'] ?? [];
  foreach ($testimonialQuotes as $idx => $quote) {
    $quote = trim((string)$quote);
    $author = trim((string)($testimonialAuthors[$idx] ?? ''));
    $role = trim((string)($testimonialRoles[$idx] ?? ''));
    if ($quote === '' && $author === '' && $role === '') {
      continue;
    }
    $testimonials[] = ['quote' => $quote, 'author' => $author, 'role' => $role];
  }
  if (!$testimonials) {
    $testimonials = $defaults['testimonials'];
  }
  $payload['testimonials'] = $testimonials;

  $leadFormBullets = [];
  $leadFormBulletsInput = $_POST['lead_form_bullet'] ?? [];
  foreach ($leadFormBulletsInput as $bullet) {
    $bullet = trim((string)$bullet);
    if ($bullet === '') {
      continue;
    }
    $leadFormBullets[] = $bullet;
  }
  if (!$leadFormBullets) {
    $leadFormBullets = $defaults['lead_form_bullets'];
  }
  $payload['lead_form_bullets'] = $leadFormBullets;

  $siteLogo = $content['site_logo'] ?? $defaults['site_logo'];
  if (!empty($_POST['site_logo_remove'])) {
    site_content_delete_asset($siteLogo);
    $siteLogo = '';
  }
  if (!empty($_FILES['site_logo']) && (int)($_FILES['site_logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $uploaded = site_content_store_upload($_FILES['site_logo'], $siteLogo ?: null);
    if ($uploaded) {
      $siteLogo = $uploaded;
    }
  }
  $payload['site_logo'] = $siteLogo;

  $seoFavicon = $content['seo_favicon'] ?? $defaults['seo_favicon'];
  if (!empty($_POST['seo_favicon_remove'])) {
    site_content_delete_asset($seoFavicon);
    $seoFavicon = '';
  }
  if (!empty($_FILES['seo_favicon']) && (int)($_FILES['seo_favicon']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $uploaded = site_content_store_upload($_FILES['seo_favicon'], $seoFavicon ?: null);
    if ($uploaded) {
      $seoFavicon = $uploaded;
    }
  }
  $payload['seo_favicon'] = $seoFavicon;

  $heroMain = $content['hero_image_main'] ?? $defaults['hero_image_main'];
  if (!empty($_POST['hero_image_main_remove'])) {
    site_content_delete_asset($heroMain);
    $heroMain = '';
  }
  if (!empty($_FILES['hero_image_main']) && (int)($_FILES['hero_image_main']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
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
  if (!empty($_FILES['hero_image_secondary']) && (int)($_FILES['hero_image_secondary']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
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
  if (!empty($_FILES['about_image']) && (int)($_FILES['about_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
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
  if (!empty($_FILES['dealer_showcase_image']) && (int)($_FILES['dealer_showcase_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
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
$heroMetrics = $content['hero_metrics'] ?? $defaults['hero_metrics'];
if (!is_array($heroMetrics)) {
  $heroMetrics = $defaults['hero_metrics'];
}
$heroMetrics = array_values($heroMetrics);
$aboutFeatures = $content['about_features'] ?? $defaults['about_features'];
if (!is_array($aboutFeatures)) {
  $aboutFeatures = $defaults['about_features'];
}
$aboutFeatures = array_values($aboutFeatures);
$featureBlocks = $content['feature_blocks'] ?? $defaults['feature_blocks'];
if (!is_array($featureBlocks)) {
  $featureBlocks = $defaults['feature_blocks'];
}
$featureBlocks = array_values($featureBlocks);
$timelineSteps = $content['timeline_steps'] ?? $defaults['timeline_steps'];
if (!is_array($timelineSteps)) {
  $timelineSteps = $defaults['timeline_steps'];
}
$timelineSteps = array_values($timelineSteps);
$packagesHighlights = $content['packages_highlights'] ?? $defaults['packages_highlights'];
if (!is_array($packagesHighlights)) {
  $packagesHighlights = $defaults['packages_highlights'];
}
$packagesHighlights = array_values($packagesHighlights);
$dealerHighlights = $content['dealer_highlights'] ?? $defaults['dealer_highlights'];
if (!is_array($dealerHighlights)) {
  $dealerHighlights = $defaults['dealer_highlights'];
}
$dealerHighlights = array_values($dealerHighlights);
$testimonials = $content['testimonials'] ?? $defaults['testimonials'];
if (!is_array($testimonials)) {
  $testimonials = $defaults['testimonials'];
}
$testimonials = array_values($testimonials);
$leadFormBullets = $content['lead_form_bullets'] ?? $defaults['lead_form_bullets'];
if (!is_array($leadFormBullets)) {
  $leadFormBullets = $defaults['lead_form_bullets'];
}
$leadFormBullets = array_values($leadFormBullets);
$blogPosts = $content['blog_posts'] ?? $defaults['blog_posts'];
if (!is_array($blogPosts)) {
  $blogPosts = $defaults['blog_posts'];
}
$blogPosts = array_values($blogPosts);
$blogPosts = array_map(function ($post) {
  if (!is_array($post)) {
    $post = [];
  }
  $gallery = [];
  if (!empty($post['gallery']) && is_array($post['gallery'])) {
    foreach ($post['gallery'] as $item) {
      $item = trim((string)$item);
      if ($item !== '') {
        $gallery[] = $item;
      }
    }
  } elseif (!empty($post['gallery']) && is_string($post['gallery'])) {
    $lines = preg_split('~[\r\n]+~', $post['gallery']) ?: [];
    foreach ($lines as $line) {
      $line = trim((string)$line);
      if ($line !== '') {
        $gallery[] = $line;
      }
    }
  }
  $published = $post['published_at'] ?? '';
  $published = site_normalize_blog_date($published) ?? trim((string)$published);
  return [
    'title' => trim((string)($post['title'] ?? '')),
    'description' => trim((string)($post['description'] ?? '')),
    'slug' => trim((string)($post['slug'] ?? '')),
    'image' => trim((string)($post['image'] ?? ($post['hero_image'] ?? ''))),
    'content' => trim((string)($post['content'] ?? ($post['body'] ?? ''))),
    'published_at' => $published,
    'gallery' => $gallery,
  ];
}, $blogPosts);

while (count($faqItems) < 4) {
  $faqItems[] = ['question' => '', 'answer' => ''];
}
while (count($navItems) < 5) {
  $navItems[] = ['label' => '', 'url' => ''];
}
while (count($heroMetrics) < 3) {
  $heroMetrics[] = ['value' => '', 'label' => ''];
}
while (count($aboutFeatures) < 2) {
  $aboutFeatures[] = ['title' => '', 'text' => ''];
}
while (count($featureBlocks) < 3) {
  $featureBlocks[] = ['icon' => '', 'title' => '', 'text' => ''];
}
while (count($timelineSteps) < 3) {
  $timelineSteps[] = ['title' => '', 'text' => ''];
}
while (count($packagesHighlights) < 4) {
  $packagesHighlights[] = '';
}
while (count($dealerHighlights) < 3) {
  $dealerHighlights[] = '';
}
while (count($testimonials) < 2) {
  $testimonials[] = ['quote' => '', 'author' => '', 'role' => ''];
}
while (count($leadFormBullets) < 3) {
  $leadFormBullets[] = '';
}
while (count($blogPosts) < 3) {
  $blogPosts[] = [
    'title' => '',
    'description' => '',
    'slug' => '',
    'image' => '',
    'content' => '',
    'published_at' => '',
    'gallery' => [],
  ];
}

$paytrEnabledSetting = (int)($content['paytr_enabled'] ?? '0') === 1;
$paytrTestModeSetting = (int)($content['paytr_test_mode'] ?? '1') === 1;
$paytrConfigLive = site_payment_config(true);
$paytrActiveNow = !empty($paytrConfigLive['enabled']);
$paytrModeNow = (int)($paytrConfigLive['test_mode'] ?? 1) === 1;
$paytrStatusBadge = $paytrActiveNow ? 'text-bg-success' : 'text-bg-warning';
$paytrStatusText = $paytrActiveNow ? 'Aktif' : 'Pasif';
$paytrModeText = $paytrModeNow ? 'Test Modu' : 'Canlı Mod';
$paytrCurrentId = trim((string)($paytrConfigLive['merchant_id'] ?? ''));
$whatsappEnabledSetting = (int)($content['whatsapp_api_enabled'] ?? '0') === 1;
$whatsappConfigLive = site_whatsapp_config(true);
$whatsappActiveNow = whatsapp_is_enabled();
$whatsappStatusBadge = $whatsappActiveNow ? 'text-bg-success' : 'text-bg-warning';
$whatsappStatusText = $whatsappActiveNow ? 'Aktif' : 'Pasif';
$whatsappCurrentUrl = trim((string)($whatsappConfigLive['api_url'] ?? ''));
$whatsappCurrentSender = trim((string)($whatsappConfigLive['sender'] ?? ''));

?>
<!doctype html>
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
    .media-preview .btn-outline-danger{border-radius:12px;}
    .media-preview .btn-outline-danger i{margin-right:.35rem;}
    .media-gallery-grid{display:grid;gap:1rem;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));}
    .media-gallery-item{background:#f8fafc;border-radius:16px;padding:.75rem;border:1px solid rgba(148,163,184,.25);display:flex;flex-direction:column;gap:.5rem;}
    .media-gallery-item img{border-radius:12px;width:100%;height:120px;object-fit:cover;box-shadow:0 12px 28px rgba(15,118,110,.15);}
    .media-gallery-item .btn{border-radius:12px;}
    .blog-grid{display:flex;flex-direction:column;gap:1.25rem;}
    .blog-empty-state{display:flex;gap:1rem;align-items:center;padding:1.5rem;border:1.5px dashed rgba(14,165,181,.35);border-radius:18px;background:rgba(14,165,181,.05);}
    .blog-empty-illustration{width:60px;height:60px;border-radius:16px;background:rgba(14,165,181,.12);display:flex;align-items:center;justify-content:center;color:var(--admin-brand);font-size:1.6rem;}
    .blog-entry{border-radius:20px;border:1px solid rgba(148,163,184,.28);background:#fff;box-shadow:0 35px 80px -60px rgba(15,23,42,.45);overflow:hidden;transition:box-shadow .2s ease,transform .2s ease;}
    .blog-entry:hover{box-shadow:0 35px 90px -55px rgba(15,23,42,.55);transform:translateY(-2px);}
    .blog-entry-header{display:flex;align-items:center;gap:1.25rem;padding:1.25rem 1.5rem;background:linear-gradient(135deg,rgba(14,165,181,.08),rgba(14,165,181,.02));}
    .blog-entry.is-open .blog-entry-header{border-bottom:1px solid rgba(148,163,184,.25);}
    .blog-entry-thumb{width:82px;height:82px;border-radius:18px;background:rgba(148,163,184,.18);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;position:relative;}
    .blog-entry-thumb img{width:100%;height:100%;object-fit:cover;}
    .blog-entry-thumb-empty{display:flex;align-items:center;justify-content:center;width:100%;height:100%;color:rgba(71,85,105,.65);font-size:1.75rem;}
    .blog-entry-summary{flex:1;min-width:0;}
    .blog-entry-summary .blog-entry-title{font-weight:700;color:var(--admin-ink);}
    .blog-entry-summary p{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .blog-entry-actions{display:flex;flex-direction:column;gap:.5rem;}
    .blog-entry-body{display:none;padding:1.6rem 1.6rem 1.8rem;background:#fff;}
    .blog-entry.is-open .blog-entry-body{display:block;}
    .blog-cover-preview{border:1px dashed rgba(148,163,184,.45);border-radius:18px;height:160px;background:rgba(241,245,249,.7);display:flex;align-items:center;justify-content:center;overflow:hidden;position:relative;}
    .blog-cover-preview img{width:100%;height:100%;object-fit:cover;}
    .blog-cover-placeholder{display:flex;align-items:center;justify-content:center;width:100%;height:100%;color:rgba(100,116,139,.65);font-size:2rem;}
    .blog-gallery-list{display:flex;flex-wrap:wrap;gap:.5rem;}
    .blog-gallery-chip{display:inline-flex;align-items:center;gap:.35rem;padding:.45rem .7rem;border-radius:999px;background:rgba(14,165,181,.15);border:1px solid rgba(14,165,181,.25);color:var(--admin-ink);}
    .blog-gallery-chip .btn-close{width:.8rem;height:.8rem;font-size:.6rem;opacity:.6;}
    .blog-gallery-chip .btn-close:hover{opacity:1;}
    .blog-gallery-adder .form-control{border-radius:10px 0 0 10px;}
    .blog-gallery-adder .btn{border-radius:0 10px 10px 0;}
    .blog-gallery-note{margin-top:.4rem;}
    @media (max-width: 991px){
      .blog-entry-header{flex-direction:column;align-items:flex-start;gap:1rem;}
      .blog-entry-actions{flex-direction:row;width:100%;}
      .blog-entry-actions .btn{flex:1;}
    }
    @media (max-width: 991px){
      .settings-shell{gap:1rem;}
    }
  </style>
</head>
<body class="admin-body">
<?php admin_layout_start('site', 'Site İçerikleri', 'Landing sayfanızdaki blokları düzenleyin ve hızlıca yayınlayın.'); ?>
    <?php flash_box(); ?>
    <form method="post" id="siteContentForm" class="settings-shell" novalidate enctype="multipart/form-data">
      <div class="row g-4 align-items-start">
        <div class="col-lg-4">
          <div class="pane-nav">
            <h6>İçerik Başlıkları</h6>
            <button type="button" class="pane-button active" data-pane-target="contact"><i class="bi bi-person-rolodex"></i>İletişim Bilgileri</button>
            <button type="button" class="pane-button" data-pane-target="hero"><i class="bi bi-stars"></i>Hero &amp; Giriş</button>
            <button type="button" class="pane-button" data-pane-target="about"><i class="bi bi-file-earmark-text"></i>Hakkımızda Bölümü</button>
            <button type="button" class="pane-button" data-pane-target="features"><i class="bi bi-grid-3x3-gap"></i>Özellikler &amp; Akış</button>
            <button type="button" class="pane-button" data-pane-target="packages"><i class="bi bi-box-seam"></i>Paket Bilgileri</button>
            <button type="button" class="pane-button" data-pane-target="dealer"><i class="bi bi-people"></i>Bayi &amp; Yorumlar</button>
            <button type="button" class="pane-button" data-pane-target="gallery"><i class="bi bi-collection"></i>Galeri Başlıkları</button>
            <button type="button" class="pane-button" data-pane-target="lead"><i class="bi bi-ui-checks"></i>Sipariş Formu</button>
            <button type="button" class="pane-button" data-pane-target="blog"><i class="bi bi-journal-text"></i>Blog Bölümü</button>
            <button type="button" class="pane-button" data-pane-target="media"><i class="bi bi-images"></i>Görsel İçerikler</button>
            <button type="button" class="pane-button" data-pane-target="cta"><i class="bi bi-bullseye"></i>Çağrı Alanı</button>
            <button type="button" class="pane-button" data-pane-target="sales"><i class="bi bi-shop"></i>Satış Ayarları</button>
            <button type="button" class="pane-button" data-pane-target="payment"><i class="bi bi-credit-card-2-front"></i>Ödeme Ayarları</button>
            <button type="button" class="pane-button" data-pane-target="whatsapp"><i class="bi bi-whatsapp"></i>WhatsApp API</button>
            <button type="button" class="pane-button" data-pane-target="smtp"><i class="bi bi-envelope-paper"></i>SMTP Ayarları</button>
            <button type="button" class="pane-button" data-pane-target="seo"><i class="bi bi-graph-up"></i>SEO &amp; Meta</button>
            <button type="button" class="pane-button" data-pane-target="faq"><i class="bi bi-chat-dots"></i>Sıkça Sorulanlar</button>
            <button type="button" class="pane-button" data-pane-target="footer"><i class="bi bi-columns-gap"></i>Footer İçeriği</button>
          </div>
        </div>
        <div class="col-lg-8">
          <div class="card card-lite content-pane" data-pane="hero">
            <div class="card-section border-bottom">
              <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
                <div>
                  <h5 class="fw-bold mb-1">Hero &amp; Açılış Metni</h5>
                  <p class="text-muted mb-0">Anasayfa giriş alanındaki başlık, açıklama ve butonları burada güncelleyin.</p>
                </div>
                <i class="bi bi-stars" style="font-size:1.6rem;color:var(--admin-brand);"></i>
              </div>
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label">Hero Rozeti</label>
                  <input type="text" name="hero_badge" class="form-control" value="<?=h($content['hero_badge'] ?? '')?>" placeholder="Örn. Yeni nesil çözüm">
                </div>
                <div class="col-12">
                  <label class="form-label">Hero Başlığı</label>
                  <input type="text" name="hero_title" class="form-control" value="<?=h($content['hero_title'] ?? '')?>" required>
                </div>
                <div class="col-12">
                  <label class="form-label">Hero Açıklaması</label>
                  <textarea name="hero_text" class="form-control" rows="3" placeholder="Hero açıklama metni"><?=h($content['hero_text'] ?? '')?></textarea>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Birincil Buton Metni</label>
                  <input type="text" name="hero_primary_label" class="form-control" value="<?=h($content['hero_primary_label'] ?? '')?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Birincil Buton URL</label>
                  <input type="text" name="hero_primary_url" class="form-control" value="<?=h($content['hero_primary_url'] ?? '')?>" placeholder="#paketler">
                </div>
                <div class="col-md-6">
                  <label class="form-label">İkincil Buton Metni</label>
                  <input type="text" name="hero_secondary_label" class="form-control" value="<?=h($content['hero_secondary_label'] ?? '')?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">İkincil Buton URL</label>
                  <input type="text" name="hero_secondary_url" class="form-control" value="<?=h($content['hero_secondary_url'] ?? '')?>" placeholder="#lead-form">
                </div>
              </div>
            </div>
            <div class="card-section">
              <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
                <div>
                  <h6 class="fw-semibold mb-1">Öne Çıkan Rakamlar</h6>
                  <p class="text-muted small mb-0">Hero alanındaki metrikler için değer ve açıklama girin. Boş satırlar yayınlanmaz.</p>
                </div>
              </div>
              <?php foreach ($heroMetrics as $idx => $metric): ?>
                <div class="row g-3 align-items-end mb-3">
                  <div class="col-md-4">
                    <label class="form-label">Değer</label>
                    <input type="text" class="form-control" name="hero_metric_value[]" value="<?=h($metric['value'])?>" placeholder="Örn. 12.500+">
                  </div>
                  <div class="col-md-8">
                    <label class="form-label">Açıklama</label>
                    <input type="text" class="form-control" name="hero_metric_label[]" value="<?=h($metric['label'])?>" placeholder="Örn. Toplanan içerikler">
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="card card-lite content-pane" data-pane="about">
            <div class="card-section border-bottom">
              <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
                <div>
                  <h5 class="fw-bold mb-1">Hakkımızda Bölümü</h5>
                  <p class="text-muted mb-0">"Hakkımızda" alanındaki başlık, açıklama ve öne çıkan maddeleri düzenleyin.</p>
                </div>
                <i class="bi bi-file-earmark-text" style="font-size:1.5rem;color:var(--admin-brand);"></i>
              </div>
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label">Rozet</label>
                  <input type="text" name="about_badge" class="form-control" value="<?=h($content['about_badge'] ?? '')?>">
                </div>
                <div class="col-12">
                  <label class="form-label">Başlık</label>
                  <input type="text" name="about_title" class="form-control" value="<?=h($content['about_title'] ?? '')?>">
                </div>
                <div class="col-12">
                  <label class="form-label">Açıklama</label>
                  <textarea name="about_text" class="form-control" rows="4" placeholder="Bölüm açıklaması"><?=h($content['about_text'] ?? '')?></textarea>
                </div>
              </div>
            </div>
            <div class="card-section">
              <h6 class="fw-semibold mb-3">Öne Çıkan Özellikler</h6>
              <div class="row g-3">
                <?php foreach ($aboutFeatures as $idx => $feature): ?>
                  <div class="col-md-6">
                    <div class="repeater-item h-100">
                      <label class="form-label">Başlık</label>
                      <input type="text" class="form-control mb-2" name="about_feature_title[]" value="<?=h($feature['title'])?>" placeholder="Örn. Profesyonel destek">
                      <label class="form-label">Açıklama</label>
                      <textarea class="form-control" name="about_feature_text[]" rows="3" placeholder="Kısa açıklama"><?=h($feature['text'])?></textarea>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <div class="card card-lite content-pane" data-pane="features">
            <div class="card-section border-bottom">
              <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
                <div>
                  <h5 class="fw-bold mb-1">Özellik Kartları</h5>
                  <p class="text-muted mb-0">Hero altındaki üçlü özellik kartlarını özelleştirin. Emoji veya kısa ikon ifadeleri kullanabilirsiniz.</p>
                </div>
                <i class="bi bi-grid-3x3-gap" style="font-size:1.5rem;color:var(--admin-brand);"></i>
              </div>
              <?php foreach ($featureBlocks as $idx => $block): ?>
                <div class="repeater-item mb-3">
                  <div class="row g-3">
                    <div class="col-md-2">
                      <label class="form-label">İkon</label>
                      <input type="text" class="form-control" name="feature_icon[]" value="<?=h($block['icon'])?>" placeholder="Örn. 📸">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Başlık</label>
                      <input type="text" class="form-control" name="feature_title[]" value="<?=h($block['title'])?>">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Açıklama</label>
                      <textarea class="form-control" name="feature_text[]" rows="2" placeholder="Özellik açıklaması"><?=h($block['text'])?></textarea>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="card-section">
              <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
                <div>
                  <h5 class="fw-bold mb-1">Zaman Çizelgesi</h5>
                  <p class="text-muted mb-0">"BİKARE nasıl çalışır?" bölümündeki başlık ve adımları düzenleyin.</p>
                </div>
                <i class="bi bi-list-ol" style="font-size:1.5rem;color:var(--admin-brand);"></i>
              </div>
              <div class="row g-3 mb-3">
                <div class="col-md-6">
                  <label class="form-label">Bölüm Başlığı</label>
                  <input type="text" name="timeline_title" class="form-control" value="<?=h($content['timeline_title'] ?? '')?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Bölüm Açıklaması</label>
                  <textarea name="timeline_text" class="form-control" rows="2"><?=h($content['timeline_text'] ?? '')?></textarea>
                </div>
              </div>
              <?php foreach ($timelineSteps as $idx => $step): ?>
                <div class="repeater-item mb-3">
                  <div class="row g-3">
                    <div class="col-md-4">
                      <label class="form-label">Adım Başlığı</label>
                      <input type="text" class="form-control" name="timeline_step_title[]" value="<?=h($step['title'])?>" placeholder="Adım <?=h((string)($idx + 1))?>">
                    </div>
                    <div class="col-md-8">
                      <label class="form-label">Adım Açıklaması</label>
                      <textarea class="form-control" name="timeline_step_text[]" rows="2" placeholder="Açıklama"><?=h($step['text'])?></textarea>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="card card-lite content-pane" data-pane="packages">
            <div class="card-section">
              <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
                <div>
                  <h5 class="fw-bold mb-1">Paketler Bölümü</h5>
                  <p class="text-muted mb-0">Paket listesi üstündeki başlık, açıklama ve vurgulu maddeleri güncelleyin.</p>
                </div>
                <i class="bi bi-box-seam" style="font-size:1.5rem;color:var(--admin-brand);"></i>
              </div>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Bölüm Başlığı</label>
                  <input type="text" name="packages_title" class="form-control" value="<?=h($content['packages_title'] ?? '')?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Bölüm Açıklaması</label>
                  <textarea name="packages_text" class="form-control" rows="2"><?=h($content['packages_text'] ?? '')?></textarea>
                </div>
              </div>
              <div class="row g-3 mt-1">
                <?php foreach ($packagesHighlights as $highlight): ?>
                  <div class="col-md-6">
                    <label class="form-label">Öne Çıkan Madde</label>
                    <input type="text" class="form-control" name="packages_highlight[]" value="<?=h($highlight)?>" placeholder="Örn. Otomatik panel kurulumu">
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <div class="card card-lite content-pane" data-pane="dealer">
            <div class="card-section border-bottom">
              <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
                <div>
                  <h5 class="fw-bold mb-1">Bayi Ağı Bölümü</h5>
                  <p class="text-muted mb-0">Bayi vitrini metinlerini ve aksiyon butonunu özelleştirin.</p>
                </div>
                <i class="bi bi-people" style="font-size:1.5rem;color:var(--admin-brand);"></i>
              </div>
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label">Rozet</label>
                  <input type="text" name="dealer_badge" class="form-control" value="<?=h($content['dealer_badge'] ?? '')?>">
                </div>
                <div class="col-12">
                  <label class="form-label">Başlık</label>
                  <input type="text" name="dealer_title" class="form-control" value="<?=h($content['dealer_title'] ?? '')?>">
                </div>
                <div class="col-12">
                  <label class="form-label">Açıklama</label>
                  <textarea name="dealer_text" class="form-control" rows="4"><?=h($content['dealer_text'] ?? '')?></textarea>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Buton Metni</label>
                  <input type="text" name="dealer_button_label" class="form-control" value="<?=h($content['dealer_button_label'] ?? '')?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Buton URL</label>
                  <input type="text" name="dealer_button_url" class="form-control" value="<?=h($content['dealer_button_url'] ?? '')?>" placeholder="dealer/apply.php">
                </div>
              </div>
            </div>
            <div class="card-section border-bottom">
              <h6 class="fw-semibold mb-3">Bayi Avantajları</h6>
              <div class="row g-3">
                <?php foreach ($dealerHighlights as $highlight): ?>
                  <div class="col-md-6">
                    <label class="form-label">Madde</label>
                    <input type="text" class="form-control" name="dealer_highlight[]" value="<?=h($highlight)?>" placeholder="Örn. Detaylı raporlama">
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="card-section">
              <h6 class="fw-semibold mb-3">Referans Yorumları</h6>
              <?php foreach ($testimonials as $testimonial): ?>
                <div class="repeater-item mb-3">
                  <div class="row g-3">
                    <div class="col-12">
                      <label class="form-label">Alıntı</label>
                      <textarea class="form-control" name="testimonial_quote[]" rows="2" placeholder="Müşteri yorumu"><?=h($testimonial['quote'])?></textarea>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">İsim</label>
                      <input type="text" class="form-control" name="testimonial_author[]" value="<?=h($testimonial['author'])?>">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Unvan / Etkinlik</label>
                      <input type="text" class="form-control" name="testimonial_role[]" value="<?=h($testimonial['role'])?>">
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="card card-lite content-pane" data-pane="gallery">
            <div class="card-section">
              <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
                <div>
                  <h5 class="fw-bold mb-1">Galeri Başlıkları</h5>
                  <p class="text-muted mb-0">Galeri bölümünde görünen başlık ve açıklama metnini düzenleyin.</p>
                </div>
                <i class="bi bi-collection" style="font-size:1.5rem;color:var(--admin-brand);"></i>
              </div>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Başlık</label>
                  <input type="text" name="gallery_title" class="form-control" value="<?=h($content['gallery_title'] ?? '')?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Açıklama</label>
                  <textarea name="gallery_text" class="form-control" rows="3"><?=h($content['gallery_text'] ?? '')?></textarea>
                </div>
              </div>
            </div>
          </div>

          <div class="card card-lite content-pane" data-pane="lead">
            <div class="card-section">
              <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
                <div>
                  <h5 class="fw-bold mb-1">Sipariş Formu Alanı</h5>
                  <p class="text-muted mb-0">Sipariş formu yanında gösterilen metin ve bilgilendirici maddeleri düzenleyin.</p>
                </div>
                <i class="bi bi-ui-checks" style="font-size:1.5rem;color:var(--admin-brand);"></i>
              </div>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Form Başlığı</label>
                  <input type="text" name="lead_form_title" class="form-control" value="<?=h($content['lead_form_title'] ?? '')?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Form Açıklaması</label>
                  <textarea name="lead_form_text" class="form-control" rows="3"><?=h($content['lead_form_text'] ?? '')?></textarea>
                </div>
              </div>
              <div class="row g-3 mt-1">
                <?php foreach ($leadFormBullets as $bullet): ?>
                  <div class="col-md-6">
                    <label class="form-label">Bilgilendirme Maddesi</label>
                    <input type="text" class="form-control" name="lead_form_bullet[]" value="<?=h($bullet)?>">
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="row g-3 mt-1">
                <div class="col-md-6">
                  <label class="form-label">Alt Bilgi (Formu Gönder)</label>
                  <input type="text" name="lead_form_notice" class="form-control" value="<?=h($content['lead_form_notice'] ?? '')?>" placeholder="Formu gönderdiğinizde ...">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Buton Metni</label>
                  <input type="text" name="lead_form_submit_label" class="form-control" value="<?=h($content['lead_form_submit_label'] ?? '')?>" placeholder="Ödeme Adımına Geç">
                </div>
          </div>
        </div>
      </div>

      <div class="card card-lite content-pane" data-pane="blog">
        <div class="card-section border-bottom">
          <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
            <div>
              <h5 class="fw-bold mb-1">Blog Bölümü</h5>
              <p class="text-muted mb-0">Anasayfadaki blog ve kaynaklar alanı için başlık, açıklama ve bağlantıları yönetin.</p>
            </div>
            <i class="bi bi-journal-text" style="font-size:1.6rem;color:var(--admin-brand);"></i>
          </div>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Rozet</label>
              <input type="text" name="blog_section_badge" class="form-control" value="<?=h($content['blog_section_badge'] ?? '')?>" placeholder="Örn. Blog">
            </div>
            <div class="col-md-8">
              <label class="form-label">Başlık</label>
              <input type="text" name="blog_section_title" class="form-control" value="<?=h($content['blog_section_title'] ?? '')?>" placeholder="Örn. BİKARE Blog &amp; Kaynaklar">
            </div>
            <div class="col-12">
              <label class="form-label">Açıklama</label>
              <textarea name="blog_section_text" class="form-control" rows="3" placeholder="Kısa açıklama metni"><?=h($content['blog_section_text'] ?? '')?></textarea>
            </div>
          </div>
        </div>
        <div class="card-section">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
            <div>
              <h6 class="fw-semibold mb-1">Blog Yazıları</h6>
              <p class="text-muted small mb-0">Görselleri yükleyip meta alanlarını doldurarak yeni yazıları kolayca düzenleyin. Modal pencereden yeni yazı oluşturabilirsiniz.</p>
            </div>
            <button type="button" class="btn btn-sm btn-brand" data-blog-open-modal><i class="bi bi-plus-lg me-1"></i>Yeni Blog Yazısı</button>
          </div>
          <?php
            $blogLoopIndex = 0;
          ?>
          <div class="blog-empty-state<?=empty($blogPosts) ? '' : ' d-none'?>" data-blog-empty>
            <div class="blog-empty-illustration">
              <i class="bi bi-journal-richtext"></i>
            </div>
            <div>
              <h6 class="fw-semibold mb-1">Henüz blog yazısı eklenmedi</h6>
              <p class="text-muted mb-0">Yeni bir içerik oluşturmak için yukarıdaki &ldquo;Yeni Blog Yazısı&rdquo; butonunu kullanın.</p>
            </div>
          </div>
          <div data-repeater="blog" class="blog-grid" data-blog-next-index="<?=count($blogPosts)?>">
            <?php foreach ($blogPosts as $post):
              $postTitle = trim((string)($post['title'] ?? ''));
              $postDescription = trim((string)($post['description'] ?? ''));
              $postSlug = trim((string)($post['slug'] ?? ''));
              $postImage = trim((string)($post['image'] ?? ''));
              $postPublished = trim((string)($post['published_at'] ?? ''));
              $postContent = trim((string)($post['content'] ?? ''));
              $postGalleryItems = [];
              if (!empty($post['gallery']) && is_array($post['gallery'])) {
                foreach ($post['gallery'] as $galleryItem) {
                  $galleryItem = trim((string)$galleryItem);
                  if ($galleryItem !== '') {
                    $postGalleryItems[] = $galleryItem;
                  }
                }
              }
              $postGalleryValue = implode("\n", $postGalleryItems);
              $publishedDisplay = 'Taslak';
              if ($postPublished !== '') {
                $ts = strtotime($postPublished);
                $publishedDisplay = $ts ? date('d.m.Y', $ts) : $postPublished;
              }
              $postSummaryTitle = $postTitle !== '' ? $postTitle : 'Yeni blog yazısı';
              $postSummarySlug = $postSlug !== '' ? $postSlug : 'Slug henüz oluşturulmadı';
              $isOpen = $blogLoopIndex === 0 ? ' is-open' : '';
            ?>
              <div class="blog-entry<?=$isOpen?>" data-blog-entry data-blog-index="<?=$blogLoopIndex?>">
                <div class="blog-entry-header">
                  <div class="blog-entry-thumb" data-blog-cover>
                    <img src="<?=h($postImage)?>" alt="Blog kapak görseli" class="<?=$postImage !== '' ? '' : 'd-none'?>" loading="lazy">
                    <div class="blog-entry-thumb-empty<?=$postImage !== '' ? ' d-none' : ''?>"><i class="bi bi-image"></i></div>
                  </div>
                  <div class="blog-entry-summary">
                    <span class="badge rounded-pill text-bg-light blog-entry-status" data-blog-summary-status><?=h($postPublished !== '' ? 'Yayın Tarihi: '.$publishedDisplay : 'Taslak')?></span>
                    <h6 class="mb-1 blog-entry-title" data-blog-summary-title><?=h($postSummaryTitle)?></h6>
                    <p class="text-muted small mb-0" data-blog-summary-slug><?=h($postSummarySlug)?></p>
                  </div>
                  <div class="blog-entry-actions">
                    <button type="button" class="btn btn-sm btn-outline-primary" data-blog-toggle>Detayları <?= $isOpen ? 'Gizle' : 'Göster' ?></button>
                    <button type="button" class="btn btn-sm btn-outline-danger" data-blog-remove><i class="bi bi-trash"></i></button>
                  </div>
                </div>
                <div class="blog-entry-body">
                  <div class="row g-3">
                    <div class="col-lg-8">
                      <label class="form-label">Başlık</label>
                      <input type="text" class="form-control" name="blog_title[<?=$blogLoopIndex?>]" value="<?=h($postTitle)?>" placeholder="Blog başlığı" data-blog-title>
                    </div>
                    <div class="col-lg-4">
                      <label class="form-label">Slug</label>
                      <input type="text" class="form-control" name="blog_slug[<?=$blogLoopIndex?>]" value="<?=h($postSlug)?>" placeholder="ornek-yazi" data-blog-slug data-slug-auto="<?=$postSlug === '' ? '1' : '0'?>">
                      <div class="form-text">Boş bırakılırsa başlıktan otomatik oluşturulur.</div>
                    </div>
                    <div class="col-lg-4">
                      <label class="form-label">Yayın Tarihi</label>
                      <input type="date" class="form-control" name="blog_published_at[<?=$blogLoopIndex?>]" value="<?=h($postPublished)?>" data-blog-date>
                    </div>
                    <div class="col-lg-8">
                      <label class="form-label">Kısa Açıklama</label>
                      <textarea class="form-control" name="blog_description[<?=$blogLoopIndex?>]" rows="2" placeholder="Özet veya spot metni" data-blog-description><?=h($postDescription)?></textarea>
                    </div>
                  </div>
                  <hr class="my-4">
                  <div class="row g-3 align-items-start">
                    <div class="col-md-4">
                      <div class="blog-cover-preview" data-blog-cover-preview>
                        <img src="<?=h($postImage)?>" alt="Blog kapak görseli" class="<?=$postImage !== '' ? '' : 'd-none'?>">
                        <div class="blog-cover-placeholder<?=$postImage !== '' ? ' d-none' : ''?>"><i class="bi bi-image"></i></div>
                      </div>
                    </div>
                    <div class="col-md-8">
                      <label class="form-label">Kapak Görseli</label>
                      <input type="hidden" name="blog_image[<?=$blogLoopIndex?>]" value="<?=h($postImage)?>" data-blog-image>
                      <div class="input-group input-group-sm">
                        <span class="input-group-text">URL</span>
                        <input type="text" class="form-control" placeholder="https://..." value="<?=h($postImage)?>" data-blog-image-url>
                      </div>
                      <div class="d-flex flex-wrap gap-2 mt-3">
                        <input type="file" class="d-none" name="blog_image_upload[<?=$blogLoopIndex?>]" accept="image/*" data-blog-image-file>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-blog-image-browse><i class="bi bi-upload"></i> Görsel Yükle</button>
                        <button type="button" class="btn btn-sm btn-outline-danger" data-blog-image-remove><i class="bi bi-x"></i> Görseli Kaldır</button>
                      </div>
                      <div class="form-text mt-2">Harici bağlantı girebilir veya yeni görsel yükleyebilirsiniz. Yüklediğiniz görsel kaydedildiğinde önizleme güncellenir.</div>
                    </div>
                  </div>
                  <div class="mt-4">
                    <label class="form-label">İçerik</label>
                    <textarea class="form-control" name="blog_content[<?=$blogLoopIndex?>]" rows="6" placeholder="Makale metni" data-blog-content><?=h($postContent)?></textarea>
                    <div class="form-text">Paragraflar arasında boş satır bırakarak yeni paragraf oluşturabilirsiniz.</div>
                  </div>
                  <div class="mt-4 blog-gallery" data-blog-gallery>
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                      <div>
                        <label class="form-label mb-1">Galeri Görselleri</label>
                        <p class="text-muted small mb-0">URL ekleyin veya görsel yükleyin. Kaydettiğinizde galeri güncellenir.</p>
                      </div>
                      <button type="button" class="btn btn-sm btn-outline-secondary" data-blog-gallery-browse><i class="bi bi-images"></i> Görsel Yükle</button>
                    </div>
                    <input type="file" class="d-none" name="blog_gallery_upload[<?=$blogLoopIndex?>][]" accept="image/*" multiple data-blog-gallery-file>
                    <div class="blog-gallery-list" data-blog-gallery-list>
                      <?php foreach ($postGalleryItems as $galleryItem): ?>
                        <span class="blog-gallery-chip" data-gallery-item>
                          <span class="blog-gallery-chip-label"><?=h($galleryItem)?></span>
                          <button type="button" class="btn-close" aria-label="Sil" data-gallery-remove></button>
                        </span>
                      <?php endforeach; ?>
                    </div>
                    <div class="input-group input-group-sm blog-gallery-adder mt-3">
                      <input type="text" class="form-control" placeholder="https://..." data-blog-gallery-input>
                      <button type="button" class="btn btn-outline-primary" data-blog-gallery-add>Ekle</button>
                    </div>
                    <div class="blog-gallery-note text-muted small d-none" data-gallery-upload-note></div>
                    <div class="form-text mt-2">Yüklenen dosyalar kaydettikten sonra galeri listesine eklenir.</div>
                    <textarea class="d-none" name="blog_gallery[<?=$blogLoopIndex?>]" data-blog-gallery-storage><?=h($postGalleryValue)?></textarea>
                  </div>
                </div>
              </div>
            <?php $blogLoopIndex++; endforeach; ?>
          </div>
        </div>
      </div>

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
              if (!site_content_asset_exists($siteLogoCurrent)) {
                $siteLogoCurrent = $defaults['site_logo'];
              }
              $heroMainImage = $content['hero_image_main'] ?? $defaults['hero_image_main'];
              if (!site_content_asset_exists($heroMainImage)) {
                $heroMainImage = $defaults['hero_image_main'];
              }
              $heroSecondaryImage = $content['hero_image_secondary'] ?? $defaults['hero_image_secondary'];
              if (!site_content_asset_exists($heroSecondaryImage)) {
                $heroSecondaryImage = $defaults['hero_image_secondary'];
              }
              $aboutImageCurrent = $content['about_image'] ?? $defaults['about_image'];
              if (!site_content_asset_exists($aboutImageCurrent)) {
                $aboutImageCurrent = $defaults['about_image'];
              }
              $dealerImageCurrent = $content['dealer_showcase_image'] ?? $defaults['dealer_showcase_image'];
              if (!site_content_asset_exists($dealerImageCurrent)) {
                $dealerImageCurrent = $defaults['dealer_showcase_image'];
              }
              $galleryImages = $content['gallery_images'] ?? $defaults['gallery_images'];
              if (!is_array($galleryImages)) {
                $galleryImages = $defaults['gallery_images'];
              }
              $galleryImages = array_values(array_filter($galleryImages, function ($image) {
                if (!is_string($image)) {
                  return false;
                }
                $image = trim($image);
                if ($image === '') {
                  return false;
                }
                return site_content_asset_exists($image);
              }));
              if (!$galleryImages) {
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
                    <input type="hidden" name="site_logo_remove" value="0">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                      <input type="file" name="site_logo" class="form-control" accept="image/*">
                      <?php if (!empty($content['site_logo'])): ?>
                        <button type="button" class="btn btn-outline-danger btn-sm js-remove-asset" data-remove-field="site_logo_remove" data-confirm="Site logosunu silmek istediğinize emin misiniz?">
                          <i class="bi bi-trash3"></i>
                          Görseli Sil
                        </button>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="media-preview">
                    <div>
                      <strong>Hero Görseli 1</strong>
                      <p class="text-muted small mb-0">Önerilen boyut: 1200x900px. JPG veya WEBP formatında yükleyin.</p>
                    </div>
                    <img src="<?=h($heroMainImage)?>" alt="Hero görseli 1" loading="lazy">
                    <input type="hidden" name="hero_image_main_remove" value="0">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                      <input type="file" name="hero_image_main" class="form-control" accept="image/*">
                      <?php if (!empty($content['hero_image_main'])): ?>
                        <button type="button" class="btn btn-outline-danger btn-sm js-remove-asset" data-remove-field="hero_image_main_remove" data-confirm="Bu hero görselini silmek istediğinize emin misiniz?">
                          <i class="bi bi-trash3"></i>
                          Görseli Sil
                        </button>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="media-preview">
                    <div>
                      <strong>Hero Görseli 2</strong>
                      <p class="text-muted small mb-0">Hero bölümündeki ikinci görsel. Önerilen boyut: 900x900px.</p>
                    </div>
                    <img src="<?=h($heroSecondaryImage)?>" alt="Hero görseli 2" loading="lazy">
                    <input type="hidden" name="hero_image_secondary_remove" value="0">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                      <input type="file" name="hero_image_secondary" class="form-control" accept="image/*">
                      <?php if (!empty($content['hero_image_secondary'])): ?>
                        <button type="button" class="btn btn-outline-danger btn-sm js-remove-asset" data-remove-field="hero_image_secondary_remove" data-confirm="Bu hero görselini silmek istediğinize emin misiniz?">
                          <i class="bi bi-trash3"></i>
                          Görseli Sil
                        </button>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
                <div class="col-12">
                  <div class="media-preview">
                    <div>
                      <strong>Hakkımızda Görseli</strong>
                      <p class="text-muted small mb-0">"Hakkımızda" bölümünde kullanılan görsel. Önerilen boyut: 1200x900px.</p>
                    </div>
                    <img src="<?=h($aboutImageCurrent)?>" alt="Hakkımızda görseli" loading="lazy">
                    <input type="hidden" name="about_image_remove" value="0">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                      <input type="file" name="about_image" class="form-control" accept="image/*">
                      <?php if (!empty($content['about_image'])): ?>
                        <button type="button" class="btn btn-outline-danger btn-sm js-remove-asset" data-remove-field="about_image_remove" data-confirm="Hakkımızda görselini silmek istediğinize emin misiniz?">
                          <i class="bi bi-trash3"></i>
                          Görseli Sil
                        </button>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
                <div class="col-12">
                  <div class="media-preview">
                    <div>
                      <strong>Bayi Paneli Görseli</strong>
                      <p class="text-muted small mb-0">"Bayi Ağı" bölümünde kullanılan tanıtım görseli. Önerilen boyut: 1200x900px.</p>
                    </div>
                    <img src="<?=h($dealerImageCurrent)?>" alt="Bayi paneli görseli" loading="lazy">
                    <input type="hidden" name="dealer_showcase_image_remove" value="0">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                      <input type="file" name="dealer_showcase_image" class="form-control" accept="image/*">
                      <?php if (!empty($content['dealer_showcase_image'])): ?>
                        <button type="button" class="btn btn-outline-danger btn-sm js-remove-asset" data-remove-field="dealer_showcase_image_remove" data-confirm="Bayi paneli görselini silmek istediğinize emin misiniz?">
                          <i class="bi bi-trash3"></i>
                          Görseli Sil
                        </button>
                      <?php endif; ?>
                    </div>
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
                        <button type="button" class="btn btn-outline-danger btn-sm w-100 js-remove-gallery" data-remove-value="<?=h($image)?>" data-confirm="Bu galeri görselini silmek istediğinize emin misiniz?">
                          <i class="bi bi-trash3"></i>
                          Görseli Sil
                        </button>
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

          <div class="card card-lite content-pane" data-pane="sales">
            <div class="card-section border-bottom">
              <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
                <div>
                  <h5 class="fw-bold mb-1">Satış Ayarları</h5>
                  <p class="text-muted mb-0">Web siparişlerinde referans kodu boş bırakıldığında atanacak varsayılan bayi kodunu belirleyin.</p>
                </div>
                <i class="bi bi-shop" style="font-size:1.5rem;color:var(--admin-brand);"></i>
              </div>
              <div class="mb-3">
                <label class="form-label">Varsayılan Bayi Referans Kodu</label>
                <input type="text" name="default_dealer_referral_code" class="form-control" value="<?=h($content['default_dealer_referral_code'] ?? '')?>" placeholder="Örn. BIKARE01">
                <div class="form-text">Müşteri referans kodu girmezse bu bayi siparişe otomatik atanır.</div>
              </div>
              <?php
                $currentCode = trim((string)($content['default_dealer_referral_code'] ?? ''));
                if ($currentCode !== '') {
                  $currentDealer = dealer_find_by_code($currentCode);
                  if ($currentDealer) {
                    $badgeClass = in_array($currentDealer['status'], [DEALER_STATUS_ACTIVE, DEALER_STATUS_PENDING], true) ? 'text-bg-success' : 'text-bg-warning';
                    $statusLabel = dealer_status_badge($currentDealer['status']);
              ?>
                <div class="alert alert-light border d-flex align-items-center gap-3" role="alert">
                  <div class="flex-grow-1">
                    <div class="fw-semibold"><?=h($currentDealer['name'] ?? ($currentDealer['contact_name'] ?? 'Bayi'))?></div>
                    <div class="small text-muted mb-1">Kod: <?=h($currentCode)?></div>
                    <span class="badge <?=$badgeClass?>"><?=$statusLabel?></span>
                  </div>
                  <i class="bi bi-people-fill fs-4 text-secondary"></i>
                </div>
              <?php
                  } else {
              ?>
                <div class="alert alert-warning" role="alert">
                  Bu kod herhangi bir bayi ile eşleşmediği için siparişlerde kullanılmayacak.
                </div>
              <?php
                  }
                }
              ?>
            </div>
          </div>

          <div class="card card-lite content-pane" data-pane="payment">
            <div class="card-section border-bottom">
              <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
                <div>
                  <h5 class="fw-bold mb-1">Ödeme Ayarları</h5>
                  <p class="text-muted mb-0">PayTR entegrasyon anahtarlarını girin ve çevrimiçi ödemeleri yönetin.</p>
                </div>
                <i class="bi bi-credit-card-2-front" style="font-size:1.5rem;color:var(--admin-brand);"></i>
              </div>
              <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="badge <?=$paytrStatusBadge?>">Durum: <?=$paytrStatusText?></span>
                <span class="badge text-bg-info-subtle text-info-emphasis">Mod: <?=$paytrModeText?></span>
                <?php if ($paytrCurrentId !== ''): ?>
                  <span class="badge text-bg-light text-secondary">Aktif Merchant ID: <?=h($paytrCurrentId)?></span>
                <?php endif; ?>
              </div>
              <?php if (!$paytrActiveNow): ?>
                <div class="alert alert-warning mt-3 mb-0" role="alert">
                  Online ödeme şu anda ziyaretçilere kapalı. Anahtarları güncelleyip durumu aktifleştirdiğinizde sipariş sayfası PayTR üzerinden tahsilat almaya başlar.
                </div>
              <?php endif; ?>
            </div>
            <div class="card-section">
              <div class="row g-3">
                <div class="col-12">
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" value="1" id="paytr_enabled" name="paytr_enabled" <?=$paytrEnabledSetting ? 'checked' : ''?>>
                    <label class="form-check-label" for="paytr_enabled">Online ödemeyi aktifleştir</label>
                  </div>
                  <div class="form-text">Anahtar alanlarını boş bırakırsanız mevcut .env değerleri kullanılmaya devam eder.</div>
                </div>
                <div class="col-md-4">
                  <label class="form-label">PayTR Merchant ID</label>
                  <input type="text" class="form-control" name="paytr_merchant_id" value="<?=h($content['paytr_merchant_id'] ?? '')?>" autocomplete="off" spellcheck="false">
                </div>
                <div class="col-md-4">
                  <label class="form-label">PayTR Merchant Key</label>
                  <input type="text" class="form-control" name="paytr_merchant_key" value="<?=h($content['paytr_merchant_key'] ?? '')?>" autocomplete="off" spellcheck="false">
                </div>
                <div class="col-md-4">
                  <label class="form-label">PayTR Merchant Salt</label>
                  <input type="text" class="form-control" name="paytr_merchant_salt" value="<?=h($content['paytr_merchant_salt'] ?? '')?>" autocomplete="off" spellcheck="false">
                </div>
                <div class="col-md-4">
                  <label class="form-label">Çalışma Modu</label>
                  <select class="form-select" name="paytr_test_mode">
                    <option value="1" <?=$paytrTestModeSetting ? 'selected' : ''?>>Test Modu (Sandbox)</option>
                    <option value="0" <?=!$paytrTestModeSetting ? 'selected' : ''?>>Canlı Mod (Gerçek Tahsilat)</option>
                  </select>
                </div>
                <div class="col-12">
                  <div class="alert alert-info mb-0 small" role="alert">
                    PayTR test modunda ödemeler otomatik onaylanır. Canlı moda geçmeden önce PayTR panelinizde mağaza ayarlarınızı ve izinlerinizi doğrulayın.
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="card card-lite content-pane" data-pane="whatsapp">
            <div class="card-section border-bottom">
              <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
                <div>
                  <h5 class="fw-bold mb-1">WhatsApp API</h5>
                  <p class="text-muted mb-0">Pazarlama panelinden toplu WhatsApp gönderimi yapabilmek için servis bilgilerinizi girin.</p>
                </div>
                <i class="bi bi-whatsapp" style="font-size:1.6rem;color:#25d366;"></i>
              </div>
              <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="badge <?=$whatsappStatusBadge?>">Durum: <?=$whatsappStatusText?></span>
                <?php if ($whatsappCurrentUrl !== ''): ?>
                  <span class="badge text-bg-light text-secondary">Aktif URL: <?=h(mb_strimwidth($whatsappCurrentUrl, 0, 42, '…', 'UTF-8'))?></span>
                <?php endif; ?>
                <?php if ($whatsappCurrentSender !== ''): ?>
                  <span class="badge text-bg-success-subtle text-success-emphasis">Gönderen: <?=h($whatsappCurrentSender)?></span>
                <?php endif; ?>
              </div>
              <?php if (!$whatsappActiveNow): ?>
                <div class="alert alert-warning mt-3 mb-0" role="alert">
                  WhatsApp entegrasyonu şu anda pasif. Bilgileri kaydedip aktifleştirdiğinizde mesajlar otomatik olarak API üzerinden gönderilir.
                </div>
              <?php endif; ?>
            </div>
            <div class="card-section">
              <div class="row g-3">
                <div class="col-12">
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" value="1" id="whatsapp_api_enabled" name="whatsapp_api_enabled" <?=$whatsappEnabledSetting ? 'checked' : ''?>>
                    <label class="form-check-label" for="whatsapp_api_enabled">WhatsApp API entegrasyonunu aktifleştir</label>
                  </div>
                  <div class="form-text">URL ve token alanları doldurulduğunda pazarlama ekranından seçilen kişilere anında gönderim yapılır.</div>
                </div>
                <div class="col-md-6">
                  <label class="form-label">API URL</label>
                  <input type="text" class="form-control" name="whatsapp_api_url" value="<?=h($content['whatsapp_api_url'] ?? '')?>" placeholder="https://api.ornek.com/messages">
                </div>
                <div class="col-md-6">
                  <label class="form-label">API Erişim Token</label>
                  <input type="text" class="form-control" name="whatsapp_api_token" value="<?=h($content['whatsapp_api_token'] ?? '')?>" autocomplete="off" spellcheck="false">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Gönderici Kimliği / Numara</label>
                  <input type="text" class="form-control" name="whatsapp_api_sender" value="<?=h($content['whatsapp_api_sender'] ?? '')?>" placeholder="90XXXXXXXXXX">
                  <div class="form-text">API'nizin beklediği WhatsApp iş numarası veya kanal kimliğini girin.</div>
                </div>
                <div class="col-12">
                  <div class="alert alert-info mb-0 small" role="alert">
                    Sunucu, istekleri JSON olarak gönderir: <code>{"to":"905XXXXXXXXX","message":"Merhaba","media":[{"filename":"dosya.jpg","mime_type":"image/jpeg","content":"BASE64"}]}</code>. Servisiniz farklı alanlar bekliyorsa bu verileri karşılayacak şekilde adapte edin.
                  </div>
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
              <hr class="my-4">
              <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
                <div>
                  <h6 class="fw-semibold mb-1">Alt Sayfa CTA Bloğu</h6>
                  <p class="text-muted small mb-0">"Etkinliğiniz için hazırız" alanındaki metin ve buton bilgilerini burada güncelleyin.</p>
                </div>
                <i class="bi bi-lightning-charge-fill" style="font-size:1.4rem;color:var(--admin-brand);"></i>
              </div>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Alt CTA Başlığı</label>
                  <input type="text" name="cta_banner_title" class="form-control" value="<?=h($content['cta_banner_title'] ?? '')?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Alt CTA Açıklaması</label>
                  <textarea name="cta_banner_text" class="form-control" rows="2"><?=h($content['cta_banner_text'] ?? '')?></textarea>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Alt CTA Buton Metni</label>
                  <input type="text" name="cta_banner_button_label" class="form-control" value="<?=h($content['cta_banner_button_label'] ?? '')?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Alt CTA Buton URL</label>
                  <input type="text" name="cta_banner_button_url" class="form-control" value="<?=h($content['cta_banner_button_url'] ?? '')?>" placeholder="#lead-form">
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

      <div class="card card-lite content-pane" data-pane="seo">
        <div class="card-section">
          <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
            <div>
              <h5 class="fw-bold mb-1">SEO &amp; Meta Bilgileri</h5>
              <p class="text-muted mb-0">Anasayfa başlığı ve meta açıklamalarını arama motorları için optimize edin.</p>
            </div>
            <i class="bi bi-graph-up" style="font-size:1.6rem;color:var(--admin-brand);"></i>
          </div>
          <div class="row g-3">
            <div class="col-md-6 col-lg-4">
              <label class="form-label">Favicon</label>
              <input type="hidden" name="seo_favicon_remove" value="0">
              <input type="file" name="seo_favicon" class="form-control" accept="image/png,image/svg+xml,image/x-icon,image/webp,image/jpeg">
              <?php
                $currentFavicon = $content['seo_favicon'] ?? '';
                if ($currentFavicon !== '' && !site_content_asset_exists($currentFavicon)) {
                  $currentFavicon = '';
                }
                if ($currentFavicon === '' && !empty($defaults['seo_favicon']) && site_content_asset_exists($defaults['seo_favicon'])) {
                  $currentFavicon = $defaults['seo_favicon'];
                }
              ?>
              <?php if ($currentFavicon !== ''): ?>
                <div class="d-flex align-items-center gap-3 mt-3 p-3 border rounded bg-light-subtle">
                  <img src="<?=h($currentFavicon)?>" alt="Favicon" style="width:48px;height:48px;object-fit:contain;border-radius:12px;background:#fff;">
                  <div class="flex-grow-1">
                    <div class="small text-muted mb-1">Mevcut favicon</div>
                    <div class="text-break"><a href="<?=h($currentFavicon)?>" target="_blank" rel="noopener"><?=h($currentFavicon)?></a></div>
                  </div>
                  <button type="button" class="btn btn-outline-danger btn-sm js-remove-asset" data-remove-field="seo_favicon_remove" data-confirm="Favicon görselini silmek istediğinize emin misiniz?">
                    <i class="bi bi-trash"></i>
                  </button>
                </div>
              <?php endif; ?>
              <div class="form-text">.ico, .png veya .svg formatında en az 32x32 piksel bir simge yükleyin.</div>
            </div>
            <div class="col-12">
              <label class="form-label">Sayfa Başlığı (Title)</label>
              <input type="text" name="seo_meta_title" class="form-control" value="<?=h($content['seo_meta_title'] ?? '')?>" placeholder="Örn. BİKARE — Dijital Etkinlik Platformu">
              <div class="form-text">Tarayıcı sekmesinde ve arama sonuçlarında görünen başlık. 60 karakteri aşmaması önerilir.</div>
            </div>
            <div class="col-12">
              <label class="form-label">Meta Açıklaması</label>
              <textarea name="seo_meta_description" class="form-control" rows="3" placeholder="Kısa tanıtım metni"><?=h($content['seo_meta_description'] ?? '')?></textarea>
              <div class="form-text">Arama sonuçlarında görünen açıklama metni. 120-160 karakter arası önerilir.</div>
            </div>
            <div class="col-12">
              <label class="form-label">Anahtar Kelimeler</label>
              <input type="text" name="seo_meta_keywords" class="form-control" value="<?=h($content['seo_meta_keywords'] ?? '')?>" placeholder="bikare, dijital etkinlik, qr kod">
              <div class="form-text">Virgülle ayırarak anahtar kelimeler ekleyebilirsiniz.</div>
            </div>
          </div>
          <hr class="my-4">
          <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
            <div>
              <h6 class="fw-semibold mb-1">Sitemap.xml Oluştur</h6>
              <p class="text-muted small mb-0">Blog bağlantıları ve anlaşmalı şirket sayfaları dahil tüm önemli adresleri kapsayan sitemap dosyasını tek tıkla yenileyin.</p>
            </div>
            <button type="submit" name="generate_sitemap" value="1" class="btn btn-outline-primary px-4" formnovalidate>Site Haritasını Güncelle</button>
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

    <div class="modal fade" id="blogEntryModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Yeni Blog Yazısı</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
          </div>
          <div class="modal-body">
            <p class="text-muted small mb-3">Başlık ve kısa açıklamayı girip, gerekirse slug bilgisini düzenleyin. Kaydettiğinizde detayları panelde düzenlemeye devam edebilirsiniz.</p>
            <div class="row g-3">
              <div class="col-md-8">
                <label class="form-label">Başlık</label>
                <input type="text" class="form-control" placeholder="Örn. BİKARE ile düğün planlama" data-modal-blog-title>
              </div>
              <div class="col-md-4">
                <label class="form-label">Slug</label>
                <input type="text" class="form-control" placeholder="ornek-yazi" data-modal-blog-slug>
                <div class="form-text">Boş bırakılırsa başlıktan otomatik alınır.</div>
              </div>
              <div class="col-md-4">
                <label class="form-label">Yayın Tarihi</label>
                <input type="date" class="form-control" data-modal-blog-date>
              </div>
              <div class="col-md-8">
                <label class="form-label">Kısa Açıklama</label>
                <textarea class="form-control" rows="2" placeholder="Özet veya spot metin" data-modal-blog-description></textarea>
              </div>
              <div class="col-12">
                <label class="form-label">İçerik (isteğe bağlı)</label>
                <textarea class="form-control" rows="4" placeholder="Blog yazınızın kısa içeriğini buraya yazabilirsiniz." data-modal-blog-content></textarea>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Vazgeç</button>
            <button type="button" class="btn btn-brand" data-modal-blog-save>Blog Yazısı Oluştur</button>
          </div>
        </div>
      </div>
    </div>

<?php admin_layout_end(); ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  const form = document.getElementById('siteContentForm');
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

  if (form) {
    const selectorForField = (name) => '[name="' + name.replace(/([\\[\\]])/g, '\\$1') + '"]';

    form.querySelectorAll('.js-remove-asset').forEach(button => {
      button.addEventListener('click', () => {
        const fieldName = button.dataset.removeField || '';
        if (!fieldName) {
          return;
        }
        const input = form.querySelector(selectorForField(fieldName));
        if (!input) {
          return;
        }
        const message = button.dataset.confirm || 'Bu görseli silmek istediğinize emin misiniz?';
        if (!window.confirm(message)) {
          return;
        }
        input.value = '1';
        button.disabled = true;
        form.submit();
      });
    });

      form.querySelectorAll('.js-remove-gallery').forEach(button => {
        button.addEventListener('click', () => {
          const value = button.dataset.removeValue || '';
          if (value === '') {
            return;
        }
        const message = button.dataset.confirm || 'Bu görseli silmek istediğinize emin misiniz?';
        if (!window.confirm(message)) {
          return;
        }
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'gallery_remove[]';
        hidden.value = value;
        form.appendChild(hidden);
        button.disabled = true;
        form.submit();
      });
    });
  }

  const blogList = document.querySelector('[data-repeater="blog"]');
  const blogEmpty = document.querySelector('[data-blog-empty]');
  let blogIndexCounter = blogList ? parseInt(blogList.dataset.blogNextIndex || '0', 10) : 0;
  if (!Number.isFinite(blogIndexCounter)) {
    blogIndexCounter = 0;
  }

  const escapeHtml = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

  const slugifyTr = (value) => {
    return String(value ?? '')
      .toLocaleLowerCase('tr-TR')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .replace(/-+/g, '-');
  };

  const formatDisplayDate = (value) => {
    if (!value) {
      return '';
    }
    const date = new Date(value);
    if (!Number.isNaN(date.getTime())) {
      const day = String(date.getDate()).padStart(2, '0');
      const month = String(date.getMonth() + 1).padStart(2, '0');
      const year = date.getFullYear();
      return `${day}.${month}.${year}`;
    }
    return value;
  };

  const updateToggleLabel = (entry) => {
    const toggleBtn = entry.querySelector('[data-blog-toggle]');
    if (!toggleBtn) {
      return;
    }
    toggleBtn.textContent = entry.classList.contains('is-open') ? 'Detayları Gizle' : 'Detayları Göster';
  };

  const refreshBlogEmptyState = () => {
    if (!blogEmpty) {
      return;
    }
    const hasEntries = blogList && blogList.querySelector('[data-blog-entry]');
    blogEmpty.classList.toggle('d-none', !!hasEntries);
  };

  const appendDeletionInput = (path) => {
    if (!form || !path) {
      return;
    }
    const already = Array.from(form.querySelectorAll('input[name="blog_image_delete_existing[]"]'))
      .some(input => input.value === path);
    if (already) {
      return;
    }
    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'blog_image_delete_existing[]';
    hidden.value = path;
    form.appendChild(hidden);
  };

  const updateBlogSummary = (entry) => {
    const titleInput = entry.querySelector('[data-blog-title]');
    const slugInput = entry.querySelector('[data-blog-slug]');
    const dateInput = entry.querySelector('[data-blog-date]');
    const summaryTitle = entry.querySelector('[data-blog-summary-title]');
    const summarySlug = entry.querySelector('[data-blog-summary-slug]');
    const status = entry.querySelector('[data-blog-summary-status]');
    const title = titleInput ? titleInput.value.trim() : '';
    const slug = slugInput ? slugInput.value.trim() : '';
    const date = dateInput ? dateInput.value.trim() : '';
    if (summaryTitle) {
      summaryTitle.textContent = title !== '' ? title : 'Yeni blog yazısı';
    }
    if (summarySlug) {
      summarySlug.textContent = slug !== '' ? slug : 'Slug henüz oluşturulmadı';
    }
    if (status) {
      status.textContent = date !== '' ? `Yayın Tarihi: ${formatDisplayDate(date)}` : 'Taslak';
    }
  };

  const updateCoverPreview = (entry, src) => {
    const preview = entry.querySelector('[data-blog-cover-preview]');
    if (preview) {
      const img = preview.querySelector('img');
      const placeholder = preview.querySelector('.blog-cover-placeholder');
      const hasImage = src && src.trim() !== '';
      if (img) {
        if (hasImage) {
          img.src = src;
          img.classList.remove('d-none');
        } else {
          img.src = '';
          img.classList.add('d-none');
        }
      }
      if (placeholder) {
        placeholder.classList.toggle('d-none', !!hasImage);
      }
    }
    const thumbImg = entry.querySelector('[data-blog-cover] img');
    const thumbPlaceholder = entry.querySelector('[data-blog-cover] .blog-entry-thumb-empty');
    const hasThumbImage = src && src.trim() !== '';
    if (thumbImg) {
      if (hasThumbImage) {
        thumbImg.src = src;
        thumbImg.classList.remove('d-none');
      } else {
        thumbImg.src = '';
        thumbImg.classList.add('d-none');
      }
    }
    if (thumbPlaceholder) {
      thumbPlaceholder.classList.toggle('d-none', !!hasThumbImage);
    }
  };

  const initGallery = (wrapper) => {
    if (!wrapper) {
      return;
    }
    const list = wrapper.querySelector('[data-blog-gallery-list]');
    const storage = wrapper.querySelector('[data-blog-gallery-storage]');
    const input = wrapper.querySelector('[data-blog-gallery-input]');
    const addBtn = wrapper.querySelector('[data-blog-gallery-add]');
    const browseBtn = wrapper.querySelector('[data-blog-gallery-browse]');
    const fileInput = wrapper.querySelector('[data-blog-gallery-file]');
    const note = wrapper.querySelector('[data-gallery-upload-note]');
    if (!list || !storage) {
      return;
    }

    const syncStorage = () => {
      const values = Array.from(list.querySelectorAll('[data-gallery-item] .blog-gallery-chip-label'))
        .map(el => el.textContent.trim())
        .filter(Boolean);
      storage.value = values.join('\n');
    };

    list.querySelectorAll('[data-gallery-remove]').forEach(btn => {
      btn.addEventListener('click', () => {
        const chip = btn.closest('[data-gallery-item]');
        if (chip) {
          chip.remove();
          syncStorage();
        }
      });
    });

    const addChip = (value) => {
      const trimmed = (value || '').trim();
      if (trimmed === '') {
        return;
      }
      const chip = document.createElement('span');
      chip.className = 'blog-gallery-chip';
      chip.dataset.galleryItem = '1';
      const label = document.createElement('span');
      label.className = 'blog-gallery-chip-label';
      label.textContent = trimmed;
      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'btn-close';
      removeBtn.setAttribute('aria-label', 'Sil');
      removeBtn.dataset.galleryRemove = '1';
      removeBtn.addEventListener('click', () => {
        chip.remove();
        syncStorage();
      });
      chip.append(label, removeBtn);
      list.appendChild(chip);
      syncStorage();
    };

    if (addBtn && input) {
      const addFromInput = () => {
        addChip(input.value);
        input.value = '';
        input.focus();
      };
      addBtn.addEventListener('click', addFromInput);
      input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
          event.preventDefault();
          addFromInput();
        }
      });
    }

    if (browseBtn && fileInput) {
      browseBtn.addEventListener('click', () => fileInput.click());
    }

    if (fileInput && note) {
      fileInput.addEventListener('change', () => {
        const count = fileInput.files ? fileInput.files.length : 0;
        if (count > 0) {
          note.textContent = `${count} yeni görsel seçildi. Kaydettiğinizde listeye eklenecek.`;
          note.classList.remove('d-none');
        } else {
          note.textContent = '';
          note.classList.add('d-none');
        }
      });
    }

    syncStorage();
  };

  const initBlogEntry = (entry) => {
    if (!entry) {
      return;
    }
    const toggleBtn = entry.querySelector('[data-blog-toggle]');
    const removeBtn = entry.querySelector('[data-blog-remove]');
    const titleInput = entry.querySelector('[data-blog-title]');
    const slugInput = entry.querySelector('[data-blog-slug]');
    const dateInput = entry.querySelector('[data-blog-date]');
    const imageHidden = entry.querySelector('[data-blog-image]');
    const imageUrlInput = entry.querySelector('[data-blog-image-url]');
    const imageFileInput = entry.querySelector('[data-blog-image-file]');
    const imageBrowseBtn = entry.querySelector('[data-blog-image-browse]');
    const imageRemoveBtn = entry.querySelector('[data-blog-image-remove]');
    const galleryWrapper = entry.querySelector('[data-blog-gallery]');

    if (toggleBtn) {
      toggleBtn.addEventListener('click', () => {
        entry.classList.toggle('is-open');
        updateToggleLabel(entry);
      });
      updateToggleLabel(entry);
    }

    if (removeBtn) {
      removeBtn.addEventListener('click', () => {
        if (!window.confirm('Bu blog yazısını kaldırmak istediğinize emin misiniz?')) {
          return;
        }
        entry.remove();
        refreshBlogEmptyState();
      });
    }

    if (slugInput) {
      const autoAttr = slugInput.dataset.slugAuto === '1';
      if (!autoAttr && slugInput.value.trim() !== '') {
        slugInput.dataset.slugDirty = '1';
      }
      slugInput.addEventListener('input', () => {
        slugInput.dataset.slugDirty = '1';
        slugInput.dataset.slugAuto = '0';
        updateBlogSummary(entry);
      });
      slugInput.addEventListener('blur', () => {
        if (slugInput.value.trim() === '' && titleInput) {
          const generated = slugifyTr(titleInput.value);
          slugInput.value = generated;
          slugInput.dataset.slugDirty = generated !== '' ? '' : '';
          slugInput.dataset.slugAuto = '1';
          updateBlogSummary(entry);
        }
      });
    }

    if (titleInput && slugInput) {
      titleInput.addEventListener('input', () => {
        if (slugInput.dataset.slugDirty !== '1') {
          const generated = slugifyTr(titleInput.value);
          slugInput.value = generated;
          slugInput.dataset.slugAuto = '1';
        }
        updateBlogSummary(entry);
      });
      titleInput.addEventListener('blur', () => updateBlogSummary(entry));
    }

    if (dateInput) {
      dateInput.addEventListener('change', () => updateBlogSummary(entry));
      dateInput.addEventListener('blur', () => updateBlogSummary(entry));
    }

    if (imageUrlInput && imageHidden) {
      imageUrlInput.addEventListener('input', () => {
        const value = imageUrlInput.value.trim();
        imageHidden.value = value;
        updateCoverPreview(entry, value);
      });
    }

    if (imageBrowseBtn && imageFileInput) {
      imageBrowseBtn.addEventListener('click', () => imageFileInput.click());
    }

    if (imageFileInput) {
      imageFileInput.addEventListener('change', () => {
        if (imageFileInput.files && imageFileInput.files[0]) {
          const reader = new FileReader();
          reader.addEventListener('load', () => {
            updateCoverPreview(entry, reader.result || '');
          });
          reader.readAsDataURL(imageFileInput.files[0]);
        }
      });
    }

    if (imageRemoveBtn) {
      imageRemoveBtn.addEventListener('click', () => {
        if (!window.confirm('Kapak görselini kaldırmak istediğinize emin misiniz?')) {
          return;
        }
        const current = imageHidden ? imageHidden.value.trim() : '';
        if (current !== '') {
          appendDeletionInput(current);
        }
        if (imageHidden) {
          imageHidden.value = '';
        }
        if (imageUrlInput) {
          imageUrlInput.value = '';
        }
        if (imageFileInput) {
          imageFileInput.value = '';
        }
        updateCoverPreview(entry, '');
      });
    }

    if (galleryWrapper) {
      initGallery(galleryWrapper);
    }

    updateBlogSummary(entry);
    const initialCover = (imageHidden && imageHidden.value.trim()) || (imageUrlInput && imageUrlInput.value.trim()) || '';
    updateCoverPreview(entry, initialCover);
  };

  const createBlogEntry = (index, defaults = {}) => {
    const title = (defaults.title || '').trim();
    let slugValue = (defaults.slug || '').trim();
    let slugAuto = '0';
    if (slugValue === '') {
      slugValue = slugifyTr(title);
      slugAuto = '1';
    }
    const dateValue = (defaults.date || '').trim();
    const description = (defaults.description || '').trim();
    const content = (defaults.content || '').trim();
    const image = (defaults.image || '').trim();
    const galleryItems = Array.isArray(defaults.gallery) ? defaults.gallery.filter(Boolean) : [];
    const galleryValue = galleryItems.join('\n');
    const summaryTitle = title !== '' ? title : 'Yeni blog yazısı';
    const summarySlug = slugValue !== '' ? slugValue : 'Slug henüz oluşturulmadı';
    const summaryStatus = dateValue !== '' ? `Yayın Tarihi: ${formatDisplayDate(dateValue)}` : 'Taslak';
    const galleryChipsHtml = galleryItems.map(item => `
      <span class="blog-gallery-chip" data-gallery-item>
        <span class="blog-gallery-chip-label">${escapeHtml(item)}</span>
        <button type="button" class="btn-close" aria-label="Sil" data-gallery-remove></button>
      </span>
    `).join('');

    const wrapper = document.createElement('div');
    wrapper.innerHTML = `
      <div class="blog-entry is-open" data-blog-entry data-blog-index="${index}">
        <div class="blog-entry-header">
          <div class="blog-entry-thumb" data-blog-cover>
            <img src="${escapeHtml(image)}" alt="Blog kapak görseli" class="${image ? '' : 'd-none'}" loading="lazy">
            <div class="blog-entry-thumb-empty${image ? ' d-none' : ''}"><i class="bi bi-image"></i></div>
          </div>
          <div class="blog-entry-summary">
            <span class="badge rounded-pill text-bg-light blog-entry-status" data-blog-summary-status>${escapeHtml(summaryStatus)}</span>
            <h6 class="mb-1 blog-entry-title" data-blog-summary-title>${escapeHtml(summaryTitle)}</h6>
            <p class="text-muted small mb-0" data-blog-summary-slug>${escapeHtml(summarySlug)}</p>
          </div>
          <div class="blog-entry-actions">
            <button type="button" class="btn btn-sm btn-outline-primary" data-blog-toggle>Detayları Gizle</button>
            <button type="button" class="btn btn-sm btn-outline-danger" data-blog-remove><i class="bi bi-trash"></i></button>
          </div>
        </div>
        <div class="blog-entry-body">
          <div class="row g-3">
            <div class="col-lg-8">
              <label class="form-label">Başlık</label>
              <input type="text" class="form-control" name="blog_title[${index}]" value="${escapeHtml(title)}" placeholder="Blog başlığı" data-blog-title>
            </div>
            <div class="col-lg-4">
              <label class="form-label">Slug</label>
              <input type="text" class="form-control" name="blog_slug[${index}]" value="${escapeHtml(slugValue)}" placeholder="ornek-yazi" data-blog-slug data-slug-auto="${slugAuto}">
              <div class="form-text">Boş bırakılırsa başlıktan otomatik oluşturulur.</div>
            </div>
            <div class="col-lg-4">
              <label class="form-label">Yayın Tarihi</label>
              <input type="date" class="form-control" name="blog_published_at[${index}]" value="${escapeHtml(dateValue)}" data-blog-date>
            </div>
            <div class="col-lg-8">
              <label class="form-label">Kısa Açıklama</label>
              <textarea class="form-control" name="blog_description[${index}]" rows="2" placeholder="Özet veya spot metni" data-blog-description>${escapeHtml(description)}</textarea>
            </div>
          </div>
          <hr class="my-4">
          <div class="row g-3 align-items-start">
            <div class="col-md-4">
              <div class="blog-cover-preview" data-blog-cover-preview>
                <img src="${escapeHtml(image)}" alt="Blog kapak görseli" class="${image ? '' : 'd-none'}">
                <div class="blog-cover-placeholder${image ? ' d-none' : ''}"><i class="bi bi-image"></i></div>
              </div>
            </div>
            <div class="col-md-8">
              <label class="form-label">Kapak Görseli</label>
              <input type="hidden" name="blog_image[${index}]" value="${escapeHtml(image)}" data-blog-image>
              <div class="input-group input-group-sm">
                <span class="input-group-text">URL</span>
                <input type="text" class="form-control" placeholder="https://..." value="${escapeHtml(image)}" data-blog-image-url>
              </div>
              <div class="d-flex flex-wrap gap-2 mt-3">
                <input type="file" class="d-none" name="blog_image_upload[${index}]" accept="image/*" data-blog-image-file>
                <button type="button" class="btn btn-sm btn-outline-primary" data-blog-image-browse><i class="bi bi-upload"></i> Görsel Yükle</button>
                <button type="button" class="btn btn-sm btn-outline-danger" data-blog-image-remove><i class="bi bi-x"></i> Görseli Kaldır</button>
              </div>
              <div class="form-text mt-2">Harici bağlantı girebilir veya yeni görsel yükleyebilirsiniz. Yüklediğiniz görsel kaydedildiğinde önizleme güncellenir.</div>
            </div>
          </div>
          <div class="mt-4">
            <label class="form-label">İçerik</label>
            <textarea class="form-control" name="blog_content[${index}]" rows="6" placeholder="Makale metni" data-blog-content>${escapeHtml(content)}</textarea>
            <div class="form-text">Paragraflar arasında boş satır bırakarak yeni paragraf oluşturabilirsiniz.</div>
          </div>
          <div class="mt-4 blog-gallery" data-blog-gallery>
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
              <div>
                <label class="form-label mb-1">Galeri Görselleri</label>
                <p class="text-muted small mb-0">URL ekleyin veya görsel yükleyin. Kaydettiğinizde galeri güncellenir.</p>
              </div>
              <button type="button" class="btn btn-sm btn-outline-secondary" data-blog-gallery-browse><i class="bi bi-images"></i> Görsel Yükle</button>
            </div>
            <input type="file" class="d-none" name="blog_gallery_upload[${index}][]" accept="image/*" multiple data-blog-gallery-file>
            <div class="blog-gallery-list" data-blog-gallery-list>${galleryChipsHtml}</div>
            <div class="input-group input-group-sm blog-gallery-adder mt-3">
              <input type="text" class="form-control" placeholder="https://..." data-blog-gallery-input>
              <button type="button" class="btn btn-outline-primary" data-blog-gallery-add>Ekle</button>
            </div>
            <div class="blog-gallery-note text-muted small d-none" data-gallery-upload-note></div>
            <div class="form-text mt-2">Yüklenen dosyalar kaydettikten sonra galeri listesine eklenir.</div>
            <textarea class="d-none" name="blog_gallery[${index}]" data-blog-gallery-storage>${escapeHtml(galleryValue)}</textarea>
          </div>
        </div>
      </div>
    `;
    return wrapper.firstElementChild;
  };

  if (blogList) {
    blogList.querySelectorAll('[data-blog-entry]').forEach(initBlogEntry);
  }
  refreshBlogEmptyState();

  const blogOpenBtn = document.querySelector('[data-blog-open-modal]');
  const blogModalEl = document.getElementById('blogEntryModal');
  if (blogModalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
    const blogModal = new bootstrap.Modal(blogModalEl);
    const modalTitleInput = blogModalEl.querySelector('[data-modal-blog-title]');
    const modalSlugInput = blogModalEl.querySelector('[data-modal-blog-slug]');
    const modalDateInput = blogModalEl.querySelector('[data-modal-blog-date]');
    const modalDescriptionInput = blogModalEl.querySelector('[data-modal-blog-description]');
    const modalContentInput = blogModalEl.querySelector('[data-modal-blog-content]');
    const modalSaveBtn = blogModalEl.querySelector('[data-modal-blog-save]');
    let modalSlugDirty = false;

    const resetModal = () => {
      if (modalTitleInput) modalTitleInput.value = '';
      if (modalSlugInput) modalSlugInput.value = '';
      if (modalDateInput) modalDateInput.value = '';
      if (modalDescriptionInput) modalDescriptionInput.value = '';
      if (modalContentInput) modalContentInput.value = '';
      modalSlugDirty = false;
    };

    if (modalTitleInput && modalSlugInput) {
      modalTitleInput.addEventListener('input', () => {
        if (!modalSlugDirty) {
          modalSlugInput.value = slugifyTr(modalTitleInput.value);
        }
      });
      modalSlugInput.addEventListener('input', () => {
        modalSlugDirty = modalSlugInput.value.trim() !== '';
      });
      modalSlugInput.addEventListener('blur', () => {
        if (modalSlugInput.value.trim() === '') {
          modalSlugInput.value = slugifyTr(modalTitleInput.value);
          modalSlugDirty = false;
        }
      });
    }

    blogModalEl.addEventListener('hidden.bs.modal', resetModal);
    blogModalEl.addEventListener('shown.bs.modal', () => {
      if (modalTitleInput) {
        modalTitleInput.focus();
      }
    });

    if (blogOpenBtn) {
      blogOpenBtn.addEventListener('click', () => {
        resetModal();
        blogModal.show();
      });
    }

    if (modalSaveBtn) {
      modalSaveBtn.addEventListener('click', () => {
        const title = modalTitleInput ? modalTitleInput.value.trim() : '';
        const slug = modalSlugInput ? modalSlugInput.value.trim() : '';
        const date = modalDateInput ? modalDateInput.value.trim() : '';
        const description = modalDescriptionInput ? modalDescriptionInput.value.trim() : '';
        const content = modalContentInput ? modalContentInput.value.trim() : '';
        const entry = createBlogEntry(blogIndexCounter, {
          title,
          slug,
          date,
          description,
          content,
        });
        blogIndexCounter += 1;
        if (blogList) {
          blogList.dataset.blogNextIndex = String(blogIndexCounter);
          blogList.appendChild(entry);
          initBlogEntry(entry);
          refreshBlogEmptyState();
          updateBlogSummary(entry);
          setTimeout(() => {
            entry.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }, 150);
        }
        blogModal.hide();
      });
    }
  } else if (blogOpenBtn) {
    blogOpenBtn.addEventListener('click', () => {
      window.alert('Blog oluşturma modali şu anda yüklenemedi.');
    });
  }
})();
</script>
</body>
</html>
