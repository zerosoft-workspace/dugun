<?php
require_once __DIR__.'/_auth.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/event_games.php';
require_once __DIR__.'/../includes/theme.php';

$EVENT_ID = couple_current_event_id();
$ev = couple_event_row_current();
if (!$ev) {
  http_response_code(404);
  exit('Etkinlik bulunamadı');
}

$PRIMARY = $ev['theme_primary'] ?: '#0ea5b5';
$ACCENT  = $ev['theme_accent'] ?: '#e0f7fb';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['do'] ?? '';
  if ($action !== '') {
    csrf_or_die();
  }
  try {
    switch ($action) {
      case 'wheel_save':
        $raw = $_POST['wheel_entries'] ?? [];
        if (!is_array($raw)) {
          $raw = [];
        }
        event_wheel_entries_save($EVENT_ID, $raw);
        flash('ok', 'Çark içerikleri güncellendi.');
        break;
      case 'quiz_create':
        $question = trim($_POST['quiz_question'] ?? '');
        $answers = $_POST['quiz_answers'] ?? [];
        if (!is_array($answers)) {
          $answers = [];
        }
        $correct = (int)($_POST['quiz_correct'] ?? 0);
        event_quiz_question_create($EVENT_ID, $question, $answers, $correct);
        flash('ok', 'Yeni soru kaydedildi.');
        break;
      case 'quiz_status':
        $questionId = (int)($_POST['question_id'] ?? 0);
        $status = $_POST['status'] ?? 'draft';
        if ($questionId > 0) {
          event_quiz_question_set_status($EVENT_ID, $questionId, $status);
          flash('ok', 'Soru durumu güncellendi.');
        }
        break;
      case 'quiz_delete':
        $questionId = (int)($_POST['question_id'] ?? 0);
        if ($questionId > 0) {
          event_quiz_question_delete($EVENT_ID, $questionId);
          flash('ok', 'Soru silindi.');
        }
        break;
    }
  } catch (Throwable $e) {
    flash('err', $e->getMessage());
  }
  redirect($_SERVER['REQUEST_URI']);
}

