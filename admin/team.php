<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/partials/ui.php';

require_admin();
require_superadmin();
install_schema();

function superadmin_count(): int {
  return (int)pdo()->query("SELECT COUNT(*) FROM users WHERE role='superadmin'")->fetchColumn();
}

$me = admin_user();
$action = $_POST['do'] ?? '';
if ($action) {
  csrf_or_die();
}

if ($action === 'create') {
  $name  = trim($_POST['name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $role  = $_POST['role'] ?? 'admin';
  $pass  = (string)($_POST['password'] ?? '');
  $pass2 = (string)($_POST['password_confirm'] ?? '');

  if (mb_strlen($name) < 3) {
    flash('err', 'Ad en az 3 karakter olmalıdır.');
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('err', 'Geçerli bir e-posta girin.');
  } elseif (!in_array($role, ['admin','superadmin'], true)) {
    flash('err', 'Geçersiz rol seçimi.');
  } elseif (mb_strlen($pass) < 8) {
    flash('err', 'Şifre en az 8 karakter olmalıdır.');
  } elseif ($pass !== $pass2) {
    flash('err', 'Şifreler eşleşmiyor.');
  } else {
    $st = pdo()->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
    $st->execute([$email]);
    if ($st->fetch()) {
      flash('err', 'Bu e-posta ile kayıtlı bir yönetici zaten var.');
    } else {
      pdo()->prepare("INSERT INTO users (email,password_hash,name,role,created_at,updated_at) VALUES (?,?,?,?,?,?)")
          ->execute([$email, password_hash($pass, PASSWORD_DEFAULT), $name, $role, now(), now()]);
      flash('ok', 'Yeni yönetici hesabı oluşturuldu.');
    }
  }
  redirect($_SERVER['REQUEST_URI']);
}

if ($action === 'update_role') {
  $userId = (int)($_POST['user_id'] ?? 0);
  $role   = $_POST['role'] ?? 'admin';
  if (!in_array($role, ['admin','superadmin'], true)) {
    flash('err', 'Geçersiz rol seçimi.');
    redirect($_SERVER['REQUEST_URI']);
  }
  $st = pdo()->prepare("SELECT id, role FROM users WHERE id=? LIMIT 1");
  $st->execute([$userId]);
  $target = $st->fetch();
  if (!$target) {
    flash('err', 'Kullanıcı bulunamadı.');
    redirect($_SERVER['REQUEST_URI']);
  }
  if ($target['role'] === 'superadmin' && $role !== 'superadmin' && superadmin_count() <= 1) {
    flash('err', 'Sistemde en az bir süperadmin kalmalıdır.');
    redirect($_SERVER['REQUEST_URI']);
  }
  pdo()->prepare("UPDATE users SET role=?, updated_at=? WHERE id=?")
      ->execute([$role, now(), $userId]);
  if ($userId === ($me['id'] ?? 0)) {
    $_SESSION['admin']['role'] = $role;
  }
  flash('ok', 'Rol güncellendi.');
  redirect($_SERVER['REQUEST_URI']);
}

if ($action === 'reset_password') {
  $userId = (int)($_POST['user_id'] ?? 0);
  $pass   = (string)($_POST['password'] ?? '');
  $pass2  = (string)($_POST['password_confirm'] ?? '');
  if (mb_strlen($pass) < 8) {
    flash('err', 'Yeni şifre en az 8 karakter olmalıdır.');
    redirect($_SERVER['REQUEST_URI']);
  }
  if ($pass !== $pass2) {
    flash('err', 'Yeni şifreler eşleşmiyor.');
    redirect($_SERVER['REQUEST_URI']);
  }
  $st = pdo()->prepare("SELECT id FROM users WHERE id=? LIMIT 1");
  $st->execute([$userId]);
  if (!$st->fetch()) {
    flash('err', 'Kullanıcı bulunamadı.');
    redirect($_SERVER['REQUEST_URI']);
  }
  pdo()->prepare("UPDATE users SET password_hash=?, updated_at=? WHERE id=?")
      ->execute([password_hash($pass, PASSWORD_DEFAULT), now(), $userId]);
  if ($userId === ($me['id'] ?? 0)) {
    flash('ok', 'Şifreniz güncellendi.');
  } else {
    flash('ok', 'Şifre başarıyla sıfırlandı.');
  }
  redirect($_SERVER['REQUEST_URI']);
}

$admins = pdo()->query("SELECT id, name, email, role, created_at, last_login_at FROM users ORDER BY created_at DESC")->fetchAll();
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h(APP_NAME)?> — Yönetici Ekibi</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <?=admin_base_styles()?>
  <style>
    .team-card{ border-radius:20px; border:1px solid rgba(148,163,184,.16); box-shadow:0 22px 45px -30px rgba(15,23,42,.45); }
    .role-chip{ display:inline-flex; align-items:center; gap:.35rem; padding:.35rem .8rem; border-radius:999px; font-weight:600; }
    .role-chip.admin{ background:rgba(148,163,184,.2); color:#475569; }
    .role-chip.superadmin{ background:rgba(14,165,181,.16); color:#0b8b98; }
    details summary{ cursor:pointer; font-weight:600; color:var(--brand); }
    details summary::-webkit-details-marker{ display:none; }
    details[open] summary{ color:var(--brand-dark); }
  </style>
</head>
<body class="admin-body">
<?php render_admin_topnav('team', 'Yönetici Ekibi', 'Süperadmin ve admin rollerini yönetin, ekip arkadaşlarınızı davet edin.'); ?>

<main class="admin-main">
  <div class="container">
    <?php flash_box(); ?>

    <div class="row g-4">
      <div class="col-lg-4">
        <div class="card-lite p-4 team-card">
          <h5 class="mb-3">Yeni Yönetici Davet Et</h5>
          <form method="post" class="vstack gap-3">
            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="do" value="create">
            <div>
              <label class="form-label">Ad Soyad</label>
              <input class="form-control" name="name" required placeholder="Örn. Mehmet Kara">
            </div>
            <div>
              <label class="form-label">E-posta</label>
              <input class="form-control" type="email" name="email" required placeholder="ornek@firma.com">
            </div>
            <div>
              <label class="form-label">Rol</label>
              <select class="form-select" name="role">
                <option value="admin">Admin</option>
                <option value="superadmin">Süperadmin</option>
              </select>
            </div>
            <div>
              <label class="form-label">Geçici Şifre</label>
              <input class="form-control" type="password" name="password" required placeholder="En az 8 karakter">
            </div>
            <div>
              <label class="form-label">Şifre (Tekrar)</label>
              <input class="form-control" type="password" name="password_confirm" required>
            </div>
            <button class="btn btn-brand mt-2">Yöneticiyi Oluştur</button>
            <p class="text-muted small mb-0">Hesap oluşturulduktan sonra parolayı paylaşmayı unutmayın. Girişte değiştirilebilir.</p>
          </form>
        </div>
      </div>

      <div class="col-lg-8">
        <div class="card-lite p-4 team-card">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Mevcut Yöneticiler</h5>
            <span class="text-muted small">Toplam <?=count($admins)?> yönetici</span>
          </div>
          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>Adı</th>
                  <th>E-posta</th>
                  <th>Rol</th>
                  <th>Son Giriş</th>
                  <th class="text-end">İşlemler</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($admins as $admin): ?>
                  <tr>
                    <td>
                      <div class="fw-semibold"><?=h($admin['name'] ?: '—')?><?= $admin['id'] === ($me['id'] ?? 0) ? ' <span class="badge bg-info">Siz</span>' : '' ?></div>
                      <div class="text-muted small">Oluşturulma: <?=h($admin['created_at'])?></div>
                    </td>
                    <td><?=h($admin['email'])?></td>
                    <td>
                      <span class="role-chip <?=h($admin['role'])?>"><?= $admin['role'] === 'superadmin' ? 'Süperadmin' : 'Admin' ?></span>
                    </td>
                    <td>
                      <?php if ($admin['last_login_at']): ?>
                        <span class="text-muted small"><?=h($admin['last_login_at'])?></span>
                      <?php else: ?>
                        <span class="text-muted small">Henüz giriş yapmadı</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end">
                      <form method="post" class="d-inline-flex gap-2 align-items-center flex-wrap justify-content-end">
                        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                        <input type="hidden" name="do" value="update_role">
                        <input type="hidden" name="user_id" value="<?=$admin['id']?>">
                        <select class="form-select form-select-sm" name="role">
                          <option value="admin" <?=$admin['role']==='admin'?'selected':''?>>Admin</option>
                          <option value="superadmin" <?=$admin['role']==='superadmin'?'selected':''?>>Süperadmin</option>
                        </select>
                        <button class="btn btn-sm btn-brand-outline" type="submit">Rolü Kaydet</button>
                      </form>
                      <details class="mt-2">
                        <summary>Parola Sıfırla</summary>
                        <form method="post" class="vstack gap-2 mt-2">
                          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                          <input type="hidden" name="do" value="reset_password">
                          <input type="hidden" name="user_id" value="<?=$admin['id']?>">
                          <input class="form-control form-control-sm" type="password" name="password" placeholder="Yeni şifre" required>
                          <input class="form-control form-control-sm" type="password" name="password_confirm" placeholder="Şifre tekrar" required>
                          <button class="btn btn-sm btn-brand" type="submit">Parolayı Güncelle</button>
                        </form>
                      </details>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>
</body>
</html>
