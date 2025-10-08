<?php
require_once __DIR__.'/_auth.php'; // global login + aktif düğün zorunlu
require_once __DIR__.'/../includes/couple_auth.php';

$forceReset = couple_current_requires_reset();
$err = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_or_die();
  $current = (string)($_POST['current'] ?? '');
  $new1    = (string)($_POST['new1'] ?? '');
  $new2    = (string)($_POST['new2'] ?? '');
  if ($new1 !== $new2)       $err = 'Yeni şifreler eşleşmiyor.';
  elseif (strlen($new1) < 6) $err = 'Yeni şifre en az 6 karakter olmalı.';
  else {
    if (!couple_update_password_current($current, $new1)) {
      $err = $forceReset ? 'Şifreniz güncellenemedi. Lütfen tekrar deneyin.' : 'Mevcut şifre hatalı.';
    } else {
      flash('ok','Şifreniz güncellendi.');
      redirect(BASE_URL.'/couple/index.php');
    }
  }
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Şifre Değiştir — <?=h(APP_NAME)?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{ --zs:#0ea5b5; --zs-soft:#e0f7fb; }
    body{ min-height:100vh; display:flex; align-items:center; justify-content:center; background:linear-gradient(180deg,#f0fbfd,#fff) }
    .cardx{ width:100%; max-width:520px; background:#fff; border:1px solid #e9eef5; border-radius:18px; box-shadow:0 10px 30px rgba(2,6,23,.06) }
    .btn-zs{ background:var(--zs); color:#fff; border:none; border-radius:12px; padding:.65rem 1rem; font-weight:700 }
  </style>
</head>
<body>
  <div class="cardx p-4">
    <h5 class="mb-3">Şifre Değiştir</h5>
    <?php flash_box(); ?>
    <?php if ($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif; ?>

    <form method="post" class="vstack gap-2">
      <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
      <?php if (!$forceReset): ?>
        <label class="form-label">Mevcut Şifre</label>
        <input class="form-control" type="password" name="current" required>
      <?php else: ?>
        <div class="alert alert-info small">
          <strong>Hoş geldiniz!</strong> Güvenliğiniz için yeni bir şifre belirleyin. Mevcut şifreyi girmeniz gerekmiyor.
        </div>
      <?php endif; ?>
      <div class="row g-2">
        <div class="col-md-6">
          <label class="form-label">Yeni Şifre</label>
          <input class="form-control" type="password" name="new1" minlength="6" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Yeni Şifre (Tekrar)</label>
          <input class="form-control" type="password" name="new2" minlength="6" required>
        </div>
      </div>
      <button class="btn btn-zs mt-3 w-100">Güncelle</button>
      <div class="mt-2 text-center"><a class="small" href="<?=h(BASE_URL)?>/couple/index.php">Panele dön</a></div>
    </form>
  </div>
</body>
</html>
