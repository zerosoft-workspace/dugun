<?php
// admin/marketing_contacts.php — Pazarlama & mailing listesi yönetimi
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/sms.php';
require_once __DIR__.'/../includes/whatsapp.php';
require_once __DIR__.'/partials/ui.php';

require_admin();
require_admin_permission('marketing');
install_schema();

function format_local_datetime(?string $value): string {
  if (!$value) {
    return '—';
  }
  try {
    $dt = new DateTime($value);
    return $dt->format('d.m.Y H:i');
  } catch (Throwable $e) {
    return $value;
  }
}

function format_local_date(?string $value): ?string {
  if (!$value) {
    return null;
  }
  try {
    $dt = new DateTime($value);
    return $dt->format('d.m.Y');
  } catch (Throwable $e) {
    return $value;
  }
}

function normalize_phone(string $phone): string {
  $digits = preg_replace('/\D+/', '', $phone);
  return $digits ?? '';
}

function unique_values(array $values, bool $numeric = false): array {
  $unique = [];
  $seen = [];
  foreach ($values as $value) {
    if (!is_string($value)) {
      continue;
    }
    $trimmed = trim($value);
    if ($trimmed === '') {
      continue;
    }
    $key = $numeric ? normalize_phone($trimmed) : mb_strtolower($trimmed, 'UTF-8');
    if ($key === '') {
      $key = $trimmed;
    }
    if (isset($seen[$key])) {
      continue;
    }
    $seen[$key] = true;
    $unique[] = $trimmed;
  }
  return $unique;
}

function parse_list_input(string $raw, bool $numeric = false): array {
  if ($raw === '') {
    return [];
  }
  $parts = preg_split('/[\s,;]+/u', $raw);
  if ($parts === false) {
    return [];
  }
  return unique_values($parts, $numeric);
}

function build_email_html(string $body): string {
  $content = nl2br(h($body));
  $footer = '<p style="margin-top:24px;font-size:13px;color:#64748b;">'.h(APP_NAME).' Pazarlama Ekibi</p>';
  return '<div style="font-family:Inter,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#0f172a;">'.$content.$footer.'</div>';
}

function summarize_list(array $items, int $limit = 5): string {
  if ($items === []) {
    return '';
  }
  $slice = array_slice($items, 0, $limit);
  $text = implode(', ', $slice);
  if (count($items) > $limit) {
    $text .= '…';
  }
  return $text;
}

function marketing_decode_attachments($value): array {
  if (is_array($value)) {
    return $value;
  }
  if (!is_string($value) || trim($value) === '') {
    return [];
  }
  $decoded = json_decode($value, true);
  if (!is_array($decoded)) {
    return [];
  }
  $files = [];
  foreach ($decoded as $row) {
    if (!is_array($row)) {
      continue;
    }
    $name = isset($row['name']) && is_string($row['name']) ? $row['name'] : null;
    $path = isset($row['path']) && is_string($row['path']) ? $row['path'] : null;
    if ($name === null || $path === null) {
      continue;
    }
    $files[] = [
      'name' => $name,
      'path' => $path,
      'size' => isset($row['size']) ? (int)$row['size'] : null,
      'mime' => isset($row['mime']) && is_string($row['mime']) ? $row['mime'] : null,
    ];
  }
  return $files;
}

function marketing_format_filesize(?int $bytes): string {
  if ($bytes === null || $bytes <= 0) {
    return '';
  }
  if ($bytes >= 1048576) {
    return round($bytes / 1048576, 1).' MB';
  }
  if ($bytes >= 1024) {
    return round($bytes / 1024, 1).' KB';
  }
  return $bytes.' B';
}

function marketing_target_status_options(): array {
  return [
    'pending' => 'Beklemede',
    'sent'    => 'Gönderildi',
    'failed'  => 'Hata',
    'skipped' => 'Atlandı',
  ];
}

function marketing_target_status_meta(string $status): array {
  $map = [
    'sent'    => ['label' => 'Gönderildi', 'class' => 'success'],
    'failed'  => ['label' => 'Hata',       'class' => 'danger'],
    'pending' => ['label' => 'Beklemede',  'class' => 'warning'],
    'skipped' => ['label' => 'Atlandı',    'class' => 'secondary'],
  ];
  return $map[$status] ?? ['label' => ucfirst($status), 'class' => 'secondary'];
}

function marketing_channel_meta(string $channel): array {
  $map = [
    'email'    => ['label' => 'E-posta',   'icon' => 'bi-envelope',      'class' => 'primary'],
    'sms'      => ['label' => 'SMS',       'icon' => 'bi-chat-dots',     'class' => 'info'],
    'whatsapp' => ['label' => 'WhatsApp',  'icon' => 'bi-whatsapp',      'class' => 'success'],
  ];
  return $map[$channel] ?? ['label' => ucfirst($channel), 'icon' => 'bi-broadcast', 'class' => 'secondary'];
}

function marketing_normalize_uploads(?array $files): array {
  if ($files === null) {
    return [];
  }
  if (!isset($files['name'])) {
    return [];
  }
  if (is_array($files['name'])) {
    $count = count($files['name']);
    $normalized = [];
    for ($i = 0; $i < $count; $i++) {
      $normalized[] = [
        'name'     => $files['name'][$i] ?? null,
        'type'     => $files['type'][$i] ?? null,
        'tmp_name' => $files['tmp_name'][$i] ?? null,
        'error'    => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
        'size'     => $files['size'][$i] ?? 0,
      ];
    }
    return $normalized;
  }
  return [[
    'name'     => $files['name'],
    'type'     => $files['type'] ?? null,
    'tmp_name' => $files['tmp_name'] ?? null,
    'error'    => $files['error'] ?? UPLOAD_ERR_NO_FILE,
    'size'     => $files['size'] ?? 0,
  ]];
}

function marketing_store_media_uploads(?array $files): array {
  $result = ['files' => [], 'errors' => []];
  $uploads = marketing_normalize_uploads($files);
  if ($uploads === []) {
    return $result;
  }

  $dir = __DIR__.'/../uploads/marketing';
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }

  $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'm4v', 'pdf'];
  $maxSize = 25 * 1024 * 1024; // 25 MB

  foreach ($uploads as $upload) {
    $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
      continue;
    }
    $original = isset($upload['name']) && is_string($upload['name']) ? $upload['name'] : 'dosya';
    if ($error !== UPLOAD_ERR_OK) {
      $result['errors'][] = $original.' yüklenemedi (hata kodu '.$error.').';
      continue;
    }
    $size = isset($upload['size']) ? (int)$upload['size'] : 0;
    if ($size <= 0 || $size > $maxSize) {
      $result['errors'][] = $original.' dosyası izin verilen 25MB sınırını aşıyor.';
      continue;
    }
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, $allowedExtensions, true)) {
      $result['errors'][] = $original.' dosya türü desteklenmiyor.';
      continue;
    }
    $tmp = $upload['tmp_name'] ?? null;
    if (!is_string($tmp) || $tmp === '') {
      $result['errors'][] = $original.' geçici dosyası bulunamadı.';
      continue;
    }
    $token = bin2hex(random_bytes(16));
    $filename = $token.'.'.$ext;
    $dest = $dir.'/'.$filename;
    if (!@move_uploaded_file($tmp, $dest)) {
      $result['errors'][] = $original.' sunucuya taşınamadı.';
      continue;
    }
    $result['files'][] = [
      'name' => $original,
      'path' => 'marketing/'.$filename,
      'size' => $size,
      'mime' => isset($upload['type']) && is_string($upload['type']) ? $upload['type'] : null,
    ];
  }

  return $result;
}

function marketing_log_broadcast(string $channel, string $audienceKey, string $title, string $body, array $attachments, array $targets): ?int {
  $pdo = pdo();
  try {
    $pdo->beginTransaction();
    $admin = admin_user();
    $adminId = $admin['id'] ?? null;
    $attachmentsJson = $attachments !== [] ? json_encode($attachments, JSON_UNESCAPED_UNICODE) : null;
    $st = $pdo->prepare("INSERT INTO marketing_broadcasts (channel, audience_key, title, body, attachments, created_by_admin_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $st->execute([
      $channel,
      $audienceKey !== '' ? $audienceKey : null,
      $title,
      $body !== '' ? $body : null,
      $attachmentsJson,
      $adminId,
      now(),
    ]);
    $broadcastId = (int)$pdo->lastInsertId();

    if ($broadcastId > 0 && $targets !== []) {
      $insert = $pdo->prepare("INSERT INTO marketing_broadcast_targets (broadcast_id, target_name, target_email, target_phone, status, detail, sent_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
      foreach ($targets as $target) {
        $insert->execute([
          $broadcastId,
          isset($target['name']) && $target['name'] !== '' ? $target['name'] : null,
          isset($target['email']) && $target['email'] !== '' ? $target['email'] : null,
          isset($target['phone']) && $target['phone'] !== '' ? $target['phone'] : null,
          $target['status'] ?? 'pending',
          isset($target['detail']) && $target['detail'] !== '' ? $target['detail'] : null,
          isset($target['sent_at']) && $target['sent_at'] !== '' ? $target['sent_at'] : null,
          now(),
        ]);
      }
    }

    $pdo->commit();
    return $broadcastId > 0 ? $broadcastId : null;
  } catch (Throwable $e) {
    try { $pdo->rollBack(); } catch (Throwable $rollback) {}
    return null;
  }
}

