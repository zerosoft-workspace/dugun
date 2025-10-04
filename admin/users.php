<?php
// admin/users.php — Tüm kullanıcılar (çiftler) ve ödeme/ paket özetleri + filtreler + PDF çıktısı
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/partials/ui.php';

require_admin();
install_schema();

/* --------- Yardımcılar --------- */
function days_left_badge(?string $expires_at): array {
  if (!$expires_at) return ['secondary','Tanımsız'];
  try {
    $exp = new DateTime($expires_at);
    $now = new DateTime();
    $diff = $now->diff($exp, false);
    if ($diff->invert) return ['danger','Süresi doldu'];
    // Yıl/ay/gün kompakt yazı
    $text = ($diff->y ? $diff->y.'y ' : '').($diff->m ? $diff->m.'a ' : '').$diff->d.'g kaldı';
    return ['info',$text];
  } catch(Throwable $e){ return ['secondary','—']; }
}
function fmt_tl($kurus){
  $tl = ((int)$kurus)/100;
  return number_format($tl, 2, ',', '.').' TL';
}

/* --------- Filtreler (GET) --------- */
$q       = trim($_GET['q'] ?? '');           // ad, e-posta
$venue   = (int)($_GET['venue'] ?? 0);       // salon id
$paid    = $_GET['paid'] ?? '';              // '', 'paid', 'unpaid'
$lic     = $_GET['license'] ?? '';           // '', 'active', 'expired', 'unset'
$df      = trim($_GET['date_from'] ?? '');   // etkinlik tarihi >=
$dt      = trim($_GET['date_to'] ?? '');     // etkinlik tarihi <=
$order   = $_GET['order'] ?? 'new';          // sıralama

$where = ["e.is_active = 1"];  // silinmiş/pasif eventleri gizle
$args  = [];

// Arama: başlık veya e-posta
if ($q !== '') {
  $where[] = "(e.title LIKE ? OR e.contact_email LIKE ?)";
  $args[]  = '%'.$q.'%';
  $args[]  = '%'.$q.'%';
}
// Salon
if ($venue > 0) {
  $where[] = "e.venue_id = ?";
  $args[]  = $venue;
}
// Etkinlik tarihi aralığı
if ($df !== '') { $where[] = "(e.event_date IS NOT NULL AND e.event_date >= ?)"; $args[] = $df; }
if ($dt !== '') { $where[] = "(e.event_date IS NOT NULL AND e.event_date <= ?)"; $args[] = $dt; }

// Lisans filtresi
if ($lic === 'active') {
  $where[] = "(e.license_expires_at IS NOT NULL AND e.license_expires_at >= NOW())";
} elseif ($lic === 'expired') {
  $where[] = "(e.license_expires_at IS NOT NULL AND e.license_expires_at < NOW())";
} elseif ($lic === 'unset') {
  $where[] = "(e.license_expires_at IS NULL)";
}

// Ödeme filtresi (“paid” olan en az 1 kaydı var/yok)
if ($paid === 'paid') {
  $where[] = "EXISTS (SELECT 1 FROM purchases p WHERE p.event_id=e.id AND p.status='paid')";
} elseif ($paid === 'unpaid') {
  $where[] = "NOT EXISTS (SELECT 1 FROM purchases p WHERE p.event_id=e.id AND p.status='paid')";
}

$W = $where ? ('WHERE '.implode(' AND ', $where)) : '';

switch ($order) {
  case 'name_az': $ORDER = 'ORDER BY e.title ASC, e.id DESC'; break;
  case 'date_asc': $ORDER = 'ORDER BY e.event_date ASC, e.id DESC'; break;
  case 'date_desc': $ORDER = 'ORDER BY e.event_date DESC, e.id DESC'; break;
  default: $ORDER = 'ORDER BY e.id DESC'; // new
}

/* --------- Veriler --------- */
// Salonlar (filtre için)
$venues = pdo()->query("SELECT id,name FROM venues ORDER BY name ASC")->fetchAll();

// Ana liste (özet kolonlarla)
$sql = "
  SELECT
    e.id, e.title, e.event_date, e.venue_id, e.contact_email, e.couple_phone,
    e.license_expires_at,
    v.name AS venue_name,
    -- ödenen toplam (kurus)
    (SELECT COALESCE(SUM(amount),0) FROM purchases p WHERE p.event_id=e.id AND p.status='paid') AS paid_sum,
    -- ödenen işlem sayısı
    (SELECT COUNT(*) FROM purchases p WHERE p.event_id=e.id AND p.status='paid') AS paid_count,
    -- son ödeme tarihi
    (SELECT MAX(COALESCE(p.paid_at, p.created_at))
   FROM purchases p
  WHERE p.event_id=e.id AND p.status='paid') AS last_paid_at
  FROM events e
  LEFT JOIN venues v ON v.id=e.venue_id
  $W
  $ORDER
";
$st = pdo()->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

