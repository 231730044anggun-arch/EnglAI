<?php
declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

use EnglAI\Mvp\ClassroomService;
use EnglAI\Security\Csrf;

function classroom_h(mixed $value, string $fallback = ''): string {
    $text = trim((string)($value ?? ''));
    return htmlspecialchars($text !== '' ? $text : $fallback, ENT_QUOTES, 'UTF-8');
}

require_admin();
$teacher = (string)($_SESSION['admin_username'] ?? env_value('ADMIN_USERNAME', 'admin'));

try {
    $classroom = (new ClassroomService(db()))->requireOwned((int)($_GET['id'] ?? 0), $teacher);
} catch (Throwable $e) {
    http_response_code(404);
    exit('Classroom tidak ditemukan.');
}

$id = (int)$classroom['id'];
$message = trim((string)($_GET['message'] ?? ''));

// Fetch active lesson plan
$stmt = db()->prepare('SELECT * FROM classroom_lesson_plans WHERE classroom_id=? AND is_active=1 ORDER BY version DESC LIMIT 1');
$stmt->execute([$id]);
$rpp = $stmt->fetch();

// Fetch content question banks counts
$stmt = db()->prepare('SELECT content_type, source, COUNT(*) total, MAX(created_at) generated_at FROM content_questions WHERE classroom_id=? GROUP BY content_type, source');
$stmt->execute([$id]);
$bankRows = $stmt->fetchAll();
$banks = [];
$source = '—';
$generatedAt = null;
foreach ($bankRows as $row) {
    $banks[$row['content_type']] = ($banks[$row['content_type']] ?? 0) + (int)$row['total'];
    $source = $row['source'];
    $generatedAt = $row['generated_at'];
}

// Fetch active/pending classroom members (filter out removed)
$stmt = db()->prepare("SELECT id, display_name, avatar, membership_status, last_seen_at, created_at FROM classroom_members WHERE classroom_id=? AND membership_status != 'removed' ORDER BY created_at DESC LIMIT 30");
$stmt->execute([$id]);
$members = $stmt->fetchAll();

// Fetch quiz sessions
$stmt = db()->prepare('SELECT id, state, question_count, difficulty, created_at FROM quiz_sessions WHERE classroom_id=? ORDER BY id DESC LIMIT 12');
$stmt->execute([$id]);
$quizzes = $stmt->fetchAll();

$analysis = [
    'topic' => $rpp ? pathinfo($rpp['original_name'], PATHINFO_FILENAME) : 'Belum tersedia',
    'objectives' => 'Menunggu RPP classroom',
    'vocabulary' => '—',
    'grammar' => 'Teridentifikasi saat content generation',
    'level' => 'Intermediate'
];

if ($rpp) {
    preg_match_all('/\b[A-Za-z]{5,}\b/u', (string)$rpp['extracted_text'], $m);
    $words = array_values(array_unique(array_map('strtolower', $m[0] ?? [])));
    $words = array_slice(array_filter($words, fn($w) => !in_array($w, ['teacher', 'student', 'learning', 'bahasa', 'inggris'], true)), 0, 6);
    $analysis['objectives'] = 'Teks berhasil diekstrak · ' . number_format(mb_strlen($rpp['extracted_text'])) . ' karakter';
    $analysis['vocabulary'] = $words ? implode(', ', $words) : 'Menunggu analisis vocabulary';
}

$ready = (int)($banks['self_learning'] ?? 0) >= 20 && (int)($banks['live_quiz'] ?? 0) >= 20;

// Fetch AI RPP analysis recommendations
$stmt = db()->prepare("SELECT * FROM ai_analyses WHERE classroom_id=? AND status='valid' ORDER BY id DESC LIMIT 1");
$stmt->execute([$id]);
$storedAnalysis = $stmt->fetch();
if ($storedAnalysis) {
    $analysis = [
        'topic' => $storedAnalysis['topic'],
        'objectives' => implode(' · ', json_decode($storedAnalysis['learning_objectives_json'], true) ?: []),
        'vocabulary' => implode(', ', json_decode($storedAnalysis['vocabulary_json'], true) ?: []),
        'grammar' => implode(', ', json_decode($storedAnalysis['grammar_json'], true) ?: []),
        'level' => ucfirst($storedAnalysis['recommended_level'])
    ];
}

