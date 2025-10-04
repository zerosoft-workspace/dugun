<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/dealers.php';
require_once __DIR__.'/../includes/dealer_auth.php';

install_schema();

dealer_require_login();
$sessionDealer = dealer_user();
$dealer = dealer_get((int)$sessionDealer['id']);
if (!$dealer) {
  dealer_logout();
  redirect('login.php');
}

$venueId = (int)($_GET['venue_id'] ?? 0);
if ($venueId <= 0) {
  flash('err', 'Salon seçilmedi.');
  redirect('dashboard.php');
}

$st = pdo()->prepare("SELECT v.* FROM venues v INNER JOIN dealer_venues dv ON dv.venue_id=v.id WHERE dv.dealer_id=? AND v.id=? LIMIT 1");
$st->execute([(int)$dealer['id'], $venueId]);
$venue = $st->fetch();
if (!$venue) {
  flash('err', 'Bu salona erişiminiz yok.');
  redirect('dashboard.php');
}

$canManage = dealer_can_manage_events($dealer);

$action = $_POST['do'] ?? '';
if ($action === 'create_event') {
  csrf_or_die();
  if (!$canManage) {
    flash('err', 'Lisans süreniz dolduğu için yeni etkinlik oluşturamazsınız.');
    redirect($_SERVER['PHP_SELF'].'?venue_id='.$venueId);
  }

  $title = trim($_POST['title'] ?? '');
  $date  = trim($_POST['event_date'] ?? '');
  $email = trim($_POST['couple_email'] ?? '');

  if ($title === '' || $email === '') {
    flash('err', 'Başlık ve e-posta alanları zorunlu.');
    redirect($_SERVER['PHP_SELF'].'?venue_id='.$venueId);
  }
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('err', 'Geçerli bir e-posta girin.');
    redirect($_SERVER['PHP_SELF'].'?venue_id='.$venueId);
  }

  $slug = slugify($title);
  if ($slug === '') $slug = 'event-'.bin2hex(random_bytes(3));
  $base = $slug; $i = 1;
  while (true) {
    $chk = pdo()->prepare("SELECT id FROM events WHERE venue_id=? AND slug=? LIMIT 1");
    $chk->execute([$venueId, $slug]);
    if (!$chk->fetch()) break;
    $slug = $base.'-'.$i++;
  }

  $same = pdo()->prepare("SELECT id FROM events WHERE couple_username=? LIMIT 1");
  $same->execute([$email]);
  if ($same->fetch()) {
    flash('err', 'Bu e-posta farklı bir etkinlik için kullanılıyor.');
    redirect($_SERVER['PHP_SELF'].'?venue_id='.$venueId);
  }

  $key  = bin2hex(random_bytes(16));
  $plain_pass = substr(bin2hex(random_bytes(8)),0,12);
  $hash = password_hash($plain_pass, PASSWORD_DEFAULT);
  $primary = '#0ea5b5';
  $accent  = '#e0f7fb';

  $sql = "INSERT INTO events (venue_id,dealer_id,user_id,title,slug,couple_panel_key,theme_primary,theme_accent,event_date,created_at,couple_username,couple_password_hash,couple_force_reset,contact_email) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
  pdo()->prepare($sql)->execute([
    $venueId,
    (int)$dealer['id'],
    null,
    $title,
    $slug,
    $key,
    $primary,
    $accent,
    $date ?: null,
    now(),
    $email,
    $hash,
    1,
    $email,
  ]);

  $eventId = (int)pdo()->lastInsertId();
  if ($eventId) {
    couple_set_account($eventId, $email, $plain_pass);
  }

  flash('ok', 'Etkinlik oluşturuldu ve bilgiler çifte e-posta ile gönderildi.');
  redirect($_SERVER['PHP_SELF'].'?venue_id='.$venueId);
}

