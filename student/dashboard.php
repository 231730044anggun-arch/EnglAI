<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../vendor/autoload.php';

use EnglAI\Mvp\StudentSession;
use EnglAI\Learning\ReadingSessionService;

function student_h(mixed $value, string $fallback = ''): string {
    $text = trim((string)($value ?? ''));
    return htmlspecialchars($text !== '' ? $text : $fallback, ENT_QUOTES, 'UTF-8');
}

$pdo = db();
$member = StudentSession::requireMember($pdo);
$classroomId = (int)$member['classroom_id'];
$studentName = trim((string)($member['display_name'] ?? '')) ?: 'Guest Student';
$avatarFile = trim((string)($member['avatar'] ?? '')) ?: 'a.jpg';
if (!preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $avatarFile)) {
    $avatarFile = 'a.jpg';
}

// Fetch active Live Quiz session
$stmt = $pdo->prepare("SELECT q.* FROM quiz_sessions q WHERE q.classroom_id=? AND q.state IN ('LOBBY','COUNTDOWN','ACTIVE','BETWEEN_QUESTIONS','EVALUATING') ORDER BY q.id DESC LIMIT 1");
$stmt->execute([$classroomId]);
$quiz = $stmt->fetch();

// Fetch completed practice sessions stats
$stmt = $pdo->prepare("SELECT COUNT(*) completed, COALESCE(SUM(score),0) total_score, COALESCE(MAX(score),0) best_score FROM student_learning_sessions WHERE member_id=? AND status='completed'");
$stmt->execute([(int)$member['id']]);
$pracStats = $stmt->fetch();

// Fetch completed modular activities stats
$stmt = $pdo->prepare("SELECT COUNT(*) completed, COALESCE(SUM(score),0) total_score, COALESCE(MAX(score),0) best_score FROM learning_attempts WHERE member_id=? AND status='completed'");
$stmt->execute([(int)$member['id']]);
$actStats = $stmt->fetch();

$totalCompleted = (int)$pracStats['completed'] + (int)$actStats['completed'];
$maxBestScore = max((int)$pracStats['best_score'], (int)$actStats['best_score']);

$avgScore = 0;
if ($totalCompleted > 0) {
    $avgScore = ((float)$pracStats['total_score'] + (float)$actStats['total_score']) / $totalCompleted;
}

$progress = [
    'completed' => $totalCompleted,
    'average_score' => $avgScore,
    'best_score' => $maxBestScore
];

// Fetch total self learning questions in bank
$stmt = $pdo->prepare("SELECT COUNT(*) FROM content_questions WHERE classroom_id=? AND content_type='self_learning'");
$stmt->execute([$classroomId]);
$bank = (int)$stmt->fetchColumn();

// Fetch teacher real name
$stmt = $pdo->prepare("SELECT name FROM users WHERE email = (SELECT teacher_key FROM classrooms WHERE id = ?)");
$stmt->execute([$classroomId]);
$teacherName = $stmt->fetchColumn() ?: (string)db()->query('SELECT teacher_key FROM classrooms WHERE id='.$classroomId)->fetchColumn();

// Fetch RPP topic or original name
$stmt = $pdo->prepare("SELECT topic FROM ai_analyses WHERE classroom_id=? AND status='valid' ORDER BY id DESC LIMIT 1");
$stmt->execute([$classroomId]);
$lesson = $stmt->fetchColumn();
if (!$lesson) {
    $stmt = $pdo->prepare("SELECT original_name FROM classroom_lesson_plans WHERE classroom_id=? AND is_active=1 ORDER BY version DESC LIMIT 1");
    $stmt->execute([$classroomId]);
    $origName = $stmt->fetchColumn();
    $lesson = $origName ? pathinfo($origName, PATHINFO_FILENAME) : 'Lesson plan sedang disiapkan';
}

// Fetch recent from student_learning_sessions
$stmt = $pdo->prepare("SELECT 'practice' as type, score, completed_at, NULL as title, NULL as skill FROM student_learning_sessions WHERE member_id=? AND status='completed' ORDER BY id DESC LIMIT 1");
$stmt->execute([(int)$member['id']]);
$rec1 = $stmt->fetch();

// Fetch recent from learning_attempts
$stmt = $pdo->prepare("SELECT 'activity' as type, a.score, a.completed_at, l.title, l.skill FROM learning_attempts a JOIN learning_activities l ON l.id=a.activity_id WHERE a.member_id=? AND a.status='completed' ORDER BY a.id DESC LIMIT 1");
$stmt->execute([(int)$member['id']]);
$rec2 = $stmt->fetch();

$recent = null;
if ($rec1 && $rec2) {
    $recent = (strtotime($rec1['completed_at']) > strtotime($rec2['completed_at'])) ? $rec1 : $rec2;
} else if ($rec1) {
    $recent = $rec1;
} else if ($rec2) {
    $recent = $rec2;
}

// Fetch past quiz leaderboard stats
$stmt = $pdo->prepare("SELECT p.display_name, p.avatar, p.total_score FROM quiz_participants p JOIN quiz_sessions q ON q.id=p.quiz_session_id WHERE q.classroom_id=? AND q.state IN ('FINISHED','CLOSED') ORDER BY p.total_score DESC LIMIT 5");
$stmt->execute([$classroomId]);
$leaders = $stmt->fetchAll();

$accuracy = (int)round((float)$progress['average_score']);
$level = $accuracy >= 80 ? 'Advanced' : ($accuracy >= 50 ? 'Intermediate' : 'Basic');

// Fetch classroom learning activity matrix
$stmt = $pdo->prepare("SELECT skill, level, COUNT(*) available FROM learning_activities WHERE classroom_id=? AND status='ready' GROUP BY skill, level");
$stmt->execute([$classroomId]);
$phase2 = [];
foreach ($stmt->fetchAll() as $item) {
    $phase2[$item['skill']][$item['level']] = $item;
}

