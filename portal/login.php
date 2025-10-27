<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/dealer_auth.php';
require_once __DIR__.'/../includes/representative_auth.php';
require_once __DIR__.'/../includes/theme.php';
require_once __DIR__.'/../includes/login_header.php';

install_schema();

$allowedPortals = ['dealer', 'representative'];
$defaultPortal = $GLOBALS['PORTAL_LOGIN_DEFAULT'] ?? 'dealer';
if (!in_array($defaultPortal, $allowedPortals, true)) {
    $defaultPortal = 'dealer';
}

$rawNext = trim($_GET['next'] ?? $_POST['next'] ?? '');
$portalParam = $_GET['portal'] ?? $_POST['portal'] ?? $defaultPortal;
$portal = in_array($portalParam, $allowedPortals, true) ? $portalParam : $defaultPortal;

$tabParam = $_GET['tab'] ?? $_POST['tab'] ?? null;
if ($tabParam === 'representative') {
    $portal = 'representative';
} elseif ($tabParam === 'dealer') {
    $portal = 'dealer';
}
$tab = $portal === 'representative' ? 'representative' : 'dealer';

if (isset($_GET['logout'])) {
    dealer_logout();
    representative_logout();
    flash('ok', 'Oturum kapatıldı.');
    $qs = [];
    if ($portal !== $defaultPortal) {
        $qs['portal'] = $portal;
    }
    if ($rawNext !== '') {
        $qs['next'] = $rawNext;
    }
    $self = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: $_SERVER['PHP_SELF'];
    $target = $self . ($qs ? '?' . http_build_query($qs) : '');
    redirect($target);
}

if (dealer_user()) {
    redirect(BASE_URL . '/dealer/dashboard.php');
}
if (representative_user()) {
    redirect(BASE_URL . '/representative/dashboard.php');
}

