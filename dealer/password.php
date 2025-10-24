<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/dealers.php';
require_once __DIR__.'/../includes/dealer_auth.php';
require_once __DIR__.'/partials/ui.php';

install_schema();

dealer_require_login();
$sessionDealer = dealer_user();
$dealerId = (int)($sessionDealer['id'] ?? 0);
$dealer = $dealerId > 0 ? dealer_get($dealerId) : null;
if (!$dealer) {
  dealer_logout();
  redirect('login.php');
}

dealer_refresh_session($dealerId);
$forceReset = dealer_session_requires_password_change();
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_or_die();
  $current = (string)($_POST['current'] ?? '');
  $new1    = (string)($_POST['new1'] ?? '');
  $new2    = (string)($_POST['new2'] ?? '');

  if ($new1 !== $new2) {
    $err = 'Yeni şifreler eşleşmiyor.';
  } elseif (mb_strlen($new1) < 8) {
    $err = 'Yeni şifre en az 8 karakter olmalıdır.';
  } elseif (!$forceReset && (empty($dealer['password_hash']) || !password_verify($current, $dealer['password_hash']))) {
    $err = 'Mevcut şifreniz hatalı.';
  } else {
    $hash = password_hash($new1, PASSWORD_DEFAULT);
    pdo()->prepare("UPDATE dealers SET password_hash=?, updated_at=? WHERE id=?")
        ->execute([$hash, now(), $dealerId]);
    dealer_mark_password_changed($dealerId);
    flash('ok', 'Şifreniz güncellendi.');
    redirect('dashboard.php');
  }
}

dealer_layout_start('password', [
  'title' => 'Şifre & Güvenlik',
  'subtitle' => 'Panele erişim şifrenizi güvenle güncelleyin.',
  'icon' => 'bi-shield-lock'
]);
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
<?php dealer_layout_end(); ?>
