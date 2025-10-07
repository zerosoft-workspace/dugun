<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/auth.php';

install_schema();

if (is_admin_logged_in()) {
  redirect('dashboard.php');
}

$err = null;
$sent = false;
$email = trim($_POST['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_or_die();
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $err = 'Lütfen geçerli bir e-posta adresi girin.';
  } else {
    admin_send_password_reset($email);
    $sent = true;
  }
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Şifremi Unuttum — <?=h(APP_NAME)?> Yönetim</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2.5rem;background:radial-gradient(circle at top,#ecfeff 0%,#f8fafc 55%,#eef2ff 100%);font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;color:#0f172a;}
    .card{width:100%;max-width:420px;border-radius:22px;border:1px solid rgba(148,163,184,.18);box-shadow:0 32px 90px -48px rgba(15,23,42,.45);padding:2.4rem;background:#fff;}
    .brand{font-weight:800;font-size:1.4rem;margin-bottom:.5rem;}
    .brand span{display:block;font-weight:600;font-size:.9rem;color:#64748b;}
    .form-control{border-radius:14px;border:1px solid rgba(148,163,184,.35);padding:.7rem 1rem;font-size:1rem;}
    .form-control:focus{border-color:#0ea5b5;box-shadow:0 0 0 .25rem rgba(14,165,181,.18);}
    .btn-brand{background:#0ea5b5;color:#fff;border:none;border-radius:14px;padding:.8rem 1rem;font-weight:700;font-size:1rem;}
    .btn-brand:hover{background:#0b8b98;color:#fff;}
    .text-muted{color:#64748b!important;}
  </style>
</head>
<body>
  <div class="card">
    <div class="brand"><?=h(APP_NAME)?> <span>Yönetim Paneli</span></div>
    <h1 class="h4 mb-3">Şifremi Unuttum</h1>
    <p class="text-muted mb-4">E-posta adresinizi yazın. Hesabınız bulunuyorsa bir saat içinde kullanılabilecek şifre sıfırlama bağlantısı göndereceğiz.</p>
    <?php if ($err): ?>
      <div class="alert alert-danger"><?=$err?></div>
    <?php elseif ($sent): ?>
      <div class="alert alert-success">Eğer bu e-posta sistemimizde kayıtlıysa birkaç dakika içinde şifre sıfırlama bağlantısı alacaksınız.</div>
    <?php endif; ?>
    <form method="post" class="vstack gap-3">
      <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
      <div>
        <label class="form-label">E-posta</label>
        <input type="email" name="email" value="<?=h($email)?>" class="form-control" required>
      </div>
      <button type="submit" class="btn-brand">Bağlantı Gönder</button>
    </form>
    <div class="mt-3"><a href="login.php">← Giriş ekranına dön</a></div>
  </div>
</body>
</html>
