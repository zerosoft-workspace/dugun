<?php
/**
 * includes/listings.php — Bayi ilan sistemi yardımcıları
 */
require_once __DIR__.'/../config.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/dealers.php';

const LISTING_STATUS_DRAFT    = 'draft';
const LISTING_STATUS_PENDING  = 'pending';
const LISTING_STATUS_APPROVED = 'approved';
const LISTING_STATUS_REJECTED = 'rejected';
const LISTING_STATUS_ARCHIVED = 'archived';

const LISTING_CATEGORY_REQUEST_PENDING  = 'pending';
const LISTING_CATEGORY_REQUEST_APPROVED = 'approved';
const LISTING_CATEGORY_REQUEST_REJECTED = 'rejected';

const LISTING_MIN_PACKAGES = 3;
const LISTING_MAX_PACKAGES = 5;

function listing_seed_default_categories(): void {
  static $seeded = false;
  if ($seeded) {
    return;
  }
  $seeded = true;
  try {
    if (!table_exists('listing_categories')) {
      return;
    }
    $count = (int)pdo()->query("SELECT COUNT(*) FROM listing_categories")->fetchColumn();
    if ($count > 0) {
      return;
    }
  } catch (Throwable $e) {
    return;
  }

  $defaults = [
    ['name' => 'Düğün Salonları',      'description' => 'Düğün, nişan, kına ve özel gün davet alanları'],
    ['name' => 'Organizasyon Ajansları','description' => 'Etkinlik ve davet organizasyon desteği sunan ajanslar'],
    ['name' => 'Fotoğraf & Video',     'description' => 'Profesyonel çekim, kurgu ve yayın ekipleri'],
    ['name' => 'Tur & Balayı',         'description' => 'Balayı ve etkinlik sonrası tatil paketleri sunan firmalar'],
    ['name' => 'Catering & İkram',     'description' => 'Yiyecek-içecek ve catering çözümleri sağlayan bayiler'],
  ];

  $now = now();
  $pdo = pdo();
  $insert = $pdo->prepare("INSERT INTO listing_categories (name, slug, description, is_active, created_at, updated_at) VALUES (?,?,?,?,?,?)");
  foreach ($defaults as $category) {
    $slug = listing_category_unique_slug($category['name']);
    $insert->execute([
      $category['name'],
      $slug,
      $category['description'],
      1,
      $now,
      $now,
    ]);
  }
}

function listing_category_unique_slug(string $name, ?int $excludeId = null): string {
  $base = slugify($name);
  if ($base === '') {
    $base = 'kategori-'.bin2hex(random_bytes(4));
  }
  $base = substr($base, 0, 160);
  $slug = $base;
  $pdo = pdo();
  $i = 1;
  while (true) {
    $params = [$slug];
    $sql = "SELECT id FROM listing_categories WHERE slug=?";
    if ($excludeId) {
      $sql .= " AND id<>?";
      $params[] = $excludeId;
    }
    $sql .= " LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    if (!$st->fetchColumn()) {
      return $slug;
    }
    $slug = substr($base, 0, 150).'-'.(++$i);
  }
}

function listing_category_all(bool $onlyActive = false): array {
  listing_seed_default_categories();
  try {
    if (!table_exists('listing_categories')) {
      return [];
    }
    $sql = "SELECT id, name, slug, description, is_active, created_at, updated_at FROM listing_categories";
    if ($onlyActive) {
      $sql .= " WHERE is_active=1";
    }
    $sql .= " ORDER BY name";
    return pdo()->query($sql)->fetchAll();
  } catch (Throwable $e) {
    return [];
  }
}

