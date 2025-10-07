<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/guests.php';
require_once __DIR__.'/../includes/couple_auth.php';
require_once __DIR__.'/../includes/login_header.php';

install_schema();

$activeTab = $_GET['tab'] ?? 'guest';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postTab = $_POST['tab'] ?? null;
    if ($postTab === 'guest' || $postTab === 'couple') {
        $activeTab = $postTab;
    }
}
$activeTab = $activeTab === 'couple' ? 'couple' : 'guest';

$guestEmailPrefill = guest_profile_normalize_email($_GET['email'] ?? '');
$guestStage = 'form';
$guestChoices = [];
if ($activeTab === 'guest') {
    if (isset($_GET['reset'])) {
        unset($_SESSION['guest_login_choices'], $_SESSION['guest_login_stage']);
        header('Location: '.BASE_URL.'/public/guest_login.php');
        exit;
    }

    $guestStage = $_SESSION['guest_login_stage'] ?? 'form';
    $guestChoices = $_SESSION['guest_login_choices'] ?? [];
    if ($guestStage !== 'choose' || !$guestChoices) {
        $guestStage = 'form';
        $guestChoices = [];
        unset($_SESSION['guest_login_stage'], $_SESSION['guest_login_choices']);
    }
} else {
    unset($_SESSION['guest_login_stage'], $_SESSION['guest_login_choices']);
}

$coupleEmailPrefill = trim($_GET['couple_email'] ?? '');
$coupleError = null;

