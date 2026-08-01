<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../vendor/autoload.php';

use EnglAI\Mvp\StudentSession;

$pdo = db();
$member = StudentSession::requireMember($pdo);
$classroomId = (int)$member['classroom_id'];
$avatarFile = $member['avatar'] ?: 'a.jpg';
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
$stmt = $pdo->prepare("SELECT skill, COUNT(*) completed, COALESCE(AVG(score),0) average_score, MAX(score) latest_score FROM learning_attempts a JOIN learning_activities l ON l.id=a.activity_id WHERE a.member_id=? AND a.status='completed' GROUP BY skill");
$stmt->execute([(int)$member['id']]);
$phase2Progress = array_column($stmt->fetchAll(), null, 'skill');

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

// Fetch up to 10 recent activities from attempts
$stmt = $pdo->prepare("SELECT 'activity' as type, a.score, a.completed_at, l.title, l.skill, l.level FROM learning_attempts a JOIN learning_activities l ON l.id=a.activity_id WHERE a.member_id=? AND a.status='completed' ORDER BY a.completed_at DESC LIMIT 10");
$stmt->execute([(int)$member['id']]);
$hist1 = $stmt->fetchAll();

// Fetch up to 10 recent practice sessions
$stmt = $pdo->prepare("SELECT 'practice' as type, score, completed_at, 'Practice Quiz' as title, 'general' as skill, 'all' as level FROM student_learning_sessions WHERE member_id=? AND status='completed' ORDER BY completed_at DESC LIMIT 10");
$stmt->execute([(int)$member['id']]);
$hist2 = $stmt->fetchAll();

// Combine and sort history logs
$history = array_merge($hist1, $hist2);
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
    </style>
</head>
<body>
    <div class="stars" aria-hidden="true"></div>
    <header class="nav">
        <a class="brand" href="/student/dashboard.php"><span class="brand-mark">E</span>EnglAI</a>
        <div class="row">
            <span class="badge available"><?= htmlspecialchars($member['display_name']) ?></span>
            <a class="button secondary" href="/student/logout.php">Leave Classroom</a>
        </div>
    </header>
    <main class="shell">
        <nav class="breadcrumb">
            <a href="/student/account.php">Workspace</a>
            <span>›</span>
            <strong><?= htmlspecialchars($member['classroom_name']) ?></strong>
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
                        <span class="code"><?= htmlspecialchars($member['classroom_code']) ?></span>
                        <h1><?= htmlspecialchars($member['classroom_name']) ?></h1>
                        <p class="muted">Student: <strong><?= htmlspecialchars($member['display_name']) ?></strong> · Teacher: <?= htmlspecialchars($teacherName) ?> · <?= htmlspecialchars((string)$lesson) ?></p>
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
                    <div style="overflow-x: auto; margin-top: 15px;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr>
                                    <th style="text-align: left; padding: 10px; border-bottom: 2px solid rgba(255,255,255,0.1);">Modul / Aktivitas</th>
                                    <th style="text-align: left; padding: 10px; border-bottom: 2px solid rgba(255,255,255,0.1);">Skill</th>
                                    <th style="text-align: left; padding: 10px; border-bottom: 2px solid rgba(255,255,255,0.1);">Level</th>
                                    <th style="text-align: center; padding: 10px; border-bottom: 2px solid rgba(255,255,255,0.1);">Skor</th>
                                    <th style="text-align: left; padding: 10px; border-bottom: 2px solid rgba(255,255,255,0.1);">Tanggal Selesai</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($history as $h): ?>
                                    <tr>
                                        <td style="padding: 12px 10px; border-bottom: 1px solid rgba(255,255,255,0.05); font-weight: 500;">
                                            <?= $h['type'] === 'practice' ? 'Self Learning Practice' : htmlspecialchars($h['title']) ?>
                                        </td>
                                        <td style="padding: 12px 10px; border-bottom: 1px solid rgba(255,255,255,0.05);">
                                            <span class="badge" style="background: rgba(255,255,255,0.05);"><?= ucfirst(htmlspecialchars($h['skill'])) ?></span>
                                        </td>
                                        <td style="padding: 12px 10px; border-bottom: 1px solid rgba(255,255,255,0.05); text-transform: capitalize;">
                                            <?= htmlspecialchars($h['level']) ?>
                                        </td>
                                        <td style="padding: 12px 10px; border-bottom: 1px solid rgba(255,255,255,0.05); text-align: center; font-weight: bold; color: <?= $h['score'] >= 80 ? '#10b981' : ($h['score'] >= 50 ? '#3b82f6' : '#ef4444') ?>;">
                                            <?= (int)$h['score'] ?>/100
                                        </td>
                                        <td style="padding: 12px 10px; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 0.85rem; color: rgba(255,255,255,0.5);">
                                            <?= htmlspecialchars($h['completed_at']) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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
                    $skillProgress = $phase2Progress[$skillName] ?? null;
                    $done = (int)($skillProgress['completed'] ?? 0);
                    $status = !$availableLevels ? 'Not Generated' : ($done > 0 ? 'In Progress' : 'Available');
                ?>
                    <article class="card <?= $availableLevels ? 'hover' : '' ?>" id="<?= $skillName ?>">
                        <span class="badge <?= $availableLevels ? 'available' : 'dev' ?>"><?= $status ?></span>
                        <div class="skill-icon"><?= $skillData[0] ?></div>
                        <h3><?= ucfirst($skillName) ?></h3>
                        <p class="muted"><?= count($availableLevels) ?> level tersedia · <?= number_format((float)($skillProgress['average_score'] ?? 0), 0) ?>% average</p>
                        <?php if ($availableLevels): ?>
                            <a class="button primary wide" href="/student/skill.php?skill=<?= $skillName ?>">Continue Learning</a>
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
                                        <?= htmlspecialchars($lead['display_name']) ?>
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
                                    <td><b>#<?= $idx + 1 ?></b> <?= htmlspecialchars($m['display_name'] ?: 'Joined Student') ?></td>
                                    <td>
                                        <img src="/assets/images/avatars/<?= htmlspecialchars($memberAvatar) ?>" alt="Avatar" width="36" height="36" style="border-radius: 50%; background: #2b2b36; border: 1px solid rgba(255,255,255,0.2); object-fit: cover; vertical-align: middle;">
                                    </td>
                                    <td><?= htmlspecialchars($m['last_seen_at'] ?: '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        </div>
    </main>

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
        });
    </script>
</body>
</html>
