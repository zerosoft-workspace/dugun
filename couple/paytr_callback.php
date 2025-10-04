<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/dealers.php';

$oid    = $_POST['merchant_oid'] ?? '';
$status = $_POST['status'] ?? '';
if($oid!==''){
  if (strncasecmp($oid, 'DT', 2) === 0) {
    dealer_handle_topup_callback($oid, $status, $_POST);
  } else {
    $st=pdo()->prepare("SELECT id FROM purchases WHERE paytr_oid=? LIMIT 1");
    $st->execute([$oid]);
    if($r=$st->fetch()){
      $new = ($status==='success')?'paid':'failed';
      pdo()->prepare("UPDATE purchases SET status=?, updated_at=NOW() WHERE id=?")->execute([$new,$r['id']]);
    }
  }
}
echo "OK";
