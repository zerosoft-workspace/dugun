<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/functions.php';
require_once __DIR__.'/includes/site.php';
require_once __DIR__.'/includes/addons.php';
require_once __DIR__.'/includes/campaigns.php';
require_once __DIR__.'/includes/public_header.php';
require_once __DIR__.'/includes/login_header.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

install_schema();

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : (int)($_SESSION['current_order_id'] ?? 0);
if ($orderId <= 0) {
  flash('err', 'Sipariş oturumu bulunamadı.');
  redirect('index.php');
}

$order = site_get_order($orderId);
if (!$order) {
  flash('err', 'Sipariş kaydı bulunamadı.');
  redirect('index.php');
}

$package = dealer_package_get($order['package_id']);
if (!$package) {
  flash('err', 'Paket bilgisi alınamadı.');
  redirect('index.php');
}

$addons = site_addon_all(true);
$campaigns = site_campaign_all(true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check()) {
    flash('err', 'Oturum doğrulaması başarısız. Lütfen tekrar deneyin.');
    redirect($_SERVER['REQUEST_URI']);
  }

  $selected = $_POST['selected_addons'] ?? [];
  $quantities = $_POST['qty'] ?? [];
  $variantsInput = $_POST['variant'] ?? [];
  $map = [];
  if (is_array($selected)) {
    foreach ($selected as $addonId) {
      $addonId = (int)$addonId;
      if ($addonId <= 0) {
        continue;
      }
      $qty = 1;
      if (isset($quantities[$addonId])) {
        $qty = max(1, (int)$quantities[$addonId]);
      }
      $variantId = null;
      if (is_array($variantsInput) && isset($variantsInput[$addonId])) {
        $variantId = (int)$variantsInput[$addonId];
        if ($variantId <= 0) {
          $variantId = null;
        }
      }
      $map[$addonId] = [
        'quantity' => $qty,
        'variant_id' => $variantId,
      ];
    }
  }

  $selectedCampaigns = $_POST['selected_campaigns'] ?? [];
  $campaignQuantities = $_POST['campaign_qty'] ?? [];
  $campaignMap = [];
  if (is_array($selectedCampaigns)) {
    foreach ($selectedCampaigns as $campaignId) {
      $campaignId = (int)$campaignId;
      if ($campaignId <= 0) {
        continue;
      }
      $qty = 1;
      if (isset($campaignQuantities[$campaignId])) {
        $qty = max(1, (int)$campaignQuantities[$campaignId]);
      }
      $campaignMap[$campaignId] = $qty;
    }
  }

  site_order_sync_addons($order['id'], $map);
  site_order_sync_campaigns($order['id'], $campaignMap);
  $_SESSION['order_addons_open'] = false;
  flash('ok', 'Seçimleriniz kaydedildi. Şimdi ödeme adımına yönlendiriliyorsunuz.');
  redirect('order_paytr.php?order_id='.$order['id']);
}

$currentAddons = site_order_addons_list($order['id']);
$addonSelections = [];
foreach ($currentAddons as $line) {
  $addonSelections[(int)$line['addon_id']] = [
    'quantity' => (int)$line['quantity'],
    'variant_id' => isset($line['variant_id']) ? (int)$line['variant_id'] : null,
  ];
}

$currentCampaigns = site_order_campaigns_list($order['id']);
$campaignQty = [];
foreach ($currentCampaigns as $line) {
  $campaignQty[(int)$line['campaign_id']] = (int)$line['quantity'];
}

$groupedAddons = [];
foreach ($addons as $addon) {
  $category = trim((string)($addon['category'] ?? ''));
  $group = $category !== '' ? $category : 'Diğer Hizmetler';
  if (!isset($groupedAddons[$group])) {
    $groupedAddons[$group] = [];
  }
  $groupedAddons[$group][] = $addon;
}

$categorySlugs = [];
$slugUsage = [];
foreach ($groupedAddons as $groupName => $_items) {
  $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $groupName), '-'));
  if ($slug === '') {
    $slug = 'kategori';
  }
  if (isset($slugUsage[$slug])) {
    $slugUsage[$slug]++;
    $slug .= '-'.($slugUsage[$slug]);
  } else {
    $slugUsage[$slug] = 0;
  }
  $categorySlugs[$groupName] = $slug;
}

?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ek Hizmetler — <?=h(APP_NAME)?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
<?=login_header_styles()?>

:root {
  --extras-primary: #0ea5b5;
  --extras-primary-dark: #0b8b98;
  --extras-deep: #0f172a;
  --extras-muted: #64748b;
  --extras-surface: rgba(255, 255, 255, 0.92);
  --extras-border: rgba(14, 165, 181, 0.16);
  --extras-shadow: 0 32px 80px rgba(14, 165, 181, 0.18);
  --extras-radius: 28px;
}

