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
$creationStatus = dealer_event_creation_status($dealer);
$canCreate = $creationStatus['allowed'];
$quotaSummary = $creationStatus['summary'];
$balance = dealer_get_balance((int)$dealer['id']);
$activeNav = 'dashboard';

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
  .dealer-topnav{background:#fff;border-bottom:1px solid rgba(15,23,42,.08);box-shadow:0 12px 30px rgba(15,23,42,.06);}
  .dealer-topnav .navbar-brand{font-weight:700;color:var(--text);}
  .dealer-topnav .nav-link{color:var(--muted);font-weight:600;border-radius:12px;padding:.45rem .95rem;}
  .dealer-topnav .nav-link:hover{color:var(--brand-dark);background:rgba(14,165,181,.08);}
  .dealer-topnav .nav-link.active{color:var(--brand);background:rgba(14,165,181,.16);}
  .dealer-topnav .badge-soft{background:rgba(14,165,181,.12);color:var(--brand-dark);border-radius:999px;padding:.3rem .85rem;font-weight:600;font-size:.85rem;}
  .dealer-hero{padding:2.6rem 0 2rem;}
  .dealer-hero h1{font-size:1.8rem;font-weight:700;margin-bottom:.6rem;}
  .dealer-hero p{color:var(--muted);max-width:620px;}
  .hero-actions{gap:.75rem;}
  .card-lite{border:1px solid rgba(15,23,42,.06);border-radius:20px;background:#fff;box-shadow:0 18px 40px rgba(15,23,42,.07);}
  .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:1.2rem;}
  .stat-card{padding:1.4rem;border-radius:16px;background:linear-gradient(145deg,#fff,rgba(14,165,181,.08));position:relative;overflow:hidden;}
  .stat-card h6{font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:.35rem;}
  .stat-card strong{font-size:1.7rem;display:block;}
  .stat-card span{color:var(--muted);font-size:.82rem;}
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
<header class="dealer-header">
  <nav class="dealer-topnav navbar navbar-expand-lg py-3">
    <div class="container">
      <a class="navbar-brand" href="dashboard.php"><?=h(APP_NAME)?> — Bayi Paneli</a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#dealerNav" aria-controls="dealerNav" aria-expanded="false" aria-label="Menü">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="dealerNav">
        <ul class="navbar-nav me-auto mb-2 mb-lg-0">
          <li class="nav-item"><a class="nav-link<?= $activeNav === 'dashboard' ? ' active' : '' ?>" href="dashboard.php">Genel Bakış</a></li>
          <li class="nav-item"><a class="nav-link<?= $activeNav === 'venues' ? ' active' : '' ?>" href="#venues">Salonlar</a></li>
          <li class="nav-item"><a class="nav-link<?= $activeNav === 'billing' ? ' active' : '' ?>" href="billing.php">Bakiye & Paketler</a></li>
        </ul>
        <div class="d-flex align-items-center gap-3 mb-2 mb-lg-0">
          <span class="badge-soft"><?=h($dealer['name'])?></span>
          <a class="text-decoration-none fw-semibold" href="login.php?logout=1">Çıkış</a>
        </div>
      </div>
    </div>
  </nav>
</header>
<main class="dealer-main">
  <div class="container py-4">
    <?php flash_box(); ?>
    <section class="dealer-hero">
      <div class="card-lite p-4 p-lg-5">
        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-4">
          <div>
            <h1>Merhaba <?=h($dealer['name'])?> 👋</h1>
            <p>Bayi panelinizde atanmış salonlarınızı yönetin, etkinliklerinizi takip edin ve <?=h(APP_NAME)?> ekibinin sunduğu kampanyalardan haberdar olun.</p>
            <div class="d-flex flex-wrap hero-actions mt-3">
              <span class="badge-soft">Lisans Bitiş: <?=h(dealer_license_label($dealer))?></span>
              <?php if ($warning): ?>
                <span class="badge bg-warning-subtle text-warning-emphasis fw-semibold"><?=h($warning)?></span>
              <?php else: ?>
                <span class="badge-soft">Durum: <?= dealer_has_valid_license($dealer) ? 'Geçerli' : 'Geçersiz' ?></span>
              <?php endif; ?>
              <a class="btn btn-brand btn-sm" href="billing.php">Bakiye & Paketler</a>
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
              <h6>Bakiye</h6>
              <strong><?=h(format_currency($balance))?></strong>
              <span>Bakiye hareketlerini takip edin</span>
            </div>
            <?php
              $quotaLabel = $quotaSummary['has_unlimited'] ? 'Sınırsız' : (string)$quotaSummary['remaining_events'];
              $quotaHint  = $quotaSummary['has_unlimited']
                ? ($quotaSummary['unlimited_until'] ? 'Süre bitişi: '.date('d.m.Y', strtotime($quotaSummary['unlimited_until'])) : 'Süre sınırı yok')
                : 'Kalan etkinlik hakkı';
            ?>
            <div class="stat-card">
              <h6>Etkinlik Hakkı</h6>
              <strong><?=h($quotaLabel)?></strong>
              <span><?=h($quotaHint)?></span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <?php if (!$creationStatus['allowed'] && $creationStatus['reason'] && $quotaSummary['has_credit']): ?>
      <div class="alert alert-warning mb-4">
        <?=h($creationStatus['reason'])?>
      </div>
    <?php endif; ?>

    <?php if (!$quotaSummary['has_credit']): ?>
      <div class="alert alert-warning d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>Yeni etkinlik oluşturmak için aktif bir paket veya bakiye yüklemesine ihtiyacınız var.</div>
        <a class="btn btn-sm btn-outline-brand" href="billing.php">Paketleri Gör</a>
      </div>
    <?php endif; ?>

    <?php if ($quotaSummary['cashback_waiting'] > 0): ?>
      <div class="alert alert-info d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div><strong><?=$quotaSummary['cashback_waiting']?></strong> paket için cashback ödemesi bekliyor (<?=h(format_currency($quotaSummary['cashback_pending_amount']))?>).</div>
        <a class="btn btn-sm btn-outline-brand" href="billing.php#wallet">Hareketleri Gör</a>
      </div>
    <?php endif; ?>

    <div class="card-lite p-4 mb-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Aktif Paketleriniz</h5>
        <a class="btn btn-sm btn-outline-brand" href="billing.php">Paket Yönetimi</a>
      </div>
      <?php if (empty($quotaSummary['active'])): ?>
        <p class="text-muted mb-0">Aktif paket bulunmuyor. Yeni paket satın almak için <a href="billing.php">Bakiye &amp; Paketler</a> sayfasını ziyaret edin.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead><tr><th>Paket</th><th>Kalan</th><th>Bitiş</th><th>Cashback</th></tr></thead>
            <tbody>
              <?php foreach ($quotaSummary['active'] as $package): ?>
                <?php
                  $quota = $package['event_quota'];
                  $used = $package['events_used'];
                  $remaining = $quota === null ? 'Sınırsız' : max(0, $quota - $used).' / '.$quota;
                  $expiry = $package['expires_at'] ? date('d.m.Y', strtotime($package['expires_at'])) : 'Süresiz';
                  $cashbackLabel = dealer_cashback_status_label($package['cashback_status']);
                  $cashbackAmount = $package['cashback_amount'] > 0 ? format_currency($package['cashback_amount']) : '';
                ?>
                <tr>
                  <td>
                    <div class="fw-semibold"><?=h($package['package_name'])?></div>
                    <?php if (!empty($package['package_description'])): ?>
                      <div class="small text-muted"><?=h($package['package_description'])?></div>
                    <?php endif; ?>
                  </td>
                  <td><?=h($remaining)?></td>
                  <td><?=h($expiry)?></td>
                  <td>
                    <?=h($cashbackLabel)?>
                    <?php if ($package['cashback_status'] === DEALER_CASHBACK_PENDING && $cashbackAmount): ?>
                      <span class="text-muted small">• <?=h($cashbackAmount)?></span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

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

    <div id="venues" class="card-lite p-4 mb-4">
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
            <p class="text-muted small mb-0">Her salon için kalıcı QR kodlarını <strong>Etkinlikleri Yönet</strong> sayfasından görüntüleyip davetlilerinizle paylaşabilirsiniz.</p>
          </div>
        </div>
        <div class="col-md-4">
          <div class="p-3 border rounded-4 h-100">
            <h6 class="fw-semibold">Cari &amp; Paket Yönetimi</h6>
            <p class="text-muted small mb-0"><strong>Bakiye &amp; Paketler</strong> alanından bakiyenizi takip edin, paket satın alın ve geçmiş hareketlerinizi görüntüleyin.</p>
          </div>
        </div>
        <div class="col-md-4">
          <div class="p-3 border rounded-4 h-100">
            <h6 class="fw-semibold">Cashback Avantajı</h6>
            <p class="text-muted small mb-0">Tekli paketlerinizde gerçekleşen satışlar için %50 cashback talebinizi aynı sayfadan takip edebilirsiniz.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>
</body>
</html>
