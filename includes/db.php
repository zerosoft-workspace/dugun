<?php
require_once __DIR__.'/../config.php';

if (!defined('APP_SCHEMA_VERSION')) {
  define('APP_SCHEMA_VERSION', '20240615_01');
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

function table_exists(string $t): bool {
  $st = pdo()->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1");
  $st->execute([$t]);
  return (bool)$st->fetchColumn();
}

function ensure_site_orders_campaigns_column(): bool {
  static $cached = null;
  if ($cached !== null) {
    return $cached;
  }

  try {
    if (!table_exists('site_orders')) {
      return false;
    }

    if (!column_exists('site_orders', 'campaigns_total_cents')) {
      pdo()->exec("ALTER TABLE site_orders ADD campaigns_total_cents INT NOT NULL DEFAULT 0 AFTER addons_total_cents");
    }

    $cached = column_exists('site_orders', 'campaigns_total_cents');
  } catch (Throwable $e) {
    $cached = false;
  }

  return $cached;
}
function supports_json(): bool { try{pdo()->query("SELECT JSON_VALID('[]')");return true;}catch(Throwable){return false;} }

function ensure_schema_patches(PDO $pdo): void {
  try {
    if (table_exists('dealer_representative_commissions') && !column_exists('dealer_representative_commissions', 'approved_at')) {
      $pdo->exec("ALTER TABLE dealer_representative_commissions ADD approved_at DATETIME NULL AFTER paid_at");
    }
  } catch (Throwable $e) {
    // ignore migration errors, column will exist on fresh installs
  }
}

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

  $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings(
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value MEDIUMTEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $currentVersion = null;
  try {
    $st = $pdo->prepare("SELECT meta_value FROM app_meta WHERE meta_key='schema_version' LIMIT 1");
    $st->execute();
    $currentVersion = $st->fetchColumn() ?: null;
  } catch (Throwable $e) {
    $currentVersion = null;
  }

  $needsInstall = ($currentVersion !== APP_SCHEMA_VERSION);

  if (!$needsInstall) {
    $criticalTables = [
      'guest_profiles',
      'guest_upload_likes',
      'guest_upload_comments',
      'guest_chat_messages',
      'guest_event_notes',
      'guest_private_messages',
      'dealer_representatives',
      'dealer_representative_assignments',
      'representative_leads',
      'representative_lead_notes',
      'listing_categories',
      'dealer_listings',
      'dealer_listing_packages',
      'dealer_listing_media',
      'listing_category_requests',
      'event_wheel_entries',
      'event_quiz_questions',
      'event_quiz_answers',
      'event_quiz_attempts',
    ];
    foreach ($criticalTables as $table) {
      if (!table_exists($table)) {
        $needsInstall = true;
        break;
      }
    }
  }

  ensure_schema_patches($pdo);

  if (!$needsInstall) {
    return;
  }

  /* users */
  pdo()->exec("CREATE TABLE IF NOT EXISTS users(
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(190) NOT NULL,
    role ENUM('superadmin','admin') NOT NULL DEFAULT 'admin',
    reset_code VARCHAR(64) NULL,
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
    billing_title VARCHAR(190) NULL,
    billing_address TEXT NULL,
    tax_office VARCHAR(190) NULL,
    tax_number VARCHAR(64) NULL,
    invoice_email VARCHAR(190) NULL,
    tax_document_path VARCHAR(255) NULL,
    notes TEXT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    license_expires_at DATETIME NULL,
    password_hash VARCHAR(255) NULL,
    approved_at DATETIME NULL,
    last_login_at DATETIME NULL,
    balance_cents INT NOT NULL DEFAULT 0,
    reset_code VARCHAR(64) NULL,
    reset_expires DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  try {
    pdo()->exec("ALTER TABLE users MODIFY reset_code VARCHAR(64) NULL");
  } catch (Throwable $e) {}

  if (!column_exists('dealers', 'reset_code')) {
    pdo()->exec("ALTER TABLE dealers ADD reset_code VARCHAR(64) NULL AFTER last_login_at");
  }
  if (!column_exists('dealers', 'reset_expires')) {
    pdo()->exec("ALTER TABLE dealers ADD reset_expires DATETIME NULL AFTER reset_code");
  }
  try {
    pdo()->exec("ALTER TABLE dealers MODIFY reset_code VARCHAR(64) NULL");
  } catch (Throwable $e) {}

  if (!column_exists('dealers', 'code')) {
    pdo()->exec("ALTER TABLE dealers ADD code VARCHAR(16) NULL AFTER id");
  }
  if (!column_exists('dealers', 'billing_title')) {
    pdo()->exec("ALTER TABLE dealers ADD billing_title VARCHAR(190) NULL AFTER company");
  }
  if (!column_exists('dealers', 'billing_address')) {
    pdo()->exec("ALTER TABLE dealers ADD billing_address TEXT NULL AFTER billing_title");
  }
  if (!column_exists('dealers', 'tax_office')) {
    pdo()->exec("ALTER TABLE dealers ADD tax_office VARCHAR(190) NULL AFTER billing_address");
  }
  if (!column_exists('dealers', 'tax_number')) {
    pdo()->exec("ALTER TABLE dealers ADD tax_number VARCHAR(64) NULL AFTER tax_office");
  }
  if (!column_exists('dealers', 'invoice_email')) {
    pdo()->exec("ALTER TABLE dealers ADD invoice_email VARCHAR(190) NULL AFTER tax_number");
  }
  if (!column_exists('dealers', 'tax_document_path')) {
    pdo()->exec("ALTER TABLE dealers ADD tax_document_path VARCHAR(255) NULL AFTER invoice_email");
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

  pdo()->exec("CREATE TABLE IF NOT EXISTS dealer_qr_codes(
    id INT AUTO_INCREMENT PRIMARY KEY,
    dealer_id INT NOT NULL,
    venue_id INT NOT NULL,
    code VARCHAR(128) NOT NULL,
    target_event_id INT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    UNIQUE KEY uniq_qr_code (code),
    UNIQUE KEY uniq_dealer_venue (dealer_id, venue_id),
    INDEX idx_qr_target (target_event_id),
    FOREIGN KEY (dealer_id) REFERENCES dealers(id) ON DELETE CASCADE,
    FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE CASCADE,
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

  pdo()->exec("CREATE TABLE IF NOT EXISTS listing_categories(
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    slug VARCHAR(190) NOT NULL UNIQUE,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  pdo()->exec("CREATE TABLE IF NOT EXISTS listing_category_requests(
    id INT AUTO_INCREMENT PRIMARY KEY,
    dealer_id INT NOT NULL,
    name VARCHAR(190) NOT NULL,
    details TEXT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    response_note TEXT NULL,
    processed_by INT NULL,
    processed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    INDEX idx_listing_request_status (status),
    INDEX idx_listing_request_dealer (dealer_id, status),
    FOREIGN KEY (dealer_id) REFERENCES dealers(id) ON DELETE CASCADE,
    FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  pdo()->exec("CREATE TABLE IF NOT EXISTS dealer_listings(
    id INT AUTO_INCREMENT PRIMARY KEY,
    dealer_id INT NOT NULL,
    category_id INT NULL,
    title VARCHAR(190) NOT NULL,
    slug VARCHAR(190) NOT NULL UNIQUE,
    summary VARCHAR(255) NULL,
    description MEDIUMTEXT NULL,
    city VARCHAR(100) NOT NULL,
    district VARCHAR(100) NOT NULL,
    contact_email VARCHAR(190) NULL,
    contact_phone VARCHAR(60) NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'draft',
    status_note TEXT NULL,
    requested_at DATETIME NULL,
    approved_at DATETIME NULL,
    rejected_at DATETIME NULL,
    published_at DATETIME NULL,
    processed_by INT NULL,
    hero_image VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    INDEX idx_listing_dealer (dealer_id, status),
    INDEX idx_listing_category (category_id),
    INDEX idx_listing_status (status),
    INDEX idx_listing_city (city, district),
    FOREIGN KEY (dealer_id) REFERENCES dealers(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES listing_categories(id) ON DELETE SET NULL,
    FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  pdo()->exec("CREATE TABLE IF NOT EXISTS dealer_listing_packages(
    id INT AUTO_INCREMENT PRIMARY KEY,
    listing_id INT NOT NULL,
    name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    price_cents INT NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    INDEX idx_listing_packages_listing (listing_id),
    FOREIGN KEY (listing_id) REFERENCES dealer_listings(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  pdo()->exec("CREATE TABLE IF NOT EXISTS dealer_listing_media(
    id INT AUTO_INCREMENT PRIMARY KEY,
    listing_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    caption VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    INDEX idx_listing_media_listing (listing_id, sort_order),
    FOREIGN KEY (listing_id) REFERENCES dealer_listings(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  pdo()->exec("CREATE TABLE IF NOT EXISTS event_wheel_entries(
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    label VARCHAR(190) NOT NULL,
    weight INT NOT NULL DEFAULT 1,
    color VARCHAR(16) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    INDEX idx_wheel_event (event_id, is_active),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  pdo()->exec("CREATE TABLE IF NOT EXISTS event_quiz_questions(
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    question TEXT NOT NULL,
    status ENUM('draft','active','closed') NOT NULL DEFAULT 'draft',
    reveal_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    INDEX idx_quiz_event (event_id, status),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  pdo()->exec("CREATE TABLE IF NOT EXISTS event_quiz_answers(
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_id INT NOT NULL,
    answer_text VARCHAR(255) NOT NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    INDEX idx_quiz_answers_question (question_id, sort_order),
    FOREIGN KEY (question_id) REFERENCES event_quiz_questions(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  pdo()->exec("CREATE TABLE IF NOT EXISTS event_quiz_attempts(
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_id INT NOT NULL,
    answer_id INT NULL,
    profile_id INT NULL,
    guest_name VARCHAR(190) NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    points INT NOT NULL DEFAULT 0,
    answered_at DATETIME NOT NULL,
    INDEX idx_quiz_attempt_question (question_id, profile_id),
    INDEX idx_quiz_attempt_profile (profile_id),
    UNIQUE KEY uniq_quiz_attempt (question_id, profile_id),
    FOREIGN KEY (question_id) REFERENCES event_quiz_questions(id) ON DELETE CASCADE,
    FOREIGN KEY (answer_id) REFERENCES event_quiz_answers(id) ON DELETE SET NULL,
    FOREIGN KEY (profile_id) REFERENCES guest_profiles(id) ON DELETE SET NULL
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

  try {
    pdo()->exec("ALTER TABLE dealer_representatives MODIFY dealer_id INT NULL");
  } catch (Throwable $e) {}
  try {
    pdo()->exec("ALTER TABLE dealer_representatives ADD COLUMN assigned_at DATETIME NULL AFTER dealer_id");
  } catch (Throwable $e) {}
  try {
    pdo()->exec("UPDATE dealer_representatives SET assigned_at = COALESCE(assigned_at, created_at) WHERE dealer_id IS NOT NULL AND assigned_at IS NULL");
  } catch (Throwable $e) {}

  if (!column_exists('dealer_listings', 'contact_email')) {
    try {
      pdo()->exec("ALTER TABLE dealer_listings ADD contact_email VARCHAR(190) NULL AFTER district");
    } catch (Throwable $e) {}
  }
  if (!column_exists('dealer_listings', 'contact_phone')) {
    try {
      pdo()->exec("ALTER TABLE dealer_listings ADD contact_phone VARCHAR(60) NULL AFTER contact_email");
    } catch (Throwable $e) {}
  }
  try {
    pdo()->exec("UPDATE dealer_listings l JOIN dealers d ON d.id=l.dealer_id SET l.contact_email = COALESCE(NULLIF(l.contact_email,''), d.email), l.contact_phone = COALESCE(NULLIF(l.contact_phone,''), d.phone) WHERE l.contact_email IS NULL OR l.contact_email='' OR l.contact_phone IS NULL OR l.contact_phone=''");
  } catch (Throwable $e) {}

  pdo()->exec("CREATE TABLE IF NOT EXISTS dealer_representatives(
    id INT AUTO_INCREMENT PRIMARY KEY,
    dealer_id INT NULL,
    assigned_at DATETIME NULL,
    name VARCHAR(190) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    phone VARCHAR(64) NULL,
    password_hash VARCHAR(255) NOT NULL,
    reset_code VARCHAR(64) NULL,
    reset_expires DATETIME NULL,
    commission_rate DECIMAL(5,2) NOT NULL DEFAULT 10.00,
    status VARCHAR(16) NOT NULL DEFAULT 'active',
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    UNIQUE KEY uniq_dealer_representative (dealer_id),
    INDEX idx_representative_status (status),
    FOREIGN KEY (dealer_id) REFERENCES dealers(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  if (!column_exists('dealer_representatives', 'reset_code')) {
    pdo()->exec("ALTER TABLE dealer_representatives ADD reset_code VARCHAR(64) NULL AFTER password_hash");
  }
  if (!column_exists('dealer_representatives', 'reset_expires')) {
    pdo()->exec("ALTER TABLE dealer_representatives ADD reset_expires DATETIME NULL AFTER reset_code");
  }
  try {
    pdo()->exec("ALTER TABLE dealer_representatives MODIFY reset_code VARCHAR(64) NULL");
  } catch (Throwable $e) {}

  pdo()->exec("CREATE TABLE IF NOT EXISTS dealer_representative_assignments(
    representative_id INT NOT NULL,
    dealer_id INT NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    commission_rate DECIMAL(5,2) NULL,
    PRIMARY KEY (representative_id, dealer_id),
    UNIQUE KEY uniq_assignment_dealer (dealer_id),
    INDEX idx_assignment_rep (representative_id),
    FOREIGN KEY (representative_id) REFERENCES dealer_representatives(id) ON DELETE CASCADE,
    FOREIGN KEY (dealer_id) REFERENCES dealers(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  try {
    pdo()->exec("ALTER TABLE dealer_representative_assignments ADD COLUMN commission_rate DECIMAL(5,2) NULL AFTER assigned_at");
  } catch (Throwable $e) {}

  try {
    $migrateAssignments = $pdo->query("SELECT id, dealer_id, assigned_at, created_at, commission_rate FROM dealer_representatives WHERE dealer_id IS NOT NULL");
    $insertAssignment = $pdo->prepare("INSERT IGNORE INTO dealer_representative_assignments (representative_id, dealer_id, assigned_at, commission_rate) VALUES (?,?,?,?)");
    foreach ($migrateAssignments as $row) {
      $repId = (int)$row['id'];
      $dealerId = (int)$row['dealer_id'];
      if ($repId <= 0 || $dealerId <= 0) {
        continue;
      }
      $assignedAt = $row['assigned_at'] ?? ($row['created_at'] ?? now());
      $rate = isset($row['commission_rate']) ? number_format((float)$row['commission_rate'], 2, '.', '') : null;
      $insertAssignment->execute([$repId, $dealerId, $assignedAt, $rate]);
    }
  } catch (Throwable $e) {}

  try {
    pdo()->exec("UPDATE dealer_representative_assignments a
      INNER JOIN dealer_representatives r ON r.id = a.representative_id
      SET a.commission_rate = r.commission_rate
      WHERE a.commission_rate IS NULL");
  } catch (Throwable $e) {}

  pdo()->exec("CREATE TABLE IF NOT EXISTS dealer_representative_commissions(
    id INT AUTO_INCREMENT PRIMARY KEY,
    representative_id INT NOT NULL,
    dealer_id INT NOT NULL,
    dealer_topup_id INT NULL,
    package_purchase_id INT NULL,
    site_order_id INT NULL,
    source_type VARCHAR(32) NOT NULL DEFAULT 'package',
    source_label VARCHAR(190) NULL,
    commission_rate DECIMAL(7,4) NULL,
    amount_cents INT NOT NULL,
    commission_cents INT NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    notes VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    available_at DATETIME NULL,
    approved_at DATETIME NULL,
    paid_at DATETIME NULL,
    UNIQUE KEY uniq_commission_topup (dealer_topup_id),
    UNIQUE KEY uniq_commission_package (package_purchase_id),
    UNIQUE KEY uniq_commission_site_order (site_order_id),
    INDEX idx_commission_rep_status (representative_id, status),
    INDEX idx_commission_rep_available (representative_id, available_at),
    INDEX idx_commission_dealer (dealer_id),
    FOREIGN KEY (representative_id) REFERENCES dealer_representatives(id) ON DELETE CASCADE,
    FOREIGN KEY (dealer_id) REFERENCES dealers(id) ON DELETE CASCADE,
    FOREIGN KEY (dealer_topup_id) REFERENCES dealer_topups(id) ON DELETE CASCADE,
    FOREIGN KEY (package_purchase_id) REFERENCES dealer_package_purchases(id) ON DELETE CASCADE,
    FOREIGN KEY (site_order_id) REFERENCES site_orders(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  try {
    pdo()->exec("ALTER TABLE dealer_representative_commissions MODIFY dealer_topup_id INT NULL");
  } catch (Throwable $e) {}

  if (!column_exists('dealer_representative_commissions', 'dealer_id')) {
    try {
      pdo()->exec("ALTER TABLE dealer_representative_commissions ADD dealer_id INT NULL AFTER representative_id");
      pdo()->exec("ALTER TABLE dealer_representative_commissions ADD INDEX idx_commission_dealer (dealer_id)");
      pdo()->exec("ALTER TABLE dealer_representative_commissions ADD CONSTRAINT fk_commission_dealer FOREIGN KEY (dealer_id) REFERENCES dealers(id) ON DELETE CASCADE");
    } catch (Throwable $e) {}
  }

  if (!column_exists('dealer_representative_commissions', 'package_purchase_id')) {
    try {
      pdo()->exec("ALTER TABLE dealer_representative_commissions ADD package_purchase_id INT NULL AFTER dealer_topup_id");
      pdo()->exec("ALTER TABLE dealer_representative_commissions ADD CONSTRAINT fk_commission_package FOREIGN KEY (package_purchase_id) REFERENCES dealer_package_purchases(id) ON DELETE CASCADE");
    } catch (Throwable $e) {}
  }

  if (!column_exists('dealer_representative_commissions', 'site_order_id')) {
    try {
      pdo()->exec("ALTER TABLE dealer_representative_commissions ADD site_order_id INT NULL AFTER package_purchase_id");
      pdo()->exec("ALTER TABLE dealer_representative_commissions ADD CONSTRAINT fk_commission_order FOREIGN KEY (site_order_id) REFERENCES site_orders(id) ON DELETE CASCADE");
    } catch (Throwable $e) {}
  }

  if (!column_exists('dealer_representative_commissions', 'source_type')) {
    try {
      pdo()->exec("ALTER TABLE dealer_representative_commissions ADD source_type VARCHAR(32) NOT NULL DEFAULT 'package' AFTER site_order_id");
    } catch (Throwable $e) {}
  }

  if (!column_exists('dealer_representative_commissions', 'source_label')) {
    try {
      pdo()->exec("ALTER TABLE dealer_representative_commissions ADD source_label VARCHAR(190) NULL AFTER source_type");
    } catch (Throwable $e) {}
  }

  if (!column_exists('dealer_representative_commissions', 'commission_rate')) {
    try {
      pdo()->exec("ALTER TABLE dealer_representative_commissions ADD commission_rate DECIMAL(7,4) NULL AFTER source_label");
    } catch (Throwable $e) {}
  }

  if (!column_exists('dealer_representative_commissions', 'available_at')) {
    try {
      pdo()->exec("ALTER TABLE dealer_representative_commissions ADD available_at DATETIME NULL AFTER created_at");
    } catch (Throwable $e) {}
  }

  if (!column_exists('dealer_representative_commissions', 'approved_at')) {
    try {
      pdo()->exec("ALTER TABLE dealer_representative_commissions ADD approved_at DATETIME NULL AFTER available_at");
    } catch (Throwable $e) {}
  }

  if (column_exists('dealer_representative_commissions', 'available_at')) {
    try {
      pdo()->exec("UPDATE dealer_representative_commissions SET available_at = DATE_ADD(created_at, INTERVAL 30 DAY) WHERE available_at IS NULL");
    } catch (Throwable $e) {}
  }

  try {
    pdo()->exec("UPDATE dealer_representative_commissions c INNER JOIN dealer_package_purchases pp ON pp.id = c.package_purchase_id SET c.dealer_id = pp.dealer_id WHERE c.dealer_id IS NULL AND c.package_purchase_id IS NOT NULL");
  } catch (Throwable $e) {}
  try {
    pdo()->exec("UPDATE dealer_representative_commissions c INNER JOIN dealer_topups dt ON dt.id = c.dealer_topup_id SET c.dealer_id = dt.dealer_id WHERE c.dealer_id IS NULL AND c.dealer_topup_id IS NOT NULL");
  } catch (Throwable $e) {}
  try {
    pdo()->exec("UPDATE dealer_representative_commissions c INNER JOIN site_orders so ON so.id = c.site_order_id SET c.dealer_id = so.dealer_id WHERE c.dealer_id IS NULL AND c.site_order_id IS NOT NULL AND so.dealer_id IS NOT NULL");
  } catch (Throwable $e) {}

  if (!column_exists('dealer_representative_commissions', 'paid_at')) {
    try {
      pdo()->exec("ALTER TABLE dealer_representative_commissions ADD paid_at DATETIME NULL AFTER approved_at");
    } catch (Throwable $e) {}
  }

  try {
    pdo()->exec("ALTER TABLE dealer_representative_commissions ADD UNIQUE KEY uniq_commission_package (package_purchase_id)");
  } catch (Throwable $e) {}

  try {
    pdo()->exec("ALTER TABLE dealer_representative_commissions ADD UNIQUE KEY uniq_commission_site_order (site_order_id)");
  } catch (Throwable $e) {}

  try {
    pdo()->exec("ALTER TABLE dealer_representative_commissions ADD INDEX idx_commission_rep_available (representative_id, available_at)");
  } catch (Throwable $e) {}

  pdo()->exec("CREATE TABLE IF NOT EXISTS representative_payout_requests(
    id INT AUTO_INCREMENT PRIMARY KEY,
    representative_id INT NOT NULL,
    amount_cents INT NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    invoice_path VARCHAR(255) NULL,
    note VARCHAR(255) NULL,
    requested_at DATETIME NOT NULL,
    reviewed_at DATETIME NULL,
    reviewed_by INT NULL,
    response_note VARCHAR(255) NULL,
    FOREIGN KEY (representative_id) REFERENCES dealer_representatives(id) ON DELETE CASCADE,
    INDEX idx_rep_payout_status (status),
    INDEX idx_rep_payout_rep (representative_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  pdo()->exec("CREATE TABLE IF NOT EXISTS representative_payout_request_commissions(
    request_id INT NOT NULL,
    commission_id INT NOT NULL,
    PRIMARY KEY (request_id, commission_id),
    FOREIGN KEY (request_id) REFERENCES representative_payout_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (commission_id) REFERENCES dealer_representative_commissions(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  pdo()->exec("CREATE TABLE IF NOT EXISTS dealer_leads(
    id INT AUTO_INCREMENT PRIMARY KEY,
    dealer_id INT NOT NULL,
    representative_id INT NULL,
    name VARCHAR(190) NOT NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(64) NULL,
    company VARCHAR(190) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'new',
    source VARCHAR(64) NULL,
    notes TEXT NULL,
    last_contact_at DATETIME NULL,
    next_action_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    INDEX idx_leads_dealer_status (dealer_id, status),
    INDEX idx_leads_rep (representative_id),
    INDEX idx_leads_next (next_action_at),
    FOREIGN KEY (dealer_id) REFERENCES dealers(id) ON DELETE CASCADE,
    FOREIGN KEY (representative_id) REFERENCES dealer_representatives(id) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  pdo()->exec("CREATE TABLE IF NOT EXISTS dealer_lead_notes(
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_id INT NOT NULL,
    representative_id INT NULL,
    note TEXT NOT NULL,
    contact_type VARCHAR(32) NULL,
    next_action_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (lead_id) REFERENCES dealer_leads(id) ON DELETE CASCADE,
    FOREIGN KEY (representative_id) REFERENCES dealer_representatives(id) ON DELETE SET NULL,
    INDEX idx_lead_notes_lead (lead_id),
    INDEX idx_lead_notes_next (next_action_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  pdo()->exec("CREATE TABLE IF NOT EXISTS representative_leads(
    id INT AUTO_INCREMENT PRIMARY KEY,
    representative_id INT NOT NULL,
    name VARCHAR(190) NOT NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(64) NULL,
    company VARCHAR(190) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'new',
    source VARCHAR(64) NULL,
    potential_value_cents INT NULL,
    notes TEXT NULL,
    last_contact_at DATETIME NULL,
    next_action_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    INDEX idx_rep_leads_rep (representative_id),
    INDEX idx_rep_leads_status (representative_id, status),
    INDEX idx_rep_leads_next (representative_id, next_action_at),
    FOREIGN KEY (representative_id) REFERENCES dealer_representatives(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  pdo()->exec("CREATE TABLE IF NOT EXISTS representative_lead_notes(
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_id INT NOT NULL,
    representative_id INT NOT NULL,
    note TEXT NOT NULL,
    contact_type VARCHAR(32) NULL,
    next_action_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (lead_id) REFERENCES representative_leads(id) ON DELETE CASCADE,
    FOREIGN KEY (representative_id) REFERENCES dealer_representatives(id) ON DELETE CASCADE,
    INDEX idx_rep_lead_notes_lead (lead_id),
    INDEX idx_rep_lead_notes_next (representative_id, next_action_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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
    base_price_cents INT NOT NULL DEFAULT 0,
    addons_total_cents INT NOT NULL DEFAULT 0,
    campaigns_total_cents INT NOT NULL DEFAULT 0,
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
  if (!column_exists('site_orders', 'base_price_cents')) {
    pdo()->exec("ALTER TABLE site_orders ADD base_price_cents INT NOT NULL DEFAULT 0 AFTER price_cents");
    pdo()->exec("UPDATE site_orders SET base_price_cents = price_cents WHERE base_price_cents = 0");
  }
  if (!column_exists('site_orders', 'addons_total_cents')) {
    pdo()->exec("ALTER TABLE site_orders ADD addons_total_cents INT NOT NULL DEFAULT 0 AFTER base_price_cents");
  }
  if (!column_exists('site_orders', 'campaigns_total_cents')) {
    pdo()->exec("ALTER TABLE site_orders ADD campaigns_total_cents INT NOT NULL DEFAULT 0 AFTER addons_total_cents");
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

  /* sipariş ek hizmet kataloğu */
  pdo()->exec("CREATE TABLE IF NOT EXISTS site_addons(
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    slug VARCHAR(190) NOT NULL UNIQUE,
    description TEXT NULL,
    detail LONGTEXT NULL,
    category VARCHAR(120) NULL,
    price_cents INT NOT NULL DEFAULT 0,
    image_path VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    meta_json $jsonMeta NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  if (!column_exists('site_addons', 'image_path')) {
    try {
      pdo()->exec('ALTER TABLE site_addons ADD image_path VARCHAR(255) NULL AFTER price_cents');
    } catch (Throwable $e) {}
  }

  if (!column_exists('site_addons', 'detail')) {
    try {
      pdo()->exec('ALTER TABLE site_addons ADD detail LONGTEXT NULL AFTER description');
    } catch (Throwable $e) {}
  }

  /* ek hizmet varyantları */
  pdo()->exec("CREATE TABLE IF NOT EXISTS site_addon_variants(
    id INT AUTO_INCREMENT PRIMARY KEY,
    addon_id INT NOT NULL,
    name VARCHAR(190) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    description TEXT NULL,
    detail LONGTEXT NULL,
    price_cents INT NOT NULL DEFAULT 0,
    image_path VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    meta_json $jsonMeta NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    UNIQUE KEY uniq_addon_variant_slug (addon_id, slug),
    FOREIGN KEY (addon_id) REFERENCES site_addons(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  if (!column_exists('site_addon_variants', 'detail')) {
    try {
      pdo()->exec('ALTER TABLE site_addon_variants ADD detail LONGTEXT NULL AFTER description');
    } catch (Throwable $e) {}
  }

  if (!column_exists('site_addon_variants', 'image_path')) {
    try {
      pdo()->exec('ALTER TABLE site_addon_variants ADD image_path VARCHAR(255) NULL AFTER price_cents');
    } catch (Throwable $e) {}
  }

  try {
    pdo()->exec('ALTER TABLE site_addon_variants ADD UNIQUE KEY uniq_addon_variant_slug (addon_id, slug)');
  } catch (Throwable $e) {}

  /* sosyal sorumluluk kampanyası kataloğu */
  pdo()->exec("CREATE TABLE IF NOT EXISTS site_campaigns(
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    slug VARCHAR(190) NOT NULL UNIQUE,
    summary TEXT NULL,
    detail LONGTEXT NULL,
    price_cents INT NOT NULL DEFAULT 0,
    image_path VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  /* sipariş ek hizmet seçimleri */
  pdo()->exec("CREATE TABLE IF NOT EXISTS site_order_addons(
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    addon_id INT NOT NULL,
    addon_name VARCHAR(190) NOT NULL,
    addon_description TEXT NULL,
    variant_id INT NULL,
    variant_name VARCHAR(190) NULL,
    variant_price_cents INT NULL DEFAULT 0,
    price_cents INT NOT NULL DEFAULT 0,
    quantity INT NOT NULL DEFAULT 1,
    total_cents INT NOT NULL DEFAULT 0,
    meta_json $jsonMeta NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (order_id) REFERENCES site_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (addon_id) REFERENCES site_addons(id) ON DELETE RESTRICT,
    FOREIGN KEY (variant_id) REFERENCES site_addon_variants(id) ON DELETE SET NULL,
    INDEX idx_site_order_addons_order (order_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  if (!column_exists('site_order_addons', 'variant_id')) {
    try {
      pdo()->exec('ALTER TABLE site_order_addons ADD variant_id INT NULL AFTER addon_description');
    } catch (Throwable $e) {}
  }

  if (!column_exists('site_order_addons', 'variant_name')) {
    try {
      pdo()->exec('ALTER TABLE site_order_addons ADD variant_name VARCHAR(190) NULL AFTER variant_id');
    } catch (Throwable $e) {}
  }

  if (!column_exists('site_order_addons', 'variant_price_cents')) {
    try {
      pdo()->exec('ALTER TABLE site_order_addons ADD variant_price_cents INT NULL DEFAULT 0 AFTER variant_name');
    } catch (Throwable $e) {}
  }

  /* sipariş kampanya seçimleri */
  pdo()->exec("CREATE TABLE IF NOT EXISTS site_order_campaigns(
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    campaign_id INT NOT NULL,
    campaign_name VARCHAR(190) NOT NULL,
    campaign_summary TEXT NULL,
    price_cents INT NOT NULL DEFAULT 0,
    quantity INT NOT NULL DEFAULT 1,
    total_cents INT NOT NULL DEFAULT 0,
    meta_json $jsonMeta NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (order_id) REFERENCES site_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (campaign_id) REFERENCES site_campaigns(id) ON DELETE RESTRICT,
    INDEX idx_site_order_campaigns_order (order_id)
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

  pdo()->exec("CREATE TABLE IF NOT EXISTS guest_event_notes(
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    profile_id INT NULL,
    guest_name VARCHAR(190) NULL,
    guest_email VARCHAR(190) NULL,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_guest_event_notes (event_id, created_at),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (profile_id) REFERENCES guest_profiles(id) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  pdo()->exec("CREATE TABLE IF NOT EXISTS guest_private_messages(
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    sender_profile_id INT NOT NULL,
    recipient_profile_id INT NULL,
    recipient_upload_id BIGINT NULL,
    recipient_email VARCHAR(190) NULL,
    recipient_name VARCHAR(190) NULL,
    body TEXT NOT NULL,
    is_for_host TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    INDEX idx_guest_pm_event (event_id, created_at),
    INDEX idx_guest_pm_recipient (recipient_profile_id),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_profile_id) REFERENCES guest_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_profile_id) REFERENCES guest_profiles(id) ON DELETE SET NULL,
    FOREIGN KEY (recipient_upload_id) REFERENCES uploads(id) ON DELETE SET NULL
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

