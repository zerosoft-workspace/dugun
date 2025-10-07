<?php
require_once __DIR__ . '/public_header.php';

if (!function_exists('login_header_styles')) {
    function login_header_styles(): string
    {
        return <<<'CSS'
.site-navbar {
  --nav-bg: #ffffffcc;
  backdrop-filter: blur(18px);
  background: var(--nav-bg);
}

.site-navbar .btn-brand {
  background: linear-gradient(135deg, #0ea5b5, #0b8b98);
  border: none;
  border-radius: 999px;
  font-weight: 700;
  box-shadow: 0 16px 38px -18px rgba(14, 165, 181, .55);
}

.site-navbar .btn-brand:hover,
.site-navbar .btn-brand:focus {
  box-shadow: 0 22px 46px -20px rgba(14, 165, 181, .65);
  transform: translateY(-1px);
}

.site-navbar .login-dropdown__menu {
  min-width: 260px;
  padding: 1.2rem;
  border: none;
  border-radius: 20px;
  background: radial-gradient(circle at top, rgba(14, 165, 181, .14), #fff 65%);
  box-shadow: 0 26px 60px -24px rgba(15, 23, 42, .35);
}

.site-navbar .login-dropdown__menu li + li {
  margin-top: .35rem;
}

.site-navbar .login-dropdown__menu .dropdown-item {
  border-radius: 14px;
  font-weight: 600;
  padding: .7rem .95rem;
  color: #0f172a;
  display: flex;
  align-items: center;
  gap: .65rem;
  transition: all .18s ease;
}

.site-navbar .login-dropdown__menu .dropdown-item::before {
  content: '';
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: linear-gradient(135deg, #0ea5b5, #0b8b98);
  opacity: .55;
  transition: opacity .18s ease, transform .18s ease;
}

.site-navbar .login-dropdown__menu .dropdown-item:hover,
.site-navbar .login-dropdown__menu .dropdown-item:focus {
  background: rgba(14, 165, 181, .14);
  color: #0b8b98;
}

.site-navbar .login-dropdown__menu .dropdown-item:hover::before,
.site-navbar .login-dropdown__menu .dropdown-item:focus::before {
  opacity: 1;
  transform: scale(1.2);
}

.site-navbar .login-dropdown__menu .dropdown-divider {
  margin: .6rem 0;
  opacity: .4;
}

@media (max-width: 991.98px) {
  .site-navbar .cta-bar {
    padding-top: 1rem;
  }

  .site-navbar .login-dropdown__menu {
    border-radius: 16px;
    box-shadow: 0 18px 44px -26px rgba(15, 23, 42, .4);
  }
}
CSS;
    }
}

if (!function_exists('render_login_header')) {
    function render_login_header(?string $active = null): void
    {
        $activeMap = [
            'dealer' => 'home',
            'representative' => 'home',
            'guest' => 'home',
            'portal' => 'home',
        ];

        $target = $activeMap[$active] ?? 'home';
        site_public_header($target);
    }
}
