<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';

function site_addon_normalize(array $row): array {
  $row['id'] = (int)$row['id'];
  $row['price_cents'] = (int)$row['price_cents'];
  $row['is_active'] = (int)$row['is_active'];
  $row['display_order'] = (int)$row['display_order'];
  if (isset($row['meta_json'])) {
    $meta = $row['meta_json'];
    unset($row['meta_json']);
    $row['meta'] = $meta ? (safe_json_decode($meta) ?: []) : [];
  } else {
    $row['meta'] = [];
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

  $pdo = pdo();
  $now = now();

  if ($id) {
    $exists = site_addon_get($id);
    if (!$exists) {
      throw new RuntimeException('Ek hizmet kaydı bulunamadı.');
    }
    $st = $pdo->prepare('UPDATE site_addons SET name=?, slug=?, description=?, category=?, price_cents=?, is_active=?, display_order=?, meta_json=?, updated_at=? WHERE id=?');
    $st->execute([
      $name,
      $slug,
      $description !== '' ? $description : null,
      $category !== '' ? $category : null,
      $priceCents,
      $isActive,
      $displayOrder,
      $meta ? safe_json_encode($meta) : null,
      $now,
      $id,
    ]);
    return $id;
  }

  $st = $pdo->prepare('INSERT INTO site_addons (name, slug, description, category, price_cents, is_active, display_order, meta_json, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
  $st->execute([
    $name,
    $slug,
    $description !== '' ? $description : null,
    $category !== '' ? $category : null,
    $priceCents,
    $isActive,
    $displayOrder,
    $meta ? safe_json_encode($meta) : null,
    $now,
    $now,
  ]);
  return (int)$pdo->lastInsertId();
}

function site_addon_delete(int $id): void {
  $st = pdo()->prepare('DELETE FROM site_addons WHERE id=?');
  $st->execute([$id]);
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
    $pdo->prepare('INSERT INTO site_order_addons (order_id, addon_id, addon_name, addon_description, price_cents, quantity, total_cents, meta_json, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([
          $orderId,
          (int)$addon['id'],
          $addon['name'],
          $addon['description'] ?? null,
          $addon['price_cents'],
          $qty,
          $lineTotal,
          $addon['meta'] ? safe_json_encode($addon['meta']) : null,
          $now,
        ]);
  }

  $pdo->prepare('UPDATE site_orders SET addons_total_cents=?, price_cents=base_price_cents + ?, updated_at=? WHERE id=?')
      ->execute([$total, $total, $now, $orderId]);

  $pdo->commit();
}

function site_order_addons_total(int $orderId): int {
  $st = pdo()->prepare('SELECT COALESCE(SUM(total_cents),0) FROM site_order_addons WHERE order_id=?');
  $st->execute([$orderId]);
  return (int)$st->fetchColumn();
}