body.extras-body {
  margin: 0;
  font-family: 'Inter', 'Poppins', sans-serif;
  background: linear-gradient(180deg, #f3f8fb 0%, #ffffff 55%);
  color: var(--extras-deep);
}

.extras-main {
  padding-top: 140px;
  padding-bottom: 80px;
}

@media (max-width: 991.98px) {
  .extras-main {
    padding-top: 110px;
    padding-bottom: 60px;
  }
}

.extras-hero {
  background: rgba(255, 255, 255, 0.92);
  border-radius: var(--extras-radius);
  padding: 32px 36px;
  box-shadow: 0 34px 80px rgba(14, 165, 181, 0.16);
  margin-bottom: 40px;
  backdrop-filter: blur(14px);
}

.extras-badge {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  padding: 8px 16px;
  border-radius: 999px;
  background: rgba(14, 165, 181, 0.12);
  color: var(--extras-primary-dark);
  font-weight: 600;
  font-size: 0.9rem;
}

.extras-badge i {
  font-size: 1.1rem;
}

.extras-title {
  margin-top: 12px;
  font-size: clamp(1.6rem, 2.8vw, 2.2rem);
  font-weight: 700;
}

.extras-subtitle {
  margin: 0;
  color: var(--extras-muted);
  font-size: 1rem;
}

.extras-progress {
  display: flex;
  gap: 18px;
  margin-top: 18px;
  font-size: 0.9rem;
  color: var(--extras-muted);
}

.extras-progress div {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  background: rgba(14, 165, 181, 0.08);
  padding: 8px 14px;
  border-radius: 999px;
  letter-spacing: 0.02em;
}

.extras-progress div span {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: var(--extras-primary);
  color: #fff;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-weight: 600;
  font-size: 0.9rem;
}

.extras-progress div.is-active {
  background: var(--extras-primary);
  color: #fff;
  box-shadow: 0 18px 40px rgba(14, 165, 181, 0.28);
}

.extras-progress div.is-active span {
  background: rgba(255, 255, 255, 0.9);
  color: var(--extras-primary-dark);
}

.summary-panel {
  position: sticky;
  top: 120px;
  display: grid;
  gap: 20px;
}

@media (max-width: 991.98px) {
  .summary-panel {
    position: static;
  }
}

.summary-card {
  background: var(--extras-surface);
  border-radius: var(--extras-radius);
  padding: 24px 26px;
  border: 1px solid var(--extras-border);
  box-shadow: var(--extras-shadow);
}

.summary-card h2 {
  font-size: 1.35rem;
  margin-bottom: 12px;
  font-weight: 700;
}

.summary-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 10px;
  font-size: 0.95rem;
}

.summary-list li {
  display: flex;
  justify-content: space-between;
  gap: 12px;
}

.summary-list span {
  color: var(--extras-muted);
}

.summary-list strong {
  font-weight: 600;
}

.summary-steps {
  display: grid;
  gap: 16px;
  background: rgba(14, 165, 181, 0.08);
  border-radius: var(--extras-radius);
  padding: 20px 24px;
  border: 1px solid rgba(14, 165, 181, 0.18);
}

.summary-step {
  display: flex;
  gap: 14px;
  align-items: flex-start;
}

.summary-step span {
  width: 32px;
  height: 32px;
  border-radius: 12px;
  background: var(--extras-primary);
  color: #fff;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-weight: 600;
  font-size: 1rem;
}

.summary-step span i {
  font-size: 1rem;
}

.summary-step strong {
  display: block;
  font-size: 0.95rem;
  color: var(--extras-deep);
}

.summary-step small {
  color: var(--extras-muted);
  font-size: 0.82rem;
}

.flash-messages .alert {
  border-radius: 18px;
  box-shadow: var(--extras-shadow);
}

.category-switcher {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 10px;
}

.category-pill {
  border: none;
  border-radius: 999px;
  padding: 10px 18px;
  background: rgba(14, 165, 181, 0.12);
  color: var(--extras-primary-dark);
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  gap: 10px;
  transition: all 0.2s ease;
  cursor: pointer;
  position: relative;
}

.category-pill::after {
  content: '';
  position: absolute;
  inset: 0;
  border-radius: inherit;
  box-shadow: 0 18px 40px rgba(14, 165, 181, 0.15);
  opacity: 0;
  transition: opacity 0.2s ease;
  pointer-events: none;
}

.category-pill span {
  pointer-events: none;
}

.category-pill .category-pill__count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 28px;
  height: 28px;
  border-radius: 50%;
  background: rgba(14, 165, 181, 0.18);
  font-size: 0.8rem;
  color: var(--extras-primary-dark);
}

.category-pill:hover,
.category-pill:focus {
  background: rgba(14, 165, 181, 0.18);
  color: var(--extras-primary-dark);
}

.category-pill.active {
  background: linear-gradient(135deg, var(--extras-primary), var(--extras-primary-dark));
  color: #fff;
}

.category-pill.active .category-pill__count {
  background: rgba(255, 255, 255, 0.24);
  color: #fff;
}

.category-pill.active::after {
  opacity: 1;
}

.category-pill:focus-visible {
  outline: 3px solid rgba(14, 165, 181, 0.35);
  outline-offset: 2px;
}

.addon-section {
  display: none;
  gap: 18px;
}

.addon-section.is-active {
  display: grid;
}

