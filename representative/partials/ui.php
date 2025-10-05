<?php
if (!function_exists('representative_base_styles')) {
  function representative_base_styles(): string {
    return <<<'CSS'
    <style>
      @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

      :root {
        --rep-ink:#0f172a;
        --rep-muted:#64748b;
        --rep-brand:#0ea5b5;
        --rep-brand-dark:#0b8b98;
        --rep-surface:#ffffff;
        --rep-background:#f4f7fb;
      }

      body.rep-body {
        margin:0;
        min-height:100vh;
        background:linear-gradient(180deg,var(--rep-background) 0%,#fff 100%);
        color:var(--rep-ink);
        font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;
      }

      .rep-app {
        min-height:100vh;
        display:flex;
        flex-direction:column;
      }

      .rep-header {
        position:relative;
        background:linear-gradient(135deg,rgba(14,165,181,.94),rgba(99,102,241,.9));
        color:#fff;
        padding:3.4rem 0 3.7rem;
        overflow:hidden;
        box-shadow:0 42px 120px -68px rgba(15,23,42,.85);
      }

      .rep-header::before,
      .rep-header::after {
        content:"";
        position:absolute;
        border-radius:50%;
        background:rgba(255,255,255,.12);
        filter:blur(0);
        z-index:1;
      }

      .rep-header::before {
        width:420px;
        height:420px;
        top:-180px;
        right:-160px;
      }

      .rep-header::after {
        width:260px;
        height:260px;
        bottom:-140px;
        left:-120px;
      }

      .rep-header-inner {
        max-width:1240px;
        margin:0 auto;
        padding:0 1.5rem;
        display:flex;
        flex-wrap:wrap;
        align-items:flex-start;
        justify-content:space-between;
        gap:2.4rem;
        position:relative;
        z-index:2;
      }

      .rep-brand {
        display:flex;
        align-items:center;
        gap:1rem;
      }

      .rep-brand span {
        width:48px;
        height:48px;
        border-radius:16px;
        background:rgba(255,255,255,.18);
        display:flex;
        align-items:center;
        justify-content:center;
        font-weight:700;
        font-size:1.2rem;
      }

      .rep-brand strong {
        display:block;
        font-size:1.2rem;
        letter-spacing:.3px;
      }

      .rep-brand small {
        display:block;
        font-size:.88rem;
        color:rgba(255,255,255,.78);
        font-weight:500;
      }

      .rep-header-main {
        flex:1;
        min-width:260px;
      }

      .rep-header-main h1 {
        font-size:2rem;
        font-weight:700;
        margin:1.4rem 0 .6rem;
        letter-spacing:-.01em;
      }

      .rep-header-main p {
        margin:0;
        max-width:560px;
        font-size:1rem;
        color:rgba(255,255,255,.85);
        line-height:1.5;
      }

      .rep-dealer-pill {
        margin-top:1.6rem;
        display:inline-flex;
        align-items:center;
        gap:.65rem;
        padding:.55rem 1.1rem;
        border-radius:999px;
        background:rgba(255,255,255,.18);
        color:#fff;
        font-weight:600;
        letter-spacing:.02em;
      }

      .rep-dealer-pill span {
        font-weight:500;
        opacity:.85;
      }

      .rep-dealer-pill--pending {
        background:rgba(15,23,42,.2);
        color:rgba(255,255,255,.78);
      }

      .rep-header-side {
        display:flex;
        flex-direction:column;
        gap:1.2rem;
        align-items:flex-end;
        min-width:240px;
      }

      .rep-meta-card {
        padding:1.2rem 1.4rem;
        border-radius:20px;
        background:rgba(255,255,255,.16);
        box-shadow:0 34px 80px -40px rgba(15,23,42,.6);
        display:flex;
        gap:1rem;
        align-items:center;
        text-align:left;
        min-width:240px;
      }

      .rep-avatar {
        width:58px;
        height:58px;
        border-radius:18px;
        background:rgba(255,255,255,.22);
        display:flex;
        align-items:center;
        justify-content:center;
        font-weight:700;
        font-size:1.35rem;
      }

      .rep-meta-info strong {
        display:block;
        font-size:1.05rem;
      }

      .rep-meta-info span {
        display:block;
        font-size:.85rem;
        color:rgba(255,255,255,.85);
      }

      .rep-logout {
        display:inline-flex;
        align-items:center;
        gap:.55rem;
        border-radius:14px;
        padding:.65rem 1.2rem;
        font-weight:600;
        background:#fff;
        color:#0f172a;
        border:none;
        box-shadow:0 18px 50px -32px rgba(15,23,42,.6);
        text-decoration:none;
      }

      .rep-logout:hover {
        background:#f1f5f9;
        color:#0f172a;
        text-decoration:none;
      }

      .rep-content {
        flex:1;
        position:relative;
        z-index:2;
        margin-top:-72px;
      }

      .rep-shell {
        max-width:1240px;
        margin:0 auto;
        padding:0 1.5rem 3.8rem;
        width:100%;
      }

      .rep-shell > .alert {
        border-radius:16px;
        border:none;
        box-shadow:0 20px 44px -28px rgba(15,23,42,.35);
      }

      .rep-footer {
        padding:2.5rem 1.5rem;
        background:#0f172a;
        color:rgba(241,245,249,.86);
        margin-top:auto;
      }

      .rep-footer-inner {
        max-width:1240px;
        margin:0 auto;
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:1rem;
        font-size:.85rem;
      }

      .rep-footer-inner a {
        color:rgba(14,165,181,.85);
        font-weight:600;
        text-decoration:none;
      }

      .rep-footer-inner a:hover {
        color:#38bdf8;
      }

      @media (max-width: 992px) {
        .rep-header {
          padding:2.8rem 0 3.2rem;
        }

        .rep-header-side {
          align-items:flex-start;
          width:100%;
        }

        .rep-logout {
          align-self:flex-start;
        }
      }

      @media (max-width: 576px) {
        .rep-brand span {
          width:42px;
          height:42px;
        }

        .rep-header-main h1 {
          font-size:1.7rem;
        }

        .rep-content {
          margin-top:-56px;
        }

        .rep-shell {
          padding:0 1.1rem 3rem;
        }

        .rep-footer {
          padding:2rem 1.2rem;
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
    echo '<header class="rep-header">';
    echo '<div class="rep-header-inner">';
    echo '<div class="rep-header-main">';
    echo '<div class="rep-brand">';
    echo '<span>'.h(mb_strtoupper(mb_substr(APP_NAME, 0, 2, 'UTF-8'), 'UTF-8')).'</span>';
    echo '<div><strong>'.h(APP_NAME).'</strong><small>Temsilci Paneli</small></div>';
    echo '</div>';
    echo '<h1>'.h($headerTitle).'</h1>';
    echo '<p>'.h($headerSubtitle).'</p>';
    if ($dealerSelector) {
      echo $dealerSelector;
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
    echo '<div class="rep-header-side">';
    echo '<div class="rep-meta-card">';
    echo '<div class="rep-avatar">'.h($repInitial).'</div>';
    echo '<div class="rep-meta-info">';
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
    echo '</div>';
    echo '</header>';
    echo '<main class="rep-content">';
    echo '<div class="rep-shell">';
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
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>';
    echo '</body></html>';
  }
}
