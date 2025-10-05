<?php
/**
 * includes/dealer_crm.php — Bayi CRM yardımcı fonksiyonları
 */
require_once __DIR__.'/../config.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/representatives.php';

const DEALER_LEAD_STATUS_NEW = 'new';
const DEALER_LEAD_STATUS_CONTACTED = 'contacted';
const DEALER_LEAD_STATUS_QUALIFIED = 'qualified';
const DEALER_LEAD_STATUS_FOLLOW_UP = 'follow_up';
const DEALER_LEAD_STATUS_PROPOSAL = 'proposal_sent';
const DEALER_LEAD_STATUS_NEGOTIATION = 'negotiation';
const DEALER_LEAD_STATUS_ON_HOLD = 'on_hold';
const DEALER_LEAD_STATUS_WON = 'won';
const DEALER_LEAD_STATUS_LOST = 'lost';

function dealer_lead_status_options(): array {
  return [
    DEALER_LEAD_STATUS_NEW => 'Yeni',
    DEALER_LEAD_STATUS_CONTACTED => 'İlk Görüşme Yapıldı',
    DEALER_LEAD_STATUS_QUALIFIED => 'İhtiyaç Analizi',
    DEALER_LEAD_STATUS_FOLLOW_UP => 'Takip Ediliyor',
    DEALER_LEAD_STATUS_PROPOSAL => 'Teklif Gönderildi',
    DEALER_LEAD_STATUS_NEGOTIATION => 'Pazarlık / Görüşme',
    DEALER_LEAD_STATUS_ON_HOLD => 'Beklemede',
    DEALER_LEAD_STATUS_WON => 'Kazanıldı',
    DEALER_LEAD_STATUS_LOST => 'Kaybedildi',
  ];
}

function dealer_lead_status_badge_class(string $status): string {
  return match ($status) {
    DEALER_LEAD_STATUS_WON => 'success',
    DEALER_LEAD_STATUS_LOST => 'danger',
    DEALER_LEAD_STATUS_NEGOTIATION => 'warning',
    DEALER_LEAD_STATUS_PROPOSAL => 'primary',
    DEALER_LEAD_STATUS_QUALIFIED => 'primary',
    DEALER_LEAD_STATUS_FOLLOW_UP => 'info',
    DEALER_LEAD_STATUS_CONTACTED => 'info',
    DEALER_LEAD_STATUS_ON_HOLD => 'secondary',
    default => 'secondary',
  };
}

