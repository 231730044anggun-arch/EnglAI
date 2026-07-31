<?php
declare(strict_types=1);

namespace EnglAI\AI;

interface ContentProvider
{
    /** @return array<string,mixed> */
    public function generate(string $prompt): array;
}
