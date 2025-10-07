<?php
if (!function_exists('login_header_styles')) {
    function login_header_styles(): string
    {
        return <<<'CSS'
    .site-header{width:100%;background:rgba(255,255,255,0.92);backdrop-filter:blur(16px);border-bottom:1px solid rgba(15,23,42,0.05);box-shadow:0 18px 40px -32px rgba(15,23,42,0.45);position:sticky;top:0;z-index:100;padding:0.85rem 0;}
    .site-header__inner{max-width:1180px;margin:0 auto;padding:0 1.5rem;display:flex;align-items:center;gap:2rem;}
    .site-header__brand{font-weight:800;font-size:1.35rem;letter-spacing:0.08em;color:#0f172a;text-decoration:none;display:inline-flex;align-items:center;gap:0.5rem;}
    .site-header__brand span{display:inline-block;padding:0.25rem 0.65rem;border-radius:999px;background:linear-gradient(135deg,#38bdf8,#3b82f6);color:#fff;font-size:0.7rem;font-weight:700;letter-spacing:0.12em;}
    .site-header__nav{flex:1;}
    .site-header__nav ul{margin:0;display:flex;align-items:center;gap:1.25rem;list-style:none;padding:0;justify-content:flex-start;}
    .site-header__nav a{font-weight:600;color:rgba(15,23,42,0.72);text-decoration:none;font-size:0.95rem;transition:color 0.2s ease;}
    .site-header__nav a:hover{color:#0f172a;}
    .site-header__cta{display:flex;align-items:center;gap:0.65rem;}
    .site-header__cta-link{display:inline-flex;align-items:center;justify-content:center;height:44px;padding:0 1.35rem;border-radius:999px;font-weight:600;font-size:0.95rem;border:1px solid rgba(14,165,233,0.18);background:rgba(240,249,255,0.75);color:#0ea5e9;text-decoration:none;transition:background 0.2s ease,box-shadow 0.2s ease,color 0.2s ease,border-color 0.2s ease;}
    .site-header__cta-link:hover{background:rgba(14,165,233,0.18);box-shadow:0 16px 32px -24px rgba(14,165,233,0.65);}
    .site-header__cta-link.is-active{background:linear-gradient(135deg,#0ea5e9,#2563eb);color:#fff;border-color:transparent;box-shadow:0 20px 45px -28px rgba(37,99,235,0.6);}
    .site-header__cta-link.is-active:hover{color:#fff;}
    @media (max-width: 992px){
      .site-header__inner{flex-wrap:wrap;gap:1rem 1.2rem;}
      .site-header__nav ul{width:100%;flex-wrap:wrap;gap:0.9rem;}
      .site-header__cta{width:100%;justify-content:flex-start;}
    }
    @media (max-width: 640px){
      .site-header{padding:0.75rem 0;}
      .site-header__inner{padding:0 1rem;}
      .site-header__cta{flex-wrap:wrap;gap:0.5rem;}
      .site-header__cta-link{flex:1 1 calc(50% - 0.5rem);min-width:150px;}
    }
CSS;
    }
}

if (!function_exists('render_login_header')) {
    function render_login_header(?string $active = null): void
    {
        $baseUrl = rtrim((string) (defined('BASE_URL') ? BASE_URL : ''), '/');
        $navLinks = [
            ['label' => 'Ana Sayfa', 'href' => $baseUrl . '/'],
            ['label' => 'Özellikler', 'href' => $baseUrl . '/#ozellikler'],
            ['label' => 'Paketler', 'href' => $baseUrl . '/#paketler'],
            ['label' => 'Referanslar', 'href' => $baseUrl . '/#referanslar'],
            ['label' => 'Ekip', 'href' => $baseUrl . '/#ekip'],
        ];

        $ctaLinks = [
            'dealer' => ['label' => 'Bayi Girişi', 'href' => $baseUrl . '/dealer/login.php'],
            'guest' => ['label' => 'Düğün Girişi', 'href' => $baseUrl . '/public/guest_login.php'],
            'representative' => ['label' => 'Etkinlik Girişi', 'href' => $baseUrl . '/representative/login.php'],
        ];

        $activeKey = array_key_exists((string) $active, $ctaLinks) ? $active : null;

        echo '<header class="site-header"><div class="site-header__inner">';
        echo '<a class="site-header__brand" href="' . htmlspecialchars($baseUrl . '/', ENT_QUOTES, 'UTF-8') . '">BİKARE<span>STUDIO</span></a>';
        echo '<nav class="site-header__nav"><ul>';
        foreach ($navLinks as $link) {
            echo '<li><a href="' . htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') . '</a></li>';
        }
        echo '</ul></nav>';
        echo '<div class="site-header__cta">';
        foreach ($ctaLinks as $key => $link) {
            $class = 'site-header__cta-link';
            if ($activeKey === $key) {
                $class .= ' is-active';
            }
            echo '<a class="' . $class . '" href="' . htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') . '</a>';
        }
        echo '</div></div></header>';
    }
}
