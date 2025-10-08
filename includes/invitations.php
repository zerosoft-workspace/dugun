<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';

function invitation_template_defaults(): array {
  return [
    'title' => 'Düğün Davetiyemiz',
    'subtitle' => 'Sevincimizi paylaşmaya davetlisiniz',
    'message' => "Birlikteliğimizi kutlayacağımız bu özel günde sizleri yanımızda görmek istiyoruz.\n\nbikara.com",
    'primary_color' => '#0ea5b5',
    'accent_color' => '#f8fafc',
    'button_label' => 'Katılımınızı Bildirin',
  ];
}

function invitation_require_branding(string $text): string {
  if (stripos($text, 'bikara.com') === false) {
    $text = rtrim($text);
    $suffix = $text === '' ? '' : "\n\n";
    $text .= $suffix.'bikara.com';
  }
  return $text;
}

function invitation_template_get(int $eventId): array {
  $defaults = invitation_template_defaults();
  try {
    $st = pdo()->prepare("SELECT title, subtitle, message, primary_color, accent_color, button_label FROM event_invitation_templates WHERE event_id=? LIMIT 1");
    $st->execute([$eventId]);
    $row = $st->fetch();
    if ($row) {
      $row['message'] = invitation_require_branding((string)$row['message']);
      return array_merge($defaults, $row);
    }
  } catch (Throwable $e) {
    // ignore and return defaults
  }
  return $defaults;
}

function invitation_color_or_default(?string $value, string $fallback): string {
  $value = is_string($value) ? trim($value) : '';
  if ($value === '') {
    return $fallback;
  }
  if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
    return $fallback;
  }
  return strtolower($value);
}

