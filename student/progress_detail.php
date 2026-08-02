<?php
declare(strict_types=1);
require_once __DIR__.'/../config/koneksi.php';
require_once __DIR__.'/../vendor/autoload.php';
use EnglAI\Mvp\StudentSession;
use EnglAI\Learning\Level;

header('Content-Type: application/json; charset=UTF-8');
try {
    $pdo = db();
    $member = StudentSession::requireMember($pdo);
    $classroomId = (int)$member['classroom_id'];
    $memberId = (int)$member['id'];

    $skill = strtolower((string)($_GET['skill'] ?? ''));
    $level = Level::validate((string)($_GET['level'] ?? ''));

    if (!in_array($skill, ['reading', 'listening', 'speaking', 'writing'], true)) {
        throw new InvalidArgumentException('Skill tidak valid.');
    }

    $stmt = $pdo->prepare("
        SELECT a.id, a.score, a.answer_json, a.transcript, a.writing_submission, a.feedback, a.submitted_at,
               l.title, l.instruction, l.content_json, l.skill, l.level, l.activity_type,
               ar.criteria_json, ar.strengths_json, ar.improvements_json, ar.grammar_notes_json, ar.vocabulary_notes_json, ar.suggested_revision, ar.example_answer
        FROM learning_attempts a
        JOIN learning_activities l ON l.id = a.activity_id
        LEFT JOIN assessment_results ar ON ar.attempt_id = a.id
        WHERE a.member_id = ? AND a.classroom_id = ? AND l.skill = ? AND l.level = ? AND a.status = 'completed'
        ORDER BY a.submitted_at DESC
    ");
    $stmt->execute([$memberId, $classroomId, $skill, $level]);
    $attempts = $stmt->fetchAll();

    $results = [];
    foreach ($attempts as $row) {
        $content = json_decode((string)$row['content_json'], true) ?: [];
        
        // Normalize reading / listening activity content structure
        if (!isset($content['passage']) && isset($content['short_context'])) {
            $content['passage'] = $content['short_context'];
        }
        if (isset($content['options']) && is_array($content['options'])) {
            $rawOptions = $content['options'];
            $content['options'] = array_map(function($o) {
                return is_array($o) ? ($o['text'] ?? '') : (string)$o;
            }, $content['options']);
            
            if (!isset($content['answer'])) {
                if (isset($content['correct_option_id'])) {
                    foreach ($rawOptions as $index => $opt) {
                        if (is_array($opt) && isset($opt['id']) && $opt['id'] === $content['correct_option_id']) {
                            $content['answer'] = chr(65 + $index);
                            break;
                        }
                    }
                }
                if (!isset($content['answer'])) {
                    $content['answer'] = 'A';
                }
            }
        }

        $results[] = [
            'id' => (int)$row['id'],
            'title' => $row['title'],
            'instruction' => $row['instruction'],
            'score' => (int)$row['score'],
            'feedback' => $row['feedback'],
            'submitted_at' => $row['submitted_at'],
            'activity_type' => $row['activity_type'],
            'skill' => $row['skill'],
            'level' => $row['level'],
            'answer_json' => json_decode((string)$row['answer_json'], true),
            'transcript' => $row['transcript'],
            'writing_submission' => $row['writing_submission'],
            'criteria_json' => json_decode((string)$row['criteria_json'], true),
            'strengths_json' => json_decode((string)$row['strengths_json'], true),
            'improvements_json' => json_decode((string)$row['improvements_json'], true),
            'grammar_notes_json' => json_decode((string)$row['grammar_notes_json'], true),
            'vocabulary_notes_json' => json_decode((string)$row['vocabulary_notes_json'], true),
            'suggested_revision' => $row['suggested_revision'],
            'example_answer' => $row['example_answer'],
            'question_data' => [
                'passage' => $content['passage'] ?? $content['short_context'] ?? null,
                'question' => $content['question'] ?? $content['prompt'] ?? null,
                'options' => $content['options'] ?? null,
                'explanation' => $content['explanation'] ?? null,
                'transcript' => $content['transcript'] ?? $content['script'] ?? null,
                'scenario' => $content['scenario'] ?? null,
                'context' => $content['context'] ?? null,
            ]
        ];
    }

    echo json_encode(['success' => true, 'attempts' => $results]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
