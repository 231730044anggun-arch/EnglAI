<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/koneksi.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use EnglAI\Mvp\ContentBankGenerator;
use EnglAI\Mvp\ClassroomService;
use EnglAI\Mvp\QuizService;

$pdo = db();
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$classroom = $pdo->query('SELECT * FROM classrooms ORDER BY id LIMIT 1')->fetch();
if (!$classroom) {
    throw new RuntimeException('Migration belum menghasilkan classroom demo.');
}
$classroomId = (int) $classroom['id'];
$classroomService = new ClassroomService($pdo);
$isolationIds = [];
try {
    $isolationIds[] = $classroomService->create('admin', 'Isolation Classroom A');
    $isolationIds[] = $classroomService->create('admin', 'Isolation Classroom B');
    $a = $classroomService->requireOwned($isolationIds[0], 'admin');
    $b = $classroomService->requireOwned($isolationIds[1], 'admin');
    $check($a['code'] !== $b['code'], 'Dua classroom harus memiliki code berbeda.');
    $insertPlan = $pdo->prepare(
        "INSERT INTO classroom_lesson_plans
         (classroom_id,original_name,stored_name,file_type,extracted_text,version,is_active)
         VALUES(?,?,?,?,?,1,1)"
    );
    $insertPlan->execute([$a['id'], 'a.pdf', 'test-a.pdf', 'pdf', 'Unique lesson plan alpha.']);
    $insertPlan->execute([$b['id'], 'b.pdf', 'test-b.pdf', 'pdf', 'Unique lesson plan beta.']);
    $verifyPlan = $pdo->prepare('SELECT extracted_text FROM classroom_lesson_plans WHERE classroom_id=?');
    $verifyPlan->execute([$a['id']]);
    $aText = (string) $verifyPlan->fetchColumn();
    $verifyPlan->execute([$b['id']]);
    $bText = (string) $verifyPlan->fetchColumn();
    $check($aText !== $bText, 'RPP harus terisolasi per classroom.');
} finally {
    if ($isolationIds) {
        $pdo->exec('DELETE FROM classrooms WHERE id IN (' . implode(',', array_map('intval', $isolationIds)) . ')');
    }
}
$result = (new ContentBankGenerator($pdo))->generate($classroomId);
$counts = $pdo->prepare('SELECT content_type, COUNT(*) total FROM content_questions WHERE classroom_id=? GROUP BY content_type');
$counts->execute([$classroomId]);
$banks = array_column($counts->fetchAll(), 'total', 'content_type');
$check((int) ($banks['self_learning'] ?? 0) >= 20, 'Self Learning bank minimum 20.');
$check((int) ($banks['live_quiz'] ?? 0) >= 20, 'Live Quiz bank minimum 20.');
$overlap = $pdo->prepare(
    "SELECT COUNT(*) FROM content_questions a JOIN content_questions b
     ON a.classroom_id=b.classroom_id AND a.question_hash=b.question_hash AND a.id<>b.id
     WHERE a.classroom_id=? AND a.content_type<>b.content_type"
);
$overlap->execute([$classroomId]);
$check((int) $overlap->fetchColumn() === 0, 'Hash kedua content bank harus terpisah.');

$quizService = new QuizService($pdo);
$quizId = $quizService->create($classroomId, (string) $classroom['teacher_key'], 10, 'medium');
$memberIds = [];
$participantIds = [];
try {
    foreach ([['Zulu', '🦊'], ['Alpha', '🐼']] as $identity) {
        $token = bin2hex(random_bytes(32));
        $pdo->prepare('INSERT INTO classroom_members(classroom_id,session_token,last_seen_at) VALUES(?,?,NOW())')
            ->execute([$classroomId, $token]);
        $memberId = (int) $pdo->lastInsertId();
        $memberIds[] = $memberId;
        $pdo->prepare(
            'INSERT INTO quiz_participants(quiz_session_id,member_id,display_name,avatar,last_seen_at,joined_at)
             VALUES(?,?,?,?,NOW(),NOW())'
        )->execute([$quizId, $memberId, $identity[0], $identity[1]]);
        $participantIds[] = (int) $pdo->lastInsertId();
    }
    $lobby = $quizService->status($quizId);
    $check(count($lobby['participants']) === 2, 'Dua browser/session harus muncul di lobby.');
    $quizService->start($quizId, $classroomId);
    $first = $quizService->status($quizId, $participantIds[0]);
    $second = $quizService->status($quizId, $participantIds[1]);
    $check($first['question']['id'] === $second['question']['id'], 'Peserta menerima snapshot pertanyaan yang sama.');
    $check($first['question']['options'] === $second['question']['options'], 'Urutan opsi Live Quiz sama untuk semua peserta.');
    $answerStatement = $pdo->prepare('SELECT answer FROM quiz_session_questions WHERE id=?');
    $answerStatement->execute([(int) $first['question']['id']]);
    $correctAnswer = (string) $answerStatement->fetchColumn();
    $wrongAnswer = $correctAnswer === 'A' ? 'B' : 'A';
    $correct = $quizService->submit($quizId, $participantIds[0], $correctAnswer);
    $wrong = $quizService->submit($quizId, $participantIds[1], $wrongAnswer);
    $check($correct['correct'] && $correct['score'] >= 1000 && $correct['score'] <= 1500, 'Jawaban benar memakai formula server.');
    $check(!$wrong['correct'] && $wrong['score'] === 0, 'Jawaban salah mendapat 0.');
    try {
        $quizService->submit($quizId, $participantIds[0], $correctAnswer);
        $check(false, 'Double submission harus ditolak.');
    } catch (RuntimeException) {
        $check(true, 'Double submission ditolak.');
    }
    $leaderboard = $quizService->leaderboard($quizId);
    $check($leaderboard[0]['display_name'] === 'Zulu', 'Leaderboard mengutamakan skor sebelum nama.');
    $state = $quizService->status($quizId);
    while ($state['state'] === 'ACTIVE') {
        $questionId = (int) $state['question']['id'];
        $answerStatement->execute([$questionId]);
        $roundAnswer = (string) $answerStatement->fetchColumn();
        $roundWrong = $roundAnswer === 'A' ? 'B' : 'A';
        $quizService->submit($quizId, $participantIds[0], $roundAnswer);
        $quizService->submit($quizId, $participantIds[1], $roundWrong);
        $state = $quizService->status($quizId);
    }
    $check($state['state'] === 'FINISHED', 'Quiz harus mencapai FINISHED setelah pertanyaan terakhir.');
    $check(count($state['leaderboard']) === 2, 'Final leaderboard harus tersimpan untuk semua peserta.');
    $snapshotCount = $pdo->prepare('SELECT COUNT(*) FROM quiz_session_questions WHERE quiz_session_id=?');
    $snapshotCount->execute([$quizId]);
    $check((int) $snapshotCount->fetchColumn() === 10, 'Quiz menyimpan 10 snapshot pertanyaan.');
} finally {
    $pdo->prepare('DELETE FROM quiz_sessions WHERE id=?')->execute([$quizId]);
    if ($memberIds) {
        $pdo->exec('DELETE FROM classroom_members WHERE id IN (' . implode(',', array_map('intval', $memberIds)) . ')');
    }
}

if ($failures) {
    fwrite(STDERR, "MVP integration gagal:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo 'MVP integration OK; generation=' . $result['source'] . PHP_EOL;
