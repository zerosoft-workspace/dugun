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
$dealerId = (int)$dealer['id'];
$activeNav = 'billing';

$action = $_POST['do'] ?? '';
if ($action) {
  csrf_or_die();
}

try {
  if ($action === 'request_topup') {
    $amountInput = $_POST['amount'] ?? '';
    $amountCents = money_to_cents($amountInput);
    if ($amountCents < 10000) {
      throw new RuntimeException('Minimum 100 TL yükleme yapabilirsiniz.');
    }
    dealer_create_topup_request($dealerId, $amountCents);
    flash('ok', 'Bakiye yükleme talebiniz alındı. PayTR doğrulaması sonrası bakiyenize yansıyacaktır.');
    redirect('billing.php#topup');
  }
  if ($action === 'buy_package') {
    $packageId = (int)($_POST['package_id'] ?? 0);
    if ($packageId <= 0) {
      throw new RuntimeException('Paket seçilmedi.');
    }
    dealer_purchase_package($dealerId, $packageId);
    flash('ok', 'Paket satın alma işlemi tamamlandı.');
    redirect('billing.php');
  }
  if ($action === 'cancel_topup') {
    $topupId = (int)($_POST['topup_id'] ?? 0);
    if ($topupId <= 0) {
      throw new RuntimeException('Kayıt bulunamadı.');
    }
    dealer_cancel_topup($topupId, $dealerId);
    flash('ok', 'Bekleyen yükleme talebiniz iptal edildi.');
    redirect('billing.php#topup');
  }
} catch (Throwable $e) {
  flash('err', $e->getMessage());
  redirect('billing.php');
}

dealer_refresh_purchase_states($dealerId);
$balance = dealer_get_balance($dealerId);
$summary = dealer_event_quota_summary($dealerId);
$packages = dealer_packages_available();
$walletTransactions = dealer_wallet_transactions($dealerId, 20);
$purchaseHistory = dealer_fetch_purchases($dealerId);
$topups = dealer_topups_for_dealer($dealerId);
$pendingTopups = array_filter($topups, fn($row) => $row['status'] === DEALER_TOPUP_STATUS_PENDING);

