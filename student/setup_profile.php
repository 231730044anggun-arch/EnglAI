<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../vendor/autoload.php';

use EnglAI\Mvp\ClassroomService;
use EnglAI\Mvp\StudentSession;
use EnglAI\Security\Csrf;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Enforce student login
if (($_SESSION['user_role'] ?? '') !== 'student') {
    header('Location: /auth/student_login.php');
    exit;
}

$code = strtoupper(trim((string)($_GET['code'] ?? '')));
if ($code === '') {
    header('Location: /student/join_flow.php');
    exit;
}

$classroom = (new ClassroomService(db()))->findActiveByCode($code);
if (!$classroom) {
    header('Location: /student/join_flow.php?error=' . rawurlencode('Classroom tidak ditemukan atau tidak aktif.'));
    exit;
}

$classroomId = (int)$classroom['id'];
$userId = (int)$_SESSION['user_id'];
$defaultNickname = (string)($_SESSION['user_name'] ?? '');

// Dynamically scan avatars directory
$avatarDir = __DIR__ . '/../assets/images/avatars';
$avatars = [];
if (is_dir($avatarDir)) {
    $files = scandir($avatarDir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            $avatars[] = $file;
        }
    }
}
if (empty($avatars)) {
    $avatars = ['a.jpg', 'b.jpg', 'c.jpg', 'd.jpg', 'e.jpg', 'f.jpg', 'g.jpg', 'h.jpg', 'i.jpg', 'j.jpg', 'k.jpg', 'l.jpg', 'm.jpg', 'n.jpg', 'o.jpg', 'p.jpg', 'q.jpg', 'r.jpg', 's.jpg', 't.jpg', 'u.jpg'];
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Csrf::requireValid($_POST['csrf_token'] ?? null);
        $nickname = trim((string)($_POST['nickname'] ?? ''));
        $selectedAvatar = trim((string)($_POST['avatar'] ?? ''));

        if ($nickname === '' || mb_strlen($nickname) > 60) {
            throw new InvalidArgumentException('Nickname wajib diisi (maksimal 60 karakter).');
        }
        if (!in_array($selectedAvatar, $avatars, true)) {
            throw new InvalidArgumentException('Karakter avatar tidak valid.');
        }

        $pdo = db();
        $status = !empty($classroom['require_approval']) ? 'pending' : 'active';
        $token = bin2hex(random_bytes(32));

        // Check if student already has a member record in this class
        $existing = $pdo->prepare('SELECT * FROM classroom_members WHERE classroom_id=? AND user_id=?');
        $existing->execute([$classroomId, $userId]);
        $member = $existing->fetch();

        if ($member) {
            if ($member['membership_status'] !== 'active') {
                throw new RuntimeException('Keanggotaan kelas Anda sedang menunggu persetujuan atau dinonaktifkan oleh Guru.');
            }
            
            // Update their active nickname & avatar
            $up = $pdo->prepare('UPDATE classroom_members SET display_name=?, avatar=?, last_seen_at=NOW() WHERE id=?');
            $up->execute([$nickname, $selectedAvatar, (int)$member['id']]);
            
            StudentSession::establish((int)$member['id'], $classroomId, (string)$member['session_token']);
            header('Location: /student/dashboard.php');
            exit;
        }

        // Insert new member record linked to user account
        $stmt = $pdo->prepare('INSERT INTO classroom_members(classroom_id, user_id, session_token, display_name, avatar, membership_status, last_seen_at, approved_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), IF(?="active", NOW(), NULL))');
        $stmt->execute([$classroomId, $userId, $token, $nickname, $selectedAvatar, $status, $status]);

        if ($status === 'pending') {
            header('Location: /student/account.php?message=' . rawurlencode('Bergabung berhasil. Menunggu persetujuan Guru.'));
            exit;
        }

        $memberId = (int)$pdo->lastInsertId();
        StudentSession::establish($memberId, $classroomId, $token);
        header('Location: /student/dashboard.php');
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
    <title>Setup Profile Kelas · EnglAI</title>
    <link rel="stylesheet" href="/assets/css/mvp.css">
    <style>

        .avatar-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            max-height: 380px;
            overflow-y: auto;
            padding: 16px;
            background: radial-gradient(circle at center, #1b1b36 0%, #0c0c16 100%);
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: inset 0 4px 20px rgba(0, 0, 0, 0.7);
        }
        .avatar-option {
            cursor: pointer;
            position: relative;
        }
        .avatar-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.06) 0%, rgba(255, 255, 255, 0.01) 100%);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }
        .avatar-card:hover {
            transform: translateY(-4px);
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1) 0%, rgba(255, 255, 255, 0.02) 100%);
            border-color: rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
        }
        .avatar-wrapper {
            width: 90px;
            height: 85px;
            border-radius: 12px;
            overflow: hidden;
            border: 2px solid rgba(255, 255, 255, 0.15);
            background: rgba(255, 255, 255, 0.05);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
            transition: all 0.3s ease;
        }
        .avatar-card img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .avatar-badge {
            position: absolute;
            top: 6px;
            right: 6px;
            background: #FFE66D;
            color: #000;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            font-size: 0.7rem;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transform: scale(0.5);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.3);
        }
        .avatar-option input:checked + .avatar-card {
            background: rgba(255, 230, 109, 0.08);
            border-color: #FFE66D;
            box-shadow: 0 0 15px rgba(255, 230, 109, 0.25);
        }
        .avatar-option input:checked + .avatar-card .avatar-wrapper {
            border-color: #FFE66D;
            transform: scale(1.05);
        }
        .avatar-option input:checked + .avatar-card .avatar-badge {
            opacity: 1;
            transform: scale(1);
        }
    </style>
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
        <section class="card" style="max-width: 500px;">
            <span class="eyebrow">Classroom: <?= htmlspecialchars($classroom['name']) ?></span>
            <h1>Pilih Karakter & Nickname</h1>
            <p class="muted">Sesuaikan identitas Anda untuk kelas ini.</p>

            <?php if ($error): ?>
                <div class="alert error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post">
                <?= Csrf::field() ?>
                
                <label for="nickname">Nickname Kelas</label>
                <input id="nickname" name="nickname" maxlength="60" placeholder="Contoh: jejeboy" value="<?= htmlspecialchars($defaultNickname) ?>" required>
                <small class="muted">Nama panggilan unik Anda yang akan tampil di kelas.</small>

                <label style="margin-top:20px; display:block; margin-bottom:8px;">Pilih Karakter Anda</label>
                
                <div class="avatar-grid">
                    <?php foreach ($avatars as $i => $av): ?>
                        <label class="avatar-option">
                            <input type="radio" name="avatar" value="<?= htmlspecialchars($av) ?>" <?= $i === 0 ? 'required' : '' ?> style="display:none">
                            <div class="avatar-card">
                                <div class="avatar-wrapper">
                                    <img src="/assets/images/avatars/<?= htmlspecialchars($av) ?>" alt="Avatar" loading="lazy">
                                </div>
                                <div class="avatar-badge">✓</div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>

                <button class="button gold wide" style="margin-top:20px">Join Classroom →</button>
            </form>
        </section>
    </main>
</body>
</html>
