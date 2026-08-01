<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/config/koneksi.php';
require_once dirname(__DIR__) . '/src/LessonPlan/RppTextCleaner.php';
require_once dirname(__DIR__) . '/src/Security/Csrf.php';

use EnglAI\LessonPlan\RppTextCleaner;
use EnglAI\Security\Csrf;

$failures = [];
function check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

$cleaned = RppTextCleaner::clean('Birds &amp; habitats. Birds &amp; habitats.  Passive   voice.');
check(substr_count($cleaned, 'Birds & habitats.') === 1, 'Cleaner harus menghapus duplikasi berurutan.');
check(str_contains($cleaned, 'Passive voice.'), 'Cleaner harus menormalisasi whitespace.');

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionDirectory = dirname(__DIR__) . '/storage/sessions';
    if (!is_dir($sessionDirectory)) {
        mkdir($sessionDirectory, 0770, true);
    }
    session_save_path($sessionDirectory);
    session_start();
}
$token = Csrf::token();
check(strlen($token) === 64, 'CSRF token harus 32-byte hex.');
check(Csrf::validate($token), 'CSRF token yang benar harus valid.');
check(!Csrf::validate('invalid'), 'CSRF token yang salah harus ditolak.');

$envFixture = dirname(__DIR__) . '/storage/cache/test-env-' . bin2hex(random_bytes(4));
file_put_contents($envFixture, "VERIFICATION_ENV_VALUE=loaded\n");
load_env_file($envFixture);
check(env_value('VERIFICATION_ENV_VALUE') === 'loaded', 'Environment loader harus memuat value valid.');
unlink($envFixture);
putenv('VERIFICATION_ENV_VALUE');

$firstRequestId = request_id();
check(strlen($firstRequestId) === 16 && $firstRequestId === request_id(), 'Request ID harus stabil dan 8-byte hex per request.');
$logMarker = 'verification-' . bin2hex(random_bytes(4));
app_log('info', 'Structured logger verification', ['marker' => $logMarker]);
$logPath = dirname(__DIR__) . '/storage/logs/englai-' . gmdate('Y-m-d') . '.log';
$logLines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$lastLog = json_decode((string) end($logLines), true);
check(is_array($lastLog) && ($lastLog['context']['marker'] ?? '') === $logMarker && ($lastLog['level'] ?? '') === 'INFO', 'Structured logger harus menulis JSON valid beserta context.');

putenv('ADMIN_SESSION_TIMEOUT_SECONDS=60');
require_once dirname(__DIR__) . '/admin/_auth.php';
$_SESSION['admin_authenticated_at'] = time() - 61;
check(!admin_is_authenticated(), 'Admin session timeout harus menolak session lama.');
unset($_SESSION['admin_authenticated_at']);
putenv('ADMIN_SESSION_TIMEOUT_SECONDS');

require __DIR__ . '/unit/ai_test.php';
require __DIR__ . '/unit/upload_test.php';
require __DIR__ . '/unit/rate_limiter_test.php';
require __DIR__ . '/unit/frontend_redesign_test.php';
require __DIR__ . '/unit/release_test.php';
require __DIR__ . '/unit/reading_self_learning_test.php';

if ($failures) {
    fwrite(STDERR, "Test gagal:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Unit smoke tests OK.\n";
