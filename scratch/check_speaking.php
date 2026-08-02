<?php
require_once __DIR__ . '/../config/koneksi.php';
$pdo = db();

$stmt = $pdo->query("SELECT id, level, activity_type, title, instruction, content_json FROM learning_activities WHERE skill='speaking' LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
