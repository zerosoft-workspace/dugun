<?php
// admin/venues.php — Salon listesi, ekleme, düzenleme, pasif/aktif, seçim + ARAMA/FİLTRE + Geçmiş günleri gör
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/auth.php';

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

?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h(APP_NAME)?> — Düğün Salonları</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  :root{ --zs:#0ea5b5; --zs-soft:#e0f7fb; --muted:#6b7280; }
  body{ background:linear-gradient(180deg,var(--zs-soft),#fff) no-repeat }
  .card-lite{ border:1px solid #eef2f7; border-radius:18px; background:#fff; box-shadow:0 6px 20px rgba(17,24,39,.05) }
  .btn-zs{ background:var(--zs); border:none; color:#fff; border-radius:12px; padding:.55rem 1rem; font-weight:600 }
  .btn-zs-outline{ background:#fff; border:1px solid var(--zs); color:var(--zs); border-radius:12px; font-weight:600 }
  .grid-compact .form-control, .grid-compact .form-select{ height:42px }
  .muted{ color:var(--muted) }
</style>
<script>
function askToggle(){
  if(!confirm('Bu salonu aktifleştirmek/pasifleştirmek istiyor musunuz?')) return false;
  return confirm('Emin misiniz? Pasife alınırsa panelde kullanılamaz.');
}
</script>
</head>
<body>
<nav class="navbar bg-white border-bottom zs-topbar">
  <div class="container">
    <!-- Sol: Marka -->
    <a class="navbar-brand fw-semibold d-flex align-items-center gap-2" href="<?=h(BASE_URL)?>">
      <?=h(APP_NAME)?>
    </a>

    <!-- Sağ: Aksiyonlar (buton görünümleri senin mevcut stillerle) -->
    <div class="d-flex align-items-center gap-2">
      <a href="<?=h(BASE_URL)?>/admin/dashboard.php" class="btn btn-sm btn-zs-outline">Panel</a>

      <!-- Yeni: Kullanıcılar (liste sayfasına gider) -->
      <a href="<?=h(BASE_URL)?>/admin/users.php" class="btn btn-sm btn-zs">Kullanıcılar</a>

      <a href="<?=h(BASE_URL)?>/admin/dealers.php" class="btn btn-sm btn-zs-outline">Bayiler</a>

      <span class="text-muted px-1">•</span>

      <a href="<?=h(BASE_URL)?>/admin/login.php?logout=1" class="btn btn-sm btn-outline-secondary">Çıkış</a>
    </div>
  </div>
</nav>
<div class="container py-4">
  <?php flash_box(); ?>

  <!-- Yeni Salon -->
  <div class="card-lite p-3 mb-4">
    <h5 class="mb-3">Yeni Düğün Salonu Ekle</h5>
    <form method="post" class="row g-2 grid-compact">
      <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="do" value="create">
      <div class="col-md-6">
        <label class="form-label">Ad</label>
        <input class="form-control" name="name" placeholder="Örn: Zero Soft Garden" required>
      </div>
      <div class="col-md-4">
        <label class="form-label">Slug (opsiyonel)</label>
        <input class="form-control" name="slug" placeholder="zero-soft-garden">
      </div>
      <div class="col-md-2 d-grid align-items-end">
        <button class="btn btn-zs" style="margin-top:30px">Ekle</button>
      </div>
    </form>
  </div>

  <!-- ARAMA / FİLTRE -->
  <div class="card-lite p-3 mb-3">
    <h5 class="mb-3">Salonlarda Ara / Filtrele</h5>
    <form method="get" class="row g-2 grid-compact">
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
          <option value="new" <?= $order==='new'?'selected':'' ?>>Yeni Eklenen</option>
          <option value="name_az" <?= $order==='name_az'?'selected':'' ?>>Ada göre (A→Z)</option>
          <option value="name_za" <?= $order==='name_za'?'selected':'' ?>>Ada göre (Z→A)</option>
        </select>
      </div>
      <div class="col-12 d-flex gap-2">
        <button class="btn btn-zs-outline">Filtrele</button>
        <a class="btn btn-outline-secondary" href="<?=h($_SERVER['PHP_SELF'])?>">Temizle</a>
      </div>
    </form>
  </div>

  <!-- Salon Listesi -->
  <div class="card-lite p-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h5 class="m-0">Salonlar</h5>
      <div class="small muted">
        <?= count($venues) ?> sonuç
        <?php if($q!==''): ?> • “<?=h($q)?>”<?php endif; ?>
        <?php if($status==='1'): ?> • sadece aktif<?php elseif($status==='0'): ?> • sadece pasif<?php endif; ?>
      </div>
    </div>

    <?php if (!$venues): ?>
      <div class="muted">Kriterlere uygun salon bulunamadı.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead>
            <tr>
              <th>Ad</th>
              <th>Slug</th>
              <th>Durum</th>
              <th style="width:420px">İşlemler</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($venues as $v): ?>
              <tr>
                <td class="fw-semibold"><?=h($v['name'])?></td>
                <td class="small"><?=h($v['slug'])?></td>
                <td>
                  <?= $v['is_active'] ? '<span class="badge bg-success">Aktif</span>'
                                      : '<span class="badge bg-secondary">Pasif</span>' ?>
                </td>
                <td>
                  <!-- Seç -->
                  <form method="post" class="d-inline">
                    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="do" value="select">
                    <input type="hidden" name="id" value="<?=$v['id']?>">
                    <button class="btn btn-sm btn-zs">Seç ve Panele Git</button>
                  </form>

                  <!-- Aktif/Pasif -->
                  <form method="post" class="d-inline" onsubmit="return askToggle()">
                    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="do" value="toggle">
                    <input type="hidden" name="id" value="<?=$v['id']?>">
                    <button class="btn btn-sm btn-outline-secondary">
                      <?= $v['is_active'] ? 'Pasifleştir' : 'Aktifleştir' ?>
                    </button>
                  </form>

                  <!-- Geçmiş günleri gör (seç + venue_events.php'ye git) -->
                  <form method="post" class="d-inline">
                    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="do" value="select">
                    <input type="hidden" name="goto_events" value="1">
                    <input type="hidden" name="id" value="<?=$v['id']?>">
                    <button class="btn btn-sm btn-zs-outline">Geçmiş günleri gör</button>
                  </form>

                  <!-- Düzenle aç/kapa -->
                  <button class="btn btn-sm btn-zs-outline" type="button"
                          onclick="document.getElementById('edit<?=$v['id']?>').classList.toggle('d-none')">
                    Düzenle
                  </button>
                </td>
              </tr>
              <tr id="edit<?=$v['id']?>" class="d-none">
                <td colspan="4">
                  <form method="post" class="row g-2 grid-compact">
                    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="do" value="update">
                    <input type="hidden" name="id" value="<?=$v['id']?>">
                    <div class="col-md-6">
                      <label class="form-label">Ad</label>
                      <input class="form-control" name="name" value="<?=h($v['name'])?>">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Slug</label>
                      <input class="form-control" name="slug" value="<?=h($v['slug'])?>">
                    </div>
                    <div class="col-md-2 d-grid align-items-end">
                      <button class="btn btn-zs" style="margin-top:30px">Kaydet</button>
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
</div>
</body>
</html>
