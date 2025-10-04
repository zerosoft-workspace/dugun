<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/dealers.php';
require_once __DIR__.'/../includes/guests.php';

if (!function_exists('csrf_token')) {
  function csrf_token(){ if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
}
if (!function_exists('csrf_or_die')) {
  function csrf_or_die(){ $t=$_POST['csrf']??$_POST['_csrf']??''; if(!$t || !hash_equals($_SESSION['csrf']??'', $t)){ http_response_code(400); exit('CSRF'); } }
}

function client_ip(){
  if(isset($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
  if(isset($_SERVER['HTTP_X_FORWARDED_FOR'])) return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
  return $_SERVER['REMOTE_ADDR']??'0.0.0.0';
}
function is_image_mime($m){ return (bool)preg_match('~^image/(jpeg|png|webp|gif)$~i',$m); }
function is_video_mime($m){ return (bool)preg_match('~^video/(mp4|quicktime|webm)$~i',$m); }
function relative_time($dt){
  if(!$dt) return '';
  $ts = is_numeric($dt) ? (int)$dt : strtotime($dt);
  if(!$ts) return '';
  $diff = time() - $ts;
  if($diff < 60) return 'az önce';
  if($diff < 3600) return floor($diff/60).' dk önce';
  if($diff < 86400) return floor($diff/3600).' sa önce';
  if($diff < 172800) return 'dün';
  return date('d.m.Y H:i', $ts);
}
function avatar_colors(string $seed): array {
  $palette = ['#0ea5b5','#6366f1','#f97316','#ec4899','#14b8a6','#9333ea','#ef4444','#22d3ee'];
  $text = ['#fff','#fff','#fff','#fff','#073042','#fff','#fff','#0f172a'];
  $idx = hexdec(substr($seed,0,2)) % count($palette);
  return [$palette[$idx], $text[$idx] ?? '#fff'];
}
function avatar_initial(string $name): string {
  $name = trim($name);
  if($name==='') return 'M';
  return mb_strtoupper(mb_substr($name,0,1,'UTF-8'),'UTF-8');
}

$event_id = (int)($_GET['event'] ?? 0);
$token    = trim($_GET['t'] ?? '');
if ($event_id <= 0){ http_response_code(400); exit('Geçersiz istek'); }

$st = pdo()->prepare("SELECT * FROM events WHERE id=? LIMIT 1");
$st->execute([$event_id]);
$ev = $st->fetch();
if (!$ev || (int)$ev['is_active']!==1){ http_response_code(404); exit('Etkinlik bulunamadı veya pasif.'); }

$VID      = (int)$ev['venue_id'];
$TITLE    = $ev['guest_title'] ?: 'BİKARE Etkinliğimize Hoş Geldiniz';
$SUBTITLE = $ev['guest_subtitle'] ?: 'En güzel anlarınızı bizimle paylaşın';
$PROMPT   = $ev['guest_prompt'] ?: 'Adınızı yazıp anınızı yükleyin.';
$PRIMARY  = $ev['theme_primary'] ?: '#0ea5b5';
$ACCENT   = $ev['theme_accent']  ?: '#e0f7fb';
$CAN_VIEW = (int)$ev['allow_guest_view']===1;
$CAN_DOWN = (int)$ev['allow_guest_download']===1;

$layout   = $ev['layout_json'] ?: '{"title":{"x":24,"y":24},"subtitle":{"x":24,"y":60},"prompt":{"x":24,"y":396}}';
$stickers = $ev['stickers_json'] ?: '[]';
$layoutArr   = json_decode($layout,true);
$stickersArr = json_decode($stickers,true);
if(!is_array($layoutArr)){
  $layoutArr = array('title'=>array('x'=>24,'y'=>24),'subtitle'=>array('x'=>24,'y'=>60),'prompt'=>array('x'=>24,'y'=>396));
}
if(!is_array($stickersArr)){ $stickersArr = array(); }
$tPos = isset($layoutArr['title'])    ? $layoutArr['title']    : array('x'=>24,'y'=>24);
$sPos = isset($layoutArr['subtitle']) ? $layoutArr['subtitle'] : array('x'=>24,'y'=>60);
$pPos = isset($layoutArr['prompt'])   ? $layoutArr['prompt']   : array('x'=>24,'y'=>396);

$token_ok = token_valid($event_id, $token);
$guestProfile = guest_profile_current($event_id);

if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_or_die();
  $action = $_POST['do'] ?? '';
  $redirect = BASE_URL.'/public/upload.php?event='.$event_id.'&t='.rawurlencode($token);
  if($action === 'upload'){
    $errors=[]; $okCount=0; $verificationFlash=null;
    $p_token = trim($_POST['t']??'');
    if(!token_valid($event_id,$p_token)){
      $errors[]='Güvenlik anahtarı zaman aşımına uğradı. Lütfen QR’ı yeniden okutun.';
    }else{
      $guest = trim($_POST['guest_name']??'');
      $guestEmail = trim($_POST['guest_email']??'');
      if($guest===''){ $errors[]='Lütfen adınızı yazın.'; }
      if($guestEmail!==''){ if(!filter_var($guestEmail, FILTER_VALIDATE_EMAIL)){ $errors[]='Geçerli bir e-posta adresi yazın.'; } }
      $marketingOpt = isset($_POST['marketing_opt_in']);
      $profileForUpload = $guestProfile;
      if($guestEmail!=='' && !$errors){
        try {
          $profileForUpload = guest_profile_upsert($event_id, $guest, $guestEmail, $marketingOpt);
          if($profileForUpload){
            if((int)$profileForUpload['is_verified']===1){
              guest_profile_set_session($event_id, (int)$profileForUpload['id']);
              $guestProfile = $profileForUpload;
            }else{
              $lastSent = $profileForUpload['last_verification_sent_at'] ?? null;
              if(!$lastSent || strtotime($lastSent) < time()-600){
                guest_profile_send_verification($profileForUpload, $ev);
                $verificationFlash = 'Profilinizi doğrulayabilmeniz için e-posta gönderildi.';
              }
            }
          }
        } catch(Throwable $e){
          $errors[]='Profil oluşturulurken bir hata oluştu: '.$e->getMessage();
        }
      }
      if(!isset($_FILES['files'])){ $errors[]='Dosya seçilmedi.'; }
      else{
        $f=$_FILES['files'];
        $n=is_array($f['name'])?count($f['name']):0;
        if($n<=0) $errors[]='Dosya seçilmedi.';
        else{
          $dir=ensure_upload_dir($VID,$event_id);
          $profileId = $profileForUpload['id'] ?? ($guestProfile['id'] ?? null);
          $profileEmail = $profileForUpload['email'] ?? ($guestProfile['email'] ?? null);
          for($i=0;$i<$n;$i++){
            if($f['error'][$i]!==UPLOAD_ERR_OK){ $errors[]='Yükleme hatası (kod '.$f['error'][$i].')'; continue; }
            $tmp=$f['tmp_name'][$i]; $nm=$f['name'][$i]; $sz=(int)$f['size'][$i];
            if($sz<=0 || $sz>MAX_UPLOAD_BYTES){ $errors[]=h($nm).': limit '.round(MAX_UPLOAD_BYTES/1048576).' MB'; continue; }
            $fi=finfo_open(FILEINFO_MIME_TYPE); $mime=finfo_file($fi,$tmp); finfo_close($fi);
            if(!isset(ALLOWED_MIMES[$mime])){ $errors[]=h($nm).': desteklenmeyen tür ('.$mime.')'; continue; }
            $ext=ALLOWED_MIMES[$mime];
            $base=preg_replace('~[^a-zA-Z0-9-_]+~','_', pathinfo($nm,PATHINFO_FILENAME)); if($base==='') $base='file';
            $final=$base.'_'.date('Ymd_His').'_'.$i.'_'.bin2hex(random_bytes(3)).'.'.$ext;
            $dest=$dir.'/'.$final;
            if(!move_uploaded_file($tmp,$dest)){ $errors[]=h($nm).': taşınamadı'; continue; }
            $rel='uploads/v'.$VID.'/'.$event_id.'/'.$final;
            pdo()->prepare("INSERT INTO uploads (venue_id,event_id,guest_name,profile_id,guest_email,file_path,mime,file_size,ip,created_at)
                            VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$VID,$event_id,$guest,$profileId,$profileEmail,$rel,$mime,$sz,client_ip(),now()]);
            $okCount++;
          }
        }
      }
    }
    if ($okCount > 0 && !empty($ev['dealer_id'])) {
      $pdo = pdo();
      $ownTxn = !$pdo->inTransaction();
      if ($ownTxn) { $pdo->beginTransaction(); }
      try {
        $lock = $pdo->prepare("SELECT dealer_id, dealer_credit_consumed_at FROM events WHERE id=? FOR UPDATE");
        $lock->execute([$event_id]);
        if ($row = $lock->fetch()) {
          $dealerId = (int)$row['dealer_id'];
          $consumedAt = $row['dealer_credit_consumed_at'];
          if ($dealerId > 0 && empty($consumedAt)) {
            dealer_consume_event_credit($dealerId, $event_id);
            $stamp = now();
            $pdo->prepare("UPDATE events SET dealer_credit_consumed_at=?, updated_at=? WHERE id=?")
                ->execute([$stamp, $stamp, $event_id]);
            $ev['dealer_credit_consumed_at'] = $stamp;
          }
        }
        if ($ownTxn) { $pdo->commit(); }
      } catch (Throwable $consumeErr) {
        if ($ownTxn && $pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('Dealer credit consumption failed for event '.$event_id.': '.$consumeErr->getMessage());
      }
    }
    if($okCount>0){
      $msg = $okCount.' dosya yüklendi. Teşekkürler!';
      if($verificationFlash) $msg .= '<br>'.$verificationFlash;
      flash('ok',$msg);
      header('Location:'.$redirect); exit;
    }
    if($errors){ flash('err',implode('<br>',array_map('h',$errors))); header('Location:'.$redirect); exit; }
    header('Location:'.$redirect); exit;
  }
  if($action === 'like' || $action === 'unlike'){
    $uploadId = (int)($_POST['upload_id'] ?? 0);
    if(!$guestProfile || (int)$guestProfile['is_verified']!==1){
      flash('err','Beğenmek için e-postanızı doğrulayın.');
      header('Location:'.$redirect); exit;
    }
    $st = pdo()->prepare('SELECT id FROM uploads WHERE id=? AND event_id=? LIMIT 1');
    $st->execute([$uploadId,$event_id]);
    if(!$st->fetch()){ flash('err','Kayıt bulunamadı.'); header('Location:'.$redirect); exit; }
    if($action==='like'){ guest_upload_like($uploadId, (int)$guestProfile['id']); }
    else{ guest_upload_unlike($uploadId, (int)$guestProfile['id']); }
    guest_profile_touch((int)$guestProfile['id']);
    header('Location:'.$redirect.'#media-'.$uploadId); exit;
  }
  if($action === 'comment'){
    $uploadId = (int)($_POST['upload_id'] ?? 0);
    $body = trim($_POST['comment_body'] ?? '');
    if(!$guestProfile || (int)$guestProfile['is_verified']!==1){
      flash('err','Yorum yapmak için e-postanızı doğrulayın.');
      header('Location:'.$redirect); exit;
    }
    $st = pdo()->prepare('SELECT id FROM uploads WHERE id=? AND event_id=? LIMIT 1');
    $st->execute([$uploadId,$event_id]);
    if(!$st->fetch()){ flash('err','Kayıt bulunamadı.'); header('Location:'.$redirect); exit; }
    if($body===''){ flash('err','Yorum metni boş olamaz.'); header('Location:'.$redirect.'#media-'.$uploadId); exit; }
    guest_upload_comment_add($uploadId, $guestProfile, $body);
    guest_profile_touch((int)$guestProfile['id']);
    flash('ok','Yorumunuz paylaşıldı.');
    header('Location:'.$redirect.'#media-'.$uploadId); exit;
  }
  if($action === 'chat'){
    if(!$guestProfile || (int)$guestProfile['is_verified']!==1){
      flash('err','Sohbete katılmak için e-postanızı doğrulayın.');
      header('Location:'.$redirect.'#chat'); exit;
    }
    $message = trim($_POST['chat_message'] ?? '');
    if($message===''){ flash('err','Mesaj boş olamaz.'); header('Location:'.$redirect.'#chat'); exit; }
    guest_chat_add_message($event_id, $guestProfile, $message, null);
    flash('ok','Mesajın paylaşıldı.');
    header('Location:'.$redirect.'#chat'); exit;
  }
  if($action === 'profile'){
    if(!$guestProfile){ flash('err','Profilinizi düzenlemek için önce e-posta adresinizle kayıt olun.'); header('Location:'.$redirect.'#profile'); exit; }
    $display = trim($_POST['display_name'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $avatar = trim($_POST['avatar_token'] ?? '');
    $marketing = isset($_POST['profile_marketing']);
    guest_profile_update((int)$guestProfile['id'], $display, $bio, $avatar, $marketing);
    flash('ok','Profiliniz güncellendi.');
    header('Location:'.$redirect.'#profile'); exit;
  }
  if($action === 'resend_verification'){
    if(!$guestProfile){ flash('err','Önce e-posta adresinizle bir içerik yükleyin.'); header('Location:'.$redirect); exit; }
    if((int)$guestProfile['is_verified']===1){ flash('ok','Profiliniz zaten doğrulandı.'); header('Location:'.$redirect); exit; }
    $lastSent = $guestProfile['last_verification_sent_at'] ?? null;
    if($lastSent && strtotime($lastSent) > time()-300){
      flash('err','Yeni bir bağlantı göndermeden önce birkaç dakika bekleyin.');
      header('Location:'.$redirect); exit;
    }
    guest_profile_send_verification($guestProfile, $ev);
    flash('ok','Doğrulama bağlantısı e-posta adresinize gönderildi.');
    header('Location:'.$redirect); exit;
  }
}

$guestProfile = guest_profile_current($event_id);
$profileVerified = $guestProfile && (int)$guestProfile['is_verified']===1;

$uploads=[]; $uploadLikes=[]; $uploadLikedByMe=[]; $uploadComments=[]; $commentCounts=[];
if($CAN_VIEW){
  $st=pdo()->prepare("SELECT u.id,u.guest_name,u.profile_id,u.guest_email,u.file_path,u.mime,u.file_size,u.created_at,
                             gp.display_name AS profile_display_name,gp.avatar_token AS profile_avatar_token,gp.is_verified AS profile_verified
                      FROM uploads u
                      LEFT JOIN guest_profiles gp ON gp.id=u.profile_id
                      WHERE u.venue_id=? AND u.event_id=?
                      ORDER BY u.id DESC LIMIT 300");
  $st->execute([$VID,$event_id]);
  $uploads=$st->fetchAll();
  $uploadIds = array_column($uploads,'id');
  if($uploadIds){
    $placeholders = implode(',', array_fill(0,count($uploadIds),'?'));
    $st=pdo()->prepare("SELECT upload_id, COUNT(*) AS cnt FROM guest_upload_likes WHERE upload_id IN ($placeholders) GROUP BY upload_id");
    $st->execute($uploadIds);
    foreach($st->fetchAll() as $row){ $uploadLikes[(int)$row['upload_id']] = (int)$row['cnt']; }
    if($profileVerified){
      $params = array_merge([(int)$guestProfile['id']], $uploadIds);
      $st=pdo()->prepare("SELECT upload_id FROM guest_upload_likes WHERE profile_id=? AND upload_id IN ($placeholders)");
      $st->execute($params);
      foreach($st->fetchAll() as $row){ $uploadLikedByMe[(int)$row['upload_id']] = true; }
    }
    $st=pdo()->prepare("SELECT c.*, gp.display_name AS profile_display_name, gp.avatar_token AS profile_avatar_token
                         FROM guest_upload_comments c
                         LEFT JOIN guest_profiles gp ON gp.id=c.profile_id
                         WHERE c.upload_id IN ($placeholders)
                         ORDER BY c.created_at ASC");
    $st->execute($uploadIds);
    foreach($st->fetchAll() as $c){
      $uid = (int)$c['upload_id'];
      if(!isset($uploadComments[$uid])) $uploadComments[$uid]=[];
      $uploadComments[$uid][] = $c;
      $commentCounts[$uid] = ($commentCounts[$uid] ?? 0) + 1;
    }
  }
}

$chatMessages=[];
$st = pdo()->prepare("SELECT m.*, gp.display_name, gp.avatar_token FROM guest_chat_messages m
                      LEFT JOIN guest_profiles gp ON gp.id=m.profile_id
                      WHERE m.event_id=? ORDER BY m.id DESC LIMIT 60");
$st->execute([$event_id]);
$chatMessages = array_reverse($st->fetchAll());
?>
<!doctype html>
<html lang="tr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($ev['title'])?> — Misafir Sosyal Alanı</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
:root{ --zs:<?=h($PRIMARY)?>; --zs-soft:<?=h($ACCENT)?>; --ink:#0f172a; --muted:#64748b }
body{ background:linear-gradient(180deg,var(--zs-soft),#fff); font-family:'Inter','Helvetica Neue',Arial,sans-serif; color:var(--ink); }
.card-lite{ border:1px solid rgba(148,163,184,.25); border-radius:22px; background:#fff; box-shadow:0 18px 45px rgba(15,23,42,.08); }
.btn-zs{ background:var(--zs); border:none; color:#fff; border-radius:999px; padding:.65rem 1.2rem; font-weight:600; }
.btn-zs:disabled{ background:rgba(148,163,184,.6); }
.btn-zs-outline{ background:#fff; border:1px solid var(--zs); color:var(--zs); border-radius:999px; font-weight:600; }
.hero{ background:#fff; border-radius:26px; padding:32px; position:relative; overflow:hidden; }
.hero::after{ content:""; position:absolute; inset:auto -40px -120px auto; width:320px; height:320px; background:radial-gradient(circle at center, rgba(14,165,181,.35), transparent 70%); }
.hero-badge{ display:inline-flex; align-items:center; gap:8px; background:rgba(14,165,181,.12); color:var(--zs); padding:6px 14px; border-radius:999px; font-size:14px; font-weight:600; }
.page-grid{ display:grid; gap:24px; margin-top:32px; }
@media(min-width:992px){ .page-grid{ grid-template-columns:2fr 1fr; } }
.upload-card{ padding:28px; }
.upload-card h5{ font-weight:700; }
.dropzone{ border:2px dashed rgba(148,163,184,.45); border-radius:24px; padding:36px; text-align:center; background:rgba(241,245,249,.6); transition:.2s; }
.dropzone.drag{ border-color:var(--zs); background:rgba(14,165,181,.08); }
.form-text{ color:var(--muted); }
.gallery-card{ padding:28px; }
.gallery-masonry{ column-count:1; column-gap:24px; }
@media(min-width:768px){ .gallery-masonry{ column-count:2; } }
@media(min-width:1200px){ .gallery-masonry{ column-count:3; } }
.gallery-item{ break-inside:avoid; margin-bottom:24px; border-radius:20px; overflow:hidden; background:#fff; border:1px solid rgba(148,163,184,.2); box-shadow:0 12px 30px rgba(15,23,42,.08); position:relative; }
.gallery-media img,.gallery-media video{ width:100%; display:block; object-fit:cover; }
.gallery-header{ display:flex; align-items:center; gap:12px; padding:18px 20px 12px; }
.avatar{ width:46px; height:46px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; letter-spacing:.5px; }
.gallery-actions{ display:flex; align-items:center; justify-content:space-between; padding:12px 20px 4px; gap:12px; }
.action-buttons{ display:flex; gap:10px; }
.icon-btn{ border:none; background:rgba(248,250,252,.9); border-radius:999px; padding:8px 16px; display:flex; align-items:center; gap:8px; font-weight:600; color:var(--muted); cursor:pointer; }
.icon-btn.active{ background:rgba(14,165,181,.12); color:var(--zs); }
.icon-btn:hover{ background:rgba(14,165,181,.18); color:var(--zs); }
.gallery-meta{ font-size:13px; color:var(--muted); }
.gallery-body{ padding:8px 20px 20px; }
.comment{ padding:10px 14px; border-radius:14px; background:rgba(241,245,249,.6); margin-top:8px; }
.comment strong{ display:block; font-size:13px; color:var(--ink); }
.comment p{ margin:4px 0 0; font-size:13px; color:var(--muted); }
.comment-form textarea{ resize:vertical; border-radius:16px; }
.badge-soft{ background:rgba(14,165,181,.12); color:var(--zs); border-radius:999px; padding:4px 12px; font-size:12px; font-weight:600; }
.profile-card{ padding:28px; }
.profile-card h5{ font-weight:700; }
.chat-card{ padding:28px; }
.chat-stream{ max-height:420px; overflow:auto; display:flex; flex-direction:column; gap:12px; }
.chat-bubble{ padding:14px 18px; border-radius:18px; background:rgba(241,245,249,.8); }
.chat-author{ font-weight:600; font-size:14px; color:var(--ink); }
.chat-time{ font-size:12px; color:var(--muted); }
.preview-shell{ width:min(100%,980px); margin:0 auto; }
.preview-stage{ position:relative; width:100%; border:1px dashed #cbd5e1; border-radius:20px; background:#fff; overflow:hidden; }
.stage-scale{ position:absolute; left:0; top:0; width:960px; height:540px; transform-origin:top left; transform:scale(var(--s,1)); }
.preview-canvas{ position:absolute; inset:0; background:linear-gradient(180deg,var(--zs-soft),#fff); }
.pv-title{ position:absolute; font-size:28px; font-weight:800; color:#111; }
.pv-sub{ position:absolute; color:#334155; font-size:16px; }
.pv-prompt{ position:absolute; color:#0f172a; font-size:16px; }
.sticker{ position:absolute; user-select:none; pointer-events:none; }
.smallmuted{ color:var(--muted); font-size:13px; }
.share-toast{ position:fixed; left:50%; bottom:32px; transform:translateX(-50%); background:#0ea5b5; color:#fff; padding:12px 20px; border-radius:999px; font-weight:600; box-shadow:0 18px 30px rgba(14,165,181,.3); opacity:0; transition:.4s; pointer-events:none; }
.share-toast.show{ opacity:1; }
</style>
</head>
<body>
<div class="container py-4">
  <?php flash_box(); ?>
  <section class="hero mb-4">
    <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
      <div>
        <span class="hero-badge">BİKARE Misafir Alanı</span>
        <h1 class="mt-3 mb-2" style="font-weight:800; font-size:32px; color:var(--ink);"><?=h($TITLE)?></h1>
        <p class="mb-3" style="font-size:16px; color:var(--muted); max-width:560px;"><?=h($SUBTITLE)?></p>
        <?php if($guestProfile): ?>
          <?php if($profileVerified): ?>
            <div class="badge-soft">Hoş geldin, <?=h($guestProfile['display_name'] ?: $guestProfile['name'])?>! Profilin doğrulandı.</div>
          <?php else: ?>
            <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center gap-2">
              <div class="badge-soft">E-posta doğrulaması bekleniyor</div>
              <form method="post" class="d-flex">
                <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                <input type="hidden" name="do" value="resend_verification">
                <button class="btn btn-zs-outline btn-sm">Bağlantıyı tekrar gönder</button>
              </form>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <div class="badge-soft">Adınızı ve e-postanızı paylaşarak misafir topluluğuna katılın.</div>
        <?php endif; ?>
      </div>
      <div class="text-end smallmuted">
        <div>Etkinlik Tarihi: <?= $ev['event_date'] ? date('d.m.Y', strtotime($ev['event_date'])) : 'Belirtilmedi' ?></div>
        <div>Misafir bağlantısı bu sayfadır.</div>
      </div>
    </div>
  </section>
  <div class="page-grid">
    <div class="vstack gap-4">
      <div class="card-lite upload-card">
        <h5 class="mb-3">Anınızı Yükleyin</h5>
        <p class="smallmuted mb-4">Fotoğraflar, videolar ve GIF’ler yüksek kalitede saklanır. Yükledikten sonra topluluk beğenebilir, yorum yapabilir ve paylaşabilir.</p>
        <form method="post" enctype="multipart/form-data" id="upForm" class="vstack gap-4">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="do" value="upload">
          <input type="hidden" name="t" value="<?=h($token)?>">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Adınız Soyadınız *</label>
              <input class="form-control" name="guest_name" placeholder="Ör. Ayşe Yılmaz" required <?= !$token_ok?'disabled':'' ?>>
            </div>
            <div class="col-md-6">
              <label class="form-label">E-posta (opsiyonel)</label>
              <input class="form-control" type="email" name="guest_email" placeholder="ornek@mail.com" <?= !$token_ok?'disabled':'' ?>>
              <div class="form-text">Düğün sonrasında size gönderilecek kullanıcı adı ve şifreniz için e-posta adresinizi paylaşabilirsiniz.</div>
            </div>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="marketing_opt_in" value="1" id="marketingOpt" <?= !$token_ok?'disabled':'' ?>>
            <label class="form-check-label" for="marketingOpt">BİKARE’den kampanya ve yenilik bildirimleri almak istiyorum.</label>
          </div>
          <div class="dropzone" id="drop" <?= !$token_ok?'data-disabled="1"':'' ?>>
            <p class="m-0">
              <strong>Dosyalarınızı buraya sürükleyin</strong> veya
              <label class="text-decoration-underline" style="cursor:pointer">cihazınızdan seçin
                <input type="file" name="files[]" id="fileI" accept="<?=implode(',',array_keys(ALLOWED_MIMES))?>" multiple hidden <?= !$token_ok?'disabled':'' ?>>
              </label>
            </p>
            <div class="form-text mt-2">İzinli formatlar: jpg, png, webp, gif, mp4, mov, webm. Maksimum <?=round(MAX_UPLOAD_BYTES/1048576)?> MB / dosya.</div>
          </div>
          <div id="list" class="smallmuted"></div>
          <div class="d-grid d-sm-flex align-items-center gap-3">
            <button class="btn btn-zs" <?= !$token_ok?'disabled':'' ?>>Anımı Paylaş</button>
            <?php if(!$token_ok): ?><span class="text-danger small">Güvenlik anahtarınızın süresi doldu. Lütfen QR kodu yeniden okutun.</span><?php endif; ?>
          </div>
        </form>
      </div>

      <div class="card-lite p-3">
        <div class="preview-shell">
          <div class="preview-stage" id="pvStage">
            <div class="stage-scale" id="scaleBox">
              <div class="preview-canvas">
                <div class="pv-title"  style="left:<?= (int)$tPos['x']?>px; top:<?= (int)$tPos['y']?>px;"><?=h($TITLE)?></div>
                <div class="pv-sub"    style="left:<?= (int)$sPos['x']?>px; top:<?= (int)$sPos['y']?>px;"><?=h($SUBTITLE)?></div>
                <div class="pv-prompt" style="left:<?= (int)$pPos['x']?>px; top:<?= (int)$pPos['y']?>px;"><?=h($PROMPT)?></div>
                <?php foreach($stickersArr as $st){
                  $txt = isset($st['txt'])?$st['txt']:'💍';
                  $x   = isset($st['x'])?(int)$st['x']:20;
                  $y   = isset($st['y'])?(int)$st['y']:90;
                  $sz  = isset($st['size'])?(int)$st['size']:32; ?>
                  <div class="sticker" style="left:<?=$x?>px; top:<?=$y?>px; font-size:<?=$sz?>px"><?=$txt?></div>
                <?php } ?>
              </div>
            </div>
          </div>
        </div>
        <div class="smallmuted mt-2 text-center">Bu önizleme çift panelinde kayıtlı tasarımın birebir halidir.</div>
      </div>

      <div class="card-lite gallery-card" id="galeri">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <h5 class="mb-0">Topluluk Galerisi</h5>
            <div class="smallmuted">Paylaşılan tüm fotoğraf ve videolar burada toplanır.</div>
          </div>
          <div>
            <?php if(!$CAN_VIEW): ?><span class="badge bg-secondary">Galeri gizli</span>
            <?php else: ?>
              <span class="badge-soft"><?= $CAN_DOWN ? 'İndirme açık' : 'İndirme kapalı' ?></span>
            <?php endif; ?>
          </div>
        </div>
        <?php if(!$CAN_VIEW): ?>
          <div class="smallmuted">Galeri bu etkinlikte sadece çift tarafından görüntülenebilir.</div>
        <?php elseif(!$uploads): ?>
          <div class="smallmuted">Henüz yükleme yapılmadı. İlk paylaşımı siz yapın!</div>
        <?php else: ?>
          <div class="gallery-masonry">
            <?php foreach($uploads as $u):
              $path = '/'.$u['file_path'];
              $isImg=is_image_mime($u['mime']); $isVid=is_video_mime($u['mime']);
              $name = $u['profile_display_name'] ?: $u['guest_name'];
              $seed = guest_profile_avatar_seed([
                'avatar_token'=>$u['profile_avatar_token'],
                'email'=>$u['guest_email'] ?? ('upload'.$u['id'].'@guest'),
                'id'=> $u['profile_id'] ? (int)$u['profile_id'] : (int)$u['id']
              ]);
              [$bg,$fg] = avatar_colors($seed);
              $liked = !empty($uploadLikedByMe[$u['id']]);
              $likes = $uploadLikes[$u['id']] ?? 0;
              $comments = $uploadComments[$u['id']] ?? [];
              $commentCount = $commentCounts[$u['id']] ?? 0;
            ?>
            <div class="gallery-item" id="media-<?=$u['id']?>">
              <div class="gallery-header">
                <div class="avatar" style="background:<?=$bg?>;color:<?=$fg?>;">
                  <?=h(avatar_initial($name))?>
                </div>
                <div>
                  <div style="font-weight:600; color:var(--ink);"><?=h($name)?></div>
                  <div class="gallery-meta"><?=relative_time($u['created_at'])?></div>
                </div>
              </div>
              <div class="gallery-media">
                <?php if($isImg): ?><img src="<?=h($path)?>" alt="">
                <?php elseif($isVid): ?><video src="<?=h($path)?>" controls playsinline></video>
                <?php else: ?><div class="p-4 text-center smallmuted">Dosya</div><?php endif; ?>
              </div>
              <div class="gallery-actions">
                <div class="action-buttons">
                  <form method="post" class="d-inline">
                    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="do" value="<?= $liked ? 'unlike' : 'like' ?>">
                    <input type="hidden" name="upload_id" value="<?=$u['id']?>">
                    <button type="submit" class="icon-btn <?= $liked?'active':'' ?>" <?= !$profileVerified?'disabled title="Beğenmek için e-posta doğrulaması gerekir"':'' ?>>
                      <span><?= $liked ? '❤️' : '🤍' ?></span>
                      <span><?= $likes ?></span>
                    </button>
                  </form>
                  <button class="icon-btn share-btn" type="button" data-share="<?=h(BASE_URL.$path)?>"><span>🔗</span>Paylaş</button>
                </div>
                <?php if($CAN_DOWN): ?>
                  <a class="btn btn-sm btn-zs-outline" href="<?=h($path)?>" download>İndir</a>
                <?php endif; ?>
              </div>
              <div class="gallery-body">
                <div class="smallmuted">Yorumlar (<?=$commentCount?>)</div>
                <?php foreach($comments as $c):
                  $cName = $c['profile_display_name'] ?: ($c['guest_name'] ?: 'Misafir');
                  $cSeed = guest_profile_avatar_seed([
                    'avatar_token'=>$c['profile_avatar_token'],
                    'email'=>$c['guest_email'] ?? ('comment'.($c['id'] ?? '0').'@guest'),
                    'id'=> $c['profile_id'] ? (int)$c['profile_id'] : (int)($c['id'] ?? 0)
                  ]);
                  [$cbg,$cfg] = avatar_colors($cSeed);
                ?>
                  <div class="comment">
                    <strong><?=h($cName)?></strong>
                    <div class="smallmuted"><?=relative_time($c['created_at'])?></div>
                    <p><?=nl2br(h($c['body']))?></p>
                  </div>
                <?php endforeach; ?>
                <?php if($profileVerified): ?>
                <form method="post" class="comment-form mt-3">
                  <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="do" value="comment">
                  <input type="hidden" name="upload_id" value="<?=$u['id']?>">
                  <textarea class="form-control" name="comment_body" rows="2" placeholder="Güzel bir not bırak..." required></textarea>
                  <div class="text-end mt-2"><button class="btn btn-sm btn-zs">Yorumu Gönder</button></div>
                </form>
                <?php elseif($guestProfile && !$profileVerified): ?>
                  <div class="comment mt-3">Yorum yapabilmek için e-posta adresinizi doğrulayın.</div>
                <?php else: ?>
                  <div class="comment mt-3">Yorum yapmak için önce e-postanızı paylaşarak içerik yükleyin.</div>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="vstack gap-4">
      <div class="card-lite profile-card" id="profile">
        <h5 class="mb-3">Profiliniz</h5>
        <?php if($guestProfile): ?>
          <form method="post" class="vstack gap-3">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="do" value="profile">
            <div>
              <label class="form-label">Görünen Ad</label>
              <input class="form-control" name="display_name" value="<?=h($guestProfile['display_name'] ?: $guestProfile['name'])?>">
            </div>
            <div>
              <label class="form-label">Hakkınızda</label>
              <textarea class="form-control" name="bio" rows="3" placeholder="Misafir defterine kısa bir not bırakın."><?=h($guestProfile['bio'] ?? '')?></textarea>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="profile_marketing" value="1" id="profileMarketing" <?= !empty($guestProfile['marketing_opt_in'])?'checked':'' ?>>
              <label class="form-check-label" for="profileMarketing">BİKARE’den kampanya ve yenilik bildirimleri almak istiyorum.</label>
            </div>
            <div class="d-flex justify-content-between align-items-center">
              <div class="smallmuted">E-postanız: <?=h($guestProfile['email'] ?? '—')?> <?= $profileVerified ? '<span class="badge-soft ms-2">Doğrulandı</span>' : '' ?></div>
              <button class="btn btn-sm btn-zs">Kaydet</button>
            </div>
          </form>
        <?php else: ?>
          <div class="smallmuted">E-posta adresinizle içerik yüklediğinizde profilinizi düzenleyebilir ve topluluk özelliklerini kullanabilirsiniz.</div>
        <?php endif; ?>
      </div>

      <div class="card-lite chat-card" id="chat">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h5 class="m-0">Misafir Sohbeti</h5>
          <span class="badge-soft"><?=count($chatMessages)?> mesaj</span>
        </div>
        <div class="chat-stream mb-3">
          <?php if(!$chatMessages): ?>
            <div class="smallmuted">Sohbeti başlatmak için ilk mesajınızı yazın.</div>
          <?php else: ?>
            <?php foreach($chatMessages as $msg):
              $display = $msg['display_name'] ?: 'Misafir';
              $seed = guest_profile_avatar_seed([
                'avatar_token'=>$msg['avatar_token'],
                'email'=>$msg['profile_id'] ? ('profile'.$msg['profile_id'].'@chat') : ('chat'.$msg['id'].'@guest'),
                'id'=> $msg['profile_id'] ? (int)$msg['profile_id'] : (int)$msg['id']
              ]);
              [$bg,$fg] = avatar_colors($seed);
            ?>
              <div class="chat-bubble">
                <div class="d-flex align-items-center gap-2">
                  <div class="avatar" style="width:38px;height:38px;background:<?=$bg?>;color:<?=$fg?>;font-size:13px;">
                    <?=h(avatar_initial($display))?>
                  </div>
                  <div>
                    <div class="chat-author"><?=h($display)?></div>
                    <div class="chat-time"><?=relative_time($msg['created_at'])?></div>
                  </div>
                </div>
                <div class="mt-2" style="white-space:pre-wrap; font-size:14px; color:var(--muted);"><?=nl2br(h($msg['message']))?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <?php if($profileVerified): ?>
          <form method="post" class="vstack gap-3">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="do" value="chat">
            <textarea class="form-control" name="chat_message" rows="2" placeholder="Kutlama mesajınızı yazın" required></textarea>
            <div class="text-end"><button class="btn btn-sm btn-zs">Gönder</button></div>
          </form>
        <?php elseif($guestProfile && !$profileVerified): ?>
          <div class="smallmuted">Sohbete katılmak için e-posta adresinizi doğrulayın.</div>
        <?php else: ?>
          <div class="smallmuted">Sohbete katılmak için önce e-posta adresinizle içerik yükleyin.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<div class="share-toast" id="shareToast">Bağlantı kopyalandı!</div>

<script>
(function(){
  const stage=document.getElementById('pvStage'), box=document.getElementById('scaleBox');
  function fit(){ if(!stage||!box) return; const W=stage.clientWidth, S=W/960; box.style.setProperty('--s',S); stage.style.height=(540*S)+'px'; }
  window.addEventListener('resize',fit,{passive:true}); new ResizeObserver(fit).observe(stage); fit();
})();

const dz=document.getElementById('drop'), fi=document.getElementById('fileI'), lst=document.getElementById('list'), fm=document.getElementById('upForm');
if(dz && !dz.dataset.disabled){
  ['dragenter','dragover'].forEach(ev=>dz.addEventListener(ev,e=>{e.preventDefault();dz.classList.add('drag');}));
  ['dragleave','drop'].forEach(ev=>dz.addEventListener(ev,e=>{e.preventDefault();dz.classList.remove('drag');}));
  dz.addEventListener('drop',e=>{ const fs=e.dataTransfer.files; if(fs&&fi){ fi.files=fs; renderList(fs); } });
}
fi?.addEventListener('change',e=>renderList(e.target.files));
function renderList(files){ if(!files||!files.length){ lst.innerHTML=''; return; } let out='<ul class="m-0 ps-3">'; for(let i=0;i<files.length;i++){ const f=files[i]; out+=`<li>${esc(f.name)} — ${(f.size/1048576).toFixed(1)} MB</li>`; } lst.innerHTML=out+'</ul>'; }
function esc(s){return s.replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}
fm?.addEventListener('submit',e=>{ const name=fm.querySelector('[name=guest_name]').value.trim(); if(!name){e.preventDefault(); alert('Lütfen adınızı yazın.');} const fs=fi?.files||[]; if(!fs.length){e.preventDefault(); alert('Lütfen dosya seçin.');} });

const shareButtons=document.querySelectorAll('.share-btn');
const toast=document.getElementById('shareToast');
shareButtons.forEach(btn=>btn.addEventListener('click',async()=>{
  const url=btn.dataset.share;
  if(navigator.share){ try{ await navigator.share({url:url}); return;}catch(err){} }
  try{ await navigator.clipboard.writeText(url); toast?.classList.add('show'); setTimeout(()=>toast?.classList.remove('show'),2000);}catch(err){ alert('Bağlantı kopyalanamadı: '+err); }
}));
</script>
</body></html>
