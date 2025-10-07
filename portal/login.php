<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/dealer_auth.php';
require_once __DIR__.'/../includes/representative_auth.php';
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
    $rawNext = trim($_POST['next'] ?? '');

    if ($portal === 'representative') {
        if (representative_login($email, $password)) {
            redirect(resolve_next_redirect($rawNext, 'representative'));
        }
        flash('err', 'Giriş başarısız. Bilgilerinizi kontrol edin.');
        $params = ['portal' => 'representative'];
        if ($rawNext !== '') {
            $params['next'] = $rawNext;
        }
        redirect(BASE_URL . '/portal/login.php' . ($params ? '?' . http_build_query($params) : ''));
    } else {
        if (dealer_login($email, $password)) {
            redirect(resolve_next_redirect($rawNext, 'dealer'));
        }
        flash('err', 'Giriş başarısız. Bilgilerinizi kontrol edin.');
        $params = [];
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
$dealerTabUrl = $selfPath . '?' . http_build_query(array_merge($nextQuery, ['portal' => 'dealer']));
$repTabUrl = $selfPath . '?' . http_build_query(array_merge($nextQuery, ['portal' => 'representative']));
$formAction = htmlspecialchars($selfPath, ENT_QUOTES, 'UTF-8');
$nextInput = htmlspecialchars($rawNext, ENT_QUOTES, 'UTF-8');
$portalInput = htmlspecialchars($portal, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?=h(APP_NAME)?> — Bayi &amp; Temsilci Girişi</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    <?=login_header_styles()?>
    :root{--brand:#0ea5b5;--brand-dark:#0b8b98;--ink:#0f172a;--muted:#5f6c7b;}
    *{box-sizing:border-box;}
    body{margin:0;min-height:100vh;display:flex;flex-direction:column;padding:0;background:radial-gradient(circle at 20% 20%,rgba(14,165,181,.18),rgba(14,165,181,.04) 60%,#f8fafc);font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;color:var(--ink);}
    .auth-layout{flex:1;width:100%;display:flex;align-items:center;justify-content:center;padding:2.5rem 1.5rem 3rem;}
    .auth-shell{width:100%;max-width:1180px;background:#fff;border-radius:30px;box-shadow:0 48px 120px -60px rgba(15,23,42,.55);display:flex;overflow:hidden;border:1px solid rgba(148,163,184,.18);position:relative;}
    .auth-visual{flex:1.05;position:relative;padding:3.2rem;background:linear-gradient(140deg,rgba(15,118,110,.92),rgba(14,165,181,.75)),url('https://images.unsplash.com/photo-1530023367847-a683933f4177?auto=format&fit=crop&w=1200&q=80') center/cover;color:#fff;display:flex;flex-direction:column;justify-content:space-between;}
    .auth-visual::after{content:"";position:absolute;inset:0;background:linear-gradient(155deg,rgba(12,74,110,.25),rgba(15,23,42,.45));mix-blend-mode:soft-light;}
    .auth-visual > *{position:relative;z-index:1;}
    .badge{display:inline-flex;align-items:center;gap:.6rem;padding:.45rem 1.2rem;border-radius:999px;background:rgba(255,255,255,.18);font-weight:600;letter-spacing:.08em;text-transform:uppercase;font-size:.78rem;}
    .visual-title{font-size:2.1rem;font-weight:800;line-height:1.2;margin:1.6rem 0 1rem;max-width:420px;}
    .visual-text{font-size:1.02rem;line-height:1.7;color:rgba(255,255,255,.86);max-width:440px;}
    .feature-list{list-style:none;padding:0;margin:1.8rem 0 0;display:flex;flex-direction:column;gap:1rem;}
    .feature-list li{display:flex;align-items:flex-start;gap:.75rem;font-weight:600;color:rgba(255,255,255,.9);}
    .feature-list span{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,.18);font-size:1rem;}
    .visual-footer{font-size:.84rem;color:rgba(255,255,255,.75);max-width:360px;margin-top:2.8rem;}
    .auth-form{flex:.95;padding:3.2rem;display:flex;flex-direction:column;gap:2rem;justify-content:center;}
    .switcher{display:inline-flex;align-items:center;gap:.6rem;background:#f1fbfc;padding:.45rem;border-radius:999px;border:1px solid rgba(14,165,181,.18);box-shadow:0 12px 32px -24px rgba(14,165,181,.45);}
    .switcher a{padding:.55rem 1.4rem;border-radius:999px;font-weight:600;color:var(--muted);text-decoration:none;transition:all .2s ease;}
    .switcher a.is-active{background:linear-gradient(135deg,#0ea5b5,#0b8b98);color:#fff;box-shadow:0 18px 32px -20px rgba(14,165,181,.6);}
    .switcher a:hover{color:var(--ink);}
    .switcher-note{margin-top:.75rem;font-weight:600;color:var(--brand-dark);background:rgba(14,165,181,.08);display:inline-flex;align-items:center;gap:.5rem;padding:.45rem .85rem;border-radius:999px;box-shadow:0 12px 24px -22px rgba(14,165,181,.5);}
    .brand{font-weight:800;font-size:1.7rem;letter-spacing:.18rem;margin-bottom:.2rem;text-transform:uppercase;}
    .brand span{display:block;font-size:.95rem;font-weight:600;color:var(--muted);margin-top:.35rem;letter-spacing:0;text-transform:none;}
    .form-note{color:var(--muted);font-size:.94rem;line-height:1.6;max-width:480px;}
    .form-control{border-radius:14px;border:1px solid rgba(148,163,184,.32);padding:.75rem 1rem;font-size:1rem;}
    .form-control:focus{border-color:var(--brand);box-shadow:0 0 0 .25rem rgba(14,165,181,.18);}
    .btn-brand{background:linear-gradient(135deg,#0ea5b5,#0b8b98);color:#fff;border:none;border-radius:14px;padding:.85rem 1rem;font-weight:700;font-size:1rem;transition:transform .2s ease,box-shadow .2s ease;}
    .btn-brand:hover{transform:translateY(-1px);box-shadow:0 18px 32px -20px rgba(14,165,181,.6);color:#fff;}
    .support-card{display:flex;align-items:flex-start;gap:1rem;padding:1.1rem 1.4rem;border-radius:18px;background:#f1fbfc;border:1px solid rgba(14,165,181,.18);}
    .support-card strong{color:var(--ink);}
    .support-card a{font-weight:700;color:var(--brand);text-decoration:none;}
    .support-card a:hover{text-decoration:underline;color:var(--brand-dark);}
    .cta-box{margin-top:auto;}
    .guest-card{margin-top:2.5rem;border-radius:22px;padding:1.6rem;border:1px solid rgba(148,163,184,.18);background:linear-gradient(135deg,rgba(14,165,181,.08),rgba(148,163,184,.06));box-shadow:0 22px 48px -30px rgba(15,23,42,.25);}
    .guest-card h3{margin:0 0 .6rem;font-size:1.2rem;font-weight:700;color:var(--ink);}
    .guest-card p{margin:0 0 1.1rem;color:var(--muted);}
    .guest-card a{display:inline-flex;align-items:center;gap:.5rem;padding:.7rem 1.2rem;border-radius:14px;background:linear-gradient(135deg,#0891b2,#0ea5e9);color:#fff;font-weight:600;text-decoration:none;}
    .guest-card a:hover{color:#fff;box-shadow:0 18px 36px -24px rgba(14,165,181,.6);}
    .form-footer{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.6rem;font-size:.9rem;color:var(--muted);}
    .form-footer a{color:var(--brand);text-decoration:none;font-weight:600;}
    .form-footer a:hover{text-decoration:underline;color:var(--brand-dark);}
    .alert{border-radius:14px;font-weight:500;}
    @media(max-width:992px){body{padding:1.5rem;} .auth-shell{flex-direction:column;} .auth-visual{padding:2.6rem;} .auth-form{padding:2.6rem 2.4rem;}}
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
      <nav class="switcher">
        <a class="<?= $portal === 'dealer' ? 'is-active' : '' ?>" href="<?=h($dealerTabUrl)?>">Bayi Girişi</a>
        <a class="<?= $portal === 'representative' ? 'is-active' : '' ?>" href="<?=h($repTabUrl)?>">Temsilci Girişi</a>
      </nav>
      <?php if ($portal === 'dealer'): ?>
        <div class="switcher-note">Bayi oldu de</div>
      <?php endif; ?>
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
      <div class="guest-card">
        <h3>Misafir &amp; Davetli Girişi</h3>
        <p>Etkinlik davetlileri için hazırlanan misafir paneline şifrenizle erişebilirsiniz. Giriş için e-posta adresiniz yeterli.</p>
        <a href="<?=BASE_URL?>/public/guest_login.php">Misafir / Davetli Girişi</a>
      </div>
    </section>
  </div>
  </main>
</body>
</html>
