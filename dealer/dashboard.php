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

$action = $_POST['do'] ?? '';
if ($action) {
  csrf_or_die();
  if ($action === 'assign_static') {
    $eventId = (int)($_POST['event_id'] ?? 0);
    if ($eventId > 0 && !dealer_event_belongs_to_dealer((int)$dealer['id'], $eventId)) {
      flash('err', 'Bu etkinliği yönetme yetkiniz yok.');
    } else {
      dealer_set_code_target((int)$dealer['id'], DEALER_CODE_STATIC, $eventId > 0 ? $eventId : null);
      flash('ok', 'Kalıcı QR yönlendirmesi güncellendi.');
    }
    redirect($_SERVER['PHP_SELF'].'#qr');
  }
}

$codes   = dealer_sync_codes((int)$dealer['id']);
$staticCode = $codes[DEALER_CODE_STATIC] ?? null;
$venues  = dealer_fetch_venues((int)$dealer['id']);
$events  = dealer_allowed_events((int)$dealer['id']);
$warning = dealer_license_warning($dealer);
$canCreate = dealer_can_manage_events($dealer);
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h(APP_NAME)?> — Bayi Paneli</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:linear-gradient(180deg,#f4f9fb,#fff) no-repeat;font-family:'Inter',sans-serif;}
  .topbar{background:#fff;border-bottom:1px solid #e5e7eb;}
  .card-lite{border:1px solid #e5e7eb;border-radius:16px;background:#fff;box-shadow:0 10px 30px rgba(15,23,42,.08);}
  .code-pill{font-family:'Fira Code',monospace;font-size:1.1rem;padding:.35rem .8rem;border-radius:10px;background:#f1f5f9;display:inline-block;}
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
  <div class="row g-4">
    <div class="col-lg-4">
      <div class="card-lite p-4 h-100">
        <h5 class="mb-3">Lisans Durumu</h5>
        <p class="mb-1"><strong>Bitiş:</strong> <?=h(dealer_license_label($dealer))?></p>
        <p class="text-muted small">Durum: <?= dealer_has_valid_license($dealer) ? 'Geçerli' : 'Geçersiz' ?></p>
        <?php if ($warning): ?>
          <div class="alert alert-warning small mb-0"><?=h($warning)?></div>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-lg-8">
      <div class="card-lite p-4" id="qr">
        <h5 class="mb-3">Kalıcı QR Kodunuz</h5>
        <?php if (!$staticCode): ?>
          <p class="text-muted mb-0">Kalıcı kod oluşturulamadı. Lütfen yönetici ile iletişime geçin.</p>
        <?php else: ?>
          <?php $staticUrl = BASE_URL.'/qr.php?code='.urlencode($staticCode['code']); ?>
          <div class="row g-4 align-items-start">
            <div class="col-md-6">
              <div class="border rounded-3 p-3 h-100">
                <div class="text-uppercase small text-muted fw-semibold mb-1">Kod</div>
                <div class="code-pill mb-3"><?=h($staticCode['code'])?></div>
                <p class="small text-muted mb-1">Bu kod sabittir ve tek bir kez atanmıştır.</p>
                <p class="small text-muted mb-0"><a href="<?=h($staticUrl)?>" target="_blank">Kalıcı QR bağlantısını aç</a></p>
              </div>
            </div>
            <div class="col-md-6">
              <div class="border rounded-3 p-3 h-100">
                <h6 class="fw-semibold mb-2">Yönlendirme</h6>
                <p class="small text-muted">Kod okutulduğunda misafirler seçtiğiniz etkinliğe yönlendirilir.</p>
                <form method="post" class="vstack gap-2">
                  <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="do" value="assign_static">
                  <label class="form-label small mb-1">Bağlı etkinlik</label>
                  <select class="form-select form-select-sm" name="event_id">
                    <option value="0">— Seçili değil —</option>
                    <?php foreach ($events as $ev): ?>
                      <?php
                        $selected = ($staticCode['target_event_id'] ?? null) == $ev['id'] ? 'selected' : '';
                        $dateLabel = $ev['event_date'] ? date('d.m.Y', strtotime($ev['event_date'])) : 'Tarihsiz';
                      ?>
                      <option value="<?= (int)$ev['id'] ?>" <?=$selected?>><?=h($dateLabel.' • '.$ev['title'])?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-sm btn-primary" type="submit">Yönlendirmeyi Kaydet</button>
                </form>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card-lite p-4 mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="mb-0">Salonlarınız</h5>
      <a class="btn btn-sm btn-outline-primary" href="mailto:<?=h(MAIL_FROM ?? 'info@localhost')?>?subject=Bayi%20Salon%20Talebi">Yeni salon talep et</a>
    </div>
    <?php if (!$venues): ?>
      <p class="text-muted">Henüz size atanmış salon bulunmuyor. Lütfen yönetici ile iletişime geçin.</p>
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
                <td><?= $v['is_active'] ? 'Aktif' : 'Pasif' ?></td>
                <td class="text-end">
                  <a class="btn btn-sm btn-primary" href="venue_events.php?venue_id=<?= (int)$v['id'] ?>">Etkinlikleri Yönet</a>
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
