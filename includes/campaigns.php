<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/order_helpers.php';

install_schema();

site_campaign_try_create_tables();

function site_campaign_try_create_tables(): void {
  static $attempted = false;
  if ($attempted) {
    return;
  }
  $attempted = true;

  try {
    $pdo = pdo();
  } catch (Throwable $e) {
    return;
  }

  $jsonMeta = supports_json() ? 'JSON' : 'LONGTEXT';

  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_campaigns(
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
  } catch (Throwable $e) {
    // ignore - existence checks below will handle failures
  }

  if (table_exists('site_campaigns')) {
    try {
      if (!column_exists('site_campaigns', 'detail')) {
        $pdo->exec('ALTER TABLE site_campaigns ADD detail LONGTEXT NULL AFTER summary');
      }
    } catch (Throwable $e) {}

    try {
      if (!column_exists('site_campaigns', 'image_path')) {
        $pdo->exec('ALTER TABLE site_campaigns ADD image_path VARCHAR(255) NULL AFTER price_cents');
      }
    } catch (Throwable $e) {}

    try {
      if (!column_exists('site_campaigns', 'display_order')) {
        $pdo->exec('ALTER TABLE site_campaigns ADD display_order INT NOT NULL DEFAULT 0 AFTER is_active');
      }
    } catch (Throwable $e) {}
  }

  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_order_campaigns(
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
  } catch (Throwable $e) {}

  if (table_exists('site_order_campaigns')) {
    try {
      if (!column_exists('site_order_campaigns', 'meta_json')) {
        $pdo->exec("ALTER TABLE site_order_campaigns ADD meta_json $jsonMeta NULL AFTER total_cents");
      }
    } catch (Throwable $e) {}
  }
}

function site_campaign_tables_ready(): bool {
  static $catalogReady = null;
  if ($catalogReady === null) {
    site_campaign_try_create_tables();
    try {
      $catalogReady = table_exists('site_campaigns');
    } catch (Throwable $e) {
      $catalogReady = false;
    }
  }
  return $catalogReady;
}

function site_campaign_order_table_ready(): bool {
  static $orderReady = null;
  if ($orderReady === null) {
    site_campaign_try_create_tables();
    try {
      $orderReady = table_exists('site_order_campaigns');
    } catch (Throwable $e) {
      $orderReady = false;
    }
  }
  return $orderReady;
}

function site_campaign_upload_dir(): string {
  $dir = __DIR__.'/../uploads/campaigns';
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
  return $dir;
}

function site_campaign_store_upload(array $file): string {
  if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
    throw new RuntimeException('Geçerli bir görsel dosyası seçin.');
  }

  $allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
  ];

  $mime = $file['type'] ?? '';
  if (function_exists('mime_content_type')) {
    $detected = @mime_content_type($file['tmp_name']);
    if ($detected) {
      $mime = $detected;
    }
  }
  $mime = strtolower((string)$mime);
  $ext = $allowed[$mime] ?? null;

  if (!$ext) {
    $nameExt = strtolower((string)pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (in_array($nameExt, array_values($allowed), true)) {
      $ext = $nameExt;
    }
  }

  if (!$ext) {
    throw new RuntimeException('Görsel formatı desteklenmiyor. (jpg, png, webp, gif)');
  }

  $dir = site_campaign_upload_dir();
  $safeName = bin2hex(random_bytes(10)).'.'.$ext;
  $dest = $dir.'/'.$safeName;
  if (!@move_uploaded_file($file['tmp_name'], $dest)) {
    throw new RuntimeException('Görsel kaydedilemedi.');
  }

  return 'uploads/campaigns/'.$safeName;
}

function site_campaign_delete_file(?string $path): void {
  if (!$path) {
    return;
  }
  $relative = ltrim($path, '/');
  $full = __DIR__.'/../'.$relative;
  if (!is_file($full)) {
    return;
  }
  $root = realpath(site_campaign_upload_dir());
  $real = realpath($full);
  if ($root && $real && strpos($real, $root) === 0) {
    @unlink($real);
  }
}

