<?php
require_once __DIR__.'/_auth.php';                  // tek URL login + aktif düğün
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/license.php';    // lisans yardımcıları
require_once __DIR__.'/../includes/theme.php';

// Aktif etkinlik bilgisi
$EVENT_ID = couple_current_event_id();
$ev = couple_event_row_current();
if (!$ev) {
  couple_global_logout();
  redirect(BASE_URL.'/couple/login.php');
}

$GUEST_FONTS = guest_font_options();
$currentBackgroundPath = trim((string)($ev['guest_background_path'] ?? ''));

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
  $titleFont = $_POST['guest_title_font'] ?? ($ev['guest_title_font'] ?? 'inter');
  $subFont   = $_POST['guest_subtitle_font'] ?? ($ev['guest_subtitle_font'] ?? $titleFont);
  $promptFont= $_POST['guest_prompt_font'] ?? ($ev['guest_prompt_font'] ?? $subFont);
  $titleFont = isset($GUEST_FONTS[$titleFont]) ? $titleFont : 'inter';
  $subFont   = isset($GUEST_FONTS[$subFont])   ? $subFont   : $titleFont;
  $promptFont= isset($GUEST_FONTS[$promptFont])? $promptFont: $subFont;
  $view      = isset($_POST['allow_guest_view']) ? 1 : 0;
  $download  = isset($_POST['allow_guest_download']) ? 1 : 0;
  $delete    = isset($_POST['allow_guest_delete']) ? 1 : 0;
  $layout    = $_POST['layout_json'] ?? null;
  $stickers  = $_POST['stickers_json'] ?? null;

  $layoutPayload = json_decode((string)$layout, true);
  $layoutNormalized = normalize_event_layout($layoutPayload);
  $layout = json_encode($layoutNormalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  $stickersPayload = json_decode((string)$stickers, true);
  $stickerAssetUrls = [];
  $stickersNormalized = normalize_event_stickers($stickersPayload ?: [], $EVENT_ID, $stickerAssetUrls);

  $newOverlayPath = null;
  if (!empty($_FILES['sticker_image']) && is_array($_FILES['sticker_image'])) {
    $newOverlayPath = event_guest_overlay_store($EVENT_ID, $_FILES['sticker_image']);
    if ($newOverlayPath) {
      $stickersNormalized[] = [
        'type'  => 'image',
        'path'  => $newOverlayPath,
        'x'     => 40,
        'y'     => 300,
        'width' => 260,
      ];
    }
  }
  $stickersNormalized = array_values($stickersNormalized);
  $stickers = json_encode($stickersNormalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  $newImagePaths = [];
  foreach ($stickersNormalized as $entry) {
    if (($entry['type'] ?? '') === 'image' && !empty($entry['path'])) {
      $newImagePaths[$entry['path']] = true;
    }
  }
  foreach ($existingImagePaths as $oldPath) {
    if (!isset($newImagePaths[$oldPath])) {
      event_guest_overlay_delete($oldPath);
    }
  }

  $removeBg  = ($_POST['remove_guest_background'] ?? '') === '1';
  $backgroundPath = $currentBackgroundPath;
  if ($removeBg && $backgroundPath !== '') {
    event_guest_background_delete($backgroundPath);
    $backgroundPath = '';
  }
  if (!empty($_FILES['guest_background']) && is_array($_FILES['guest_background'])) {
    $backgroundPath = event_guest_background_store($EVENT_ID, $_FILES['guest_background'], $backgroundPath ?: null) ?: $backgroundPath;
  }


  $contact   = trim($_POST['contact_email'] ?? '');
  $phone     = trim($_POST['couple_phone'] ?? '');
  $tckn      = trim($_POST['couple_tckn'] ?? '');
  $inv_title = trim($_POST['invoice_title'] ?? '');
  $inv_vkn   = trim($_POST['invoice_vkn'] ?? '');
  $inv_addr  = trim($_POST['invoice_address'] ?? '');

  pdo()->prepare("UPDATE events SET
    guest_title=?, guest_subtitle=?, guest_prompt=?,
    guest_title_font=?, guest_subtitle_font=?, guest_prompt_font=?,
    theme_primary=?, theme_accent=?,
    allow_guest_view=?, allow_guest_download=?, allow_guest_delete=?,
    layout_json=?, guest_background_path=?, stickers_json=?,
    contact_email=?, couple_phone=?, couple_tckn=?, invoice_title=?, invoice_vkn=?, invoice_address=?,
    updated_at=?
  WHERE id=?")->execute([
    $title?:null,$subtitle?:null,$prompt?:null,
    $titleFont?:null,$subFont?:null,$promptFont?:null,
    $primary,$accent,$view,$download,$delete,
    $layout?:null,$backgroundPath?:null,$stickers?:null,
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
$appName = APP_NAME;
$appInitialsSource = preg_replace('/[^\p{L}\p{Nd}]+/u', '', $appName);
$appInitials = mb_strtoupper(mb_substr($appInitialsSource !== '' ? $appInitialsSource : 'APP', 0, 2, 'UTF-8'), 'UTF-8');
$greetingName = $ev['title'] ?: 'Hoş geldiniz';
$licenseUsagePercent = null;
if (is_numeric($licenseDaysRemaining)) {
  $licenseDaysValue = (int)$licenseDaysRemaining;
  $licenseBaseline = max(1, $licenseDaysValue > 365 ? $licenseDaysValue : 365);
  $remainingRatio = max(0, min(100, round(($licenseDaysValue / $licenseBaseline) * 100)));
  $licenseUsagePercent = 100 - $remainingRatio;
  if ($licenseDaysValue <= 0) {
    $licenseUsagePercent = 100;
  }
}
if ($licenseUsagePercent === null) {
  $licenseUsagePercent = $license_active ? 15 : 100;
}
$licenseUsagePercent = max(0, min(100, (int)$licenseUsagePercent));
$storageQuotaBytes = 10 * 1024 * 1024 * 1024; // 10 GB varsayılan paket
$storageUsagePercent = $storageQuotaBytes > 0 ? (int)min(100, round(($uploadsBytes / $storageQuotaBytes) * 100)) : 0;
$storageQuotaReadable = fmt_bytes($storageQuotaBytes);
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
$TITLE    = $ev['guest_title'] ?: 'Etkinliğimize Hoş Geldiniz';
$SUBTITLE = $ev['guest_subtitle'] ?: 'En güzel anlarınızı bizimle paylaşın';
$PROMPT   = $ev['guest_prompt'] ?: 'Adınızı yazıp anınızı yükleyin.';
$PRIMARY  = $ev['theme_primary'] ?: '#0ea5b5';
$ACCENT   = $ev['theme_accent']  ?: '#e0f7fb';

// Layout & stickers (normalize with helpers)
$layoutDecoded = json_decode($ev['layout_json'] ?? '', true);
$layoutArr = normalize_event_layout($layoutDecoded);
$layoutJson = json_encode($layoutArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$tPos = $layoutArr['title'];
$sPos = $layoutArr['subtitle'];
$pPos = $layoutArr['prompt'];

$existingStickerMap = [];
$stickersDecoded = json_decode($ev['stickers_json'] ?? '[]', true);
$stickersArr = normalize_event_stickers($stickersDecoded, $EVENT_ID, $existingStickerMap);
$stickersJson = json_encode($stickersArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$existingImagePaths = [];
foreach ($stickersArr as $entry) {
  if (($entry['type'] ?? '') === 'image' && !empty($entry['path'])) {
    $existingImagePaths[] = $entry['path'];
  }
}

$TITLE_FONT_KEY = $ev['guest_title_font'] ?: 'inter';
if (!isset($GUEST_FONTS[$TITLE_FONT_KEY])) { $TITLE_FONT_KEY = 'inter'; }
$SUBTITLE_FONT_KEY = $ev['guest_subtitle_font'] ?: $TITLE_FONT_KEY;
if (!isset($GUEST_FONTS[$SUBTITLE_FONT_KEY])) { $SUBTITLE_FONT_KEY = $TITLE_FONT_KEY; }
$PROMPT_FONT_KEY = $ev['guest_prompt_font'] ?: $SUBTITLE_FONT_KEY;
if (!isset($GUEST_FONTS[$PROMPT_FONT_KEY])) { $PROMPT_FONT_KEY = $SUBTITLE_FONT_KEY; }
$TITLE_FONT_STACK = guest_font_stack($TITLE_FONT_KEY);
$SUBTITLE_FONT_STACK = guest_font_stack($SUBTITLE_FONT_KEY);
$PROMPT_FONT_STACK = guest_font_stack($PROMPT_FONT_KEY);
$FONT_IMPORTS = guest_font_imports([$TITLE_FONT_KEY, $SUBTITLE_FONT_KEY, $PROMPT_FONT_KEY]);
$TITLE_FONT_STACK_ESC = htmlspecialchars($TITLE_FONT_STACK, ENT_NOQUOTES, 'UTF-8');
$SUBTITLE_FONT_STACK_ESC = htmlspecialchars($SUBTITLE_FONT_STACK, ENT_NOQUOTES, 'UTF-8');
$PROMPT_FONT_STACK_ESC = htmlspecialchars($PROMPT_FONT_STACK, ENT_NOQUOTES, 'UTF-8');
$BACKGROUND_PATH = trim((string)($ev['guest_background_path'] ?? ''));
if (!event_guest_background_exists($BACKGROUND_PATH)) { $BACKGROUND_PATH = ''; }
$BACKGROUND_URL = $BACKGROUND_PATH !== '' ? event_guest_background_url($BACKGROUND_PATH) : null;
$canvasStyle = '--zs:'.htmlspecialchars($PRIMARY, ENT_QUOTES, 'UTF-8').'; --zs-soft:'.htmlspecialchars($ACCENT, ENT_QUOTES, 'UTF-8').';';
if ($BACKGROUND_URL) {
  $canvasStyle .= ' background-image:url('.htmlspecialchars($BACKGROUND_URL, ENT_QUOTES, 'UTF-8').');';
}
$canvasHasBg = $BACKGROUND_URL ? '1' : '0';
$bgPreviewStyle = $BACKGROUND_URL ? 'background-image:url('.htmlspecialchars($BACKGROUND_URL, ENT_QUOTES, 'UTF-8').');' : '';
$fontConfigForJs = [];
foreach ($GUEST_FONTS as $key => $fontMeta) {
  $fontConfigForJs[$key] = [
    'stack' => $fontMeta['stack'],
    'import' => $fontMeta['import'] ?? '',
  ];
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($ev['title'])?> — Etkinlik Paneli</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?=theme_head_assets()?>
<?php foreach ($FONT_IMPORTS as $import): ?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=<?=h($import)?>&display=swap">
<?php endforeach; ?>
<style>
:root{
  --ink:#0f172a;
  --muted:#64748b;
  --brand:<?=h($PRIMARY)?>;
  --brand-soft:<?=h($ACCENT)?>;
  --zs:<?=h($PRIMARY)?>;
  --zs-soft:<?=h($ACCENT)?>;
  --guest-title-font: <?=$TITLE_FONT_STACK_ESC?>;
  --guest-subtitle-font: <?=$SUBTITLE_FONT_STACK_ESC?>;
  --guest-prompt-font: <?=$PROMPT_FONT_STACK_ESC?>;
}
*,*::before,*::after{ box-sizing:border-box; }
body{
  font-family:var(--guest-subtitle-font, 'Inter','Segoe UI','Helvetica Neue',sans-serif);
  background:radial-gradient(circle at top left, rgba(148,197,255,.25), transparent 35%),
             radial-gradient(circle at bottom right, rgba(14,165,181,.18), transparent 45%),
             #f5f7fb;
  color:var(--ink);
  margin:0;
}
a{ color:inherit; text-decoration:none; }
a:hover{ text-decoration:none; }
.portal-shell{
  min-height:100vh;
  display:flex;
  gap:36px;
  padding:40px clamp(1.5rem, 4vw, 4rem);
}
.portal-sidebar{
  width:320px;
  background:rgba(255,255,255,.82);
  border-radius:32px;
  border:1px solid rgba(148,163,184,.14);
  box-shadow:0 45px 80px -60px rgba(15,23,42,.45);
  padding:32px 28px;
  display:flex;
  flex-direction:column;
  gap:28px;
  position:sticky;
  top:32px;
  align-self:flex-start;
}
.sidebar-brand{ display:flex; align-items:center; gap:16px; }
.sidebar-logo{
  width:54px;
  height:54px;
  border-radius:16px;
  background:linear-gradient(135deg,var(--brand),#0b8b98);
  color:#fff;
  display:grid;
  place-items:center;
  font-weight:700;
  font-size:1.1rem;
  box-shadow:0 20px 40px -25px rgba(14,165,181,.7);
}
.sidebar-title{ font-size:1.15rem; font-weight:700; margin:0; color:var(--ink); }
.sidebar-welcome{ font-size:.75rem; text-transform:uppercase; letter-spacing:.12em; color:rgba(100,116,139,.85); font-weight:600; }
.sidebar-nav{ display:flex; flex-direction:column; gap:20px; }
.nav-group{ display:flex; flex-direction:column; gap:10px; }
.nav-heading{ font-size:.72rem; text-transform:uppercase; letter-spacing:.18em; color:rgba(100,116,139,.75); font-weight:700; margin-bottom:.4rem; }
.nav-link{ display:flex; align-items:center; gap:.65rem; padding:.6rem .85rem; border-radius:14px; font-weight:600; color:var(--muted); transition:background .2s, color .2s, transform .2s; }
.nav-link i{ font-size:1.1rem; }
.nav-link:hover{ background:rgba(14,165,181,.08); color:var(--ink); transform:translateX(4px); }
.nav-link.active{ background:linear-gradient(135deg,var(--brand),#0b8b98); color:#fff; box-shadow:0 16px 32px -24px rgba(14,165,181,.75); }
.nav-link.active i{ color:inherit; }
.sidebar-card{ border-radius:22px; background:rgba(248,250,252,.9); border:1px solid rgba(148,163,184,.18); padding:22px; display:flex; flex-direction:column; gap:16px; }
.sidebar-card h3{ margin:0; font-size:.95rem; font-weight:700; color:var(--ink); }
.sidebar-card p{ margin:0; font-size:.85rem; color:var(--muted); }
.usage-circle{ width:130px; aspect-ratio:1 / 1; border-radius:50%; background:conic-gradient(var(--brand) calc(var(--percent) * 1%), rgba(226,232,240,.8) 0); display:grid; place-items:center; position:relative; margin:0 auto; }
.usage-circle::after{ content:attr(data-label); font-size:1.2rem; font-weight:700; color:var(--ink); }
.usage-inner{ position:absolute; inset:18px; background:#fff; border-radius:50%; display:grid; place-items:center; font-size:.8rem; font-weight:600; color:var(--muted); }
.sidebar-footer{ margin-top:auto; display:flex; flex-direction:column; gap:12px; }
.sidebar-footer .btn{ border-radius:14px; padding:.65rem 1.1rem; font-weight:600; }
.btn-zs{ background:var(--brand); border:none; color:#fff; border-radius:14px; padding:.75rem 1.1rem; font-weight:600; transition:transform .2s, box-shadow .2s; }
.btn-zs:hover{ color:#fff; transform:translateY(-1px); box-shadow:0 16px 34px -24px rgba(14,165,181,.8); }
.btn-zs-outline{ background:#fff; border:1px solid rgba(14,165,181,.35); color:var(--brand); border-radius:14px; padding:.6rem 1rem; font-weight:600; transition:background .2s, color .2s; }
.btn-zs-outline:hover{ background:rgba(14,165,181,.12); color:var(--brand); }
.portal-main-area{ flex:1; display:flex; flex-direction:column; gap:28px; }
.portal-container{ width:100%; max-width:1080px; margin:0 auto; display:flex; flex-direction:column; gap:28px; }
.portal-header-card{
  position:relative;
  border-radius:32px;
  background:linear-gradient(135deg, rgba(255,255,255,.92), rgba(226,247,252,.92));
  padding:36px 40px;
  box-shadow:0 55px 90px -72px rgba(14,165,181,.7);
  overflow:hidden;
}
.portal-header-card::after{ content:''; position:absolute; width:220px; height:220px; right:-60px; top:-60px; background:radial-gradient(circle at center, rgba(14,165,181,.35), rgba(14,165,181,0)); }
.portal-header-card::before{ content:''; position:absolute; width:180px; height:180px; left:-80px; bottom:-80px; background:radial-gradient(circle at center, rgba(14,165,181,.2), rgba(14,165,181,0) 70%); }
.portal-header-content{ position:relative; display:flex; flex-wrap:wrap; gap:32px 48px; align-items:flex-start; justify-content:space-between; }
.hero-text{ max-width:560px; display:flex; flex-direction:column; gap:12px; }
.hero-overline{ text-transform:uppercase; letter-spacing:.16em; font-size:.75rem; color:rgba(15,23,42,.68); font-weight:600; }
.hero-title{ font-size:2.2rem; font-weight:700; color:var(--ink); margin:0; }
.hero-sub{ color:rgba(15,23,42,.72); font-size:1rem; margin:0; }
.hero-meta{ display:flex; flex-wrap:wrap; gap:1rem 1.4rem; font-size:.92rem; color:rgba(15,23,42,.75); }
.hero-meta span{ display:flex; align-items:center; gap:.5rem; font-weight:600; }
.hero-chip{ display:inline-flex; align-items:center; gap:.4rem; font-size:.85rem; background:rgba(255,255,255,.78); padding:.4rem .9rem; border-radius:999px; color:var(--ink); font-weight:600; box-shadow:0 16px 40px -36px rgba(15,23,42,.65); }
.hero-actions{ display:flex; flex-wrap:wrap; gap:.75rem; margin-top:8px; }
.hero-license{ display:flex; flex-direction:column; gap:1rem; }
.hero-license .hero-badge{ display:inline-flex; align-items:center; gap:.55rem; border-radius:999px; padding:.45rem 1.4rem; font-weight:600; background:rgba(255,255,255,.85); color:var(--ink); border:1px solid rgba(148,163,184,.28); }
.hero-license .hero-badge.success{ border-color:rgba(34,197,94,.35); color:#047857; background:rgba(220,252,231,.85); }
.hero-license .hero-badge.danger{ border-color:rgba(248,113,113,.3); color:#b91c1c; background:rgba(254,226,226,.92); }
.hero-license form{ display:flex; flex-wrap:wrap; gap:.75rem; align-items:center; }
.hero-license .form-select{ min-width:200px; border-radius:999px; border-color:rgba(148,163,184,.5); padding:0 1.1rem; height:44px; font-weight:600; background:rgba(255,255,255,.9); }
.hero-license .btn{ border-radius:999px; padding:.55rem 1.3rem; font-weight:600; background:var(--brand); border:none; color:#fff; }
.hero-usage{ display:flex; flex-direction:column; align-items:center; justify-content:center; gap:.45rem; min-width:180px; }
.hero-usage p{ margin:0; }
.portal-summary{ display:flex; flex-direction:column; gap:18px; }
.stat-grid{ display:grid; gap:16px; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); }
.stat-card{ border-radius:22px; border:1px solid rgba(148,163,184,.24); background:rgba(255,255,255,.88); padding:20px 22px; display:flex; flex-direction:column; gap:10px; min-height:150px; position:relative; overflow:hidden; }
.stat-card .stat-label{ font-size:.78rem; text-transform:uppercase; letter-spacing:.14em; color:rgba(71,85,105,.85); font-weight:700; }
.stat-card .stat-value{ font-size:1.75rem; font-weight:700; color:var(--ink); }
.stat-card .stat-sub{ font-size:.9rem; color:var(--muted); margin-bottom:0; }
.stat-card.success{ border-color:rgba(34,197,94,.28); }
.stat-card.success .stat-value{ color:#047857; }
.stat-card.danger{ border-color:rgba(248,113,113,.3); }
.stat-card.danger .stat-value{ color:#b91c1c; }
.stat-card .stat-icon{ position:absolute; right:20px; top:20px; font-size:1.5rem; color:rgba(100,116,139,.35); }
.portal-main{ display:flex; flex-direction:column; gap:28px; }
.portal-form .form-label{ font-weight:600; color:var(--ink); }
.portal-form .form-control,
.portal-form .form-select,
.portal-form .form-control-color{ border-radius:14px; border-color:rgba(148,163,184,.4); background:rgba(248,250,252,.75); padding:.65rem .85rem; }
.portal-form textarea.form-control{ min-height:100px; }
.portal-form .form-check{ display:flex; align-items:center; gap:.55rem; padding:.35rem 0; }
.portal-form .form-check-input{ width:18px; height:18px; border-radius:6px; }
.card-lite{ border-radius:26px; border:1px solid rgba(148,163,184,.22); background:rgba(255,255,255,.92); box-shadow:0 34px 60px -50px rgba(15,23,42,.45); }
.card-lite.filled{ background:rgba(255,255,255,.96); }
.card-lite .card-title{ font-size:1.05rem; font-weight:600; color:var(--ink); }
.card-lite .card-subtitle{ color:var(--muted); font-size:.9rem; }
.addon-grid{ display:grid; gap:18px; }
@media (min-width:576px){ .addon-grid{ grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); } }
.addon-card{ position:relative; display:block; cursor:pointer; }
.addon-card input{ position:absolute; inset:0; opacity:0; pointer-events:none; }
.addon-card .addon-body{ border-radius:22px; border:1.5px solid rgba(148,163,184,.35); padding:22px 24px; background:rgba(255,255,255,.9); display:flex; flex-direction:column; gap:14px; min-height:190px; transition:transform .22s ease, box-shadow .22s ease, border-color .22s ease, background .22s ease; }
.addon-card:hover .addon-body{ transform:translateY(-2px); box-shadow:0 22px 48px -32px rgba(14,165,181,.6); border-color:rgba(14,165,181,.6); }
.addon-card input:checked + .addon-body{ border-color:var(--brand); box-shadow:0 28px 58px -36px rgba(14,165,181,.65); background:linear-gradient(140deg, rgba(14,165,181,.12), rgba(14,165,181,.02)); }
.addon-header{ display:flex; justify-content:space-between; align-items:center; }
.addon-type{ font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; background:rgba(14,165,181,.16); color:var(--brand); padding:.35rem .75rem; border-radius:999px; }
.addon-price{ font-weight:700; font-size:1.28rem; color:var(--ink); }
.addon-title{ font-size:1.08rem; font-weight:600; color:var(--ink); }
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
.preview-shell{ position:relative; width:min(100%,980px); margin:0 auto; padding:1.8rem; border-radius:28px; background:linear-gradient(135deg, rgba(14,165,181,.08), rgba(79,70,229,.06)); border:1px solid rgba(148,163,184,.2); box-shadow:0 42px 80px -60px rgba(14,165,181,.38); }
.preview-shell::after{ content:''; position:absolute; inset:0; border-radius:inherit; background:linear-gradient(140deg, rgba(255,255,255,.65), rgba(255,255,255,.2)); pointer-events:none; mix-blend-mode:screen; }
.preview-stage{ position:relative; width:100%; border-radius:26px; background:rgba(255,255,255,.95); overflow:hidden; border:1px solid rgba(148,163,184,.22); box-shadow:0 45px 90px -68px rgba(15,23,42,.42); }
.preview-card{ position:relative; overflow:hidden; }
.preview-grid{ display:flex; flex-direction:column; gap:24px; }
@media(min-width:1200px){ .preview-grid{ flex-direction:row; align-items:stretch; } }
.preview-controls{ flex:0 0 320px; display:flex; flex-direction:column; gap:18px; }
@media(max-width:1199px){ .preview-controls{ width:100%; } }
.preview-control{ border-radius:20px; padding:18px 20px; background:rgba(255,255,255,.9); border:1px solid rgba(148,163,184,.18); box-shadow:0 28px 60px -48px rgba(15,23,42,.35); display:flex; flex-direction:column; gap:14px; }
.preview-control--accent{ background:linear-gradient(140deg, rgba(14,165,181,.12), rgba(79,70,229,.08)); border-color:rgba(14,165,181,.28); box-shadow:0 32px 68px -48px rgba(14,165,181,.45); }
.preview-control-head{ display:flex; flex-direction:column; gap:4px; }
.preview-control-title{ font-weight:700; font-size:.95rem; color:var(--ink); }
.preview-control-sub{ font-size:.85rem; color:var(--muted); }
.preview-hint{ display:flex; align-items:center; gap:.65rem; background:rgba(255,255,255,.85); border:1px solid rgba(148,163,184,.2); border-radius:999px; padding:.45rem .9rem; font-size:.85rem; font-weight:600; color:var(--ink); box-shadow:0 20px 40px -32px rgba(15,23,42,.35); }
.preview-form{ display:flex; flex-direction:column; gap:12px; }
.preview-form-label{ font-weight:600; font-size:.85rem; color:var(--ink); }
.preview-form-control{ border-radius:14px; border:1px solid rgba(148,163,184,.35); background:rgba(255,255,255,.92); box-shadow:0 16px 32px -28px rgba(15,23,42,.28); }
.preview-form-control:focus{ border-color:var(--brand); box-shadow:0 0 0 4px rgba(14,165,181,.18); }
.preview-font-grid{ display:grid; gap:14px; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); }
.preview-font-field select option{ font-weight:600; }
.preview-font-field select option[data-font-stack]{ font-family:inherit; }
.preview-bg-grid{ display:flex; flex-wrap:wrap; gap:16px; align-items:center; }
.preview-bg-actions{ flex:1; display:flex; flex-direction:column; gap:12px; }
.preview-bg-actions .btn{ border-radius:12px; }
.preview-bg-actions .form-text{ font-size:.8rem; color:var(--muted); }
.pv-editable{ cursor:text; outline:none; }
.pv-editable:focus{ box-shadow:0 0 0 4px rgba(14,165,181,.25); border-radius:12px; padding:.2rem .4rem; margin:-.2rem -.4rem; background:rgba(255,255,255,.8); }
.stage-scale{ position:absolute; left:0; top:0; width:960px; height:540px; transform-origin:top left; transform:scale(var(--s,1)); }
.preview-canvas{ position:absolute; inset:0; background:linear-gradient(180deg,var(--zs-soft),#fff); background-size:cover; background-position:center; transition:background-image .35s ease, background-color .35s ease; }
.preview-canvas::after{ content:''; position:absolute; inset:0; background:linear-gradient(180deg,rgba(255,255,255,.88),rgba(255,255,255,.6)); opacity:0; transition:opacity .3s ease; pointer-events:none; }
.preview-canvas[data-has-bg="1"]::after{ opacity:1; }
#pv-title{ position:absolute; font-size:30px; font-weight:800; color:#0f172a; letter-spacing:.015em; font-family:var(--guest-title-font); text-shadow:0 12px 30px rgba(15,23,42,.18); }
#pv-sub{ position:absolute; color:#334155; font-size:18px; font-weight:600; max-width:520px; line-height:1.45; font-family:var(--guest-subtitle-font); }
#pv-prompt{ position:absolute; color:#0f172a; font-size:16px; font-weight:500; letter-spacing:.01em; background:rgba(255,255,255,.85); padding:.75rem 1rem; border-radius:14px; box-shadow:0 14px 28px -20px rgba(15,23,42,.45); font-family:var(--guest-prompt-font); }
.sticker{ position:absolute; user-select:none; cursor:move; filter:drop-shadow(0 8px 18px rgba(15,23,42,.25)); transition:transform .18s ease; touch-action:none; }
.sticker.is-active{ outline:2px dashed var(--brand); outline-offset:6px; }
.sticker-img img{ display:block; max-width:520px; border-radius:18px; pointer-events:none; user-select:none; box-shadow:0 24px 50px -36px rgba(15,23,42,.4); }
.sticker-actions{ display:flex; flex-wrap:wrap; gap:.75rem; margin-top:1.1rem; }
.sticker-actions .btn{ border-radius:12px; padding:.45rem .85rem; font-weight:600; }
.sticker-size-tool .form-range{ --bs-form-range-thumb-bg: var(--brand); }
.sticker-size-tool .form-range:disabled{ opacity:.4; }
.font-chooser-grid{ display:grid; gap:16px; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); }
.font-chooser{ border-radius:18px; border:1px solid rgba(148,163,184,.2); background:rgba(255,255,255,.85); padding:18px 20px; display:flex; flex-direction:column; gap:12px; box-shadow:0 24px 48px -40px rgba(15,23,42,.35); }
.font-chooser-head{ display:flex; flex-direction:column; gap:4px; }
.font-chooser-title{ font-weight:700; color:var(--ink); }
.font-chooser-sub{ font-size:.85rem; color:var(--muted); }
.font-chip-list{ display:flex; flex-wrap:wrap; gap:10px; }
.font-chip{ border:1px solid rgba(148,163,184,.35); border-radius:12px; padding:.6rem .85rem; background:rgba(255,255,255,.82); font-weight:600; transition:all .2s ease; color:var(--ink); }
.font-chip:hover{ border-color:var(--brand); box-shadow:0 12px 24px -18px rgba(14,165,181,.45); transform:translateY(-1px); }
.font-chip.active{ border-color:var(--brand); background:rgba(14,165,181,.12); color:var(--brand); box-shadow:0 18px 36px -24px rgba(14,165,181,.45); }
.font-chip-label{ pointer-events:none; }
.preview-controls .btn{ border-radius:12px; font-weight:600; }
.bg-preview{ width:170px; height:120px; border-radius:18px; border:1px solid rgba(148,163,184,.25); background:linear-gradient(135deg, rgba(14,165,181,.12), rgba(14,165,181,.02)); display:flex; align-items:center; justify-content:center; color:var(--muted); font-weight:600; position:relative; overflow:hidden; transition:transform .25s ease, box-shadow .25s ease; }
.bg-preview span{ z-index:1; }
.bg-preview::after{ content:''; position:absolute; inset:0; background:linear-gradient(135deg, rgba(14,165,181,.18), rgba(14,165,181,0)); opacity:.35; transition:opacity .25s ease; }
.bg-preview.has-image{ background-size:cover; background-position:center; color:#fff; box-shadow:0 28px 60px -48px rgba(15,23,42,.55); }
.bg-preview.has-image::after{ opacity:.6; }
.badge-soft{ background:#eef2ff; color:#334155; border-radius:999px; padding:.45rem 1rem; font-weight:600; }
@media (max-width:1199px){
  .portal-shell{ flex-direction:column; padding:32px clamp(1.5rem,5vw,3rem); }
  .portal-sidebar{ width:100%; position:static; flex-direction:row; flex-wrap:wrap; align-items:flex-start; gap:24px; }
  .sidebar-nav{ flex-direction:row; flex-wrap:wrap; gap:18px 28px; flex:1; }
  .nav-group{ min-width:200px; }
  .sidebar-footer{ flex-direction:row; flex-wrap:wrap; }
}
@media (max-width:991px){
  .portal-header-card{ padding:28px; }
  .portal-header-content{ gap:24px; }
}
@media (max-width:767px){
  .portal-shell{ padding:28px 1.25rem 40px; }
  .portal-sidebar{ padding:24px; border-radius:24px; }
  .portal-header-card{ border-radius:26px; }
  .portal-header-content{ flex-direction:column; }
  .hero-title{ font-size:1.8rem; }
  .hero-license .form-select{ min-width:0; flex:1; }
  .portal-container{ gap:24px; }
}
@media (max-width:575px){
  .nav-group{ min-width:0; width:100%; }
  .hero-meta{ flex-direction:column; align-items:flex-start; }
  .usage-circle{ width:120px; }
}
</style>
</head>

<body>
<div class="portal-shell">
  <aside class="portal-sidebar">
    <div class="sidebar-brand">
      <div class="sidebar-logo"><?=$appInitials?></div>
      <div>
        <span class="sidebar-welcome">Etkinlik Paneli</span>
        <h2 class="sidebar-title"><?=h(APP_NAME)?></h2>
      </div>
    </div>
    <div class="sidebar-card">
      <h3>Aktif Etkinlik</h3>
      <p class="fw-semibold mb-1"><?=h($ev['title'])?></p>
      <p class="mb-0"><?= $eventDateFormatted ? h($eventDateFormatted) : 'Tarih belirlenmedi' ?><?php if($eventCountdownText){ ?> · <?=h($eventCountdownText)?><?php } ?></p>
      <?php if($venueName): ?>
        <p class="mb-0 small text-muted"><i class="bi bi-geo-alt me-1"></i><?=h($venueName)?></p>
      <?php endif; ?>
    </div>
    <div class="sidebar-card text-center">
      <div class="usage-circle mb-3" style="--percent:<?=$storageUsagePercent?>;" data-label="<?=$storageUsagePercent?>%">
        <div class="usage-inner">Depo</div>
      </div>
      <h3>Depolama Kullanımı</h3>
      <p class="mb-1"><?=h($uploadsSizeReadable)?> / <?=h($storageQuotaReadable)?></p>
      <p class="small text-muted mb-3">Toplam içerik: <?=number_format($uploadsCount,0,',','.')?></p>
      <div class="d-grid">
        <a class="btn btn-zs-outline" href="list.php"><i class="bi bi-images me-1"></i>Yüklemeleri Aç</a>
      </div>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-group">
        <span class="nav-heading">Main Navigation</span>
        <a class="nav-link active" href="index.php"><i class="bi bi-speedometer2"></i>Kontrol Paneli</a>
        <a class="nav-link" href="list.php"><i class="bi bi-collection"></i>Galeri</a>
        <a class="nav-link" href="engage.php"><i class="bi bi-stars"></i>Etkileşim Araçları</a>
        <a class="nav-link" href="invitations.php"><i class="bi bi-envelope-open"></i>Davetiye Merkezi</a>
      </div>
      <div class="nav-group">
        <span class="nav-heading">Support & Settings</span>
        <a class="nav-link" href="password.php"><i class="bi bi-shield-lock"></i>Şifreyi Güncelle</a>
        <a class="nav-link" href="pay_license.php"><i class="bi bi-credit-card"></i>Lisansı Uzat</a>
      </div>
    </nav>
    <div class="sidebar-footer">
      <a class="btn btn-zs-outline" href="logout.php"><i class="bi bi-box-arrow-right me-1"></i>Çıkış Yap</a>
    </div>
  </aside>
  <main class="portal-main-area">
    <div class="portal-container">
      <section class="portal-header-card">
        <div class="portal-header-content">
          <div class="hero-text">
            <span class="hero-overline">Hoş geldin</span>
            <h1 class="hero-title"><?=h($greetingName)?></h1>
            <p class="hero-sub">Misafir deneyimini kişiselleştirin, kampanyaları keşfedin ve yüklemelerinizi yönetin.</p>
            <?php if($coupleEmail): ?>
              <span class="hero-chip"><i class="bi bi-envelope-open me-1"></i><?=h($coupleEmail)?></span>
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
            <div class="hero-actions">
              <a class="btn btn-zs" href="list.php"><i class="bi bi-images me-1"></i>Tüm Dosyalar</a>
              <a class="btn btn-zs-outline" href="engage.php"><i class="bi bi-magic me-1"></i>Etkileşim Araçları</a>
              <a class="btn btn-zs-outline" href="guest_preview.php" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Misafir Panelini Aç (Şifresiz)</a>
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
          <div class="hero-usage text-center">
            <div class="usage-circle mb-3" style="--percent:<?=$licenseUsagePercent?>;" data-label="<?=$licenseUsagePercent?>%">
              <div class="usage-inner">Lisans</div>
            </div>
            <p class="fw-semibold mb-1"><?=$licenseStatusText?></p>
            <p class="small text-muted mb-0"><?=$licenseStatSub?></p>
          </div>
        </div>
      </section>
      <section class="portal-summary">
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
            <span class="stat-sub">Kullanılan alan</span>
          </div>
        </div>
      </section>
      <?php flash_box(); ?>
      <section class="portal-main">
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
                <form id="settingsForm" method="post" class="row g-3" enctype="multipart/form-data">
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
                  <div class="col-12">
                    <label class="form-label mb-2">Tipografi</label>
                    <div class="font-chooser-grid">
                      <div class="font-chooser" data-font-input="guest_title_font">
                        <div class="font-chooser-head">
                          <span class="font-chooser-title">Başlık</span>
                          <span class="font-chooser-sub">Hero metninin karakterini seçin.</span>
                        </div>
                        <select class="form-select d-none" name="guest_title_font" id="guest_title_font" data-font-select data-css-var="--guest-title-font" data-preview-target="pv-title">
                          <?php foreach($GUEST_FONTS as $fontKey => $fontMeta): ?>
                            <option value="<?=h($fontKey)?>" <?=$TITLE_FONT_KEY === $fontKey ? 'selected' : ''?>><?=h($fontMeta['label'])?></option>
                          <?php endforeach; ?>
                        </select>
                        <div class="font-chip-list">
                          <?php foreach($GUEST_FONTS as $fontKey => $fontMeta): ?>
                            <button type="button" class="font-chip<?=$TITLE_FONT_KEY === $fontKey ? ' active' : ''?>" data-font-chip data-font="<?=h($fontKey)?>" style="font-family:<?=h($fontMeta['stack'])?>;">
                              <span class="font-chip-label"><?=h($fontMeta['label'])?></span>
                            </button>
                          <?php endforeach; ?>
                        </div>
                      </div>
                      <div class="font-chooser" data-font-input="guest_subtitle_font">
                        <div class="font-chooser-head">
                          <span class="font-chooser-title">Alt Başlık</span>
                          <span class="font-chooser-sub">Davet notunuzun tonu.</span>
                        </div>
                        <select class="form-select d-none" name="guest_subtitle_font" id="guest_subtitle_font" data-font-select data-css-var="--guest-subtitle-font" data-preview-target="pv-sub">
                          <?php foreach($GUEST_FONTS as $fontKey => $fontMeta): ?>
                            <option value="<?=h($fontKey)?>" <?=$SUBTITLE_FONT_KEY === $fontKey ? 'selected' : ''?>><?=h($fontMeta['label'])?></option>
                          <?php endforeach; ?>
                        </select>
                        <div class="font-chip-list">
                          <?php foreach($GUEST_FONTS as $fontKey => $fontMeta): ?>
                            <button type="button" class="font-chip<?=$SUBTITLE_FONT_KEY === $fontKey ? ' active' : ''?>" data-font-chip data-font="<?=h($fontKey)?>" style="font-family:<?=h($fontMeta['stack'])?>;">
                              <span class="font-chip-label"><?=h($fontMeta['label'])?></span>
                            </button>
                          <?php endforeach; ?>
                        </div>
                      </div>
                      <div class="font-chooser" data-font-input="guest_prompt_font">
                        <div class="font-chooser-head">
                          <span class="font-chooser-title">Mesaj</span>
                          <span class="font-chooser-sub">Yükleme talimatının stili.</span>
                        </div>
                        <select class="form-select d-none" name="guest_prompt_font" id="guest_prompt_font" data-font-select data-css-var="--guest-prompt-font" data-preview-target="pv-prompt">
                          <?php foreach($GUEST_FONTS as $fontKey => $fontMeta): ?>
                            <option value="<?=h($fontKey)?>" <?=$PROMPT_FONT_KEY === $fontKey ? 'selected' : ''?>><?=h($fontMeta['label'])?></option>
                          <?php endforeach; ?>
                        </select>
                        <div class="font-chip-list">
                          <?php foreach($GUEST_FONTS as $fontKey => $fontMeta): ?>
                            <button type="button" class="font-chip<?=$PROMPT_FONT_KEY === $fontKey ? ' active' : ''?>" data-font-chip data-font="<?=h($fontKey)?>" style="font-family:<?=h($fontMeta['stack'])?>;">
                              <span class="font-chip-label"><?=h($fontMeta['label'])?></span>
                            </button>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    </div>
                  </div>

                  <input type="hidden" name="remove_guest_background" id="removeBgFlag" value="0">

                  <div class="col-md-12">
                    <label class="form-label">İletişim E-postası</label>
                    <input type="email" class="form-control" name="contact_email" value="<?=h($ev['contact_email'] ?? '')?>">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Telefon</label>
                    <input class="form-control" name="couple_phone" value="<?=h($ev['couple_phone'] ?? '')?>">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">TCKN / Vergi No</label>
                    <input class="form-control" name="couple_tckn" value="<?=h($ev['couple_tckn'] ?? '')?>">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Fatura Ünvanı</label>
                    <input class="form-control" name="invoice_title" value="<?=h($ev['invoice_title'] ?? '')?>">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">VKN</label>
                    <input class="form-control" name="invoice_vkn" value="<?=h($ev['invoice_vkn'] ?? '')?>">
                  </div>
                  <div class="col-12">
                    <label class="form-label">Fatura Adresi</label>
                    <textarea class="form-control" name="invoice_address"><?=h($ev['invoice_address'] ?? '')?></textarea>
                  </div>
                  <div class="col-12">
                    <label class="form-label">Misafir Yetkileri</label>
                    <div class="row g-2">
                      <div class="col-sm-4">
                        <div class="form-check">
                          <input class="form-check-input" type="checkbox" name="allow_guest_view" id="allow_view" <?=!empty($ev['allow_guest_view'])?'checked':''?>>
                          <label class="form-check-label" for="allow_view">Görüntülesin</label>
                        </div>
                      </div>
                      <div class="col-sm-4">
                        <div class="form-check">
                          <input class="form-check-input" type="checkbox" name="allow_guest_download" id="allow_download" <?=!empty($ev['allow_guest_download'])?'checked':''?>>
                          <label class="form-check-label" for="allow_download">İndirebilsin</label>
                        </div>
                      </div>
                      <div class="col-sm-4">
                        <div class="form-check">
                          <input class="form-check-input" type="checkbox" name="allow_guest_delete" id="allow_delete" <?=!empty($ev['allow_guest_delete'])?'checked':''?>>
                          <label class="form-check-label" for="allow_delete">Silebilsin</label>
                        </div>
                      </div>
                    </div>
                  </div>
                  <input type="hidden" id="layout_json" name="layout_json" value='<?=h($layoutJson)?>'>
                  <input type="hidden" id="stickers_json" name="stickers_json" value='<?=h($stickersJson)?>'>
                  <div class="col-12">
                    <button class="btn btn-zs" type="submit"><i class="bi bi-save me-1"></i>Ayarları Kaydet</button>
                  </div>
                </form>
              </div>
              <div class="card-lite filled p-4">
                <h2 class="card-title mb-1">Ek Paket ve Kampanyalar</h2>
                <p class="card-subtitle mb-3">Etkileşimi artırmak için özel kampanyaları seçin.</p>
                <?php if($campaigns): ?>
                  <form method="post" action="pay_addons.php" class="addon-form">
                    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                    <div class="addon-grid">
                      <?php foreach($campaigns as $c): ?>
                        <label class="addon-card">
                          <input type="checkbox" name="campaign_ids[]" value="<?=$c['id']?>">
                          <div class="addon-body">
                            <div class="addon-header">
                              <span class="addon-type">Aktif</span>
                              <span class="addon-price"><?=number_format($c['price'],0,',','.')?> TL</span>
                            </div>
                            <div>
                              <div class="addon-title"><?=h($c['title'])?></div>
                              <div class="addon-desc"><?=h($c['description'])?></div>
                            </div>
                            <div class="addon-footer">
                              <span class="addon-check"><i class="bi bi-check2-circle"></i>Seçildi</span>
                              <span class="small text-muted"><?=h($c['duration'])?></span>
                            </div>
                          </div>
                        </label>
                      <?php endforeach; ?>
                    </div>
                    <div class="mt-3 d-flex flex-wrap gap-2">
                      <button class="btn btn-zs" type="submit"><i class="bi bi-cart-plus me-1"></i>Paketleri Satın Al</button>
                      <?php if($hasInactiveCampaigns): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="#inactiveCampaigns" data-bs-toggle="collapse"><i class="bi bi-clock-history me-1"></i>Pasif kampanyalar</a>
                      <?php endif; ?>
                    </div>
                  </form>
                <?php else: ?>
                  <div class="addon-empty">Şu anda aktif kampanya bulunmuyor.</div>
                <?php endif; ?>
                <?php if($campaignsInactive): ?>
                  <div class="collapse mt-3" id="inactiveCampaigns">
                    <div class="addon-grid">
                      <?php foreach($campaignsInactive as $c): ?>
                        <div class="addon-card">
                          <div class="addon-body">
                            <div class="addon-header">
                              <span class="addon-type">Pasif</span>
                              <span class="addon-price text-muted">
                                <?php if(isset($c['price'])): ?>
                                  <?=number_format($c['price'],0,',','.')?> TL
                                <?php endif; ?>
                              </span>
                            </div>
                            <div>
                              <div class="addon-title"><?=h($c['title'])?></div>
                              <div class="addon-desc"><?=h($c['description'])?></div>
                            </div>
                            <div class="addon-footer">
                              <span class="small text-muted">Yakında yeniden aktif edilebilir.</span>
                            </div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
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

              <div class="card-lite filled p-4 preview-card">
                <div class="d-flex flex-column flex-xl-row gap-3 align-items-xl-center justify-content-between mb-3">
                  <div>
                    <h2 class="card-title mb-1">Misafir Sayfası Önizleme</h2>
                    <p class="card-subtitle mb-0">Metinleri doğrudan sahne üzerinde düzenleyin, yeni fontlar deneyin ve sticker/görsellerle kişiselleştirin.</p>
                  </div>
                  <div class="preview-hint">
                    <span class="badge rounded-pill bg-light text-dark fw-semibold"><i class="bi bi-magic me-1"></i>İpucu</span>
                    <span>Metinlerin üzerine çift tıklayarak içerik düzenleyebilirsiniz.</span>
                  </div>
                </div>
                <div class="preview-grid">
                  <div class="preview-shell">
                    <div class="preview-stage" id="pvStage">
                      <div class="stage-scale" id="scaleBox">
                        <div class="preview-canvas" id="canvas" data-has-bg="<?=$canvasHasBg?>" data-initial-bg="<?= $BACKGROUND_URL ? h($BACKGROUND_URL) : '' ?>" style="<?=$canvasStyle?>">
                          <div id="pv-title" class="pv-title pv-editable" contenteditable="true" data-field="guest_title" style="left:<?= (int)$tPos['x']?>px; top:<?= (int)$tPos['y']?>px;"><?=h($TITLE)?></div>
                          <div id="pv-sub" class="pv-sub pv-editable" contenteditable="true" data-field="guest_subtitle" style="left:<?= (int)$sPos['x']?>px; top:<?= (int)$sPos['y']?>px;"><?=h($SUBTITLE)?></div>
                          <div id="pv-prompt" class="pv-prompt pv-editable" contenteditable="true" data-field="guest_prompt" style="left:<?= (int)$pPos['x']?>px; top:<?= (int)$pPos['y']?>px;"><?=h($PROMPT)?></div>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div class="preview-controls">
                    <div class="preview-control preview-control--accent">
                      <div class="preview-control-head">
                        <span class="preview-control-title">Hızlı düzenleme</span>
                        <span class="preview-control-sub">Metinleri burada güncelleyin; değişiklikler anında önizlemeye yansır.</span>
                      </div>
                      <div class="preview-form">
                        <label class="preview-form-label" for="quickTitle">Başlık</label>
                        <input type="text" id="quickTitle" class="form-control preview-form-control" placeholder="Örn. Mutluluğumuza Ortak Olun" data-sync-field="guest_title" value="<?=h($TITLE)?>">
                        <label class="preview-form-label" for="quickSubtitle">Alt Başlık</label>
                        <textarea id="quickSubtitle" class="form-control preview-form-control" rows="2" data-sync-field="guest_subtitle" placeholder="Davet metninizi buraya yazın."><?=h($SUBTITLE)?></textarea>
                        <label class="preview-form-label" for="quickPrompt">Yükleme Mesajı</label>
                        <textarea id="quickPrompt" class="form-control preview-form-control" rows="3" data-sync-field="guest_prompt" placeholder="Misafirleriniz için açıklama ekleyin."><?=h($PROMPT)?></textarea>
                      </div>
                    </div>
                    <div class="preview-control">
                      <div class="preview-control-head">
                        <span class="preview-control-title">Tipografi</span>
                        <span class="preview-control-sub">Favori fontlarınızı seçin ve anında deneyin.</span>
                      </div>
                      <div class="preview-font-grid">
                        <div class="preview-font-field">
                          <label class="preview-form-label" for="quickFontTitle">Başlık fontu</label>
                          <select id="quickFontTitle" class="form-select preview-form-control" data-sync-font="guest_title_font">
                            <?php foreach($GUEST_FONTS as $fontKey => $fontMeta): ?>
                              <option value="<?=h($fontKey)?>" <?=$TITLE_FONT_KEY === $fontKey ? 'selected' : ''?>><?=h($fontMeta['label'])?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="preview-font-field">
                          <label class="preview-form-label" for="quickFontSub">Alt başlık fontu</label>
                          <select id="quickFontSub" class="form-select preview-form-control" data-sync-font="guest_subtitle_font">
                            <?php foreach($GUEST_FONTS as $fontKey => $fontMeta): ?>
                              <option value="<?=h($fontKey)?>" <?=$SUBTITLE_FONT_KEY === $fontKey ? 'selected' : ''?>><?=h($fontMeta['label'])?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="preview-font-field">
                          <label class="preview-form-label" for="quickFontPrompt">Mesaj fontu</label>
                          <select id="quickFontPrompt" class="form-select preview-form-control" data-sync-font="guest_prompt_font">
                            <?php foreach($GUEST_FONTS as $fontKey => $fontMeta): ?>
                              <option value="<?=h($fontKey)?>" <?=$PROMPT_FONT_KEY === $fontKey ? 'selected' : ''?>><?=h($fontMeta['label'])?></option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                      </div>
                    </div>
                    <div class="preview-control">
                      <div class="preview-control-head">
                        <span class="preview-control-title">Arka plan ve vitrin</span>
                        <span class="preview-control-sub">Etkinlik temanızla uyumlu görsel ve renkleri seçin.</span>
                      </div>
                      <div class="preview-bg-grid">
                        <div class="bg-preview<?= $BACKGROUND_URL ? ' has-image' : ''?>" id="bgPreview"<?=$bgPreviewStyle ? ' style="'.$bgPreviewStyle.'"' : ''?>>
                          <span id="bgPreviewLabel"><?= $BACKGROUND_URL ? 'Yüklenen görsel' : 'Varsayılan' ?></span>
                        </div>
                        <div class="preview-bg-actions">
                          <input class="d-none" type="file" name="guest_background" id="guestBackground" accept="image/*" form="settingsForm">
                          <div class="d-flex flex-wrap gap-2">
                            <button class="btn btn-sm btn-zs" type="button" data-trigger-upload="guestBackground"><i class="bi bi-upload me-1"></i>Yeni görsel ekle</button>
                            <button class="btn btn-sm btn-outline-danger" type="button" id="removeBgBtn" <?=$BACKGROUND_URL ? '' : 'disabled'?>>
                              <i class="bi bi-trash me-1"></i>Varsayılanı kullan
                            </button>
                          </div>
                          <p class="form-text mb-0">JPG, PNG veya WEBP görselleri yükleyebilirsiniz. Minimum 1920×1080 önerilir.</p>
                        </div>
                      </div>
                    </div>
                    <div class="preview-control">
                      <div class="preview-control-head">
                        <span class="preview-control-title">Sahne kısayolları</span>
                        <span class="preview-control-sub">Metinlerin üzerine çift tıklayın veya aşağıdaki butonlarla odaklanın.</span>
                      </div>
                      <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-focus-preview="pv-title">Başlığı seç</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-focus-preview="pv-sub">Alt başlığı seç</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-focus-preview="pv-prompt">Mesajı seç</button>
                      </div>
                    </div>
                    <div class="preview-control">
                      <div class="preview-control-head">
                        <span class="preview-control-title">Sticker & görsel boyutu</span>
                        <span class="preview-control-sub">Bir öğeye tıkladığınızda buradan boyutunu değiştirebilirsiniz.</span>
                      </div>
                      <div class="sticker-size-tool">
                        <input type="range" class="form-range" id="stickerSizeRange" min="20" max="200" step="2" disabled>
                        <div class="small text-muted" id="stickerSizeValue">Bir sticker seçin</div>
                      </div>
                      <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm btn-outline-danger" id="deleteStickerBtn" disabled><i class="bi bi-trash me-1"></i>Seçili öğeyi sil</button>
                        <label class="btn btn-sm btn-zs-outline mb-0">
                          <i class="bi bi-image me-1"></i>Özel görsel yükle
                          <input type="file" name="sticker_image" accept="image/*" class="d-none" form="settingsForm">
                        </label>
                      </div>
                      <p class="form-text mb-0">PNG, JPG, WEBP, GIF veya SVG yükleyebilirsiniz. Kaydettikten sonra misafir sayfasına yansır.</p>
                    </div>
                  </div>
                </div>
                <p class="small text-muted mt-3 mb-0">Not: Bu önizleme gerçek misafir sayfasının birebir yansımasıdır.</p>
              </div>

              <div class="card-lite filled p-4">
                <h2 class="card-title mb-1">Sticker ve Simgeler</h2>
                <p class="card-subtitle mb-3">Misafir sayfanızı renklendirmek için aşağıdan simge ekleyin; seçtiğiniz öğeleri sağdaki araçtan boyutlandırabilirsiniz.</p>
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
      </section>
    </div>
  </main>
</div>
</body>
<script>
(function(){
  const stage=document.getElementById('pvStage'), box=document.getElementById('scaleBox');
  function fit(){ if(!stage||!box) return; const W=stage.clientWidth, S=W/960; box.style.setProperty('--s',S); stage.style.height=(540*S)+'px'; }
  window.addEventListener('resize',fit,{passive:true}); new ResizeObserver(fit).observe(stage); fit();
})();

const fontMap = <?=safe_json_encode($fontConfigForJs)?>;
const loadedFontImports = new Set(<?=safe_json_encode($FONT_IMPORTS)?>);
const headEl = document.head || document.getElementsByTagName('head')[0];
const canvas = document.getElementById('canvas');
const bgInput = document.getElementById('guestBackground');
const bgPreview = document.getElementById('bgPreview');
const bgPreviewLabel = document.getElementById('bgPreviewLabel');
const removeBgBtn = document.getElementById('removeBgBtn');
const removeBgFlag = document.getElementById('removeBgFlag');
const layoutField = document.getElementById('layout_json');
const stickersField = document.getElementById('stickers_json');
const stickerSizeRange = document.getElementById('stickerSizeRange');
const stickerSizeValue = document.getElementById('stickerSizeValue');
const deleteStickerBtn = document.getElementById('deleteStickerBtn');
const stickerAssetMap = <?=safe_json_encode($existingStickerMap)?>;
let stickerState = <?=(string)$stickersJson?>;
try { stickerState = Array.isArray(stickerState) ? stickerState : JSON.parse(stickerState || '[]'); }
catch(e){ stickerState = []; }
let activeStickerIndex = null;

function ensureFontLoaded(key){
  const cfg = fontMap[key];
  if (!cfg) return;
  const imp = cfg.import || '';
  if (!imp || loadedFontImports.has(imp)) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = 'https://fonts.googleapis.com/css2?family='+imp+'&display=swap';
  link.dataset.fontImport = imp;
  headEl && headEl.appendChild(link);
  loadedFontImports.add(imp);
}

function markFontChipActive(inputName, key){
  if (!inputName) return;
  document.querySelectorAll(`[data-font-input="${inputName}"] .font-chip`).forEach((btn)=>{
    if (btn.dataset.font === key) {
      btn.classList.add('active');
    } else {
      btn.classList.remove('active');
    }
  });
}

function updateFont(select){
  const key = select.value;
  const cfg = fontMap[key];
  if (!cfg) return;
  ensureFontLoaded(key);
  const cssVar = select.dataset.cssVar;
  const target = select.dataset.previewTarget;
  if (cssVar) {
    document.documentElement.style.setProperty(cssVar, cfg.stack);
  }
  if (target) {
    const el = document.getElementById(target);
    if (el) {
      el.style.fontFamily = cfg.stack;
    }
  }
  const inputName = select.getAttribute('name');
  markFontChipActive(inputName, key);
}

document.querySelectorAll('[data-font-select]').forEach((select)=>{
  select.addEventListener('change', ()=>{ updateFont(select); saveHidden(); });
  updateFont(select);
});

document.querySelectorAll('[data-font-chip]').forEach((chip)=>{
  chip.addEventListener('click', ()=>{
    const wrapper = chip.closest('[data-font-input]');
    if (!wrapper) return;
    const inputName = wrapper.dataset.fontInput;
    if (!inputName) return;
    const select = document.querySelector(`select[name="${inputName}"]`);
    if (!select) return;
    select.value = chip.dataset.font;
    updateFont(select);
    saveHidden();
  });
});

const quickTextInputs = {};
document.querySelectorAll('[data-sync-field]').forEach((input)=>{
  const field = input.dataset.syncField;
  if (!field) return;
  quickTextInputs[field] = input;
  const formInput = document.querySelector(`[name=${field}]`);
  const preview = document.querySelector(`[data-field=${field}]`);
  if (formInput) {
    input.value = formInput.value || '';
    input.addEventListener('input', ()=>{
      formInput.value = input.value;
      if (preview) preview.textContent = input.value;
      saveHidden();
    });
    formInput.addEventListener('input', ()=>{
      if (document.activeElement === input) return;
      input.value = formInput.value || '';
    });
  }
});

function styleQuickSelectOptions(select){
  Array.from(select.options || []).forEach((opt)=>{
    const cfg = fontMap[opt.value];
    if (cfg) {
      opt.style.fontFamily = cfg.stack;
      opt.dataset.fontStack = cfg.stack;
    }
  });
}

document.querySelectorAll('[data-sync-font]').forEach((select)=>{
  styleQuickSelectOptions(select);
  const targetName = select.dataset.syncFont;
  if (!targetName) return;
  const formSelect = document.querySelector(`select[name=${targetName}]`);
  if (!formSelect) return;
  select.value = formSelect.value;
  select.addEventListener('change', ()=>{
    formSelect.value = select.value;
    formSelect.dispatchEvent(new Event('change', { bubbles:true }));
  });
  formSelect.addEventListener('change', ()=>{
    if (select.value !== formSelect.value) {
      select.value = formSelect.value;
    }
  });
});

document.querySelectorAll('[data-trigger-upload]').forEach((btn)=>{
  btn.addEventListener('click', ()=>{
    const target = btn.dataset.triggerUpload;
    if (!target) return;
    const input = document.getElementById(target);
    if (input) input.click();
  });
});

function setCanvasBackground(url, skipFlagUpdate){
  if (!canvas) return;
  if (url) {
    canvas.style.backgroundImage = `url(${url.replace(/"/g,'\"')})`;
    canvas.dataset.hasBg = '1';
    if (bgPreview) {
      bgPreview.style.backgroundImage = `url(${url})`;
      bgPreview.classList.add('has-image');
    }
    if (bgPreviewLabel) {
      bgPreviewLabel.textContent = 'Yüklenen görsel';
    }
    if (removeBgBtn) removeBgBtn.disabled = false;
    if (!skipFlagUpdate && removeBgFlag) removeBgFlag.value = '0';
  } else {
    canvas.style.backgroundImage = '';
    canvas.dataset.hasBg = '0';
    if (bgPreview) {
      bgPreview.style.backgroundImage = '';
      bgPreview.classList.remove('has-image');
    }
    if (bgPreviewLabel) {
      bgPreviewLabel.textContent = 'Varsayılan';
    }
    if (removeBgBtn) removeBgBtn.disabled = true;
    if (!skipFlagUpdate && removeBgFlag) removeBgFlag.value = '1';
  }
}

if (bgInput) {
  bgInput.addEventListener('change', ()=>{
    const file = bgInput.files && bgInput.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = (evt)=>{
      const url = evt && evt.target ? evt.target.result : '';
      setCanvasBackground(url || '', false);
    };
    reader.readAsDataURL(file);
    if (removeBgFlag) removeBgFlag.value = '0';
  });
}

if (removeBgBtn) {
  removeBgBtn.addEventListener('click', ()=>{
    if (!confirm('Arka plan görselini kaldırmak istediğinize emin misiniz?')) return;
    if (bgInput) bgInput.value = '';
    if (removeBgFlag) removeBgFlag.value = '1';
    setCanvasBackground('', false);
  });
}

if (canvas) {
  if (canvas.dataset.hasBg === '1' && canvas.dataset.initialBg) {
    setCanvasBackground(canvas.dataset.initialBg, true);
  } else if (canvas.dataset.hasBg !== '1') {
    setCanvasBackground('', true);
  }
}

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
  if(canvas){ canvas.style.setProperty('--zs',p); canvas.style.setProperty('--zs-soft',a); }
}

const textBindings = [
  { field:'guest_title', previewId:'pv-title' },
  { field:'guest_subtitle', previewId:'pv-sub' },
  { field:'guest_prompt', previewId:'pv-prompt' },
];
textBindings.forEach(({field, previewId})=>{
  const input = document.querySelector(`[name=${field}]`);
  const preview = document.getElementById(previewId);
  if (!preview) return;
  if (input) {
    input.addEventListener('input', ()=>{
      preview.textContent = input.value || '';
      const quick = quickTextInputs[field];
      if (quick && document.activeElement !== quick) {
        quick.value = input.value || '';
      }
      saveHidden();
    });
  }
  preview.addEventListener('keydown', (evt)=>{
    if (evt.key === 'Enter') { evt.preventDefault(); preview.blur(); }
  });
  preview.addEventListener('input', ()=>{
    const value = preview.textContent.replace(/\s+/g,' ').trim();
    if (input) input.value = value;
    const quick = quickTextInputs[field];
    if (quick && document.activeElement !== quick) {
      quick.value = value;
    }
    saveHidden();
  });
  preview.addEventListener('blur', ()=>{
    const value = preview.textContent.replace(/\s+/g,' ').trim();
    preview.textContent = value;
    if (input) input.value = value;
    const quick = quickTextInputs[field];
    if (quick && document.activeElement !== quick) {
      quick.value = value;
    }
    saveHidden();
  });
});

document.querySelectorAll('[data-focus-preview]').forEach((btn)=>{
  btn.addEventListener('click', ()=>{
    const id = btn.dataset.focusPreview;
    const target = document.getElementById(id);
    if (!target) return;
    const range = document.createRange();
    range.selectNodeContents(target);
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
    target.focus();
  });
});

const titleEl=document.getElementById('pv-title');
const subEl  =document.getElementById('pv-sub');
const prEl   =document.getElementById('pv-prompt');

let layout = {};
try { layout = layoutField && layoutField.value ? JSON.parse(layoutField.value) : {}; }
catch(e){ layout = {}; }

function saveHidden(){
  if (titleEl && subEl && prEl && layoutField) {
    layout = {
      title   : {x:parseInt(titleEl.style.left)||24, y:parseInt(titleEl.style.top)||24},
      subtitle: {x:parseInt(subEl.style.left)||24,   y:parseInt(subEl.style.top)||60},
      prompt  : {x:parseInt(prEl.style.left)||24,    y:parseInt(prEl.style.top)||396}
    };
    layoutField.value = JSON.stringify(layout);
  }
  if (stickersField) {
    stickersField.value = JSON.stringify(stickerState);
  }
}

function makeTextDraggable(el){
  if (!el) return;
  let ox=0, oy=0, dragging=false;
  el.style.cursor='move';
  el.addEventListener('mousedown',e=>{ dragging=true; ox=e.offsetX; oy=e.offsetY; el.style.cursor='grabbing'; });
  window.addEventListener('mousemove',e=>{
    if(!dragging) return;
    const rect=canvas.getBoundingClientRect();
    let x=e.clientX-rect.left-ox, y=e.clientY-rect.top-oy;
    x=Math.max(0,Math.min(940,x)); y=Math.max(0,Math.min(520,y));
    el.style.left=x+'px'; el.style.top=y+'px';
  });
  window.addEventListener('mouseup',()=>{ if(!dragging) return; dragging=false; el.style.cursor='move'; saveHidden(); });
  el.addEventListener('touchstart',(e)=>{
    const touch=e.touches[0]; if(!touch) return; dragging=true; const rect=el.getBoundingClientRect(); ox=touch.clientX-rect.left; oy=touch.clientY-rect.top; el.style.cursor='grabbing';
  },{passive:false});
  window.addEventListener('touchmove',(e)=>{
    if(!dragging) return; const touch=e.touches[0]; if(!touch) return; const rect=canvas.getBoundingClientRect(); let x=touch.clientX-rect.left-ox, y=touch.clientY-rect.top-oy; x=Math.max(0,Math.min(940,x)); y=Math.max(0,Math.min(520,y)); el.style.left=x+'px'; el.style.top=y+'px';
  },{passive:false});
  window.addEventListener('touchend',()=>{ if(!dragging) return; dragging=false; el.style.cursor='move'; saveHidden(); });
}
[titleEl,subEl,prEl].forEach(makeTextDraggable);

function createStickerNode(st, idx){
  const type = st && st.type === 'image' ? 'image' : 'emoji';
  const node = document.createElement('div');
  node.className = type === 'image' ? 'sticker sticker-img' : 'sticker';
  node.dataset.index = idx;
  node.dataset.type = type;
  const left = st && typeof st.x === 'number' ? st.x : 20;
  const top = st && typeof st.y === 'number' ? st.y : 90;
  node.style.left = left + 'px';
  node.style.top  = top + 'px';
  node.addEventListener('click',(evt)=>{ evt.preventDefault(); evt.stopPropagation(); setActiveSticker(idx); });
  if (type === 'image') {
    const path = st && st.path ? st.path : '';
    const width = st && st.width ? st.width : 220;
    node.dataset.path = path;
    node.dataset.width = width;
    const src = stickerAssetMap && path ? (stickerAssetMap[path] || '') : '';
    if (src) {
      const img = document.createElement('img');
      img.src = src;
      img.alt = '';
      img.draggable = false;
      img.style.width = width + 'px';
      node.appendChild(img);
    }
  } else {
    const txt = st && st.txt ? st.txt : '💍';
    const size = st && st.size ? st.size : 32;
    node.dataset.size = size;
    node.dataset.text = txt;
    node.style.fontSize = size + 'px';
    node.textContent = txt;
  }
  makeStickerDraggable(node);
  return node;
}

function makeStickerDraggable(el){
  if (!el) return;
  el.style.touchAction = 'none';
  let pointerId = null;
  let ox = 0, oy = 0;
  el.addEventListener('pointerdown',(evt)=>{
    evt.preventDefault();
    pointerId = evt.pointerId;
    ox = evt.offsetX;
    oy = evt.offsetY;
    el.setPointerCapture(pointerId);
    const idx = parseInt(el.dataset.index, 10);
    if (!Number.isNaN(idx)) {
      setActiveSticker(idx);
    }
  });
  el.addEventListener('pointermove',(evt)=>{
    if (pointerId === null) return;
    const rect = canvas.getBoundingClientRect();
    let x = evt.clientX - rect.left - ox;
    let y = evt.clientY - rect.top - oy;
    x = Math.max(0, Math.min(940, x));
    y = Math.max(0, Math.min(520, y));
    el.style.left = x + 'px';
    el.style.top  = y + 'px';
    const idx = parseInt(el.dataset.index, 10);
    if (!Number.isNaN(idx) && stickerState[idx]) {
      stickerState[idx].x = x;
      stickerState[idx].y = y;
    }
  });
  const finishDrag = ()=>{
    if (pointerId === null) return;
    el.releasePointerCapture(pointerId);
    pointerId = null;
    saveHidden();
  };
  el.addEventListener('pointerup', finishDrag);
  el.addEventListener('pointercancel', finishDrag);
}

function setActiveSticker(idx){
  if (canvas) {
    canvas.querySelectorAll('.sticker').forEach((node)=>node.classList.remove('is-active'));
  }
  if (typeof idx !== 'number' || !stickerState[idx]) {
    activeStickerIndex = null;
    if (stickerSizeRange) {
      stickerSizeRange.disabled = true;
      stickerSizeRange.value = stickerSizeRange.min || 20;
    }
    if (stickerSizeValue) stickerSizeValue.textContent = 'Bir sticker seçin';
    if (deleteStickerBtn) deleteStickerBtn.disabled = true;
    return;
  }
  activeStickerIndex = idx;
  if (canvas) {
    const node = canvas.querySelector(`.sticker[data-index="${idx}"]`);
    if (node) node.classList.add('is-active');
  }
  const st = stickerState[idx];
  if (!stickerSizeRange || !stickerSizeValue) return;
  if (st.type === 'image') {
    stickerSizeRange.min = 80;
    stickerSizeRange.max = 520;
    stickerSizeRange.step = 4;
    stickerSizeRange.value = st.width || 220;
    stickerSizeValue.textContent = `Genişlik: ${stickerSizeRange.value}px`;
  } else {
    stickerSizeRange.min = 20;
    stickerSizeRange.max = 160;
    stickerSizeRange.step = 2;
    stickerSizeRange.value = st.size || 32;
    stickerSizeValue.textContent = `Boyut: ${stickerSizeRange.value}px`;
  }
  stickerSizeRange.disabled = false;
  if (deleteStickerBtn) deleteStickerBtn.disabled = false;
}

function renderStickers(){
  if (!canvas) return;
  canvas.querySelectorAll('.sticker').forEach((node)=>node.remove());
  stickerState.forEach((st, idx)=>{
    const node = createStickerNode(st, idx);
    if (node) canvas.appendChild(node);
  });
  if (activeStickerIndex !== null && !stickerState[activeStickerIndex]) {
    activeStickerIndex = null;
  }
  if (activeStickerIndex !== null) {
    setActiveSticker(activeStickerIndex);
  } else {
    setActiveSticker(null);
  }
}

if (stickerSizeRange) {
  stickerSizeRange.addEventListener('input', ()=>{
    if (activeStickerIndex === null) return;
    const st = stickerState[activeStickerIndex];
    if (!st) return;
    const value = parseInt(stickerSizeRange.value, 10) || 0;
    if (st.type === 'image') {
      st.width = value;
      const node = canvas && canvas.querySelector(`.sticker[data-index="${activeStickerIndex}"] img`);
      if (node) node.style.width = value + 'px';
      stickerSizeValue.textContent = `Genişlik: ${value}px`;
    } else {
      st.size = value;
      const node = canvas && canvas.querySelector(`.sticker[data-index="${activeStickerIndex}"]`);
      if (node) node.style.fontSize = value + 'px';
      stickerSizeValue.textContent = `Boyut: ${value}px`;
    }
    saveHidden();
  });
}

if (deleteStickerBtn) {
  deleteStickerBtn.addEventListener('click', ()=>{
    if (activeStickerIndex === null) return;
    stickerState.splice(activeStickerIndex, 1);
    activeStickerIndex = null;
    renderStickers();
    saveHidden();
  });
}

document.querySelectorAll('.add-sticker').forEach((btn)=>{
  btn.addEventListener('click',(e)=>{
    e.preventDefault();
    const emoji = btn.dataset.emoji || '💍';
    stickerState.push({ type:'emoji', txt:emoji, x:20, y:90, size:32 });
    renderStickers();
    setActiveSticker(stickerState.length - 1);
    saveHidden();
  });
});

const clearBtn = document.getElementById('clearStickers');
if (clearBtn) {
  clearBtn.addEventListener('click',(e)=>{
    e.preventDefault();
    stickerState = [];
    activeStickerIndex = null;
    renderStickers();
    saveHidden();
  });
}

const resetBtn = document.getElementById('resetLayout');
if (resetBtn) {
  resetBtn.addEventListener('click',(e)=>{
    e.preventDefault();
    if (titleEl) { titleEl.style.left='24px'; titleEl.style.top='24px'; }
    if (subEl) { subEl.style.left='24px'; subEl.style.top='60px'; }
    if (prEl)  { prEl.style.left='24px'; prEl.style.top='396px'; }
    saveHidden();
  });
}

renderStickers();
</script>
</body>
</html>
