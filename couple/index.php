<?php
require_once __DIR__.'/_auth.php';                  // tek URL login + aktif düğün
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/license.php';    // lisans yardımcıları

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
$cs = pdo()->prepare("SELECT * FROM campaigns WHERE venue_id=? AND is_active=1 ORDER BY id DESC");
$cs->execute([$VID]);
$campaigns = $cs->fetchAll();

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
<style>
:root{ --ink:#111827; --muted:#6b7280; --zs:<?=h($PRIMARY)?>; --zs-soft:<?=h($ACCENT)?>; }
body{ background:linear-gradient(180deg,#f8fafc,#fff) }
.card-lite{ border:1px solid #eef2f7; border-radius:18px; background:#fff; box-shadow:0 6px 20px rgba(17,24,39,.05) }
.btn-zs{ background:var(--zs); border:none; color:#fff; border-radius:12px; padding:.6rem 1rem; font-weight:600 }
.btn-zs-outline{ background:#fff; border:1px solid var(--zs); color:var(--zs); border-radius:12px; font-weight:600 }

/* === 960×540 ölçekli sahne (upload.php ile birebir) === */
.preview-shell{ width:min(100%,980px); margin:0 auto }
.preview-stage{ position:relative; width:100%; border:1px dashed #cbd5e1; border-radius:16px; background:#fff; overflow:hidden }
.stage-scale{ position:absolute; left:0; top:0; width:960px; height:540px; transform-origin:top left; transform:scale(var(--s,1)) }
.preview-canvas{ position:absolute; inset:0; background:linear-gradient(180deg,var(--zs-soft),#fff) }
#pv-title{ position:absolute; font-size:28px; font-weight:800; color:#111 }
#pv-sub{ position:absolute; color:#334155; font-size:16px }
#pv-prompt{ position:absolute; color:#0f172a; font-size:16px }
.sticker{ position:absolute; user-select:none; cursor:move }
.badge-soft{ background:#eef2ff; color:#334155 }
</style>
</head>
<body>
<nav class="navbar bg-white border-bottom">
  <div class="container">
    <a class="navbar-brand fw-semibold" href="<?=h(BASE_URL)?>"><?=h(APP_NAME)?></a>
    <div class="d-flex align-items-center gap-2 gap-md-3 small">
      <span class="fw-semibold text-truncate"><?=h($ev['title'])?></span>
      <a class="btn btn-sm btn-outline-secondary" href="list.php" title="Yüklemeler / Galeri">Yüklemeler</a>
      <a class="btn btn-sm btn-outline-secondary" href="logout.php">Çıkış</a>
    </div>
  </div>
</nav>

<div class="container py-4">
  <?php flash_box(); ?>

  <!-- Lisans bandı -->
  <?php if(!$license_active): ?>
    <div class="alert alert-danger d-flex flex-wrap gap-2 justify-content-between align-items-center" style="border-radius:14px">
      <div class="me-3"><strong>Lisans:</strong> Süresi doldu. Lütfen lisans satın alın.</div>
      <form method="post" class="d-flex align-items-center gap-2 m-0" action="pay_license.php">
        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="event_id" value="<?=$EVENT_ID?>">
        <label class="small text-light">Süre:</label>
        <select name="years" class="form-select form-select-sm" style="width:auto">
          <?php foreach($LICENSE_YEARS as $y): ?>
            <option value="<?=$y?>"><?=$y?> yıl — <?= (int)$LICENSE_PLANS[$y] ?> TL</option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-light">Lisans Satın Al</button>
      </form>
    </div>
  <?php else: ?>
    <div class="alert alert-info d-flex flex-wrap gap-2 justify-content-between align-items-center" style="border-radius:14px">
      <div class="me-3"><strong><?=$license_badge?></strong></div>
      <form method="post" class="d-flex align-items-center gap-2 m-0" action="pay_license.php">
        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="event_id" value="<?=$EVENT_ID?>">
        <label class="small text-muted">Süreyi uzat:</label>
        <select name="years" class="form-select form-select-sm" style="width:auto">
          <?php foreach($LICENSE_YEARS as $y): ?>
            <option value="<?=$y?>"><?=$y?> yıl — <?= (int)$LICENSE_PLANS[$y] ?> TL</option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-primary">Lisans Satın Al</button>
      </form>
    </div>
  <?php endif; ?>

  <div class="row g-4">
    <!-- Sol -->
    <div class="col-lg-6">
      <div class="card-lite p-3 mb-4">
        <div class="d-flex justify-content-between align-items-center">
          <h5 class="mb-3 m-0">Misafir Sayfası Ayarları</h5>
          <!-- Listeye git butonu (üstte de var, burada da hızlı erişim için) -->
          <a class="btn btn-sm btn-zs-outline" href="list.php" title="Toplu gör / indir / sil">Yüklemeler</a>
        </div>

        <form method="post" class="row g-3">
          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="do" value="save_settings">

          <div class="col-12"><label class="form-label">Başlık</label>
            <input class="form-control" name="guest_title" value="<?=h($ev['guest_title'] ?? '')?>">
          </div>
          <div class="col-12"><label class="form-label">Alt Başlık</label>
            <input class="form-control" name="guest_subtitle" value="<?=h($ev['guest_subtitle'] ?? '')?>">
          </div>
          <div class="col-12"><label class="form-label">Yükleme Mesajı</label>
            <textarea class="form-control" name="guest_prompt"><?=h($ev['guest_prompt'] ?? '')?></textarea>
          </div>

          <div class="col-md-6"><label class="form-label">Ana Renk</label>
            <input type="color" class="form-control form-control-color" name="theme_primary" value="<?=h($PRIMARY)?>" oninput="applyTheme()">
          </div>
          <div class="col-md-6"><label class="form-label">Aksan Renk</label>
            <input type="color" class="form-control form-control-color" name="theme_accent" value="<?=h($ACCENT)?>" oninput="applyTheme()">
          </div>

          <div class="col-md-12"><label class="form-label">İletişim E-postası</label>
            <input type="email" class="form-control" name="contact_email" value="<?=h($ev['contact_email'] ?? '')?>">
          </div>
          <div class="col-md-6"><label class="form-label">Telefon</label>
            <input class="form-control" name="couple_phone" value="<?=h($ev['couple_phone'] ?? '')?>">
          </div>
          <div class="col-md-6"><label class="form-label">T.C. Kimlik</label>
            <input class="form-control" name="couple_tckn" value="<?=h($ev['couple_tckn'] ?? '')?>">
          </div>
          <div class="col-md-6"><label class="form-label">Fatura Ünvanı</label>
            <input class="form-control" name="invoice_title" value="<?=h($ev['invoice_title'] ?? '')?>">
          </div>
          <div class="col-md-6"><label class="form-label">VKN/TCKN</label>
            <input class="form-control" name="invoice_vkn" value="<?=h($ev['invoice_vkn'] ?? '')?>">
          </div>
          <div class="col-12"><label class="form-label">Fatura Adresi</label>
            <input class="form-control" name="invoice_address" value="<?=h($ev['invoice_address'] ?? '')?>">
          </div>

          <div class="col-12 d-flex flex-wrap gap-3 mt-1">
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

          <input type="hidden" name="layout_json" id="layout_json" value="<?=h($layoutJson)?>">
          <input type="hidden" name="stickers_json" id="stickers_json" value="<?=h($stickersJson)?>">

          <div class="col-12 d-grid mt-2">
            <button class="btn btn-zs">Kaydet</button>
          </div>
        </form>
      </div>

      <!-- Ek Paketler -->
      <div class="card-lite p-3 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="m-0">Ek Paket Satın Al</h5>
          <span class="text-muted small">Birden fazla paket seçebilirsiniz.</span>
        </div>
        <?php if(!$campaigns): ?>
          <div class="text-muted">Aktif paket yok.</div>
        <?php else: ?>
          <form class="vstack gap-2" method="post" action="pay_addons.php">
            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="event_id" value="<?=$EVENT_ID?>">
            <input type="hidden" name="key" value="<?=h($ev['couple_panel_key'])?>">
            <?php foreach($campaigns as $c): ?>
              <label class="d-flex justify-content-between align-items-center border rounded p-2">
                <span>
                  <strong><?=h($c['name'])?></strong>
                  <span class="badge bg-light text-dark ms-2"><?=h($c['type'])?></span><br>
                  <small class="text-muted"><?=h($c['description'])?></small>
                </span>
                <span class="d-flex align-items-center gap-3">
                  <span class="fw-semibold"><?= (int)$c['price'] ?> TL</span>
                  <input type="checkbox" class="form-check-input" name="addons[]" value="<?=$c['id']?>">
                </span>
              </label>
            <?php endforeach; ?>
            <div class="d-grid mt-1"><button class="btn btn-zs">Ödemeye Geç</button></div>
          </form>
        <?php endif; ?>
      </div>

      <div class="card-lite p-3">
        <h6 class="mb-2">Toplam Yükleme</h6>
        <div class="d-flex align-items-center gap-3">
          <span class="badge bg-secondary"><?= (int)$tot['c'] ?> adet</span>
          <span class="badge badge-soft"><?= fmt_bytes((int)$tot['b']) ?></span>
        </div>
      </div>
    </div>

    <!-- Sağ -->
    <div class="col-lg-6">
      <div class="card-lite p-3 mb-3">
        <h5 class="mb-3">Misafir Sayfası Önizleme (Birebir)</h5>

        <!-- BİREBİR 960×540 sahne -->
        <div class="preview-shell">
          <div class="preview-stage" id="pvStage">
            <div class="stage-scale" id="scaleBox">
              <div class="preview-canvas" id="canvas" style="--zs:<?=h($PRIMARY)?>; --zs-soft:<?=h($ACCENT)?>;">
                <div id="pv-title"  style="left:<?= (int)$tPos['x']?>px; top:<?= (int)$tPos['y']?>px;"><?=h($TITLE)?></div>
                <div id="pv-sub"    style="left:<?= (int)$sPos['x']?>px; top:<?= (int)$sPos['y']?>px;"><?=h($SUBTITLE)?></div>
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

        <div class="small text-muted mt-2">Not: Renkler ve yerleşim sadece bu önizlemede uygulanır; site genelini etkilemez.</div>
      </div>

      <div class="card-lite p-3">
        <h6 class="mb-3">Sticker/Simgeler</h6>
        <div class="d-flex flex-wrap gap-2 mb-2">
          <?php foreach(['💍','💐','🎉','🎶','📸','❤️','✨','🎈','🥂','👰','🤵','🍰','🌟','🎊','💞','🕊️'] as $em): ?>
            <button class="btn btn-sm btn-zs-outline add-sticker" data-emoji="<?=h($em)?>"><?=$em?></button>
          <?php endforeach; ?>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-sm btn-outline-danger" id="clearStickers">Tüm Simgeleri Kaldır</button>
          <button class="btn btn-sm btn-outline-secondary" id="resetLayout">Yerleşimi Sıfırla</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
// 960x540 sahneyi container'a orantılı sığdır (upload.php ile aynı hesap)
(function(){
  const stage=document.getElementById('pvStage'), box=document.getElementById('scaleBox');
  function fit(){ if(!stage||!box) return; const W=stage.clientWidth, S=W/960; box.style.setProperty('--s',S); stage.style.height=(540*S)+'px'; }
  window.addEventListener('resize',fit,{passive:true}); new ResizeObserver(fit).observe(stage); fit();
})();

// Sadece önizleme alanına tema uygula
function applyTheme(){
  const p=document.querySelector('[name=theme_primary]').value;
  const a=document.querySelector('[name=theme_accent]').value;
  const root=document.getElementById('canvas');
  if(root){ root.style.setProperty('--zs',p); root.style.setProperty('--zs-soft',a); }
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
