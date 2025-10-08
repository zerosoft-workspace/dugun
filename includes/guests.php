<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';

function guest_profile_normalize_email(string $email): string {
  $email = trim($email);
  return $email === '' ? '' : mb_strtolower($email, 'UTF-8');
}

function guest_profile_current(int $eventId): ?array {
  $store = $_SESSION['guest_profiles'][$eventId] ?? null;
  if (!$store) return null;
  $profileId = (int)$store;
  $st = pdo()->prepare("SELECT * FROM guest_profiles WHERE id=? AND event_id=? LIMIT 1");
  $st->execute([$profileId, $eventId]);
  $profile = $st->fetch();
  if (!$profile) {
    unset($_SESSION['guest_profiles'][$eventId]);
    return null;
  }
  return $profile;
}

function guest_profile_set_session(int $eventId, int $profileId): void {
  if (!isset($_SESSION['guest_profiles']) || !is_array($_SESSION['guest_profiles'])) {
    $_SESSION['guest_profiles'] = [];
  }
  $_SESSION['guest_profiles'][$eventId] = $profileId;
}

function guest_profile_clear_session(int $eventId): void {
  if (isset($_SESSION['guest_profiles'][$eventId])) {
    unset($_SESSION['guest_profiles'][$eventId]);
  }
}

function guest_profile_is_host_preview(array $profile): bool {
  return isset($profile['is_host_preview']) && (int)$profile['is_host_preview'] === 1;
}

