<?php
declare(strict_types=1);

namespace EnglAI\AI;

final class GeminiProvider implements ContentProvider
{
    /** @var callable(string,array<string,mixed>,int):array{status:int,body:string,error:string} */
    private $transport;

    /** @param null|callable(string,array<string,mixed>,int):array{status:int,body:string,error:string} $transport */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeoutSeconds = 45,
        ?callable $transport = null
    ) {
        $this->transport = $transport ?? [$this, 'curlTransport'];
    }

    public function generate(string $prompt): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('AI provider is not configured.');
        }
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($this->model) . ':generateContent';
        $payload = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.8, 'responseMimeType' => 'application/json'],
        ];
        $response = ($this->transport)($url, $payload, $this->timeoutSeconds);
        if ($response['status'] < 200 || $response['status'] >= 300 || $response['body'] === '') {
            throw new \RuntimeException('AI provider request failed (HTTP '.(int)$response['status'].').');
        }
        $envelope = json_decode($response['body'], true);
        if (!is_array($envelope) || isset($envelope['error'])) {
            throw new \RuntimeException('AI provider returned an invalid envelope.');
        }
        $text = $envelope['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($text) || $text === '') {
            throw new \RuntimeException('AI provider response has no content.');
        }
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text)) ?? trim($text);
        $data = json_decode($text, true);
        if (!is_array($data)) {
            throw new ContentValidationException('AI provider content is malformed.');
        }
        return $data;
    }

    /** @return array{status:int,body:string,error:string} */
    private function curlTransport(string $url, array $payload, int $timeoutSeconds): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-goog-api-key: ' . $this->apiKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => max(5, min(60, $timeoutSeconds)),
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        return ['status' => $status, 'body' => is_string($body) ? $body : '', 'error' => $error];
    }
}
