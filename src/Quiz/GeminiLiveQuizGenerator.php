<?php
declare(strict_types=1);
namespace EnglAI\Quiz;

use EnglAI\AI\GeminiProvider;

/**
 * Generates live quiz items in batch using the Gemini AI API,
 * based on the classroom's active lesson plan (RPP) content.
 */
final class GeminiLiveQuizGenerator
{
    private const MAX_ATTEMPTS = 2;

    public function __construct(private readonly GeminiProvider $gemini) {}

    /**
     * Generate a batch of quiz items for the given skill/level using Gemini.
     * Returns null or empty array if AI generation fails.
     *
     * @return list<array<string,mixed>>|null
     */
    public function generateBatch(string $skill, string $level, int $startN, int $count, string $rppExcerpt): ?array
    {
        $prompt = $this->buildBatchPrompt($skill, $level, $startN, $count, $rppExcerpt);

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $raw = $this->gemini->generate($prompt);
                $items = array_is_list($raw) ? $raw : ($raw['items'] ?? $raw['questions'] ?? []);
                if (!is_array($items) || count($items) === 0) {
                    continue;
                }
                
                $parsed = [];
                foreach ($items as $idx => $rawItem) {
                    if (!is_array($rawItem)) continue;
                    $itemNum = $startN + $idx;
                    $parsedItem = $this->parseItem($skill, $level, $itemNum, $rawItem);
                    if ($parsedItem !== null) {
                        $parsed[] = $parsedItem;
                    }
                }
                if (count($parsed) > 0) {
                    return $parsed;
                }
            } catch (\Throwable) {
                // try again or fall through
            }
        }

        return null;
    }

    private function buildBatchPrompt(string $skill, string $level, int $startN, int $count, string $rppExcerpt): string
    {
        $difficulty = match ($level) {
            'basic'    => 'easy (suitable for beginners)',
            'advanced' => 'hard (challenging, requires deep understanding)',
            default    => 'medium (intermediate level)',
        };

        $excerpt = mb_substr($rppExcerpt, 0, 3000);

        return match ($skill) {
            'reading'   => $this->readingPrompt($startN, $count, $difficulty, $excerpt),
            'listening' => $this->listeningPrompt($startN, $count, $difficulty, $excerpt),
            'speaking'  => $this->speakingPrompt($startN, $count, $difficulty, $excerpt),
            default     => $this->writingPrompt($startN, $count, $difficulty, $excerpt),
        };
    }

    private function readingPrompt(int $startN, int $count, string $difficulty, string $excerpt): string
    {
        return <<<PROMPT
You are an English quiz generator for Indonesian junior/senior high school students.
Study the lesson plan (RPP) excerpt carefully, then generate exactly {$count} reading comprehension multiple-choice questions.

LESSON PLAN EXCERPT:
{$excerpt}

STRICT REQUIREMENTS:
- Total items to generate: {$count}. Start numbering sequence from item index {$startN}.
- For each item, write a SHORT passage (3–5 sentences) about a SPECIFIC topic, animal, person, or event mentioned in the RPP.
- The question must ask about a SPECIFIC DETAIL from the passage:
  * A name (of a bird, person, character, place)
  * A fact (what the animal eats, where it lives, what it looks like)
  * A grammar point from the lesson (e.g. passive voice usage)
  * A vocabulary meaning
- DO NOT ask generic questions like "What is the main idea?" or "What is the best summary?".
- The correct answer keys MUST rotate (A, B, C, D) so that not all questions have the same answer.
- All 4 options must be plausible but clearly only one is correct.
- Return a JSON array of objects only.

Respond ONLY with a valid JSON array, no markdown fences:
[
  {
    "passage": "3–5 sentence English passage about a specific topic from the RPP",
    "question": "specific detail question about the passage",
    "options": ["option text", "option text", "option text", "option text"],
    "answer": "A, B, C, or D",
    "explanation": "1 sentence explaining why the answer is correct"
  }
]
PROMPT;
    }

    private function listeningPrompt(int $startN, int $count, string $difficulty, string $excerpt): string
    {
        return <<<PROMPT
You are an English listening quiz generator for Indonesian junior/senior high school students.
Study the lesson plan (RPP) excerpt carefully, then generate exactly {$count} listening multiple-choice questions.

LESSON PLAN EXCERPT:
{$excerpt}

STRICT REQUIREMENTS:
- Total items to generate: {$count}. Start numbering sequence from item index {$startN}.
- For each item, write a SHORT audio script (3–5 sentences): a dialogue or monologue featuring SPECIFIC names, places, or animals from the RPP.
- The question must ask about a SPECIFIC DETAIL from the audio:
  * Who said something / who did something
  * Where a character went or what they saw
  * A specific fact mentioned (name of animal, its habitat, what it eats)
- DO NOT ask generic questions like "What is the main focus?".
- The correct answer keys MUST rotate (A, B, C, D).
- Return a JSON array of objects only.

Respond ONLY with a valid JSON array, no markdown fences:
[
  {
    "script": "3–5 sentence audio script with specific names/facts from the RPP",
    "language": "en-US",
    "rate": 0.95,
    "question": "specific detail question about who/what/where in the audio",
    "options": ["option text", "option text", "option text", "option text"],
    "answer": "A, B, C, or D",
    "explanation": "1 sentence explaining why the answer is correct"
  }
]
PROMPT;
    }

    private function speakingPrompt(int $startN, int $count, string $difficulty, string $excerpt): string
    {
        $minWords = match (true) {
            str_contains($difficulty, 'easy') => 8,
            str_contains($difficulty, 'hard') => 20,
            default => 15,
        };
        $duration = str_contains($difficulty, 'hard') ? 90 : 60;

        return <<<PROMPT
You are an English speaking task generator for Indonesian junior/senior high school students.
Study the lesson plan (RPP) excerpt carefully, then generate exactly {$count} speaking tasks.

LESSON PLAN EXCERPT:
{$excerpt}

STRICT REQUIREMENTS:
- Total items to generate: {$count}. Start numbering sequence from item index {$startN}.
- The prompt MUST be a specific English sentence of 8-15 words based on the RPP lesson content for the student to read aloud. Do NOT make it a question.
- Return a JSON array of objects only.

Respond ONLY with a valid JSON array, no markdown fences:
[
  {
    "prompt": "The specific English sentence to be read aloud, grounded in the lesson plan",
    "scenario": "Read the sentence aloud with clear pronunciation.",
    "keywords": ["keyword1", "keyword2", "keyword3"],
    "minimum_words": {$minWords},
    "response_duration": {$duration}
  }
]
PROMPT;
    }

    private function writingPrompt(int $startN, int $count, string $difficulty, string $excerpt): string
    {
        [$minW, $maxW] = match (true) {
            str_contains($difficulty, 'easy') => [20, 80],
            str_contains($difficulty, 'hard') => [80, 220],
            default => [45, 150],
        };

        return <<<PROMPT
You are an English writing task generator for Indonesian junior/senior high school students.
Study the lesson plan (RPP) excerpt carefully, then generate exactly {$count} writing tasks.

LESSON PLAN EXCERPT:
{$excerpt}

STRICT REQUIREMENTS:
- Total items to generate: {$count}. Start numbering sequence from item index {$startN}.
- The prompt MUST be framed either as a **5W + 1H question series** (Who, What, Where, When, Why, How) or a **story-based / narrative scenario** (soal cerita) based on the RPP lesson material.
- E.g. "Imagine you are Galang going birdwatching in Papua. Write a story about: (1) Who did you go with? (2) What bird did you see? ...", or "Answer these 5W+1H questions to write a descriptive paragraph...".
- Word limit: {$minW}–{$maxW} words.
- Return a JSON array of objects only.

Respond ONLY with a valid JSON array, no markdown fences:
[
  {
    "prompt": "story-based prompt or a structured 5W+1H question prompt based on the RPP content",
    "context": "brief instruction or scenario setting for the student",
    "minimum_words": {$minW},
    "maximum_words": {$maxW}
  }
]
PROMPT;
    }

    /** Rotate answer key A→B→C→D based on item number */
    private function rotateAnswer(int $n): string
    {
        return ['A', 'B', 'C', 'D'][($n - 1) % 4];
    }

    /**
     * Parse and validate a single Gemini item response into the live_quiz_items content_json shape.
     */
    private function parseItem(string $skill, string $level, int $n, array $raw): ?array
    {
        $common = [
            'skill'      => $skill,
            'level'      => $level,
            'competency' => 'RPP-aligned competency',
            'sequence'   => $n,
        ];

        if ($skill === 'reading') {
            $passage     = trim((string)($raw['passage'] ?? ''));
            $question    = trim((string)($raw['question'] ?? ''));
            $options     = $raw['options'] ?? [];
            $answer      = strtoupper(trim((string)($raw['answer'] ?? '')));
            $explanation = trim((string)($raw['explanation'] ?? ''));

            if ($passage === '' || $question === '' || !is_array($options) || count($options) !== 4) {
                return null;
            }
            if (!in_array($answer, ['A', 'B', 'C', 'D'], true)) {
                $answer = $this->rotateAnswer($n);
            }
            $optArr = array_values(array_map('strval', $options));

            return [
                'type'    => 'objective',
                'title'   => "Reading Challenge {$n}",
                'prompt'  => $question,
                'answer'  => $answer,
                'rubric'  => [],
                'content' => $common + [
                    'passage'     => $passage,
                    'question'    => $question,
                    'options'     => $optArr,
                    'answer'      => $answer,
                    'explanation' => $explanation,
                ],
            ];
        }

        if ($skill === 'listening') {
            $script      = trim((string)($raw['script'] ?? ''));
            $question    = trim((string)($raw['question'] ?? ''));
            $options     = $raw['options'] ?? [];
            $answer      = strtoupper(trim((string)($raw['answer'] ?? '')));
            $explanation = trim((string)($raw['explanation'] ?? ''));

            if ($script === '' || $question === '' || !is_array($options) || count($options) !== 4) {
                return null;
            }
            if (!in_array($answer, ['A', 'B', 'C', 'D'], true)) {
                $answer = $this->rotateAnswer($n);
            }
            $rate   = is_numeric($raw['rate'] ?? null) ? (float)$raw['rate'] : 0.95;
            $rate   = max(0.7, min(1.2, $rate));
            $optArr = array_values(array_map('strval', $options));

            return [
                'type'    => 'listening_objective',
                'title'   => "Listening Challenge {$n}",
                'prompt'  => $question,
                'answer'  => $answer,
                'rubric'  => [],
                'content' => $common + [
                    'script'            => $script,
                    'language'          => 'en-US',
                    'rate'              => $rate,
                    'pitch'             => 1,
                    'max_replays'       => 2,
                    'duration_estimate' => 12,
                    'question'          => $question,
                    'options'           => $optArr,
                    'answer'            => $answer,
                    'explanation'       => $explanation,
                ],
            ];
        }

        if ($skill === 'speaking') {
            $prompt   = trim((string)($raw['prompt'] ?? ''));
            $scenario = trim((string)($raw['scenario'] ?? ''));
            $keywords = is_array($raw['keywords'] ?? null) ? array_values(array_map('strval', $raw['keywords'])) : [];
            $minWords = max(8, (int)($raw['minimum_words'] ?? 15));
            $duration = max(30, (int)($raw['response_duration'] ?? 60));

            if ($prompt === '') {
                return null;
            }

            return [
                'type'    => 'speaking_response',
                'title'   => "Speaking Challenge {$n}",
                'prompt'  => $prompt,
                'answer'  => null,
                'rubric'  => ['relevance', 'task_completion', 'grammar', 'vocabulary', 'completeness', 'clarity_based_on_transcription'],
                'content' => $common + [
                    'scenario'          => $scenario ?: 'Share your answer with your classmates.',
                    'instruction'       => 'AI Speaking Feedback evaluates transcription, not pronunciation.',
                    'prompt'            => $prompt,
                    'keywords'          => $keywords,
                    'minimum_words'     => $minWords,
                    'response_duration' => $duration,
                ],
            ];
        }

        // writing
        $prompt   = trim((string)($raw['prompt'] ?? ''));
        $context  = trim((string)($raw['context'] ?? ''));
        $minWords = max(20, (int)($raw['minimum_words'] ?? 45));
        $maxWords = max($minWords + 20, (int)($raw['maximum_words'] ?? 150));

        if ($prompt === '') {
            return null;
        }

        return [
            'type'    => 'writing_response',
            'title'   => "Writing Challenge {$n}",
            'prompt'  => $prompt,
            'answer'  => null,
            'rubric'  => ['task_completion', 'relevance', 'grammar', 'vocabulary', 'organization', 'coherence', 'mechanics'],
            'content' => $common + [
                'context'       => $context ?: 'Use evidence from the classroom lesson.',
                'instruction'   => 'Write within the word limit.',
                'prompt'        => $prompt,
                'minimum_words' => $minWords,
                'maximum_words' => $maxWords,
            ],
        ];
    }
}
