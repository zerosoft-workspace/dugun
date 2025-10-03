<?php
// admin/dashboard.php — kampanya input, arama/filtre, soft-delete, QR PDF, çift hesabı
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/auth.php';

require_admin();
install_schema();

$me = admin_user();

/* Salon doğrulaması */
if (function_exists('require_current_venue_or_redirect')) {
  $venue = require_current_venue_or_redirect();
  $VID   = (int)$venue['id'];
  $VNAME = $venue['name'];
  $VSLUG = $venue['slug'];
} else {
  if (empty($_SESSION['venue_id'])) { redirect(BASE_URL.'/admin/venues.php'); }
  $VID   = (int)$_SESSION['venue_id'];
  $VNAME = $_SESSION['venue_name'] ?? 'Salon';
  $VSLUG = $_SESSION['venue_slug'] ?? '';
}

/* ---- Migrasyonlar (gerekirse) ---- */
try { pdo()->query("SELECT event_date FROM events LIMIT 1"); }
catch(Throwable $e){ try{ pdo()->exec("ALTER TABLE events ADD COLUMN event_date DATE NULL"); }catch(Throwable $e2){} }

try { pdo()->query("SELECT 1 FROM campaigns LIMIT 1"); }
catch(Throwable $e){
  try{
    pdo()->exec("
      CREATE TABLE campaigns (
        id INT AUTO_INCREMENT PRIMARY KEY,
        venue_id INT NOT NULL,
        name VARCHAR(190) NOT NULL,
        type VARCHAR(120) NOT NULL,
        description TEXT NULL,
        price INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE CASCADE,
        INDEX (venue_id, is_active)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
  }catch(Throwable $e2){}
}

/* ---- Kampanya işlemleri ---- */
if (($_POST['do'] ?? '') === 'camp_create') {
  csrf_or_die();
  $name = trim($_POST['name'] ?? '');
  $type = trim($_POST['type'] ?? '');
  $desc = trim($_POST['description'] ?? '');
  $price= max(0,(int)($_POST['price'] ?? 0));
  if(!$name || !$type){ flash('err','Kampanya adı ve türü gerekli.'); redirect($_SERVER['PHP_SELF'].'#cmp'); }
  pdo()->prepare("INSERT INTO campaigns (venue_id,name,type,description,price,is_active,created_at) VALUES (?,?,?,?,?,1,?)")
      ->execute([$VID,$name,$type,$desc,$price, now()]);
  flash('ok','Kampanya eklendi.');
  redirect($_SERVER['PHP_SELF'].'#cmp');
}

if (($_POST['do'] ?? '') === 'camp_update') {
  csrf_or_die();
  $id    = (int)($_POST['id'] ?? 0);
  $name  = trim($_POST['name'] ?? '');
  $type  = trim($_POST['type'] ?? '');
  $desc  = trim($_POST['description'] ?? '');
  $price = max(0,(int)($_POST['price'] ?? 0));
  $act   = isset($_POST['is_active']) ? 1 : 0;
  pdo()->prepare("UPDATE campaigns SET name=?, type=?, description=?, price=?, is_active=?, updated_at=? WHERE id=? AND venue_id=?")
      ->execute([$name,$type,$desc,$price,$act, now(), $id, $VID]);
  flash('ok','Kampanya güncellendi.');
  redirect($_SERVER['PHP_SELF'].'#cmp');
}

if (($_POST['do'] ?? '') === 'camp_toggle') {
  csrf_or_die();
  $id=(int)($_POST['id'] ?? 0);
  pdo()->prepare("UPDATE campaigns SET is_active=1-is_active, updated_at=? WHERE id=? AND venue_id=?")
      ->execute([now(), $id, $VID]);
  redirect($_SERVER['PHP_SELF'].'#cmp');
}

/* ---- Etkinlik işlemleri (ÇİFT HESABI İLE) ---- */
if (($_POST['do'] ?? '') === 'create_event') {
  csrf_or_die();

  $title = trim($_POST['title'] ?? '');
  $date  = trim($_POST['event_date'] ?? '');
  $email = trim($_POST['couple_email'] ?? '');
  $pass  = (string)($_POST['couple_pass'] ?? '');
  $force_reset = isset($_POST['force_reset']) ? 1 : 0;

  if(!$title){ flash('err','Başlık gerekli.'); redirect($_SERVER['PHP_SELF'].'#ev'); }
  if(!filter_var($email, FILTER_VALIDATE_EMAIL)){ flash('err','Geçerli bir e-posta girin.'); redirect($_SERVER['PHP_SELF'].'#ev'); }
  if(strlen($pass) < 6){ flash('err','Geçici şifre en az 6 karakter olmalı.'); redirect($_SERVER['PHP_SELF'].'#ev'); }

  // salon gerçekten var mı (aktif)?
  $st = pdo()->prepare("SELECT id FROM venues WHERE id=? AND is_active=1");
  $st->execute([$VID]);
  if(!$st->fetch()){
    flash('err','Geçersiz veya pasif salon. Lütfen salon seçin.');
    redirect(BASE_URL.'/admin/venues.php');
  }

  // slug benzersizliği
  $slug = slugify($title); if ($slug==='') $slug = 'event-'.bin2hex(random_bytes(3));
  $base=$slug; $i=1;
  while(true){
    $chk=pdo()->prepare("SELECT id FROM events WHERE venue_id=? AND slug=?"); $chk->execute([$VID,$slug]);
    if(!$chk->fetch()) break; $slug=$base.'-'.$i++;
  }

  // aynı e-posta başka etkinlikte kullanılmış mı? (isteğe bağlı engel)
  $same = pdo()->prepare("SELECT id FROM events WHERE couple_username=? LIMIT 1");
  $same->execute([$email]);
  if($same->fetch()){
    flash('err','Bu e-posta başka bir etkinlik için tanımlı. Lütfen farklı bir e-posta kullanın.');
    redirect($_SERVER['PHP_SELF'].'#ev');
  }

  $key   = bin2hex(random_bytes(16));
  $pcol  = defined('THEME_PRIMARY_DEFAULT') ? THEME_PRIMARY_DEFAULT : '#0ea5b5';
  $acol  = defined('THEME_ACCENT_DEFAULT')  ? THEME_ACCENT_DEFAULT  : '#e0f7fb';
  $hash  = password_hash($pass, PASSWORD_DEFAULT);

  // insert
  try {
    $sql="
      INSERT INTO events
        (venue_id,user_id,title,slug,couple_panel_key,theme_primary,theme_accent,event_date,created_at,
         couple_username,couple_password_hash,couple_force_reset,contact_email)
      VALUES
        (?,?,?,?,?,?,?,?,?,
         ?,?,?,?)
    ";
    pdo()->prepare($sql)->execute([
      $VID, ($me['id'] ?? null), $title, $slug, $key, $pcol, $acol, ($date?:null), now(),
      $email, $hash, $force_reset, $email
    ]);
  } catch(Throwable $e){
    // eski şema desteği (user_id yoksa)
    $sql="
      INSERT INTO events
        (venue_id,title,slug,couple_panel_key,theme_primary,theme_accent,event_date,created_at,
         couple_username,couple_password_hash,couple_force_reset,contact_email)
      VALUES
        (?,?,?,?,?,?,?,?,
         ?,?,?,?)
    ";
    pdo()->prepare($sql)->execute([
      $VID, $title, $slug, $key, $pcol, $acol, ($date?:null), now(),
      $email, $hash, $force_reset, $email
    ]);
  }

  // mail gönder
  $loginUrl = BASE_URL.'/couple/login.php?event='.pdo()->lastInsertId();
  $html = '<h3>'.h(APP_NAME).'</h3>
           <p>Çift paneliniz oluşturuldu.</p>
           <p><b>Kullanıcı adı (e-posta):</b> '.h($email).'<br>
              <b>Geçici şifre:</b> '.h($pass).'</p>
           <p><a href="'.h($loginUrl).'">Panele giriş</a></p>'.
           ($force_reset ? '<p>İlk girişte şifrenizi değiştirmeniz istenecektir.</p>' : '');
  send_mail_simple($email, 'Çift Panel Giriş Bilgileriniz', $html);

  flash('ok','Düğün oluşturuldu ve çift hesabı tanımlandı.');
  redirect($_SERVER['PHP_SELF'].'#ev');
}

/* Aktif-pasif (toggle) */
if (($_GET['do'] ?? '')==='toggle' && isset($_GET['id'])) {
  $eid=(int)$_GET['id'];
  pdo()->prepare("UPDATE events SET is_active=1-is_active WHERE id=? AND venue_id=?")->execute([$eid,$VID]);
  redirect($_SERVER['PHP_SELF'].'#ev');
}

/* Soft-delete (Sil görünür ama pasife alır) — iki kez onay JS ile */
if (($_POST['do'] ?? '')==='soft_delete' && isset($_POST['event_id'])) {
  csrf_or_die();
  $eid = (int)$_POST['event_id'];
  pdo()->prepare("UPDATE events SET is_active=0 WHERE id=? AND venue_id=?")->execute([$eid,$VID]);
  flash('ok','Düğün pasife alındı (silindi olarak işaretlendi).');
  redirect($_SERVER['PHP_SELF'].'#ev');
}

/* ---- QR işlemleri ---- */
if (($_POST['do'] ?? '')==='create_qr'){
  csrf_or_die();
  $code=trim($_POST['code'] ?? ''); if ($code==='') $code='qr-'.bin2hex(random_bytes(3));
  $code=preg_replace('~[^a-zA-Z0-9_-]+~','',$code);
  try{
    pdo()->prepare("INSERT INTO qr_codes (venue_id,code,created_at) VALUES (?,?,?)")->execute([$VID,$code,now()]);
    flash('ok','Kalıcı QR oluşturuldu.');
  }catch(Throwable $e){
    flash('err','Kod zaten var.');
  }
  redirect($_SERVER['PHP_SELF'].'#qr');
}
if (($_POST['do'] ?? '')==='bind_qr'){
  csrf_or_die();
  $qid=(int)($_POST['qr_id'] ?? 0); $eid=(int)($_POST['event_id'] ?? 0);
  pdo()->prepare("UPDATE qr_codes SET target_event_id=?, updated_at=? WHERE id=? AND venue_id=?")
      ->execute([$eid,now(),$qid,$VID]);
  flash('ok','QR hedefi güncellendi.');
  redirect($_SERVER['PHP_SELF'].'#qr');
}

/* ---- Filtreler (düğün listesi) ---- */
$q  = trim($_GET['q'] ?? '');
$df = trim($_GET['date_from'] ?? '');
$dt = trim($_GET['date_to'] ?? '');
$where = ["e.venue_id = ?"];
$args  = [$VID];
if ($q!==''){ $where[]="(e.title LIKE ? OR e.slug LIKE ?)"; $args[]='%'.$q.'%'; $args[]='%'.$q.'%'; }
if ($df!==''){ $where[]="(e.event_date IS NOT NULL AND e.event_date >= ?)"; $args[]=$df; }
if ($dt!==''){ $where[]="(e.event_date IS NOT NULL AND e.event_date <= ?)"; $args[]=$dt; }
// Sadece aktifleri göster
$where[] = "e.is_active = 1";
$W = implode(' AND ',$where);

/* ---- Veriler ---- */
$campaigns = pdo()->prepare("SELECT * FROM campaigns WHERE venue_id=? ORDER BY is_active DESC, id DESC");
$campaigns->execute([$VID]); $campaigns=$campaigns->fetchAll();

$sql = "
  SELECT e.*,
         (SELECT COUNT(*) FROM uploads u WHERE u.venue_id=e.venue_id AND u.event_id=e.id) AS file_count,
         (SELECT COALESCE(SUM(u.file_size),0) FROM uploads u WHERE u.venue_id=e.venue_id AND u.event_id=e.id) AS total_bytes
  FROM events e
  WHERE $W
  ORDER BY e.id DESC
";
$st = pdo()->prepare($sql); $st->execute($args); $events=$st->fetchAll();

$qr = pdo()->prepare("
  SELECT q.*, e.title AS ev_title
  FROM qr_codes q
  LEFT JOIN events e ON e.id=q.target_event_id
  WHERE q.venue_id=? ORDER BY q.id DESC
");
$qr->execute([$VID]); $qr=$qr->fetchAll();

function fmt_bytes($b){
  if ($b<=0) return '0 MB';
  $mb=$b/1048576; if ($mb<1024) return number_format($mb,1).' MB';
  return number_format($mb/1024,2).' GB';
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h(APP_NAME)?> — Panel (<?=h($VNAME)?>)</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  :root{ --zs:#0ea5b5; --zs-soft:#e0f7fb; --ink:#111827; --muted:#6b7280; }
  body{ background:linear-gradient(180deg,var(--zs-soft),#fff) no-repeat }
  .card-lite{ border:1px solid #eef2f7; border-radius:18px; background:#fff; box-shadow:0 6px 20px rgba(17,24,39,.05) }
  .btn-zs{ background:var(--zs); border:none; color:#fff; border-radius:12px; padding:.6rem 1rem; font-weight:600 }
  .btn-zs-outline{ background:#fff; border:1px solid var(--zs); color:var(--zs); border-radius:12px; font-weight:600 }
  .link-badge{ padding:.35rem .6rem; border-radius:10px; background:#f3f6fb; display:inline-block; }
  .muted{ color:var(--muted) }
  .title-chip{ padding:.35rem .7rem; border-radius:999px; background:#eef5ff; font-weight:600 }
  .qr-img{ width:96px; height:96px; border-radius:12px; border:1px solid #e5e7eb }
  .grid-compact .form-control, .grid-compact .form-select{ height:42px }
</style>
<script>
function confirmSoftDelete(){
  if(!confirm('Bu düğünü silmek istiyor musunuz?')) return false;
  return confirm('Emin misiniz? Silinirse panelden kaldırılacak (veriler silinmez).');
}
</script>
</head>
<body>
<nav class="navbar bg-white border-bottom">
  <div class="container">
    <a class="navbar-brand fw-semibold" href="<?=h(BASE_URL)?>"><?=h(APP_NAME)?></a>
    <div class="d-flex align-items-center gap-3 small">
      <span class="title-chip">Salon: <?=h($VNAME)?></span>
      <a class="text-decoration-none" href="<?=h(BASE_URL)?>/admin/venues.php">Salon Değiştir</a>
      <span>•</span>
      <span>Admin: <?= h($me['name'] ?? ($_SESSION['uname'] ?? 'Kullanıcı')) ?></span>
      <span>•</span>

      <a href="<?=h(BASE_URL)?>/admin/login.php?logout=1">Çıkış</a>
    </div>
  </div>
</nav>

<div class="container py-4">
  <?php flash_box(); ?>

  <!-- Kampanyalar -->
  <div id="cmp" class="card-lite p-3 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h5 class="m-0">Kampanyalar</h5>
      <span class="muted small">PyTR entegrasyonu çift panelinde yapılacak; burada yalnız fiyat girilir.</span>
    </div>
    <div class="row g-3 grid-compact">
      <div class="col-lg-5">
        <div class="p-3 border rounded-3">
          <h6 class="fw-semibold mb-3">Yeni Kampanya</h6>
          <form method="post" class="row g-2">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="do" value="camp_create">
            <div class="col-12">
              <label class="form-label">Ad</label>
              <input class="form-control" name="name" placeholder="Örn: En Mutlu Anlar">
            </div>
            <div class="col-12">
              <label class="form-label">Tür</label>
              <input class="form-control" name="type" placeholder="Örn: video_kolaj, slayt, premium">
            </div>
            <div class="col-12">
              <label class="form-label">Açıklama</label>
              <textarea class="form-control" name="description" rows="3" placeholder="Kısa açıklama"></textarea>
            </div>
            <div class="col-6">
              <label class="form-label">Fiyat (TL)</label>
              <input type="number" class="form-control" name="price" value="0" min="0">
            </div>
            <div class="col-12 d-grid mt-1">
              <button class="btn btn-zs">Ekle</button>
            </div>
          </form>
        </div>
      </div>
      <div class="col-lg-7">
        <?php if(!$campaigns): ?>
          <div class="muted">Henüz kampanya yok.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table align-middle">
              <thead><tr><th>Ad</th><th>Tür</th><th>Açıklama</th><th>Fiyat</th><th>Durum</th><th style="width:180px">İşlem</th></tr></thead>
              <tbody>
                <?php foreach($campaigns as $c): ?>
                  <tr>
                    <td class="fw-semibold"><?=h($c['name'])?></td>
                    <td><?=h($c['type'])?></td>
                    <td class="small"><?=h($c['description'])?></td>
                    <td><?= (int)$c['price'] ?> TL</td>
                    <td><?= $c['is_active']?'<span class="badge bg-success">Aktif</span>':'<span class="badge bg-secondary">Pasif</span>' ?></td>
                    <td>
                      <form method="post" class="d-inline">
                        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                        <input type="hidden" name="do" value="camp_toggle">
                        <input type="hidden" name="id" value="<?=$c['id']?>">
                        <button class="btn btn-sm btn-outline-secondary"><?= $c['is_active']?'Pasifleştir':'Aktifleştir' ?></button>
                      </form>
                      <button class="btn btn-sm btn-zs-outline" type="button" onclick="document.getElementById('edit<?= $c['id']?>').classList.toggle('d-none')">Düzenle</button>
                    </td>
                  </tr>
                  <tr id="edit<?= $c['id']?>" class="d-none">
                    <td colspan="6">
                      <form method="post" class="row g-2 grid-compact">
                        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                        <input type="hidden" name="do" value="camp_update">
                        <input type="hidden" name="id" value="<?=$c['id']?>">
                        <div class="col-md-3"><input class="form-control" name="name" value="<?=h($c['name'])?>"></div>
                        <div class="col-md-2"><input class="form-control" name="type" value="<?=h($c['type'])?>"></div>
                        <div class="col-md-4"><input class="form-control" name="description" value="<?=h($c['description'])?>"></div>
                        <div class="col-md-2"><input type="number" class="form-control" name="price" value="<?= (int)$c['price'] ?>"></div>
                        <div class="col-md-1 form-check d-flex align-items-center">
                          <input class="form-check-input" type="checkbox" name="is_active" <?= $c['is_active']?'checked':'' ?>>
                        </div>
                        <div class="col-12 d-grid"><button class="btn btn-zs">Kaydet</button></div>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Yeni Düğün + Çift hesabı -->
  <div id="ev" class="card-lite p-3 mb-4">
    <h5 class="mb-3">Yeni Düğün (<?=h($VNAME)?>)</h5>
    <form method="post" class="row g-2 grid-compact">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="do" value="create_event">

      <div class="col-md-6">
        <label class="form-label">Başlık</label>
        <input class="form-control" name="title" placeholder="Örn: Elif & Arda Düğünü" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Düğün Tarihi</label>
        <input type="date" class="form-control" name="event_date">
      </div>

      <div class="col-12 mt-2"><hr></div>

      <div class="col-md-6">
        <label class="form-label">Çift E-posta (kullanıcı adı)</label>
        <input type="email" class="form-control" name="couple_email" placeholder="musteri@ornek.com" required>
      </div>
      <div class="col-md-4">
        <label class="form-label">Geçici Şifre</label>
        <input type="text" class="form-control" name="couple_pass" placeholder="en az 6 karakter" required>
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="force_reset" id="fr" checked>
          <label class="form-check-label" for="fr">İlk girişte değiştir</label>
        </div>
      </div>

      <div class="col-md-2 d-grid align-items-end">
        <button class="btn btn-zs" style="margin-top:30px">Oluştur</button>
      </div>
    </form>
  </div>

  <!-- Arama / Filtre -->
  <div class="card-lite p-3 mb-3">
    <form class="row g-2 grid-compact" method="get">
      <div class="col-md-6">
        <label class="form-label">Başlık</label>
        <input class="form-control" name="q" value="<?=h($q)?>" placeholder="Başlık/slug ara…">
      </div>
      <div class="col-md-3">
        <label class="form-label">Tarih (Başlangıç)</label>
        <input type="date" class="form-control" name="date_from" value="<?=h($df)?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Tarih (Bitiş)</label>
        <input type="date" class="form-control" name="date_to" value="<?=h($dt)?>">
      </div>
      <div class="col-12 d-flex gap-2">
        <button class="btn btn-zs-outline">Filtrele</button>
        <a class="btn btn-outline-secondary" href="<?=h($_SERVER['PHP_SELF'])?>#ev">Temizle</a>
      </div>
    </form>
  </div>

  <!-- Düğün Listesi (aktifler) -->
  <div class="card-lite p-3 mb-4">
    <h5 class="mb-3">Düğünlerim</h5>
    <?php if(!$events): ?>
      <div class="muted">Kayıt bulunamadı.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead>
            <tr>
              <th>Başlık</th>
              <th>Tarih</th>
              <th>Linkler</th>
              <th class="text-center">Dosya</th>
              <th class="text-center">Toplam Boyut</th>
              <th>Durum</th>
              <th style="width:90px">Sil</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($events as $e):
              $pub = public_upload_url($e['id']);
              $couple_link = BASE_URL.'/couple/index.php?event='.$e['id'].'&key='.$e['couple_panel_key'];
            ?>
              <tr>
                <td class="fw-semibold">
                  <?=h($e['title'])?>
                  <div class="small muted"><?=h($e['slug'])?></div>
                </td>
                <td class="small"><?= $e['event_date'] ? h($e['event_date']) : '—' ?></td>
                <td class="small">
                  <a class="link-badge text-decoration-none" target="_blank" href="<?=h($pub)?>">Misafir Yükleme</a>
                  <a class="link-badge text-decoration-none" target="_blank" href="<?=h($couple_link)?>">Çift Paneli</a>
                </td>
                <td class="text-center"><span class="badge bg-secondary"><?= (int)$e['file_count'] ?></span></td>
                <td class="text-center"><?= fmt_bytes((int)$e['total_bytes']) ?></td>
                <td>
                  <a class="btn btn-sm <?= $e['is_active']?'btn-success':'btn-outline-secondary' ?>" href="?do=toggle&id=<?=$e['id']?>#ev">
                    <?= $e['is_active']?'Aktif':'Pasif' ?>
                  </a>
                </td>
                <td>
                  <form method="post" onsubmit="return confirmSoftDelete()">
                    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="do" value="soft_delete">
                    <input type="hidden" name="event_id" value="<?=$e['id']?>">
                    <button class="btn btn-sm btn-outline-danger w-100">Sil</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Kalıcı QR Yönetimi -->
  <div id="qr" class="card-lite p-3 mb-4">
    <h5 class="mb-3">Kalıcı QR Kodlar</h5>
    <form method="post" class="row g-2 align-items-end mb-3 grid-compact">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="do" value="create_qr">
      <div class="col-md-5">
        <label class="form-label">Kod (opsiyonel)</label>
        <input name="code" class="form-control" placeholder="salon-brosur-2025">
      </div>
      <div class="col-md-3 d-grid">
        <button class="btn btn-zs-outline">QR Oluştur</button>
      </div>
      <div class="col-md-4 small muted">
        Broşür URL: <code>/qr.php?code=&lt;KOD&gt;&amp;v=<?=h($VSLUG)?></code>
      </div>
    </form>

    <?php if(!$qr): ?>
      <div class="muted">QR kod yok.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr><th>Kod</th><th>Önizleme</th><th>Bağlı Etkinlik</th><th style="width:420px">Hedefi Değiştir</th><th style="width:160px">PDF</th></tr></thead>
          <tbody>
            <?php foreach($qr as $q):
              $qrLink = BASE_URL.'/qr.php?code='.rawurlencode($q['code']).'&v='.rawurlencode($VSLUG);
              $img    = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data='.rawurlencode($qrLink);
            ?>
              <tr>
                <td>
                  <div class="fw-semibold"><?=h($q['code'])?></div>
                  <div class="small"><a target="_blank" href="<?=h($qrLink)?>">Kalıcı URL</a></div>
                </td>
                <td><img class="qr-img" src="<?=h($img)?>" alt="qr"></td>
                <td><?= $q['ev_title'] ? h($q['ev_title']) : '<span class="muted">—</span>' ?></td>
                <td>
                  <form method="post" class="row g-2 grid-compact">
                    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="do" value="bind_qr">
                    <input type="hidden" name="qr_id" value="<?=$q['id']?>">
                    <div class="col-md-8">
                      <select name="event_id" class="form-select" required>
                        <option value="">Seçiniz…</option>
                        <?php foreach($events as $ev): ?>
                          <option value="<?=$ev['id']?>" <?= $q['target_event_id']==$ev['id']?'selected':'' ?>>
                            <?=h($ev['title'])?> <?= $ev['event_date']?'('.h($ev['event_date']).')':'' ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-4 d-grid">
                      <button class="btn btn-zs">Kaydet</button>
                    </div>
                  </form>
                </td>
                <td>
                  <a class="btn btn-sm btn-zs-outline w-100" target="_blank"
                     href="<?=h(BASE_URL)?>/qr_pdf.php?code=<?=rawurlencode($q['code'])?>&v=<?=rawurlencode($VSLUG)?>">
                     QR PDF İndir
                  </a>
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