if ($activeTab === 'couple' && couple_is_global_logged_in()) {
    $eid = couple_current_event_id();
    if ($eid > 0) {
        redirect(BASE_URL.'/couple/index.php');
    }
    redirect(BASE_URL.'/couple/switch_event.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_or_die();
    $intent = $_POST['tab'] ?? 'guest';
    if ($intent === 'guest') {
        $action = $_POST['action'] ?? 'login';
        if ($action === 'login') {
            $email = guest_profile_normalize_email($_POST['email'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $guestEmailPrefill = $email;
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('err', 'Geçerli bir e-posta adresi yazın.');
                header('Location: '.BASE_URL.'/public/guest_login.php?tab=guest&email='.rawurlencode($email));
                exit;
            }
            if ($password === '') {
                flash('err', 'Şifrenizi yazın.');
                header('Location: '.BASE_URL.'/public/guest_login.php?tab=guest&email='.rawurlencode($email));
                exit;
            }
            $matches = guest_profile_authenticate($email, $password);
            if (!$matches) {
                flash('err', 'E-posta veya şifre hatalı ya da henüz doğrulama tamamlanmadı.');
                header('Location: '.BASE_URL.'/public/guest_login.php?tab=guest&email='.rawurlencode($email));
                exit;
            }
            if (count($matches) === 1) {
                $match = $matches[0];
                $profileId = (int)$match['profile']['id'];
                $eventId = (int)$match['event']['id'];
                guest_profile_set_session($eventId, $profileId);
                guest_profile_record_login($profileId);
                guest_profile_touch($profileId);
                flash('ok', 'Misafir paneline giriş yapıldı.');
                header('Location: '.public_upload_url($eventId));
                exit;
            }
            $store = [];
            foreach ($matches as $match) {
                $profileId = (int)$match['profile']['id'];
                $store[$profileId] = [
                    'event_id' => (int)$match['event']['id'],
                    'event_title' => $match['event']['title'],
                    'event_date' => $match['event']['event_date'],
                ];
            }
            $_SESSION['guest_login_choices'] = $store;
            $_SESSION['guest_login_stage'] = 'choose';
            header('Location: '.BASE_URL.'/public/guest_login.php?tab=guest');
            exit;
        }
        if ($action === 'choose') {
            $choices = $_SESSION['guest_login_choices'] ?? [];
            $profileId = (int)($_POST['profile_id'] ?? 0);
            if (!$choices || !isset($choices[$profileId])) {
                flash('err', 'Seçim geçersiz veya süresi doldu. Lütfen tekrar giriş yapın.');
                header('Location: '.BASE_URL.'/public/guest_login.php?tab=guest');
                exit;
            }
            $eventId = (int)$choices[$profileId]['event_id'];
            unset($_SESSION['guest_login_choices'], $_SESSION['guest_login_stage']);
            guest_profile_set_session($eventId, $profileId);
            guest_profile_record_login($profileId);
            guest_profile_touch($profileId);
            flash('ok', 'Misafir paneline giriş yapıldı.');
            header('Location: '.public_upload_url($eventId));
            exit;
        }
    }

    if ($intent === 'couple') {
        $activeTab = 'couple';
        $email = trim($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $coupleEmailPrefill = $email;
        if ($email === '' || $password === '') {
            $coupleError = 'E-posta ve şifrenizi yazın.';
        } else {
            $events = couple_login_global($email, $password);
            if (!$events) {
                $coupleError = 'E-posta veya şifre hatalı ya da etkinliğiniz aktif değil.';
            } else {
                if (count($events) === 1) {
                    couple_set_current_event((int)$events[0]['id']);
                    redirect(BASE_URL.'/couple/index.php');
                }
                redirect(BASE_URL.'/couple/switch_event.php');
            }
        }
    }
}

$guestFlash = flash_messages();

$tabCopy = [
    'guest' => [
        'badge' => 'Misafir &amp; Davetli Paneli',
        'headline' => 'Etkinliğe ait fotoğraf ve videolar burada buluşuyor.',
        'text' => 'BİKARE misafir paneli, etkinlik sahipleriyle anılarınızı kolayca paylaşmanızı, diğer davetlilerle sohbet etmenizi ve özel içeriklere erişmenizi sağlar.',
        'features' => [
            'Fotoğraf ve videoları yüksek çözünürlükte yükleyin',
            'Sevdiklerinizle sohbet edin, yorum yapın, beğeni bırakın',
            'Etkinlik programını ve önemli duyuruları kaçırmayın',
        ],
        'footer' => 'Misafir deneyimi Zerosoft güvencesiyle geliştirilen BİKARE ekibi tarafından desteklenmektedir.',
        'button' => 'Misafir Paneline Gir',
    ],
    'couple' => [
        'badge' => 'Çift Paneli',
        'headline' => 'Tüm etkinlik yönetimi tek panelde birleşiyor.',
        'text' => 'BİKARE çift paneliyle davetli listesinden QR yönetimine, kampanya yayınlarından misafir etkileşimlerine kadar tüm süreci kontrol edebilirsiniz.',
        'features' => [
            'Misafir yüklemelerini gerçek zamanlı takip edin',
            'Etkinlik görünümünü kişiselleştirin ve davetiyeleri paylaşın',
            'Bayi ve destek ekibiyle tek ekrandan iletişim kurun',
        ],
        'footer' => 'BİKARE, çiftlere kusursuz bir etkinlik deneyimi sunmak için Zerosoft tarafından geliştirildi.',
        'button' => 'Çift Paneline Gir',
    ],
];

$visualBackground = [
    'guest' => "linear-gradient(150deg,rgba(14,165,181,.88),rgba(99,102,241,.7)),url('https://images.unsplash.com/photo-1519744346363-69e9faebabd1?auto=format&fit=crop&w=1200&q=80') center/cover",
    'couple' => "linear-gradient(150deg,rgba(14,165,181,.9),rgba(45,212,191,.75)),url('https://images.unsplash.com/photo-1520854221050-0f4caff449fb?auto=format&fit=crop&w=1200&q=80') center/cover",
];

$copy = $tabCopy[$activeTab];
$visualStyle = $visualBackground[$activeTab] ?? $visualBackground['guest'];

function portal_tab_link(string $tab, array $params = []): string {
    $params['tab'] = $tab;
    $query = http_build_query($params);
    return BASE_URL.'/public/guest_login.php'.($query ? '?'.$query : '');
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Misafir &amp; Çift Girişi — <?=h(APP_NAME)?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    <?=login_header_styles()?>
    :root{ --brand:#0ea5b5; --brand-dark:#0b8b98; --ink:#0f172a; --muted:#526070; }
    *{box-sizing:border-box;}
    body{margin:0;min-height:100vh;display:flex;flex-direction:column;background:linear-gradient(135deg,rgba(14,165,181,.12),rgba(148,163,184,.08));font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;color:var(--ink);}
    .auth-layout{flex:1;width:100%;display:flex;align-items:center;justify-content:center;padding:2.5rem 1.5rem 3rem;}
    .auth-shell{width:100%;max-width:1080px;background:#fff;border-radius:30px;box-shadow:0 40px 120px -55px rgba(15,23,42,.5);display:flex;overflow:hidden;border:1px solid rgba(148,163,184,.18);}
    .auth-visual{flex:1.05;position:relative;padding:3.1rem;color:#fff;display:flex;flex-direction:column;justify-content:space-between;}
    .auth-visual::after{content:"";position:absolute;inset:0;background:linear-gradient(160deg,rgba(15,23,42,.15),rgba(15,23,42,.45));}
    .auth-visual > *{position:relative;z-index:1;}
    .badge{display:inline-flex;align-items:center;gap:.6rem;padding:.45rem 1.2rem;border-radius:999px;background:rgba(255,255,255,.18);font-weight:600;letter-spacing:.08em;text-transform:uppercase;font-size:.78rem;}
    .visual-title{font-size:2.2rem;font-weight:800;line-height:1.2;margin:1.6rem 0 1rem;max-width:440px;}
    .visual-text{font-size:1.04rem;line-height:1.7;color:rgba(255,255,255,.9);max-width:440px;}
    .feature-list{list-style:none;padding:0;margin:1.8rem 0 0;display:flex;flex-direction:column;gap:1rem;}
    .feature-list li{display:flex;align-items:flex-start;gap:.75rem;font-weight:600;color:rgba(255,255,255,.92);}
    .feature-list span{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,.2);font-size:1rem;}
    .visual-footer{font-size:.84rem;color:rgba(255,255,255,.78);max-width:360px;margin-top:2.7rem;}
    .auth-form{flex:.95;padding:3rem 3.1rem;display:flex;flex-direction:column;gap:1.8rem;justify-content:center;}
    .brand{font-weight:800;font-size:1.65rem;letter-spacing:.15rem;margin-bottom:.25rem;}
    .brand span{display:block;font-size:.95rem;font-weight:600;color:var(--muted);margin-top:.35rem;letter-spacing:0;}
    .tab-switch{display:flex;gap:.75rem;border-radius:16px;background:rgba(14,165,181,.08);padding:.4rem;}
    .tab-switch a{flex:1;padding:.75rem 1.1rem;border-radius:12px;font-weight:600;text-align:center;color:var(--muted);transition:all .2s ease;text-decoration:none;}
    .tab-switch a.active{background:#0ea5b5;color:#fff;box-shadow:0 18px 40px -22px rgba(14,165,181,.6);}
    .tab-switch a:hover{color:#0b8b98;}
    .form-note{color:var(--muted);font-size:.95rem;line-height:1.6;}
    .form-control{border-radius:14px;border:1px solid rgba(148,163,184,.32);padding:.8rem 1rem;font-size:1rem;}
    .form-control:focus{border-color:var(--brand);box-shadow:0 0 0 .25rem rgba(14,165,181,.18);}
    .btn-brand{background:var(--brand);color:#fff;border:none;border-radius:14px;padding:.85rem 1rem;font-weight:700;font-size:1rem;transition:transform .2s ease,box-shadow .2s ease;background-image:linear-gradient(135deg,#0ea5b5,#0b8b98);}
    .btn-brand:hover{transform:translateY(-1px);box-shadow:0 20px 36px -24px rgba(14,165,181,.65);color:#fff;}
    .alert{border-radius:14px;font-weight:500;}
    .option-grid{display:flex;flex-direction:column;gap:1rem;}
    .option-card{border:1px solid rgba(148,163,184,.24);border-radius:18px;padding:1.25rem 1.4rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;transition:transform .2s ease,box-shadow .2s ease;border-left:4px solid transparent;}
    .option-card:hover{transform:translateY(-2px);box-shadow:0 22px 50px -30px rgba(15,23,42,.3);border-left-color:var(--brand);}
    .option-card h3{margin:0;font-size:1.1rem;font-weight:700;color:var(--ink);}
    .option-card time{display:block;font-size:.9rem;color:var(--muted);margin-top:.25rem;}
    .option-card button{min-width:160px;}
    .footer-links{display:flex;flex-wrap:wrap;gap:.75rem;font-weight:600;}
    .footer-links a{color:var(--brand);text-decoration:none;}
    .footer-links a:hover{text-decoration:underline;color:var(--brand-dark);}
    .muted-tip{font-size:.85rem;color:var(--muted);}
    @media(max-width:992px){body{padding:1.5rem;} .auth-shell{flex-direction:column;} .auth-visual{padding:2.6rem;} .auth-form{padding:2.4rem;}}
    @media(max-width:576px){.auth-form{padding:2rem;} .visual-title{font-size:1.75rem;}}
  </style>
</head>
<body>
  <?php render_login_header('guest'); ?>
  <main class="auth-layout">
    <div class="auth-shell">
      <aside class="auth-visual" style="background: <?=$visualStyle?>;">
        <div>
          <span class="badge"><?=$copy['badge']?></span>
          <h1 class="visual-title"><?=$copy['headline']?></h1>
          <p class="visual-text"><?=$copy['text']?></p>
          <ul class="feature-list">
            <?php foreach ($copy['features'] as $feature): ?>
              <li><span>✓</span><?=$feature?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <p class="visual-footer"><?=$copy['footer']?></p>
      </aside>
      <section class="auth-form">
        <div class="tab-switch">
          <a class="<?= $activeTab === 'guest' ? 'active' : '' ?>" href="<?=h(portal_tab_link('guest'))?>">Misafir &amp; Davetli</a>
          <a class="<?= $activeTab === 'couple' ? 'active' : '' ?>" href="<?=h(portal_tab_link('couple'))?>">Çift</a>
        </div>
        <div>
          <div class="brand">BİKARE <span><?=$activeTab === 'guest' ? 'Misafir &amp; Davetli Girişi' : 'Çift Girişi'?></span></div>
          <p class="form-note"><?php
            if ($activeTab === 'guest') {
                echo 'Davetli veya misafir olarak tanımlandığınız etkinlik için e-posta adresinizi doğruladıktan sonra belirlediğiniz şifre ile panelinize ulaşabilirsiniz.';
            } else {
                echo 'Etkinlik sahibi giriş bilgilerinizle tüm etkinliklerinizi tek yerden yönetin. Tek şifre ile birden fazla düğün ya da etkinliği kontrol edebilirsiniz.';
            }
          ?></p>
        </div>

        <?php if ($activeTab === 'guest'): ?>
          <?=$guestFlash?>
          <?php if ($guestStage === 'form'): ?>
            <form method="post" class="d-flex flex-column gap-3">
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="tab" value="guest">
              <input type="hidden" name="action" value="login">
              <div>
                <label class="form-label fw-semibold">E-posta Adresi</label>
                <input type="email" name="email" value="<?=h($guestEmailPrefill)?>" class="form-control" placeholder="ornek@eposta.com" required>
              </div>
              <div>
                <label class="form-label fw-semibold">Şifre</label>
                <input type="password" name="password" class="form-control" placeholder="Şifrenizi yazın" required>
              </div>
              <div class="text-end">
                <a class="small text-decoration-none" href="guest_forgot.php">Şifremi unuttum</a>
              </div>
              <button type="submit" class="btn btn-brand mt-2"><?=$copy['button']?></button>
            </form>
            <div class="muted-tip">Doğrulama bağlantısındaki şifre oluşturma adımını tamamladıktan sonra panel erişiminiz aktifleşir.</div>
          <?php else: ?>
            <div class="d-flex justify-content-between align-items-center">
              <h2 class="h5 fw-semibold mb-0">Etkinlik Seçin</h2>
              <a class="small text-decoration-none" href="<?=h(portal_tab_link('guest', ['reset' => 1]))?>">Başka bir hesapla giriş yap</a>
            </div>
            <div class="option-grid mt-3">
              <?php foreach ($guestChoices as $profileId => $event): ?>
                <?php
                  $eventDateLabel = null;
                  if (!empty($event['event_date'])) {
                      $ts = strtotime($event['event_date']);
                      if ($ts) {
                          $eventDateLabel = date('d.m.Y', $ts);
                      }
                  }
                ?>
                <form method="post" class="option-card">
                  <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="tab" value="guest">
                  <input type="hidden" name="action" value="choose">
                  <input type="hidden" name="profile_id" value="<?=h($profileId)?>">
                  <div>
                    <h3><?=h($event['event_title'] ?? 'Etkinlik')?></h3>
                    <?php if ($eventDateLabel): ?>
                      <time><?=h($eventDateLabel)?></time>
                    <?php endif; ?>
                  </div>
                  <button type="submit" class="btn btn-brand">Bu Etkinliğe Gir</button>
                </form>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <?php if ($coupleError): ?><div class="alert alert-danger"><?=h($coupleError)?></div><?php endif; ?>
          <form method="post" class="d-flex flex-column gap-3">
            <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="tab" value="couple">
            <div>
              <label class="form-label fw-semibold">E-posta</label>
              <input type="text" name="email" value="<?=h($coupleEmailPrefill)?>" class="form-control" placeholder="ornek@bikare.com" required autofocus>
            </div>
            <div>
              <label class="form-label fw-semibold">Şifre</label>
              <input type="password" name="password" class="form-control" placeholder="Şifrenizi yazın" required>
            </div>
            <button type="submit" class="btn btn-brand mt-2"><?=$copy['button']?></button>
          </form>
          <div class="footer-links">
            <a href="<?=h(BASE_URL)?>">← Anasayfaya dön</a>
            <a href="<?=h(BASE_URL)?>/couple/logout.php">Panel erişimi mi değişti?</a>
          </div>
        <?php endif; ?>
      </section>
    </div>
  </main>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
