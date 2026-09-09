<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

use EnglAI\Analytics\AnalyticsService;
use EnglAI\Mvp\ClassroomService;
use EnglAI\Security\Csrf;

require_admin();
$cid = (int)($_GET['classroom_id'] ?? 0);
$mid = (int)($_GET['member_id'] ?? 0);
$actor = (string)($_SESSION['admin_username'] ?? 'admin');
$classroom = (new ClassroomService(db()))->requireOwned($cid, $actor);
$data = (new AnalyticsService(db()))->student($cid, $mid);

$q = db()->prepare("SELECT a.id, a.score, a.assessment_status, a.assessment_source, a.transcript, a.writing_submission, a.rubric_json, s.question, s.skill, q.title quiz_title FROM quiz_answers a JOIN quiz_session_questions s ON s.id=a.session_question_id JOIN quiz_sessions q ON q.id=a.quiz_session_id JOIN quiz_participants p ON p.id=a.participant_id WHERE q.classroom_id=? AND p.member_id=? AND s.skill IN('speaking','writing') ORDER BY a.id DESC");
$q->execute([$cid, $mid]);
$assessments = $q->fetchAll();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Analytics · EnglAI</title>
    <link rel="stylesheet" href="/assets/css/mvp.css?v=<?= time() ?>">
    <link rel="stylesheet" href="/assets/css/analytics.css?v=<?= time() ?>">
