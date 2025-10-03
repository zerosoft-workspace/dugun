<?php
require __DIR__.'/includes/db.php';  // config.php zaten db.php içinde çağrılıyor
install_schema();
echo "OK: Şema kuruldu. <a href='admin/login.php'>Admin giriş</a>";
