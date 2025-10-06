<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';

function event_wheel_entries_list(int $eventId, bool $onlyActive = false): array {
  install_schema();
  $sql = "SELECT id, label, weight, color, is_active FROM event_wheel_entries WHERE event_id=?";
  if ($onlyActive) {
    $sql .= " AND is_active=1";
  }
  $sql .= " ORDER BY created_at ASC, id ASC";
  $st = pdo()->prepare($sql);
  $st->execute([$eventId]);
  $rows = $st->fetchAll();
  $out = [];
  foreach ($rows as $row) {
    $out[] = [
      'id' => (int)$row['id'],
      'label' => $row['label'],
      'weight' => max(1, (int)$row['weight']),
      'color' => $row['color'] ?: null,
      'is_active' => (int)$row['is_active'] === 1,
    ];
  }
  return $out;
}

function event_wheel_entries_save(int $eventId, array $entries): array {
  install_schema();
  $clean = [];
  foreach ($entries as $entry) {
    if (!is_array($entry)) {
      continue;
    }
    $label = trim((string)($entry['label'] ?? ''));
    if ($label === '') {
      continue;
    }
    $weight = (int)($entry['weight'] ?? 1);
    if ($weight <= 0) {
      $weight = 1;
    }
    $color = trim((string)($entry['color'] ?? ''));
    $clean[] = [
      'label' => mb_substr($label, 0, 190, 'UTF-8'),
      'weight' => $weight,
      'color' => $color === '' ? null : mb_substr($color, 0, 16, 'UTF-8'),
      'is_active' => !empty($entry['is_active']) ? 1 : 0,
    ];
  }

  $pdo = pdo();
  $pdo->beginTransaction();
  try {
    $pdo->prepare('DELETE FROM event_wheel_entries WHERE event_id=?')->execute([$eventId]);
    if ($clean) {
      $ins = $pdo->prepare('INSERT INTO event_wheel_entries (event_id, label, weight, color, is_active, created_at) VALUES (?,?,?,?,?,?)');
      $now = now();
      foreach ($clean as $row) {
        $ins->execute([$eventId, $row['label'], $row['weight'], $row['color'], $row['is_active'], $now]);
      }
    }
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
  return $clean;
}

function event_quiz_questions_all(int $eventId): array {
  install_schema();
  $st = pdo()->prepare("SELECT id, question, status, reveal_at, created_at, updated_at FROM event_quiz_questions WHERE event_id=? ORDER BY created_at DESC, id DESC");
  $st->execute([$eventId]);
  $rows = $st->fetchAll();
  if (!$rows) {
    return [];
  }
  $ids = array_map(fn($r) => (int)$r['id'], $rows);
  $ans = event_quiz_answers_group($ids);
  $out = [];
  foreach ($rows as $row) {
    $id = (int)$row['id'];
    $out[] = [
      'id' => $id,
      'question' => $row['question'],
      'status' => $row['status'],
      'reveal_at' => $row['reveal_at'],
      'answers' => $ans[$id] ?? [],
      'created_at' => $row['created_at'],
      'updated_at' => $row['updated_at'],
    ];
  }
  return $out;
}

function event_quiz_answers_group(array $questionIds): array {
  if (!$questionIds) {
    return [];
  }
  $in = implode(',', array_fill(0, count($questionIds), '?'));
  $st = pdo()->prepare("SELECT id, question_id, answer_text, is_correct, sort_order FROM event_quiz_answers WHERE question_id IN ($in) ORDER BY sort_order ASC, id ASC");
  $st->execute($questionIds);
  $grouped = [];
  while ($row = $st->fetch()) {
    $qid = (int)$row['question_id'];
    if (!isset($grouped[$qid])) {
      $grouped[$qid] = [];
    }
    $grouped[$qid][] = [
      'id' => (int)$row['id'],
      'text' => $row['answer_text'],
      'is_correct' => (int)$row['is_correct'] === 1,
    ];
  }
  return $grouped;
}

function event_quiz_question_create(int $eventId, string $question, array $answers, int $correctIndex = 0): int {
  install_schema();
  $question = trim($question);
  if ($question === '') {
    throw new InvalidArgumentException('Soru metni boş olamaz');
  }
  $cleanAnswers = [];
  foreach ($answers as $idx => $answer) {
    $text = trim((string)$answer);
    if ($text === '') {
      continue;
    }
    $cleanAnswers[] = [
      'text' => mb_substr($text, 0, 255, 'UTF-8'),
      'is_correct' => ($idx === $correctIndex) ? 1 : 0,
    ];
  }
  if (count($cleanAnswers) < 2) {
    throw new InvalidArgumentException('En az iki cevap eklenmelidir');
  }
  $hasCorrect = false;
  foreach ($cleanAnswers as $row) {
    if ($row['is_correct'] === 1) {
      $hasCorrect = true;
      break;
    }
  }
  if (!$hasCorrect) {
    $cleanAnswers[0]['is_correct'] = 1;
  }
  $pdo = pdo();
  $pdo->beginTransaction();
  try {
    $now = now();
    $pdo->prepare('INSERT INTO event_quiz_questions (event_id, question, status, created_at, updated_at) VALUES (?,?,?,?,?)')
        ->execute([$eventId, $question, 'draft', $now, $now]);
    $questionId = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO event_quiz_answers (question_id, answer_text, is_correct, sort_order, created_at) VALUES (?,?,?,?,?)');
    $order = 0;
    foreach ($cleanAnswers as $row) {
      $ins->execute([$questionId, $row['text'], $row['is_correct'], $order++, $now]);
    }
    $pdo->commit();
    return $questionId;
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}

function event_quiz_question_set_status(int $eventId, int $questionId, string $status): bool {
  $status = in_array($status, ['draft','active','closed'], true) ? $status : 'draft';
  $pdo = pdo();
  $pdo->beginTransaction();
  try {
    if ($status === 'active') {
      $pdo->prepare('UPDATE event_quiz_questions SET status="closed" WHERE event_id=? AND status="active"')
          ->execute([$eventId]);
    }
    $now = now();
    $pdo->prepare('UPDATE event_quiz_questions SET status=?, reveal_at = CASE WHEN ?="closed" THEN COALESCE(reveal_at, ?) ELSE reveal_at END, updated_at=? WHERE id=? AND event_id=?')
        ->execute([$status, $status, $now, $now, $questionId, $eventId]);
    $pdo->commit();
    return true;
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}

function event_quiz_active_question(int $eventId): ?array {
  install_schema();
  $st = pdo()->prepare('SELECT id, question, status, reveal_at FROM event_quiz_questions WHERE event_id=? AND status="active" ORDER BY updated_at DESC LIMIT 1');
  $st->execute([$eventId]);
  $row = $st->fetch();
  if (!$row) {
    return null;
  }
  $answers = event_quiz_answers_group([(int)$row['id']]);
  return [
    'id' => (int)$row['id'],
    'question' => $row['question'],
    'status' => $row['status'],
    'reveal_at' => $row['reveal_at'],
    'answers' => $answers[(int)$row['id']] ?? [],
  ];
}

function event_quiz_attempt_submit(int $eventId, int $questionId, int $answerId, ?array $profile): array {
  install_schema();
  if (!$profile || empty($profile['id'])) {
    throw new RuntimeException('Katılım için misafir girişi gereklidir.');
  }
  $pdo = pdo();
  $st = $pdo->prepare('SELECT id, status FROM event_quiz_questions WHERE id=? AND event_id=? LIMIT 1');
  $st->execute([$questionId, $eventId]);
  $question = $st->fetch();
  if (!$question || $question['status'] !== 'active') {
    throw new RuntimeException('Bu soru aktif değil.');
  }
  $ansSt = $pdo->prepare('SELECT id, is_correct FROM event_quiz_answers WHERE id=? AND question_id=? LIMIT 1');
  $ansSt->execute([$answerId, $questionId]);
  $answer = $ansSt->fetch();
  if (!$answer) {
    throw new RuntimeException('Geçersiz cevap seçimi.');
  }
  $isCorrect = (int)$answer['is_correct'] === 1;
  $points = $isCorrect ? 100 : 0;
  $now = now();
  $attempt = $pdo->prepare('INSERT INTO event_quiz_attempts (question_id, answer_id, profile_id, guest_name, is_correct, points, answered_at) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE answer_id=VALUES(answer_id), is_correct=VALUES(is_correct), points=VALUES(points), answered_at=VALUES(answered_at)');
  $attempt->execute([$questionId, $answerId, (int)$profile['id'], $profile['display_name'] ?? ($profile['guest_name'] ?? $profile['email'] ?? null), $isCorrect ? 1 : 0, $points, $now]);
  return [
    'is_correct' => $isCorrect,
    'points' => $points,
  ];
}

function event_quiz_scoreboard(int $eventId, int $limit = 10): array {
  install_schema();
  $sql = "SELECT gp.id AS profile_id, COALESCE(gp.display_name, gp.guest_name, gp.email, CONCAT('Misafir #', gp.id)) AS name,
                 SUM(a.points) AS total_points,
                 SUM(CASE WHEN a.is_correct=1 THEN 1 ELSE 0 END) AS correct_count,
                 MAX(a.answered_at) AS last_answered
          FROM event_quiz_attempts a
          INNER JOIN guest_profiles gp ON gp.id = a.profile_id
          INNER JOIN event_quiz_questions q ON q.id = a.question_id
          WHERE gp.event_id = :event_id AND q.event_id = :event_id
          GROUP BY gp.id
          HAVING total_points > 0
          ORDER BY total_points DESC, correct_count DESC, last_answered ASC
          LIMIT :limit";
  $pdo = pdo();
  $st = $pdo->prepare($sql);
  $st->bindValue(':event_id', $eventId, PDO::PARAM_INT);
  $st->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
  $st->execute();
  $rows = $st->fetchAll();
  $rank = 1;
  $out = [];
  foreach ($rows as $row) {
    $out[] = [
      'rank' => $rank++,
      'profile_id' => (int)$row['profile_id'],
      'name' => $row['name'],
      'points' => (int)$row['total_points'],
      'correct' => (int)$row['correct_count'],
    ];
  }
  return $out;
}

function event_quiz_question_delete(int $eventId, int $questionId): void {
  $pdo = pdo();
  $pdo->prepare('DELETE FROM event_quiz_questions WHERE id=? AND event_id=?')->execute([$questionId, $eventId]);
}

function event_quiz_attempt_for_profile(int $questionId, int $profileId): ?array {
  if ($questionId <= 0 || $profileId <= 0) {
    return null;
  }
  $st = pdo()->prepare('SELECT answer_id, is_correct, points, answered_at FROM event_quiz_attempts WHERE question_id=? AND profile_id=? LIMIT 1');
  $st->execute([$questionId, $profileId]);
  $row = $st->fetch();
  if (!$row) {
    return null;
  }
  return [
    'answer_id' => $row['answer_id'] !== null ? (int)$row['answer_id'] : null,
    'is_correct' => (int)$row['is_correct'] === 1,
    'points' => (int)$row['points'],
    'answered_at' => $row['answered_at'],
  ];
}

function event_quiz_profile_stats(int $eventId, int $profileId): ?array {
  if ($profileId <= 0) {
    return null;
  }
  $sql = "SELECT SUM(a.points) AS total_points,
                 SUM(CASE WHEN a.is_correct=1 THEN 1 ELSE 0 END) AS correct_count,
                 COUNT(*) AS attempt_count
          FROM event_quiz_attempts a
          INNER JOIN event_quiz_questions q ON q.id = a.question_id
          WHERE q.event_id = ? AND a.profile_id = ?";
  $st = pdo()->prepare($sql);
  $st->execute([$eventId, $profileId]);
  $row = $st->fetch();
  if (!$row) {
    return null;
  }
  return [
    'points' => (int)($row['total_points'] ?? 0),
    'correct' => (int)($row['correct_count'] ?? 0),
    'attempts' => (int)($row['attempt_count'] ?? 0),
  ];
}

function event_wheel_random_entry(array $entries): ?array {
  if (!$entries) {
    return null;
  }
  $pool = [];
  foreach ($entries as $entry) {
    $weight = max(1, (int)($entry['weight'] ?? 1));
    for ($i = 0; $i < $weight; $i++) {
      $pool[] = $entry;
    }
  }
  if (!$pool) {
    return $entries[array_rand($entries)];
  }
  return $pool[array_rand($pool)];
}
