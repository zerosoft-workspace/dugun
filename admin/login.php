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

// İlk admin yoksa oluşturmak için (ilk kurulumda 1 kez aktif edin, sonra bu satırı silebilirsiniz)
// ensure_first_admin('admin@site.com', 'Sifre123', 'Yönetici');

$err = null;

// Giriş denemesi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_or_die(); // CSRF kontrolü

  $email = trim($_POST['email'] ?? '');
  $pass  = (string)($_POST['password'] ?? '');
  $next  = $_POST['next'] ?? 'dashboard.php';

  if ($email === '' || $pass === '') {
    $err = 'E-posta ve şifre zorunludur.';
  } else {
    if (admin_login($email, $pass)) {
      // Başarılı → yönlendir
      // İsteğe bağlı: admin adını session'a yazmak istiyorsanız:
      // $_SESSION['uname'] = admin_user()['name'] ?? $email;
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
  <title>Yönetici Girişi — <?=h(APP_NAME)?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{ --zs:#0ea5b5; }
    body{ min-height:100vh; display:flex; align-items:center; justify-content:center; background:linear-gradient(180deg,#f0fbfd,#fff) }
    .cardx{ width:100%; max-width:420px; background:#fff; border:1px solid #e9eef5; border-radius:18px; box-shadow:0 10px 30px rgba(2,6,23,.06) }
    .btn-zs{ background:var(--zs); color:#fff; border:none; border-radius:12px; padding:.65rem 1rem; font-weight:700 }
    .brand{ font-weight:800; letter-spacing:.2px; }
  </style>
</head>
<body>
  <div class="cardx p-4">
    <div class="mb-3 text-center">
      <div class="brand"><?=h(APP_NAME)?></div>
      <div class="text-muted small">Yönetici Paneli</div>
    </div>

    <?php if ($m = flash('ok')): ?>
      <div class="alert alert-success"><?=$m?></div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div class="alert alert-danger"><?=$err?></div>
    <?php endif; ?>

    <form method="post" class="vstack gap-2">
      <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="next" value="<?=h($next)?>">
      <label class="form-label">E-posta</label>
      <input class="form-control" type="email" name="email" required autofocus>
      <label class="form-label mt-2">Şifre</label>
      <input class="form-control" type="password" name="password" required>
      <button class="btn btn-zs mt-3 w-100">Giriş Yap</button>
    </form>

    <div class="mt-3 text-center">
      <a class="small" href="../index.php">Ana sayfa</a>
    </div>
  </div>
</body>
</html>