function dealer_lead_parse_datetime(?string $input): ?string {
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

function dealer_lead_create(int $dealer_id, array $data, ?int $representative_id = null): int {
  $dealer_id = max(0, $dealer_id);
  if ($dealer_id <= 0) {
    throw new InvalidArgumentException('Geçerli bir bayi seçin.');
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
  $status = $data['status'] ?? DEALER_LEAD_STATUS_NEW;
  if (!array_key_exists($status, dealer_lead_status_options())) {
    $status = DEALER_LEAD_STATUS_NEW;
  }
  $source = trim($data['source'] ?? '');
  $notes = trim($data['notes'] ?? '');
  $nextAction = dealer_lead_parse_datetime($data['next_action_at'] ?? null);

  $pdo = pdo();
  $pdo->prepare("INSERT INTO dealer_leads (dealer_id, representative_id, name, email, phone, company, status, source, notes, next_action_at, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([
        $dealer_id,
        $representative_id ?: null,
        $name,
        $email !== '' ? $email : null,
        $phone !== '' ? $phone : null,
        $company !== '' ? $company : null,
        $status,
        $source !== '' ? $source : null,
        $notes !== '' ? $notes : null,
        $nextAction,
        now(),
        now(),
      ]);
  return (int)$pdo->lastInsertId();
}

function dealer_lead_get(int $lead_id, int $dealer_id): ?array {
  $st = pdo()->prepare("SELECT * FROM dealer_leads WHERE id=? AND dealer_id=? LIMIT 1");
  $st->execute([$lead_id, $dealer_id]);
  $row = $st->fetch();
  if (!$row) {
    return null;
  }
  return [
    'id' => (int)$row['id'],
    'dealer_id' => (int)$row['dealer_id'],
    'representative_id' => isset($row['representative_id']) ? (int)$row['representative_id'] : null,
    'name' => $row['name'] ?? '',
    'email' => $row['email'] ?? null,
    'phone' => $row['phone'] ?? null,
    'company' => $row['company'] ?? null,
    'status' => $row['status'] ?? DEALER_LEAD_STATUS_NEW,
    'source' => $row['source'] ?? null,
    'notes' => $row['notes'] ?? null,
    'last_contact_at' => $row['last_contact_at'] ?? null,
    'next_action_at' => $row['next_action_at'] ?? null,
    'created_at' => $row['created_at'] ?? null,
    'updated_at' => $row['updated_at'] ?? null,
  ];
}

function dealer_leads_list(int $dealer_id): array {
  $st = pdo()->prepare("SELECT * FROM dealer_leads WHERE dealer_id=? ORDER BY created_at DESC");
  $st->execute([$dealer_id]);
  $rows = [];
  foreach ($st as $row) {
    $rows[] = [
      'id' => (int)$row['id'],
      'dealer_id' => (int)$row['dealer_id'],
      'representative_id' => isset($row['representative_id']) ? (int)$row['representative_id'] : null,
      'name' => $row['name'] ?? '',
      'email' => $row['email'] ?? null,
      'phone' => $row['phone'] ?? null,
      'company' => $row['company'] ?? null,
      'status' => $row['status'] ?? DEALER_LEAD_STATUS_NEW,
      'source' => $row['source'] ?? null,
      'notes' => $row['notes'] ?? null,
      'last_contact_at' => $row['last_contact_at'] ?? null,
      'next_action_at' => $row['next_action_at'] ?? null,
      'created_at' => $row['created_at'] ?? null,
      'updated_at' => $row['updated_at'] ?? null,
    ];
  }
  return $rows;
}

function dealer_lead_update(int $lead_id, int $dealer_id, array $data, ?int $representative_id = null): void {
  $lead = dealer_lead_get($lead_id, $dealer_id);
  if (!$lead) {
    throw new InvalidArgumentException('Kayıt bulunamadı.');
  }
  $name = trim($data['name'] ?? $lead['name']);
  if ($name === '') {
    throw new InvalidArgumentException('Ad alanı boş bırakılamaz.');
  }
  $email = trim($data['email'] ?? ($lead['email'] ?? ''));
  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    throw new InvalidArgumentException('Geçerli bir e-posta adresi girin.');
  }
  $phone = trim($data['phone'] ?? ($lead['phone'] ?? ''));
  $company = trim($data['company'] ?? ($lead['company'] ?? ''));
  $status = $data['status'] ?? $lead['status'];
  if (!array_key_exists($status, dealer_lead_status_options())) {
    $status = $lead['status'];
  }
  $source = trim($data['source'] ?? ($lead['source'] ?? ''));
  $notes = trim($data['notes'] ?? ($lead['notes'] ?? ''));
  $nextAction = array_key_exists('next_action_at', $data) ? dealer_lead_parse_datetime($data['next_action_at']) : $lead['next_action_at'];
  $representativeValue = $representative_id ?: (isset($data['representative_id']) ? (int)$data['representative_id'] : ($lead['representative_id'] ?? null));

  pdo()->prepare("UPDATE dealer_leads SET name=?, email=?, phone=?, company=?, status=?, source=?, notes=?, next_action_at=?, representative_id=?, updated_at=? WHERE id=? AND dealer_id=?")
      ->execute([
        $name,
        $email !== '' ? $email : null,
        $phone !== '' ? $phone : null,
        $company !== '' ? $company : null,
        $status,
        $source !== '' ? $source : null,
        $notes !== '' ? $notes : null,
        $nextAction,
        $representativeValue ?: null,
        now(),
        $lead_id,
        $dealer_id,
      ]);
}

function dealer_lead_add_note(int $lead_id, int $dealer_id, string $note, ?string $contact_type = null, ?string $next_action_at = null, ?int $representative_id = null): void {
  $lead = dealer_lead_get($lead_id, $dealer_id);
  if (!$lead) {
    throw new InvalidArgumentException('Potansiyel müşteri bulunamadı.');
  }
  $note = trim($note);
  if ($note === '') {
    throw new InvalidArgumentException('Görüşme notu boş olamaz.');
  }
  $contact_type = $contact_type ? trim($contact_type) : null;
  $nextAction = dealer_lead_parse_datetime($next_action_at);

  $pdo = pdo();
  $pdo->prepare("INSERT INTO dealer_lead_notes (lead_id, representative_id, note, contact_type, next_action_at, created_at) VALUES (?,?,?,?,?,?)")
      ->execute([
        $lead_id,
        $representative_id ?: null,
        $note,
        $contact_type ?: null,
        $nextAction,
        now(),
      ]);

  $pdo->prepare("UPDATE dealer_leads SET last_contact_at=?, next_action_at=?, representative_id=?, updated_at=? WHERE id=?")
      ->execute([
        now(),
        $nextAction ?: $lead['next_action_at'],
        $representative_id ?: ($lead['representative_id'] ?? null),
        now(),
        $lead_id,
      ]);
}