function invitation_template_save(int $eventId, array $data): array {
  $defaults = invitation_template_defaults();
  $title = trim((string)($data['title'] ?? $defaults['title']));
  if ($title === '') {
    $title = $defaults['title'];
  }
  $subtitle = trim((string)($data['subtitle'] ?? ''));
  $subtitle = $subtitle !== '' ? $subtitle : null;
  $message = (string)($data['message'] ?? $defaults['message']);
  $message = invitation_require_branding($message);
  $primary = invitation_color_or_default($data['primary_color'] ?? null, $defaults['primary_color']);
  $accent = invitation_color_or_default($data['accent_color'] ?? null, $defaults['accent_color']);
  $button = trim((string)($data['button_label'] ?? ''));
  $button = $button !== '' ? mb_substr($button, 0, 120) : null;

  $pdo = pdo();
  $now = now();
  $st = $pdo->prepare("INSERT INTO event_invitation_templates (event_id, title, subtitle, message, primary_color, accent_color, button_label, created_at, updated_at)
    VALUES (:event_id, :title, :subtitle, :message, :primary, :accent, :button, :created_at, :updated_at)
    ON DUPLICATE KEY UPDATE
      title=VALUES(title),
      subtitle=VALUES(subtitle),
      message=VALUES(message),
      primary_color=VALUES(primary_color),
      accent_color=VALUES(accent_color),
      button_label=VALUES(button_label),
      updated_at=VALUES(updated_at)");
  $st->execute([
    ':event_id' => $eventId,
    ':title' => $title,
    ':subtitle' => $subtitle,
    ':message' => $message,
    ':primary' => $primary,
    ':accent' => $accent,
    ':button' => $button,
    ':created_at' => $now,
    ':updated_at' => $now,
  ]);

  return [
    'title' => $title,
    'subtitle' => $subtitle,
    'message' => $message,
    'primary_color' => $primary,
    'accent_color' => $accent,
    'button_label' => $button,
  ] + $defaults;
}

function invitation_contact_generate_token(PDO $pdo): string {
  do {
    $token = bin2hex(random_bytes(16));
    $st = $pdo->prepare("SELECT 1 FROM event_invitation_contacts WHERE invite_token=? LIMIT 1");
    $st->execute([$token]);
    $exists = (bool)$st->fetchColumn();
  } while ($exists);
  return $token;
}

function invitation_normalize_phone(?string $phone): string {
  $digits = preg_replace('/\D+/', '', (string)$phone);
  if ($digits === '') {
    return '';
  }
  if (strpos($digits, '00') === 0) {
    $digits = substr($digits, 2);
  }
  if (strpos($digits, '0') === 0 && strlen($digits) === 11) {
    $digits = substr($digits, 1);
  }
  if (strlen($digits) === 10) {
    $digits = '90'.$digits;
  }
  if (strpos($digits, '9') === 0 && strlen($digits) === 11) {
    $digits = '90'.substr($digits, 1);
  }
  return $digits;
}

function invitation_contact_create(int $eventId, string $name, ?string $email, ?string $phone): array {
  $name = trim($name);
  if ($name === '') {
    throw new InvalidArgumentException('İsim gerekli.');
  }
  $email = is_string($email) ? trim($email) : '';
  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    throw new InvalidArgumentException('Geçerli bir e-posta adresi girin.');
  }
  $phoneRaw = is_string($phone) ? trim($phone) : '';
  $phoneNormalized = invitation_normalize_phone($phoneRaw);

  $pdo = pdo();
  $token = invitation_contact_generate_token($pdo);
  $now = now();
  $st = $pdo->prepare("INSERT INTO event_invitation_contacts (event_id, name, email, phone, phone_normalized, invite_token, created_at)
    VALUES (:event_id, :name, :email, :phone, :phone_normalized, :token, :created_at)");
  $st->execute([
    ':event_id' => $eventId,
    ':name' => $name,
    ':email' => $email !== '' ? $email : null,
    ':phone' => $phoneRaw !== '' ? $phoneRaw : null,
    ':phone_normalized' => $phoneNormalized !== '' ? $phoneNormalized : null,
    ':token' => $token,
    ':created_at' => $now,
  ]);

  $id = (int)$pdo->lastInsertId();
  return invitation_contact_by_id($eventId, $id);
}

function invitation_contacts_list(int $eventId): array {
  $st = pdo()->prepare("SELECT * FROM event_invitation_contacts WHERE event_id=? ORDER BY name ASC, id ASC");
  $st->execute([$eventId]);
  return $st->fetchAll();
}

function invitation_contact_by_id(int $eventId, int $contactId): ?array {
  $st = pdo()->prepare("SELECT * FROM event_invitation_contacts WHERE id=? AND event_id=? LIMIT 1");
  $st->execute([$contactId, $eventId]);
  $row = $st->fetch();
  return $row ?: null;
}

function invitation_contact_by_token(string $token): ?array {
  $token = trim($token);
  if ($token === '') {
    return null;
  }
  $st = pdo()->prepare("SELECT * FROM event_invitation_contacts WHERE invite_token=? LIMIT 1");
  $st->execute([$token]);
  $row = $st->fetch();
  return $row ?: null;
}

function invitation_contact_update(int $eventId, int $contactId, string $name, ?string $email, ?string $phone, ?string $newPassword = null): void {
  $existing = invitation_contact_by_id($eventId, $contactId);
  if (!$existing) {
    throw new InvalidArgumentException('Davetli bulunamadı.');
  }
  $name = trim($name);
  if ($name === '') {
    throw new InvalidArgumentException('İsim gerekli.');
  }
  $email = is_string($email) ? trim($email) : '';
  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    throw new InvalidArgumentException('Geçerli bir e-posta adresi girin.');
  }
  $phoneRaw = is_string($phone) ? trim($phone) : '';
  $phoneNormalized = invitation_normalize_phone($phoneRaw);

  $timestamp = now();
  $set = [
    'name' => $name,
    'email' => $email !== '' ? $email : null,
    'phone' => $phoneRaw !== '' ? $phoneRaw : null,
    'phone_normalized' => $phoneNormalized !== '' ? $phoneNormalized : null,
    'updated_at' => $timestamp,
  ];
  if ($newPassword !== null && $newPassword !== '') {
    $set['password_hash'] = password_hash($newPassword, PASSWORD_BCRYPT);
    $set['password_set_at'] = $timestamp;
  }

  $parts = [];
  $params = [];
  foreach ($set as $col => $value) {
    $parts[] = "$col=?";
    $params[] = $value;
  }
  $params[] = $contactId;
  $params[] = $eventId;

  $sql = "UPDATE event_invitation_contacts SET ".implode(',', $parts)." WHERE id=? AND event_id=?";
  $st = pdo()->prepare($sql);
  $st->execute($params);
}

function invitation_contact_delete(int $eventId, int $contactId): void {
  $st = pdo()->prepare("DELETE FROM event_invitation_contacts WHERE id=? AND event_id=? LIMIT 1");
  $st->execute([$contactId, $eventId]);
}

function invitation_contact_mark_sent(int $eventId, int $contactId): void {
  $st = pdo()->prepare("UPDATE event_invitation_contacts SET last_sent_at=?, send_count=send_count+1, updated_at=? WHERE id=? AND event_id=?");
  $now = now();
  $st->execute([$now, $now, $contactId, $eventId]);
}

function invitation_contact_touch_view(int $contactId): void {
  $st = pdo()->prepare("UPDATE event_invitation_contacts SET last_viewed_at=?, updated_at=? WHERE id=?");
  $now = now();
  $st->execute([$now, $now, $contactId]);
}

