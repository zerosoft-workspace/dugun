<?php
// admin/dashboard.php — yönetim genel bakış ekranı
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/dealers.php';
require_once __DIR__.'/partials/ui.php';

require_admin();
install_schema();

$me    = admin_user();
$venue = require_current_venue_or_redirect();
$VNAME = $venue['name'];
$today = date('Y-m-d');

$dealerCounts = dealer_status_counts();
$topupStats = [
  DEALER_TOPUP_STATUS_PENDING         => ['count' => 0, 'amount' => 0],
  DEALER_TOPUP_STATUS_AWAITING_REVIEW => ['count' => 0, 'amount' => 0],
  DEALER_TOPUP_STATUS_COMPLETED       => ['count' => 0, 'amount' => 0],
];
$cashbackSummary = ['count' => 0, 'amount' => 0];
$campaignCount   = 0;
$eventStats      = [
  'total'     => 0,
  'active'    => 0,
  'upcoming'  => 0,
  'completed' => 0,
];

try {
  $st = pdo()->query("SELECT status, COUNT(*) AS c, COALESCE(SUM(amount_cents),0) AS total FROM dealer_topups GROUP BY status");
  foreach ($st->fetchAll() as $row) {
    $status = $row['status'] ?? '';
    if (!isset($topupStats[$status])) {
      continue;
    }
    $topupStats[$status]['count']  = (int)$row['c'];
    $topupStats[$status]['amount'] = (int)$row['total'];
  }
} catch (Throwable $e) {}

try {
  $st = pdo()->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(cashback_amount),0) AS total FROM dealer_package_purchases WHERE cashback_status=?");
  $st->execute([DEALER_CASHBACK_PENDING]);
  if ($row = $st->fetch()) {
    $cashbackSummary['count']  = (int)$row['c'];
    $cashbackSummary['amount'] = (int)$row['total'];
  }
} catch (Throwable $e) {}

try {
  $campaignCount = (int)pdo()->query("SELECT COUNT(*) FROM campaigns WHERE is_active=1")->fetchColumn();
} catch (Throwable $e) {}

try {
  $eventAgg = pdo()->prepare(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN event_date IS NOT NULL AND event_date >= ? THEN 1 ELSE 0 END) AS upcoming,
            SUM(CASE WHEN event_date IS NOT NULL AND event_date < ? THEN 1 ELSE 0 END) AS completed
       FROM events"
  );
  $eventAgg->execute([$today, $today]);
  if ($row = $eventAgg->fetch()) {
    $eventStats['total']     = (int)$row['total'];
    $eventStats['active']    = (int)($row['active'] ?? 0);
    $eventStats['upcoming']  = (int)($row['upcoming'] ?? 0);
    $eventStats['completed'] = (int)($row['completed'] ?? 0);
  }
} catch (Throwable $e) {}

$pendingTopups = [];
try {
  $st = pdo()->prepare(
    "SELECT dt.*, d.name AS dealer_name, d.code AS dealer_code
       FROM dealer_topups dt
       INNER JOIN dealers d ON d.id = dt.dealer_id
       WHERE dt.status IN (?, ?)
       ORDER BY dt.created_at DESC
       LIMIT 6"
  );
  $st->execute([DEALER_TOPUP_STATUS_PENDING, DEALER_TOPUP_STATUS_AWAITING_REVIEW]);
  $pendingTopups = $st->fetchAll();
} catch (Throwable $e) {}

$pendingDealers = [];
try {
  $st = pdo()->query("SELECT id, name, email, phone, created_at, code FROM dealers WHERE status='pending' ORDER BY created_at ASC LIMIT 6");
  $pendingDealers = $st->fetchAll();
} catch (Throwable $e) {}

function fmt_count($n) {
  return number_format((int)$n, 0, ',', '.');
}

$pendingTopupAmount   = $topupStats[DEALER_TOPUP_STATUS_PENDING]['amount'] ?? 0;
$pendingTopupCount    = $topupStats[DEALER_TOPUP_STATUS_PENDING]['count'] ?? 0;
$reviewTopupAmount    = $topupStats[DEALER_TOPUP_STATUS_AWAITING_REVIEW]['amount'] ?? 0;
$reviewTopupCount     = $topupStats[DEALER_TOPUP_STATUS_AWAITING_REVIEW]['count'] ?? 0;
$completedTopupAmount = $topupStats[DEALER_TOPUP_STATUS_COMPLETED]['amount'] ?? 0;
$completedTopupCount  = $topupStats[DEALER_TOPUP_STATUS_COMPLETED]['count'] ?? 0;

