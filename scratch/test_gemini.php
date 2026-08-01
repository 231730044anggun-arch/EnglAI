<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../vendor/autoload.php';

use EnglAI\AI\GeminiProvider;

$key = (string)env_value('GEMINI_API_KEY', '');
$model = (string)env_value('GEMINI_MODEL', 'gemini-2.5-flash');

echo "Testing Gemini Connection...\n";
echo "API Key: " . substr($key, 0, 8) . "...\n";
echo "Model: " . $model . "\n\n";

if ($key === '') {
    echo "ERROR: GEMINI_API_KEY is empty in .env!\n";
    exit(1);
}

// Custom Curl request to dump detailed HTTP response
$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
$payload = [
    'contents' => [['parts' => [['text' => 'Hello. Reply with only one word: OK.']]]],
    'generationConfig' => ['temperature' => 0.8, 'responseMimeType' => 'application/json'],
];

$curl = curl_init($url);
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-goog-api-key: ' . $key],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_SSL_VERIFYPEER => false, // bypass SSL just in case certificate is missing in Laragon
]);

$body = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
$error = curl_error($curl);
curl_close($curl);

echo "HTTP Status Code: " . $status . "\n";
if ($error) {
    echo "CURL Error: " . $error . "\n";
} else {
    echo "Response Body:\n" . $body . "\n";
}
