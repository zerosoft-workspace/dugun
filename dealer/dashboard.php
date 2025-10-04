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

dealer_refresh_session((int)$dealer['id']);

$venues  = dealer_fetch_venues((int)$dealer['id']);
$events  = dealer_allowed_events((int)$dealer['id']);
$warning = dealer_license_warning($dealer);
$canCreate = dealer_can_manage_events($dealer);

$totalVenues  = count($venues);
$activeVenues = count(array_filter($venues, fn($v) => !empty($v['is_active'])));
$totalEvents  = count($events);
$today = new DateTimeImmutable('today');
$upcoming = array_values(array_filter($events, function ($ev) use ($today) {
  if (empty($ev['event_date'])) {
    return false;
  }
  try {
    $date = new DateTimeImmutable($ev['event_date']);
  } catch (Exception $e) {
    return false;
  }
  return $date >= $today;
}));
usort($upcoming, function ($a, $b) {
  $da = $a['event_date'] ? strtotime($a['event_date']) : 0;
  $db = $b['event_date'] ? strtotime($b['event_date']) : 0;
  return $da <=> $db;
});
$nextEvent = $upcoming[0] ?? null;
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h(APP_NAME)?> — Bayi Paneli</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  :root{
    --brand:#0ea5b5;
    --brand-dark:#0b8692;
    --bg:#f4f7fb;
    --text:#0f172a;
    --muted:#6b7280;
  }
  body{background:var(--bg);font-family:'Inter',sans-serif;color:var(--text);}
  a{color:var(--brand);} a:hover{color:var(--brand-dark);}
  .topbar{background:#fff;border-bottom:1px solid rgba(15,23,42,.05);box-shadow:0 10px 20px rgba(15,23,42,.04);}
  .hero{padding:2.5rem 0;}
  .hero h1{font-size:1.75rem;font-weight:700;margin-bottom:.5rem;}
  .hero p{color:var(--muted);max-width:560px;}
  .card-lite{border:1px solid rgba(15,23,42,.06);border-radius:18px;background:#fff;box-shadow:0 15px 35px rgba(15,23,42,.07);}
  .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1.2rem;}
  .stat-card{padding:1.4rem;border-radius:16px;background:linear-gradient(145deg,#fff,rgba(14,165,181,.08));position:relative;overflow:hidden;}
  .stat-card h6{font-size:.85rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:.35rem;}
  .stat-card strong{font-size:1.8rem;display:block;}
  .stat-card span{color:var(--muted);font-size:.85rem;}
  .table thead th{font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);}
  .badge-soft{background:rgba(14,165,181,.12);color:var(--brand);border-radius:999px;padding:.35rem .75rem;font-weight:600;font-size:.8rem;}
  .empty-state{padding:2.5rem;text-align:center;color:var(--muted);}
  .btn-brand{background:var(--brand);border:none;color:#fff;border-radius:14px;padding:.65rem 1.4rem;font-weight:600;box-shadow:0 8px 18px rgba(14,165,181,.25);}
  .btn-brand:hover{background:var(--brand-dark);color:#fff;}
  .btn-outline-brand{background:#fff;border:1px solid rgba(14,165,181,.5);color:var(--brand);border-radius:14px;padding:.65rem 1.4rem;font-weight:600;}
  .btn-outline-brand:hover{background:rgba(14,165,181,.08);color:var(--brand-dark);}
  .btn-brand.btn-sm,.btn-outline-brand.btn-sm{padding:.45rem .9rem;border-radius:12px;font-size:.85rem;}
</style>
</head>
<body>
<nav class="topbar py-3">
  <div class="container d-flex justify-content-between align-items-center">
    <span class="fw-semibold"><?=h(APP_NAME)?> — Bayi Paneli</span>
    <div class="d-flex align-items-center gap-3 small">
      <span><?=h($dealer['name'])?></span>
      <span>•</span>
      <a class="text-decoration-none" href="login.php?logout=1">Çıkış</a>
    </div>
  </div>
</nav>
<div class="container py-4">
  <?php flash_box(); ?>
  <section class="hero">
    <div class="card-lite p-4 p-lg-5">
      <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-4">
        <div>
          <h1>Merhaba <?=h($dealer['name'])?> 👋</h1>
          <p>Bayi panelinizde atanmış salonlarınızı yönetin, etkinliklerinizi takip edin ve <?=h(APP_NAME)?> ekibinin sunduğu kampanyalardan haberdar olun.</p>
          <div class="d-flex flex-wrap gap-3 mt-3">
            <span class="badge-soft">Lisans Bitiş: <?=h(dealer_license_label($dealer))?></span>
            <?php if ($warning): ?>
              <span class="badge bg-warning-subtle text-warning-emphasis fw-semibold"><?=h($warning)?></span>
            <?php else: ?>
              <span class="badge-soft">Durum: <?= dealer_has_valid_license($dealer) ? 'Geçerli' : 'Geçersiz' ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div class="stat-grid flex-grow-1">
          <div class="stat-card">
            <h6>Salon</h6>
            <strong><?=$totalVenues?></strong>
            <span><?=$activeVenues?> aktif</span>
          </div>
          <div class="stat-card">
            <h6>Etkinlik</h6>
            <strong><?=$totalEvents?></strong>
            <span><?=count($upcoming)?> yaklaşan</span>
          </div>
          <div class="stat-card">
            <h6>Son Giriş</h6>
            <strong><?=h($dealer['last_login_at'] ? date('d.m.Y', strtotime($dealer['last_login_at'])) : '—')?></strong>
            <span><?=h($dealer['last_login_at'] ? date('H:i', strtotime($dealer['last_login_at'])).' • '.APP_NAME : 'İlk girişinizi yapın')?></span>
          </div>
        </div>
      </div>
    </div>
  </section>

  <?php if ($nextEvent): ?>
    <div class="card-lite p-4 mb-4">
      <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
        <div>
          <h5 class="mb-1">Sıradaki Etkinliğiniz</h5>
          <div class="text-muted"><?=h(date('d.m.Y', strtotime($nextEvent['event_date'] ?? 'now'))).' • '.h($nextEvent['title'] ?? 'Etkinlik')?></div>
        </div>
        <div class="d-flex gap-2">
          <a class="btn btn-outline-brand" href="venue_events.php?venue_id=<?= (int)$nextEvent['venue_id'] ?>">Detayları Gör</a>
          <?php if ($canCreate): ?>
            <a class="btn btn-brand" href="venue_events.php?venue_id=<?= (int)$nextEvent['venue_id'] ?>#create">Yeni Etkinlik Oluştur</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="card-lite p-4 mb-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Salonlarınız</h5>
        <a class="btn btn-sm btn-outline-brand" href="mailto:<?=h(MAIL_FROM ?? 'info@localhost')?>?subject=Bayi%20Salon%20Talebi">Yeni salon talep et</a>
      </div>
    <?php if (!$venues): ?>
      <div class="empty-state">
        Henüz size atanmış salon bulunmuyor. Lütfen yönetici ile iletişime geçin.
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr><th>Salon</th><th>Durum</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($venues as $v): ?>
              <tr>
                <td>
                  <div class="fw-semibold"><?=h($v['name'])?></div>
                  <div class="small text-muted">Slug: <?=h($v['slug'])?></div>
                </td>
                <td><?= $v['is_active'] ? '<span class="badge-soft">Aktif</span>' : '<span class="badge bg-secondary-subtle text-secondary-emphasis fw-semibold">Pasif</span>' ?></td>
                <td class="text-end">
                  <a class="btn btn-sm btn-brand" href="venue_events.php?venue_id=<?= (int)$v['id'] ?>">Etkinlikleri Yönet</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card-lite p-4">
    <h5 class="mb-3">Panel İpuçları</h5>
    <div class="row g-3">
      <div class="col-md-4">
        <div class="p-3 border rounded-4 h-100">
          <h6 class="fw-semibold">Kalıcı QR Yönetimi</h6>
          <p class="text-muted small mb-0">Her salon için kalıcı QR kodlarını <strong>Etkinlikleri Yönet</strong> sayfasından görebilir, davetlilerinizle paylaşabilirsiniz.</p>
        </div>
      </div>
      <div class="col-md-4">
        <div class="p-3 border rounded-4 h-100">
          <h6 class="fw-semibold">Etkinlik Kampanyaları</h6>
          <p class="text-muted small mb-0">Yönetici ekibinin yayınladığı kampanyalar etkinlik panelinde otomatik görünür. Ek avantajlar için yöneticinizle iletişime geçin.</p>
        </div>
      </div>
      <div class="col-md-4">
        <div class="p-3 border rounded-4 h-100">
          <h6 class="fw-semibold">Destek</h6>
          <p class="text-muted small mb-0">Sorularınız için <a href="mailto:<?=h(MAIL_FROM ?? 'destek@localhost')?>"><?=h(MAIL_FROM ?? 'destek@localhost')?></a> adresine yazabilirsiniz.</p>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
