<?php
if (!function_exists('representative_base_styles')) {
  function representative_base_styles(): string {
    return <<<'CSS'
    <style>
      @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
      @import url('https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css');

      :root {
        --rep-brand:#0ea5b5;
        --rep-brand-dark:#0b8b98;
        --rep-ink:#0f172a;
        --rep-muted:#64748b;
        --rep-bg:#eef2f9;
        --rep-surface:#ffffff;
      }

      body.rep-body {
        margin:0;
        min-height:100vh;
        background:var(--rep-bg);
        color:var(--rep-ink);
        font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;
      }

      .rep-app {
        min-height:100vh;
        display:flex;
        background:var(--rep-bg);
      }

      .rep-sidebar {
        width:270px;
        background:linear-gradient(170deg,var(--rep-brand) 0%,var(--rep-brand-dark) 92%);
        color:#f0fdfd;
        display:flex;
        flex-direction:column;
        padding:28px 22px 32px;
        box-shadow:0 30px 90px -50px rgba(15,23,42,.7);
        position:relative;
        z-index:20;
      }

      .rep-sidebar-header {
        display:flex;
        align-items:center;
        gap:12px;
        margin-bottom:2.4rem;
      }

      .rep-sidebar-header span {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        width:44px;
        height:44px;
        border-radius:14px;
        background:rgba(255,255,255,.18);
        font-weight:700;
        letter-spacing:.04em;
      }

      .rep-sidebar-header strong {
        display:block;
        font-size:1.05rem;
      }

      .rep-sidebar-header small {
        display:block;
        font-size:.82rem;
        color:rgba(255,255,255,.82);
        font-weight:500;
      }

      .rep-nav {
        display:flex;
        flex-direction:column;
        gap:6px;
        margin-bottom:auto;
      }

      .rep-nav-link {
        display:flex;
        align-items:center;
        gap:12px;
        padding:11px 12px;
        border-radius:12px;
        color:rgba(255,255,255,.82);
        font-weight:500;
        text-decoration:none;
        transition:all .2s ease;
      }

      .rep-nav-link i {
        font-size:1.05rem;
      }

      .rep-nav-link:hover,
      .rep-nav-link:focus {
        background:rgba(255,255,255,.16);
        color:#fff;
      }

      .rep-nav-link.active {
        background:#fff;
        color:var(--rep-ink);
        box-shadow:0 18px 40px -32px rgba(15,23,42,.6);
      }

      .rep-sidebar-meta {
        margin-top:2.2rem;
        border-top:1px solid rgba(255,255,255,.18);
        padding-top:1.4rem;
        font-size:.8rem;
        color:rgba(255,255,255,.7);
        line-height:1.6;
      }

      .rep-main {
        flex:1;
        display:flex;
        flex-direction:column;
        min-width:0;
      }

      .rep-topbar {
        background:var(--rep-surface);
        padding:1.75rem 2.25rem;
        box-shadow:0 22px 48px -32px rgba(15,23,42,.25);
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:1.6rem;
        position:sticky;
        top:0;
        z-index:10;
      }

      .rep-topbar-info {
        display:flex;
        flex-direction:column;
        gap:.45rem;
      }

      .rep-topbar-info h1 {
        margin:0;
        font-size:1.65rem;
        font-weight:700;
      }

      .rep-topbar-info p {
        margin:0;
        color:var(--rep-muted);
        font-size:.92rem;
        max-width:620px;
      }

      .rep-topbar-actions {
        display:flex;
        align-items:center;
        gap:1rem;
        flex-wrap:wrap;
        justify-content:flex-end;
      }

      .rep-user-card {
        display:flex;
        align-items:center;
        gap:.85rem;
        background:linear-gradient(160deg,rgba(14,165,181,.14),rgba(14,165,181,.05));
        border-radius:16px;
        padding:.75rem 1rem;
        border:1px solid rgba(14,165,181,.22);
      }

      .rep-avatar {
        width:48px;
        height:48px;
        border-radius:14px;
        background:rgba(14,165,181,.16);
        color:var(--rep-brand-dark);
        display:flex;
        align-items:center;
        justify-content:center;
        font-weight:700;
        font-size:1.05rem;
      }

      .rep-user-card strong {
        display:block;
        font-size:.98rem;
      }

      .rep-user-card span {
        display:block;
        font-size:.78rem;
        color:var(--rep-muted);
      }

      .rep-logout {
        display:inline-flex;
        align-items:center;
        gap:.45rem;
        border-radius:12px;
        padding:.65rem 1.1rem;
        font-weight:600;
        background:var(--rep-brand);
        color:#fff;
        border:none;
        text-decoration:none;
        box-shadow:0 16px 36px -26px rgba(14,165,181,.6);
      }

      .rep-logout:hover {
        background:var(--rep-brand-dark);
        color:#fff;
        text-decoration:none;
      }

      .rep-selector {
        display:flex;
        align-items:center;
        gap:.65rem;
        background:#f1f5f9;
        border-radius:12px;
        padding:.35rem .75rem;
        border:1px solid rgba(148,163,184,.25);
        max-width:320px;
      }

      .rep-selector i {
        color:var(--rep-brand-dark);
        font-size:1.05rem;
      }

      .rep-selector select {
        border:none;
        background:transparent;
        font-weight:600;
        color:var(--rep-ink);
        font-size:.95rem;
        flex:1;
      }

      .rep-selector select:focus {
        outline:none;
        box-shadow:none;
      }

      .rep-selector select option {
        color:#0f172a;
      }

      .rep-selector-wrapper {
        margin-top:.3rem;
      }

      .rep-dealer-pill {
        display:inline-flex;
        align-items:center;
        gap:.45rem;
        padding:.4rem .85rem;
        border-radius:999px;
        background:rgba(14,165,181,.12);
        color:var(--rep-brand-dark);
        font-weight:600;
        font-size:.82rem;
      }

      .rep-dealer-pill--pending {
        background:rgba(148,163,184,.16);
        color:var(--rep-muted);
      }

      .rep-content {
        flex:1;
        display:flex;
        flex-direction:column;
      }

      .rep-container {
        width:100%;
        max-width:1240px;
        padding:2.25rem 2.25rem 3.2rem;
        margin:0 auto;
      }

      .rep-container > .alert {
        border-radius:14px;
        border:none;
        box-shadow:0 18px 40px -28px rgba(15,23,42,.28);
      }

      .rep-footer {
        padding:1.8rem 2.25rem;
        background:var(--rep-surface);
        border-top:1px solid rgba(148,163,184,.18);
        color:var(--rep-muted);
        font-size:.84rem;
      }

      .rep-footer-inner {
        max-width:1240px;
        margin:0 auto;
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:1rem;
        flex-wrap:wrap;
      }

      .rep-footer-inner a {
        color:var(--rep-brand-dark);
        font-weight:600;
        text-decoration:none;
      }

      .rep-footer-inner a:hover {
        color:#0284c7;
      }

      @media (max-width: 992px) {
        .rep-app {
          flex-direction:column;
        }

        .rep-sidebar {
          width:100%;
          flex-direction:row;
          align-items:center;
          gap:1.2rem;
          padding:20px 22px;
          position:sticky;
          top:0;
          z-index:30;
        }

        .rep-nav {
          flex-direction:row;
          flex-wrap:wrap;
          margin-bottom:0;
        }

        .rep-nav-link {
          padding:10px 14px;
        }

        .rep-sidebar-meta {
          display:none;
        }

        .rep-main {
          width:100%;
        }
      }

      @media (max-width: 768px) {
        .rep-topbar {
          flex-direction:column;
          align-items:flex-start;
        }

        .rep-topbar-actions {
          width:100%;
          justify-content:space-between;
        }

        .rep-container {
          padding:1.8rem 1.4rem 2.6rem;
        }
      }

      @media (max-width: 576px) {
        .rep-user-card {
          width:100%;
          justify-content:flex-start;
        }

        .rep-topbar-actions {
          flex-direction:column;
          align-items:flex-start;
        }

        .rep-selector {
          width:100%;
        }

        .rep-footer {
          padding:1.6rem 1.4rem;
        }
      }
    </style>
    CSS;
  }

  function representative_layout_start(array $options = []): void {
    $pageTitle = $options['page_title'] ?? (APP_NAME.' — Temsilci Paneli');
    $headerTitle = $options['header_title'] ?? 'Temsilci Paneli';
    $headerSubtitle = $options['header_subtitle'] ?? 'Bayi yüklemelerini, komisyon özetlerini ve CRM akışını buradan yönetin.';
    $representative = $options['representative'] ?? null;
    $dealer = $options['dealer'] ?? null;
    $dealerSelector = $options['dealer_selector'] ?? null;
    $extraHead = $options['extra_head'] ?? '';
    $logoutUrl = $options['logout_url'] ?? 'login.php?logout=1';
    $activeNav = $options['active_nav'] ?? 'dashboard';
    $baseHost = parse_url(BASE_URL, PHP_URL_HOST) ?: 'dugun.com';

    $repName = $representative['name'] ?? '';
    $repEmail = $representative['email'] ?? '';
    $repPhone = $representative['phone'] ?? '';
    $repInitial = $repName !== '' ? mb_strtoupper(mb_substr($repName, 0, 1, 'UTF-8'), 'UTF-8') : mb_strtoupper(mb_substr(APP_NAME, 0, 1, 'UTF-8'), 'UTF-8');

    $dealerName = $dealer['name'] ?? null;
    $dealerCompany = $dealer['company'] ?? null;

    echo '<!doctype html><html lang="tr"><head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>'.h($pageTitle).'</title>';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">';
    echo representative_base_styles();
    if ($extraHead) {
      echo $extraHead;
    }
    echo '</head><body class="rep-body">';
    echo '<div class="rep-app">';
    echo '<aside class="rep-sidebar">';
    echo '<div class="rep-sidebar-header">';
    echo '<span>'.h(mb_strtoupper(mb_substr(APP_NAME, 0, 2, 'UTF-8'), 'UTF-8')).'</span>';
    echo '<div><strong>'.h(APP_NAME).'</strong><small>Temsilci Paneli</small></div>';
    echo '</div>';
    echo '<nav class="rep-nav">';
    echo '<a class="rep-nav-link'.($activeNav === 'dashboard' ? ' active' : '').'" href="dashboard.php"><i class="bi bi-grid"></i><span>Ana Sayfa</span></a>';
    echo '<a class="rep-nav-link'.($activeNav === 'crm' ? ' active' : '').'" href="crm.php"><i class="bi bi-kanban"></i><span>CRM</span></a>';
    echo '<a class="rep-nav-link'.($activeNav === 'commissions' ? ' active' : '').'" href="commissions.php"><i class="bi bi-cash-coin"></i><span>Komisyonlar</span></a>';
    echo '</nav>';
    echo '<div class="rep-sidebar-meta">';
    echo '<div>'.h(date('d.m.Y')).' itibarıyla güncel.</div>';
    $supportEmail = $repEmail !== '' ? $repEmail : 'destek@'.$baseHost;
    echo '<div>Destek: <a href="mailto:'.h($supportEmail).'">'.h($supportEmail).'</a></div>';
    echo '</div>';
    echo '</aside>';
    echo '<div class="rep-main">';
    echo '<header class="rep-topbar">';
    echo '<div class="rep-topbar-info">';
    echo '<h1>'.h($headerTitle).'</h1>';
    echo '<p>'.h($headerSubtitle).'</p>';
    if ($dealerSelector) {
      echo '<div class="rep-selector-wrapper">'.$dealerSelector.'</div>';
    } elseif ($dealerName) {
      echo '<div class="rep-dealer-pill"><i class="bi bi-building"></i><span>'.h($dealerName).'</span>';
      if ($dealerCompany) {
        echo '<span>'.h($dealerCompany).'</span>';
      }
      echo '</div>';
    } else {
      echo '<div class="rep-dealer-pill rep-dealer-pill--pending"><i class="bi bi-hourglass-split"></i><span>Atama bekleniyor</span></div>';
    }
    echo '</div>';
    echo '<div class="rep-topbar-actions">';
    echo '<div class="rep-user-card">';
    echo '<div class="rep-avatar">'.h($repInitial).'</div>';
    echo '<div>';
    echo '<strong>'.h($repName !== '' ? $repName : APP_NAME.' Temsilcisi').'</strong>';
    if ($repEmail !== '') {
      echo '<span>'.h($repEmail).'</span>';
    }
    if ($repPhone !== '') {
      echo '<span>'.h($repPhone).'</span>';
    }
    echo '</div>';
    echo '</div>';
    echo '<a class="rep-logout" href="'.h($logoutUrl).'"><i class="bi bi-box-arrow-right"></i><span>Çıkış Yap</span></a>';
    echo '</div>';
    echo '</header>';
    echo '<main class="rep-content">';
    echo '<div class="rep-container">';
  }

  function representative_layout_end(): void {
    echo '</div>';
    echo '</main>';
    echo '<footer class="rep-footer">';
    echo '<div class="rep-footer-inner">';
    echo '<span>© '.date('Y').' '.h(APP_NAME).'. Tüm hakları saklıdır.</span>';
    echo '<a href="'.h(BASE_URL).'" target="_blank" rel="noopener">Site ana sayfasına dön</a>';
    echo '</div>';
    echo '</footer>';
    echo '</div>';
    echo '</div>';
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>';
    echo '</body></html>';
  }
}
