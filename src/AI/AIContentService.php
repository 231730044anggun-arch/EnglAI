<?php
declare(strict_types=1);

namespace EnglAI\AI;

final class AIContentService
{
    /** @var null|callable(string,string,array<string,mixed>):void */
    private $logger;

    public function __construct(
        private readonly ContentProvider $provider,
        private readonly FallbackProvider $fallback,
        private readonly ContentValidator $validator,
        private readonly int $maxAttempts = 3,
        ?callable $logger = null
    ) {
        $this->logger = $logger;
    }

    /** @return array{success:true,source:string,warning?:string,data:array<string,mixed>,attempts:int} */
    public function generate(string $mode, string $prompt, int $unit, string $difficulty): array
    {
        $lastError = null;
        $attempts = max(1, min(3, $this->maxAttempts));
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $data = $this->validator->validate($mode, $this->provider->generate($prompt));
                $this->log('info', 'AI content generated', ['attempt' => $attempt, 'mode' => $mode]);
                return ['success' => true, 'source' => 'ai', 'data' => $data, 'attempts' => $attempt];
            } catch (\Throwable $e) {
                $lastError = $e;
                $this->log('warning', 'AI content attempt failed', ['attempt' => $attempt, 'mode' => $mode, 'exception' => get_class($e)]);
            }
        }
        $fallback = $this->fallback->for($mode, $unit, $difficulty);
        if ($fallback !== null) {
            return [
                'success' => true,
                'source' => 'fallback',
                'warning' => 'AI content is temporarily unavailable.',
                'data' => $this->validator->validate($mode, $fallback),
                'attempts' => $attempts,
            ];
        }
        throw new \RuntimeException('No valid AI or fallback content is available.', 0, $lastError);
    }

    /** @param array<string,mixed> $context */
    private function log(string $level, string $message, array $context): void
    {
        if ($this->logger !== null) {
            ($this->logger)($level, $message, $context);
        }
    }
}
