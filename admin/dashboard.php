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
  .stat-grid .stat-card{ display:flex; gap:18px; align-items:flex-start; background:var(--surface); border-radius:22px; padding:22px; box-shadow:0 24px 60px -38px rgba(14,165,181,.55); position:relative; overflow:hidden; }
  .stat-grid .stat-card::after{ content:''; position:absolute; inset:auto -70px -70px auto; width:160px; height:160px; border-radius:50%; background:rgba(14,165,181,.1); }
  .stat-icon{ width:58px; height:58px; border-radius:18px; display:flex; align-items:center; justify-content:center; background:rgba(14,165,181,.15); color:var(--brand); font-size:1.5rem; flex-shrink:0; }
  .stat-meta{ position:relative; z-index:1; }
  .stat-title{ font-size:.78rem; letter-spacing:.16em; text-transform:uppercase; color:var(--admin-muted); font-weight:600; margin-bottom:6px; }
  .stat-value{ font-size:2.1rem; font-weight:700; color:var(--ink); margin-bottom:4px; }
  .stat-sub{ font-size:.9rem; color:var(--admin-muted); }
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

  <div class="stat-grid row row-cols-1 row-cols-md-2 row-cols-xl-4 g-3 mb-4">
    <div class="col"><div class="stat-card">
      <div class="stat-icon"><i class="bi bi-shop"></i></div>
      <div class="stat-meta">
        <div class="stat-title">Toplam Bayi</div>
        <div class="stat-value"><?=fmt_count($dealerCounts['all'] ?? 0)?></div>
        <div class="stat-sub">Aktif: <?=fmt_count($dealerCounts['active'] ?? 0)?> • Pasif: <?=fmt_count($dealerCounts['inactive'] ?? 0)?></div>
      </div>
    </div></div>
    <div class="col"><div class="stat-card">
      <div class="stat-icon"><i class="bi bi-person-check"></i></div>
      <div class="stat-meta">
        <div class="stat-title">Onay Bekleyen Bayi</div>
        <div class="stat-value"><?=fmt_count($dealerCounts['pending'] ?? 0)?></div>
        <div class="stat-sub">Yeni başvuruları Bayiler sayfasından inceleyin.</div>
      </div>
    </div></div>
    <div class="col"><div class="stat-card">
      <div class="stat-icon"><i class="bi bi-megaphone"></i></div>
      <div class="stat-meta">
        <div class="stat-title">Aktif Kampanyalar</div>
        <div class="stat-value"><?=fmt_count($campaignCount)?></div>
        <div class="stat-sub">Tüm etkinlik panellerinde yayında.</div>
      </div>
    </div></div>
    <div class="col"><div class="stat-card">
      <div class="stat-icon"><i class="bi bi-calendar-event"></i></div>
      <div class="stat-meta">
        <div class="stat-title">Aktif Etkinlik</div>
        <div class="stat-value"><?=fmt_count($eventStats['active'])?></div>
        <div class="stat-sub">Toplam etkinlik: <?=fmt_count($eventStats['total'])?>.</div>
      </div>
    </div></div>
  </div>

  <div class="stat-grid row row-cols-1 row-cols-md-2 row-cols-xl-4 g-3 mb-4">
    <div class="col"><div class="stat-card">
      <div class="stat-icon"><i class="bi bi-credit-card"></i></div>
      <div class="stat-meta">
        <div class="stat-title">Ödeme Bekleyen</div>
        <div class="stat-value"><?=fmt_count($pendingTopupCount)?></div>
        <div class="stat-sub">Toplam tutar <?=format_currency($pendingTopupAmount)?>.</div>
      </div>
    </div></div>
    <div class="col"><div class="stat-card">
      <div class="stat-icon"><i class="bi bi-clipboard-check"></i></div>
      <div class="stat-meta">
        <div class="stat-title">İnceleme Bekleyen</div>
        <div class="stat-value"><?=fmt_count($reviewTopupCount)?></div>
        <div class="stat-sub">Doğrulanmayı bekleyen tutar <?=format_currency($reviewTopupAmount)?>.</div>
      </div>
    </div></div>
    <div class="col"><div class="stat-card">
      <div class="stat-icon"><i class="bi bi-cash-coin"></i></div>
      <div class="stat-meta">
        <div class="stat-title">Tamamlanan Ödemeler</div>
        <div class="stat-value"><?=fmt_count($completedTopupCount)?></div>
        <div class="stat-sub">Toplam tahsilat <?=format_currency($completedTopupAmount)?>.</div>
      </div>
    </div></div>
    <div class="col"><div class="stat-card">
      <div class="stat-icon"><i class="bi bi-gift"></i></div>
      <div class="stat-meta">
        <div class="stat-title">Bekleyen Cashback</div>
        <div class="stat-value"><?=fmt_count($cashbackSummary['count'])?></div>
        <div class="stat-sub">Ödenecek tutar <?=format_currency($cashbackSummary['amount'])?>.</div>
      </div>
    </div></div>
  </div>

  <div class="stat-grid row row-cols-1 row-cols-md-2 row-cols-xl-4 g-3 mb-5">
    <div class="col"><div class="stat-card">
      <div class="stat-icon"><i class="bi bi-flag"></i></div>
      <div class="stat-meta">
        <div class="stat-title">Yaklaşan Etkinlik</div>
        <div class="stat-value"><?=fmt_count($eventStats['upcoming'])?></div>
        <div class="stat-sub">Önümüzdeki tarihler için hazır.</div>
      </div>
    </div></div>
    <div class="col"><div class="stat-card">
      <div class="stat-icon"><i class="bi bi-check2-circle"></i></div>
      <div class="stat-meta">
        <div class="stat-title">Tamamlanan Etkinlik</div>
        <div class="stat-value"><?=fmt_count($eventStats['completed'])?></div>
        <div class="stat-sub">Arşivde güvenli şekilde saklanıyor.</div>
      </div>
    </div></div>
    <div class="col"><div class="stat-card">
      <div class="stat-icon"><i class="bi bi-people"></i></div>
      <div class="stat-meta">
        <div class="stat-title">Aktif Çiftler</div>
        <div class="stat-value"><?=fmt_count($eventStats['active'])?></div>
        <div class="stat-sub">Panel erişimi açık çift sayısı.</div>
      </div>
    </div></div>
    <div class="col"><div class="stat-card">
      <div class="stat-icon"><i class="bi bi-arrow-repeat"></i></div>
      <div class="stat-meta">
        <div class="stat-title">Günlük Güncelleme</div>
        <div class="stat-value"><?=date('d.m')?></div>
        <div class="stat-sub">Raporlar güncel verilerden oluşturuldu.</div>
      </div>
    </div></div>
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
