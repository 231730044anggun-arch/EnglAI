<?php
declare(strict_types=1);
require_once __DIR__.'/_auth.php';
require_once __DIR__.'/../vendor/autoload.php';

use EnglAI\AI\GeminiProvider;
use EnglAI\Mvp\ClassroomService;
use EnglAI\Quiz\LiveQuizBankGenerator;
use EnglAI\Security\Csrf;

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

Csrf::requireValid($_POST['csrf_token'] ?? null);

$id      = (int)($_POST['classroom_id'] ?? 0);
$level   = (string)($_POST['level'] ?? 'intermediate');
$mode    = (string)($_POST['mode'] ?? 'append');   // 'append' | 'clear'
$teacher = (string)($_SESSION['admin_username'] ?? env_value('ADMIN_USERNAME', 'admin'));

try {
    (new ClassroomService(db()))->requireOwned($id, $teacher);

    // Inject Gemini provider if API key is configured
    $apiKey  = (string)env_value('GEMINI_API_KEY', '');
    $model   = (string)env_value('GEMINI_MODEL', 'gemini-2.5-flash');
    $timeout = (int)(env_value('GEMINI_TIMEOUT_SECONDS', '45') ?? 45);

    $gemini  = $apiKey !== '' ? new GeminiProvider($apiKey, $model, $timeout) : null;
    $service = new LiveQuizBankGenerator(db(), $gemini);

    $skill = (string)($_POST['skill'] ?? 'all');

    if ($mode === 'clear') {
        // Delete old items and regenerate fresh
        $result   = $skill === 'all'
            ? $service->clearAllAndRegenerate($id, $level)
            : [$skill => $service->clearAndRegenerate($id, $skill, $level)];
        $modeLabel = 'Generate Ulang';
    } else {
        // Just fill up to target (existing items are kept)
        $result   = $skill === 'all'
            ? $service->generateAll($id, $level)
            : [$skill => $service->generate($id, $skill, $level)];
        $modeLabel = 'Tambah';
    }

    $total   = array_sum(array_column($result, 'created'));
    $aiTotal = array_sum(array_column($result, 'ai_count'));

    if ($gemini !== null) {
        $message = "{$modeLabel} Live Quiz bank {$level}: {$total} item baru tersimpan ({$aiTotal} via Gemini AI, sisanya fallback).";
    } else {
        $message = "{$modeLabel} Live Quiz bank {$level}: {$total} item tersimpan (fallback — GEMINI_API_KEY belum diisi).";
    }
} catch (Throwable $e) {
    $message = $e->getMessage();
}

header('Location: /admin/quiz_wizard.php?classroom_id='.$id.'&message='.rawurlencode($message));
exit;