function site_campaign_normalize(array $row): array {
  $row['id'] = (int)$row['id'];
  $row['price_cents'] = (int)$row['price_cents'];
  $row['is_active'] = (int)$row['is_active'];
  $row['display_order'] = (int)$row['display_order'];
  if (!empty($row['image_path'])) {
    $row['image_url'] = BASE_URL.'/'.ltrim($row['image_path'], '/');
  } else {
    $row['image_url'] = null;
  }
  return $row;
}

function site_campaign_all(bool $onlyActive = true): array {
  if (!site_campaign_tables_ready()) {
    return [];
  }
  $sql = 'SELECT * FROM site_campaigns';
  $conds = [];
  if ($onlyActive) {
    $conds[] = 'is_active=1';
  }
  if ($conds) {
    $sql .= ' WHERE '.implode(' AND ', $conds);
  }
  $sql .= ' ORDER BY display_order ASC, name ASC';
  try {
    $st = pdo()->query($sql);
    $rows = $st->fetchAll();
  } catch (Throwable $e) {
    return [];
  }
  return array_map('site_campaign_normalize', $rows ?: []);
}

function site_campaign_get(int $id): ?array {
  if (!site_campaign_tables_ready()) {
    return null;
  }
  try {
    $st = pdo()->prepare('SELECT * FROM site_campaigns WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
  } catch (Throwable $e) {
    return null;
  }
  return $row ? site_campaign_normalize($row) : null;
}

function site_campaign_find_by_slug(string $slug): ?array {
  $slug = trim($slug);
  if ($slug === '') {
    return null;
  }
  if (!site_campaign_tables_ready()) {
    return null;
  }
  try {
    $st = pdo()->prepare('SELECT * FROM site_campaigns WHERE slug=? LIMIT 1');
    $st->execute([$slug]);
    $row = $st->fetch();
  } catch (Throwable $e) {
    return null;
  }
  return $row ? site_campaign_normalize($row) : null;
}

function site_campaign_save(array $input, ?int $id = null): int {
  $name = trim($input['name'] ?? '');
  if ($name === '') {
    throw new RuntimeException('Kampanya adı zorunludur.');
  }

  $priceCents = isset($input['price_cents']) ? (int)$input['price_cents'] : money_to_cents((string)($input['price'] ?? '0'));
  if ($priceCents < 0) {
    $priceCents = 0;
  }

  $slug = trim($input['slug'] ?? '');
  if ($slug === '') {
    $slug = slugify($name);
  }

  $summary = trim($input['summary'] ?? '');
  $detail = trim($input['detail'] ?? '');
  $imagePath = trim($input['image_path'] ?? '');
  $displayOrder = isset($input['display_order']) ? (int)$input['display_order'] : 0;
  $isActive = !empty($input['is_active']) ? 1 : 0;

  if (!site_campaign_tables_ready()) {
    throw new RuntimeException('Kampanya tabloları oluşturulamadı. Lütfen daha sonra tekrar deneyin.');
  }

  $pdo = pdo();
  $now = now();

  if ($id) {
    $existing = site_campaign_get($id);
    if (!$existing) {
      throw new RuntimeException('Kampanya kaydı bulunamadı.');
    }
    $st = $pdo->prepare('UPDATE site_campaigns SET name=?, slug=?, summary=?, detail=?, price_cents=?, image_path=?, is_active=?, display_order=?, updated_at=? WHERE id=?');
    $st->execute([
      $name,
      $slug,
      $summary !== '' ? $summary : null,
      $detail !== '' ? $detail : null,
      $priceCents,
      $imagePath !== '' ? $imagePath : null,
      $isActive,
      $displayOrder,
      $now,
      $id,
    ]);
    return $id;
  }

  $st = $pdo->prepare('INSERT INTO site_campaigns (name, slug, summary, detail, price_cents, image_path, is_active, display_order, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
  $st->execute([
    $name,
    $slug,
    $summary !== '' ? $summary : null,
    $detail !== '' ? $detail : null,
    $priceCents,
    $imagePath !== '' ? $imagePath : null,
    $isActive,
    $displayOrder,
    $now,
    $now,
  ]);
  return (int)$pdo->lastInsertId();
}

function site_campaign_delete(int $id): void {
  if (!site_campaign_tables_ready()) {
    return;
  }
  $campaign = site_campaign_get($id);
  if (!$campaign) {
    return;
  }
  try {
    pdo()->prepare('DELETE FROM site_campaigns WHERE id=?')->execute([$id]);
    if (!empty($campaign['image_path'])) {
      site_campaign_delete_file($campaign['image_path']);
    }
  } catch (Throwable $e) {
    throw new RuntimeException('Kampanya siparişlerde kullanıldığı için silinemedi. Pasif hale getirebilirsiniz.');
  }
}

function site_order_campaigns_list(int $orderId): array {
  if (!site_campaign_order_table_ready()) {
    return [];
  }
  try {
    $st = pdo()->prepare('SELECT * FROM site_order_campaigns WHERE order_id=? ORDER BY id ASC');
    $st->execute([$orderId]);
  } catch (Throwable $e) {
    return [];
  }
  $rows = $st->fetchAll();
  if (!$rows) {
    return [];
  }
  return array_map(static function (array $row): array {
    $row['id'] = (int)$row['id'];
    $row['order_id'] = (int)$row['order_id'];
    $row['campaign_id'] = (int)$row['campaign_id'];
    $row['price_cents'] = (int)$row['price_cents'];
    $row['quantity'] = (int)$row['quantity'];
    $row['total_cents'] = (int)$row['total_cents'];
    if (isset($row['meta_json'])) {
      $meta = $row['meta_json'];
      unset($row['meta_json']);
      $row['meta'] = $meta ? (safe_json_decode($meta) ?: []) : [];
    } else {
      $row['meta'] = [];
    }
    if (!empty($row['meta']['image_path'])) {
      $row['image_path'] = $row['meta']['image_path'];
      $row['image_url'] = BASE_URL.'/'.ltrim($row['image_path'], '/');
    }
    if (!empty($row['meta']['detail'])) {
      $row['detail'] = $row['meta']['detail'];
    }
    return $row;
  }, $rows);
}

function site_order_sync_campaigns(int $orderId, array $selectedCampaigns): void {
  if (!site_campaign_order_table_ready()) {
    return;
  }
  if (!site_campaign_tables_ready()) {
    return;
  }
  $pdo = pdo();
  $pdo->beginTransaction();

  try {
    $pdo->prepare('DELETE FROM site_order_campaigns WHERE order_id=?')->execute([$orderId]);
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }

  $total = 0;
  $now = now();

  foreach ($selectedCampaigns as $campaignId => $quantity) {
    $campaign = site_campaign_get((int)$campaignId);
    if (!$campaign || !$campaign['is_active']) {
      continue;
    }
    $qty = max(1, (int)$quantity);
    $lineTotal = $campaign['price_cents'] * $qty;
    $total += $lineTotal;
    $meta = [];
    if (!empty($campaign['image_path'])) {
      $meta['image_path'] = $campaign['image_path'];
    }
    if (!empty($campaign['detail'])) {
      $meta['detail'] = $campaign['detail'];
    }
    $pdo->prepare('INSERT INTO site_order_campaigns (order_id, campaign_id, campaign_name, campaign_summary, price_cents, quantity, total_cents, meta_json, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([
          $orderId,
          (int)$campaign['id'],
          $campaign['name'],
          $campaign['summary'] ?? null,
          $campaign['price_cents'],
          $qty,
          $lineTotal,
          $meta ? safe_json_encode($meta) : null,
          $now,
        ]);
  }

  site_order_recalculate_totals($orderId, null, $total);

  $pdo->commit();
}

function site_order_campaigns_total(int $orderId): int {
  if (!site_campaign_order_table_ready()) {
    return 0;
  }
  try {
    $st = pdo()->prepare('SELECT COALESCE(SUM(total_cents),0) FROM site_order_campaigns WHERE order_id=?');
    $st->execute([$orderId]);
    return (int)$st->fetchColumn();
  } catch (Throwable $e) {
    return 0;
  }
}
