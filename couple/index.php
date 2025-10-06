<?php
require_once __DIR__.'/_auth.php';                  // tek URL login + aktif düğün
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/license.php';    // lisans yardımcıları
require_once __DIR__.'/../includes/theme.php';

// Aktif etkinlik bilgisi
$EVENT_ID = couple_current_event_id();
$ev = couple_event_row_current();
if (!$ev) { http_response_code(404); exit('Etkinlik bulunamadı'); }

// Lisans kur / kontrol et
license_ensure_active($EVENT_ID);
$license_active = license_is_active($EVENT_ID);
$license_badge  = license_badge_text($EVENT_ID);

// Etkinliğin salonu
$VID = (int)$ev['venue_id'];

// Kampanyalar (ek paketler)
$cs = pdo()->prepare("SELECT * FROM campaigns WHERE venue_id=? ORDER BY is_active DESC, id DESC");
$cs->execute([$VID]);
$campaignRows = $cs->fetchAll();
$campaigns = [];
$campaignsInactive = [];
foreach ($campaignRows as $row) {
  if (!empty($row['is_active'])) {
    $campaigns[] = $row;
  } else {
    $campaignsInactive[] = $row;
  }
}
$hasInactiveCampaigns = !empty($campaignsInactive);

// ---- Ayarlar kaydet (misafir sayfası, fatura bilgileri vs.) ----
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='save_settings') {
  csrf_or_die();

  $title     = trim($_POST['guest_title'] ?? '');
  $subtitle  = trim($_POST['guest_subtitle'] ?? '');
  $prompt    = trim($_POST['guest_prompt'] ?? '');
  $primary   = trim($_POST['theme_primary'] ?? '#0ea5b5');
  $accent    = trim($_POST['theme_accent'] ?? '#e0f7fb');
  $view      = isset($_POST['allow_guest_view']) ? 1 : 0;
  $download  = isset($_POST['allow_guest_download']) ? 1 : 0;
  $delete    = isset($_POST['allow_guest_delete']) ? 1 : 0;
  $layout    = $_POST['layout_json'] ?? null;
  $stickers  = $_POST['stickers_json'] ?? null;

  $contact   = trim($_POST['contact_email'] ?? '');
  $phone     = trim($_POST['couple_phone'] ?? '');
  $tckn      = trim($_POST['couple_tckn'] ?? '');
  $inv_title = trim($_POST['invoice_title'] ?? '');
  $inv_vkn   = trim($_POST['invoice_vkn'] ?? '');
  $inv_addr  = trim($_POST['invoice_address'] ?? '');

  pdo()->prepare("UPDATE events SET
    guest_title=?, guest_subtitle=?, guest_prompt=?,
    theme_primary=?, theme_accent=?,
    allow_guest_view=?, allow_guest_download=?, allow_guest_delete=?,
    layout_json=?, stickers_json=?,
    contact_email=?, couple_phone=?, couple_tckn=?, invoice_title=?, invoice_vkn=?, invoice_address=?,
    updated_at=?
  WHERE id=?")->execute([
    $title?:null,$subtitle?:null,$prompt?:null,
    $primary,$accent,$view,$download,$delete,
    $layout?:null,$stickers?:null,
    $contact?:null,$phone?:null,$tckn?:null,$inv_title?:null,$inv_vkn?:null,$inv_addr?:null,
    now(), $EVENT_ID
  ]);

  flash('ok','Ayarlar kaydedildi.');
  redirect($_SERVER['REQUEST_URI']);
}

// ---- (Bilgi amaçlı) Upload özeti ----
$sum = pdo()->prepare("SELECT COUNT(*) c, COALESCE(SUM(file_size),0) b FROM uploads WHERE venue_id=? AND event_id=?");
$sum->execute([$VID,$EVENT_ID]);
$tot = $sum->fetch();
function fmt_bytes($b){ if($b<=0) return '0 MB'; $m=$b/1048576; return $m<1024?number_format($m,1).' MB':number_format($m/1024,2).' GB'; }

// Tarih ve özetler
$eventDateRaw = $ev['event_date'] ?? null;
$eventDateFormatted = null;
$eventCountdownText = null;
if ($eventDateRaw) {
  try {
    $eventDateObj = new DateTime($eventDateRaw);
    $eventDateFormatted = $eventDateObj->format('d.m.Y');
    $now = new DateTime('today');
    $diffDays = (int)$now->diff($eventDateObj)->format('%r%a');
    if ($diffDays > 0) {
      $eventCountdownText = $diffDays . ' gün kaldı';
    } elseif ($diffDays === 0) {
      $eventCountdownText = 'Etkinlik bugün';
    } else {
      $eventCountdownText = 'Etkinlik tamamlandı';
    }
  } catch (Throwable $e) {
    $eventDateFormatted = $eventDateRaw;
  }
}

