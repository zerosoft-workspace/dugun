<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/partials/ui.php';

install_schema();
require_admin();

$me = admin_user();
$userId = (int)($me['id'] ?? 0);
if ($userId <= 0) {
  redirect('login.php');
}

$forceReset = admin_session_requires_password_change();
$err = null;

$st = pdo()->prepare("SELECT password_hash FROM users WHERE id=? LIMIT 1");
$st->execute([$userId]);
$currentRow = $st->fetch();
$currentHash = $currentRow['password_hash'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_or_die();
  $current = (string)($_POST['current'] ?? '');
  $new1    = (string)($_POST['new1'] ?? '');
  $new2    = (string)($_POST['new2'] ?? '');

  if ($new1 !== $new2) {
    $err = 'Yeni şifreler eşleşmiyor.';
  } elseif (mb_strlen($new1) < 8) {
    $err = 'Yeni şifre en az 8 karakter olmalıdır.';
  } elseif (!$forceReset && (empty($currentHash) || !password_verify($current, $currentHash))) {
    $err = 'Mevcut şifreniz hatalı.';
  } else {
    $hash = password_hash($new1, PASSWORD_DEFAULT);
    pdo()->prepare("UPDATE users SET password_hash=?, updated_at=? WHERE id=?")
        ->execute([$hash, now(), $userId]);
    admin_mark_password_changed($userId);
    flash('ok', 'Şifreniz güncellendi.');
    redirect('dashboard.php');
  }
}

admin_layout_start('password', 'Şifre & Güvenlik', 'Yönetici hesabınız için güçlü ve güvenli bir şifre belirleyin.', 'bi-shield-lock');
?>
<div class="card-lite p-4">
  <h5 class="mb-3">Şifre Değiştir</h5>
  <?php flash_box(); ?>
  <?php if ($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif; ?>
  <?php if ($forceReset): ?>
    <div class="alert alert-info">
      <strong>Hoş geldiniz!</strong> Geçici şifrenizi kişisel şifrenizle değiştirin. Mevcut şifreyi girmenize gerek yok.
    </div>
  <?php endif; ?>
  <form method="post" class="row g-3">
    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
    <?php if (!$forceReset): ?>
      <div class="col-12">
        <label class="form-label">Mevcut Şifre</label>
        <input type="password" class="form-control" name="current" required>
      </div>
    <?php endif; ?>
    <div class="col-md-6">
      <label class="form-label">Yeni Şifre</label>
      <input type="password" class="form-control" name="new1" minlength="8" required>
    </div>
    <div class="col-md-6">
      <label class="form-label">Yeni Şifre (Tekrar)</label>
      <input type="password" class="form-control" name="new2" minlength="8" required>
    </div>
    <div class="col-12 d-grid d-md-flex justify-content-md-end gap-2">
      <a class="btn btn-outline-secondary" href="dashboard.php">Vazgeç</a>
      <button class="btn btn-brand" type="submit">Şifremi Güncelle</button>
    </div>
  </form>
</div>
<?php admin_layout_end(); ?>
