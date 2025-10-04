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
<title>Ödeme Başarısız — <?=h(APP_NAME)?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:#fff5f5;color:#111827;font-family:'Inter','Segoe UI',system-ui,sans-serif;min-height:100vh;display:grid;place-items:center;padding:24px;}
  .card-lite{max-width:520px;width:100%;background:#fff;border-radius:20px;padding:32px;border:1px solid rgba(248,113,113,.35);box-shadow:0 30px 60px -38px rgba(220,38,38,.55);text-align:center;}
  .icon{width:64px;height:64px;border-radius:999px;background:rgba(248,113,113,.15);display:grid;place-items:center;margin:0 auto 16px;}
  .icon svg{width:32px;height:32px;color:#ef4444;}
  .btn-brand{background:#ef4444;border:none;color:#fff;border-radius:14px;padding:.65rem 1.4rem;font-weight:600;}
  .btn-brand:hover{background:#dc2626;color:#fff;}
  .muted{color:#6b7280;}
</style>
</head>
<body>
  <div class="card-lite">
    <div class="icon">
      <svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="11" stroke="currentColor" stroke-width="2"/><path d="M12 7v6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="16" r="1.5" fill="currentColor"/></svg>
    </div>
    <h3 class="fw-bold mb-2">Ödeme Tamamlanamadı</h3>
    <p class="muted mb-4">Kart provizyonu sırasında bir sorun oluştu. Lütfen kart bilgilerinizi kontrol edip tekrar deneyin. Sorun devam ederse yöneticinizle iletişime geçebilirsiniz.</p>
    <div class="d-flex gap-2 justify-content-center flex-wrap">
      <?php if ($loggedIn): ?>
        <a class="btn btn-brand" href="billing.php#topup">Tekrar Dene</a>
      <?php else: ?>
        <a class="btn btn-brand" href="login.php">Bayi Paneline Dön</a>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
