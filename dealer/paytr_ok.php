<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/dealer_auth.php';

$sessionDealer = dealer_user();
$loggedIn = (bool)$sessionDealer;
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ödeme Alındı — <?=h(APP_NAME)?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:#f5f7fb;color:#0f172a;font-family:'Inter','Segoe UI',system-ui,sans-serif;min-height:100vh;display:grid;place-items:center;padding:24px;}
  .card-lite{max-width:520px;width:100%;background:#fff;border-radius:20px;padding:32px;border:1px solid rgba(148,163,184,.2);box-shadow:0 30px 60px -35px rgba(15,23,42,.55);text-align:center;}
  .icon{width:64px;height:64px;border-radius:999px;background:rgba(14,165,181,.15);display:grid;place-items:center;margin:0 auto 16px;}
  .icon svg{width:32px;height:32px;color:#0ea5b5;}
  .btn-brand{background:#0ea5b5;border:none;color:#fff;border-radius:14px;padding:.65rem 1.4rem;font-weight:600;}
  .btn-brand:hover{background:#0b8b98;color:#fff;}
  .muted{color:#64748b;}
</style>
</head>
<body>
  <div class="card-lite">
    <div class="icon">
      <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="11" stroke="currentColor" stroke-width="2"/><path d="M7.5 12.5l3 3 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </div>
    <h3 class="fw-bold mb-2">Ödeme Talebiniz Alındı</h3>
    <p class="muted mb-4">Kart işleminiz PayTR tarafından başarıyla alındı. Finans ekibimiz kısa süre içinde onaylayarak bakiyenize yansıtacaktır.</p>
    <div class="d-flex gap-2 justify-content-center flex-wrap">
      <?php if ($loggedIn): ?>
        <a class="btn btn-brand" href="billing.php#topup">Bakiye Ekranına Dön</a>
      <?php else: ?>
        <a class="btn btn-brand" href="login.php">Bayi Paneline Giriş Yap</a>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
