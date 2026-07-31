<?php
declare(strict_types=1);

namespace EnglAI\LessonPlan;

final class RppTextCleaner
{
    public static function clean(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        $segments = preg_split('/(?<=[.!?])\s+|\n+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $cleaned = [];
        $previous = null;
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            $fingerprint = mb_strtolower(preg_replace('/\s+/u', ' ', $segment) ?? $segment);
            if ($fingerprint === $previous) {
                continue;
            }
            $cleaned[] = $segment;
            $previous = $fingerprint;
        }
        return trim(implode("\n", $cleaned));
    }
}