// Fetch stats of self learning per skill
$stmt = $pdo->prepare("SELECT l.skill, COUNT(a.id) attempts, COUNT(DISTINCT CASE WHEN a.status='completed' THEN a.activity_id END) completed, COALESCE(AVG(CASE WHEN a.status='completed' THEN a.score END),0) average_score, MAX(CASE WHEN a.status='completed' THEN a.score END) latest_score FROM learning_attempts a JOIN learning_activities l ON l.id=a.activity_id WHERE a.member_id=? GROUP BY l.skill");
$stmt->execute([(int)$member['id']]);
$phase2Progress = array_column($stmt->fetchAll(), null, 'skill');
$readingLevels = (new ReadingSessionService($pdo))->productionLevelAvailability($classroomId, (int)$member['id']);

// Dynamic recommendation engine
$recSkill = 'reading'; // default fallback
$recReason = 'Mulai latihan pertama Anda hari ini!';
$hasRecommended = false;

$skillsToTest = ['reading', 'listening', 'speaking', 'writing'];
$skillsData = [];

foreach ($skillsToTest as $sk) {
    if (isset($phase2[$sk])) {
        $totalAvail = 0;
        foreach ($phase2[$sk] as $lvl => $lvlData) {
            $totalAvail += (int)$lvlData['available'];
        }
        $comp = isset($phase2Progress[$sk]) ? (int)$phase2Progress[$sk]['completed'] : 0;
        $rem = max(0, $totalAvail - $comp);
        $avg = isset($phase2Progress[$sk]) ? (float)$phase2Progress[$sk]['average_score'] : 0.0;
        
        if ($totalAvail > 0) {
            $skillsData[$sk] = [
                'total' => $totalAvail,
                'completed' => $comp,
                'remaining' => $rem,
                'average' => $avg
            ];
        }
    }
}

// Find skill with remaining activities
$candidateRem = null;
$minCompleted = 999999;
foreach ($skillsData as $sk => $data) {
    if ($data['remaining'] > 0) {
        if ($data['completed'] < $minCompleted) {
            $minCompleted = $data['completed'];
            $candidateRem = $sk;
        }
    }
}

if ($candidateRem !== null) {
    $recSkill = $candidateRem;
    $remCount = $skillsData[$recSkill]['remaining'];
    $recReason = "💡 <b>Rekomendasi:</b> Anda memiliki <b>" . $remCount . " aktivitas baru</b> pada skill ini yang belum diselesaikan. Yuk, kerjakan!";
    $hasRecommended = true;
} else if (!empty($skillsData)) {
    // If all completed, recommend the one with lowest average score
    $candidateLow = null;
    $minScore = 101.0;
    foreach ($skillsData as $sk => $data) {
        if ($data['average'] < $minScore) {
            $minScore = $data['average'];
            $candidateLow = $sk;
        }
    }
    if ($candidateLow !== null) {
        $recSkill = $candidateLow;
        $recReason = "💡 <b>Rekomendasi Ulang:</b> Anda telah menyelesaikan semua latihan, namun rata-rata nilai pada skill ini paling rendah (<b>" . number_format($minScore, 1) . "%</b>). Latih kembali untuk meningkatkan nilai Anda!";
        $hasRecommended = true;
    }
} else {
    $recReason = "💡 Guru Anda sedang menyiapkan materi pembelajaran di kelas ini.";
}
$skillDescriptions = [
    'reading' => 'Latih vocabulary, comprehension, dan inference melalui materi membaca dari lesson plan Classroom.',
    'listening' => 'Latih pemahaman pendengaran Anda dengan audio simulasi AI dan transkrip teks interaktif.',
    'speaking' => 'Latih percakapan bahasa Inggris Anda dengan Speech-to-Text dan AI Speaking Feedback instan.',
    'writing' => 'Latih penulisan esai bahasa Inggris terstruktur sesuai dengan prompt topik dari Guru.'
];

// Fetch up to 10 recent Reading sessions
$stmt = $pdo->prepare("SELECT 'reading_session' as type, id as reading_session_id, NULL as listening_session_id, NULL as speaking_session_id, NULL as writing_session_id, ROUND((score / (total_questions * 5)) * 100) as score, completed_at, CONCAT('Reading – ', UPPER(level)) as title, 'reading' as skill, level FROM reading_sessions WHERE member_id=? AND classroom_id=? AND status='completed' ORDER BY completed_at DESC LIMIT 10");
$stmt->execute([(int)$member['id'], (int)$classroomId]);
$histReading = $stmt->fetchAll();

// Fetch up to 10 recent Listening sessions
$stmt = $pdo->prepare("SELECT 'listening_session' as type, NULL as reading_session_id, id as listening_session_id, NULL as speaking_session_id, NULL as writing_session_id, score, completed_at, CONCAT('Listening – ', UPPER(level)) as title, 'listening' as skill, level FROM listening_sessions WHERE member_id=? AND classroom_id=? AND status='completed' ORDER BY completed_at DESC LIMIT 10");
$stmt->execute([(int)$member['id'], (int)$classroomId]);
$histListening = $stmt->fetchAll();

// Fetch up to 10 recent Speaking sessions
$stmt = $pdo->prepare("SELECT 'speaking_session' as type, NULL as reading_session_id, NULL as listening_session_id, id as speaking_session_id, NULL as writing_session_id, ROUND(COALESCE((SELECT AVG(sr.score) FROM speaking_recordings sr WHERE sr.session_id=ss.id),0)) as score, completed_at, CONCAT('Speaking – ', UPPER(level)) as title, 'speaking' as skill, level FROM speaking_sessions ss WHERE member_id=? AND classroom_id=? AND status='completed' ORDER BY completed_at DESC LIMIT 10");
$stmt->execute([(int)$member['id'], (int)$classroomId]);
$histSpeaking = $stmt->fetchAll();

// Fetch up to 10 recent Writing sessions
$stmt = $pdo->prepare("SELECT 'writing_session' as type, NULL as reading_session_id, NULL as listening_session_id, NULL as speaking_session_id, id as writing_session_id, ROUND(COALESCE((SELECT AVG(ws2.score) FROM writing_submissions ws2 WHERE ws2.session_id=ws.id AND ws2.score IS NOT NULL),0)) as score, completed_at, CONCAT('Writing – ', UPPER(level)) as title, 'writing' as skill, level FROM writing_sessions ws WHERE member_id=? AND classroom_id=? AND status='completed' ORDER BY completed_at DESC LIMIT 10");
$stmt->execute([(int)$member['id'], (int)$classroomId]);
$histWriting = $stmt->fetchAll();

