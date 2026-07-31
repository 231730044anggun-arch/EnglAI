<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assets = [
    'assets/css/design-system.css',
    'assets/css/public.css',
    'assets/css/teacher.css',
    'assets/css/student.css',
    'assets/css/game.css',
    'assets/css/learning.css',
    'assets/js/visual-effects.js',
    'assets/js/public.js',
    'assets/js/teacher.js',
    'assets/js/student.js',
    'assets/js/self-learning.js',
    'assets/js/live-quiz.js',
    'assets/js/learning-activities.js',
];
foreach ($assets as $asset) {
    check(is_file($root . '/' . $asset), "Asset redesign tidak tersedia: {$asset}");
}

$activeFrontendFiles = array_merge(
    glob($root . '/assets/js/*.js') ?: [],
    [$root . '/index.php'],
    glob($root . '/admin/*.php') ?: [],
    glob($root . '/student/*.php') ?: []
);
foreach ($activeFrontendFiles as $file) {
    $source = (string) file_get_contents($file);
    check(
        !preg_match('/\b(innerHTML|insertAdjacentHTML|outerHTML|document\.write)\b/', $source),
        'Unsafe DOM API ditemukan: ' . basename($file)
    );
    check(
        !preg_match('/AIza[0-9A-Za-z_-]{20,}/', $source),
        'Gemini key marker ditemukan pada frontend: ' . basename($file)
    );
}

foreach ([
    'index.php',
    'admin/login.php',
    'admin/index.php',
    'admin/classroom.php',
    'admin/quiz.php',
    'student/dashboard.php',
    'student/self_learning.php',
    'student/quiz_join.php',
    'student/quiz_play.php',
    'student/skill.php',
    'student/activity.php',
    'student/progress.php',
] as $page) {
    $source = (string) file_get_contents($root . '/' . $page);
    check(str_contains($source, 'name="viewport"'), "Responsive viewport missing: {$page}");
    check(str_contains($source, '/assets/css/mvp.css'), "Design system entry missing: {$page}");
}

$public = (string) file_get_contents($root . '/index.php');
foreach (['/admin/login.php', '/student/join.php', '/public_demo.php', '#features', '#how'] as $link) {
    check(str_contains($public, $link), "Public feature link missing: {$link}");
}
$design = (string) file_get_contents($root . '/assets/css/design-system.css');
foreach (['Orbitron', 'Poppins', 'prefers-reduced-motion', ':focus-visible'] as $marker) {
    check(str_contains($design, $marker), "Accessibility/design marker missing: {$marker}");
}
