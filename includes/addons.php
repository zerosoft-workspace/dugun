<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/order_helpers.php';

function site_addon_upload_dir(): string {
  $dir = __DIR__.'/../uploads/addons';
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
  return $dir;
}

function site_addon_store_upload(array $file): string {
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

  $dir = site_addon_upload_dir();
  $safeName = bin2hex(random_bytes(10)).'.'.$ext;
  $dest = $dir.'/'.$safeName;
  if (!@move_uploaded_file($file['tmp_name'], $dest)) {
    throw new RuntimeException('Görsel kaydedilemedi.');
  }

  return 'uploads/addons/'.$safeName;
}

function site_addon_delete_file(?string $path): void {
  if (!$path) {
    return;
  }
  $relative = ltrim($path, '/');
  $full = __DIR__.'/../'.$relative;
  if (!is_file($full)) {
    return;
  }
  $root = realpath(site_addon_upload_dir());
  $real = realpath($full);
  if ($root && $real && strpos($real, $root) === 0) {
    @unlink($real);
  }
}

function site_addon_supports_images(): bool {
  static $supports = null;
  if ($supports !== null) {
    return $supports;
  }

  try {
    if (!table_exists('site_addons')) {
      $supports = false;
      return $supports;
    }
  } catch (Throwable $e) {
    $supports = false;
    return $supports;
  }

  try {
    if (!column_exists('site_addons', 'image_path')) {
      pdo()->exec('ALTER TABLE site_addons ADD image_path VARCHAR(255) NULL AFTER price_cents');
    }
  } catch (Throwable $e) {
    // ignore - availability checked below
  }

  try {
    $supports = column_exists('site_addons', 'image_path');
  } catch (Throwable $e) {
    $supports = false;
  }

  return $supports;
}

function site_addon_normalize(array $row): array {
  $row['id'] = (int)$row['id'];
  $row['price_cents'] = (int)$row['price_cents'];
  $row['is_active'] = (int)$row['is_active'];
  $row['display_order'] = (int)$row['display_order'];
  $row['image_path'] = isset($row['image_path']) && $row['image_path'] !== ''
    ? trim((string)$row['image_path'])
    : null;
  if (isset($row['meta_json'])) {
    $meta = $row['meta_json'];
    unset($row['meta_json']);
    $row['meta'] = $meta ? (safe_json_decode($meta) ?: []) : [];
  } else {
    $row['meta'] = [];
  }
  if (!is_array($row['meta'])) {
    $row['meta'] = [];
  }
  if (!$row['image_path'] && !empty($row['meta']['image_path'])) {
    $row['image_path'] = $row['meta']['image_path'];
  }
  if (!empty($row['image_path'])) {
    $row['image_url'] = BASE_URL.'/'.ltrim($row['image_path'], '/');
  } else {
    $row['image_url'] = null;
  }
  return $row;
}

function site_addon_all(bool $onlyActive = true): array {
  $sql = 'SELECT * FROM site_addons';
  $conds = [];
  if ($onlyActive) {
    $conds[] = 'is_active=1';
  }
  if ($conds) {
    $sql .= ' WHERE '.implode(' AND ', $conds);
  }
  $sql .= ' ORDER BY display_order ASC, name ASC';
  $st = pdo()->query($sql);
  $rows = $st->fetchAll();
  return array_map('site_addon_normalize', $rows ?: []);
}

function site_addon_get(int $id): ?array {
  $st = pdo()->prepare('SELECT * FROM site_addons WHERE id=? LIMIT 1');
  $st->execute([$id]);
  $row = $st->fetch();
  return $row ? site_addon_normalize($row) : null;
}

function site_addon_find_by_slug(string $slug): ?array {
  $slug = trim($slug);
  if ($slug === '') {
    return null;
  }
  $st = pdo()->prepare('SELECT * FROM site_addons WHERE slug=? LIMIT 1');
  $st->execute([$slug]);
  $row = $st->fetch();
  return $row ? site_addon_normalize($row) : null;
}

