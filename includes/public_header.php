<?php
require_once __DIR__.'/functions.php';

function site_public_header(string $active = 'home'): void {
  $links = [
    'home' => ['label' => 'Ana Sayfa', 'url' => BASE_URL.'/index.php#hero'],
    'features' => ['label' => 'Özellikler', 'url' => BASE_URL.'/index.php#ozellikler'],
    'packages' => ['label' => 'Paketler', 'url' => BASE_URL.'/index.php#paketler'],
    'partners' => ['label' => 'Anlaşmalı Şirketler', 'url' => BASE_URL.'/public/partners.php'],
    'contact' => ['label' => 'İletişim', 'url' => BASE_URL.'/index.php#iletisim'],
  ];
  echo '<nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom shadow-sm py-3 site-navbar sticky-top">';
  echo '<div class="container">';
  echo '<a class="navbar-brand fw-bold fs-3" href="'.h(BASE_URL).'/index.php#hero">'.h(APP_NAME).'</a>';
  echo '<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#publicNav" aria-controls="publicNav" aria-expanded="false" aria-label="Menüyü Aç">';
  echo '<span class="navbar-toggler-icon"></span>';
  echo '</button>';
  echo '<div class="collapse navbar-collapse" id="publicNav">';
  echo '<ul class="navbar-nav ms-auto mb-3 mb-lg-0 align-items-lg-center gap-lg-3">';
  foreach ($links as $key => $link) {
    $class = 'nav-link';
    if ($active === $key) {
      $class .= ' active';
    }
    echo '<li class="nav-item"><a class="'.$class.'" href="'.h($link['url']).'">'.h($link['label']).'</a></li>';
  }
  echo '</ul>';
  echo '<div class="cta-bar ms-lg-4 d-flex flex-column flex-lg-row align-items-lg-center gap-2">';
  echo '<a class="btn btn-outline-secondary rounded-pill px-4" href="'.h(BASE_URL).'/dealer/apply.php">Bayi Ol</a>';
  echo '<div class="dropdown">';
  echo '<button class="btn btn-brand dropdown-toggle px-4" type="button" id="loginDropdown" data-bs-toggle="dropdown" aria-expanded="false">Giriş Yap</button>';
  echo '<ul class="dropdown-menu dropdown-menu-end shadow-sm rounded-4" aria-labelledby="loginDropdown">';
  echo '<li><a class="dropdown-item" href="'.h(BASE_URL).'/portal/login.php?tab=dealer">Bayi &amp; Temsilci Girişi</a></li>';
  echo '<li><a class="dropdown-item" href="'.h(BASE_URL).'/public/guest_login.php">Etkinlik &amp; Davetli Girişi</a></li>';
  echo '</ul>';
  echo '</div>';
  echo '</div>';
  echo '</div>';
  echo '</div>';
  echo '</nav>';
}