function marketing_fetch_broadcast(int $id): ?array {
  try {
    $st = pdo()->prepare("SELECT b.*, u.name AS admin_name, u.email AS admin_email FROM marketing_broadcasts b LEFT JOIN users u ON u.id = b.created_by_admin_id WHERE b.id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
      return null;
    }
    $row['attachments'] = marketing_decode_attachments($row['attachments'] ?? null);
    $row['targets'] = [];
    $row['status_counts'] = ['sent' => 0, 'failed' => 0, 'pending' => 0, 'skipped' => 0];
    $targetStmt = pdo()->prepare("SELECT id, target_name, target_email, target_phone, status, detail, sent_at, created_at FROM marketing_broadcast_targets WHERE broadcast_id = ? ORDER BY created_at ASC, id ASC");
    $targetStmt->execute([$id]);
    while ($target = $targetStmt->fetch()) {
      $status = $target['status'] ?? 'pending';
      if (!isset($row['status_counts'][$status])) {
        $row['status_counts'][$status] = 0;
      }
      $row['status_counts'][$status]++;
      $row['targets'][] = $target;
    }
    $row['total_targets'] = count($row['targets']);
    return $row;
  } catch (Throwable $e) {
    return null;
  }
}

function marketing_fetch_recent_broadcasts(int $limit = 15): array {
  $limit = max(1, min(50, $limit));
  try {
    $sql = "
      SELECT b.id, b.channel, b.audience_key, b.title, b.created_at, b.attachments,
             u.name AS admin_name,
             SUM(CASE WHEN t.status = 'sent' THEN 1 ELSE 0 END)    AS sent_count,
             SUM(CASE WHEN t.status = 'failed' THEN 1 ELSE 0 END)  AS failed_count,
             SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
             SUM(CASE WHEN t.status = 'skipped' THEN 1 ELSE 0 END) AS skipped_count,
             COUNT(t.id) AS total_count
        FROM marketing_broadcasts b
        LEFT JOIN marketing_broadcast_targets t ON t.broadcast_id = b.id
        LEFT JOIN users u ON u.id = b.created_by_admin_id
       GROUP BY b.id
       ORDER BY b.created_at DESC
       LIMIT ?
    ";
    $st = pdo()->prepare($sql);
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();
    foreach ($rows as &$row) {
      $row['attachments'] = marketing_decode_attachments($row['attachments'] ?? null);
    }
    return $rows;
  } catch (Throwable $e) {
    return [];
  }
}

function marketing_broadcast_url(string $category, string $search, ?int $broadcastId): string {
  $params = ['category' => $category];
  if ($search !== '') {
    $params['q'] = $search;
  }
  if ($broadcastId) {
    $params['broadcast'] = $broadcastId;
  }
  return '?'.http_build_query($params);
}

function fetch_marketing_contacts(string $category): array {
  switch ($category) {
    case 'events':
      if (!table_exists('events')) {
        return [];
      }
      $sql = "
        SELECT e.id, e.title, e.contact_email, e.couple_phone, e.event_date, e.license_expires_at,
               e.created_at, v.name AS venue_name
          FROM events e
          LEFT JOIN venues v ON v.id = e.venue_id
         WHERE e.is_active = 1
         ORDER BY e.created_at DESC
      ";
      try {
        $rows = pdo()->query($sql)->fetchAll();
      } catch (Throwable $e) {
        return [];
      }
      $contacts = [];
      foreach ($rows as $row) {
        $email = trim($row['contact_email'] ?? '');
        $phone = trim($row['couple_phone'] ?? '');
        if ($email === '' && $phone === '') {
          continue;
        }
        $badges = [];
        if (!empty($row['venue_name'])) {
          $badges[] = 'Salon: '.trim($row['venue_name']);
        }
        if (!empty($row['event_date'])) {
          $badges[] = 'Etkinlik: '.format_local_date($row['event_date']);
        }
        if (!empty($row['license_expires_at'])) {
          $badges[] = 'Lisans: '.format_local_datetime($row['license_expires_at']);
        }
        $contacts[] = [
          'name'       => $row['title'] !== '' ? $row['title'] : ('Etkinlik #'.$row['id']),
          'email'      => $email !== '' ? $email : null,
          'phone'      => $phone !== '' ? $phone : null,
          'context'    => 'Etkinlik Sahibi',
          'badges'     => $badges,
          'created_at' => $row['created_at'] ?? null,
          'notes'      => null,
        ];
      }
      return $contacts;

    case 'dealers':
      if (!table_exists('dealers')) {
        return [];
      }
      $sql = "
        SELECT id, name, email, phone, company, status, code, created_at
          FROM dealers
         ORDER BY created_at DESC
      ";
      try {
        $rows = pdo()->query($sql)->fetchAll();
      } catch (Throwable $e) {
        return [];
      }
      $contacts = [];
      foreach ($rows as $row) {
        $email = trim($row['email'] ?? '');
        $phone = trim($row['phone'] ?? '');
        if ($email === '' && $phone === '') {
          continue;
        }
        $badges = [];
        if (!empty($row['company'])) {
          $badges[] = 'Firma: '.trim($row['company']);
        }
        if (!empty($row['status'])) {
          $badges[] = 'Durum: '.trim($row['status']);
        }
        if (!empty($row['code'])) {
          $badges[] = 'Kod: '.trim($row['code']);
        }
        $contacts[] = [
          'name'       => $row['name'] ?? 'Bayi #'.$row['id'],
          'email'      => $email !== '' ? $email : null,
          'phone'      => $phone !== '' ? $phone : null,
          'context'    => 'Bayi Hesabı',
          'badges'     => $badges,
          'created_at' => $row['created_at'] ?? null,
          'notes'      => null,
        ];
      }
      return $contacts;

    case 'representatives':
      if (!table_exists('dealer_representatives')) {
        return [];
      }
      $sql = "
        SELECT r.id, r.name, r.email, r.phone, r.status, r.created_at,
               d.name AS dealer_name
          FROM dealer_representatives r
          LEFT JOIN dealers d ON d.id = r.dealer_id
         ORDER BY r.created_at DESC
      ";
      try {
        $rows = pdo()->query($sql)->fetchAll();
      } catch (Throwable $e) {
        return [];
      }
      $contacts = [];
      foreach ($rows as $row) {
        $email = trim($row['email'] ?? '');
        $phone = trim($row['phone'] ?? '');
        if ($email === '' && $phone === '') {
          continue;
        }
        $badges = [];
        if (!empty($row['dealer_name'])) {
          $badges[] = 'Bayi: '.trim($row['dealer_name']);
        }
        if (!empty($row['status'])) {
          $badges[] = 'Durum: '.trim($row['status']);
        }
        $contacts[] = [
          'name'       => $row['name'] ?? 'Temsilci #'.$row['id'],
          'email'      => $email !== '' ? $email : null,
          'phone'      => $phone !== '' ? $phone : null,
          'context'    => 'Temsilci',
          'badges'     => $badges,
          'created_at' => $row['created_at'] ?? null,
          'notes'      => null,
        ];
      }
      return $contacts;

    case 'leads':
      if (!table_exists('representative_leads')) {
        return [];
      }
      $sql = "
        SELECT l.id, l.name, l.email, l.phone, l.company, l.status, l.source,
               l.potential_value_cents, l.created_at,
               r.name AS representative_name
          FROM representative_leads l
          LEFT JOIN dealer_representatives r ON r.id = l.representative_id
         ORDER BY l.created_at DESC
      ";
      try {
        $rows = pdo()->query($sql)->fetchAll();
      } catch (Throwable $e) {
        return [];
      }
      $contacts = [];
      foreach ($rows as $row) {
        $email = trim($row['email'] ?? '');
        $phone = trim($row['phone'] ?? '');
        if ($email === '' && $phone === '') {
          continue;
        }
        $badges = [];
        if (!empty($row['status'])) {
          $badges[] = 'Durum: '.trim($row['status']);
        }
        if (!empty($row['representative_name'])) {
          $badges[] = 'Temsilci: '.trim($row['representative_name']);
        }
        if (!empty($row['company'])) {
          $badges[] = 'Firma: '.trim($row['company']);
        }
        if (!empty($row['source'])) {
          $badges[] = 'Kaynak: '.trim($row['source']);
        }
        $notes = null;
        if (!empty($row['potential_value_cents'])) {
          $notes = 'Potansiyel değer: '.format_currency((int)$row['potential_value_cents']);
        }
        $contacts[] = [
          'name'       => $row['name'] ?? 'Lead #'.$row['id'],
          'email'      => $email !== '' ? $email : null,
          'phone'      => $phone !== '' ? $phone : null,
          'context'    => 'Satış Lead\'i',
          'badges'     => $badges,
          'created_at' => $row['created_at'] ?? null,
          'notes'      => $notes,
        ];
      }
      return $contacts;

    case 'orders':
      if (!table_exists('site_orders')) {
        return [];
      }
      $sql = "
        SELECT id, customer_name, customer_email, customer_phone, event_title,
               event_date, status, created_at
          FROM site_orders
         ORDER BY created_at DESC
      ";
      try {
        $rows = pdo()->query($sql)->fetchAll();
      } catch (Throwable $e) {
        return [];
      }
      $contacts = [];
      foreach ($rows as $row) {
        $email = trim($row['customer_email'] ?? '');
        $phone = trim($row['customer_phone'] ?? '');
        if ($email === '' && $phone === '') {
          continue;
        }
        $badges = [];
        if (!empty($row['event_title'])) {
          $badges[] = 'Etkinlik: '.trim($row['event_title']);
        }
        if (!empty($row['event_date'])) {
          $badges[] = 'Tarih: '.format_local_date($row['event_date']);
        }
        if (!empty($row['status'])) {
          $badges[] = 'Durum: '.trim($row['status']);
        }
        $contacts[] = [
          'name'       => $row['customer_name'] ?? 'Sipariş #'.$row['id'],
          'email'      => $email !== '' ? $email : null,
          'phone'      => $phone !== '' ? $phone : null,
          'context'    => 'Online Sipariş',
          'badges'     => $badges,
          'created_at' => $row['created_at'] ?? null,
          'notes'      => null,
        ];
      }
      return $contacts;

    case 'guests':
      if (!table_exists('guest_profiles')) {
        return [];
      }
      $sql = "
        SELECT gp.id, gp.name, gp.display_name, gp.email, gp.marketing_opted_at,
               gp.created_at, gp.last_seen_at,
               e.title AS event_title, e.event_date,
               v.name AS venue_name
          FROM guest_profiles gp
          LEFT JOIN events e ON e.id = gp.event_id
          LEFT JOIN venues v ON v.id = e.venue_id
         WHERE gp.marketing_opt_in = 1
           AND gp.is_host_preview = 0
         ORDER BY gp.marketing_opted_at DESC, gp.created_at DESC
      ";
      try {
        $rows = pdo()->query($sql)->fetchAll();
      } catch (Throwable $e) {
        return [];
      }
      $contacts = [];
      foreach ($rows as $row) {
        $email = trim($row['email'] ?? '');
        if ($email === '') {
          continue;
        }
        $badges = [];
        if (!empty($row['event_title'])) {
          $badges[] = 'Etkinlik: '.trim($row['event_title']);
        }
        if (!empty($row['event_date'])) {
          $badges[] = 'Tarih: '.format_local_date($row['event_date']);
        }
        if (!empty($row['venue_name'])) {
          $badges[] = 'Salon: '.trim($row['venue_name']);
        }
        if (!empty($row['last_seen_at'])) {
          $badges[] = 'Son ziyaret: '.format_local_datetime($row['last_seen_at']);
        }
        $notes = null;
        if (!empty($row['marketing_opted_at'])) {
          $notes = 'İzin: '.format_local_datetime($row['marketing_opted_at']);
        }
        $displayName = $row['display_name'] ?? $row['name'] ?? null;
        $contacts[] = [
          'name'       => $displayName && trim($displayName) !== '' ? trim($displayName) : ('Misafir #'.$row['id']),
          'email'      => $email,
          'phone'      => null,
          'context'    => 'Misafir (İzinli)',
          'badges'     => $badges,
          'created_at' => $row['created_at'] ?? null,
          'notes'      => $notes,
        ];
      }
      return $contacts;
  }

  return [];
}

