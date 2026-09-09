<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

use EnglAI\Analytics\AnalyticsService;
use EnglAI\Mvp\ClassroomService;
use EnglAI\Security\Csrf;

require_admin();
$id = (int)($_GET['classroom_id'] ?? 0);
$teacher = (string)($_SESSION['admin_username'] ?? env_value('ADMIN_USERNAME', 'admin'));
$classroom = (new ClassroomService(db()))->requireOwned($id, $teacher);
$data = (new AnalyticsService(db()))->classroom($id, $_GET);

$q = db()->prepare('SELECT * FROM classroom_members WHERE classroom_id=? ORDER BY last_seen_at DESC, id DESC LIMIT 50');
$q->execute([$id]);
$members = $q->fetchAll();

$q = db()->prepare("SELECT * FROM ai_recommendations WHERE classroom_id=? AND member_id IS NULL AND status='active' ORDER BY id DESC LIMIT 1");
$q->execute([$id]);
$rec = $q->fetch();
$recommendation = $rec ? json_decode($rec['recommendation_json'], true) : null;
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Classroom Analytics · EnglAI</title>
    <link rel="stylesheet" href="/assets/css/mvp.css?v=<?= time() ?>">
    <link rel="stylesheet" href="/assets/css/analytics.css?v=<?= time() ?>">
