<?php
// admin/venue_events.php — Seçili salonun düğünlerini aylık listele + PDF çıktı
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/partials/ui.php';

require_admin();
install_schema();

$venue = require_current_venue_or_redirect();
$VID   = (int)$venue['id'];
$VNAME = $venue['name'];
$VSLUG = $venue['slug'];

// Yardımcı
if (!function_exists('fmt_bytes_ve')) {
  function fmt_bytes_ve($b){
    $b = (int)$b;
    if ($b <= 0) return '0 MB';
    $mb = $b / 1048576;
    if ($mb < 1024) return number_format($mb, 1).' MB';
    return number_format($mb/1024, 2).' GB';
  }
}

// --- Filtreler ---
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$all   = isset($_GET['all']) ? 1 : 0; // tüm aylar

// Yıl/ay seçenekleri için min-max tespit
$rangeSt = pdo()->prepare("SELECT MIN(event_date) AS min_d, MAX(event_date) AS max_d FROM events WHERE venue_id=?");
$rangeSt->execute([$VID]);
$r = $rangeSt->fetch();
$minY = $r['min_d'] ? (int)date('Y', strtotime($r['min_d'])) : (int)date('Y');
$maxY = $r['max_d'] ? (int)date('Y', strtotime($r['max_d'])) : (int)date('Y');

$hasDateFilter = !$all;

// WHERE
$where = ["e.venue_id = ?"];
$args  = [$VID];

if ($hasDateFilter) {
  // Ay filtresi: ayın 1'i ile son günü (event_date dolu olanlar)
  $start = sprintf('%04d-%02d-01', $year, $month);
  $end   = date('Y-m-d', strtotime("$start +1 month"));
  $where[] = "e.event_date IS NOT NULL AND e.event_date >= ? AND e.event_date < ?";
  $args[] = $start; $args[] = $end;
}

// Soft-delete: sadece aktifler
$where[] = "e.is_active = 1";

$W = implode(' AND ', $where);

// Veri çek: uploads sayısı ve toplam boyut ile birlikte
$sql = "
  SELECT e.*,
         (SELECT COUNT(*) FROM uploads u WHERE u.venue_id=e.venue_id AND u.event_id=e.id) AS file_count,
         (SELECT COALESCE(SUM(u.file_size),0) FROM uploads u WHERE u.venue_id=e.venue_id AND u.event_id=e.id) AS total_bytes
  FROM events e
  WHERE $W
  ORDER BY e.event_date IS NULL ASC, e.event_date DESC, e.id DESC
";
$st = pdo()->prepare($sql);
$st->execute($args);
$events = $st->fetchAll();

// Grupla: Yıl-Ay başlıkları
$grouped = [];
foreach ($events as $ev) {
  if (!empty($ev['event_date'])) {
    $k = date('Y-m', strtotime($ev['event_date']));
  } else {
    $k = 'tarihsiz';
  }
  $grouped[$k][] = $ev;
}

