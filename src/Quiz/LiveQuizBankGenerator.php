<?php
declare(strict_types=1);
namespace EnglAI\Quiz;

use EnglAI\AI\GeminiProvider;
use EnglAI\Learning\Level;

/**
 * Extract a meaningful short topic string from raw RPP text.
 * Skips the repetitive header ("MODUL AJAR BAHASA INGGRIS...") and tries to
 * find the Chapter/Topic line, or falls back to the first real sentence.
 */
function extractRppTopic(string $text): string
{
    // The RPP text often duplicates every line, e.g.:
    // "Chapter / Topik Chapter / Topik Chapter 1 - Exploring Fauna..."
    // Strategy: find "Chapter / Topik" then grab the content after the second occurrence.
    if (preg_match('/Chapter\s*\/\s*Topik(?:\s*Chapter\s*\/\s*Topik)?\s+(.{5,200})/ui', $text, $m)) {
        $candidate = trim(preg_replace('/\s+/', ' ', $m[1]) ?? '');
        // The content may be duplicated — detect and trim
        // e.g. "Chapter 1 - Exploring Fauna... Chapter 1 - Exploring Fauna..."
        $len = mb_strlen($candidate);
        for ($splitAt = (int)ceil($len / 3); $splitAt <= (int)ceil($len * 2 / 3); $splitAt++) {
            $firstHalf = mb_substr($candidate, 0, $splitAt);
            if (mb_strpos($candidate, $firstHalf, 1) !== false) {
                $candidate = trim($firstHalf);
                break;
            }
        }
        if (mb_strlen($candidate) >= 5) {
            return mb_substr($candidate, 0, 120);
        }
    }

    // Fallback: skip header block and grab first meaningful sentence
    $skip = preg_replace('/^.*?(?:Tahun Penyusunan|A\.\s*KONTEKS|TUJUAN PEMBELAJARAN|PEMAHAMAN BERMAKNA)/uis', '', $text);
    if ($skip !== null && mb_strlen(trim($skip)) > 20) {
        $text = $skip;
    }

    $clean = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
    $topic = mb_substr($clean, 0, 120);

    if (stripos($topic, 'MODUL AJAR') !== false || mb_strlen(trim($topic)) < 5) {
        return 'the classroom lesson';
    }
    return $topic;
}

final class LiveQuizBankGenerator
{
    public const SKILLS = ['reading', 'listening', 'speaking', 'writing'];

    /** @var GeminiLiveQuizGenerator|null */
    private ?GeminiLiveQuizGenerator $ai = null;

    public function __construct(private readonly \PDO $pdo, ?GeminiProvider $gemini = null)
    {
        if ($gemini !== null) {
            $this->ai = new GeminiLiveQuizGenerator($gemini);
        }
    }

    /** @return array<string,array<string,int>> */
    public function generateAll(int $classroomId, string $level, int $target = 30): array
    {
        $out = [];
        foreach (self::SKILLS as $skill) {
            $out[$skill] = $this->generate($classroomId, $skill, $level, $target);
        }
        return $out;
    }

