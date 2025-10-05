<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/representatives.php';
require_once __DIR__.'/../includes/representative_auth.php';

install_schema();

if (isset($_GET['logout'])) {
  representative_logout();
  flash('ok', 'Oturum kapatıldı.');
}

if (representative_user()) {
  redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $password = (string)($_POST['password'] ?? '');
  if (representative_login($email, $password)) {
    $next = $_GET['next'] ?? 'dashboard.php';
    redirect($next);
  } else {
    flash('err', 'Giriş başarısız. Bilgilerinizi kontrol edin.');
  }
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h(APP_NAME)?> — Temsilci Girişi</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{--brand:#0ea5b5;--brand-dark:#0b8b98;--ink:#0f172a;--muted:#5f6c7b;}
    body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem;background:linear-gradient(160deg,rgba(14,165,181,.15),#f8fafc);font-family:'Inter','Segoe UI',system-ui,sans-serif;color:var(--ink);}
    .auth-card{width:100%;max-width:480px;background:#fff;border-radius:24px;box-shadow:0 48px 120px -60px rgba(15,23,42,.45);padding:2.5rem;border:1px solid rgba(148,163,184,.16);}
    .form-control{border-radius:14px;border:1px solid rgba(148,163,184,.3);padding:.75rem 1rem;}
    .form-control:focus{border-color:var(--brand);box-shadow:0 0 0 .2rem rgba(14,165,181,.15);}
    .btn-brand{background:var(--brand);color:#fff;border:none;border-radius:14px;padding:.75rem 1rem;font-weight:600;}
    .btn-brand:hover{background:var(--brand-dark);color:#fff;}
    .brand{display:flex;align-items:center;gap:.6rem;margin-bottom:1.5rem;}
    .brand span{display:inline-flex;width:40px;height:40px;border-radius:12px;background:rgba(14,165,181,.12);align-items:center;justify-content:center;font-weight:700;color:var(--brand);}
    .alert{border-radius:14px;}
  </style>
</head>
<body>
  <div class="auth-card">
    <div class="brand">
      <span><?=h(mb_strtoupper(mb_substr(APP_NAME, 0, 2, 'UTF-8')))?></span>
      <div>
        <strong><?=h(APP_NAME)?> Temsilci Paneli</strong><br>
        <small class="text-muted">Bayi finans hareketlerini ve müşteri notlarını takip edin.</small>
      </div>
    </div>
    <?php flash_box(); ?>
    <form method="post" class="vstack gap-3">
      <div>
        <label class="form-label">E-posta</label>
        <input type="email" class="form-control" name="email" required>
      </div>
      <div>
        <label class="form-label">Şifre</label>
        <input type="password" class="form-control" name="password" required>
      </div>
      <button class="btn-brand" type="submit">Temsilci Paneline Giriş Yap</button>
    </form>
  </div>
</body>
</html>
