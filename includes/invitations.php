<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/invitation_font_data.php';

function invitation_theme_options(): array {
  return [
    'wedding' => [
      'label' => 'Düğün',
      'description' => 'Pastel tonlarda zarif ve romantik davetiye tasarımı.',
      'defaults' => [
        'title' => 'Düğün Davetiyemiz',
        'subtitle' => 'Sevincimizi paylaşmaya davetlisiniz',
        'message' => "Çünkü sevgimizi paylaşırken, Şehrin ışıkları altında, Özel günümüzde sizleri aramızda görmek istiyoruz. Aşkımıza, neşemize ve bütün sevdiklerimize İyi ki varsınız diyoruz. Mutluluğumuzu birlikte çoğaltalım.\n\nbikara.com",
        'primary_color' => '#c2788f',
        'accent_color' => '#fdf2f8',
        'button_label' => 'Katılımınızı Bildirin',
      ],
    ],
    'kina' => [
      'label' => 'Kına Gecesi',
      'description' => 'Geleneksel kına gecesine sıcak ve altın vurgulu bir görünüm.',
      'defaults' => [
        'title' => 'Kına Gecemize Davet',
        'subtitle' => 'Gecemizin renklerini birlikte yaşayalım',
        'message' => "Kına gecemizde geleneksel coşkumuzu paylaşmak için sabırsızlanıyoruz. Bu özel gecemizde sizi de aramızda görmek istiyoruz.\n\nbikara.com",
        'primary_color' => '#b45309',
        'accent_color' => '#fef3c7',
        'button_label' => 'Katılımınızı Bildirin',
      ],
    ],
    'engagement' => [
      'label' => 'Nişan',
      'description' => 'Modern mor tonlarıyla şık bir nişan kartı.',
      'defaults' => [
        'title' => 'Nişan Törenimize Davet',
        'subtitle' => 'Bu heyecanı birlikte paylaşalım',
        'message' => "Söz ve nişan törenimizde bu özel anı birlikte kutlamak için sizi bekliyoruz. Sevgimizi paylaşacağımız bu günde yanımızda olun.\n\nbikara.com",
        'primary_color' => '#7c3aed',
        'accent_color' => '#ede9fe',
        'button_label' => 'Katılımınızı Bildirin',
      ],
    ],
    'celebration' => [
      'label' => 'Kutlama',
      'description' => 'Doğum günü, baby shower ve tüm kutlamalar için enerjik bir seçenek.',
      'defaults' => [
        'title' => 'Kutlamamıza Davetlisiniz',
        'subtitle' => 'Neşemizi paylaşmaya hazır mısınız?',
        'message' => "Kutlamamızda sevincimizi paylaşmak için sizleri aramızda görmek istiyoruz. Birlikte güzel anılar biriktirelim.\n\nbikara.com",
        'primary_color' => '#0ea5b5',
        'accent_color' => '#e0f2fe',
        'button_label' => 'Kutlamaya Katıl',
      ],
    ],
  ];
}

function invitation_template_normalize_theme(?string $theme): string {
  $options = invitation_theme_options();
  $theme = is_string($theme) ? strtolower(trim($theme)) : '';
  if ($theme === '' || !isset($options[$theme])) {
    return 'wedding';
  }
  return $theme;
}

function invitation_template_defaults(?string $theme = null): array {
  $themeKey = invitation_template_normalize_theme($theme);
  $options = invitation_theme_options();
  $base = [
    'share_token' => '',
    'theme' => $themeKey,
    'title' => 'Düğün Davetiyemiz',
    'subtitle' => 'Sevincimizi paylaşmaya davetlisiniz',
    'message' => "Çünkü sevgimizi paylaşırken, Şehrin ışıkları altında, Özel günümüzde sizleri aramızda görmek istiyoruz. Aşkımıza, neşemize ve bütün sevdiklerimize İyi ki varsınız diyoruz. Mutluluğumuzu birlikte çoğaltalım.\n\nbikara.com",
    'primary_color' => '#0ea5b5',
    'accent_color' => '#f8fafc',
    'button_label' => 'Katılımınızı Bildirin',
    'updated_at' => null,
  ];
  $themeDefaults = $options[$themeKey]['defaults'] ?? [];
  if ($themeDefaults) {
    $themeDefaults['message'] = invitation_require_branding((string)($themeDefaults['message'] ?? ''));
  }
  $merged = array_merge($base, $themeDefaults);
  $merged['message'] = invitation_require_branding((string)$merged['message']);
  return $merged;
}

