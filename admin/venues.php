<?php
// admin/venues.php — Salon listesi, ekleme, düzenleme, pasif/aktif, seçim + ARAMA/FİLTRE + Geçmiş günleri gör
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/partials/ui.php';

require_admin();
install_schema();

/* --- İşlemler (POST) --- */
$action = $_POST['do'] ?? '';

if ($action === 'create') {
  csrf_or_die();
  $name = trim($_POST['name'] ?? '');
  $slug = slugify($_POST['slug'] ?? $name);
  if ($name === '') {
    flash('err','Salon adı gerekli.');
    redirect($_SERVER['PHP_SELF']);
  }
  // slug benzersizleştir
  $base = $slug ?: 'salon-'.bin2hex(random_bytes(2));
  $slug = $base; $i = 1;
  while (true) {
    $st = pdo()->prepare("SELECT id FROM venues WHERE slug=? LIMIT 1");
    $st->execute([$slug]);
    if (!$st->fetch()) break;
    $slug = $base.'-'.$i++;
  }
  pdo()->prepare("INSERT INTO venues (name, slug, created_at, is_active) VALUES (?,?,?,1)")
      ->execute([$name, $slug, now()]);
  flash('ok','Salon eklendi.');
  redirect($_SERVER['PHP_SELF']);
}

if ($action === 'update') {
  csrf_or_die();
  $id   = (int)($_POST['id'] ?? 0);
  $name = trim($_POST['name'] ?? '');
  $slug = slugify($_POST['slug'] ?? $name);
  if ($name === '') { flash('err','Salon adı gerekli.'); redirect($_SERVER['PHP_SELF']); }

  // slug benzersiz (kendi hariç)
  $st = pdo()->prepare("SELECT id FROM venues WHERE slug=? AND id<>? LIMIT 1");
  $st->execute([$slug,$id]);
  if ($st->fetch()) {
    flash('err','Bu slug zaten kullanılıyor.');
    redirect($_SERVER['PHP_SELF']);
  }
  pdo()->prepare("UPDATE venues SET name=?, slug=? WHERE id=?")->execute([$name,$slug,$id]);

  // Seçili salon ise session tazele
  if (!empty($_SESSION['venue_id']) && (int)$_SESSION['venue_id'] === $id) {
    $_SESSION['venue_name'] = $name;
    $_SESSION['venue_slug'] = $slug;
  }

  flash('ok','Salon güncellendi.');
  redirect($_SERVER['PHP_SELF']);
}

if ($action === 'toggle') {
  csrf_or_die();
  $id = (int)($_POST['id'] ?? 0);
  pdo()->prepare("UPDATE venues SET is_active = 1 - is_active WHERE id=?")->execute([$id]);

  // Pasife alınan seçili salon ise seçimi temizle
  if (!empty($_SESSION['venue_id']) && (int)$_SESSION['venue_id'] === $id) {
    $st = pdo()->prepare("SELECT is_active FROM venues WHERE id=?");
    $st->execute([$id]);
    $is_active = (int)($st->fetchColumn() ?: 0);
    if ($is_active === 0) {
      unset($_SESSION['venue_id'], $_SESSION['venue_name'], $_SESSION['venue_slug']);
    }
  }

  flash('ok','Durum güncellendi.');
  redirect($_SERVER['PHP_SELF']);
}

if ($action === 'select') {
  csrf_or_die();
  $id = (int)($_POST['id'] ?? 0);
  $st = pdo()->prepare("SELECT id,name,slug,is_active FROM venues WHERE id=? LIMIT 1");
  $st->execute([$id]);
  $row = $st->fetch();
  if (!$row) {
    flash('err','Salon bulunamadı.');
    redirect($_SERVER['PHP_SELF']);
  }
  if ((int)$row['is_active'] !== 1) {
    flash('err','Bu salon pasif. Lütfen aktif bir salon seçin.');
    redirect($_SERVER['PHP_SELF']);
  }

  // Seç ve session'a yaz
  $_SESSION['venue_id']   = (int)$row['id'];
  $_SESSION['venue_name'] = $row['name'];
  $_SESSION['venue_slug'] = $row['slug'];
  flash('ok','Salon seçildi.');

  // “Geçmiş günleri gör” butonundan mı gelindi?
  if (!empty($_POST['goto_events'])) {
    redirect('venue_events.php');   // doğrudan salonun etkinlik listesine
  } else {
    redirect('dashboard.php');      // varsayılan: panele
  }
}

