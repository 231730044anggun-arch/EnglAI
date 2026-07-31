<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../src/Security/Csrf.php';

use EnglAI\Security\Csrf;

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionDirectory = dirname(__DIR__) . '/storage/sessions';
    if (!is_dir($sessionDirectory) && !mkdir($sessionDirectory, 0770, true) && !is_dir($sessionDirectory)) {
        throw new RuntimeException('Session directory tidak dapat dibuat.');
    }
    session_save_path($sessionDirectory);
    session_name('englai_admin');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

apply_security_headers(true);

function admin_is_authenticated(): bool
{
    $timeout = max(60, min(86400, (int) (env_value('ADMIN_SESSION_TIMEOUT_SECONDS', '28800') ?? '28800')));
    return isset($_SESSION['admin_authenticated_at'])
        && is_int($_SESSION['admin_authenticated_at'])
        && $_SESSION['admin_authenticated_at'] >= time() - $timeout;
}

function require_admin(): void
{
    if (!admin_is_authenticated()) {
        if (isset($_SESSION['admin_authenticated_at'])) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        header('Location: /admin/login.php');
        exit;
    }
    $_SESSION['admin_authenticated_at'] = time();
}
