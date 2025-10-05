<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/dealers.php';
require_once __DIR__.'/../includes/representatives.php';
require_once __DIR__.'/../includes/dealer_crm.php';
require_once __DIR__.'/../includes/dealer_auth.php';
require_once __DIR__.'/partials/ui.php';

install_schema();

dealer_require_login();
$sessionDealer = dealer_user();
$dealer = dealer_get((int)$sessionDealer['id']);
if (!$dealer) {
  dealer_logout();
  redirect('login.php');
}

dealer_refresh_session((int)$dealer['id']);
$refCode = $dealer['code'] ?: dealer_ensure_identifier((int)$dealer['id']);

$venues  = dealer_fetch_venues((int)$dealer['id']);
$events  = dealer_allowed_events((int)$dealer['id']);
$warning = dealer_license_warning($dealer);
$creationStatus = dealer_event_creation_status($dealer);
$canCreate = $creationStatus['allowed'];
$quotaSummary = $creationStatus['summary'];
$balance = dealer_get_balance((int)$dealer['id']);
$representative = representative_for_dealer((int)$dealer['id']);
$leadStats = dealer_lead_status_counts((int)$dealer['id']);
$leadTotal = array_sum($leadStats);
$leadStatusLabels = dealer_lead_status_options();
$upcomingActions = dealer_lead_upcoming_actions((int)$dealer['id'], 5);
$recentLeadNotes = dealer_lead_recent_notes((int)$dealer['id'], 5);
$totalCashback = dealer_total_cashback((int)$dealer['id']);
$cashbackPending = dealer_cashback_candidates((int)$dealer['id'], DEALER_CASHBACK_PENDING);
$pendingCashbackCount = count($cashbackPending);
$pendingCashbackAmount = 0;
foreach ($cashbackPending as $row) {
  $pendingCashbackAmount += max(0, (int)$row['cashback_amount']);
}

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

$licenseLabel = dealer_license_label($dealer);
$quotaLabel = $quotaSummary['has_unlimited'] ? 'Sınırsız' : (string)$quotaSummary['remaining_events'];
$quotaHint  = $quotaSummary['has_unlimited']
  ? ($quotaSummary['unlimited_until'] ? 'Süre bitişi: '.date('d.m.Y', strtotime($quotaSummary['unlimited_until'])) : 'Süre sınırı yok')
  : 'Kalan etkinlik hakkı';

