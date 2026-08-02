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

    $attemptId = isset($_GET['attempt_id']) ? (int)$_GET['attempt_id'] : 0;
    $readingSessionId = isset($_GET['reading_session_id']) ? (int)$_GET['reading_session_id'] : 0;

    if ($readingSessionId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM reading_sessions WHERE id = ? AND member_id = ? AND classroom_id = ?");
        $stmt->execute([$readingSessionId, $memberId, $classroomId]);
        $session = $stmt->fetch();
        if (!$session) {
            throw new RuntimeException("Reading session tidak ditemukan.");
        }
        
        $stmt = $pdo->prepare("SELECT * FROM reading_attempts WHERE reading_session_id = ? ORDER BY id");
        $stmt->execute([$readingSessionId]);
        $attempts = $stmt->fetchAll();
        
        $snapshot = json_decode((string)$session['snapshot_json'], true) ?: [];
        $questions = $snapshot['questions'] ?? [];
        $qMap = [];
        foreach ($questions as $q) {
            $qMap[(string)$q['id']] = $q;
        }
        
        $results = [];
        foreach ($attempts as $row) {
            $qId = (string)$row['question_id'];
            $qData = $qMap[$qId] ?? null;
            if (!$qData) continue;
            
            $optionOrder = json_decode((string)$row['option_order_json'], true) ?: [];
            $selectedOption = (string)$row['selected_option_id'];
            $correctOption = (string)$qData['correct_option_id'];
            
            $selectedLetter = null;
            $correctLetter = null;
            
            foreach ($optionOrder as $idx => $optId) {
                if ($optId === $selectedOption) $selectedLetter = chr(65 + $idx);
                if ($optId === $correctOption) $correctLetter = chr(65 + $idx);
            }
            
            $optionsList = [];
            foreach ($optionOrder as $optId) {
                foreach ($qData['options'] as $o) {
                    if ((string)$o['id'] === (string)$optId) {
                        $optionsList[] = (string)$o['text'];
                        break;
                    }
                }
            }
            
            $results[] = [
                'id' => (int)$row['id'],
                'title' => 'Reading Question: ' . ($qData['type'] ?? 'Standalone'),
                'instruction' => 'Read the context carefully and select the correct answer.',
                'score' => $row['result'] === 'correct' ? 100 : 0,
                'feedback' => $qData['explanation'] ?? '',
                'submitted_at' => $row['answered_at'],
                'activity_type' => 'standalone_question',
                'skill' => 'reading',
                'level' => $session['level'],
                'answer_json' => [
                    'selected' => $selectedLetter,
                    'correct_answer' => $correctLetter
                ],
                'transcript' => null,
                'writing_submission' => null,
                'criteria_json' => null,
                'strengths_json' => null,
                'improvements_json' => null,
                'grammar_notes_json' => null,
                'vocabulary_notes_json' => null,
                'suggested_revision' => null,
                'example_answer' => null,
                'question_data' => [
                    'passage' => $qData['short_context'] ?? null,
                    'question' => $qData['question'] ?? null,
                    'options' => $optionsList,
                    'explanation' => $qData['explanation'] ?? null,
                    'transcript' => null,
                    'scenario' => null,
                    'context' => null,
                ]
            ];
        }
        echo json_encode(['success' => true, 'attempts' => $results]);
        exit;
    }

    $skill = strtolower((string)($_GET['skill'] ?? ''));
    if ($attemptId > 0) {
        $stmt = $pdo->prepare("
            SELECT a.id, a.score, a.answer_json, a.transcript, a.writing_submission, a.feedback, a.submitted_at,
                   l.title, l.instruction, l.content_json, l.skill, l.level, l.activity_type,
                   ar.criteria_json, ar.strengths_json, ar.improvements_json, ar.grammar_notes_json, ar.vocabulary_notes_json, ar.suggested_revision, ar.example_answer
            FROM learning_attempts a
            JOIN learning_activities l ON l.id = a.activity_id
            LEFT JOIN assessment_results ar ON ar.attempt_id = a.id
            WHERE a.id = ? AND a.member_id = ? AND a.classroom_id = ?
        ");
        $stmt->execute([$attemptId, $memberId, $classroomId]);
        $attempts = $stmt->fetchAll();
    } elseif ($skill === 'reading') {
        $level = Level::validate((string)($_GET['level'] ?? ''));
        $stmt = $pdo->prepare("SELECT id FROM reading_sessions WHERE member_id = ? AND classroom_id = ? AND level = ? AND status = 'completed' ORDER BY completed_at DESC");
        $stmt->execute([$memberId, $classroomId, $level]);
        $sessionIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $results = [];
        if (!empty($sessionIds)) {
            $inClause = implode(',', array_map('intval', $sessionIds));
            $stmt = $pdo->prepare("
                SELECT ra.*, rs.level, rs.snapshot_json
                FROM reading_attempts ra
                JOIN reading_sessions rs ON rs.id = ra.reading_session_id
                WHERE ra.reading_session_id IN ($inClause)
                ORDER BY ra.id DESC
            ");
            $stmt->execute();
            $attemptsData = $stmt->fetchAll();
            
            $sessionSnapshots = [];
            foreach ($attemptsData as $row) {
                $sessId = (int)$row['reading_session_id'];
                if (!isset($sessionSnapshots[$sessId])) {
                    $snapshot = json_decode((string)$row['snapshot_json'], true) ?: [];
                    $questions = $snapshot['questions'] ?? [];
                    $qMap = [];
                    foreach ($questions as $q) {
                        $qMap[(string)$q['id']] = $q;
                    }
                    $sessionSnapshots[$sessId] = $qMap;
                }
                $qMap = $sessionSnapshots[$sessId];
                
                $qId = (string)$row['question_id'];
                $qData = $qMap[$qId] ?? null;
                if (!$qData) continue;
                
                $optionOrder = json_decode((string)$row['option_order_json'], true) ?: [];
                $selectedOption = (string)$row['selected_option_id'];
                $correctOption = (string)$qData['correct_option_id'];
                
                $selectedLetter = null;
                $correctLetter = null;
                
                foreach ($optionOrder as $idx => $optId) {
                    if ($optId === $selectedOption) $selectedLetter = chr(65 + $idx);
                    if ($optId === $correctOption) $correctLetter = chr(65 + $idx);
                }
                
                $optionsList = [];
                foreach ($optionOrder as $optId) {
                    foreach ($qData['options'] as $o) {
                        if ((string)$o['id'] === (string)$optId) {
                            $optionsList[] = (string)$o['text'];
                            break;
                        }
                    }
                }
                
                $results[] = [
                    'id' => (int)$row['id'],
                    'title' => 'Reading Practice - ' . ($qData['type'] ?? 'Question'),
                    'instruction' => 'Read the passage and select the correct option.',
                    'score' => $row['result'] === 'correct' ? 100 : 0,
                    'feedback' => $qData['explanation'] ?? '',
                    'submitted_at' => $row['answered_at'],
                    'activity_type' => 'standalone_question',
                    'skill' => 'reading',
                    'level' => $row['level'],
                    'answer_json' => [
                        'selected' => $selectedLetter,
                        'correct_answer' => $correctLetter
                    ],
                    'transcript' => null,
                    'writing_submission' => null,
                    'criteria_json' => null,
                    'strengths_json' => null,
                    'improvements_json' => null,
                    'grammar_notes_json' => null,
                    'vocabulary_notes_json' => null,
                    'suggested_revision' => null,
                    'example_answer' => null,
                    'question_data' => [
                        'passage' => $qData['short_context'] ?? null,
                        'question' => $qData['question'] ?? null,
                        'options' => $optionsList,
                        'explanation' => $qData['explanation'] ?? null,
                        'transcript' => null,
                        'scenario' => null,
                        'context' => null,
                    ]
                ];
            }
        }
        echo json_encode(['success' => true, 'attempts' => $results]);
        exit;
    } else {
        $level = Level::validate((string)($_GET['level'] ?? ''));
        if (!in_array($skill, ['listening', 'speaking', 'writing'], true)) {
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
    }

    $results = [];
    foreach ($attempts as $row) {
        $content = json_decode((string)$row['content_json'], true) ?: [];
        
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

        $ansJson = json_decode((string)$row['answer_json'], true) ?: [];
        $selectedLetter = $ansJson['selected'] ?? null;
        $correctLetter = $ansJson['correct_answer'] ?? null;
        
        if (isset($content['options']) && is_array($content['options'])) {
            if ($selectedLetter === null && isset($ansJson['selected_option_id'])) {
                foreach ($rawOptions as $index => $opt) {
                    if (is_array($opt) && isset($opt['id']) && (string)$opt['id'] === (string)$ansJson['selected_option_id']) {
                        $selectedLetter = chr(65 + $index);
                        break;
                    }
                }
            }
            if ($correctLetter === null && isset($ansJson['correct_option_id'])) {
                foreach ($rawOptions as $index => $opt) {
                    if (is_array($opt) && isset($opt['id']) && (string)$opt['id'] === (string)$ansJson['correct_option_id']) {
                        $correctLetter = chr(65 + $index);
                        break;
                    }
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
            'answer_json' => [
                'selected' => $selectedLetter,
                'correct_answer' => $correctLetter ?: ($content['answer'] ?? null)
            ],
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

