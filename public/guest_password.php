<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/guests.php';

install_schema();

$token = trim($_POST['token'] ?? ($_GET['token'] ?? ''));
if ($token === '') {
  flash('err', 'Şifre belirleme bağlantısı geçersiz.');
  header('Location: '.BASE_URL);
  exit;
}

$profile = guest_profile_find_by_password_token($token);
if (!$profile) {
  flash('err', 'Bu bağlantının süresi dolmuş olabilir. Lütfen e-postanızı doğrulayarak yeni bir bağlantı isteyin.');
  header('Location: '.BASE_URL.'/public/guest_login.php');
  exit;
}

$eventStmt = pdo()->prepare('SELECT title FROM events WHERE id=? LIMIT 1');
$eventStmt->execute([(int)$profile['event_id']]);
$eventTitle = $eventStmt->fetchColumn() ?: 'Etkinliğiniz';

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_or_die();
  $password = trim($_POST['password'] ?? '');
  $confirm  = trim($_POST['password_confirm'] ?? '');
  if (mb_strlen($password, 'UTF-8') < 8) {
    $errors[] = 'Şifreniz en az 8 karakter olmalıdır.';
  }
  if ($password !== $confirm) {
    $errors[] = 'Şifre ve şifre tekrarı eşleşmiyor.';
  }
  if (!$errors) {
    guest_profile_set_password((int)$profile['id'], $password);
    guest_profile_set_session((int)$profile['event_id'], (int)$profile['id']);
    guest_profile_record_login((int)$profile['id']);
    guest_profile_touch((int)$profile['id']);
    flash('ok', 'Şifreniz başarıyla kaydedildi. Keyifli paylaşımlar!');
    header('Location: '.public_upload_url((int)$profile['event_id']));
    exit;
  }
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Şifrenizi Belirleyin — <?=h(APP_NAME)?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#f1f5f9;font-family:'Inter',sans-serif;color:#0f172a;}
    .card{border:none;border-radius:24px;box-shadow:0 28px 70px rgba(14,165,181,0.18);}
    .brand-badge{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:999px;background:rgba(14,165,181,0.12);color:#0ea5b5;font-weight:600;font-size:0.9rem;}
    .btn-brand{background:#0ea5b5;color:#fff;border:none;border-radius:14px;padding:12px 24px;font-weight:600;}
    .btn-brand:hover{background:#0c8d9a;color:#fff;}
  </style>
</head>
<body>
  <div class="container py-5">
    <div class="row justify-content-center">
      <div class="col-lg-5 col-md-7">
        <div class="text-center mb-4">
          <a class="brand-badge text-decoration-none" href="<?=BASE_URL?>"><?=h(APP_NAME)?></a>
        </div>
        <div class="card p-4 p-lg-5 bg-white">
          <div class="mb-4 text-center">
            <h1 class="h3 fw-bold mb-2">Şifrenizi belirleyin</h1>
            <p class="text-muted mb-0"><?=h($eventTitle)?> etkinliğinin misafir paneline güvenle giriş yapmak için yeni şifrenizi oluşturun.</p>
          </div>
          <?php if ($errors): ?>
            <div class="alert alert-danger">
              <?=implode('<br>', array_map('h', $errors))?>
            </div>
          <?php endif; ?>
          <?php flash_box(); ?>
          <form method="post" class="d-flex flex-column gap-3">
            <input type="hidden" name="token" value="<?=h($token)?>">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <div>
              <label class="form-label fw-semibold">Yeni Şifre</label>
              <input type="password" name="password" class="form-control form-control-lg" placeholder="En az 8 karakter" required>
            </div>
            <div>
              <label class="form-label fw-semibold">Şifre Tekrarı</label>
              <input type="password" name="password_confirm" class="form-control form-control-lg" placeholder="Şifrenizi tekrar yazın" required>
            </div>
            <div class="small text-muted">Bu şifre ile doğrulama gerekmeksizin panelinize tekrar giriş yapabilirsiniz.</div>
            <button type="submit" class="btn btn-brand mt-2">Şifremi Kaydet</button>
          </form>
          <div class="mt-4 text-center small text-muted">
            Bağlantı süresi dolduysa <a href="<?=BASE_URL?>/public/upload.php?event=<?=intval($profile['event_id'])?>" class="text-decoration-none" style="color:#0ea5b5;">misafir sayfasından</a> e-posta doğrulamasını tekrar talep edebilirsiniz.
          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
