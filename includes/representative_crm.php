<?php
/**
 * includes/representative_crm.php — Temsilci CRM yardımcı fonksiyonları
 */
require_once __DIR__.'/../config.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';

install_schema();

const REP_LEAD_STATUS_NEW = 'new';
const REP_LEAD_STATUS_CONTACTED = 'contacted';
const REP_LEAD_STATUS_DISCOVERY = 'discovery';
const REP_LEAD_STATUS_PROPOSAL = 'proposal_sent';
const REP_LEAD_STATUS_NEGOTIATION = 'negotiation';
const REP_LEAD_STATUS_PILOT = 'pilot';
const REP_LEAD_STATUS_ON_HOLD = 'on_hold';
const REP_LEAD_STATUS_WON = 'won';
const REP_LEAD_STATUS_LOST = 'lost';

function representative_crm_tables_ready(): bool {
  static $ready = null;
  if ($ready !== null) {
    return $ready;
  }

  try {
    install_schema();
  } catch (Throwable $e) {
    // kurulum hatalarını sessizce yok sayıyoruz, hazır kontrolü false dönebilir
  }

  $ready = table_exists('representative_leads') && table_exists('representative_lead_notes');
  return $ready;
}

function representative_crm_status_options(): array {
  return [
    REP_LEAD_STATUS_NEW => 'Yeni Kayıt',
    REP_LEAD_STATUS_CONTACTED => 'İlk Temas Sağlandı',
    REP_LEAD_STATUS_DISCOVERY => 'İhtiyaç Analizi',
    REP_LEAD_STATUS_PROPOSAL => 'Teklif Gönderildi',
    REP_LEAD_STATUS_NEGOTIATION => 'Pazarlık / Süreç',
    REP_LEAD_STATUS_PILOT => 'Demo / Pilot',
    REP_LEAD_STATUS_ON_HOLD => 'Beklemede',
    REP_LEAD_STATUS_WON => 'Kazanıldı',
    REP_LEAD_STATUS_LOST => 'Kaybedildi',
  ];
}

function representative_crm_status_badge_class(string $status): string {
  return match ($status) {
    REP_LEAD_STATUS_WON => 'success',
    REP_LEAD_STATUS_LOST => 'danger',
    REP_LEAD_STATUS_NEGOTIATION, REP_LEAD_STATUS_PROPOSAL => 'primary',
    REP_LEAD_STATUS_PILOT => 'info',
    REP_LEAD_STATUS_CONTACTED, REP_LEAD_STATUS_DISCOVERY => 'info',
    REP_LEAD_STATUS_ON_HOLD => 'secondary',
    default => 'secondary',
  };
}

function representative_crm_parse_datetime(?string $input): ?string {
  $input = trim($input ?? '');
  if ($input === '') {
    return null;
  }
  try {
    $dt = new DateTime($input);
    return $dt->format('Y-m-d H:i:s');
  } catch (Throwable $e) {
    return null;
  }
}

function representative_crm_lead_create(int $representative_id, array $data): int {
  $representative_id = max(0, $representative_id);
  if ($representative_id <= 0) {
    throw new InvalidArgumentException('Geçerli bir temsilci seçin.');
  }
  if (!representative_crm_tables_ready()) {
    throw new RuntimeException('CRM tabloları henüz oluşturulmadı. Lütfen sistem yöneticinizle iletişime geçin.');
  }
  $name = trim($data['name'] ?? '');
  if ($name === '') {
    throw new InvalidArgumentException('Potansiyel müşteri adı zorunludur.');
  }
  $email = trim($data['email'] ?? '');
  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    throw new InvalidArgumentException('Geçerli bir e-posta adresi girin.');
  }
  $phone = trim($data['phone'] ?? '');
  $company = trim($data['company'] ?? '');
  $status = $data['status'] ?? REP_LEAD_STATUS_NEW;
  if (!array_key_exists($status, representative_crm_status_options())) {
    $status = REP_LEAD_STATUS_NEW;
  }
  $source = trim($data['source'] ?? '');
  $notes = trim($data['notes'] ?? '');
  $potentialValue = isset($data['potential_value_cents']) ? (int)$data['potential_value_cents'] : null;
  if ($potentialValue !== null && $potentialValue < 0) {
    $potentialValue = null;
  }
  $nextAction = representative_crm_parse_datetime($data['next_action_at'] ?? null);

  $pdo = pdo();
  $pdo->prepare("INSERT INTO representative_leads (representative_id, name, email, phone, company, status, source, notes, potential_value_cents, next_action_at, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([
        $representative_id,
        $name,
        $email !== '' ? $email : null,
        $phone !== '' ? $phone : null,
        $company !== '' ? $company : null,
        $status,
        $source !== '' ? $source : null,
        $notes !== '' ? $notes : null,
        $potentialValue,
        $nextAction,
        now(),
        now(),
      ]);
  return (int)$pdo->lastInsertId();
}