// Combine and sort history logs
$history = array_merge($histReading, $histListening, $histSpeaking, $histWriting);
usort($history, function($a, $b) {
    return strcmp($b['completed_at'], $a['completed_at']);
});
$history = array_slice($history, 0, 10);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Student Classroom · EnglAI</title>
    <link rel="stylesheet" href="/assets/css/mvp.css">
    <style>
        .tab-panel {
            display: none;
        }
        .tab-panel.active {
            display: block;
        }
        .pulsing-alert {
            background: linear-gradient(135deg, #FF9F1C 0%, #FF6B6B 100%);
            color: #fff;
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 15px rgba(255, 159, 28, 0.4);
            animation: pulse-border 2s infinite alternate;
        }
        @keyframes pulse-border {
            0% { box-shadow: 0 4px 15px rgba(255, 159, 28, 0.4); }
            100% { box-shadow: 0 4px 25px rgba(255, 107, 107, 0.7); }
        }
        .history-item {
            transition: transform 0.2s ease, background 0.2s ease, border-color 0.2s ease;
        }
        .history-item:hover {
            transform: translateY(-2px);
            background: rgba(255, 255, 255, 0.04) !important;
            border-color: rgba(255, 255, 255, 0.12) !important;
        }
        /* Modal Styling */
        .modal {
            display: none; 
            position: fixed; 
            z-index: 10000; 
            left: 0;
            top: 0;
            width: 100%; 
            height: 100%; 
            overflow: auto; 
            background-color: rgba(7, 7, 26, 0.85); 
            backdrop-filter: blur(8px);
        }
        .modal-content {
            background: #0f1035; 
            border: 1px solid rgba(255, 255, 255, 0.1); 
            margin: 5% auto; 
            padding: 32px; 
            width: min(800px, 95%); 
            border-radius: 24px; 
            position: relative; 
            box-shadow: 0 25px 60px rgba(0,0,0,0.8);
            animation: modalFadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .close {
            position: absolute; 
            right: 24px; 
            top: 24px; 
            background: rgba(255, 255, 255, 0.05); 
            border: 1px solid rgba(255, 255, 255, 0.1); 
            color: #94a3b8; 
            font-size: 20px; 
            cursor: pointer;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            transition: background 0.2s, color 0.2s;
        }
        .close:hover {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }
        .modal-body {
            max-height: 70vh; 
            overflow-y: auto; 
            display: flex; 
            flex-direction: column; 
            gap: 16px; 
            padding-right: 8px;
            margin-top: 16px;
        }
        .attempt-detail-item {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            padding: 20px;
            transition: border-color 0.2s;
        }
        .attempt-detail-item:hover {
            border-color: rgba(255, 255, 255, 0.1);
        }
        .detail-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 8px;
        }
        .detail-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin: 0;
            color: #fff;
        }
        .detail-badge-group {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .detail-section {
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
        }
        .detail-section-title {
            font-size: 0.85rem;
            color: var(--muted);
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.05em;
            margin-bottom: 8px;
        }
        .option-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 10px;
        }
        .option-item {
            padding: 10px 14px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.06);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
        }
        .option-item.correct {
            background: rgba(52, 211, 153, 0.08);
            border-color: rgba(52, 211, 153, 0.3);
            color: #34d399;
        }
        .option-item.incorrect {
            background: rgba(248, 113, 113, 0.08);
            border-color: rgba(248, 113, 113, 0.3);
            color: #f87171;
        }
        .option-letter {
            font-weight: 800;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            display: grid;
            place-items: center;
            font-size: 0.8rem;
        }
        .option-item.correct .option-letter {
            background: #34d399;
            color: #07071a;
        }
        .option-item.incorrect .option-letter {
            background: #f87171;
            color: #fff;
        }
        .explanation-box {
            background: rgba(124, 58, 237, 0.05);
            border: 1px dashed rgba(124, 58, 237, 0.2);
            border-radius: 10px;
            padding: 12px 16px;
            margin-top: 10px;
            font-size: 0.88rem;
        }
        .collapsible-trigger {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: #cbd5e1;
            padding: 6px 12px;
            font-size: 0.8rem;
            font-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 8px;
            transition: background 0.2s;
        }
        .collapsible-trigger:hover {
            background: rgba(255, 255, 255, 0.08);
        }
        .collapsible-content {
            display: none;
            background: rgba(255, 255, 255, 0.01);
            border: 1px solid rgba(255, 255, 255, 0.04);
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 0.88rem;
            color: #cbd5e1;
            margin-bottom: 12px;
            line-height: 1.5;
        }
        .collapsible-content p {
            margin: 0;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="stars" aria-hidden="true"></div>
    <header class="nav">
        <a class="brand" href="/student/dashboard.php"><span class="brand-mark">E</span>EnglAI</a>
        <div class="row">
            <span class="badge available"><?= student_h($studentName, 'Guest Student') ?></span>
            <a class="button secondary" href="/student/logout.php">Leave Classroom</a>
        </div>
    </header>
    <main class="shell">
        <nav class="breadcrumb">
            <a href="/student/account.php">Workspace</a>
            <span>›</span>
            <strong><?= student_h($member['classroom_name'] ?? null, 'Classroom') ?></strong>
        </nav>

        <!-- Dynamic Live Quiz Pulsing Notification Banner -->
        <?php if ($quiz): ?>
            <div class="pulsing-alert">
                <div>
                    <span style="font-weight: bold; font-size: 1.1rem; display: block;">🔥 Live Quiz Aktif!</span>
                    <span style="font-size: 0.9rem; opacity: 0.9;">Lobby kuis sedang dibuka. Masuk sekarang dan bersenang-senanglah!</span>
                </div>
                <a class="button gold" href="/student/quiz_join.php?id=<?= (int)$quiz['id'] ?>" style="margin: 0; box-shadow: none;">Ikuti Live Quiz →</a>
            </div>
        <?php endif; ?>

        <section class="card student-hero">
            <div class="toolbar">
                <div class="row" style="align-items: center; gap: 15px;">
                    <img src="/assets/images/avatars/<?= htmlspecialchars($avatarFile) ?>" alt="Avatar" width="60" height="60" style="border-radius: 50%; border: 2px solid #FFE66D; background: #2b2b36; object-fit: cover;">
                    <div>
                        <span class="code"><?= student_h($member['classroom_code'] ?? null, '-') ?></span>
                        <h1><?= student_h($member['classroom_name'] ?? null, 'Classroom') ?></h1>
                        <p class="muted">Student: <strong><?= student_h($studentName, 'Guest Student') ?></strong> · Teacher: <?= student_h($teacherName, 'Belum tersedia') ?> · <?= student_h($lesson, 'Lesson plan sedang disiapkan') ?></p>
                    </div>
                </div>
                <?php if ($quiz): ?>
                    <span class="badge live"><?= $quiz['state'] === 'LOBBY' ? 'Live Quiz Ready' : 'Live Now' ?></span>
                <?php endif; ?>
            </div>
            <div class="row" style="justify-content:space-between; margin-top: 15px;">
                <span>Global Learning Progress</span>
                <b><?= $accuracy ?>% Accuracy</b>
            </div>
            <div class="progress-track">
                <div class="progress-fill" data-progress="<?= $accuracy ?>"></div>
            </div>
        </section>

        <nav class="tabs glass" aria-label="Student sections">
            <a class="active" href="#overview">Overview & Stats</a>
            <a href="#learning">Self Learning</a>
            <a href="#quiz">Live Classroom Quiz</a>
            <a href="#leaderboard">Leaderboard</a>
        </nav>

        <!-- TAB: OVERVIEW -->
        <div id="tab-overview" class="tab-panel active">


            <section style="margin-top: 22px;">
                <article class="card">
                    <h2>Skill Proficiency</h2>
                    <p class="muted">Perbandingan nilai rata-rata Anda untuk setiap skill bahasa Inggris:</p>
                    <div style="display: flex; flex-direction: column; gap: 16px; margin-top: 20px;">
                        <?php 
                        $skillIcons = ['reading' => '📖', 'listening' => '🎧', 'speaking' => '🎙️', 'writing' => '✍️'];
                        foreach ($skillsToTest as $sk): 
                            $prog = $phase2Progress[$sk] ?? null;
                            $score = $prog ? (float)$prog['average_score'] : 0.0;
                            $comp = $prog ? (int)$prog['completed'] : 0;
                        ?>
                            <div>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 0.9rem;">
                                    <span style="font-weight: bold;"><?= $skillIcons[$sk] ?> <?= ucfirst($sk) ?> <span class="muted" style="font-weight: normal; font-size: 0.8rem;">(<?= $comp ?> selesai)</span></span>
                                    <b style="color: <?= $score >= 80 ? '#10b981' : ($score >= 50 ? '#3b82f6' : '#ef4444') ?>"><?= number_format($score, 1) ?>%</b>
                                </div>
                                <div class="progress-track" style="margin: 0; height: 10px; background: rgba(255,255,255,0.06); border-radius: 99px;">
                                    <div class="progress-fill" style="width: <?= $score ?>%; height: 100%; border-radius: 99px; background: <?= $score >= 80 ? 'linear-gradient(90deg, #10b981, #34d399)' : ($score >= 50 ? 'linear-gradient(90deg, #3b82f6, #60a5fa)' : 'linear-gradient(90deg, #ef4444, #f87171)') ?>;"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>
            </section>

            <section class="card" style="margin-top: 22px;">
                <h2>History Log & Progress</h2>
                <p class="muted">Daftar riwayat aktivitas dan latihan mandiri yang telah Anda selesaikan di kelas ini:</p>
                <?php if (empty($history)): ?>
                    <div class="empty" style="margin-top: 15px;">Belum ada riwayat pengerjaan latihan.</div>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 12px; margin-top: 18px;">
                        <?php foreach ($history as $h): 
                            $skill = strtolower($h['skill']);
                            $skillIcons = ['reading' => '📖', 'listening' => '🎧', 'speaking' => '🎙️', 'writing' => '✍️'];
                            $skillIcon = $skillIcons[$skill] ?? '📝';
                            $score = (int)$h['score'];
                            $scoreColor = $score >= 80 ? '#10b981' : ($score >= 50 ? '#3b82f6' : '#ef4444');
                            $scoreBg = $score >= 80 ? 'rgba(16, 185, 129, 0.08)' : ($score >= 50 ? 'rgba(59, 82, 246, 0.08)' : 'rgba(239, 68, 68, 0.08)');
                            
                            $title = $h['type'] === 'practice' ? 'Self Learning Practice' : student_h($h['title'] ?? null, 'Learning Activity');
                            
                            $isClickable = in_array($h['type'], ['reading_session', 'listening_session', 'speaking_session', 'writing_session'], true);
                            $clickableClass = $isClickable ? 'btn-view-history-detail' : '';
                            $clickableStyle = $isClickable ? 'cursor: pointer;' : '';
                            ?>
                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; background: rgba(255,255,255,0.015); border: 1px solid rgba(255,255,255,0.06); border-radius: 14px; gap: 16px; transition: transform 0.2s ease, background 0.2s ease; <?= $clickableStyle ?>" 
                                 class="history-item <?= $clickableClass ?>"
                                 data-type="<?= $h['type'] ?>"
                                 data-reading-session-id="<?= $h['reading_session_id'] ?? '' ?>"
                                 data-listening-session-id="<?= $h['listening_session_id'] ?? '' ?>"
                                 data-speaking-session-id="<?= $h['speaking_session_id'] ?? '' ?>"
                                 data-writing-session-id="<?= $h['writing_session_id'] ?? '' ?>"
                                 data-title="<?= student_h($title) ?>">
                                <div style="display: flex; align-items: center; gap: 14px; min-width: 0;">
                                    <div style="font-size: 1.4rem; width: 42px; height: 42px; flex-shrink: 0; display: grid; place-items: center; background: rgba(255,255,255,0.03); border-radius: 10px; border: 1px solid rgba(255,255,255,0.08);">
                                        <?= $skillIcon ?>
                                    </div>
                                    <div style="min-width: 0;">
                                        <h3 style="margin: 0; font-size: 0.95rem; font-weight: 600; color: #fff; line-height: 1.35; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= $title ?></h3>
                                        <div style="display: flex; align-items: center; gap: 8px; margin-top: 4px; flex-wrap: wrap;">
                                            <span class="badge" style="background: rgba(255,255,255,0.06); font-size: 0.72rem; padding: 2px 8px; text-transform: uppercase; letter-spacing: 0.03em;"><?= ucfirst($skill) ?></span>
                                            <span class="badge" style="background: rgba(255,255,255,0.06); font-size: 0.72rem; padding: 2px 8px; text-transform: capitalize;"><?= student_h($h['level'] ?? null, '-') ?></span>
                                            <span style="font-size: 0.78rem; color: rgba(255,255,255,0.4);"><?= date('d M Y, H:i', strtotime((string)$h['completed_at'])) ?></span>
                                            <?php if ($isClickable): ?>
                                                <span style="font-size: 0.72rem; color: #a78bfa; font-weight: bold; background: rgba(167,139,250,0.1); padding: 1px 6px; border-radius: 4px;">Lihat Detail</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div style="flex-shrink: 0;">
                                    <div style="background: <?= $scoreBg ?>; color: <?= $scoreColor ?>; padding: 6px 12px; border-radius: 10px; font-weight: 700; font-family: Orbitron, sans-serif; font-size: 0.88rem; border: 1px solid <?= $scoreColor ?>20; white-space: nowrap;">
                                        <?= $score ?>/100
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <!-- TAB: SELF LEARNING -->
        <div id="tab-learning" class="tab-panel">
            
            <?php if ($hasRecommended): ?>
                <div class="card recommend" style="margin-bottom: 22px; background: linear-gradient(135deg, rgba(124,58,237,0.1) 0%, rgba(236,72,153,0.05) 100%); border: 1px solid rgba(167,139,250,0.3);">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                        <div>
                            <span class="badge available" style="margin-bottom: 8px;">Recommended Activity</span>
                            <h3 style="margin: 0; font-size: 1.4rem; font-family: Orbitron;"><?= ucfirst($recSkill) ?> Self Learning</h3>
                            <p class="muted" style="margin: 4px 0 0 0; font-size: 0.9rem;"><?= htmlspecialchars($skillDescriptions[$recSkill]) ?></p>
                            <p style="margin: 8px 0 0 0; font-size: 0.85rem; opacity: 0.9;"><?= $recReason ?></p>
                        </div>
                        <a class="button gold" href="/student/skill.php?skill=<?= $recSkill ?>" style="margin: 0;">Mulai Latihan →</a>
                    </div>
                </div>
            <?php endif; ?>
            <section class="grid four">
                <?php foreach ([['📖', 'reading'], ['🎧', 'listening'], ['🎤', 'speaking'], ['✍️', 'writing']] as $skillData): 
                    $skillName = $skillData[1];
                    $availableLevels = $phase2[$skillName] ?? [];
                    if ($skillName === 'reading') {
                        $availableLevels = array_filter($readingLevels, static fn(array $item): bool => $item['ready']);
                    }
                    $skillProgress = $phase2Progress[$skillName] ?? null;
                    $done = (int)($skillProgress['completed'] ?? 0);
                    $attempts = (int)($skillProgress['attempts'] ?? 0);
                    $totalAvailable = $skillName === 'reading' ? array_sum(array_map(static fn(array $row): int => (int)$row['valid_count'], $availableLevels)) : array_sum(array_map(static fn(array $row): int => (int)$row['available'], $availableLevels));
                    $status = $totalAvailable === 0 ? 'Not Generated' : ($done >= $totalAvailable ? 'Completed' : ($attempts > 0 ? 'In Progress' : 'Available'));
                    if ($skillName === 'reading' && $totalAvailable > 0) {
                        $hasActiveReading = count(array_filter($readingLevels, static fn(array $item): bool => !empty($item['active_session']))) > 0;
                        $hasCompletedReading = count(array_filter($readingLevels, static fn(array $item): bool => (int)$item['completed_sessions'] > 0)) > 0;
                        $status = $hasActiveReading ? 'In Progress' : ($hasCompletedReading ? 'Completed' : 'Available');
                    }
                ?>
                    <article class="card <?= $availableLevels ? 'hover' : '' ?>" id="<?= $skillName ?>">
                        <span class="badge <?= $availableLevels ? 'available' : 'dev' ?>"><?= $status ?></span>
                        <div class="skill-icon"><?= $skillData[0] ?></div>
                        <h3><?= ucfirst($skillName) ?></h3>
                        <p class="muted"><?= count($availableLevels) ?> level tersedia · <?= number_format((float)($skillProgress['average_score'] ?? 0), 0) ?>% average</p>
                        <?php if ($availableLevels): ?>
                            <a class="button primary wide" href="<?= $skillName === 'reading' ? '/student/self_learning.php' : '/student/skill.php?skill=' . rawurlencode($skillName) ?>">Continue Learning</a>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </section>
        </div>

        <!-- TAB: LIVE CLASSROOM QUIZ -->
        <div id="tab-quiz" class="tab-panel">
            <section class="grid two" style="margin-top: 0;">
                <article class="card">
                    <span class="eyebrow">Live Quiz Lobby</span>
                    <h2>Status Sesi</h2>
                    <p class="muted"><?= $quiz ? 'Sesi kuis live aktif terdeteksi. Silakan bergabung untuk bermain bersama teman sekelas Anda.' : 'Saat ini tidak ada sesi kuis live yang sedang berjalan dari Teacher Anda.' ?></p>
                    <div style="margin-top: 20px;">
                        <?php if ($quiz): ?>
                            <a class="button gold wide" href="/student/quiz_join.php?id=<?= (int)$quiz['id'] ?>" style="text-align: center; display: block; font-weight: bold;">Join Live Quiz Now</a>
                        <?php else: ?>
                            <span class="badge dev" style="font-size: 1rem; display: block; text-align: center; padding: 12px;">Classroom is Offline</span>
                        <?php endif; ?>
                    </div>
                </article>

                <article class="card">
                    <h2>Live Quiz History</h2>
                    <?php if (!$leaders): ?>
                        <div class="empty">Belum ada riwayat Live Quiz di kelas ini.</div>
                    <?php else: ?>
                        <p class="muted">Leaderboard teratas dari kuis kelas sebelumnya:</p>
                        <ol class="leaderboard" style="padding-left: 0; list-style: none;">
                            <?php foreach ($leaders as $idx => $lead): 
                                $leadAvatar = $lead['avatar'] ?: 'a.jpg';
                                if (!preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $leadAvatar)) {
                                    $leadAvatar = 'a.jpg';
                                }
                            ?>
                                <li style="display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.05);">
                                    <span style="display: flex; align-items: center; gap: 8px;">
                                        <b>#<?= $idx + 1 ?></b> 
                                        <img src="/assets/images/avatars/<?= htmlspecialchars($leadAvatar) ?>" alt="Avatar" width="28" height="28" style="border-radius: 50%; object-fit: cover; background: #2b2b36; border: 1px solid rgba(255,255,255,0.1); vertical-align: middle;">
                                        <?= student_h($lead['display_name'] ?? null, 'Joined Student') ?>
                                    </span>
                                    <b><?= (int)$lead['total_score'] ?> pts</b>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </article>
            </section>
        </div>

        <!-- TAB: LEADERBOARD -->
        <div id="tab-leaderboard" class="tab-panel">
            <section class="card" style="margin-top: 0;">
                <span class="eyebrow">Leaderboard Kelas</span>
                <h2>Top Students</h2>
                <p class="muted">Peringkat akumulatif keaktifan belajar siswa di kelas ini.</p>
                <?php
                $stmt = $pdo->prepare("SELECT display_name, avatar, last_seen_at FROM classroom_members WHERE classroom_id=? AND membership_status='active' ORDER BY last_seen_at DESC LIMIT 15");
                $stmt->execute([$classroomId]);
                $classMembers = $stmt->fetchAll();
                if (!$classMembers):
                ?>
                    <div class="empty">Belum ada siswa aktif.</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Avatar</th>
                                <th>Last Seen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($classMembers as $idx => $m): 
                                $memberAvatar = $m['avatar'] ?: 'a.jpg';
                                if (!preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $memberAvatar)) {
                                    $memberAvatar = 'a.jpg';
                                }
                            ?>
                                <tr>
                                    <td><b>#<?= $idx + 1 ?></b> <?= student_h($m['display_name'] ?? null, 'Joined Student') ?></td>
                                    <td>
                                        <img src="/assets/images/avatars/<?= htmlspecialchars($memberAvatar) ?>" alt="Avatar" width="36" height="36" style="border-radius: 50%; background: #2b2b36; border: 1px solid rgba(255,255,255,0.2); object-fit: cover; vertical-align: middle;">
                                    </td>
                                    <td><?= student_h($m['last_seen_at'] ?? null, '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <!-- Detail Modal -->
    <div id="detail-modal" class="modal">
        <div class="modal-content card">
            <button type="button" class="close" id="close-modal" aria-label="Close">&times;</button>
            <span class="eyebrow" id="modal-eyebrow">Detail Latihan Mandiri</span>
            <h2 id="modal-title" style="margin: 4px 0 20px 0;">Skill &amp; Level</h2>
            <div id="modal-body" class="modal-body">
                <div class="empty">Loading...</div>
            </div>
        </div>
    </div>

    <script src="/assets/js/visual-effects.js" defer></script>
    <script>
        function switchStudentTab(tabId, event) {
            if (event) {
                event.preventDefault();
            }
            // Remove active class from all links
            document.querySelectorAll('.tabs a').forEach(el => el.classList.remove('active'));
            // Hide all tab panels
            document.querySelectorAll('.tab-panel').forEach(el => el.classList.remove('active'));
            
            // Add active to current link
            const targetLink = document.querySelector(`.tabs a[href="#${tabId}"]`);
            if (targetLink) {
                targetLink.classList.add('active');
            }
            // Show target panel
            const targetPanel = document.getElementById(`tab-${tabId}`);
            if (targetPanel) {
                targetPanel.classList.add('active');
            }
            // Store in localStorage
            localStorage.setItem('student-active-tab', tabId);
        }

        document.addEventListener('DOMContentLoaded', () => {
            // Intercept clicks on links
            document.querySelectorAll('.tabs a').forEach(el => {
                const href = el.getAttribute('href');
                if (href.startsWith('#')) {
                    const tabId = href.substring(1);
                    el.addEventListener('click', (e) => {
                        switchStudentTab(tabId, e);
                    });
                }
            });

            // Restore active tab
            let activeTab = localStorage.getItem('student-active-tab') || 'overview';
            if (window.location.hash) {
                const hashTab = window.location.hash.substring(1);
                if (['overview', 'learning', 'quiz', 'leaderboard'].includes(hashTab)) {
                    activeTab = hashTab;
                }
            }
            switchStudentTab(activeTab);

            // Modal Logic
            const modal = document.getElementById("detail-modal");
            const closeModal = document.getElementById("close-modal");
            const modalTitle = document.getElementById("modal-title");
            const modalBody = document.getElementById("modal-body");

            if (modal && closeModal) {
                closeModal.addEventListener("click", () => {
                    modal.style.display = "none";
                });

                window.addEventListener("click", (e) => {
                    if (e.target === modal) {
                        modal.style.display = "none";
                    }
                });
            }

            function el(tag, attrs = {}, children = []) {
                const element = document.createElement(tag);
                for (const [key, val] of Object.entries(attrs)) {
                    if (key === 'style' && typeof val === 'object') {
                        Object.assign(element.style, val);
                    } else if (key === 'className') {
                        element.className = val;
                    } else if (key.startsWith('data-')) {
                        element.setAttribute(key, val);
                    } else {
                        element[key] = val;
                    }
                }
                children.forEach(child => {
                    if (typeof child === 'string' || typeof child === 'number') {
                        element.appendChild(document.createTextNode(String(child)));
                    } else if (child instanceof HTMLElement) {
                        element.appendChild(child);
                    }
                });
                return element;
            }

            document.querySelectorAll(".btn-view-history-detail").forEach(card => {
                card.addEventListener("click", function() {
                    const type = this.dataset.type;
                    const readingSessionId = this.dataset.readingSessionId;
                    const listeningSessionId = this.dataset.listeningSessionId;
                    const speakingSessionId = this.dataset.speakingSessionId;
                    const writingSessionId = this.dataset.writingSessionId;
                    const titleText = this.dataset.title;

                    modalTitle.textContent = titleText || "Detail Latihan";
                    modalBody.replaceChildren(
                        el('div', { style: { textAlign: 'center', padding: '40px 0' } }, [
                            el('span', { style: { display: 'inline-block', width: '30px', height: '30px', border: '3px solid rgba(255,255,255,0.1)', borderTopColor: '#3b82f6', borderRadius: '50%', animation: 'spin 1s linear infinite', marginBottom: '10px' } }),
                            el('p', { className: 'muted' }, ['Loading details...'])
                        ])
                    );
                    modal.style.display = "block";

                    let url = '';
                    if (type === 'reading_session' && readingSessionId) {
                        url = `/student/progress_detail.php?reading_session_id=${readingSessionId}`;
                    } else if (type === 'listening_session' && listeningSessionId) {
                        url = `/student/progress_detail.php?listening_session_id=${listeningSessionId}`;
                    } else if (type === 'speaking_session' && speakingSessionId) {
                        url = `/student/progress_detail.php?speaking_session_id=${speakingSessionId}`;
                    } else if (type === 'writing_session' && writingSessionId) {
                        url = `/student/progress_detail.php?writing_session_id=${writingSessionId}`;
                    } else {
                        modalBody.replaceChildren(el('div', { className: 'empty' }, ['Tidak ada detail pengerjaan yang tersedia.']));
                        return;
                    }

                    fetch(url)
                        .then(res => {
                            if (!res.ok) throw new Error("Gagal mengambil data.");
                            return res.json();
                        })
                        .then(data => {
                            if (!data.success || !data.attempts || data.attempts.length === 0) {
                                modalBody.replaceChildren(el('div', { className: 'empty' }, ['Tidak ada detail pengerjaan yang tersedia.']));
                                return;
                            }

                            const fragment = document.createDocumentFragment();
                            data.attempts.forEach((a, index) => {
                                const scoreColor = a.score >= 70 ? '#34d399' : '#f87171';
                                const isObjective = ['reading', 'listening'].includes(a.skill);
                                
                                const itemEl = el('div', { className: 'attempt-detail-item' }, [
                                    el('div', { className: 'detail-header' }, [
                                        el('h4', { className: 'detail-title' }, [`${a.title || 'Latihan'}`]),
                                        el('div', { className: 'detail-badge-group' }, [
                                            el('span', { className: 'badge', style: { color: scoreColor, borderColor: scoreColor + '50', background: scoreColor + '08', fontWeight: 'bold', fontSize: '0.85rem' } }, [`Score: ${a.score}/100`])
                                        ])
                                    ])
                                ]);

                                if (a.instruction) {
                                    itemEl.appendChild(el('p', { className: 'muted', style: { fontSize: '0.85rem', margin: '-4px 0 10px 0' } }, [
                                        el('i', {}, [`Instruction: ${a.instruction}`])
                                    ]));
                                }

                                function addCollapsible(titleText, contentText) {
                                    const btn = el('button', { type: 'button', className: 'collapsible-trigger' }, [titleText]);
                                    const content = el('div', { className: 'collapsible-content' });
                                    
                                    contentText.split('\n').forEach(line => {
                                        content.appendChild(el('p', {}, [line]));
                                    });

                                    btn.addEventListener('click', function() {
                                        if (content.style.display === 'block') {
                                            content.style.display = 'none';
                                            btn.textContent = titleText.replace("Hide", "Show");
                                        } else {
                                            content.style.display = 'block';
                                            btn.textContent = titleText.replace("Show", "Hide");
                                        }
                                    });
                                    itemEl.appendChild(btn);
                                    itemEl.appendChild(content);
                                }

                                const passage = a.question_data.passage;
                                if (passage) {
                                    addCollapsible('📖 Show Passage / Context', passage);
                                }
                                const transcript = a.question_data.transcript;
                                if (a.skill === 'listening' && transcript) {
                                    addCollapsible('🎧 Show Listening Script / Transcript', transcript);
                                }
                                const scenario = a.question_data.scenario;
                                if (a.skill === 'speaking' && scenario) {
                                    addCollapsible('🎤 Show Speaking Scenario', scenario);
                                }
                                const context = a.question_data.context;
                                if (a.skill === 'writing' && context) {
                                    addCollapsible('✍️ Show Writing Context', context);
                                }

                                if (a.question_data.question) {
                                    itemEl.appendChild(el('div', { className: 'detail-section' }, [
                                        el('div', { className: 'detail-section-title' }, ['Question / Prompt']),
                                        el('p', { style: { fontWeight: '600', margin: '4px 0 0 0', color: '#fff', fontSize: '0.95rem' } }, [a.question_data.question])
                                    ]));
                                }

                                if (isObjective && a.question_data.options && a.answer_json) {
                                    const selectedLetter = a.answer_json.selected;
                                    const correctLetter = a.answer_json.correct_answer;
                                    
                                    const optionChildren = [];
                                    a.question_data.options.forEach((optText, optIdx) => {
                                        const letter = String.fromCharCode(65 + optIdx);
                                        let optClass = '';
                                        if (letter === correctLetter) {
                                            optClass = 'correct';
                                        } else if (letter === selectedLetter) {
                                            optClass = 'incorrect';
                                        }
                                        
                                        const labelChilds = [optText];
                                        if (letter === selectedLetter) {
                                            labelChilds.push(el('small', { style: { marginLeft: 'auto', fontWeight: 'bold' } }, [' (Your Answer)']));
                                        }
                                        if (letter === correctLetter && letter !== selectedLetter) {
                                            labelChilds.push(el('small', { style: { marginLeft: 'auto', fontWeight: 'bold' } }, [' (Correct Answer)']));
                                        }

                                        optionChildren.push(el('div', { className: `option-item ${optClass}` }, [
                                            el('span', { className: 'option-letter' }, [letter]),
                                            el('span', {}, labelChilds)
                                        ]));
                                    });

                                    const optSection = el('div', { className: 'detail-section' }, [
                                        el('div', { className: 'detail-section-title' }, ['Answers']),
                                        el('div', { className: 'option-list' }, optionChildren)
                                    ]);

                                    if (a.question_data.explanation) {
                                        optSection.appendChild(el('div', { className: 'explanation-box' }, [
                                            el('b', {}, ['Explanation: ']),
                                            a.question_data.explanation
                                        ]));
                                    }
                                    itemEl.appendChild(optSection);
                                }

                                if (!isObjective) {
                                    const submissionText = a.skill === 'speaking' ? a.transcript : a.writing_submission;
                                    
                                    const subContent = el('div', { style: { background: 'rgba(255,255,255,0.02)', border: '1px solid rgba(255,255,255,0.06)', padding: '12px 16px', borderRadius: '10px', fontSize: '0.9rem', lineHeight: '1.5', color: '#fff' } });
                                    (submissionText || '').split('\n').forEach(line => {
                                        subContent.appendChild(el('span', {}, [line]));
                                        subContent.appendChild(el('br'));
                                    });

                                    itemEl.appendChild(el('div', { className: 'detail-section' }, [
                                        el('div', { className: 'detail-section-title' }, ['Your Submission']),
                                        subContent
                                    ]));

                                    if (a.criteria_json && a.criteria_json.length > 0) {
                                        const critList = [];
                                        a.criteria_json.forEach(criterion => {
                                            critList.push(el('div', { style: { background: 'rgba(255,255,255,0.01)', border: '1px solid rgba(255,255,255,0.04)', padding: '10px 14px', borderRadius: '8px' } }, [
                                                el('div', { style: { display: 'flex', justifyContent: 'space-between', marginBottom: '6px' } }, [
                                                    el('b', { style: { fontSize: '0.85rem', color: '#fff' } }, [criterion.name.replace(/_/g, ' ').toUpperCase()]),
                                                    el('span', { style: { fontSize: '0.85rem', fontWeight: 'bold', color: '#fbbf24' } }, [`${criterion.score}/${criterion.max || 100}`])
                                                ]),
                                                el('div', { style: { background: 'rgba(255,255,255,0.05)', height: '4px', borderRadius: '2px', marginBottom: '6px' } }, [
                                                    el('div', { style: { background: '#fbbf24', height: '100%', borderRadius: '2px', width: `${Math.max(0, Math.min(100, (criterion.score / (criterion.max || 100)) * 100))}%` } })
                                                ]),
                                                el('small', { style: { fontSize: '0.75rem', color: 'var(--muted)', display: 'block', lineHeight: '1.3' } }, [criterion.feedback])
                                            ]));
                                        });
                                        itemEl.appendChild(el('div', { className: 'detail-section' }, [
                                            el('div', { className: 'detail-section-title' }, ['AI Evaluation Breakdown']),
                                            el('div', { style: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: '12px', marginTop: '10px' } }, critList)
                                        ]));
                                    }

                                    if (a.feedback) {
                                        itemEl.appendChild(el('div', { className: 'detail-section' }, [
                                            el('div', { className: 'detail-section-title' }, ['Overall AI Feedback']),
                                            el('p', { className: 'muted', style: { margin: '4px 0 0 0', fontSize: '0.9rem', lineHeight: '1.5' } }, [a.feedback])
                                        ]));
                                    }

                                    if ((a.strengths_json && a.strengths_json.length > 0) || (a.improvements_json && a.improvements_json.length > 0)) {
                                        const strongList = [];
                                        const improveList = [];
                                        if (a.strengths_json && a.strengths_json.length > 0) {
                                            const items = a.strengths_json.map(s => el('li', {}, [s]));
                                            strongList.push(el('div', {}, [
                                                el('div', { className: 'detail-section-title', style: { color: '#34d399' } }, ['Strengths']),
                                                el('ul', { style: { margin: '6px 0 0 0', paddingLeft: '20px', fontSize: '0.85rem', color: '#cbd5e1', lineHeight: '1.4' } }, items)
                                            ]));
                                        }
                                        if (a.improvements_json && a.improvements_json.length > 0) {
                                            const items = a.improvements_json.map(s => el('li', {}, [s]));
                                            improveList.push(el('div', {}, [
                                                el('div', { className: 'detail-section-title', style: { color: '#f87171' } }, ['Improvements']),
                                                el('ul', { style: { margin: '6px 0 0 0', paddingLeft: '20px', fontSize: '0.85rem', color: '#cbd5e1', lineHeight: '1.4' } }, items)
                                            ]));
                                        }
                                        itemEl.appendChild(el('div', { className: 'detail-section', style: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: '14px' } }, [
                                            ...strongList,
                                            ...improveList
                                        ]));
                                    }

                                    if (a.suggested_revision) {
                                        const revBox = el('div', { style: { background: 'rgba(251,191,36,0.03)', border: '1px dashed rgba(251,191,36,0.2)', padding: '12px 16px', borderRadius: '10px', fontSize: '0.9rem', lineHeight: '1.5', color: '#fcd34d' } });
                                        a.suggested_revision.split('\n').forEach(line => {
                                            revBox.appendChild(el('span', {}, [line]));
                                            revBox.appendChild(el('br'));
                                        });
                                        itemEl.appendChild(el('div', { className: 'detail-section' }, [
                                            el('div', { className: 'detail-section-title', style: { color: '#fbbf24' } }, ['Suggested Revision']),
                                            revBox
                                        ]));
                                    }

                                    if (a.example_answer) {
                                        const exBox = el('div', { style: { background: 'rgba(255,255,255,0.02)', border: '1px solid rgba(255,255,255,0.06)', padding: '12px 16px', borderRadius: '10px', fontSize: '0.9rem', lineHeight: '1.5', color: '#cbd5e1' } });
                                        a.example_answer.split('\n').forEach(line => {
                                            exBox.appendChild(el('span', {}, [line]));
                                            exBox.appendChild(el('br'));
                                        });
                                        itemEl.appendChild(el('div', { className: 'detail-section' }, [
                                            el('div', { className: 'detail-section-title' }, ['Model Answer / Example Response']),
                                            exBox
                                        ]));
                                    }
                                }

                                fragment.appendChild(itemEl);
                            });
                            modalBody.replaceChildren(fragment);
                        })
                        .catch(err => {
                            modalBody.replaceChildren(el('div', { className: 'alert error' }, [err.message]));
                        });
                });
            });
        });
    </script>
</body>
</html>
