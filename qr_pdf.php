<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/functions.php';
// qr_pdf.php
require_once __DIR__.'/vendor/fpdf.php';   // <-- doğru dosya

$code = trim($_GET['code'] ?? '');
$vslug= trim($_GET['v'] ?? '');

$st=pdo()->prepare("SELECT * FROM venues WHERE slug=?"); $st->execute([$vslug]); $venue=$st->fetch();
if (!$venue){ http_response_code(404); exit('Salon yok'); }

$st=pdo()->prepare("SELECT q.*, e.title AS ev_title
  FROM qr_codes q LEFT JOIN events e ON e.id=q.target_event_id
  WHERE q.venue_id=? AND q.code=?");
$st->execute([$venue['id'],$code]); $q=$st->fetch();
if (!$q){ http_response_code(404); exit('QR yok'); }

$qrLink = BASE_URL.'/qr.php?code='.rawurlencode($code).'&v='.rawurlencode($vslug);
$img = 'https://api.qrserver.com/v1/create-qr-code/?size=900x900&data='.rawurlencode($qrLink);

// PNG'yi indir ve geçici dosyaya kaydet
$tmp = sys_get_temp_dir().'/qr_'.bin2hex(random_bytes(4)).'.png';
file_put_contents($tmp, file_get_contents($img));
if (!class_exists('FPDF') || !method_exists('FPDF', 'AddPage')) {
  http_response_code(500);
}
if (!class_exists('FPDF') || !method_exists('FPDF', 'AddPage')) {
  http_response_code(500);
  exit('FPDF yüklenemedi: Lütfen gerçek fpdf.php dosyasını sunucuya ekleyin.');
}


// PDF
$pdf = new FPDF('P','mm','A4');
$pdf->AddPage();
$pdf->SetFont('Arial','B',22);
$pdf->Cell(0,12,utf8_decode(APP_NAME.' — QR Broşür'),0,1,'C');
$pdf->Ln(6);
$pdf->SetFont('Arial','',14);
$line = $q['ev_title'] ? $q['ev_title'] : $venue['name'];
$pdf->Cell(0,8,utf8_decode($line),0,1,'C');
$pdf->Ln(4);

// Orta QR
// A4 genişlik ~210mm, resim 120mm yapalım:
$x = (210-120)/2;
$pdf->Image($tmp,$x,60,120,120,'PNG');

// Alt yazılar
$pdf->SetXY(10,185);
$pdf->SetFont('Arial','',12);
$pdf->Cell(0,8,utf8_decode('Salon: '.$venue['name'].'  |  Kod: '.$code.'  |  Slug: '.$vslug),0,1,'C');
$pdf->SetFont('Arial','U',12);
$pdf->SetTextColor(0,0,255);
$pdf->Cell(0,8,utf8_decode($qrLink),0,1,'C');
$pdf->SetTextColor(0,0,0);

@unlink($tmp);
$pdf->Output('D', 'QR_Brosur_'.$vslug.'_'.$code.'.pdf');
exit;
