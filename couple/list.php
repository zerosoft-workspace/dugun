<?php
require_once __DIR__.'/_auth.php';          // çift girişi gerekir
require_once __DIR__.'/../includes/functions.php';

$EVENT_ID = couple_current_event_id();
$ev = couple_event_row_current();
if(!$ev){ http_response_code(404); exit('Etkinlik bulunamadı'); }
$VID = (int)$ev['venue_id'];

function is_image($m){ return (bool)preg_match('~^image/(jpeg|png|webp|gif)$~i',$m); }
function is_video($m){ return (bool)preg_match('~^video/(mp4|quicktime|webm)$~i',$m); }

$q = trim($_GET['q'] ?? '');
$where = 'venue_id=? AND event_id=?';
$args = [$VID,$EVENT_ID];
if($q!==''){ $where .= ' AND (guest_name LIKE ? OR file_path LIKE ?)'; $args[]='%'.$q.'%'; $args[]='%'.$q.'%'; }

/* Silme (tek/çoklu) */
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='delete'){
  csrf_or_die();
  $ids = $_POST['ids'] ?? [];
  if(!is_array($ids)) $ids=[];
  $ids = array_map('intval',$ids);
  if($ids){
    $in = implode(',', array_fill(0,count($ids),'?'));
    $st = pdo()->prepare("SELECT id,file_path FROM uploads WHERE id IN ($in) AND venue_id=? AND event_id=?");
    $st->execute(array_merge($ids, [$VID,$EVENT_ID]));
    $rows = $st->fetchAll();
    foreach($rows as $r){
      $full = __DIR__.'/../'.ltrim($r['file_path'],'/');
      if(is_file($full)) @unlink($full);
    }
    $st = pdo()->prepare("DELETE FROM uploads WHERE id IN ($in) AND venue_id=? AND event_id=?");
    $st->execute(array_merge($ids, [$VID,$EVENT_ID]));
    flash('ok', count($rows).' öğe silindi.');
  }
  redirect($_SERVER['REQUEST_URI']);
}

/* Zip indir (seçilenler) */
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='zip'){
  csrf_or_die();
  $ids = $_POST['ids'] ?? [];
  if(!is_array($ids)) $ids=[];
  $ids = array_map('intval',$ids);
  if(!$ids){ flash('err','Lütfen en az bir öğe seçin.'); redirect($_SERVER['REQUEST_URI']); }
  $in = implode(',', array_fill(0,count($ids),'?'));
  $st = pdo()->prepare("SELECT id,file_path FROM uploads WHERE id IN ($in) AND venue_id=? AND event_id=?");
  $st->execute(array_merge($ids, [$VID,$EVENT_ID]));
  $rows = $st->fetchAll();
  if(!$rows){ flash('err','Seçili öğe bulunamadı.'); redirect($_SERVER['REQUEST_URI']); }

  $zipName = 'bikare_'.date('Ymd_His').'.zip';
  $tmp = sys_get_temp_dir().'/'.$zipName;
  $zip = new ZipArchive();
  if($zip->open($tmp, ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true){ flash('err','ZIP oluşturulamadı.'); redirect($_SERVER['REQUEST_URI']); }
  foreach($rows as $r){
    $full = __DIR__.'/../'.ltrim($r['file_path'],'/');
    if(is_file($full)) $zip->addFile($full, basename($r['file_path']));
  }
  $zip->close();

  header('Content-Type: application/zip');
  header('Content-Disposition: attachment; filename="'.$zipName.'"');
  header('Content-Length: '.filesize($tmp));
  readfile($tmp);
  @unlink($tmp);
  exit;
}

/* Liste */
$st = pdo()->prepare("SELECT id,guest_name,file_path,mime,file_size,created_at FROM uploads WHERE $where ORDER BY id DESC");
$st->execute($args);
$rows = $st->fetchAll();
?>
<!doctype html>
<html lang="tr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($ev['title'])?> — Yüklemeler</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
:root{ --zs:#0ea5b5; --muted:#6b7280 }
body{ background:#f8fafc }
.card-lite{ border:1px solid #eef2f7; border-radius:18px; background:#fff; box-shadow:0 6px 20px rgba(17,24,39,.05) }
.btn-zs{ background:var(--zs); border:none; color:#fff; border-radius:10px; padding:.55rem 1rem; font-weight:700 }
.thumb{ border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; background:#fff }
.thumb img,.thumb video{ width:100%; height:150px; object-fit:cover; display:block }
.smallmuted{ color:var(--muted) }
</style>
</head>
<body>
<div class="container py-4">
  <?php flash_box(); ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="m-0"><?=h($ev['title'])?> — Yüklemeler</h4>
    <a class="btn btn-outline-secondary" href="index.php">← Çift paneli</a>
  </div>

  <div class="card-lite p-3 mb-3">
    <form class="row g-2" method="get">
      <div class="col-md-6"><input class="form-control" name="q" value="<?=h($q)?>" placeholder="Ad veya dosya adı ara"></div>
      <div class="col-md-6 d-flex gap-2">
        <button class="btn btn-outline-secondary">Ara</button>
        <a class="btn btn-outline-secondary" href="list.php">Temizle</a>
      </div>
    </form>
  </div>

  <form method="post" class="card-lite p-3">
    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
    <div class="d-flex flex-wrap gap-2 mb-2">
      <button name="do" value="zip" class="btn btn-zs">Seçilenleri ZIP indir</button>
      <button name="do" value="delete" class="btn btn-danger" onclick="return confirm('Seçilenler silinsin mi?')">Seçilenleri Sil</button>
    </div>

    <?php if(!$rows): ?>
      <div class="smallmuted">Kayıt yok.</div>
    <?php else: ?>
      <div class="row g-3">
        <?php foreach($rows as $r):
          $p='/'.$r['file_path']; $img=is_image($r['mime']); $vid=is_video($r['mime']); ?>
          <div class="col-6 col-md-4 col-lg-3">
            <label class="w-100">
              <input type="checkbox" class="form-check-input me-2" name="ids[]" value="<?=$r['id']?>">
              <div class="thumb mt-1">
                <?php if($img): ?><img src="<?=h($p)?>" alt="">
                <?php elseif($vid): ?><video src="<?=h($p)?>" muted></video>
                <?php else: ?><div class="p-4 text-center smallmuted">Dosya</div><?php endif; ?>
              </div>
              <div class="d-flex justify-content-between align-items-center mt-1">
                <span class="small text-truncate" title="<?=h($r['guest_name'])?>"><?=h($r['guest_name'])?></span>
                <a class="btn btn-sm btn-outline-secondary" href="<?=h($p)?>" download>indir</a>
              </div>
              <div class="small smallmuted"><?=number_format($r['file_size']/1048576,1)?> MB • <?=date('d.m.Y H:i',strtotime($r['created_at']))?></div>
            </label>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </form>
</div>
</body></html>
