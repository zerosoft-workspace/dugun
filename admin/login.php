<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/theme.php';
require_once __DIR__ . '/../includes/login_header.php';

// Çıkış
if (isset($_GET['logout'])) {
  admin_logout();
  flash('ok', 'Çıkış yapıldı.');
  redirect('login.php');
}

// Zaten girişliyse dashboard'a
if (is_admin_logged_in()) {
  $next = $_GET['next'] ?? 'dashboard.php';
  redirect($next);
}

$firstRun = ((int)pdo()->query("SELECT COUNT(*) FROM users")->fetchColumn()) === 0;
$mode = $firstRun ? 'setup' : 'login';
$err = null;

if ($mode === 'setup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_or_die();

  $name  = trim($_POST['name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $pass  = (string)($_POST['password'] ?? '');
  $pass2 = (string)($_POST['password_confirm'] ?? '');

  if (mb_strlen($name) < 3) {
    $err = 'Lütfen en az 3 karakterlik bir ad girin.';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $err = 'Geçerli bir e-posta adresi girin.';
  } elseif (mb_strlen($pass) < 8) {
    $err = 'Şifre en az 8 karakter olmalıdır.';
  } elseif ($pass !== $pass2) {
    $err = 'Şifreler eşleşmiyor.';
  } else {
    try {
      pdo()->prepare("INSERT INTO users (email,password_hash,name,role,created_at,updated_at) VALUES (?,?,?,?,?,?)")
          ->execute([$email, password_hash($pass, PASSWORD_DEFAULT), $name, 'superadmin', now(), now()]);
      admin_login($email, $pass);
      flash('ok', 'İlk yönetici hesabı hazır!');
      redirect('dashboard.php');
    } catch (Throwable $e) {
      $err = 'Hesap oluşturulamadı. Lütfen bilgileri kontrol edin.';
    }
  }
}

// Giriş denemesi
if ($mode === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_or_die(); // CSRF kontrolü

  $email = trim($_POST['email'] ?? '');
  $pass  = (string)($_POST['password'] ?? '');
  $next  = $_POST['next'] ?? 'dashboard.php';

  if ($email === '' || $pass === '') {
    $err = 'E-posta ve şifre zorunludur.';
  } else {
    if (admin_login($email, $pass)) {
      redirect($next);
    } else {
      $err = 'E-posta veya şifre hatalı.';
    }
  }
}

// next parametresini koru
$next = $_GET['next'] ?? ($_POST['next'] ?? 'dashboard.php');
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $mode === 'setup' ? 'İlk Yönetici Kurulumu' : 'Yönetici Girişi' ?> — <?=h(APP_NAME)?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <?=theme_head_assets()?>
  <style>
    <?=login_header_styles()?>
    :root{ --brand:#0ea5b5; --brand-dark:#0b8b98; --ink:#0f172a; --muted:#64748b; --surface:#ffffff; --shell-shadow:0 40px 120px -45px rgba(15,23,42,.45); }
    :root[data-theme="dark"]{ --brand:#38bdf8; --brand-dark:#0ea5b5; --ink:#e2e8f0; --muted:#94a3b8; --surface:rgba(15,23,42,.9); --shell-shadow:0 46px 120px -60px rgba(8,47,73,.85); }
    *{box-sizing:border-box;}
    body{min-height:100vh;margin:0;display:flex;flex-direction:column;background:radial-gradient(circle at top,#ecfeff 0%,#f8fafc 55%,#eef2ff 100%);font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;color:var(--ink);transition:background .25s ease,color .25s ease;}
    [data-theme="dark"] body{background:radial-gradient(circle at top,#0f172a 0%,#020617 52%,#010b17 100%);color:var(--ink);}
    .auth-layout{flex:1;width:100%;display:flex;align-items:center;justify-content:center;padding:2.5rem 1.5rem 3rem;}
    .auth-shell{width:100%;max-width:1040px;background:var(--surface);border-radius:28px;box-shadow:var(--shell-shadow);display:flex;overflow:hidden;border:1px solid rgba(148,163,184,.18);transition:background .25s ease,box-shadow .25s ease,border-color .25s ease;}
    [data-theme="dark"] .auth-shell{border-color:rgba(148,163,184,.24);}
    .auth-visual{flex:1;position:relative;padding:3rem 3.2rem;background:linear-gradient(135deg,rgba(14,165,181,.92),rgba(14,165,181,.72)),url('https://images.unsplash.com/photo-1520854221050-0f4caff449fb?auto=format&fit=crop&w=1200&q=80') center/cover;color:#fff;display:flex;flex-direction:column;justify-content:space-between;min-height:100%;transition:background .25s ease,color .25s ease;}
    [data-theme="dark"] .auth-visual{background:linear-gradient(140deg,rgba(14,165,181,.32),rgba(15,23,42,.9)),url('https://images.unsplash.com/photo-1520854221050-0f4caff449fb?auto=format&fit=crop&w=1200&q=80') center/cover;color:#f8fafc;box-shadow:inset 0 0 0 1px rgba(56,189,248,.12);}
    .auth-visual::after{content:"";position:absolute;inset:0;background:linear-gradient(160deg,rgba(15,23,42,.05),rgba(15,23,42,.35));mix-blend-mode:multiply;}
    [data-theme="dark"] .auth-visual::after{background:linear-gradient(170deg,rgba(8,47,73,.55),rgba(15,23,42,.72));mix-blend-mode:normal;opacity:.85;}
    .auth-visual > *{position:relative;z-index:1;}
    .visual-badge{display:inline-flex;align-items:center;gap:.55rem;background:rgba(255,255,255,.16);border-radius:999px;padding:.45rem 1.1rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;font-size:.78rem;}
    .visual-title{font-size:2rem;font-weight:800;line-height:1.2;margin-top:1.5rem;margin-bottom:1rem;}
    .visual-text{font-size:1rem;line-height:1.6;color:rgba(255,255,255,.85);max-width:420px;}
    [data-theme="dark"] .visual-text{color:rgba(226,232,240,.86);}
    .visual-list{list-style:none;padding:0;margin:1.5rem 0 0;display:flex;flex-direction:column;gap:.85rem;}
    .visual-list li{display:flex;align-items:flex-start;gap:.7rem;font-weight:600;color:rgba(255,255,255,.9);}
    .visual-list span{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background:rgba(255,255,255,.18);font-size:.9rem;}
    .visual-footer{font-size:.82rem;color:rgba(255,255,255,.75);margin-top:2.5rem;max-width:320px;}
    [data-theme="dark"] .visual-footer{color:rgba(226,232,240,.72);}
    .auth-form{flex:1;padding:3rem 3.2rem;display:flex;flex-direction:column;justify-content:center;gap:1.75rem;}
    .brand{font-weight:800;font-size:1.6rem;color:var(--ink);letter-spacing:.2px;transition:color .25s ease;}
    .brand span{display:block;font-size:.92rem;font-weight:600;color:var(--muted);margin-top:.35rem;}
    .alert{border-radius:14px;font-weight:500;}
    form .form-label{font-weight:600;color:var(--muted);}
    .form-control{border-radius:14px;border:1px solid rgba(148,163,184,.35);padding:.7rem 1rem;font-size:1rem;background:#fff;color:var(--ink);transition:background .25s ease,border-color .25s ease,color .25s ease;}
    .form-control:focus{border-color:var(--brand);box-shadow:0 0 0 .25rem rgba(14,165,181,.18);}
    [data-theme="dark"] .form-control{background:rgba(2,8,23,.68);border-color:rgba(148,163,184,.36);color:var(--ink);}
    [data-theme="dark"] .form-control::placeholder{color:rgba(148,163,184,.68);}
    .btn-brand{background:var(--brand);color:#fff;border:none;border-radius:14px;padding:.8rem 1rem;font-weight:700;font-size:1rem;transition:background .2s ease,transform .2s ease,color .2s ease,box-shadow .2s ease;}
    .btn-brand:hover{background:var(--brand-dark);transform:translateY(-1px);color:#fff;}
    [data-theme="dark"] .btn-brand{background:linear-gradient(135deg,#38bdf8,#0ea5b5);color:#04121f;box-shadow:0 22px 46px -22px rgba(8,47,73,.78);}
    [data-theme="dark"] .btn-brand:hover{color:#04121f;}
    .form-note{font-size:.9rem;color:var(--muted);line-height:1.5;}
    .back-link{font-weight:600;text-decoration:none;color:var(--brand);}
    .back-link:hover{text-decoration:underline;color:var(--brand-dark);}
    [data-theme="dark"] .back-link{color:#38bdf8;}
    [data-theme="dark"] .back-link:hover{color:#7dd3fc;}
    .first-run-badge{display:inline-flex;align-items:center;gap:.45rem;background:rgba(14,165,181,.12);color:var(--brand-dark);padding:.4rem .9rem;border-radius:999px;font-weight:600;font-size:.85rem;transition:background .25s ease,color .25s ease;}
    [data-theme="dark"] .first-run-badge{background:rgba(56,189,248,.18);color:#38bdf8;}
    @media (max-width: 992px){
      body{padding:1.5rem;}
      .auth-shell{flex-direction:column;}
      .auth-form{order:-1;padding:2.4rem;}
      .auth-visual{min-height:260px;padding:2.6rem;}
    }
    @media (max-width: 576px){.auth-form{padding:2rem;} .visual-title{font-size:1.6rem;} }
  </style>
</head>
<body>
  <?php render_login_header(null); ?>
  <main class="auth-layout">
  <div class="auth-shell">
    <aside class="auth-visual">
      <div>
        <span class="visual-badge">BİKARE Studio</span>
        <h1 class="visual-title">Etkinlik yönetiminin kalbi burada.</h1>
        <p class="visual-text">BİKARE ile kampanyaları planlayın, bayilerinizi yönetin ve etkinlik sahipleriniz için unutulmaz dijital deneyimler hazırlayın. Yönetici paneli tüm operasyonu tek ekranda toplar.</p>
        <ul class="visual-list">
          <li><span>✓</span>Gerçek zamanlı etkinlik performansı ve raporlar</li>
          <li><span>✓</span>Bayi ve salon ağınızı tek noktadan yönetin</li>
          <li><span>✓</span>Misafir deneyimini marka standartlarında yönlendirin</li>
        </ul>
      </div>
      <p class="visual-footer">BİKARE, Zerosoft güvencesiyle etkinlik teknolojisini yeniden tanımlar.</p>
    </aside>
    <section class="auth-form">
      <div>
        <?php if ($mode === 'setup'): ?>
          <span class="first-run-badge">🚀 İlk Kurulum</span>
        <?php endif; ?>
        <div class="brand"><?=h(APP_NAME)?> <span>Yönetim Paneli</span></div>
      </div>

      <?php if ($m = flash('ok')): ?>
        <div class="alert alert-success mb-0"><?=$m?></div>
      <?php endif; ?>
      <?php if ($err): ?>
        <div class="alert alert-danger mb-0"><?=$err?></div>
      <?php endif; ?>

      <?php if ($mode === 'setup'): ?>
        <div>
          <p class="form-note">İlk süperadmin hesabınızı oluşturun. Bu hesap, ekibinizi davet etmek ve tüm organizasyonel ayarları yapmak için kullanılacaktır.</p>
          <form method="post" class="vstack gap-3">
            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
            <div>
              <label class="form-label">Ad Soyad</label>
              <input class="form-control" type="text" name="name" required autofocus placeholder="Örn. Ayşe Yılmaz">
            </div>
            <div>
              <label class="form-label">E-posta</label>
              <input class="form-control" type="email" name="email" required placeholder="ornek@firma.com">
            </div>
            <div>
              <label class="form-label">Şifre</label>
              <input class="form-control" type="password" name="password" required placeholder="En az 8 karakter">
            </div>
            <div>
              <label class="form-label">Şifre (Tekrar)</label>
              <input class="form-control" type="password" name="password_confirm" required>
            </div>
            <button class="btn-brand mt-2 w-100">İlk Süperadmini Oluştur</button>
          </form>
        </div>
      <?php else: ?>
        <div>
          <p class="form-note">Yönetici hesabınızla giriş yapın ve paneldeki canlı etkinlikleri, bayi bakiyelerini ve kampanyaları takip etmeye devam edin.</p>
          <form method="post" class="vstack gap-3">
            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="next" value="<?=h($next)?>">
            <div>
              <label class="form-label">E-posta</label>
              <input class="form-control" type="email" name="email" required autofocus placeholder="ornek@firma.com">
            </div>
            <div>
              <label class="form-label">Şifre</label>
              <input class="form-control" type="password" name="password" required placeholder="Şifrenizi yazın">
            </div>
            <div class="d-flex justify-content-end">
              <a class="small text-decoration-none" href="forgot.php">Şifremi unuttum</a>
            </div>
            <button class="btn-brand mt-2 w-100">Giriş Yap</button>
          </form>
        </div>
      <?php endif; ?>

      <div>
        <a class="back-link" href="https://drive.demozerosoft.com.tr/">← drive.demozerosoft.com.tr ana sayfasına dön</a>
      </div>
    </section>
  </div>
  </main>
</body>
</html>
