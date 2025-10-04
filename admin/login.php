<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Çıkış
if (isset($_GET['logout'])) {
  admin_logout();
  flash('ok', 'Çıkış yapıldı.');
  redirect('login.php');
}

// Zaten girişliyse dashboard'a
if (is_admin_logged_in()) {
  $next = $_GET['next'] ?? 'dashboard.php';
  redirect($next);
}

$firstRun = ((int)pdo()->query("SELECT COUNT(*) FROM users")->fetchColumn()) === 0;
$mode = $firstRun ? 'setup' : 'login';
$err = null;

if ($mode === 'setup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_or_die();

  $name  = trim($_POST['name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $pass  = (string)($_POST['password'] ?? '');
  $pass2 = (string)($_POST['password_confirm'] ?? '');

  if (mb_strlen($name) < 3) {
    $err = 'Lütfen en az 3 karakterlik bir ad girin.';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $err = 'Geçerli bir e-posta adresi girin.';
  } elseif (mb_strlen($pass) < 8) {
    $err = 'Şifre en az 8 karakter olmalıdır.';
  } elseif ($pass !== $pass2) {
    $err = 'Şifreler eşleşmiyor.';
  } else {
    try {
      pdo()->prepare("INSERT INTO users (email,password_hash,name,role,created_at,updated_at) VALUES (?,?,?,?,?,?)")
          ->execute([$email, password_hash($pass, PASSWORD_DEFAULT), $name, 'superadmin', now(), now()]);
      admin_login($email, $pass);
      flash('ok', 'İlk yönetici hesabı hazır!');
      redirect('dashboard.php');
    } catch (Throwable $e) {
      $err = 'Hesap oluşturulamadı. Lütfen bilgileri kontrol edin.';
    }
  }
}

// Giriş denemesi
if ($mode === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_or_die(); // CSRF kontrolü

  $email = trim($_POST['email'] ?? '');
  $pass  = (string)($_POST['password'] ?? '');
  $next  = $_POST['next'] ?? 'dashboard.php';

  if ($email === '' || $pass === '') {
    $err = 'E-posta ve şifre zorunludur.';
  } else {
    if (admin_login($email, $pass)) {
      redirect($next);
    } else {
      $err = 'E-posta veya şifre hatalı.';
    }
  }
}

// next parametresini koru
$next = $_GET['next'] ?? ($_POST['next'] ?? 'dashboard.php');
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $mode === 'setup' ? 'İlk Yönetici Kurulumu' : 'Yönetici Girişi' ?> — <?=h(APP_NAME)?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{ --brand:#0ea5b5; --brand-dark:#0b8b98; --ink:#0f172a; --muted:#64748b; }
    body{ min-height:100vh; margin:0; background:linear-gradient(135deg,rgba(14,165,181,.18),rgba(14,165,181,.04)); font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif; display:flex; align-items:center; justify-content:center; padding:2rem; color:var(--ink); }
    .auth-card{ width:100%; max-width:460px; background:#fff; border-radius:22px; border:1px solid rgba(148,163,184,.18); box-shadow:0 32px 60px -30px rgba(15,23,42,.4); padding:2.8rem 2.4rem; }
    .brand{ font-weight:800; font-size:1.4rem; letter-spacing:.3px; }
    .subtitle{ color:var(--muted); font-size:.95rem; }
    .btn-brand{ background:var(--brand); color:#fff; border:none; border-radius:12px; padding:.7rem 1.1rem; font-weight:600; }
    .btn-brand:hover{ background:var(--brand-dark); color:#fff; }
    label{ font-weight:600; color:var(--muted); }
    .form-control{ border-radius:12px; border:1px solid rgba(148,163,184,.35); padding:.65rem .85rem; }
    .form-control:focus{ border-color:var(--brand); box-shadow:0 0 0 .2rem rgba(14,165,181,.15); }
    .alert{ border-radius:12px; }
    .first-run-badge{ display:inline-flex; align-items:center; gap:.4rem; background:rgba(14,165,181,.12); color:var(--brand-dark); padding:.35rem .85rem; border-radius:999px; font-weight:600; font-size:.82rem; }
  </style>
</head>
<body>
  <div class="auth-card">
    <div class="mb-4 text-center">
      <?php if ($mode === 'setup'): ?>
        <span class="first-run-badge">🚀 İlk Kurulum</span>
      <?php endif; ?>
      <div class="brand mt-2"><?=h(APP_NAME)?></div>
      <div class="subtitle">Yönetim Paneli</div>
    </div>

    <?php if ($m = flash('ok')): ?>
      <div class="alert alert-success"><?=$m?></div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div class="alert alert-danger"><?=$err?></div>
    <?php endif; ?>

    <?php if ($mode === 'setup'): ?>
      <form method="post" class="vstack gap-3">
        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
        <div>
          <label class="form-label">Ad Soyad</label>
          <input class="form-control" type="text" name="name" required autofocus placeholder="Örn. Ayşe Yılmaz">
        </div>
        <div>
          <label class="form-label">E-posta</label>
          <input class="form-control" type="email" name="email" required placeholder="ornek@firma.com">
        </div>
        <div>
          <label class="form-label">Şifre</label>
          <input class="form-control" type="password" name="password" required placeholder="En az 8 karakter">
        </div>
        <div>
          <label class="form-label">Şifre (Tekrar)</label>
          <input class="form-control" type="password" name="password_confirm" required>
        </div>
        <button class="btn btn-brand mt-2 w-100">İlk Süperadmini Oluştur</button>
      </form>
      <p class="subtitle text-center mt-3">Bu hesap süperadmin yetkisine sahip olacak ve diğer yöneticileri davet edebilecek.</p>
    <?php else: ?>
      <form method="post" class="vstack gap-3">
        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="next" value="<?=h($next)?>">
        <div>
          <label class="form-label">E-posta</label>
          <input class="form-control" type="email" name="email" required autofocus>
        </div>
        <div>
          <label class="form-label">Şifre</label>
          <input class="form-control" type="password" name="password" required>
        </div>
        <button class="btn btn-brand mt-2 w-100">Giriş Yap</button>
      </form>
    <?php endif; ?>

    <div class="mt-4 text-center">
      <a class="text-decoration-none" href="../index.php">← Ana sayfaya dön</a>
    </div>
  </div>
</body>
</html>
