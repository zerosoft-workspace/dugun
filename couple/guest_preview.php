<?php
require_once __DIR__.'/_auth.php';
require_once __DIR__.'/../includes/guests.php';

$profile = guest_profile_host_preview($EVENT_ID);
if (!$profile) {
  flash('err', 'Misafir paneli önizlemesi oluşturulamadı.');
  redirect('index.php');
}

guest_profile_clear_session($EVENT_ID);
guest_profile_set_session($EVENT_ID, (int)$profile['id']);
guest_profile_record_login((int)$profile['id']);
guest_profile_touch((int)$profile['id']);

if (!isset($_SESSION['guest_host_preview']) || !is_array($_SESSION['guest_host_preview'])) {
  $_SESSION['guest_host_preview'] = [];
}
$_SESSION['guest_host_preview'][$EVENT_ID] = true;

flash('ok', 'Misafir paneli önizlemesi açılıyor...');
redirect(public_upload_url($EVENT_ID));
