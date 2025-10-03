<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/includes/db.php';
$set = pdo()->query("SELECT * FROM settings WHERE id=1")->fetch();
?>
<!doctype html><html lang="tr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h(APP_NAME)?> — Düğün Foto/Video Paylaşım</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  :root{--gold:#c7a06b;--ink:#333}
  .hero{background:linear-gradient(180deg,#ffdbe6,#fff);border-radius:18px;padding:48px 16px}
  .btn-gold{background:var(--gold);color:#fff;border:none}
</style>
</head><body class="bg-light">
<nav class="navbar bg-white border-bottom"><div class="container">
  <a class="navbar-brand fw-bold" href="#"><?=h(APP_NAME)?></a>
</div></nav>

<div class="container py-5">
  <div class="hero text-center mb-5">
    <h1 class="fw-bold mb-2">Düğününüzden Anılar — Kolayca Toplayın</h1>
    <p class="lead text-muted mb-3">Misafirleriniz QR’ı okutsun, anında foto/video yüklensin. Siz de tek panelden yönetin.</p>
    <a class="btn btn-gold" href="https://wa.me/905555555555?text=Wedding%20Share%20satın%20alma%20talebi">Hemen Fiyat Al</a>
  </div>

  <div class="row g-4">
    <div class="col-md-4"><div class="card shadow-sm h-100"><div class="card-body">
      <h5>Nasıl Çalışır?</h5>
      <ol class="small">
        <li>Biz kalıcı bir QR oluştururuz (broşüre basılır).</li>
        <li>Her düğünde QR’ı yeni etkinliğe bağlarız.</li>
        <li>Misafirler ad yazarak foto/video yükler.</li>
      </ol>
    </div></div></div>
    <div class="col-md-4"><div class="card shadow-sm h-100"><div class="card-body">
      <h5>Çift Paneli</h5>
      <p class="small">Tema & metin özelleştirme, toplu ZIP indirme, seçerek silme, montaj talebi.</p>
    </div></div></div>
    <div class="col-md-4"><div class="card shadow-sm h-100"><div class="card-body">
      <h5>Fiyatlar</h5>
      <ul class="small">
        <li>Tek düğün kurulumu: <strong>1000 TL</strong></li>
        <li>Montaj 10 sn: <strong><?= (int)$set['price_10s']?> TL</strong></li>
        <li>Montaj 100 sn: <strong><?= (int)$set['price_100s']?> TL</strong></li>
      </ul>
    </div></div></div>
  </div>

  <div class="text-center mt-5">
    <a class="btn btn-outline-secondary" href="mailto:sales@demozerosoft.com.tr?subject=Wedding%20Share%20Talep">E-posta ile İletişim</a>
  </div>
</div>
</body></html>
