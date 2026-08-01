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
            (string) env_value('GEMINI_MODEL', 'gemini-2.0-flash'),
            (int) env_value('GEMINI_TIMEOUT_SECONDS', '45')
        );
        $prompt = "Create exactly 40 English Reading multiple-choice questions from this lesson plan.\n"
            . "Return a JSON array only. Items 1-20 content_type self_learning; items 21-40 content_type live_quiz.\n"
            . "Each object: content_type, q, op (four unique strings), ans (A-D), exp, cat='Reading', dif (easy|medium|hard), u.\n"
            . "Banks must use different wording; answer must point to its option.\nRPP:\n"
            . mb_substr($text, 0, 24000);
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

    /** @return list<array<string,mixed>> */
    private function fallbackItems(string $text): array
    {
        preg_match_all('/\b[A-Za-z]{5,}\b/u', strip_tags($text), $matches);
        $words = array_values(array_unique(array_map('strtolower', $matches[0] ?? [])));
        $words = array_values(array_filter($words, static fn(string $word): bool => !in_array($word, [
            'dengan', 'untuk', 'dalam', 'siswa', 'peserta', 'didik', 'pembelajaran', 'teacher', 'student',
        ], true)));
        if (count($words) < 8) {
            $words = ['language', 'reading', 'context', 'meaning', 'sentence', 'vocabulary', 'grammar', 'purpose'];
        }
        $items = [];
        $difficulty = ['easy', 'medium', 'hard'];
        $templates = [
            'What is the meaning of the key word "%s" in the uploaded RPP context?',
            'Select the best vocabulary word connected to the classroom topic: "%s".',
            'Which term from the lesson plan is most relevant to this reading focus? ("%s")',
            'Identify the core concept discussed in the lesson plan matching: "%s".',
            'Choose the vocabulary option that matches this week\'s lesson focus: "%s".'
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
                    $options = [$answer, 'Different idea', 'Unrelated detail', 'Opposite meaning'];
                }
                shuffle($options);
                $correctIndex = array_search($answer, $options, true);
                $ans = chr(65 + $correctIndex);

                $template = $templates[$i % count($templates)];
                $question = sprintf($template, $answer);
                
                $items[] = [
                    'content_type' => $type,
                    'u' => $i + 1,
                    'q' => $question,
                    'op' => $options,
                    'ans' => $ans,
                    'exp' => "\"{$answer}\" appears in the uploaded lesson-plan context.",
                    'cat' => 'Reading',
                    'dif' => $difficulty[($i + $typeIndex) % 3],
                ];
            }
        }
        return $items;
    }
}
