<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/invitations.php';
require_once __DIR__.'/../includes/theme.php';

install_schema();

$code = trim($_GET['code'] ?? '');
if ($code === '') {
  http_response_code(404);
  exit('Davet bulunamadı.');
}

$contact = invitation_contact_by_token($code);
if (!$contact) {
  http_response_code(404);
  exit('Davet bulunamadı.');
}

$eventId = (int)$contact['event_id'];
$contactId = (int)$contact['id'];

$st = pdo()->prepare("SELECT e.*, v.name AS venue_name FROM events e LEFT JOIN venues v ON v.id = e.venue_id WHERE e.id=? LIMIT 1");
$st->execute([$eventId]);
$event = $st->fetch();
if (!$event) {
  http_response_code(404);
  exit('Etkinlik bulunamadı.');
}

$template = invitation_template_get($eventId);
$buttonLabel = $template['button_label'] ?: 'Katılımınızı Bildirin';
$sessionKey = invitation_contact_session_key($contactId);
$authenticated = !empty($_SESSION[$sessionKey]);
$hasPassword = !empty($contact['password_hash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if ($action !== '') {
    csrf_or_die();
  }
  try {
    switch ($action) {
      case 'set_credentials':
        if ($hasPassword) {
          throw new RuntimeException('Şifre zaten oluşturulmuş.');
        }
        $email = trim((string)($_POST['email'] ?? ''));
        $password = trim((string)($_POST['password'] ?? ''));
        $confirm = trim((string)($_POST['password_confirm'] ?? ''));
        if ($password !== $confirm) {
          throw new RuntimeException('Şifreler eşleşmiyor.');
        }
        invitation_contact_set_credentials($eventId, $contactId, $email, $password);
        invitation_contact_set_session($contactId);
        flash('ok', 'Şifreniz oluşturuldu. Davetiyeniz hazır!');
        break;
      case 'login':
        $email = trim((string)($_POST['email'] ?? ''));
        $password = trim((string)($_POST['password'] ?? ''));
        if (!invitation_contact_attempt_login($contact, $email, $password)) {
          throw new RuntimeException('E-posta veya şifre hatalı.');
        }
        invitation_contact_set_session($contactId);
        flash('ok', 'Davetiyenize hoş geldiniz.');
        break;
      case 'update_credentials':
        if (!$authenticated) {
          throw new RuntimeException('Oturum açmanız gerekiyor.');
        }
        $name = $_POST['name'] ?? $contact['name'];
        $email = $_POST['email'] ?? $contact['email'];
        $phone = $_POST['phone'] ?? $contact['phone'];
        $newPassword = $_POST['new_password'] ?? null;
        $newPassword = is_string($newPassword) ? trim($newPassword) : null;
        if ($newPassword === '') {
          $newPassword = null;
        }
        invitation_contact_update($eventId, $contactId, (string)$name, $email, $phone, $newPassword);
        flash('ok', 'Bilgileriniz güncellendi.');
        break;
      case 'logout':
        invitation_contact_clear_session($contactId);
        flash('ok', 'Oturum kapatıldı.');
        break;
      default:
        throw new RuntimeException('Geçersiz işlem.');
    }
  } catch (Throwable $e) {
    flash('err', $e->getMessage());
  }
  redirect(BASE_URL.'/public/invite.php?code='.rawurlencode($code));
}

$contact = invitation_contact_refresh($contactId) ?? $contact;
$hasPassword = !empty($contact['password_hash']);
$authenticated = !empty($_SESSION[$sessionKey]);
$stage = 'login';
if (!$hasPassword) {
  $stage = 'setup';
} elseif ($authenticated) {
  $stage = 'view';
}

if ($stage === 'view') {
  invitation_contact_touch_view($contactId);
  $contact = invitation_contact_refresh($contactId) ?? $contact;
}