// Fetch self learning module matrix
$stmt = db()->prepare("SELECT skill, level, COUNT(*) total, MAX(source) source FROM learning_activities WHERE classroom_id=? AND status='ready' GROUP BY skill, level");
$stmt->execute([$id]);
$learningMatrix = [];
foreach ($stmt->fetchAll() as $row) {
    $learningMatrix[$row['skill']][$row['level']] = $row;
}
$readingBankStatus = [];
if ($rpp) {
    $stmt = db()->prepare("SELECT level, SUM(status='ready') gemini_count, SUM(status='ready' AND JSON_UNQUOTE(JSON_EXTRACT(content_json,'$.source'))='local_fallback') fallback_count, SUM(status='archived') archived_count, MAX(CASE WHEN status='ready' THEN created_at END) last_generated FROM learning_activities WHERE classroom_id=? AND lesson_plan_id=? AND skill='reading' AND activity_type='standalone_question' GROUP BY level");
    $stmt->execute([$id, $rpp['id']]);
    foreach ($stmt->fetchAll() as $row) $readingBankStatus[strtolower((string)$row['level'])] = $row;
}

// Fetch stats of self learning progress
$stmt = db()->prepare("SELECT l.skill, COUNT(a.id) attempts, COALESCE(AVG(a.score), 0) average_score FROM learning_activities l LEFT JOIN learning_attempts a ON a.activity_id=l.id AND a.status='completed' WHERE l.classroom_id=? GROUP BY l.skill");
$stmt->execute([$id]);
$skillStats = $stmt->fetchAll();

$classroomSkillStats = [];
foreach ($skillStats as $st) {
    $classroomSkillStats[$st['skill']] = $st;
}

