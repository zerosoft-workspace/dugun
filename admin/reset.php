<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/login_header.php';

install_schema();

if (is_admin_logged_in()) {
  redirect('dashboard.php');
}

$email = trim($_GET['email'] ?? ($_POST['email'] ?? ''));
$code  = trim($_GET['code'] ?? ($_POST['code'] ?? ''));
$err = null;
$done = false;

if ($email === '' || $code === '' || !admin_reset_request_valid($email, $code)) {
  $err = 'Sıfırlama bağlantısı geçersiz veya süresi dolmuş. Lütfen yeniden bağlantı isteyin.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_or_die();
  $pass  = (string)($_POST['password'] ?? '');
  $pass2 = (string)($_POST['password_confirm'] ?? '');

  if (mb_strlen($pass) < 8) {
    $err = 'Şifre en az 8 karakter olmalıdır.';
  } elseif ($pass !== $pass2) {
    $err = 'Şifreler eşleşmiyor.';
  } elseif (!admin_complete_password_reset($email, $code, $pass)) {
    $err = 'Sıfırlama işlemi tamamlanamadı. Lütfen yeni bir bağlantı isteyin.';
  } else {
    $done = true;
  }
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Yeni Şifre Oluştur — <?=h(APP_NAME)?> Yönetim</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    <?=login_header_styles()?>
    body{margin:0;min-height:100vh;display:flex;flex-direction:column;background:radial-gradient(circle at top,#ecfeff 0%,#f8fafc 55%,#eef2ff 100%);font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;color:#0f172a;}
    .auth-layout{flex:1;width:100%;display:flex;align-items:center;justify-content:center;padding:2.5rem 1.5rem 3rem;}
    .card{width:100%;max-width:420px;border-radius:22px;border:1px solid rgba(148,163,184,.18);box-shadow:0 32px 90px -48px rgba(15,23,42,.45);padding:2.4rem;background:#fff;}
    .brand{font-weight:800;font-size:1.4rem;margin-bottom:.5rem;}
    .brand span{display:block;font-weight:600;font-size:.9rem;color:#64748b;}
    .form-control{border-radius:14px;border:1px solid rgba(148,163,184,.35);padding:.7rem 1rem;font-size:1rem;}
    .form-control:focus{border-color:#0ea5b5;box-shadow:0 0 0 .25rem rgba(14,165,181,.18);}
    .btn-brand{background:#0ea5b5;color:#fff;border:none;border-radius:14px;padding:.8rem 1rem;font-weight:700;font-size:1rem;}
    .btn-brand:hover{background:#0b8b98;color:#fff;}
  </style>
</head>
<body>
  <?php render_login_header(null); ?>
  <main class="auth-layout">
  <div class="card">
    <div class="brand"><?=h(APP_NAME)?> <span>Yönetim Paneli</span></div>
    <h1 class="h4 mb-3">Yeni Şifre Oluştur</h1>
    <?php if ($done): ?>
      <div class="alert alert-success">Şifreniz yenilendi. Şimdi giriş yapabilirsiniz.</div>
      <a class="btn-brand w-100" href="login.php">Giriş sayfasına git</a>
    <?php else: ?>
      <?php if ($err): ?>
        <div class="alert alert-danger"><?=$err?></div>
        <div class="mt-3"><a href="forgot.php">← Yeni bağlantı iste</a></div>
      <?php else: ?>
        <p class="text-muted mb-4">Güçlü bir şifre belirleyin ve aşağıdaki formu gönderin.</p>
        <form method="post" class="vstack gap-3">
          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="email" value="<?=h($email)?>">
          <input type="hidden" name="code" value="<?=h($code)?>">
          <div>
            <label class="form-label">Yeni Şifre</label>
            <input type="password" name="password" class="form-control" required minlength="8" placeholder="En az 8 karakter">
          </div>
          <div>
            <label class="form-label">Yeni Şifre (Tekrar)</label>
            <input type="password" name="password_confirm" class="form-control" required minlength="8">
          </div>
          <button type="submit" class="btn-brand">Şifremi Güncelle</button>
        </form>
        <div class="mt-3"><a href="forgot.php">← Yeni bağlantı iste</a></div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  </main>
</body>
</html>
