<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/couple_auth.php';

install_schema();

if (!couple_is_global_logged_in()) {
  redirect(BASE_URL.'/couple/login.php');
}
$g = couple_global_user();
$email = $g['email'] ?? '';
if ($email === '') {
  couple_global_logout();
  redirect(BASE_URL.'/couple/login.php');
}

// Bu email'e ait aktif event’ler
$st = pdo()->prepare("
  SELECT id, title, event_date
    FROM events
   WHERE is_active=1 AND LOWER(couple_username)=?
   ORDER BY id DESC
");
$st->execute([$email]);
$events = $st->fetchAll();

// Seçim işlemi
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_or_die();
  $choose = (int)($_POST['event_id'] ?? 0);
  if ($choose>0 && couple_set_current_event($choose)) {
    redirect(BASE_URL.'/couple/index.php');
  }
  flash('err','Seçim yapılamadı.');
  redirect($_SERVER['PHP_SELF']);
}

?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Düğün Seç — <?=h(APP_NAME)?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{ --zs:#0ea5b5; --zs-soft:#e0f7fb; }
    body{ min-height:100vh; background:linear-gradient(180deg,var(--zs-soft),#fff) }
    .cardx{ max-width:800px; margin:40px auto; background:#fff; border:1px solid #e9eef5; border-radius:18px; box-shadow:0 10px 30px rgba(2,6,23,.06) }
    .btn-zs{ background:var(--zs); color:#fff; border:none; border-radius:10px; padding:.55rem 1rem; font-weight:700 }
  </style>
</head>
<body>
  <div class="cardx p-4">
    <h5 class="mb-3">Düğün Seç</h5>
    <p class="text-muted">Giriş yaptınız: <b><?=h($email)?></b>. Bu e-posta ile aşağıdaki aktif düğün(ler) bulundu:</p>

    <?php flash_box(); ?>

    <?php if (!$events): ?>
      <div class="alert alert-warning">Aktif düğün bulunamadı. Lütfen yönetici ile iletişime geçiniz.</div>
      <a class="btn btn-outline-secondary" href="<?=h(BASE_URL)?>/couple/logout.php">Çıkış</a>
    <?php else: ?>
      <form method="post" class="vstack gap-3">
        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
        <div class="list-group">
          <?php foreach($events as $e): ?>
            <label class="list-group-item d-flex align-items-center justify-content-between">
              <div>
                <div class="fw-semibold"><?=h($e['title'])?></div>
                <div class="small text-muted"><?= $e['event_date'] ? h($e['event_date']) : 'Tarih belirtilmemiş' ?></div>
              </div>
              <input class="form-check-input" type="radio" name="event_id" value="<?=$e['id']?>" required>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-zs">Devam Et</button>
          <a class="btn btn-outline-secondary" href="<?=h(BASE_URL)?>/couple/logout.php">Çıkış</a>
        </div>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>