function listing_category_get(int $id): ?array {
  try {
    $st = pdo()->prepare("SELECT * FROM listing_categories WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  } catch (Throwable $e) {
    return null;
  }
}

function listing_category_save(array $data, ?int $id = null): int {
  $name = trim($data['name'] ?? '');
  if ($name === '') {
    throw new InvalidArgumentException('Kategori adı boş bırakılamaz.');
  }
  $description = trim($data['description'] ?? '');
  $isActive = isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1;
  $now = now();
  $pdo = pdo();
  if ($id) {
    $slug = listing_category_unique_slug($name, $id);
    $st = $pdo->prepare("UPDATE listing_categories SET name=?, slug=?, description=?, is_active=?, updated_at=? WHERE id=?");
    $st->execute([$name, $slug, $description ?: null, $isActive, $now, $id]);
    return $id;
  }
  $slug = listing_category_unique_slug($name);
  $st = $pdo->prepare("INSERT INTO listing_categories (name, slug, description, is_active, created_at, updated_at) VALUES (?,?,?,?,?,?)");
  $st->execute([$name, $slug, $description ?: null, $isActive, $now, $now]);
  return (int)$pdo->lastInsertId();
}

function listing_category_toggle(int $id, bool $active): void {
  $st = pdo()->prepare("UPDATE listing_categories SET is_active=?, updated_at=? WHERE id=?");
  $st->execute([$active ? 1 : 0, now(), $id]);
}

function listing_category_request_submit(int $dealerId, string $name, string $details = ''): int {
  if (!table_exists('listing_category_requests')) {
    throw new RuntimeException('Kategori talep sistemi hazır değil.');
  }
  $name = trim($name);
  if ($name === '') {
    throw new InvalidArgumentException('Kategori adı yazılmalıdır.');
  }
  $details = trim($details);
  $now = now();
  $st = pdo()->prepare("INSERT INTO listing_category_requests (dealer_id, name, details, status, created_at, updated_at) VALUES (?,?,?,?,?,?)");
  $st->execute([$dealerId, $name, $details ?: null, LISTING_CATEGORY_REQUEST_PENDING, $now, $now]);
  return (int)pdo()->lastInsertId();
}

function listing_category_requests(string $status = LISTING_CATEGORY_REQUEST_PENDING): array {
  if (!table_exists('listing_category_requests')) {
    return [];
  }
  $sql = "SELECT r.*, d.name AS dealer_name, d.company AS dealer_company FROM listing_category_requests r JOIN dealers d ON d.id=r.dealer_id";
  $params = [];
  if ($status !== 'all') {
    $sql .= " WHERE r.status=?";
    $params[] = $status;
  }
  $sql .= " ORDER BY r.created_at DESC";
  $st = pdo()->prepare($sql);
  $st->execute($params);
  return $st->fetchAll();
}

function listing_category_request_update(int $requestId, string $status, array $options = []): void {
  if (!in_array($status, [LISTING_CATEGORY_REQUEST_PENDING, LISTING_CATEGORY_REQUEST_APPROVED, LISTING_CATEGORY_REQUEST_REJECTED], true)) {
    throw new InvalidArgumentException('Geçersiz talep durumu.');
  }
  $request = listing_category_request_find($requestId);
  if (!$request) {
    throw new RuntimeException('Kategori talebi bulunamadı.');
  }
  $pdo = pdo();
  $now = now();
  $note = trim($options['note'] ?? '');
  $adminId = isset($options['admin_id']) ? (int)$options['admin_id'] : null;
  $pdo->prepare("UPDATE listing_category_requests SET status=?, response_note=?, processed_by=?, processed_at=?, updated_at=? WHERE id=?")
      ->execute([$status, $note ?: null, $adminId, $status === LISTING_CATEGORY_REQUEST_PENDING ? null : $now, $now, $requestId]);

  if ($status === LISTING_CATEGORY_REQUEST_APPROVED && !empty($options['create_category'])) {
    $existing = listing_category_find_by_name($request['name']);
    if (!$existing) {
      listing_category_save([
        'name' => $request['name'],
        'description' => $request['details'] ?? '',
        'is_active' => 1,
      ]);
    }
  }
}

function listing_category_request_find(int $id): ?array {
  if (!table_exists('listing_category_requests')) {
    return null;
  }
  $st = pdo()->prepare("SELECT * FROM listing_category_requests WHERE id=? LIMIT 1");
  $st->execute([$id]);
  $row = $st->fetch();
  return $row ?: null;
}

function listing_category_find_by_name(string $name): ?array {
  if (!table_exists('listing_categories')) {
    return null;
  }
  $st = pdo()->prepare("SELECT * FROM listing_categories WHERE name=? LIMIT 1");
  $st->execute([trim($name)]);
  $row = $st->fetch();
  return $row ?: null;
}

function dealer_listing_unique_slug(string $title, ?int $excludeId = null): string {
  $base = slugify($title);
  if ($base === '') {
    $base = 'ilan-'.bin2hex(random_bytes(4));
  }
  $base = substr($base, 0, 160);
  $slug = $base;
  $pdo = pdo();
  $i = 1;
  while (true) {
    $params = [$slug];
    $sql = "SELECT id FROM dealer_listings WHERE slug=?";
    if ($excludeId) {
      $sql .= " AND id<>?";
      $params[] = $excludeId;
    }
    $sql .= " LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    if (!$st->fetchColumn()) {
      return $slug;
    }
    $slug = substr($base, 0, 150).'-'.(++$i);
  }
}