$categories = [
  'events'          => ['label' => 'Etkinlik Sahipleri',        'icon' => 'bi-calendar-heart',  'description' => 'Paneli yöneten etkinlik sahiplerinin iletişim bilgileri.'],
  'dealers'         => ['label' => 'Bayiler',                    'icon' => 'bi-shop',             'description' => 'Platformdaki bayi ve çözüm ortakları.'],
  'representatives' => ['label' => 'Temsilciler',                'icon' => 'bi-person-badge',     'description' => 'Satış temsilcileri ve sorumluları.'],
  'leads'           => ['label' => 'Lead Havuzu',                'icon' => 'bi-lightning-charge', 'description' => 'Temsilci CRM üzerindeki potansiyel müşteriler.'],
  'orders'          => ['label' => 'Online Siparişler',          'icon' => 'bi-bag-check',        'description' => 'Web sitesi üzerinden paket satın alan etkinlik sahipleri.'],
  'guests'          => ['label' => 'Misafir İzinlileri',         'icon' => 'bi-people',           'description' => 'Pazarlama izni vermiş etkinlik misafirleri.'],
];

$selected = $_GET['category'] ?? 'events';
if (!isset($categories[$selected])) {
  $selected = 'events';
}

$search = trim($_GET['q'] ?? '');

$allContacts = [];
$counts = [];
foreach (array_keys($categories) as $key) {
  $contacts = fetch_marketing_contacts($key);
  $allContacts[$key] = $contacts;
  $counts[$key] = count($contacts);
}

$activeContacts = $allContacts[$selected] ?? [];

if ($search !== '') {
  $needle = mb_strtolower($search, 'UTF-8');
  $activeContacts = array_values(array_filter($activeContacts, function (array $contact) use ($needle): bool {
    $haystack = [
      $contact['name'] ?? '',
      $contact['email'] ?? '',
      $contact['phone'] ?? '',
      $contact['context'] ?? '',
      $contact['notes'] ?? '',
    ];
    foreach ($contact['badges'] ?? [] as $badge) {
      $haystack[] = $badge;
    }
    foreach ($haystack as $field) {
      if (!is_string($field)) {
        continue;
      }
      if ($field !== '' && mb_strpos(mb_strtolower($field, 'UTF-8'), $needle) !== false) {
        return true;
      }
    }
    return false;
  }));
}

$emails = unique_values(array_map(function ($contact) {
  return $contact['email'] ?? '';
}, $activeContacts));

$phones = unique_values(array_map(function ($contact) {
  return $contact['phone'] ?? '';
}, $activeContacts), true);