$uploadsCount = (int)($tot['c'] ?? 0);
$uploadsBytes = (int)($tot['b'] ?? 0);
$uploadsSizeReadable = fmt_bytes($uploadsBytes);
$licenseDaysRemaining = license_remaining_days($EVENT_ID);
$licenseStatusText = $license_active ? 'Lisans aktif' : 'Lisans süresi doldu';
$licenseStatusTone = $license_active ? 'success' : 'danger';
$licenseStatusDetail = $license_active ? $license_badge : 'Yenileme gerekli';
$coupleEmail = $COUPLE['email'] ?? null;
$licenseStatValue = $license_active ? ($licenseDaysRemaining > 1 ? $licenseDaysRemaining . ' gün' : ($licenseDaysRemaining === 1 ? '1 gün' : ($licenseDaysRemaining === 0 ? 'Son gün' : 'Aktif'))) : 'Pasif';
$licenseStatSub = $licenseStatusDetail;
$venueName = null;
try {
  $venueStmt = pdo()->prepare("SELECT name FROM venues WHERE id=? LIMIT 1");
  $venueStmt->execute([$VID]);
  $venueName = $venueStmt->fetchColumn() ?: null;
} catch (Throwable $e) {
  $venueName = null;
}

// ---- Lisans planları ve fiyatlar ----
$LICENSE_PLANS = [
  1 => 1000,  2 => 1800,  3 => 2500,  4 => 3000,  5 => 3500,
];
$LICENSE_YEARS = [1,2,3,4,5];

// Önizleme metin/tema (birebir yansısın)
$TITLE    = $ev['guest_title'] ?: 'Düğünümüze Hoş Geldiniz';
$SUBTITLE = $ev['guest_subtitle'] ?: 'En güzel anlarınızı bizimle paylaşın';
$PROMPT   = $ev['guest_prompt'] ?: 'Adınızı yazıp anınızı yükleyin.';
$PRIMARY  = $ev['theme_primary'] ?: '#0ea5b5';
$ACCENT   = $ev['theme_accent']  ?: '#e0f7fb';

