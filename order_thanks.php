<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$success = $_SESSION['lead_success'] ?? null;
$summary = $_SESSION['order_summary'] ?? null;
if (!$success || !$summary) {
  redirect('index.php');
}
unset($_SESSION['lead_success'], $_SESSION['order_summary']);
?>
<!doctype html><html lang="tr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Siparişiniz Alındı — <?=h(APP_NAME)?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:linear-gradient(180deg,#f5fbfd,#fff);font-family:'Inter',sans-serif;color:#0f172a;}
  .card{border-radius:24px;border:none;box-shadow:0 24px 70px rgba(14,165,181,0.18);padding:40px;}
  .qr-preview img{max-width:220px;border-radius:18px;box-shadow:0 12px 40px rgba(15,118,110,0.25);}
</style>
</head><body>
<div class="container py-5">
  <div class="card mx-auto" style="max-width:720px;">
    <div class="text-center mb-4">
      <div class="badge bg-success-subtle text-success-emphasis rounded-pill px-3 py-2">Teşekkürler</div>
      <h1 class="fw-bold mt-3">Talebiniz Başarıyla Alındı</h1>
      <p class="text-muted mb-0"><?=h($success)?></p>
    </div>
    <div class="row g-4 align-items-center">
      <div class="col-md-6">
        <h5 class="fw-semibold">Etkinlik Bilgileriniz</h5>
        <ul class="list-unstyled small text-muted mb-3">
          <li><strong>Etkinlik:</strong> <?=h($summary['event_title'])?></li>
          <li><strong>Misafir bağlantısı:</strong><br><a href="<?=h($summary['upload_url'])?>" target="_blank" rel="noopener"><?=h($summary['upload_url'])?></a></li>
          <?php if (!empty($summary['login_url'])): ?>
            <li class="mt-2"><strong>Çift paneli:</strong><br><a href="<?=h($summary['login_url'])?>" target="_blank" rel="noopener"><?=h($summary['login_url'])?></a></li>
          <?php endif; ?>
          <?php if (!empty($summary['plain_password'])): ?>
            <li class="mt-2"><strong>Geçici şifre:</strong> <?=h($summary['plain_password'])?> <span class="badge bg-warning-subtle text-warning-emphasis ms-2">İlk girişte değiştirin</span></li>
          <?php endif; ?>
          <?php $hasAddons = !empty($summary['addons']); $hasCampaigns = !empty($summary['campaigns']); ?>
          <?php if ($hasAddons): ?>
            <li class="mt-3"><strong>Satın Alınan Ek Hizmetler</strong></li>
            <ul class="small ps-3 mb-2">
              <?php foreach ($summary['addons'] as $addon):
                $addonName = $addon['addon_name'] ?? '';
                $variantName = $addon['variant_name'] ?? '';
              ?>
                <li class="d-flex justify-content-between">
                  <div>
                    <strong><?=h($addonName)?></strong>
                    <?php if ($variantName): ?>
                      <div class="text-muted small"><?=h($variantName)?></div>
                    <?php endif; ?>
                    <div class="text-muted small">Adet: <?= (int)$addon['quantity'] ?></div>
                  </div>
                  <span><?=h(format_currency((int)$addon['total_cents']))?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <?php if ($hasCampaigns): ?>
            <li class="<?= $hasAddons ? 'mt-2' : 'mt-3'?>"><strong>Desteklediğiniz Hayır Kampanyaları</strong></li>
            <ul class="small ps-3 mb-2">
              <?php foreach ($summary['campaigns'] as $campaign): ?>
                <li class="d-flex justify-content-between">
                  <span><?=h($campaign['campaign_name'])?> × <?= (int)$campaign['quantity'] ?></span>
                  <span><?=h(format_currency((int)$campaign['total_cents']))?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <?php if ($hasAddons): ?>
            <li><strong>Ek Hizmet Toplamı:</strong> <?=h(format_currency((int)$summary['addons_total']))?></li>
          <?php endif; ?>
          <?php if ($hasCampaigns): ?>
            <li><strong>Hayır Kampanyası Toplamı:</strong> <?=h(format_currency((int)($summary['campaigns_total'] ?? 0)))?></li>
          <?php endif; ?>
          <li><strong>Genel Toplam:</strong> <?=h(format_currency((int)$summary['order_total']))?></li>
        </ul>
        <div class="d-flex flex-wrap gap-2">
          <a class="btn btn-outline-info" href="<?=h($summary['upload_url'])?>" target="_blank" rel="noopener">Misafir Sayfasını Aç</a>
          <?php if (!empty($summary['login_url'])): ?>
            <a class="btn btn-outline-primary" href="<?=h($summary['login_url'])?>" target="_blank" rel="noopener">Panelde Oturum Aç</a>
          <?php endif; ?>
          <a class="btn btn-link" href="index.php">Anasayfaya Dön</a>
        </div>
      </div>
      <div class="col-md-6 text-center qr-preview">
        <p class="text-muted small">QR kodu yazdırmak için görsele sağ tıklayıp kaydedebilirsiniz.</p>
        <img src="<?=h($summary['qr_image'])?>" alt="QR Önizleme">
      </div>
    </div>
  </div>
</div>
</body></html>