$emailLookup = [];
$phoneLookup = [];
foreach ($activeContacts as $contact) {
  $name = isset($contact['name']) ? trim((string)$contact['name']) : null;
  if (!empty($contact['email']) && is_string($contact['email'])) {
    $emailLookup[mb_strtolower($contact['email'], 'UTF-8')] = [
      'name'  => $name,
      'email' => $contact['email'],
      'phone' => $contact['phone'] ?? null,
    ];
  }
  if (!empty($contact['phone']) && is_string($contact['phone'])) {
    $normalizedSimple = normalize_phone($contact['phone']);
    if ($normalizedSimple !== '') {
      $phoneLookup[$normalizedSimple] = [
        'name'  => $name,
        'email' => $contact['email'] ?? null,
        'phone' => $contact['phone'],
      ];
    }
    $normalizedSms = sms_normalize_number($contact['phone']);
    if ($normalizedSms !== '') {
      $phoneLookup[$normalizedSms] = [
        'name'  => $name,
        'email' => $contact['email'] ?? null,
        'phone' => $contact['phone'],
      ];
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_or_die();
  $action = $_POST['action'] ?? '';
  $redirectTo = filter_url($selected, $search);

  if ($action === 'send_email') {
    $subject = trim((string)($_POST['email_subject'] ?? ''));
    $body = trim((string)($_POST['email_body'] ?? ''));
    $rawTargets = trim((string)($_POST['email_targets'] ?? implode("\n", $emails)));
    $targets = $rawTargets === '' ? [] : parse_list_input($rawTargets, false);
    $valid = [];
    $invalid = [];
    foreach ($targets as $email) {
      if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $valid[] = $email;
      } else {
        $invalid[] = $email;
      }
    }

    if ($subject === '' || $body === '') {
      flash('err', 'E-posta başlığı ve mesajı boş bırakılamaz.');
    } elseif ($valid === []) {
      $message = 'Gönderilecek geçerli e-posta adresi bulunamadı.';
      if ($invalid !== []) {
        $message .= ' Geçersiz: '.summarize_list($invalid).'.';
      }
      flash('err', $message);
    } else {
      $htmlBody = build_email_html($body);
      $sent = 0;
      $failed = [];
      $recipientLogs = [];
      foreach ($valid as $email) {
        if (send_mail_simple($email, $subject, $htmlBody)) {
          $sent++;
          $lookup = $emailLookup[mb_strtolower($email, 'UTF-8')] ?? null;
          $recipientLogs[] = [
            'name'    => $lookup['name'] ?? null,
            'email'   => $email,
            'status'  => 'sent',
            'detail'  => 'E-posta gönderildi',
            'sent_at' => now(),
          ];
        } else {
          $failed[] = $email;
          $lookup = $emailLookup[mb_strtolower($email, 'UTF-8')] ?? null;
          $recipientLogs[] = [
            'name'    => $lookup['name'] ?? null,
            'email'   => $email,
            'status'  => 'failed',
            'detail'  => 'E-posta gönderimi başarısız oldu',
          ];
        }
      }
      $parts = [];
      $parts[] = $sent.' kişiye e-posta gönderildi.';
      if ($failed !== []) {
        $parts[] = count($failed).' adres için gönderim başarısız: '.summarize_list($failed).'.';
      }
      if ($invalid !== []) {
        $parts[] = count($invalid).' adres geçersiz olduğu için atlandı.';
        foreach ($invalid as $email) {
          $lookup = $emailLookup[mb_strtolower($email, 'UTF-8')] ?? null;
          $recipientLogs[] = [
            'name'   => $lookup['name'] ?? null,
            'email'  => $email,
            'status' => 'skipped',
            'detail' => 'Geçersiz e-posta adresi',
          ];
        }
      }
      $summary = implode(' ', $parts);
      if ($sent > 0) {
        flash('ok', 'Toplu e-posta işlemi tamamlandı. '.$summary);
      } else {
        flash('err', 'Hiçbir e-posta gönderilemedi. '.$summary);
      }

      if ($recipientLogs !== []) {
        $broadcastId = marketing_log_broadcast('email', $selected, $subject, $body, [], $recipientLogs);
        if ($broadcastId) {
          $redirectTo = marketing_broadcast_url($selected, $search, $broadcastId);
        }
      }
    }
  } elseif ($action === 'send_sms') {
    $message = trim((string)($_POST['sms_message'] ?? ''));
    $rawTargets = trim((string)($_POST['sms_targets'] ?? implode("\n", $phones)));
    $targets = $rawTargets === '' ? [] : parse_list_input($rawTargets, true);

    if ($message === '') {
      flash('err', 'SMS mesajı boş bırakılamaz.');
    } elseif (mb_strlen($message, 'UTF-8') > 918) {
      flash('err', 'SMS metni 918 karakteri aşamaz.');
    } elseif ($targets === []) {
      flash('err', 'Gönderilecek geçerli telefon numarası bulunamadı.');
    } else {
      $normalizedMap = [];
      $skippedNumbers = [];
      foreach ($targets as $original) {
        $normalized = sms_normalize_number($original);
        if ($normalized === '') {
          $skippedNumbers[] = $original;
          continue;
        }
        if (!isset($normalizedMap[$normalized])) {
          $contact = $phoneLookup[$normalized] ?? $phoneLookup[normalize_phone($original)] ?? null;
          $normalizedMap[$normalized] = [
            'original' => $original,
            'name'     => $contact['name'] ?? null,
            'email'    => $contact['email'] ?? null,
          ];
        }
      }

      $validNumbers = array_keys($normalizedMap);
      if ($validNumbers === []) {
        flash('err', 'Geçerli telefon numarası bulunamadı.');
      } else {
        $result = sms_send_bulk($validNumbers, $message);
        $sent = (int)($result['sent'] ?? 0);
        $failed = is_array($result['failed'] ?? null) ? $result['failed'] : [];
        $error = isset($result['error']) && is_string($result['error']) ? trim($result['error']) : '';

        $parts = [];
        if ($sent > 0) {
          $parts[] = $sent.' numaraya SMS gönderildi.';
        }
        if ($failed !== []) {
          $failedNumbers = array_map(function ($row) {
            if (is_array($row) && isset($row['number'])) {
              return (string)$row['number'];
            }
            return is_string($row) ? $row : '';
          }, $failed);
          $failedNumbers = array_values(array_filter($failedNumbers, function ($value) {
            return $value !== '';
          }));
          $parts[] = count($failedNumbers).' numaraya gönderilemedi: '.summarize_list($failedNumbers).'.';
        }
        if ($skippedNumbers !== []) {
          $parts[] = count($skippedNumbers).' numara geçersiz olduğu için atlandı: '.summarize_list($skippedNumbers).'.';
        }
        if ($error !== '') {
          $parts[] = 'Servis mesajı: '.$error;
        }
        $summary = implode(' ', $parts);
        if ($sent > 0) {
          flash('ok', 'Toplu SMS işlemi tamamlandı. '.$summary);
        } else {
          flash('err', 'SMS gönderimi başarısız. '.$summary);
        }

        $failedMap = [];
        foreach ($failed as $row) {
          if (is_array($row)) {
            $number = sms_normalize_number((string)($row['number'] ?? ''));
            $detail = isset($row['error']) ? trim((string)$row['error']) : 'SMS gönderilemedi';
          } else {
            $number = sms_normalize_number((string)$row);
            $detail = 'SMS gönderilemedi';
          }
          if ($number !== '') {
            $failedMap[$number] = $detail;
          }
        }

        $recipientLogs = [];
        foreach ($normalizedMap as $normalized => $info) {
          $status = isset($failedMap[$normalized]) ? 'failed' : 'sent';
          $detail = $failedMap[$normalized] ?? 'SMS gönderildi';
          $recipientLogs[] = [
            'name'    => $info['name'] ?? null,
            'email'   => $info['email'] ?? null,
            'phone'   => $info['original'],
            'status'  => $status,
            'detail'  => $detail,
            'sent_at' => $status === 'sent' ? now() : null,
          ];
        }
        foreach ($skippedNumbers as $number) {
          $recipientLogs[] = [
            'name'   => null,
            'phone'  => $number,
            'status' => 'skipped',
            'detail' => 'Geçersiz numara',
          ];
        }

        if ($recipientLogs !== []) {
          $broadcastId = marketing_log_broadcast('sms', $selected, mb_substr($message, 0, 120), $message, [], $recipientLogs);
          if ($broadcastId) {
            $redirectTo = marketing_broadcast_url($selected, $search, $broadcastId);
          }
        }
      }
    }
  } elseif ($action === 'create_whatsapp') {
    $title = trim((string)($_POST['whatsapp_title'] ?? ''));
    $message = trim((string)($_POST['whatsapp_message'] ?? ''));
    $rawTargets = trim((string)($_POST['whatsapp_targets'] ?? implode("\n", $phones)));
    $targets = $rawTargets === '' ? [] : parse_list_input($rawTargets, true);
    $uploads = marketing_store_media_uploads($_FILES['whatsapp_media'] ?? null);
    $attachments = $uploads['files'];
    $uploadErrors = $uploads['errors'];

    $cleanupUploads = function () use (&$attachments): void {
      foreach ($attachments as $file) {
        $relative = isset($file['path']) ? (string)$file['path'] : '';
        $path = __DIR__.'/../uploads/'.ltrim($relative, '/');
        if ($relative !== '' && is_file($path)) {
          @unlink($path);
        }
      }
      $attachments = [];
    };

    if ($title === '') {
      $cleanupUploads();
      flash('err', 'Kampanya başlığını belirtin.');
    } elseif ($message === '' && $attachments === []) {
      $cleanupUploads();
      flash('err', 'Mesaj metni veya en az bir medya dosyası ekleyin.');
    } elseif ($targets === []) {
      $cleanupUploads();
      flash('err', 'Gönderilecek telefon numarası bulunamadı.');
    } else {
      $normalizedMap = [];
      $skippedNumbers = [];
      foreach ($targets as $original) {
        $normalized = sms_normalize_number($original);
        if ($normalized === '') {
          $skippedNumbers[] = $original;
          continue;
        }
        if (!isset($normalizedMap[$normalized])) {
          $contact = $phoneLookup[$normalized] ?? $phoneLookup[normalize_phone($original)] ?? null;
          $normalizedMap[$normalized] = [
            'original' => $original,
            'name'     => $contact['name'] ?? null,
            'email'    => $contact['email'] ?? null,
          ];
        }
      }

      if ($normalizedMap === []) {
        $cleanupUploads();
        $messageText = 'Gönderilecek geçerli telefon numarası bulunamadı.';
        if ($skippedNumbers !== []) {
          $messageText .= ' Geçersiz: '.summarize_list($skippedNumbers).'.';
        }
        flash('err', $messageText);
      } else {
        $configError = whatsapp_config_error();
        if ($configError !== null) {
          $cleanupUploads();
          flash('err', 'WhatsApp API yapılandırması eksik: '.$configError);
        } else {
          $validNumbers = array_keys($normalizedMap);
          $sendResult = whatsapp_send_bulk($validNumbers, $message, $attachments);
          $deliveryResults = is_array($sendResult['results'] ?? null) ? $sendResult['results'] : [];

          $recipientLogs = [];
          $sentCount = 0;
          $failedCount = 0;
          $failedNumbers = [];

          foreach ($normalizedMap as $normalized => $info) {
            $delivery = $deliveryResults[$normalized] ?? null;
            $status = ($delivery && ($delivery['status'] ?? '') === 'sent') ? 'sent' : 'failed';
            $detail = is_array($delivery) && isset($delivery['detail']) ? trim((string)$delivery['detail']) : '';
            if ($status === 'sent') {
              $sentCount++;
              if ($detail === '') {
                $detail = 'WhatsApp mesajı gönderildi';
              }
            } else {
              $failedCount++;
              $failedNumbers[] = $info['original'];
              if ($detail === '') {
                $detail = 'WhatsApp mesajı gönderilemedi';
              }
            }
            $recipientLogs[] = [
              'name'    => $info['name'] ?? null,
              'email'   => $info['email'] ?? null,
              'phone'   => $info['original'],
              'status'  => $status,
              'detail'  => $detail,
              'sent_at' => $status === 'sent' ? now() : null,
            ];
          }

          foreach ($skippedNumbers as $number) {
            $recipientLogs[] = [
              'name'   => null,
              'phone'  => $number,
              'status' => 'skipped',
              'detail' => 'Geçersiz numara',
            ];
          }

          $summaryParts = [];
          if ($sentCount > 0) {
            $summaryParts[] = $sentCount.' numaraya WhatsApp mesajı gönderildi.';
          }
          if ($failedCount > 0) {
            $failedList = unique_values($failedNumbers, true);
            if ($failedList !== []) {
              $summaryParts[] = $failedCount.' numaraya gönderilemedi: '.summarize_list($failedList).'.';
            } else {
              $summaryParts[] = $failedCount.' numaraya gönderilemedi.';
            }
          }
          if ($skippedNumbers !== []) {
            $summaryParts[] = count($skippedNumbers).' numara geçersiz olduğu için atlandı: '.summarize_list($skippedNumbers).'.';
          }
          $serviceErrors = array_unique(array_map('trim', is_array($sendResult['errors'] ?? null) ? $sendResult['errors'] : []));
          $serviceErrors = array_values(array_filter($serviceErrors, function ($val) {
            return $val !== '';
          }));
          if ($serviceErrors !== []) {
            $summaryParts[] = 'Servis mesajı: '.implode(' ', $serviceErrors);
          }

          $summary = implode(' ', $summaryParts);

          if ($recipientLogs !== []) {
            $broadcastId = marketing_log_broadcast('whatsapp', $selected, $title, $message, $attachments, $recipientLogs);
            if ($broadcastId) {
              $redirectTo = marketing_broadcast_url($selected, $search, $broadcastId);
            }
          }

          if ($sentCount > 0 && $failedCount === 0) {
            flash('ok', 'WhatsApp kampanyası gönderildi. '.$summary);
          } elseif ($sentCount > 0) {
            flash('ok', 'WhatsApp kampanyası kısmen gönderildi. '.$summary);
          } else {
            flash('err', 'WhatsApp mesajları gönderilemedi. '.$summary);
          }

          if (!empty($sendResult['attachment_errors'])) {
            flash('info', implode(' ', (array)$sendResult['attachment_errors']));
          }
          if ($uploadErrors !== []) {
            flash('info', implode(' ', $uploadErrors));
          }
        }
      }
    }
  } elseif ($action === 'update_target_status') {
    $targetId = (int)($_POST['target_id'] ?? 0);
    $broadcastId = (int)($_POST['broadcast_id'] ?? 0);
    $status = trim((string)($_POST['status'] ?? ''));
    $detail = trim((string)($_POST['detail'] ?? ''));
    $options = marketing_target_status_options();

    if ($targetId <= 0 || $broadcastId <= 0 || !isset($options[$status])) {
      flash('err', 'Alıcı güncellemesi yapılamadı.');
    } else {
      try {
        $sentAt = $status === 'sent' ? now() : null;
        $st = pdo()->prepare("UPDATE marketing_broadcast_targets SET status = ?, detail = ?, sent_at = ? WHERE id = ? AND broadcast_id = ?");
        $st->execute([
          $status,
          $detail !== '' ? $detail : null,
          $sentAt,
          $targetId,
          $broadcastId,
        ]);
        flash('ok', 'Alıcı kaydı güncellendi.');
      } catch (Throwable $e) {
        flash('err', 'Alıcı kaydı güncellenemedi.');
      }
    }
    $redirectTo = marketing_broadcast_url($selected, $search, $broadcastId);
  } elseif ($action === 'mark_all_sent') {
    $broadcastId = (int)($_POST['broadcast_id'] ?? 0);
    $detail = trim((string)($_POST['detail'] ?? ''));
    if ($broadcastId <= 0) {
      flash('err', 'Kampanya seçilemedi.');
    } else {
      try {
        $note = $detail !== '' ? $detail : 'WhatsApp üzerinden gönderildi';
        $st = pdo()->prepare("UPDATE marketing_broadcast_targets SET status = 'sent', detail = ?, sent_at = ? WHERE broadcast_id = ? AND status = 'pending'");
        $st->execute([$note, now(), $broadcastId]);
        if ($st->rowCount() > 0) {
          flash('ok', $st->rowCount().' kayıt gönderildi olarak işaretlendi.');
        } else {
          flash('info', 'Bekleyen kayıt bulunamadı.');
        }
      } catch (Throwable $e) {
        flash('err', 'Gönderim durumu güncellenemedi.');
      }
    }
    $redirectTo = marketing_broadcast_url($selected, $search, $broadcastId);
  }

  redirect($redirectTo);
}

if (($activeContacts !== []) && isset($_GET['export']) && $_GET['export'] === 'csv') {
  $filename = 'marketing-'.preg_replace('~[^a-z0-9]+~i', '-', $selected).'-'.date('Ymd_His').'.csv';
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  $fh = fopen('php://output', 'w');
  fputcsv($fh, ['Kategori', 'İsim', 'E-posta', 'Telefon', 'Bilgi', 'Etiketler', 'Oluşturulma']);
  foreach ($activeContacts as $contact) {
    fputcsv($fh, [
      $categories[$selected]['label'] ?? ucfirst($selected),
      $contact['name'] ?? '',
      $contact['email'] ?? '',
      $contact['phone'] ?? '',
      $contact['context'] ?? '',
      implode(' | ', $contact['badges'] ?? []),
      $contact['created_at'] ?? '',
    ]);
  }
  exit;
}

$activeMeta = $categories[$selected];
$activeCount = count($activeContacts);
$emailCount = count($emails);
$phoneCount = count($phones);

function export_url(string $category, string $search): string {
  $params = ['category' => $category, 'export' => 'csv'];
  if ($search !== '') {
    $params['q'] = $search;
  }
  return '?'.http_build_query($params);
}

function filter_url(string $category, string $search): string {
  $params = ['category' => $category];
  if ($search !== '') {
    $params['q'] = $search;
  }
  return '?'.http_build_query($params);
}

$selectedBroadcastId = isset($_GET['broadcast']) ? (int)$_GET['broadcast'] : 0;
$recentBroadcasts = marketing_fetch_recent_broadcasts(20);
$broadcastDetail = null;

if ($selectedBroadcastId > 0) {
  $broadcastDetail = marketing_fetch_broadcast($selectedBroadcastId);
  if (!$broadcastDetail) {
    flash('err', 'Seçilen kampanya bulunamadı veya silinmiş.');
    if ($recentBroadcasts !== []) {
      $selectedBroadcastId = (int)$recentBroadcasts[0]['id'];
      $broadcastDetail = marketing_fetch_broadcast($selectedBroadcastId);
    }
  }
} elseif ($recentBroadcasts !== []) {
  $selectedBroadcastId = (int)$recentBroadcasts[0]['id'];
  $broadcastDetail = marketing_fetch_broadcast($selectedBroadcastId);
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h(APP_NAME)?> — Pazarlama & Mailing</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?=admin_base_styles()?>
<style>
  .card-lite{
    border-radius:20px;
    border:1px solid rgba(148,163,184,.16);
    box-shadow:0 18px 40px -28px rgba(15,23,42,.35);
    background:var(--admin-surface);
  }
  .summary-card{
    display:flex;
    flex-direction:column;
    gap:6px;
    padding:18px;
    border-radius:18px;
    border:1px solid rgba(15,23,42,.08);
    background:linear-gradient(135deg, rgba(14,165,181,.1), rgba(14,165,181,.03));
    height:100%;
  }
  .summary-card .icon{
    width:44px;
    height:44px;
    border-radius:14px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    background:rgba(14,165,181,.18);
    color:var(--admin-brand);
    font-size:1.2rem;
  }
  .summary-card .count{
    font-size:1.6rem;
    font-weight:700;
    color:var(--admin-ink);
  }
  .summary-card .muted{
    color:var(--admin-muted);
    font-size:.85rem;
  }
  .btn-zs{
    background:var(--admin-brand);
    border:none;
    color:#fff;
    border-radius:12px;
    padding:.55rem 1rem;
    font-weight:600;
  }
  .btn-zs:hover{
    background:var(--admin-brand-dark);
    color:#fff;
  }
  .btn-zs-outline{
    background:#fff;
    border:1px solid rgba(14,165,181,.45);
    color:var(--admin-brand);
    border-radius:12px;
    font-weight:600;
  }
  .btn-zs-outline:hover{
    background:rgba(14,165,181,.12);
    color:var(--admin-brand-dark);
  }
  .badge-soft{
    border-radius:999px;
    background:rgba(14,165,181,.12);
    color:var(--admin-brand-dark);
    padding:.32rem .75rem;
    font-weight:600;
    font-size:.78rem;
  }
  .contact-badge{ 
    background:rgba(15,23,42,.05);
    color:var(--admin-ink);
    border-radius:999px;
    padding:.2rem .65rem;
    margin-right:.25rem;
    margin-bottom:.25rem;
    font-size:.78rem;
    display:inline-flex;
    align-items:center;
    gap:.25rem;
  }
  .contact-badge i{
    font-size:.75rem;
  }
  textarea.copy-field{
    min-height:140px;
    resize:vertical;
    font-family:monospace;
  }
  .message-preview{
    background:rgba(15,23,42,.04);
    border-radius:14px;
    padding:1rem 1.25rem;
    font-size:.92rem;
    color:var(--admin-ink);
    white-space:pre-wrap;
  }
  .table thead th{
    font-size:.78rem;
    text-transform:uppercase;
    letter-spacing:.02em;
    color:var(--admin-muted);
  }
  .table tbody td{
    vertical-align:middle;
  }
  .empty-state{
    padding:32px;
    text-align:center;
    color:var(--admin-muted);
  }
  .media-chip{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:.35rem .75rem;
    border-radius:999px;
    background:rgba(15,23,42,.08);
    color:var(--admin-ink);
    font-size:.78rem;
    margin-right:.35rem;
    margin-bottom:.35rem;
  }
  .media-chip i{font-size:.85rem;color:var(--admin-brand);}
  .channel-badge{
    display:inline-flex;
    align-items:center;
    gap:.35rem;
    border-radius:999px;
    font-size:.78rem;
    font-weight:600;
    padding:.35rem .9rem;
  }
  .channel-badge i{font-size:.9rem;}
  .channel-primary{background:rgba(59,130,246,.15);color:#1d4ed8;}
  .channel-info{background:rgba(14,165,233,.18);color:#0369a1;}
  .channel-success{background:rgba(34,197,94,.18);color:#15803d;}
  .channel-secondary{background:rgba(148,163,184,.22);color:#475569;}
  .broadcast-list{
    display:flex;
    flex-direction:column;
    gap:.6rem;
  }
  .broadcast-item{
    display:block;
    padding:.85rem 1rem;
    border-radius:16px;
    border:1px solid rgba(148,163,184,.2);
    text-decoration:none;
    color:inherit;
    transition:border-color .2s ease, box-shadow .2s ease, transform .2s ease;
    background:rgba(255,255,255,.65);
  }
  .broadcast-item:hover{
    border-color:var(--admin-brand);
    box-shadow:0 8px 24px -18px rgba(14,165,181,.65);
    transform:translateY(-1px);
  }
  .broadcast-item.active{
    border-color:var(--admin-brand);
    background:linear-gradient(135deg, rgba(14,165,181,.15), rgba(14,165,181,.05));
  }
  .broadcast-item .title{
    font-weight:600;
    font-size:1rem;
    color:var(--admin-ink);
  }
  .broadcast-meta{
    font-size:.78rem;
    color:var(--admin-muted);
    display:flex;
    gap:12px;
    flex-wrap:wrap;
  }
  .status-pill{
    display:inline-flex;
    align-items:center;
    padding:.2rem .65rem;
    border-radius:999px;
    font-size:.75rem;
    font-weight:600;
  }
  .status-pill.sent{background:rgba(34,197,94,.16);color:#15803d;}
  .status-pill.failed{background:rgba(239,68,68,.16);color:#b91c1c;}
  .status-pill.pending{background:rgba(234,179,8,.2);color:#b45309;}
  .status-pill.skipped{background:rgba(148,163,184,.22);color:#475569;}
</style>
</head>
<body class="admin-body">
<?php admin_layout_start('marketing', 'Pazarlama & Mailing', 'Tüm iletişim kanallarını tek ekranda toplayın ve kampanyalarınızı hızlandırın.', 'bi-envelope-paper'); ?>

  <?php flash_box(); ?>

  <div class="row g-3 mb-3">
    <?php foreach ($categories as $key => $meta): ?>
      <div class="col-12 col-sm-6 col-xl-4">
        <div class="summary-card<?= $selected === $key ? ' border-2 border-brand' : '' ?>">
          <div class="d-flex align-items-center justify-content-between">
            <div class="icon"><i class="bi <?=h($meta['icon'])?>"></i></div>
            <a class="btn btn-sm btn-light" href="<?=h(filter_url($key, $search))?>">Görüntüle</a>
          </div>
          <div>
            <h5 class="mb-1"><?=h($meta['label'])?></h5>
            <div class="count"><?=number_format($counts[$key] ?? 0, 0, ',', '.')?></div>
            <div class="muted"><?=h($meta['description'])?></div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card-lite p-3 mb-4">
    <form method="get" class="row g-3 align-items-end">
      <div class="col-sm-6 col-lg-4">
        <label class="form-label">Arama</label>
        <input type="search" class="form-control" name="q" value="<?=h($search)?>" placeholder="Ad, e-posta, telefon veya etiket">
      </div>
      <div class="col-sm-6 col-lg-3">
        <label class="form-label">Kategori</label>
        <select class="form-select" name="category">
          <?php foreach ($categories as $key => $meta): ?>
            <option value="<?=h($key)?>" <?=$selected === $key ? 'selected' : ''?>><?=h($meta['label'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-4 col-lg-2">
        <label class="form-label d-block">&nbsp;</label>
        <button class="btn btn-zs w-100" type="submit">Filtrele</button>
      </div>
      <?php if ($search !== ''): ?>
        <div class="col-sm-4 col-lg-2">
          <label class="form-label d-block">&nbsp;</label>
          <a class="btn btn-outline-secondary w-100" href="<?=h(filter_url($selected, ''))?>">Sıfırla</a>
        </div>
      <?php endif; ?>
      <div class="col-sm-4 col-lg-3 ms-auto">
        <label class="form-label d-block">&nbsp;</label>
        <a class="btn btn-zs-outline w-100" href="<?=h(export_url($selected, $search))?>"><i class="bi bi-download me-1"></i>CSV indir</a>
      </div>
    </form>
  </div>

  <div class="mb-3">
    <h4 class="mb-1 d-flex align-items-center gap-2">
      <i class="bi <?=h($activeMeta['icon'])?>"></i>
      <?=h($activeMeta['label'])?>
    </h4>
    <div class="text-muted">Toplam <?=number_format($activeCount, 0, ',', '.')?> kişi — <?=number_format($emailCount, 0, ',', '.')?> e-posta, <?=number_format($phoneCount, 0, ',', '.')?> telefon kaydı</div>
  </div>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card-lite p-3 mb-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="m-0">E-posta listesi</h5>
          <button class="btn btn-sm btn-light" type="button" data-copy="emails"><i class="bi bi-clipboard"></i> Kopyala</button>
        </div>
        <textarea id="emails" class="form-control copy-field" readonly><?=h(implode("\n", $emails))?></textarea>
        <div class="small text-muted mt-2">Liste virgül veya satır sonu ile ayrılmıştır; e-posta gönderim aracınıza doğrudan yapıştırabilirsiniz.</div>
      </div>
      <div class="card-lite p-3">
        <form method="post" class="marketing-form">
          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="action" value="send_email">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="m-0">Toplu e-posta gönder</h5>
            <span class="badge-soft"><?=number_format($emailCount, 0, ',', '.') ?> alıcı</span>
          </div>
          <?php if ($emailCount === 0): ?>
            <div class="alert alert-warning small" role="alert">
              Bu kategori için hazır e-posta bulunamadı; formu kullanarak yeni bir liste oluşturabilir veya dışarıdan yapıştırabilirsiniz.
            </div>
          <?php endif; ?>
          <div class="mb-3">
            <label class="form-label">Alıcı listesi</label>
            <textarea name="email_targets" class="form-control" rows="4" placeholder="ornek@firma.com"><?=h(implode("\n", $emails))?></textarea>
            <div class="form-text">Adresleri satır satır ya da virgülle ayırarak düzenleyebilirsiniz; geçersiz adresler otomatik olarak atlanır.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Konu</label>
            <input type="text" name="email_subject" class="form-control" placeholder="Örn. Yeni sezon kampanyamız" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Mesaj</label>
            <textarea name="email_body" class="form-control" rows="6" placeholder="Merhaba..." required></textarea>
            <div class="form-text">Satır sonları korunarak HTML formatında gönderilir; dilerseniz ek olarak imza bilgisi de ekleyebilirsiniz.</div>
          </div>
          <button class="btn btn-zs" type="submit"><i class="bi bi-send me-1"></i>Toplu e-posta gönder</button>
        </form>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card-lite p-3 mb-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="m-0">SMS / Telefon listesi</h5>
          <button class="btn btn-sm btn-light" type="button" data-copy="phones"><i class="bi bi-clipboard"></i> Kopyala</button>
        </div>
        <textarea id="phones" class="form-control copy-field" readonly><?=h(implode("\n", $phones))?></textarea>
        <div class="small text-muted mt-2">Telefon numaraları düz metin olarak listelenir; toplu SMS araçlarına aktarmadan önce doğrulama yapmayı unutmayın.</div>
      </div>
      <div class="card-lite p-3">
        <form method="post" class="marketing-form">
          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="action" value="send_sms">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="m-0">Toplu SMS gönder</h5>
            <span class="badge-soft"><?=number_format($phoneCount, 0, ',', '.') ?> numara</span>
          </div>
          <?php
            $smsUrlMissing = SMS_API_URL === '';
            $smsCredsMissing = !$smsUrlMissing && (SMS_API_KEY === '' && SMS_API_SECRET === '');
          ?>
          <?php if ($smsUrlMissing || $smsCredsMissing): ?>
            <div class="alert alert-warning small" role="alert">
              <?php if ($smsUrlMissing): ?>
                SMS API adresi tanımlanmadı. <code>SMS_API_URL</code> değerini <code>config.php</code> veya ortam değişkeni üzerinden belirleyin.
              <?php else: ?>
                SMS API erişimi için kullanıcı adı/şifre ya da anahtar bilgisi ekleyin. <code>SMS_API_KEY</code> ve <code>SMS_API_SECRET</code> değerlerini girerek isteğin yetkilendirilmesini sağlayın.
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <div class="mb-3">
            <label class="form-label">Numara listesi</label>
            <textarea name="sms_targets" class="form-control" rows="4" placeholder="905XXXXXXXXX"><?=h(implode("\n", $phones))?></textarea>
            <div class="form-text">Numaralar otomatik olarak 90 ile başlayan uluslararası formata dönüştürülür ve tekrar edenler temizlenir.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Mesaj</label>
            <textarea name="sms_message" class="form-control" rows="6" placeholder="Kampanyamız başladı..." data-sms-length data-counter="sms-length" required></textarea>
            <div class="form-text"><span id="sms-length">0 karakter, 0 SMS</span> — Türkçe karakter içeren mesajlarda segment başına 70 karakter sınırı uygulanabilir.</div>
          </div>
          <button class="btn btn-zs" type="submit"><i class="bi bi-chat-dots me-1"></i>Toplu SMS gönder</button>
        </form>
      </div>
    </div>
  </div>

  <div class="card-lite p-3 mt-3">
    <form method="post" enctype="multipart/form-data" class="marketing-form">
      <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="create_whatsapp">
      <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h5 class="m-0">WhatsApp kampanyası oluştur</h5>
        <span class="badge-soft"><?=number_format($phoneCount, 0, ',', '.') ?> kişi</span>
      </div>
      <div class="alert alert-info small" role="alert">
        Görsel ve medya içeriklerinizi yükleyin, mesajınızı hazırlayın. Kaydedilen kampanyayı listeden açarak her alıcıyı WhatsApp Business üzerinden gönderdikçe <strong>Gönderildi</strong> olarak işaretleyebilirsiniz.
      </div>
      <div class="mb-3">
        <label class="form-label">Kampanya başlığı</label>
        <input type="text" name="whatsapp_title" class="form-control" placeholder="Örn. Yeni sezon tanıtımı" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Numara listesi</label>
        <textarea name="whatsapp_targets" class="form-control" rows="4" placeholder="905XXXXXXXXX" required><?=h(implode("\n", $phones))?></textarea>
        <div class="form-text">Numaralar otomatik temizlenir; aynı numara bir kez eklenir.</div>
      </div>
      <div class="mb-3">
        <label class="form-label">Mesaj</label>
        <textarea name="whatsapp_message" class="form-control" rows="5" placeholder="Merhaba..." ></textarea>
        <div class="form-text">Mesaj içeriği alıcılara ön izleme olarak kaydedilir; WhatsApp gönderimleri panelden işaretlenir.</div>
      </div>
      <div class="mb-3">
        <label class="form-label">Medya ekleri</label>
        <input type="file" name="whatsapp_media[]" class="form-control" multiple accept="image/*,video/mp4,video/quicktime,application/pdf">
        <div class="form-text">Görsel veya videoları 25MB sınırı dahilinde yükleyebilirsiniz. Ekler paylaşılabilir bağlantı olarak saklanır.</div>
      </div>
      <button class="btn btn-zs" type="submit"><i class="bi bi-whatsapp me-1"></i>Kampanyayı kaydet</button>
    </form>
  </div>

  <div class="card-lite p-3 mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <h5 class="m-0">Gönderim geçmişi</h5>
      <?php if ($recentBroadcasts !== []): ?>
        <span class="text-muted small">Son <?=count($recentBroadcasts)?> kayıt gösteriliyor</span>
      <?php endif; ?>
    </div>
    <?php if ($recentBroadcasts === []): ?>
      <div class="empty-state">
        <i class="bi bi-clock-history fs-3 mb-2"></i>
        <p class="mb-0">Henüz kaydedilmiş bir kampanya bulunmuyor. İlk WhatsApp, e-posta veya SMS gönderiminizi tamamladığınızda burada listelenir.</p>
      </div>
    <?php else: ?>
      <div class="row g-3">
        <div class="col-lg-5">
          <div class="broadcast-list">
            <?php foreach ($recentBroadcasts as $item): ?>
              <?php
                $itemId = (int)($item['id'] ?? 0);
                $channelMeta = marketing_channel_meta($item['channel'] ?? '');
                $createdAt = $item['created_at'] ?? null;
                $createdLabel = $createdAt ? format_local_datetime($createdAt) : '—';
                $sentCount = (int)($item['sent_count'] ?? 0);
                $pendingCount = (int)($item['pending_count'] ?? 0);
                $totalCount = (int)($item['total_count'] ?? 0);
                $isActive = $selectedBroadcastId === $itemId;
              ?>
              <a class="broadcast-item<?=$isActive ? ' active' : ''?>" href="<?=h(marketing_broadcast_url($selected, $search, $itemId))?>">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                  <span class="channel-badge channel-<?=$channelMeta['class']?>"><i class="bi <?=$channelMeta['icon']?>"></i><?=h($channelMeta['label'])?></span>
                  <small class="text-muted"><?=$createdLabel?></small>
                </div>
                <div class="title mt-2"><?=h($item['title'] ?: ('Kampanya #'.$itemId))?></div>
                <div class="broadcast-meta mt-2">
                  <span><i class="bi bi-people me-1"></i><?=number_format($totalCount, 0, ',', '.')?></span>
                  <span><i class="bi bi-check2-circle me-1"></i><?=number_format($sentCount, 0, ',', '.')?></span>
                  <span><i class="bi bi-hourglass-split me-1"></i><?=number_format($pendingCount, 0, ',', '.')?></span>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="col-lg-7">
          <?php if (!$broadcastDetail): ?>
            <div class="empty-state">
              <i class="bi bi-info-circle fs-3 mb-2"></i>
              <p class="mb-0">Detayları görmek için listeden bir kampanya seçin.</p>
            </div>
          <?php else: ?>
            <?php
              $detailMeta = marketing_channel_meta($broadcastDetail['channel'] ?? '');
              $detailCreated = $broadcastDetail['created_at'] ?? null;
              $detailCreatedLabel = $detailCreated ? format_local_datetime($detailCreated) : '—';
              $detailAudience = $broadcastDetail['audience_key'] ?? '';
              $audienceLabel = $detailAudience && isset($categories[$detailAudience]) ? $categories[$detailAudience]['label'] : 'Genel liste';
              $counts = $broadcastDetail['status_counts'] ?? [];
            ?>
            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
              <div>
                <div class="channel-badge channel-<?=$detailMeta['class']?> mb-2"><i class="bi <?=$detailMeta['icon']?>"></i><?=h($detailMeta['label'])?></div>
                <h5 class="mb-1"><?=h($broadcastDetail['title'] ?: ('Kampanya #'.$broadcastDetail['id']))?></h5>
                <div class="text-muted small">Oluşturulma: <?=$detailCreatedLabel?> · Hedef: <?=h($audienceLabel)?></div>
              </div>
              <?php if (($broadcastDetail['channel'] ?? '') === 'whatsapp' && !empty($counts['pending'])): ?>
                <form method="post" class="d-flex gap-2 align-items-center flex-wrap">
                  <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                  <input type="hidden" name="action" value="mark_all_sent">
                  <input type="hidden" name="broadcast_id" value="<?= (int)$broadcastDetail['id']?>">
                  <input type="text" name="detail" class="form-control form-control-sm" placeholder="Not (opsiyonel)">
                  <button class="btn btn-sm btn-zs" type="submit"><i class="bi bi-check2-all me-1"></i>Bekleyenleri gönderildi yap</button>
                </form>
              <?php endif; ?>
            </div>
            <div class="broadcast-meta mt-3">
              <span><i class="bi bi-people me-1"></i><?=number_format((int)($broadcastDetail['total_targets'] ?? 0), 0, ',', '.')?></span>
              <span><i class="bi bi-check2-circle me-1"></i><?=number_format((int)($counts['sent'] ?? 0), 0, ',', '.')?></span>
              <span><i class="bi bi-hourglass-split me-1"></i><?=number_format((int)($counts['pending'] ?? 0), 0, ',', '.')?></span>
              <span><i class="bi bi-exclamation-circle me-1"></i><?=number_format((int)($counts['failed'] ?? 0), 0, ',', '.')?></span>
            </div>
            <?php if (!empty($broadcastDetail['attachments'])): ?>
              <div class="mt-3">
                <?php foreach ($broadcastDetail['attachments'] as $file): ?>
                  <?php
                    $path = isset($file['path']) ? ltrim((string)$file['path'], '/') : '';
                    $url = $path !== '' ? rtrim(BASE_URL, '/').'/uploads/'.$path : '#';
                    $sizeText = isset($file['size']) ? marketing_format_filesize((int)$file['size']) : '';
                  ?>
                  <a class="media-chip" href="<?=h($url)?>" target="_blank" rel="noopener">
                    <i class="bi bi-paperclip"></i>
                    <span><?=h($file['name'] ?? 'Dosya')?></span>
                    <?php if ($sizeText !== ''): ?><span class="text-muted">(<?=h($sizeText)?>)</span><?php endif; ?>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <?php if (!empty(trim((string)($broadcastDetail['body'] ?? '')))): ?>
              <div class="message-preview mt-3"><?=nl2br(h($broadcastDetail['body']))?></div>
            <?php endif; ?>
            <div class="table-responsive mt-4">
              <table class="table table-sm align-middle">
                <thead>
                  <tr>
                    <th>Alıcı</th>
                    <th>Durum</th>
                    <th>Not</th>
                    <?php if (($broadcastDetail['channel'] ?? '') === 'whatsapp'): ?>
                      <th width="38%">Güncelle</th>
                    <?php endif; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($broadcastDetail['targets'])): ?>
                    <tr>
                      <td colspan="<?=($broadcastDetail['channel'] ?? '') === 'whatsapp' ? 4 : 3?>" class="text-center text-muted">Henüz alıcı kaydı bulunmuyor.</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($broadcastDetail['targets'] as $recipient): ?>
                      <?php $statusMeta = marketing_target_status_meta($recipient['status'] ?? 'pending'); ?>
                      <tr>
                        <td>
                          <div class="fw-semibold"><?=h($recipient['target_name'] ?: ($recipient['target_email'] ?: ($recipient['target_phone'] ?: 'Alıcı'))) ?></div>
                          <div class="text-muted small">
                            <?php if (!empty($recipient['target_email'])): ?><span><?=h($recipient['target_email'])?></span><?php endif; ?>
                            <?php if (!empty($recipient['target_phone'])): ?><span class="ms-2"><?=h($recipient['target_phone'])?></span><?php endif; ?>
                            <?php if (!empty($recipient['sent_at'])): ?><span class="ms-2"><?=h(format_local_datetime($recipient['sent_at']))?></span><?php endif; ?>
                          </div>
                        </td>
                        <td><span class="status-pill <?=$statusMeta['class']?>"><?=h($statusMeta['label'])?></span></td>
                        <td><?=h($recipient['detail'] ?? '—')?></td>
                        <?php if (($broadcastDetail['channel'] ?? '') === 'whatsapp'): ?>
                          <td>
                            <form method="post" class="d-flex gap-2 align-items-center flex-wrap">
                              <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                              <input type="hidden" name="action" value="update_target_status">
                              <input type="hidden" name="broadcast_id" value="<?= (int)$broadcastDetail['id']?>">
                              <input type="hidden" name="target_id" value="<?= (int)$recipient['id']?>">
                              <select name="status" class="form-select form-select-sm w-auto">
                                <?php foreach (marketing_target_status_options() as $key => $label): ?>
                                  <option value="<?=h($key)?>" <?=$recipient['status'] === $key ? 'selected' : ''?>><?=h($label)?></option>
                                <?php endforeach; ?>
                              </select>
                              <input type="text" name="detail" class="form-control form-control-sm flex-grow-1" value="<?=h($recipient['detail'] ?? '')?>" placeholder="Not">
                              <button class="btn btn-sm btn-zs" type="submit">Kaydet</button>
                            </form>
                          </td>
                        <?php endif; ?>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div class="card-lite mt-4">
    <?php if (empty($activeContacts)): ?>
      <div class="empty-state">
        <i class="bi bi-inbox fs-1 mb-2"></i>
        <p class="mb-0">Bu kategori için listelenecek kişi bulunamadı. Filtreleri genişletmeyi deneyin.</p>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th scope="col">Kişi / Kurum</th>
              <th scope="col">E-posta</th>
              <th scope="col">Telefon</th>
              <th scope="col">Etiketler</th>
              <th scope="col">Oluşturulma</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($activeContacts as $contact): ?>
              <tr>
                <td>
                  <div class="fw-semibold"><?=h($contact['name'] ?? '')?></div>
                  <div class="text-muted small">
                    <?=h($contact['context'] ?? '')?>
                    <?php if (!empty($contact['notes'])): ?>
                      <span class="d-block mt-1"><?=h($contact['notes'])?></span>
                    <?php endif; ?>
                  </div>
                </td>
                <td><?=h($contact['email'] ?? '—')?></td>
                <td><?=h($contact['phone'] ?? '—')?></td>
                <td>
                  <?php foreach ($contact['badges'] ?? [] as $badge): ?>
                    <span class="contact-badge"><i class="bi bi-tag"></i><?=h($badge)?></span>
                  <?php endforeach; ?>
                </td>
                <td><?=h($contact['created_at'] ? format_local_datetime($contact['created_at']) : '—')?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php admin_layout_end(); ?>
<script>
document.querySelectorAll('[data-copy]').forEach(function(button){
  button.addEventListener('click', function(){
    var targetId = button.getAttribute('data-copy');
    var field = document.getElementById(targetId);
    if (!field) {
      return;
    }
    var value = field.value;
    if (!value) {
      return;
    }
    navigator.clipboard.writeText(value).then(function(){
      var original = button.innerHTML;
      button.innerHTML = '<i class="bi bi-check2"></i> Kopyalandı';
      setTimeout(function(){ button.innerHTML = original; }, 2000);
    }).catch(function(){
      field.select();
      document.execCommand('copy');
      var original = button.innerHTML;
      button.innerHTML = '<i class="bi bi-check2"></i> Kopyalandı';
      setTimeout(function(){ button.innerHTML = original; }, 2000);
    });
  });
});

document.querySelectorAll('[data-sms-length]').forEach(function(field){
  var counterId = field.getAttribute('data-counter');
  var counter = counterId ? document.getElementById(counterId) : null;
  if (!counter) {
    return;
  }
  var update = function(){
    var text = field.value || '';
    var length = text.length;
    var unicode = /[^\u0000-\u007f]/.test(text);
    var segmentSize = unicode ? 70 : 160;
    var segments = length === 0 ? 0 : Math.ceil(length / segmentSize);
    counter.textContent = length + ' karakter, ' + segments + ' SMS';
  };
  field.addEventListener('input', update);
  update();
});
</script>
</body>
</html>
