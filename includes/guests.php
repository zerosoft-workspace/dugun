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

function guest_profile_touch(int $profileId): void {
  $st = pdo()->prepare("UPDATE guest_profiles SET last_seen_at=?, updated_at=? WHERE id=?");
  $now = now();
  $st->execute([$now, $now, $profileId]);
}

function guest_profile_find_by_email(int $eventId, string $email): ?array {
  if ($email === '') return null;
  $st = pdo()->prepare("SELECT * FROM guest_profiles WHERE event_id=? AND email=? LIMIT 1");
  $st->execute([$eventId, $email]);
  return $st->fetch() ?: null;
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
        .'<p style="color:#475569;font-size:15px;line-height:1.6;">'.h($event['title']).' etkinliğinin dijital albümüne katıldığınız için teşekkür ederiz. Profilinizi doğruladığınızda fotoğrafları beğenebilir, yorum yazabilir ve misafir sohbetine katılabilirsiniz.</p>'
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
  $st = pdo()->prepare('SELECT * FROM guest_profiles WHERE verify_token=? LIMIT 1');
  $st->execute([$token]);
  $profile = $st->fetch();
  if (!$profile) return null;
  $now = now();
  pdo()->prepare('UPDATE guest_profiles SET is_verified=1, verified_at=?, verify_token=NULL, updated_at=? WHERE id=?')
      ->execute([$now, $now, (int)$profile['id']]);
  $fresh = guest_profile_current_after_verify((int)$profile['id']);
  if ($fresh) {
    guest_profile_touch((int)$fresh['id']);
  }
  return $fresh;
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

function guest_upload_comment_add(int $uploadId, ?array $profile, string $body): void {
  $body = trim($body);
  if ($body === '') return;
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
