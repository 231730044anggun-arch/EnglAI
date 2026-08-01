<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
require $root . '/config/koneksi.php';
require $root . '/vendor/autoload.php';
$pdo = db();
$classroom = $pdo->query("SELECT code FROM classrooms ORDER BY id LIMIT 1")->fetch();
if (!$classroom) throw new RuntimeException('Onboarding test requires an active classroom.');
$pdo->beginTransaction();
try {
    $service = new EnglAI\Auth\AccountService($pdo);
    $email = 'onboarding.' . bin2hex(random_bytes(5)) . '@example.test';
    $userId = $service->register('student', $email, 'Onboarding Student', 'SecurePass123');
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_role'] = 'student';
    $_SESSION['user_name'] = 'Onboarding Student';
    $_GET['code'] = (string)$classroom['code'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    require $root . '/student/setup_profile.php';
    $html = (string)ob_get_clean();
    foreach (['Complete your classroom profile', 'name="avatar"', 'name="nickname"', 'Continue to Classroom'] as $marker) {
        if (!str_contains($html, $marker)) throw new RuntimeException('Onboarding marker missing: ' . $marker);
    }
    if (str_contains($html, 'Continue as Guest')) throw new RuntimeException('Guest option exposed in onboarding.');
    echo "Student onboarding render test OK.\n";
} finally {
    $pdo->rollBack();
}
