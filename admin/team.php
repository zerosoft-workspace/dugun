<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/partials/ui.php';

require_admin();
require_superadmin();
install_schema();
ensure_admin_roles_seeded();

$assignablePermissions = admin_assignable_permissions();
$roles = admin_roles_all();
$defaultRoleId = admin_default_role_id();
$roleUsage = [];
try {
  $usageStmt = pdo()->query("SELECT admin_role_id, COUNT(*) AS c FROM users WHERE admin_role_id IS NOT NULL GROUP BY admin_role_id");
  while ($row = $usageStmt->fetch()) {
    $roleUsage[(int)$row['admin_role_id']] = (int)$row['c'];
  }
} catch (Throwable $e) {
  $roleUsage = [];
}

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
  $adminRoleId = (int)($_POST['admin_role_id'] ?? 0);
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
    if ($role === 'admin') {
      if ($adminRoleId <= 0 || !isset($roles[$adminRoleId])) {
        $adminRoleId = $defaultRoleId ?? 0;
      }
      if ($adminRoleId <= 0) {
        flash('err', 'En az bir yetki grubu oluşturmalısınız.');
        redirect($_SERVER['REQUEST_URI']);
      }
    } else {
      $adminRoleId = null;
    }
    $st = pdo()->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
    $st->execute([$email]);
    if ($st->fetch()) {
      flash('err', 'Bu e-posta ile kayıtlı bir yönetici zaten var.');
    } else {
      pdo()->prepare("INSERT INTO users (email,password_hash,name,role,admin_role_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?)")
          ->execute([
            $email,
            password_hash($pass, PASSWORD_DEFAULT),
            $name,
            $role,
            $role === 'superadmin' ? null : $adminRoleId,
            now(),
            now(),
          ]);
      flash('ok', 'Yeni yönetici hesabı oluşturuldu.');
    }
  }
  redirect($_SERVER['REQUEST_URI']);
}

if ($action === 'update_role') {
  $userId = (int)($_POST['user_id'] ?? 0);
  $role   = $_POST['role'] ?? 'admin';
  $adminRoleId = (int)($_POST['admin_role_id'] ?? 0);
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
  if ($role === 'admin') {
    if ($adminRoleId <= 0 || !isset($roles[$adminRoleId])) {
      $adminRoleId = $defaultRoleId ?? 0;
    }
    if ($adminRoleId <= 0) {
      flash('err', 'Geçerli bir yetki grubu seçmelisiniz.');
      redirect($_SERVER['REQUEST_URI']);
    }
  } else {
    $adminRoleId = null;
  }
  pdo()->prepare("UPDATE users SET role=?, admin_role_id=?, updated_at=? WHERE id=?")
      ->execute([$role, $role === 'superadmin' ? null : $adminRoleId, now(), $userId]);
  if ($userId === ($me['id'] ?? 0)) {
    $_SESSION['admin']['role'] = $role;
    $_SESSION['admin']['role_id'] = $role === 'superadmin' ? null : $adminRoleId;
    admin_refresh_session(true);
  }
  flash('ok', 'Rol güncellendi.');
  redirect($_SERVER['REQUEST_URI']);
}

if ($action === 'role_create') {
  $name = trim($_POST['name'] ?? '');
  $selected = $_POST['permissions'] ?? [];
  $isDefault = isset($_POST['is_default']);
  if (mb_strlen($name) < 3) {
    flash('err', 'Yetki grubu adı en az 3 karakter olmalıdır.');
    redirect($_SERVER['REQUEST_URI']);
  }
  $perms = [];
  foreach ((array)$selected as $perm) {
    $perm = (string)$perm;
    if (isset($assignablePermissions[$perm])) {
      $perms[] = $perm;
    }
  }
  $slug = slugify($name);
  if ($slug === '') {
    $slug = 'yetki-'.bin2hex(random_bytes(3));
  }
  $baseSlug = $slug;
  $check = pdo()->prepare("SELECT id FROM admin_roles WHERE slug=? LIMIT 1");
  $i = 1;
  while (true) {
    $check->execute([$slug]);
    if (!$check->fetch()) {
      break;
    }
    $slug = $baseSlug.'-'.$i++;
  }
  $now = now();
  pdo()->prepare("INSERT INTO admin_roles (name, slug, permissions_json, is_default, created_at, updated_at) VALUES (?,?,?,?,?,?)")
      ->execute([
        $name,
        $slug,
        json_encode($perms, JSON_UNESCAPED_UNICODE),
        $isDefault ? 1 : 0,
        $now,
        $now,
      ]);
  $newId = (int)pdo()->lastInsertId();
  if ($isDefault && $newId > 0) {
    pdo()->prepare("UPDATE admin_roles SET is_default=0, updated_at=? WHERE id<>?")
        ->execute([$now, $newId]);
  }
  ensure_admin_roles_seeded(true);
  admin_roles_all(true);
  flash('ok', 'Yetki grubu oluşturuldu.');
  redirect($_SERVER['REQUEST_URI']);
}

