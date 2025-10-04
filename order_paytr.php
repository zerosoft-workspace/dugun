<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/functions.php';
require_once __DIR__.'/includes/site.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

install_schema();

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : (int)($_SESSION['current_order_id'] ?? 0);
if ($orderId <= 0) {
  flash('err', 'Ödeme oturumu bulunamadı.');
  redirect('index.php');
}

try {
  $order = site_get_order($orderId);
  if (!$order) {
    throw new RuntimeException('Sipariş kaydı bulunamadı.');
  }
  if ($order['status'] === SITE_ORDER_STATUS_COMPLETED && $order['event_id']) {
    $result = site_finalize_order($order['id']);
    $_SESSION['lead_success'] = 'Ödemeniz alınmış, etkinliğiniz oluşturulmuş durumda.';
    $_SESSION['order_summary'] = [
      'event_title'    => $result['event']['title'],
      'upload_url'     => $result['event']['upload_url'],
      'qr_image'       => $result['event']['qr_image_url'],
      'login_url'      => $result['event']['login_url'],
      'plain_password' => $result['event']['plain_password'],
      'customer_email' => $result['customer']['email'],
    ];
    redirect('order_thanks.php');
  }
  $paytr = site_ensure_order_paytr_token($orderId);
  $order = $paytr['order'];
  $package = $paytr['package'];
  $token = $paytr['token'];
  if (!empty($paytr['test_mode']) && !empty($paytr['result'])) {
    $result = $paytr['result'];
    $_SESSION['lead_success'] = 'Test modunda ödeme başarıyla işlendi.';
    $_SESSION['order_summary'] = [
      'event_title'    => $result['event']['title'],
      'upload_url'     => $result['event']['upload_url'],
      'qr_image'       => $result['event']['qr_image_url'],
      'login_url'      => $result['event']['login_url'],
      'plain_password' => $result['event']['plain_password'],
      'customer_email' => $result['customer']['email'],
    ];
    redirect('order_thanks.php');
  }
} catch (Throwable $e) {
  flash('err', $e->getMessage());
  redirect('index.php#lead-form');
}

$_SESSION['current_order_id'] = $order['id'];
$_SESSION['current_order_oid'] = $paytr['merchant_oid'];

?>
<!doctype html><html lang="tr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ödeme Adımı — <?=h(APP_NAME)?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:linear-gradient(180deg,#f3f7fb,#fff);font-family:'Inter',sans-serif;color:#0f172a;}
  .checkout-card{border-radius:32px;background:#fff;box-shadow:0 24px 70px rgba(14,165,181,0.18);padding:48px;}
  .summary-card{border-radius:24px;background:rgba(14,165,181,0.08);padding:24px;}
  .step{display:flex;align-items:center;gap:12px;margin-bottom:12px;}
  .step span{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;background:#0ea5b5;color:#fff;}
  .badge-soft{background:rgba(14,165,181,0.12);color:#0f766e;border-radius:999px;padding:6px 16px;font-weight:600;}
  .price{font-size:1.8rem;font-weight:800;color:#0ea5b5;}
  .iframe-wrapper{border-radius:24px;border:1px solid rgba(15,118,110,0.16);overflow:hidden;background:#f8fbfc;}
  @media(max-width:768px){.checkout-card{padding:32px;}}
</style>
</head><body>
<div class="container py-5">
  <div class="checkout-card mx-auto" style="max-width:960px;">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
      <div>
        <div class="badge-soft mb-3">Ödeme Adımı</div>
        <h1 class="fw-bold mb-2">Siparişinizi Güvenle Tamamlayın</h1>
        <p class="text-muted mb-0">Ödemenizi tamamladıktan sonra etkinlik paneliniz otomatik olarak oluşturulacak ve giriş bilgileri e-posta adresinize gönderilecek.</p>
      </div>
      <div class="text-lg-end">
        <div class="price mb-1"><?=h(format_currency((int)$order['price_cents']))?></div>
        <div class="text-muted small">Paket: <?=h($package['name'])?></div>
      </div>
    </div>

    <?php flash_box(); ?>

    <div class="row g-4 align-items-start">
      <div class="col-lg-7">
        <div class="iframe-wrapper p-3">
          <?php if ($token): ?>
            <script src="https://www.paytr.com/js/iframeResizer.min.js"></script>
            <iframe src="https://www.paytr.com/odeme/guvenli/<?=h($token)?>" id="paytriframe" frameborder="0" scrolling="no" style="width:100%;min-height:620px;"></iframe>
            <script>iFrameResize({}, '#paytriframe');</script>
          <?php else: ?>
            <div class="alert alert-danger mb-0">Ödeme başlatılırken bir sorun oluştu. Lütfen tekrar deneyin.</div>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="summary-card mb-4">
          <h5 class="fw-semibold mb-3">Sipariş Özeti</h5>
          <ul class="list-unstyled small text-muted mb-0">
            <li class="mb-2"><strong>Paket:</strong> <?=h($package['name'])?></li>
            <li class="mb-2"><strong>Ad Soyad:</strong> <?=h($order['customer_name'])?></li>
            <li class="mb-2"><strong>E-posta:</strong> <?=h($order['customer_email'])?></li>
            <?php if (!empty($order['customer_phone'])): ?>
              <li class="mb-2"><strong>Telefon:</strong> <?=h($order['customer_phone'])?></li>
            <?php endif; ?>
            <li class="mb-2"><strong>Etkinlik:</strong> <?=h($order['event_title'])?></li>
            <?php if (!empty($order['event_date'])): ?>
              <li><strong>Tarih:</strong> <?=h(date('d.m.Y', strtotime($order['event_date'])))?></li>
            <?php endif; ?>
          </ul>
        </div>
        <div>
          <h6 class="fw-semibold mb-3">Sonraki Adımlar</h6>
          <div class="step"><span>1</span><div><strong>Ödeme</strong><br><small class="text-muted">Kart bilgileriniz PayTR güvenli altyapısı ile alınır.</small></div></div>
          <div class="step"><span>2</span><div><strong>Otomatik Kurulum</strong><br><small class="text-muted">Ödeme onaylanır onaylanmaz etkinlik paneliniz oluşturulur.</small></div></div>
          <div class="step"><span>3</span><div><strong>E-posta Bilgilendirmesi</strong><br><small class="text-muted">QR kodunuz ve giriş bilgileriniz e-postanıza ve varsa bayinize gönderilir.</small></div></div>
        </div>
      </div>
    </div>
  </div>
</div>
</body></html>