function dealer_listing_extract_packages(array $input): array {
  $names = $input['package_name'] ?? [];
  $descs = $input['package_description'] ?? [];
  $prices = $input['package_price'] ?? [];
  if (!is_array($names)) {
    $names = [];
  }
  $packages = [];
  $count = max(count($names), count($prices));
  for ($i = 0; $i < $count; $i++) {
    $name = trim((string)($names[$i] ?? ''));
    $desc = trim((string)($descs[$i] ?? ''));
    $priceRaw = (string)($prices[$i] ?? '');
    if ($name === '' && $priceRaw === '' && $desc === '') {
      continue;
    }
    if ($name === '') {
      throw new InvalidArgumentException('Paket adı boş bırakılamaz.');
    }
    $priceCents = money_to_cents($priceRaw);
    $packages[] = [
      'name' => $name,
      'description' => $desc,
      'price_cents' => $priceCents,
    ];
  }
  if (count($packages) < LISTING_MIN_PACKAGES) {
    throw new InvalidArgumentException('En az '.LISTING_MIN_PACKAGES.' paket tanımlanmalıdır.');
  }
  if (count($packages) > LISTING_MAX_PACKAGES) {
    throw new InvalidArgumentException('En fazla '.LISTING_MAX_PACKAGES.' paket eklenebilir.');
  }
  return $packages;
}

function dealer_listing_validate_category(?int $categoryId): ?int {
  if (!$categoryId) {
    throw new InvalidArgumentException('Lütfen bir kategori seçin.');
  }
  $category = listing_category_get($categoryId);
  if (!$category || !(int)$category['is_active']) {
    throw new InvalidArgumentException('Seçilen kategori kullanılamıyor.');
  }
  return (int)$category['id'];
}