// --- PDF EXPORT ---
if (isset($_GET['export']) && $_GET['export']==='pdf') {
  // FPDF yüklemeyi dene (3 farklı tipik yol)
  $fpdfLoaded = false;
  foreach ([
    __DIR__.'/../fpdf/fpdf.php',
    __DIR__.'/../lib/fpdf/fpdf.php',
    __DIR__.'/../vendor/fpdf/fpdf.php',
  ] as $fp) {
    if (is_file($fp)) { require_once $fp; $fpdfLoaded = true; break; }
  }

  if ($fpdfLoaded && class_exists('FPDF')) {
    // Gerçek PDF
    $pdf = new FPDF('P','mm','A4');
    $pdf->AddPage();
    $pdf->SetFont('Arial','B',14);

    $title = $VNAME.' — Düğün Listesi';
    if ($hasDateFilter) $title .= ' ('.sprintf('%02d/%04d',$month,$year).')';
    $pdf->Cell(0,10, iconv('UTF-8','ISO-8859-9',$title), 0, 1, 'L');

    $pdf->SetFont('Arial','',10);
    $pdf->Cell(85,8, iconv('UTF-8','ISO-8859-9','Başlık'),1,0,'L');
    $pdf->Cell(25,8, iconv('UTF-8','ISO-8859-9','Tarih'),1,0,'C');
    $pdf->Cell(25,8, iconv('UTF-8','ISO-8859-9','Yükleme'),1,0,'C');
    $pdf->Cell(35,8, iconv('UTF-8','ISO-8859-9','Toplam Boyut'),1,0,'R');
    $pdf->Cell(20,8, iconv('UTF-8','ISO-8859-9','Durum'),1,1,'C');

    foreach ($grouped as $ym => $rows) {
      // Alt başlık
      $label = ($ym==='tarihsiz') ? 'Tarihsiz' : date('F Y', strtotime($ym.'-01'));
      $pdf->SetFont('Arial','B',10);
      $pdf->Cell(0,7, iconv('UTF-8','ISO-8859-9',$label),0,1,'L');
      $pdf->SetFont('Arial','',10);

      foreach ($rows as $e) {
        $tarih = $e['event_date'] ? date('d.m.Y', strtotime($e['event_date'])) : '—';
        $title = $e['title'];
        $size  = fmt_bytes_ve($e['total_bytes']);
        $cnt   = (int)$e['file_count'];
        $durum = $e['is_active'] ? 'Aktif' : 'Pasif';

        $pdf->Cell(85,8, iconv('UTF-8','ISO-8859-9', mb_strimwidth($title,0,80,'…','UTF-8')),1,0,'L');
        $pdf->Cell(25,8, iconv('UTF-8','ISO-8859-9',$tarih),1,0,'C');
        $pdf->Cell(25,8, (string)$cnt,1,0,'C');
        $pdf->Cell(35,8, iconv('UTF-8','ISO-8859-9',$size),1,0,'R');
        $pdf->Cell(20,8, iconv('UTF-8','ISO-8859-9',$durum),1,1,'C');
      }
    }

    $pdf->Output('I', 'dugunler.pdf');
    exit;
  } else {
    // FPDF yoksa yazıcı-dostu HTML
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html><html lang="tr"><head>
    <meta charset="utf-8">
    <title><?=h($VNAME)?> — Düğünler (Yazdır)</title>
    <style>
      body{ font-family:Arial, Helvetica, sans-serif; margin:20px; }
      h2{ margin:0 0 6px 0; }
      .muted{ color:#666 }
      table{ border-collapse:collapse; width:100%; margin-top:14px }
      th,td{ border:1px solid #ddd; padding:8px; font-size:13px }
      th{ background:#f3f6fb; text-align:left }
      .group{ margin-top:18px; font-weight:bold }
      @media print { .no-print{ display:none } }
    </style>
    </head><body>
      <div class="no-print" style="margin-bottom:8px">
        <button onclick="window.print()">Yazdır</button>
      </div>
      <h2><?=h($VNAME)?> — Düğün Listesi</h2>
      <div class="muted">
        <?php if($hasDateFilter): ?>
          Ay: <?=sprintf('%02d/%04d',$month,$year)?>
        <?php else: ?>
          Tüm Aylar
        <?php endif; ?>
      </div>
      <?php foreach($grouped as $ym=>$rows): ?>
        <div class="group">
          <?= $ym==='tarihsiz' ? 'Tarihsiz' : date('F Y', strtotime($ym.'-01')) ?>
        </div>
        <table>
          <thead><tr>
            <th>Başlık</th><th>Tarih</th><th>Yükleme</th><th>Toplam Boyut</th><th>Durum</th>
          </tr></thead>
          <tbody>
          <?php foreach($rows as $e): ?>
            <tr>
              <td><?=h($e['title'])?></td>
              <td><?= $e['event_date'] ? h(date('d.m.Y', strtotime($e['event_date']))) : '—' ?></td>
              <td><?= (int)$e['file_count'] ?></td>
              <td><?= fmt_bytes_ve((int)$e['total_bytes']) ?></td>
              <td><?= $e['is_active'] ? 'Aktif' : 'Pasif' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endforeach; ?>
    </body></html>
    <?php
    exit;
  }
}

// --- HTML SAYFA ---
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h(APP_NAME)?> — <?=h($VNAME)?> / Düğünler</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?=admin_base_styles()?>
<style>
  .btn-zs{ background:var(--brand); border:none; color:#fff; border-radius:12px; padding:.55rem 1rem; font-weight:600; }
  .btn-zs:hover{ background:var(--brand-dark); color:#fff; }
  .btn-zs-outline{ background:#fff; border:1px solid rgba(14,165,181,.55); color:var(--brand); border-radius:12px; font-weight:600; }
  .btn-zs-outline:hover{ background:rgba(14,165,181,.12); color:var(--brand-dark); }
  .muted{ color:var(--muted); }
  .group-title{ font-weight:800; color:var(--ink); margin:8px 0 6px; }
  .badge-soft{ background:rgba(14,165,181,.14); color:var(--brand-dark); border-radius:999px; padding:.25rem .55rem; font-weight:600; }
  .table td, .table th { vertical-align: middle; }
</style>
</head>
<body class="admin-body">
<?php admin_layout_start('venues', 'Salon Etkinlikleri', 'Salon: '.$VNAME.' için etkinlik listesi'); ?>

  <?php flash_box(); ?>

  <!-- Filtre + PDF -->
  <div class="card-lite p-3 mb-3">
    <form class="row g-2 align-items-end" method="get">
      <div class="col-md-3">
        <label class="form-label">Yıl</label>
        <select name="year" class="form-select" <?= $all?'disabled':'' ?>>
          <?php for($y=$maxY; $y>=$minY; $y--): ?>
            <option value="<?=$y?>" <?= $y===$year ? 'selected':'' ?>><?=$y?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Ay</label>
        <select name="month" class="form-select" <?= $all?'disabled':'' ?>>
          <?php for($m=1;$m<=12;$m++): ?>
            <option value="<?=$m?>" <?= $m===$month ? 'selected':'' ?>><?=sprintf('%02d',$m)?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-md-3 form-check" style="padding-top: 32px;">
        <input type="checkbox" class="form-check-input" id="all" name="all" value="1" <?= $all?'checked':'' ?>
               onclick="document.querySelector('[name=year]').disabled=this.checked;document.querySelector('[name=month]').disabled=this.checked;">
        <label class="form-check-label" for="all">Tüm aylar</label>
      </div>
      <div class="col-md-3 d-flex gap-2">
        <button class="btn btn-zs">Listele</button>
        <?php
          $pdfQuery = $_GET;
          $pdfQuery['export'] = 'pdf';
          $pdfUrl = $_SERVER['PHP_SELF'].'?'.http_build_query($pdfQuery);
        ?>
        <a class="btn btn-zs-outline" target="_blank" href="<?=h($pdfUrl)?>">PDF İndir</a>
      </div>
    </form>
  </div>

  <!-- Liste -->
  <?php if(!$events): ?>
    <div class="alert alert-info">Bu filtrede kayıt bulunamadı.</div>
  <?php else: ?>
    <?php foreach($grouped as $ym => $rows): ?>
      <div class="card-lite p-3 mb-3">
        <div class="group-title">
          <?= $ym==='tarihsiz' ? 'Tarihsiz' : date('F Y', strtotime($ym.'-01')) ?>
        </div>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>Başlık</th>
                <th>Tarih</th>
                <th class="text-center">Yükleme</th>
                <th class="text-center">Toplam Boyut</th>
                <th>Linkler</th>
                <th>Durum</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($rows as $e):
                $pub = public_upload_url($e['id']);
                $couple = BASE_URL.'/couple/index.php?event='.$e['id'].'&key='.$e['couple_panel_key'];
              ?>
                <tr>
                  <td class="fw-semibold">
                    <?=h($e['title'])?>
                    <div class="small muted"><?=h($e['slug'])?></div>
                  </td>
                  <td class="small"><?= $e['event_date'] ? h(date('d.m.Y', strtotime($e['event_date']))) : '—' ?></td>
                  <td class="text-center"><span class="badge bg-secondary"><?= (int)$e['file_count'] ?></span></td>
                  <td class="text-center"><?= fmt_bytes_ve((int)$e['total_bytes']) ?></td>
                  <td class="small">
                    <a class="badge-soft text-decoration-none" target="_blank" href="<?=h($pub)?>">Misafir Yükleme</a>
                    <a class="badge-soft text-decoration-none" target="_blank" href="<?=h($couple)?>">Çift Paneli</a>
                  </td>
                  <td>
                    <?= $e['is_active'] ? '<span class="badge bg-success">Aktif</span>' : '<span class="badge bg-secondary">Pasif</span>' ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
<?php admin_layout_end(); ?>
</body>
</html>
