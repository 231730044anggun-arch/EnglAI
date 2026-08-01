<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../vendor/autoload.php';

$pdo = db();

echo "Cleaning up Reading data for ALL Classrooms...\n";

$pdo->beginTransaction();
try {
    // 1. Delete all reading attempts
    $pdo->exec("DELETE FROM reading_attempts");
    echo "- Deleted all reading attempts.\n";

    // 2. Delete all reading sessions
    $pdo->exec("DELETE FROM reading_sessions");
    echo "- Deleted all reading sessions.\n";

    // 3. Delete all learning activities with skill = 'reading'
    $pdo->exec("DELETE FROM learning_activities WHERE skill = 'reading'");
    echo "- Deleted all learning activities for skill 'reading'.\n";

    // 4. Reset all student skill progress for 'reading'
    $pdo->exec("DELETE FROM student_skill_progress WHERE skill = 'reading'");
    echo "- Reset all student skill progress for 'reading'.\n";

    $pdo->commit();
    echo "SUCCESS: Reading data cleared for ALL classrooms!\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    echo "ERROR: Global cleanup failed: " . $e->getMessage() . "\n";
}
