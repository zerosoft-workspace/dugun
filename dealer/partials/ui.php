<?php
if (!function_exists('dealer_base_styles')) {
  function dealer_base_styles(): string {
    return <<<'CSS'
    <style>
      @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
      @import url('https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css');

      :root {
        --dealer-brand:#0ea5b5;
        --dealer-brand-dark:#0b8b98;
        --dealer-ink:#0f172a;
        --dealer-muted:#64748b;
        --dealer-bg:#eef2f9;
        --dealer-surface:#ffffff;
        --dealer-sidebar:#0ea5b5;
        --dealer-sidebar-accent:#0b8b98;
      }

      body.dealer-body {
        background:var(--dealer-bg);
        color:var(--dealer-ink);
        min-height:100vh;
        font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;
        margin:0;
      }

      .dealer-app {
        min-height:100vh;
        display:flex;
        background:var(--dealer-bg);
      }

      .dealer-sidebar {
        width:280px;
        background:linear-gradient(165deg,var(--dealer-sidebar) 0%,var(--dealer-sidebar-accent) 95%);
        color:#f0fdfd;
        display:flex;
        flex-direction:column;
        padding:28px 22px 32px;
        position:relative;
        z-index:1030;
        transition:transform .3s ease,width .3s ease,padding .3s ease;
      }

      .dealer-sidebar .sidebar-brand {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        margin-bottom:2.2rem;
      }

      .dealer-sidebar .sidebar-brand .brand-mark {
        display:flex;
        align-items:center;
        gap:12px;
      }

      .dealer-sidebar .sidebar-brand .brand-mark span {
        width:40px;
        height:40px;
        border-radius:12px;
        background:rgba(255,255,255,.16);
        display:inline-flex;
        align-items:center;
        justify-content:center;
        font-weight:700;
        font-size:1.05rem;
      }

      .dealer-sidebar .sidebar-brand .brand-mark strong {
        font-size:1.05rem;
        letter-spacing:.3px;
      }

      .dealer-sidebar .sidebar-toggle-min {
        border:none;
        background:rgba(255,255,255,.14);
        color:#fff;
        width:38px;
        height:38px;
        border-radius:12px;
        display:inline-flex;
        align-items:center;
        justify-content:center;
      }

      .dealer-sidebar .sidebar-summary {
        border-radius:16px;
        background:rgba(255,255,255,.12);
        padding:16px;
        margin-bottom:1.8rem;
        box-shadow:0 18px 30px -24px rgba(0,0,0,.6);
      }

      .dealer-sidebar .sidebar-summary strong {
        display:block;
        font-size:1rem;
        margin-bottom:.15rem;
      }

      .dealer-sidebar .sidebar-summary span {
        display:block;
        font-size:.9rem;
        color:rgba(255,255,255,.75);
      }

      .dealer-sidebar .sidebar-summary .balance {
        margin-top:1rem;
        padding:10px 12px;
        border-radius:14px;
        background:rgba(255,255,255,.18);
        display:flex;
        align-items:center;
        gap:8px;
        font-weight:600;
      }

      .dealer-sidebar .sidebar-summary .balance i {
        font-size:1.1rem;
      }

      .dealer-sidebar .sidebar-nav {
        display:flex;
        flex-direction:column;
        gap:8px;
      }

      .dealer-sidebar .sidebar-heading {
        font-size:.75rem;
        text-transform:uppercase;
        letter-spacing:.12em;
        color:rgba(255,255,255,.7);
        margin-top:1.4rem;
        margin-bottom:.4rem;
      }

      .dealer-sidebar .sidebar-link {
        display:flex;
        align-items:center;
        gap:12px;
        padding:10px 12px;
        border-radius:12px;
        color:rgba(255,255,255,.82);
        font-weight:500;
        text-decoration:none;
        transition:all .2s ease;
      }

      .dealer-sidebar .sidebar-link i {
        font-size:1.05rem;
      }

      .dealer-sidebar .sidebar-link:hover,
      .dealer-sidebar .sidebar-link:focus {
        color:#fff;
        background:rgba(255,255,255,.14);
        text-decoration:none;
      }

      .dealer-sidebar .sidebar-link.active {
        background:rgba(255,255,255,.24);
        color:#fff;
        box-shadow:0 16px 34px -26px rgba(0,0,0,.5);
      }

      .dealer-sidebar .sidebar-footer {
        margin-top:auto;
        padding:14px 16px;
        border-radius:14px;
        background:rgba(255,255,255,.16);
        font-size:.82rem;
        line-height:1.45;
      }

      .dealer-workspace {
        flex:1;
        display:flex;
        flex-direction:column;
        position:relative;
      }

      .dealer-toolbar {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:16px;
        padding:22px 32px 18px;
      }

      .dealer-toolbar .toolbar-left {
        display:flex;
        align-items:center;
        gap:16px;
        flex:1;
      }

      .dealer-toolbar .sidebar-toggle {
        border:none;
        background:#fff;
        color:var(--dealer-ink);
        border-radius:12px;
        width:46px;
        height:46px;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        box-shadow:0 10px 25px -15px rgba(15,23,42,.35);
      }

      .dealer-toolbar .toolbar-pill {
        background:#fff;
        border-radius:14px;
        padding:10px 16px;
        display:flex;
        align-items:center;
        gap:10px;
        box-shadow:0 18px 40px -24px rgba(15,23,42,.45);
        font-weight:500;
        color:var(--dealer-muted);
      }

      .dealer-toolbar .toolbar-right {
        display:flex;
        align-items:center;
        gap:14px;
      }

      .dealer-toolbar .toolbar-user {
        display:flex;
        align-items:center;
        gap:10px;
        background:#fff;
        border-radius:16px;
        padding:10px 14px;
        box-shadow:0 22px 45px -32px rgba(15,23,42,.4);
      }

      .dealer-toolbar .toolbar-user .avatar {
        width:40px;
        height:40px;
        border-radius:12px;
        background:linear-gradient(145deg,rgba(14,165,181,.28),rgba(14,165,181,.6));
        display:flex;
        align-items:center;
        justify-content:center;
        font-weight:700;
        color:var(--dealer-ink);
      }

      .dealer-toolbar .toolbar-user span {
        display:flex;
        flex-direction:column;
        line-height:1.2;
      }

      .dealer-toolbar .toolbar-user span strong {
        font-size:.95rem;
      }

      .dealer-toolbar .toolbar-user span small {
        color:var(--dealer-muted);
        font-size:.78rem;
      }

      .dealer-hero {
        padding:0 32px 12px;
      }

      .dealer-hero-card {
        border-radius:24px;
        background:linear-gradient(130deg,#fff 0%,rgba(14,165,181,.08) 100%);
        padding:32px;
        box-shadow:0 25px 60px -40px rgba(15,23,42,.55);
      }

      .dealer-hero-card h1 {
        font-size:1.85rem;
        margin-bottom:.4rem;
      }

      .dealer-hero-card p {
        color:var(--dealer-muted);
        max-width:720px;
      }

      .dealer-main {
        flex:1;
      }

      .dealer-main-inner {
        padding:0 32px 48px;
      }

      .dealer-main-inner .flash-box {
        margin-bottom:1.4rem;
      }

      body.sidebar-collapsed .dealer-sidebar {
        width:96px;
        padding:28px 16px;
      }

      body.sidebar-collapsed .dealer-sidebar .brand-mark strong,
      body.sidebar-collapsed .dealer-sidebar .sidebar-summary span,
      body.sidebar-collapsed .dealer-sidebar .sidebar-summary .balance span,
      body.sidebar-collapsed .dealer-sidebar .sidebar-summary strong,
      body.sidebar-collapsed .dealer-sidebar .sidebar-heading,
      body.sidebar-collapsed .dealer-sidebar .sidebar-link .sidebar-label,
      body.sidebar-collapsed .dealer-sidebar .sidebar-footer {
        display:none;
      }

      body.sidebar-collapsed .dealer-sidebar .sidebar-link {
        justify-content:center;
        padding:12px;
      }

      body.sidebar-open .dealer-sidebar {
        transform:translateX(0);
      }

      @media (max-width: 991px) {
        .dealer-sidebar {
          position:fixed;
          top:0;
          bottom:0;
          transform:translateX(-100%);
          box-shadow:24px 0 60px -34px rgba(15,23,42,.55);
        }
        body.sidebar-open {
          overflow:hidden;
        }
        body.sidebar-open .dealer-sidebar {
          transform:translateX(0);
        }
        .dealer-main-inner {
          padding:0 20px 32px;
        }
        .dealer-toolbar {
          padding:20px 20px 16px;
        }
        .dealer-hero {
          padding:0 20px 8px;
        }
        .dealer-hero-card {
          padding:24px;
        }
      }
    </style>
CSS;
  }

  function dealer_layout_start(string $active = '', array $options = []): void {
    $pageTitle   = $options['page_title'] ?? (APP_NAME.' — Bayi Paneli');
    $heroTitle   = $options['title'] ?? '';
    $heroSubtitle= $options['subtitle'] ?? '';
    $dealer      = $options['dealer'] ?? null;
    $venues      = $options['venues'] ?? [];
    $activeVenue = $options['active_venue_id'] ?? null;
    $balanceText = $options['balance_text'] ?? null;
    $licenseText = $options['license_text'] ?? null;
    $refCode     = $options['ref_code'] ?? null;
    $extraHead   = $options['extra_head'] ?? '';

    $dealerName  = $dealer['name'] ?? ($dealer['email'] ?? 'Bayi');
    $initial     = $dealerName ? mb_strtoupper(mb_substr($dealerName, 0, 1, 'UTF-8'), 'UTF-8') : 'B';

    echo '<!doctype html>';
    echo '<html lang="tr">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>'.h($pageTitle).'</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo dealer_base_styles();
    if ($extraHead) {
      echo $extraHead;
    }
    echo '</head>';
    echo '<body class="dealer-body">';
    echo '<div class="dealer-app">';
    echo '<aside class="dealer-sidebar" id="dealerSidebar">';
    echo '<div class="sidebar-brand">';
    echo '<div class="brand-mark"><span>'.mb_strtoupper(mb_substr(APP_NAME, 0, 2, 'UTF-8')).'</span><strong>'.h(APP_NAME).' Bayi</strong></div>';
    echo '<button class="dealer-sidebar-toggle dealer-sidebar-min sidebar-toggle-min" type="button" data-sidebar-toggle aria-label="Menüyü daralt"><i class="bi bi-chevron-double-left"></i></button>';
    echo '</div>';

    if ($dealer) {
      echo '<div class="sidebar-summary">';
      echo '<strong>'.h($dealerName).'</strong>';
      if ($refCode) {
        echo '<span>Referans Kodu: '.h($refCode).'</span>';
      }
      if ($licenseText) {
        echo '<span>Lisans: '.h($licenseText).'</span>';
      }
      if ($balanceText !== null) {
        echo '<div class="balance"><i class="bi bi-wallet2"></i><span>'.h($balanceText).'</span></div>';
      }
      echo '</div>';
    }

    $links = [
      'dashboard' => ['href' => BASE_URL.'/dealer/dashboard.php', 'label' => 'Genel Bakış', 'icon' => 'bi-speedometer2'],
      'billing'   => ['href' => BASE_URL.'/dealer/billing.php', 'label' => 'Bakiye & Paketler', 'icon' => 'bi-wallet2'],
    ];

    echo '<nav class="sidebar-nav">';
    foreach ($links as $key => $link) {
      $cls = 'sidebar-link'.($active === $key ? ' active' : '');
      echo '<a class="'.$cls.'" href="'.h($link['href']).'"><i class="bi '.$link['icon'].'"></i><span class="sidebar-label">'.h($link['label']).'</span></a>';
    }
    if ($venues) {
      echo '<div class="sidebar-heading">Salonlarım</div>';
      foreach ($venues as $venue) {
        $vid = (int)($venue['id'] ?? 0);
        $cls = 'sidebar-link'.($activeVenue && $vid === (int)$activeVenue ? ' active' : '');
        echo '<a class="'.$cls.'" href="'.h(BASE_URL.'/dealer/venue_events.php?venue_id='.$vid).'"><i class="bi bi-building"></i><span class="sidebar-label">'.h($venue['name'] ?? 'Salon').'</span></a>';
      }
    }
    echo '</nav>';

    $supportEmail = defined('SITE_SUPPORT_EMAIL') && SITE_SUPPORT_EMAIL ? SITE_SUPPORT_EMAIL : 'destek@zerosoft.com.tr';
    echo '<div class="sidebar-footer">';
    echo '<strong>Destek</strong> Sorularınız için <a class="text-white text-decoration-none fw-semibold" href="mailto:'.h($supportEmail).'">'.h($supportEmail).'</a> adresine ulaşabilirsiniz.';
    echo '</div>';

    echo '</aside>';

    echo '<div class="dealer-workspace">';
    echo '<header class="dealer-toolbar">';
    echo '<div class="toolbar-left">';
    echo '<button class="sidebar-toggle" type="button" data-sidebar-toggle aria-label="Menüyü aç/kapat"><i class="bi bi-list"></i></button>';
    echo '<div class="toolbar-pill"><i class="bi bi-calendar3"></i>'.date('d.m.Y').'</div>';
    echo '</div>';
    echo '<div class="toolbar-right">';
    if ($balanceText !== null) {
      echo '<div class="toolbar-pill"><i class="bi bi-cash-stack"></i>'.$balanceText.'</div>';
    }
    echo '<div class="toolbar-user">';
    echo '<div class="avatar">'.h($initial).'</div>';
    echo '<span><strong>'.h($dealerName).'</strong><small>Bayi Yetkilisi</small></span>';
    echo '<a class="ms-1 text-decoration-none text-danger" href="'.h(BASE_URL.'/dealer/login.php?logout=1').'" title="Çıkış Yap"><i class="bi bi-box-arrow-right"></i></a>';
    echo '</div>';
    echo '</div>';
    echo '</header>';

    if ($heroTitle !== '') {
      echo '<section class="dealer-hero">';
      echo '<div class="dealer-hero-card">';
      echo '<h1>'.h($heroTitle).'</h1>';
      if ($heroSubtitle !== '') {
        echo '<p>'.h($heroSubtitle).'</p>';
      }
      echo '</div>';
      echo '</section>';
    }

    echo '<main class="dealer-main">';
    echo '<div class="dealer-main-inner">';
    if (function_exists('flash_box')) {
      flash_box();
    }
  }

  function dealer_layout_end(): void {
    echo '</div>';
    echo '</main>';
    echo '</div>';
    echo '</div>';
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>';
    echo '<script>(function(){var body=document.body;var sidebar=document.getElementById("dealerSidebar");var collapseBtn=sidebar?sidebar.querySelector(".dealer-sidebar-min"):null;var collapseIcon=collapseBtn?collapseBtn.querySelector("i"):null;var mql=window.matchMedia("(max-width: 991px)");var key="dealerSidebarCollapsed";function isMobile(){return mql.matches;}function updateIcon(state){if(!collapseIcon)return;if(state){collapseIcon.classList.remove("bi-chevron-double-left");collapseIcon.classList.add("bi-chevron-double-right");}else{collapseIcon.classList.add("bi-chevron-double-left");collapseIcon.classList.remove("bi-chevron-double-right");}}function setCollapsed(state){if(state){body.classList.add("sidebar-collapsed");localStorage.setItem(key,"1");}else{body.classList.remove("sidebar-collapsed");localStorage.removeItem(key);}updateIcon(state);}function toggle(){if(isMobile()){body.classList.toggle("sidebar-open");return;}setCollapsed(!body.classList.contains("sidebar-collapsed"));}document.querySelectorAll("[data-sidebar-toggle]").forEach(function(btn){btn.addEventListener("click",function(ev){ev.preventDefault();toggle();});});document.addEventListener("click",function(ev){if(!body.classList.contains("sidebar-open"))return;if(sidebar && sidebar.contains(ev.target))return;var toggleBtn=ev.target.closest("[data-sidebar-toggle]");if(toggleBtn)return;body.classList.remove("sidebar-open");});function applyStored(){if(isMobile()){body.classList.remove("sidebar-collapsed");updateIcon(false);return;}var stored=localStorage.getItem(key)==="1";setCollapsed(stored);}applyStored();if(mql.addEventListener){mql.addEventListener("change",function(){applyStored();body.classList.remove("sidebar-open");});}else if(mql.addListener){mql.addListener(function(){applyStored();body.classList.remove("sidebar-open");});}})();</script>';
    echo '</body>';
    echo '</html>';
  }
}
