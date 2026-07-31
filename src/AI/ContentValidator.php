<?php
declare(strict_types=1);

namespace EnglAI\AI;

final class ContentValidator
{
    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function validate(string $mode, array $data): array
    {
        return $mode === 'speaking' ? $this->validateSpeaking($data) : $this->validateQuiz($data);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function validateQuiz(array $data): array
    {
        foreach (['q', 'op', 'ans', 'exp', 'cat'] as $field) {
            if (!array_key_exists($field, $data)) {
                throw new ContentValidationException("Field quiz {$field} tidak tersedia.");
            }
        }
        if (!is_string($data['q']) || trim($data['q']) === '') {
            throw new ContentValidationException('Pertanyaan quiz kosong.');
        }
        if (!is_array($data['op']) || count($data['op']) !== 4) {
            throw new ContentValidationException('Quiz harus memiliki tepat empat option.');
        }
        $options = array_values($data['op']);
        foreach ($options as $option) {
            if (!is_string($option) || trim($option) === '') {
                throw new ContentValidationException('Option quiz harus berupa string non-kosong.');
            }
        }
        if (count(array_unique(array_map(static fn(string $value): string => mb_strtolower(trim($value)), $options))) !== 4) {
            throw new ContentValidationException('Option quiz harus unik.');
        }
        $answer = strtoupper(trim((string) $data['ans']));
        if (!in_array($answer, ['A', 'B', 'C', 'D'], true)) {
            throw new ContentValidationException('Kunci jawaban quiz tidak menunjuk pada option valid.');
        }
        return [
            'u' => max(1, min(99, (int) ($data['u'] ?? 1))),
            'q' => trim($data['q']),
            'op' => array_map('trim', $options),
            'ans' => $answer,
            'exp' => trim((string) $data['exp']),
            'cat' => trim((string) $data['cat']) ?: 'Grammar',
            'dif' => $this->difficulty($data['dif'] ?? 'medium'),
        ];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function validateSpeaking(array $data): array
    {
        if (!isset($data['phrase']) || !is_string($data['phrase']) || trim($data['phrase']) === '') {
            throw new ContentValidationException('Speaking phrase kosong.');
        }
        return [
            'u' => max(1, min(99, (int) ($data['u'] ?? 1))),
            'phrase' => trim($data['phrase']),
            'tips' => trim((string) ($data['tips'] ?? 'Ucapkan dengan jelas.')),
            'exp' => trim((string) ($data['exp'] ?? '')),
            'cat' => 'Speaking',
            'dif' => $this->difficulty($data['dif'] ?? 'medium'),
        ];
    }

    private function difficulty(mixed $value): string
    {
        $value = is_string($value) ? strtolower(trim($value)) : 'medium';
        return in_array($value, ['easy', 'medium', 'hard'], true) ? $value : 'medium';
    }
}
