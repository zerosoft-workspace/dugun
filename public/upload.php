<?php
// public/upload.php — Misafir yükleme + galeri + Çift panelle birebir 960x540 ölçekli önizleme
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/dealers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

/* ---- CSRF (shim) ---- */
if (!function_exists('csrf_token')) {
  function csrf_token(){ if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
}
if (!function_exists('csrf_or_die')) {
  function csrf_or_die(){ $t=$_POST['csrf']??$_POST['_csrf']??''; if(!$t || !hash_equals($_SESSION['csrf']??'', $t)){ http_response_code(400); exit('CSRF'); } }
}

/* ---- Yardımcılar ---- */
function client_ip(){
  if(isset($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
  if(isset($_SERVER['HTTP_X_FORWARDED_FOR'])) return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
  return $_SERVER['REMOTE_ADDR']??'0.0.0.0';
}
function is_image_mime($m){ return (bool)preg_match('~^image/(jpeg|png|webp|gif)$~i',$m); }
function is_video_mime($m){ return (bool)preg_match('~^video/(mp4|quicktime|webm)$~i',$m); }

/* ---- Parametreler ---- */
$event_id = (int)($_GET['event'] ?? 0);
$token    = trim($_GET['t'] ?? '');
if ($event_id <= 0){ http_response_code(400); exit('Geçersiz istek'); }

/* ---- Etkinlik satırı ---- */
$st = pdo()->prepare("SELECT * FROM events WHERE id=? LIMIT 1");
$st->execute([$event_id]);
$ev = $st->fetch();
if (!$ev || (int)$ev['is_active']!==1){ http_response_code(404); exit('Etkinlik bulunamadı veya pasif.'); }

$VID      = (int)$ev['venue_id'];
$TITLE    = $ev['guest_title'] ?: 'Düğünümüze Hoş Geldiniz';
$SUBTITLE = $ev['guest_subtitle'] ?: 'En güzel anlarınızı bizimle paylaşın';
$PROMPT   = $ev['guest_prompt'] ?: 'Adınızı yazıp anınızı yükleyin.';
$PRIMARY  = $ev['theme_primary'] ?: '#0ea5b5';
$ACCENT   = $ev['theme_accent']  ?: '#e0f7fb';
$CAN_VIEW = (int)$ev['allow_guest_view']===1;
$CAN_DOWN = (int)$ev['allow_guest_download']===1;

/* ---- Çift panelden gelen layout/sticker ---- */
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

/* ---- Dinamik QR token doğrulama ---- */
$token_ok = token_valid($event_id, $token);

/* ---- Yükleme ---- */
$errors=[]; $okCount=0;
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='upload'){
  csrf_or_die();
  $p_token = trim($_POST['t']??'');
  if(!token_valid($event_id,$p_token)){
    $errors[]='Güvenlik anahtarı zaman aşımına uğradı. Lütfen QR’ı yeniden okutun.';
  }else{
    $guest = trim($_POST['guest_name']??'');
    if($guest===''){ $errors[]='Lütfen adınızı yazın.'; }
    if(!isset($_FILES['files'])){ $errors[]='Dosya seçilmedi.'; }
    else{
      $f=$_FILES['files'];
      $n=is_array($f['name'])?count($f['name']):0;
      if($n<=0) $errors[]='Dosya seçilmedi.';
      else{
        $dir=ensure_upload_dir($VID,$event_id);
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
          pdo()->prepare("INSERT INTO uploads (venue_id,event_id,guest_name,file_path,mime,file_size,ip,created_at)
                          VALUES (?,?,?,?,?,?,?,?)")
              ->execute([$VID,$event_id,$guest,$rel,$mime,$sz,client_ip(),now()]);
          $okCount++;
        }
      }
    }
  }
  if ($okCount > 0 && !empty($ev['dealer_id'])) {
    $pdo = pdo();
    $ownTxn = !$pdo->inTransaction();
    if ($ownTxn) {
      $pdo->beginTransaction();
    }
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
      if ($ownTxn) {
        $pdo->commit();
      }
    } catch (Throwable $consumeErr) {
      if ($ownTxn && $pdo->inTransaction()) {
        $pdo->rollBack();
      }
      error_log('Dealer credit consumption failed for event '.$event_id.': '.$consumeErr->getMessage());
    }
  }
  $to=BASE_URL.'/public/upload.php?event='.$event_id.'&t='.rawurlencode($token);
  if($okCount>0){ flash('ok',$okCount.' dosya yüklendi. Teşekkürler!'); header('Location:'.$to); exit; }
  if($errors){ flash('err',implode('<br>',array_map('h',$errors))); header('Location:'.$to); exit; }
}

/* ---- Galeri ---- */
$uploads=[];
if($CAN_VIEW){
  $st=pdo()->prepare("SELECT id,guest_name,file_path,mime,file_size,created_at
                      FROM uploads WHERE venue_id=? AND event_id=? ORDER BY id DESC LIMIT 300");
  $st->execute([$VID,$event_id]);
  $uploads=$st->fetchAll();
}
?>
<!doctype html>
<html lang="tr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($ev['title'])?> — Misafir Yükleme</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
:root{ --zs:<?=h($PRIMARY)?>; --zs-soft:<?=h($ACCENT)?>; --ink:#111827; --muted:#6b7280 }
body{ background:linear-gradient(180deg,var(--zs-soft),#fff) }
.card-lite{ border:1px solid #eef2f7; border-radius:18px; background:#fff; box-shadow:0 6px 20px rgba(17,24,39,.05) }
.btn-zs{ background:var(--zs); border:none; color:#fff; border-radius:12px; padding:.65rem 1rem; font-weight:700 }
.btn-zs-outline{ background:#fff; border:1px solid var(--zs); color:var(--zs); border-radius:12px; font-weight:700 }
.dropzone{ border:2px dashed #cbd5e1; border-radius:16px; padding:24px; text-align:center; background:#fff; transition:.2s }
.dropzone.drag{ border-color:var(--zs); background:#f0fbfd }
.smallmuted{ color:var(--muted) }
.thumb{ overflow:hidden; border-radius:12px; border:1px solid #e5e7eb; background:#f8fafc }
.thumb img,.thumb video{ width:100%; height:180px; object-fit:cover; display:block }
.badge-soft{ background:#eef2ff; color:#334155 }

/* === 960×540 ölçekli sahne (çift panelle birebir) === */
.preview-shell{ width:min(100%,980px); margin:0 auto }
.preview-stage{ position:relative; width:100%; border:1px dashed #cbd5e1; border-radius:16px; background:#fff; overflow:hidden }
.stage-scale{ position:absolute; left:0; top:0; width:960px; height:540px; transform-origin:top left; transform:scale(var(--s,1)) }
.preview-canvas{ position:absolute; inset:0; background:linear-gradient(180deg,var(--zs-soft),#fff) }
.pv-title{ position:absolute; font-size:28px; font-weight:800; color:#111 }
.pv-sub{ position:absolute; color:#334155; font-size:16px }
.pv-prompt{ position:absolute; color:#0f172a; font-size:16px }
.sticker{ position:absolute; user-select:none; pointer-events:none }
</style>
</head>
<body>
<div class="container py-4">
  <?php flash_box(); ?>

  <div class="card-lite p-3 mb-4">
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
    <div class="smallmuted mt-2">Önizleme, çift panelde kaydettiğiniz düzenin birebir yansımasıdır.</div>
  </div>

  <?php if(!$token_ok): ?>
    <div class="alert alert-warning" style="border-radius:14px">Güvenlik anahtarı süresi dolmuş. Lütfen QR’ı yeniden okutun.</div>
  <?php endif; ?>

  <!-- Yükleme formu -->
  <div class="card-lite p-3 mb-4">
    <h5 class="mb-3">Anınızı Yükleyin</h5>
    <form method="post" enctype="multipart/form-data" id="upForm" class="vstack gap-3">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="do" value="upload">
      <input type="hidden" name="t" value="<?=h($token)?>">
      <div>
        <label class="form-label">Adınız</label>
        <input class="form-control" name="guest_name" placeholder="Ad Soyad" required <?= !$token_ok?'disabled':'' ?>>
      </div>
      <div class="dropzone" id="drop">
        <p class="m-0">
          <b>Dosyalarınızı buraya sürükleyin</b> veya
          <label class="text-decoration-underline" style="cursor:pointer">bilgisayardan seçin
            <input type="file" name="files[]" id="fileI" accept="<?=implode(',',array_keys(ALLOWED_MIMES))?>" multiple hidden <?= !$token_ok?'disabled':'' ?>>
          </label>
        </p>
        <div class="smallmuted mt-2">İzinli: jpg, png, webp, gif, mp4, mov, webm — Maks: <?=round(MAX_UPLOAD_BYTES/1048576)?> MB/dosya</div>
      </div>
      <div id="list" class="smallmuted"></div>
      <div class="d-grid"><button class="btn btn-zs" <?= !$token_ok?'disabled':'' ?>>Yükle</button></div>
    </form>
  </div>

  <!-- Galeri -->
  <div class="card-lite p-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h5 class="m-0">Galeri</h5>
      <?php if(!$CAN_VIEW): ?><span class="badge bg-secondary">Gizli</span>
      <?php else: ?><?= $CAN_DOWN?'<span class="badge badge-soft">İndirme açık</span>':'<span class="badge bg-secondary">İndirme kapalı</span>' ?><?php endif; ?>
    </div>
    <?php if(!$CAN_VIEW): ?>
      <div class="smallmuted">Galeri bu etkinlikte gizli.</div>
    <?php else: ?>
      <?php if(!$uploads): ?>
        <div class="smallmuted">Henüz yükleme yok.</div>
      <?php else: ?>
        <div class="row g-3">
          <?php foreach($uploads as $u):
            $path = '/'.$u['file_path'];
            $isImg=is_image_mime($u['mime']); $isVid=is_video_mime($u['mime']); ?>
            <div class="col-6 col-md-4 col-lg-3">
              <div class="thumb">
                <?php if($isImg): ?><img src="<?=h($path)?>" alt="">
                <?php elseif($isVid): ?><video src="<?=h($path)?>" muted></video>
                <?php else: ?><div class="p-4 text-center smallmuted">Dosya</div><?php endif; ?>
              </div>
              <div class="d-flex justify-content-between align-items-center mt-1">
                <span class="small text-truncate" title="<?=h($u['guest_name'])?>"><?=h($u['guest_name'])?></span>
                <?php if($CAN_DOWN): ?><a class="btn btn-sm btn-zs-outline" href="<?=h($path)?>" download>İndir</a><?php endif; ?>
              </div>
              <div class="small smallmuted"><?=number_format($u['file_size']/1048576,1)?> MB • <?=date('d.m.Y H:i', strtotime($u['created_at']))?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<script>
// 960x540 sahneyi container'a orantılı sığdır
(function(){
  const stage=document.getElementById('pvStage'), box=document.getElementById('scaleBox');
  function fit(){ if(!stage||!box) return; const W=stage.clientWidth, S=W/960; box.style.setProperty('--s',S); stage.style.height=(540*S)+'px'; }
  window.addEventListener('resize',fit,{passive:true}); new ResizeObserver(fit).observe(stage); fit();
})();

// Drag&Drop
const dz=document.getElementById('drop'), fi=document.getElementById('fileI'), lst=document.getElementById('list'), fm=document.getElementById('upForm');
if(dz){
  ['dragenter','dragover'].forEach(ev=>dz.addEventListener(ev,e=>{e.preventDefault();dz.classList.add('drag');}));
  ['dragleave','drop'].forEach(ev=>dz.addEventListener(ev,e=>{e.preventDefault();dz.classList.remove('drag');}));
  dz.addEventListener('drop',e=>{ const fs=e.dataTransfer.files; if(fs&&fi){ fi.files=fs; renderList(fs); } });
}
fi?.addEventListener('change',e=>renderList(e.target.files));
function renderList(files){ if(!files||!files.length){ lst.innerHTML=''; return; } let out='<ul class="m-0 ps-3">'; for(let i=0;i<files.length;i++){ const f=files[i]; out+=`<li>${esc(f.name)} — ${(f.size/1048576).toFixed(1)} MB</li>`; } lst.innerHTML=out+'</ul>'; }
function esc(s){return s.replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}
fm?.addEventListener('submit',e=>{ const name=fm.querySelector('[name=guest_name]').value.trim(); if(!name){e.preventDefault(); alert('Lütfen adınızı yazın.');} const fs=fi?.files||[]; if(!fs.length){e.preventDefault(); alert('Lütfen dosya seçin.');} });
</script>
</body></html>
