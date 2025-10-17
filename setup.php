<?php
$storageDir = __DIR__.'/storage';
$setupFile = $storageDir.'/setup.json';
$existingConfig = [];

if (is_readable($setupFile)) {
    $contents = file_get_contents($setupFile);
    $decoded = json_decode($contents, true);
    if (is_array($decoded)) {
        $existingConfig = $decoded;
    }
}

if (PHP_SAPI === 'cli') {
    if (!$existingConfig) {
        fwrite(STDERR, "Lütfen setup.php dosyasını tarayıcıdan açarak veritabanı bilgilerini girin.\n");
        exit(1);
    }

    require __DIR__.'/includes/db.php';
    install_schema();
    echo "OK: Şema kuruldu. Yönetim paneline admin/login.php adresinden ulaşabilirsiniz.\n";
    exit;
}

header('Content-Type: text/html; charset=utf-8');

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$detectedBase = 'http://localhost';
if (!empty($_SERVER['HTTP_HOST'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $detectedBase = $scheme.'://'.$_SERVER['HTTP_HOST'];
}

$defaults = [
    'db_host' => $existingConfig['APP_DB_HOST'] ?? '127.0.0.1',
    'db_name' => $existingConfig['APP_DB_NAME'] ?? 'dugun',
    'db_user' => $existingConfig['APP_DB_USER'] ?? 'dugun',
    'base_url' => $existingConfig['APP_BASE_URL'] ?? $detectedBase,
];

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['db_host'] ?? '');
    $name = trim($_POST['db_name'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $baseUrlInput = $_POST['base_url'] ?? '';
    $baseUrl = is_string($baseUrlInput) ? trim($baseUrlInput) : '';
    $passInput = $_POST['db_pass'] ?? '';
    $pass = is_string($passInput) ? trim($passInput) : '';

    if ($host === '') {
        $errors[] = 'Veritabanı sunucusu zorunludur.';
    }

    if ($name === '') {
        $errors[] = 'Veritabanı adı zorunludur.';
    }

    if ($user === '') {
        $errors[] = 'Veritabanı kullanıcısı zorunludur.';
    }

    if ($pass === '') {
        if (!empty($existingConfig['APP_DB_PASS'])) {
            $pass = $existingConfig['APP_DB_PASS'];
        } else {
            $errors[] = 'Veritabanı şifresi zorunludur.';
        }
    }

    if ($baseUrl === '') {
        $errors[] = 'Site adresi (base URL) zorunludur.';
    } elseif (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
        $errors[] = 'Geçerli bir site adresi girin. Örn: https://alanadiniz.com';
    }

    if (!$errors) {
        try {
            $testPdo = new PDO(
                "mysql:host={$host};dbname={$name};charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
            $testPdo->query('SELECT 1');
        } catch (Throwable $e) {
            $errors[] = 'Veritabanına bağlanılamadı: '.h($e->getMessage());
        }
    }

    if (!$errors) {
        if (!is_dir($storageDir)) {
            if (!mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
                $errors[] = 'storage klasörü oluşturulamadı. İzinleri kontrol edin.';
            }
        }

        if (!$errors) {
            $configData = [
                'APP_DB_HOST' => $host,
                'APP_DB_NAME' => $name,
                'APP_DB_USER' => $user,
                'APP_DB_PASS' => $pass,
                'APP_BASE_URL' => rtrim($baseUrl, '/'),
            ];

            $htaccessFile = $storageDir.'/.htaccess';
            $htaccessContents = <<<HTACCESS
# Prevent direct web access to setup artifacts
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule mod_authz_host.c>
    Deny from all
</IfModule>
HTACCESS;

            if (!is_file($htaccessFile)) {
                if (file_put_contents($htaccessFile, $htaccessContents, LOCK_EX) === false) {
                    $errors[] = 'storage/.htaccess dosyası oluşturulamadı. İzinleri kontrol edin.';
                }
            } else {
                $existingHtaccess = file_get_contents($htaccessFile);
                if ($existingHtaccess === false) {
                    $errors[] = 'storage/.htaccess dosyası okunamadı. İzinleri kontrol edin.';
                } elseif (strpos($existingHtaccess, 'Require all denied') === false && strpos($existingHtaccess, 'Deny from all') === false) {
                    if (file_put_contents($htaccessFile, PHP_EOL.$htaccessContents.PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
                        $errors[] = 'storage/.htaccess dosyası güncellenemedi. İzinleri kontrol edin.';
                    }
                }
            }

        }

        if (!$errors) {
            $json = json_encode($configData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($json === false || file_put_contents($setupFile, $json, LOCK_EX) === false) {
                $errors[] = 'Veritabanı bilgileri kaydedilemedi.';
            } else {
                require __DIR__.'/includes/db.php';
                install_schema();
                $success = true;
                $defaults = [
                    'db_host' => $host,
                    'db_name' => $name,
                    'db_user' => $user,
                    'base_url' => rtrim($baseUrl, '/'),
                ];
            }
        }
    }
}

?><!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>Ala Döner — Kurulum</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: 'Inter', 'Poppins', system-ui, sans-serif; margin: 0; background: #f1f5fb; color: #0f172a; }
        .container { max-width: 640px; margin: 4rem auto; padding: 2.5rem 2rem; background: #ffffff; border-radius: 24px; box-shadow: 0 18px 48px rgba(15, 23, 42, 0.12); }
        h1 { margin-top: 0; font-size: 1.8rem; }
        p { line-height: 1.6; }
        form { display: grid; gap: 1.25rem; margin-top: 1.5rem; }
        label { display: grid; gap: 0.4rem; font-weight: 600; }
        input[type="text"], input[type="password"] { padding: 0.75rem 1rem; border-radius: 14px; border: 1px solid rgba(15, 23, 42, 0.15); font-size: 1rem; }
        input[type="text"]:focus, input[type="password"]:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.18); }
        button { padding: 0.85rem 1.6rem; border-radius: 16px; border: none; background: linear-gradient(135deg, #3b82f6, #2563eb); color: #fff; font-weight: 600; cursor: pointer; box-shadow: 0 12px 32px rgba(59, 130, 246, 0.26); }
        button:hover { transform: translateY(-1px); box-shadow: 0 18px 40px rgba(59, 130, 246, 0.3); }
        .alert { padding: 1rem 1.2rem; border-radius: 16px; font-weight: 500; }
        .alert.error { background: rgba(239, 68, 68, 0.12); color: #7f1d1d; }
        .alert.success { background: rgba(34, 197, 94, 0.16); color: #166534; }
        ul { margin: 0.6rem 0 0; padding-left: 1.4rem; }
        footer { margin-top: 2rem; font-size: 0.85rem; color: rgba(15, 23, 42, 0.65); text-align: center; }
        .muted { color: rgba(15, 23, 42, 0.65); font-size: 0.95rem; }
        .input-help { font-size: 0.85rem; color: rgba(15, 23, 42, 0.6); }
    </style>
</head>
<body>
<div class="container">
    <h1>Sistem Kurulumu</h1>
    <?php if ($success): ?>
        <div class="alert success">Kurulum tamamlandı. Şimdi <a href="<?= h(($defaults['base_url'] ?? $detectedBase).'/admin/login.php') ?>">yönetim paneline giriş yapabilirsiniz</a>.</div>
    <?php else: ?>
        <p class="muted">Lütfen veritabanı bağlantı bilgilerini girin. Bu bilgiler doğrudan web erişimine kapatılmış olan <code>storage/setup.json</code> dosyasında saklanacaktır.</p>
        <?php if ($errors): ?>
            <div class="alert error">
                <strong>Kurulum tamamlanamadı:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= $error ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <label>
                Veritabanı Sunucusu
                <input type="text" name="db_host" value="<?= h($defaults['db_host']) ?>" required>
                <span class="input-help">Örn: 127.0.0.1 veya localhost</span>
            </label>
            <label>
                Veritabanı Adı
                <input type="text" name="db_name" value="<?= h($defaults['db_name']) ?>" required>
            </label>
            <label>
                Veritabanı Kullanıcısı
                <input type="text" name="db_user" value="<?= h($defaults['db_user']) ?>" required>
            </label>
            <label>
                Site Adresi (Base URL)
                <input type="text" name="base_url" value="<?= h($defaults['base_url']) ?>" placeholder="https://alanadiniz.com" required>
                <span class="input-help">Sitenizin dışarıdan erişilen adresi. Örn: https://drive.demozerosoft.com.tr</span>
            </label>
            <label>
                Veritabanı Şifresi
                <input type="password" name="db_pass" value="">
                <span class="input-help">Mevcut bir kurulum varsa boş bırakabilirsiniz, mevcut şifre korunur.</span>
            </label>
            <button type="submit">Kurulumu Tamamla</button>
        </form>
    <?php endif; ?>
</div>
<footer>© <?= date('Y') ?> Ala Döner</footer>
</body>
</html>
