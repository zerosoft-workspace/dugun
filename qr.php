<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/functions.php';

$code=trim($_GET['code']??'');
if ($code===''){ http_response_code(404); exit('QR yok'); }

$st=pdo()->prepare("SELECT q.*, e.id AS ev_id FROM qr_codes q LEFT JOIN events e ON e.id=q.target_event_id WHERE q.code=?");
$st->execute([$code]);
$q=$st->fetch();
if ($q && $q['ev_id']){
  $dest = public_upload_url((int)$q['ev_id']);
  redirect($dest, 301);
}

$st2=pdo()->prepare("SELECT dc.*, e.id AS ev_id FROM dealer_codes dc LEFT JOIN events e ON e.id=dc.target_event_id WHERE dc.code=? LIMIT 1");
$st2->execute([$code]);
$dc=$st2->fetch();
if ($dc && $dc['ev_id']){
  $dest = public_upload_url((int)$dc['ev_id']);
  redirect($dest, 301);
}

http_response_code(404);
exit('Hedef yok');
