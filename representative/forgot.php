<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/representative_auth.php';

install_schema();

if (representative_user()) {
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
    representative_send_password_reset($email);
    $sent = true;
  }
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Şifremi Unuttum — <?=h(APP_NAME)?> Temsilci Paneli</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem;background:linear-gradient(160deg,rgba(14,165,181,.15),#f8fafc);font-family:'Inter','Segoe UI',system-ui,sans-serif;color:#0f172a;}
    .card{width:100%;max-width:420px;background:#fff;border-radius:24px;box-shadow:0 40px 110px -60px rgba(15,23,42,.45);padding:2.4rem;border:1px solid rgba(148,163,184,.16);}
    .brand{display:flex;align-items:center;gap:.6rem;margin-bottom:1.5rem;}
    .brand span{display:inline-flex;width:40px;height:40px;border-radius:12px;background:rgba(14,165,181,.12);align-items:center;justify-content:center;font-weight:700;color:#0ea5b5;}
    .form-control{border-radius:14px;border:1px solid rgba(148,163,184,.3);padding:.75rem 1rem;}
    .form-control:focus{border-color:#0ea5b5;box-shadow:0 0 0 .2rem rgba(14,165,181,.15);}
    .btn-brand{background:#0ea5b5;color:#fff;border:none;border-radius:14px;padding:.75rem 1rem;font-weight:600;}
    .btn-brand:hover{background:#0b8b98;color:#fff;}
  </style>
</head>
<body>
  <div class="card">
    <div class="brand">
      <span><?=h(mb_strtoupper(mb_substr(APP_NAME, 0, 2, 'UTF-8')))?></span>
      <div>
        <strong><?=h(APP_NAME)?> Temsilci Paneli</strong><br>
        <small class="text-muted">Bayilerinizle olan tüm süreci tek yerden yönetin.</small>
      </div>
    </div>
    <h1 class="h5 mb-3">Şifremi Unuttum</h1>
    <p class="text-muted mb-4">Aktif temsilci hesabınıza kayıtlı e-posta adresini yazın. Şifre sıfırlama bağlantısını e-posta ile göndereceğiz.</p>
    <?php if ($err): ?>
      <div class="alert alert-danger"><?=$err?></div>
    <?php elseif ($sent): ?>
      <div class="alert alert-success">Eğer bu adres aktif bir temsilci hesabına aitse kısa süre içinde şifre sıfırlama bağlantısı alacaksınız.</div>
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