/* --- ARAMA / FİLTRE (GET) --- */
$q      = trim($_GET['q'] ?? '');       // ad/slug arama
$status = $_GET['status'] ?? '';        // '', '1' (aktif), '0' (pasif)
$order  = $_GET['order'] ?? 'new';      // 'new' | 'name_az' | 'name_za'

$where = [];
$args  = [];

if ($q !== '') {
  $where[] = "(name LIKE ? OR slug LIKE ?)";
  $args[] = '%'.$q.'%';
  $args[] = '%'.$q.'%';
}
if ($status === '1' || $status === '0') {
  $where[] = "is_active = ?";
  $args[]  = (int)$status;
}
$W = $where ? ('WHERE '.implode(' AND ',$where)) : '';

switch ($order) {
  case 'name_az': $ORDER = 'ORDER BY name ASC, id DESC'; break;
  case 'name_za': $ORDER = 'ORDER BY name DESC, id DESC'; break;
  default:        $ORDER = 'ORDER BY id DESC'; break; // new
}

/* --- Veriler --- */
$st = pdo()->prepare("SELECT * FROM venues $W $ORDER");
$st->execute($args);
$venues = $st->fetchAll();

$venueTotals = [
  'all'    => count($venues),
  'active' => 0,
  'passive'=> 0,
];
foreach ($venues as $venueRow) {
  if (!empty($venueRow['is_active'])) {
    $venueTotals['active']++;
  } else {
    $venueTotals['passive']++;
  }
}

