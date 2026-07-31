<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/koneksi.php';

$quizId = (int) ($argv[1] ?? 0);
if ($quizId < 1) {
    exit(0);
}
$pdo = db();
$statement = $pdo->prepare(
    "SELECT member_id FROM quiz_participants
     WHERE quiz_session_id = ? AND display_name IN ('Browser Alpha','Browser Beta')"
);
$statement->execute([$quizId]);
$memberIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
$pdo->prepare('DELETE FROM quiz_sessions WHERE id = ?')->execute([$quizId]);
if ($memberIds) {
    $pdo->exec('DELETE FROM classroom_members WHERE id IN (' . implode(',', $memberIds) . ')');
}
