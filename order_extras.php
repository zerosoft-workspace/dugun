<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/functions.php';
require_once __DIR__.'/includes/site.php';
require_once __DIR__.'/includes/addons.php';
require_once __DIR__.'/includes/campaigns.php';

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
$addonQty = [];
foreach ($currentAddons as $line) {
  $addonQty[(int)$line['addon_id']] = (int)$line['quantity'];
}

$currentCampaigns = site_order_campaigns_list($order['id']);
$campaignQty = [];
foreach ($currentCampaigns as $line) {
  $campaignQty[(int)$line['campaign_id']] = (int)$line['quantity'];
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
  .addon-image{border-radius:18px;overflow:hidden;min-height:160px;background:linear-gradient(135deg,rgba(14,165,181,.12),rgba(59,130,246,.1));position:relative;}
  .addon-image img{width:100%;height:100%;object-fit:cover;display:block;}
  .addon-image.placeholder{display:grid;place-items:center;color:#0f172a;font-weight:600;}
  .addon-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;}
  .btn-continue{border-radius:18px;padding:14px 22px;font-weight:600;}
  .package-summary{border-radius:24px;background:rgba(14,165,181,.08);padding:24px;margin-bottom:32px;}
  .package-summary h2{font-size:1.35rem;font-weight:700;margin-bottom:8px;}
  .package-summary ul{margin:0;padding-left:18px;color:#4b5563;}
  .skip-link{font-weight:600;text-decoration:none;color:#0ea5b5;}
  .campaign-section{display:grid;gap:18px;margin-top:12px;}
  .campaign-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:22px;}
  .campaign-card{border-radius:26px;padding:22px;background:rgba(255,255,255,.95);box-shadow:0 24px 60px -40px rgba(15,118,110,.45);border:1px solid rgba(14,165,181,.14);display:flex;flex-direction:column;gap:16px;transition:all .2s ease;}
  .campaign-card.active{border-color:rgba(14,165,181,.45);box-shadow:0 32px 80px -36px rgba(14,165,181,.6);}
  .campaign-image{border-radius:20px;background-size:cover;background-position:center;background-repeat:no-repeat;min-height:160px;position:relative;overflow:hidden;}
  .campaign-image::after{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(14,165,181,.08),rgba(15,23,42,.2));opacity:.6;}
  .campaign-image.placeholder{display:grid;place-items:center;background:linear-gradient(135deg,rgba(14,165,181,.15),rgba(59,130,246,.12));color:#0f172a;font-weight:600;font-size:.9rem;}
  .campaign-summary{color:#475569;font-size:.95rem;line-height:1.5;}
  .campaign-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;}
  .campaign-footer{display:flex;justify-content:flex-end;}
  .campaign-footer .btn{border-radius:14px;font-weight:600;}
  .campaign-price{font-size:1.1rem;font-weight:700;color:#0ea5b5;}
  .campaign-card h3{margin:0;font-size:1.18rem;font-weight:700;}
  @media(max-width:768px){.extras-wrapper{padding:32px;} .addon-actions{flex-direction:column;align-items:flex-start;} .addon-actions input{width:100%;}}
  @media(max-width:768px){.campaign-actions{flex-direction:column;align-items:flex-start;}.campaign-actions input{width:100%;}}
</style>
</head>
<body>
<div class="container py-5">
  <div class="extras-wrapper">
    <div class="extras-hero">
      <span class="extras-badge"><i class="bi bi-stars"></i> Ek Hizmet &amp; Hayır Kampanyaları</span>
      <h1 class="fw-bold mb-0">Etkinliğinizi Zenginleştirecek Seçenekleri Ekleyin</h1>
      <p class="text-muted mb-0">Siparişinizi tamamlamadan önce ek hizmetleri ve destek olmak istediğiniz hayır kampanyalarını seçebilir, ödemeyi tek seferde tamamlayabilirsiniz.</p>
    </div>

    <div class="package-summary">
      <h2><?=h($package['name'])?></h2>
      <div class="d-flex flex-wrap align-items-center gap-3 mb-2">
        <span class="fw-semibold">Temel Paket Tutarı:</span>
        <span class="badge bg-info-subtle text-info-emphasis px-3 py-2"><?=h(format_currency((int)$order['base_price_cents']))?></span>
      </div>
      <p class="mb-0 text-muted">Devam etmek istemezseniz doğrudan ödeme adımına geçebilirsiniz.</p>
    </div>

    <?php if (!$addons && !$campaigns): ?>
      <div class="alert alert-info mb-0">Şu anda ek hizmet veya hayır kampanyası bulunmuyor. <a class="fw-semibold" href="order_paytr.php?order_id=<?=(int)$order['id']?>">Ödeme adımına geçin</a>.</div>
    <?php else: ?>
      <?=flash_messages()?>
      <form method="post" class="d-grid gap-4">
        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
        <?php if ($addons): ?>
          <div class="d-flex flex-column gap-3">
            <div>
              <h2 class="fw-semibold mb-1">Önerilen Ek Hizmetler</h2>
              <p class="text-muted mb-0">Davetiye baskıları, QR kod tabloları ve etkinlik sonrası montaj gibi hizmetleri paketinizle birlikte satın alabilirsiniz.</p>
            </div>
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
                  <?php if (!empty($addon['image_url'])): ?>
                    <div class="addon-image">
                      <img src="<?=h($addon['image_url'])?>" alt="<?=h($addon['name'])?>">
                    </div>
                  <?php endif; ?>
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
          </div>
        <?php endif; ?>

        <?php if ($campaigns): ?>
          <div class="campaign-section">
            <div>
              <h2 class="fw-semibold mb-1">Hayır Kampanyalarını Destekleyin</h2>
              <p class="text-muted mb-0">Etkinliğiniz adına sosyal sorumluluk projelerine katkı sağlayarak bağışlarınızı siparişinizle birlikte ödeyin.</p>
            </div>
            <div class="campaign-grid">
              <?php foreach ($campaigns as $campaign):
                $campaignId = (int)$campaign['id'];
                $isChecked = array_key_exists($campaignId, $campaignQty);
                $qtyValue = $campaignQty[$campaignId] ?? 1;
                $imageUrl = $campaign['image_url'] ?? null;
              ?>
                <div class="campaign-card <?= $isChecked ? 'active' : '' ?>">
                  <?php if ($imageUrl): ?>
                    <div class="campaign-image" style="background-image:url('<?=h($imageUrl)?>');"></div>
                  <?php else: ?>
                    <div class="campaign-image placeholder">Görsel Bekleniyor</div>
                  <?php endif; ?>
                  <div class="d-flex align-items-start justify-content-between gap-3">
                    <h3><?=h($campaign['name'])?></h3>
                    <span class="campaign-price"><?=h(format_currency((int)$campaign['price_cents']))?></span>
                  </div>
                  <?php if (!empty($campaign['summary'])): ?>
                    <p class="campaign-summary mb-0"><?=nl2br(h($campaign['summary']))?></p>
                  <?php endif; ?>
                  <div class="campaign-actions">
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" name="selected_campaigns[]" value="<?=$campaignId?>" id="campaign-<?=$campaignId?>" <?= $isChecked ? 'checked' : '' ?> data-campaign-toggle>
                      <label class="form-check-label" for="campaign-<?=$campaignId?>">Bağışı ekle</label>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                      <label class="text-muted small mb-0" for="campaign-qty-<?=$campaignId?>">Adet</label>
                      <input class="form-control" style="width:90px;" type="number" min="1" name="campaign_qty[<?=$campaignId?>]" id="campaign-qty-<?=$campaignId?>" value="<?= (int)max(1, $qtyValue) ?>">
                    </div>
                  </div>
                  <div class="campaign-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-campaign-detail data-name="<?=h($campaign['name'])?>" data-summary="<?=h($campaign['summary'] ?? '')?>" data-detail="<?=h($campaign['detail'] ?? '')?>" data-image="<?=h($imageUrl ?? '')?>"><i class="bi bi-info-circle"></i> Detayları Gör</button>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
          <a class="skip-link" href="order_paytr.php?order_id=<?=(int)$order['id']?>">Bu adımı atla</a>
          <button type="submit" class="btn btn-primary btn-continue">Ödeme Adımına Geç</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>
<div class="modal fade" id="campaignDetailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-0 rounded-4">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-semibold" data-campaign-modal-title>Hayır Kampanyası</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted mb-3 d-none" data-campaign-modal-summary></p>
        <div class="mb-3 d-none" data-campaign-modal-image></div>
        <div data-campaign-modal-body class="text-muted"></div>
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
    document.querySelectorAll('.addon-card input[type="checkbox"]').forEach(function (checkbox) {
      const card = checkbox.closest('.addon-card');
      const toggle = function () {
        if (card) {
          card.classList.toggle('active', checkbox.checked);
        }
      };
      checkbox.addEventListener('change', toggle);
      toggle();
    });

    document.querySelectorAll('[data-campaign-toggle]').forEach(function (checkbox) {
      const card = checkbox.closest('.campaign-card');
      const toggle = function () {
        if (card) {
          card.classList.toggle('active', checkbox.checked);
        }
      };
      checkbox.addEventListener('change', toggle);
      toggle();
    });

    const modalEl = document.getElementById('campaignDetailModal');
    if (!modalEl) {
      return;
    }
    const modal = new bootstrap.Modal(modalEl);
    const titleEl = modalEl.querySelector('[data-campaign-modal-title]');
    const summaryEl = modalEl.querySelector('[data-campaign-modal-summary]');
    const imageEl = modalEl.querySelector('[data-campaign-modal-image]');
    const bodyEl = modalEl.querySelector('[data-campaign-modal-body]');

    const escapeHtml = function (str) {
      const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
      };
      return (str || '').replace(/[&<>"']/g, function (ch) {
        return map[ch] || ch;
      });
    };

    document.querySelectorAll('[data-campaign-detail]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const name = btn.getAttribute('data-name') || '';
        const summary = btn.getAttribute('data-summary') || '';
        const detail = btn.getAttribute('data-detail') || '';
        const image = btn.getAttribute('data-image') || '';

        if (titleEl) {
          titleEl.textContent = name;
        }
        if (summaryEl) {
          if (summary.trim() !== '') {
            summaryEl.textContent = summary;
            summaryEl.classList.remove('d-none');
          } else {
            summaryEl.textContent = '';
            summaryEl.classList.add('d-none');
          }
        }
        if (imageEl) {
          if (image) {
            imageEl.innerHTML = '<img src="' + image + '" alt="' + escapeHtml(name) + '" class="img-fluid rounded-4 shadow-sm">';
            imageEl.classList.remove('d-none');
          } else {
            imageEl.innerHTML = '';
            imageEl.classList.add('d-none');
          }
        }
        if (bodyEl) {
          if (detail.trim() !== '') {
            const html = detail.split(/\r?\n/).map(function (line) {
              return escapeHtml(line);
            }).join('<br>');
            bodyEl.innerHTML = html;
            bodyEl.classList.remove('text-muted');
          } else {
            bodyEl.innerHTML = '<p class="text-muted mb-0">Detay bilgisi eklenmedi.</p>';
            bodyEl.classList.remove('text-muted');
          }
        }
        modal.show();
      });
    });
  });
</script>
</body>
</html>
