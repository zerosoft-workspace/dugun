<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/guests.php';
require_once __DIR__.'/../includes/login_header.php';

install_schema();

$err = null;
$sent = false;
$emailInput = trim($_POST['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_or_die();
  if (!filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
    $err = 'Lütfen geçerli bir e-posta adresi yazın.';
  } else {
    guest_send_password_reset($emailInput);
    $sent = true;
  }
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Şifremi Unuttum — <?=h(APP_NAME)?> Misafir Paneli</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    <?=login_header_styles()?>
    body{margin:0;min-height:100vh;display:flex;flex-direction:column;background:radial-gradient(circle at 15% 20%,rgba(14,165,181,.16),rgba(148,163,184,.08) 60%,#f8fafc);font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;color:#0f172a;}
    .auth-layout{flex:1;width:100%;display:flex;align-items:center;justify-content:center;padding:2.5rem 1.5rem 3rem;}
    .card{width:100%;max-width:440px;border-radius:24px;border:1px solid rgba(148,163,184,.18);box-shadow:0 36px 110px -58px rgba(15,23,42,.5);padding:2.6rem;background:#fff;}
    .brand{font-weight:800;font-size:1.5rem;margin-bottom:.5rem;letter-spacing:.18rem;}
    .brand span{display:block;font-weight:600;font-size:.95rem;color:#5f6c7b;letter-spacing:0;}
    .form-control{border-radius:14px;border:1px solid rgba(148,163,184,.32);padding:.8rem 1rem;font-size:1rem;}
    .form-control:focus{border-color:#0ea5b5;box-shadow:0 0 0 .25rem rgba(14,165,181,.18);}
    .btn-brand{background:linear-gradient(135deg,#0ea5b5,#0b8b98);color:#fff;border:none;border-radius:14px;padding:.85rem 1rem;font-weight:700;font-size:1rem;}
    .btn-brand:hover{transform:translateY(-1px);box-shadow:0 18px 32px -22px rgba(14,165,181,.6);color:#fff;}
  </style>
</head>
<body>
  <?php render_login_header('guest'); ?>
  <main class="auth-layout">
  <div class="card">
    <div class="brand">BİKARE <span>Misafir Paneli</span></div>
    <h1 class="h4 mb-3">Şifremi Unuttum</h1>
    <p class="text-muted mb-4">Misafir hesabınızla eşleşen e-posta adresini yazın. Hesabınız etkin ise şifre yenileme bağlantısı göndereceğiz.</p>
    <?php if ($err): ?>
      <div class="alert alert-danger"><?=$err?></div>
    <?php elseif ($sent): ?>
      <div class="alert alert-success">Eğer bu adres doğrulanmış bir misafir hesabına aitse birkaç dakika içinde şifre sıfırlama bağlantısı alacaksınız.</div>
    <?php endif; ?>
    <form method="post" class="vstack gap-3">
      <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
      <div>
        <label class="form-label">E-posta</label>
        <input type="email" name="email" value="<?=h($emailInput)?>" class="form-control" required>
      </div>
      <button type="submit" class="btn-brand">Bağlantı Gönder</button>
    </form>
    <div class="mt-3"><a href="guest_login.php">← Giriş ekranına dön</a></div>
  </div>
  </main>
</body>
</html>