$wheelEntries = event_wheel_entries_list($EVENT_ID);
$quizQuestions = event_quiz_questions_all($EVENT_ID);
$activeQuestion = event_quiz_active_question($EVENT_ID);
$quizLeaderboard = event_quiz_scoreboard($EVENT_ID, 10);
$now = new DateTimeImmutable();
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($ev['title'])?> — Etkileşim Araçları</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<?=theme_head_assets()?>
<style>
:root{
  --ink:#0f172a;
  --muted:#64748b;
  --zs:<?=h($PRIMARY)?>;
  --zs-soft:<?=h($ACCENT)?>;
  --surface:#ffffff;
}
body{font-family:'Inter','Segoe UI','Helvetica Neue',sans-serif;background:linear-gradient(180deg,#f8fafc 0%,#fff 120%);color:var(--ink);}
.navbar.portal-navbar{background:rgba(255,255,255,.85);backdrop-filter:blur(16px);border-bottom:1px solid rgba(148,163,184,.2);}
.portal-navbar .navbar-brand{font-weight:700;letter-spacing:-.01em;color:var(--ink);}
.portal-navbar .btn{border-radius:999px;font-weight:600;padding:.35rem 1rem;}
.portal-navbar .btn-active{background:linear-gradient(135deg,var(--zs),#0b8b98);color:#fff;border:none;box-shadow:0 12px 30px -16px rgba(14,165,181,.35);}
.portal-navbar .btn-active:hover{color:#fff;filter:brightness(.97);}
.portal-hero{padding:42px 0 28px;}
.portal-hero-card{border-radius:28px;background:linear-gradient(140deg,var(--zs-soft),rgba(255,255,255,.95));padding:32px;box-shadow:0 45px 80px -60px rgba(14,165,181,.55);position:relative;overflow:hidden;}
.portal-hero-card::after{content:'';position:absolute;width:220px;height:220px;right:-60px;top:-60px;background:radial-gradient(circle at center,rgba(14,165,181,.3),rgba(14,165,181,0));}
.portal-hero-card h1{font-weight:800;margin-bottom:12px;}
.portal-hero-card p{color:var(--muted);max-width:520px;}
.stat-grid{display:grid;gap:18px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));}
.stat-card{border-radius:18px;background:#fff;border:1px solid rgba(148,163,184,.18);padding:18px;box-shadow:0 30px 80px -55px rgba(15,23,42,.35);}
.stat-card h6{font-size:.88rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);}
.stat-card .value{font-size:1.75rem;font-weight:800;color:var(--ink);}
.card-lite{border-radius:22px;border:1px solid rgba(148,163,184,.16);background:#fff;box-shadow:0 30px 90px -55px rgba(15,23,42,.35);}
.card-lite h5{font-weight:700;}
.card-lite .form-control{border-radius:14px;border:1px solid rgba(148,163,184,.28);}
.card-lite .form-control:focus{border-color:var(--zs);box-shadow:0 0 0 .2rem rgba(14,165,181,.15);}
.wheel-row{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:12px;align-items:center;}
.wheel-row .form-check{margin-bottom:0;}
.wheel-row .badge{font-weight:600;}
#wheelRows{display:flex;flex-direction:column;gap:12px;}
.quiz-builder .answer-row{display:grid;grid-template-columns:auto 1fr auto;gap:12px;align-items:center;}
.quiz-builder .answer-row .input-group-text{background:rgba(14,165,181,.12);border:none;border-radius:12px;}
.quiz-builder .answer-row .form-control{border-radius:12px;}
.answer-remove{border:none;background:none;color:var(--muted);}
.answer-remove:hover{color:#ef4444;}
.question-card{border:1px solid rgba(148,163,184,.18);border-radius:18px;padding:18px;background:#fff;box-shadow:0 18px 40px -38px rgba(15,23,42,.45);}
.question-card + .question-card{margin-top:16px;}
.question-card .badge{border-radius:999px;padding:.4rem .9rem;font-weight:600;}
.answer-chip{display:inline-flex;align-items:center;gap:.45rem;border-radius:999px;padding:.35rem .85rem;background:rgba(14,165,181,.08);font-weight:600;margin-right:.35rem;margin-bottom:.35rem;}
.answer-chip.correct{background:rgba(22,163,74,.16);color:#15803d;}
.scoreboard-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.75rem;}
.scoreboard-item{border-radius:16px;border:1px solid rgba(148,163,184,.2);padding:.85rem 1.1rem;display:flex;justify-content:space-between;align-items:center;background:#fff;}
.scoreboard-item strong{font-size:1.05rem;}
.scoreboard-meta{display:flex;gap:.65rem;color:var(--muted);font-size:.9rem;}
.badge-draft{background:rgba(148,163,184,.22);color:#475569;}
.badge-active{background:rgba(59,130,246,.18);color:#2563eb;}
.badge-closed{background:rgba(22,163,74,.18);color:#15803d;}
.add-row-btn{border-radius:12px;border:1px dashed rgba(148,163,184,.35);background:rgba(240,249,255,.6);color:var(--zs);padding:.6rem 1rem;font-weight:600;}
.add-row-btn:hover{background:rgba(14,165,181,.1);}
</style>
</head>
<body>
<nav class="navbar portal-navbar">
  <div class="container py-3">
    <a class="navbar-brand fw-semibold" href="<?=h(BASE_URL)?>"><?=h(APP_NAME)?></a>
    <div class="d-flex align-items-center gap-2 gap-md-3">
      <a class="btn btn-sm btn-active" href="engage.php"><i class="bi bi-stars me-1"></i>Etkileşim Araçları</a>
      <a class="btn btn-sm btn-outline-secondary" href="index.php"><i class="bi bi-speedometer2 me-1"></i>Panel</a>
      <a class="btn btn-sm btn-outline-secondary" href="list.php"><i class="bi bi-images me-1"></i>Yüklemeler</a>
      <a class="btn btn-sm btn-outline-secondary" href="logout.php"><i class="bi bi-box-arrow-right me-1"></i>Çıkış</a>
    </div>
  </div>
</nav>

<section class="portal-hero">
  <div class="container">
    <div class="portal-hero-card">
      <div class="row g-4 align-items-center">
        <div class="col-lg-7">
          <span class="badge bg-light text-dark rounded-pill px-3 py-2 fw-semibold">Etkileşim Merkezi</span>
          <h1><?=h($ev['title'])?></h1>
          <p>Çarkınızı yapılandırın, canlı quiz sorularınızı hazırlayın ve davetlilerinizin puan tablosunu tek ekrandan takip edin.</p>
        </div>
        <div class="col-lg-5">
          <div class="stat-grid">
            <div class="stat-card">
              <h6>Aktif Çark Ögesi</h6>
              <div class="value"><?=count(array_filter($wheelEntries, fn($e) => !empty($e['is_active'])))?></div>
              <div class="text-muted small">Toplam öge: <?=count($wheelEntries)?></div>
            </div>
            <div class="stat-card">
              <h6>Toplam Soru</h6>
              <div class="value"><?=count($quizQuestions)?></div>
              <div class="text-muted small"><?= $activeQuestion ? '1 aktif soru var' : 'Şu an aktif soru yok' ?></div>
            </div>
            <div class="stat-card">
              <h6>Öne Çıkan Misafir</h6>
              <div class="value"><?= $quizLeaderboard ? h($quizLeaderboard[0]['name']) : '—' ?></div>
              <div class="text-muted small"><?= $quizLeaderboard ? $quizLeaderboard[0]['points'].' puan' : 'Henüz yanıt yok' ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<main class="container pb-5">
  <?php flash_box(); ?>
  <div class="row g-4 align-items-stretch">
    <div class="col-xl-7">
      <div class="card-lite p-4 mb-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
          <div>
            <h5 class="mb-1">Çark İçerikleri</h5>
            <div class="text-muted small">Davetlilerin butona bastığında göreceği ödülleri veya mesajları düzenleyin.</div>
          </div>
          <span class="badge bg-light text-dark">Gösterim butonu misafir galerisinde yer alır.</span>
        </div>
        <form method="post" class="vstack gap-3" id="wheelForm">
          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="do" value="wheel_save">
          <div id="wheelRows">
            <?php $displayEntries = $wheelEntries ?: [[ 'label'=>'', 'weight'=>1, 'color'=>null, 'is_active'=>1 ]];
            foreach ($displayEntries as $index => $entry): ?>
            <div class="wheel-row" data-index="<?=$index?>">
              <input class="form-control" name="wheel_entries[<?=$index?>][label]" placeholder="Öğe adı" value="<?=h($entry['label'] ?? '')?>" required>
              <input class="form-control" type="number" min="1" name="wheel_entries[<?=$index?>][weight]" value="<?=h($entry['weight'] ?? 1)?>">
              <input class="form-control form-control-color" type="color" name="wheel_entries[<?=$index?>][color]" value="<?=h($entry['color'] ?? $PRIMARY)?>">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="wheel_entries[<?=$index?>][is_active]" value="1" <?=!empty($entry['is_active'])?'checked':''?>>
                <label class="form-check-label">Aktif</label>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <div>
            <button class="add-row-btn w-100" type="button" id="addWheelRow"><i class="bi bi-plus-circle me-1"></i>Satır ekle</button>
          </div>
          <div class="d-flex justify-content-end">
            <button class="btn btn-zs" type="submit">Çarkı Kaydet</button>
          </div>
        </form>
      </div>

      <div class="card-lite p-4 quiz-builder">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
          <div>
            <h5 class="mb-1">Yeni Quiz Sorusu</h5>
            <div class="text-muted small">Sorularınızı hazırlayın, doğru cevabı seçin ve yayına alın.</div>
          </div>
          <span class="badge bg-light text-dark">Sorular misafir panelinde tek tek gösterilir.</span>
        </div>
        <form method="post" class="vstack gap-3" id="quizForm">
          <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="do" value="quiz_create">
          <div>
            <label class="form-label fw-semibold">Soru</label>
            <textarea class="form-control" name="quiz_question" rows="2" required placeholder="Örnek: Düğünümüzde çalmasını istediğiniz ilk şarkı hangisi?"></textarea>
          </div>
          <div>
            <label class="form-label fw-semibold">Cevaplar</label>
            <div id="quizAnswers" class="vstack gap-2">
              <?php for($i=0;$i<3;$i++): ?>
              <div class="answer-row" data-index="<?=$i?>">
                <div class="input-group-text">
                  <input class="form-check-input" type="radio" name="quiz_correct" value="<?=$i?>" <?=$i===0?'checked':''?>>
                </div>
                <input class="form-control" name="quiz_answers[<?=$i?>]" placeholder="Cevap" required>
                <button class="answer-remove" type="button" title="Sil"><i class="bi bi-x-lg"></i></button>
              </div>
              <?php endfor; ?>
            </div>
            <button class="add-row-btn mt-3" type="button" id="addAnswerRow"><i class="bi bi-plus-lg me-1"></i>Yeni cevap ekle</button>
          </div>
          <div class="d-flex justify-content-end">
            <button class="btn btn-zs" type="submit">Soruyu Kaydet</button>
          </div>
        </form>
      </div>
    </div>
    <div class="col-xl-5">
      <div class="card-lite p-4 h-100">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h5 class="mb-0"><i class="bi bi-trophy-fill text-warning me-2"></i>Quiz Sıralaması</h5>
          <?php if($quizLeaderboard): ?>
            <span class="badge bg-light text-dark">Toplam katılım: <?=count($quizLeaderboard)?></span>
          <?php endif; ?>
        </div>
        <?php if($quizLeaderboard): ?>
          <ul class="scoreboard-list">
            <?php foreach($quizLeaderboard as $row): ?>
            <li class="scoreboard-item">
              <div>
                <strong>#<?=$row['rank']?> <?=h($row['name'])?></strong>
                <div class="scoreboard-meta"><span><i class="bi bi-lightning-charge-fill text-warning"></i><?=$row['points']?> puan</span><span><i class="bi bi-check-circle-fill text-success"></i><?=$row['correct']?> doğru</span></div>
              </div>
            </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="text-muted">Henüz puanlanan bir cevap yok. İlk soruyu yayınlayarak liderlik tablosunu başlatın.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card-lite p-4 mt-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
      <div>
        <h5 class="mb-1">Sorular ve Durumları</h5>
        <div class="text-muted small">Aktif sorular misafirlere anında gösterilir. Dilediğinizde durumu değiştirebilirsiniz.</div>
      </div>
      <?php if($activeQuestion): ?>
        <span class="badge badge-active"><i class="bi bi-broadcast-pin me-1"></i>Canlı soru yayında</span>
      <?php endif; ?>
    </div>
    <?php if(!$quizQuestions): ?>
      <div class="text-muted">Henüz kayıtlı soru yok.</div>
    <?php else: ?>
      <?php foreach($quizQuestions as $question):
        $status = $question['status'] ?? 'draft';
        $badgeClass = $status==='active' ? 'badge-active' : ($status==='closed' ? 'badge-closed' : 'badge-draft');
      ?>
        <div class="question-card">
          <div class="d-flex flex-column flex-md-row justify-content-between gap-3">
            <div>
              <div class="d-flex align-items-center gap-2 mb-2">
                <span class="badge <?=$badgeClass?> text-uppercase"><?=$status==='active'?'Aktif':($status==='closed'?'Tamamlandı':'Taslak')?></span>
                <small class="text-muted">Oluşturma: <?=h(date('d.m.Y H:i', strtotime($question['created_at'] ?? 'now')))?></small>
              </div>
              <h6 class="fw-semibold mb-2"><?=h($question['question'])?></h6>
              <div>
                <?php foreach($question['answers'] as $ans): ?>
                  <span class="answer-chip <?=$ans['is_correct'] ? 'correct' : ''?>"><i class="bi <?=$ans['is_correct']?'bi-check-lg':'bi-dot'?>"></i><?=h($ans['text'])?></span>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <?php if($status!=='active'): ?>
              <form method="post">
                <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                <input type="hidden" name="do" value="quiz_status">
                <input type="hidden" name="question_id" value="<?=$question['id']?>">
                <input type="hidden" name="status" value="active">
                <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-broadcast me-1"></i>Yayına al</button>
              </form>
              <?php endif; ?>
              <?php if($status==='active'): ?>
              <form method="post">
                <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                <input type="hidden" name="do" value="quiz_status">
                <input type="hidden" name="question_id" value="<?=$question['id']?>">
                <input type="hidden" name="status" value="closed">
                <button class="btn btn-sm btn-outline-success" type="submit"><i class="bi bi-flag me-1"></i>Soruyu kapat</button>
              </form>
              <?php endif; ?>
              <?php if($status!=='active'): ?>
              <form method="post" onsubmit="return confirm('Soruyu silmek istediğinize emin misiniz?');">
                <input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">
                <input type="hidden" name="do" value="quiz_delete">
                <input type="hidden" name="question_id" value="<?=$question['id']?>">
                <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash me-1"></i>Sil</button>
              </form>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const wheelRows = document.getElementById('wheelRows');
const addWheelRowBtn = document.getElementById('addWheelRow');
let wheelIndex = wheelRows ? wheelRows.querySelectorAll('.wheel-row').length : 0;
addWheelRowBtn?.addEventListener('click', () => {
  const idx = wheelIndex++;
  const row = document.createElement('div');
  row.className = 'wheel-row';
  row.dataset.index = idx;
  row.innerHTML = `
    <input class="form-control" name="wheel_entries[${idx}][label]" placeholder="Öğe adı" required>
    <input class="form-control" type="number" min="1" name="wheel_entries[${idx}][weight]" value="1">
    <input class="form-control form-control-color" type="color" name="wheel_entries[${idx}][color]" value="<?=h($PRIMARY)?>">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="wheel_entries[${idx}][is_active]" value="1" checked>
      <label class="form-check-label">Aktif</label>
    </div>`;
  wheelRows.appendChild(row);
});

const quizAnswers = document.getElementById('quizAnswers');
const addAnswerRow = document.getElementById('addAnswerRow');
let answerIndex = quizAnswers ? quizAnswers.querySelectorAll('.answer-row').length : 0;
addAnswerRow?.addEventListener('click', () => {
  if(answerIndex >= 6) return;
  const idx = answerIndex++;
  const row = document.createElement('div');
  row.className = 'answer-row';
  row.dataset.index = idx;
  row.innerHTML = `
    <div class="input-group-text">
      <input class="form-check-input" type="radio" name="quiz_correct" value="${idx}">
    </div>
    <input class="form-control" name="quiz_answers[${idx}]" placeholder="Cevap" required>
    <button class="answer-remove" type="button" title="Sil"><i class="bi bi-x-lg"></i></button>`;
  quizAnswers.appendChild(row);
  bindAnswerRow(row);
});

function bindAnswerRow(row){
  const removeBtn = row.querySelector('.answer-remove');
  removeBtn?.addEventListener('click', () => {
    if(quizAnswers.children.length <= 2) return;
    const radio = row.querySelector('input[type="radio"]');
    const wasChecked = radio && radio.checked;
    row.remove();
    if(wasChecked){
      const firstRadio = quizAnswers.querySelector('input[type="radio"]');
      if(firstRadio) firstRadio.checked = true;
    }
  });
}

quizAnswers?.querySelectorAll('.answer-row').forEach(row => bindAnswerRow(row));
</script>
</body>
</html>
