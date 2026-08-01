<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/config/koneksi.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

$pdo = db();
$pdo->beginTransaction();
try {
    $email = 'student.test.' . bin2hex(random_bytes(5)) . '@example.test';
    $service = new EnglAI\Auth\AccountService($pdo);
    $id = $service->register('student', $email, 'Student Test', 'SecurePass123');
    $query = $pdo->prepare('SELECT role, password_hash FROM users WHERE id=?');
    $query->execute([$id]);
    $user = $query->fetch();
    if (!$user || $user['role'] !== 'student' || !password_verify('SecurePass123', (string)$user['password_hash'])) {
        throw new RuntimeException('Student registration assertion failed.');
    }
    $loggedIn = $service->login($email, 'SecurePass123', 'student');
    if ((int)$loggedIn['id'] !== $id) {
        throw new RuntimeException('Student login assertion failed.');
    }
    try {
        $service->register('student', $email, 'Duplicate Student', 'SecurePass123');
        throw new RuntimeException('Duplicate email was accepted.');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() === 'Duplicate email was accepted.') {
            throw $exception;
        }
    }
    echo "Student registration/login transaction test OK.\n";
} finally {
    $pdo->rollBack();
}