if ($action === 'toggle' && isset($_POST['event_id'])) {
  csrf_or_die();
  $eventId = (int)$_POST['event_id'];
  if (!dealer_event_belongs_to_dealer((int)$dealer['id'], $eventId)) {
    flash('err', 'Bu etkinliği yönetme yetkiniz yok.');
  } else {
    pdo()->prepare("UPDATE events SET is_active=1-is_active WHERE id=? AND venue_id=?")
        ->execute([$eventId, $venueId]);
    flash('ok', 'Durum güncellendi.');
  }
  redirect($_SERVER['PHP_SELF'].'?venue_id='.$venueId);
}

$events = [];
$st = pdo()->prepare("SELECT * FROM events WHERE venue_id=? ORDER BY event_date DESC, id DESC");
$st->execute([$venueId]);
$events = $st->fetchAll();
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h($venue['name'])?> — Etkinlikler</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:#f4f6fb;}
  .card-lite{border:1px solid #e5e7eb;border-radius:16px;background:#fff;box-shadow:0 10px 30px rgba(15,23,42,.06);}
</style>
</head>
<body>
<nav class="navbar bg-white border-bottom mb-4">
  <div class="container d-flex justify-content-between align-items-center py-2">
    <div>
      <a class="navbar-brand fw-semibold" href="dashboard.php">← Bayi Paneli</a>
      <span class="ms-2 text-muted">Salon: <?=h($venue['name'])?></span>
    </div>
    <a class="text-decoration-none" href="login.php?logout=1">Çıkış</a>
  </div>
</nav>
<div class="container pb-5">
  <?php flash_box(); ?>
  <div class="card-lite p-4 mb-4">
    <h5 class="mb-3">Yeni Etkinlik Oluştur</h5>
    <?php if (!$canManage): ?>
      <div class="alert alert-warning">Lisans süreniz geçersiz olduğu için yeni etkinlik oluşturamazsınız. Lütfen yönetici ile iletişime geçin.</div>
    <?php endif; ?>
    <form method="post" class="row g-3" autocomplete="off">
      <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="do" value="create_event">
      <div class="col-md-6">
        <label class="form-label">Etkinlik Başlığı</label>
        <input class="form-control" name="title" required <?= $canManage ? '' : 'disabled' ?>>
      </div>
      <div class="col-md-3">
        <label class="form-label">Etkinlik Tarihi</label>
        <input type="date" class="form-control" name="event_date" value="<?=h(date('Y-m-d'))?>" <?= $canManage ? '' : 'disabled' ?>>
      </div>
      <div class="col-md-3">
        <label class="form-label">Çift E-postası</label>
        <input type="email" class="form-control" name="couple_email" required <?= $canManage ? '' : 'disabled' ?>>
      </div>
      <div class="col-md-9 d-flex align-items-end">
        <button class="btn btn-primary" type="submit" <?= $canManage ? '' : 'disabled' ?>>Etkinliği Oluştur</button>
      </div>
    </form>
  </div>

  <div class="card-lite p-4">
    <h5 class="mb-3">Etkinlik Listesi</h5>
    <?php if (!$events): ?>
      <p class="text-muted">Bu salona ait etkinlik bulunmuyor.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr><th>Başlık</th><th>Tarih</th><th>Durum</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($events as $ev): ?>
              <tr>
                <td>
                  <div class="fw-semibold"><?=h($ev['title'])?></div>
                  <div class="small text-muted">Çift e-postası: <?=h($ev['couple_username'] ?? $ev['contact_email'])?></div>
                </td>
                <td><?= $ev['event_date'] ? h(date('d.m.Y', strtotime($ev['event_date']))) : '—' ?></td>
                <td><?= $ev['is_active'] ? 'Aktif' : 'Pasif' ?></td>
                <td class="text-end">
                  <form method="post" class="d-inline">
                    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="do" value="toggle">
                    <input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>">
                    <button class="btn btn-sm btn-outline-secondary" type="submit">Durumu Değiştir</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