function dealer_lead_notes(int $lead_id, int $dealer_id): array {
  $lead = dealer_lead_get($lead_id, $dealer_id);
  if (!$lead) {
    return [];
  }
  $st = pdo()->prepare("SELECT n.*, r.name AS representative_name FROM dealer_lead_notes n LEFT JOIN dealer_representatives r ON r.id = n.representative_id WHERE n.lead_id=? ORDER BY n.created_at DESC");
  $st->execute([$lead_id]);
  $rows = [];
  foreach ($st as $row) {
    $rows[] = [
      'id' => (int)$row['id'],
      'note' => $row['note'] ?? '',
      'contact_type' => $row['contact_type'] ?? null,
      'next_action_at' => $row['next_action_at'] ?? null,
      'created_at' => $row['created_at'] ?? null,
      'representative_name' => $row['representative_name'] ?? null,
    ];
  }
  return $rows;
}

function dealer_lead_status_counts(int $dealer_id): array {
  $summary = [];
  $st = pdo()->prepare("SELECT status, COUNT(*) AS c FROM dealer_leads WHERE dealer_id=? GROUP BY status");
  $st->execute([$dealer_id]);
  foreach ($st as $row) {
    $status = $row['status'] ?? DEALER_LEAD_STATUS_NEW;
    $summary[$status] = (int)($row['c'] ?? 0);
  }
  $ordered = [];
  foreach (dealer_lead_status_options() as $key => $_) {
    $ordered[$key] = (int)($summary[$key] ?? 0);
  }
  return $ordered;
}

function dealer_lead_upcoming_actions(int $dealer_id, int $limit = 5): array {
  $limit = max(1, $limit);
  $sql = "SELECT id, name, next_action_at, status FROM dealer_leads WHERE dealer_id=? AND next_action_at IS NOT NULL AND next_action_at >= ? ORDER BY next_action_at ASC LIMIT ?";
  $st = pdo()->prepare($sql);
  $st->bindValue(1, $dealer_id, PDO::PARAM_INT);
  $st->bindValue(2, date('Y-m-d 00:00:00'), PDO::PARAM_STR);
  $st->bindValue(3, $limit, PDO::PARAM_INT);
  $st->execute();
  $rows = [];
  foreach ($st as $row) {
    $rows[] = [
      'id' => (int)$row['id'],
      'name' => $row['name'] ?? '',
      'next_action_at' => $row['next_action_at'] ?? null,
      'status' => $row['status'] ?? DEALER_LEAD_STATUS_NEW,
    ];
  }
  return $rows;
}

function dealer_lead_recent_notes(int $dealer_id, int $limit = 5): array {
  $limit = max(1, $limit);
  $sql = "SELECT n.id, n.note, n.contact_type, n.created_at, l.name AS lead_name, r.name AS representative_name
          FROM dealer_lead_notes n
          INNER JOIN dealer_leads l ON l.id = n.lead_id
          LEFT JOIN dealer_representatives r ON r.id = n.representative_id
          WHERE l.dealer_id=?
          ORDER BY n.created_at DESC
          LIMIT ?";
  $st = pdo()->prepare($sql);
  $st->bindValue(1, $dealer_id, PDO::PARAM_INT);
  $st->bindValue(2, $limit, PDO::PARAM_INT);
  $st->execute();
  $rows = [];
  foreach ($st as $row) {
    $rows[] = [
      'id' => (int)$row['id'],
      'note' => $row['note'] ?? '',
      'contact_type' => $row['contact_type'] ?? null,
      'created_at' => $row['created_at'] ?? null,
      'lead_name' => $row['lead_name'] ?? '',
      'representative_name' => $row['representative_name'] ?? null,
    ];
  }
  return $rows;
}
