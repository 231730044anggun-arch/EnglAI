<?php
declare(strict_types=1);

namespace EnglAI\Mvp;

use EnglAI\AI\ContentValidationException;
use EnglAI\AI\ContentValidator;
use EnglAI\AI\GeminiProvider;

final class ContentBankGenerator
{
    private ContentValidator $validator;

    public function __construct(private readonly \PDO $pdo)
    {
        $this->validator = new ContentValidator();
    }

    /** @return array{source:string,self_learning:int,live_quiz:int,duplicates:int,warning:?string} */
    public function generate(int $classroomId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM classroom_lesson_plans WHERE classroom_id = ? AND is_active = 1 ORDER BY version DESC LIMIT 1'
        );
        $statement->execute([$classroomId]);
        $lessonPlan = $statement->fetch();
        if (!$lessonPlan) {
            throw new \RuntimeException('Upload RPP classroom sebelum generate content.');
        }

        $source = 'fallback';
        $warning = null;
        try {
            $items = $this->generateWithAi((string) $lessonPlan['extracted_text']);
            $source = 'ai';
        } catch (\Throwable $exception) {
            $items = $this->fallbackItems((string) $lessonPlan['extracted_text']);
            $warning = 'Gemini tidak tersedia; bank lokal yang valid digunakan.';
            app_log('warning', 'MVP batch generation fallback used.', [
                'classroom_id' => $classroomId,
                'reason' => get_class($exception),
            ]);
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO content_questions
                (classroom_id, lesson_plan_id, content_type, skill, difficulty, question, options_json, answer, explanation, question_hash, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $counts = ['self_learning' => 0, 'live_quiz' => 0];
        $duplicates = 0;
        $this->pdo->beginTransaction();
        try {
            foreach ($items as $item) {
                $validated = $this->validator->validate('quiz', $item);
                $type = (string) ($item['content_type'] ?? '');
                if (!isset($counts[$type])) {
                    throw new ContentValidationException('Content type batch tidak valid.');
                }
                $canonicalOptions = json_encode(
                    $validated['op'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
                $hash = hash('sha256', mb_strtolower(trim($validated['q'])));
                try {
                    $insert->execute([
                        $classroomId,
                        (int) $lessonPlan['id'],
                        $type,
                        'reading',
                        $validated['dif'],
                        $validated['q'],
                        $canonicalOptions,
                        $validated['ans'],
                        $validated['exp'],
                        $hash,
                        $source,
                    ]);
                    $counts[$type]++;
                } catch (\PDOException $exception) {
                    if ((string) $exception->getCode() === '23000') {
                        $duplicates++;
                        continue;
                    }
                    throw $exception;
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
        return [
            'source' => $source,
            'self_learning' => $counts['self_learning'],
            'live_quiz' => $counts['live_quiz'],
            'duplicates' => $duplicates,
            'warning' => $warning,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function generateWithAi(string $text): array
    {
        $apiKey = (string) env_value('GEMINI_API_KEY', '');
        if ($apiKey === '') {
            throw new \RuntimeException('Gemini key is not configured.');
        }
        $provider = new GeminiProvider(
            $apiKey,
            (string) env_value('GEMINI_MODEL', 'gemini-2.5-flash'),
            (int) env_value('GEMINI_TIMEOUT_SECONDS', '45')
        );
        
        $body = $this->extractRppBody($text);
        
        $prompt = "You are a professional English teacher.\n"
            . "Create exactly 40 high-quality English Reading multiple-choice questions based on the lesson content below.\n"
            . "Return a JSON array only. Items 1-20 must have content_type 'self_learning'; items 21-40 must have content_type 'live_quiz'.\n"
            . "Each object must contain: content_type, q, op (exactly four unique strings), ans (A-D), exp, cat='Reading', dif (easy|medium|hard), u.\n"
            . "STRICT RULES:\n"
            . "- Ignore any document headers like 'MODUL AJAR', classroom meta, school names, etc.\n"
            . "- Base the questions on SPECIFIC content from the lesson: animals (e.g., Bekantan, Cendrawasih, Hornbills), character names (e.g., Galang, Monita, Andre), vocabulary, grammar, and facts.\n"
            . "- DO NOT ask generic questions like 'What is the meaning of the keyword in the uploaded RPP?'. Ask natural reading comprehension questions, e.g. 'Where is the Bekantan primarily found?' or 'What makes the Helmeted Hornbill unique?'.\n"
            . "- Distribute answer keys (A, B, C, D) evenly across questions (rotate them: 1=A, 2=B, 3=C, 4=D, etc.) so that they are not all 'A'.\n"
            . "LESSON CONTENT:\n"
            . $body;

        $data = $provider->generate($prompt);
        $items = array_is_list($data) ? $data : ($data['questions'] ?? []);
        if (!is_array($items) || count($items) < 40) {
            throw new ContentValidationException('AI batch kurang dari 40 pertanyaan.');
        }
        $counts = ['self_learning' => 0, 'live_quiz' => 0];
        $valid = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($counts[$item['content_type'] ?? ''])) {
                throw new ContentValidationException('AI batch schema tidak valid.');
            }
            $this->validator->validate('quiz', $item);
            $counts[$item['content_type']]++;
            $valid[] = $item;
        }
        if ($counts['self_learning'] < 20 || $counts['live_quiz'] < 20) {
            throw new ContentValidationException('AI batch tidak memenuhi minimum per bank.');
        }
        return $valid;
    }

    /** Skip RPP document header and return only the meaningful lesson body. */
    private function extractRppBody(string $text): string
    {
        $body = preg_replace('/^.*?(?:A\.\s*KONTEKS|KONTEKS SOSIAL|TUJUAN PEMBELAJARAN|PEMAHAMAN BERMAKNA)/uis', '', $text);
        if ($body !== null && mb_strlen(trim($body)) > 100) {
            return trim(mb_substr(preg_replace('/\s+/u', ' ', $body) ?? $body, 0, 18000));
        }
        return trim(mb_substr(preg_replace('/\s+/u', ' ', $text) ?? $text, 800, 18000));
    }

    /** @return list<array<string,mixed>> */
    private function fallbackItems(string $text): array
    {
        $body = $this->extractRppBody($text);
        preg_match_all('/\b[A-Za-z]{5,}\b/u', strip_tags($body), $matches);
        $words = array_values(array_unique(array_map('strtolower', $matches[0] ?? [])));
        $words = array_values(array_filter($words, static fn(string $word): bool => !in_array($word, [
            'dengan', 'untuk', 'dalam', 'siswa', 'peserta', 'didik', 'pembelajaran', 'teacher', 'student',
        ], true)));
        if (count($words) < 8) {
            $words = ['bekantan', 'cendrawasih', 'hornbill', 'habitat', 'endemic', 'species', 'forest', 'kalimantan'];
        }
        
        $items = [];
        $difficulty = ['easy', 'medium', 'hard'];
        
        // Topic extraction for friendly labelling
        $topic = 'Indonesian Endemic Fauna';
        if (preg_match('/Chapter\s*\/\s*Topik(?:\s*Chapter\s*\/\s*Topik)?\s+(.{5,200})/ui', $text, $m)) {
            $c = trim(preg_replace('/\s+/', ' ', $m[1]) ?? '');
            $len = mb_strlen($c);
            for ($s = (int)ceil($len/3); $s <= (int)ceil($len*2/3); $s++) {
                $h = mb_substr($c, 0, $s);
                if (mb_strpos($c, $h, 1) !== false) {
                    $c = trim($h);
                    break;
                }
            }
            if (mb_strlen($c) >= 5) {
                $topic = mb_substr($c, 0, 80);
            }
        }

        $templates = [
            'Which of the following is a primary characteristic of the "%s" mentioned in the lesson?',
            'Based on the classroom topic of ' . $topic . ', how is the term "%s" defined?',
            'In the context of protecting Indonesian species, what does "%s" represent?',
            'Identify the correct factual statement about the "%s" from the reading material.',
            'What is the conservation status or aspect of the "%s" as discussed in class?'
        ];

        foreach (['self_learning', 'live_quiz'] as $typeIndex => $type) {
            for ($i = 0; $i < 20; $i++) {
                $answer = ucfirst($words[$i % count($words)]);
                $options = [
                    $answer,
                    ucfirst($words[($i + 1) % count($words)]),
                    ucfirst($words[($i + 2) % count($words)]),
                    ucfirst($words[($i + 3) % count($words)]),
                ];
                if (count(array_unique($options)) < 4) {
                    $options = [$answer, 'Different animal', 'Another species', 'A unique habitat'];
                }
                shuffle($options);
                
                // Rotated answer key so it is not always 'A' (e.g. rotating index 0, 1, 2, 3)
                $targetKey = ['A', 'B', 'C', 'D'][($i + $typeIndex) % 4];
                $correctIndex = ord($targetKey) - 65;
                
                // Ensure the answer is at the correct index
                $oldPos = array_search($answer, $options, true);
                if ($oldPos !== $correctIndex) {
                    // Swap
                    $temp = $options[$correctIndex];
                    $options[$correctIndex] = $answer;
                    $options[$oldPos] = $temp;
                }

                $template = $templates[$i % count($templates)];
                $question = sprintf($template, $answer);
                
                $items[] = [
                    'content_type' => $type,
                    'u' => $i + 1,
                    'q' => $question,
                    'op' => $options,
                    'ans' => $targetKey,
                    'exp' => "The reading material emphasizes \"{$answer}\" as a key concept in the lesson.",
                    'cat' => 'Reading',
                    'dif' => $difficulty[($i + $typeIndex) % 3],
                ];
            }
        }
        return $items;
    }
}
