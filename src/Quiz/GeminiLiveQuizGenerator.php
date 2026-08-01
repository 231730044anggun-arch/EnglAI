<?php
declare(strict_types=1);
namespace EnglAI\Quiz;

use EnglAI\AI\GeminiProvider;

/**
 * Generates live quiz items using the Gemini AI API,
 * based on the classroom's active lesson plan (RPP) content.
 */
final class GeminiLiveQuizGenerator
{
    private const MAX_ATTEMPTS = 2;

    public function __construct(private readonly GeminiProvider $gemini) {}

    /**
     * Generate one quiz item for the given skill/level using Gemini.
     * Returns null if AI generation fails (caller should use fallback).
     *
     * @return array<string,mixed>|null
     */
    public function generate(string $skill, string $level, int $n, string $rppExcerpt): ?array
    {
        $prompt = $this->buildPrompt($skill, $level, $n, $rppExcerpt);

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $raw  = $this->gemini->generate($prompt);
                $item = $this->parse($skill, $level, $n, $raw);
                if ($item !== null) {
                    return $item;
                }
            } catch (\Throwable) {
                // try again or fall through to null
            }
        }

        return null;
    }

    private function buildPrompt(string $skill, string $level, int $n, string $rppExcerpt): string
    {
        $difficulty = match ($level) {
            'basic'    => 'easy (suitable for beginners)',
            'advanced' => 'hard (challenging, requires deep understanding)',
            default    => 'medium (intermediate level)',
        };

        // Send enough context but not too much
        $excerpt = mb_substr($rppExcerpt, 0, 2000);

        return match ($skill) {
            'reading'   => $this->readingPrompt($n, $difficulty, $excerpt),
            'listening' => $this->listeningPrompt($n, $difficulty, $excerpt),
            'speaking'  => $this->speakingPrompt($n, $difficulty, $excerpt),
            default     => $this->writingPrompt($n, $difficulty, $excerpt),
        };
    }

    private function readingPrompt(int $n, string $difficulty, string $excerpt): string
    {
        return <<<PROMPT
You are an English quiz generator for Indonesian junior/senior high school students.
Study the lesson plan (RPP) excerpt carefully, then create ONE reading comprehension multiple-choice question.

LESSON PLAN EXCERPT:
{$excerpt}

STRICT REQUIREMENTS:
- Question number: {$n}, difficulty: {$difficulty}
- Write a SHORT passage (3–5 sentences) about a SPECIFIC topic, animal, person, or event mentioned in the RPP
- The question must ask about a SPECIFIC DETAIL from the passage:
  * A name (of a bird, person, character, place)
  * A fact (what the animal eats, where it lives, what it looks like)
  * A grammar point from the lesson (e.g. passive voice usage)
  * A vocabulary meaning
  * An inference about a character's action or feeling
- DO NOT ask generic questions like "What is the main idea?" or "What is the best summary?"
- The correct answer key MUST rotate: for question 1 use A, 2 use B, 3 use C, 4 use D, then repeat
- For question {$n}, use answer key: {$this->rotateAnswer($n)}
- All 4 options must be plausible but clearly only one is correct
- Options must NOT start with "A.", "B.", etc. — just the plain text

Respond ONLY with valid JSON, no markdown fences:
{
  "passage": "3–5 sentence English passage about a specific topic from the RPP",
  "question": "specific detail question about the passage",
  "options": ["option text", "option text", "option text", "option text"],
  "answer": "{$this->rotateAnswer($n)}",
  "explanation": "1 sentence explaining why the answer is correct"
}
PROMPT;
    }

    private function listeningPrompt(int $n, string $difficulty, string $excerpt): string
    {
        return <<<PROMPT
You are an English listening quiz generator for Indonesian junior/senior high school students.
Study the lesson plan (RPP) excerpt carefully, then create ONE listening multiple-choice question.

LESSON PLAN EXCERPT:
{$excerpt}

STRICT REQUIREMENTS:
- Question number: {$n}, difficulty: {$difficulty}
- Write a SHORT audio script (3–5 sentences): a dialogue or monologue featuring SPECIFIC names, places, or animals from the RPP
  * Use character names from the RPP if mentioned (e.g. Galang, Andre, Monita, Pipit)
  * Reference specific animals, places, or topics from the RPP lesson material
- The question must ask about a SPECIFIC DETAIL from the audio:
  * Who said something / who did something
  * Where a character went or what they saw
  * A specific fact mentioned (name of animal, its habitat, what it eats)
- DO NOT ask generic questions like "What is the main focus?" 
- Answer key for question {$n}: {$this->rotateAnswer($n)}
- All 4 options must be plausible, based on names/facts from the RPP

Respond ONLY with valid JSON, no markdown fences:
{
  "script": "3–5 sentence audio script with specific names/facts from the RPP",
  "language": "en-US",
  "rate": 0.95,
  "question": "specific detail question about who/what/where in the audio",
  "options": ["option text", "option text", "option text", "option text"],
  "answer": "{$this->rotateAnswer($n)}",
  "explanation": "1 sentence explaining why the answer is correct"
}
PROMPT;
    }

    private function speakingPrompt(int $n, string $difficulty, string $excerpt): string
    {
        $minWords = match (true) {
            str_contains($difficulty, 'easy') => 8,
            str_contains($difficulty, 'hard') => 20,
            default => 15,
        };
        $duration = str_contains($difficulty, 'hard') ? 90 : 60;

        return <<<PROMPT
You are an English speaking task generator for Indonesian junior/senior high school students.
Study the lesson plan (RPP) excerpt carefully, then create ONE speaking task.

LESSON PLAN EXCERPT:
{$excerpt}

STRICT REQUIREMENTS:
- Task number: {$n}, difficulty: {$difficulty}
- The prompt must reference a SPECIFIC topic, animal, person, or activity from the RPP
- Examples of good prompts:
  * "Describe the Cendrawasih bird: where does it live, what does it look like, and why is it special?"
  * "Explain what passive voice is and give one example from the lesson about Indonesian birds."
  * "Imagine you are Galang on a birdwatching trip. Describe what you saw and what you did."
  * "Why is the Helmeted Hornbill in danger? What can people do to protect it?"
- Minimum words: {$minWords}, response duration: {$duration} seconds
- Include 3–5 keywords from the RPP material

Respond ONLY with valid JSON, no markdown fences:
{
  "prompt": "specific speaking task prompt referencing RPP content",
  "scenario": "brief context (1 sentence) for the student",
  "keywords": ["keyword1", "keyword2", "keyword3"],
  "minimum_words": {$minWords},
  "response_duration": {$duration}
}
PROMPT;
    }

    private function writingPrompt(int $n, string $difficulty, string $excerpt): string
    {
        [$minW, $maxW] = match (true) {
            str_contains($difficulty, 'easy') => [20, 80],
            str_contains($difficulty, 'hard') => [80, 220],
            default => [45, 150],
        };

        return <<<PROMPT
You are an English writing task generator for Indonesian junior/senior high school students.
Study the lesson plan (RPP) excerpt carefully, then create ONE writing task.

LESSON PLAN EXCERPT:
{$excerpt}

STRICT REQUIREMENTS:
- Task number: {$n}, difficulty: {$difficulty}
- The prompt MUST be framed either as a **5W + 1H question series** (Who, What, Where, When, Why, How) or a **story-based / narrative scenario** (soal cerita) based on the RPP lesson material.
- Do NOT make it a generic dry prompt. Give it a creative narrative context (story/scenario) or a structured list of 5W+1H questions.
- Examples of good prompts:
  * "Imagine you are Galang going birdwatching in the forest of Papua. Write a short story about your adventure. In your story, explain: (1) Who did you go with? (2) What beautiful bird did you see? (3) Where did you find it? (4) Why is it special? (5) How did you feel?"
  * "Create a story about a day in the life of a Bekantan (proboscis monkey) in Kalimantan. Your story must answer: Who is the main character? What is it doing? Where does it live? When does it search for food? Why is its habitat in danger? How can we save it?"
  * "Answer the following 5W+1H questions to write a descriptive paragraph about the Helmeted Hornbill: Who hunts this bird? What does it look like? Where is its nest? Why is it critically endangered? How can people protect its population?"
- Word limit: {$minW}–{$maxW} words
- Include a helpful instruction sentence

Respond ONLY with valid JSON, no markdown fences:
{
  "prompt": "story-based prompt or a structured 5W+1H question prompt based on the RPP content",
  "context": "brief instruction or scenario setting for the student",
  "minimum_words": {$minW},
  "maximum_words": {$maxW}
}
PROMPT;
    }

    /** Rotate answer key A→B→C→D based on item number */
    private function rotateAnswer(int $n): string
    {
        return ['A', 'B', 'C', 'D'][($n - 1) % 4];
    }

    /**
     * Parse and validate Gemini response into the live_quiz_items content_json shape.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>|null
     */
    private function parse(string $skill, string $level, int $n, array $raw): ?array
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
