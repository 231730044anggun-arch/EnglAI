<?php
declare(strict_types=1);
require_once __DIR__.'/../config/koneksi.php';
require_once __DIR__.'/../vendor/autoload.php';

use EnglAI\Analytics\AnalyticsService;
use EnglAI\Learning\ProgressService;
use EnglAI\Mvp\StudentSession;

$member = StudentSession::requireMember(db());
$cid = (int)$member['classroom_id'];
$mid = (int)$member['id'];

$summary = (new ProgressService(db()))->summary($cid, $mid);
$rows = $summary['rows'];
$recommended = $summary['recommended'];
$analytics = (new AnalyticsService(db()))->student($cid, $mid);

$skillIcons = [
    'reading'   => '📖',
    'listening' => '🎧',
    'speaking'  => '🎤',
    'writing'   => '✍️'
];

$skillColors = [
    'reading'   => 'linear-gradient(90deg, #3b82f6, #60a5fa)',
    'listening' => 'linear-gradient(90deg, #8b5cf6, #a78bfa)',
    'speaking'  => 'linear-gradient(90deg, #10b981, #34d399)',
    'writing'   => 'linear-gradient(90deg, #f59e0b, #fb923c)',
];
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Learning Progress · EnglAI</title>
<link rel="stylesheet" href="/assets/css/mvp.css">
<link rel="stylesheet" href="/assets/css/analytics.css">
<style>
.progress-shell {
    width: min(1000px, calc(100% - 32px));
    margin: auto;
    padding: 24px 0 80px;
}

/* Recommended Banner */
.recommend-banner {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(135deg, rgba(124,58,237,0.15), rgba(236,72,153,0.1));
    border: 1px solid rgba(139,92,246,0.3);
    padding: 24px 30px;
    border-radius: 20px;
    margin-bottom: 24px;
    box-shadow: 0 10px 30px rgba(124,58,237,0.1);
}
.recommend-text h2 {
    font-size: 1.4rem;
    margin: 4px 0 0;
    background: linear-gradient(135deg, #a5b4fc, #f472b6);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

/* Skill Progress Area */
.progress-grid {
    display: grid;
    gap: 16px;
    margin-top: 24px;
}
.skill-card {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 20px 24px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 18px;
    transition: transform 0.2s, border-color 0.2s;
}
.skill-card:hover {
    transform: translateY(-2px);
    border-color: rgba(124, 58, 237, 0.3);
}
.skill-icon {
    font-size: 2rem;
    width: 60px;
    height: 60px;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.05);
    display: grid;
    place-items: center;
    border: 1px solid rgba(255, 255, 255, 0.1);
}
.skill-info {
    flex: 1;
    min-width: 0;
}
.skill-header {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin-bottom: 8px;
}
.skill-title-group {
    display: flex;
    align-items: center;
    gap: 8px;
}
.skill-title {
    font-size: 1.1rem;
    font-weight: 700;
    margin: 0;
}
.skill-meta {
    font-size: 0.8rem;
    color: var(--muted);
}
.score-badge {
    font-size: 0.85rem;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 8px;
    background: rgba(255,255,255,0.06);
}
.progress-bar-container {
    display: flex;
    align-items: center;
    gap: 12px;
}
.progress-track-custom {
    flex: 1;
    height: 10px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 10px;
    overflow: hidden;
}
.progress-fill-custom {
    display: block;
    height: 100%;
    border-radius: inherit;
    transition: width 0.8s ease-in-out;
}
.progress-pct {
    font-family: Orbitron, monospace;
    font-size: 0.85rem;
    font-weight: 700;
    min-width: 40px;
    text-align: right;
}

/* Insight and Achievements section */
.dashboard-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-top: 24px;
}
.insight-card {
    padding: 24px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 20px;
}
.insight-stat {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    border-radius: 12px;
    background: rgba(0, 0, 0, 0.15);
    margin-top: 12px;
    border: 1px solid rgba(255, 255, 255, 0.04);
}
.insight-label {
    font-size: 0.85rem;
    color: var(--muted);
}
.insight-value {
    font-weight: 700;
    font-family: Orbitron, monospace;
    font-size: 0.95rem;
}

/* Achievements Cabinet */
.trophy-cabinet {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 14px;
}
.trophy-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 999px;
    background: linear-gradient(135deg, rgba(245,158,11,0.15), rgba(249,115,22,0.1));
    border: 1px solid rgba(245,158,11,0.3);
    color: #fcd34d;
    font-size: 0.82rem;
    font-weight: 700;
    box-shadow: 0 4px 15px rgba(245,158,11,0.05);
}

