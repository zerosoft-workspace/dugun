<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/representatives.php';
require_once __DIR__.'/../includes/representative_auth.php';
require_once __DIR__.'/../includes/login_header.php';

install_schema();

if (isset($_GET['logout'])) {
  representative_logout();
  flash('ok', 'Oturum kapatıldı.');
}

if (representative_user()) {
  redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $password = (string)($_POST['password'] ?? '');
  if (representative_login($email, $password)) {
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
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h(APP_NAME)?> — Temsilci Girişi</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    <?=login_header_styles()?>
    :root{--brand:#8b5cf6;--brand-dark:#7c3aed;--ink:#0f172a;--muted:#5f6c7b;--bg:radial-gradient(circle at top,#ede9fe 0%,#f8fafc 58%);}
    *{box-sizing:border-box;}
    body{margin:0;min-height:100vh;display:flex;flex-direction:column;background:var(--bg);font-family:'Inter','Poppins','Segoe UI',system-ui,sans-serif;color:var(--ink);}
    .auth-layout{flex:1;width:100%;display:flex;align-items:center;justify-content:center;padding:2.5rem 1.5rem 3rem;}
    .auth-shell{width:100%;max-width:1100px;background:#fff;border-radius:30px;box-shadow:0 56px 120px -62px rgba(76,29,149,.35);display:flex;overflow:hidden;border:1px solid rgba(148,163,184,.18);}
    .auth-visual{flex:1.02;position:relative;padding:3.1rem;background:linear-gradient(140deg,rgba(76,29,149,.92),rgba(129,140,248,.82)),url('https://images.unsplash.com/photo-1545239351-1141bd82e8a6?auto=format&fit=crop&w=1200&q=80') center/cover;color:#fff;display:flex;flex-direction:column;justify-content:space-between;}
    .auth-visual::after{content:"";position:absolute;inset:0;background:linear-gradient(155deg,rgba(30,64,175,.35),rgba(30,41,59,.45));mix-blend-mode:soft-light;}
    .auth-visual > *{position:relative;z-index:1;}
    .badge{display:inline-flex;align-items:center;gap:.6rem;padding:.48rem 1.25rem;border-radius:999px;background:rgba(255,255,255,.18);font-weight:600;letter-spacing:.08em;text-transform:uppercase;font-size:.78rem;}
    .visual-title{font-size:2.05rem;font-weight:800;line-height:1.25;margin:1.6rem 0 1.1rem;max-width:430px;}
    .visual-text{font-size:1.02rem;line-height:1.75;color:rgba(248,250,252,.86);max-width:440px;}
    .feature-list{list-style:none;padding:0;margin:1.8rem 0 0;display:flex;flex-direction:column;gap:1rem;max-width:420px;}
    .feature-list li{display:flex;align-items:flex-start;gap:.75rem;font-weight:600;color:rgba(255,255,255,.9);}
    .feature-list span{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,.18);font-size:1rem;}
    .visual-metrics{display:grid;gap:1rem;grid-template-columns:repeat(2,minmax(0,1fr));margin-top:2.2rem;}
    .metric-card{background:rgba(15,23,42,.22);border-radius:18px;padding:1rem 1.1rem;backdrop-filter:blur(8px);}
    .metric-value{font-size:1.4rem;font-weight:700;margin:0;}
    .metric-label{margin:0;color:rgba(226,232,240,.78);font-size:.85rem;letter-spacing:.04em;text-transform:uppercase;}
    .auth-form{flex:.98;padding:3.1rem 3.2rem;display:flex;flex-direction:column;justify-content:center;gap:2rem;}
    .brand{font-weight:800;font-size:1.6rem;letter-spacing:.16rem;margin-bottom:.3rem;color:var(--ink);text-transform:uppercase;}
    .brand span{display:block;font-size:.95rem;font-weight:600;color:var(--muted);margin-top:.35rem;letter-spacing:0;text-transform:none;}
    .form-note{color:var(--muted);font-size:.95rem;line-height:1.65;max-width:460px;}
    .form-control{border-radius:14px;border:1px solid rgba(148,163,184,.32);padding:.8rem 1rem;font-size:1rem;}
    .form-control:focus{border-color:var(--brand);box-shadow:0 0 0 .25rem rgba(139,92,246,.18);}
    .btn-brand{background:linear-gradient(135deg,#8b5cf6,#7c3aed);color:#fff;border:none;border-radius:14px;padding:.85rem 1rem;font-weight:700;font-size:1rem;transition:transform .2s ease,box-shadow .2s ease;}
    .btn-brand:hover{transform:translateY(-1px);box-shadow:0 20px 36px -22px rgba(99,102,241,.65);color:#fff;}
    .alert{border-radius:14px;font-weight:500;}
    .support-card{background:#f5f3ff;border:1px solid rgba(129,140,248,.28);border-radius:16px;padding:1.1rem 1.3rem;display:flex;gap:1rem;align-items:flex-start;}
    .support-card strong{color:var(--ink);}
    .support-card span{display:inline-flex;width:36px;height:36px;border-radius:12px;background:rgba(129,140,248,.18);align-items:center;justify-content:center;font-size:1.1rem;color:#4c1d95;}
    .home-link{font-weight:600;color:#7c3aed;text-decoration:none;}
    .home-link:hover{text-decoration:underline;color:#6d28d9;}
    @media(max-width:992px){body{padding:1.5rem;} .auth-shell{flex-direction:column;} .auth-visual{padding:2.6rem;} .auth-form{padding:2.6rem 2.4rem;}}
    @media(max-width:576px){.auth-form{padding:2.2rem;} .visual-title{font-size:1.7rem;}}
  </style>
</head>
<body>
  <?php render_login_header('representative'); ?>
  <main class="auth-layout">
  <div class="auth-shell">
    <aside class="auth-visual">
      <div>
        <span class="badge">Temsilci Portalı</span>
        <h1 class="visual-title">Salon ve etkinlik ağınızı tek panelden yönetin.</h1>
        <p class="visual-text">BİKARE temsilcileri, bölgesindeki tüm bayi ve salonları tek yerden takip eder. Operasyon akışlarını hızlandırır, müşteri memnuniyetini yükseltir ve yeni iş fırsatlarını raporlarla görünür kılar.</p>
        <ul class="feature-list">
          <li><span>✓</span>Görüşme notlarını, görevleri ve müşteri durumlarını aynı ekranda tutun</li>
          <li><span>✓</span>Bayilere ait ciro hedeflerini ve ödeme planlarını günlük olarak takip edin</li>
          <li><span>✓</span>Etkinlik QR paylaşımları ve medya yüklemeleri için merkezi arayüz kullanın</li>
        </ul>
      </div>
      <div class="visual-metrics">
        <div class="metric-card">
          <p class="metric-value">%97</p>
          <p class="metric-label">Memnuniyet Skoru</p>
        </div>
        <div class="metric-card">
          <p class="metric-value">12+</p>
          <p class="metric-label">Şehirde Aktif Ağ</p>
        </div>
      </div>
    </aside>
    <section class="auth-form">
      <div>
        <div class="brand">BİKARE <span>Temsilci Paneli</span></div>
        <p class="form-note">Temsilci e-posta adresiniz ve size iletilen şifreniz ile giriş yapın. Panel üzerinden sorumlu olduğunuz bayilerin performansını izleyebilir, etkinlik taleplerini yönlendirebilir ve finansal süreçleri raporlayabilirsiniz.</p>
      </div>
      <?php flash_box(); ?>
      <form method="post" class="vstack gap-3">
        <div>
          <label class="form-label">E-posta</label>
          <input type="email" class="form-control" name="email" required placeholder="ornek@bikaretemsilci.com">
        </div>
        <div>
          <label class="form-label">Şifre</label>
          <input type="password" class="form-control" name="password" required placeholder="Şifrenizi yazın">
        </div>
        <div class="text-end">
          <a class="small text-decoration-none" href="forgot.php">Şifremi unuttum</a>
        </div>
        <button class="btn-brand" type="submit">Temsilci Paneline Giriş Yap</button>
      </form>
      <div class="support-card">
        <span>☎</span>
        <div>
          <strong>Destek mi lazım?</strong>
          <div class="text-muted small">Bölge sorumlusu veya destek@demozerosoft.com.tr adresinden yardım isteyin. Ekip sizi dakikalar içinde geri arar.</div>
        </div>
      </div>
      <div class="text-center">
        <a class="home-link" href="https://drive.demozerosoft.com.tr/">← drive.demozerosoft.com.tr ana sayfasına dön</a>
      </div>
    </section>
  </div>
  </main>
</body>
</html>