function representative_crm_lead_get(int $lead_id, int $representative_id): ?array {
  if (!representative_crm_tables_ready()) {
    return null;
  }
  $st = pdo()->prepare('SELECT * FROM representative_leads WHERE id=? AND representative_id=? LIMIT 1');
  $st->execute([$lead_id, $representative_id]);
  $row = $st->fetch();
  if (!$row) {
    return null;
  }
  return [
    'id' => (int)$row['id'],
    'representative_id' => (int)$row['representative_id'],
    'name' => $row['name'] ?? '',
    'email' => $row['email'] ?? null,
    'phone' => $row['phone'] ?? null,
    'company' => $row['company'] ?? null,
    'status' => $row['status'] ?? REP_LEAD_STATUS_NEW,
    'source' => $row['source'] ?? null,
    'notes' => $row['notes'] ?? null,
    'potential_value_cents' => isset($row['potential_value_cents']) ? (int)$row['potential_value_cents'] : null,
    'last_contact_at' => $row['last_contact_at'] ?? null,
    'next_action_at' => $row['next_action_at'] ?? null,
    'created_at' => $row['created_at'] ?? null,
    'updated_at' => $row['updated_at'] ?? null,
  ];
}

function representative_crm_leads(int $representative_id): array {
  if (!representative_crm_tables_ready()) {
    return [];
  }
  $st = pdo()->prepare('SELECT * FROM representative_leads WHERE representative_id=? ORDER BY created_at DESC');
  $st->execute([$representative_id]);
  $rows = [];
  foreach ($st as $row) {
    $rows[] = [
      'id' => (int)$row['id'],
      'representative_id' => (int)$row['representative_id'],
      'name' => $row['name'] ?? '',
      'email' => $row['email'] ?? null,
      'phone' => $row['phone'] ?? null,
      'company' => $row['company'] ?? null,
      'status' => $row['status'] ?? REP_LEAD_STATUS_NEW,
      'source' => $row['source'] ?? null,
      'notes' => $row['notes'] ?? null,
      'potential_value_cents' => isset($row['potential_value_cents']) ? (int)$row['potential_value_cents'] : null,
      'last_contact_at' => $row['last_contact_at'] ?? null,
      'next_action_at' => $row['next_action_at'] ?? null,
      'created_at' => $row['created_at'] ?? null,
      'updated_at' => $row['updated_at'] ?? null,
    ];
  }
  return $rows;
}

function representative_crm_lead_update(int $lead_id, int $representative_id, array $data): void {
  if (!representative_crm_tables_ready()) {
    throw new RuntimeException('CRM tabloları henüz hazır olmadığı için güncelleme yapılamadı.');
  }
  $lead = representative_crm_lead_get($lead_id, $representative_id);
  if (!$lead) {
    throw new InvalidArgumentException('Kayıt bulunamadı.');
  }
  $name = trim($data['name'] ?? $lead['name']);
  if ($name === '') {
    throw new InvalidArgumentException('Ad alanı boş olamaz.');
  }
  $email = trim($data['email'] ?? ($lead['email'] ?? ''));
  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    throw new InvalidArgumentException('Geçerli bir e-posta adresi girin.');
  }
  $phone = trim($data['phone'] ?? ($lead['phone'] ?? ''));
  $company = trim($data['company'] ?? ($lead['company'] ?? ''));
  $status = $data['status'] ?? $lead['status'];
  if (!array_key_exists($status, representative_crm_status_options())) {
    $status = $lead['status'];
  }
  $source = trim($data['source'] ?? ($lead['source'] ?? ''));
  $notes = trim($data['notes'] ?? ($lead['notes'] ?? ''));
  $potentialValue = array_key_exists('potential_value_cents', $data)
    ? (int)$data['potential_value_cents']
    : $lead['potential_value_cents'];
  if ($potentialValue !== null && $potentialValue < 0) {
    $potentialValue = null;
  }
  $nextAction = array_key_exists('next_action_at', $data)
    ? representative_crm_parse_datetime($data['next_action_at'])
    : $lead['next_action_at'];

  pdo()->prepare('UPDATE representative_leads SET name=?, email=?, phone=?, company=?, status=?, source=?, notes=?, potential_value_cents=?, next_action_at=?, updated_at=? WHERE id=? AND representative_id=?')
      ->execute([
        $name,
        $email !== '' ? $email : null,
        $phone !== '' ? $phone : null,
        $company !== '' ? $company : null,
        $status,
        $source !== '' ? $source : null,
        $notes !== '' ? $notes : null,
        $potentialValue,
        $nextAction,
        now(),
        $lead_id,
        $representative_id,
      ]);
}