function resolve_next_redirect(string $next, string $portal): string
{
    if ($next !== '' && preg_match('~^https?://~i', $next)) {
        return $next;
    }
    $base = BASE_URL;
    if ($next === '') {
        return $base . ($portal === 'representative' ? '/representative/dashboard.php' : '/dealer/dashboard.php');
    }
    if ($next[0] === '/') {
        return $base . $next;
    }
    $prefix = $portal === 'representative' ? '/representative/' : '/dealer/';
    return $base . $prefix . ltrim($next, '/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $portal = in_array($_POST['portal'] ?? '', $allowedPortals, true) ? $_POST['portal'] : $defaultPortal;
    $tab = $portal === 'representative' ? 'representative' : 'dealer';
    $rawNext = trim($_POST['next'] ?? '');

    if ($portal === 'representative') {
        if (representative_login($email, $password)) {
            redirect(resolve_next_redirect($rawNext, 'representative'));
        }
        flash('err', 'Giriş başarısız. Bilgilerinizi kontrol edin.');
        $params = ['portal' => 'representative', 'tab' => 'representative'];
        if ($rawNext !== '') {
            $params['next'] = $rawNext;
        }
        redirect(BASE_URL . '/portal/login.php' . ($params ? '?' . http_build_query($params) : ''));
    } else {
        if (dealer_login($email, $password)) {
            redirect(resolve_next_redirect($rawNext, 'dealer'));
        }
        flash('err', 'Giriş başarısız. Bilgilerinizi kontrol edin.');
        $params = ['portal' => 'dealer', 'tab' => 'dealer'];
        if ($rawNext !== '') {
            $params['next'] = $rawNext;
        }
        redirect(BASE_URL . '/portal/login.php' . ($params ? '?' . http_build_query($params) : ''));
    }
}

$portalContent = [
    'dealer' => [
        'badge' => 'Bayi Paneli',
        'title' => 'Yerel iş ortaklıklarını BİKARE ile büyütün.',
        'text' => 'Etkinlik sahiplerine değer katan salonlar ve ajanslar, BİKARE bayi paneli sayesinde QR yönetiminden kampanya yayınlarına kadar tüm süreci uçtan uca takip eder.',
        'button' => 'Bayi Paneline Giriş Yap',
        'features' => [
            'Etkinlik kredilerinizi ve cari bakiyenizi tek bakışta görün',
            'Salonlarınızdaki tüm etkinlikleri yönetin ve QR paylaşımlarını üretin',
            'Yeni paketler satın alarak kapasitenizi dakikalar içinde artırın',
        ],
        'support' => 'Bayi ağımıza katılmak ister misiniz? Başvuru formunu doldurun, ekibimiz en kısa sürede sizi arayarak detayları paylaşsın.',
        'support_link' => ['href' => BASE_URL . '/dealer/apply.php', 'label' => 'Başvuru Formu →'],
    ],
    'representative' => [
        'badge' => 'Temsilci Portalı',
        'title' => 'Salon ve etkinlik ağınızı tek panelden yönetin.',
        'text' => 'BİKARE temsilcileri, bölgesindeki tüm bayi ve salonları tek yerden takip eder. Operasyon akışlarını hızlandırır, müşteri memnuniyetini yükseltir ve yeni iş fırsatlarını raporlarla görünür kılar.',
        'button' => 'Temsilci Paneline Giriş Yap',
        'features' => [
            'Görüşme notlarını, görevleri ve müşteri durumlarını aynı ekranda tutun',
            'Bayilere ait ciro hedeflerini ve ödeme planlarını günlük olarak takip edin',
            'Etkinlik QR paylaşımları ve medya yüklemeleri için merkezi arayüz kullanın',
        ],
        'support' => 'Destek mi lazım? Bölge sorumlusu veya destek@demozerosoft.com.tr adresinden yardım isteyin. Ekip sizi dakikalar içinde geri arar.',
        'support_link' => null,
    ],
];

$content = $portalContent[$portal];
$selfPath = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: $_SERVER['PHP_SELF'];
$nextQuery = $rawNext !== '' ? ['next' => $rawNext] : [];
$dealerTabUrl = $selfPath . '?' . http_build_query(array_merge($nextQuery, ['portal' => 'dealer', 'tab' => 'dealer']));
$repTabUrl = $selfPath . '?' . http_build_query(array_merge($nextQuery, ['portal' => 'representative', 'tab' => 'representative']));
$formAction = htmlspecialchars($selfPath, ENT_QUOTES, 'UTF-8');
$nextInput = htmlspecialchars($rawNext, ENT_QUOTES, 'UTF-8');
$portalInput = htmlspecialchars($portal, ENT_QUOTES, 'UTF-8');
$tabInput = htmlspecialchars($tab, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?=h(APP_NAME)?> — Bayi &amp; Temsilci Girişi</title>
  <?=site_head_favicon()?>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <?=theme_head_assets()?>
  <style>
    <?=login_header_styles()?>
    :root{--brand:#0ea5b5;--brand-dark:#0b8b98;--ink:#0f172a;--muted:#5f6c7b;--surface:#ffffff;--shell-shadow:0 48px 120px -60px rgba(15,23,42,.55);}
    :root[data-theme="dark"]{--brand:#38bdf8;--brand-dark:#0ea5b5;--ink:#e2e8f0;--muted:#94a3b8;--surface:rgba(15,23,42,.88);--shell-shadow:0 48px 120px -64px rgba(8,47,73,.85);}
    *{box-sizing:border-box;}
    body{margin:0;min-height:100vh;display:flex;flex-direction:column;padding:0;background:radial-gradient(circle at 20% 20%,rgba(14,165,181,.18),rgba(14,165,181,.04) 60%,#f8fafc);font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;color:var(--ink);transition:background .25s ease,color .25s ease;}
    [data-theme="dark"] body{background:radial-gradient(circle at top,#0f172a 0%,#020617 48%,#010b17 100%);color:var(--ink);}
    .auth-layout{flex:1;width:100%;display:flex;align-items:center;justify-content:center;padding:2.5rem 1.5rem 3rem;}
    .auth-shell{width:100%;max-width:1180px;background:var(--surface);border-radius:30px;box-shadow:var(--shell-shadow);display:flex;overflow:hidden;border:1px solid rgba(148,163,184,.18);position:relative;transition:background .25s ease,box-shadow .25s ease,border-color .25s ease;}
    [data-theme="dark"] .auth-shell{border-color:rgba(148,163,184,.26);}
    .auth-visual{flex:1.05;position:relative;padding:3.2rem;background:linear-gradient(140deg,rgba(15,118,110,.92),rgba(14,165,181,.75)),url('https://images.unsplash.com/photo-1530023367847-a683933f4177?auto=format&fit=crop&w=1200&q=80') center/cover;color:#fff;display:flex;flex-direction:column;justify-content:space-between;transition:background .25s ease,color .25s ease;}
    [data-theme="dark"] .auth-visual{background:linear-gradient(145deg,rgba(14,165,181,.38),rgba(15,23,42,.9)),url('https://images.unsplash.com/photo-1530023367847-a683933f4177?auto=format&fit=crop&w=1200&q=80') center/cover;color:#f8fafc;box-shadow:inset 0 0 0 1px rgba(56,189,248,.12);}
    .auth-visual::after{content:"";position:absolute;inset:0;background:linear-gradient(155deg,rgba(12,74,110,.25),rgba(15,23,42,.45));mix-blend-mode:soft-light;}
    [data-theme="dark"] .auth-visual::after{background:linear-gradient(160deg,rgba(8,47,73,.55),rgba(15,23,42,.68));mix-blend-mode:normal;opacity:.82;}
    .auth-visual > *{position:relative;z-index:1;}
    .badge{display:inline-flex;align-items:center;gap:.6rem;padding:.45rem 1.2rem;border-radius:999px;background:rgba(255,255,255,.18);font-weight:600;letter-spacing:.08em;text-transform:uppercase;font-size:.78rem;}
    .visual-title{font-size:2.1rem;font-weight:800;line-height:1.2;margin:1.6rem 0 1rem;max-width:420px;}
    .visual-text{font-size:1.02rem;line-height:1.7;color:rgba(255,255,255,.86);max-width:440px;}
    [data-theme="dark"] .visual-text{color:rgba(226,232,240,.86);}
    .feature-list{list-style:none;padding:0;margin:1.8rem 0 0;display:flex;flex-direction:column;gap:1rem;}
    .feature-list li{display:flex;align-items:flex-start;gap:.75rem;font-weight:600;color:rgba(255,255,255,.9);}
    .feature-list span{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,.18);font-size:1rem;}
    .visual-footer{font-size:.84rem;color:rgba(255,255,255,.75);max-width:360px;margin-top:2.8rem;}
    [data-theme="dark"] .visual-footer{color:rgba(226,232,240,.7);}
    .auth-form{flex:.95;padding:3.2rem;display:flex;flex-direction:column;gap:2rem;justify-content:center;}
    .entry-hub{display:flex;flex-direction:column;gap:1rem;background:#f6feff;border-radius:22px;padding:1.35rem 1.4rem;border:1px solid rgba(14,165,181,.18);box-shadow:0 24px 56px -36px rgba(14,165,181,.45);transition:background .25s ease,border-color .25s ease,box-shadow .25s ease;}
    [data-theme="dark"] .entry-hub{background:rgba(15,23,42,.82);border-color:rgba(56,189,248,.22);box-shadow:0 28px 70px -46px rgba(8,47,73,.85);}
    .entry-hub__tabs{position:relative;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));background:#fff;border-radius:18px;padding:.35rem;border:1px solid rgba(14,165,181,.16);box-shadow:0 22px 44px -38px rgba(15,118,110,.4);overflow:hidden;transition:background .25s ease,border-color .25s ease,box-shadow .25s ease;}
    [data-theme="dark"] .entry-hub__tabs{background:rgba(2,6,23,.88);border-color:rgba(56,189,248,.2);box-shadow:0 26px 60px -42px rgba(8,47,73,.85);}
    .entry-hub__tabs::after{content:"";position:absolute;inset:0;border-radius:inherit;pointer-events:none;box-shadow:inset 0 1px 0 rgba(255,255,255,.9);}
    [data-theme="dark"] .entry-hub__tabs::after{box-shadow:none;}
    .entry-hub__tabs a{position:relative;display:flex;align-items:center;justify-content:center;padding:.7rem 1.1rem;border-radius:14px;font-weight:600;font-size:.95rem;color:var(--muted);text-decoration:none;transition:color .2s ease,transform .2s ease;min-width:0;}
    .entry-hub__tabs a span{position:relative;z-index:1;}
    .entry-hub__tabs a::before{content:"";position:absolute;inset:.1rem;border-radius:12px;background:transparent;transition:background .25s ease,box-shadow .25s ease;}
    .entry-hub__tabs a.is-active{color:#fff;}
    .entry-hub__tabs a.is-active::before{background:linear-gradient(135deg,#0ea5b5,#0b8b98);box-shadow:0 18px 32px -20px rgba(14,165,181,.55);}
    [data-theme="dark"] .entry-hub__tabs a{color:rgba(226,232,240,.78);}
    [data-theme="dark"] .entry-hub__tabs a.is-active::before{background:linear-gradient(135deg,#38bdf8,#0ea5b5);box-shadow:0 22px 44px -26px rgba(56,189,248,.55);}
    .entry-hub__tabs a:hover{color:var(--ink);}
    [data-theme="dark"] .entry-hub__tabs a:hover{color:#38bdf8;}
    .entry-hub__note{color:var(--muted);font-size:.9rem;line-height:1.55;margin:0;}
    .brand{font-weight:800;font-size:1.7rem;letter-spacing:.18rem;margin-bottom:.2rem;text-transform:uppercase;color:var(--ink);transition:color .25s ease;}
    .brand span{display:block;font-size:.95rem;font-weight:600;color:var(--muted);margin-top:.35rem;letter-spacing:0;text-transform:none;}
    .form-note{color:var(--muted);font-size:.94rem;line-height:1.6;max-width:480px;}
    .form-control{border-radius:14px;border:1px solid rgba(148,163,184,.32);padding:.75rem 1rem;font-size:1rem;background:#fff;color:var(--ink);transition:background .25s ease,border-color .25s ease,color .25s ease;}
    .form-control:focus{border-color:var(--brand);box-shadow:0 0 0 .25rem rgba(14,165,181,.18);}
    [data-theme="dark"] .form-control{background:rgba(2,8,23,.68);border-color:rgba(148,163,184,.36);color:var(--ink);}
    [data-theme="dark"] .form-control::placeholder{color:rgba(148,163,184,.68);}
    .btn-brand{background:linear-gradient(135deg,#0ea5b5,#0b8b98);color:#fff;border:none;border-radius:14px;padding:.85rem 1rem;font-weight:700;font-size:1rem;transition:transform .2s ease,box-shadow .2s ease,background .2s ease,color .2s ease;}
    .btn-brand:hover{transform:translateY(-1px);box-shadow:0 18px 32px -20px rgba(14,165,181,.6);color:#fff;}
    [data-theme="dark"] .btn-brand{background:linear-gradient(135deg,#38bdf8,#0ea5b5);color:#04121f;box-shadow:0 22px 44px -20px rgba(8,47,73,.8);}
    [data-theme="dark"] .btn-brand:hover{color:#04121f;box-shadow:0 28px 56px -22px rgba(56,189,248,.55);}
    .support-card{display:flex;align-items:flex-start;gap:1rem;padding:1.1rem 1.4rem;border-radius:18px;background:#f1fbfc;border:1px solid rgba(14,165,181,.18);transition:background .25s ease,border-color .25s ease,color .25s ease;}
    .support-card strong{color:var(--ink);}
    .support-card a{font-weight:700;color:var(--brand);text-decoration:none;}
    .support-card a:hover{text-decoration:underline;color:var(--brand-dark);}
    [data-theme="dark"] .support-card{background:rgba(15,23,42,.82);border-color:rgba(56,189,248,.22);}
    .cta-box{margin-top:auto;}
    .form-footer{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.6rem;font-size:.9rem;color:var(--muted);}
    .form-footer a{color:var(--brand);text-decoration:none;font-weight:600;}
    .form-footer a:hover{text-decoration:underline;color:var(--brand-dark);}
    [data-theme="dark"] .form-footer a{color:#38bdf8;}
    [data-theme="dark"] .form-footer a:hover{color:#7dd3fc;}
    .alert{border-radius:14px;font-weight:500;}
    @media(max-width:992px){
      body{padding:1.5rem;}
      .auth-shell{flex-direction:column;}
      .auth-form{order:-1;padding:2.6rem 2.4rem;}
      .auth-visual{padding:2.6rem;}
    }
    @media(max-width:576px){.auth-form{padding:2.2rem;} .visual-title{font-size:1.75rem;}}
  </style>
</head>
<body>
  <?php render_login_header('portal'); ?>
  <main class="auth-layout">
  <div class="auth-shell">
    <aside class="auth-visual">
      <div>
        <span class="badge"><?=h($content['badge'])?></span>
        <h1 class="visual-title"><?=h($content['title'])?></h1>
        <p class="visual-text"><?=h($content['text'])?></p>
        <ul class="feature-list">
          <?php foreach ($content['features'] as $feature): ?>
            <li><span>✓</span><?=h($feature)?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="cta-box">
        <p class="visual-footer">BİKARE platformu Zerosoft güvencesiyle bayiler, temsilciler ve etkinlik ekipleri için tasarlanmıştır.</p>
      </div>
    </aside>
    <section class="auth-form">
      <div class="entry-hub">
        <nav class="entry-hub__tabs" aria-label="Panel seçimi">
          <a class="<?= $tab === 'dealer' ? 'is-active' : '' ?>" href="<?=h($dealerTabUrl)?>"><span>Bayi Girişi</span></a>
          <a class="<?= $tab === 'representative' ? 'is-active' : '' ?>" href="<?=h($repTabUrl)?>"><span>Temsilci Girişi</span></a>
        </nav>
        <p class="entry-hub__note">Bayi veya temsilci olarak giriş yapmak için yukarıdaki sekmelerden seçim yapabilirsiniz.</p>
      </div>
      <div>
        <div class="brand">BİKARE <span><?= $portal === 'dealer' ? 'Bayi Paneli' : 'Temsilci Paneli' ?></span></div>
        <p class="form-note">
          <?php if ($portal === 'dealer'): ?>
            Bayi kodunuz ve size özel şifrenizle giriş yapın. Panel üzerinden etkinlik oluşturabilir, müşterilerinizin yüklemelerini takip edebilir ve finansal hareketlerinizi yönetebilirsiniz.
          <?php else: ?>
            Temsilci e-posta adresiniz ve size iletilen şifreniz ile giriş yapın. Sorumlu olduğunuz bayilerin performansını izleyebilir, etkinlik taleplerini yönlendirebilir ve finansal süreçleri raporlayabilirsiniz.
          <?php endif; ?>
        </p>
      </div>
      <?php flash_box(); ?>
      <form method="post" action="<?=$formAction?>" class="vstack gap-3">
        <input type="hidden" name="portal" value="<?=$portalInput?>">
        <input type="hidden" name="tab" value="<?=$tabInput?>">
        <input type="hidden" name="next" value="<?=$nextInput?>">
        <div>
          <label class="form-label">E-posta</label>
          <input type="email" name="email" class="form-control" required placeholder="<?= $portal === 'dealer' ? 'ornek@bikarebayi.com' : 'ornek@bikaretemsilci.com' ?>">
        </div>
        <div>
          <label class="form-label">Şifre</label>
          <input type="password" name="password" class="form-control" required placeholder="Şifrenizi yazın">
        </div>
        <div class="form-footer">
          <a href="<?= $portal === 'dealer' ? BASE_URL . '/dealer/forgot.php' : BASE_URL . '/representative/forgot.php' ?>">Şifremi unuttum</a>
          <button class="btn-brand" type="submit"><?=h($content['button'])?></button>
        </div>
      </form>
      <div class="support-card">
        <div>
          <strong><?= $portal === 'dealer' ? 'Bayi ağına katılmak ister misiniz?' : 'Destek mi lazım?' ?></strong>
          <div class="text-muted small"><?=h($content['support'])?></div>
        </div>
        <?php if ($content['support_link']): ?>
          <a href="<?=h($content['support_link']['href'])?>"><?=h($content['support_link']['label'])?></a>
        <?php endif; ?>
      </div>
    </section>
  </div>
  </main>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
