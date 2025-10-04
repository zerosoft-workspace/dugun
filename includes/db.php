<?php
require_once __DIR__.'/../config.php';

if (!defined('APP_SCHEMA_VERSION')) {
  define('APP_SCHEMA_VERSION', '20240506_01');
}

function pdo(): PDO {
  static $pdo = null;
  if (!$pdo) {
    $pdo = new PDO(
      "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
      DB_USER, DB_PASS,
      [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
  }
  return $pdo;
}
function column_exists(string $t, string $c): bool {
  $st=pdo()->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $st->execute([$t,$c]); return (bool)$st->fetchColumn();
}
function supports_json(): bool { try{pdo()->query("SELECT JSON_VALID('[]')");return true;}catch(Throwable){return false;} }

function install_schema(){
  static $ran = false;
  if ($ran) {
    return;
  }
  $ran = true;

  $pdo = pdo();

  $pdo->exec("CREATE TABLE IF NOT EXISTS app_meta(
    meta_key VARCHAR(64) PRIMARY KEY,
    meta_value TEXT NULL,
    updated_at DATETIME NOT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $currentVersion = null;
  try {
    $st = $pdo->prepare("SELECT meta_value FROM app_meta WHERE meta_key='schema_version' LIMIT 1");
    $st->execute();
    $currentVersion = $st->fetchColumn() ?: null;
  } catch (Throwable $e) {
    $currentVersion = null;
  }

  if ($currentVersion === APP_SCHEMA_VERSION) {
    return;
  }

  /* users */
  pdo()->exec("CREATE TABLE IF NOT EXISTS users(
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(190) NOT NULL,
    role ENUM('superadmin','admin') NOT NULL DEFAULT 'admin',
    reset_code VARCHAR(10) NULL,
    reset_expires DATETIME NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  /* venues */
  pdo()->exec("CREATE TABLE IF NOT EXISTS venues(
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    slug VARCHAR(190) UNIQUE NOT NULL,
    created_at DATETIME NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  /* dealers */
  pdo()->exec("CREATE TABLE IF NOT EXISTS dealers(
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(16) NULL,
    name VARCHAR(190) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    phone VARCHAR(64) NULL,
    company VARCHAR(190) NULL,
    notes TEXT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    license_expires_at DATETIME NULL,
    password_hash VARCHAR(255) NULL,
    approved_at DATETIME NULL,
    last_login_at DATETIME NULL,
    balance_cents INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  if (!column_exists('dealers', 'code')) {
    pdo()->exec("ALTER TABLE dealers ADD code VARCHAR(16) NULL AFTER id");
  }
  try {
    pdo()->exec("ALTER TABLE dealers ADD UNIQUE KEY uniq_dealer_code (code)");
  } catch (Throwable $e) {
    // index already exists
  }

  /* events */
  pdo()->exec("CREATE TABLE IF NOT EXISTS events(
    id INT AUTO_INCREMENT PRIMARY KEY,
    venue_id INT NOT NULL,
    dealer_id INT NULL,
    dealer_credit_consumed_at DATETIME NULL,
    user_id INT NULL,
    contact_email VARCHAR(190) NULL,
    title VARCHAR(190) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    couple_panel_key VARCHAR(64) NOT NULL,
    guest_prompt VARCHAR(255) DEFAULT 'Adınızı yazıp anınızı yükleyin.',
    theme_primary VARCHAR(7) DEFAULT '#0ea5b5',
    theme_accent  VARCHAR(7) DEFAULT '#e0f7fb',
    allow_downloads TINYINT(1) NOT NULL DEFAULT 1,
    event_date DATE NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    UNIQUE KEY uniq_event_slug (venue_id, slug),
    INDEX (venue_id),
    INDEX (dealer_id),
    FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE CASCADE,
    FOREIGN KEY (dealer_id) REFERENCES dealers(id) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  /* dealer_venues (çoktan çoğa) */
  pdo()->exec("CREATE TABLE IF NOT EXISTS dealer_venues(
    dealer_id INT NOT NULL,
    venue_id INT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (dealer_id, venue_id),
    INDEX (venue_id),
    FOREIGN KEY (dealer_id) REFERENCES dealers(id) ON DELETE CASCADE,
    FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  /* dealer_codes — statik & deneme kodları */
  pdo()->exec("CREATE TABLE IF NOT EXISTS dealer_codes(
    id INT AUTO_INCREMENT PRIMARY KEY,
    dealer_id INT NOT NULL,
    type VARCHAR(16) NOT NULL,
    code VARCHAR(64) NOT NULL,
    target_event_id INT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    UNIQUE KEY uniq_dealer_type (dealer_id, type),
    UNIQUE KEY uniq_code (code),
    INDEX (dealer_id, type),
    FOREIGN KEY (dealer_id) REFERENCES dealers(id) ON DELETE CASCADE,
    FOREIGN KEY (target_event_id) REFERENCES events(id) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  pdo()->exec("CREATE TABLE IF NOT EXISTS dealer_packages(
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    price_cents INT NOT NULL,
    event_quota INT NULL,
    duration_days INT NULL,
    cashback_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_public TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    INDEX idx_dealer_packages_active (is_active),
    INDEX idx_dealer_packages_public (is_public)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  if (!column_exists('dealer_packages', 'is_public')) {
    try {
      pdo()->exec("ALTER TABLE dealer_packages ADD is_public TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
      pdo()->exec("CREATE INDEX idx_dealer_packages_public ON dealer_packages(is_public)");
    } catch (Throwable $e) {}
  }

  $jsonMeta = supports_json() ? 'JSON' : 'LONGTEXT';
  pdo()->exec("CREATE TABLE IF NOT EXISTS dealer_package_purchases(
    id INT AUTO_INCREMENT PRIMARY KEY,
    dealer_id INT NOT NULL,
    package_id INT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    price_cents INT NOT NULL,
    event_quota INT NULL,
    events_used INT NOT NULL DEFAULT 0,
    duration_days INT NULL,
    starts_at DATETIME NOT NULL,
    expires_at DATETIME NULL,
    cashback_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
    cashback_status VARCHAR(20) NOT NULL DEFAULT 'none',
    cashback_amount INT NOT NULL DEFAULT 0,
    cashback_note VARCHAR(255) NULL,
    cashback_paid_at DATETIME NULL,
    lead_event_id INT NULL,
    source VARCHAR(16) NOT NULL DEFAULT 'dealer',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    INDEX idx_package_dealer (dealer_id, status),
    INDEX idx_package_event (lead_event_id),
    INDEX idx_package_expiry (expires_at),
    FOREIGN KEY (dealer_id) REFERENCES dealers(id) ON DELETE CASCADE,
    FOREIGN KEY (package_id) REFERENCES dealer_packages(id) ON DELETE CASCADE,
    FOREIGN KEY (lead_event_id) REFERENCES events(id) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  if (!column_exists('dealer_package_purchases', 'source')) {
    try {
      pdo()->exec("ALTER TABLE dealer_package_purchases ADD source VARCHAR(16) NOT NULL DEFAULT 'dealer' AFTER lead_event_id");
    } catch (Throwable $e) {}
  }

  pdo()->exec("CREATE TABLE IF NOT EXISTS dealer_wallet_transactions(
    id INT AUTO_INCREMENT PRIMARY KEY,
    dealer_id INT NOT NULL,
    type VARCHAR(32) NOT NULL,
    amount_cents INT NOT NULL,
    balance_after INT NOT NULL,
    description VARCHAR(255) NULL,
    meta_json $jsonMeta NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_wallet_dealer (dealer_id, created_at),
    FOREIGN KEY (dealer_id) REFERENCES dealers(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  pdo()->exec("CREATE TABLE IF NOT EXISTS dealer_topups(
    id INT AUTO_INCREMENT PRIMARY KEY,
    dealer_id INT NOT NULL,
    amount_cents INT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    paytr_token VARCHAR(64) NULL,
    merchant_oid VARCHAR(64) NULL,
    paytr_reference VARCHAR(64) NULL,
    payload_json $jsonMeta NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    completed_at DATETIME NULL,
    INDEX idx_topups_status (dealer_id, status),
    UNIQUE KEY uniq_topup_oid (merchant_oid),
    FOREIGN KEY (dealer_id) REFERENCES dealers(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  if (!column_exists('dealer_topups','merchant_oid')) {
    try {
      pdo()->exec("ALTER TABLE dealer_topups ADD merchant_oid VARCHAR(64) NULL AFTER paytr_token");
      pdo()->exec("ALTER TABLE dealer_topups ADD UNIQUE KEY uniq_topup_oid (merchant_oid)");
    } catch (Throwable $e) {}
  }

  /* müşteri web siparişleri */
  $jsonMeta = supports_json() ? 'JSON' : 'LONGTEXT';
  pdo()->exec("CREATE TABLE IF NOT EXISTS site_orders(
    id INT AUTO_INCREMENT PRIMARY KEY,
    package_id INT NOT NULL,
    dealer_id INT NULL,
    event_id INT NULL,
    customer_name VARCHAR(190) NOT NULL,
    customer_email VARCHAR(190) NOT NULL,
    customer_phone VARCHAR(64) NULL,
    event_title VARCHAR(190) NOT NULL,
    event_date DATE NULL,
    referral_code VARCHAR(64) NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending_payment',
    merchant_oid VARCHAR(64) NULL,
    paytr_token VARCHAR(64) NULL,
    paytr_reference VARCHAR(64) NULL,
    price_cents INT NOT NULL DEFAULT 0,
    cashback_cents INT NOT NULL DEFAULT 0,
    paid_at DATETIME NULL,
    meta_json $jsonMeta NULL,
    payload_json $jsonMeta NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    UNIQUE KEY uniq_site_orders_oid (merchant_oid),
    FOREIGN KEY (package_id) REFERENCES dealer_packages(id) ON DELETE RESTRICT,
    FOREIGN KEY (dealer_id) REFERENCES dealers(id) ON DELETE SET NULL,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  if (!column_exists('site_orders', 'merchant_oid')) {
    pdo()->exec("ALTER TABLE site_orders ADD merchant_oid VARCHAR(64) NULL AFTER status");
  }
  if (!column_exists('site_orders', 'paytr_token')) {
    pdo()->exec("ALTER TABLE site_orders ADD paytr_token VARCHAR(64) NULL AFTER merchant_oid");
  }
  if (!column_exists('site_orders', 'paytr_reference')) {
    pdo()->exec("ALTER TABLE site_orders ADD paytr_reference VARCHAR(64) NULL AFTER paytr_token");
  }
  if (!column_exists('site_orders', 'paid_at')) {
    pdo()->exec("ALTER TABLE site_orders ADD paid_at DATETIME NULL AFTER cashback_cents");
  }
  if (!column_exists('site_orders', 'payload_json')) {
    pdo()->exec("ALTER TABLE site_orders ADD payload_json $jsonMeta NULL AFTER meta_json");
  }
  try {
    pdo()->exec("ALTER TABLE site_orders MODIFY status VARCHAR(24) NOT NULL DEFAULT 'pending_payment'");
  } catch (Throwable $e) {}
  try {
    pdo()->exec("ALTER TABLE site_orders ADD UNIQUE KEY uniq_site_orders_oid (merchant_oid)");
  } catch (Throwable $e) {
    // index already exists
  }

  /* uploads */
  pdo()->exec("CREATE TABLE IF NOT EXISTS uploads(
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    venue_id INT NOT NULL,
    event_id INT NOT NULL,
    guest_name VARCHAR(190) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    mime VARCHAR(100) NOT NULL,
    file_size INT NOT NULL,
    ip VARCHAR(64) NULL,
    created_at DATETIME NOT NULL,
    INDEX (venue_id,event_id),
    FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  /* guest profiles & sosyal etkileşim tabloları */
  pdo()->exec("CREATE TABLE IF NOT EXISTS guest_profiles(
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    email VARCHAR(190) NOT NULL,
    name VARCHAR(190) NOT NULL,
    display_name VARCHAR(190) NOT NULL,
    bio TEXT NULL,
    avatar_token VARCHAR(32) NULL,
    password_hash VARCHAR(255) NULL,
    password_set_at DATETIME NULL,
    password_token VARCHAR(64) NULL,
    password_token_expires_at DATETIME NULL,
    last_login_at DATETIME NULL,
    is_verified TINYINT(1) NOT NULL DEFAULT 0,
    verify_token VARCHAR(64) NULL,
    verified_at DATETIME NULL,
    marketing_opt_in TINYINT(1) NOT NULL DEFAULT 0,
    marketing_opted_at DATETIME NULL,
    last_verification_sent_at DATETIME NULL,
    last_seen_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    UNIQUE KEY uniq_guest_profile (event_id, email),
    INDEX idx_guest_event (event_id),
    INDEX idx_guest_verify (verify_token),
    INDEX idx_guest_password_token (password_token),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  if (!column_exists('guest_profiles', 'avatar_token')) {
    try { pdo()->exec("ALTER TABLE guest_profiles ADD avatar_token VARCHAR(32) NULL AFTER bio"); } catch (Throwable $e) {}
  }
  if (!column_exists('guest_profiles', 'bio')) {
    try { pdo()->exec("ALTER TABLE guest_profiles ADD bio TEXT NULL AFTER display_name"); } catch (Throwable $e) {}
  }
  if (!column_exists('guest_profiles', 'marketing_opt_in')) {
    try {
      pdo()->exec("ALTER TABLE guest_profiles ADD marketing_opt_in TINYINT(1) NOT NULL DEFAULT 0 AFTER verify_token");
      pdo()->exec("ALTER TABLE guest_profiles ADD marketing_opted_at DATETIME NULL AFTER marketing_opt_in");
    } catch (Throwable $e) {}
  }
  if (!column_exists('guest_profiles', 'last_verification_sent_at')) {
    try { pdo()->exec("ALTER TABLE guest_profiles ADD last_verification_sent_at DATETIME NULL AFTER marketing_opted_at"); } catch (Throwable $e) {}
  }
  if (!column_exists('guest_profiles', 'last_seen_at')) {
    try { pdo()->exec("ALTER TABLE guest_profiles ADD last_seen_at DATETIME NULL AFTER last_verification_sent_at"); } catch (Throwable $e) {}
  }
  if (!column_exists('guest_profiles', 'display_name')) {
    try { pdo()->exec("ALTER TABLE guest_profiles ADD display_name VARCHAR(190) NOT NULL DEFAULT '' AFTER name"); } catch (Throwable $e) {}
  }
  if (!column_exists('guest_profiles', 'verified_at')) {
    try { pdo()->exec("ALTER TABLE guest_profiles ADD verified_at DATETIME NULL AFTER verify_token"); } catch (Throwable $e) {}
  }
  if (!column_exists('guest_profiles', 'password_hash')) {
    try { pdo()->exec("ALTER TABLE guest_profiles ADD password_hash VARCHAR(255) NULL AFTER avatar_token"); } catch (Throwable $e) {}
  }
  if (!column_exists('guest_profiles', 'password_set_at')) {
    try { pdo()->exec("ALTER TABLE guest_profiles ADD password_set_at DATETIME NULL AFTER password_hash"); } catch (Throwable $e) {}
  }
  if (!column_exists('guest_profiles', 'password_token')) {
    try { pdo()->exec("ALTER TABLE guest_profiles ADD password_token VARCHAR(64) NULL AFTER password_set_at"); } catch (Throwable $e) {}
  }
  if (!column_exists('guest_profiles', 'password_token_expires_at')) {
    try { pdo()->exec("ALTER TABLE guest_profiles ADD password_token_expires_at DATETIME NULL AFTER password_token"); } catch (Throwable $e) {}
  }
  if (!column_exists('guest_profiles', 'last_login_at')) {
    try { pdo()->exec("ALTER TABLE guest_profiles ADD last_login_at DATETIME NULL AFTER password_token_expires_at"); } catch (Throwable $e) {}
  }
  try {
    pdo()->exec("ALTER TABLE guest_profiles ADD INDEX idx_guest_password_token (password_token)");
  } catch (Throwable $e) {}

  if (!column_exists('uploads', 'profile_id')) {
    try { pdo()->exec("ALTER TABLE uploads ADD profile_id INT NULL AFTER guest_name"); } catch (Throwable $e) {}
    try { pdo()->exec("ALTER TABLE uploads ADD CONSTRAINT fk_upload_profile FOREIGN KEY (profile_id) REFERENCES guest_profiles(id) ON DELETE SET NULL"); } catch (Throwable $e) {}
  }
  if (!column_exists('uploads', 'guest_email')) {
    try { pdo()->exec("ALTER TABLE uploads ADD guest_email VARCHAR(190) NULL AFTER profile_id"); } catch (Throwable $e) {}
  }
  try {
    pdo()->exec("CREATE INDEX idx_upload_profile ON uploads(profile_id)");
  } catch (Throwable $e) {}

  pdo()->exec("CREATE TABLE IF NOT EXISTS guest_upload_likes(
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    upload_id BIGINT NOT NULL,
    profile_id INT NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_upload_like (upload_id, profile_id),
    INDEX idx_like_profile (profile_id),
    FOREIGN KEY (upload_id) REFERENCES uploads(id) ON DELETE CASCADE,
    FOREIGN KEY (profile_id) REFERENCES guest_profiles(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  pdo()->exec("CREATE TABLE IF NOT EXISTS guest_upload_comments(
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    upload_id BIGINT NOT NULL,
    profile_id INT NULL,
    guest_name VARCHAR(190) NULL,
    guest_email VARCHAR(190) NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_comment_upload (upload_id, created_at),
    INDEX idx_comment_profile (profile_id),
    FOREIGN KEY (upload_id) REFERENCES uploads(id) ON DELETE CASCADE,
    FOREIGN KEY (profile_id) REFERENCES guest_profiles(id) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  pdo()->exec("CREATE TABLE IF NOT EXISTS guest_chat_messages(
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    profile_id INT NULL,
    message TEXT NOT NULL,
    attachment_upload_id BIGINT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_chat_event (event_id, created_at),
    INDEX idx_chat_profile (profile_id),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (profile_id) REFERENCES guest_profiles(id) ON DELETE SET NULL,
    FOREIGN KEY (attachment_upload_id) REFERENCES uploads(id) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  /* qr_codes */
  pdo()->exec("CREATE TABLE IF NOT EXISTS qr_codes(
    id INT AUTO_INCREMENT PRIMARY KEY,
    venue_id INT NOT NULL,
    code VARCHAR(64) NOT NULL,
    target_event_id INT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    UNIQUE KEY uniq_qr (venue_id, code),
    INDEX (venue_id),
    FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE CASCADE,
    FOREIGN KEY (target_event_id) REFERENCES events(id) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  /* campaigns */
  pdo()->exec("CREATE TABLE IF NOT EXISTS campaigns(
    id INT AUTO_INCREMENT PRIMARY KEY,
    venue_id INT NOT NULL,
    name VARCHAR(190) NOT NULL,
    type VARCHAR(120) NOT NULL,
    description TEXT NULL,
    price INT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    INDEX (venue_id, is_active),
    INDEX idx_campaigns_name (name),
    FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  /* purchases */
  $json = supports_json() ? 'JSON' : 'LONGTEXT';
  pdo()->exec("CREATE TABLE IF NOT EXISTS purchases(
    id INT AUTO_INCREMENT PRIMARY KEY,
    venue_id INT NOT NULL,
    event_id INT NOT NULL,
    campaign_id INT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    amount INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'TL',
    paytr_oid VARCHAR(64) NULL,
    items_json $json NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    UNIQUE KEY uniq_paytr_oid (paytr_oid),
    INDEX (venue_id,event_id,campaign_id),
    INDEX idx_purchases_status (status),
    INDEX idx_purchases_created (created_at),
    FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  /* settings */
  pdo()->exec("CREATE TABLE IF NOT EXISTS settings(
    id INT PRIMARY KEY DEFAULT 1,
    price_10s INT DEFAULT 50,
    price_100s INT DEFAULT 200,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  pdo()->exec("INSERT IGNORE INTO settings (id, created_at) VALUES (1, NOW())");

  if (!column_exists('users','role')) {
    pdo()->exec("ALTER TABLE users ADD role ENUM('superadmin','admin') NOT NULL DEFAULT 'admin' AFTER name");
    // En az bir süperadmin olsun
    pdo()->exec("UPDATE users SET role='superadmin' WHERE id IN (SELECT id FROM (SELECT id FROM users ORDER BY id ASC LIMIT 1) AS t)");
  }
  if (!column_exists('users','last_login_at')) {
    pdo()->exec("ALTER TABLE users ADD last_login_at DATETIME NULL AFTER reset_expires");
  }
  if (!column_exists('users','updated_at')) {
    pdo()->exec("ALTER TABLE users ADD updated_at DATETIME NULL AFTER created_at");
  }

  /* çift hesap alanları + lisans + fatura */
  if (!column_exists('events','dealer_id')) {
    try {
      pdo()->exec("ALTER TABLE events ADD dealer_id INT NULL AFTER venue_id");
      pdo()->exec("CREATE INDEX idx_events_dealer ON events(dealer_id)");
      pdo()->exec("ALTER TABLE events ADD CONSTRAINT fk_events_dealer FOREIGN KEY (dealer_id) REFERENCES dealers(id) ON DELETE SET NULL");
    } catch (Throwable $e) {
      // sessizce yut (bazı eski MySQL sürümleri aynı anda FK eklemeyi desteklemeyebilir)
    }
  }
  if (!column_exists('events','dealer_credit_consumed_at')) {
    try {
      pdo()->exec("ALTER TABLE events ADD dealer_credit_consumed_at DATETIME NULL AFTER dealer_id");
    } catch (Throwable $e) {}
  }
  if (!column_exists('events','guest_title'))         pdo()->exec("ALTER TABLE events ADD guest_title VARCHAR(190) NULL");
  if (!column_exists('events','guest_subtitle'))      pdo()->exec("ALTER TABLE events ADD guest_subtitle VARCHAR(255) NULL");
  if (!column_exists('events','allow_guest_view'))    pdo()->exec("ALTER TABLE events ADD allow_guest_view TINYINT(1) NOT NULL DEFAULT 1");
  if (!column_exists('events','allow_guest_download'))pdo()->exec("ALTER TABLE events ADD allow_guest_download TINYINT(1) NOT NULL DEFAULT 1");
  if (!column_exists('events','allow_guest_delete'))  pdo()->exec("ALTER TABLE events ADD allow_guest_delete TINYINT(1) NOT NULL DEFAULT 0");
  if (!column_exists('events','layout_json'))         pdo()->exec("ALTER TABLE events ADD layout_json ".($json==='JSON'?'JSON':'LONGTEXT')." NULL");
  if (!column_exists('events','stickers_json'))       pdo()->exec("ALTER TABLE events ADD stickers_json ".($json==='JSON'?'JSON':'LONGTEXT')." NULL");
  if (!column_exists('events','updated_at'))          pdo()->exec("ALTER TABLE events ADD updated_at DATETIME NULL");
  if (!column_exists('events','contact_email'))       pdo()->exec("ALTER TABLE events ADD contact_email VARCHAR(190) NULL");
  if (!column_exists('events','couple_username'))     pdo()->exec("ALTER TABLE events ADD couple_username VARCHAR(190) NULL");
  if (!column_exists('events','couple_password_hash'))pdo()->exec("ALTER TABLE events ADD couple_password_hash VARCHAR(255) NULL");
  if (!column_exists('events','couple_force_reset'))  pdo()->exec("ALTER TABLE events ADD couple_force_reset TINYINT(1) NOT NULL DEFAULT 1");
  if (!column_exists('events','couple_phone'))        pdo()->exec("ALTER TABLE events ADD couple_phone VARCHAR(32) NULL");
  if (!column_exists('events','couple_tckn'))         pdo()->exec("ALTER TABLE events ADD couple_tckn VARCHAR(11) NULL");
  if (!column_exists('events','invoice_title'))       pdo()->exec("ALTER TABLE events ADD invoice_title VARCHAR(190) NULL");
  if (!column_exists('events','invoice_vkn'))         pdo()->exec("ALTER TABLE events ADD invoice_vkn VARCHAR(16) NULL");
  if (!column_exists('events','invoice_address'))     pdo()->exec("ALTER TABLE events ADD invoice_address VARCHAR(255) NULL");
  if (!column_exists('events','license_expires_at'))  pdo()->exec("ALTER TABLE events ADD license_expires_at DATETIME NULL");

  try { pdo()->exec("ALTER TABLE events DROP INDEX uniq_events_couple_username"); } catch(Throwable $e){}
  try { pdo()->exec("CREATE INDEX idx_events_couple_username ON events(couple_username)"); } catch(Throwable $e){}
  // purchases.paid_at yoksa ekle
try {
  $col = pdo()->query("SHOW COLUMNS FROM purchases LIKE 'paid_at'")->fetch();
  if (!$col) {
    pdo()->exec("ALTER TABLE purchases ADD COLUMN paid_at DATETIME NULL AFTER status");
  }
} catch (Throwable $e) {
  // loglamak isterseniz:
  // error_log('paid_at alter failed: '.$e->getMessage());
}

  /* performans indexleri */
  try { pdo()->exec("CREATE INDEX idx_events_active_date ON events(venue_id,is_active,event_date)"); } catch(Throwable $e){}
  try { pdo()->exec("CREATE INDEX idx_uploads_evt_created ON uploads(venue_id,event_id,created_at)"); } catch(Throwable $e){}

  /* otomatik timestamp varsayılanları (uyumlu sürümlerde) */
  foreach(['users','venues','events','uploads','qr_codes','campaigns','purchases','settings'] as $t){
    try{ pdo()->exec("ALTER TABLE $t MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"); }catch(Throwable $e){}
    try{ if(column_exists($t,'updated_at')) pdo()->exec("ALTER TABLE $t MODIFY updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); }catch(Throwable $e){}
  }

  try {
    $st = $pdo->prepare("REPLACE INTO app_meta (meta_key, meta_value, updated_at) VALUES ('schema_version', ?, ?)");
    $st->execute([APP_SCHEMA_VERSION, date('Y-m-d H:i:s')]);
  } catch (Throwable $e) {}
}
  if (!column_exists('dealers', 'balance_cents')) {
    pdo()->exec("ALTER TABLE dealers ADD balance_cents INT NOT NULL DEFAULT 0 AFTER last_login_at");
  }