</head>
<body>
    <div class="stars" aria-hidden="true"></div>
    <header class="nav">
        <a class="brand" href="/admin/"><span class="brand-mark">E</span>EnglAI</a>
        <a class="button secondary" href="/admin/classroom.php?id=<?= $id ?>">← Classroom</a>
    </header>

    <main class="analytics-shell">
        <!-- Hero Section -->
        <section class="card dashboard-hero analytics-hero">
            <span class="eyebrow">System-calculated metrics</span>
            <h1><?= htmlspecialchars($classroom['name']) ?> <span class="gradient-text">Analytics</span></h1>
            <p class="muted">Dihitung deterministik dari attempts, answers, assessments, dan quiz results nyata secara real-time.</p>
            <div class="row">
                <a class="button secondary" href="/admin/export.php?classroom_id=<?= $id ?>">Export Data</a>
                <a class="button secondary" href="/admin/report.php?classroom_id=<?= $id ?>">Print Report</a>
                <a class="button secondary" href="/admin/audit.php?classroom_id=<?= $id ?>">Audit Log</a>
            </div>
        </section>

        <!-- Filter Card -->
        <form class="card analytics-filter-card" method="get" aria-label="Analytics filters">
            <input type="hidden" name="classroom_id" value="<?= $id ?>">
            <div class="filter-header">
                <span class="eyebrow">Data Filters</span>
                <span class="muted">Sesuaikan rentang tanggal, skill, dan level siswa</span>
            </div>
            <div class="filter-grid">
                <div class="form-group">
                    <label for="date_from">Date from</label>
                    <input id="date_from" type="date" name="date_from" value="<?= htmlspecialchars($data['filters']['date_from']) ?>">
                </div>
                <div class="form-group">
                    <label for="date_to">Date to</label>
                    <input id="date_to" type="date" name="date_to" value="<?= htmlspecialchars($data['filters']['date_to']) ?>">
                </div>
                <div class="form-group">
                    <label for="filter_skill">Skill</label>
                    <select id="filter_skill" name="skill">
                        <option value="">All Skills</option>
                        <?php foreach (['reading', 'listening', 'speaking', 'writing'] as $s): ?>
                            <option value="<?= $s ?>" <?= $data['filters']['skill'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="filter_level">Level</label>
                    <select id="filter_level" name="level">
                        <option value="">All Levels</option>
                        <?php foreach (['basic', 'intermediate', 'advanced'] as $l): ?>
                            <option value="<?= $l ?>" <?= $data['filters']['level'] === $l ? 'selected' : '' ?>><?= ucfirst($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group filter-submit">
                    <button class="button secondary wide">Apply Filters</button>
                </div>
            </div>
        </form>

        <!-- 8 Stat Metrics Grid -->
        <section class="analytics-metrics-grid" aria-label="Ringkasan Statistik Kelas">
            <?php foreach ([
                ['Students', $data['students'], '👥'],
                ['Active (30d)', $data['active_students'], '⚡'],
                ['Self Learning Attempts', $data['self_learning_attempts'], '📚'],
                ['Live Quiz Sessions', $data['live_quiz_sessions'], '🎮'],
                ['Self Learning Avg', $data['self_learning_average'] . '%', '🎯'],
                ['Live Quiz Avg', $data['live_quiz_average'] . '%', '🏆'],
                ['Completion Rate', $data['completion_rate'] . '%', '📈'],
                ['Classroom Level', ucfirst($data['classroom_level']), '🌟']
            ] as $m): ?>
                <article class="card metric-card">
                    <div class="metric-top">
                        <span class="metric-label"><?= htmlspecialchars($m[0]) ?></span>
                        <span class="metric-icon"><?= $m[2] ?></span>
                    </div>
                    <div class="stat metric-val"><?= htmlspecialchars((string)$m[1]) ?></div>
                </article>
            <?php endforeach; ?>
        </section>

        <!-- Skill & Level Performance Grid -->
        <section class="analytics-grid-section">
            <article class="card">
                <div class="card-header">
                    <h2>Skill Performance</h2>
                    <span class="muted">Rata-rata 4 Keterampilan</span>
                </div>
                <div class="skills-list">
                    <?php foreach ($data['skills'] as $skill => $v): ?>
                        <div class="metric-bar">
                            <div class="metric-bar-header">
                                <b><?= ucfirst($skill) ?></b>
                                <span class="stat-pill"><?= $v['average'] ?>% <small class="muted">· <?= $v['attempts'] ?> attempts</small></span>
                            </div>
                            <div class="progress-track" role="img" aria-label="<?= ucfirst($skill) ?> average <?= $v['average'] ?> percent">
                                <span style="width: <?= min(100, $v['average']) ?>%"></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="card">
                <div class="card-header">
                    <h2>Level Performance</h2>
                    <span class="muted">Penguasaan Tingkat Kesulitan</span>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Level</th>
                                <th class="text-right">Available</th>
                                <th class="text-right">Completed</th>
                                <th class="text-right">Students</th>
                                <th class="text-right">Average</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['levels'] as $r): ?>
                                <tr>
                                    <td><b><?= ucfirst($r['level']) ?></b></td>
                                    <td class="text-right"><?= (int)$r['available'] ?></td>
                                    <td class="text-right"><?= (int)$r['completed'] ?></td>
                                    <td class="text-right"><?= (int)$r['students'] ?></td>
                                    <td class="text-right"><span class="highlight-score"><?= number_format((float)$r['average'], 1) ?>%</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        </section>

        <!-- Activity Trends & AI Teaching Recommendation -->
        <section class="analytics-grid-section">
            <article class="card">
                <div class="card-header">
                    <h2>Activity Trends · 30 Days</h2>
                    <span class="muted">Grafik aktivitas harian</span>
                </div>
                <?php if (!$data['trends']): ?>
                    <div class="empty">Belum cukup aktivitas untuk membuat analisis.</div>
                <?php else: ?>
                    <div class="trend-chart-wrapper">
                        <div class="trend-chart">
                            <?php foreach ($data['trends'] as $r): ?>
                                <div class="trend-column">
                                    <span style="height: <?= max(8, min(100, (int)$r['activities'] * 10)) ?>%" title="<?= (int)$r['activities'] ?> aktivitas pada <?= htmlspecialchars($r['day']) ?>"></span>
                                    <small><?= htmlspecialchars(substr($r['day'], 5)) ?></small>
                                    <b><?= (int)$r['activities'] ?></b>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </article>

            <article class="card ai-recommendation-card">
                <div class="card-header">
                    <h2>AI Teaching Recommendation</h2>
                    <?php if ($rec): ?>
                        <span class="badge dev"><?= htmlspecialchars(strtoupper($rec['source'])) ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($recommendation): ?>
                    <div class="recommendation-content">
                        <p class="rec-summary"><?= htmlspecialchars($recommendation['summary']) ?></p>
                        <ul class="rec-actions">
                            <?php foreach ($recommendation['recommended_actions'] as $a): ?>
                                <li><?= htmlspecialchars($a) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php else: ?>
                    <div class="empty">Generate recommendation hanya saat diperlukan.</div>
                <?php endif; ?>
                <p class="muted disclaimer-text">Rekomendasi AI membantu Teacher mengambil keputusan dan tidak menggantikan penilaian profesional Teacher.</p>
                <form method="post" action="/admin/generate_recommendation.php" class="rec-form">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="classroom_id" value="<?= $id ?>">
                    <button class="button gold">Generate / Refresh Recommendation</button>
                </form>
            </article>
        </section>

        <!-- Competency Analysis -->
        <section class="card analytics-table-card">
            <div class="card-header">
                <h2>Competency Analysis</h2>
                <span class="muted">Evaluasi penguasaan indikator kompetensi pembelajaran</span>
            </div>
            <?php if (!$data['competencies']): ?>
                <div class="empty">Belum cukup aktivitas untuk membuat analisis.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Competency</th>
                                <th class="text-right">Items</th>
                                <th class="text-right">Students</th>
                                <th class="text-right">Attempts</th>
                                <th class="text-right">Average</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['competencies'] as $r): ?>
                                <tr>
                                    <td><b><?= htmlspecialchars($r['competency']) ?></b></td>
                                    <td class="text-right"><?= (int)$r['items'] ?></td>
                                    <td class="text-right"><?= (int)$r['students'] ?></td>
                                    <td class="text-right"><?= (int)$r['attempts'] ?></td>
                                    <td class="text-right"><?= number_format((float)$r['average'], 1) ?>%</td>
                                    <td class="text-center">
                                        <span class="badge <?= $r['status'] === 'Mastered' ? 'available' : ($r['status'] === 'Developing' ? 'dev' : 'danger') ?>">
                                            <?= $r['status'] ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <!-- Student Identities -->
        <section class="card analytics-table-card student-identities-card">
            <div class="student-identities-header">
                <div>
                    <div class="row" style="gap: 12px; align-items: center;">
                        <h2 style="margin: 0;">Student Identities</h2>
                        <span class="badge available"><?= count($members) ?> Enrolled</span>
                    </div>
                    <p class="muted" style="margin: 6px 0 0 0; font-size: 0.85rem;">
                        Daftar profil siswa & akun tamu yang terdaftar di kelas ini.
                    </p>
                </div>
                <div class="student-search-wrapper">
                    <input type="text" id="studentSearchInput" placeholder="Cari siswa atau ID..." class="student-search-input" autocomplete="off">
                </div>
            </div>

            <div class="table-responsive">
                <?php if (empty($members)): ?>
                    <div class="empty-student-state">
                        <div style="font-size: 2.5rem; margin-bottom: 10px;">👥</div>
                        <h3>Belum Ada Siswa</h3>
                        <p class="muted">Belum ada siswa yang bergabung di kelas ini. Berikan kode kelas ke siswa untuk memulai.</p>
                    </div>
                <?php else: ?>
                    <table id="studentIdentitiesTable">
                        <thead>
                            <tr>
                                <th style="min-width: 220px;">Siswa</th>
                                <th>Status Akun</th>
                                <th>Waktu Bergabung</th>
                                <th>Aktivitas Terakhir</th>
                                <th class="text-right" style="min-width: 130px;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($members as $m): 
                                $avatarFile = trim((string)($m['avatar'] ?? '')) ?: 'a.jpg';
                                if (!preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $avatarFile)) {
                                    $avatarFile = 'a.jpg';
                                }
                                $displayName = trim((string)($m['display_name'] ?? '')) ?: 'Guest Student';
                                $hasUserAccount = !empty($m['user_id']);
                                $lastSeen = !empty($m['last_seen_at']) ? date('d M Y · H:i', strtotime($m['last_seen_at'])) : '—';
                                $joined = !empty($m['created_at']) ? date('d M Y', strtotime($m['created_at'])) : '—';
                                $isRecent = !empty($m['last_seen_at']) && (time() - strtotime($m['last_seen_at']) < 86400 * 2);
                            ?>
                                <tr class="student-row" data-search="<?= strtolower(htmlspecialchars($displayName . ' ' . $m['id'])) ?>">
                                    <td>
                                        <div class="student-profile-cell">
                                            <img src="/assets/images/avatars/<?= htmlspecialchars($avatarFile) ?>" alt="Avatar" class="student-avatar-img" loading="lazy" onerror="this.src='/assets/images/avatars/a.jpg'">
                                            <div class="student-info">
                                                <b class="student-name"><?= htmlspecialchars($displayName) ?></b>
                                                <span class="code student-id">#<?= (int)$m['id'] ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($hasUserAccount): ?>
                                            <span class="badge available"><span class="status-dot"></span> Registered</span>
                                        <?php else: ?>
                                            <span class="badge dev"><span class="status-dot"></span> Guest</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="muted date-text"><?= htmlspecialchars($joined) ?></span>
                                    </td>
                                    <td>
                                        <div class="activity-cell">
                                            <?php if ($isRecent): ?>
                                                <span class="recent-dot" title="Aktif baru-baru ini"></span>
                                            <?php endif; ?>
                                            <span class="<?= $isRecent ? 'active-text' : 'muted' ?>"><?= htmlspecialchars($lastSeen) ?></span>
                                        </div>
                                    </td>
                                    <td class="text-right">
                                        <a class="button secondary btn-sm" href="/admin/student_analytics.php?classroom_id=<?= $id ?>&member_id=<?= (int)$m['id'] ?>">Open Profile →</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div id="noStudentMatch" class="empty" style="display: none; padding: 36px 20px;">
                        Tidak ada siswa yang cocok dengan pencarian.
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <script src="/assets/js/visual-effects.js" defer></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('studentSearchInput');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const query = this.value.toLowerCase().trim();
                const rows = document.querySelectorAll('.student-row');
                let matched = 0;
                rows.forEach(function(row) {
                    const text = row.getAttribute('data-search') || '';
                    if (!query || text.indexOf(query) !== -1) {
                        row.style.display = '';
                        matched++;
                    } else {
                        row.style.display = 'none';
                    }
                });
                const noMatch = document.getElementById('noStudentMatch');
                if (noMatch) {
                    noMatch.style.display = (matched === 0 && rows.length > 0) ? 'block' : 'none';
                }
            });
        }
    });
    </script>
</body>
</html>