function representative_crm_lead_notes(int $lead_id, int $representative_id): array {
  if (!representative_crm_tables_ready()) {
    return [];
  }
  $st = pdo()->prepare('SELECT * FROM representative_lead_notes WHERE lead_id=? AND representative_id=? ORDER BY created_at DESC');
  $st->execute([$lead_id, $representative_id]);
  $rows = [];
  foreach ($st as $row) {
    $rows[] = [
      'id' => (int)$row['id'],
      'lead_id' => (int)$row['lead_id'],
      'representative_id' => (int)$row['representative_id'],
      'note' => $row['note'] ?? '',
      'contact_type' => $row['contact_type'] ?? null,
      'next_action_at' => $row['next_action_at'] ?? null,
      'created_at' => $row['created_at'] ?? null,
    ];
  }
  return $rows;
}

function representative_crm_lead_add_note(int $lead_id, int $representative_id, string $note, ?string $contact_type = null, ?string $next_action_at = null): void {
  if (!representative_crm_tables_ready()) {
    throw new RuntimeException('CRM tabloları hazır olmadığı için not eklenemedi.');
  }
  $lead = representative_crm_lead_get($lead_id, $representative_id);
  if (!$lead) {
    throw new InvalidArgumentException('Potansiyel müşteri bulunamadı.');
  }
  $note = trim($note);
  if ($note === '') {
    throw new InvalidArgumentException('Görüşme notu boş olamaz.');
  }
  $contact_type = trim($contact_type ?? '');
  $nextAction = representative_crm_parse_datetime($next_action_at);

  $pdo = pdo();
  $pdo->beginTransaction();
  try {
    $pdo->prepare('INSERT INTO representative_lead_notes (lead_id, representative_id, note, contact_type, next_action_at, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([
          $lead_id,
          $representative_id,
          $note,
          $contact_type !== '' ? $contact_type : null,
          $nextAction,
          now(),
        ]);

    $pdo->prepare('UPDATE representative_leads SET last_contact_at=?, next_action_at=?, updated_at=? WHERE id=? AND representative_id=?')
        ->execute([
          now(),
          $nextAction ?: $lead['next_action_at'],
          now(),
          $lead_id,
          $representative_id,
        ]);

    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}

function representative_crm_status_counts(int $representative_id): array {
  $summary = array_fill_keys(array_keys(representative_crm_status_options()), 0);
  $summary['total'] = 0;
  if (!representative_crm_tables_ready()) {
    return $summary;
  }
  $st = pdo()->prepare('SELECT status, COUNT(*) AS c FROM representative_leads WHERE representative_id=? GROUP BY status');
  $st->execute([$representative_id]);
  foreach ($st as $row) {
    $status = $row['status'] ?? REP_LEAD_STATUS_NEW;
    $count = (int)($row['c'] ?? 0);
    if (!array_key_exists($status, $summary)) {
      $summary[$status] = 0;
    }
    $summary[$status] += $count;
    $summary['total'] += $count;
  }
  return $summary;
}

function representative_crm_upcoming_actions(int $representative_id, int $limit = 5): array {
  $limit = max(1, $limit);
  if (!representative_crm_tables_ready()) {
    return [];
  }
  $st = pdo()->prepare('SELECT * FROM representative_leads WHERE representative_id=? AND next_action_at IS NOT NULL ORDER BY next_action_at ASC LIMIT ?');
  $st->bindValue(1, $representative_id, PDO::PARAM_INT);
  $st->bindValue(2, $limit, PDO::PARAM_INT);
  $st->execute();
  $rows = [];
  foreach ($st as $row) {
    $rows[] = [
      'id' => (int)$row['id'],
      'name' => $row['name'] ?? '',
      'company' => $row['company'] ?? null,
      'status' => $row['status'] ?? REP_LEAD_STATUS_NEW,
      'next_action_at' => $row['next_action_at'] ?? null,
    ];
  }
  return $rows;
}

function representative_crm_recent_notes(int $representative_id, int $limit = 5): array {
  $limit = max(1, $limit);
  if (!representative_crm_tables_ready()) {
    return [];
  }
  $sql = 'SELECT n.*, l.name AS lead_name, l.company AS lead_company
          FROM representative_lead_notes n
          INNER JOIN representative_leads l ON l.id = n.lead_id
          WHERE n.representative_id=?
          ORDER BY n.created_at DESC
          LIMIT ?';
  $st = pdo()->prepare($sql);
  $st->bindValue(1, $representative_id, PDO::PARAM_INT);
  $st->bindValue(2, $limit, PDO::PARAM_INT);
  $st->execute();
  $rows = [];
  foreach ($st as $row) {
    $rows[] = [
      'id' => (int)$row['id'],
      'lead_id' => (int)$row['lead_id'],
      'lead_name' => $row['lead_name'] ?? '',
      'lead_company' => $row['lead_company'] ?? null,
      'note' => $row['note'] ?? '',
      'contact_type' => $row['contact_type'] ?? null,
      'next_action_at' => $row['next_action_at'] ?? null,
      'created_at' => $row['created_at'] ?? null,
    ];
  }
  return $rows;
}
