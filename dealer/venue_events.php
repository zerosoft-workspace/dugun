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

$creationStatus = dealer_event_creation_status($dealer);
$canManage = $creationStatus['allowed'];
$quotaSummary = $creationStatus['summary'];
$activeNav = 'venues';

$action = $_POST['do'] ?? '';
if ($action === 'create_event') {
  csrf_or_die();
  $creationStatus = dealer_event_creation_status($dealer);
  if (!$creationStatus['allowed']) {
    $reason = $creationStatus['reason'] ?? 'Yeni etkinlik oluşturma yetkiniz bulunmuyor.';
    flash('err', $reason);
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

if ($action === 'create_qr') {
  csrf_or_die();
  if (!dealer_has_valid_license($dealer)) {
    flash('err', 'Lisans süreniz geçersiz olduğu için QR oluşturamazsınız.');
    redirect($_SERVER['PHP_SELF'].'?venue_id='.$venueId.'#qr');
  }
  $code = trim($_POST['code'] ?? '');
  if ($code === '') {
    $code = 'qr-'.bin2hex(random_bytes(3));
  }
  $code = preg_replace('~[^a-zA-Z0-9_-]+~', '', $code);
  if ($code === '') {
    flash('err', 'Geçerli bir kod girin.');
    redirect($_SERVER['PHP_SELF'].'?venue_id='.$venueId.'#qr');
  }
  try {
    pdo()->prepare("INSERT INTO qr_codes (venue_id, code, created_at) VALUES (?,?,?)")
        ->execute([$venueId, $code, now()]);
    flash('ok', 'Kalıcı QR kodu oluşturuldu.');
  } catch (Throwable $e) {
    flash('err', 'Bu kod zaten kullanılıyor.');
  }
  redirect($_SERVER['PHP_SELF'].'?venue_id='.$venueId.'#qr');
}

if ($action === 'bind_qr') {
  csrf_or_die();
  $qrId = (int)($_POST['qr_id'] ?? 0);
  $eventId = (int)($_POST['event_id'] ?? 0);
  $st = pdo()->prepare("SELECT id FROM qr_codes WHERE id=? AND venue_id=? LIMIT 1");
  $st->execute([$qrId, $venueId]);
  if (!$st->fetch()) {
    flash('err', 'QR kaydı bulunamadı.');
    redirect($_SERVER['PHP_SELF'].'?venue_id='.$venueId.'#qr');
  }
  if ($eventId > 0) {
    $eventCheck = pdo()->prepare("SELECT id FROM events WHERE id=? AND venue_id=? LIMIT 1");
    $eventCheck->execute([$eventId, $venueId]);
    if (!$eventCheck->fetch()) {
      flash('err', 'Seçilen etkinlik bu salona ait değil.');
      redirect($_SERVER['PHP_SELF'].'?venue_id='.$venueId.'#qr');
    }
  }
  pdo()->prepare("UPDATE qr_codes SET target_event_id=?, updated_at=? WHERE id=?")
      ->execute([$eventId ?: null, now(), $qrId]);
  flash('ok', 'QR yönlendirmesi güncellendi.');
  redirect($_SERVER['PHP_SELF'].'?venue_id='.$venueId.'#qr');
}

$events = [];
$st = pdo()->prepare("SELECT * FROM events WHERE venue_id=? ORDER BY event_date DESC, id DESC");
$st->execute([$venueId]);
$events = $st->fetchAll();

$activeEvents = [];
$passiveEvents = [];
$todayTs = strtotime('today');
foreach ($events as $ev) {
  $eventTs = null;
  if (!empty($ev['event_date'])) {
    $eventTs = strtotime($ev['event_date']);
  }
  $isPast = $eventTs !== null && $eventTs < $todayTs;
  if (!empty($ev['is_active']) && !$isPast) {
    $activeEvents[] = $ev;
  } else {
    $passiveEvents[] = $ev;
  }
}

$qrCodes = pdo()->prepare("SELECT q.*, e.title AS event_title, e.event_date FROM qr_codes q LEFT JOIN events e ON e.id=q.target_event_id WHERE q.venue_id=? ORDER BY q.id DESC");
$qrCodes->execute([$venueId]);
$qrCodes = $qrCodes->fetchAll();
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h($venue['name'])?> — Etkinlik Yönetimi</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  :root{
    --brand:#0ea5b5;
    --brand-dark:#0b8b98;
    --ink:#0f172a;
    --muted:#64748b;
    --surface:#ffffff;
    --soft:#f1f7fb;
  }
  body{background:var(--soft);color:var(--ink);font-family:'Inter','Segoe UI',system-ui,sans-serif;min-height:100vh;}
  .dealer-topnav{background:var(--surface);border-bottom:1px solid rgba(148,163,184,.22);box-shadow:0 12px 30px rgba(15,23,42,.05);}
  .dealer-topnav .navbar-brand{font-weight:700;color:var(--ink);}
  .dealer-topnav .nav-link{color:var(--muted);font-weight:600;}
  .dealer-topnav .nav-link:hover{color:var(--brand-dark);}
  .dealer-hero{background:linear-gradient(120deg,rgba(14,165,181,.18),rgba(14,165,181,.04));padding:2rem 0 1.5rem;margin-bottom:2rem;}
  .dealer-hero h1{font-weight:700;margin-bottom:.4rem;}
  .dealer-hero p{margin:0;color:var(--muted);max-width:720px;}
  .dealer-main{padding-bottom:4rem;}
  .card-lite{border-radius:20px;background:var(--surface);border:1px solid rgba(148,163,184,.18);box-shadow:0 26px 48px -30px rgba(15,23,42,.45);}
  .card-section{padding:1.6rem 1.5rem;}
  .btn-brand{background:var(--brand);border:none;color:#fff;border-radius:12px;font-weight:600;padding:.6rem 1.2rem;}
  .btn-brand:hover{background:var(--brand-dark);color:#fff;}
  .btn-brand-outline{background:#fff;border:1px solid rgba(14,165,181,.6);color:var(--brand);border-radius:12px;font-weight:600;padding:.55rem 1.2rem;}
  .badge-soft{background:rgba(14,165,181,.12);color:var(--brand-dark);border-radius:999px;padding:.35rem .75rem;font-size:.75rem;font-weight:600;}
  .form-control,.form-select{border-radius:12px;border:1px solid rgba(148,163,184,.45);padding:.6rem .75rem;}
  .form-control:focus,.form-select:focus{border-color:var(--brand);box-shadow:0 0 0 .2rem rgba(14,165,181,.15);}
  .table thead th{color:var(--muted);font-weight:600;text-transform:uppercase;font-size:.75rem;letter-spacing:.05em;border-bottom:1px solid rgba(148,163,184,.3);}
  .table tbody td{vertical-align:middle;}
  .status-pill{display:inline-flex;align-items:center;gap:.4rem;border-radius:999px;padding:.35rem .85rem;font-weight:600;font-size:.8rem;}
  .status-pill.active{background:rgba(34,197,94,.15);color:#166534;}
  .status-pill.passive{background:rgba(248,113,113,.16);color:#b91c1c;}
  .qr-thumb{width:120px;height:120px;border-radius:12px;border:1px solid rgba(148,163,184,.3);object-fit:cover;}
  @media(max-width:767px){
    .dealer-hero{padding:1.6rem 0 1.2rem;margin-bottom:1.2rem;}
  }
</style>
</head>
<body>
<header class="dealer-header">
  <nav class="dealer-topnav navbar navbar-expand-lg">
    <div class="container">
      <a class="navbar-brand" href="dashboard.php"><?=h(APP_NAME)?> — Bayi Paneli</a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#dealerNav" aria-controls="dealerNav" aria-expanded="false" aria-label="Menü">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="dealerNav">
        <ul class="navbar-nav me-auto mb-2 mb-lg-0">
          <li class="nav-item"><a class="nav-link<?= $activeNav === 'dashboard' ? ' active' : '' ?>" href="dashboard.php">Genel Bakış</a></li>
          <li class="nav-item"><a class="nav-link<?= $activeNav === 'venues' ? ' active' : '' ?>" href="dashboard.php#venues">Salonlar</a></li>
          <li class="nav-item"><a class="nav-link<?= $activeNav === 'billing' ? ' active' : '' ?>" href="billing.php">Bakiye & Paketler</a></li>
        </ul>
        <div class="d-flex align-items-center gap-3 mb-2 mb-lg-0">
          <span class="badge-soft"><?=h($dealer['name'])?></span>
          <a class="text-decoration-none fw-semibold" href="login.php?logout=1">Çıkış</a>
        </div>
      </div>
    </div>
  </nav>
  <div class="dealer-hero">
    <div class="container">
      <h1><?=h($venue['name'])?></h1>
      <p>Atandığınız salon için etkinlik oluşturun, QR kodlarınızı yönetin ve davetli akışını yönlendirin.</p>
    </div>
  </div>
</header>

<main class="dealer-main">
  <div class="container">
    <?php flash_box(); ?>

    <section class="card-lite card-section mb-4" id="create">
      <div class="d-flex justify-content-between flex-wrap gap-3 align-items-start mb-3">
        <div>
          <h5 class="mb-1">Yeni Etkinlik Oluştur</h5>
          <p class="text-muted mb-0">Çift e-postasını yazın, giriş bilgileri otomatik olarak gönderilsin.</p>
        </div>
        <div class="d-flex flex-column align-items-end gap-2">
          <span class="badge-soft">Lisans: <?= dealer_has_valid_license($dealer) ? 'Geçerli' : 'Süresi Doldu' ?></span>
          <?php $remainingEvents = $quotaSummary['has_unlimited'] ? 'Sınırsız' : $quotaSummary['remaining_events']; ?>
          <span class="badge-soft">Kalan Hak: <?=h($remainingEvents)?></span>
        </div>
      </div>
      <?php if (!$canManage && !empty($creationStatus['reason'])): ?>
        <div class="alert alert-warning"><?=h($creationStatus['reason'])?></div>
      <?php endif; ?>
      <?php if (!$canManage): ?>
        <div class="alert alert-warning">Lisans süreniz geçersiz olduğu için yeni etkinlik oluşturamazsınız. Lütfen yönetici ile iletişime geçin.</div>
      <?php endif; ?>
      <form method="post" class="row g-3" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="do" value="create_event">
        <div class="col-md-5">
          <label class="form-label">Etkinlik Başlığı</label>
          <input class="form-control" name="title" required <?= $canManage ? '' : 'disabled' ?>>
        </div>
        <div class="col-md-3">
          <label class="form-label">Etkinlik Tarihi</label>
          <input type="date" class="form-control" name="event_date" value="<?=h(date('Y-m-d'))?>" <?= $canManage ? '' : 'disabled' ?>>
        </div>
        <div class="col-md-4">
          <label class="form-label">Çift E-postası</label>
          <input type="email" class="form-control" name="couple_email" required <?= $canManage ? '' : 'disabled' ?>>
        </div>
        <div class="col-12 d-grid d-md-flex justify-content-md-end">
          <button class="btn btn-brand" type="submit" <?= $canManage ? '' : 'disabled' ?>>Etkinliği Oluştur</button>
        </div>
      </form>
    </section>

    <section class="card-lite card-section mb-4" id="events">
      <div class="d-flex justify-content-between align-items-start mb-3 flex-column flex-md-row">
        <div>
          <h5 class="mb-1">Etkinlik Listesi</h5>
          <span class="text-muted small">Toplam <?=count($events)?> etkinlik</span>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <span class="badge-soft">Aktif: <?=count($activeEvents)?></span>
          <span class="badge-soft">Pasif / Geçmiş: <?=count($passiveEvents)?></span>
        </div>
      </div>
      <?php if (!$events): ?>
        <p class="text-muted mb-0">Bu salona ait etkinlik bulunmuyor.</p>
      <?php else: ?>
        <?php
          $renderEventTable = function(array $list) {
            ob_start();
        ?>
        <div class="table-responsive mb-3">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>Başlık</th>
                <th>Tarih</th>
                <th>Bağlantılar</th>
                <th>Durum</th>
                <th class="text-end">İşlem</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($list as $ev):
                $guestLink = public_upload_url($ev['id']);
                $coupleLink = BASE_URL.'/couple/index.php?event='.$ev['id'].'&key='.$ev['couple_panel_key'];
                $qrImage = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data='.urlencode($guestLink);
              ?>
              <tr>
                <td>
                  <div class="fw-semibold mb-1"><?=h($ev['title'])?></div>
                  <div class="small text-muted">Çift e-postası: <?=h($ev['couple_username'] ?? $ev['contact_email'])?></div>
                </td>
                <td class="small">
                  <?= $ev['event_date'] ? h(date('d.m.Y', strtotime($ev['event_date']))) : '—' ?>
                </td>
                <td class="small">
                  <a class="badge-soft text-decoration-none me-1" target="_blank" href="<?=h($guestLink)?>">Misafir Sayfası</a>
                  <a class="badge-soft text-decoration-none" target="_blank" href="<?=h($coupleLink)?>">Etkinlik Paneli</a>
                </td>
                <td>
                  <span class="status-pill <?= $ev['is_active'] ? 'active' : 'passive' ?>"><?= $ev['is_active'] ? 'Aktif' : 'Pasif' ?></span>
                </td>
                <td class="text-end">
                  <div class="d-flex flex-column flex-sm-row gap-2 justify-content-end">
                    <a class="btn btn-sm btn-brand-outline" target="_blank" href="<?=h($qrImage)?>">Anlık QR</a>
                    <form method="post">
                      <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                      <input type="hidden" name="do" value="toggle">
                      <input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>">
                      <button class="btn btn-sm btn-outline-secondary" type="submit">Durumu Değiştir</button>
                    </form>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php
            return ob_get_clean();
          };
        ?>

        <h6 class="text-uppercase small fw-semibold text-muted">Aktif Etkinlikler</h6>
        <?php if ($activeEvents): ?>
          <?= $renderEventTable($activeEvents) ?>
        <?php else: ?>
          <p class="text-muted fst-italic mb-4">Aktif etkinlik bulunmuyor.</p>
        <?php endif; ?>

        <h6 class="text-uppercase small fw-semibold text-muted mt-3">Pasif / Geçmiş Etkinlikler</h6>
        <?php if ($passiveEvents): ?>
          <?= $renderEventTable($passiveEvents) ?>
        <?php else: ?>
          <p class="text-muted fst-italic mb-0">Pasif veya geçmiş etkinlik bulunmuyor.</p>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <section class="card-lite card-section" id="qr">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
          <h5 class="mb-1">Kalıcı QR Kodlar</h5>
          <p class="text-muted mb-0">Broşür ve tabelalarınız için kalıcı kodlar oluşturun, istediğiniz etkinliğe yönlendirin.</p>
        </div>
        <form method="post" class="d-flex gap-2">
          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="do" value="create_qr">
          <input class="form-control" name="code" placeholder="Örn. salon-brosur" style="max-width:220px">
          <button class="btn btn-brand" type="submit">QR Oluştur</button>
        </form>
      </div>
      <?php if (!$qrCodes): ?>
        <p class="text-muted mb-0">Bu salona ait oluşturulmuş kalıcı QR kodu bulunmuyor.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>Kod</th>
                <th>Önizleme</th>
                <th>Bağlı Etkinlik</th>
                <th>Yönlendirme</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($qrCodes as $code):
                $qrLink = BASE_URL.'/qr.php?code='.rawurlencode($code['code']).'&v='.rawurlencode($venue['slug']);
                $img = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data='.urlencode($qrLink);
              ?>
              <tr>
                <td>
                  <div class="fw-semibold"><?=h($code['code'])?></div>
                  <div class="small"><a target="_blank" href="<?=h($qrLink)?>">Kalıcı bağlantı</a></div>
                </td>
                <td><img class="qr-thumb" src="<?=h($img)?>" alt="qr"></td>
                <td>
                  <?php if ($code['event_title']): ?>
                    <div class="fw-semibold mb-1"><?=h($code['event_title'])?></div>
                    <div class="small text-muted"><?= $code['event_date'] ? h(date('d.m.Y', strtotime($code['event_date']))) : 'Tarih yok' ?></div>
                  <?php else: ?>
                    <span class="text-muted">Bağlı etkinlik seçilmedi.</span>
                  <?php endif; ?>
                </td>
                <td style="width:320px;">
                  <form method="post" class="row g-2 align-items-center">
                    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="do" value="bind_qr">
                    <input type="hidden" name="qr_id" value="<?= (int)$code['id'] ?>">
                    <div class="col-sm-8">
                      <select class="form-select" name="event_id">
                        <option value="0">— Bağlı değil —</option>
                        <?php foreach ($events as $ev): ?>
                          <option value="<?= (int)$ev['id'] ?>" <?= ($code['target_event_id'] ?? null) == $ev['id'] ? 'selected' : '' ?>><?=h($ev['title'])?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-sm-4 d-grid">
                      <button class="btn btn-brand-outline" type="submit">Kaydet</button>
                    </div>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
