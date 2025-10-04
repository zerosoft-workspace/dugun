<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/dealer_auth.php';
require_once __DIR__.'/../includes/dealers.php';

install_schema();

if (isset($_GET['logout'])) {
  dealer_logout();
  flash('ok', 'Oturum kapatıldı.');
}

if (dealer_user()) {
  redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $pass  = (string)($_POST['password'] ?? '');
  if (dealer_login($email, $pass)) {
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
<title><?=h(APP_NAME)?> — Bayi Girişi</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:#f4f6fb;}
  .login-card{max-width:420px;margin:80px auto;padding:32px;border-radius:16px;background:#fff;box-shadow:0 12px 40px rgba(15,23,42,.12);}
</style>
</head>
<body>
<div class="login-card">
  <h2 class="h4 mb-3 text-center">Bayi Paneli</h2>
  <p class="text-muted small text-center mb-4">Bayi kodlarınızı ve düğünlerinizi buradan yönetin.</p>
  <?php flash_box(); ?>
  <form method="post" class="vstack gap-3">
    <div>
      <label class="form-label">E-posta</label>
      <input type="email" name="email" class="form-control" required>
    </div>
    <div>
      <label class="form-label">Şifre</label>
      <input type="password" name="password" class="form-control" required>
    </div>
    <button class="btn btn-primary w-100" type="submit">Giriş Yap</button>
  </form>
  <div class="text-center mt-3">
    <a class="small" href="apply.php">Bayi olmak için başvurun</a>
  </div>
</div>
</body>
</html>
