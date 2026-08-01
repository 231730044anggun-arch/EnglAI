<?php
declare(strict_types=1);

require_once __DIR__ . '/config/koneksi.php';
apply_security_headers(true);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$isStudent = (($_SESSION['user_role'] ?? '') === 'student');
$joinUrl = $isStudent ? '/student/account.php' : '/auth/student_login.php';
$error = trim((string)($_GET['error'] ?? ''));
$publicDemo = filter_var(env_value('PUBLIC_DEMO_ENABLED', 'true'), FILTER_VALIDATE_BOOL);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="description" content="EnglAI mengubah RPP menjadi AI Classroom untuk Self Learning dan Live Quiz Bahasa Inggris.">
    <title>EnglAI · AI Classroom Platform</title>
    <link rel="stylesheet" href="/assets/css/mvp.css">
</head>
<body>
    <div class="stars" aria-hidden="true"></div>
    <header class="nav">
        <a class="brand" href="/" aria-label="EnglAI home"><span class="brand-mark">E</span>EnglAI</a>
        <nav class="nav-links" aria-label="Navigasi utama">
            <a href="#about">About</a>
            <a href="#features">Features</a>
            <a href="#how">How It Works</a>
            <?php if($publicDemo): ?><a href="/public_demo.php">Public Demo</a><?php endif; ?>
            <a href="/auth/student_login.php">Student Login</a>
            <a class="button secondary" href="/admin/login.php">Teacher Login</a>
        </nav>
    </header>
    <main>
        <section class="public-hero" id="about">
            <div>
                <span class="eyebrow">AI-powered English classroom</span>
                <h1 class="display">Transformasi Kelas Anda. <span class="gradient-text">Hidupkan AI Classroom.</span></h1>
                <p class="lead">EnglAI membantu Teacher mengubah materi ajar menjadi Self Learning adaptif dan Live Quiz real-time yang aman, seru, dan terukur.</p>
                <div class="hero-actions">
                    <a class="button primary" href="/admin/login.php">Masuk sebagai Teacher →</a>
                    <a class="button gold" href="<?= htmlspecialchars($joinUrl) ?>">Join Classroom</a>
                </div>
                <div class="hero-proof">
                    <span><b>2 bank</b>Konten terpisah</span>
                    <span><b>Server</b>Scoring authoritative</span>
                    <span><b>4 skills</b>Roadmap pembelajaran</span>
                </div>
            </div>
            
            <aside class="card join-panel" aria-labelledby="demo-title">
                <span class="eyebrow">Instant Experience</span>
                <h2 id="demo-title" style="margin-top: 5px;">Explore Public Demo</h2>
                <p class="muted">Cobalah platform pembelajaran secara instan tanpa perlu mendaftar atau setup kelas. Rasakan langsung modul latihan Reading adaptif kami secara gratis.</p>
                <div style="margin-top: 20px;">
                    <a class="button gold wide" href="/public_demo.php" style="text-align: center; display: block; font-weight: bold;">Mulai Demo Sekarang →</a>
                </div>
                <div class="preview-window" aria-label="Preview learning progress" style="margin-top: 24px;">
                    <div class="row">
                        <span class="badge available">Reading · Available</span>
                        <span class="badge live">Live Quiz</span>
                    </div>
                    <div class="preview-bars">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                </div>
                <!-- Compatibility link: /student/join.php -->
            </aside>
        </section>

        <section class="section" id="features">
            <div class="section-head">
                <span class="eyebrow">Platform Features</span>
                <h2>Satu alur dari Materi hingga Progress</h2>
            </div>
            <div class="grid four">
                <?php foreach([['🧠','AI Content Analysis','Menemukan fokus, vocabulary, grammar, dan level dari materi ajar.'],['📚','Self Learning','Latihan Reading acak yang dapat dimainkan kapan saja.'],['🏆','Live Quiz','Lobby multiplayer, timer server, scoring, dan Leaderboard.'],['📈','Learning Analytics','Progress nyata dari aktivitas Student; pengembangan bertahap.']] as $feature): ?>
                    <article class="card hover feature-card">
                        <div class="icon-box"><?= $feature[0] ?></div>
                        <h3><?= $feature[1] ?></h3>
                        <p class="muted"><?= $feature[2] ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="section">
            <div class="section-head">
                <span class="eyebrow">English Skills</span>
                <h2>Belajar sesuai skill</h2>
            </div>
            <div class="grid four">
                <article class="card hover skill-card active">
                    <span class="badge available">Available</span>
                    <div class="skill-icon">📖</div>
                    <h3>Reading</h3>
                    <p class="muted">Passage, vocabulary, comprehension, dan objective feedback.</p>
                </article>
                <?php foreach([['🎧','Listening'],['🎙️','Speaking'],['✍️','Writing']] as $skill): ?>
                    <article class="card skill-card">
                        <span class="badge dev">In Development</span>
                        <div class="skill-icon"><?= $skill[0] ?></div>
                        <h3><?= $skill[1] ?></h3>
                        <p class="muted">Akan hadir dalam phase pengembangan berikutnya.</p>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="section" id="how">
            <div class="section-head">
                <span class="eyebrow">How It Works</span>
                <h2>Dari materi ajar ke pengalaman kelas</h2>
            </div>
            <div class="grid steps">
                <?php foreach([['Unggah Dokumen','Teacher mengunggah PDF atau DOCX pada Classroom tertentu.'],['AI Analysis','Backend menganalisis dan menyiapkan dua content bank.'],['AI Classroom','Student masuk menggunakan Classroom ID yang valid.'],['Self Learning','Reading tersedia kapan saja dengan pertanyaan acak.'],['Live Quiz','Teacher membuka lobby dan memulai permainan sinkron.'],['Progress','Jawaban, skor, dan hasil disimpan sebagai data aktivitas.']] as $step): ?>
                    <article class="card step">
                        <h3><?= $step[0] ?></h3>
                        <p class="muted"><?= $step[1] ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
    <footer class="public-footer">
        <span class="brand">EnglAI</span>
        <p>AI Classroom Platform untuk pembelajaran Bahasa Inggris.</p>
    </footer>
    <script src="/assets/js/visual-effects.js" defer></script>
    <script src="/assets/js/public.js" defer></script>
</body>
</html>
