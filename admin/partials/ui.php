<?php
if (!function_exists('admin_base_styles')) {
  function admin_base_styles(): string {
    return <<<'CSS'
    <style>
      :root{
        --brand:#0ea5b5;
        --brand-dark:#0b8b98;
        --ink:#0f172a;
        --muted:#64748b;
        --surface:#ffffff;
        --soft:#f1f7fb;
      }
      body.admin-body{background:var(--soft);color:var(--ink);min-height:100vh;font-family:'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;}
      .admin-topnav{background:var(--surface);border-bottom:1px solid rgba(148,163,184,.22);box-shadow:0 10px 30px rgba(15,23,42,.05);position:sticky;top:0;z-index:1030;}
      .admin-topnav .navbar-brand{font-weight:700;letter-spacing:.2px;color:var(--ink);}
      .admin-topnav .navbar-toggler{border-color:rgba(148,163,184,.45);}
      .admin-topnav .navbar-toggler:focus{box-shadow:0 0 0 .2rem rgba(14,165,181,.25);}
      .admin-topnav .navbar-toggler-icon{background-image:url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba(15,23,42,0.7)' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");}
      .admin-topnav .nav-link{font-weight:600;color:var(--muted);border-radius:12px;padding:.45rem .95rem;}
      .admin-topnav .nav-link:hover{color:var(--brand-dark);background:rgba(14,165,181,.08);}
      .admin-topnav .nav-link.active{color:var(--brand);background:rgba(14,165,181,.14);}
      .admin-topnav .user-chip{background:rgba(14,165,181,.12);border-radius:999px;padding:.35rem .85rem;font-size:.85rem;color:var(--brand-dark);font-weight:600;}
      .admin-hero{background:linear-gradient(120deg,rgba(14,165,181,.18),rgba(14,165,181,.04));padding:2.4rem 0 1.9rem;margin-bottom:2rem;}
      .admin-hero h1{font-weight:700;margin-bottom:.35rem;}
      .admin-hero p{margin:0;color:var(--muted);font-size:.97rem;max-width:720px;}
      .admin-main{padding-bottom:4rem;}
      .card-lite{border-radius:20px;background:var(--surface);border:1px solid rgba(148,163,184,.18);box-shadow:0 28px 50px -32px rgba(15,23,42,.55);}
      .card-section{padding:1.7rem 1.5rem;}
      .btn-brand{background:var(--brand);border:none;color:#fff;border-radius:12px;font-weight:600;padding:.58rem 1.3rem;}
      .btn-brand:hover{background:var(--brand-dark);color:#fff;}
      .btn-brand-outline{background:#fff;border:1px solid rgba(14,165,181,.6);color:var(--brand);border-radius:12px;font-weight:600;padding:.55rem 1.2rem;}
      .badge-soft{background:rgba(14,165,181,.12);color:var(--brand-dark);border-radius:999px;padding:.35rem .75rem;font-size:.75rem;font-weight:600;}
      .table thead th{color:var(--muted);font-weight:600;text-transform:uppercase;font-size:.75rem;letter-spacing:.04em;border-bottom:1px solid rgba(148,163,184,.25);}
      .table tbody td{vertical-align:middle;}
      .form-control, .form-select{border-radius:12px;border:1px solid rgba(148,163,184,.45);padding:.6rem .75rem;}
      .form-control:focus, .form-select:focus{border-color:var(--brand);box-shadow:0 0 0 .2rem rgba(14,165,181,.15);}
      @media (max-width: 991px){
        .admin-topnav{position:static;}
        .admin-hero{padding:1.7rem 0 1.2rem;margin-bottom:1.2rem;}
      }
      @media print{
        .admin-header, .admin-hero{display:none !important;}
        body.admin-body{background:#fff;}
        .admin-main{padding:0;}
      }
    </style>
CSS;
  }

  function render_admin_topnav(string $active = '', string $title = '', string $subtitle = ''): void {
    $me = admin_user();
    $links = [
      'dashboard'  => ['href' => BASE_URL.'/admin/dashboard.php',  'label' => 'Genel Bakış'],
      'campaigns'  => ['href' => BASE_URL.'/admin/campaigns.php',  'label' => 'Kampanyalar'],
      'venues'     => ['href' => BASE_URL.'/admin/venues.php',     'label' => 'Salonlar'],
      'users'      => ['href' => BASE_URL.'/admin/users.php',      'label' => 'Etkinlikler'],
      'dealers'    => ['href' => BASE_URL.'/admin/dealers.php',    'label' => 'Bayiler'],
    ];
    if (is_superadmin()) {
      $links['team'] = ['href' => BASE_URL.'/admin/team.php', 'label' => 'Yönetim'];
    }
    echo '<header class="admin-header">';
    echo '<nav class="admin-topnav navbar navbar-expand-lg">';
    echo '<div class="container">';
    echo '<a class="navbar-brand" href="'.h(BASE_URL.'/admin/dashboard.php').'">'.h(APP_NAME).'</a>';
    echo '<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNav" aria-controls="adminNav" aria-expanded="false" aria-label="Menü">';
    echo '<span class="navbar-toggler-icon"></span>';
    echo '</button>';
    echo '<div class="collapse navbar-collapse" id="adminNav">';
    echo '<ul class="navbar-nav me-auto mb-2 mb-lg-0">';
    foreach ($links as $key => $link) {
      $cls = 'nav-link'.($active === $key ? ' active' : '');
      echo '<li class="nav-item"><a class="'.$cls.'" href="'.h($link['href']).'">'.h($link['label']).'</a></li>';
    }
    echo '</ul>';
    echo '<div class="d-flex align-items-center gap-3 mb-2 mb-lg-0">';
    if ($me) {
      $roleLabel = is_superadmin() ? 'Süperadmin' : 'Admin';
      echo '<span class="user-chip">'.h($me['name'] ?? $me['email']).' • '.h($roleLabel).'</span>';
    }
    echo '<a class="text-decoration-none fw-semibold" href="'.h(BASE_URL.'/admin/login.php?logout=1').'">Çıkış</a>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</nav>';
    if ($title !== '') {
      echo '<div class="admin-hero">';
      echo '<div class="container">';
      echo '<h1>'.h($title).'</h1>';
      if ($subtitle !== '') {
        echo '<p>'.h($subtitle).'</p>';
      }
      echo '</div>';
      echo '</div>';
    }
    echo '</header>';
  }
}
