<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/guests.php';

install_schema();

if (isset($_GET['reset'])) {
  unset($_SESSION['guest_login_choices'], $_SESSION['guest_login_stage']);
  header('Location: '.BASE_URL.'/public/guest_login.php');
  exit;
}

$emailPrefill = guest_profile_normalize_email($_GET['email'] ?? '');
$stage = $_SESSION['guest_login_stage'] ?? 'form';
$choices = $_SESSION['guest_login_choices'] ?? [];
if ($stage !== 'choose' || !$choices) {
  $stage = 'form';
  $choices = [];
  unset($_SESSION['guest_login_stage'], $_SESSION['guest_login_choices']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_or_die();
  $action = $_POST['action'] ?? 'login';
  if ($action === 'login') {
    $email = guest_profile_normalize_email($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $emailPrefill = $email;
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      flash('err', 'Geçerli bir e-posta adresi yazın.');
      header('Location: '.BASE_URL.'/public/guest_login.php?email='.rawurlencode($email));
      exit;
    }
    if ($password === '') {
      flash('err', 'Şifrenizi yazın.');
      header('Location: '.BASE_URL.'/public/guest_login.php?email='.rawurlencode($email));
      exit;
    }
    $matches = guest_profile_authenticate($email, $password);
    if (!$matches) {
      flash('err', 'E-posta veya şifre hatalı ya da henüz doğrulama tamamlanmadı.');
      header('Location: '.BASE_URL.'/public/guest_login.php?email='.rawurlencode($email));
      exit;
    }
    if (count($matches) === 1) {
      $match = $matches[0];
      $profileId = (int)$match['profile']['id'];
      $eventId = (int)$match['event']['id'];
      guest_profile_set_session($eventId, $profileId);
      guest_profile_record_login($profileId);
      guest_profile_touch($profileId);
      flash('ok', 'Misafir paneline giriş yapıldı.');
      header('Location: '.public_upload_url($eventId));
      exit;
    }
    $store = [];
    foreach ($matches as $match) {
      $profileId = (int)$match['profile']['id'];
      $store[$profileId] = [
        'event_id' => (int)$match['event']['id'],
        'event_title' => $match['event']['title'],
        'event_date' => $match['event']['event_date'],
      ];
    }
    $_SESSION['guest_login_choices'] = $store;
    $_SESSION['guest_login_stage'] = 'choose';
    header('Location: '.BASE_URL.'/public/guest_login.php');
    exit;
  }
  if ($action === 'choose') {
    $choices = $_SESSION['guest_login_choices'] ?? [];
    $profileId = (int)($_POST['profile_id'] ?? 0);
    if (!$choices || !isset($choices[$profileId])) {
      flash('err', 'Seçim geçersiz veya süresi doldu. Lütfen tekrar giriş yapın.');
      header('Location: '.BASE_URL.'/public/guest_login.php');
      exit;
    }
    $eventId = (int)$choices[$profileId]['event_id'];
    unset($_SESSION['guest_login_choices'], $_SESSION['guest_login_stage']);
    guest_profile_set_session($eventId, $profileId);
    guest_profile_record_login($profileId);
    guest_profile_touch($profileId);
    flash('ok', 'Misafir paneline giriş yapıldı.');
    header('Location: '.public_upload_url($eventId));
    exit;
  }
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Misafir Girişi — <?=h(APP_NAME)?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#f1f5f9;font-family:'Inter',sans-serif;color:#0f172a;}
    .card{border:none;border-radius:24px;box-shadow:0 28px 70px rgba(14,165,181,0.18);}
    .brand-badge{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:999px;background:rgba(14,165,181,0.12);color:#0ea5b5;font-weight:600;font-size:0.9rem;}
    .btn-brand{background:#0ea5b5;color:#fff;border:none;border-radius:14px;padding:12px 24px;font-weight:600;}
    .btn-brand:hover{background:#0c8d9a;color:#fff;}
    .event-option{border:1px solid rgba(148,163,184,0.2);border-radius:18px;padding:18px 20px;transition:transform .2s ease,box-shadow .2s ease;}
    .event-option:hover{transform:translateY(-2px);box-shadow:0 18px 45px rgba(148,163,184,0.25);}
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
          <?php if ($stage === 'form'): ?>
            <div class="mb-4 text-center">
              <h1 class="h3 fw-bold mb-2">Misafir girişi</h1>
              <p class="text-muted mb-0">E-postanızı doğrulayıp şifrenizi belirledikten sonra bu panel üzerinden etkinliklerinize ulaşabilirsiniz.</p>
            </div>
            <?php flash_box(); ?>
            <form method="post" class="d-flex flex-column gap-3">
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="action" value="login">
              <div>
                <label class="form-label fw-semibold">E-posta Adresi</label>
                <input type="email" name="email" value="<?=h($emailPrefill)?>" class="form-control form-control-lg" placeholder="ornek@eposta.com" required>
              </div>
              <div>
                <label class="form-label fw-semibold">Şifre</label>
                <input type="password" name="password" class="form-control form-control-lg" placeholder="Şifrenizi yazın" required>
              </div>
              <button type="submit" class="btn btn-brand mt-2">Giriş Yap</button>
            </form>
            <div class="mt-4 text-center small text-muted">
              Henüz şifrenizi oluşturmadıysanız doğrulama e-postasındaki bağlantıyı kullanarak şifre belirleyebilirsiniz.
            </div>
          <?php else: ?>
            <div class="mb-4 text-center">
              <h1 class="h4 fw-bold mb-2">Etkinlik seçin</h1>
              <p class="text-muted mb-3">Bu e-posta adresiyle birden fazla etkinliğe davetlisiniz. Girmek istediğiniz etkinliği seçin.</p>
            </div>
            <?php flash_box(); ?>
            <div class="d-flex flex-column gap-3">
              <?php foreach ($choices as $profileId => $event): ?>
                <?php
                  $eventDateLabel = null;
                  if (!empty($event['event_date'])) {
                    $ts = strtotime($event['event_date']);
                    if ($ts) {
                      $eventDateLabel = date('d.m.Y', $ts);
                    }
                  }
                ?>
                <form method="post" class="event-option">
                  <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="choose">
                  <input type="hidden" name="profile_id" value="<?=intval($profileId)?>">
                  <div class="d-flex justify-content-between align-items-center gap-3">
                    <div>
                      <div class="fw-semibold"><?=h($event['event_title'] ?? 'Etkinlik')?> </div>
                      <?php if ($eventDateLabel): ?>
                        <div class="small text-muted"><?=h($eventDateLabel)?></div>
                      <?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn-brand">Paneli Aç</button>
                  </div>
                </form>
              <?php endforeach; ?>
            </div>
            <div class="mt-4 text-center">
              <a href="<?=BASE_URL?>/public/guest_login.php?reset=1" class="text-decoration-none" style="color:#0ea5b5;">Farklı bir hesapla giriş yap</a>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