$eventTitle = $event['title'] ?? 'Etkinlik';
$eventDateFormatted = '';
if (!empty($event['event_date'])) {
  try {
    $dt = new DateTime($event['event_date']);
    $eventDateFormatted = $dt->format('d.m.Y');
  } catch (Throwable $e) {
    $eventDateFormatted = $event['event_date'];
  }
}
$venueName = $event['venue_name'] ?? '';
$inviteMessage = nl2br(h($template['message']));
$accent = invitation_color_or_default($template['accent_color'] ?? null, '#f8fafc');
$primary = invitation_color_or_default($template['primary_color'] ?? null, '#0ea5b5');
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($eventTitle)?> — Davetiye</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<?=theme_head_assets()?>
<style>
:root{ --primary:<?=$primary?>; --accent:<?=$accent?>; --ink:#0f172a; --muted:#64748b; }
body{ background:linear-gradient(160deg,rgba(248,250,252,1) 0%,rgba(255,255,255,1) 40%,rgba(14,165,181,.08) 100%); min-height:100vh; font-family:'Inter','Segoe UI','Helvetica Neue',sans-serif; color:var(--ink); display:flex; align-items:center; justify-content:center; padding:32px 16px; }
.invite-shell{ width:100%; max-width:640px; }
.invite-card{ border-radius:28px; overflow:hidden; background:#fff; box-shadow:0 45px 90px -60px rgba(14,165,181,.55); border:1px solid rgba(148,163,184,.18); }
.invite-head{ background:var(--accent); padding:32px 36px 24px 36px; }
.invite-head h1{ margin:0; font-size:2rem; font-weight:800; }
.invite-head .subtitle{ margin:8px 0 0; color:var(--muted); font-size:1.05rem; }
.invite-head .meta{ margin-top:16px; display:flex; flex-wrap:wrap; gap:12px; color:#475569; font-weight:600; }
.invite-body{ padding:32px 36px; font-size:1.05rem; line-height:1.7; color:#1f2937; }
.invite-footer{ padding:0 36px 32px; text-align:center; }
.invite-footer .cta{ display:inline-block; padding:14px 34px; border-radius:999px; background:var(--primary); color:#fff; text-decoration:none; font-weight:700; box-shadow:0 18px 36px -22px rgba(14,165,181,.65); }
.invite-footer .brand{ margin-top:20px; font-size:.75rem; letter-spacing:.24em; text-transform:uppercase; color:#94a3b8; }
.panel-card{ border-radius:24px; background:#fff; border:1px solid rgba(148,163,184,.16); box-shadow:0 30px 80px -60px rgba(15,23,42,.35); padding:28px 32px; }
.panel-card h2{ font-weight:700; font-size:1.4rem; }
.btn-pill{ border-radius:999px; font-weight:600; }
.btn-primary{ background:var(--primary); border:none; }
.btn-primary:hover{ background:var(--primary); filter:brightness(.95); }
.btn-outline-secondary{ border-radius:999px; }
</style>
</head>
<body>
<div class="invite-shell">
  <?php flash_box(); ?>

  <?php if ($stage === 'setup'): ?>
    <div class="panel-card">
      <h2>Davetiye erişimi oluşturun</h2>
      <p class="text-muted">Bu davetiye size özel. E-posta adresinizi ve şifrenizi belirleyerek davetiyeyi dilediğiniz zaman görüntüleyebilirsiniz.</p>
      <form method="post" class="row g-3">
        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="set_credentials">
        <div class="col-12">
          <label class="form-label">E-posta</label>
          <input type="email" class="form-control" name="email" value="<?=h($contact['email'] ?? '')?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Şifre</label>
          <input type="password" class="form-control" name="password" minlength="6" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Şifre (tekrar)</label>
          <input type="password" class="form-control" name="password_confirm" minlength="6" required>
        </div>
        <div class="col-12 d-grid">
          <button class="btn btn-primary btn-pill" type="submit"><i class="bi bi-shield-lock me-1"></i>Şifremi Oluştur</button>
        </div>
      </form>
    </div>
  <?php elseif ($stage === 'login'): ?>
    <div class="panel-card">
      <h2>Davetiye giriş</h2>
      <p class="text-muted">Davetiyenizi görmek için kayıtlı e-posta ve şifrenizi yazın.</p>
      <form method="post" class="row g-3">
        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="login">
        <div class="col-12">
          <label class="form-label">E-posta</label>
          <input type="email" class="form-control" name="email" value="<?=h($contact['email'] ?? '')?>" required>
        </div>
        <div class="col-12">
          <label class="form-label">Şifre</label>
          <input type="password" class="form-control" name="password" required>
        </div>
        <div class="col-12 d-grid">
          <button class="btn btn-primary btn-pill" type="submit"><i class="bi bi-door-open me-1"></i>Davetiyeyi Aç</button>
        </div>
      </form>
      <p class="text-muted small mt-3">Şifrenizi hatırlamıyorsanız çift ile iletişime geçebilirsiniz.</p>
    </div>
  <?php else: ?>
    <div class="invite-card mb-4">
      <div class="invite-head">
        <h1><?=h($template['title'])?></h1>
        <?php if (!empty($template['subtitle'])): ?>
          <div class="subtitle"><?=h($template['subtitle'])?></div>
        <?php endif; ?>
        <div class="meta">
          <span><i class="bi bi-people me-1"></i><?=h($eventTitle)?></span>
          <?php if ($eventDateFormatted): ?>
            <span><i class="bi bi-calendar-event me-1"></i><?=$eventDateFormatted?></span>
          <?php endif; ?>
          <?php if ($venueName): ?>
            <span><i class="bi bi-geo-alt me-1"></i><?=h($venueName)?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="invite-body"><?=$inviteMessage?></div>
      <div class="invite-footer">
        <a class="cta" href="#" onclick="return false;"><?=h($buttonLabel)?></a>
        <div class="brand">bikara.com</div>
      </div>
    </div>

    <div class="panel-card">
      <h2>Bilgilerinizi güncelleyin</h2>
      <p class="text-muted">E-posta veya telefon değişikliklerini kaydedebilir, dilerseniz yeni bir şifre belirleyebilirsiniz.</p>
      <form method="post" class="row g-3">
        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="update_credentials">
        <div class="col-md-6">
          <label class="form-label">İsim</label>
          <input type="text" class="form-control" name="name" value="<?=h($contact['name'])?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Telefon</label>
          <input type="text" class="form-control" name="phone" value="<?=h($contact['phone'] ?? '')?>" placeholder="05xx ...">
        </div>
        <div class="col-12">
          <label class="form-label">E-posta</label>
          <input type="email" class="form-control" name="email" value="<?=h($contact['email'] ?? '')?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Yeni Şifre</label>
          <input type="password" class="form-control" name="new_password" placeholder="(Opsiyonel) en az 6 karakter">
        </div>
        <div class="col-md-6 d-flex align-items-end">
          <button class="btn btn-primary btn-pill w-100" type="submit"><i class="bi bi-save me-1"></i>Kaydet</button>
        </div>
      </form>
      <form method="post" class="mt-3 text-end">
        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="action" value="logout">
        <button class="btn btn-outline-secondary btn-pill" type="submit"><i class="bi bi-box-arrow-right me-1"></i>Çıkış yap</button>
      </form>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