// Fetch recent student activities from attempts
$stmt = db()->prepare("
    SELECT a.score, a.completed_at, l.title, l.skill, l.level, m.display_name, m.avatar 
    FROM learning_attempts a 
    JOIN learning_activities l ON l.id = a.activity_id 
    JOIN classroom_members m ON m.id = a.member_id 
    WHERE a.classroom_id = ? AND a.status = 'completed' 
    ORDER BY a.completed_at DESC LIMIT 10
");
$stmt->execute([$id]);
$recentAttempts = $stmt->fetchAll();

// Fetch recent student general practices
$stmt = db()->prepare("
    SELECT s.score, s.completed_at, 'Practice Quiz' as title, 'general' as skill, 'all' as level, m.display_name, m.avatar 
    FROM student_learning_sessions s 
    JOIN classroom_members m ON m.id = s.member_id 
    WHERE s.classroom_id = ? AND s.status = 'completed' 
    ORDER BY s.completed_at DESC LIMIT 10
");
$stmt->execute([$id]);
$recentPractices = $stmt->fetchAll();

// Combine and sort recent student activities
$recentActivity = array_merge($recentAttempts, $recentPractices);
usort($recentActivity, function($a, $b) {
    return strcmp((string)($b['completed_at'] ?? ''), (string)($a['completed_at'] ?? ''));
});
$recentActivity = array_slice($recentActivity, 0, 10);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= classroom_h($classroom['name'] ?? null, 'Classroom') ?> · EnglAI</title>
    <link rel="stylesheet" href="/assets/css/mvp.css">
    <style>
        .tab-panel {
            display: none;
        }
        .tab-panel.active {
            display: block;
        }
    </style>
</head>
<body>
    <div class="stars" aria-hidden="true"></div>
    <header class="nav">
        <a class="brand" href="/admin/"><span class="brand-mark">E</span>EnglAI</a>
        <a class="button secondary" href="/admin/">← Teacher Dashboard</a>
    </header>
    <main class="shell">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="/admin/">Teacher</a>
            <span>›</span>
            <strong><?= classroom_h($classroom['name'] ?? null, 'Classroom') ?></strong>
        </nav>

        <section class="card dashboard-hero">
            <div class="toolbar">
                <div>
                    <span class="code"><?= classroom_h($classroom['code'] ?? null, '-') ?></span>
                    <h1><?= classroom_h($classroom['name'] ?? null, 'Classroom') ?></h1>
                    <p class="muted"><?= classroom_h($rpp['original_name'] ?? null, 'Belum ada lesson plan') ?></p>
                </div>
                <div class="row">
                    <span class="badge <?= $ready ? 'available' : 'dev' ?>"><?= $ready ? 'Content Ready' : 'Setup Required' ?></span>
                    <button class="button secondary" data-copy="<?= classroom_h($classroom['code'] ?? null) ?>">Copy Classroom ID</button>
                </div>
            </div>
        </section>

        <?php if ($message): ?>
            <div class="alert ok" role="status"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <nav class="tabs glass" aria-label="Classroom sections">
            <a class="active" href="#overview">Overview</a>
            <a href="#lesson-plan">Lesson Plan</a>
            <a href="#self-learning">Self Learning</a>
            <a href="#live-quiz">Live Quiz</a>
            <a href="#students">Students</a>
            <a href="/admin/analytics.php?classroom_id=<?= $id ?>">Analytics</a>
            <a href="/admin/settings.php?classroom_id=<?= $id ?>">Settings</a>
        </nav>

        <!-- TAB: OVERVIEW -->
        <div id="tab-overview" class="tab-panel active">
            <section class="grid four" style="margin-top: 0;">
                <article class="card metric">
                    <div class="icon-box">👥</div>
                    <div>
                        <div class="stat"><?= count($members) ?></div>
                        <span class="muted">Students</span>
                    </div>
                </article>
                <article class="card metric">
                    <div class="icon-box">📚</div>
                    <div>
                        <div class="stat"><?= (int)($banks['self_learning'] ?? 0) ?></div>
                        <span class="muted">Self Learning</span>
                    </div>
                </article>
                <article class="card metric">
                    <div class="icon-box">🏆</div>
                    <div>
                        <div class="stat"><?= (int)($banks['live_quiz'] ?? 0) ?></div>
                        <span class="muted">Live Quiz Bank</span>
                    </div>
                </article>
                <article class="card metric">
                    <div class="icon-box">🎮</div>
                    <div>
                        <div class="stat"><?= count($quizzes) ?></div>
                        <span class="muted">Quiz Sessions</span>
                    </div>
                </article>
            </section>

            <section class="grid two" style="margin-top: 22px;">
                <article class="card">
                    <h2>Classroom Skill Proficiency</h2>
                    <p class="muted">Rata-rata akurasi nilai siswa di kelas ini untuk masing-masing skill:</p>
                    <div style="display: flex; flex-direction: column; gap: 16px; margin-top: 20px;">
                        <?php 
                        $skillIcons = ['reading' => '📖', 'listening' => '🎧', 'speaking' => '🎙️', 'writing' => '✍️'];
                        $skillsToTest = ['reading', 'listening', 'speaking', 'writing'];
                        foreach ($skillsToTest as $sk): 
                            $prog = $classroomSkillStats[$sk] ?? null;
                            $score = $prog ? (float)$prog['average_score'] : 0.0;
                            $attempts = $prog ? (int)$prog['attempts'] : 0;
                        ?>
                            <div>
                                <div style="display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 0.9rem;">
                                    <span style="font-weight: bold;"><?= $skillIcons[$sk] ?> <?= ucfirst($sk) ?> <span class="muted" style="font-weight: normal; font-size: 0.8rem;">(<?= $attempts ?> pengerjaan)</span></span>
                                    <b style="color: <?= $score >= 80 ? '#10b981' : ($score >= 50 ? '#3b82f6' : '#ef4444') ?>"><?= number_format($score, 1) ?>%</b>
                                </div>
                                <div class="progress-track" style="margin: 0; height: 10px; background: rgba(255,255,255,0.06); border-radius: 99px;">
                                    <div class="progress-fill" style="width: <?= $score ?>%; height: 100%; border-radius: 99px; background: <?= $score >= 80 ? 'linear-gradient(90deg, #10b981, #34d399)' : ($score >= 50 ? 'linear-gradient(90deg, #3b82f6, #60a5fa)' : 'linear-gradient(90deg, #ef4444, #f87171)') ?>;"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>

                <article class="card">
                    <h2>Recent Student Activities</h2>
                    <p class="muted">10 aktivitas belajar terakhir yang diselesaikan oleh siswa:</p>
                    <?php if (empty($recentActivity)): ?>
                        <div class="empty" style="margin-top: 15px;">Belum ada aktivitas siswa yang selesai.</div>
                    <?php else: ?>
                        <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 15px;">
                            <?php foreach ($recentActivity as $act): 
                                $studAvatar = $act['avatar'] ?: 'a.jpg';
                                if (!preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $studAvatar)) {
                                    $studAvatar = 'a.jpg';
                                }
                                $score = (int)$act['score'];
                                $scoreColor = $score >= 80 ? '#10b981' : ($score >= 50 ? '#3b82f6' : '#ef4444');
                                $timeStr = '';
                                if (!empty($act['completed_at'])) {
                                    $dt = new DateTime($act['completed_at']);
                                    $timeStr = $dt->format('H:i · d M');
                                }
                            ?>
                                <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; gap: 12px;">
                                    <div style="display: flex; align-items: center; gap: 10px; min-width: 0; flex: 1;">
                                        <img src="/assets/images/avatars/<?= htmlspecialchars($studAvatar) ?>" alt="Avatar" style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover; flex-shrink: 0; border: 1px solid rgba(255,255,255,0.1);">
                                        <div style="min-width: 0; line-height: 1.3;">
                                            <div style="font-weight: bold; font-size: 0.9rem; color: #fff; text-overflow: ellipsis; overflow: hidden; white-space: nowrap;">
                                                <?= classroom_h($act['display_name'] ?? null, 'Joined Student') ?>
                                            </div>
                                            <div style="font-size: 0.75rem; color: var(--muted); text-overflow: ellipsis; overflow: hidden; white-space: nowrap;">
                                                <?= ($act['title'] ?? '') === 'Practice Quiz' ? 'Self Learning Practice' : classroom_h($act['title'] ?? null, 'Learning Activity') ?>
                                                <span style="opacity: 0.5;">·</span> <?= ucfirst(classroom_h($act['skill'] ?? null, 'general')) ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 12px; flex-shrink: 0; text-align: right;">
                                        <div style="font-size: 0.7rem; color: var(--muted);">
                                            <?= htmlspecialchars($timeStr) ?>
                                        </div>
                                        <span style="font-family: monospace; font-size: 0.85rem; font-weight: bold; background: <?= $scoreColor ?>15; color: <?= $scoreColor ?>; border: 1px solid <?= $scoreColor ?>30; padding: 2px 8px; border-radius: 6px;">
                                            <?= $score ?>/100
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            </section>
        </div>

        <!-- TAB: LESSON PLAN -->
        <div id="tab-lesson-plan" class="tab-panel">
            <section class="grid two" style="margin-top: 0;">
                <article class="card" id="lesson-plan">
                    <div class="row" style="justify-content:space-between">
                        <div>
                            <span class="eyebrow">Lesson Plan</span>
                            <h2>Upload RPP</h2>
                        </div>
                        <?php if ($rpp): ?><span class="badge available">Extracted</span><?php endif; ?>
                    </div>
                    <?php if ($rpp): ?>
                        <p><b><?= classroom_h($rpp['original_name'] ?? null, 'Lesson plan') ?></b><br>
                        <small class="muted">Version <?= (int)$rpp['version'] ?> · <?= classroom_h(strtoupper((string)($rpp['file_type'] ?? '')), '-') ?> · text extraction ready</small></p>
                    <?php endif; ?>
                    <form method="post" enctype="multipart/form-data" action="/admin/classroom_upload.php" data-upload-form>
                        <div class="dropzone" data-dropzone>
                            <div class="icon-box" style="margin:auto">⇧</div>
                            <h3>Drag & drop lesson plan</h3>
                            <p class="muted" data-file-status>PDF atau DOCX · Maksimal 15 MB</p>
                            <input type="file" name="rpp_file" accept=".pdf,.docx" required>
                            <?= Csrf::field() ?>
                            <input type="hidden" name="classroom_id" value="<?= $id ?>">
                            <button class="button primary">Upload & Extract RPP</button>
                            <div class="generation-meter" style="margin-top:16px" aria-label="Upload progress">
                                <span data-upload-progress style="width:0"></span>
                            </div>
                            <small class="muted" data-upload-message aria-live="polite"></small>
                        </div>
                    </form>
                </article>

                <article class="card">
                    <div class="row" style="justify-content:space-between">
                        <div>
                            <span class="eyebrow">AI Analysis</span>
                            <h2>Lesson insights</h2>
                        </div>
                        <span class="badge <?= $source === 'ai' ? 'available' : 'dev' ?>"><?= classroom_h(strtoupper((string)$source), '-') ?></span>
                    </div>
                    <div class="analysis-grid">
                        <?php foreach ([['Topic', $analysis['topic']], ['Objectives', $analysis['objectives']], ['Vocabulary', $analysis['vocabulary']], ['Grammar', $analysis['grammar']], ['AI Recommended Level', $analysis['level']], ['Reason', $storedAnalysis['recommendation_reason'] ?? 'Run AI Analysis untuk rekomendasi terstruktur.']] as $item): ?>
                            <div class="analysis-item">
                                <small class="muted"><?= $item[0] ?></small><br>
                                <b><?= htmlspecialchars((string)$item[1]) ?></b>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="grid two" style="margin-top:18px">
                        <form method="post" action="/admin/analyze_rpp.php">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="classroom_id" value="<?= $id ?>">
                            <button class="button secondary wide" <?= $rpp ? '' : 'disabled' ?>><?= $storedAnalysis ? 'Run Analysis Again' : 'Run AI Analysis' ?></button>
                        </form>
                        <form method="post" action="/admin/confirm_level.php">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="classroom_id" value="<?= $id ?>">
                            <label for="level">Classroom Default Level</label>
                            <select id="level" name="level">
                                <?php foreach (['basic', 'intermediate', 'advanced'] as $level): ?>
                                    <option value="<?= $level ?>" <?= $classroom['default_level'] === $level ? 'selected' : '' ?>><?= ucfirst($level) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="button primary wide">Confirm Level</button>
                        </form>
                    </div>
                    <form method="post" action="/admin/generate_content.php">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="classroom_id" value="<?= $id ?>">
                        <button class="button ghost wide" <?= $rpp ? '' : 'disabled' ?>><?= $ready ? 'Regenerate Reading Live Quiz Banks' : 'Generate Reading Live Quiz Banks' ?></button>
                    </form>
                </article>
            </section>
        </div>

        <!-- TAB: SELF LEARNING -->
        <div id="tab-self-learning" class="tab-panel">
            <section class="grid two" style="margin-top: 0;">
                <article class="card" id="self-learning">
                    <span class="eyebrow">Self Learning Content</span>
                    <h2>Generate Skill & Level</h2>
                    <p class="muted">Setiap generation membuat 3 modules dan minimal 10 activities tanpa menghapus level lama.</p>
                    <form method="post" action="/admin/generate_learning.php">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="classroom_id" value="<?= $id ?>">
                        <input type="hidden" name="reading_mode" value="target">
                        <div class="grid three" style="gap: 16px; margin-bottom: 20px;">
                            <div>
                                <label style="font-size: 0.85rem; color: var(--muted); margin-bottom: 6px; display: block;">Skill Focus</label>
                                <select name="skill" id="generator-skill-select" style="margin-bottom: 0; border-radius: 10px; background: rgba(255,255,255,0.05);">
                                    <?php foreach (['reading', 'listening', 'speaking', 'writing'] as $skill): ?>
                                        <option value="<?= $skill ?>"><?= ucfirst($skill) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label style="font-size: 0.85rem; color: var(--muted); margin-bottom: 6px; display: block;">Difficulty Level</label>
                                <select name="level" style="margin-bottom: 0; border-radius: 10px; background: rgba(255,255,255,0.05);">
                                    <?php foreach (['basic', 'intermediate', 'advanced'] as $level): ?>
                                        <option value="<?= $level ?>" <?= $classroom['default_level'] === $level ? 'selected' : '' ?>><?= ucfirst($level) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label style="font-size: 0.85rem; color: var(--muted); margin-bottom: 6px; display: block;">Jumlah Soal</label>
                                <input type="number" name="activity_count" min="10" max="60" value="10" required style="margin-bottom: 0; border-radius: 10px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: #fff; width: 100%; height: 42px; padding: 0 12px; box-sizing: border-box;">
                            </div>
                        </div>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 20px;">
                            <button class="button gold wide" style="flex: 1;" type="submit">⚡ Generate Content Bank</button>
                        </div>
                    </form>
                    <h3>Reading Gemini Bank</h3>
                    <table><thead><tr><th>Level</th><th>Active Qs</th><th>Fallback</th><th>RPP</th><th>Status</th></tr></thead><tbody>
                    <?php foreach(['basic','intermediate','advanced'] as $readingLevel): $bank=$readingBankStatus[$readingLevel]??[];$gemini=(int)($bank['gemini_count']??0); ?>
                    <tr><td><?= ucfirst($readingLevel) ?></td><td><?= $gemini ?></td><td><?= (int)($bank['fallback_count']??0) ?> generated</td><td><?= $rpp ? 'v'.(int)$rpp['version'] : '-' ?></td><td><span class="badge <?= $gemini>=20?'available':'dev' ?>"><?= $gemini<20?'Preparing':($gemini<40?'Low Question Stock':'Ready') ?></span></td></tr>
                    <?php endforeach; ?>
                    </tbody></table>
                    <table>
                        <thead>
                            <tr>
                                <th>Skill</th>
                                <th>Basic</th>
                                <th>Intermediate</th>
                                <th>Advanced</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (['reading', 'listening', 'speaking', 'writing'] as $skill): ?>
                                <tr>
                                    <td><?= ucfirst($skill) ?></td>
                                    <?php foreach (['basic', 'intermediate', 'advanced'] as $level): $cell = $learningMatrix[$skill][$level] ?? null; ?>
                                        <td><span class="badge <?= $cell ? 'available' : 'dev' ?>"><?= $cell ? (int)$cell['total'] . ' ready' : 'Not generated' ?></span></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </article>

                <section class="card" style="margin:0">
                    <h2>Self Learning Progress</h2>
                    <p><a class="button secondary" href="/admin/speaking_review.php?classroom_id=<?= $id ?>">Open Speaking Responses</a></p>
                    <table>
                        <thead>
                            <tr>
                                <th>Skill</th>
                                <th>Completed Attempts</th>
                                <th>Average Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($skillStats as $stat): ?>
                                <tr>
                                    <td><?= classroom_h(ucfirst((string)($stat['skill'] ?? '')), '-') ?></td>
                                    <td><?= (int)$stat['attempts'] ?></td>
                                    <td><?= number_format((float)$stat['average_score'], 1) ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            </section>
        </div>

        <!-- TAB: LIVE QUIZ -->
        <div id="tab-live-quiz" class="tab-panel">
            <section class="grid two" style="margin-top: 0;">
                <article class="card" id="live-quiz">
                    <span class="eyebrow">Multi-Skill Live Quiz</span>
                    <h2>Create live session</h2>
                    <p class="muted">Reading, Listening, AI Speaking Feedback, Writing, Mixed Skills, atau Final Challenge dengan server timer dan scoring backend.</p>
                    <a class="button primary wide" href="/admin/quiz_wizard.php?classroom_id=<?= $id ?>">Open Live Quiz Wizard →</a>
                </article>

                <section class="card" style="margin: 0;">
                    <h2>Live Quiz History</h2>
                    <?php if (!$quizzes): ?>
                        <div class="empty">Belum ada Live Quiz.</div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Session</th>
                                    <th>State</th>
                                    <th>Questions</th>
                                    <th>Difficulty</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($quizzes as $q): ?>
                                    <tr>
                                        <td>#<?= (int)$q['id'] ?></td>
                                        <td><span class="badge <?= $q['state'] === 'ACTIVE' ? 'live' : '' ?>"><?= classroom_h($q['state'] ?? null, '-') ?></span></td>
                                        <td><?= (int)$q['question_count'] ?></td>
                                        <td><?= classroom_h($q['difficulty'] ?? null, '-') ?></td>
                                        <td><a class="button secondary" href="/admin/quiz.php?id=<?= (int)$q['id'] ?>">Open</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </section>
            </section>
        </div>

        <!-- TAB: STUDENTS -->
        <div id="tab-students" class="tab-panel">
            <section class="card" id="students" style="margin-top: 0;">
                <div class="row" style="justify-content:space-between">
                    <h2>Students</h2>
                    <span class="status"><?= count($members) ?> membership records</span>
                </div>
                <?php if (!$members): ?>
                    <div class="empty">Bagikan Classroom ID agar Student dapat bergabung.</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Avatar</th>
                                <th>Status</th>
                                <th>Last seen</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($members as $m): 
                                $memberAvatar = $m['avatar'] ?: 'a.jpg';
                                if (!preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $memberAvatar)) {
                                    $memberAvatar = 'a.jpg';
                                }
                            ?>
                                <tr>
                                    <td><?= classroom_h($m['display_name'] ?? null, 'Joined Student') ?></td>
                                    <td>
                                        <img src="/assets/images/avatars/<?= htmlspecialchars($memberAvatar) ?>" alt="Avatar" width="40" height="40" style="border-radius:50%; background:#2b2b36; border:1px solid rgba(255,255,255,0.2); vertical-align:middle; object-fit:cover;">
                                    </td>
                                    <td><span class="badge"><?= classroom_h($m['membership_status'] ?? null, 'active') ?></span></td>
                                    <td><?= classroom_h($m['last_seen_at'] ?? $m['created_at'] ?? null, '-') ?></td>
                                    <td>
                                        <form method="post" action="/admin/membership_action.php" class="row">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="classroom_id" value="<?= $id ?>">
                                            <input type="hidden" name="member_id" value="<?= (int)$m['id'] ?>">
                                            <?php if (($m['membership_status'] ?? 'active') === 'pending'): ?>
                                                <button class="button secondary" name="action" value="approve">Approve</button>
                                            <?php endif; ?>
                                            <button class="button secondary" name="action" value="remove">Remove</button>
                                            <button class="button secondary" name="action" value="block">Block</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <script src="/assets/js/visual-effects.js" defer></script>
    <script src="/assets/js/teacher.js" defer></script>
    <script src="/assets/js/classroom-tabs.js" defer></script>
</body>
</html>
