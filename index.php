<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/functions.php';
require_once __DIR__.'/includes/site.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

install_schema();

$packages = site_public_packages();
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
<style>
  :root {
    --ink:#111827;
    --muted:#6b7280;
    --brand:#0ea5b5;
    --brand-soft:#e0f7fb;
    --card:#ffffff;
  }
  body { background:linear-gradient(180deg,#f5fbfd,#fff); font-family:'Inter',sans-serif; color:var(--ink); }
  .hero { border-radius:28px; background:linear-gradient(135deg,#0ea5b5,#60a5fa); color:#fff; padding:72px 32px; position:relative; overflow:hidden; }
  .hero::after { content:""; position:absolute; inset:auto -80px -80px 50%; width:280px; height:280px; background:rgba(255,255,255,0.15); border-radius:50%; }
  .hero h1 { font-weight:800; font-size:2.8rem; }
  .stat-card { border-radius:20px; background:#fff; box-shadow:0 20px 60px rgba(14,165,181,0.15); padding:28px; }
  .package-card { border-radius:20px; border:1px solid rgba(14,165,181,0.12); background:#fff; transition:transform .2s, box-shadow .2s; }
  .package-card:hover { transform:translateY(-4px); box-shadow:0 16px 40px rgba(15,118,110,0.12); }
  .package-price { font-size:1.6rem; font-weight:700; color:var(--brand); }
  .bg-brand { background:var(--brand); }
  .text-brand { color:var(--brand); }
  .feature-badge { display:inline-flex; align-items:center; gap:8px; padding:6px 14px; border-radius:999px; background:rgba(255,255,255,0.2); color:#fff; font-weight:600; }
  .form-section { border-radius:24px; background:#fff; box-shadow:0 24px 70px rgba(15,118,110,0.18); padding:40px; }
  .btn-brand { background:var(--brand); color:#fff; border:none; border-radius:16px; padding:14px 28px; font-weight:700; }
  .btn-brand:hover { background:#0c8d9a; color:#fff; }
  .input-rounded { border-radius:14px; border:1px solid #dbe9ee; padding:12px 16px; }
  .muted { color:var(--muted); }
  @media (max-width: 768px) {
    .hero { padding:48px 24px; }
    .hero h1 { font-size:2.2rem; }
    .form-section { padding:28px; }
  }
</style>
</head><body>
<nav class="navbar navbar-expand-lg bg-white border-bottom shadow-sm">
  <div class="container">
    <a class="navbar-brand fw-bold" href="#"><?=h(APP_NAME)?></a>
    <span class="badge bg-info-subtle text-info-emphasis rounded-pill">Etkinlik Teknolojisi</span>
  </div>
</nav>

<main class="container py-5">
  <section class="hero mb-5 text-center text-lg-start">
    <div class="row align-items-center g-4">
      <div class="col-lg-7 position-relative">
        <span class="feature-badge">Misafirleriniz QR ile paylaşsın</span>
        <h1 class="mt-4 mb-3">Anılarınızı aynı gün dijitalleştirin</h1>
        <p class="lead mb-4">Wedding Share; davetlilerinizden fotoğraf ve videoları tek QR ile toplar, çift paneli ve bayi ekosistemiyle profesyonel bir deneyim sunar.</p>
        <div class="d-flex flex-wrap gap-3">
          <a class="btn btn-light text-brand fw-semibold" href="#paketler">Paketleri Gör</a>
          <a class="btn btn-outline-light fw-semibold" href="#lead-form">Demo Talep Et</a>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="stat-card text-start">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
              <div class="muted text-uppercase small">2024 Yazı</div>
              <h3 class="fw-bold mb-0">Davetli deneyimini yükseltin</h3>
            </div>
            <span class="badge bg-success-subtle text-success-emphasis rounded-pill">Yeni</span>
          </div>
          <ul class="list-unstyled mb-0 small">
            <li class="mb-2">• Kalıcı & anlık QR kod üretimi</li>
            <li class="mb-2">• Sosyal medya tarzı galeri ve beğeni sistemi</li>
            <li class="mb-2">• Otomatik çift paneli, e-posta bildirimleri</li>
            <li>• Bayi referansıyla %20 cashback fırsatı</li>
          </ul>
        </div>
      </div>
    </div>
  </section>

  <?php if ($success): ?>
    <div class="alert alert-success shadow-sm"><?=h($success)?></div>
  <?php endif; ?>
  <?php flash_box(); ?>

  <section id="paketler" class="mb-5">
    <div class="text-center mb-4">
      <h2 class="fw-bold">Sizin için tasarlanan paketler</h2>
      <p class="muted">Etkinlik sayınıza göre seçim yapın, QR kodlar ve paneliniz dakikalar içinde hazır olsun.</p>
    </div>
    <div class="row g-4">
      <?php foreach ($packages as $pkg): ?>
        <div class="col-md-4">
          <div class="package-card h-100 p-4">
            <div class="d-flex justify-content-between align-items-start mb-3">
              <h4 class="fw-semibold mb-0"><?=h($pkg['name'])?></h4>
              <?php if ($pkg['event_quota'] === null): ?>
                <span class="badge bg-brand text-white">Sınırsız</span>
              <?php else: ?>
                <span class="badge bg-info-subtle text-info-emphasis"><?=$pkg['event_quota']?> etkinlik</span>
              <?php endif; ?>
            </div>
            <div class="package-price mb-3"><?=h(format_currency((int)$pkg['price_cents']))?></div>
            <?php if (!empty($pkg['description'])): ?>
              <p class="muted small mb-4"><?=nl2br(h($pkg['description']))?></p>
            <?php endif; ?>
            <ul class="small text-muted mb-0">
              <li>Kalıcı ve etkinliğe özel QR kodlar</li>
              <li>Çift paneli otomatik kurulum</li>
              <li>Misafir galerisi ve sosyal etkileşim</li>
              <?php if ($pkg['cashback_rate'] > 0): ?>
                <li>Bayi referansında %<?=number_format($pkg['cashback_rate'] * 100, 0)?> cashback</li>
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

  <section id="lead-form" class="form-section">
    <div class="row g-4 align-items-start">
      <div class="col-lg-5">
        <h3 class="fw-bold">Müşteri formu</h3>
        <p class="muted">Paketinizi seçin, referans kodunuzu paylaşın. Wedding Share ekibi etkinliğinizi otomatik olarak oluşturup QR kodları size ve bayinize e-posta ile iletsin.</p>
        <div class="d-flex flex-column gap-3 mt-4 small text-muted">
          <div><strong>•</strong> Paket ücreti online olarak alınır, referans bayi %20 cashback kazanır.</div>
          <div><strong>•</strong> QR bağlantınız ve çift paneli giriş bilgileriniz otomatik gönderilir.</div>
          <div><strong>•</strong> Referans kodunuz yoksa alanı boş bırakabilirsiniz.</div>
        </div>
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
            <input type="text" name="event_title" class="form-control input-rounded" value="<?=h($formData['event_title'])?>" placeholder="Örn. Deniz & Efe Düğünü">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Etkinlik Tarihi</label>
            <input type="date" name="event_date" class="form-control input-rounded" value="<?=h($formData['event_date'])?>">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Referans Kodu</label>
            <input type="text" name="referral_code" class="form-control input-rounded" value="<?=h($formData['referral_code'])?>" placeholder="Bayi kodu">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Notunuz</label>
            <textarea name="notes" class="form-control input-rounded" rows="3" placeholder="Etkinliğe dair eklemek istediğiniz bilgiler"><?=h($formData['notes'])?></textarea>
          </div>
          <div class="col-12 d-flex justify-content-between align-items-center pt-3">
            <span class="muted small">Formu gönderdiğinizde ekibimiz sizinle iletişime geçer.</span>
            <button class="btn btn-brand" type="submit">QR Kodlarımı Oluştur</button>
          </div>
        </form>
      </div>
    </div>
  </section>
</main>

<footer class="py-4 border-top bg-white">
  <div class="container d-flex flex-wrap gap-2 justify-content-between small text-muted">
    <span>© <?=date('Y')?> <?=h(APP_NAME)?> — Wedding Share Teknoloji</span>
    <span>Destek: <a href="mailto:support@demozerosoft.com.tr" class="text-decoration-none">support@demozerosoft.com.tr</a></span>
  </div>
</footer>
</body></html>
