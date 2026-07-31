<?php
declare(strict_types=1);

namespace EnglAI\Mvp;

final class QuizService
{
    public const QUESTION_SECONDS = 20;

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function create(int $classroomId, string $teacherKey, int $count, string $difficulty): int
    {
        $count = in_array($count, [10, 20], true) ? $count : 10;
        $difficulty = in_array($difficulty, ['easy', 'medium', 'hard'], true) ? $difficulty : 'medium';
        $statement = $this->pdo->prepare(
            "SELECT * FROM content_questions
             WHERE classroom_id = ? AND content_type = 'live_quiz' AND difficulty = ?
             ORDER BY RAND() LIMIT {$count}"
        );
        $statement->execute([$classroomId, $difficulty]);
        $questions = $statement->fetchAll();
        if (count($questions) < $count) {
            $statement = $this->pdo->prepare(
                "SELECT * FROM content_questions
                 WHERE classroom_id = ? AND content_type = 'live_quiz'
                 ORDER BY RAND() LIMIT {$count}"
            );
            $statement->execute([$classroomId]);
            $questions = $statement->fetchAll();
        }
        if (count($questions) < $count) {
            throw new \RuntimeException("Live Quiz Bank membutuhkan minimal {$count} pertanyaan.");
        }

        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                "INSERT INTO quiz_sessions (classroom_id, state, question_count, difficulty, created_by)
                 VALUES (?, 'LOBBY', ?, ?, ?)"
            );
            $insert->execute([$classroomId, $count, $difficulty, $teacherKey]);
            $quizId = (int) $this->pdo->lastInsertId();
            $snapshot = $this->pdo->prepare(
                'INSERT INTO quiz_session_questions
                    (quiz_session_id, position, source_question_id, question, options_json, answer, explanation, difficulty)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($questions as $position => $question) {
                $snapshot->execute([
                    $quizId,
                    $position,
                    $question['id'],
                    $question['question'],
                    $question['options_json'],
                    $question['answer'],
                    $question['explanation'],
                    $question['difficulty'],
                ]);
            }
            $this->pdo->commit();
            return $quizId;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function start(int $quizId, int $classroomId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE quiz_sessions
             SET state = 'ACTIVE', current_index = 0, question_started_at = NOW(3),
                 question_deadline_at = DATE_ADD(NOW(3), INTERVAL " . self::QUESTION_SECONDS . " SECOND)
             WHERE id = ? AND classroom_id = ? AND state = 'LOBBY'"
        );
        $statement->execute([$quizId, $classroomId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Quiz hanya dapat dimulai dari lobby.');
        }
    }

    public function close(int $quizId, int $classroomId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE quiz_sessions SET state = 'CLOSED'
             WHERE id = ? AND classroom_id = ? AND state IN ('LOBBY','FINISHED')"
        );
        $statement->execute([$quizId, $classroomId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Quiz hanya dapat ditutup dari lobby atau setelah selesai.');
        }
    }

    public function advanceIfNeeded(int $quizId): void
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT *, (question_deadline_at IS NOT NULL AND question_deadline_at <= NOW(3)) AS is_expired
                 FROM quiz_sessions WHERE id = ? FOR UPDATE'
            );
            $statement->execute([$quizId]);
            $quiz = $statement->fetch();
            if (!$quiz || $quiz['state'] !== 'ACTIVE') {
                $this->pdo->commit();
                return;
            }
            $participantCount = (int) $this->scalar(
                'SELECT COUNT(*) FROM quiz_participants WHERE quiz_session_id = ?',
                [$quizId]
            );
            $answerCount = (int) $this->scalar(
                'SELECT COUNT(*) FROM quiz_answers a
                 JOIN quiz_session_questions q ON q.id = a.session_question_id
                 WHERE a.quiz_session_id = ? AND q.position = ?',
                [$quizId, (int) $quiz['current_index']]
            );
            $expired = (bool) $quiz['is_expired'];
            if (!$expired && ($participantCount < 1 || $answerCount < $participantCount)) {
                $this->pdo->commit();
                return;
            }
            $next = (int) $quiz['current_index'] + 1;
            if ($next >= (int) $quiz['question_count']) {
                $update = $this->pdo->prepare(
                    "UPDATE quiz_sessions SET state = 'FINISHED', question_started_at = NULL,
                     question_deadline_at = NULL, finished_at = NOW() WHERE id = ?"
                );
                $update->execute([$quizId]);
            } else {
                $update = $this->pdo->prepare(
                    "UPDATE quiz_sessions SET current_index = ?, question_started_at = NOW(3),
                     question_deadline_at = DATE_ADD(NOW(3), INTERVAL " . self::QUESTION_SECONDS . " SECOND)
                     WHERE id = ?"
                );
                $update->execute([$next, $quizId]);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    public function status(int $quizId, ?int $participantId = null): array
    {
        $this->advanceIfNeeded($quizId);
        $statement = $this->pdo->prepare('SELECT * FROM quiz_sessions WHERE id = ?');
        $statement->execute([$quizId]);
        $quiz = $statement->fetch();
        if (!$quiz) {
            throw new \RuntimeException('Quiz tidak ditemukan.');
        }
        $question = null;
        if ($quiz['state'] === 'ACTIVE') {
            $statement = $this->pdo->prepare(
                'SELECT id, position, question, options_json, difficulty
                 FROM quiz_session_questions WHERE quiz_session_id = ? AND position = ?'
            );
            $statement->execute([$quizId, (int) $quiz['current_index']]);
            $question = $statement->fetch();
            if ($question) {
                $question['options'] = json_decode((string) $question['options_json'], true);
                unset($question['options_json']);
                if ($participantId !== null) {
                    $answered = $this->pdo->prepare(
                        'SELECT selected_answer, is_correct, score FROM quiz_answers
                         WHERE participant_id = ? AND session_question_id = ?'
                    );
                    $answered->execute([$participantId, (int) $question['id']]);
                    $question['submitted'] = $answered->fetch() ?: null;
                }
            }
        }
        $submittedCount = 0;
        if ($question) {
            $submittedCount = (int) $this->scalar(
                'SELECT COUNT(*) FROM quiz_answers WHERE quiz_session_id = ? AND session_question_id = ?',
                [$quizId, (int) $question['id']]
            );
        }
        $serverEpochMs = (int) $this->scalar(
            'SELECT FLOOR(UNIX_TIMESTAMP(NOW(3)) * 1000)',
            []
        );
        $deadlineEpochMs = $quiz['question_deadline_at'] === null ? null : (int) $this->scalar(
            'SELECT FLOOR(UNIX_TIMESTAMP(question_deadline_at) * 1000) FROM quiz_sessions WHERE id = ?',
            [$quizId]
        );
        return [
            'id' => (int) $quiz['id'],
            'state' => $quiz['state'],
            'current_index' => (int) $quiz['current_index'],
            'question_count' => (int) $quiz['question_count'],
            'deadline' => $quiz['question_deadline_at'],
            'deadline_epoch_ms' => $deadlineEpochMs,
            'server_epoch_ms' => $serverEpochMs,
            'submitted_count' => $submittedCount,
            'question' => $question,
            'participants' => $this->participants($quizId),
            'leaderboard' => $this->leaderboard($quizId),
        ];
    }

    /** @return array{score:int,correct:bool,response_ms:int} */
    public function submit(int $quizId, int $participantId, string $selected): array
    {
        $selected = strtoupper(trim($selected));
        if (!in_array($selected, ['A', 'B', 'C', 'D'], true)) {
            throw new \InvalidArgumentException('Jawaban tidak valid.');
        }
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('SELECT * FROM quiz_sessions WHERE id = ? FOR UPDATE');
            $statement->execute([$quizId]);
            $quiz = $statement->fetch();
            if (!$quiz || $quiz['state'] !== 'ACTIVE') {
                throw new \RuntimeException('Quiz tidak sedang aktif.');
            }
            $timingStatement = $this->pdo->prepare(
                'SELECT question_deadline_at >= NOW(3) AS within_deadline,
                        GREATEST(0, LEAST(?, FLOOR(TIMESTAMPDIFF(MICROSECOND, question_started_at, NOW(3)) / 1000))) AS response_ms
                 FROM quiz_sessions WHERE id = ?'
            );
            $timingStatement->execute([self::QUESTION_SECONDS * 1000, $quizId]);
            $timing = $timingStatement->fetch();
            if (!$timing || !(bool) $timing['within_deadline']) {
                throw new \RuntimeException('Waktu menjawab sudah habis.');
            }
            $statement = $this->pdo->prepare(
                'SELECT * FROM quiz_participants WHERE id = ? AND quiz_session_id = ? FOR UPDATE'
            );
            $statement->execute([$participantId, $quizId]);
            if (!$statement->fetch()) {
                throw new \RuntimeException('Peserta quiz tidak valid.');
            }
            $statement = $this->pdo->prepare(
                'SELECT * FROM quiz_session_questions WHERE quiz_session_id = ? AND position = ?'
            );
            $statement->execute([$quizId, (int) $quiz['current_index']]);
            $question = $statement->fetch();
            if (!$question) {
                throw new \RuntimeException('Pertanyaan quiz tidak tersedia.');
            }
            $responseMs = (int) $timing['response_ms'];
            $correct = hash_equals((string) $question['answer'], $selected);
            $remainingMs = max(0, self::QUESTION_SECONDS * 1000 - $responseMs);
            $score = $correct ? 1000 + (int) round(500 * ($remainingMs / (self::QUESTION_SECONDS * 1000))) : 0;
            $insert = $this->pdo->prepare(
                'INSERT INTO quiz_answers
                    (quiz_session_id, participant_id, session_question_id, selected_answer, is_correct, score, response_ms, answered_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(3))'
            );
            $insert->execute([$quizId, $participantId, $question['id'], $selected, $correct ? 1 : 0, $score, $responseMs]);
            $update = $this->pdo->prepare(
                'UPDATE quiz_participants SET total_score = total_score + ?,
                 correct_answers = correct_answers + ?, last_seen_at = NOW() WHERE id = ?'
            );
            $update->execute([$score, $correct ? 1 : 0, $participantId]);
            $this->pdo->commit();
            return ['score' => $score, 'correct' => $correct, 'response_ms' => $responseMs];
        } catch (\PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ((string) $exception->getCode() === '23000') {
                throw new \RuntimeException('Jawaban untuk pertanyaan ini sudah dikirim.');
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return list<array<string,mixed>> */
    public function participants(int $quizId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, display_name, avatar, last_seen_at FROM quiz_participants
             WHERE quiz_session_id = ? ORDER BY joined_at, id'
        );
        $statement->execute([$quizId]);
        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function leaderboard(int $quizId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.display_name, p.avatar, p.total_score, p.correct_answers,
                    COALESCE(AVG(a.response_ms), 999999) AS average_response_ms
             FROM quiz_participants p
             LEFT JOIN quiz_answers a ON a.participant_id = p.id
             WHERE p.quiz_session_id = ?
             GROUP BY p.id
             ORDER BY p.total_score DESC, p.correct_answers DESC, average_response_ms ASC,
                      p.display_name ASC, p.id ASC'
        );
        $statement->execute([$quizId]);
        return $statement->fetchAll();
    }

    private function scalar(string $sql, array $parameters): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchColumn();
    }
}
