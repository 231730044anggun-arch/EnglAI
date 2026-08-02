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

// Fetch total ready self learning activities from learning_activities
$stmt = db()->prepare("SELECT COUNT(*) FROM learning_activities WHERE classroom_id=? AND status='ready'");
$stmt->execute([$id]);
$selfLearningCount = (int)$stmt->fetchColumn();

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

$ready = $selfLearningCount >= 20 && (int)($banks['live_quiz'] ?? 0) >= 20;

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

// Fetch student activities for the last 30 days
$stmt = db()->prepare("
    SELECT 
        ROUND(AVG(a.score)) as score, 
        DATE(a.completed_at) as completed_date,
        MAX(a.completed_at) as completed_at, 
        CONCAT(UCASE(LEFT(l.skill, 1)), SUBSTRING(l.skill, 2), ' Practice') as title, 
        l.skill, 
        l.level, 
        m.display_name, 
        m.avatar,
        COUNT(*) as activity_count
    FROM learning_attempts a 
    JOIN learning_activities l ON l.id = a.activity_id 
    JOIN classroom_members m ON m.id = a.member_id 
    WHERE a.classroom_id = ? AND a.status = 'completed' AND a.completed_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY a.member_id, l.skill, l.level, DATE(a.completed_at)
    
    UNION ALL
    
    SELECT 
        ROUND((r.score / (r.total_questions * 5)) * 100) as score, 
        DATE(r.completed_at) as completed_date,
        r.completed_at, 
        'Reading Session' as title, 
        'reading' as skill, 
        r.level, 
        m.display_name, 
        m.avatar,
        1 as activity_count
    FROM reading_sessions r
    JOIN classroom_members m ON m.id = r.member_id
    WHERE r.classroom_id = ? AND r.status = 'completed' AND r.completed_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    
    ORDER BY completed_at DESC
");
$stmt->execute([$id, $id]);
$allActivities = $stmt->fetchAll(PDO::FETCH_ASSOC);
$allActivitiesJson = json_encode($allActivities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
        /* Calendar UI Styles */
        .calendar-container {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 24px;
            margin-top: 20px;
        }
        @media (max-width: 768px) {
            .calendar-container {
                grid-template-columns: 1fr;
            }
        }
        .calendar-widget {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 16px;
            padding: 20px;
        }
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        .calendar-header h3 {
            font-size: 1rem;
            margin: 0;
            font-family: 'Poppins', sans-serif;
            color: #fff;
            font-weight: 600;
        }
        .calendar-nav-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #fff;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: inline-grid;
            place-items: center;
            cursor: pointer;
            font-size: 0.9rem;
            transition: background 0.2s, border-color 0.2s;
        }
        .calendar-nav-btn:hover {
            background: rgba(255, 255, 255, 0.15);
            border-color: rgba(255, 255, 255, 0.3);
        }
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 6px;
            text-align: center;
        }
        .calendar-day-label {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--muted);
            padding: 4px 0;
            text-transform: uppercase;
        }
        .calendar-cell {
            aspect-ratio: 1;
            display: grid;
            place-items: center;
            font-size: 0.9rem;
            color: #94a3b8;
            border-radius: 10px;
            cursor: pointer;
            position: relative;
            transition: background 0.2s, color 0.2s;
            border: 1px solid transparent;
        }
        .calendar-cell:hover:not(.empty-cell) {
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
            border-color: rgba(255, 255, 255, 0.1);
        }
        .calendar-cell.empty-cell {
            cursor: default;
        }
        .calendar-cell.has-activity {
            color: #fff;
            font-weight: bold;
            background: rgba(124, 58, 237, 0.12);
            border: 1px solid rgba(124, 58, 237, 0.3);
        }
        .calendar-cell.has-activity::after {
            content: '';
            position: absolute;
            bottom: 6px;
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: #ec4899;
            box-shadow: 0 0 6px #ec4899;
        }
        .calendar-cell.selected {
            background: linear-gradient(135deg, var(--purple), var(--indigo)) !important;
            color: #fff !important;
            border-color: transparent !important;
            box-shadow: 0 0 14px rgba(124, 58, 237, 0.5);
        }
        .calendar-activities-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            max-height: 320px;
            overflow-y: auto;
            padding-right: 6px;
        }
        .calendar-activities-panel {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .calendar-activities-title {
            font-size: 1rem;
            font-weight: 700;
            color: #fff;
            margin: 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding-bottom: 10px;
        }
        .view-all-link {
            font-size: 0.8rem;
            color: #a78bfa;
            cursor: pointer;
            text-decoration: none;
            font-weight: 600;
            padding: 4px 10px;
            background: rgba(124, 58, 237, 0.1);
            border-radius: 6px;
            transition: background 0.2s, color 0.2s;
        }
        .view-all-link:hover {
            background: rgba(124, 58, 237, 0.2);
            color: #c4b5fd;
        }
        .activity-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 14px;
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            gap: 12px;
            transition: transform 0.2s, background-color 0.2s;
        }
        .activity-item:hover {
            background: rgba(255, 255, 255, 0.04);
            transform: translateY(-1px);
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
                        <div class="stat"><?= $selfLearningCount ?></div>
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

            <section class="card" style="margin-top: 22px;">
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
            </section>

            <section class="card" style="margin-top: 22px;">
                <h2>Activity Calendar & Logs</h2>
                <p class="muted">Pilih tanggal pada kalender di bawah untuk melihat detail aktivitas siswa dalam 30 hari terakhir:</p>
                
                <div class="calendar-container">
                    <!-- Calendar Widget -->
                    <div id="calendar-widget-container"></div>
                    
                    <!-- Activities Panel -->
                    <div class="calendar-activities-panel">
                        <h4 class="calendar-activities-title">
                            <span id="calendar-activities-title-text">Aktivitas Hari Ini</span>
                            <span class="view-all-link" onclick="displayActivities('all')">Lihat Semua</span>
                        </h4>
                        <div id="calendar-activities-list" class="calendar-activities-list">
                            <div class="empty">Pilih tanggal pada kalender.</div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card" id="students" style="margin-top: 22px;">
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
                                <th class="text-center">Status</th>
                                <th>Last seen</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($members as $m): 
                                $memberAvatar = $m['avatar'] ?: 'a.jpg';
                                if (!preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $memberAvatar)) {
                                    $memberAvatar = 'a.jpg';
                                }
                                $status = $m['membership_status'] ?? 'active';
                            ?>
                                <tr>
                                    <td><?= classroom_h($m['display_name'] ?? null, 'Joined Student') ?></td>
                                    <td>
                                        <img src="/assets/images/avatars/<?= htmlspecialchars($memberAvatar) ?>" alt="Avatar" width="40" height="40" style="border-radius:50%; background:#2b2b36; border:1px solid rgba(255,255,255,0.2); vertical-align:middle; object-fit:cover;">
                                    </td>
                                    <td class="text-center"><span class="badge <?= $status==='active'?'available':($status==='pending'?'dev':'danger') ?>"><?= classroom_h($status, 'active') ?></span></td>
                                    <td><?= classroom_h($m['last_seen_at'] ?? $m['created_at'] ?? null, '-') ?></td>
                                    <td class="text-right">
                                        <form method="post" action="/admin/membership_action.php" class="row" style="justify-content: flex-end;">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="classroom_id" value="<?= $id ?>">
                                            <input type="hidden" name="member_id" value="<?= (int)$m['id'] ?>">
                                            <?php if ($status === 'pending'): ?>
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


    </main>

    <script src="/assets/js/visual-effects.js" defer></script>
    <script src="/assets/js/teacher.js" defer></script>
    <script src="/assets/js/classroom-tabs.js" defer></script>
    <script>
        const classroomActivities = <?= $allActivitiesJson ?>;

        class ClassroomCalendar {
            constructor(containerId, activities, onDateSelected) {
                this.container = document.getElementById(containerId);
                this.activities = activities;
                this.onDateSelected = onDateSelected;
                
                const today = new Date();
                this.year = today.getFullYear();
                this.month = today.getMonth(); // 0-11
                
                if (this.activities.length > 0) {
                    const latestDateStr = this.activities[0].completed_date;
                    const parts = latestDateStr.split('-');
                    this.selectedDateStr = latestDateStr;
                    this.year = parseInt(parts[0]);
                    this.month = parseInt(parts[1]) - 1;
                } else {
                    this.selectedDateStr = `${this.year}-${String(this.month + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
                }
                
                this.init();
            }
            
            init() {
                this.render();
            }
            
            prevMonth() {
                if (this.month === 0) {
                    this.month = 11;
                    this.year--;
                } else {
                    this.month--;
                }
                this.render();
            }
            
            nextMonth() {
                if (this.month === 11) {
                    this.month = 0;
                    this.year++;
                } else {
                    this.month++;
                }
                this.render();
            }
            
            selectDate(dateStr) {
                this.selectedDateStr = dateStr;
                this.render();
                if (this.onDateSelected) {
                    this.onDateSelected(dateStr);
                }
            }
            
            render() {
                const monthNames = [
                    "Januari", "Februari", "Maret", "April", "Mei", "Juni",
                    "Juli", "Agustus", "September", "Oktober", "November", "Desember"
                ];
                
                const firstDay = new Date(this.year, this.month, 1).getDay();
                const daysInMonth = new Date(this.year, this.month + 1, 0).getDate();
                
                this.container.textContent = '';
                
                const widget = document.createElement('div');
                widget.className = 'calendar-widget';
                
                const header = document.createElement('div');
                header.className = 'calendar-header';
                
                const prevBtn = document.createElement('button');
                prevBtn.type = 'button';
                prevBtn.className = 'calendar-nav-btn';
                prevBtn.textContent = '<';
                prevBtn.addEventListener('click', () => this.prevMonth());
                
                const title = document.createElement('h3');
                title.textContent = `${monthNames[this.month]} ${this.year}`;
                
                const nextBtn = document.createElement('button');
                nextBtn.type = 'button';
                nextBtn.className = 'calendar-nav-btn';
                nextBtn.textContent = '>';
                nextBtn.addEventListener('click', () => this.nextMonth());
                
                header.appendChild(prevBtn);
                header.appendChild(title);
                header.appendChild(nextBtn);
                widget.appendChild(header);
                
                const grid = document.createElement('div');
                grid.className = 'calendar-grid';
                
                const dayLabels = ["Mg", "Sn", "Sl", "Rb", "Km", "Jm", "Sb"];
                dayLabels.forEach(d => {
                    const label = document.createElement('div');
                    label.className = 'calendar-day-label';
                    label.textContent = d;
                    grid.appendChild(label);
                });
                
                for (let i = 0; i < firstDay; i++) {
                    const emptyCell = document.createElement('div');
                    emptyCell.className = 'calendar-cell empty-cell';
                    grid.appendChild(emptyCell);
                }
                
                for (let day = 1; day <= daysInMonth; day++) {
                    const dateStr = `${this.year}-${String(this.month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                    const hasActivity = this.activities.some(act => act.completed_date === dateStr);
                    const isSelected = this.selectedDateStr === dateStr;
                    
                    const cell = document.createElement('div');
                    cell.className = 'calendar-cell';
                    cell.textContent = day;
                    
                    if (hasActivity) cell.classList.add('has-activity');
                    if (isSelected) cell.classList.add('selected');
                    
                    cell.addEventListener('click', () => this.selectDate(dateStr));
                    grid.appendChild(cell);
                }
                
                widget.appendChild(grid);
                this.container.appendChild(widget);
            }
        }

        function displayActivities(dateStr) {
            const listContainer = document.getElementById('calendar-activities-list');
            const titleContainer = document.getElementById('calendar-activities-title-text');
            
            let filtered = [];
            let title = "Aktivitas Hari Ini";
            
            if (dateStr === 'all') {
                filtered = classroomActivities.slice(0, 10);
                title = "Aktivitas Terbaru";
                document.querySelectorAll('.calendar-cell.selected').forEach(cell => cell.classList.remove('selected'));
                if (window.clsCalendar) {
                    window.clsCalendar.selectedDateStr = '';
                }
            } else {
                filtered = classroomActivities.filter(act => act.completed_date === dateStr);
                const parts = dateStr.split('-');
                const date = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
                const options = { day: 'numeric', month: 'short', year: 'numeric' };
                title = date.toLocaleDateString('id-ID', options);
            }
            
            titleContainer.textContent = title;
            listContainer.textContent = '';
            
            if (filtered.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'empty';
                empty.textContent = 'Tidak ada aktivitas pada tanggal ini.';
                listContainer.appendChild(empty);
                return;
            }
            
            filtered.forEach(act => {
                let studAvatar = act.avatar || 'a.jpg';
                if (!/\.(jpg|jpeg|png|webp|gif)$/i.test(studAvatar)) {
                    studAvatar = 'a.jpg';
                }
                const score = parseInt(act.score);
                const scoreColor = score >= 80 ? '#10b981' : (score >= 50 ? '#3b82f6' : '#ef4444');
                
                const completedAt = new Date(act.completed_at);
                const timeStr = String(completedAt.getHours()).padStart(2, '0') + ':' + String(completedAt.getMinutes()).padStart(2, '0');
                
                let actTitle = act.title === 'Practice Quiz' ? 'Self Learning Practice' : act.title;
                if (parseInt(act.activity_count) > 1) {
                    actTitle += ` (${act.activity_count} soal)`;
                }
                
                const skillCapitalized = act.skill.charAt(0).toUpperCase() + act.skill.slice(1);
                const levelCapitalized = act.level.charAt(0).toUpperCase() + act.level.slice(1);
                
                const row = document.createElement('div');
                row.style.display = 'flex';
                row.style.alignItems = 'center';
                row.style.justifyContent = 'space-between';
                row.style.padding = '8px 12px';
                row.style.background = 'rgba(255,255,255,0.02)';
                row.style.border = '1px solid rgba(255,255,255,0.05)';
                row.style.borderRadius = '12px';
                row.style.gap = '12px';
                
                const left = document.createElement('div');
                left.style.display = 'flex';
                left.style.alignItems = 'center';
                left.style.gap = '10px';
                left.style.minWidth = '0';
                left.style.flex = '1';
                
                const img = document.createElement('img');
                img.src = '/assets/images/avatars/' + studAvatar;
                img.alt = 'Avatar';
                img.style.width = '32px';
                img.style.height = '32px';
                img.style.borderRadius = '50%';
                img.style.objectFit = 'cover';
                img.style.flexShrink = '0';
                img.style.border = '1px solid rgba(255,255,255,0.1)';
                
                const textCol = document.createElement('div');
                textCol.style.minWidth = '0';
                textCol.style.lineHeight = '1.3';
                
                const name = document.createElement('div');
                name.style.fontWeight = 'bold';
                name.style.fontSize = '0.9rem';
                name.style.color = '#fff';
                name.style.textOverflow = 'ellipsis';
                name.style.overflow = 'hidden';
                name.style.whiteSpace = 'nowrap';
                name.textContent = act.display_name;
                
                const meta = document.createElement('div');
                meta.style.fontSize = '0.75rem';
                meta.style.color = 'var(--muted)';
                meta.style.textOverflow = 'ellipsis';
                meta.style.overflow = 'hidden';
                meta.style.whiteSpace = 'nowrap';
                meta.textContent = `${actTitle} · ${skillCapitalized} (${levelCapitalized})`;
                
                textCol.appendChild(name);
                textCol.appendChild(meta);
                left.appendChild(img);
                left.appendChild(textCol);
                
                const right = document.createElement('div');
                right.style.display = 'flex';
                right.style.alignItems = 'center';
                right.style.gap = '12px';
                right.style.flexShrink = '0';
                right.style.textAlign = 'right';
                
                const time = document.createElement('div');
                time.style.fontSize = '0.7rem';
                time.style.color = 'var(--muted)';
                time.textContent = timeStr;
                
                const badge = document.createElement('span');
                badge.style.fontFamily = 'monospace';
                badge.style.fontSize = '0.85rem';
                badge.style.fontWeight = 'bold';
                badge.style.background = scoreColor + '15';
                badge.style.color = scoreColor;
                badge.style.border = '1px solid ' + scoreColor + '30';
                badge.style.padding = '2px 8px';
                badge.style.borderRadius = '6px';
                badge.textContent = `${score}/100`;
                
                right.appendChild(time);
                right.appendChild(badge);
                
                row.appendChild(left);
                row.appendChild(right);
                
                listContainer.appendChild(row);
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            window.clsCalendar = new ClassroomCalendar('calendar-widget-container', classroomActivities, (dateStr) => {
                displayActivities(dateStr);
            });
            
            if (window.clsCalendar.selectedDateStr) {
                displayActivities(window.clsCalendar.selectedDateStr);
            } else {
                displayActivities('all');
            }
        });
    </script>
</body>
</html>
