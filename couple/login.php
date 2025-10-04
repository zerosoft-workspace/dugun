<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/couple_auth.php';

install_schema();

// Zaten girişli ise:
if (couple_is_global_logged_in()) {
  $eid = couple_current_event_id();
  if ($eid > 0) redirect(BASE_URL.'/couple/index.php');
  redirect(BASE_URL.'/couple/switch_event.php');
}

$err = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_or_die();

  $email = trim($_POST['email'] ?? '');
  $pass  = (string)($_POST['password'] ?? '');

  $events = couple_login_global($email, $pass);
  if (empty($events)) {
    $err = 'E-posta veya şifre hatalı ya da aktif etkinlik bulunamadı.';
  } else {
    if (count($events) === 1) {
      couple_set_current_event((int)$events[0]['id']);
      redirect(BASE_URL.'/couple/index.php');
    } else {
      // Çoklu etkinlik: seçme sayfasına
      // Geçici olarak listeyi session’a koymuyoruz; switch_event.php email üzerinden bulur.
      redirect(BASE_URL.'/couple/switch_event.php');
    }
  }
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Giriş — <?=h(APP_NAME)?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{ --brand:#0ea5b5; --brand-dark:#0b8b98; --ink:#0f172a; --muted:#5b6676; }
    *{box-sizing:border-box;}
    body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2.5rem;background:radial-gradient(circle at top,#e0f7fb 0%,#f8fafc 60%,#fff);font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;color:var(--ink);}
    .auth-shell{width:100%;max-width:1080px;background:#fff;border-radius:30px;box-shadow:0 48px 120px -60px rgba(15,23,42,.5);display:flex;overflow:hidden;border:1px solid rgba(148,163,184,.18);}
    .auth-visual{flex:1.05;position:relative;padding:3.1rem;background:linear-gradient(150deg,rgba(14,165,181,.9),rgba(45,212,191,.75)),url('https://images.unsplash.com/photo-1520854221050-0f4caff449fb?auto=format&fit=crop&w=1200&q=80') center/cover;color:#fff;display:flex;flex-direction:column;justify-content:space-between;}
    .auth-visual::after{content:"";position:absolute;inset:0;background:linear-gradient(160deg,rgba(15,23,42,.12),rgba(15,23,42,.45));}
    .auth-visual > *{position:relative;z-index:1;}
    .badge{display:inline-flex;align-items:center;gap:.6rem;padding:.45rem 1.15rem;border-radius:999px;background:rgba(255,255,255,.18);font-weight:600;letter-spacing:.08em;text-transform:uppercase;font-size:.78rem;}
    .visual-title{font-size:2.15rem;font-weight:800;line-height:1.2;margin:1.6rem 0 1.1rem;max-width:440px;}
    .visual-text{font-size:1.02rem;line-height:1.7;color:rgba(255,255,255,.88);max-width:440px;}
    .feature-list{list-style:none;padding:0;margin:1.8rem 0 0;display:flex;flex-direction:column;gap:1rem;}
    .feature-list li{display:flex;align-items:flex-start;gap:.75rem;font-weight:600;color:rgba(255,255,255,.9);}
    .feature-list span{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,.18);font-size:1rem;}
    .visual-footer{font-size:.84rem;color:rgba(255,255,255,.75);max-width:360px;margin-top:2.7rem;}
    .auth-form{flex:.95;padding:3rem 3.1rem;display:flex;flex-direction:column;justify-content:center;gap:1.7rem;}
    .brand{font-weight:800;font-size:1.7rem;letter-spacing:.18rem;margin-bottom:.25rem;}
    .brand span{display:block;font-size:.95rem;font-weight:600;color:var(--muted);margin-top:.35rem;letter-spacing:0;}
    .form-note{color:var(--muted);font-size:.95rem;line-height:1.6;}
    .form-control{border-radius:14px;border:1px solid rgba(148,163,184,.3);padding:.78rem 1rem;font-size:1rem;}
    .form-control:focus{border-color:var(--brand);box-shadow:0 0 0 .25rem rgba(14,165,181,.18);}
    .btn-brand{background:linear-gradient(135deg,#0ea5b5,#0b8b98);color:#fff;border:none;border-radius:14px;padding:.85rem 1rem;font-weight:700;font-size:1rem;transition:transform .2s ease,box-shadow .2s ease;}
    .btn-brand:hover{transform:translateY(-1px);box-shadow:0 20px 36px -24px rgba(14,165,181,.65);color:#fff;}
    .alert{border-radius:14px;font-weight:500;}
    .footer-links{display:flex;gap:.8rem;font-weight:600;flex-wrap:wrap;}
    .footer-links a{color:var(--brand);text-decoration:none;}
    .footer-links a:hover{text-decoration:underline;color:var(--brand-dark);}
    @media(max-width:992px){body{padding:1.5rem;} .auth-shell{flex-direction:column;} .auth-visual{padding:2.6rem;} .auth-form{padding:2.4rem;}}
    @media(max-width:576px){.auth-form{padding:2rem;} .visual-title{font-size:1.75rem;}}
  </style>
</head>
<body>
  <div class="auth-shell">
    <aside class="auth-visual">
      <div>
        <span class="badge">Çift Paneli</span>
        <h1 class="visual-title">Tüm etkinlik yönetimi tek panelde birleşiyor.</h1>
        <p class="visual-text">BİKARE çift paneliyle davetli listesinden QR yönetimine, kampanya yayınlarından misafir etkileşimlerine kadar tüm süreci yönetebilirsiniz.</p>
        <ul class="feature-list">
          <li><span>✓</span>Misafir yüklemelerini gerçek zamanlı takip edin</li>
          <li><span>✓</span>Etkinlik görünümünü kişiselleştirin ve davetiyeleri paylaşın</li>
          <li><span>✓</span>Bayi ve destek ekibiyle tek ekrandan iletişim kurun</li>
        </ul>
      </div>
      <p class="visual-footer">BİKARE, çiftlere kusursuz bir etkinlik deneyimi sunmak için Zerosoft tarafından geliştirildi.</p>
    </aside>
    <section class="auth-form">
      <div>
        <div class="brand">BİKARE <span>Çift Girişi</span></div>
        <p class="form-note">Sisteme tanımlı e-posta adresiniz ve şifrenizle giriş yaparak etkinliğinizi yönetebilirsiniz.</p>
      </div>
      <?php flash_box(); ?>
      <?php if ($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif; ?>
      <form method="post" class="vstack gap-3">
        <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
        <div>
          <label class="form-label">E-posta</label>
          <input class="form-control" type="email" name="email" required autofocus placeholder="ornek@bikare.com">
        </div>
        <div>
          <label class="form-label">Şifre</label>
          <input class="form-control" type="password" name="password" required placeholder="Şifrenizi yazın">
        </div>
        <button class="btn-brand mt-2 w-100">Giriş Yap</button>
      </form>
      <div class="footer-links">
        <a href="<?=h(BASE_URL)?>">← Anasayfaya dön</a>
        <a href="<?=h(BASE_URL)?>/couple/logout.php">Panel erişimi mi değişti?</a>
      </div>
    </section>
  </div>
</body>
</html>
