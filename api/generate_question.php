<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/koneksi.php';

function fail(string $message, int $status = 400): never
{
    json_response([
        'success' => false,
        'message' => $message
    ], $status);
}

function gemini_json(string $prompt): array
{
    $key = getenv('GEMINI_API_KEY') ?: '';
    if ($key === '') {
        fail('API key Gemini belum dikonfigurasi.', 500);
    }

    $model = 'gemini-flash-latest';

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
        fail('Gagal menghubungi Gemini: ' . $error, 502);
    }

    if ($status < 200 || $status >= 300) {
        fail('Gemini mengembalikan HTTP ' . $status . '. ' . $raw, 502);
    }

    $response = json_decode($raw, true);

    if (isset($response['error'])) {
        fail($response['error']['message'] ?? 'Gemini Error', 502);
    }

    $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';

    $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text));

    $data = json_decode($text, true);

    if (!is_array($data)) {
        fail('Respons Gemini tidak valid.', 502);
    }

    return $data;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Gunakan metode POST.', 405);
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

Gunakan HANYA materi RPP berikut.

Jangan mengambil informasi dari luar RPP.

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

RPP:
{$material}";

} else {

    $prompt = "Anda adalah pembuat soal Bahasa Inggris SMP.

Gunakan HANYA materi RPP berikut.

Jangan mengambil informasi dari luar RPP.

Buat tepat SATU soal pilihan ganda.

4 opsi.
1 jawaban benar.

Kembalikan JSON valid saja:

{
\"u\":{$unit},
\"q\":\"...\",
\"op\":[\"A...\",\"B...\",\"C...\",\"D...\"],
\"ans\":\"A\",
\"exp\":\"...\",
\"cat\":\"Grammar\",
\"dif\":\"{$difficulty}\"
}

RPP:
{$material}";
}

$result = gemini_json($prompt);

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
    'rpp_id' => (int)$rpp['id'],
    'data' => $result
]);