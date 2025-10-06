<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/guests.php';
require_once __DIR__.'/../includes/event_games.php';

if (session_status() === PHP_SESSION_NONE) session_start();

install_schema();

if (!function_exists('csrf_token')) {
  function csrf_token(){ if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
}
if (!function_exists('csrf_or_die')) {
  function csrf_or_die(){ $t=$_POST['csrf']??$_POST['_csrf']??''; if(!$t || !hash_equals($_SESSION['csrf']??'', $t)){ http_response_code(400); exit('CSRF'); } }
}

function client_ip(){
  if(isset($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
  if(isset($_SERVER['HTTP_X_FORWARDED_FOR'])) return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
  return $_SERVER['REMOTE_ADDR']??'0.0.0.0';
}
function is_image_mime($m){ return (bool)preg_match('~^image/(jpeg|png|webp|gif)$~i',$m); }
function is_video_mime($m){ return (bool)preg_match('~^video/(mp4|quicktime|webm)$~i',$m); }

$event_id = (int)($_GET['event'] ?? 0);
$token    = trim($_GET['t'] ?? '');
if ($event_id <= 0){ http_response_code(400); exit('Geçersiz istek'); }

$st = pdo()->prepare("SELECT * FROM events WHERE id=? LIMIT 1");
$st->execute([$event_id]);
$ev = $st->fetch();
if (!$ev || (int)$ev['is_active']!==1){ http_response_code(404); exit('Etkinlik bulunamadı veya pasif.'); }

$VID      = (int)$ev['venue_id'];
$TITLE    = $ev['guest_title'] ?: 'Düğünümüze Hoş Geldiniz';
$SUBTITLE = $ev['guest_subtitle'] ?: 'En güzel anlarınızı bizimle paylaşın';
$PROMPT   = $ev['guest_prompt'] ?: 'Adınızı yazıp anınızı yükleyin.';
$PRIMARY  = $ev['theme_primary'] ?: '#0ea5b5';
$ACCENT   = $ev['theme_accent']  ?: '#e0f7fb';
$CAN_VIEW = (int)$ev['allow_guest_view']===1;
$CAN_DOWN = (int)$ev['allow_guest_download']===1;

$layout   = $ev['layout_json'] ?: '{"title":{"x":24,"y":24},"subtitle":{"x":24,"y":60},"prompt":{"x":24,"y":396}}';
$stickers = $ev['stickers_json'] ?: '[]';
$layoutArr   = json_decode($layout,true);
$stickersArr = json_decode($stickers,true);
if(!is_array($layoutArr)){
  $layoutArr = array('title'=>array('x'=>24,'y'=>24),'subtitle'=>array('x'=>24,'y'=>60),'prompt'=>array('x'=>24,'y'=>396));
}
if(!is_array($stickersArr)){ $stickersArr = array(); }
$tPos = isset($layoutArr['title'])    ? $layoutArr['title']    : array('x'=>24,'y'=>24);
$sPos = isset($layoutArr['subtitle']) ? $layoutArr['subtitle'] : array('x'=>24,'y'=>60);
$pPos = isset($layoutArr['prompt'])   ? $layoutArr['prompt']   : array('x'=>24,'y'=>396);

$profile = guest_profile_current($event_id);
$token_ok = token_valid($event_id, $token);
$pageCsrf = csrf_token();

$wheelEntries = event_wheel_entries_list($event_id, true);
$activeQuiz = event_quiz_active_question($event_id);
$activeAttempt = ($activeQuiz && $profile) ? event_quiz_attempt_for_profile($activeQuiz['id'], (int)$profile['id']) : null;
$quizLeaderboard = event_quiz_scoreboard($event_id, 12);
$quizStats = $profile ? event_quiz_profile_stats($event_id, (int)$profile['id']) : null;
$quizFlash = null;
if (!empty($_SESSION['quiz_flash']) && is_array($_SESSION['quiz_flash']) && isset($_SESSION['quiz_flash'][$event_id])) {
  $quizFlash = $_SESSION['quiz_flash'][$event_id];
  unset($_SESSION['quiz_flash'][$event_id]);
}

function json_fail(string $code, string $message, int $status = 400): never {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'code' => $code, 'message' => $message], JSON_UNESCAPED_UNICODE);
  exit;
}
function json_ok(array $payload = []): never {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => true] + $payload, JSON_UNESCAPED_UNICODE);
  exit;
}
function ensure_ajax_csrf(string $token): void {
  if ($token === '' || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
    json_fail('csrf', 'Oturum süresi doldu. Sayfayı yenileyin.');
  }
}
function ensure_profile(?array $profile): array {
  if (!$profile) {
    json_fail('auth', 'Bu işlem için önce misafir hesabınızla giriş yapın.');
  }
  return $profile;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
  $action = $_POST['action'] ?? '';
  ensure_ajax_csrf($_POST['csrf'] ?? '');

  switch ($action) {
    case 'load_upload':
      $uploadId = (int)($_POST['upload_id'] ?? 0);
      if ($uploadId <= 0) json_fail('input', 'Geçersiz kayıt.');
      $st = pdo()->prepare("SELECT u.*, gp.display_name, gp.avatar_token, gp.id AS gp_id
                             FROM uploads u
                             LEFT JOIN guest_profiles gp ON gp.id=u.profile_id
                             WHERE u.id=? AND u.event_id=? LIMIT 1");
      $st->execute([$uploadId, $event_id]);
      $upload = $st->fetch();
      if (!$upload) json_fail('not_found', 'Paylaşım bulunamadı.');

      $likeCount = guest_upload_like_count($uploadId);
      $commentCount = guest_upload_comment_count($uploadId);
      $liked = $profile ? guest_upload_is_liked($uploadId, (int)$profile['id']) : false;

      $commentsStmt = pdo()->prepare("SELECT c.*, gp.display_name, gp.avatar_token
                                       FROM guest_upload_comments c
                                       LEFT JOIN guest_profiles gp ON gp.id=c.profile_id
                                       WHERE c.upload_id=?
                                       ORDER BY c.id DESC LIMIT 40");
      $commentsStmt->execute([$uploadId]);
      $comments = [];
      foreach ($commentsStmt->fetchAll() as $row) {
        $comments[] = [
          'id' => (int)$row['id'],
          'body' => $row['body'],
          'display_name' => $row['display_name'] ?: ($row['guest_name'] ?: 'Misafir'),
          'avatar_token' => $row['avatar_token'] ?: null,
          'created_at' => $row['created_at'],
        ];
      }

      $data = [
        'id' => (int)$upload['id'],
        'guest_name' => $upload['display_name'] ?: ($upload['guest_name'] ?: 'Misafir'),
        'mime' => $upload['mime'],
        'file_path' => '/'.$upload['file_path'],
        'created_at' => $upload['created_at'],
        'like_count' => $likeCount,
        'comment_count' => $commentCount,
        'liked' => $liked,
        'can_download' => $CAN_DOWN,
        'is_video' => is_video_mime($upload['mime']),
        'share_url' => BASE_URL.'/'.$upload['file_path'],
        'comments' => $comments,
      ];
      json_ok(['upload' => $data]);

    case 'toggle_like':
      $profile = ensure_profile($profile);
      $uploadId = (int)($_POST['upload_id'] ?? 0);
      if ($uploadId <= 0) json_fail('input', 'Geçersiz kayıt.');
      $st = pdo()->prepare('SELECT id FROM uploads WHERE id=? AND event_id=? LIMIT 1');
      $st->execute([$uploadId, $event_id]);
      if (!$st->fetch()) json_fail('not_found', 'Paylaşım bulunamadı.');
      $liked = guest_upload_is_liked($uploadId, (int)$profile['id']);
      if ($liked) guest_upload_unlike($uploadId, (int)$profile['id']);
      else guest_upload_like($uploadId, (int)$profile['id']);
      $likeCount = guest_upload_like_count($uploadId);
      json_ok(['liked' => !$liked, 'like_count' => $likeCount]);

    case 'add_comment':
      $profile = ensure_profile($profile);
      $uploadId = (int)($_POST['upload_id'] ?? 0);
      $body = trim($_POST['body'] ?? '');
      if ($uploadId <= 0) json_fail('input', 'Geçersiz kayıt.');
      $st = pdo()->prepare('SELECT id FROM uploads WHERE id=? AND event_id=? LIMIT 1');
      $st->execute([$uploadId, $event_id]);
      if (!$st->fetch()) json_fail('not_found', 'Paylaşım bulunamadı.');
      $comment = guest_upload_comment_add($uploadId, $profile, $body);
      if (!$comment) json_fail('input', 'Yorum boş olamaz.');
      $payload = [
        'id' => (int)$comment['id'],
        'body' => $comment['body'],
        'display_name' => $comment['profile_display_name'] ?: ($comment['guest_name'] ?: 'Misafir'),
        'avatar_token' => $comment['profile_avatar_token'] ?: null,
        'created_at' => $comment['created_at'],
        'comment_count' => guest_upload_comment_count($uploadId),
      ];
      json_ok(['comment' => $payload]);

    case 'load_conversation':
      $profile = ensure_profile($profile);
      $otherId = (int)($_POST['profile_id'] ?? 0);
      if ($otherId <= 0) json_fail('input', 'Geçersiz profil.');
      $other = guest_profile_find_by_id($otherId);
      if (!$other || (int)$other['event_id'] !== $event_id) json_fail('not_found', 'Misafir bulunamadı.');
      $messages = guest_private_conversation($event_id, (int)$profile['id'], $otherId, 80);
      $out = [];
      foreach ($messages as $msg) {
        $out[] = [
          'id' => (int)$msg['id'],
          'sender_id' => (int)$msg['sender_profile_id'],
          'body' => $msg['body'],
          'created_at' => $msg['created_at'],
        ];
      }
      json_ok([
        'messages' => $out,
        'recipient' => [
          'id' => $otherId,
          'name' => $other['display_name'] ?: ($other['name'] ?: 'Misafir'),
        ],
      ]);

    case 'send_message':
      $profile = ensure_profile($profile);
      $otherId = (int)($_POST['profile_id'] ?? 0);
      $body = trim($_POST['body'] ?? '');
      if ($otherId <= 0) json_fail('input', 'Geçersiz profil.');
      $other = guest_profile_find_by_id($otherId);
      if (!$other || (int)$other['event_id'] !== $event_id) json_fail('not_found', 'Misafir bulunamadı.');
      $msg = guest_private_message_to_profile($event_id, $profile, $other, $body);
      if (!$msg) json_fail('input', 'Mesajınızı yazın.');
      json_ok([
        'message' => [
          'id' => (int)$msg['id'],
          'sender_id' => (int)$msg['sender_profile_id'],
          'body' => $msg['body'],
          'created_at' => $msg['created_at'],
        ]
      ]);

    case 'host_note':
      $profile = ensure_profile($profile);
      $body = trim($_POST['body'] ?? '');
      $note = guest_event_note_add($event_id, $profile, $body);
      if (!$note) json_fail('input', 'Mesajınızı yazın.');
      json_ok(['created_at' => $note['created_at']]);

    default:
      json_fail('action', 'Desteklenmeyen işlem.');
  }
}

$errors=[]; $okCount=0;

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='quiz_answer'){
  csrf_or_die();
  $redirectTo = BASE_URL.'/public/upload.php?event='.$event_id.'&t='.rawurlencode($token);
  if(!$profile){
    flash('err','Quiz sorularını cevaplamak için misafir girişi yapın.');
    redirect($redirectTo);
  }
  $questionId = (int)($_POST['question_id'] ?? 0);
  $answerId = (int)($_POST['answer_id'] ?? 0);
  if($questionId <= 0 || $answerId <= 0){
    flash('err','Lütfen bir cevap seçin.');
    redirect($redirectTo);
  }
  try {
    $result = event_quiz_attempt_submit($event_id, $questionId, $answerId, $profile);
    if (!isset($_SESSION['quiz_flash']) || !is_array($_SESSION['quiz_flash'])) {
      $_SESSION['quiz_flash'] = [];
    }
    $_SESSION['quiz_flash'][$event_id] = [
      'question_id' => $questionId,
      'answer_id' => $answerId,
      'correct' => $result['is_correct'],
      'points' => $result['points'],
    ];
    flash('ok','Cevabınız kaydedildi!');
  } catch (Throwable $e) {
    flash('err',$e->getMessage());
  }
  redirect($redirectTo);
}

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['do']??'')==='upload'){
  csrf_or_die();
  $p_token = trim($_POST['t']??'');
  if(!token_valid($event_id,$p_token)){
    $errors[]='Güvenlik anahtarı zaman aşımına uğradı. Lütfen QR’ı yeniden okutun.';
  }else{
    $guest = trim($_POST['guest_name']??'');
    $guestEmail = guest_profile_normalize_email($_POST['guest_email'] ?? '') ?: ($profile['email'] ?? '');
    if($guest===''){ $errors[]='Lütfen adınızı yazın.'; }
    if(!isset($_FILES['files'])){ $errors[]='Dosya seçilmedi.'; }
    else{
      $f=$_FILES['files'];
      $n=is_array($f['name'])?count($f['name']):0;
      if($n<=0) $errors[]='Dosya seçilmedi.';
      else{
        $dir=ensure_upload_dir($VID,$event_id);
        for($i=0;$i<$n;$i++){
          if($f['error'][$i]!==UPLOAD_ERR_OK){ $errors[]='Yükleme hatası (kod '.$f['error'][$i].')'; continue; }
          $tmp=$f['tmp_name'][$i]; $nm=$f['name'][$i]; $sz=(int)$f['size'][$i];
          if($sz<=0 || $sz>MAX_UPLOAD_BYTES){ $errors[]=h($nm).': limit '.round(MAX_UPLOAD_BYTES/1048576).' MB'; continue; }
          $fi=finfo_open(FILEINFO_MIME_TYPE); $mime=finfo_file($fi,$tmp); finfo_close($fi);
          if(!isset(ALLOWED_MIMES[$mime])){ $errors[]=h($nm).': desteklenmeyen tür ('.$mime.')'; continue; }
          $ext=ALLOWED_MIMES[$mime];
          $base=preg_replace('~[^a-zA-Z0-9-_]+~','_', pathinfo($nm,PATHINFO_FILENAME)); if($base==='') $base='file';
          $final=$base.'_'.date('Ymd_His').'_'.$i.'_'.bin2hex(random_bytes(3)).'.'.$ext;
          $dest=$dir.'/'.$final;
          if(!move_uploaded_file($tmp,$dest)){ $errors[]=h($nm).': taşınamadı'; continue; }
          $rel='uploads/v'.$VID.'/'.$event_id.'/'.$final;
          pdo()->prepare("INSERT INTO uploads (venue_id,event_id,guest_name,profile_id,guest_email,file_path,mime,file_size,ip,created_at)
                          VALUES (?,?,?,?,?,?,?,?,?,?)")
              ->execute([$VID,$event_id,$guest,$profile['id'] ?? null,$guestEmail ?: null,$rel,$mime,$sz,client_ip(),now()]);
          $okCount++;
        }
      }
    }
  }
  $to=BASE_URL.'/public/upload.php?event='.$event_id.'&t='.rawurlencode($token);
  if($okCount>0){ flash('ok',$okCount.' dosya yüklendi. Teşekkürler!'); header('Location:'.$to); exit; }
  if($errors){ flash('err',implode('<br>',array_map('h',$errors))); header('Location:'.$to); exit; }
}

$uploads=[];
if($CAN_VIEW){
  $st=pdo()->prepare("SELECT u.id,u.guest_name,u.file_path,u.mime,u.file_size,u.created_at,u.profile_id,
                            gp.display_name,
                            (SELECT COUNT(*) FROM guest_upload_likes gl WHERE gl.upload_id=u.id) AS like_count,
                            (SELECT COUNT(*) FROM guest_upload_comments gc WHERE gc.upload_id=u.id) AS comment_count
                      FROM uploads u
                      LEFT JOIN guest_profiles gp ON gp.id=u.profile_id
                      WHERE u.venue_id=? AND u.event_id=?
                      ORDER BY u.id DESC LIMIT 300");
  $st->execute([$VID,$event_id]);
  $uploads=$st->fetchAll();
}

$directory = $profile ? guest_event_profile_directory($event_id, (int)$profile['id']) : [];
?>
<!doctype html>
<html lang="tr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($ev['title'])?> — Misafir Yükleme</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<meta name="csrf" content="<?=h($pageCsrf)?>">
<style>
:root{ --zs:<?=h($PRIMARY)?>; --zs-soft:<?=h($ACCENT)?>; --ink:#0f172a; --muted:#64748b; --card:#ffffff; --border:#e2e8f0; }
body{ background:linear-gradient(180deg,var(--zs-soft),#fff); font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif; color:var(--ink); }
.page-shell{ max-width:1200px; margin:0 auto; }
.card-lite{ border:1px solid rgba(148,163,184,.22); border-radius:24px; background:var(--card); box-shadow:0 25px 70px -45px rgba(15,23,42,.4); }
.card-lite h5{ font-weight:700; }
.btn-zs{ background:linear-gradient(135deg,var(--zs),#0b8b98); border:none; color:#fff; border-radius:14px; padding:.75rem 1.4rem; font-weight:700; letter-spacing:.01em; }
.btn-zs:hover{ color:#fff; filter:brightness(.98); }
.btn-zs-outline{ background:rgba(14,165,181,.08); border:1px solid rgba(14,165,181,.4); color:var(--zs); border-radius:14px; font-weight:600; padding:.65rem 1.2rem; }
.dropzone{ border:2px dashed rgba(148,163,184,.4); border-radius:18px; padding:28px; text-align:center; background:#f8fafc; transition:.2s; }
.dropzone.drag{ border-color:var(--zs); background:#eefcfe; }
.smallmuted{ color:var(--muted); font-size:.92rem; }
.gallery-shell{ margin-top:2rem; }
.gallery-header{ display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom:1.5rem; }
.gallery-stream{ column-count:1; column-gap:1.6rem; }
@media(min-width:768px){ .gallery-stream{ column-count:2; } }
@media(min-width:1200px){ .gallery-stream{ column-count:3; } }
.gallery-card{ break-inside:avoid; margin-bottom:1.6rem; position:relative; }
.gallery-card button{ all:unset; cursor:pointer; display:block; }
.media-wrap{ position:relative; overflow:hidden; border-radius:24px; }
.media-wrap::after{ content:""; position:absolute; inset:0; background:linear-gradient(180deg,rgba(15,23,42,0) 45%,rgba(15,23,42,.68)); opacity:0; transition:.2s; }
.gallery-card:hover .media-wrap::after{ opacity:1; }
.media-wrap img,.media-wrap video{ width:100%; height:auto; display:block; }
.media-info{ position:absolute; left:0; right:0; bottom:0; padding:1.1rem 1.3rem; display:flex; flex-direction:column; gap:.55rem; color:#fff; }
.media-meta{ display:flex; align-items:center; justify-content:space-between; gap:.75rem; font-size:.95rem; font-weight:600; text-shadow:0 6px 20px rgba(0,0,0,.35); }
.media-actions{ display:flex; align-items:center; gap:.85rem; font-size:.92rem; }
.media-actions span{ display:inline-flex; align-items:center; gap:.3rem; }
.pill{ display:inline-flex; align-items:center; gap:.45rem; font-size:.82rem; font-weight:600; padding:.35rem .75rem; border-radius:999px; background:rgba(255,255,255,.16); backdrop-filter:blur(6px); }
.muted-link{ color:var(--muted); text-decoration:none; }
.muted-link:hover{ text-decoration:underline; }
.preview-shell{ width:min(100%,960px); margin:0 auto; }
.preview-stage{ position:relative; width:100%; border:1px dashed rgba(148,163,184,.45); border-radius:22px; background:#fff; overflow:hidden; }
.stage-scale{ position:absolute; left:0; top:0; width:960px; height:540px; transform-origin:top left; transform:scale(var(--s,1)); }
.preview-canvas{ position:absolute; inset:0; background:linear-gradient(180deg,var(--zs-soft),#fff); }
.pv-title{ position:absolute; font-size:28px; font-weight:800; color:#111; }
.pv-sub{ position:absolute; color:#334155; font-size:16px; }
.pv-prompt{ position:absolute; color:#0f172a; font-size:16px; }
.sticker{ position:absolute; user-select:none; pointer-events:none; }
.note-card{ border-radius:20px; border:1px solid rgba(148,163,184,.28); padding:1.8rem; background:#f8fafc; }
.note-card textarea{ border-radius:16px; border:1px solid rgba(148,163,184,.32); padding:1rem; font-size:.98rem; }
.note-card textarea:focus{ border-color:var(--zs); box-shadow:0 0 0 .25rem rgba(14,165,181,.2); }
.quiz-card h5{font-weight:700;}
.quiz-card .badge{background:rgba(14,165,181,.12);color:var(--zs);border-radius:999px;font-weight:600;padding:.4rem .9rem;}
.quiz-option{border:1px solid rgba(148,163,184,.28);border-radius:14px;padding:1rem;display:flex;align-items:center;gap:.75rem;cursor:pointer;transition:all .2s ease;background:#fff;}
.quiz-option:hover{border-color:rgba(14,165,181,.45);box-shadow:0 12px 30px -18px rgba(14,165,181,.6);}
.quiz-option input{accent-color:var(--zs);width:18px;height:18px;}
.quiz-option.selected{border-color:var(--zs);box-shadow:0 16px 40px -20px rgba(14,165,181,.45);}
.quiz-result{border-radius:14px;padding:.85rem 1rem;font-weight:600;}
.quiz-result.ok{background:rgba(22,163,74,.12);color:#15803d;}
.quiz-result.err{background:rgba(248,113,113,.16);color:#b91c1c;}
.scoreboard-card h5{font-weight:700;}
.scoreboard-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.65rem;}
.scoreboard-item{border:1px solid rgba(148,163,184,.24);border-radius:14px;padding:.75rem 1rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;background:#fff;}
.scoreboard-item.me{border-color:var(--zs);background:rgba(14,165,181,.08);}
.scoreboard-item strong{font-size:1.05rem;}
.scoreboard-meta{display:flex;align-items:center;gap:.6rem;color:var(--muted);font-size:.9rem;}
.spin-trigger{position:fixed;bottom:24px;right:24px;z-index:1080;border-radius:999px;padding:.85rem 1.4rem;font-weight:700;background:linear-gradient(135deg,var(--zs),#0b8b98);color:#fff;border:none;box-shadow:0 22px 60px -20px rgba(14,165,181,.6);}
.spin-trigger:hover{color:#fff;filter:brightness(.98);}
.wheel-visual{width:280px;height:280px;border-radius:50%;margin:0 auto 1.5rem;background:conic-gradient(#0ea5b5 0deg,#0ea5b5 360deg);position:relative;transition:transform 4.2s cubic-bezier(.19,1,.22,1);transform:rotate(var(--wheel-rotation,0deg));box-shadow:0 30px 90px -45px rgba(14,165,181,.6);}
.wheel-visual::after{content:"";position:absolute;inset:18%;background:rgba(255,255,255,.85);border-radius:50%;}
.wheel-pointer{position:absolute;top:-22px;left:50%;transform:translateX(-50%);width:0;height:0;border-left:18px solid transparent;border-right:18px solid transparent;border-bottom:24px solid rgba(14,165,181,.85);filter:drop-shadow(0 6px 14px rgba(14,165,181,.4));}
.wheel-modal .modal-content{border-radius:28px;border:none;}
.wheel-entries{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.5rem;}
.wheel-entries li{display:flex;align-items:center;gap:.75rem;border:1px solid rgba(148,163,184,.24);border-radius:12px;padding:.55rem .75rem;background:#fff;}
.wheel-chip{width:20px;height:20px;border-radius:50%;flex-shrink:0;}
.wheel-result{font-weight:700;font-size:1.1rem;text-align:center;margin-top:.5rem;}
.quiz-empty{color:var(--muted);font-style:italic;}
.leaderboard-empty{color:var(--muted);font-style:italic;}
@media(max-width:768px){.spin-trigger{right:16px;bottom:16px;padding:.75rem 1.2rem;font-size:.95rem;}}
.modal-media{ width:100%; border-radius:20px; background:#000; overflow:hidden; }
.modal-media img,.modal-media video{ width:100%; height:auto; display:block; }
.comment-list{ max-height:300px; overflow:auto; display:flex; flex-direction:column; gap:1rem; }
.comment-item{ display:flex; gap:.9rem; }
.comment-avatar{ width:42px; height:42px; border-radius:14px; background:rgba(14,165,181,.15); display:flex; align-items:center; justify-content:center; font-weight:700; color:var(--zs); }
.comment-body{ flex:1; }
.comment-body h6{ margin:0; font-size:.95rem; font-weight:700; }
.comment-body p{ margin:.35rem 0 0; font-size:.94rem; color:var(--ink); }
.comment-body time{ display:block; font-size:.8rem; color:var(--muted); margin-top:.2rem; }
.like-btn{ border:none; background:rgba(14,165,181,.12); color:var(--zs); border-radius:999px; padding:.45rem 1.1rem; font-weight:600; display:inline-flex; align-items:center; gap:.45rem; }
.like-btn.active{ background:linear-gradient(135deg,var(--zs),#0b8b98); color:#fff; }
.share-btn{ border:none; background:rgba(15,23,42,.08); color:var(--ink); border-radius:999px; padding:.45rem 1.1rem; font-weight:600; display:inline-flex; align-items:center; gap:.45rem; }
.offcanvas-bikare{ width:360px; }
.chat-user{ display:flex; align-items:center; gap:.85rem; padding:.65rem .85rem; border-radius:14px; cursor:pointer; transition:.2s; }
.chat-user:hover{ background:rgba(14,165,181,.1); }
.chat-user.active{ background:rgba(14,165,181,.18); }
.chat-avatar{ width:42px; height:42px; border-radius:14px; background:rgba(14,165,181,.14); display:flex; align-items:center; justify-content:center; font-weight:700; color:var(--zs); }
.chat-window{ min-height:260px; max-height:360px; overflow:auto; display:flex; flex-direction:column; gap:.75rem; padding:1rem; background:#f8fafc; border-radius:18px; }
.bubble{ padding:.6rem .9rem; border-radius:16px; max-width:80%; font-size:.94rem; line-height:1.5; }
.bubble.me{ background:linear-gradient(135deg,var(--zs),#0b8b98); color:#fff; margin-left:auto; }
.bubble.them{ background:#fff; border:1px solid rgba(148,163,184,.26); color:var(--ink); }
.chat-form textarea{ border-radius:14px; border:1px solid rgba(148,163,184,.32); padding:.75rem; resize:none; }
.chat-form textarea:focus{ border-color:var(--zs); box-shadow:0 0 0 .25rem rgba(14,165,181,.2); }
@media(max-width:768px){ .card-lite{ border-radius:18px; } }
</style>
</head>
<body>
<div class="container py-4 page-shell">
  <?php flash_box(); ?>

  <div class="card-lite p-4 mb-4">
    <div class="preview-shell">
      <div class="preview-stage" id="pvStage">
        <div class="stage-scale" id="scaleBox">
          <div class="preview-canvas">
            <div class="pv-title"  style="left:<?= (int)$tPos['x']?>px; top:<?= (int)$tPos['y']?>px;"><?=h($TITLE)?></div>
            <div class="pv-sub"    style="left:<?= (int)$sPos['x']?>px; top:<?= (int)$sPos['y']?>px;"><?=h($SUBTITLE)?></div>
            <div class="pv-prompt" style="left:<?= (int)$pPos['x']?>px; top:<?= (int)$pPos['y']?>px;"><?=h($PROMPT)?></div>
            <?php foreach($stickersArr as $st){
              $txt = isset($st['txt'])?$st['txt']:'💍';
              $x   = isset($st['x'])?(int)$st['x']:20;
              $y   = isset($st['y'])?(int)$st['y']:90;
              $sz  = isset($st['size'])?(int)$st['size']:32; ?>
              <div class="sticker" style="left:<?=$x?>px; top:<?=$y?>px; font-size:<?=$sz?>px"><?=$txt?></div>
            <?php } ?>
          </div>
        </div>
      </div>
    </div>
    <div class="smallmuted mt-3">Önizleme, çift panelde kaydettiğiniz düzenin birebir yansımasıdır.</div>
  </div>

  <?php if(!$token_ok): ?>
    <div class="alert alert-warning" style="border-radius:14px">Güvenlik anahtarı süresi dolmuş. Lütfen QR’ı yeniden okutun.</div>
  <?php endif; ?>

  <div class="card-lite p-4 mb-4">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-3">
      <div>
        <h5 class="mb-1">Anınızı Paylaşın</h5>
        <div class="smallmuted">Fotoğraf veya videolarınızı yüksek çözünürlükte yükleyin. Adınızı paylaşmayı unutmayın.</div>
      </div>
      <?php if($profile): ?>
        <div class="pill">✔ <?=h($profile['display_name'] ?: $profile['name'])?> olarak giriş yaptınız</div>
      <?php else: ?>
        <a class="btn btn-zs-outline" href="<?=BASE_URL?>/public/guest_login.php">Misafir Girişi</a>
      <?php endif; ?>
    </div>
    <form method="post" enctype="multipart/form-data" id="upForm" class="vstack gap-3">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="do" value="upload">
      <input type="hidden" name="t" value="<?=h($token)?>">
      <div>
        <label class="form-label">Adınız</label>
        <input class="form-control" name="guest_name" placeholder="Ad Soyad" required <?= !$token_ok?'disabled':'' ?>>
      </div>
      <div>
        <label class="form-label">E-posta (opsiyonel)</label>
        <input type="email" class="form-control" name="guest_email" placeholder="ornek@eposta.com" <?= !$token_ok?'disabled':'' ?>>
        <div class="form-text">E-postanızı eklemeniz, galeriye tekrar girebilmeniz ve şifrenizi belirlemeniz için önerilir.</div>
      </div>
      <div class="dropzone" id="drop">
        <p class="m-0">
          <b>Dosyalarınızı buraya sürükleyin</b> veya
          <label class="text-decoration-underline" style="cursor:pointer">bilgisayardan seçin
            <input type="file" name="files[]" id="fileI" accept="<?=implode(',',array_keys(ALLOWED_MIMES))?>" multiple hidden <?= !$token_ok?'disabled':'' ?>>
          </label>
        </p>
        <div class="smallmuted mt-2">İzinli: jpg, png, webp, gif, mp4, mov, webm — Maks: <?=round(MAX_UPLOAD_BYTES/1048576)?> MB/dosya</div>
      </div>
      <div id="list" class="smallmuted"></div>
      <div class="d-grid"><button class="btn btn-zs" <?= !$token_ok?'disabled':'' ?>>Yükle</button></div>
    </form>
  </div>

  <?php if($profile): ?>
    <div class="note-card mb-4">
      <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-3">
        <div>
          <h5 class="mb-1">Etkinlik sahibine mesaj gönder</h5>
          <div class="smallmuted">Güzel dileklerinizi veya teşekkürlerinizi iletebilirsiniz.</div>
        </div>
        <button class="btn btn-zs-outline" type="button" data-bs-toggle="offcanvas" data-bs-target="#messagesPanel">Misafirlerle Mesajlaş</button>
      </div>
      <form id="hostNoteForm" class="vstack gap-3">
        <textarea name="note" rows="3" placeholder="Mesajınızı yazın..."></textarea>
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
          <div class="smallmuted" id="hostNoteStatus"></div>
          <button class="btn btn-zs" type="submit">Mesajı Gönder</button>
        </div>
      </form>
    </div>
  <?php endif; ?>

  <div class="row g-4 mb-4 align-items-stretch">
    <div class="col-xl-7">
      <div class="card-lite p-4 quiz-card h-100">
        <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-3">
          <div>
            <h5 class="mb-1"><i class="bi bi-patch-question-fill me-2"></i>Canlı Quiz</h5>
            <div class="smallmuted">Etkinlik sahibi tarafından paylaşılan soruları cevaplayarak puan toplayın.</div>
          </div>
          <?php if($activeQuiz): ?>
            <span class="badge align-self-start">Aktif Soru</span>
          <?php endif; ?>
        </div>
        <?php if($quizFlash): ?>
          <div class="quiz-result <?=$quizFlash['correct'] ? 'ok' : 'err'?> mb-3">
            <?=$quizFlash['correct'] ? 'Tebrikler! Doğru cevabı verdiniz.' : 'Bu kez olmadı, bir sonraki soruya hazır olun.'?>
            <?php if(isset($quizFlash['points']) && $quizFlash['points']>0): ?>
              <span class="ms-2">(+<?=$quizFlash['points']?> puan)</span>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <?php if($activeQuiz): ?>
          <h6 class="fw-semibold mb-3"><?=h($activeQuiz['question'])?></h6>
          <?php if($profile): ?>
            <form method="post" class="vstack gap-3">
              <input type="hidden" name="_csrf" value="<?=h($pageCsrf)?>">
              <input type="hidden" name="do" value="quiz_answer">
              <input type="hidden" name="question_id" value="<?=$activeQuiz['id']?>">
              <?php foreach($activeQuiz['answers'] as $ans):
                $checked = $activeAttempt && $activeAttempt['answer_id'] === $ans['id'];
              ?>
              <label class="quiz-option <?=$checked?'selected':''?>">
                <input type="radio" name="answer_id" value="<?=$ans['id']?>" <?=$checked?'checked':''?>>
                <span><?=h($ans['text'])?></span>
                <?php if($activeAttempt && $activeAttempt['answer_id'] === $ans['id']): ?>
                  <span class="badge ms-auto <?=$activeAttempt['is_correct'] ? 'bg-success text-white' : 'bg-danger text-white'?>">
                    <?=$activeAttempt['is_correct'] ? 'Seçiminiz' : 'Seçiminiz'?>
                  </span>
                <?php endif; ?>
              </label>
              <?php endforeach; ?>
              <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-zs" type="submit">Cevabı Gönder</button>
                <?php if(!$activeAttempt): ?>
                  <div class="smallmuted align-self-center">Her misafir bir soruyu bir kez cevaplayabilir.</div>
                <?php else: ?>
                  <div class="smallmuted align-self-center">Cevabınızı güncellemek isterseniz tekrar seçim yapıp gönderin.</div>
                <?php endif; ?>
              </div>
            </form>
          <?php else: ?>
            <div class="quiz-empty">Quiz sorularını cevaplamak için <a href="<?=BASE_URL?>/public/guest_login.php" class="muted-link">misafir girişi</a> yapın.</div>
          <?php endif; ?>
        <?php else: ?>
          <div class="quiz-empty">Şu anda aktif bir soru bulunmuyor. Yeni sorular geldiğinde buradan takip edebilirsiniz.</div>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-xl-5">
      <div class="card-lite p-4 scoreboard-card h-100">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h5 class="mb-0"><i class="bi bi-trophy-fill me-2 text-warning"></i>Quiz Sıralaması</h5>
          <?php if($quizStats && ($quizStats['points']>0 || $quizStats['attempts']>0)): ?>
            <span class="badge bg-light text-dark">Sizin puanınız: <?=$quizStats['points']?></span>
          <?php endif; ?>
        </div>
        <?php if($quizLeaderboard): ?>
          <ul class="scoreboard-list">
            <?php foreach($quizLeaderboard as $item):
              $isMe = $profile && (int)$profile['id'] === $item['profile_id'];
            ?>
            <li class="scoreboard-item <?=$isMe?'me':''?>">
              <div>
                <strong>#<?=$item['rank']?> <?=h($item['name'])?></strong>
                <div class="scoreboard-meta"><span><i class="bi bi-lightning-charge-fill text-warning"></i><?=$item['points']?> puan</span><span><i class="bi bi-check-circle-fill text-success"></i><?=$item['correct']?> doğru</span></div>
              </div>
            </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="leaderboard-empty">Henüz puanlanan bir cevap yok. İlk puanı siz alın!</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card-lite p-4">
    <div class="gallery-header">
      <div>
        <h5 class="mb-1">Galeri</h5>
        <div class="smallmuted">Sevdiklerinizin paylaştığı anları keşfedin, beğenin ve yorum yapın.</div>
      </div>
      <?php if(!$CAN_VIEW): ?>
        <span class="pill">Galeri gizli</span>
      <?php else: ?>
        <span class="pill">İndirme <?= $CAN_DOWN ? 'açık' : 'kapalı' ?></span>
      <?php endif; ?>
    </div>
    <?php if(!$CAN_VIEW): ?>
      <div class="smallmuted">Galeri bu etkinlikte gizli.</div>
    <?php else: ?>
      <?php if(!$uploads): ?>
        <div class="smallmuted">Henüz paylaşım yapılmadı. İlk anıyı siz yükleyin!</div>
      <?php else: ?>
        <div class="gallery-stream" id="galleryStream">
          <?php foreach($uploads as $u):
            $path = '/'.$u['file_path'];
            $isImg=is_image_mime($u['mime']); $isVid=is_video_mime($u['mime']);
            $displayName = $u['display_name'] ?: ($u['guest_name'] ?: 'Misafir');
          ?>
          <article class="gallery-card" data-upload="<?=intval($u['id'])?>">
            <button type="button" class="gallery-open" data-upload="<?=intval($u['id'])?>">
              <div class="media-wrap">
                <?php if($isImg): ?>
                  <img src="<?=h($path)?>" alt="<?=h($displayName)?> paylaşımı">
                <?php elseif($isVid): ?>
                  <video src="<?=h($path)?>" muted playsinline></video>
                <?php else: ?>
                  <div style="padding:4rem 1rem;text-align:center;color:var(--muted);background:#f1f5f9;">Dosya önizlemesi desteklenmiyor</div>
                <?php endif; ?>
                <div class="media-info">
                  <div class="media-meta">
                    <span><?=h($displayName)?></span>
                    <span><?=date('d.m.Y H:i', strtotime($u['created_at']))?></span>
                  </div>
                  <div class="media-actions">
                    <span>❤️ <?= (int)$u['like_count'] ?></span>
                    <span>💬 <?= (int)$u['comment_count'] ?></span>
                  </div>
                </div>
              </div>
            </button>
          </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="modal fade" id="uploadModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content" style="border-radius:24px; border:none;">
      <div class="modal-body p-4">
        <div class="row g-4">
          <div class="col-lg-7">
            <div class="modal-media" id="modalMedia"></div>
          </div>
          <div class="col-lg-5 d-flex flex-column gap-3">
            <div>
              <h5 id="modalTitle" class="mb-0"></h5>
              <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
                <button class="like-btn" type="button" id="modalLike"><span>❤️</span> <span id="modalLikeCount">0</span></button>
                <button class="share-btn" type="button" id="modalShare">Paylaş</button>
                <a class="btn btn-sm btn-zs-outline d-none" id="modalDownload" download>İndir</a>
              </div>
            </div>
            <div>
              <div class="d-flex align-items-center gap-2 mb-2"><strong>Yorumlar</strong><span class="pill" id="modalCommentCount">0</span></div>
              <div class="comment-list" id="modalComments"></div>
            </div>
            <?php if($profile): ?>
              <form id="commentForm" class="vstack gap-2">
                <textarea id="commentText" rows="3" class="form-control" placeholder="Yorum yaz..." required></textarea>
                <button class="btn btn-zs" type="submit">Yorumu Gönder</button>
              </form>
            <?php else: ?>
              <div class="smallmuted">Yorum yapmak için <a href="<?=BASE_URL?>/public/guest_login.php" class="muted-link">misafir girişi</a> yapın.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="modal-footer" style="border:none; padding:0 2rem 1.5rem;">
        <button class="btn btn-zs-outline" data-bs-dismiss="modal">Kapat</button>
      </div>
    </div>
  </div>
</div>

<?php if($profile): ?>
<div class="offcanvas offcanvas-end offcanvas-bikare" tabindex="-1" id="messagesPanel">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title">Mesajlar</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body d-flex flex-column gap-3">
    <div>
      <div class="smallmuted mb-2">Misafir listesi</div>
      <div id="chatUsers" class="d-flex flex-column gap-1"></div>
    </div>
    <div>
      <div class="smallmuted mb-2" id="chatHeading">Mesaj seçilmedi</div>
      <div class="chat-window" id="chatWindow"></div>
    </div>
    <form id="chatForm" class="chat-form vstack gap-2">
      <textarea id="chatMessage" rows="2" placeholder="Mesajınızı yazın..." required></textarea>
      <button class="btn btn-zs" type="submit">Gönder</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if($wheelEntries): ?>
  <button class="spin-trigger" type="button" data-bs-toggle="modal" data-bs-target="#wheelModal"><i class="bi bi-record-circle me-2"></i>Çarkı Çevir</button>
  <div class="modal fade wheel-modal" id="wheelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content p-4">
        <div class="wheel-pointer"></div>
        <div class="wheel-visual" id="wheelVisual"></div>
        <div class="wheel-result" id="wheelResult">Şanslı isim için çarkı çevirin!</div>
        <div class="d-flex justify-content-center mt-3">
          <button class="btn btn-zs" type="button" id="wheelSpin">Çevir</button>
        </div>
        <hr class="my-4">
        <h6 class="fw-semibold mb-3">Çark Segmentleri</h6>
        <ul class="wheel-entries" id="wheelEntriesList"></ul>
      </div>
    </div>
  </div>
  <script type="application/json" id="wheelData"><?=json_encode($wheelEntries, JSON_UNESCAPED_UNICODE)?></script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// 960x540 sahneyi container'a orantılı sığdır
(function(){
  const stage=document.getElementById('pvStage'), box=document.getElementById('scaleBox');
  function fit(){ if(!stage||!box) return; const W=stage.clientWidth, S=W/960; box.style.setProperty('--s',S); stage.style.height=(540*S)+'px'; }
  window.addEventListener('resize',fit,{passive:true}); new ResizeObserver(fit).observe(stage); fit();
})();

const dz=document.getElementById('drop'), fi=document.getElementById('fileI'), lst=document.getElementById('list'), fm=document.getElementById('upForm');
if(dz){
  ['dragenter','dragover'].forEach(ev=>dz.addEventListener(ev,e=>{e.preventDefault();dz.classList.add('drag');}));
  ['dragleave','drop'].forEach(ev=>dz.addEventListener(ev,e=>{e.preventDefault();dz.classList.remove('drag');}));
  dz.addEventListener('drop',e=>{ const fs=e.dataTransfer.files; if(fs&&fi){ fi.files=fs; renderList(fs); } });
}
fi?.addEventListener('change',e=>renderList(e.target.files));
function renderList(files){ if(!files||!files.length){ lst.innerHTML=''; return; } let out='<ul class="m-0 ps-3">'; for(let i=0;i<files.length;i++){ const f=files[i]; out+=`<li>${esc(f.name)} — ${(f.size/1048576).toFixed(1)} MB</li>`; } lst.innerHTML=out+'</ul>'; }
function esc(s){return s.replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}
fm?.addEventListener('submit',e=>{ const name=fm.querySelector('[name=guest_name]').value.trim(); if(!name){e.preventDefault();alert('Lütfen adınızı yazın.');} const fs=fi?.files||[]; if(!fs.length){e.preventDefault(); alert('Lütfen dosya seçin.');} });

const csrfMeta=document.querySelector('meta[name="csrf"]');
const csrfToken=csrfMeta?csrfMeta.content:'';
const galleryModal=document.getElementById('uploadModal');
const modalTitle=document.getElementById('modalTitle');
const modalMedia=document.getElementById('modalMedia');
const modalLike=document.getElementById('modalLike');
const modalLikeCount=document.getElementById('modalLikeCount');
const modalCommentCount=document.getElementById('modalCommentCount');
const modalComments=document.getElementById('modalComments');
const commentForm=document.getElementById('commentForm');
const commentTextarea=document.getElementById('commentText');
const downloadBtn=document.getElementById('modalDownload');
let activeUploadId=null;
let bootstrapModal=null;

function fmtDate(tr){
  if(!tr) return '';
  const d=new Date(tr.replace(' ','T'));
  if(isNaN(d)) return tr;
  return d.toLocaleString('tr-TR',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});
}
function renderComment(c){
  const initials=(c.display_name||'M').trim().split(/\s+/).map(p=>p.charAt(0)).join('').substring(0,2).toUpperCase();
  return `<div class="comment-item"><div class="comment-avatar">${initials}</div><div class="comment-body"><h6>${escapeHtml(c.display_name||'Misafir')}</h6><p>${escapeHtml(c.body)}</p><time>${fmtDate(c.created_at)}</time></div></div>`;
}
function escapeHtml(str){
  return String(str).replace(/[&<>"']/g,m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#039;"}[m]));
}
function loadUpload(id){
  activeUploadId=id;
  fetch(window.location.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body:new URLSearchParams({ajax:'1',action:'load_upload',upload_id:id,csrf:csrfToken})})
    .then(r=>r.json())
    .then(data=>{
      if(!data.ok) throw new Error(data.message||'Bir hata oluştu');
      const up=data.upload;
      modalTitle.textContent=`${up.guest_name} · ${fmtDate(up.created_at)}`;
      modalMedia.innerHTML='';
      if(up.is_video){
        const vid=document.createElement('video');
        vid.src=up.file_path;
        vid.controls=true;
        vid.playsInline=true;
        modalMedia.appendChild(vid);
      }else{
        const img=document.createElement('img');
        img.src=up.file_path;
        img.alt=up.guest_name;
        modalMedia.appendChild(img);
      }
      modalLike.dataset.active=up.liked?'1':'0';
      modalLike.classList.toggle('active', !!up.liked);
      modalLikeCount.textContent=up.like_count;
      modalCommentCount.textContent=up.comment_count;
      if(up.can_download){
        downloadBtn.classList.remove('d-none');
        downloadBtn.href=up.file_path;
      }else{
        downloadBtn.classList.add('d-none');
      }
      modalComments.innerHTML='';
      if(!up.comments.length){
        modalComments.innerHTML='<div class="smallmuted">Henüz yorum yok. İlk yorumu siz yazın!</div>';
      }else{
        up.comments.slice().reverse().forEach(c=>{ modalComments.insertAdjacentHTML('beforeend', renderComment(c)); });
      }
      if(!bootstrapModal){ bootstrapModal = new bootstrap.Modal(galleryModal); }
      bootstrapModal.show();
    })
    .catch(err=>{ alert(err.message||'Bir hata oluştu'); });
}

document.querySelectorAll('.gallery-open').forEach(btn=>{
  btn.addEventListener('click',()=>{
    const id=btn.dataset.upload;
    if(id) loadUpload(id);
  });
});

modalLike?.addEventListener('click',()=>{
  if(!activeUploadId) return;
  fetch(window.location.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body:new URLSearchParams({ajax:'1',action:'toggle_like',upload_id:activeUploadId,csrf:csrfToken})})
    .then(r=>r.json())
    .then(data=>{
      if(!data.ok) throw new Error(data.message||'Bir hata oluştu');
      modalLike.dataset.active=data.liked?'1':'0';
      modalLike.classList.toggle('active', !!data.liked);
      modalLikeCount.textContent=data.like_count;
      const card=document.querySelector(`.gallery-card[data-upload="${activeUploadId}"] .media-actions span:first-child`);
      if(card) card.textContent=`❤️ ${data.like_count}`;
    })
    .catch(err=>{ alert(err.message||'Giriş yapmanız gerekiyor.'); });
});

commentForm?.addEventListener('submit',ev=>{
  ev.preventDefault();
  if(!activeUploadId) return;
  const body=commentTextarea.value.trim();
  if(!body){ commentTextarea.focus(); return; }
  commentTextarea.disabled=true;
  fetch(window.location.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body:new URLSearchParams({ajax:'1',action:'add_comment',upload_id:activeUploadId,body:body,csrf:csrfToken})})
    .then(r=>r.json())
    .then(data=>{
      if(!data.ok) throw new Error(data.message||'Bir hata oluştu');
      if(modalComments.querySelector('.smallmuted')) modalComments.innerHTML='';
      modalComments.insertAdjacentHTML('beforeend', renderComment(data.comment));
      modalCommentCount.textContent=data.comment.comment_count;
      const card=document.querySelector(`.gallery-card[data-upload="${activeUploadId}"] .media-actions span:last-child`);
      if(card) card.textContent=`💬 ${data.comment.comment_count}`;
      commentTextarea.value='';
    })
    .catch(err=>{ alert(err.message||'Bir hata oluştu'); })
    .finally(()=>{ commentTextarea.disabled=false; commentTextarea.focus(); });
});

const shareBtn=document.getElementById('modalShare');
shareBtn?.addEventListener('click',()=>{
  if(!activeUploadId) return;
  const link=downloadBtn?.href||window.location.href;
  if(navigator.share){
    navigator.share({ title: document.title, url: link }).catch(()=>{});
  }else if(navigator.clipboard){
    navigator.clipboard.writeText(link).then(()=>{ shareBtn.textContent='Bağlantı kopyalandı'; setTimeout(()=>shareBtn.textContent='Paylaş',2000); }).catch(()=>{ alert('Bağlantı: '+link); });
  }else{
    alert('Bağlantı: '+link);
  }
});

const hostForm=document.getElementById('hostNoteForm');
const hostStatus=document.getElementById('hostNoteStatus');
hostForm?.addEventListener('submit',ev=>{
  ev.preventDefault();
  const body=hostForm.note.value.trim();
  if(!body){ hostStatus.textContent='Mesajınızı yazın.'; return; }
  hostStatus.textContent='Gönderiliyor...';
  fetch(window.location.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body:new URLSearchParams({ajax:'1',action:'host_note',body:body,csrf:csrfToken})})
    .then(r=>r.json())
    .then(data=>{
      if(!data.ok) throw new Error(data.message||'Bir hata oluştu');
      hostStatus.textContent='Mesajınız iletildi. Teşekkür ederiz!';
      hostForm.note.value='';
    })
    .catch(err=>{ hostStatus.textContent=err.message||'Bir sorun oluştu.'; });
});

<?php if($profile): ?>
const directory = <?=json_encode($directory, JSON_UNESCAPED_UNICODE)?>;
const currentProfileId = <?= (int)$profile['id'] ?>;
const chatUsers=document.getElementById('chatUsers');
const chatWindow=document.getElementById('chatWindow');
const chatHeading=document.getElementById('chatHeading');
const chatForm=document.getElementById('chatForm');
const chatMessage=document.getElementById('chatMessage');
let activeChatId=null;

function initials(name){
  return name.trim().split(/\s+/).map(p=>p.charAt(0)).join('').substring(0,2).toUpperCase();
}
function renderDirectory(){
  if(!chatUsers) return;
  chatUsers.innerHTML='';
  if(!directory.length){
    chatUsers.innerHTML='<div class="smallmuted">Henüz doğrulanmış başka misafir yok.</div>';
    chatForm.classList.add('d-none');
    return;
  }
  directory.forEach(item=>{
    const btn=document.createElement('div');
    btn.className='chat-user';
    btn.dataset.profileId=item.id;
    btn.innerHTML=`<div class="chat-avatar">${initials(item.display_name || 'Misafir')}</div><div><div class="fw-semibold">${escapeHtml(item.display_name || 'Misafir')}</div><div class="smallmuted">${item.email||''}</div></div>`;
    btn.addEventListener('click',()=>selectChat(item.id, item.display_name || 'Misafir'));
    chatUsers.appendChild(btn);
  });
}
function selectChat(id,name){
  activeChatId=id;
  chatMessage.value='';
  chatWindow.innerHTML='<div class="smallmuted">Yükleniyor...</div>';
  chatUsers.querySelectorAll('.chat-user').forEach(el=>{
    el.classList.toggle('active', parseInt(el.dataset.profileId,10)===id);
  });
  fetch(window.location.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body:new URLSearchParams({ajax:'1',action:'load_conversation',profile_id:id,csrf:csrfToken})})
    .then(r=>r.json())
    .then(data=>{
      if(!data.ok) throw new Error(data.message||'Bir hata oluştu');
      chatHeading.textContent=data.recipient.name+' ile sohbet';
      chatWindow.innerHTML='';
      if(!data.messages.length){
        chatWindow.innerHTML='<div class="smallmuted">İlk mesajı siz gönderin.</div>';
      }else{
        data.messages.forEach(msg=>{
          const bubble=document.createElement('div');
          bubble.className='bubble '+(msg.sender_id===currentProfileId?'me':'them');
          bubble.textContent=msg.body;
          chatWindow.appendChild(bubble);
        });
        chatWindow.scrollTop=chatWindow.scrollHeight;
      }
    })
    .catch(err=>{ chatWindow.innerHTML='<div class="smallmuted">'+escapeHtml(err.message||'Bir hata oluştu')+'</div>'; });
}
chatForm?.addEventListener('submit',ev=>{
  ev.preventDefault();
  if(!activeChatId){ chatHeading.textContent='Önce bir misafir seçin.'; return; }
  const body=chatMessage.value.trim();
  if(!body){ chatMessage.focus(); return; }
  chatMessage.disabled=true;
  fetch(window.location.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body:new URLSearchParams({ajax:'1',action:'send_message',profile_id:activeChatId,body:body,csrf:csrfToken})})
    .then(r=>r.json())
    .then(data=>{
      if(!data.ok) throw new Error(data.message||'Bir hata oluştu');
      if(chatWindow.querySelector('.smallmuted')) chatWindow.innerHTML='';
      const bubble=document.createElement('div');
      bubble.className='bubble me';
      bubble.textContent=data.message.body;
      chatWindow.appendChild(bubble);
      chatWindow.scrollTop=chatWindow.scrollHeight;
      chatMessage.value='';
    })
    .catch(err=>{ alert(err.message||'Bir hata oluştu'); })
    .finally(()=>{ chatMessage.disabled=false; chatMessage.focus(); });
});
renderDirectory();
<?php endif; ?>

document.querySelectorAll('.quiz-option input').forEach(input => {
  input.addEventListener('change', () => {
    document.querySelectorAll('.quiz-option').forEach(opt => opt.classList.remove('selected'));
    const parent = input.closest('.quiz-option');
    if (parent) parent.classList.add('selected');
  });
});

(function(){
  const dataEl = document.getElementById('wheelData');
  if(!dataEl) return;
  let entries = [];
  try {
    entries = JSON.parse(dataEl.textContent || '[]');
  } catch(e) {
    entries = [];
  }
  if(!Array.isArray(entries) || !entries.length) return;
  const wheel = document.getElementById('wheelVisual');
  const resultEl = document.getElementById('wheelResult');
  const listEl = document.getElementById('wheelEntriesList');
  const spinBtn = document.getElementById('wheelSpin');
  if(listEl){
    listEl.innerHTML='';
    entries.forEach((entry, idx) => {
      const color = entry.color && entry.color !== '' ? entry.color : palette(idx);
      const li = document.createElement('li');
      li.innerHTML = `<span class="wheel-chip" style="background:${color}"></span><span>${escapeHtml(entry.label)}</span>`;
      listEl.appendChild(li);
      entry.__color = color;
    });
  }
  const totalWeight = entries.reduce((acc, entry) => acc + Math.max(1, entry.weight || 1), 0);
  let gradientParts = [];
  let accWeight = 0;
  entries.forEach((entry, idx) => {
    const weight = Math.max(1, entry.weight || 1);
    const start = (accWeight / totalWeight) * 360;
    accWeight += weight;
    const end = (accWeight / totalWeight) * 360;
    const color = entry.__color || palette(idx);
    gradientParts.push(`${color} ${start}deg ${end}deg`);
    entry.__start = start;
    entry.__end = end;
  });
  if(wheel){
    wheel.style.background = `conic-gradient(${gradientParts.join(',')})`;
  }
  let spinning = false;
  spinBtn?.addEventListener('click', () => {
    if(spinning) return;
    spinning = true;
    const pool = [];
    entries.forEach(entry => {
      const w = Math.max(1, entry.weight || 1);
      for(let i=0;i<w;i++) pool.push(entry);
    });
    const chosen = pool[Math.floor(Math.random()*pool.length)];
    let mid = 0;
    if(chosen){
      mid = (chosen.__start + chosen.__end) / 2;
    }
    const rotation = (360 * 5) + (360 - mid);
    if(wheel){
      wheel.style.setProperty('--wheel-rotation', `${rotation}deg`);
    }
    setTimeout(() => {
      if(resultEl && chosen){
        resultEl.textContent = `Kazanan: ${chosen.label}`;
      }
      spinning = false;
    }, 4200);
  });

  function palette(idx){
    const colors = ['#0ea5b5','#6366f1','#f97316','#8b5cf6','#22c55e','#facc15','#ec4899','#14b8a6'];
    return colors[idx % colors.length];
  }

  function escapeHtml(str){
    return String(str).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
  }
})();
</script>
</body></html>
