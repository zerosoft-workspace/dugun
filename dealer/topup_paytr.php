<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/dealers.php';
require_once __DIR__.'/../includes/dealer_auth.php';

install_schema();

dealer_require_login();
$sessionDealer = dealer_user();
$dealerId = (int)($sessionDealer['id'] ?? 0);
if ($dealerId <= 0) {
  redirect('login.php');
}

dealer_refresh_session($dealerId);
$topupId = (int)($_GET['topup_id'] ?? 0);
if ($topupId <= 0) {
  flash('err', 'Geçersiz yükleme talebi.');
  redirect('billing.php#topup');
}
$topup = dealer_topup_get($topupId);
if (!$topup || (int)$topup['dealer_id'] !== $dealerId) {
  flash('err', 'Yükleme talebi bulunamadı.');
  redirect('billing.php#topup');
}
$status = $topup['status'];
if ($status === DEALER_TOPUP_STATUS_CANCELLED) {
  flash('err', 'Bu yükleme talebi iptal edildi.');
  redirect('billing.php#topup');
}
if ($status === DEALER_TOPUP_STATUS_COMPLETED) {
  flash('ok', 'Yükleme zaten tamamlanmış.');
  redirect('billing.php#topup');
}
if ($status === DEALER_TOPUP_STATUS_AWAITING_REVIEW) {
  flash('ok', 'Ödeme alındı, yönetici onayı bekleniyor.');
  redirect('billing.php#topup');
}
$token = $topup['paytr_token'] ?? null;
if (!$token) {
  flash('err', 'Ödeme formu hazırlanamadı. Lütfen yeni bir talep oluşturun.');
  redirect('billing.php#topup');
}
$amountTL = format_currency($topup['amount_cents']);
$createdAt = $topup['created_at'] ?? null;
$createdLabel = $createdAt ? date('d.m.Y H:i', strtotime($createdAt)) : date('d.m.Y H:i');
$reference = $topup['merchant_oid'] ?: '—';
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h(APP_NAME)?> — PayTR Ödeme</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://www.paytr.com/js/iframeResizer.min.js"></script>
<style>
  :root{
    --brand:#0ea5b5;
    --brand-dark:#0b8b98;
    --ink:#0f172a;
    --muted:#64748b;
    --soft:#f1f7fb;
  }
  body{background:var(--soft);color:var(--ink);font-family:'Inter','Segoe UI',system-ui,sans-serif;min-height:100vh;}
  a{color:var(--brand);} a:hover{color:var(--brand-dark);}
  .dealer-topnav{background:#fff;border-bottom:1px solid rgba(148,163,184,.22);box-shadow:0 12px 30px rgba(15,23,42,.05);}
  .dealer-topnav .navbar-brand{font-weight:700;color:var(--ink);}
  .dealer-topnav .nav-link{color:var(--muted);font-weight:600;border-radius:12px;padding:.45rem .95rem;}
  .dealer-topnav .nav-link:hover{color:var(--brand-dark);background:rgba(14,165,181,.1);}
  .dealer-topnav .nav-link.active{color:var(--brand);background:rgba(14,165,181,.18);}
  .dealer-topnav .badge-soft{background:rgba(14,165,181,.12);color:var(--brand-dark);border-radius:999px;padding:.3rem .85rem;font-weight:600;font-size:.85rem;}
  .payment-hero{padding:2.4rem 0 1.8rem;}
  .card-lite{border-radius:20px;background:#fff;border:1px solid rgba(148,163,184,.16);box-shadow:0 22px 45px -28px rgba(15,23,42,.45);}
  .meta-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;}
  .meta-card{padding:1.25rem;border-radius:16px;background:linear-gradient(150deg,#fff,rgba(14,165,181,.08));}
  .meta-card h6{font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:.35rem;}
  .meta-card strong{font-size:1.4rem;display:block;}
  .meta-card span{color:var(--muted);font-size:.85rem;}
  .iframe-wrap{position:relative;overflow:hidden;border-radius:18px;border:1px solid rgba(148,163,184,.22);background:#fff;}
  .skeleton{position:absolute;inset:0;display:grid;place-items:center;background:linear-gradient(90deg,#f2f5f9 25%,#e8edf4 37%,#f2f5f9 63%);background-size:400% 100%;animation:shimmer 1.2s infinite;}
  @keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
  .btn-brand{background:var(--brand);border:none;color:#fff;border-radius:14px;padding:.6rem 1.3rem;font-weight:600;}
  .btn-brand:hover{background:var(--brand-dark);color:#fff;}
  .lead-muted{color:var(--muted);}
</style>
</head>
<body>
<header class="dealer-header">
  <nav class="dealer-topnav navbar navbar-expand-lg py-3">
    <div class="container">
      <a class="navbar-brand" href="dashboard.php"><?=h(APP_NAME)?> — Bayi Paneli</a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#dealerNav" aria-controls="dealerNav" aria-expanded="false" aria-label="Menü">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="dealerNav">
        <ul class="navbar-nav me-auto mb-2 mb-lg-0">
          <li class="nav-item"><a class="nav-link" href="dashboard.php">Genel Bakış</a></li>
          <li class="nav-item"><a class="nav-link" href="dashboard.php#venues">Salonlar</a></li>
          <li class="nav-item"><a class="nav-link active" href="billing.php">Bakiye & Paketler</a></li>
        </ul>
        <div class="d-flex align-items-center gap-3 mb-2 mb-lg-0">
          <span class="badge-soft"><?=h($sessionDealer['name'] ?? '')?></span>
          <a class="text-decoration-none fw-semibold" href="login.php?logout=1">Çıkış</a>
        </div>
      </div>
    </div>
  </nav>
</header>
<main class="payment-main">
  <div class="container py-4">
    <?php flash_box(); ?>
    <section class="payment-hero mb-4">
      <div class="card-lite p-4 p-lg-5 mb-4">
        <div class="meta-grid">
          <div class="meta-card">
            <h6>Yükleme Tutarı</h6>
            <strong><?=h($amountTL)?></strong>
            <span><?=h($createdLabel)?> tarihinde oluşturuldu</span>
          </div>
          <div class="meta-card">
            <h6>Talep No</h6>
            <strong><?=h($reference)?></strong>
            <span>PayTR üzerinden ödeme tamamlandığında yönetici onayına düşer.</span>
          </div>
          <div class="meta-card">
            <h6>Durum</h6>
            <strong><?=h(dealer_topup_status_label($status))?></strong>
            <span>Ödeme formunu doldurarak işlemi tamamlayın.</span>
          </div>
        </div>
      </div>
      <div class="card-lite p-4 p-lg-5">
        <div class="mb-3">
          <h5 class="mb-1">Kart ile Ödeme</h5>
          <p class="lead-muted mb-0">Ödeme PayTR güvencesiyle alınır. 3D Secure doğrulaması gerekebilir.</p>
        </div>
        <div class="iframe-wrap mb-3">
          <div class="skeleton" id="skeleton">
            <div class="text-center">
              <div class="spinner-border text-info" role="status" style="--bs-spinner-width:2.2rem;--bs-spinner-height:2.2rem"></div>
              <div class="mt-2 lead-muted">Ödeme formu yükleniyor…</div>
            </div>
          </div>
          <iframe
            src="https://www.paytr.com/odeme/guvenli/<?=h($token)?>"
            id="paytriframe"
            frameborder="0"
            scrolling="no"
            style="width:100%;min-height:680px;border:0;border-radius:18px;">
          </iframe>
        </div>
        <div class="d-flex gap-2 flex-wrap justify-content-between align-items-center">
          <div class="lead-muted">Ödeme sonrası bu sayfayı kapatarak bakiye ekranına dönebilirsiniz.</div>
          <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="billing.php#topup">Bakiye Ekranına Dön</a>
          </div>
        </div>
      </div>
    </section>
  </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const iframe = document.getElementById('paytriframe');
  const skel = document.getElementById('skeleton');
  iframe.addEventListener('load', ()=>{ if(skel){ skel.style.display='none'; } });
  iFrameResize({}, '#paytriframe');
</script>
</body>
</html>