.section-head {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.section-chip {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 8px 14px;
  border-radius: 999px;
  font-weight: 600;
  background: rgba(14, 165, 181, 0.12);
  color: var(--extras-primary-dark);
  text-transform: uppercase;
  font-size: 0.75rem;
  letter-spacing: 0.08em;
}

.section-chip i {
  font-size: 1rem;
}

.section-copy h3 {
  margin: 0;
  font-size: 1.25rem;
  font-weight: 700;
}

.section-copy p {
  margin: 0;
  color: var(--extras-muted);
  font-size: 0.95rem;
}

.addon-grid {
  display: grid;
  gap: 20px;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
}

.addon-card {
  background: var(--extras-surface);
  border-radius: 26px;
  padding: 24px;
  border: 1px solid var(--extras-border);
  box-shadow: 0 26px 70px rgba(15, 23, 42, 0.08);
  display: grid;
  gap: 18px;
  position: relative;
  transition: all 0.2s ease;
}

.addon-card::after {
  content: '';
  position: absolute;
  inset: 0;
  background: radial-gradient(circle at top right, rgba(14, 165, 181, 0.12), transparent 60%);
  opacity: 0;
  transition: opacity 0.2s ease;
  pointer-events: none;
}

.addon-card.active {
  border-color: rgba(14, 165, 181, 0.5);
  box-shadow: 0 34px 80px rgba(14, 165, 181, 0.22);
}

.addon-card.active::after {
  opacity: 1;
}

.addon-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
}

.addon-head h3 {
  margin: 0;
  font-size: 1.2rem;
  font-weight: 700;
}

.addon-price {
  text-align: right;
  display: grid;
  gap: 4px;
}

.addon-price span[data-addon-price] {
  font-size: 1.1rem;
  font-weight: 700;
  color: var(--extras-primary);
}

.addon-selected-variant {
  font-size: 0.82rem;
  color: var(--extras-muted);
}

.addon-image {
  border-radius: 20px;
  overflow: hidden;
  background: linear-gradient(135deg, rgba(14, 165, 181, 0.12), rgba(96, 165, 250, 0.1));
  max-height: 180px;
}

.addon-image img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.addon-desc {
  color: var(--extras-muted);
  font-size: 0.92rem;
  margin: 0;
}

.variant-picker {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
}

.variant-option {
  position: relative;
}

.variant-tile {
  border: 1px solid rgba(14, 165, 181, 0.18);
  border-radius: 18px;
  padding: 12px 14px;
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: 12px;
  align-items: center;
  background: rgba(255, 255, 255, 0.85);
  transition: all 0.18s ease;
  cursor: pointer;
}

.variant-thumb {
  width: 48px;
  height: 48px;
  border-radius: 14px;
  overflow: hidden;
  background: rgba(14, 165, 181, 0.12);
  display: grid;
  place-items: center;
  color: var(--extras-primary-dark);
  font-size: 1.2rem;
}

.variant-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.variant-thumb--placeholder i {
  font-size: 1rem;
}

.variant-name {
  font-weight: 600;
  font-size: 0.95rem;
  color: var(--extras-deep);
}

.variant-desc {
  font-size: 0.82rem;
  color: var(--extras-muted);
  display: block;
}

.variant-price {
  font-weight: 600;
  color: var(--extras-primary-dark);
}

.btn-check:checked + .variant-tile {
  border-color: var(--extras-primary);
  background: rgba(14, 165, 181, 0.12);
  box-shadow: 0 18px 40px rgba(14, 165, 181, 0.18);
}

.variant-brief {
  font-size: 0.82rem;
  color: var(--extras-muted);
}

.variant-brief span {
  display: inline-block;
  margin-top: 4px;
}

.addon-controls {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.addon-controls .form-check {
  margin-bottom: 0;
}

.addon-qty {
  display: grid;
  gap: 4px;
}

.addon-qty input {
  width: 96px;
  border-radius: 12px;
}

.addon-footer {
  display: flex;
  justify-content: flex-end;
}

.addon-footer .btn {
  border-radius: 14px;
  font-weight: 600;
}

.campaign-section {
  display: grid;
  gap: 18px;
  margin-top: 12px;
}

.campaign-grid {
  display: grid;
  gap: 20px;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
}

.campaign-card {
  border-radius: 26px;
  border: 1px solid rgba(14, 165, 181, 0.18);
  background: rgba(255, 255, 255, 0.9);
  padding: 22px;
  display: grid;
  gap: 16px;
  box-shadow: 0 24px 60px rgba(14, 165, 181, 0.16);
  transition: all 0.2s ease;
}

.campaign-card.active {
  border-color: rgba(14, 165, 181, 0.5);
  box-shadow: 0 30px 70px rgba(14, 165, 181, 0.22);
}

.campaign-image {
  border-radius: 18px;
  overflow: hidden;
  background: linear-gradient(135deg, rgba(14, 165, 181, 0.12), rgba(96, 165, 250, 0.1));
  min-height: 160px;
  position: relative;
}

.campaign-image img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  position: relative;
  z-index: 1;
}

.campaign-summary {
  color: var(--extras-muted);
  font-size: 0.92rem;
}

.campaign-price {
  font-weight: 700;
  color: var(--extras-primary);
}

.campaign-actions {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}

.campaign-actions input {
  width: 96px;
  border-radius: 12px;
}

.campaign-footer {
  display: flex;
  justify-content: flex-end;
}

.extras-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-top: 12px;
}

