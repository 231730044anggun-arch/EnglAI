<?php
declare(strict_types=1);
require_once __DIR__.'/_auth.php';
require_once __DIR__.'/../vendor/autoload.php';
use EnglAI\Mvp\ClassroomService;

require_admin();
$cid=(int)($_GET['classroom_id']??0);
$actor=(string)($_SESSION['admin_username']??'admin');
$classroom=(new ClassroomService(db()))->requireOwned($cid,$actor);
$page=max(1,(int)($_GET['page']??1));
$offset=($page-1)*50;
$q=db()->prepare("SELECT * FROM audit_logs WHERE classroom_id=? ORDER BY id DESC LIMIT 50 OFFSET $offset");
$q->execute([$cid]);
$rows=$q->fetchAll();

function formatAction(string $action): string {
    $parts = explode('.', $action);
    $formattedParts = array_map(function($p) {
        return implode(' ', array_map('ucfirst', explode('_', $p)));
    }, $parts);
    return implode(' ➔ ', $formattedParts);
}

function getActionBadgeStyle(string $action): string {
    $a = strtolower($action);
    if (str_contains($a, 'started') || str_contains($a, 'created') || str_contains($a, 'generated') || str_contains($a, 'add') || str_contains($a, 'join')) {
        return 'background: rgba(16, 185, 129, 0.08); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.2);';
    }
    if (str_contains($a, 'closed') || str_contains($a, 'remove') || str_contains($a, 'block') || str_contains($a, 'delete')) {
        return 'background: rgba(239, 68, 68, 0.08); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.2);';
    }
    return 'background: rgba(59, 130, 246, 0.08); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.2);';
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Audit Log · EnglAI</title>
    <link rel="stylesheet" href="/assets/css/mvp.css">
    <style>
        .audit-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .audit-table th {
            text-align: left;
            padding: 14px 16px;
            border-bottom: 2px solid rgba(255,255,255,0.08);
            color: var(--muted);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .audit-table td {
            padding: 14px 16px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            font-size: 0.9rem;
            vertical-align: middle;
        }
        .audit-table tr:hover {
            background: rgba(255,255,255,0.01);
        }
        .action-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 99px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: capitalize;
        }
        .actor-info {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .actor-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: rgba(255,255,255,0.1);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: bold;
            text-transform: uppercase;
            border: 1px solid rgba(255,255,255,0.15);
        }
        .time-cell {
            font-family: monospace;
            font-size: 0.8rem;
            color: var(--muted);
            white-space: nowrap;
        }
        .entity-cell {
            font-weight: 500;
            color: #e2e8f0;
            white-space: nowrap;
        }
        .req-id-badge {
            font-family: monospace;
            font-size: 0.75rem;
            padding: 2px 6px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 6px;
            color: var(--muted);
        }
    </style>
</head>
<body>
<div class="stars"></div>
<header class="nav">
    <a class="brand" href="/admin/">EnglAI</a>
    <a class="button secondary" href="/admin/analytics.php?classroom_id=<?=$cid?>">← Analytics</a>
</header>
<main class="shell">
    <section class="card dashboard-hero">
        <span class="eyebrow">Read-only history</span>
        <h1>Classroom Audit Log</h1>
        <p class="muted"><?=htmlspecialchars($classroom['name'])?></p>
    </section>
    
    <section class="card">
        <div style="overflow-x: auto;">
            <table class="audit-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Actor</th>
                        <th>Action</th>
                        <th>Entity</th>
                        <th>Reason</th>
                        <th>Request ID</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px; color: var(--muted);">No audit logs found for this classroom.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($rows as $r):
                            $actorName = $r['actor'];
                            $initials = mb_substr($actorName, 0, 2);
                        ?>
                            <tr>
                                <td class="time-cell"><?=htmlspecialchars($r['created_at'])?></td>
                                <td>
                                    <div class="actor-info">
                                        <span class="actor-avatar" title="<?=htmlspecialchars($actorName)?>"><?=$initials?></span>
                                        <span style="font-weight: 500;"><?=htmlspecialchars($actorName)?></span>
                                    </div>
                                </td>
                                <td>
                                    <span class="action-badge" style="<?=getActionBadgeStyle($r['action'])?>">
                                        <?=htmlspecialchars(formatAction($r['action']))?>
                                    </span>
                                </td>
                                <td class="entity-cell">
                                    <span style="opacity: 0.5; font-size: 0.8rem;"><?=htmlspecialchars(ucfirst(str_replace('_', ' ', $r['entity_type'])))?></span>
                                    <span style="font-weight: 600; color: #fff;">#<?=htmlspecialchars((string)($r['entity_id']??'—'))?></span>
                                </td>
                                <td style="color: <?= $r['reason'] ? '#e2e8f0' : 'var(--muted)' ?>;">
                                    <?=htmlspecialchars($r['reason'] ?: '—')?>
                                </td>
                                <td>
                                    <span class="req-id-badge" title="Request ID: <?=htmlspecialchars($r['request_id'])?>">
                                        <?=htmlspecialchars(mb_substr($r['request_id'], 0, 8))?>...
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach;?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div class="row" style="margin-top: 24px; justify-content: space-between; display: flex; align-items: center;">
            <span style="font-size: 0.85rem; color: var(--muted);">Page <b><?=$page?></b></span>
            <div style="display: flex; gap: 8px;">
                <a class="button secondary" href="?classroom_id=<?=$cid?>&page=<?=max(1,$page-1)?>" style="margin: 0; padding: 6px 16px;">Previous</a>
                <a class="button secondary" href="?classroom_id=<?=$cid?>&page=<?=$page+1?>" style="margin: 0; padding: 6px 16px;">Next</a>
            </div>
        </div>
    </section>
</main>
</body>
</html>
