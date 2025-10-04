<?php
require_once __DIR__.'/../config.php';

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
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  /* events */
  pdo()->exec("CREATE TABLE IF NOT EXISTS events(
    id INT AUTO_INCREMENT PRIMARY KEY,
    venue_id INT NOT NULL,
    dealer_id INT NULL,
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

  try { pdo()->exec("CREATE UNIQUE INDEX uniq_events_couple_username ON events(couple_username)"); } catch(Throwable $e){}
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
}