/* Table Style for Quiz History */
.table-card {
    padding: 24px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 20px;
    margin-top: 24px;
    overflow-x: auto;
}
.table-card h2 {
    margin-bottom: 18px;
}
.premium-table {
    width: 100%;
    border-collapse: collapse;
}
.premium-table th {
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.08em;
    color: var(--muted);
    padding: 12px 16px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    font-weight: 700;
}
.premium-table td {
    padding: 14px 16px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.04);
    font-size: 0.9rem;
}
.premium-table tr:last-child td {
    border-bottom: none;
}
.rank-badge {
    display: inline-grid;
    place-items: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    font-family: Orbitron, monospace;
    font-weight: 800;
    font-size: 0.8rem;
}
.rank-badge.rank-1 {
    background: linear-gradient(135deg, #fbbf24, #d97706);
    color: #07071a;
    box-shadow: 0 0 10px rgba(251,191,36,0.3);
}
.rank-badge.rank-other {
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.15);
    color: #fff;
}
.text-gold {
    color: #fbbf24;
    font-weight: 700;
}

@media(max-width:768px) {
    .recommend-banner {
        flex-direction: column;
        align-items: stretch;
        gap: 16px;
        text-align: center;
    }
    .skill-card {
        flex-direction: column;
        align-items: center;
        text-align: center;
        padding: 24px;
    }
    .skill-header {
        flex-direction: column;
        align-items: center;
        gap: 6px;
    }
    .skill-title-group {
        flex-direction: column;
        gap: 4px;
    }
    .dashboard-row {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>
<div class="stars" aria-hidden="true"></div>
<header class="nav">
    <a class="brand" href="/student/dashboard.php"><span class="brand-mark">E</span>EnglAI</a>
    <a class="button secondary" href="/student/dashboard.php">← Classroom</a>
</header>

<main class="progress-shell">
    <nav class="breadcrumb">
        <a href="/student/dashboard.php">Classroom</a>
        <span>›</span>
        <strong>Progress</strong>
    </nav>

    <!-- Hero Title -->
    <section class="card student-hero" style="margin-bottom: 24px;">
        <span class="eyebrow">Real Activity Data</span>
        <h1>Learning Progress</h1>
        <p class="muted">Statistik performa belajar berdasarkan pengerjaan latihan mandiri &amp; hasil sesi Live Quiz Anda.</p>
    </section>

    <!-- Recommended Activity -->
    <?php if($recommended):?>
    <section class="recommend-banner">
        <div class="recommend-text">
            <span class="eyebrow" style="color: #c4b5fd;">Recommended Next Activity</span>
            <h2><?=ucfirst($recommended['skill'])?> · <?=ucfirst($recommended['level'])?></h2>
        </div>
        <a class="button gold" href="/student/skill.php?skill=<?=$recommended['skill']?>&level=<?=$recommended['level']?>" style="min-width: 160px;">
            Continue Learning →
        </a>
    </section>
    <?php endif;?>

    <!-- Skill Cards Progress Section -->
    <section class="card">
        <span class="eyebrow">Skills Competency</span>
        <h2 style="margin: 4px 0 20px 0;">Self Learning Status</h2>
        <div class="progress-grid">
            <?php foreach($rows as $row):
                $skillKey = strtolower($row['skill']);
                $pct = $row['available'] ? (int)round($row['completed'] / $row['available'] * 100) : 0;
                $color = $skillColors[$skillKey] ?? $skillColors['reading'];
                $icon = $skillIcons[$skillKey] ?? '📖';
            ?>
            <div class="skill-card">
                <div class="skill-icon">
                    <?=$icon?>
                </div>
                <div class="skill-info">
                    <div class="skill-header">
                        <div class="skill-title-group">
                            <h3 class="skill-title">
                                <a href="/student/skill.php?skill=<?=$row['skill']?>&level=<?=$row['level']?>" style="color: inherit; text-decoration: none;">
                                    <?=ucfirst($row['skill'])?>
                                </a>
                            </h3>
                            <span class="badge" style="font-size: 0.7rem; border-color: rgba(255,255,255,0.05); background: rgba(255,255,255,0.03);">
                                <?=ucfirst($row['level'])?>
                            </span>
                        </div>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <span class="skill-meta" style="font-size: 0.8rem; display: inline-flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                <span><?=$row['completed']?> / <?=$row['available']?> Activities</span>
                                <?php if ((int)$row['completed'] > 0): ?>
                                    <span style="opacity: 0.3;">•</span>
                                    <span style="color: #34d399; font-weight: 700; background: rgba(52, 211, 153, 0.08); padding: 2px 6px; border-radius: 4px;">✓ <?=(int)$row['correct_count']?> Bener</span>
                                    <span style="opacity: 0.3;">•</span>
                                    <span style="color: #f87171; font-weight: 700; background: rgba(248, 113, 113, 0.08); padding: 2px 6px; border-radius: 4px;">✗ <?=(int)$row['incorrect_count']?> Salah</span>
                                <?php endif; ?>
                            </span>
                            <span class="score-badge">
                                Avg: <?=number_format((float)$row['average_score'], 1)?>%
                            </span>
                        </div>
                    </div>
                    <div class="progress-bar-container">
                        <div class="progress-track-custom">
                            <span class="progress-fill-custom" style="width: <?=$pct?>%; background: <?=$color?>;"></span>
                        </div>
                        <span class="progress-pct" style="color: <?=str_replace('linear-gradient(90deg, ', '', explode(',', $color)[1] ?? '#fff')?>">
                            <?=$pct?>%
                        </span>
                    </div>
                </div>
            </div>
            <?php endforeach;?>
        </div>
    </section>

    <!-- Insights & Achievements -->
    <div class="dashboard-row">
        <!-- Personal Insights -->
        <article class="insight-card">
            <span class="eyebrow" style="color: #34d399;">Analysis</span>
            <h2 style="margin: 4px 0 16px 0;">Personal Insight</h2>
            <?php if(!$analytics['skills']):?>
                <div class="empty" style="padding: 20px 0;">Belum cukup aktivitas untuk membuat analisis.</div>
            <?php else:?>
                <div class="insight-stat" style="border-left: 3px solid #34d399;">
                    <span class="insight-label">🎖️ Strongest Skill</span>
                    <span class="insight-value" style="color: #34d399;"><?=ucfirst((string)$analytics['strongest_skill'])?></span>
                </div>
                <div class="insight-stat" style="border-left: 3px solid #f87171;">
                    <span class="insight-label">📈 Need to Improve</span>
                    <span class="insight-value" style="color: #f87171;"><?=ucfirst((string)$analytics['weakest_skill'])?></span>
                </div>
            <?php endif;?>
        </article>

        <!-- Achievements Cabinet -->
        <article class="insight-card">
            <span class="eyebrow" style="color: #fbbf24;">Cabinet</span>
            <h2 style="margin: 4px 0 16px 0;">Achievements</h2>
            <?php 
            $ach = array_values(array_filter(array_column($analytics['quiz_history'], 'achievement')));
            if(!$ach):?>
                <div class="empty" style="padding: 20px 0;">Selesaikan Live Quiz untuk memperoleh achievement.</div>
            <?php else:?>
                <div class="trophy-cabinet">
                    <?php foreach(array_unique($ach) as $a):?>
                        <span class="trophy-badge">
                            🏆 <?=htmlspecialchars($a)?>
                        </span>
                    <?php endforeach;?>
                </div>
            <?php endif;?>
        </article>
    </div>

    <!-- Live Quiz History -->
    <section class="table-card">
        <span class="eyebrow" style="color: #fb923c;">History</span>
        <h2>Live Quiz History</h2>
        <?php if(!$analytics['quiz_history']):?>
            <div class="empty" style="padding: 30px 0;">Belum ada riwayat pengerjaan Live Quiz.</div>
        <?php else:?>
            <table class="premium-table">
                <thead>
                    <tr>
                        <th>Quiz Title</th>
                        <th>Quiz Mode</th>
                        <th style="text-align: center;">Rank</th>
                        <th style="text-align: right;">Score</th>
                        <th style="padding-left: 24px;">Achievement</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($analytics['quiz_history'] as $q):
                        $isFirst = (int)($q['final_rank'] ?? 0) === 1;
                    ?>
                    <tr>
                        <td style="font-weight: 700;">
                            <?=htmlspecialchars($q['title']?:'Quiz #'.$q['id'])?>
                        </td>
                        <td>
                            <span class="badge" style="text-transform: capitalize; background: rgba(255,255,255,0.03); border-color: rgba(255,255,255,0.05);">
                                <?=htmlspecialchars(str_replace('_',' ',$q['quiz_mode']?:'reading'))?>
                            </span>
                        </td>
                        <td style="text-align: center;">
                            <span class="rank-badge <?=$isFirst ? 'rank-1' : 'rank-other'?>">
                                <?=htmlspecialchars((string)($q['final_rank'] ?: '—'))?>
                            </span>
                        </td>
                        <td style="text-align: right; font-family: Orbitron, monospace; font-weight: 700;">
                            <?=number_format((int)$q['total_score'])?>
                        </td>
                        <td style="padding-left: 24px;">
                            <?php if($q['achievement']):?>
                                <span class="text-gold">🏆 <?=htmlspecialchars($q['achievement'])?></span>
                            <?php else:?>
                                <span style="opacity: 0.4;">—</span>
                            <?php endif;?>
                        </td>
                    </tr>
                    <?php endforeach;?>
                </tbody>
            </table>
        <?php endif;?>
    </section>
</main>

<script src="/assets/js/visual-effects.js" defer></script>
</body>
</html>
