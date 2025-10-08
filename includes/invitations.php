<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';

function invitation_template_defaults(): array {
  return [
    'share_token' => '',
    'title' => 'Düğün Davetiyemiz',
    'subtitle' => 'Sevincimizi paylaşmaya davetlisiniz',
    'message' => "Birlikteliğimizi kutlayacağımız bu özel günde sizleri yanımızda görmek istiyoruz.\n\nbikara.com",
    'primary_color' => '#0ea5b5',
    'accent_color' => '#f8fafc',
    'button_label' => 'Katılımınızı Bildirin',
    'updated_at' => null,
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

function invitation_template_share_token_ensure(int $eventId, ?array $knownRow = null): string {
  $pdo = pdo();
  $existing = null;
  if ($knownRow !== null) {
    $existing = (string)($knownRow['share_token'] ?? '');
    if ($existing !== '') {
      return $existing;
    }
  }

  try {
    $st = $pdo->prepare("SELECT share_token FROM event_invitation_templates WHERE event_id=? LIMIT 1");
    $st->execute([$eventId]);
    $existing = (string)($st->fetchColumn() ?: '');
    if ($existing !== '') {
      return $existing;
    }
  } catch (Throwable $e) {
    $existing = '';
  }

  $token = bin2hex(random_bytes(16));
  $now = now();

  try {
    $up = $pdo->prepare("UPDATE event_invitation_templates SET share_token=:token, updated_at=COALESCE(updated_at, :now) WHERE event_id=:event_id");
    $up->execute([
      ':token' => $token,
      ':now' => $now,
      ':event_id' => $eventId,
    ]);
    if ($up->rowCount() > 0) {
      return $token;
    }
  } catch (Throwable $e) {
    // continue to insert defaults if row is missing
  }

  $defaults = invitation_template_defaults();
  try {
    $ins = $pdo->prepare("INSERT INTO event_invitation_templates (event_id, share_token, title, subtitle, message, primary_color, accent_color, button_label, created_at, updated_at) VALUES (:event_id, :token, :title, :subtitle, :message, :primary, :accent, :button, :created_at, :updated_at)");
    $ins->execute([
      ':event_id' => $eventId,
      ':token' => $token,
      ':title' => $defaults['title'],
      ':subtitle' => $defaults['subtitle'],
      ':message' => $defaults['message'],
      ':primary' => $defaults['primary_color'],
      ':accent' => $defaults['accent_color'],
      ':button' => $defaults['button_label'],
      ':created_at' => $now,
      ':updated_at' => $now,
    ]);
    return $token;
  } catch (Throwable $e) {
    try {
      $st = $pdo->prepare("SELECT share_token FROM event_invitation_templates WHERE event_id=? LIMIT 1");
      $st->execute([$eventId]);
      $existing = (string)($st->fetchColumn() ?: '');
      if ($existing !== '') {
        return $existing;
      }
    } catch (Throwable $e2) {
      // ignore final failure
    }
  }

  return $token;
}

function invitation_template_get(int $eventId): array {
  $defaults = invitation_template_defaults();
  try {
    $st = pdo()->prepare("SELECT title, subtitle, message, primary_color, accent_color, button_label, share_token, updated_at FROM event_invitation_templates WHERE event_id=? LIMIT 1");
    $st->execute([$eventId]);
    $row = $st->fetch();
    if ($row) {
      $row['message'] = invitation_require_branding((string)$row['message']);
      $row['share_token'] = invitation_template_share_token_ensure($eventId, $row);
      return array_merge($defaults, $row);
    }
  } catch (Throwable $e) {
    // ignore and return defaults
  }
  $defaults['share_token'] = invitation_template_share_token_ensure($eventId, null);
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
  $shareToken = invitation_template_share_token_ensure($eventId, null);
  $st = $pdo->prepare("INSERT INTO event_invitation_templates (event_id, share_token, title, subtitle, message, primary_color, accent_color, button_label, created_at, updated_at)
    VALUES (:event_id, :share_token, :title, :subtitle, :message, :primary, :accent, :button, :created_at, :updated_at)
    ON DUPLICATE KEY UPDATE
      title=VALUES(title),
      subtitle=VALUES(subtitle),
      message=VALUES(message),
      primary_color=VALUES(primary_color),
      accent_color=VALUES(accent_color),
      button_label=VALUES(button_label),
      share_token=COALESCE(share_token, VALUES(share_token)),
      updated_at=VALUES(updated_at)");
  $st->execute([
    ':event_id' => $eventId,
    ':share_token' => $shareToken,
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
    'share_token' => $shareToken,
    'updated_at' => $now,
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
  $lines[] = 'Kart: '.public_invitation_card_url((string)$contact['invite_token']);
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

function invitation_template_by_share_token(string $shareToken): ?array {
  $shareToken = trim($shareToken);
  if ($shareToken === '') {
    return null;
  }
  try {
    $st = pdo()->prepare("SELECT event_id FROM event_invitation_templates WHERE share_token=? LIMIT 1");
    $st->execute([$shareToken]);
    $eventId = (int)$st->fetchColumn();
    if ($eventId <= 0) {
      return null;
    }
    $template = invitation_template_get($eventId);
    $template['event_id'] = $eventId;
    $template['share_token'] = $shareToken;
    return $template;
  } catch (Throwable $e) {
    return null;
  }
}

function invitation_event_row(int $eventId): ?array {
  try {
    $st = pdo()->prepare("SELECT id, title, event_date FROM events WHERE id=? LIMIT 1");
    $st->execute([$eventId]);
    $row = $st->fetch();
    return $row ?: null;
  } catch (Throwable $e) {
    return null;
  }
}

function invitation_card_font_path(string $weight = 'regular'): ?string {
  static $cache = [];
  if (array_key_exists($weight, $cache)) {
    return $cache[$weight];
  }

  $base = realpath(__DIR__.'/../bin/fonts') ?: null;
  if (!$base) {
    $cache[$weight] = null;
    return null;
  }

  $map = [
    'regular' => $base.'/Inter-Regular.ttf',
    'semibold' => $base.'/Inter-SemiBold.ttf',
  ];

  $candidates = [];
  if (isset($map[$weight])) {
    $candidates[] = $map[$weight];
  }
  foreach ($map as $path) {
    if (!in_array($path, $candidates, true)) {
      $candidates[] = $path;
    }
  }

  foreach ($candidates as $path) {
    if (!is_file($path) || !is_readable($path)) {
      continue;
    }
    if (!invitation_card_font_is_usable($path)) {
      continue;
    }
    $cache[$weight] = $path;
    return $path;
  }

  $cache[$weight] = null;
  return null;
}

function invitation_card_font_is_usable(?string $fontPath): bool {
  if (!$fontPath || !function_exists('imagettfbbox')) {
    return false;
  }
  $probe = @imagettfbbox(16, 0, $fontPath, 'probe');
  return is_array($probe);
}

function invitation_card_text_metrics(float $size, string $font, string $text, int $angle = 0): array {
  $box = @imagettfbbox($size, $angle, $font, $text);
  if (!is_array($box)) {
    throw new RuntimeException('Metin ölçümü başarısız oldu.');
  }
  return [
    'box' => $box,
    'width' => (int)abs($box[2] - $box[0]),
    'height' => (int)abs($box[1] - $box[7]),
  ];
}

function invitation_card_draw_text(GdImage $img, float $size, int $x, int $y, int $color, string $font, string $text, int $angle = 0): void {
  $result = @imagettftext($img, $size, $angle, $x, $y, $color, $font, $text);
  if ($result === false) {
    throw new RuntimeException('Metin çizimi başarısız oldu.');
  }
}

function invitation_card_hex_to_rgb(?string $value, array $fallback): array {
  $value = is_string($value) ? trim($value) : '';
  if (preg_match('/^#?([0-9A-Fa-f]{6})$/', $value, $m)) {
    $hex = $m[1];
    return [
      hexdec(substr($hex, 0, 2)),
      hexdec(substr($hex, 2, 2)),
      hexdec(substr($hex, 4, 2)),
    ];
  }
  return $fallback;
}

function invitation_card_wrap_text(string $text, string $font, float $size, int $maxWidth): array {
  $text = trim(preg_replace(["/\r\n/", "/\r/"], "\n", $text));
  if ($text === '') {
    return [];
  }
  $lines = [];
  foreach (explode("\n", $text) as $paragraph) {
    $paragraph = trim($paragraph);
    if ($paragraph === '') {
      $lines[] = '';
      continue;
    }
    $words = preg_split('/\s+/u', $paragraph);
    $current = '';
    foreach ($words as $word) {
      $candidate = $current === '' ? $word : $current.' '.$word;
      $width = invitation_card_text_metrics($size, $font, $candidate)['width'];
      if ($width <= $maxWidth) {
        $current = $candidate;
        continue;
      }
      if ($current !== '') {
        $lines[] = $current;
        $current = $word;
      } else {
        $width = invitation_card_text_metrics($size, $font, $word)['width'];
        if ($width > $maxWidth) {
          $lines[] = $word;
          $current = '';
        } else {
          $current = $word;
        }
      }
    }
    if ($current !== '') {
      $lines[] = $current;
    }
    $lines[] = '';
  }
  if ($lines && end($lines) === '') {
    array_pop($lines);
  }
  return $lines;
}

function invitation_card_draw_lines(GdImage $img, array $lines, string $font, float $size, int $color, int $centerX, int $startY, float $lineHeight = 1.3): int {
  $y = $startY;
  foreach ($lines as $line) {
    if ($line === '') {
      $y += (int)round($size * $lineHeight);
      continue;
    }
    $metrics = invitation_card_text_metrics($size, $font, $line);
    $x = (int)round($centerX - ($metrics['width'] / 2));
    invitation_card_draw_text($img, $size, $x, $y, $color, $font, $line);
    $y += (int)round($size * $lineHeight);
  }
  return $y;
}

function invitation_card_draw_pill(GdImage $img, int $x1, int $y1, int $x2, int $y2, int $color): void {
  $radius = (int)floor(($y2 - $y1) / 2);
  if ($radius <= 0) {
    imagefilledrectangle($img, $x1, $y1, $x2, $y2, $color);
    return;
  }
  imagefilledellipse($img, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
  imagefilledellipse($img, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
  imagefilledrectangle($img, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
}

function invitation_card_render(array $template, array $event, ?array $contact = null): GdImage {
  $fontRegular = invitation_card_font_path('regular');
  $fontSemi = invitation_card_font_path('semibold') ?: $fontRegular;

  if (!$fontRegular || !invitation_card_font_is_usable($fontRegular) || !invitation_card_font_is_usable($fontSemi)) {
    return invitation_card_render_basic($template, $event, $contact);
  }

  $img = null;
  try {
    $width = 1080;
    $height = 1350;
    $img = imagecreatetruecolor($width, $height);
    imagealphablending($img, true);
    imagesavealpha($img, true);

    $primaryRgb = invitation_card_hex_to_rgb($template['primary_color'] ?? '#0ea5b5', [14, 165, 181]);
    $accentRgb = invitation_card_hex_to_rgb($template['accent_color'] ?? '#f8fafc', [248, 250, 252]);

    $accentColor = imagecolorallocate($img, $accentRgb[0], $accentRgb[1], $accentRgb[2]);
    $primaryColor = imagecolorallocate($img, $primaryRgb[0], $primaryRgb[1], $primaryRgb[2]);
    $white = imagecolorallocate($img, 255, 255, 255);
    $ink = imagecolorallocate($img, 15, 23, 42);
    $muted = imagecolorallocate($img, 94, 106, 131);
    $brand = imagecolorallocate($img, 148, 163, 184);

    imagefilledrectangle($img, 0, 0, $width, $height, $accentColor);
    imagefilledrectangle($img, 0, 0, $width, 480, $primaryColor);

    $overlay = imagecolorallocatealpha($img, 255, 255, 255, 90);
    imagefilledellipse($img, (int)round($width * 0.25), -120, 700, 600, $overlay);
    imagefilledellipse($img, (int)round($width * 0.85), -80, 820, 640, $overlay);

    $panelLeft = 64;
    $panelRight = $width - 64;
    $panelTop = 420;
    $panelBottom = $height - 180;
    imagefilledrectangle($img, $panelLeft, $panelTop, $panelRight, $panelBottom, $white);

    $title = trim((string)($template['title'] ?? ''));
    if ($title === '') {
      $title = 'Düğün Davetiyemiz';
    }
    $titleSize = 60;
    $maxTitleWidth = 840;
    while ($titleSize > 36) {
      $widthText = invitation_card_text_metrics($titleSize, $fontSemi, $title)['width'];
      if ($widthText <= $maxTitleWidth) {
        break;
      }
      $titleSize -= 2;
    }
    $titleWidth = invitation_card_text_metrics($titleSize, $fontSemi, $title)['width'];
    $titleX = (int)round(($width - $titleWidth) / 2);
    $titleY = 210;
    invitation_card_draw_text($img, $titleSize, $titleX, $titleY, $white, $fontSemi, $title);

    $subtitle = trim((string)($template['subtitle'] ?? ''));
    $eventDate = isset($event['event_date']) && $event['event_date'] ? (new DateTime($event['event_date']))->format('d.m.Y') : '';
    $subtitleLines = [];
    if ($subtitle !== '') {
      $subtitleLines[] = $subtitle;
    }
    if ($eventDate !== '') {
      $subtitleLines[] = 'Etkinlik Tarihi: '.$eventDate;
    }
    if ($subtitleLines) {
      $subtitleText = implode(' • ', $subtitleLines);
      $subtitleSize = 30;
      $maxSubtitleWidth = 900;
      while ($subtitleSize > 22) {
        $subtitleWidth = invitation_card_text_metrics($subtitleSize, $fontRegular, $subtitleText)['width'];
        if ($subtitleWidth <= $maxSubtitleWidth) {
          break;
        }
        $subtitleSize -= 1;
      }
      $subtitleWidth = invitation_card_text_metrics($subtitleSize, $fontRegular, $subtitleText)['width'];
      $subtitleX = (int)round(($width - $subtitleWidth) / 2);
      $subtitleY = $titleY + 60;
      invitation_card_draw_text($img, $subtitleSize, $subtitleX, $subtitleY, $white, $fontRegular, $subtitleText);
    }

    $cursorY = $panelTop + 120;
    $centerX = (int)round($width / 2);

    if ($contact && !empty($contact['name'])) {
      $recipient = trim((string)$contact['name']);
      if ($recipient !== '') {
        $recipientText = 'Sevgili '.$recipient;
        $recipientSize = 34;
        $recipientWidth = invitation_card_text_metrics($recipientSize, $fontSemi, $recipientText)['width'];
        if ($recipientWidth > 780) {
          $recipientSize = 30;
          $recipientWidth = invitation_card_text_metrics($recipientSize, $fontSemi, $recipientText)['width'];
        }
        $recipientX = (int)round($centerX - ($recipientWidth / 2));
        invitation_card_draw_text($img, $recipientSize, $recipientX, $cursorY, $ink, $fontSemi, $recipientText);
        $cursorY += (int)round($recipientSize * 1.6);
      }
    }

    $message = (string)($template['message'] ?? '');
    if (stripos($message, 'bikara.com') === false) {
      $message = invitation_require_branding($message);
    }
    $messageFontSize = 36;
    $maxMessageWidth = 760;
    $messageLines = invitation_card_wrap_text($message, $fontRegular, $messageFontSize, $maxMessageWidth);
    while (count($messageLines) > 9 && $messageFontSize > 26) {
      $messageFontSize -= 2;
      $messageLines = invitation_card_wrap_text($message, $fontRegular, $messageFontSize, $maxMessageWidth);
    }
    if ($messageLines) {
      $cursorY = invitation_card_draw_lines($img, $messageLines, $fontRegular, $messageFontSize, $muted, $centerX, $cursorY, 1.4);
    }

    $cursorY += 40;
    $buttonLabel = trim((string)($template['button_label'] ?? ''));
    if ($buttonLabel === '') {
      $buttonLabel = 'Katılımınızı Bildirin';
    }
    $buttonFontSize = 30;
    $buttonTextWidth = invitation_card_text_metrics($buttonFontSize, $fontSemi, $buttonLabel)['width'];
    $buttonPadding = 140;
    $buttonWidth = min(760, $buttonTextWidth + $buttonPadding);
    $buttonHeight = 94;
    $buttonX1 = (int)round($centerX - ($buttonWidth / 2));
    $buttonY1 = $cursorY;
    $buttonX2 = $buttonX1 + $buttonWidth;
    $buttonY2 = $buttonY1 + $buttonHeight;
    invitation_card_draw_pill($img, $buttonX1, $buttonY1, $buttonX2, $buttonY2, $primaryColor);
    $buttonTextX = (int)round($centerX - ($buttonTextWidth / 2));
    $buttonBaseline = $buttonY1 + (int)round($buttonHeight / 2 + $buttonFontSize / 2.4);
    invitation_card_draw_text($img, $buttonFontSize, $buttonTextX, $buttonBaseline, $white, $fontSemi, $buttonLabel);

    $brandY = $panelBottom - 60;
    invitation_card_draw_text($img, 26, (int)round($centerX - 120), $brandY, $brand, $fontSemi, 'bikara.com');

    return $img;
  } catch (RuntimeException $e) {
    if ($img instanceof GdImage) {
      imagedestroy($img);
    }
    return invitation_card_render_basic($template, $event, $contact);
  }
}

function invitation_card_render_basic(array $template, array $event, ?array $contact = null): GdImage {
  $width = 1080;
  $height = 1350;
  $img = imagecreatetruecolor($width, $height);
  imagealphablending($img, true);
  imagesavealpha($img, true);

  $primaryRgb = invitation_card_hex_to_rgb($template['primary_color'] ?? '#0ea5b5', [14, 165, 181]);
  $accentRgb = invitation_card_hex_to_rgb($template['accent_color'] ?? '#f8fafc', [248, 250, 252]);

  $accentColor = imagecolorallocate($img, $accentRgb[0], $accentRgb[1], $accentRgb[2]);
  $primaryColor = imagecolorallocate($img, $primaryRgb[0], $primaryRgb[1], $primaryRgb[2]);
  $textColor = imagecolorallocate($img, 30, 41, 59);
  $muted = imagecolorallocate($img, 71, 85, 105);
  $white = imagecolorallocate($img, 255, 255, 255);

  imagefilledrectangle($img, 0, 0, $width, $height, $accentColor);
  imagefilledrectangle($img, 0, 0, $width, 360, $primaryColor);

  $font = 5;
  $fontWidth = imagefontwidth($font);
  $fontHeight = imagefontheight($font);

  $title = trim((string)($template['title'] ?? ''));
  if ($title === '') {
    $title = 'Düğün Davetiyemiz';
  }
  $cursorY = 110;
  $cursorY = invitation_card_basic_draw_centered($img, $font, strtoupper($title), $white, $width, $cursorY, $fontHeight + 10);

  $subtitleParts = [];
  $subtitle = trim((string)($template['subtitle'] ?? ''));
  if ($subtitle !== '') {
    $subtitleParts[] = $subtitle;
  }
  $eventDate = isset($event['event_date']) && $event['event_date'] ? (new DateTime($event['event_date']))->format('d.m.Y') : '';
  if ($eventDate !== '') {
    $subtitleParts[] = 'Etkinlik Tarihi: '.$eventDate;
  }
  if ($subtitleParts) {
    $cursorY = invitation_card_basic_draw_centered($img, $font, implode(' • ', $subtitleParts), $white, $width, $cursorY, $fontHeight + 6);
  }

  $panelTop = 420;
  imagefilledrectangle($img, 64, $panelTop, $width - 64, $height - 160, $white);

  $cursorY = $panelTop + 80;
  if ($contact && !empty($contact['name'])) {
    $recipient = trim((string)$contact['name']);
    if ($recipient !== '') {
      $cursorY = invitation_card_basic_draw_centered($img, $font, 'Sevgili '.$recipient, $textColor, $width, $cursorY, $fontHeight + 10);
    }
  }

  $message = (string)($template['message'] ?? '');
  if (stripos($message, 'bikara.com') === false) {
    $message = invitation_require_branding($message);
  }
  $wrapWidth = max(20, (int)floor(760 / $fontWidth));
  $messageLines = [];
  foreach (explode("\n", trim(preg_replace(["/\r\n/", "/\r/"], "\n", $message))) as $paragraph) {
    $paragraph = trim($paragraph);
    if ($paragraph === '') {
      $messageLines[] = '';
      continue;
    }
    foreach (explode("\n", wordwrap($paragraph, $wrapWidth, "\n", true)) as $line) {
      $messageLines[] = $line;
    }
    $messageLines[] = '';
  }
  if ($messageLines && end($messageLines) === '') {
    array_pop($messageLines);
  }
  foreach ($messageLines as $line) {
    if ($line === '') {
      $cursorY += (int)round($fontHeight * 1.6);
      continue;
    }
    $cursorY = invitation_card_basic_draw_centered($img, $font, $line, $muted, $width, $cursorY, (int)round($fontHeight * 1.6));
  }

  $cursorY += 40;
  $buttonLabel = trim((string)($template['button_label'] ?? ''));
  if ($buttonLabel === '') {
    $buttonLabel = 'Katılımınızı Bildirin';
  }
  $buttonWidth = min(760, $fontWidth * strlen($buttonLabel) + 200);
  $buttonHeight = 80;
  $buttonX1 = (int)round(($width - $buttonWidth) / 2);
  $buttonY1 = $cursorY;
  $buttonX2 = $buttonX1 + $buttonWidth;
  $buttonY2 = $buttonY1 + $buttonHeight;
  invitation_card_draw_pill($img, $buttonX1, $buttonY1, $buttonX2, $buttonY2, $primaryColor);
  $buttonTextWidth = $fontWidth * strlen($buttonLabel);
  $buttonTextX = (int)round(($width - $buttonTextWidth) / 2);
  imagestring($img, $font, $buttonTextX, $buttonY1 + (int)round(($buttonHeight - $fontHeight) / 2), $buttonLabel, $white);

  $brandText = 'bikara.com';
  $brandWidth = $fontWidth * strlen($brandText);
  $brandX = (int)round(($width - $brandWidth) / 2);
  $brandY = ($height - 200);
  imagestring($img, $font, $brandX, $brandY, $brandText, $muted);

  return $img;
}

function invitation_card_basic_draw_centered(GdImage $img, int $font, string $text, int $color, int $width, int $y, int $lineGap): int {
  $text = trim($text);
  $fontWidth = imagefontwidth($font);
  $fontHeight = imagefontheight($font);
  $textWidth = $fontWidth * strlen($text);
  $x = (int)max(0, round(($width - $textWidth) / 2));
  imagestring($img, $font, $x, $y, $text, $color);
  return $y + $fontHeight + $lineGap;
}
