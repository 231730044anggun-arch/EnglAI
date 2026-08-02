<?php
require_once __DIR__ . '/../config/koneksi.php';
$pdo = db();

echo "speaking_sessions:\n";
$q = $pdo->query("SELECT id, classroom_id, member_id, level, status, completed_at FROM speaking_sessions");
print_r($q->fetchAll(PDO::FETCH_ASSOC));

echo "writing_sessions:\n";
$q = $pdo->query("SELECT id, classroom_id, member_id, level, status, completed_at FROM writing_sessions");
print_r($q->fetchAll(PDO::FETCH_ASSOC));
