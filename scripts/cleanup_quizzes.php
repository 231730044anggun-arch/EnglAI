<?php
declare(strict_types=1);require_once __DIR__.'/../config/koneksi.php';
$q=db()->exec("UPDATE quiz_sessions SET state='CANCELLED',finished_at=NOW() WHERE state IN('DRAFT','GENERATING','LOBBY','COUNTDOWN','ACTIVE','BETWEEN_QUESTIONS','EVALUATING') AND created_at<DATE_SUB(NOW(),INTERVAL 12 HOUR)");
echo"Cancelled abandoned quiz sessions: {$q}\n";
