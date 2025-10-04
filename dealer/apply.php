<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/dealers.php';

install_schema();

$submitted = isset($_GET['done']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_or_die();
  $name = trim($_POST['name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $phone = trim($_POST['phone'] ?? '');
  $company = trim($_POST['company'] ?? '');
  $notes = trim($_POST['notes'] ?? '');

  if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('err', 'Lütfen adınızı ve geçerli bir e-posta adresini girin.');
    redirect($_SERVER['PHP_SELF']);
  }

  if (dealer_find_by_email($email)) {
    flash('err', 'Bu e-posta ile kayıtlı bir bayi başvurusu bulunuyor.');
    redirect($_SERVER['PHP_SELF']);
  }

  $st = pdo()->prepare("INSERT INTO dealers (name,email,phone,company,notes,status,created_at) VALUES (?,?,?,?,?,'pending',?)");
  $st->execute([$name,$email,$phone,$company,$notes, now()]);
  $dealerId = (int)pdo()->lastInsertId();
  dealer_ensure_codes($dealerId);

  $dealer = dealer_get($dealerId);
  dealer_notify_new_application($dealer);
  dealer_send_application_receipt($dealer);

  flash('ok', 'Başvurunuz alınmıştır. En kısa sürede sizinle iletişime geçeceğiz.');
  redirect($_SERVER['PHP_SELF'].'?done=1');
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h(APP_NAME)?> — Bayi Başvurusu</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:linear-gradient(180deg,#fff5f7,#ffffff);}
  .hero{max-width:640px;margin:40px auto;padding:32px;border-radius:18px;background:#fff;box-shadow:0 12px 40px rgba(15,23,42,.08);}
</style>
</head>
<body>
<div class="hero">
  <h1 class="h3 mb-3">Bayi Başvuru Formu</h1>
  <p class="text-muted">Düğün salonunuz veya organizasyon şirketiniz için başvuru yapın, yönetici onayıyla bayilik paneliniz açılsın.</p>
  <?php if ($submitted): ?>
    <?php flash_box(); ?>
  <?php else: ?>
    <?php flash_box(); ?>
    <form method="post" class="row g-3">
      <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
      <div class="col-md-6">
        <label class="form-label">Ad Soyad</label>
        <input class="form-control" name="name" required>
      </div>
      <div class="col-md-6">
        <label class="form-label">Firma Adı</label>
        <input class="form-control" name="company">
      </div>
      <div class="col-md-6">
        <label class="form-label">E-posta</label>
        <input type="email" class="form-control" name="email" required>
      </div>
      <div class="col-md-6">
        <label class="form-label">Telefon</label>
        <input class="form-control" name="phone">
      </div>
      <div class="col-12">
        <label class="form-label">Notlar</label>
        <textarea class="form-control" name="notes" rows="4" placeholder="Salon sayısı, bulunduğunuz şehir vb."></textarea>
      </div>
      <div class="col-12">
        <button class="btn btn-primary" type="submit">Başvuruyu Gönder</button>
      </div>
    </form>
  <?php endif; ?>
  <div class="mt-4 text-center">
    <a href="login.php" class="small text-decoration-none">Zaten bayimiz misiniz? Giriş yapın.</a>
  </div>
</div>
</body>
</html>