.btn-continue {
  background: linear-gradient(135deg, var(--extras-primary), #38bdf8);
  border: none;
  border-radius: 18px;
  padding: 14px 26px;
  font-weight: 600;
  box-shadow: 0 18px 40px rgba(14, 165, 181, 0.25);
}

.btn-continue:hover {
  box-shadow: 0 24px 54px rgba(14, 165, 181, 0.3);
}

.skip-link {
  color: var(--extras-primary-dark);
  font-weight: 600;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.skip-link:hover {
  text-decoration: underline;
}

@media (max-width: 767.98px) {
  .addon-controls {
    flex-direction: column;
    align-items: flex-start;
  }
  .extras-actions {
    flex-direction: column;
    align-items: stretch;
  }
  .btn-continue {
    width: 100%;
  }
  .skip-link {
    justify-content: center;
  }
}
</style>
</head>
<body class="extras-body">
<?php render_login_header('portal'); ?>
<main class="extras-main py-5 py-lg-6">
  <div class="container">
    <div class="extras-hero shadow-sm">
      <span class="extras-badge"><i class="bi bi-magic"></i> Ek Hizmetler &amp; Kampanyalar</span>
      <h1 class="extras-title">Davetiye Tasarımından Sosyal Sorumluluk Kampanyasına, Her Detayı Seçin</h1>
      <p class="extras-subtitle">Siparişinizi tamamlamadan önce davetiye varyantlarını, QR kod çözümlerini ve sosyal sorumluluk kampanyalarını tek ekrandan belirleyin.</p>
      <div class="extras-progress">
        <div><span>1</span>Paket</div>
        <div class="is-active"><span>2</span>Ek Hizmetler</div>
        <div><span>3</span>Ödeme</div>
      </div>
    </div>
    <div class="row g-4 align-items-start extras-layout">
      <div class="col-lg-4">
        <aside class="summary-panel">
          <div class="summary-card">
            <h2><?=h($package['name'])?></h2>
            <ul class="summary-list">
              <li><span>Temel Paket</span><strong><?=h(format_currency((int)$order['base_price_cents']))?></strong></li>
              <li><span>Etkinlik</span><strong><?=h($order['event_title'])?></strong></li>
              <?php if (!empty($order['event_date'])): ?>
                <li><span>Tarih</span><strong><?=h(date('d.m.Y', strtotime($order['event_date'])))?></strong></li>
              <?php endif; ?>
            </ul>
          </div>
          <div class="summary-steps">
            <div class="summary-step">
              <span><i class="bi bi-stars"></i></span>
              <div><strong>Varyant Seçimi</strong><small>Davetiye ve baskı seçeneklerinin tamamı bu adımda.</small></div>
            </div>
            <div class="summary-step">
              <span><i class="bi bi-credit-card-2-front"></i></span>
              <div><strong>Tek Ödeme</strong><small>Tüm ek hizmet ve bağış tercihlerinizi tek seferde ödeyin.</small></div>
            </div>
            <div class="summary-step">
              <span><i class="bi bi-send"></i></span>
              <div><strong>Kurulum Süreci</strong><small>Ödeme sonrası ekibimiz seçtiğiniz hizmetleri planlar.</small></div>
            </div>
          </div>
        </aside>
      </div>
      <div class="col-lg-8">
        <?php if (!$addons && !$campaigns): ?>
          <div class="alert alert-info shadow-sm rounded-4">Şu anda ek hizmet veya sosyal sorumluluk kampanyası sunulmuyor. <a class="fw-semibold" href="order_paytr.php?order_id=<?=(int)$order['id']?>">Doğrudan ödeme adımına geçebilirsiniz.</a></div>
        <?php else: ?>
          <div class="flash-messages"><?=flash_messages()?></div>
          <form method="post" class="extras-form d-grid gap-4">
            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
            <?php if (count($groupedAddons) > 1): ?>
              <div class="category-switcher" role="tablist" aria-label="Ek hizmet kategorileri" data-category-switcher>
                <?php $categoryIndex = 0; foreach ($groupedAddons as $groupName => $groupItems):
                  $slug = $categorySlugs[$groupName] ?? ('kategori-'.$categoryIndex);
                  $isActive = $categoryIndex === 0;
                ?>
                  <button type="button" class="category-pill<?= $isActive ? ' active' : '' ?>" data-category-trigger="<?=h($slug)?>" role="tab" aria-selected="<?= $isActive ? 'true' : 'false' ?>" aria-controls="category-panel-<?=h($slug)?>" tabindex="<?= $isActive ? '0' : '-1' ?>">
                    <span class="category-pill__name"><?=h($groupName)?></span>
                    <span class="category-pill__count"><?=count($groupItems)?></span>
                  </button>
                <?php $categoryIndex++; endforeach; ?>
              </div>
            <?php endif; ?>
            <?php $sectionIndex = 0; foreach ($groupedAddons as $groupName => $groupItems):
              $slug = $categorySlugs[$groupName] ?? ('kategori-'.$sectionIndex);
              $isActive = $sectionIndex === 0;
            ?>
              <?php
                $groupDescription = $groupName === 'Diğer Hizmetler'
                  ? 'Etkinliğinizi tamamlayan farklı hizmetleri seçebilirsiniz.'
                  : $groupName.' kategorisindeki hizmetlerle deneyiminizi kişiselleştirin.';
              ?>
              <section class="addon-section<?= $isActive ? ' is-active' : '' ?>" id="category-panel-<?=h($slug)?>" data-category-section="<?=h($slug)?>" role="tabpanel" aria-expanded="<?= $isActive ? 'true' : 'false' ?>" aria-hidden="<?= $isActive ? 'false' : 'true' ?>"<?= $isActive ? '' : ' hidden' ?>>
                <div class="section-head">
                  <span class="section-chip"><i class="bi bi-stars"></i><?=h($groupName)?></span>
                  <div class="section-copy">
                    <h3><?=h($groupName)?> Hizmetleri</h3>
                    <p><?=h($groupDescription)?></p>
                  </div>
                </div>
                <div class="addon-grid">
                  <?php foreach ($groupItems as $addon):
                    $addonId = (int)$addon['id'];
                    $selection = $addonSelections[$addonId] ?? null;
                    $isChecked = $selection !== null;
                    $quantity = $selection['quantity'] ?? 1;
                    $selectedVariantId = $selection['variant_id'] ?? null;
                    $variants = $addon['variants'] ?? [];
                    $hasVariants = !empty($variants);
                    $displayVariant = null;
                    if ($hasVariants) {
                      foreach ($variants as $variantOption) {
                        if ($selectedVariantId && (int)$variantOption['id'] === (int)$selectedVariantId) {
                          $displayVariant = $variantOption;
                          break;
                        }
                      }
                      if (!$displayVariant) {
                        $displayVariant = $variants[0];
                      }
                      $displayPrice = (int)$displayVariant['price_cents'];
                    } else {
                      $displayPrice = (int)$addon['price_cents'];
                    }
                    $variantLabel = $displayVariant['name'] ?? '';
                    $variantDescription = $displayVariant['description'] ?? '';
                    $variantDetail = $displayVariant['detail'] ?? '';
                    $variantImage = $displayVariant['image_url'] ?? '';
                    $defaultDescription = trim((string)($addon['description'] ?? ''));
                    $defaultDetail = trim((string)($addon['detail'] ?? ''));
                    $defaultImage = !empty($addon['image_url']) ? $addon['image_url'] : '';
                  ?>
                  <div class="addon-card<?= $isChecked ? ' active' : '' ?>" data-addon-card data-base-price="<?=$addon['price_cents']?>">
                    <div class="addon-head">
                      <div>
                        <h3><?=h($addon['name'])?></h3>
                        <?php if ($defaultDescription && !$hasVariants): ?>
                          <p class="addon-desc mb-0"><?=nl2br(h($defaultDescription))?></p>
                        <?php endif; ?>
                      </div>
                      <div class="addon-price">
                        <span data-addon-price><?=h(format_currency($displayPrice))?></span>
                        <?php if ($hasVariants): ?>
                          <span class="addon-selected-variant" data-selected-variant><?= $variantLabel ? 'Seçilen: '.h($variantLabel) : '' ?></span>
                        <?php endif; ?>
                      </div>
                    </div>
                    <?php if (!empty($addon['image_url'])): ?>
                      <div class="addon-image">
                        <img src="<?=h($addon['image_url'])?>" alt="<?=h($addon['name'])?>">
                      </div>
                    <?php endif; ?>
                    <?php if ($hasVariants): ?>
                      <div class="variant-picker">
                        <?php foreach ($variants as $index => $variant):
                          $variantId = (int)$variant['id'];
                          $variantSelected = $selectedVariantId ? $variantId === (int)$selectedVariantId : $index === 0;
                          $variantPrice = (int)$variant['price_cents'];
                          $variantDesc = trim((string)($variant['description'] ?? ''));
                          $variantDet = trim((string)($variant['detail'] ?? ''));
                          $variantImg = !empty($variant['image_url']) ? $variant['image_url'] : '';
                        ?>
                        <div class="variant-option">
                          <input class="btn-check" type="radio" name="variant[<?=$addonId?>]" id="variant-<?=$addonId?>-<?=$variantId?>" value="<?=$variantId?>" <?= $variantSelected ? 'checked' : '' ?> data-variant-radio data-variant-price="<?=$variantPrice?>" data-variant-name="<?=h($variant['name'])?>" data-variant-description="<?=h($variantDesc)?>" data-variant-detail="<?=h($variantDet)?>" data-variant-image="<?=h($variantImg)?>">
                          <label class="variant-tile" for="variant-<?=$addonId?>-<?=$variantId?>">
                            <span class="variant-thumb<?= $variantImg ? '' : ' variant-thumb--placeholder' ?>">
                              <?php if ($variantImg): ?>
                                <img src="<?=h($variantImg)?>" alt="<?=h($variant['name'])?>">
                              <?php else: ?>
                                <i class="bi bi-stars"></i>
                              <?php endif; ?>
                            </span>
                            <div>
                              <span class="variant-name"><?=h($variant['name'])?></span>
                              <?php if ($variantDesc): ?>
                                <span class="variant-desc"><?=h($variantDesc)?></span>
                              <?php endif; ?>
                            </div>
                            <span class="variant-price"><?=h(format_currency($variantPrice))?></span>
                          </label>
                        </div>
                        <?php endforeach; ?>
                      </div>
                      <div class="variant-brief" data-variant-brief>
                        <?php if ($variantDescription): ?>
                          <span><?=h($variantDescription)?></span>
                        <?php endif; ?>
                      </div>
                    <?php elseif ($defaultDescription): ?>
                      <p class="addon-desc mb-0"><?=nl2br(h($defaultDescription))?></p>
                    <?php endif; ?>
                    <div class="addon-controls">
                      <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="selected_addons[]" value="<?=$addonId?>" id="addon-<?=$addonId?>" <?= $isChecked ? 'checked' : '' ?> data-addon-toggle>
                        <label class="form-check-label" for="addon-<?=$addonId?>">Hizmeti ekle</label>
                      </div>
                      <div class="addon-qty">
                        <label class="text-muted small mb-0" for="qty-<?=$addonId?>">Adet</label>
                        <input class="form-control" type="number" min="1" name="qty[<?=$addonId?>]" id="qty-<?=$addonId?>" value="<?= (int)max(1, $quantity) ?>" data-addon-qty>
                      </div>
                    </div>
                    <div class="addon-footer">
                      <button type="button" class="btn btn-outline-secondary btn-sm" data-addon-detail data-name="<?=h($addon['name'])?>" data-default-name="<?=h($addon['name'])?>" data-description="<?=h($defaultDescription)?>" data-default-description="<?=h($defaultDescription)?>" data-detail="<?=h($defaultDetail)?>" data-default-detail="<?=h($defaultDetail)?>" data-image="<?=h($defaultImage)?>" data-default-image="<?=h($defaultImage)?>"><i class="bi bi-info-circle"></i> Detayları Gör</button>
                    </div>
                  </div>
                  <?php endforeach; ?>
                </div>
              </section>
            <?php $sectionIndex++; endforeach; ?>
            <?php if ($campaigns): ?>
              <section class="campaign-section">
                <div class="section-head">
                  <span class="section-chip"><i class="bi bi-heart-fill"></i> Sosyal Sorumluluk Kampanyaları</span>
                  <div class="section-copy">
                    <h3>Etkinliğiniz Adına Destek Olun</h3>
                    <p>Sosyal sorumluluk projelerini siparişinizle birlikte destekleyip bağış ödemesini aynı adımda tamamlayabilirsiniz.</p>
                  </div>
                </div>
                <div class="campaign-grid">
                  <?php foreach ($campaigns as $campaign):
                    $campaignId = (int)$campaign['id'];
                    $isChecked = array_key_exists($campaignId, $campaignQty);
                    $qtyValue = $campaignQty[$campaignId] ?? 1;
                    $imageUrl = $campaign['image_url'] ?? null;
                  ?>
                  <div class="campaign-card<?= $isChecked ? ' active' : '' ?>">
                    <div class="campaign-image">
                      <?php if ($imageUrl): ?>
                        <img src="<?=h($imageUrl)?>" alt="<?=h($campaign['name'])?>">
                      <?php endif; ?>
                    </div>
                    <div class="d-flex justify-content-between align-items-start gap-3">
                      <div>
                        <h4 class="mb-1"><?=h($campaign['name'])?></h4>
                        <?php if (!empty($campaign['summary'])): ?>
                          <p class="campaign-summary mb-0"><?=nl2br(h($campaign['summary']))?></p>
                        <?php endif; ?>
                      </div>
                      <span class="campaign-price"><?=h(format_currency((int)$campaign['price_cents']))?></span>
                    </div>
                    <div class="campaign-actions">
                      <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="selected_campaigns[]" value="<?=$campaignId?>" id="campaign-<?=$campaignId?>" <?= $isChecked ? 'checked' : '' ?> data-campaign-toggle>
                        <label class="form-check-label" for="campaign-<?=$campaignId?>">Bağışı ekle</label>
                      </div>
                      <div class="d-flex align-items-center gap-2">
                        <label class="text-muted small mb-0" for="campaign-qty-<?=$campaignId?>">Adet</label>
                        <input class="form-control" style="width: 90px;" type="number" min="1" name="campaign_qty[<?=$campaignId?>]" id="campaign-qty-<?=$campaignId?>" value="<?= (int)max(1, $qtyValue) ?>">
                      </div>
                    </div>
                    <div class="campaign-footer">
                      <button type="button" class="btn btn-outline-secondary btn-sm" data-campaign-detail data-name="<?=h($campaign['name'])?>" data-summary="<?=h($campaign['summary'] ?? '')?>" data-detail="<?=h($campaign['detail'] ?? '')?>" data-image="<?=h($imageUrl ?? '')?>"><i class="bi bi-info-circle"></i> Detayları Gör</button>
                    </div>
                  </div>
                  <?php endforeach; ?>
                </div>
              </section>
            <?php endif; ?>
            <div class="extras-actions">
              <a class="skip-link" href="order_paytr.php?order_id=<?=(int)$order['id']?>"><i class="bi bi-arrow-right-circle"></i> Bu adımı atla</a>
              <button type="submit" class="btn btn-primary btn-continue">Ödeme Adımına Geç</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>
<div class="modal fade" id="addonDetailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-0 rounded-4 shadow-lg">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-semibold" data-addon-modal-title>Ek Hizmet Detayı</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3 d-none" data-addon-modal-image></div>
        <p class="text-muted mb-3 d-none" data-addon-modal-description></p>
        <div data-addon-modal-body class="text-muted small"></div>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
      </div>
    </div>
  </div>
</div>
<div class="modal fade" id="campaignDetailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-0 rounded-4 shadow-lg">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-semibold" data-campaign-modal-title>Sosyal Sorumluluk Kampanyası</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted mb-3 d-none" data-campaign-modal-summary></p>
        <div class="mb-3 d-none" data-campaign-modal-image></div>
        <div data-campaign-modal-body class="text-muted small"></div>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    var formatMoney = new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY' });
    var escapeHtml = function (str) {
      return (str || '').replace(/[&<>"']/g, function (ch) {
        return ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'})[ch] || ch;
      });
    };

    var categoryButtons = Array.prototype.slice.call(document.querySelectorAll('[data-category-trigger]'));
    var categorySections = Array.prototype.slice.call(document.querySelectorAll('[data-category-section]'));
    if (categoryButtons.length && categorySections.length) {
      var activateCategory = function (slug) {
        var found = false;
        categorySections.forEach(function (section, index) {
          var matches = section.getAttribute('data-category-section') === slug;
          section.classList.toggle('is-active', matches);
          section.setAttribute('aria-expanded', matches ? 'true' : 'false');
          section.setAttribute('aria-hidden', matches ? 'false' : 'true');
          if (matches) {
            section.removeAttribute('hidden');
          } else {
            section.setAttribute('hidden', '');
          }
          if (matches) {
            found = true;
          }
        });
        categoryButtons.forEach(function (button, index) {
          var matches = button.getAttribute('data-category-trigger') === slug;
          button.classList.toggle('active', matches);
          button.setAttribute('aria-selected', matches ? 'true' : 'false');
          button.setAttribute('tabindex', matches ? '0' : '-1');
          if (matches) {
            button.focus();
          }
        });
        if (!found && categorySections[0]) {
          var fallbackSlug = categorySections[0].getAttribute('data-category-section');
          if (fallbackSlug && fallbackSlug !== slug) {
            activateCategory(fallbackSlug);
          }
        }
      };

      categoryButtons.forEach(function (button, index) {
        button.addEventListener('click', function () {
          activateCategory(button.getAttribute('data-category-trigger'));
        });
        button.addEventListener('keydown', function (event) {
          if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') {
            return;
          }
          event.preventDefault();
          var delta = event.key === 'ArrowRight' ? 1 : -1;
          var nextIndex = (index + delta + categoryButtons.length) % categoryButtons.length;
          var nextButton = categoryButtons[nextIndex];
          if (nextButton) {
            activateCategory(nextButton.getAttribute('data-category-trigger'));
          }
        });
      });
    }

    document.querySelectorAll('[data-addon-card]').forEach(function (card) {
      var checkbox = card.querySelector('[data-addon-toggle]');
      var priceEl = card.querySelector('[data-addon-price]');
      var basePrice = parseInt(card.getAttribute('data-base-price') || '0', 10);
      var variantLabel = card.querySelector('[data-selected-variant]');
      var variantBrief = card.querySelector('[data-variant-brief]');
      var detailBtn = card.querySelector('[data-addon-detail]');
      var defaultName = detailBtn ? detailBtn.getAttribute('data-default-name') : '';
      var defaultDescription = detailBtn ? detailBtn.getAttribute('data-default-description') : '';
      var defaultDetail = detailBtn ? detailBtn.getAttribute('data-default-detail') : '';
      var defaultImage = detailBtn ? detailBtn.getAttribute('data-default-image') : '';
      var variantRadios = card.querySelectorAll('[data-variant-radio]');

      var updateCardState = function () {
        if (!checkbox) {
          return;
        }
        card.classList.toggle('active', checkbox.checked);
      };

      if (checkbox) {
        checkbox.addEventListener('change', updateCardState);
        updateCardState();
      }

      var setDetailDataset = function (name, description, detail, image) {
        if (!detailBtn) {
          return;
        }
        detailBtn.setAttribute('data-name', name || defaultName || '');
        detailBtn.setAttribute('data-description', description || defaultDescription || '');
        detailBtn.setAttribute('data-detail', detail || defaultDetail || '');
        detailBtn.setAttribute('data-image', image || defaultImage || '');
      };

      var updateVariantInfo = function (radio) {
        if (!radio) {
          if (priceEl) {
            priceEl.textContent = formatMoney.format(basePrice / 100);
          }
          if (variantLabel) {
            variantLabel.textContent = '';
          }
          if (variantBrief) {
            variantBrief.innerHTML = defaultDescription ? '<span>' + escapeHtml(defaultDescription) + '</span>' : '';
          }
          setDetailDataset(defaultName, defaultDescription, defaultDetail, defaultImage);
          return;
        }
        var price = parseInt(radio.getAttribute('data-variant-price') || '0', 10);
        if (priceEl) {
          priceEl.textContent = formatMoney.format(price / 100);
        }
        var name = radio.getAttribute('data-variant-name') || '';
        var description = radio.getAttribute('data-variant-description') || '';
        var detail = radio.getAttribute('data-variant-detail') || '';
        var image = radio.getAttribute('data-variant-image') || '';
        if (variantLabel) {
          variantLabel.textContent = name ? 'Seçilen: ' + name : '';
        }
        if (variantBrief) {
          if (description) {
            variantBrief.innerHTML = '<span>' + escapeHtml(description) + '</span>';
          } else if (detail) {
            var snippet = detail.split(/\r?\n/).find(function (line) { return line.trim() !== ''; }) || '';
            variantBrief.innerHTML = snippet ? '<span>' + escapeHtml(snippet) + '</span>' : '';
          } else {
            variantBrief.innerHTML = '';
          }
        }
        var modalName = defaultName && name ? defaultName + ' — ' + name : (name || defaultName);
        setDetailDataset(modalName, description || defaultDescription, detail || defaultDetail, image || defaultImage);
      };

      if (variantRadios.length) {
        var initial = Array.prototype.find.call(variantRadios, function (input) { return input.checked; }) || variantRadios[0];
        if (initial) {
          initial.checked = true;
          updateVariantInfo(initial);
        }
        variantRadios.forEach(function (radio) {
          radio.addEventListener('change', function () {
            updateVariantInfo(radio);
            if (checkbox && !checkbox.checked) {
              checkbox.checked = true;
              updateCardState();
            }
          });
        });
      } else {
        if (priceEl) {
          priceEl.textContent = formatMoney.format(basePrice / 100);
        }
        setDetailDataset(defaultName, defaultDescription, defaultDetail, defaultImage);
      }
    });

    document.querySelectorAll('[data-campaign-toggle]').forEach(function (checkbox) {
      var card = checkbox.closest('.campaign-card');
      var toggle = function () {
        if (card) {
          card.classList.toggle('active', checkbox.checked);
        }
      };
      checkbox.addEventListener('change', toggle);
      toggle();
    });

    var addonModalEl = document.getElementById('addonDetailModal');
    if (addonModalEl) {
      var addonModal = new bootstrap.Modal(addonModalEl);
      var addonTitleEl = addonModalEl.querySelector('[data-addon-modal-title]');
      var addonDescEl = addonModalEl.querySelector('[data-addon-modal-description]');
      var addonBodyEl = addonModalEl.querySelector('[data-addon-modal-body]');
      var addonImageEl = addonModalEl.querySelector('[data-addon-modal-image]');

      document.querySelectorAll('[data-addon-detail]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var name = btn.getAttribute('data-name') || '';
          var description = btn.getAttribute('data-description') || '';
          var detail = btn.getAttribute('data-detail') || '';
          var image = btn.getAttribute('data-image') || '';

          if (addonTitleEl) {
            addonTitleEl.textContent = name;
          }
          if (addonDescEl) {
            if (description.trim() !== '') {
              addonDescEl.textContent = description;
              addonDescEl.classList.remove('d-none');
            } else {
              addonDescEl.textContent = '';
              addonDescEl.classList.add('d-none');
            }
          }
          if (addonBodyEl) {
            if (detail.trim() !== '') {
              var html = detail.split(/\r?\n/).map(function (line) {
                return escapeHtml(line);
              }).join('<br>');
              addonBodyEl.innerHTML = html;
            } else if (description.trim() !== '') {
              addonBodyEl.innerHTML = '<span class="text-muted">' + escapeHtml(description) + '</span>';
            } else {
              addonBodyEl.innerHTML = '<span class="text-muted">Ek detay eklenmemiş.</span>';
            }
          }
          if (addonImageEl) {
            if (image) {
              addonImageEl.innerHTML = '<img src="' + escapeHtml(image) + '" alt="' + escapeHtml(name) + '" class="img-fluid rounded-4 shadow-sm">';
              addonImageEl.classList.remove('d-none');
            } else {
              addonImageEl.innerHTML = '';
              addonImageEl.classList.add('d-none');
            }
          }
          addonModal.show();
        });
      });
    }

    var campaignModalEl = document.getElementById('campaignDetailModal');
    if (campaignModalEl) {
      var campaignModal = new bootstrap.Modal(campaignModalEl);
      var campaignTitleEl = campaignModalEl.querySelector('[data-campaign-modal-title]');
      var campaignSummaryEl = campaignModalEl.querySelector('[data-campaign-modal-summary]');
      var campaignBodyEl = campaignModalEl.querySelector('[data-campaign-modal-body]');
      var campaignImageEl = campaignModalEl.querySelector('[data-campaign-modal-image]');

      document.querySelectorAll('[data-campaign-detail]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var name = btn.getAttribute('data-name') || '';
          var summary = btn.getAttribute('data-summary') || '';
          var detail = btn.getAttribute('data-detail') || '';
          var image = btn.getAttribute('data-image') || '';

          if (campaignTitleEl) {
            campaignTitleEl.textContent = name;
          }
          if (campaignSummaryEl) {
            if (summary.trim() !== '') {
              campaignSummaryEl.textContent = summary;
              campaignSummaryEl.classList.remove('d-none');
            } else {
              campaignSummaryEl.textContent = '';
              campaignSummaryEl.classList.add('d-none');
            }
          }
          if (campaignBodyEl) {
            if (detail.trim() !== '') {
              var html = detail.split(/\r?\n/).map(function (line) {
                return escapeHtml(line);
              }).join('<br>');
              campaignBodyEl.innerHTML = html;
            } else {
              campaignBodyEl.innerHTML = '<span class="text-muted">Ek detay eklenmemiş.</span>';
            }
          }
          if (campaignImageEl) {
            if (image) {
              campaignImageEl.innerHTML = '<img src="' + escapeHtml(image) + '" alt="' + escapeHtml(name) + '" class="img-fluid rounded-4 shadow-sm">';
              campaignImageEl.classList.remove('d-none');
            } else {
              campaignImageEl.innerHTML = '';
              campaignImageEl.classList.add('d-none');
            }
          }
          campaignModal.show();
        });
      });
    }
  });
</script>
</body>
</html>