$subtitle = 'Salon: '.$VNAME.' • BİKARE platform göstergeleri';

$statCards = [
  [
    'title' => 'Toplam Bayi',
    'value' => fmt_count($dealerCounts['all'] ?? 0),
    'sub'   => 'Aktif: '.fmt_count($dealerCounts['active'] ?? 0).' • Pasif: '.fmt_count($dealerCounts['inactive'] ?? 0),
    'icon'  => 'bi-shop',
    'href'  => BASE_URL.'/admin/dealers.php',
  ],
  [
    'title' => 'Onay Bekleyen Bayi',
    'value' => fmt_count($dealerCounts['pending'] ?? 0),
    'sub'   => 'Başvuruları Bayiler sayfasından onaylayın.',
    'icon'  => 'bi-person-check',
    'href'  => BASE_URL.'/admin/dealers.php?filter=pending',
  ],
  [
    'title' => 'Aktif Kampanyalar',
    'value' => fmt_count($campaignCount),
    'sub'   => 'Tüm etkinlik panellerinde yayında.',
    'icon'  => 'bi-megaphone',
    'href'  => BASE_URL.'/admin/campaigns.php',
  ],
  [
    'title' => 'Aktif Etkinlik',
    'value' => fmt_count($eventStats['active']),
    'sub'   => 'Toplam etkinlik: '.fmt_count($eventStats['total']).'.',
    'icon'  => 'bi-calendar-event',
    'href'  => BASE_URL.'/admin/venue_events.php',
  ],
  [
    'title' => 'Ödeme Bekleyen',
    'value' => fmt_count($pendingTopupCount),
    'sub'   => 'Toplam tutar '.format_currency($pendingTopupAmount).'.',
    'icon'  => 'bi-credit-card',
    'href'  => BASE_URL.'/admin/dealers.php?tab=finance',
  ],
  [
    'title' => 'İnceleme Bekleyen',
    'value' => fmt_count($reviewTopupCount),
    'sub'   => 'Doğrulanmayı bekleyen tutar '.format_currency($reviewTopupAmount).'.',
    'icon'  => 'bi-clipboard-check',
    'href'  => BASE_URL.'/admin/dealers.php?tab=finance',
  ],
  [
    'title' => 'Tamamlanan Ödemeler',
    'value' => fmt_count($completedTopupCount),
    'sub'   => 'Toplam tahsilat '.format_currency($completedTopupAmount).'.',
    'icon'  => 'bi-cash-coin',
    'href'  => BASE_URL.'/admin/dealers.php?tab=finance',
  ],
  [
    'title' => 'Bekleyen Cashback',
    'value' => fmt_count($cashbackSummary['count']),
    'sub'   => 'Ödenecek tutar '.format_currency($cashbackSummary['amount']).'.',
    'icon'  => 'bi-gift',
    'href'  => BASE_URL.'/admin/dealers.php?tab=cashback',
  ],
  [
    'title' => 'Yaklaşan Etkinlik',
    'value' => fmt_count($eventStats['upcoming']),
    'sub'   => 'Önümüzdeki tarihler için hazır.',
    'icon'  => 'bi-flag',
    'href'  => BASE_URL.'/admin/venue_events.php?filter=upcoming',
  ],
  [
    'title' => 'Tamamlanan Etkinlik',
    'value' => fmt_count($eventStats['completed']),
    'sub'   => 'Arşivde güvenli şekilde saklanıyor.',
    'icon'  => 'bi-check2-circle',
    'href'  => BASE_URL.'/admin/venue_events.php?filter=past',
  ],
  [
    'title' => 'Aktif Çiftler',
    'value' => fmt_count($eventStats['active']),
    'sub'   => 'Panel erişimi açık çift sayısı.',
    'icon'  => 'bi-people',
    'href'  => BASE_URL.'/admin/venue_events.php?filter=active',
  ],
  [
    'title' => 'Günlük Güncelleme',
    'value' => date('d.m'),
    'sub'   => 'Raporlar güncel verilerden oluşturuldu.',
    'icon'  => 'bi-arrow-repeat',
    'href'  => null,
  ],
];
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h(APP_NAME)?> — Panel (<?=h($VNAME)?>)</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?=admin_base_styles()?>
<style>
  .stat-board{margin-bottom:3rem;}
  .stat-card{position:relative; display:block; text-decoration:none; background:linear-gradient(145deg, rgba(14,165,181,.15), rgba(255,255,255,.9)); border:1px solid rgba(14,165,181,.18); border-radius:26px; padding:24px 26px; box-shadow:0 28px 60px -38px rgba(14,165,181,.45); color:var(--ink); min-height:180px; overflow:hidden; transition:transform .2s ease, box-shadow .2s ease, border-color .2s ease;}
  .stat-card::after{content:""; position:absolute; inset:auto -70px -70px auto; width:180px; height:180px; background:rgba(14,165,181,.18); filter:blur(0); border-radius:50%; transition:opacity .3s ease;} 
  .stat-card:hover{transform:translateY(-6px); box-shadow:0 38px 70px -36px rgba(14,165,181,.55); border-color:rgba(14,165,181,.35);} 
  .stat-card.is-static{cursor:default;} 
  .stat-card.is-static:hover{transform:none; box-shadow:0 28px 60px -38px rgba(14,165,181,.45); border-color:rgba(14,165,181,.18);} 
  .stat-icon{width:58px; height:58px; border-radius:20px; display:flex; align-items:center; justify-content:center; background:rgba(14,165,181,.18); color:var(--brand); font-size:1.5rem; margin-bottom:18px;} 
  .stat-title{font-size:.8rem; letter-spacing:.14em; text-transform:uppercase; color:var(--admin-muted); font-weight:600; margin-bottom:6px;} 
  .stat-value{font-size:2.3rem; font-weight:700; color:var(--ink); margin-bottom:6px;} 
  .stat-sub{font-size:.95rem; color:var(--admin-muted); max-width:220px;} 
  .stat-arrow{position:absolute; right:24px; top:28px; font-size:1.2rem; color:rgba(14,165,181,.65); transition:transform .2s ease;} 
  .stat-card:hover .stat-arrow{transform:translateX(4px);} 
  .quick-actions{ display:grid; gap:16px; }
  .quick-actions a{ display:flex; align-items:center; justify-content:space-between; background:var(--surface); border-radius:18px; padding:18px 20px; text-decoration:none; color:var(--ink); box-shadow:0 22px 60px -40px rgba(15,23,42,.55); transition:transform .2s ease, box-shadow .2s ease; }
  .quick-actions a:hover{ transform:translateY(-2px); box-shadow:0 30px 62px -42px rgba(14,165,181,.45); }
  .quick-actions .info{ display:flex; gap:14px; align-items:center; }
  .quick-actions .info i{ width:44px; height:44px; border-radius:14px; display:flex; align-items:center; justify-content:center; background:rgba(14,165,181,.15); color:var(--brand); font-size:1.2rem; }
  .quick-actions .info span{ display:block; font-weight:600; }
  .quick-actions small{ display:block; font-size:.86rem; color:var(--admin-muted); margin-top:2px; }
  .status-table{ background:var(--surface); border-radius:22px; padding:22px; box-shadow:0 24px 60px -40px rgba(15,23,42,.48); }
  .status-table h5{ font-weight:700; margin-bottom:12px; }
  .status-table table{ font-size:.92rem; margin-bottom:0; }
  .status-table .empty{ color:var(--admin-muted); font-size:.9rem; }
