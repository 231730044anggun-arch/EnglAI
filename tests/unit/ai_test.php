<?php
declare(strict_types=1);

use EnglAI\AI\AIContentService;
use EnglAI\AI\ContentProvider;
use EnglAI\AI\ContentValidator;
use EnglAI\AI\FallbackProvider;
use EnglAI\AI\GeminiProvider;

final class SequenceProvider implements ContentProvider
{
    public int $calls = 0;
    /** @param list<mixed> $responses */
    public function __construct(private array $responses)
    {
    }
    public function generate(string $prompt): array
    {
        $response = $this->responses[min($this->calls, count($this->responses) - 1)];
        $this->calls++;
        if ($response instanceof Throwable) {
            throw $response;
        }
        return $response;
    }
}

$validQuiz = ['u'=>1,'q'=>'Question?','op'=>['A. One','B. Two','C. Three','D. Four'],'ans'=>'A','exp'=>'Explanation','cat'=>'Grammar','dif'=>'easy'];
$validSpeaking = ['u'=>1,'phrase'=>'Speak clearly.','tips'=>'Be clear.','dif'=>'easy'];

$provider = new SequenceProvider([$validQuiz]);
$safeLogs = [];
$service = new AIContentService($provider, new FallbackProvider(), new ContentValidator(), 3, static function (string $level, string $message, array $context) use (&$safeLogs): void {
    $safeLogs[] = compact('level', 'message', 'context');
});
$response = $service->generate('quiz', 'prompt', 1, 'easy');
check($response['source'] === 'ai' && $response['success'] === true, 'Valid AI response harus menggunakan source ai.');
check(in_array($response['data']['ans'], ['A','B','C','D'], true), 'AI answer harus menunjuk option.');
check(count($safeLogs) === 1 && !str_contains(json_encode($safeLogs), 'mock-key'), 'AI log harus structured dan tidak memuat API key.');

$timeout = new SequenceProvider([new RuntimeException('timeout')]);
$response = (new AIContentService($timeout, new FallbackProvider(), new ContentValidator(), 3))->generate('quiz', 'prompt', 1, 'easy');
check($timeout->calls === 3, 'Timeout harus dicoba maksimal tiga kali.');
check($response['source'] === 'fallback' && $response['success'] === true, 'Timeout harus menggunakan backend fallback.');
check(isset($response['warning']), 'Fallback response harus memiliki warning.');

$malformed = new SequenceProvider([['q'=>'bad','op'=>['one'],'ans'=>'Z','exp'=>'','cat'=>'Grammar']]);
$response = (new AIContentService($malformed, new FallbackProvider(), new ContentValidator(), 3))->generate('quiz', 'prompt', 1, 'easy');
check($malformed->calls === 3 && $response['source'] === 'fallback', 'Malformed AI response harus divalidasi, di-retry, lalu fallback.');

$failedSpeaking = new SequenceProvider([new RuntimeException('provider error')]);
$response = (new AIContentService($failedSpeaking, new FallbackProvider(), new ContentValidator(), 3))->generate('speaking', 'prompt', 3, 'easy');
check($response['source'] === 'fallback' && isset($response['data']['phrase']), 'Speaking harus mempunyai backend fallback dengan schema speaking.');

$transport = static function (): array {
    $content = ['u'=>1,'q'=>'Mock?','op'=>['A. A','B. B','C. C','D. D'],'ans'=>'B','exp'=>'Mock','cat'=>'Grammar','dif'=>'easy'];
    return ['status'=>200,'body'=>json_encode(['candidates'=>[['content'=>['parts'=>[['text'=>json_encode($content)]]]]]]),'error'=>''];
};
$gemini = new GeminiProvider('mock-key-not-secret', 'mock-model', 5, $transport);
check($gemini->generate('prompt')['ans'] === 'B', 'Mock Gemini success harus diparse.');

$providerError = new GeminiProvider('invalid-key', 'mock-model', 5, static fn(): array => ['status'=>401,'body'=>'{"error":{"message":"invalid"}}','error'=>'']);
$thrown = false;
try { $providerError->generate('prompt'); } catch (Throwable) { $thrown = true; }
check($thrown, 'Provider HTTP error harus ditolak tanpa raw response.');

$malformedProvider = new GeminiProvider('mock-key', 'mock-model', 5, static fn(): array => ['status'=>200,'body'=>'{"candidates":[{"content":{"parts":[{"text":"not-json"}]}}]}','error'=>'']);
$thrown = false;
try { $malformedProvider->generate('prompt'); } catch (Throwable) { $thrown = true; }
check($thrown, 'Malformed provider content harus ditolak.');
