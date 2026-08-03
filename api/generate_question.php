<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Security/RateLimiter.php';

use EnglAI\AI\AIContentService;
use EnglAI\AI\ContentValidator;
use EnglAI\AI\FallbackProvider;
use EnglAI\AI\GeminiProvider;
use EnglAI\Security\RateLimiter;

function fail(string $message, int $status = 400): never
{
    json_response([
        'success' => false,
        'message' => $message
    ], $status);
}

function gemini_json(string $prompt): array
{
    $key = env_value('GEMINI_API_KEY') ?: '';
    if ($key === '') {
        fail('API key Gemini belum dikonfigurasi.', 500);
    }

    $primaryModel = env_value('GEMINI_MODEL') ?: 'gemini-3.5-flash-lite';

    // Fallback models to try if primary model hits quota/overload
    $fallbackModels = [
        'gemini-3.5-flash-lite',
        'gemini-3.5-flash',
        'gemini-3.6-flash',
        'gemini-2.0-flash-lite',
        'gemini-2.0-flash',
    ];

    // Build ordered list: primary first, then fallbacks (skip duplicates)
    $models = [$primaryModel];
    foreach ($fallbackModels as $fb) {
        if (!in_array($fb, $models, true)) {
            $models[] = $fb;
        }
    }

    $maxRetries = 2; // retries per model
    $lastError = '';

    foreach ($models as $model) {
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
                . rawurlencode($model)
                . ':generateContent';

            $payload = json_encode([
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => $prompt
                            ]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.8,
                    'responseMimeType' => 'application/json'
                ]
            ], JSON_UNESCAPED_UNICODE);

            $curl = curl_init($url);

            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-goog-api-key: ' . $key
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 45
            ]);

            $raw = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);

            curl_close($curl);

            if ($raw === false) {
                $lastError = 'Gagal menghubungi Gemini: ' . $error;
                continue;
            }

            // Retry on 429 (quota) or 503 (overloaded)
            if ($status === 429 || $status === 503) {
                $lastError = "Model $model: HTTP $status (quota/overload)";
                if ($attempt < $maxRetries) {
                    sleep(2 * ($attempt + 1)); // wait 2s, 4s
                }
                continue;
            }

            // For 429/503 after all retries, break to try next model
            if ($status < 200 || $status >= 300) {
                $lastError = "Model $model: HTTP $status. $raw";
                break; // try next model
            }

            $response = json_decode($raw, true);

            if (isset($response['error'])) {
                $lastError = $response['error']['message'] ?? 'Gemini Error';
                break; // try next model
            }

            $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text));
            $data = json_decode($text, true);

            if (!is_array($data)) {
                fail('Respons Gemini tidak valid.', 502);
            }

            return $data;
        }
    }

    fail('Semua model Gemini gagal. Terakhir: ' . $lastError, 502);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Gunakan metode POST.', 405);
}

$clientKey = ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|generate-question';
if (!RateLimiter::check($clientKey, 30, 60)) {
    fail('Terlalu banyak permintaan. Tunggu sebentar lalu coba kembali.', 429);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    fail('Payload tidak valid.');
}

$mode = ($input['mode'] ?? 'quiz') === 'speaking'
    ? 'speaking'
    : 'quiz';

$difficulty = in_array(
    $input['difficulty'] ?? '',
    ['easy', 'medium', 'hard'],
    true
)
    ? $input['difficulty']
    : 'medium';

$unit = max(1, min(99, (int)($input['unit'] ?? 1)));

try {
    $rpp = db()->query("
        SELECT id, original_name, extracted_text
        FROM rpps
        WHERE is_selected = 1
        ORDER BY id DESC
        LIMIT 1
    ")->fetch();
} catch (Throwable $e) {
    app_log('error', 'RPP database lookup failed', ['request_id' => request_id(), 'exception' => get_class($e)]);
    fail('Database RPP tidak dapat diakses.', 500);
}

if (!$rpp) {
    fail('Guru belum memilih RPP aktif. Pilih RPP melalui halaman admin.', 422);
}

$material = trim((string)$rpp['extracted_text']);

if ($material === '') {
    fail('Isi RPP aktif tidak dapat dibaca. Unggah RPP lain.', 422);
}

$material = mb_substr($material, 0, 30000);

if ($mode === 'speaking') {

    $prompt = "Anda adalah pelatih pronunciation bahasa Inggris untuk siswa SMP Indonesia.

Gunakan HANYA materi pembelajaran berikut.

Jangan mengambil informasi dari luar materi pembelajaran.

Buat SATU latihan pronunciation.

Kembalikan JSON valid saja:

{
\"phrase\":\"...\",
\"tips\":\"...\",
\"exp\":\"...\",
\"cat\":\"Pronunciation\",
\"dif\":\"{$difficulty}\",
\"u\":{$unit}
}

Materi Pembelajaran:
{$material}";

} else {

    $prompt = "Anda adalah pembuat soal Bahasa Inggris SMP.

Gunakan HANYA materi pembelajaran berikut.

Jangan mengambil informasi dari luar materi pembelajaran.

Buat tepat SATU soal pilihan ganda.

4 opsi.

Untuk meragamkan kunci jawaban, Anda harus merandom letak jawaban benar antara opsi A, B, C, atau D secara acak di setiap pemanggilan (misal: kadang jawaban yang benar di A, kadang di B, kadang di C, kadang di D). Jangan selalu menaruh jawaban benar di opsi A!

Kembalikan JSON valid saja dengan format berikut (di mana ans adalah opsi jawaban yang benar, bernilai 'A', 'B', 'C', atau 'D'):

{
\"u\":{$unit},
\"q\":\"...\",
\"op\":[\"A...\",\"B...\",\"C...\",\"D...\"],
\"ans\":\"A\",
\"exp\":\"...\",
\"cat\":\"Grammar\",
\"dif\":\"{$difficulty}\"
}

Materi Pembelajaran:
{$material}";
}

$provider = new GeminiProvider(
    env_value('GEMINI_API_KEY') ?: '',
    env_value('GEMINI_MODEL') ?: 'gemini-3.5-flash',
    max(5, min(60, (int) (env_value('GEMINI_TIMEOUT_SECONDS') ?: 45)))
);
$service = new AIContentService(
    $provider,
    new FallbackProvider(),
    new ContentValidator(),
    3,
    static function (string $level, string $message, array $context): void {
        $context['request_id'] = request_id();
        app_log($level, $message, $context);
    }
);
try {
    $serviceResponse = $service->generate($mode, $prompt, $unit, $difficulty);
} catch (Throwable $e) {
    app_log('error', 'AI and fallback generation failed', [
        'request_id' => request_id(),
        'mode' => $mode,
        'exception' => get_class($e),
    ]);
    fail('Materi AI dan fallback tidak tersedia. ID laporan: ' . request_id(), 503);
}
$result = $serviceResponse['data'];

$result['u'] = $unit;
$result['dif'] = $difficulty;

if (
    $mode === 'quiz' &&
    (
        !isset($result['q'], $result['op'], $result['ans']) ||
        !is_array($result['op']) ||
        count($result['op']) !== 4 ||
        !in_array($result['ans'], ['A', 'B', 'C', 'D'], true)
    )
) {
    fail('Format soal Gemini tidak lengkap.', 502);
}

if (
    $mode === 'speaking' &&
    !isset($result['phrase'])
) {
    fail('Format latihan Gemini tidak lengkap.', 502);
}

json_response([
    'success' => true,
    'source' => $serviceResponse['source'],
    'warning' => $serviceResponse['warning'] ?? null,
    'rpp_id' => (int)$rpp['id'],
    'data' => $result
]);