// Layout & stickers (null-safe, eski PHP uyumlu)
$layoutJson   = $ev['layout_json'] ?: '{"title":{"x":24,"y":24},"subtitle":{"x":24,"y":60},"prompt":{"x":24,"y":396}}';
$stickersJson = $ev['stickers_json'] ?: '[]';
$layoutArr    = json_decode($layoutJson, true);
$stickersArr  = json_decode($stickersJson, true);
if(!is_array($layoutArr)){
  $layoutArr = array('title'=>array('x'=>24,'y'=>24),'subtitle'=>array('x'=>24,'y'=>60),'prompt'=>array('x'=>24,'y'=>396));
}
if(!is_array($stickersArr)){ $stickersArr=array(); }
$tPos = isset($layoutArr['title'])    ? $layoutArr['title']    : array('x'=>24,'y'=>24);
$sPos = isset($layoutArr['subtitle']) ? $layoutArr['subtitle'] : array('x'=>24,'y'=>60);
$pPos = isset($layoutArr['prompt'])   ? $layoutArr['prompt']   : array('x'=>24,'y'=>396);
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($ev['title'])?> — Çift Paneli</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?=theme_head_assets()?>
<style>
:root{
  --ink:#0f172a;
  --muted:#64748b;
  --brand:<?=h($PRIMARY)?>;
  --brand-soft:<?=h($ACCENT)?>;
  --zs:<?=h($PRIMARY)?>;
  --zs-soft:<?=h($ACCENT)?>;
  --surface:#ffffff;
  --border-soft:rgba(148,163,184,.28);
}
body{
  font-family:'Inter','Segoe UI','Helvetica Neue',sans-serif;
  background:linear-gradient(180deg,#f8fafc 0%,#fff 120%);
  color:var(--ink);
}
.navbar.portal-navbar{
  background:rgba(255,255,255,.85);
  backdrop-filter:blur(16px);
  border-bottom:1px solid rgba(148,163,184,.2);
}
.portal-navbar .navbar-brand{ font-weight:700; letter-spacing:-.01em; color:var(--ink); }
.portal-navbar .btn{ border-radius:999px; font-weight:600; padding:.35rem 1rem; }
.portal-navbar .btn-active{ background:linear-gradient(135deg,var(--zs),#0b8b98); color:#fff; border:none; box-shadow:0 12px 30px -16px rgba(14,165,181,.35); }
.portal-navbar .btn-active:hover{ color:#fff; filter:brightness(.97); }

.portal-hero{ padding:42px 0 28px; }
.portal-hero-card{
  position:relative;
  border-radius:28px;
  background:linear-gradient(140deg,var(--brand-soft),rgba(255,255,255,.95));
  padding:32px;
  overflow:hidden;
  box-shadow:0 45px 80px -60px rgba(14,165,181,.55);
}
.portal-hero-card::after{
  content:'';
  position:absolute;
  width:220px; height:220px;
  right:-60px; top:-60px;
  background:radial-gradient(circle at center, rgba(14,165,181,.35), rgba(14,165,181,0));
}
.portal-hero-card::before{
  content:'';
  position:absolute;
  width:160px; height:160px;
  left:-70px; bottom:-70px;
  background:radial-gradient(circle at center, rgba(14,165,181,.18), transparent 70%);
}
.hero-overline{ text-transform:uppercase; letter-spacing:.12em; font-size:.75rem; color:rgba(15,23,42,.68); font-weight:600; }
.hero-title{ font-size:2rem; font-weight:700; color:var(--ink); margin-bottom:.25rem; }
.hero-sub{ color:rgba(15,23,42,.72); font-size:1rem; }
.hero-chip{ display:inline-flex; align-items:center; gap:.35rem; font-size:.85rem; background:rgba(255,255,255,.78); padding:.35rem .8rem; border-radius:999px; color:var(--ink); font-weight:600; }
.hero-meta{ display:flex; flex-wrap:wrap; gap:1.2rem; margin-top:1.5rem; color:rgba(15,23,42,.75); }
.hero-meta span{ display:flex; align-items:center; gap:.45rem; font-weight:600; }
.hero-license{ margin-top:1.8rem; display:flex; flex-direction:column; gap:1rem; }
.hero-license .hero-badge{ display:inline-flex; align-items:center; gap:.5rem; border-radius:999px; padding:.4rem 1.2rem; font-weight:600; background:rgba(255,255,255,.85); color:var(--ink); }
.hero-license .hero-badge.success{ border:1px solid rgba(34,197,94,.35); color:#047857; background:rgba(220,252,231,.85); }
.hero-license .hero-badge.danger{ border:1px solid rgba(248,113,113,.3); color:#b91c1c; background:rgba(254,226,226,.92); }
.hero-license form{ display:flex; flex-wrap:wrap; gap:.75rem; align-items:center; }
.hero-license .form-select, .hero-license button{ border-radius:999px; height:44px; padding:0 1.2rem; font-weight:600; }
.hero-license .form-select{ min-width:200px; border-color:rgba(148,163,184,.5); }
.hero-license .btn{ background:var(--brand); border:none; color:#fff; }

.portal-main{ padding-bottom:60px; }
.section-heading{ font-weight:700; font-size:1.05rem; color:var(--ink); }
.section-sub{ color:var(--muted); font-size:.9rem; }

.card-lite{ border-radius:22px; border:1px solid var(--border-soft); background:var(--surface); box-shadow:0 28px 60px -52px rgba(15,23,42,.45); }
.card-lite.filled{ background:rgba(255,255,255,.92); }
.card-lite .card-title{ font-size:1.05rem; font-weight:600; color:var(--ink); }
.card-lite .card-subtitle{ color:var(--muted); font-size:.9rem; }

.stat-grid{ display:grid; gap:16px; margin-top:28px; grid-template-columns:repeat(1,minmax(0,1fr)); }
@media (min-width: 768px){ .stat-grid{ grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media (min-width: 1400px){ .stat-grid{ grid-template-columns:repeat(4,minmax(0,1fr)); } }
.stat-card{ border-radius:20px; border:1px solid rgba(148,163,184,.25); background:rgba(255,255,255,.86); padding:18px 20px; display:flex; flex-direction:column; gap:8px; min-height:140px; position:relative; overflow:hidden; }
.stat-card .stat-label{ font-size:.78rem; text-transform:uppercase; letter-spacing:.12em; color:rgba(71,85,105,.8); font-weight:600; }
.stat-card .stat-value{ font-size:1.65rem; font-weight:700; color:var(--ink); }
.stat-card .stat-sub{ font-size:.9rem; color:var(--muted); }
.stat-card.success{ border-color:rgba(34,197,94,.25); }
.stat-card.success .stat-value{ color:#047857; }
.stat-card.danger{ border-color:rgba(248,113,113,.3); }
.stat-card.danger .stat-value{ color:#b91c1c; }
.stat-card .stat-icon{ position:absolute; right:18px; top:18px; font-size:1.4rem; color:rgba(100,116,139,.35); }

.btn-zs{ background:var(--brand); border:none; color:#fff; border-radius:14px; padding:.75rem 1.1rem; font-weight:600; transition:transform .2s, box-shadow .2s; }
.btn-zs:hover{ color:#fff; transform:translateY(-1px); box-shadow:0 16px 34px -24px rgba(14,165,181,.8); }
.btn-zs-outline{ background:#fff; border:1px solid rgba(14,165,181,.4); color:var(--brand); border-radius:14px; padding:.6rem 1rem; font-weight:600; }
.btn-zs-outline:hover{ color:var(--brand); background:rgba(14,165,181,.08); }

.portal-form .form-label{ font-weight:600; color:var(--ink); }
.portal-form .form-control,
.portal-form .form-select,
.portal-form .form-control-color{ border-radius:14px; border-color:rgba(148,163,184,.4); background:rgba(248,250,252,.65); padding:.65rem .85rem; }
.portal-form textarea.form-control{ min-height:100px; }
.portal-form .form-check{ display:flex; align-items:center; gap:.55rem; padding:.35rem 0; }
.portal-form .form-check-input{ width:18px; height:18px; border-radius:6px; }

.addon-form{ margin-top:1.5rem; }
.addon-grid{ display:grid; gap:18px; }
@media (min-width: 576px){ .addon-grid{ grid-template-columns:repeat(auto-fit, minmax(240px,1fr)); } }
.addon-card{ position:relative; display:block; cursor:pointer; }
.addon-card input{ position:absolute; inset:0; opacity:0; pointer-events:none; }
.addon-card .addon-body{ border-radius:20px; border:1.5px solid rgba(148,163,184,.35); padding:20px 22px; background:rgba(255,255,255,.9); display:flex; flex-direction:column; gap:14px; min-height:180px; transition:transform .22s ease, box-shadow .22s ease, border-color .22s ease, background .22s ease; }
.addon-card:hover .addon-body{ transform:translateY(-2px); box-shadow:0 22px 48px -32px rgba(14,165,181,.6); border-color:rgba(14,165,181,.6); }
.addon-card input:checked + .addon-body{ border-color:var(--brand); box-shadow:0 28px 58px -36px rgba(14,165,181,.65); background:linear-gradient(140deg, rgba(14,165,181,.12), rgba(14,165,181,.02)); }
.addon-header{ display:flex; justify-content:space-between; align-items:center; }
.addon-type{ font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; background:rgba(14,165,181,.14); color:var(--brand); padding:.3rem .65rem; border-radius:999px; }
.addon-price{ font-weight:700; font-size:1.25rem; color:var(--ink); }
.addon-title{ font-size:1.05rem; font-weight:600; color:var(--ink); }
.addon-desc{ color:var(--muted); font-size:.9rem; }
.addon-footer{ display:flex; align-items:center; justify-content:space-between; font-size:.85rem; color:rgba(15,23,42,.65); }
.addon-check{ display:flex; align-items:center; gap:.35rem; font-weight:600; opacity:0; transition:opacity .2s ease; color:var(--brand); }
.addon-card input:checked + .addon-body .addon-check{ opacity:1; }
.addon-empty{ padding:28px; border-radius:18px; border:1px dashed rgba(148,163,184,.4); background:rgba(255,255,255,.7); text-align:center; color:var(--muted); font-weight:600; }

.gallery-card{ display:flex; flex-direction:column; gap:18px; }
.gallery-metrics{ display:flex; flex-wrap:wrap; gap:1rem; }
.gallery-metrics .badge{ border-radius:999px; padding:.55rem 1.1rem; font-weight:600; font-size:.85rem; }
.gallery-metrics .badge-count{ background:rgba(37,99,235,.12); color:#1d4ed8; }
.gallery-metrics .badge-size{ background:rgba(79,70,229,.12); color:#4338ca; }

.preview-shell{ width:min(100%,980px); margin:0 auto; }
.preview-stage{ position:relative; width:100%; border:1px dashed rgba(148,163,184,.35); border-radius:20px; background:#fff; overflow:hidden; }
.stage-scale{ position:absolute; left:0; top:0; width:960px; height:540px; transform-origin:top left; transform:scale(var(--s,1)); }
.preview-canvas{ position:absolute; inset:0; background:linear-gradient(180deg,var(--zs-soft),#fff); }
#pv-title{ position:absolute; font-size:28px; font-weight:800; color:#111; }
#pv-sub{ position:absolute; color:#334155; font-size:16px; }
#pv-prompt{ position:absolute; color:#0f172a; font-size:16px; }
.sticker{ position:absolute; user-select:none; cursor:move; }

.sticker-actions{ display:flex; flex-wrap:wrap; gap:.75rem; margin-top:1.1rem; }
.sticker-actions .btn{ border-radius:12px; padding:.45rem .85rem; font-weight:600; }

.badge-soft{ background:#eef2ff; color:#334155; border-radius:999px; padding:.45rem 1rem; font-weight:600; }

@media (max-width: 991px){
  .portal-hero-card{ padding:26px; }
}
@media (max-width: 575px){
  .hero-title{ font-size:1.6rem; }
  .hero-license form{ width:100%; }
  .hero-license .form-select{ flex:1; min-width:0; }
}
</style>
</head>

<body>
<nav class="navbar portal-navbar">
  <div class="container py-3">
    <a class="navbar-brand fw-semibold" href="<?=h(BASE_URL)?>"><?=h(APP_NAME)?></a>
    <div class="d-flex align-items-center gap-2 gap-md-3">
      <a class="btn btn-sm btn-outline-secondary" href="engage.php"><i class="bi bi-stars me-1"></i>Etkileşim Araçları</a>
      <a class="btn btn-sm btn-outline-secondary" href="list.php"><i class="bi bi-images me-1"></i>Yüklemeler</a>
      <a class="btn btn-sm btn-outline-secondary" href="logout.php"><i class="bi bi-box-arrow-right me-1"></i>Çıkış</a>
    </div>
  </div>
</nav>

<section class="portal-hero">
  <div class="container">
    <div class="portal-hero-card">
      <div class="row g-4 align-items-start">
        <div class="col-lg-7">
          <span class="hero-overline">Çift Paneli</span>
          <h1 class="hero-title"><?=h($ev['title'])?></h1>
          <p class="hero-sub mb-3">Misafir deneyimini kişiselleştirin, kampanyaları keşfedin ve yüklemelerinizi yönetin.</p>
          <?php if($coupleEmail): ?>
            <span class="hero-chip mb-3"><i class="bi bi-envelope-open me-1"></i><?=h($coupleEmail)?></span>
          <?php endif; ?>
          <div class="hero-meta">
            <span><i class="bi bi-calendar3"></i><?= $eventDateFormatted ? h($eventDateFormatted) : 'Tarih planlanmadı' ?></span>
            <?php if($eventCountdownText): ?>
              <span><i class="bi bi-hourglass-split"></i><?=h($eventCountdownText)?></span>
            <?php endif; ?>
            <?php if($venueName): ?>
              <span><i class="bi bi-geo-alt"></i><?=h($venueName)?></span>
            <?php endif; ?>
          </div>
          <div class="hero-license">
            <span class="hero-badge <?=$licenseStatusTone?>">
              <i class="bi bi-shield-check"></i>
              <?=$licenseStatusText?>
              <span class="ms-2 small fw-semibold"><?=$licenseStatusDetail?></span>
            </span>
            <?php if(!$license_active): ?>
              <p class="text-danger small fw-semibold mb-0">Lisans süresi doldu. Yenilemek için süre seçip ödeme tamamlayın.</p>
            <?php else: ?>
              <p class="text-muted small mb-0">Etkinliğiniz için lisansı dilediğiniz süre kadar uzatabilirsiniz.</p>
            <?php endif; ?>
            <form method="post" action="pay_license.php">
              <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="event_id" value="<?=$EVENT_ID?>">
              <label class="small text-muted mb-0">Süre:</label>
              <select name="years" class="form-select">
                <?php foreach($LICENSE_YEARS as $y): ?>
                  <option value="<?=$y?>"><?=$y?> yıl — <?= (int)$LICENSE_PLANS[$y] ?> TL</option>
                <?php endforeach; ?>
              </select>
              <button class="btn" type="submit"><i class="bi bi-arrow-repeat me-1"></i>Lisansı <?= $license_active ? 'Uzat' : 'Yenile' ?></button>
            </form>
          </div>
        </div>
        <div class="col-lg-5">
          <div class="stat-grid">
            <div class="stat-card">
              <i class="bi bi-calendar3 stat-icon"></i>
              <span class="stat-label">Etkinlik Tarihi</span>
              <span class="stat-value"><?= $eventDateFormatted ? h($eventDateFormatted) : 'Belirlenmedi' ?></span>
              <span class="stat-sub"><?= $eventCountdownText ? h($eventCountdownText) : 'Tarih bilgisini ayarlardan güncelleyebilirsiniz.' ?></span>
            </div>
            <div class="stat-card <?=$licenseStatusTone?>">
              <i class="bi bi-shield-lock stat-icon"></i>
              <span class="stat-label">Lisans Durumu</span>
              <span class="stat-value"><?=h($licenseStatValue)?></span>
              <span class="stat-sub"><?=h($licenseStatSub)?></span>
            </div>
            <div class="stat-card">
              <i class="bi bi-collection stat-icon"></i>
              <span class="stat-label">Yükleme Adedi</span>
              <span class="stat-value"><?=number_format($uploadsCount,0,',','.')?></span>
              <span class="stat-sub">Fotoğraf + video toplamı</span>
            </div>
            <div class="stat-card">
              <i class="bi bi-hdd-stack stat-icon"></i>
              <span class="stat-label">Depolama</span>
              <span class="stat-value"><?=h($uploadsSizeReadable)?></span>
              <span class="stat-sub">Şu ana kadar kullanılan alan</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<main class="portal-main">
  <div class="container">
    <?php flash_box(); ?>
    <div class="row g-4">
      <div class="col-xl-7">
        <div class="vstack gap-4">
          <div class="card-lite filled p-4 portal-form">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
              <div>
                <h2 class="card-title mb-1">Misafir Sayfası Ayarları</h2>
                <p class="card-subtitle mb-0">Karşılama metinlerini, renkleri ve iletişim bilgilerini düzenleyin.</p>
              </div>
              <a class="btn btn-zs-outline align-self-start" href="list.php"><i class="bi bi-images me-1"></i>Yüklemeleri Aç</a>
            </div>
            <form method="post" class="row g-3">
              <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="do" value="save_settings">
              <div class="col-12">
                <label class="form-label">Başlık</label>
                <input class="form-control" name="guest_title" value="<?=h($ev['guest_title'] ?? '')?>">
              </div>
              <div class="col-12">
                <label class="form-label">Alt Başlık</label>
                <input class="form-control" name="guest_subtitle" value="<?=h($ev['guest_subtitle'] ?? '')?>">
              </div>
              <div class="col-12">
                <label class="form-label">Yükleme Mesajı</label>
                <textarea class="form-control" name="guest_prompt"><?=h($ev['guest_prompt'] ?? '')?></textarea>
              </div>
              <div class="col-md-6">
                <label class="form-label">Ana Renk</label>
                <input type="color" class="form-control form-control-color" name="theme_primary" value="<?=h($PRIMARY)?>" oninput="applyTheme()">
              </div>
              <div class="col-md-6">
                <label class="form-label">Aksan Renk</label>
                <input type="color" class="form-control form-control-color" name="theme_accent" value="<?=h($ACCENT)?>" oninput="applyTheme()">
              </div>
              <div class="col-md-12">
                <label class="form-label">İletişim E-postası</label>
                <input type="email" class="form-control" name="contact_email" value="<?=h($ev['contact_email'] ?? '')?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">Telefon</label>
                <input class="form-control" name="couple_phone" value="<?=h($ev['couple_phone'] ?? '')?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">T.C. Kimlik</label>
                <input class="form-control" name="couple_tckn" value="<?=h($ev['couple_tckn'] ?? '')?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">Fatura Ünvanı</label>
                <input class="form-control" name="invoice_title" value="<?=h($ev['invoice_title'] ?? '')?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">VKN/TCKN</label>
                <input class="form-control" name="invoice_vkn" value="<?=h($ev['invoice_vkn'] ?? '')?>">
              </div>
              <div class="col-12">
                <label class="form-label">Fatura Adresi</label>
                <input class="form-control" name="invoice_address" value="<?=h($ev['invoice_address'] ?? '')?>">
              </div>
              <div class="col-12">
                <div class="d-flex flex-wrap gap-3 mt-1">
                  <label class="form-check">
                    <input type="checkbox" class="form-check-input" name="allow_guest_view" <?= $ev['allow_guest_view']?'checked':'' ?>> Misafir galeriyi görebilsin
                  </label>
                  <label class="form-check">
                    <input type="checkbox" class="form-check-input" name="allow_guest_download" <?= $ev['allow_guest_download']?'checked':'' ?>> İndirme açık
                  </label>
                  <label class="form-check">
                    <input type="checkbox" class="form-check-input" name="allow_guest_delete" <?= $ev['allow_guest_delete']?'checked':'' ?>> Kendi yüklemesini silebilsin
                  </label>
                </div>
              </div>
              <input type="hidden" name="layout_json" id="layout_json" value="<?=h($layoutJson)?>">
              <input type="hidden" name="stickers_json" id="stickers_json" value="<?=h($stickersJson)?>">
              <div class="col-12 mt-2 d-grid d-md-flex justify-content-md-end">
                <button class="btn btn-zs px-4" type="submit">Kaydet</button>
              </div>
            </form>
          </div>

          <div class="card-lite filled p-4">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
              <div>
                <h2 class="card-title mb-1">Ek Paket ve Kampanyalar</h2>
                <p class="card-subtitle mb-0">Admin panelden tanımladığınız tüm ek paketler burada listelenir.</p>
              </div>
              <span class="badge-soft text-nowrap"><i class="bi bi-megaphone me-1"></i><?=count($campaigns)?> aktif</span>
            </div>
            <?php if(!$campaigns): ?>
              <div class="addon-empty mt-4"><i class="bi bi-box-seam me-2"></i>Şu anda yayında ek paket bulunmuyor.</div>
            <?php else: ?>
              <form class="addon-form" method="post" action="pay_addons.php">
                <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                <input type="hidden" name="event_id" value="<?=$EVENT_ID?>">
                <input type="hidden" name="key" value="<?=h($ev['couple_panel_key'])?>">
                <div class="addon-grid">
                  <?php foreach($campaigns as $c): $desc = trim((string)($c['description'] ?? '')); ?>
                    <label class="addon-card">
                      <input type="checkbox" class="addon-input" name="addons[]" value="<?=$c['id']?>">
                      <div class="addon-body">
                        <div class="addon-header">
                          <span class="addon-type"><?=h($c['type'])?></span>
                          <span class="addon-price"><?=number_format((int)$c['price'],0,',','.')?> TL</span>
                        </div>
                        <div>
                          <div class="addon-title"><?=h($c['name'])?></div>
                          <?php if($desc !== ''): ?>
                            <div class="addon-desc"><?=nl2br(h($desc))?></div>
                          <?php endif; ?>
                        </div>
                        <div class="addon-footer">
                          <span class="addon-check"><i class="bi bi-check-circle-fill"></i>Seçildi</span>
                          <span class="text-muted small">Sepete eklemek için işaretleyin</span>
                        </div>
                      </div>
                    </label>
                  <?php endforeach; ?>
                </div>
                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mt-4">
                  <div class="text-muted small"><i class="bi bi-info-circle me-1"></i>Birden fazla paketi aynı anda seçebilirsiniz.</div>
                  <button class="btn btn-zs px-4" type="submit"><i class="bi bi-wallet2 me-1"></i>Ödemeye Geç</button>
                </div>
              </form>
            <?php endif; ?>
            <?php if($hasInactiveCampaigns): ?>
              <p class="text-muted small mt-3 mb-0"><i class="bi bi-archive me-1"></i>Pasif durumdaki kampanyalar admin panelde saklanır ve burada gösterilmez.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-xl-5">
        <div class="vstack gap-4">
          <div class="card-lite filled p-4 gallery-card">
            <div>
              <h2 class="card-title mb-1">Galeri ve Depolama</h2>
              <p class="card-subtitle mb-0">Yüklenen içeriklerinizi görüntüleyin veya yönetin.</p>
            </div>
            <div class="gallery-metrics">
              <span class="badge badge-count"><i class="bi bi-cloud-arrow-up me-1"></i><?=number_format($uploadsCount,0,',','.')?> içerik</span>
              <span class="badge badge-size"><i class="bi bi-hdd-network me-1"></i><?=h($uploadsSizeReadable)?></span>
            </div>
            <div class="d-grid d-sm-flex gap-2">
              <a class="btn btn-zs flex-fill flex-sm-grow-0" href="list.php"><i class="bi bi-images me-1"></i>Yüklemeleri Aç</a>
            </div>
          </div>

          <div class="card-lite filled p-4">
            <h2 class="card-title mb-1">Misafir Sayfası Önizleme</h2>
            <p class="card-subtitle mb-3">Metinleri sürükleyerek ve renkleri değiştirerek gerçek görünümü test edin.</p>
            <div class="preview-shell">
              <div class="preview-stage" id="pvStage">
                <div class="stage-scale" id="scaleBox">
                  <div class="preview-canvas" id="canvas" style="--zs:<?=h($PRIMARY)?>; --zs-soft:<?=h($ACCENT)?>;">
                    <div id="pv-title" style="left:<?= (int)$tPos['x']?>px; top:<?= (int)$tPos['y']?>px;"><?=h($TITLE)?></div>
                    <div id="pv-sub" style="left:<?= (int)$sPos['x']?>px; top:<?= (int)$sPos['y']?>px;"><?=h($SUBTITLE)?></div>
                    <div id="pv-prompt" style="left:<?= (int)$pPos['x']?>px; top:<?= (int)$pPos['y']?>px;"><?=h($PROMPT)?></div>
                    <?php foreach($stickersArr as $i=>$st){
                      $txt = isset($st['txt'])?$st['txt']:'💍';
                      $x   = isset($st['x'])?(int)$st['x']:20;
                      $y   = isset($st['y'])?(int)$st['y']:90;
                      $sz  = isset($st['size'])?(int)$st['size']:32; ?>
                      <div class="sticker" data-i="<?=$i?>" style="left:<?=$x?>px; top:<?=$y?>px; font-size:<?=$sz?>px"><?=$txt?></div>
                    <?php } ?>
                  </div>
                </div>
              </div>
            </div>
            <p class="small text-muted mt-3 mb-0">Not: Bu önizleme gerçek misafir sayfasının birebir yansımasıdır.</p>
          </div>

          <div class="card-lite filled p-4">
            <h2 class="card-title mb-1">Sticker ve Simgeler</h2>
            <p class="card-subtitle mb-3">Misafir sayfanızı renklendirmek için aşağıdan simge ekleyebilirsiniz.</p>
            <div class="sticker-actions">
              <?php foreach(['💍','💐','🎉','🎶','📸','❤️','✨','🎈','🥂','👰','🤵','🍰','🌟','🎊','💞','🕊️'] as $em): ?>
                <button class="btn btn-sm btn-zs-outline add-sticker" data-emoji="<?=h($em)?>"><?=$em?></button>
              <?php endforeach; ?>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-3">
              <button class="btn btn-sm btn-outline-danger" id="clearStickers"><i class="bi bi-trash me-1"></i>Tüm simgeleri kaldır</button>
              <button class="btn btn-sm btn-outline-secondary" id="resetLayout"><i class="bi bi-arrow-counterclockwise me-1"></i>Yerleşimi sıfırla</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<script>
// 960x540 sahneyi container'a orantılı sığdır (upload.php ile aynı hesap)
(function(){
  const stage=document.getElementById('pvStage'), box=document.getElementById('scaleBox');
  function fit(){ if(!stage||!box) return; const W=stage.clientWidth, S=W/960; box.style.setProperty('--s',S); stage.style.height=(540*S)+'px'; }
  window.addEventListener('resize',fit,{passive:true}); new ResizeObserver(fit).observe(stage); fit();
})();

// Sadece önizleme alanına tema uygula
function applyTheme(){
  const primaryField=document.querySelector('[name=theme_primary]');
  const accentField=document.querySelector('[name=theme_accent]');
  if(!primaryField || !accentField) return;
  const p=primaryField.value;
  const a=accentField.value;
  document.documentElement.style.setProperty('--brand',p);
  document.documentElement.style.setProperty('--brand-soft',a);
  document.documentElement.style.setProperty('--zs',p);
  document.documentElement.style.setProperty('--zs-soft',a);
  const canvas=document.getElementById('canvas');
  if(canvas){ canvas.style.setProperty('--zs',p); canvas.style.setProperty('--zs-soft',a); }
}

// Metin -> preview (canlı)
[['guest_title','pv-title'],['guest_subtitle','pv-sub'],['guest_prompt','pv-prompt']]
.forEach(([n,id])=>{
  const el=document.querySelector(`[name=${n}]`);
  if(el) el.addEventListener('input',()=>{ document.getElementById(id).innerText = el.value || ''; saveHidden(); });
});

// Sürükle & bırak konumlandırma (birebir)
const canvas=document.getElementById('canvas');
const titleEl=document.getElementById('pv-title');
const subEl  =document.getElementById('pv-sub');
const prEl   =document.getElementById('pv-prompt');

let layout   = <?=(string)$layoutJson?>;
let stickers = <?=(string)$stickersJson?>;

function saveHidden(){
  // Güncel DOM konumlarını JSON’a yaz
  layout = {
    title   : {x:parseInt(titleEl.style.left)||24, y:parseInt(titleEl.style.top)||24},
    subtitle: {x:parseInt(subEl.style.left)||24,   y:parseInt(subEl.style.top)||60},
    prompt  : {x:parseInt(prEl.style.left)||24,    y:parseInt(prEl.style.top)||396}
  };
  const st = [];
  document.querySelectorAll('.sticker').forEach((d)=>{
    st.push({ txt:d.textContent, x:parseInt(d.style.left)||20, y:parseInt(d.style.top)||90, size:parseInt(d.style.fontSize)||32 });
  });
  document.getElementById('layout_json').value   = JSON.stringify(layout);
  document.getElementById('stickers_json').value = JSON.stringify(st);
}
function makeDraggable(el){
  let ox=0, oy=0, dragging=false;
  el.style.cursor='move';
  el.addEventListener('mousedown',e=>{ dragging=true; ox=e.offsetX; oy=e.offsetY; el.style.cursor='grabbing'; });
  window.addEventListener('mousemove',e=>{
    if(!dragging) return;
    const rect=canvas.getBoundingClientRect();
    let x=e.clientX-rect.left-ox, y=e.clientY-rect.top-oy;
    x=Math.max(0,Math.min(960-20,x)); y=Math.max(0,Math.min(540-20,y));
    el.style.left=x+'px'; el.style.top=y+'px';
  });
  window.addEventListener('mouseup',()=>{ if(!dragging) return; dragging=false; el.style.cursor='move'; saveHidden(); });
}
[titleEl,subEl,prEl].forEach(makeDraggable);

function addSticker(txt){
  const d=document.createElement('div');
  d.className='sticker';
  d.textContent=txt||'💍';
  d.style.left='20px'; d.style.top='90px'; d.style.fontSize='32px';
  canvas.appendChild(d);
  makeDraggable(d);
  saveHidden();
}
document.querySelectorAll('.add-sticker').forEach(b=>b.addEventListener('click',e=>{
  e.preventDefault(); addSticker(b.dataset.emoji);
}));

document.getElementById('clearStickers').onclick=(e)=>{
  e.preventDefault();
  document.querySelectorAll('.sticker').forEach(n=>n.remove());
  saveHidden();
};
document.getElementById('resetLayout').onclick=(e)=>{
  e.preventDefault();
  titleEl.style.left='24px'; titleEl.style.top='24px';
  subEl.style.left='24px';   subEl.style.top='60px';
  prEl.style.left='24px';    prEl.style.top='396px';
  saveHidden();
};

// Mevcut stickerları DOM’a bas (draggable olsun)
(function initStickers(){
  try{
    const arr = Array.isArray(stickers)? stickers : JSON.parse(stickers||'[]');
    arr.forEach(s=>{
      const d=document.createElement('div');
      d.className='sticker';
      d.textContent = s.txt || '💍';
      d.style.left  = (s.x||20)+'px';
      d.style.top   = (s.y||90)+'px';
      d.style.fontSize = (s.size||32)+'px';
      canvas.appendChild(d);
      makeDraggable(d);
    });
  }catch(e){}
})();
</script>
</body>
</html>
