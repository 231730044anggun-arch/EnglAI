<?php
require_once __DIR__ . '/../config/koneksi.php';
$pdo = db();

$pdo->exec("DELETE FROM speaking_recordings");
$pdo->exec("DELETE FROM speaking_sessions");
$pdo->exec("DELETE FROM learning_attempts WHERE activity_id IN (SELECT id FROM learning_activities WHERE skill='speaking')");

echo "Successfully reset all speaking sessions and recordings so students start fresh with Read Aloud tasks.\n";