    /** @return array<string,int|string> */
    public function generate(int $classroomId, string $skill, string $level, int $target = 30): array
    {
        if (!in_array($skill, self::SKILLS, true)) {
            throw new \InvalidArgumentException('Skill tidak valid.');
        }

        $level   = Level::validate($level);
        $target  = max(10, min(60, $target));
        $plan    = $this->plan($classroomId);
        // Pass more text to AI; extract topic only for fallback labels
        $fullText = (string)$plan['extracted_text'];
        $excerpt  = trim(mb_substr($fullText, 0, 2000));
        $topic    = extractRppTopic($fullText);

        $existing = $this->count($classroomId, $skill, $level);
        $created  = 0;
        $aiCount  = 0;

        $insert = $this->pdo->prepare("INSERT IGNORE INTO live_quiz_items
          (classroom_id, lesson_plan_id, skill, level, question_type, title, prompt, content_json,
           answer_key, rubric_json, source_excerpt, content_hash, provider_source, status)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ready')");

        for ($i = $existing; $i < $target; $i++) {
            $n = $i + 1;

            // --- Try AI generation first ---
            $item   = null;
            $source = 'fallback';

            if ($this->ai !== null) {
                try {
                    $item = $this->ai->generate($skill, $level, $n, $excerpt);
                    if ($item !== null) {
                        $source = 'gemini';
                        $aiCount++;
                    }
                } catch (\Throwable) {
                    $item = null;
                }
            }

            // --- Fallback to static template if AI failed ---
            if ($item === null) {
                $item = $this->fallbackItem($skill, $level, $n, $topic);
            }

            $hash = hash('sha256', implode('|', [
                'live_quiz_v3', $classroomId, $skill, $level, $n, mb_strtolower($item['prompt'])
            ]));

            $insert->execute([
                $classroomId,
                (int)$plan['id'],
                $skill,
                $level,
                $item['type'],
                $item['title'],
                $item['prompt'],
                json_encode($item['content'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $item['answer'],
                json_encode($item['rubric'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $excerpt ?: 'Classroom lesson plan',
                $hash,
                $source,
            ]);

            $created += $insert->rowCount();
        }

        return [
            'created'  => $created,
            'total'    => $this->count($classroomId, $skill, $level),
            'source'   => $this->ai !== null ? 'gemini' : 'fallback',
            'ai_count' => $aiCount,
        ];
    }

    public function count(int $classroomId, string $skill, string $level): int
    {
        $q = $this->pdo->prepare("SELECT COUNT(*) FROM live_quiz_items WHERE classroom_id=? AND skill=? AND level=? AND status='ready'");
        $q->execute([$classroomId, $skill, $level]);
        return (int)$q->fetchColumn();
    }

    /**
     * Delete existing bank items for this classroom/skill/level, then regenerate.
     * Use when items were generated with the old fallback template.
     *
     * @return array<string,int|string>
     */
    public function clearAndRegenerate(int $classroomId, string $skill, string $level, int $target = 30): array
    {
        if (!in_array($skill, self::SKILLS, true)) {
            throw new \InvalidArgumentException('Skill tidak valid.');
        }
        $level = Level::validate($level);
        // Delete only fallback items so AI-generated ones aren't wasted
        $this->pdo->prepare(
            "DELETE FROM live_quiz_items WHERE classroom_id=? AND skill=? AND level=? AND provider_source='fallback'"
        )->execute([$classroomId, $skill, $level]);
        return $this->generate($classroomId, $skill, $level, $target);
    }

    /**
     * Delete ALL items (fallback + AI) and regenerate fresh.
     *
     * @return array<string,array<string,int|string>>
     */
    public function clearAllAndRegenerate(int $classroomId, string $level, int $target = 30): array
    {
        $level = Level::validate($level);
        $this->pdo->prepare(
            "DELETE FROM live_quiz_items WHERE classroom_id=? AND level=?"
        )->execute([$classroomId, $level]);
        return $this->generateAll($classroomId, $level, $target);
    }

    private function plan(int $id): array
    {
        $q = $this->pdo->prepare('SELECT * FROM classroom_lesson_plans WHERE classroom_id=? AND is_active=1 ORDER BY version DESC, id DESC LIMIT 1');
        $q->execute([$id]);
        $row = $q->fetch();
        if (!$row) {
            throw new \RuntimeException('Upload RPP classroom sebelum membuat Live Quiz Content Bank.');
        }
        return $row;
    }

    /**
     * Static fallback item — used only when AI is unavailable or fails.
     * Varies question text, answer key, and options per (skill, n) so items
     * are at least slightly differentiated.
     *
     * @return array<string,mixed>
     */
    private function fallbackItem(string $skill, string $level, int $n, string $topic): array
    {
        $common = ['skill' => $skill, 'level' => $level, 'competency' => 'RPP-aligned competency', 'sequence' => $n];
        // Rotate answer key so it's not always A
        $answers = ['A', 'B', 'C', 'D'];
        $ansKey  = $answers[$n % 4];
        $ansIdx  = $n % 4; // 0-based index of correct option

        if ($skill === 'reading') {
            $questionVariants = [
                "What is the main idea of the passage about {$topic}?",
                "Which statement best describes {$topic} according to the text?",
                "What can be inferred about {$topic} from the passage?",
                "What is the author's purpose in writing about {$topic}?",
            ];
            $q = $questionVariants[$n % 4];

            $opts = [
                "{$topic} is the main focus of the passage.",
                "The passage discusses an unrelated topic.",
                "The text provides no factual information.",
                "The author only gives a list of numbers.",
            ];
            // Rotate so correct answer matches $ansKey
            $correct = $opts[0];
            $rotated = array_values(array_merge(array_slice($opts, 1), [$correct]));
            array_splice($rotated, $ansIdx, 0, [$correct]);
            $rotated = array_slice($rotated, 0, 4);

            return [
                'type'    => 'objective',
                'title'   => "Reading Challenge {$n}",
                'prompt'  => $q,
                'answer'  => $ansKey,
                'rubric'  => [],
                'content' => $common + [
                    'passage'     => "Reading challenge {$n}: {$topic}.",
                    'question'    => $q,
                    'options'     => $rotated,
                    'answer'      => $ansKey,
                    'explanation' => "Option {$ansKey} matches the passage content.",
                ],
            ];
        }

        if ($skill === 'listening') {
            $q = "What is the main focus of the audio about {$topic}?";
            $opts = [
                $topic,
                'A sports result',
                'A shopping list',
                'A weather warning',
            ];
            $correct = $opts[0];
            $rotated = array_values(array_merge(array_slice($opts, 1), [$correct]));
            array_splice($rotated, $ansIdx, 0, [$correct]);
            $rotated = array_slice($rotated, 0, 4);
            $rate = $level === 'basic' ? 0.85 : ($level === 'advanced' ? 1.05 : 0.95);

            return [
                'type'    => 'listening_objective',
                'title'   => "Listening Challenge {$n}",
                'prompt'  => $q,
                'answer'  => $ansKey,
                'rubric'  => [],
                'content' => $common + [
                    'script'            => "Listening challenge {$n}. The lesson focuses on {$topic}. Listen for the main idea.",
                    'language'          => 'en-US',
                    'rate'              => $rate,
                    'pitch'             => 1,
                    'max_replays'       => 2,
                    'duration_estimate' => 12,
                    'question'          => $q,
                    'options'           => $rotated,
                    'answer'            => $ansKey,
                    'explanation'       => 'The generated audio explicitly states the focus.',
                ],
            ];
        }

        if ($skill === 'speaking') {
            $prompts = [
                "Explain one important idea about {$topic}.",
                "Describe what you learned about {$topic} in your own words.",
                "Why is {$topic} an important topic? Give at least two reasons.",
                "Tell your classmate one interesting fact about {$topic}.",
            ];
            $prompt = $prompts[$n % 4];

            return [
                'type'    => 'speaking_response',
                'title'   => "Speaking Challenge {$n}",
                'prompt'  => $prompt,
                'answer'  => null,
                'rubric'  => ['relevance', 'task_completion', 'grammar', 'vocabulary', 'completeness', 'clarity_based_on_transcription'],
                'content' => $common + [
                    'scenario'          => 'Explain the lesson to a classmate.',
                    'instruction'       => 'AI Speaking Feedback evaluates transcription, not pronunciation.',
                    'prompt'            => $prompt,
                    'keywords'          => array_slice(array_values(array_filter(
                        preg_split('/\W+/u', mb_strtolower($topic)) ?: [],
                        fn($w) => mb_strlen($w) > 4
                    )), 0, 5),
                    'minimum_words'     => $level === 'basic' ? 8 : 15,
                    'response_duration' => $level === 'advanced' ? 90 : 60,
                ],
            ];
        }

        // writing
        $prompts = [
            "Write a short report about {$topic} using facts from the lesson.",
            "Describe {$topic} in your own words. Include at least two key details.",
            "Explain the importance of {$topic} and give examples.",
            "Write a paragraph summarising what you know about {$topic}.",
        ];
        $prompt = $prompts[$n % 4];

        return [
            'type'    => 'writing_response',
            'title'   => "Writing Challenge {$n}",
            'prompt'  => $prompt,
            'answer'  => null,
            'rubric'  => ['task_completion', 'relevance', 'grammar', 'vocabulary', 'organization', 'coherence', 'mechanics'],
            'content' => $common + [
                'context'       => 'Use evidence from the classroom lesson.',
                'instruction'   => 'Write within the word limit.',
                'prompt'        => $prompt,
                'minimum_words' => $level === 'basic' ? 20 : ($level === 'advanced' ? 80 : 45),
                'maximum_words' => $level === 'basic' ? 80 : ($level === 'advanced' ? 220 : 150),
            ],
        ];
    }
}