if ($action === 'role_update') {
  $roleId = (int)($_POST['role_id'] ?? 0);
  $name = trim($_POST['name'] ?? '');
  $selected = $_POST['permissions'] ?? [];
  $isDefault = isset($_POST['is_default']);
  if ($roleId <= 0 || !isset($roles[$roleId])) {
    flash('err', 'Yetki grubu bulunamadı.');
    redirect($_SERVER['REQUEST_URI']);
  }
  if (mb_strlen($name) < 3) {
    flash('err', 'Yetki grubu adı en az 3 karakter olmalıdır.');
    redirect($_SERVER['REQUEST_URI']);
  }
  $perms = [];
  foreach ((array)$selected as $perm) {
    $perm = (string)$perm;
    if (isset($assignablePermissions[$perm])) {
      $perms[] = $perm;
    }
  }
  $slug = slugify($name);
  if ($slug === '') {
    $slug = 'yetki-'.$roleId;
  }
  $baseSlug = $slug;
  $check = pdo()->prepare("SELECT id FROM admin_roles WHERE slug=? AND id<>? LIMIT 1");
  $i = 1;
  while (true) {
    $check->execute([$slug, $roleId]);
    if (!$check->fetch()) {
      break;
    }
    $slug = $baseSlug.'-'.$i++;
  }
  $now = now();
  pdo()->prepare("UPDATE admin_roles SET name=?, slug=?, permissions_json=?, is_default=?, updated_at=? WHERE id=?")
      ->execute([
        $name,
        $slug,
        json_encode($perms, JSON_UNESCAPED_UNICODE),
        $isDefault ? 1 : 0,
        $now,
        $roleId,
      ]);
  if ($isDefault) {
    pdo()->prepare("UPDATE admin_roles SET is_default=0, updated_at=? WHERE id<>?")
        ->execute([$now, $roleId]);
  }
  ensure_admin_roles_seeded(true);
  admin_roles_all(true);
  flash('ok', 'Yetki grubu güncellendi.');
  redirect($_SERVER['REQUEST_URI']);
}