$pageStyles = <<<'CSS'
<style>
  .dashboard-badges{display:flex;flex-wrap:wrap;gap:.65rem;margin-bottom:1.25rem;}
  .dashboard-badges .badge-soft{background:rgba(14,165,181,.12);color:#0b8b98;border-radius:999px;padding:.4rem .9rem;font-weight:600;font-size:.85rem;display:inline-flex;align-items:center;gap:.4rem;}
  .dashboard-badges .badge-soft i{font-size:1rem;}
  .card-lite{border:1px solid rgba(15,23,42,.05);border-radius:22px;background:#fff;box-shadow:0 24px 50px -34px rgba(15,23,42,.45);}
  .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:1.2rem;}
  .stat-card{padding:1.45rem;border-radius:18px;background:linear-gradient(150deg,#fff,rgba(14,165,181,.1));position:relative;overflow:hidden;}
  .stat-card h6{font-size:.78rem;text-transform:uppercase;letter-spacing:.08em;color:#64748b;margin-bottom:.35rem;}
  .stat-card strong{font-size:1.7rem;display:block;}
  .stat-card span{color:#64748b;font-size:.82rem;}
  .btn-brand{background:#0ea5b5;border:none;color:#fff;border-radius:14px;padding:.6rem 1.35rem;font-weight:600;box-shadow:0 12px 22px -14px rgba(14,165,181,.65);} 
  .btn-brand:hover{background:#0b8b98;color:#fff;}
  .btn-outline-brand{background:#fff;border:1px solid rgba(14,165,181,.45);color:#0ea5b5;border-radius:14px;padding:.6rem 1.35rem;font-weight:600;}
  .btn-outline-brand:hover{background:rgba(14,165,181,.08);color:#0b8b98;}
  .status-table{border-radius:22px;background:#fff;border:1px solid rgba(15,23,42,.06);padding:1.8rem;box-shadow:0 24px 50px -34px rgba(15,23,42,.4);}
  .status-table h5{font-weight:700;margin-bottom:1.2rem;}
  .status-table .empty{padding:2rem;text-align:center;color:#64748b;border:1px dashed rgba(148,163,184,.5);border-radius:16px;}
  .timeline-card{border-radius:22px;background:#fff;border:1px solid rgba(15,23,42,.05);padding:1.9rem;box-shadow:0 24px 50px -36px rgba(15,23,42,.4);}
  .timeline-card h5{font-weight:700;margin-bottom:1.2rem;}
  .timeline-card .empty{padding:1.6rem;text-align:center;color:#64748b;border-radius:16px;border:1px dashed rgba(148,163,184,.45);}
  .timeline{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:1rem;}
  .timeline li{display:flex;gap:1rem;align-items:flex-start;}
  .timeline .dot{width:38px;height:38px;border-radius:12px;background:linear-gradient(145deg,rgba(14,165,181,.2),rgba(14,165,181,.45));display:flex;align-items:center;justify-content:center;color:#0b8b98;font-weight:700;}
  .timeline .content{flex:1;}
  .timeline .content strong{display:block;font-weight:700;}
  .timeline .content span{display:block;color:#64748b;font-size:.85rem;}
  .info-card{border:1px solid rgba(148,163,184,.35);border-radius:18px;padding:1.3rem;background:linear-gradient(140deg,#fff,rgba(14,165,181,.06));height:100%;}
  .info-card h6{font-weight:700;margin-bottom:.35rem;}
  .info-card p{color:#64748b;font-size:.86rem;}
  .badge-soft{background:rgba(14,165,181,.12);color:#0ea5b5;border-radius:999px;padding:.35rem .75rem;font-weight:600;font-size:.82rem;display:inline-flex;align-items:center;}
  .rep-contact{display:flex;align-items:center;gap:1rem;margin-bottom:1.25rem;}
  .rep-contact .avatar{width:58px;height:58px;border-radius:18px;background:linear-gradient(150deg,rgba(14,165,181,.25),rgba(14,165,181,.55));display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1.35rem;color:#0b8b98;}
  .rep-contact .info{display:flex;flex-direction:column;gap:.2rem;}
  .rep-contact .info span{font-size:.85rem;color:#64748b;}
  .crm-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:1rem;}
  .crm-stat{border:1px solid rgba(148,163,184,.2);border-radius:18px;padding:1rem;background:linear-gradient(150deg,#fff,rgba(14,165,181,.08));box-shadow:0 18px 40px -32px rgba(15,23,42,.35);}
  .crm-stat .label{font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;color:#64748b;margin-bottom:.35rem;font-weight:600;}
  .crm-stat strong{font-size:1.4rem;color:#0f172a;display:block;}
  .crm-stat.highlight{background:linear-gradient(160deg,#0ea5b5,#6366f1);color:#fff;border:none;box-shadow:0 24px 58px -30px rgba(14,165,181,.7);}
  .crm-stat.highlight .label{color:rgba(255,255,255,.85);}
  .crm-stat.highlight strong{color:#fff;}
  .crm-timeline{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:1rem;}
  .crm-timeline li{display:flex;gap:.9rem;}
  .crm-timeline .dot{width:38px;height:38px;border-radius:12px;background:rgba(14,165,181,.12);color:#0b8b98;display:flex;align-items:center;justify-content:center;font-size:1.1rem;}
  .crm-timeline .content strong{display:block;font-weight:600;color:#0f172a;}
  .crm-timeline .content span{display:block;font-size:.85rem;color:#64748b;}
  .crm-timeline .content .badge{margin-top:.4rem;font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;}
  .crm-notes{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:1rem;}
  .crm-notes li{border:1px solid rgba(148,163,184,.22);border-radius:16px;padding:1rem;background:#f8fafc;}
  .crm-notes .title{font-weight:600;color:#0f172a;margin-bottom:.35rem;}
  .crm-notes p{margin:0 0 .45rem 0;color:#475569;font-size:.9rem;}
  .crm-notes .meta{display:flex;flex-wrap:wrap;gap:.6rem;font-size:.75rem;color:#64748b;text-transform:uppercase;letter-spacing:.05em;}
  @media (max-width: 991px){
    .status-table,.timeline-card{padding:1.4rem;}
  }
</style>
CSS;

dealer_layout_start('dashboard', [
  'page_title'   => APP_NAME.' — Bayi Paneli',
  'title'        => 'Merhaba '.$dealer['name'].' 👋',
  'subtitle'     => 'Atanmış salonlarınızı yönetin, etkinlikleri takip edin ve BİKARE avantajlarını keşfedin.',
  'dealer'       => $dealer,
  'representative' => $representative,
  'venues'       => $venues,
  'balance_text' => format_currency($balance),
  'license_text' => $licenseLabel,
  'ref_code'     => $refCode,
  'extra_head'   => $pageStyles,
]);
?>
<section class="mb-5">
  <div class="card-lite p-4 p-lg-5">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-4">
      <div class="flex-grow-1">
        <div class="dashboard-badges">
          <span class="badge-soft"><i class="bi bi-shield-check"></i>Lisans: <?=h($licenseLabel)?></span>
          <span class="badge-soft"><i class="bi bi-upc-scan"></i>Kod: <?=h($refCode)?></span>
          <?php if ($warning): ?>
            <span class="badge bg-warning-subtle text-warning-emphasis fw-semibold"><i class="bi bi-exclamation-triangle"></i><?=h($warning)?></span>
          <?php else: ?>
            <span class="badge-soft"><i class="bi bi-check-circle"></i><?= dealer_has_valid_license($dealer) ? 'Lisansınız aktif' : 'Lisans süreniz dolmak üzere' ?></span>
          <?php endif; ?>
          <a class="btn btn-brand btn-sm" href="billing.php"><i class="bi bi-wallet2 me-1"></i>Bakiye &amp; Paketler</a>
        </div>
        <p class="text-muted mb-0">Panelinizi kullanarak etkinliklerinizi yönetebilir, QR kodlarınıza erişebilir ve müşterilerinize anında bilgi gönderebilirsiniz.</p>
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
          <span>Finansal hareketlerinizi takip edin</span>
        </div>
        <div class="stat-card">
          <h6>Cashback</h6>
          <strong><?=h(format_currency($totalCashback))?></strong>
          <span><?=$pendingCashbackCount?> bekleyen onay</span>
        </div>
        <div class="stat-card">
          <h6>Etkinlik Hakkı</h6>
          <strong><?=h($quotaLabel)?></strong>
          <span><?=h($quotaHint)?></span>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="mb-4">
  <div class="row g-4">
    <div class="col-xl-4">
      <div class="card-lite p-4 h-100">
        <h5 class="mb-3">Temsilciniz</h5>
        <?php if ($representative): ?>
          <?php $repInitial = mb_strtoupper(mb_substr($representative['name'], 0, 1, 'UTF-8'), 'UTF-8'); ?>
          <div class="rep-contact">
            <div class="avatar"><?=h($repInitial)?></div>
            <div class="info">
              <strong><?=h($representative['name'])?></strong>
              <span><?=h($representative['email'])?></span>
              <?php if (!empty($representative['phone'])): ?><span><?=h($representative['phone'])?></span><?php endif; ?>
            </div>
          </div>
          <p class="text-muted small mb-3">Temsilciniz yüklemelerinizden %<?=h(number_format($representative['commission_rate'], 1))?> komisyon kazanır ve potansiyel müşterilerinizin takibini sağlar.</p>
          <div class="d-grid gap-2">
            <a class="btn btn-outline-brand" href="leads.php"><i class="bi bi-people me-1"></i>Potansiyel Müşterileri Yönet</a>
            <a class="btn btn-outline-brand" href="<?=h(BASE_URL.'/representative/login.php')?>" target="_blank"><i class="bi bi-box-arrow-up-right me-1"></i>Temsilci Paneli</a>
          </div>
        <?php else: ?>
          <p class="text-muted mb-3">Henüz hesabınıza atanmış bir temsilci bulunmuyor. Yönetici ekibimizle iletişime geçerek temsilci talep edebilirsiniz.</p>
          <a class="btn btn-outline-brand" href="leads.php">Potansiyel Müşteri Listesi</a>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-xl-8">
      <div class="card-lite p-4 h-100">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
          <h5 class="mb-0">CRM Özeti</h5>
          <a class="btn btn-sm btn-outline-brand" href="leads.php">CRM'yi Aç</a>
        </div>
        <div class="crm-stats mb-4">
          <div class="crm-stat highlight">
            <span class="label">Toplam Potansiyel</span>
            <strong><?=$leadTotal?></strong>
          </div>
          <?php foreach ($leadStats as $statusKey => $count): ?>
            <?php $label = $leadStatusLabels[$statusKey] ?? ucfirst($statusKey); ?>
            <div class="crm-stat">
              <span class="label"><?=h($label)?></span>
              <strong><?= (int)$count ?></strong>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="row g-4">
          <div class="col-md-6">
            <h6 class="fw-semibold mb-2">Yaklaşan Aksiyonlar</h6>
            <?php if (!$upcomingActions): ?>
              <p class="text-muted small mb-0">Planlanmış görüşme bulunmuyor.</p>
            <?php else: ?>
              <ul class="crm-timeline">
                <?php foreach ($upcomingActions as $item): ?>
                  <li>
                    <div class="dot"><i class="bi bi-calendar-event"></i></div>
                    <div class="content">
                      <strong><?=h($item['name'])?></strong>
                      <span><?=h(date('d.m.Y H:i', strtotime($item['next_action_at'])))?></span>
                      <span class="badge bg-light text-dark"><?=h($leadStatusLabels[$item['status']] ?? ucfirst($item['status']))?></span>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
          <div class="col-md-6">
            <h6 class="fw-semibold mb-2">Son Görüşme Notları</h6>
            <?php if (!$recentLeadNotes): ?>
              <p class="text-muted small mb-0">Henüz görüşme notu eklenmedi.</p>
            <?php else: ?>
              <ul class="crm-notes">
                <?php foreach ($recentLeadNotes as $note): ?>
                  <li>
                    <div class="title"><?=h($note['lead_name'])?></div>
                    <p><?=nl2br(h($note['note']))?></p>
                    <div class="meta">
                      <span><?=h(date('d.m.Y H:i', strtotime($note['created_at'])))?></span>
                      <?php if (!empty($note['representative_name'])): ?><span><?=h($note['representative_name'])?></span><?php endif; ?>
                      <?php if (!empty($note['contact_type'])): ?><span><?=h($note['contact_type'])?></span><?php endif; ?>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
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

<div class="alert alert-info d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
  <div>
    Web üzerinden gelen referans satışlarınız <strong>%20 cashback</strong> kazandırır ve finans onayı sonrasında bakiyenize eklenir.
    <?php if ($pendingCashbackCount): ?>Şu anda onay bekleyen <strong><?=h($pendingCashbackCount)?></strong> ödeme var (<?=h(format_currency($pendingCashbackAmount))?>).<?php elseif ($totalCashback > 0): ?>Bugüne kadar <strong><?=h(format_currency($totalCashback))?></strong> kazandınız.<?php endif; ?>
  </div>
  <a class="btn btn-sm btn-outline-brand" href="billing.php#wallet">Hareketleri Gör</a>
</div>

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
    <div class="status-table empty">Henüz size atanmış salon bulunmuyor. Lütfen yönetici ile iletişime geçin.</div>
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

<div class="card-lite p-4 mb-4">
  <h5 class="mb-3">Panel İpuçları</h5>
  <div class="row g-3">
    <div class="col-md-4">
      <div class="info-card">
        <h6>Kalıcı QR Yönetimi</h6>
        <p class="mb-0">Her salon için kalıcı QR kodlarını <strong>Etkinlikleri Yönet</strong> sayfasından görüntüleyip davetlilerinizle paylaşabilirsiniz.</p>
      </div>
    </div>
    <div class="col-md-4">
      <div class="info-card">
        <h6>Cari &amp; Paket Takibi</h6>
        <p class="mb-0"><strong>Bakiye &amp; Paketler</strong> alanından bakiyenizi takip edin, paket satın alın ve geçmiş hareketlerinizi inceleyin.</p>
      </div>
    </div>
    <div class="col-md-4">
      <div class="info-card">
        <h6>Cashback Avantajı</h6>
        <p class="mb-0">Tekli paketlerinizde gerçekleşen satışlar için %50 cashback talebinizi aynı sayfadan takip edebilirsiniz.</p>
      </div>
    </div>
  </div>
</div>

<?php dealer_layout_end();