function site_addon_save(array $input, ?int $id = null): int {
  $name = trim($input['name'] ?? '');
  if ($name === '') {
    throw new RuntimeException('Hizmet adı zorunludur.');
  }

  $priceCents = isset($input['price_cents']) ? (int)$input['price_cents'] : money_to_cents((string)($input['price'] ?? '0'));
  if ($priceCents < 0) {
    $priceCents = 0;
  }

  $slug = trim($input['slug'] ?? '');
  if ($slug === '') {
    $slug = slugify($name);
  }

  $description = trim($input['description'] ?? '');
  $category = trim($input['category'] ?? '');
  $isActive = !empty($input['is_active']) ? 1 : 0;
  $displayOrder = isset($input['display_order']) ? (int)$input['display_order'] : 0;
  $meta = isset($input['meta']) && is_array($input['meta']) ? $input['meta'] : [];
  if (!is_array($meta)) {
    $meta = [];
  }
  $imagePath = isset($input['image_path']) ? trim((string)$input['image_path']) : null;
  if ($imagePath === '') {
    $imagePath = null;
  }

  $hasImageColumn = site_addon_supports_images();

  if ($imagePath) {
    $meta['image_path'] = $imagePath;
  } else {
    unset($meta['image_path']);
  }

  $pdo = pdo();
  $now = now();

  if ($id) {
    $exists = site_addon_get($id);
    if (!$exists) {
      throw new RuntimeException('Ek hizmet kaydı bulunamadı.');
    }
    $sets = [
      'name=?',
      'slug=?',
      'description=?',
      'category=?',
    ];
    $params = [
      $name,
      $slug,
      $description !== '' ? $description : null,
      $category !== '' ? $category : null,
    ];
    if ($hasImageColumn) {
      $sets[] = 'image_path=?';
      $params[] = $imagePath ?: null;
    }
    $sets[] = 'price_cents=?';
    $params[] = $priceCents;
    $sets[] = 'is_active=?';
    $params[] = $isActive;
    $sets[] = 'display_order=?';
    $params[] = $displayOrder;
    $sets[] = 'meta_json=?';
    $params[] = $meta ? safe_json_encode($meta) : null;
    $sets[] = 'updated_at=?';
    $params[] = $now;
    $params[] = $id;

    $sql = 'UPDATE site_addons SET '.implode(', ', $sets).' WHERE id=?';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $id;
  }

  $columns = [
    'name',
    'slug',
    'description',
    'category',
  ];
  $values = [
    $name,
    $slug,
    $description !== '' ? $description : null,
    $category !== '' ? $category : null,
  ];
  if ($hasImageColumn) {
    $columns[] = 'image_path';
    $values[] = $imagePath ?: null;
  }
  $columns = array_merge($columns, [
    'price_cents',
    'is_active',
    'display_order',
    'meta_json',
    'created_at',
    'updated_at',
  ]);
  $values = array_merge($values, [
    $priceCents,
    $isActive,
    $displayOrder,
    $meta ? safe_json_encode($meta) : null,
    $now,
    $now,
  ]);

  $placeholders = implode(', ', array_fill(0, count($columns), '?'));
  $sql = 'INSERT INTO site_addons ('.implode(', ', $columns).') VALUES ('.$placeholders.')';
  $st = $pdo->prepare($sql);
  $st->execute($values);
  return (int)$pdo->lastInsertId();
}

function site_addon_delete(int $id): void {
  $addon = site_addon_get($id);
  if (!$addon) {
    return;
  }
  $st = pdo()->prepare('DELETE FROM site_addons WHERE id=?');
  $st->execute([$id]);
  $imagePath = $addon['image_path'] ?? ($addon['meta']['image_path'] ?? null);
  if ($imagePath) {
    site_addon_delete_file($imagePath);
  }
}

function site_order_addons_list(int $orderId): array {
  $st = pdo()->prepare('SELECT * FROM site_order_addons WHERE order_id=? ORDER BY id ASC');
  $st->execute([$orderId]);
  $rows = $st->fetchAll();
  if (!$rows) {
    return [];
  }
  return array_map(static function (array $row): array {
    $row['id'] = (int)$row['id'];
    $row['order_id'] = (int)$row['order_id'];
    $row['addon_id'] = (int)$row['addon_id'];
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
    if (!is_array($row['meta'])) {
      $row['meta'] = [];
    }
    if (!empty($row['meta']['image_path'])) {
      $row['image_path'] = $row['meta']['image_path'];
      $row['image_url'] = BASE_URL.'/'.ltrim($row['image_path'], '/');
    }
    return $row;
  }, $rows);
}

function site_order_sync_addons(int $orderId, array $selectedAddons): void {
  $pdo = pdo();
  $pdo->beginTransaction();

  $pdo->prepare('DELETE FROM site_order_addons WHERE order_id=?')->execute([$orderId]);

  $total = 0;
  $now = now();

  foreach ($selectedAddons as $addonId => $quantity) {
    $addon = site_addon_get((int)$addonId);
    if (!$addon || !$addon['is_active']) {
      continue;
    }
    $qty = max(1, (int)$quantity);
    $lineTotal = $addon['price_cents'] * $qty;
    $total += $lineTotal;
    $meta = $addon['meta'];
    if (!is_array($meta)) {
      $meta = [];
    }
    if (!empty($addon['image_path'])) {
      $meta['image_path'] = $addon['image_path'];
    }
    $pdo->prepare('INSERT INTO site_order_addons (order_id, addon_id, addon_name, addon_description, price_cents, quantity, total_cents, meta_json, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([
          $orderId,
          (int)$addon['id'],
          $addon['name'],
          $addon['description'] ?? null,
          $addon['price_cents'],
          $qty,
          $lineTotal,
          $meta ? safe_json_encode($meta) : null,
          $now,
        ]);
  }

  site_order_recalculate_totals($orderId, $total, null);

  $pdo->commit();
}

function site_order_addons_total(int $orderId): int {
  $st = pdo()->prepare('SELECT COALESCE(SUM(total_cents),0) FROM site_order_addons WHERE order_id=?');
  $st->execute([$orderId]);
  return (int)$st->fetchColumn();
}
