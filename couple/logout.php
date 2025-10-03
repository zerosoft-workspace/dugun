<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/couple_auth.php';

couple_global_logout();
flash('ok','Çıkış yapıldı.');
redirect(BASE_URL.'/couple/login.php');
