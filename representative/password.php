<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/representatives.php';
require_once __DIR__.'/../includes/representative_auth.php';
require_once __DIR__.'/partials/ui.php';

install_schema();

representative_require_login();
$user = representative_user();
$repId = (int)($user['id'] ?? 0);
$representative = $repId > 0 ? representative_get($repId) : null;
if (!$representative) {
  representative_logout();
  redirect('login.php');
}

representative_refresh_session($repId);
$forceReset = representative_session_requires_password_change();
$err = null;

$st = pdo()->prepare('SELECT password_hash FROM dealer_representatives WHERE id=? LIMIT 1');
$st->execute([$repId]);
$row = $st->fetch();
$currentHash = $row['password_hash'] ?? null;

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
    pdo()->prepare('UPDATE dealer_representatives SET password_hash=?, updated_at=? WHERE id=?')
        ->execute([$hash, now(), $repId]);
    representative_mark_password_changed($repId);
    flash('ok', 'Şifreniz güncellendi.');
    redirect('dashboard.php');
  }
}

representative_layout_start([
  'active_nav' => 'password',
  'header_title' => 'Şifre & Güvenlik',
  'header_subtitle' => 'Panele güvenle erişmek için güçlü bir şifre belirleyin.',
  'representative' => $representative,
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
      <button class="btn btn-primary" type="submit">Şifremi Güncelle</button>
    </div>
  </form>
</div>
<?php representative_layout_end(); ?>
