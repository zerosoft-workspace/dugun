<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/invitations.php';

install_schema();

$code = trim((string)($_GET['code'] ?? ''));
$share = trim((string)($_GET['share'] ?? ''));
$download = isset($_GET['download']);

if ($code === '' && $share === '') {
  http_response_code(404);
  exit('Davet kartı bulunamadı.');
}

try {
  if ($code !== '') {
    $contact = invitation_contact_by_token($code);
    if (!$contact) {
      throw new RuntimeException('Davetli bulunamadı.');
    }
    $eventId = (int)$contact['event_id'];
    $template = invitation_template_get($eventId);
    $event = invitation_event_row($eventId);
    if (!$event) {
      throw new RuntimeException('Etkinlik bulunamadı.');
    }
    $image = invitation_card_render($template, $event, $contact);
    $filename = 'davetiyeniz.png';
  } else {
    $template = invitation_template_by_share_token($share);
    if (!$template || empty($template['event_id'])) {
      throw new RuntimeException('Davetiye bulunamadı.');
    }
    $eventId = (int)$template['event_id'];
    $event = invitation_event_row($eventId);
    if (!$event) {
      throw new RuntimeException('Etkinlik bulunamadı.');
    }
    $image = invitation_card_render($template, $event, null);
    $filename = 'davetiye.png';
  }
} catch (Throwable $e) {
  http_response_code(404);
  exit('Davet kartı oluşturulamadı.');
}

header('Content-Type: image/png');
if ($download) {
  header('Content-Disposition: attachment; filename="'.$filename.'"');
}
header('Cache-Control: public, max-age=86400');

imagepng($image);
imagedestroy($image);