</head>
<body>
    <div class="stars" aria-hidden="true"></div>
    <header class="nav">
        <a class="brand" href="/admin/"><span class="brand-mark">E</span>EnglAI</a>
        <a class="button secondary" href="/admin/analytics.php?classroom_id=<?= $cid ?>">← Analytics</a>
    </header>

    <main class="analytics-shell">
        <!-- Student Hero Section -->
        <section class="card dashboard-hero analytics-hero">
            <span class="eyebrow">Student Identity #<?= $mid ?></span>
            <h1><?= htmlspecialchars($data['member']['display_name'] ?: 'Student') ?> <span class="gradient-text">Progress</span></h1>
            <p class="muted"><?= htmlspecialchars($classroom['name']) ?> · Joined <?= htmlspecialchars($data['member']['created_at']) ?> · Latest Activity <?= htmlspecialchars($data['member']['last_seen_at'] ?: '—') ?></p>
            <div class="row">
                <span class="badge available">Strongest: <?= htmlspecialchars(ucfirst($data['strongest_skill'] ?: 'insufficient data')) ?></span>
                <span class="badge dev">Improve: <?= htmlspecialchars(ucfirst($data['weakest_skill'] ?: 'insufficient data')) ?></span>
                <a class="button secondary" href="/admin/export.php?classroom_id=<?= $cid ?>&member_id=<?= $mid ?>&type=student_csv">Export CSV</a>
            </div>
        </section>

        <!-- 4 Skills Metric Grid -->
        <?php if ($data['skills']): ?>
            <section class="analytics-metrics-grid" aria-label="Keterampilan Siswa">
                <?php foreach ($data['skills'] as $skill => $v): ?>
                    <article class="card metric-card">
                        <div class="metric-top">
                            <span class="metric-label"><?= ucfirst($skill) ?></span>
                            <span class="metric-icon">
                                <?= $skill === 'reading' ? '📖' : ($skill === 'listening' ? '🎧' : ($skill === 'speaking' ? '🎙️' : '✍️')) ?>
                            </span>
                        </div>
                        <div class="stat metric-val"><?= $v['average_score'] ?>%</div>
                        <span class="muted" style="font-size: 0.82rem; margin-top: 6px;"><?= $v['attempts'] ?> completed attempts</span>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php else: ?>
            <div class="card empty">Belum cukup aktivitas untuk membuat analisis siswa ini.</div>
        <?php endif; ?>

        <!-- Side-by-side Recent Self Learning & Quiz History -->
        <section class="analytics-grid-section">
            <article class="card">
                <div class="card-header">
                    <h2>Recent Self Learning</h2>
                    <span class="muted">Aktivitas belajar mandiri</span>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Activity</th>
                                <th>Skill</th>
                                <th>Level</th>
                                <th class="text-right">Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($data['recent'])): ?>
                                <tr><td colspan="4" class="empty">Belum ada data latihan.</td></tr>
                            <?php else: ?>
                                <?php foreach ($data['recent'] as $r): ?>
                                    <tr>
                                        <td><b><?= htmlspecialchars($r['title']) ?></b></td>
                                        <td><?= ucfirst($r['skill']) ?></td>
                                        <td><span class="badge available"><?= ucfirst($r['level']) ?></span></td>
                                        <td class="text-right"><span class="highlight-score"><?= $r['score'] ?>%</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="card">
                <div class="card-header">
                    <h2>Historical Leaderboard</h2>
                    <span class="muted">Riwayat Live Quiz</span>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Quiz</th>
                                <th>Rank</th>
                                <th>Score</th>
                                <th class="text-right">Achievement</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($data['quiz_history'])): ?>
                                <tr><td colspan="4" class="empty">Belum ada data kuis.</td></tr>
                            <?php else: ?>
                                <?php foreach ($data['quiz_history'] as $r): ?>
                                    <tr>
                                        <td><b><?= htmlspecialchars($r['title'] ?: 'Quiz #' . $r['id']) ?></b></td>
                                        <td><span class="code">#<?= htmlspecialchars((string)($r['final_rank'] ?: '—')) ?></span></td>
                                        <td><?= (int)$r['total_score'] ?> pts</td>
                                        <td class="text-right"><span class="badge dev"><?= htmlspecialchars($r['achievement'] ?: '—') ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        </section>

        <!-- Speaking & Writing Review Table -->
        <section class="card analytics-table-card">
            <div class="card-header">
                <div>
                    <h2>Speaking & Writing Review</h2>
                    <p class="muted" style="margin: 6px 0 0 0; font-size: 0.85rem;">AI Speaking Feedback berbasis transcription; bukan pronunciation assessment.</p>
                </div>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Quiz Title</th>
                            <th>Skill</th>
                            <th>Score</th>
                            <th>Source</th>
                            <th>Status</th>
                            <th class="text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($assessments)): ?>
                            <tr><td colspan="6" class="empty">Belum ada tugas Speaking/Writing yang perlu direview.</td></tr>
                        <?php else: ?>
                            <?php foreach ($assessments as $a): ?>
                                <tr>
                                    <td><b><?= htmlspecialchars($a['quiz_title']) ?></b></td>
                                    <td><span class="badge <?= $a['skill'] === 'speaking' ? 'dev' : 'available' ?>"><?= ucfirst($a['skill']) ?></span></td>
                                    <td><span class="highlight-score"><?= (int)$a['score'] ?>/1000</span></td>
                                    <td><span class="muted"><?= htmlspecialchars((string)$a['assessment_source']) ?></span></td>
                                    <td><span class="badge <?= $a['assessment_status'] === 'completed' ? 'available' : 'dev' ?>"><?= htmlspecialchars($a['assessment_status']) ?></span></td>
                                    <td class="text-right">
                                        <a class="button secondary btn-sm" href="/admin/assessment_review.php?classroom_id=<?= $cid ?>&answer_id=<?= (int)$a['id'] ?>">Review Score</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Personal Recommendation -->
        <section class="card ai-recommendation-card" style="padding: 28px 32px;">
            <div class="card-header">
                <h2>Personal Recommendation</h2>
                <span class="muted">Rekomendasi khusus untuk kemajuan siswa ini</span>
            </div>
            <p class="muted" style="margin-bottom: 20px;">Generate rekomendasi berbasis analisis kelemahan dan tren performa siswa untuk memberikan arahan belajar yang tepat.</p>
            <form method="post" action="/admin/generate_recommendation.php">
                <?= Csrf::field() ?>
                <input type="hidden" name="classroom_id" value="<?= $cid ?>">
                <input type="hidden" name="member_id" value="<?= $mid ?>">
                <button class="button gold">Generate Student Recommendation</button>
            </form>
        </section>
    </main>

    <script src="/assets/js/visual-effects.js" defer></script>
</body>
</html>
