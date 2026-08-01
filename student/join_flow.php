<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../vendor/autoload.php';

use EnglAI\Mvp\ClassroomService;
use EnglAI\Security\Csrf;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Enforce student login
if (($_SESSION['user_role'] ?? '') !== 'student') {
    header('Location: /auth/student_login.php?error=' . rawurlencode('Silakan login terlebih dahulu untuk masuk ke Classroom.'));
    exit;
}

$error = '';
$code = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Csrf::requireValid($_POST['csrf_token'] ?? null);
        $code = strtoupper(trim((string)($_POST['classroom_code'] ?? '')));
        
        if (!preg_match('/^ENG-[A-Z0-9]{4,8}$/', $code)) {
            throw new InvalidArgumentException('Format Classroom ID tidak valid.');
        }

        $classroom = (new ClassroomService(db()))->findActiveByCode($code);
        if (!$classroom) {
            throw new RuntimeException('Classroom ID tidak ditemukan atau tidak aktif.');
        }

        // Redirect to profile & character setup page
        header('Location: /student/setup_profile.php?code=' . urlencode($code));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Join Classroom · EnglAI</title>
    <link rel="stylesheet" href="/assets/css/mvp.css">
</head>
<body>
    <div class="stars"></div>
    <header class="nav">
        <a class="brand" href="/">EnglAI</a>
        <div class="row">
            <span class="badge available">Student</span>
            <a class="button secondary" href="/student/logout.php">Logout</a>
        </div>
    </header>
    <main class="game-shell">
        <section class="card">
            <span class="eyebrow">Classroom Entry</span>
            <h1>Masukkan PIN Kelas</h1>
            <p class="muted">Masukkan Classroom ID yang dibagikan oleh Guru Anda.</p>

            <?php if ($error): ?>
                <div class="alert error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post">
                <?= Csrf::field() ?>
                <label for="classroom_code">Classroom ID (PIN)</label>
                <input id="classroom_code" name="classroom_code" maxlength="16" autocomplete="off" placeholder="ENG-7K92" value="<?= htmlspecialchars($code) ?>" required>
                <small class="muted">Format contoh: ENG-7K92</small>
                
                <button class="button primary wide" style="margin-top:20px">Lanjutkan →</button>
            </form>
            <a class="button secondary wide" href="/student/dashboard.php">Kembali ke Dashboard</a>
        </section>
    </main>
</body>
</html>
