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
    'assets/css/public-page-refresh.css',
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
foreach (['/admin/login.php', '/student/join.php', '#features', '#how'] as $link) {
    check(str_contains($public, $link), "Public feature link missing: {$link}");
}
foreach (['Transformasi Kelas Anda.', 'Hidupkan AI Classroom.', 'Join your classroom', 'Available in AI Classroom'] as $copy) {
    check(str_contains($public, $copy), "Public copy missing: {$copy}");
}
foreach (['In Development', 'Coming Soon', 'Akan hadir dalam phase', 'Masuk sebagai Teacher', 'hero-actions', 'Play Now', 'Want to try it first?', '/public_demo.php'] as $obsolete) {
    check(!str_contains($public, $obsolete), "Obsolete Public Page content remains: {$obsolete}");
}
check(is_file($root . '/student/continue.php'), 'Student access choice page missing.');
$studentChoice = (string) file_get_contents($root . '/student/continue.php');
check(!str_contains($studentChoice, 'Continue as Guest'), 'Guest option must not be exposed.');
$studentLogin = (string) file_get_contents($root . '/auth/student_login.php');
check(!str_contains($studentLogin, 'Continue as Guest'), 'Guest option must not be exposed on Student Login.');
foreach (['Student Login', 'Create Student Account', '/student/setup_profile.php'] as $marker) {
    check(str_contains($studentChoice, $marker), "Student authenticated flow marker missing: {$marker}");
}
$onboarding = (string) file_get_contents($root . '/student/setup_profile.php');
foreach (['Complete your classroom profile', 'Avatar selection', 'Display name', 'Continue to Classroom'] as $marker) {
    check(str_contains($onboarding, $marker), "Onboarding marker missing: {$marker}");
}
$teacherLogin = (string) file_get_contents($root . '/admin/login.php');
check(!str_contains($teacherLogin, 'Register Teacher'), 'Teacher registration must not be exposed.');
$register = (string) file_get_contents($root . '/auth/register.php');
check(str_contains($register, "if(\$role==='teacher'){header('Location: /admin/login.php')"), 'Teacher registration route must redirect.');
$publicNav = substr($public, strpos($public, '<nav class="nav-links"'), strpos($public, '</nav>') - strpos($public, '<nav class="nav-links"'));
check(!str_contains($publicNav, 'Play Now'), 'Play Now must not remain in the navbar.');
$classroomPage = (string) file_get_contents($root . '/admin/classroom.php');
foreach (['#overview', '#lesson-plan', '#self-learning', '#live-quiz', '/assets/js/classroom-tabs.js'] as $tabMarker) {
    check(str_contains($classroomPage, $tabMarker), "Teacher classroom tab missing: {$tabMarker}");
}
$design = (string) file_get_contents($root . '/assets/css/design-system.css');
foreach (['Orbitron', 'Poppins', 'prefers-reduced-motion', ':focus-visible'] as $marker) {
    check(str_contains($design, $marker), "Accessibility/design marker missing: {$marker}");
}