if ($action === 'role_delete') {
  $roleId = (int)($_POST['role_id'] ?? 0);
  if ($roleId <= 0 || !isset($roles[$roleId])) {
    flash('err', 'Yetki grubu bulunamadı.');
    redirect($_SERVER['REQUEST_URI']);
  }
  if (!empty($roles[$roleId]['is_default'])) {
    flash('err', 'Varsayılan yetki grubu silinemez.');
    redirect($_SERVER['REQUEST_URI']);
  }
  $usage = $roleUsage[$roleId] ?? 0;
  if ($usage > 0) {
    flash('err', 'Bu yetki grubuna bağlı '.(int)$usage.' yönetici var. Önce yöneticilerin grubunu değiştirin.');
    redirect($_SERVER['REQUEST_URI']);
  }
  pdo()->prepare("DELETE FROM admin_roles WHERE id=? LIMIT 1")
      ->execute([$roleId]);
  ensure_admin_roles_seeded(true);
  admin_roles_all(true);
  flash('ok', 'Yetki grubu silindi.');
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

$roles = admin_roles_all(true);
$defaultRoleId = admin_default_role_id();
$roleUsage = [];
try {
  $usageStmt = pdo()->query("SELECT admin_role_id, COUNT(*) AS c FROM users WHERE admin_role_id IS NOT NULL GROUP BY admin_role_id");
  while ($row = $usageStmt->fetch()) {
    $roleUsage[(int)$row['admin_role_id']] = (int)$row['c'];
  }
} catch (Throwable $e) {
  $roleUsage = [];
}

if (!$defaultRoleId && $roles) {
  $defaultRoleId = array_key_first($roles);
}

$admins = pdo()->query("SELECT id, name, email, role, admin_role_id, created_at, last_login_at FROM users ORDER BY created_at DESC")->fetchAll();
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
      .role-card{ border-radius:20px; border:1px solid rgba(148,163,184,.16); box-shadow:0 18px 40px -30px rgba(15,23,42,.35); }
      .role-permission-grid{ max-height:260px; overflow:auto; border:1px solid rgba(148,163,184,.16); border-radius:14px; background:rgba(148,163,184,.08); }
      .role-permission-grid .form-check{ padding:.4rem .75rem; border-bottom:1px solid rgba(148,163,184,.12); }
      .role-permission-grid .form-check:last-child{ border-bottom:none; }
      .role-permission-grid small{ font-size:.75rem; color:var(--muted); }
      .role-group-field small{ font-size:.75rem; }
    </style>
</head>
<body class="admin-body">
<?php admin_layout_start('team', 'Yönetici Ekibi', 'Süperadmin ve admin rollerini yönetin, ekip arkadaşlarınızı davet edin.'); ?>

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
                <select class="form-select" name="role" data-role-toggle data-role-target="#createRoleGroup">
                  <option value="admin">Admin</option>
                  <option value="superadmin">Süperadmin</option>
                </select>
              </div>
              <div id="createRoleGroup" class="role-group-field vstack gap-1">
                <label class="form-label">Yetki Grubu</label>
                <select class="form-select" name="admin_role_id">
                  <?php foreach ($roles as $roleId => $roleRow): ?>
                    <option value="<?=$roleId?>" <?=$roleId === $defaultRoleId ? 'selected' : ''?>><?=h($roleRow['name'])?></option>
                  <?php endforeach; ?>
                </select>
                <small class="text-muted">Admin kullanıcılar için sayfa erişim yetkisi.</small>
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
                    <th>Yetki Grubu</th>
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
                        <?php if ($admin['role'] === 'superadmin'): ?>
                          <span class="badge bg-secondary-subtle text-dark">Tüm Sayfalar</span>
                        <?php else: ?>
                          <?php $groupName = $roles[$admin['admin_role_id']]['name'] ?? ($roles[$defaultRoleId]['name'] ?? 'Belirlenmedi'); ?>
                          <span class="badge bg-info-subtle text-dark"><?=h($groupName)?></span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if ($admin['last_login_at']): ?>
                          <span class="text-muted small"><?=h($admin['last_login_at'])?></span>
                        <?php else: ?>
                          <span class="text-muted small">Henüz giriş yapmadı</span>
                        <?php endif; ?>
                      </td>
                      <td class="text-end">
                        <form method="post" class="d-flex flex-column align-items-end gap-2">
                          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                          <input type="hidden" name="do" value="update_role">
                          <input type="hidden" name="user_id" value="<?=$admin['id']?>">
                          <select class="form-select form-select-sm" name="role" data-role-toggle data-role-target="#roleGroupWrap<?=$admin['id']?>">
                            <option value="admin" <?=$admin['role']==='admin'?'selected':''?>>Admin</option>
                            <option value="superadmin" <?=$admin['role']==='superadmin'?'selected':''?>>Süperadmin</option>
                          </select>
                          <div id="roleGroupWrap<?=$admin['id']?>" class="role-group-field w-100 <?=$admin['role']==='superadmin'?'d-none':''?>">
                            <select class="form-select form-select-sm" name="admin_role_id" <?=$admin['role']==='superadmin'?'disabled':''?>>
                              <?php foreach ($roles as $roleId => $roleRow): ?>
                                <option value="<?=$roleId?>" <?= (($admin['admin_role_id'] ?: $defaultRoleId) === $roleId) ? 'selected' : ''?>><?=h($roleRow['name'])?></option>
                              <?php endforeach; ?>
                            </select>
                            <small class="text-muted d-block">Yetki grubu</small>
                          </div>
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
      <div class="card-lite p-4 team-card mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div>
            <h5 class="mb-1">Yetki Grupları</h5>
            <p class="text-muted mb-0">Admin kullanıcılar için sayfa bazlı izinleri düzenleyin.</p>
          </div>
        </div>
        <div class="row g-4">
          <div class="col-lg-4">
            <div class="role-card p-4 h-100">
              <h6 class="mb-3">Yeni Yetki Grubu Oluştur</h6>
              <form method="post" class="vstack gap-3">
                <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                <input type="hidden" name="do" value="role_create">
                <div>
                  <label class="form-label">Grup Adı</label>
                  <input class="form-control" name="name" required placeholder="Örn. İçerik Editörü">
                </div>
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="roleCreateDefault" name="is_default">
                  <label class="form-check-label" for="roleCreateDefault">Varsayılan grup olsun</label>
                </div>
                <div class="role-permission-grid">
                  <?php foreach ($assignablePermissions as $slug => $meta): ?>
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="permissions[]" value="<?=$slug?>" id="role-create-<?=$slug?>">
                      <label class="form-check-label" for="role-create-<?=$slug?>">
                        <span class="fw-semibold d-block"><?=h($meta['label'])?></span>
                        <small><?=h($meta['description'])?></small>
                      </label>
                    </div>
                  <?php endforeach; ?>
                </div>
                <button class="btn btn-brand" type="submit">Grubu Kaydet</button>
              </form>
            </div>
          </div>
          <?php foreach ($roles as $roleId => $roleRow): ?>
            <div class="col-lg-4">
              <div class="role-card p-4 h-100">
                <form method="post" class="vstack gap-3 h-100">
                  <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="do" value="role_update">
                  <input type="hidden" name="role_id" value="<?=$roleId?>">
                  <div class="d-flex justify-content-between align-items-start gap-3">
                    <div class="flex-grow-1">
                      <label class="form-label small text-muted text-uppercase">Grup Adı</label>
                      <input class="form-control form-control-sm" name="name" required value="<?=h($roleRow['name'])?>">
                    </div>
                    <div class="form-check form-switch mt-4">
                      <input class="form-check-input" type="checkbox" id="role-default-<?=$roleId?>" name="is_default" <?=$roleRow['is_default'] ? 'checked' : ''?>>
                      <label class="form-check-label small" for="role-default-<?=$roleId?>">Varsayılan</label>
                    </div>
                  </div>
                  <div class="text-muted small">Bu grupta <?= (int)($roleUsage[$roleId] ?? 0) ?> yönetici var.</div>
                  <div class="role-permission-grid">
                    <?php foreach ($assignablePermissions as $slug => $meta): ?>
                      <?php $checked = in_array($slug, $roleRow['permissions'], true); ?>
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="permissions[]" value="<?=$slug?>" id="role-<?=$roleId?>-<?=$slug?>" <?=$checked ? 'checked' : ''?>>
                        <label class="form-check-label" for="role-<?=$roleId?>-<?=$slug?>">
                          <span class="fw-semibold d-block"><?=h($meta['label'])?></span>
                          <small><?=h($meta['description'])?></small>
                        </label>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  <div class="d-flex justify-content-between align-items-center mt-2">
                    <button class="btn btn-sm btn-brand" type="submit">Kaydet</button>
                    <?php if (!$roleRow['is_default']): ?>
                      <?php if (($roleUsage[$roleId] ?? 0) === 0): ?>
                        <button class="btn btn-sm btn-outline-danger" type="submit" form="delete-role-<?=$roleId?>">Sil</button>
                      <?php else: ?>
                        <span class="text-muted small">Silmek için <?=$roleUsage[$roleId] ?? 0?> yönetici taşınmalı.</span>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="text-muted small">Varsayılan grup</span>
                    <?php endif; ?>
                  </div>
                </form>
                <?php if (!$roleRow['is_default']): ?>
                  <form method="post" id="delete-role-<?=$roleId?>" class="d-none">
                    <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                    <input type="hidden" name="do" value="role_delete">
                    <input type="hidden" name="role_id" value="<?=$roleId?>">
                  </form>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <script>
        (function(){
          function toggleRole(select){
            var target = select.getAttribute('data-role-target');
            if (!target) return;
            var wrap = document.querySelector(target);
            if (!wrap) return;
            var isSuper = select.value === 'superadmin';
            wrap.classList.toggle('d-none', isSuper);
            wrap.querySelectorAll('select').forEach(function(el){ el.disabled = isSuper; });
          }
          document.querySelectorAll('[data-role-toggle]').forEach(function(select){
            toggleRole(select);
            select.addEventListener('change', function(){ toggleRole(select); });
          });
        })();
      </script>
  <?php admin_layout_end(); ?>
</body>
</html>