function invitation_contact_set_credentials(int $eventId, int $contactId, string $email, string $password): void {
  $email = trim($email);
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    throw new InvalidArgumentException('Geçerli bir e-posta yazın.');
  }
  $password = trim($password);
  if ($password === '' || strlen($password) < 6) {
    throw new InvalidArgumentException('Şifre en az 6 karakter olmalı.');
  }
  $contact = invitation_contact_by_id($eventId, $contactId);
  if (!$contact) {
    throw new InvalidArgumentException('Davetli bulunamadı.');
  }
  invitation_contact_update($eventId, $contactId, (string)$contact['name'], $email, (string)($contact['phone'] ?? ''), $password);
}

function invitation_contact_attempt_login(array $contact, string $email, string $password): bool {
  $email = trim($email);
  $password = trim($password);
  if ($email === '' || $password === '') {
    return false;
  }
  $storedEmail = is_string($contact['email'] ?? null) ? trim($contact['email']) : '';
  if ($storedEmail === '' || strcasecmp($storedEmail, $email) !== 0) {
    return false;
  }
  $hash = $contact['password_hash'] ?? '';
  if ($hash === null || $hash === '' || !password_verify($password, $hash)) {
    return false;
  }
  return true;
}

function invitation_contact_session_key(int $contactId): string {
  return '__invite_contact_'.(int)$contactId;
}

function invitation_contact_set_session(int $contactId): void {
  $_SESSION[invitation_contact_session_key($contactId)] = true;
}

function invitation_contact_clear_session(int $contactId): void {
  unset($_SESSION[invitation_contact_session_key($contactId)]);
}

function invitation_contact_refresh(int $contactId): ?array {
  $st = pdo()->prepare("SELECT * FROM event_invitation_contacts WHERE id=? LIMIT 1");
  $st->execute([$contactId]);
  $row = $st->fetch();
  return $row ?: null;
}

function invitation_contact_whatsapp_url(array $contact, array $template, array $event): ?string {
  $number = (string)($contact['phone_normalized'] ?? '');
  if ($number === '') {
    return null;
  }
  $url = public_invitation_url((string)$contact['invite_token']);
  $lines = [];
  $lines[] = trim((string)$template['title']);
  $subtitle = trim((string)($template['subtitle'] ?? ''));
  if ($subtitle !== '') {
    $lines[] = $subtitle;
  }
  $lines[] = trim((string)$template['message']);
  $lines[] = $url;
  $text = implode("\n\n", array_filter($lines));
  return 'https://wa.me/'.$number.'?text='.rawurlencode($text);
}

function invitation_contact_email_subject(array $template, array $event): string {
  $title = trim((string)$template['title']);
  $eventTitle = trim((string)($event['title'] ?? '')); 
  if ($eventTitle !== '' && stripos($title, $eventTitle) === false) {
    return $eventTitle.' — '.$title;
  }
  return $title;
}

function invitation_contact_email_body(array $template, array $event, string $inviteUrl): string {
  $title = h(trim((string)$template['title']));
  $subtitle = trim((string)($template['subtitle'] ?? ''));
  $subtitleHtml = $subtitle !== '' ? '<p style="margin:0;color:#0f172a;font-size:16px;">'.h($subtitle).'</p>' : '';
  $message = nl2br(h(trim((string)$template['message'])));
  $eventDate = isset($event['event_date']) && $event['event_date'] ? h($event['event_date']) : '';
  $buttonLabel = trim((string)($template['button_label'] ?? 'Davetiyeyi Görüntüle'));
  if ($buttonLabel === '') {
    $buttonLabel = 'Davetiyeyi Görüntüle';
  }
  $primary = invitation_color_or_default($template['primary_color'] ?? null, '#0ea5b5');
  $accent = invitation_color_or_default($template['accent_color'] ?? null, '#f8fafc');
  $html = '<div style="background:#f8fafc;padding:32px;font-family:Segoe UI,Helvetica Neue,Arial,sans-serif;color:#0f172a;">';
  $html .= '<div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid rgba(14,165,181,.15);">';
  $html .= '<div style="background:'.h($accent).';padding:28px 32px 18px 32px;">';
  $html .= '<h1 style="margin:0;font-size:24px;color:#0f172a;">'.$title.'</h1>';
  $html .= $subtitleHtml;
  if ($eventDate !== '') {
    $html .= '<p style="margin-top:12px;color:#475569;font-size:14px;">Etkinlik Tarihi: '.$eventDate.'</p>';
  }
  $html .= '</div>';
  $html .= '<div style="padding:28px 32px;font-size:15px;line-height:1.6;color:#1f2937;">'.$message.'</div>';
  $html .= '<div style="padding:0 32px 32px 32px;text-align:center;">';
  $html .= '<a href="'.h($inviteUrl).'" style="display:inline-block;padding:12px 28px;background:'.h($primary).';color:#fff;border-radius:999px;font-weight:600;text-decoration:none;">'.h($buttonLabel).'</a>';
  $html .= '<p style="margin-top:16px;font-size:12px;color:#94a3b8;">bikara.com</p>';
  $html .= '</div></div></div>';
  return $html;
}