?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h(APP_NAME)?> — Düğün Salonları</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?=admin_base_styles()?>
<style>
  .grid-compact .form-control, .grid-compact .form-select { height:46px; }
  .muted { color:var(--muted); }
  .btn-zs { background:var(--brand); border:none; color:#fff; border-radius:12px; padding:.55rem 1rem; font-weight:600; }
  .btn-zs:hover { background:var(--brand-dark); color:#fff; }
  .btn-zs-outline { background:#fff; border:1px solid rgba(14,165,181,.55); color:var(--brand); border-radius:12px; font-weight:600; }
  .btn-zs-outline:hover { background:rgba(14,165,181,.12); color:var(--brand-dark); }

  .stats-row .stat-card {
    position:relative;
    border-radius:22px;
    background:var(--admin-surface);
    border:1px solid rgba(15,23,42,.06);
    padding:22px 24px 20px;
    box-shadow:0 28px 60px -38px rgba(14,165,181,.45);
    overflow:hidden;
    display:flex;
    flex-direction:column;
    gap:14px;
    min-height:158px;
  }
  .stats-row .stat-card::after {
    content:"";
    position:absolute;
    inset:auto 18px -42px auto;
    width:120px;
    height:120px;
    background:radial-gradient(circle at center, rgba(14,165,181,.22), transparent 68%);
    transform:rotate(18deg);
    opacity:.85;
  }
  .stats-row > div:nth-child(2) .stat-card {
    background:linear-gradient(160deg, rgba(14,165,181,.14), rgba(255,255,255,.94));
  }
  .stats-row > div:nth-child(3) .stat-card {
    background:linear-gradient(160deg, rgba(248,113,113,.12), rgba(255,255,255,.96));
  }
  .stats-row .stat-top {
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:10px;
  }
  .stats-row .stat-icon {
    width:46px;
    height:46px;
    border-radius:16px;
    background:rgba(14,165,181,.14);
    color:var(--admin-brand-dark);
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:1.35rem;
    box-shadow:0 18px 32px -22px rgba(14,165,181,.55);
  }
  .stats-row .stat-label {
    font-size:.8rem;
    color:var(--admin-muted);
    text-transform:uppercase;
    letter-spacing:.08em;
    font-weight:600;
  }
  .stats-row .stat-value {
    font-size:2.05rem;
    font-weight:700;
    color:var(--admin-ink);
    line-height:1.1;
  }
  .stats-row .stat-chip {
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:.35rem 1rem;
    border-radius:999px;
    background:rgba(14,165,181,.1);
    color:var(--admin-brand-dark);
    font-size:.78rem;
    font-weight:600;
    width:max-content;
  }
  .stats-row .stat-chip i { font-size:1rem; }

  [data-theme="dark"] .stats-row .stat-card {
    background:linear-gradient(155deg, rgba(14,165,181,.18), rgba(2,6,23,.9));
    border:1px solid rgba(56,189,248,.28);
    box-shadow:0 40px 80px -50px rgba(8,47,73,.75);
  }
  [data-theme="dark"] .stats-row > div:nth-child(3) .stat-card {
    background:linear-gradient(155deg, rgba(248,113,113,.22), rgba(2,6,23,.9));
  }
  [data-theme="dark"] .stats-row .stat-label { color:rgba(226,232,240,.72); }
  [data-theme="dark"] .stats-row .stat-value { color:#f8fafc; }
  [data-theme="dark"] .stats-row .stat-chip {
    background:rgba(148,163,184,.14);
    color:#e2e8f0;
  }
  [data-theme="dark"] .stats-row .stat-icon {
    background:rgba(15,23,42,.45);
    color:#38bdf8;
    box-shadow:none;
  }

  .filter-card { background:var(--admin-surface); border-radius:20px; border:1px solid rgba(15,23,42,.06); box-shadow:0 24px 45px -32px rgba(15,23,42,.28); }
  .filter-card h5 { margin-bottom:14px; }
  .filter-actions { display:flex; gap:10px; flex-wrap:wrap; }

  .venue-table thead th { white-space:nowrap; }
  .venue-table .badge-status { border-radius:999px; padding:.35rem .85rem; font-size:.75rem; font-weight:600; }
  .venue-table .badge-active { background:rgba(34,197,94,.18); color:#15803d; }
  .venue-table .badge-passive { background:rgba(248,113,113,.18); color:#b91c1c; }
  .venue-table .action-group {
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(160px, 1fr));
    gap:10px;
    align-items:start;
  }
  .venue-table .action-group form {
    display:flex;
    width:100%;
  }
  .venue-table .action-group .btn {
    width:100%;
    border-radius:12px;
    font-weight:600;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    padding:.55rem .85rem;
  }
  .btn-brand-outline {
    background:rgba(14,165,181,.08);
    border:1px solid rgba(14,165,181,.18);
    color:var(--admin-brand-dark);
  }
  .btn-brand-outline:hover { background:rgba(14,165,181,.16); color:#0c4a6e; }
  .btn-ghost {
    background:rgba(15,23,42,.04);
    border:1px solid rgba(15,23,42,.08);
    color:var(--admin-ink);
  }
  .btn-ghost:hover { background:rgba(14,165,181,.14); color:var(--admin-brand-dark); }
  [data-theme="dark"] .filter-card {
    background:rgba(15,23,42,.92);
    border-color:rgba(56,189,248,.18);
    box-shadow:0 36px 70px -48px rgba(8,47,73,.7);
  }
  [data-theme="dark"] .venue-table .action-group .btn-zs-outline {
    background:rgba(2,6,23,.6);
    border-color:rgba(148,163,184,.35);
    color:#38bdf8;
  }
  [data-theme="dark"] .venue-table .action-group .btn-zs-outline:hover { background:rgba(56,189,248,.14); color:#0f172a; }
  [data-theme="dark"] .btn-brand-outline {
    background:rgba(15,23,42,.65);
    border-color:rgba(56,189,248,.35);
    color:#38bdf8;
  }
  [data-theme="dark"] .btn-brand-outline:hover {
    background:rgba(56,189,248,.2);
    color:#04121f;
  }
  [data-theme="dark"] .btn-ghost {
    background:rgba(15,23,42,.65);
    border-color:rgba(148,163,184,.3);
    color:#e2e8f0;
  }
  [data-theme="dark"] .btn-ghost:hover { background:rgba(56,189,248,.16); color:#04121f; }
</style>
<script>
function askToggle(){
  if(!confirm('Bu salonu aktifleştirmek/pasifleştirmek istiyor musunuz?')) return false;
  return confirm('Emin misiniz? Pasife alınırsa panelde kullanılamaz.');
}
</script>
</head>
<body class="admin-body">
<?php admin_layout_start('venues', 'Düğün Salonları', 'Salon portföyünüzü yönetin ve etkinliklerinizi organize edin.'); ?>

  <?php flash_box(); ?>

  <div class="row g-3 stats-row mb-4">
    <div class="col-md-4">
      <div class="stat-card">
        <div class="stat-top">
          <span class="stat-label">Toplam Salon</span>
          <span class="stat-icon"><i class="bi bi-buildings"></i></span>
        </div>
        <span class="stat-value"><?=$venueTotals['all']?></span>
        <span class="stat-chip"><i class="bi bi-stars"></i>Portföyde</span>
      </div>
    </div>
    <div class="col-md-4">
      <div class="stat-card">
        <div class="stat-top">
          <span class="stat-label">Aktif Salon</span>
          <span class="stat-icon"><i class="bi bi-lightning-charge-fill"></i></span>
        </div>
        <span class="stat-value text-success"><?=$venueTotals['active']?></span>
        <span class="stat-chip"><i class="bi bi-check2-circle"></i>Panelde yayında</span>
      </div>
    </div>
    <div class="col-md-4">
      <div class="stat-card">
        <div class="stat-top">
          <span class="stat-label">Pasif Salon</span>
          <span class="stat-icon"><i class="bi bi-archive"></i></span>
        </div>
        <span class="stat-value text-danger"><?=$venueTotals['passive']?></span>
        <span class="stat-chip"><i class="bi bi-hourglass"></i>Arşivde bekleyen</span>
      </div>
    </div>
  </div>

  <div class="row g-4 mb-4">
    <div class="col-lg-5">
      <div class="card-lite p-4 h-100">
        <h5 class="mb-3">Yeni Düğün Salonu Ekle</h5>
        <p class="small text-muted mb-4">Markanıza yeni salonlar ekleyin ve ekiplerinizi tek tıkla yetkilendirin.</p>
        <form method="post" class="row g-3 grid-compact">
          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="do" value="create">
          <div class="col-12">
            <label class="form-label">Salon Adı</label>
            <input class="form-control" name="name" placeholder="Örn: Zerosoft Garden" required>
          </div>
          <div class="col-12">
            <label class="form-label">Slug (opsiyonel)</label>
            <input class="form-control" name="slug" placeholder="zerosoft-garden">
          </div>
          <div class="col-12 d-grid">
            <button class="btn btn-zs"><i class="bi bi-plus-lg me-1"></i>Salonu Kaydet</button>
          </div>
        </form>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="card-lite p-4 h-100 filter-card">
        <h5 class="mb-3">Salonlarda Ara / Filtrele</h5>
        <form method="get" class="row g-3 grid-compact">
          <div class="col-md-6">
            <label class="form-label">Ad veya Slug</label>
            <input class="form-control" name="q" value="<?=h($q)?>" placeholder="Örn: Garden / garden-salon">
          </div>
          <div class="col-md-3">
            <label class="form-label">Durum</label>
            <select class="form-select" name="status">
              <option value="">Tümü</option>
              <option value="1" <?= $status==='1'?'selected':'' ?>>Aktif</option>
              <option value="0" <?= $status==='0'?'selected':'' ?>>Pasif</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Sırala</label>
            <select class="form-select" name="order">
              <option value="new" <?= $order==='new'?'selected':'' ?>>En yeni</option>
              <option value="name_az" <?= $order==='name_az'?'selected':'' ?>>A→Z</option>
              <option value="name_za" <?= $order==='name_za'?'selected':'' ?>>Z→A</option>
            </select>
          </div>
          <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="small muted">Toplam <?=$venueTotals['all']?> salon listeleniyor.</div>
            <div class="filter-actions">
              <button class="btn btn-zs-outline" type="submit"><i class="bi bi-funnel me-1"></i>Filtrele</button>
              <a class="btn btn-zs" href="<?=h($_SERVER['PHP_SELF'])?>"><i class="bi bi-arrow-counterclockwise me-1"></i>Temizle</a>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="card-lite p-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 mb-3">
      <h5 class="mb-0">Salonlar</h5>
      <div class="small muted">
        <?=$venueTotals['all']?> sonuç
        <?php if($q!==''): ?> • “<?=h($q)?>”<?php endif; ?>
        <?php if($status==='1'): ?> • sadece aktif<?php elseif($status==='0'): ?> • sadece pasif<?php endif; ?>
      </div>
    </div>

    <?php if (!$venues): ?>
      <div class="muted">Kriterlere uygun salon bulunamadı.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table align-middle venue-table">
          <thead>
            <tr>
              <th>Salon</th>
              <th>Slug</th>
              <th>Durum</th>
              <th style="width:440px">İşlemler</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($venues as $v): ?>
              <tr>
                <td class="fw-semibold"><?=h($v['name'])?></td>
                <td class="small text-muted"><?=h($v['slug'])?></td>
                <td class="text-nowrap">
                  <span class="badge-status <?= $v['is_active'] ? 'badge-active' : 'badge-passive' ?>"><?= $v['is_active'] ? 'Aktif' : 'Pasif' ?></span>
                </td>
                <td>
                  <div class="action-group">
                    <form method="post">
                      <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                      <input type="hidden" name="do" value="select">
                      <input type="hidden" name="id" value="<?=$v['id']?>">
                      <button class="btn btn-sm btn-zs" type="submit"><i class="bi bi-layout-text-window me-1"></i>Seç ve Panele Git</button>
                    </form>
                    <form method="post" onsubmit="return askToggle()">
                      <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                      <input type="hidden" name="do" value="toggle">
                      <input type="hidden" name="id" value="<?=$v['id']?>">
                      <button class="btn btn-sm btn-zs-outline" type="submit">
                        <i class="bi bi-arrow-repeat me-1"></i><?= $v['is_active'] ? 'Pasifleştir' : 'Aktifleştir' ?>
                      </button>
                    </form>
                    <form method="post">
                      <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                      <input type="hidden" name="do" value="select">
                      <input type="hidden" name="goto_events" value="1">
                      <input type="hidden" name="id" value="<?=$v['id']?>">
                      <button class="btn btn-sm btn-brand-outline" type="submit"><i class="bi bi-calendar-event me-1"></i>Etkinlikleri Gör</button>
                    </form>
                    <button class="btn btn-sm btn-ghost" type="button" onclick="document.getElementById('edit<?=$v['id']?>').classList.toggle('d-none')">
                      <i class="bi bi-pencil-square me-1"></i>Düzenle
                    </button>
                  </div>
                </td>
              </tr>
              <tr id="edit<?=$v['id']?>" class="d-none">
                <td colspan="4" class="bg-light-subtle">
                  <form method="post" class="row g-3 grid-compact">
                    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="do" value="update">
                    <input type="hidden" name="id" value="<?=$v['id']?>">
                    <div class="col-md-6">
                      <label class="form-label">Salon Adı</label>
                      <input class="form-control" name="name" value="<?=h($v['name'])?>">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Slug</label>
                      <input class="form-control" name="slug" value="<?=h($v['slug'])?>">
                    </div>
                    <div class="col-md-2 d-grid align-items-end">
                      <button class="btn btn-zs"><i class="bi bi-save me-1"></i>Kaydet</button>
                    </div>
                  </form>
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
