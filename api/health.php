<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/koneksi.php';

$database = 'unavailable';
try {
    db()->query('SELECT 1');
    $database = 'ok';
} catch (Throwable $e) {
    app_log('error', 'Health database check failed', ['request_id' => request_id(), 'exception' => get_class($e)]);
}

$aiConfigured = (getenv('GEMINI_API_KEY') ?: '') !== '';
$status = $database === 'ok' ? 200 : 503;
json_response([
    'success' => $status === 200,
    'status' => $status === 200 ? 'ok' : 'degraded',
    'checks' => [
        'database' => $database,
        'ai_configured' => $aiConfigured,
    ],
], $status);
