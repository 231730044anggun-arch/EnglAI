<?php
require_once __DIR__ . '/../config/koneksi.php';
$pdo = db();
$cid = 2;
$level = 'basic';

$stmt = $pdo->prepare("SELECT COUNT(*) FROM learning_activities WHERE classroom_id=? AND skill='reading' AND level=? AND status='ready'");
$stmt->execute([$cid, $level]);
echo "Total basic reading activities in learning_activities: " . $stmt->fetchColumn() . "\n";

// Let's also check if there is an active lesson plan for classroom 2, and get its ID
$stmt = $pdo->prepare("SELECT id, is_active FROM classroom_lesson_plans WHERE classroom_id=?");
$stmt->execute([$cid]);
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

// Let's check if there is a module for reading basic in classroom 2
$stmt = $pdo->prepare("SELECT * FROM learning_modules WHERE classroom_id=? AND skill='reading' AND level=?");
$stmt->execute([$cid, $level]);
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
