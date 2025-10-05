<?php
// admin/dashboard.php — kampanya input, arama/filtre, soft-delete, QR PDF, çift hesabı
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/partials/ui.php';

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

/* ---- Etkinlik işlemleri (ÇİFT HESABI İLE) ---- */
if (($_POST['do'] ?? '') === 'create_event') {
  csrf_or_die();

  $title = trim($_POST['title'] ?? '');
  $date  = trim($_POST['event_date'] ?? '');
  $email = trim($_POST['couple_email'] ?? '');
  $force_reset = 1;

  if(!$title){ flash('err','Başlık gerekli.'); redirect($_SERVER['PHP_SELF'].'#ev'); }
  if(!filter_var($email, FILTER_VALIDATE_EMAIL)){ flash('err','Geçerli bir e-posta girin.'); redirect($_SERVER['PHP_SELF'].'#ev'); }

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

  $key   = bin2hex(random_bytes(16));
  $pcol  = defined('THEME_PRIMARY_DEFAULT') ? THEME_PRIMARY_DEFAULT : '#0ea5b5';
  $acol  = defined('THEME_ACCENT_DEFAULT')  ? THEME_ACCENT_DEFAULT  : '#e0f7fb';
  $plain_pass = substr(bin2hex(random_bytes(8)),0,12);
  $hash  = password_hash($plain_pass, PASSWORD_DEFAULT);

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

  $eventId = (int)pdo()->lastInsertId();
  if ($eventId) {
    couple_set_account($eventId, $email, $plain_pass);
  }

  flash('ok','Etkinlik oluşturuldu ve giriş bilgileri e-posta ile gönderildi.');
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
  flash('ok','Etkinlik pasife alındı (silindi olarak işaretlendi).');
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

/* ---- Filtreler (etkinlik listesi) ---- */
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
<?=admin_base_styles()?>
<style>
  .qr-img{ width:96px; height:96px; border-radius:14px; border:1px solid rgba(148,163,184,.32); background:#fff; object-fit:cover; }
  .grid-compact .form-control, .grid-compact .form-select{ height:46px; }
  .section-heading{ display:flex; justify-content:space-between; align-items:center; gap:1rem; margin-bottom:1rem; }
  .section-heading h5{ margin:0; font-weight:700; }
  .muted{ color:var(--muted); }
  .link-badge{ padding:.4rem .75rem; border-radius:12px; background:rgba(14,165,181,.12); font-weight:600; color:var(--brand-dark); display:inline-flex; align-items:center; gap:.4rem; }
  .btn-zs{ background:var(--brand); border:none; color:#fff; border-radius:12px; font-weight:600; }
  .btn-zs:hover{ background:var(--brand-dark); color:#fff; }
  .btn-zs-outline{ background:#fff; border:1px solid rgba(14,165,181,.55); color:var(--brand); border-radius:12px; font-weight:600; }
  .btn-zs-outline:hover{ background:rgba(14,165,181,.12); color:var(--brand-dark); }
</style>
<script>
function confirmSoftDelete(){
  if(!confirm('Bu etkinliği silmek istiyor musunuz?')) return false;
  return confirm('Emin misiniz? Silinirse panelden kaldırılacak (veriler silinmez).');
}
</script>
</head>
<body class="admin-body">
<?php admin_layout_start('dashboard', 'Panel Genel Bakış', 'Salon: '.$VNAME.' • Etkinlik ve QR yönetimi'); ?>

    <?php flash_box(); ?>

  <!-- Yeni Etkinlik -->
  <div id="ev" class="card-lite mb-4">
    <div class="section-heading">
      <h5>Yeni Etkinlik Oluştur</h5>
      <a class="btn btn-zs-outline" href="<?=h(BASE_URL)?>/admin/campaigns.php">Kampanyaları Yönet</a>
    </div>
    <p class="muted small mb-4">E-posta alanına yazdığınız kullanıcıya otomatik olarak geçici şifre gönderilir ve ilk girişte şifre yenilemesi istenir.</p>
    <form method="post" class="row g-3 grid-compact">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="do" value="create_event">
      <div class="col-md-4">
        <label class="form-label">Etkinlik Başlığı</label>
        <input class="form-control" name="title" placeholder="Örn: Bahar Daveti" required>
      </div>
      <div class="col-md-4">
        <label class="form-label">Çift E-posta Adresi</label>
        <input class="form-control" name="couple_email" type="email" placeholder="ornek@eposta.com" required>
      </div>
      <div class="col-md-4">
        <label class="form-label">Etkinlik Tarihi</label>
        <input class="form-control" name="event_date" type="date" value="<?=h(date('Y-m-d'))?>">
      </div>
      <div class="col-12">
        <button class="btn btn-zs" type="submit">Etkinliği Oluştur ve Davet Gönder</button>
      </div>
    </form>
  </div>

  <!-- Etkinlik Listesi -->
  <div class="card-lite mb-4">
    <div class="section-heading">
      <h5>Etkinliklerim</h5>
      <form class="d-flex align-items-center gap-2" method="get">
        <input type="date" class="form-control" name="date_from" value="<?=h($df)?>" placeholder="Başlangıç">
        <input type="date" class="form-control" name="date_to" value="<?=h($dt)?>" placeholder="Bitiş">
        <input type="search" class="form-control" name="q" value="<?=h($q)?>" placeholder="Başlık veya kısa adres">
        <button class="btn btn-zs-outline" type="submit">Filtrele</button>
      </form>
    </div>
    <?php if(!$events): ?>
      <div class="muted">Henüz etkinlik oluşturulmadı.</div>
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
                  <a class="link-badge text-decoration-none" target="_blank" href="<?=h($couple_link)?>">Etkinlik Paneli</a>
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
  <div id="qr" class="card-lite mb-4">
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

<?php admin_layout_end(); ?>
</body>
</html>
