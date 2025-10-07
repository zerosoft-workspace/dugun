<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/functions.php';
require_once __DIR__.'/includes/site.php';
require_once __DIR__.'/includes/addons.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check()) {
    flash('err', 'Oturum doğrulaması başarısız. Lütfen tekrar deneyin.');
    redirect($_SERVER['REQUEST_URI']);
  }

  $selected = $_POST['selected_addons'] ?? [];
  $quantities = $_POST['qty'] ?? [];
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
      $map[$addonId] = $qty;
    }
  }

  site_order_sync_addons($order['id'], $map);
  $_SESSION['order_addons_open'] = false;
  flash('ok', 'Seçimleriniz kaydedildi. Şimdi ödeme adımına yönlendiriliyorsunuz.');
  redirect('order_paytr.php?order_id='.$order['id']);
}

$currentAddons = site_order_addons_list($order['id']);
$addonQty = [];
foreach ($currentAddons as $line) {
  $addonQty[(int)$line['addon_id']] = (int)$line['quantity'];
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
  body{background:linear-gradient(180deg,#f3f8fb,#fff);font-family:'Inter',sans-serif;color:#0f172a;}
  .extras-wrapper{max-width:1100px;margin:0 auto;padding:48px;border-radius:32px;background:#fff;box-shadow:0 32px 80px rgba(14,165,181,.16);}
  .extras-hero{display:flex;flex-direction:column;gap:12px;margin-bottom:36px;}
  .extras-badge{display:inline-flex;align-items:center;gap:8px;background:rgba(14,165,181,.12);color:#0b8b98;border-radius:999px;padding:8px 18px;font-weight:600;}
  .extras-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;}
  .addon-card{border:1px solid rgba(14,165,181,.14);border-radius:24px;padding:22px;background:rgba(255,255,255,.92);box-shadow:0 20px 60px -35px rgba(14,165,181,.45);display:flex;flex-direction:column;gap:18px;position:relative;overflow:hidden;}
  .addon-card.active{border-color:rgba(14,165,181,.4);box-shadow:0 26px 70px -32px rgba(14,165,181,.55);}
  .addon-card h3{font-size:1.2rem;font-weight:700;margin:0;}
  .addon-price{font-size:1.1rem;font-weight:700;color:#0ea5b5;}
  .addon-desc{color:#64748b;font-size:.92rem;}
  .addon-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;}
  .btn-continue{border-radius:18px;padding:14px 22px;font-weight:600;}
  .package-summary{border-radius:24px;background:rgba(14,165,181,.08);padding:24px;margin-bottom:32px;}
  .package-summary h2{font-size:1.35rem;font-weight:700;margin-bottom:8px;}
  .package-summary ul{margin:0;padding-left:18px;color:#4b5563;}
  .skip-link{font-weight:600;text-decoration:none;color:#0ea5b5;}
  @media(max-width:768px){.extras-wrapper{padding:32px;} .addon-actions{flex-direction:column;align-items:flex-start;} .addon-actions input{width:100%;}}
</style>
</head>
<body>
<div class="container py-5">
  <div class="extras-wrapper">
    <div class="extras-hero">
      <span class="extras-badge"><i class="bi bi-stars"></i> Önerilen Ek Hizmetler</span>
      <h1 class="fw-bold mb-0">Etkinliğinizi Zenginleştirecek Seçenekleri Ekleyin</h1>
      <p class="text-muted mb-0">Siparişinizi tamamlamadan önce davetiye, QR kod baskıları ve etkinlik sonrası kurgu gibi ek hizmetleri seçebilir, dilediğiniz zaman panelden yeniden satın alabilirsiniz.</p>
    </div>

    <div class="package-summary">
      <h2><?=h($package['name'])?></h2>
      <div class="d-flex flex-wrap align-items-center gap-3 mb-2">
        <span class="fw-semibold">Temel Paket Tutarı:</span>
        <span class="badge bg-info-subtle text-info-emphasis px-3 py-2"><?=h(format_currency((int)$order['base_price_cents']))?></span>
      </div>
      <p class="mb-0 text-muted">Devam etmek istemezseniz doğrudan ödeme adımına geçebilirsiniz.</p>
    </div>

    <?php if (!$addons): ?>
      <div class="alert alert-info mb-0">Şu anda ek hizmet bulunmuyor. <a class="fw-semibold" href="order_paytr.php?order_id=<?=(int)$order['id']?>">Ödeme adımına geçin</a>.</div>
    <?php else: ?>
      <?=flash_messages()?>
      <form method="post" class="d-grid gap-4">
        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
        <div class="extras-grid">
          <?php foreach ($addons as $addon): 
            $isChecked = array_key_exists((int)$addon['id'], $addonQty);
            $qtyValue = $addonQty[$addon['id']] ?? 1;
          ?>
            <label class="addon-card <?= $isChecked ? 'active' : '' ?>">
              <div class="d-flex align-items-start justify-content-between gap-3">
                <h3><?=h($addon['name'])?></h3>
                <span class="addon-price"><?=h(format_currency((int)$addon['price_cents']))?></span>
              </div>
              <?php if (!empty($addon['description'])): ?>
                <p class="addon-desc mb-0"><?=nl2br(h($addon['description']))?></p>
              <?php endif; ?>
              <div class="addon-actions">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" name="selected_addons[]" value="<?= (int)$addon['id'] ?>" id="addon-<?= (int)$addon['id'] ?>" <?= $isChecked ? 'checked' : '' ?>>
                  <label class="form-check-label" for="addon-<?= (int)$addon['id'] ?>">Ek hizmete ekle</label>
                </div>
                <div class="d-flex align-items-center gap-2">
                  <label class="text-muted small mb-0" for="qty-<?= (int)$addon['id'] ?>">Adet</label>
                  <input class="form-control" style="width:90px;" type="number" min="1" name="qty[<?= (int)$addon['id'] ?>]" id="qty-<?= (int)$addon['id'] ?>" value="<?= (int)max(1, $qtyValue) ?>">
                </div>
              </div>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
          <a class="skip-link" href="order_paytr.php?order_id=<?=(int)$order['id']?>">Bu adımı atla</a>
          <button type="submit" class="btn btn-primary btn-continue">Ödeme Adımına Geç</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