function invitation_template_theme(array $template): string {
  return invitation_template_normalize_theme($template['theme'] ?? null);
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
    $ins = $pdo->prepare("INSERT INTO event_invitation_templates (event_id, share_token, theme, title, subtitle, message, primary_color, accent_color, button_label, created_at, updated_at) VALUES (:event_id, :token, :theme, :title, :subtitle, :message, :primary, :accent, :button, :created_at, :updated_at)");
    $ins->execute([
      ':event_id' => $eventId,
      ':token' => $token,
      ':theme' => $defaults['theme'],
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
    $st = pdo()->prepare("SELECT theme, title, subtitle, message, primary_color, accent_color, button_label, share_token, updated_at FROM event_invitation_templates WHERE event_id=? LIMIT 1");
    $st->execute([$eventId]);
    $row = $st->fetch();
    if ($row) {
      $row['theme'] = invitation_template_normalize_theme($row['theme'] ?? $defaults['theme']);
      $row['message'] = invitation_require_branding((string)$row['message']);
      $row['share_token'] = invitation_template_share_token_ensure($eventId, $row);
      $defaults = invitation_template_defaults($row['theme']);
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

function invitation_template_save(int $eventId, array $data, ?array $current = null): array {
  $current = $current ?: invitation_template_get($eventId);
  $selectedTheme = invitation_template_normalize_theme($data['theme'] ?? ($current['theme'] ?? null));
  $defaults = invitation_template_defaults($selectedTheme);

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
  $st = $pdo->prepare("INSERT INTO event_invitation_templates (event_id, share_token, theme, title, subtitle, message, primary_color, accent_color, button_label, created_at, updated_at)
    VALUES (:event_id, :share_token, :theme, :title, :subtitle, :message, :primary, :accent, :button, :created_at, :updated_at)
    ON DUPLICATE KEY UPDATE
      theme=VALUES(theme),
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
    ':theme' => $selectedTheme,
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
    'theme' => $selectedTheme,
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

function invitation_card_font_bootstrap(): ?string {
  static $attempted = false;
  static $directory = null;

  if ($attempted) {
    return $directory;
  }

  $attempted = true;
  $storageBase = realpath(__DIR__.'/../storage') ?: (__DIR__.'/../storage');
  $targetDir = rtrim($storageBase, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'invitation-fonts';

  if (!is_dir($targetDir)) {
    @mkdir($targetDir, 0755, true);
  }

  if (!is_dir($targetDir) || !is_writable($targetDir)) {
    return $directory = null;
  }

  $fonts = [
    'inter-regular' => 'Inter-Regular.ttf',
    'inter-semibold' => 'Inter-SemiBold.ttf',
  ];

  foreach ($fonts as $variant => $filename) {
    $path = $targetDir.DIRECTORY_SEPARATOR.$filename;
    $existingSize = is_file($path) ? filesize($path) : 0;
    if ($existingSize > 1024) {
      continue;
    }
    $payload = invitation_font_payload($variant);
    if ($payload === null) {
      continue;
    }
    @file_put_contents($path, $payload, LOCK_EX);
  }

  foreach ($fonts as $filename) {
    $path = $targetDir.DIRECTORY_SEPARATOR.$filename;
    if (!is_file($path) || filesize($path) <= 1024) {
      return $directory = null;
    }
  }

  return $directory = (realpath($targetDir) ?: $targetDir);
}

function invitation_card_font_path(string $weight = 'regular'): ?string {
  static $cache = [];
  $weightKey = strtolower($weight);
  if (array_key_exists($weightKey, $cache)) {
    return $cache[$weightKey];
  }

  $filenameMap = [
    'regular' => 'Inter-Regular.ttf',
    'semibold' => 'Inter-SemiBold.ttf',
    'bold' => 'Inter-SemiBold.ttf',
  ];

  $filename = $filenameMap[$weightKey] ?? $filenameMap['regular'];
  $candidates = [];

  $base = realpath(__DIR__.'/../bin/fonts') ?: null;
  if ($base) {
    $candidates[] = $base.DIRECTORY_SEPARATOR.$filename;
  }

  $storageFonts = realpath(__DIR__.'/../storage/invitation-fonts') ?: null;
  if ($storageFonts) {
    $candidates[] = $storageFonts.DIRECTORY_SEPARATOR.$filename;
  }

  $bootstrapped = invitation_card_font_bootstrap();
  if ($bootstrapped) {
    $path = rtrim($bootstrapped, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;
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
    return $cache[$weightKey] = $path;
  }

  return $cache[$weightKey] = null;
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

function invitation_card_draw_round_rect(GdImage $img, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void {
  if ($radius <= 0) {
    imagefilledrectangle($img, $x1, $y1, $x2, $y2, $color);
    return;
  }
  imagefilledrectangle($img, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
  imagefilledrectangle($img, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
  imagefilledellipse($img, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
  imagefilledellipse($img, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
  imagefilledellipse($img, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
  imagefilledellipse($img, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
}

function invitation_card_draw_overlays(GdImage $img, array $overlays): void {
  foreach ($overlays as $overlay) {
    $type = $overlay['type'] ?? '';
    switch ($type) {
      case 'ellipse':
        $rgb = $overlay['rgb'] ?? [255, 255, 255];
        $alpha = max(0, min(127, (int)($overlay['alpha'] ?? 90)));
        $color = imagecolorallocatealpha($img, $rgb[0], $rgb[1], $rgb[2], $alpha);
        imagefilledellipse(
          $img,
          (int)round($overlay['x'] ?? 0),
          (int)round($overlay['y'] ?? 0),
          (int)round($overlay['w'] ?? 0),
          (int)round($overlay['h'] ?? 0),
          $color
        );
        break;
      case 'polygon':
        $points = $overlay['points'] ?? [];
        if (count($points) >= 6) {
          $rgb = $overlay['rgb'] ?? [255, 255, 255];
          $alpha = max(0, min(127, (int)($overlay['alpha'] ?? 90)));
          $color = imagecolorallocatealpha($img, $rgb[0], $rgb[1], $rgb[2], $alpha);
          $intPoints = array_map('intval', $points);
          imagefilledpolygon($img, $intPoints, (int)(count($intPoints) / 2), $color);
        }
        break;
      case 'rectangle':
        $rgb = $overlay['rgb'] ?? [255, 255, 255];
        $alpha = max(0, min(127, (int)($overlay['alpha'] ?? 90)));
        $color = imagecolorallocatealpha($img, $rgb[0], $rgb[1], $rgb[2], $alpha);
        imagefilledrectangle(
          $img,
          (int)round($overlay['x1'] ?? 0),
          (int)round($overlay['y1'] ?? 0),
          (int)round($overlay['x2'] ?? 0),
          (int)round($overlay['y2'] ?? 0),
          $color
        );
        break;
      case 'dots':
        $dots = $overlay['dots'] ?? [];
        foreach ($dots as $dot) {
          $rgb = $dot['rgb'] ?? [255, 255, 255];
          $alpha = max(0, min(127, (int)($dot['alpha'] ?? 80)));
          $color = imagecolorallocatealpha($img, $rgb[0], $rgb[1], $rgb[2], $alpha);
          $size = max(4, (int)round($dot['size'] ?? 12));
          imagefilledellipse($img, (int)round($dot['x'] ?? 0), (int)round($dot['y'] ?? 0), $size, $size, $color);
        }
        break;
    }
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

function invitation_card_mix_color(array $a, array $b, float $ratio): array {
  $ratio = max(0, min(1, $ratio));
  return [
    (int)round($a[0] * (1 - $ratio) + $b[0] * $ratio),
    (int)round($a[1] * (1 - $ratio) + $b[1] * $ratio),
    (int)round($a[2] * (1 - $ratio) + $b[2] * $ratio),
  ];
}

function invitation_card_lighten(array $rgb, float $amount): array {
  return invitation_card_mix_color($rgb, [255, 255, 255], max(0, min(1, $amount)));
}

function invitation_card_darken(array $rgb, float $amount): array {
  return invitation_card_mix_color($rgb, [0, 0, 0], max(0, min(1, $amount)));
}

function invitation_card_theme_style(array $template): array {
  $theme = invitation_template_theme($template);
  $primary = invitation_card_hex_to_rgb($template['primary_color'] ?? '#0ea5b5', [14, 165, 181]);
  $accent = invitation_card_hex_to_rgb($template['accent_color'] ?? '#f8fafc', [248, 250, 252]);
  $white = [255, 255, 255];

  $panelRadius = 64;
  $panelMargin = [64, 360, 64, 160];
  $panel = [255, 255, 255];
  $panelBorder = invitation_card_lighten($primary, 0.55);
  $background = invitation_card_lighten($accent, 0.45);
  $top = invitation_card_mix_color($accent, $primary, 0.28);
  $headline = $white;
  $subheadline = invitation_card_lighten($white, 0);
  $ink = [47, 46, 56];
  $muted = [101, 116, 139];
  $brand = [148, 163, 184];
  $buttonText = $white;
  $overlays = [];
  $shadow = [
    'rgb' => invitation_card_darken($primary, 0.55),
    'alpha' => 110,
    'width' => 880,
    'height' => 220,
    'offset_y' => 44,
  ];

  switch ($theme) {
    case 'kina':
      $background = invitation_card_mix_color($accent, [255, 244, 230], 0.55);
      $top = invitation_card_mix_color($primary, [124, 28, 0], 0.45);
      $panel = [255, 248, 237];
      $panelBorder = invitation_card_mix_color($primary, [241, 200, 120], 0.4);
      $ink = [82, 29, 17];
      $muted = [130, 52, 19];
      $brand = [191, 128, 63];
      $overlays = [
        ['type' => 'ellipse', 'x' => -140, 'y' => -220, 'w' => 760, 'h' => 720, 'rgb' => invitation_card_lighten($primary, 0.2), 'alpha' => 80],
        ['type' => 'ellipse', 'x' => 920, 'y' => -200, 'w' => 720, 'h' => 660, 'rgb' => invitation_card_mix_color($primary, [255, 210, 130], 0.45), 'alpha' => 75],
        ['type' => 'polygon', 'points' => [0, 540, 1080, 760, 1080, 980, 0, 980], 'rgb' => invitation_card_mix_color($primary, [255, 215, 141], 0.32), 'alpha' => 80],
        ['type' => 'dots', 'dots' => [
          ['x' => 220, 'y' => 620, 'size' => 24, 'rgb' => invitation_card_mix_color($primary, [255, 235, 194], 0.55), 'alpha' => 50],
          ['x' => 320, 'y' => 700, 'size' => 18, 'rgb' => invitation_card_mix_color($primary, [255, 230, 180], 0.45), 'alpha' => 60],
          ['x' => 840, 'y' => 640, 'size' => 26, 'rgb' => invitation_card_mix_color($primary, [255, 214, 160], 0.5), 'alpha' => 55],
          ['x' => 720, 'y' => 720, 'size' => 16, 'rgb' => invitation_card_mix_color($primary, [255, 225, 170], 0.6), 'alpha' => 65],
          ['x' => 180, 'y' => 820, 'size' => 20, 'rgb' => invitation_card_mix_color($primary, [255, 228, 168], 0.5), 'alpha' => 60],
        ]],
      ];
      $shadow['rgb'] = invitation_card_darken($primary, 0.35);
      break;
    case 'engagement':
      $background = invitation_card_mix_color($accent, [244, 237, 255], 0.6);
      $top = invitation_card_mix_color($primary, [96, 47, 184], 0.5);
      $panelBorder = invitation_card_mix_color($primary, [221, 214, 254], 0.5);
      $ink = [61, 34, 99];
      $muted = [110, 81, 148];
      $brand = [188, 149, 235];
      $overlays = [
        ['type' => 'polygon', 'points' => [0, 0, 0, 360, 360, 0], 'rgb' => invitation_card_lighten($primary, 0.35), 'alpha' => 75],
        ['type' => 'polygon', 'points' => [1080, 0, 720, 0, 1080, 420], 'rgb' => invitation_card_mix_color($primary, [255, 255, 255], 0.45), 'alpha' => 70],
        ['type' => 'dots', 'dots' => [
          ['x' => 200, 'y' => 320, 'size' => 18, 'rgb' => invitation_card_lighten($primary, 0.2), 'alpha' => 55],
          ['x' => 860, 'y' => 260, 'size' => 20, 'rgb' => invitation_card_mix_color($primary, [250, 240, 255], 0.55), 'alpha' => 60],
          ['x' => 680, 'y' => 360, 'size' => 14, 'rgb' => invitation_card_mix_color($primary, [234, 221, 255], 0.65), 'alpha' => 60],
          ['x' => 320, 'y' => 440, 'size' => 16, 'rgb' => invitation_card_mix_color($primary, [255, 235, 255], 0.5), 'alpha' => 65],
          ['x' => 880, 'y' => 440, 'size' => 22, 'rgb' => invitation_card_lighten($primary, 0.28), 'alpha' => 55],
        ]],
      ];
      break;
    case 'celebration':
      $background = invitation_card_mix_color($accent, [255, 255, 255], 0.45);
      $top = invitation_card_mix_color($primary, [59, 130, 246], 0.35);
      $panelBorder = invitation_card_mix_color($primary, [186, 230, 253], 0.45);
      $ink = [15, 23, 42];
      $muted = [71, 85, 105];
      $brand = [94, 133, 150];
      $overlays = [
        ['type' => 'ellipse', 'x' => -120, 'y' => -260, 'w' => 760, 'h' => 700, 'rgb' => invitation_card_lighten($primary, 0.22), 'alpha' => 80],
        ['type' => 'ellipse', 'x' => 860, 'y' => -220, 'w' => 720, 'h' => 700, 'rgb' => invitation_card_mix_color($primary, [255, 221, 148], 0.45), 'alpha' => 75],
        ['type' => 'dots', 'dots' => [
          ['x' => 200, 'y' => 620, 'size' => 18, 'rgb' => invitation_card_mix_color($primary, [245, 158, 11], 0.5), 'alpha' => 55],
          ['x' => 300, 'y' => 520, 'size' => 16, 'rgb' => invitation_card_mix_color($primary, [16, 185, 129], 0.45), 'alpha' => 55],
          ['x' => 820, 'y' => 620, 'size' => 20, 'rgb' => invitation_card_mix_color($primary, [249, 115, 22], 0.5), 'alpha' => 60],
          ['x' => 920, 'y' => 540, 'size' => 16, 'rgb' => invitation_card_mix_color($primary, [14, 165, 233], 0.55), 'alpha' => 55],
          ['x' => 540, 'y' => 500, 'size' => 14, 'rgb' => invitation_card_mix_color($primary, [236, 72, 153], 0.55), 'alpha' => 60],
          ['x' => 640, 'y' => 700, 'size' => 18, 'rgb' => invitation_card_mix_color($primary, [22, 163, 74], 0.55), 'alpha' => 60],
        ]],
      ];
      $shadow['rgb'] = invitation_card_darken($primary, 0.45);
      break;
    default:
      // wedding / default
      $background = invitation_card_mix_color($accent, [255, 246, 243], 0.6);
      $top = invitation_card_mix_color($primary, [255, 214, 226], 0.45);
      $panel = [255, 252, 248];
      $panelBorder = invitation_card_mix_color($primary, [255, 228, 214], 0.45);
      $ink = [74, 56, 48];
      $muted = [116, 93, 82];
      $brand = [190, 160, 140];
      $overlays = [
        ['type' => 'ellipse', 'x' => -180, 'y' => -220, 'w' => 880, 'h' => 720, 'rgb' => invitation_card_lighten($primary, 0.28), 'alpha' => 90],
        ['type' => 'ellipse', 'x' => 900, 'y' => -160, 'w' => 760, 'h' => 660, 'rgb' => invitation_card_mix_color($primary, [255, 220, 220], 0.5), 'alpha' => 85],
        ['type' => 'ellipse', 'x' => 540, 'y' => 380, 'w' => 820, 'h' => 720, 'rgb' => invitation_card_lighten($accent, 0.2), 'alpha' => 90],
      ];
      $shadow['rgb'] = invitation_card_darken($primary, 0.4);
      break;
  }

  return [
    'theme' => $theme,
    'primary' => $primary,
    'accent' => $accent,
    'background' => $background,
    'top' => $top,
    'panel' => $panel,
    'panel_border' => $panelBorder,
    'panel_radius' => $panelRadius,
    'panel_margin' => $panelMargin,
    'headline' => $headline,
    'subheadline' => $subheadline,
    'ink' => $ink,
    'muted' => $muted,
    'brand' => $brand,
    'button_text' => $buttonText,
    'overlays' => $overlays,
    'panel_shadow' => $shadow,
  ];
}

function invitation_card_draw_backdrop(GdImage $img, array $style, int $width, int $height): array {
  $background = $style['background'] ?? [248, 250, 252];
  $backgroundColor = imagecolorallocate($img, $background[0], $background[1], $background[2]);
  imagefilledrectangle($img, 0, 0, $width, $height, $backgroundColor);

  if (!empty($style['top'])) {
    $top = $style['top'];
    $topColor = imagecolorallocate($img, $top[0], $top[1], $top[2]);
    $topHeight = (int)($style['top_height'] ?? 520);
    imagefilledrectangle($img, 0, 0, $width, $topHeight, $topColor);
  }

  if (!empty($style['overlays'])) {
    invitation_card_draw_overlays($img, $style['overlays']);
  }

  $panelMargin = $style['panel_margin'] ?? [64, 420, 64, 180];
  $panelLeft = (int)$panelMargin[0];
  $panelTop = (int)$panelMargin[1];
  $panelRight = $width - (int)$panelMargin[2];
  $panelBottom = $height - (int)$panelMargin[3];
  $panelRadius = (int)($style['panel_radius'] ?? 48);

  if (!empty($style['panel_shadow'])) {
    $shadow = $style['panel_shadow'];
    $shadowRgb = $shadow['rgb'] ?? [0, 0, 0];
    $shadowAlpha = max(0, min(127, (int)($shadow['alpha'] ?? 100)));
    $shadowWidth = (int)($shadow['width'] ?? (($panelRight - $panelLeft) + 220));
    $shadowHeight = (int)($shadow['height'] ?? 260);
    $shadowOffsetY = (int)($shadow['offset_y'] ?? 52);
    $shadowColor = imagecolorallocatealpha($img, $shadowRgb[0], $shadowRgb[1], $shadowRgb[2], $shadowAlpha);
    imagefilledellipse($img, (int)round($width / 2), $panelBottom + $shadowOffsetY, $shadowWidth, $shadowHeight, $shadowColor);
  }

  if (!empty($style['panel_border'])) {
    $border = $style['panel_border'];
    $borderColor = imagecolorallocate($img, $border[0], $border[1], $border[2]);
    invitation_card_draw_round_rect($img, $panelLeft - 6, $panelTop - 6, $panelRight + 6, $panelBottom + 6, $panelRadius + 6, $borderColor);
  }

  $panel = $style['panel'] ?? [255, 255, 255];
  $panelColor = imagecolorallocate($img, $panel[0], $panel[1], $panel[2]);
  invitation_card_draw_round_rect($img, $panelLeft, $panelTop, $panelRight, $panelBottom, $panelRadius, $panelColor);

  return [
    'panel_left' => $panelLeft,
    'panel_top' => $panelTop,
    'panel_right' => $panelRight,
    'panel_bottom' => $panelBottom,
    'panel_radius' => $panelRadius,
  ];
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

    $style = invitation_card_theme_style($template);
    $geometry = invitation_card_draw_backdrop($img, $style, $width, $height);

    $primaryRgb = $style['primary'];

    $primaryColor = imagecolorallocate($img, $primaryRgb[0], $primaryRgb[1], $primaryRgb[2]);
    $headlineColor = imagecolorallocate($img, $style['headline'][0], $style['headline'][1], $style['headline'][2]);
    $subtitleRgb = $style['subheadline'] ?? $style['headline'];
    $subtitleColor = imagecolorallocate($img, $subtitleRgb[0], $subtitleRgb[1], $subtitleRgb[2]);
    $ink = imagecolorallocate($img, $style['ink'][0], $style['ink'][1], $style['ink'][2]);
    $muted = imagecolorallocate($img, $style['muted'][0], $style['muted'][1], $style['muted'][2]);
    $brand = imagecolorallocate($img, $style['brand'][0], $style['brand'][1], $style['brand'][2]);
    $buttonTextRgb = $style['button_text'] ?? [255, 255, 255];
    $buttonTextColor = imagecolorallocate($img, $buttonTextRgb[0], $buttonTextRgb[1], $buttonTextRgb[2]);

    $panelLeft = $geometry['panel_left'];
    $panelRight = $geometry['panel_right'];
    $panelTop = $geometry['panel_top'];
    $panelBottom = $geometry['panel_bottom'];

    $title = trim((string)($template['title'] ?? ''));
    if ($title === '') {
      $title = 'Düğün Davetiyemiz';
    }
    $titleSize = 74;
    $maxTitleWidth = 900;
    while ($titleSize > 42) {
      $widthText = invitation_card_text_metrics($titleSize, $fontSemi, $title)['width'];
      if ($widthText <= $maxTitleWidth) {
        break;
      }
      $titleSize -= 2;
    }
    $titleWidth = invitation_card_text_metrics($titleSize, $fontSemi, $title)['width'];
    $titleX = (int)round(($width - $titleWidth) / 2);
    $titleY = 230;
    invitation_card_draw_text($img, $titleSize, $titleX, $titleY, $headlineColor, $fontSemi, $title);

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
      $subtitleSize = 36;
      $maxSubtitleWidth = 920;
      while ($subtitleSize > 24) {
        $subtitleWidth = invitation_card_text_metrics($subtitleSize, $fontRegular, $subtitleText)['width'];
        if ($subtitleWidth <= $maxSubtitleWidth) {
          break;
        }
        $subtitleSize -= 1;
      }
      $subtitleWidth = invitation_card_text_metrics($subtitleSize, $fontRegular, $subtitleText)['width'];
      $subtitleX = (int)round(($width - $subtitleWidth) / 2);
      $subtitleY = $titleY + 70;
      invitation_card_draw_text($img, $subtitleSize, $subtitleX, $subtitleY, $subtitleColor, $fontRegular, $subtitleText);
    }

    $cursorY = $panelTop + 150;
    $centerX = (int)round($width / 2);

    if ($contact && !empty($contact['name'])) {
      $recipient = trim((string)$contact['name']);
      if ($recipient !== '') {
        $recipientText = 'Sevgili '.$recipient;
        $recipientSize = 38;
        $recipientWidth = invitation_card_text_metrics($recipientSize, $fontSemi, $recipientText)['width'];
        if ($recipientWidth > 780) {
          $recipientSize = 32;
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
    $messageFontSize = 40;
    $maxMessageWidth = 780;
    $messageLines = invitation_card_wrap_text($message, $fontRegular, $messageFontSize, $maxMessageWidth);
    while (count($messageLines) > 8 && $messageFontSize > 28) {
      $messageFontSize -= 2;
      $messageLines = invitation_card_wrap_text($message, $fontRegular, $messageFontSize, $maxMessageWidth);
    }
    if ($messageLines) {
      $cursorY = invitation_card_draw_lines($img, $messageLines, $fontRegular, $messageFontSize, $muted, $centerX, $cursorY, 1.4);
    }

    $cursorY += 56;
    $buttonLabel = trim((string)($template['button_label'] ?? ''));
    if ($buttonLabel === '') {
      $buttonLabel = 'Katılımınızı Bildirin';
    }
    $buttonFontSize = 34;
    $buttonTextWidth = invitation_card_text_metrics($buttonFontSize, $fontSemi, $buttonLabel)['width'];
    $buttonPadding = 160;
    $buttonWidth = min(780, $buttonTextWidth + $buttonPadding);
    $buttonHeight = 102;
    $buttonX1 = (int)round($centerX - ($buttonWidth / 2));
    $buttonY1 = $cursorY;
    $buttonX2 = $buttonX1 + $buttonWidth;
    $buttonY2 = $buttonY1 + $buttonHeight;
    invitation_card_draw_pill($img, $buttonX1, $buttonY1, $buttonX2, $buttonY2, $primaryColor);
    $buttonTextX = (int)round($centerX - ($buttonTextWidth / 2));
    $buttonBaseline = $buttonY1 + (int)round($buttonHeight / 2 + $buttonFontSize / 2.4);
    invitation_card_draw_text($img, $buttonFontSize, $buttonTextX, $buttonBaseline, $buttonTextColor, $fontSemi, $buttonLabel);

    $brandY = $panelBottom - 60;
    $brandTextWidth = invitation_card_text_metrics(28, $fontSemi, 'bikara.com')['width'];
    $brandX = (int)round($centerX - ($brandTextWidth / 2));
    invitation_card_draw_text($img, 28, $brandX, $brandY, $brand, $fontSemi, 'bikara.com');

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

  $style = invitation_card_theme_style($template);
  $geometry = invitation_card_draw_backdrop($img, $style, $width, $height);

  $primaryColor = imagecolorallocate($img, $style['primary'][0], $style['primary'][1], $style['primary'][2]);
  $headlineColor = imagecolorallocate($img, $style['headline'][0], $style['headline'][1], $style['headline'][2]);
  $subtitleRgb = $style['subheadline'] ?? $style['headline'];
  $subtitleColor = imagecolorallocate($img, $subtitleRgb[0], $subtitleRgb[1], $subtitleRgb[2]);
  $textColor = imagecolorallocate($img, $style['ink'][0], $style['ink'][1], $style['ink'][2]);
  $muted = imagecolorallocate($img, $style['muted'][0], $style['muted'][1], $style['muted'][2]);
  $brand = imagecolorallocate($img, $style['brand'][0], $style['brand'][1], $style['brand'][2]);
  $buttonTextRgb = $style['button_text'] ?? [255, 255, 255];
  $buttonTextColor = imagecolorallocate($img, $buttonTextRgb[0], $buttonTextRgb[1], $buttonTextRgb[2]);

  $font = 5;
  $fontWidth = imagefontwidth($font);
  $fontHeight = imagefontheight($font);

  $titleScale = 5.2;
  $subtitleScale = 3.2;
  $recipientScale = 3.0;
  $bodyScale = 2.8;
  $buttonScale = 3.0;
  $brandScale = 2.4;

  $title = trim((string)($template['title'] ?? ''));
  if ($title === '') {
    $title = 'Düğün Davetiyemiz';
  }
  $titleUpper = function_exists('mb_strtoupper') ? mb_strtoupper($title, 'UTF-8') : strtoupper($title);
  $cursorY = 120;
  $cursorY = invitation_card_basic_draw_centered(
    $img,
    $font,
    $titleUpper,
    $headlineColor,
    $width,
    $cursorY,
    (int)round($fontHeight * $titleScale * 0.45),
    $titleScale
  );

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
    $cursorY = invitation_card_basic_draw_centered(
      $img,
      $font,
      implode(' • ', $subtitleParts),
      $subtitleColor,
      $width,
      $cursorY,
      (int)round($fontHeight * $subtitleScale * 0.35),
      $subtitleScale
    );
  }

  $panelTop = $geometry['panel_top'];
  $panelBottom = $geometry['panel_bottom'];

  $cursorY = $panelTop + 120;
  if ($contact && !empty($contact['name'])) {
    $recipient = trim((string)$contact['name']);
    if ($recipient !== '') {
      $cursorY = invitation_card_basic_draw_centered(
        $img,
        $font,
        'Sevgili '.$recipient,
        $textColor,
        $width,
        $cursorY,
        (int)round($fontHeight * $recipientScale * 0.4),
        $recipientScale
      );
    }
  }

  $message = (string)($template['message'] ?? '');
  if (stripos($message, 'bikara.com') === false) {
    $message = invitation_require_branding($message);
  }
  $wrapWidth = max(18, (int)floor(760 / max(1, $fontWidth * $bodyScale)));
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
  $bodyLineGap = (int)round($fontHeight * $bodyScale * 0.55);
  foreach ($messageLines as $line) {
    if ($line === '') {
      $cursorY += $bodyLineGap;
      continue;
    }
    $cursorY = invitation_card_basic_draw_centered(
      $img,
      $font,
      $line,
      $muted,
      $width,
      $cursorY,
      $bodyLineGap,
      $bodyScale
    );
  }

  $cursorY += (int)round($fontHeight * $bodyScale * 0.9);
  $buttonLabel = trim((string)($template['button_label'] ?? ''));
  if ($buttonLabel === '') {
    $buttonLabel = 'Katılımınızı Bildirin';
  }
  $buttonMetrics = invitation_card_basic_measure($font, $buttonLabel, $buttonScale);
  $buttonWidth = min(820, $buttonMetrics['width'] + 280);
  $buttonHeight = max(120, (int)round($buttonMetrics['height'] * 1.8));
  $buttonX1 = (int)round(($width - $buttonWidth) / 2);
  $buttonY1 = $cursorY;
  $buttonX2 = $buttonX1 + $buttonWidth;
  $buttonY2 = $buttonY1 + $buttonHeight;
  invitation_card_draw_pill($img, $buttonX1, $buttonY1, $buttonX2, $buttonY2, $primaryColor);
  $buttonTextX = (int)round($buttonX1 + ($buttonWidth - $buttonMetrics['width']) / 2);
  $buttonTextY = (int)round($buttonY1 + ($buttonHeight - $buttonMetrics['height']) / 2);
  invitation_card_basic_draw_block($img, $font, $buttonLabel, $buttonTextColor, $buttonTextX, $buttonTextY, $buttonScale);

  $brandText = 'bikara.com';
  $brandMetrics = invitation_card_basic_measure($font, $brandText, $brandScale);
  $brandX = (int)round(($width - $brandMetrics['width']) / 2);
  $brandBaseline = max($panelBottom - 140, $buttonY2 + (int)round($fontHeight * $brandScale));
  $brandY = max(0, min($brandBaseline, $panelBottom - $brandMetrics['height'] - 40));
  invitation_card_basic_draw_block($img, $font, $brandText, $brand, $brandX, $brandY, $brandScale);

  return $img;
}

function invitation_card_basic_measure(int $font, string $text, float $scale = 1.0): array {
  $text = (string)$text;
  $scale = max(1.0, $scale);
  $fontWidth = imagefontwidth($font);
  $fontHeight = imagefontheight($font);
  $rawWidth = max(1, $fontWidth * max(1, strlen($text)));
  $rawHeight = max(1, $fontHeight);

  return [
    'width' => (int)max(1, round($rawWidth * $scale)),
    'height' => (int)max(1, round($rawHeight * $scale)),
    'raw_width' => $rawWidth,
    'raw_height' => $rawHeight,
  ];
}

function invitation_card_basic_draw_centered(
  GdImage $img,
  int $font,
  string $text,
  int $color,
  int $width,
  int $y,
  int $lineGap,
  float $scale = 1.0
): int {
  $text = trim($text);
  if ($text === '') {
    return $y + $lineGap;
  }
  $scale = max(1.0, $scale);
  $metrics = invitation_card_basic_measure($font, $text, $scale);
  $x = (int)max(0, round(($width - $metrics['width']) / 2));
  invitation_card_basic_draw_block($img, $font, $text, $color, $x, $y, $scale);
  return $y + $metrics['height'] + max(0, $lineGap);
}

function invitation_card_basic_draw_block(
  GdImage $img,
  int $font,
  string $text,
  int $color,
  int $x,
  int $y,
  float $scale = 1.0
): array {
  $text = (string)$text;
  if ($text === '') {
    return ['width' => 0, 'height' => 0];
  }
  $scale = max(1.0, $scale);
  $rgba = invitation_card_basic_color_components($img, $color);
  $block = invitation_card_basic_render_text_block($font, $text, $rgba, $scale);
  $width = imagesx($block);
  $height = imagesy($block);
  imagecopy($img, $block, $x, $y, 0, 0, $width, $height);
  imagedestroy($block);
  return ['width' => $width, 'height' => $height];
}

function invitation_card_basic_color_components(GdImage $img, int $color): array {
  $components = @imagecolorsforindex($img, $color);
  if (!is_array($components)) {
    return ['red' => 0, 'green' => 0, 'blue' => 0, 'alpha' => 0];
  }
  return [
    'red' => (int)($components['red'] ?? 0),
    'green' => (int)($components['green'] ?? 0),
    'blue' => (int)($components['blue'] ?? 0),
    'alpha' => (int)($components['alpha'] ?? 0),
  ];
}

function invitation_card_basic_render_text_block(int $font, string $text, array $rgba, float $scale = 1.0): GdImage {
  $scale = max(1.0, $scale);
  $fontWidth = imagefontwidth($font);
  $fontHeight = imagefontheight($font);
  $rawWidth = max(1, $fontWidth * max(1, strlen($text)));
  $rawHeight = max(1, $fontHeight);

  $raw = imagecreatetruecolor($rawWidth, $rawHeight);
  imagealphablending($raw, false);
  imagesavealpha($raw, true);
  $transparent = imagecolorallocatealpha($raw, 0, 0, 0, 127);
  imagefilledrectangle($raw, 0, 0, $rawWidth, $rawHeight, $transparent);
  $rawColor = imagecolorallocatealpha($raw, $rgba['red'], $rgba['green'], $rgba['blue'], $rgba['alpha']);
  imagestring($raw, $font, 0, 0, $text, $rawColor);

  if ($scale <= 1.01) {
    return $raw;
  }

  $scaledWidth = (int)max(1, round($rawWidth * $scale));
  $scaledHeight = (int)max(1, round($rawHeight * $scale));
  $scaled = imagecreatetruecolor($scaledWidth, $scaledHeight);
  imagealphablending($scaled, false);
  imagesavealpha($scaled, true);
  $transparentScaled = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
  imagefilledrectangle($scaled, 0, 0, $scaledWidth, $scaledHeight, $transparentScaled);
  imagecopyresampled($scaled, $raw, 0, 0, 0, 0, $scaledWidth, $scaledHeight, $rawWidth, $rawHeight);
  imagedestroy($raw);
  return $scaled;
}
