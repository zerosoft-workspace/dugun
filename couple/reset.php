<?php
require_once __DIR__.'/_auth.php';
$event_id=(int)($_GET['event']??0);
$st=pdo()->prepare("SELECT id,title,couple_username FROM events WHERE id=? AND is_active=1");
$st->execute([$event_id]); $ev=$st->fetch();
if(!$ev) exit('Etkinlik yok');

$sent=false; $err=null;
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['step']??'')==='send'){
  $email=trim($_POST['email']??'');
  if(strcasecmp($email,$ev['couple_username'])!==0) $err='E-posta uyuşmuyor.';
  else{
    $code=random_int(100000,999999);
    $_SESSION['reset'][$event_id]=['code'=>$code,'ts'=>time()];
    send_mail_simple($email,'Şifre Sıfırlama Kodu','<p>Kodunuz: <b>'.$code.'</b> (15 dk geçerli)</p>');
    $sent=true;
  }
}elseif($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['step']??'')==='change'){
  $code=(int)($_POST['code']??0); $p1=(string)($_POST['p1']??'');
  $rec=$_SESSION['reset'][$event_id]??null;
  if(!$rec || time()-$rec['ts']>900) $err='Kod süresi doldu.';
  elseif($code !== (int)$rec['code']) $err='Kod hatalı.';
  elseif(strlen($p1)<6) $err='Şifre en az 6 karakter.';
  else{
    pdo()->prepare("UPDATE events SET couple_password_hash=?, couple_force_reset=0 WHERE id=?")
       ->execute([password_hash($p1,PASSWORD_DEFAULT),$event_id]);
    unset($_SESSION['reset'][$event_id]); $_SESSION['couple_auth'][$event_id]=true;
    redirect('index.php?event='.$event_id.'&key=ok');
  }
}
?>
<!doctype html><html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Şifremi Unuttum</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>.cardx{max-width:460px;margin:6vh auto;border:1px solid #e9eef5;border-radius:18px;padding:24px;background:#fff}</style>
</head><body>
<div class="cardx">
  <h4>Şifremi Unuttum</h4>
  <div class="text-muted small mb-3"><?=h($ev['title'])?></div>
  <?php if($err): ?><div class="alert alert-danger"><?=$err?></div><?php endif; ?>
  <?php if(!$sent): ?>
    <form method="post" class="vstack gap-2">
      <input type="hidden" name="step" value="send">
      <label class="form-label">Kayıtlı e-posta</label>
      <input class="form-control" type="email" name="email" required value="<?=h($ev['couple_username'])?>">
      <button class="btn btn-primary mt-2">Kodu Gönder</button>
    </form>
  <?php else: ?>
    <div class="alert alert-info py-2">E-posta adresinize 6 haneli kod gönderildi.</div>
    <form method="post" class="vstack gap-2">
      <input type="hidden" name="step" value="change">
      <label class="form-label">Kod</label>
      <input class="form-control" name="code" required>
      <label class="form-label">Yeni şifre</label>
      <input class="form-control" type="password" name="p1" required>
      <button class="btn btn-primary mt-2">Şifreyi Sıfırla</button>
    </form>
  <?php endif; ?>
</div>
</body></html>
