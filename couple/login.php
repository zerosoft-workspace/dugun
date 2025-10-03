<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/couple_auth.php';

install_schema();

// Zaten girişli ise:
if (couple_is_global_logged_in()) {
  $eid = couple_current_event_id();
  if ($eid > 0) redirect(BASE_URL.'/couple/index.php');
  redirect(BASE_URL.'/couple/switch_event.php');
}

$err = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_or_die();

  $email = trim($_POST['email'] ?? '');
  $pass  = (string)($_POST['password'] ?? '');

  $events = couple_login_global($email, $pass);
  if (empty($events)) {
    $err = 'E-posta veya şifre hatalı ya da aktif etkinlik bulunamadı.';
  } else {
    if (count($events) === 1) {
      couple_set_current_event((int)$events[0]['id']);
      redirect(BASE_URL.'/couple/index.php');
    } else {
      // Çoklu etkinlik: seçme sayfasına
      // Geçici olarak listeyi session’a koymuyoruz; switch_event.php email üzerinden bulur.
      redirect(BASE_URL.'/couple/switch_event.php');
    }
  }
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Giriş — <?=h(APP_NAME)?></title>
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
      <div class="text-muted small">Çift Girişi</div>
    </div>

    <?php flash_box(); ?>
    <?php if ($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif; ?>

    <form method="post" class="vstack gap-2">
      <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
      <label class="form-label">E-posta</label>
      <input class="form-control" type="email" name="email" required autofocus>
      <label class="form-label mt-2">Şifre</label>
      <input class="form-control" type="password" name="password" required>
      <button class="btn btn-zs mt-3 w-100">Giriş Yap</button>
    </form>

    <div class="mt-3 text-center">
      <a class="small" href="<?=h(BASE_URL)?>">Anasayfa</a>
      <span class="mx-2">•</span>
      <a class="small" href="<?=h(BASE_URL)?>/couple/logout.php">Çıkış</a>
    </div>
  </div>
</body>
</html>
