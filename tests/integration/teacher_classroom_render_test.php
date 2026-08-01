<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
require $root . '/config/koneksi.php';
$classroom = db()->query('SELECT id, teacher_key FROM classrooms ORDER BY id LIMIT 1')->fetch();
if (!$classroom) {
    throw new RuntimeException('Teacher classroom render test requires one classroom.');
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_save_path($root . '/storage/sessions');
    session_name('englai_admin');
    session_start();
}
$_SESSION['admin_authenticated_at'] = time();
$_SESSION['admin_username'] = (string)$classroom['teacher_key'];
$_GET['id'] = (int)$classroom['id'];
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
require $root . '/admin/classroom.php';
$html = (string)ob_get_clean();
foreach (['tab-lesson-plan', 'tab-self-learning', 'tab-live-quiz', 'tab-students', '/assets/js/classroom-tabs.js', 'Belum ada aktivitas'] as $marker) {
    if (!str_contains($html, $marker)) {
        throw new RuntimeException('Teacher classroom marker missing: ' . $marker);
    }
}
echo "Teacher classroom render/tab test OK.\n";