function guest_profile_host_preview(int $eventId): ?array {
  $eventId = (int)$eventId;
  if ($eventId <= 0) {
    return null;
  }

  $pdo = pdo();
  $pdo->beginTransaction();
  try {
    $existing = $pdo->prepare('SELECT * FROM guest_profiles WHERE event_id=? AND is_host_preview=1 LIMIT 1');
    $existing->execute([$eventId]);
    $profile = $existing->fetch();
    if ($profile) {
      $pdo->commit();
      return $profile;
    }

    $eventStmt = $pdo->prepare('SELECT title, contact_email FROM events WHERE id=? LIMIT 1');
    $eventStmt->execute([$eventId]);
    $event = $eventStmt->fetch();
    if (!$event) {
      $pdo->rollBack();
      return null;
    }

    $eventTitle = trim($event['title'] ?? '');
    $displayName = $eventTitle !== '' ? $eventTitle.' · Çift' : 'Çift Önizleme';
    $name = 'Çift Önizleme';
    $now = now();

    $baseHost = parse_url(BASE_URL, PHP_URL_HOST) ?: 'bikare.local';
    $safeHost = preg_replace('~[^a-z0-9]+~i', '', $baseHost);
    if ($safeHost === '') {
      $safeHost = 'bikare';
    }

    $candidates = [];
    $contactEmail = guest_profile_normalize_email($event['contact_email'] ?? '');
    if ($contactEmail !== '') {
      $candidates[] = $contactEmail;
    }
    $candidates[] = 'onizleme-'.$eventId.'@'.$safeHost.'.local';
    $candidates[] = 'guest-preview-'.$eventId.'@example.invalid';

    $email = null;
    $check = $pdo->prepare('SELECT 1 FROM guest_profiles WHERE event_id=? AND email=? LIMIT 1');
    foreach ($candidates as $candidate) {
      $candidate = guest_profile_normalize_email($candidate);
      if ($candidate === '') {
        continue;
      }
      $check->execute([$eventId, $candidate]);
      if (!$check->fetch()) {
        $email = $candidate;
        break;
      }
    }

    if ($email === null) {
      $email = 'guest-preview-'.$eventId.'-'.bin2hex(random_bytes(4)).'@example.invalid';
    }

    $insert = $pdo->prepare('INSERT INTO guest_profiles (event_id,email,name,display_name,is_verified,verified_at,last_seen_at,last_login_at,created_at,updated_at,is_host_preview) VALUES (?,?,?,?,1,?,?,?,?,?,1)');
    $insert->execute([$eventId, $email, $name, $displayName, $now, $now, $now, $now, $now]);
    $profileId = (int)$pdo->lastInsertId();
    $pdo->commit();

    $created = guest_profile_find_by_id($profileId);
    if ($created) {
      return $created;
    }

    return [
      'id' => $profileId,
      'event_id' => $eventId,
      'email' => $email,
      'name' => $name,
      'display_name' => $displayName,
      'is_host_preview' => 1,
    ];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}

function guest_profile_record_login(int $profileId): void {
  $now = now();
  pdo()->prepare('UPDATE guest_profiles SET last_login_at=?, updated_at=? WHERE id=?')
      ->execute([$now, $now, $profileId]);
}

function guest_profile_touch(int $profileId): void {
  $st = pdo()->prepare("UPDATE guest_profiles SET last_seen_at=?, updated_at=? WHERE id=?");
  $now = now();
  $st->execute([$now, $now, $profileId]);
}

function guest_profile_issue_password_token(int $profileId, int $ttlSeconds = 86400): string {
  $token = bin2hex(random_bytes(20));
  $expires = date('Y-m-d H:i:s', time() + max(300, $ttlSeconds));
  pdo()->prepare('UPDATE guest_profiles SET password_token=?, password_token_expires_at=?, updated_at=? WHERE id=?')
      ->execute([$token, $expires, now(), $profileId]);
  return $token;
}

function guest_profile_find_by_password_token(string $token): ?array {
  $token = trim($token);
  if ($token === '') return null;
  $st = pdo()->prepare('SELECT * FROM guest_profiles WHERE password_token=? AND (password_token_expires_at IS NULL OR password_token_expires_at >= ?) LIMIT 1');
  $st->execute([$token, now()]);
  $row = $st->fetch();
  return $row ?: null;
}

function guest_profile_set_password(int $profileId, string $password): void {
  $hash = password_hash($password, PASSWORD_DEFAULT);
  $now = now();
  pdo()->prepare('UPDATE guest_profiles SET password_hash=?, password_set_at=?, password_token=NULL, password_token_expires_at=NULL, updated_at=? WHERE id=?')
      ->execute([$hash, $now, $now, $profileId]);
}

function guest_send_password_reset(string $email): void {
  $email = guest_profile_normalize_email($email);
  if ($email === '') {
    return;
  }

  $st = pdo()->prepare('SELECT gp.id, gp.display_name, gp.name, gp.event_id, gp.password_hash, e.title AS event_title, e.event_date
                         FROM guest_profiles gp
                         JOIN events e ON e.id = gp.event_id
                         WHERE gp.email=? AND gp.is_verified=1 AND gp.password_hash IS NOT NULL AND e.is_active=1');
  $st->execute([$email]);
  $profiles = $st->fetchAll();
  if (!$profiles) {
    return;
  }

  $items = [];
  $greetingName = null;
  foreach ($profiles as $row) {
    $profileId = (int)$row['id'];
    if ($profileId <= 0) {
      continue;
    }

    if ($greetingName === null) {
      $greetingName = $row['display_name'] ?: ($row['name'] ?: null);
    }

    $token = guest_profile_issue_password_token($profileId, 3600);
    $resetUrl = BASE_URL.'/public/guest_password.php?token='.rawurlencode($token);
    $eventTitle = $row['event_title'] ?: 'Etkinliğiniz';

    $eventDateLabel = '';
    if (!empty($row['event_date'])) {
      $ts = strtotime($row['event_date']);
      if ($ts) {
        $eventDateLabel = date('d.m.Y', $ts);
      }
    }

    $label = '<strong>'.h($eventTitle).'</strong>';
    if ($eventDateLabel !== '') {
      $label .= ' — '.h($eventDateLabel);
    }

    $items[] = '<li>'.$label.'<br><a href="'.h($resetUrl).'">Şifreyi sıfırla</a></li>';
  }

  if (!$items) {
    return;
  }

  $greetingName = $greetingName ?: 'Misafirimiz';
  $html  = '<h2>'.h(APP_NAME).' Misafir Paneli Şifre Sıfırlama</h2>';
  $html .= '<p>Merhaba '.h($greetingName).',</p>';
  $html .= '<p>Misafir paneli hesabınız için yeni bir şifre belirlemek üzere aşağıdaki bağlantıları kullanabilirsiniz.</p>';
  $html .= '<ul>'.implode('', $items).'</ul>';
  $html .= '<p>Bağlantılar 1 saat boyunca geçerlidir. Eğer bu isteği siz göndermediyseniz bu e-postayı yok sayabilirsiniz.</p>';

  send_mail_simple($email, APP_NAME.' Misafir Paneli Şifre Sıfırlama', $html);
}

function guest_profile_authenticate(string $email, string $password): array {
  $email = guest_profile_normalize_email($email);
  if ($email === '' || trim($password) === '') {
    return [];
  }
  $st = pdo()->prepare('SELECT gp.*, e.title AS event_title, e.event_date, e.id AS evt_id FROM guest_profiles gp JOIN events e ON e.id = gp.event_id WHERE gp.email=? AND gp.is_verified=1 AND gp.password_hash IS NOT NULL AND e.is_active=1');
  $st->execute([$email]);
  $matches = [];
  while ($row = $st->fetch()) {
    if (!password_verify($password, $row['password_hash'])) {
      continue;
    }
    if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
      $rehash = password_hash($password, PASSWORD_DEFAULT);
      pdo()->prepare('UPDATE guest_profiles SET password_hash=?, password_set_at=?, updated_at=? WHERE id=?')
          ->execute([$rehash, now(), now(), (int)$row['id']]);
      $row['password_hash'] = $rehash;
    }
    $matches[] = [
      'profile' => $row,
      'event' => [
        'id' => (int)$row['evt_id'],
        'title' => $row['event_title'],
        'event_date' => $row['event_date'],
      ],
    ];
  }
  return $matches;
}

function guest_profile_find_by_email(int $eventId, string $email): ?array {
  if ($email === '') return null;
  $st = pdo()->prepare("SELECT * FROM guest_profiles WHERE event_id=? AND email=? LIMIT 1");
  $st->execute([$eventId, $email]);
  return $st->fetch() ?: null;
}

function guest_profile_find_by_id(int $profileId): ?array {
  if ($profileId <= 0) {
    return null;
  }
  $st = pdo()->prepare('SELECT * FROM guest_profiles WHERE id=? LIMIT 1');
  $st->execute([$profileId]);
  $row = $st->fetch();
  return $row ?: null;
}

function guest_event_profile_directory(int $eventId, int $excludeProfileId = 0): array {
  $st = pdo()->prepare('SELECT id, event_id, display_name, name, email, avatar_token, is_verified, last_seen_at, last_login_at
                        FROM guest_profiles WHERE event_id=? AND is_host_preview=0 ORDER BY display_name ASC, name ASC');
  $st->execute([$eventId]);
  $rows = $st->fetchAll();
  $directory = [];
  foreach ($rows as $row) {
    if ($excludeProfileId > 0 && (int)$row['id'] === $excludeProfileId) {
      continue;
    }
    $display = $row['display_name'] ?: ($row['name'] ?: 'Misafir');
    $directory[] = [
      'id' => (int)$row['id'],
      'event_id' => (int)$row['event_id'],
      'display_name' => $display,
      'name' => $row['name'],
      'email' => $row['email'],
      'avatar_token' => $row['avatar_token'],
      'is_verified' => (int)$row['is_verified'] === 1,
      'last_seen_at' => $row['last_seen_at'],
      'last_login_at' => $row['last_login_at'],
    ];
  }
  return $directory;
}

function guest_profile_upsert(int $eventId, string $name, string $email, bool $marketingOptIn): ?array {
  $email = guest_profile_normalize_email($email);
  if ($email === '') return null;
  $pdo = pdo();
  $pdo->beginTransaction();
  try {
    $profile = guest_profile_find_by_email($eventId, $email);
    $now = now();
    if ($profile) {
      $updates = [];
      $params = [];
      if ($name !== '' && $profile['name'] !== $name) {
        $updates[] = 'name=?';
        $params[] = $name;
        if ($profile['display_name'] === $profile['name']) {
          $updates[] = 'display_name=?';
          $params[] = $name;
        }
      }
      if ($profile['display_name'] === '' && $name !== '') {
        $updates[] = 'display_name=?';
        $params[] = $name;
      }
      if ($marketingOptIn && (int)$profile['marketing_opt_in'] !== 1) {
        $updates[] = 'marketing_opt_in=1';
        $updates[] = 'marketing_opted_at=?';
        $params[] = $now;
      }
      if ($updates) {
        $updates[] = 'updated_at=?';
        $params[] = $now;
        $params[] = (int)$profile['id'];
        $pdo->prepare('UPDATE guest_profiles SET '.implode(',', $updates).' WHERE id=?')->execute($params);
      }
      $pdo->prepare('UPDATE guest_profiles SET last_seen_at=? WHERE id=?')->execute([$now, (int)$profile['id']]);
    } else {
      $token = bin2hex(random_bytes(20));
      $pdo->prepare("INSERT INTO guest_profiles (event_id,email,name,display_name,is_verified,verify_token,marketing_opt_in,marketing_opted_at,last_seen_at,created_at,updated_at)
                     VALUES (?,?,?,?,0,?,?,?,?,?,?)")
          ->execute([
            $eventId,
            $email,
            $name ?: 'Misafir',
            $name ?: 'Misafir',
            $token,
            $marketingOptIn ? 1 : 0,
            $marketingOptIn ? $now : null,
            $now,
            $now,
            $now
          ]);
      $profileId = (int)$pdo->lastInsertId();
      $profile = guest_profile_find_by_email($eventId, $email);
      if ($profile) {
        $profile['id'] = $profileId;
      }
    }
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
  return guest_profile_find_by_email($eventId, $email);
}

function guest_profile_send_verification(array $profile, array $event): bool {
  if ((int)$profile['is_verified'] === 1) return true;
  if (empty($profile['verify_token'])) {
    $token = bin2hex(random_bytes(20));
    pdo()->prepare('UPDATE guest_profiles SET verify_token=?, updated_at=? WHERE id=?')->execute([$token, now(), (int)$profile['id']]);
    $profile['verify_token'] = $token;
  }
  $verifyUrl = BASE_URL.'/public/guest_verify.php?token='.rawurlencode($profile['verify_token']);
  $eventUrl = public_upload_url((int)$event['id']);
  $html = '<div style="font-family:Inter,Arial,sans-serif;background:#f8fafc;padding:24px">'
        .'<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:18px;padding:32px;box-shadow:0 18px 45px rgba(15,23,42,0.08);">'
        .'<h2 style="margin-top:0;color:#0ea5b5;font-size:24px;">BİKARE Misafir Profilinizi Doğrulayın</h2>'
        .'<p style="color:#475569;font-size:15px;line-height:1.6;">Merhaba '.h($profile['display_name'] ?: $profile['name']).',</p>'
        .'<p style="color:#475569;font-size:15px;line-height:1.6;">'.h($event['title']).' etkinliğinin dijital albümüne katıldığınız için teşekkür ederiz. Profilinizi doğruladığınızda fotoğrafları beğenebilir, yorum yazabilir, misafir sohbetine katılabilir ve şifrenizi belirleyerek panelinize dilediğiniz zaman giriş yapabilirsiniz.</p>'
        .'<p style="text-align:center;margin:32px 0">'
        .'<a href="'.h($verifyUrl).'" style="background:#0ea5b5;color:#fff;text-decoration:none;padding:14px 26px;border-radius:999px;font-weight:600;display:inline-block;">Profilimi Doğrula</a>'
        .'</p>'
        .'<p style="color:#475569;font-size:14px;line-height:1.6;">Bağlantı çalışmazsa kopyalayıp tarayıcınıza yapıştırabilirsiniz:<br><a href="'.h($verifyUrl).'" style="color:#0ea5b5;text-decoration:none;">'.h($verifyUrl).'</a></p>'
        .'<p style="color:#94a3b8;font-size:13px;margin-top:32px;">Etkinlik sayfasına dönmek için <a href="'.h($eventUrl).'" style="color:#0ea5b5;text-decoration:none;">buraya tıklayın</a>.</p>'
        .'</div></div>';
  require_once __DIR__.'/mailer.php';
  $sent = send_smtp_mail($profile['email'], 'BİKARE profil doğrulaması', $html);
  if ($sent) {
    pdo()->prepare('UPDATE guest_profiles SET last_verification_sent_at=?, updated_at=? WHERE id=?')
        ->execute([now(), now(), (int)$profile['id']]);
  }
  return $sent;
}

function guest_profile_verify_token(string $token): ?array {
  $token = trim($token);
  if ($token === '') return null;
  $pdo = pdo();
  $pdo->beginTransaction();
  $passwordToken = null;
  try {
    $st = $pdo->prepare('SELECT * FROM guest_profiles WHERE verify_token=? FOR UPDATE');
    $st->execute([$token]);
    $profile = $st->fetch();
    if (!$profile) {
      $pdo->rollBack();
      return null;
    }
    $alreadyVerified = (int)$profile['is_verified'] === 1;
    $now = now();
    if (!$alreadyVerified) {
      $pdo->prepare('UPDATE guest_profiles SET is_verified=1, verified_at=?, updated_at=? WHERE id=?')
          ->execute([$now, $now, (int)$profile['id']]);
    }
    $pdo->prepare('UPDATE guest_profiles SET verify_token=NULL, updated_at=? WHERE id=?')
        ->execute([$now, (int)$profile['id']]);
    $passwordToken = guest_profile_issue_password_token((int)$profile['id']);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }

  $fresh = guest_profile_current_after_verify((int)$profile['id']);
  if ($fresh) {
    guest_profile_touch((int)$fresh['id']);
  }

  return [
    'profile' => $fresh,
    'password_token' => $passwordToken ?? null,
    'just_verified' => !$alreadyVerified,
  ];
}

function guest_profile_current_after_verify(int $profileId): ?array {
  $st = pdo()->prepare('SELECT * FROM guest_profiles WHERE id=? LIMIT 1');
  $st->execute([$profileId]);
  return $st->fetch() ?: null;
}

function guest_upload_like(int $uploadId, int $profileId): void {
  $now = now();
  $st = pdo()->prepare('INSERT IGNORE INTO guest_upload_likes (upload_id, profile_id, created_at) VALUES (?,?,?)');
  $st->execute([$uploadId, $profileId, $now]);
}

function guest_upload_unlike(int $uploadId, int $profileId): void {
  $st = pdo()->prepare('DELETE FROM guest_upload_likes WHERE upload_id=? AND profile_id=?');
  $st->execute([$uploadId, $profileId]);
}

function guest_upload_comment_add(int $uploadId, ?array $profile, string $body): ?array {
  $body = trim($body);
  if ($body === '') return null;
  $body = mb_substr($body, 0, 1000, 'UTF-8');
  $now = now();
  $pdo = pdo();
  $pdo->prepare('INSERT INTO guest_upload_comments (upload_id, profile_id, guest_name, guest_email, body, created_at)
                 VALUES (?,?,?,?,?,?)')
      ->execute([
        $uploadId,
        $profile ? (int)$profile['id'] : null,
        $profile['display_name'] ?? ($profile['name'] ?? 'Misafir'),
        $profile['email'] ?? null,
        $body,
        $now
      ]);
  $commentId = (int)$pdo->lastInsertId();
  if ($commentId > 0) {
    $st = $pdo->prepare('SELECT c.*, gp.display_name AS profile_display_name, gp.avatar_token AS profile_avatar_token
                          FROM guest_upload_comments c
                          LEFT JOIN guest_profiles gp ON gp.id=c.profile_id
                          WHERE c.id=? LIMIT 1');
    $st->execute([$commentId]);
    $row = $st->fetch();
    if ($row) {
      return $row;
    }
  }
  return null;
}

function guest_upload_like_count(int $uploadId): int {
  $st = pdo()->prepare('SELECT COUNT(*) FROM guest_upload_likes WHERE upload_id=?');
  $st->execute([$uploadId]);
  return (int)$st->fetchColumn();
}

function guest_upload_is_liked(int $uploadId, int $profileId): bool {
  $st = pdo()->prepare('SELECT 1 FROM guest_upload_likes WHERE upload_id=? AND profile_id=? LIMIT 1');
  $st->execute([$uploadId, $profileId]);
  return (bool)$st->fetchColumn();
}

function guest_upload_comment_count(int $uploadId): int {
  $st = pdo()->prepare('SELECT COUNT(*) FROM guest_upload_comments WHERE upload_id=?');
  $st->execute([$uploadId]);
  return (int)$st->fetchColumn();
}

function guest_chat_add_message(int $eventId, array $profile, string $message, ?int $attachmentUploadId = null): void {
  $message = trim($message);
  if ($message === '') return;
  $message = mb_substr($message, 0, 2000, 'UTF-8');
  $now = now();
  pdo()->prepare('INSERT INTO guest_chat_messages (event_id, profile_id, message, attachment_upload_id, created_at)
                  VALUES (?,?,?,?,?)')
      ->execute([$eventId, (int)$profile['id'], $message, $attachmentUploadId, $now]);
  guest_profile_touch((int)$profile['id']);
}

function guest_event_note_add(int $eventId, array $profile, string $message): ?array {
  $message = trim($message);
  if ($message === '') {
    return null;
  }
  $message = mb_substr($message, 0, 2000, 'UTF-8');
  $pdo = pdo();
  $now = now();
  $pdo->prepare('INSERT INTO guest_event_notes (event_id, profile_id, guest_name, guest_email, message, created_at)
                 VALUES (?,?,?,?,?,?)')
      ->execute([
        $eventId,
        (int)$profile['id'],
        $profile['display_name'] ?: ($profile['name'] ?? 'Misafir'),
        $profile['email'] ?? null,
        $message,
        $now
      ]);
  $id = (int)$pdo->lastInsertId();
  if ($id > 0) {
    $st = $pdo->prepare('SELECT * FROM guest_event_notes WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }
  return null;
}

function guest_private_message_send(int $eventId, array $senderProfile, array $recipient, string $body): ?array {
  $body = trim($body);
  if ($body === '') {
    return null;
  }
  $body = mb_substr($body, 0, 2000, 'UTF-8');
  $pdo = pdo();
  $now = now();
  $pdo->prepare('INSERT INTO guest_private_messages (event_id, sender_profile_id, recipient_profile_id, recipient_upload_id, recipient_email, recipient_name, body, is_for_host, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?)')
      ->execute([
        $eventId,
        (int)$senderProfile['id'],
        $recipient['profile_id'] ?? null,
        $recipient['upload_id'] ?? null,
        $recipient['email'] ?? null,
        $recipient['name'] ?? null,
        $body,
        !empty($recipient['is_host']) ? 1 : 0,
        $now
      ]);
  $id = (int)$pdo->lastInsertId();
  guest_profile_touch((int)$senderProfile['id']);
  if ($id > 0) {
    $st = $pdo->prepare('SELECT * FROM guest_private_messages WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
  }
  return null;
}

function guest_private_conversation(int $eventId, int $profileId, int $otherProfileId, int $limit = 60): array {
  $limit = max(20, min($limit, 200));
  $st = pdo()->prepare('SELECT m.*, sp.display_name AS sender_display_name, sp.avatar_token AS sender_avatar_token,
                               rp.display_name AS recipient_display_name, rp.avatar_token AS recipient_avatar_token
                        FROM guest_private_messages m
                        LEFT JOIN guest_profiles sp ON sp.id = m.sender_profile_id
                        LEFT JOIN guest_profiles rp ON rp.id = m.recipient_profile_id
                        WHERE m.event_id=? AND (
                          (m.sender_profile_id=? AND m.recipient_profile_id=?) OR
                          (m.sender_profile_id=? AND m.recipient_profile_id=?)
                        )
                        ORDER BY m.id DESC LIMIT '.$limit);
  $st->execute([$eventId, $profileId, $otherProfileId, $otherProfileId, $profileId]);
  $rows = array_reverse($st->fetchAll() ?: []);
  return $rows;
}

function guest_private_message_to_profile(int $eventId, array $senderProfile, array $targetProfile, string $body, ?int $uploadId = null): ?array {
  $recipient = [
    'profile_id' => (int)$targetProfile['id'],
    'upload_id' => $uploadId,
    'email' => $targetProfile['email'] ?? null,
    'name' => $targetProfile['display_name'] ?: ($targetProfile['name'] ?? 'Misafir')
  ];
  return guest_private_message_send($eventId, $senderProfile, $recipient, $body);
}

function guest_profile_update(int $profileId, string $displayName, string $bio, string $avatarToken, bool $marketingOptIn): void {
  $displayName = trim($displayName);
  if ($displayName === '') $displayName = 'Misafir';
  $bio = trim($bio);
  $bio = mb_substr($bio, 0, 600, 'UTF-8');
  $avatarToken = trim($avatarToken);
  $now = now();
  $fields = ['display_name=?', 'bio=?', 'avatar_token=?', 'marketing_opt_in=?', 'updated_at=?'];
  $params = [
    $displayName,
    $bio === '' ? null : $bio,
    $avatarToken === '' ? null : $avatarToken,
    $marketingOptIn ? 1 : 0,
    $now,
    $profileId
  ];
  if ($marketingOptIn) {
    pdo()->prepare('UPDATE guest_profiles SET '.implode(',', $fields).', marketing_opted_at=? WHERE id=?')
        ->execute([
          $displayName,
          $bio === '' ? null : $bio,
          $avatarToken === '' ? null : $avatarToken,
          1,
          $now,
          $now,
          $profileId
        ]);
  } else {
    pdo()->prepare('UPDATE guest_profiles SET '.implode(',', $fields).', marketing_opted_at=NULL WHERE id=?')
        ->execute([
          $displayName,
          $bio === '' ? null : $bio,
          $avatarToken === '' ? null : $avatarToken,
          0,
          $now,
          $profileId
        ]);
  }
}

function guest_profile_avatar_seed(array $profile): string {
  $token = $profile['avatar_token'] ?? '';
  if ($token === '' || $token === null) {
    $token = substr(hash('sha256', $profile['email'].$profile['id']), 0, 6);
  }
  return $token;
}

function guest_profile_send_panel_access(array $profile, array $event): void {
  if (empty($profile['email'])) {
    return;
  }
  $loginUrl = BASE_URL.'/public/guest_login.php?email='.rawurlencode($profile['email']);
  $galleryUrl = public_upload_url((int)$event['id']);
  $html = '<div style="font-family:Inter,Arial,sans-serif;background:#f8fafc;padding:24px">'
        .'<div style="max-width:540px;margin:0 auto;background:#ffffff;border-radius:18px;padding:36px;box-shadow:0 22px 48px rgba(14,165,181,0.18);">'
        .'<h2 style="margin-top:0;color:#0ea5b5;font-size:24px;">Misafir paneliniz hazır</h2>'
        .'<p style="color:#475569;font-size:15px;line-height:1.6;">Merhaba '.h($profile['display_name'] ?: $profile['name']).', doğrulama işleminiz tamamlandı.</p>'
        .'<p style="color:#475569;font-size:15px;line-height:1.6;">Şifrenizi belirledikten sonra aşağıdaki bağlantılardan '.h($event['title']).' etkinliğinin BİKARE misafir alanına her zaman ulaşabilirsiniz.</p>'
        .'<div style="margin:28px 0;display:flex;flex-direction:column;gap:12px;">'
        .'<a href="'.h($loginUrl).'" style="background:#0ea5b5;color:#fff;text-decoration:none;padding:14px 24px;border-radius:999px;font-weight:600;text-align:center;">Misafir Paneline Giriş</a>'
        .'<a href="'.h($galleryUrl).'" style="background:#f1fcfd;color:#0ea5b5;text-decoration:none;padding:14px 24px;border-radius:999px;font-weight:600;text-align:center;">Galeri Bağlantısını Aç</a>'
        .'</div>'
        .'<p style="color:#94a3b8;font-size:13px;">Bu e-postayı güvenle saklayabilirsiniz. Şifrenizi unuttuğunuzda doğrulama sayfasından yeniden talep edebilirsiniz.</p>'
        .'</div></div>';
  require_once __DIR__.'/mailer.php';
  send_smtp_mail($profile['email'], 'BİKARE misafir panel bağlantınız', $html);
}