?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h(APP_NAME)?> — Bakiye & Paketler</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  :root{
    --brand:#0ea5b5;
    --brand-dark:#0b8b98;
    --ink:#0f172a;
    --muted:#64748b;
    --soft:#f1f7fb;
  }
  body{background:var(--soft);color:var(--ink);font-family:'Inter','Segoe UI',system-ui,sans-serif;min-height:100vh;}
  a{color:var(--brand);} a:hover{color:var(--brand-dark);}
  .dealer-topnav{background:#fff;border-bottom:1px solid rgba(148,163,184,.22);box-shadow:0 12px 30px rgba(15,23,42,.05);}
  .dealer-topnav .navbar-brand{font-weight:700;color:var(--ink);}
  .dealer-topnav .nav-link{color:var(--muted);font-weight:600;border-radius:12px;padding:.45rem .95rem;}
  .dealer-topnav .nav-link:hover{color:var(--brand-dark);background:rgba(14,165,181,.1);}
  .dealer-topnav .nav-link.active{color:var(--brand);background:rgba(14,165,181,.18);}
  .dealer-topnav .badge-soft{background:rgba(14,165,181,.12);color:var(--brand-dark);border-radius:999px;padding:.3rem .85rem;font-weight:600;font-size:.85rem;}
  .billing-hero{padding:2.4rem 0 1.8rem;}
  .card-lite{border-radius:20px;background:#fff;border:1px solid rgba(148,163,184,.16);box-shadow:0 22px 45px -28px rgba(15,23,42,.45);}
  .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1.1rem;}
  .stat-card{padding:1.35rem;border-radius:16px;background:linear-gradient(150deg,#fff,rgba(14,165,181,.1));}
  .stat-card h6{font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:.35rem;}
  .stat-card strong{font-size:1.6rem;display:block;}
  .stat-card span{color:var(--muted);font-size:.82rem;}
  .package-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1.2rem;}
  .package-card{border:1px solid rgba(148,163,184,.25);border-radius:18px;padding:1.4rem;background:#fff;display:flex;flex-direction:column;gap:1rem;box-shadow:0 16px 35px -28px rgba(15,23,42,.45);}
  .package-card h6{font-weight:700;}
  .package-card .price{font-size:1.8rem;font-weight:700;}
  .package-card ul{margin:0;padding-left:1.1rem;color:var(--muted);font-size:.9rem;}
  .btn-brand{background:var(--brand);border:none;color:#fff;border-radius:14px;padding:.6rem 1.3rem;font-weight:600;}
  .btn-brand:hover{background:var(--brand-dark);color:#fff;}
  .btn-outline-brand{background:#fff;border:1px solid rgba(14,165,181,.55);color:var(--brand);border-radius:14px;padding:.6rem 1.3rem;font-weight:600;}
  .btn-outline-brand:hover{background:rgba(14,165,181,.08);color:var(--brand-dark);}
  .table thead th{color:var(--muted);font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;}
  .section-title{font-weight:700;}
  .muted{color:var(--muted);}
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
</header>
<main class="billing-main">
  <div class="container py-4">
    <?php flash_box(); ?>
    <section class="billing-hero mb-4">
      <div class="card-lite p-4 p-lg-5">
        <div class="stat-grid">
          <div class="stat-card">
            <h6>Bakiye</h6>
            <strong><?=h(format_currency($balance))?></strong>
            <span>Bakiye hareketleri ve yüklemeleriniz</span>
          </div>
          <div class="stat-card">
            <h6>Aktif Paket</h6>
            <strong><?=h(count($summary['active']))?></strong>
            <span><?= $summary['has_credit'] ? 'Etkinlik oluşturmaya hazırsınız' : 'Paket satın almanız gerekiyor' ?></span>
          </div>
          <div class="stat-card">
            <h6>Kalan Etkinlik</h6>
            <strong><?=h($summary['has_unlimited'] ? 'Sınırsız' : (string)$summary['remaining_events'])?></strong>
            <span><?= $summary['has_unlimited'] ? 'Süre bitişi: '.($summary['unlimited_until'] ? date('d.m.Y', strtotime($summary['unlimited_until'])) : 'Süresiz') : 'Kullanılabilir haklarınız' ?></span>
          </div>
          <div class="stat-card">
            <h6>Cashback Bekleyen</h6>
            <strong><?=h($summary['cashback_waiting'])?></strong>
            <span><?= $summary['cashback_waiting'] ? h(format_currency($summary['cashback_pending_amount']).' bekliyor') : 'Şu anda bekleyen ödeme yok' ?></span>
          </div>
        </div>
      </div>
    </section>

    <section id="topup" class="card-lite p-4 p-lg-5 mb-4">
      <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
        <div>
          <h5 class="section-title mb-1">Bakiye Yükle</h5>
          <p class="muted mb-0">PayTR entegrasyonu ile bakiye yüklemeleri kısa süre içinde otomatikleşecektir. Şimdilik talepleriniz manuel olarak onaylanır.</p>
        </div>
        <form class="d-flex flex-column flex-sm-row gap-2" method="post">
          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="do" value="request_topup">
          <input type="text" class="form-control" name="amount" placeholder="Örn. 3.000" required>
          <button class="btn btn-brand" type="submit">Talep Oluştur</button>
        </form>
      </div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead><tr><th>Tarih</th><th>Tutar</th><th>Durum</th><th></th></tr></thead>
          <tbody>
            <?php if (!$topups): ?>
              <tr><td colspan="4" class="text-center text-muted">Henüz bakiye yükleme talebiniz bulunmuyor.</td></tr>
            <?php else: ?>
              <?php foreach ($topups as $topup): ?>
                <tr>
                  <td><?=h(date('d.m.Y H:i', strtotime($topup['created_at'] ?? 'now')))?></td>
                  <td><?=h(format_currency($topup['amount_cents']))?></td>
                  <td><?=h(dealer_topup_status_label($topup['status']))?></td>
                  <td class="text-end">
                    <?php if ($topup['status'] === DEALER_TOPUP_STATUS_PENDING): ?>
                      <form method="post" class="d-inline">
                        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                        <input type="hidden" name="do" value="cancel_topup">
                        <input type="hidden" name="topup_id" value="<?= (int)$topup['id'] ?>">
                        <button class="btn btn-sm btn-outline-brand" type="submit">İptal Et</button>
                      </form>
                    <?php else: ?>
                      <span class="text-muted small">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="card-lite p-4 p-lg-5 mb-4">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="section-title mb-0">Paket Seçenekleri</h5>
        <span class="muted">Bakiyeniz: <?=h(format_currency($balance))?></span>
      </div>
      <?php if (!$packages): ?>
        <p class="text-muted mb-0">Henüz tanımlanmış paket yok. Lütfen yönetici ile iletişime geçin.</p>
      <?php else: ?>
        <div class="package-grid">
          <?php foreach ($packages as $package): ?>
            <?php
              $canBuy = $balance >= $package['price_cents'];
              $quotaText = $package['event_quota'] === null ? 'Sınırsız etkinlik' : ($package['event_quota'].' etkinlik hakkı');
              $durationText = $package['duration_days'] ? ($package['duration_days'].' gün geçerli') : 'Süre sınırı yok';
              $cashbackText = $package['cashback_rate'] > 0 ? ('Tekli satışlarda %'.number_format($package['cashback_rate'] * 100, 0).' cashback') : null;
            ?>
            <div class="package-card">
              <div>
                <h6><?=h($package['name'])?></h6>
                <div class="price"><?=h(format_currency($package['price_cents']))?></div>
              </div>
              <?php if (!empty($package['description'])): ?>
                <p class="muted mb-0"><?=h($package['description'])?></p>
              <?php endif; ?>
              <ul class="mb-0">
                <li><?=h($quotaText)?></li>
                <li><?=h($durationText)?></li>
                <?php if ($cashbackText): ?><li><?=h($cashbackText)?></li><?php endif; ?>
              </ul>
              <div class="mt-auto">
                <?php if ($canBuy): ?>
                  <form method="post">
                    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="do" value="buy_package">
                    <input type="hidden" name="package_id" value="<?= (int)$package['id'] ?>">
                    <button class="btn btn-brand w-100" type="submit">Satın Al</button>
                  </form>
                <?php else: ?>
                  <button class="btn btn-outline-brand w-100" type="button" disabled>Bakiyeniz yetersiz</button>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="card-lite p-4 p-lg-5 mb-4">
      <h5 class="section-title mb-3">Paket Satın Alma Geçmişi</h5>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead><tr><th>Paket</th><th>Durum</th><th>Tutar</th><th>Satın Alma</th><th>Cashback</th></tr></thead>
          <tbody>
            <?php if (!$purchaseHistory): ?>
              <tr><td colspan="5" class="text-center text-muted">Henüz paket satın almadınız.</td></tr>
            <?php else: ?>
              <?php foreach (array_slice($purchaseHistory, 0, 12) as $purchase): ?>
                <?php
                  $statusLabel = dealer_purchase_status_label($purchase['status']);
                  $cashbackLabel = dealer_cashback_status_label($purchase['cashback_status']);
                  $cashbackAmount = $purchase['cashback_amount'] > 0 ? format_currency($purchase['cashback_amount']) : '—';
                ?>
                <tr>
                  <td>
                    <div class="fw-semibold"><?=h($purchase['package_name'])?></div>
                    <?php if (!empty($purchase['package_description'])): ?><div class="small text-muted"><?=h($purchase['package_description'])?></div><?php endif; ?>
                  </td>
                  <td><?=h($statusLabel)?></td>
                  <td><?=h(format_currency($purchase['price_cents']))?></td>
                  <td><?=h(date('d.m.Y H:i', strtotime($purchase['created_at'] ?? 'now')))?></td>
                  <td><?=h($cashbackLabel)?><?php if ($purchase['cashback_status'] === DEALER_CASHBACK_PENDING): ?> • <?=h($cashbackAmount)?><?php endif; ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section id="wallet" class="card-lite p-4 p-lg-5">
      <h5 class="section-title mb-3">Cari Hareketleri</h5>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead><tr><th>Tarih</th><th>İşlem</th><th>Tutar</th><th>Son Bakiye</th></tr></thead>
          <tbody>
            <?php if (!$walletTransactions): ?>
              <tr><td colspan="4" class="text-center text-muted">Henüz hareket kaydı bulunmuyor.</td></tr>
            <?php else: ?>
              <?php foreach ($walletTransactions as $movement): ?>
                <tr>
                  <td><?=h(date('d.m.Y H:i', strtotime($movement['created_at'] ?? 'now')))?></td>
                  <td>
                    <div class="fw-semibold"><?=h(dealer_wallet_type_label($movement['type']))?></div>
                    <?php if (!empty($movement['description'])): ?><div class="small text-muted"><?=h($movement['description'])?></div><?php endif; ?>
                  </td>
                  <td><?=h(format_currency($movement['amount_cents']))?></td>
                  <td><?=h(format_currency($movement['balance_after']))?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

  </div>
</main>
</body>
</html>