</style>
</head>
<body class="admin-body">
<?php admin_layout_start('dashboard', 'Genel Bakış', $subtitle); ?>

  <?php flash_box(); ?>

  <div class="stat-board">
    <div class="row g-3 row-cols-1 row-cols-sm-2 row-cols-lg-3 row-cols-xl-4">
      <?php foreach ($statCards as $card): ?>
        <?php $isLink = !empty($card['href']); $tag = $isLink ? 'a' : 'div'; ?>
        <div class="col">
          <<?=$tag?> class="stat-card<?=$isLink ? '' : ' is-static'?>" <?php if ($isLink): ?>href="<?=h($card['href'])?>"<?php endif; ?>>
            <span class="stat-icon"><i class="bi <?=h($card['icon'])?>"></i></span>
            <div class="stat-title"><?=h($card['title'])?></div>
            <div class="stat-value"><?=h($card['value'])?></div>
            <div class="stat-sub"><?=h($card['sub'])?></div>
            <?php if ($isLink): ?><span class="stat-arrow"><i class="bi bi-arrow-up-right"></i></span><?php endif; ?>
          </<?=$tag?>>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="row g-4 mb-5">
    <div class="col-xl-6">
      <div class="status-table h-100">
        <h5>Bekleyen Ödeme Talepleri</h5>
        <?php if (!$pendingTopups): ?>
          <div class="empty">Şu anda bekleyen ödeme talebi bulunmuyor.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table align-middle">
              <thead><tr><th>Bayi</th><th>Tutar</th><th>Durum</th><th>Tarih</th></tr></thead>
              <tbody>
                <?php foreach ($pendingTopups as $req): ?>
                  <tr>
                    <td>
                      <strong><?=h($req['dealer_name'] ?? 'Bayi')?></strong>
                      <?php if (!empty($req['dealer_code'])): ?>
                        <div class="text-muted small">Kod: <?=h($req['dealer_code'])?></div>
                      <?php endif; ?>
                    </td>
                    <td><?=format_currency((int)$req['amount_cents'])?></td>
                    <td><?=h(dealer_topup_status_label($req['status']))?></td>
                    <td class="text-muted small"><?=h(date('d.m.Y H:i', strtotime($req['created_at'] ?? 'now')))?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
        <a class="btn btn-link px-0 mt-2" href="<?=h(BASE_URL)?>/admin/dealers.php">Bayi faturalandırmasını yönet</a>
      </div>
    </div>
    <div class="col-xl-6">
      <div class="status-table h-100">
        <h5>Onay Bekleyen Bayiler</h5>
        <?php if (!$pendingDealers): ?>
          <div class="empty">Bekleyen bayi başvurusu bulunmuyor.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table align-middle">
              <thead><tr><th>Bayi</th><th>İletişim</th><th>Başvuru</th></tr></thead>
              <tbody>
                <?php foreach ($pendingDealers as $dealer): ?>
                  <tr>
                    <td>
                      <strong><?=h($dealer['name'] ?? 'Bayi')?></strong>
                      <?php if (!empty($dealer['code'])): ?>
                        <div class="text-muted small">Kod: <?=h($dealer['code'])?></div>
                      <?php endif; ?>
                    </td>
                    <td class="small">
                      <?php if (!empty($dealer['email'])): ?>
                        <div><?=h($dealer['email'])?></div>
                      <?php endif; ?>
                      <?php if (!empty($dealer['phone'])): ?>
                        <div><?=h($dealer['phone'])?></div>
                      <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?=h(date('d.m.Y H:i', strtotime($dealer['created_at'] ?? 'now')))?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
        <a class="btn btn-link px-0 mt-2" href="<?=h(BASE_URL)?>/admin/dealers.php?filter=pending">Başvuruları incele</a>
      </div>
    </div>
  </div>

  <div class="row g-4 mb-5">
    <div class="col-12 col-xl-6">
      <div class="status-table h-100">
        <h5>Kolay Erişim</h5>
        <div class="quick-actions">
          <a href="<?=h(BASE_URL)?>/admin/venues.php">
            <div class="info"><i class="bi bi-building"></i><div><span>Salon Yönetimi</span><small>Salonlarınızı ve etkinliklerini düzenleyin.</small></div></div>
            <i class="bi bi-chevron-right"></i>
          </a>
          <a href="<?=h(BASE_URL)?>/admin/campaigns.php">
            <div class="info"><i class="bi bi-megaphone"></i><div><span>Kampanyalar</span><small>Tüm çift panellerinde yayınlanacak kampanyaları güncelleyin.</small></div></div>
            <i class="bi bi-chevron-right"></i>
          </a>
          <a href="<?=h(BASE_URL)?>/admin/dealers.php">
            <div class="info"><i class="bi bi-shop"></i><div><span>Bayi Yönetimi</span><small>Başvuruları onaylayın, bakiye ve paketlerini takip edin.</small></div></div>
            <i class="bi bi-chevron-right"></i>
          </a>
          <?php if (is_superadmin()): ?>
          <a href="<?=h(BASE_URL)?>/admin/dealer_packages.php">
            <div class="info"><i class="bi bi-box"></i><div><span>Paketler</span><small>Bayi paketleri ve fiyatlandırmasını yönetin.</small></div></div>
            <i class="bi bi-chevron-right"></i>
          </a>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-12 col-xl-6">
      <div class="status-table h-100">
        <h5>Notlar</h5>
        <ul class="mb-0 ps-3">
          <li>Bayi bakiyeleri PayTR test modunda otomatik olarak onaylanır; canlıya alırken PayTR ayarlarını güncellemeyi unutmayın.</li>
          <li>Cashback işlemleri onaylandıktan sonra bayi ve müşteri e-postalarına otomatik bilgilendirme gönderilir.</li>
          <li>Kampanya sayfasından yayınlanan içerikler tüm çift panellerinde eş zamanlı olarak görünür.</li>
        </ul>
      </div>
    </div>
  </div>

<?php admin_layout_end(); ?>
</body>
</html>
