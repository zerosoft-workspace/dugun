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
<style>
  .password-grid {
    display:grid;
    gap:26px;
  }

  @media (min-width: 992px) {
    .password-grid {
      grid-template-columns:minmax(0,1.65fr) minmax(0,1fr);
      align-items:stretch;
    }
  }

  .password-card {
    position:relative;
    overflow:hidden;
    display:flex;
    flex-direction:column;
    gap:20px;
  }

  .password-card::after {
    content:"";
    position:absolute;
    inset:auto -120px -140px auto;
    width:280px;
    height:280px;
    background:radial-gradient(circle at center, rgba(14,165,181,.28), transparent 65%);
    pointer-events:none;
  }

  .password-card-header {
    display:flex;
    align-items:center;
    gap:18px;
  }

  .password-card-icon {
    width:58px;
    height:58px;
    border-radius:18px;
    background:rgba(14,165,181,.12);
    display:flex;
    align-items:center;
    justify-content:center;
    color:var(--admin-brand-dark);
    font-size:1.7rem;
  }

  .password-card h3 {
    margin:0;
    font-weight:600;
  }

  .password-card p {
    margin:0;
    color:var(--admin-muted);
  }

  .password-tips {
    border-radius:24px;
    background:linear-gradient(145deg, rgba(15,23,42,.92), rgba(30,64,175,.72));
    color:#f8fafc;
    display:flex;
    flex-direction:column;
    gap:18px;
    padding:34px 32px;
    position:relative;
    overflow:hidden;
    box-shadow:0 24px 42px -26px rgba(15,23,42,.65);
  }

  .password-tips::after {
    content:"";
    position:absolute;
    inset:-40px auto auto -60px;
    width:160px;
    height:160px;
    background:radial-gradient(circle at center, rgba(59,130,246,.55), transparent 60%);
    opacity:.85;
  }

  .password-tips h4 {
    margin:0;
    font-weight:600;
  }

  .password-tips ul {
    margin:0;
    padding-left:1.1rem;
    display:flex;
    flex-direction:column;
    gap:.55rem;
  }

  .password-tips li {
    font-size:.95rem;
  }

  .chip {
    display:inline-flex;
    align-items:center;
    gap:.45rem;
    border-radius:999px;
    font-size:.8rem;
    font-weight:600;
    background:rgba(14,165,181,.15);
    color:var(--admin-brand-dark);
    padding:.35rem .85rem;
  }

  .password-meta {
    display:flex;
    flex-wrap:wrap;
    gap:.45rem;
  }

  .password-divider {
    height:1px;
    background:rgba(15,23,42,.08);
    margin:6px 0 2px;
  }

  .password-form .form-label {
    font-weight:600;
    color:rgba(15,23,42,.72);
  }
</style>

<div class="password-grid">
  <div class="card-lite password-card">
    <div class="password-card-header">
      <div class="password-card-icon"><i class="bi bi-lock"></i></div>
      <div>
        <h3>Şifrenizi Güçlendirin</h3>
        <p>Hesabınızı korumak için karmaşık ve benzersiz bir şifre belirleyin.</p>
      </div>
    </div>
    <div class="password-divider"></div>
    <div class="password-meta">
      <span class="chip"><i class="bi bi-clock-history"></i> 90 günde bir yenileyin</span>
      <span class="chip"><i class="bi bi-shield-check"></i> Güçlü güvenlik</span>
    </div>
    <?php flash_box(); ?>
    <?php if ($err): ?><div class="alert alert-danger mb-1"><?=h($err)?></div><?php endif; ?>
    <?php if ($forceReset): ?>
      <div class="alert alert-info">
        <strong>Hoş geldiniz!</strong> Geçici şifrenizi kişisel şifrenizle değiştirin. Mevcut şifreyi girmenize gerek yok.
      </div>
    <?php endif; ?>
    <form method="post" class="row g-3 password-form">
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
  <aside class="password-tips">
    <span class="chip" style="background:rgba(255,255,255,.15);color:#fff;"><i class="bi bi-info-circle"></i> Güvenlik Hatırlatıcısı</span>
    <h4>Hesabınızı Her Zaman Koruyun</h4>
    <p>Şifrenizi belirlerken aşağıdaki önerileri takip ederek olası riskleri en aza indirebilirsiniz.</p>
    <ul>
      <li>En az 8 karakter ve büyük/küçük harf, rakam, sembol kombinasyonu kullanın.</li>
      <li>Aynı şifreyi birden fazla platformda paylaşmayın.</li>
      <li>Şüpheli bir giriş fark ederseniz şifrenizi hemen yenileyin.</li>
      <li>Tarayıcınıza kayıtlı şifreleri düzenli olarak kontrol edin.</li>
    </ul>
    <div class="password-divider" style="background:rgba(255,255,255,.18);"></div>
    <p class="mb-0" style="font-size:.9rem;opacity:.85;">Sorularınız için <strong>destek@bikare.com.tr</strong> adresi üzerinden ekibimizle iletişime geçebilirsiniz.</p>
  </aside>
</div>
<?php admin_layout_end(); ?>
