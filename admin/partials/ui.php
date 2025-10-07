<?php
require_once __DIR__.'/../../includes/theme.php';

if (!function_exists('admin_base_styles')) {
  function admin_base_styles(): string {
    return theme_head_assets().<<<'CSS'
    <style>
      @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
      @import url('https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css');

      :root[data-theme="light"],
      :root:not([data-theme]) {
        --admin-brand:#0ea5b5;
        --admin-brand-dark:#0b8b98;
        --admin-ink:#0f172a;
        --admin-muted:#64748b;
        --admin-bg:#eef2f9;
        --admin-surface:#ffffff;
        --admin-sidebar:#0ea5b5;
        --admin-sidebar-accent:#0b8b98;
        /* Eski değişkenlerle uyumluluk */
        --brand:var(--admin-brand);
        --brand-dark:var(--admin-brand-dark);
        --ink:var(--admin-ink);
        --muted:var(--admin-muted);
        --surface:var(--admin-surface);
      }

      body.admin-body {
        background:var(--admin-bg);
        color:var(--admin-ink);
        min-height:100vh;
        font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;
        margin:0;
      }

      .admin-app {
        min-height:100vh;
        display:flex;
        background:var(--admin-bg);
      }

      .admin-sidebar {
        width:280px;
        background:linear-gradient(165deg,var(--admin-sidebar) 0%,var(--admin-sidebar-accent) 92%);
        color:#f0fdfd;
        display:flex;
        flex-direction:column;
        padding:28px 22px 32px;
        position:relative;
        z-index:1030;
        transition:transform .3s ease,width .3s ease,padding .3s ease;
        overflow:hidden;
      }

      .admin-sidebar .sidebar-brand {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        font-weight:700;
        font-size:1.18rem;
        letter-spacing:.3px;
        margin-bottom:2.2rem;
      }

      .admin-sidebar .sidebar-brand .brand-mark {
        display:flex;
        align-items:center;
        gap:12px;
      }

      .admin-sidebar .sidebar-brand span {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        width:40px;
        height:40px;
        border-radius:12px;
        background:rgba(255,255,255,.16);
        font-weight:700;
      }

      .admin-sidebar .sidebar-brand strong {
        font-size:1.05rem;
      }

      .sidebar-collapse {
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

      .sidebar-nav {
        display:flex;
        flex-direction:column;
        gap:6px;
        margin-bottom:auto;
      }

      .sidebar-link {
        display:flex;
        align-items:center;
        gap:12px;
        padding:10px 12px;
        border-radius:12px;
        color:rgba(255,255,255,.82);
        font-weight:500;
        text-decoration:none;
        transition:all .2s ease;
        pointer-events:auto;
      }

      .sidebar-link i {
        font-size:1.05rem;
      }

      .sidebar-link:hover,
      .sidebar-link:focus {
        color:#fff;
        background:rgba(255,255,255,.12);
        text-decoration:none;
      }

      .sidebar-link.active {
        background:rgba(255,255,255,.22);
        color:#fff;
        box-shadow:0 18px 34px -22px rgba(10,126,142,.65);
      }

      .sidebar-link.active i {
        color:#fff;
      }

      .sidebar-footer {
        margin-top:2rem;
        padding:14px 16px;
        border-radius:14px;
        background:rgba(255,255,255,.16);
        font-size:.85rem;
        line-height:1.5;
      }

      .sidebar-footer strong {
        display:block;
        font-size:.95rem;
      }

      .admin-workspace {
        flex:1;
        display:flex;
        flex-direction:column;
        position:relative;
      }

      .admin-toolbar {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:16px;
        padding:22px 32px 18px;
      }

      .toolbar-left {
        display:flex;
        align-items:center;
        gap:16px;
        flex:1;
      }

      .sidebar-toggle {
        border:none;
        background:var(--admin-surface);
        color:var(--admin-ink);
        border-radius:12px;
        width:46px;
        height:46px;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        box-shadow:0 10px 25px -15px rgba(15,23,42,.35);
      }

      .toolbar-search {
        background:var(--admin-surface);
        border-radius:14px;
        padding:10px 16px;
        display:flex;
        align-items:center;
        gap:10px;
        box-shadow:0 18px 40px -24px rgba(15,23,42,.45);
        flex:1;
        max-width:520px;
      }

      .toolbar-search input {
        border:none;
        outline:none;
        width:100%;
        font-size:.95rem;
      }

      .toolbar-right {
        display:flex;
        align-items:center;
        gap:18px;
      }

      .toolbar-right [data-theme-toggle-anchor] {
        display:flex;
        align-items:center;
      }

      .toolbar-right .theme-toggle {
        width:40px;
        height:40px;
        box-shadow:0 14px 32px -24px rgba(15,23,42,.5);
      }

      .toolbar-chip {
        display:flex;
        align-items:center;
        gap:10px;
        background:var(--admin-surface);
        border-radius:999px;
        padding:8px 16px;
        font-size:.85rem;
        box-shadow:0 12px 30px -20px rgba(15,23,42,.5);
        color:var(--admin-muted);
      }

      .toolbar-user {
        display:flex;
        align-items:center;
        gap:12px;
        background:var(--admin-surface);
        border-radius:999px;
        padding:8px 14px 8px 8px;
        box-shadow:0 18px 40px -24px rgba(15,23,42,.45);
      }

      .toolbar-user .avatar {
        width:42px;
        height:42px;
        border-radius:50%;
        background:var(--admin-brand);
        color:#fff;
        font-weight:600;
        display:flex;
        align-items:center;
        justify-content:center;
      }

      .toolbar-user span {
        display:flex;
        flex-direction:column;
        line-height:1.2;
        font-size:.85rem;
        color:var(--admin-ink);
      }

      .toolbar-user small {
        color:var(--admin-muted);
      }

      .admin-hero {
        padding:0 32px 28px;
      }

      .admin-hero-card {
        background:linear-gradient(130deg,rgba(14,165,181,.18),rgba(14,165,181,.05));
        border-radius:22px;
        padding:28px 32px;
        box-shadow:0 32px 60px -42px rgba(14,165,181,.8);
      }

      .admin-hero-card-with-icon {
        display:flex;
        align-items:center;
        gap:22px;
      }

      .admin-hero-icon {
        width:68px;
        height:68px;
        border-radius:20px;
        background:rgba(255,255,255,.28);
        display:flex;
        align-items:center;
        justify-content:center;
        color:#0e7490;
        box-shadow:0 28px 48px -32px rgba(14,165,181,.55);
      }

      .admin-hero-icon i {
        font-size:2rem;
      }

      .admin-hero-card-with-icon .admin-hero-text {
        flex:1;
      }

      .admin-hero h1 {
        font-weight:700;
        margin-bottom:12px;
      }

      .admin-hero p {
        margin:0;
        color:var(--admin-muted);
        max-width:780px;
      }

      .admin-main {
        flex:1;
        padding-bottom:48px;
      }

      .admin-main-inner {
        width:100%;
      }

      .card-lite {
        border-radius:20px;
        background:var(--admin-surface);
        border:1px solid rgba(15,23,42,.06);
        box-shadow:0 28px 45px -30px rgba(15,23,42,.35);
        padding:24px 26px;
      }

      .admin-section-title {
        font-weight:600;
        margin-bottom:18px;
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:16px;
      }

      .btn-brand {
        background:var(--admin-brand);
        border:none;
        border-radius:12px;
        color:#fff;
        font-weight:600;
        padding:.6rem 1.4rem;
      }

      .btn-brand:hover,
      .btn-brand:focus {
        background:var(--admin-brand-dark);
        color:#fff;
      }

      .btn-brand-outline {
        background:#fff;
        border:1px solid rgba(14,165,181,.45);
        border-radius:12px;
        color:var(--admin-brand);
        font-weight:600;
        padding:.55rem 1.35rem;
      }

      .btn-brand-outline:hover,
      .btn-brand-outline:focus {
        background:rgba(14,165,181,.12);
        color:var(--admin-brand-dark);
      }

      .badge-soft {
        background:rgba(14,165,181,.12);
        color:var(--admin-brand-dark);
        border-radius:999px;
        padding:.35rem .8rem;
        font-size:.75rem;
        font-weight:600;
      }

      .alert {
        border-radius:14px;
        border:none;
        box-shadow:0 12px 35px -25px rgba(15,23,42,.55);
        padding:.85rem 1.1rem;
      }

      table.table thead th {
        font-size:.75rem;
        text-transform:uppercase;
        letter-spacing:.04em;
        color:var(--admin-muted);
        border-bottom:1px solid rgba(15,23,42,.08);
      }

      table.table tbody td {
        vertical-align:middle;
      }

      .form-control,
      .form-select {
        border-radius:12px !important;
        border:1px solid rgba(148,163,184,.45);
        padding:.6rem .8rem;
      }

      .form-control:focus,
      .form-select:focus {
        border-color:var(--admin-brand);
        box-shadow:0 0 0 .2rem rgba(14,165,181,.15);
      }

      body.sidebar-collapsed .admin-sidebar {
        width:90px;
        padding:28px 14px 32px;
      }

      body.sidebar-collapsed .admin-sidebar .sidebar-brand {
        justify-content:center;
        gap:0;
      }

      body.sidebar-collapsed .admin-sidebar .sidebar-brand .brand-mark {
        width:100%;
        justify-content:center;
        gap:0;
      }

      body.sidebar-collapsed .admin-sidebar .sidebar-brand .brand-mark span {
        min-width:44px;
      }

      body.sidebar-collapsed .admin-sidebar .sidebar-brand strong {
        display:none;
      }

      body.sidebar-collapsed .sidebar-footer {
        display:none;
      }

      body.sidebar-collapsed .sidebar-link {
        justify-content:center;
        padding:12px;
      }

      body.sidebar-collapsed .sidebar-link span,
      body.sidebar-collapsed .sidebar-link .sidebar-label {
        display:none;
      }

      body.sidebar-collapsed .sidebar-link i {
        font-size:1.2rem;
      }

      body.sidebar-collapsed .admin-toolbar {
        padding-left:22px;
      }

      body.sidebar-collapsed .toolbar-search {
        max-width:420px;
      }

      body.sidebar-collapsed .toolbar-user span strong {
        max-width:140px;
      }

      @media (max-width: 991px) {
        .admin-sidebar {
          position:fixed;
          inset:0 auto 0 0;
          transform:translateX(-105%);
          width:250px;
          box-shadow:25px 0 60px -40px rgba(15,23,42,.85);
        }

        body.sidebar-open .admin-sidebar {
          transform:none;
        }

        body.sidebar-open::after {
          content:'';
          position:fixed;
          inset:0;
          background:rgba(15,23,42,.45);
          z-index:1025;
        }

        .admin-toolbar {
          padding:18px 20px 16px;
        }

        .toolbar-left {
          gap:10px;
        }

        .toolbar-search {
          display:none;
        }
      }

      [data-theme="dark"] .sidebar-toggle,
      [data-theme="dark"] .toolbar-search,
      [data-theme="dark"] .toolbar-chip,
      [data-theme="dark"] .toolbar-user {
        background:rgba(15,23,42,.86);
        color:var(--ink);
        box-shadow:0 32px 74px -48px rgba(8,47,73,.65);
      }

      [data-theme="dark"] .toolbar-user span,
      [data-theme="dark"] .toolbar-chip {
        color:var(--muted);
      }

      [data-theme="dark"] .toolbar-user .avatar {
        background:rgba(56,189,248,.32);
        color:#04121f;
      }

      [data-theme="dark"] .toolbar-chip i,
      [data-theme="dark"] .toolbar-user i {
        color:#38bdf8;
      }

      [data-theme="dark"] .admin-hero-card {
        background:linear-gradient(130deg,rgba(56,189,248,.25),rgba(15,23,42,.92));
        box-shadow:0 48px 92px -48px rgba(8,47,73,.68);
      }
    </style>
CSS;
  }

  function admin_layout_start(string $active = '', string $title = '', string $subtitle = '', ?string $heroIcon = null): void {
    $me = admin_user();
    $links = [
      'dashboard' => ['href' => BASE_URL.'/admin/dashboard.php', 'label' => 'Genel Bakış', 'icon' => 'bi-speedometer2'],
      'campaigns' => ['href' => BASE_URL.'/admin/campaigns.php', 'label' => 'Kampanyalar', 'icon' => 'bi-megaphone'],
      'venues'    => ['href' => BASE_URL.'/admin/venues.php', 'label' => 'Salon Yönetimi', 'icon' => 'bi-building'],
      'users'     => ['href' => BASE_URL.'/admin/users.php', 'label' => 'Etkinlikler', 'icon' => 'bi-calendar3'],
      'dealers'   => ['href' => BASE_URL.'/admin/dealers.php', 'label' => 'Bayiler', 'icon' => 'bi-shop'],
      'listings' => ['href' => BASE_URL.'/admin/listings.php', 'label' => 'Anlaşmalı Şirketler', 'icon' => 'bi-card-list'],
      'representatives' => ['href' => BASE_URL.'/admin/representatives.php', 'label' => 'Temsilciler', 'icon' => 'bi-person-badge'],
      'crm' => ['href' => BASE_URL.'/admin/representative_crm.php', 'label' => 'Temsilci CRM', 'icon' => 'bi-kanban'],
      'finance' => ['href' => BASE_URL.'/admin/finance.php', 'label' => 'Finans', 'icon' => 'bi-wallet2'],
      'analytics' => ['href' => BASE_URL.'/admin/representative_analytics.php', 'label' => 'Analizler', 'icon' => 'bi-graph-up'],
    ];
    if (is_superadmin()) {
      $links['packages'] = ['href' => BASE_URL.'/admin/dealer_packages.php', 'label' => 'Paketler', 'icon' => 'bi-boxes'];
      $links['team']     = ['href' => BASE_URL.'/admin/team.php', 'label' => 'Yönetici Ekibi', 'icon' => 'bi-people'];
      $links['site']     = ['href' => BASE_URL.'/admin/site_content.php', 'label' => 'Site İçerikleri', 'icon' => 'bi-sliders'];
      $links['order_addons'] = ['href' => BASE_URL.'/admin/order_addons.php', 'label' => 'Ek Hizmetler', 'icon' => 'bi-stars'];
    }

    $displayName = $me['name'] ?? $me['email'] ?? '';
    $initial = $displayName ? mb_strtoupper(mb_substr($displayName, 0, 1, 'UTF-8'), 'UTF-8') : 'A';
    $roleLabel = is_superadmin() ? 'Süperadmin' : 'Admin';

    $supportEmail = defined('SITE_SUPPORT_EMAIL') && SITE_SUPPORT_EMAIL ? SITE_SUPPORT_EMAIL : 'destek@zerosoft.com.tr';

    echo '<div class="admin-app">';
    echo '<aside class="admin-sidebar" id="adminSidebar">';
    echo '<div class="sidebar-brand">';
    echo '<div class="brand-mark"><span>'.mb_strtoupper(mb_substr(APP_NAME, 0, 2, 'UTF-8')).'</span><strong>'.h(APP_NAME).' Panel</strong></div>';
    echo '<button class="sidebar-collapse" type="button" data-sidebar-toggle aria-label="Menüyü daralt"><i class="bi bi-chevron-double-left"></i></button>';
    echo '</div>';
    echo '<nav class="sidebar-nav">';
    foreach ($links as $key => $link) {
      $cls = 'sidebar-link'.($active === $key ? ' active' : '');
      echo '<a class="'.$cls.'" href="'.h($link['href']).'" title="'.h($link['label']).'"><i class="bi '.$link['icon'].'"></i><span class="sidebar-label">'.h($link['label']).'</span></a>';
    }
    echo '</nav>';
    echo '<div class="sidebar-footer">';
    echo '<strong>Destek</strong>';
    echo 'Zerosoft ekibi ile iletişime geçmek için <a class="text-white text-decoration-none fw-semibold" href="mailto:'.h($supportEmail).'">'.h($supportEmail).'</a> adresine yazabilirsiniz.';
    echo '</div>';
    echo '</aside>';

    echo '<div class="admin-workspace">';
    echo '<header class="admin-toolbar">';
    echo '<div class="toolbar-left">';
    echo '<button class="sidebar-toggle" type="button" data-sidebar-toggle aria-label="Menüyü aç/kapat"><i class="bi bi-list"></i></button>';
    echo '<div class="toolbar-search"><i class="bi bi-search"></i><input type="search" placeholder="Panelde ara..." aria-label="Panelde ara"></div>';
    echo '</div>';
    echo '<div class="toolbar-right">';
    echo '<div data-theme-toggle-anchor></div>';
    echo '<div class="toolbar-chip"><i class="bi bi-calendar3"></i>'.date('d.m.Y').'</div>';
    echo '<div class="toolbar-user">';
    echo '<div class="avatar">'.h($initial).'</div>';
    echo '<span><strong>'.h($displayName).'</strong><small>'.h($roleLabel).'</small></span>';
    echo '<a class="ms-2 text-decoration-none text-danger" href="'.h(BASE_URL.'/admin/login.php?logout=1').'" title="Çıkış Yap"><i class="bi bi-box-arrow-right"></i></a>';
    echo '</div>';
    echo '</div>';
    echo '</header>';

    if ($title !== '') {
      echo '<section class="admin-hero">';
      $heroClass = 'admin-hero-card'.($heroIcon ? ' admin-hero-card-with-icon' : '');
      echo '<div class="'.$heroClass.'">';
      if ($heroIcon) {
        echo '<div class="admin-hero-icon"><i class="bi '.h($heroIcon).'"></i></div>';
        echo '<div class="admin-hero-text">';
      }
      echo '<h1>'.h($title).'</h1>';
      if ($subtitle !== '') {
        echo '<p>'.h($subtitle).'</p>';
      }
      if ($heroIcon) {
        echo '</div>';
      }
      echo '</div>';
      echo '</section>';
    }

    echo '<main class="admin-main">';
    echo '<div class="admin-main-inner container-fluid px-3 px-xl-4 py-4">';
  }

  function admin_layout_end(): void {
    echo '</div>'; // admin-main-inner
    echo '</main>';
    echo '</div>'; // admin-workspace
    echo '</div>'; // admin-app
    echo '<script>(function(){var body=document.body;var sidebar=document.getElementById("adminSidebar");var collapseBtn=sidebar?sidebar.querySelector(".sidebar-collapse"):null;var collapseIcon=collapseBtn?collapseBtn.querySelector("i"):null;var mql=window.matchMedia("(max-width: 991px)");var key="adminSidebarCollapsed";function isMobile(){return mql.matches;}function updateIcon(state){if(!collapseIcon) return;if(state){collapseIcon.classList.remove("bi-chevron-double-left");collapseIcon.classList.add("bi-chevron-double-right");}else{collapseIcon.classList.add("bi-chevron-double-left");collapseIcon.classList.remove("bi-chevron-double-right");}}function setCollapsed(state){if(state){body.classList.add("sidebar-collapsed");localStorage.setItem(key,"1");}else{body.classList.remove("sidebar-collapsed");localStorage.removeItem(key);}updateIcon(state);}function toggle(){if(isMobile()){body.classList.toggle("sidebar-open");return;}setCollapsed(!body.classList.contains("sidebar-collapsed"));}
    document.querySelectorAll("[data-sidebar-toggle]").forEach(function(btn){btn.addEventListener("click",function(ev){ev.preventDefault();toggle();});});
    document.addEventListener("click",function(ev){if(!body.classList.contains("sidebar-open")) return;if(sidebar && sidebar.contains(ev.target)) return;var toggleBtn=ev.target.closest("[data-sidebar-toggle]");if(toggleBtn) return;body.classList.remove("sidebar-open");});
    function applyStored(){if(isMobile()){body.classList.remove("sidebar-collapsed");updateIcon(false);return;}var stored=localStorage.getItem(key)==="1";setCollapsed(stored);}applyStored();
    if(mql.addEventListener){mql.addEventListener("change",function(){applyStored();body.classList.remove("sidebar-open");});}else if(mql.addListener){mql.addListener(function(){applyStored();body.classList.remove("sidebar-open");});}
    })();</script>';
  }
}
