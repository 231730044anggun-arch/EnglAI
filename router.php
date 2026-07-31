<?php
declare(strict_types=1);

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$blocked = [
    '/.env',
    '/.git',
    '/.agents',
    '/config',
    '/database',
    '/docs',
    '/scripts',
    '/src',
    '/storage',
    '/tests',
    '/uploads',
    '/vendor',
];

foreach ($blocked as $prefix) {
    if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
        http_response_code(404);
        exit('Not found.');
    }
}

$file = __DIR__ . ($path === '/' ? '/index.php' : $path);
if (is_dir($file) && is_file(rtrim($file, '/\\') . '/index.php')) {
    require rtrim($file, '/\\') . '/index.php';
    return true;
}
if (is_file($file)) {
    return false;
}

http_response_code(404);
echo 'Not found.';
