<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/dealer_auth.php';
require_once __DIR__.'/../includes/dealers.php';

install_schema();

if (isset($_GET['logout'])) {
  dealer_logout();
  flash('ok', 'Oturum kapatıldı.');
}

if (dealer_user()) {
  redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $pass  = (string)($_POST['password'] ?? '');
  if (dealer_login($email, $pass)) {
    $next = $_GET['next'] ?? 'dashboard.php';
    redirect($next);
  } else {
    flash('err', 'Giriş başarısız. Bilgilerinizi kontrol edin.');
  }
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?=h(APP_NAME)?> — Bayi Girişi</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{ --brand:#0ea5b5; --brand-dark:#0b8b98; --ink:#0f172a; --muted:#5f6c7b; }
    *{box-sizing:border-box;}
    body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2.5rem;background:radial-gradient(circle at 20% 20%,rgba(14,165,181,.18),rgba(14,165,181,.04) 60%,#f8fafc);font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;color:var(--ink);}
    .auth-shell{width:100%;max-width:1100px;background:#fff;border-radius:30px;box-shadow:0 48px 120px -60px rgba(15,23,42,.55);display:flex;overflow:hidden;border:1px solid rgba(148,163,184,.18);}
    .auth-visual{flex:1.05;position:relative;padding:3.2rem;background:linear-gradient(140deg,rgba(15,118,110,.92),rgba(14,165,181,.75)),url('https://images.unsplash.com/photo-1530023367847-a683933f4177?auto=format&fit=crop&w=1200&q=80') center/cover;color:#fff;display:flex;flex-direction:column;justify-content:space-between;}
    .auth-visual::after{content:"";position:absolute;inset:0;background:linear-gradient(155deg,rgba(12,74,110,.25),rgba(15,23,42,.45));mix-blend-mode:soft-light;}
    .auth-visual > *{position:relative;z-index:1;}
    .badge{display:inline-flex;align-items:center;gap:.6rem;padding:.45rem 1.2rem;border-radius:999px;background:rgba(255,255,255,.18);font-weight:600;letter-spacing:.08em;text-transform:uppercase;font-size:.78rem;}
    .visual-title{font-size:2.1rem;font-weight:800;line-height:1.2;margin:1.6rem 0 1rem;max-width:420px;}
    .visual-text{font-size:1.02rem;line-height:1.7;color:rgba(255,255,255,.86);max-width:440px;}
    .feature-list{list-style:none;padding:0;margin:1.8rem 0 0;display:flex;flex-direction:column;gap:1rem;}
    .feature-list li{display:flex;align-items:flex-start;gap:.75rem;font-weight:600;color:rgba(255,255,255,.9);}
    .feature-list span{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,.18);font-size:1rem;}
    .visual-footer{font-size:.84rem;color:rgba(255,255,255,.75);max-width:340px;margin-top:2.8rem;}
    .auth-form{flex:.95;padding:3rem 3.2rem;display:flex;flex-direction:column;justify-content:center;gap:1.8rem;}
    .brand{font-weight:800;font-size:1.7rem;letter-spacing:.18rem;margin-bottom:.2rem;}
    .brand span{display:block;font-size:.95rem;font-weight:600;color:var(--muted);margin-top:.35rem;letter-spacing:0;}
    .form-note{color:var(--muted);font-size:.94rem;line-height:1.6;}
    .form-control{border-radius:14px;border:1px solid rgba(148,163,184,.32);padding:.75rem 1rem;font-size:1rem;}
    .form-control:focus{border-color:var(--brand);box-shadow:0 0 0 .25rem rgba(14,165,181,.18);}
    .btn-brand{background:var(--brand);color:#fff;border:none;border-radius:14px;padding:.85rem 1rem;font-weight:700;font-size:1rem;transition:transform .2s ease,box-shadow .2s ease;background-image:linear-gradient(135deg,#0ea5b5,#0b8b98);}
    .btn-brand:hover{transform:translateY(-1px);box-shadow:0 18px 32px -20px rgba(14,165,181,.6);color:#fff;}
    .apply-box{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1.1rem 1.4rem;border-radius:16px;background:#f1fbfc;border:1px solid rgba(14,165,181,.16);}
    .apply-box strong{color:var(--ink);}
    .apply-link{font-weight:700;color:var(--brand);text-decoration:none;}
    .apply-link:hover{text-decoration:underline;color:var(--brand-dark);}
    .alert{border-radius:14px;font-weight:500;}
    @media(max-width:992px){body{padding:1.5rem;} .auth-shell{flex-direction:column;} .auth-visual{padding:2.6rem;} .auth-form{padding:2.4rem;}}
    @media(max-width:576px){.auth-form{padding:2rem;} .visual-title{font-size:1.7rem;}}
  </style>
</head>
<body>
  <div class="auth-shell">
    <aside class="auth-visual">
      <div>
        <span class="badge">Bayi Platformu</span>
        <h1 class="visual-title">Yerel iş ortaklıklarını BİKARE ile büyütün.</h1>
        <p class="visual-text">Etkinlik sahiplerine değer katan salonlar ve ajanslar, BİKARE bayi paneli sayesinde QR yönetiminden kampanya yayınlarına kadar tüm süreci uçtan uca takip eder.</p>
        <ul class="feature-list">
          <li><span>✓</span>Etkinlik kredilerinizi ve cari bakiyenizi tek bakışta görün</li>
          <li><span>✓</span>Salonlarınızdaki tüm etkinlikleri yönetin ve QR paylaşımlarını üretin</li>
          <li><span>✓</span>Yeni paketler satın alarak kapasitenizi dakikalar içinde artırın</li>
        </ul>
      </div>
      <p class="visual-footer">Zerosoft güvencesiyle geliştirilen BİKARE, bayiler için sürdürülebilir bir gelir modeli sunar.</p>
    </aside>
    <section class="auth-form">
      <div>
        <div class="brand">BİKARE <span>Bayi Paneli</span></div>
        <p class="form-note">Bayi kodunuz ve size özel şifrenizle giriş yapın. Panel üzerinden etkinlik oluşturabilir, müşterilerinizin yüklemelerini takip edebilir ve finansal hareketlerinizi yönetebilirsiniz.</p>
      </div>
      <?php flash_box(); ?>
      <form method="post" class="vstack gap-3">
        <div>
          <label class="form-label">E-posta</label>
          <input type="email" name="email" class="form-control" required placeholder="ornek@bikarebayi.com">
        </div>
        <div>
          <label class="form-label">Şifre</label>
          <input type="password" name="password" class="form-control" required placeholder="Şifrenizi yazın">
        </div>
        <div class="text-end">
          <a class="small text-decoration-none" href="forgot.php">Şifremi unuttum</a>
        </div>
        <button class="btn-brand" type="submit">Bayi Paneline Giriş Yap</button>
      </form>
      <div class="apply-box">
        <div>
          <strong>Bayi ağımıza katılmak ister misiniz?</strong>
          <div class="text-muted small">Başvuru formunu doldurun, ekibimiz en kısa sürede sizi arayarak detayları paylaşsın.</div>
        </div>
        <a class="apply-link" href="apply.php">Başvuru Formu →</a>
      </div>
    </section>
  </div>
</body>
</html>