/* --------- Paket isimleri (badge) için: her event için paid purchases -> items_json --------- */
function load_packages_for_event(int $event_id): array {
  $ps = pdo()->prepare("SELECT items_json FROM purchases WHERE event_id=? AND status='paid' ORDER BY id DESC");
  $ps->execute([$event_id]);
  $names = [];
  while ($r = $ps->fetch()) {
    $items = json_decode($r['items_json'] ?? '[]', true) ?: [];
    foreach ($items as $it) {
      // item adı alanı farklı olabilir: name veya 0. indis
      if (is_array($it)) {
        if (isset($it['name'])) $names[$it['name']] = true;
        elseif (isset($it[0]))  $names[$it[0]] = true;
      } elseif (is_string($it)) {
        $names[$it] = true;
      }
    }
  }
  return array_keys($names);
}

/* --------- Detay tablosu için (popup değil, satır altı açılır) veriyi ajax'sız basitçe alacağız --------- */
$detail_id = (int)($_GET['detail'] ?? 0);
$detail = [];
if ($detail_id) {
  $st = pdo()->prepare("SELECT * FROM purchases WHERE event_id=? ORDER BY id DESC");
  $st->execute([$detail_id]);
  $detail = $st->fetchAll();
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h(APP_NAME)?> — Kullanıcılar & Ödemeler</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?=admin_base_styles()?>
<style>
  .card-lite{ border-radius:20px; border:1px solid rgba(148,163,184,.16); box-shadow:0 18px 45px -28px rgba(15,23,42,.4); }
  .btn-zs{ background:var(--brand); border:none; color:#fff; border-radius:12px; padding:.55rem 1rem; font-weight:600; }
  .btn-zs:hover{ background:var(--brand-dark); color:#fff; }
  .btn-zs-outline{ background:#fff; border:1px solid rgba(14,165,181,.55); color:var(--brand); border-radius:12px; font-weight:600; }
  .btn-zs-outline:hover{ background:rgba(14,165,181,.12); color:var(--brand-dark); }
  .muted{ color:var(--muted); }
  .badge-soft{ background:rgba(14,165,181,.14); color:var(--brand-dark); border-radius:999px; padding:.3rem .7rem; font-weight:600; }
  @media print{
    .admin-header, .admin-hero, .no-print{ display:none !important; }
    body.admin-body{ background:#fff; }
    .card-lite{ border:none; box-shadow:none; }
    table{ font-size:12px; }
  }
</style>
</head>
<body class="admin-body">
<?php render_admin_topnav('users', 'Etkinlik Hesapları', 'Etkinlik panellerini, lisans sürelerini ve ödeme durumlarını inceleyin.'); ?>

<main class="admin-main">
  <div class="container">
  <?php flash_box(); ?>

  <!-- Filtreler -->
  <div class="card-lite p-3 mb-3 no-print">
    <div class="d-flex justify-content-between align-items-center">
      <h5 class="m-0">Etkinlik Hesapları & Ödemeler</h5>
      <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">Yazdır / PDF</button>
      </div>
    </div>
    <form method="get" class="row g-2 mt-2">
      <div class="col-lg-3">
        <label class="form-label">Ad / E-posta</label>
        <input class="form-control" name="q" value="<?=h($q)?>" placeholder="Etkinlik adı veya e-posta">
      </div>
      <div class="col-lg-2">
        <label class="form-label">Salon</label>
        <select name="venue" class="form-select">
          <option value="0">Tümü</option>
          <?php foreach($venues as $v): ?>
            <option value="<?=$v['id']?>" <?= $venue===(int)$v['id']?'selected':'' ?>><?=h($v['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-lg-2">
        <label class="form-label">Lisans</label>
        <select name="license" class="form-select">
          <option value="">Tümü</option>
          <option value="active"  <?= $lic==='active'?'selected':'' ?>>Aktif</option>
          <option value="expired" <?= $lic==='expired'?'selected':'' ?>>Süresi dolmuş</option>
          <option value="unset"   <?= $lic==='unset'?'selected':'' ?>>Tanımsız</option>
        </select>
      </div>
      <div class="col-lg-2">
        <label class="form-label">Ödeme</label>
        <select name="paid" class="form-select">
          <option value="">Tümü</option>
          <option value="paid"   <?= $paid==='paid'?'selected':'' ?>>Ödeme yapmış</option>
          <option value="unpaid" <?= $paid==='unpaid'?'selected':'' ?>>Ödeme yapmamış</option>
        </select>
      </div>
      <div class="col-lg-3">
        <label class="form-label">Etkinlik Tarihi</label>
        <div class="d-flex gap-2">
          <input type="date" class="form-control" name="date_from" value="<?=h($df)?>">
          <input type="date" class="form-control" name="date_to"   value="<?=h($dt)?>">
        </div>
      </div>
      <div class="col-lg-3">
        <label class="form-label">Sırala</label>
        <select name="order" class="form-select">
          <option value="new"       <?= $order==='new'?'selected':'' ?>>Yeni eklenen</option>
          <option value="name_az"   <?= $order==='name_az'?'selected':'' ?>>Ada göre (A→Z)</option>
          <option value="date_desc" <?= $order==='date_desc'?'selected':'' ?>>Tarihe göre (Yeni→Eski)</option>
          <option value="date_asc"  <?= $order==='date_asc'?'selected':'' ?>>Tarihe göre (Eski→Yeni)</option>
        </select>
      </div>
      <div class="col-12 d-flex gap-2 mt-2">
        <button class="btn btn-zs-outline">Filtrele</button>
        <a class="btn btn-outline-secondary" href="<?=h($_SERVER['PHP_SELF'])?>">Temizle</a>
      </div>
    </form>
  </div>

  <!-- Liste -->
  <div class="card-lite p-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h6 class="m-0">Toplam <?=count($rows)?> kayıt</h6>
      <div class="small muted no-print">Listede sadece aktif etkinlikler gösterilir.</div>
    </div>

    <?php if(!$rows): ?>
      <div class="muted">Kayıt bulunamadı.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead>
            <tr>
              <th>Kullanıcı / E-posta</th>
              <th>Etkinlik</th>
              <th>Salon</th>
              <th>Tarih</th>
              <th>Lisans</th>
              <th class="text-end">Ödenen</th>
              <th class="text-center">Ödeme Sayısı</th>
              <th>Son Ödeme</th>
              <th>Paketler</th>
              <th class="no-print" style="width:110px">Detay</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($rows as $r):
              [$licClass,$licText] = days_left_badge($r['license_expires_at']);
              $pkgs = load_packages_for_event((int)$r['id']);
              $sum  = (int)$r['paid_sum'];
            ?>
              <tr>
                <td>
                  <div class="fw-semibold"><?=h($r['contact_email'] ?: '—')?></div>
                  <div class="small muted"><?=h($r['couple_phone'] ?: '')?></div>
                </td>
                <td>
                  <div class="fw-semibold"><?=h($r['title'])?></div>
                  <div class="small muted">#<?= (int)$r['id'] ?></div>
                </td>
                <td class="small"><?=h($r['venue_name'] ?: '—')?></td>
                <td class="small"><?= $r['event_date'] ? h($r['event_date']) : '—' ?></td>
                <td><span class="badge bg-<?=$licClass?>"><?=h($licText)?></span></td>
                <td class="text-end fw-semibold"><?= fmt_tl($sum) ?></td>
                <td class="text-center"><?= (int)$r['paid_count'] ?></td>
                <td class="small"><?= $r['last_paid_at'] ? h($r['last_paid_at']) : '—' ?></td>
                <td class="small">
                  <?php if(!$pkgs): ?>
                    <span class="badge bg-light text-dark">—</span>
                  <?php else: foreach($pkgs as $nm): ?>
                    <span class="badge badge-soft me-1 mb-1"><?=h($nm)?></span>
                  <?php endforeach; endif; ?>
                </td>
                <td class="no-print">
                  <a class="btn btn-sm btn-zs-outline" href="?<?=http_build_query(array_merge($_GET,['detail'=>$r['id']]))?>#d<?=$r['id']?>">Detay</a>
                </td>
              </tr>

              <?php if($detail_id === (int)$r['id']): ?>
                <tr id="d<?=$r['id']?>">
                  <td colspan="10">
                    <?php if(!$detail): ?>
                      <div class="muted">Bu kullanıcıya ait ödeme kaydı yok.</div>
                    <?php else: ?>
                      <div class="table-responsive">
                        <table class="table table-sm">
                          <thead>
                            <tr>
                              <th>ID</th>
                              <th>Durum</th>
                              <th>Tutar</th>
                              <th>Para</th>
                              <th>Ödeme Zamanı</th>
                              <th>OID</th>
                              <th>Öğeler</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach($detail as $p):
                              $items = json_decode($p['items_json'] ?? '[]', true) ?: [];
                              $labels=[];
                              foreach($items as $it){
                                if (is_array($it) && isset($it['name'])) $labels[]=$it['name'];
                                elseif(is_array($it) && isset($it[0]))  $labels[]=$it[0];
                                elseif(is_string($it)) $labels[]=$it;
                              }
                            ?>
                              <tr>
                                <td>#<?= (int)$p['id'] ?></td>
                                <td>
                                  <?php if($p['status']==='paid'): ?>
                                    <span class="badge bg-success">paid</span>
                                  <?php elseif($p['status']==='pending'): ?>
                                    <span class="badge bg-warning text-dark">pending</span>
                                  <?php else: ?>
                                    <span class="badge bg-secondary"><?=h($p['status'])?></span>
                                  <?php endif; ?>
                                </td>
                                <td><?= fmt_tl((int)$p['amount']) ?></td>
                                <td><?=h($p['currency'] ?: 'TL')?></td>
                                <td class="small"><?= h($p['paid_at'] ?: '—') ?></td>
                                <td class="small"><?= h($p['paytr_oid'] ?: '—') ?></td>
                                <td class="small">
                                  <?php if($labels): foreach($labels as $lb): ?>
                                    <span class="badge bg-light text-dark me-1 mb-1"><?=h($lb)?></span>
                                  <?php endforeach; else: ?>
                                    —
                                  <?php endif; ?>
                                </td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endif; ?>

            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  </div>
</main>
</body>
</html>
