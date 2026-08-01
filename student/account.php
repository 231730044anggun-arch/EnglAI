<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../vendor/autoload.php';

use EnglAI\Security\Csrf;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (($_SESSION['user_role'] ?? '') !== 'student') {
    header('Location: /auth/student_login.php');
    exit;
}

$uid = (int)$_SESSION['user_id'];
$pdo = db();

// Fetch enrolled classrooms
$q = $pdo->prepare("
    SELECT m.*, c.name classroom_name, c.code, c.id classroom_id 
    FROM classroom_members m 
    JOIN classrooms c ON c.id=m.classroom_id 
    WHERE m.user_id=? AND m.membership_status='active' 
    ORDER BY m.id DESC
");
$q->execute([$uid]);
$classrooms = $q->fetchAll();

// Fetch student metrics summary
$metrics = $pdo->prepare("
    SELECT 
        COUNT(*) as completed_sessions,
        COALESCE(AVG(score), 0) as average_score,
        COALESCE(MAX(score), 0) as best_score
    FROM student_learning_sessions 
    WHERE member_id IN (
        SELECT id FROM classroom_members WHERE user_id = ?
    ) AND status = 'completed'
");
$metrics->execute([$uid]);
$summary = $metrics->fetch();

// Fetch recent learning results
$recent = $pdo->prepare("
    SELECT s.score, s.completed_at, c.name as classroom_name
    FROM student_learning_sessions s
    JOIN classroom_members m ON m.id = s.member_id
    JOIN classrooms c ON c.id = m.classroom_id
    WHERE m.user_id = ? AND s.status = 'completed'
    ORDER BY s.id DESC LIMIT 5
");
$recent->execute([$uid]);
$activities = $recent->fetchAll();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Student Dashboard · EnglAI</title>
    <link rel="stylesheet" href="/assets/css/mvp.css">
    <style>
        /* Modal Popup styles */
        .modal-overlay {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(8px);
            justify-content: center;
            align-items: center;
        }
        .modal-content {
            background: radial-gradient(circle at center, #1b1b36 0%, #0c0c16 100%);
            border: 1px solid rgba(255, 255, 255, 0.15);
            max-width: 420px;
            width: 90%;
            padding: 24px;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.6);
            position: relative;
            animation: modalFadeIn 0.3s ease;
        }
        @keyframes modalFadeIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .modal-close {
            position: absolute;
            right: 20px;
            top: 15px;
            font-size: 28px;
            cursor: pointer;
            color: rgba(255, 255, 255, 0.5);
            transition: color 0.2s;
        }
        .modal-close:hover {
            color: #FFE66D;
        }
    </style>
</head>
<body>
    <div class="stars" aria-hidden="true"></div>
    
    <!-- Modal Popup Join Classroom -->
    <div id="join-modal" class="modal-overlay">
        <div class="modal-content">
            <span class="modal-close" onclick="closeJoinModal()">&times;</span>
            <span class="eyebrow">Student Access</span>
            <h3 style="margin-top: 5px; color: #fff;">Join New Classroom</h3>
            <p class="muted" style="font-size: 0.9rem; margin-bottom: 20px;">Masukkan Classroom ID (PIN) yang dibagikan oleh Guru Anda.</p>
            
            <form method="post" action="/student/join_flow.php">
                <?= Csrf::field() ?>
                <label for="classroom_code" style="font-size: 0.85rem; color: rgba(255,255,255,0.7);">Classroom ID</label>
                <input id="classroom_code" name="classroom_code" placeholder="Contoh: ENG-7K92" maxlength="16" required style="width: 100%; margin-top: 8px;">
                <button class="button gold wide" style="margin-top: 20px; font-weight: bold; width: 100%;">Join Classroom →</button>
            </form>
        </div>
    </div>

    <header class="nav">
        <a class="brand" href="/student/account.php"><span class="brand-mark">E</span>EnglAI</a>
        <div class="row">
            <span class="muted">Student · <?= htmlspecialchars((string)$_SESSION['user_name']) ?></span>
            <a class="button secondary" href="/auth/logout.php">Logout</a>
        </div>
    </header>
    <main class="shell">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <span>Student</span>
            <span>›</span>
            <strong>Dashboard</strong>
        </nav>

        <?php if ($urlError = trim((string)($_GET['error'] ?? ''))): ?>
            <div class="alert error" style="margin-bottom: 20px; border-radius: 12px;"><?= htmlspecialchars($urlError) ?></div>
        <?php endif; ?>
        <?php if ($urlMessage = trim((string)($_GET['message'] ?? ''))): ?>
            <div class="alert success" style="margin-bottom: 20px; border-radius: 12px;"><?= htmlspecialchars($urlMessage) ?></div>
        <?php endif; ?>

        <section class="card dashboard-hero">
            <span class="eyebrow">Student Workspace</span>
            <h1>Selamat datang, <span class="gradient-text"><?= htmlspecialchars((string)$_SESSION['user_name']) ?></span></h1>
            <p class="muted">Lihat kelas aktif Anda, selesaikan self-learning, dan ikuti Live Quiz bersama kelas Anda.</p>
            <button class="button gold" onclick="openJoinModal()" style="margin-top: 15px; font-weight: bold;">+ Join Classroom</button>
        </section>

        <section class="grid four" aria-label="Student metrics">
            <article class="card metric">
                <div class="icon-box">🏫</div>
                <div>
                    <div class="stat"><?= count($classrooms) ?></div>
                    <span class="muted">Classrooms</span>
                </div>
            </article>
            <article class="card metric">
                <div class="icon-box">📚</div>
                <div>
                    <div class="stat"><?= (int)$summary['completed_sessions'] ?></div>
                    <span class="muted">Sessions Completed</span>
                </div>
            </article>
            <article class="card metric">
                <div class="icon-box">🎯</div>
                <div>
                    <div class="stat"><?= (int)round((float)$summary['average_score']) ?>%</div>
                    <span class="muted">Average Score</span>
                </div>
            </article>
            <article class="card metric">
                <div class="icon-box">🏅</div>
                <div>
                    <div class="stat"><?= (int)$summary['best_score'] ?></div>
                    <span class="muted">Best Score</span>
                </div>
            </article>
        </section>

        <div class="toolbar" style="margin-top:34px">
            <div>
                <span class="eyebrow">Your Enrolled Classrooms</span>
                <h2>Classroom Aktif Anda</h2>
            </div>
        </div>

        <section class="grid">
            <?php if (empty($classrooms)): ?>
                <div class="card" style="grid-column: 1 / -1; text-align: center; padding: 40px 20px;">
                    <div class="icon-box" style="font-size: 2.5rem; margin-bottom: 10px;">🏫</div>
                    <h3>Belum Bergabung dengan Kelas</h3>
                    <p class="muted" style="max-width: 400px; margin: 0 auto 20px auto;">Anda belum memiliki kelas aktif. Klik tombol di bawah untuk bergabung menggunakan Classroom ID.</p>
                    <button class="button gold" onclick="openJoinModal()">+ Join Classroom</button>
                </div>
            <?php endif; ?>

            <?php foreach ($classrooms as $c): 
                // Fetch stats for this specific member
                $memberStats = $pdo->prepare("
                    SELECT 
                        COUNT(*) as sessions,
                        COALESCE(AVG(score), 0) as avg_score
                    FROM student_learning_sessions 
                    WHERE member_id = ? AND status = 'completed'
                ");
                $memberStats->execute([(int)$c['id']]);
                $mStat = $memberStats->fetch();

                // Fetch classroom teacher name and lesson plan name
                $classroomDetails = $pdo->prepare("
                    SELECT 
                        c.teacher_key,
                        (SELECT original_name FROM classroom_lesson_plans lp WHERE lp.classroom_id=c.id AND lp.is_active=1 ORDER BY lp.version DESC LIMIT 1) rpp_name
                    FROM classrooms c WHERE c.id = ?
                ");
                $classroomDetails->execute([(int)$c['classroom_id']]);
                $cDetails = $classroomDetails->fetch();
                
                // Show avatar as circle
                $avatarFile = $c['avatar'] ?: 'a.jpg';
            ?>
            <article class="card hover classroom-card">
                <div class="row" style="justify-content:space-between; align-items: center;">
                    <span class="code" style="color: #FFE66D; font-weight: bold;"><?= htmlspecialchars($c['code']) ?></span>
                    <div class="row" style="align-items: center; gap: 8px;">
                        <img src="/assets/images/avatars/<?= htmlspecialchars($avatarFile) ?>" alt="Avatar" width="30" height="30" style="border-radius: 50%; background: #2b2b36; border: 1px solid rgba(255,255,255,0.2);">
                        <span class="badge available" style="font-size: 0.75rem;"><?= htmlspecialchars($c['display_name']) ?></span>
                    </div>
                </div>
                <div>
                    <h3><?= htmlspecialchars($c['classroom_name']) ?></h3>
                    <p class="muted">Teacher: <?= htmlspecialchars($cDetails['teacher_key'] ?? '—') ?> · <?= htmlspecialchars($cDetails['rpp_name'] ?: 'RPP sedang disiapkan') ?></p>
                </div>
                <div class="classroom-meta" style="display: flex; gap: 20px; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 12px; margin-top: 12px;">
                    <div>
                        <b><?= (int)$mStat['sessions'] ?></b>
                        <small class="muted" style="display:block; font-size:0.75rem;">Completed Sessions</small>
                    </div>
                    <div>
                        <b><?= (int)round((float)$mStat['avg_score']) ?>%</b>
                        <small class="muted" style="display:block; font-size:0.75rem;">Avg Score</small>
                    </div>
                </div>
                <div class="card-actions" style="margin-top: 15px; display: flex; gap: 8px;">
                    <a class="button primary" style="flex: 1; text-align: center;" href="/student/resume.php?member_id=<?= (int)$c['id'] ?>">Open Classroom</a>
                </div>
            </article>
            <?php endforeach; ?>
        </section>

        <section class="grid two" style="margin-top:22px">
            <article class="card">
                <h3>Recent Learning Activity</h3>
                <div class="activity-list" style="display: flex; flex-direction: column; gap: 12px;">
                    <?php if (!$activities): ?>
                        <div class="empty">Belum ada aktivitas belajar.</div>
                    <?php endif; ?>
                    <?php foreach ($activities as $a): ?>
                        <div class="activity" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 8px;">
                            <span>
                                <b>Self Learning selesai (Skor: <?= (int)$a['score'] ?>/100)</b><br>
                                <small class="muted"><?= htmlspecialchars($a['classroom_name']) ?></small>
                            </span>
                            <small class="muted"><?= htmlspecialchars((string)$a['completed_at']) ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>
            <article class="card">
                <h3>Total Progress Status</h3>
                <p class="muted">Status rata-rata ketuntasan Anda berdasarkan akurasi skor di seluruh kelas.</p>
                <?php 
                $avgScore = (int)round((float)$summary['average_score']);
                ?>
                <div class="generation-meter" style="height: 10px; background: rgba(255,255,255,0.08); border-radius: 99px; overflow: hidden; margin-top: 15px;">
                    <span style="display:block; height:100%; background: linear-gradient(90deg, var(--purple), var(--indigo), var(--pink)); width: <?= $avgScore ?>%"></span>
                </div>
                <p style="margin-top:12px"><b><?= $avgScore ?>%</b> Average Accuracy Profile</p>
            </article>
        </section>
    </main>
    <script src="/assets/js/visual-effects.js" defer></script>
    <script>
        function openJoinModal() {
            document.getElementById('join-modal').style.display = 'flex';
        }
        function closeJoinModal() {
            document.getElementById('join-modal').style.display = 'none';
        }
        // Close modal when clicking outside modal-content
        window.onclick = function(event) {
            var modal = document.getElementById('join-modal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }
    </script>
</body>
</html>
