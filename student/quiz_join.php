<?php
declare(strict_types=1);

require_once __DIR__.'/../config/koneksi.php';
require_once __DIR__.'/../vendor/autoload.php';

use EnglAI\Mvp\StudentSession;
use EnglAI\Security\Csrf;

$pdo = db();
$member = StudentSession::requireMember($pdo);
$quizId = (int)($_GET['id'] ?? $_POST['quiz_id'] ?? 0);

$stmt = $pdo->prepare("SELECT q.*, c.name classroom_name, c.code classroom_code FROM quiz_sessions q JOIN classrooms c ON c.id=q.classroom_id WHERE q.id=? AND q.classroom_id=? AND q.state IN ('LOBBY','COUNTDOWN','ACTIVE','BETWEEN_QUESTIONS','EVALUATING')");
$stmt->execute([$quizId, (int)$member['classroom_id']]);
$quiz = $stmt->fetch();
if (!$quiz) {
    header('Location: /student/dashboard.php');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM quiz_participants WHERE quiz_session_id=? AND member_id=?');
$stmt->execute([$quizId, (int)$member['id']]);
$existing = $stmt->fetch();
if ($existing) {
    header('Location: /student/quiz_play.php?id='.$quizId);
    exit;
}

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
    Csrf::requireValid($_POST['csrf_token'] ?? null);
    $name = trim((string)($_POST['display_name'] ?? ''));
    $avatar = (string)($_POST['avatar'] ?? '');
    
    if ($name === '' || mb_strlen($name) > 60 || !in_array($avatar, $avatars, true)) {
        $error = 'Masukkan display name dan pilih avatar yang valid.';
    } else {
        try {
            $stmt = $pdo->prepare('INSERT INTO quiz_participants(quiz_session_id, member_id, display_name, avatar, last_seen_at, joined_at) VALUES(?,?,?,?,NOW(),NOW())');
            $stmt->execute([$quizId, (int)$member['id'], $name, $avatar]);
            
            $pdo->prepare('UPDATE classroom_members SET display_name=?, avatar=? WHERE id=?')->execute([$name, $avatar, (int)$member['id']]);
            header('Location: /student/quiz_play.php?id='.$quizId);
            exit;
        } catch (PDOException $e) {
            header('Location: /student/quiz_play.php?id='.$quizId);
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Join Live Quiz · EnglAI</title>
    <link rel="stylesheet" href="/assets/css/mvp.css">
    <style>
        .avatar-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            max-height: 250px;
            overflow-y: auto;
            padding: 12px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .avatar-option {
            cursor: pointer;
            position: relative;
        }
        .avatar-card {
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        .avatar-card:hover {
            transform: translateY(-2px);
            border-color: rgba(255, 255, 255, 0.2);
        }
        .avatar-wrapper {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }
        .avatar-card img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .avatar-badge {
            position: absolute;
            top: 2px;
            right: 2px;
            background: #FFE66D;
            color: #000;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            font-size: 0.6rem;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transform: scale(0.5);
            transition: all 0.2s ease;
        }
        .avatar-option input:checked + .avatar-card {
            background: rgba(255, 230, 109, 0.08);
            border-color: #FFE66D;
        }
        .avatar-option input:checked + .avatar-card .avatar-badge {
            opacity: 1;
            transform: scale(1);
        }
    </style>
</head>
<body>
    <div class="stars" aria-hidden="true"></div>
    <header class="nav">
        <a class="brand" href="/student/dashboard.php"><span class="brand-mark">E</span>EnglAI</a>
        <span class="badge live">Live Quiz Ready</span>
    </header>
    <main class="game-shell">
        <nav class="breadcrumb">
            <a href="/student/dashboard.php">Classroom</a>
            <span>›</span>
            <strong>Live Quiz Lobby</strong>
        </nav>
        <section class="card game-card" style="max-width: 500px; margin: auto;">
            <div style="text-align:center">
                <span class="eyebrow"><?= htmlspecialchars($quiz['classroom_code']) ?></span>
                <h1 class="game-title">Choose your player identity</h1>
                <p class="muted"><?= htmlspecialchars($quiz['classroom_name']) ?> · Quiz #<?=$quizId?></p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert error" role="alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <form method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="quiz_id" value="<?=$quizId?>">
                
                <label for="display_name">Display name</label>
                <input id="display_name" name="display_name" maxlength="60" autocomplete="nickname" placeholder="Nama yang terlihat di Leaderboard" value="<?= htmlspecialchars($member['display_name'] ?: '') ?>" required>
                
                <fieldset style="border:0;padding:0; margin-top: 15px;">
                    <legend style="font-weight:600;margin-bottom:10px">Pilih avatar</legend>
                    <div class="avatar-grid">
                        <?php foreach ($avatars as $av): 
                            $isSelected = ($member['avatar'] === $av);
                        ?>
                            <label class="avatar-option">
                                <input type="radio" name="avatar" value="<?= htmlspecialchars($av) ?>" required style="display:none" <?= $isSelected ? 'checked' : '' ?>>
                                <div class="avatar-card">
                                    <div class="avatar-wrapper">
                                        <img src="/assets/images/avatars/<?= htmlspecialchars($av) ?>" alt="Avatar">
                                    </div>
                                    <div class="avatar-badge">✓</div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                
                <button class="button gold wide" style="margin-top:22px">Join Lobby →</button>
            </form>
        </section>
    </main>
    <script src="/assets/js/visual-effects.js" defer></script>
</body>
</html>