function dealer_listing_save(int $dealerId, array $data, array $packages, ?int $listingId = null): array {
  if (!table_exists('dealer_listings')) {
    throw new RuntimeException('İlan tablosu hazır değil.');
  }
  $title = trim($data['title'] ?? '');
  if ($title === '') {
    throw new InvalidArgumentException('İlan başlığı yazılmalıdır.');
  }
  $summary = trim($data['summary'] ?? '');
  $description = trim($data['description'] ?? '');
  $city = trim($data['city'] ?? '');
  $district = trim($data['district'] ?? '');
  if ($city === '' || $district === '') {
    throw new InvalidArgumentException('İlan için şehir ve ilçe bilgisi zorunludur.');
  }
  $categoryId = dealer_listing_validate_category(isset($data['category_id']) ? (int)$data['category_id'] : null);

  $pdo = pdo();
  $pdo->beginTransaction();
  try {
    $now = now();
    $prevStatus = null;
    if ($listingId) {
      $st = $pdo->prepare("SELECT * FROM dealer_listings WHERE id=? AND dealer_id=? LIMIT 1");
      $st->execute([$listingId, $dealerId]);
      $existing = $st->fetch();
      if (!$existing) {
        throw new RuntimeException('İlan bulunamadı.');
      }
      $prevStatus = $existing['status'];
      $newSlug = dealer_listing_unique_slug($title, $listingId);
      $newStatus = $prevStatus;
      if (in_array($prevStatus, [LISTING_STATUS_PENDING, LISTING_STATUS_APPROVED], true)) {
        $newStatus = LISTING_STATUS_DRAFT;
      }
      $statusChanged = ($newStatus !== $prevStatus);
      $sets = [
        'title = ?',
        'slug = ?',
        'summary = ?',
        'description = ?',
        'category_id = ?',
        'city = ?',
        'district = ?',
        'status_note = NULL',
        'updated_at = ?',
      ];
      $params = [
        $title,
        $newSlug,
        $summary ?: null,
        $description ?: null,
        $categoryId,
        $city,
        $district,
        $now,
      ];
      if ($statusChanged) {
        $sets[] = 'status = ?';
        $params[] = $newStatus;
      }
      if ($statusChanged && $newStatus === LISTING_STATUS_DRAFT) {
        $sets[] = 'requested_at = NULL';
        $sets[] = 'approved_at = NULL';
        $sets[] = 'published_at = NULL';
        $sets[] = 'rejected_at = NULL';
      }
      $params[] = $listingId;
      $sql = 'UPDATE dealer_listings SET '.implode(', ', $sets).' WHERE id=?';
      $pdo->prepare($sql)->execute($params);
      $listingId = (int)$listingId;
      $currentStatus = $newStatus;
    } else {
      $slug = dealer_listing_unique_slug($title);
      $st = $pdo->prepare("INSERT INTO dealer_listings (dealer_id, category_id, title, slug, summary, description, city, district, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
      $st->execute([
        $dealerId,
        $categoryId,
        $title,
        $slug,
        $summary ?: null,
        $description ?: null,
        $city,
        $district,
        LISTING_STATUS_DRAFT,
        $now,
        $now,
      ]);
      $listingId = (int)$pdo->lastInsertId();
      $prevStatus = null;
      $currentStatus = LISTING_STATUS_DRAFT;
    }

    dealer_listing_sync_packages($listingId, $packages);

    $pdo->commit();
    return [
      'id' => $listingId,
      'status' => $currentStatus,
      'previous_status' => $prevStatus,
    ];
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}

function dealer_listing_sync_packages(int $listingId, array $packages): void {
  $pdo = pdo();
  $pdo->prepare("DELETE FROM dealer_listing_packages WHERE listing_id=?")->execute([$listingId]);
  $insert = $pdo->prepare("INSERT INTO dealer_listing_packages (listing_id, name, description, price_cents, sort_order, created_at, updated_at) VALUES (?,?,?,?,?,?,?)");
  $now = now();
  $order = 0;
  foreach ($packages as $package) {
    $insert->execute([
      $listingId,
      $package['name'],
      $package['description'] ?: null,
      (int)$package['price_cents'],
      $order++,
      $now,
      $now,
    ]);
  }
}

function dealer_listing_submit_for_review(int $listingId, int $dealerId): void {
  $pdo = pdo();
  $st = $pdo->prepare("SELECT status FROM dealer_listings WHERE id=? AND dealer_id=? LIMIT 1");
  $st->execute([$listingId, $dealerId]);
  $listing = $st->fetch();
  if (!$listing) {
    throw new RuntimeException('İlan bulunamadı.');
  }
  if (!in_array($listing['status'], [LISTING_STATUS_DRAFT, LISTING_STATUS_REJECTED], true)) {
    throw new RuntimeException('Bu ilan zaten incelemede veya yayında.');
  }
  $pkgCount = dealer_listing_package_count($listingId);
  if ($pkgCount < LISTING_MIN_PACKAGES) {
    throw new RuntimeException('İlan onaya gönderilmeden önce en az '.LISTING_MIN_PACKAGES.' paket olmalıdır.');
  }
  $pdo->prepare("UPDATE dealer_listings SET status=?, requested_at=?, status_note=NULL WHERE id=?")
      ->execute([LISTING_STATUS_PENDING, now(), $listingId]);
}

function dealer_listing_package_count(int $listingId): int {
  $st = pdo()->prepare("SELECT COUNT(*) FROM dealer_listing_packages WHERE listing_id=?");
  $st->execute([$listingId]);
  return (int)$st->fetchColumn();
}

function dealer_listings_for_dealer(int $dealerId): array {
  if (!table_exists('dealer_listings')) {
    return [];
  }
  $sql = "SELECT l.*, c.name AS category_name FROM dealer_listings l LEFT JOIN listing_categories c ON c.id=l.category_id WHERE l.dealer_id=? ORDER BY l.updated_at DESC";
  $st = pdo()->prepare($sql);
  $st->execute([$dealerId]);
  $rows = $st->fetchAll();
  if (!$rows) {
    return [];
  }
  $packages = listing_packages_group(array_column($rows, 'id'));
  foreach ($rows as &$row) {
    $row['packages'] = $packages[$row['id']] ?? [];
  }
  return $rows;
}

function dealer_listing_find_for_owner(int $dealerId, int $listingId): ?array {
  if (!table_exists('dealer_listings')) {
    return null;
  }
  $st = pdo()->prepare("SELECT l.*, c.name AS category_name FROM dealer_listings l LEFT JOIN listing_categories c ON c.id=l.category_id WHERE l.id=? AND l.dealer_id=? LIMIT 1");
  $st->execute([$listingId, $dealerId]);
  $row = $st->fetch();
  if (!$row) {
    return null;
  }
  $row['packages'] = listing_packages_group([$listingId])[$listingId] ?? [];
  return $row;
}

function dealer_listing_status_counts(int $dealerId): array {
  if (!table_exists('dealer_listings')) {
    return [];
  }
  $sql = "SELECT status, COUNT(*) AS total FROM dealer_listings WHERE dealer_id=? GROUP BY status";
  $st = pdo()->prepare($sql);
  $st->execute([$dealerId]);
  $counts = [
    LISTING_STATUS_DRAFT => 0,
    LISTING_STATUS_PENDING => 0,
    LISTING_STATUS_APPROVED => 0,
    LISTING_STATUS_REJECTED => 0,
    LISTING_STATUS_ARCHIVED => 0,
  ];
  foreach ($st->fetchAll() as $row) {
    $counts[$row['status']] = (int)$row['total'];
  }
  return $counts;
}

function listing_packages_group(array $listingIds): array {
  $listingIds = array_values(array_unique(array_map('intval', array_filter($listingIds))));
  if (!$listingIds) {
    return [];
  }
  $placeholders = implode(',', array_fill(0, count($listingIds), '?'));
  $sql = "SELECT * FROM dealer_listing_packages WHERE listing_id IN ($placeholders) ORDER BY listing_id, sort_order";
  $st = pdo()->prepare($sql);
  $st->execute($listingIds);
  $map = [];
  while ($row = $st->fetch()) {
    $lid = (int)$row['listing_id'];
    if (!isset($map[$lid])) {
      $map[$lid] = [];
    }
    $map[$lid][] = $row;
  }
  return $map;
}

function listing_category_requests_for_dealer(int $dealerId): array {
  if (!table_exists('listing_category_requests')) {
    return [];
  }
  $st = pdo()->prepare("SELECT * FROM listing_category_requests WHERE dealer_id=? ORDER BY created_at DESC");
  $st->execute([$dealerId]);
  return $st->fetchAll();
}

function listing_admin_counts(): array {
  if (!table_exists('dealer_listings')) {
    return [];
  }
  $rows = pdo()->query("SELECT status, COUNT(*) AS total FROM dealer_listings GROUP BY status")->fetchAll();
  $counts = [
    LISTING_STATUS_PENDING => 0,
    LISTING_STATUS_APPROVED => 0,
    LISTING_STATUS_REJECTED => 0,
    LISTING_STATUS_DRAFT => 0,
    LISTING_STATUS_ARCHIVED => 0,
  ];
  foreach ($rows as $row) {
    $counts[$row['status']] = (int)$row['total'];
  }
  return $counts;
}

function listing_admin_search(array $filters = []): array {
  if (!table_exists('dealer_listings')) {
    return [];
  }
  $sql = "SELECT l.*, d.name AS dealer_name, d.company AS dealer_company, d.email AS dealer_email, d.phone AS dealer_phone, d.billing_address AS dealer_billing_address, d.tax_office AS dealer_tax_office, d.tax_number AS dealer_tax_number, d.invoice_email AS dealer_invoice_email, c.name AS category_name FROM dealer_listings l JOIN dealers d ON d.id=l.dealer_id LEFT JOIN listing_categories c ON c.id=l.category_id";
  $where = [];
  $params = [];
  if (!empty($filters['status']) && $filters['status'] !== 'all') {
    $where[] = 'l.status = ?';
    $params[] = $filters['status'];
  }
  if (!empty($filters['category_id'])) {
    $where[] = 'l.category_id = ?';
    $params[] = (int)$filters['category_id'];
  }
  if (!empty($filters['dealer_id'])) {
    $where[] = 'l.dealer_id = ?';
    $params[] = (int)$filters['dealer_id'];
  }
  if (!empty($filters['q'])) {
    $where[] = '(l.title LIKE ? OR d.name LIKE ?)';
    $params[] = '%'.$filters['q'].'%';
    $params[] = '%'.$filters['q'].'%';
  }
  if ($where) {
    $sql .= ' WHERE '.implode(' AND ', $where);
  }
  $sql .= ' ORDER BY l.updated_at DESC LIMIT 200';
  $st = pdo()->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll();
  if (!$rows) {
    return [];
  }
  $packages = listing_packages_group(array_column($rows, 'id'));
  foreach ($rows as &$row) {
    $row['packages'] = $packages[$row['id']] ?? [];
  }
  return $rows;
}

function listing_admin_set_status(int $listingId, string $status, int $adminId, string $note = ''): void {
  if (!in_array($status, [LISTING_STATUS_APPROVED, LISTING_STATUS_REJECTED, LISTING_STATUS_ARCHIVED], true)) {
    throw new InvalidArgumentException('Geçersiz ilan durumu.');
  }
  $st = pdo()->prepare("SELECT * FROM dealer_listings WHERE id=? LIMIT 1");
  $st->execute([$listingId]);
  $listing = $st->fetch();
  if (!$listing) {
    throw new RuntimeException('İlan bulunamadı.');
  }
  $now = now();
  $note = trim($note);
  $fields = [
    'status' => $status,
    'status_note' => $note ?: null,
    'updated_at' => $now,
    'processed_by' => $adminId,
  ];
  $set = ['status = :status', 'status_note = :status_note', 'updated_at = :updated_at'];
  $params = [
    ':status' => $fields['status'],
    ':status_note' => $fields['status_note'],
    ':updated_at' => $fields['updated_at'],
    ':id' => $listingId,
  ];
  if (!column_exists('dealer_listings', 'processed_by')) {
    try {
      pdo()->exec("ALTER TABLE dealer_listings ADD processed_by INT NULL AFTER approved_at");
    } catch (Throwable $e) {
      // ignore
    }
  }
  $set[] = 'processed_by = :processed_by';
  $params[':processed_by'] = $fields['processed_by'];

  if ($status === LISTING_STATUS_APPROVED) {
    $set[] = 'approved_at = :approved_at';
    $set[] = 'published_at = :published_at';
    $set[] = 'rejected_at = NULL';
    $params[':approved_at'] = $now;
    $params[':published_at'] = $now;
  } elseif ($status === LISTING_STATUS_REJECTED) {
    $set[] = 'rejected_at = :rejected_at';
    $set[] = 'approved_at = NULL';
    $set[] = 'published_at = NULL';
    $params[':rejected_at'] = $now;
  } elseif ($status === LISTING_STATUS_ARCHIVED) {
    $set[] = 'published_at = NULL';
    $set[] = 'approved_at = NULL';
    $set[] = 'rejected_at = NULL';
  }
  $sql = 'UPDATE dealer_listings SET '.implode(', ', $set).' WHERE id = :id';
  $update = pdo()->prepare($sql);
  $update->execute($params);
}

function listing_public_search(array $filters = []): array {
  if (!table_exists('dealer_listings')) {
    return [];
  }
  $sql = "SELECT l.*, d.name AS dealer_name, d.company AS dealer_company, d.phone AS dealer_phone, d.email AS dealer_email, c.name AS category_name FROM dealer_listings l JOIN dealers d ON d.id=l.dealer_id LEFT JOIN listing_categories c ON c.id=l.category_id WHERE l.status='approved'";
  $params = [];
  if (!empty($filters['category_id'])) {
    $sql .= ' AND l.category_id = ?';
    $params[] = (int)$filters['category_id'];
  }
  if (!empty($filters['city'])) {
    $sql .= ' AND l.city LIKE ?';
    $params[] = $filters['city'].'%';
  }
  if (!empty($filters['district'])) {
    $sql .= ' AND l.district LIKE ?';
    $params[] = $filters['district'].'%';
  }
  if (!empty($filters['q'])) {
    $sql .= " AND (l.title LIKE ? OR l.summary LIKE ? OR d.name LIKE ?)";
    $params[] = '%'.$filters['q'].'%';
    $params[] = '%'.$filters['q'].'%';
    $params[] = '%'.$filters['q'].'%';
  }
  $sql .= ' ORDER BY l.published_at DESC, l.updated_at DESC';
  $st = pdo()->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll();
  if (!$rows) {
    return [];
  }
  $packages = listing_packages_group(array_column($rows, 'id'));
  foreach ($rows as &$row) {
    $row['packages'] = $packages[$row['id']] ?? [];
  }
  return $rows;
}

function listing_public_locations(): array {
  if (!table_exists('dealer_listings')) {
    return [];
  }
  $sql = "SELECT DISTINCT city, district FROM dealer_listings WHERE status='approved' AND city IS NOT NULL AND city<>'' ORDER BY city, district";
  $rows = pdo()->query($sql)->fetchAll();
  $locations = [];
  foreach ($rows as $row) {
    $city = $row['city'];
    $district = $row['district'];
    if (!isset($locations[$city])) {
      $locations[$city] = [];
    }
    if ($district) {
      $locations[$city][] = $district;
    }
  }
  foreach ($locations as &$districts) {
    $districts = array_values(array_unique($districts));
  }
  return $locations;
}

function listing_find_by_slug(string $slug): ?array {
  if (!table_exists('dealer_listings')) {
    return null;
  }
  $st = pdo()->prepare("SELECT l.*, c.name AS category_name, d.name AS dealer_name, d.company AS dealer_company FROM dealer_listings l JOIN dealers d ON d.id=l.dealer_id LEFT JOIN listing_categories c ON c.id=l.category_id WHERE l.slug=? LIMIT 1");
  $st->execute([$slug]);
  $row = $st->fetch();
  if (!$row) {
    return null;
  }
  $row['packages'] = listing_packages_group([$row['id']])[$row['id']] ?? [];
  return $row;
}

function listing_status_label(string $status): array {
  return match ($status) {
    LISTING_STATUS_APPROVED => ['Yayında', 'success'],
    LISTING_STATUS_PENDING => ['Onay Bekliyor', 'warning'],
    LISTING_STATUS_REJECTED => ['Reddedildi', 'danger'],
    LISTING_STATUS_ARCHIVED => ['Arşivlendi', 'secondary'],
    default => ['Taslak', 'secondary'],
  };
}
