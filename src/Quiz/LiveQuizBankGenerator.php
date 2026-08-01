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
        $needed   = $target - $existing;
        if ($needed <= 0) {
            return [
                'created'  => 0,
                'total'    => $existing,
                'source'   => 'none',
                'ai_count' => 0,
            ];
        }

        $created  = 0;
        $aiCount  = 0;

        $insert = $this->pdo->prepare("INSERT IGNORE INTO live_quiz_items
          (classroom_id, lesson_plan_id, skill, level, question_type, title, prompt, content_json,
           answer_key, rubric_json, source_excerpt, content_hash, provider_source, status)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ready')");

        // --- 1. Try batch AI generation first ---
        $aiItems = null;
        if ($this->ai !== null) {
            try {
                $aiItems = $this->ai->generateBatch($skill, $level, $existing + 1, $needed, $excerpt);
            } catch (\Throwable) {
                $aiItems = null;
            }
        }

        // --- 2. Iterate and insert items (AI or fallback) ---
        for ($i = 0; $i < $needed; $i++) {
            $n = $existing + $i + 1;
            $item = null;
            $source = 'fallback';

            if ($aiItems !== null && isset($aiItems[$i])) {
                $item = $aiItems[$i];
                $source = 'gemini';
                $aiCount++;
            } else {
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
        $ansKey  = $answers[($n - 1) % 4];
        $ansIdx  = ($n - 1) % 4; // 0-based index of correct option

        // Animals and topics extracted dynamically or defaulted based on standard RPP unit
        $animals = ['Bekantan', 'Cendrawasih', 'Helmeted Hornbill', 'Bali Starling', 'Orangutan'];
        $animal = $animals[($n - 1) % count($animals)];

        if ($skill === 'reading') {
            // Level-specific reading passages and questions
            if ($level === 'basic') {
                $passages = [
                    "The Bekantan is a unique monkey. It is famous for its big nose. It has reddish-brown hair. It lives in trees near rivers in Kalimantan.",
                    "The Cendrawasih is a beautiful bird. It has bright feathers. People call it the Bird of Paradise. It lives in the forests of Papua.",
                    "The Helmeted Hornbill is a large bird. It has a heavy red and yellow casque on its beak. It eats fruits and lives in Kalimantan forests.",
                    "The Bali Starling is a rare white bird. It has clean white feathers and a blue patch around its eyes. It is the national symbol of Bali."
                ];
                $questions = [
                    "What is the Bekantan famous for?",
                    "Where does the Cendrawasih live?",
                    "What does the Helmeted Hornbill eat?",
                    "What color is the Bali Starling?"
                ];
                $optionSets = [
                    ["Its big nose", "Its blue eyes", "Its long tail", "Its yellow beak"],
                    ["Papua forests", "Bali beaches", "Jakarta cities", "Kalimantan rivers"],
                    ["Fruits", "Insects", "Leaves", "Fish"],
                    ["White", "Yellow", "Blue", "Green"]
                ];
            } elseif ($level === 'advanced') {
                $passages = [
                    "The Bekantan (proboscis monkey) faces critical threats due to palm oil expansion. Deforestation destroys mangrove forests along Borneo's rivers. Consequently, their wild population has decreased by over 50% in the last few decades.",
                    "Papua's iconic Cendrawasih is heavily impacted by the illegal wildlife trade and deforestation. Because they exhibit beautiful courtship dances, male birds are frequently hunted for their feathers, disrupting natural mating cycles.",
                    "The Helmeted Hornbill is critically endangered due to the high demand for its solid ivory casque. Unlike other hornbills with hollow casques, this species has a solid block of keratin, which is carved into luxury ornaments.",
                    "The Bali Starling is near extinction in the wild. Despite intensive captive breeding programs, illegal poaching for the songbird market remains a persistent threat to the surviving wild population in West Bali National Park."
                ];
                $questions = [
                    "Why has the Bekantan population decreased by over 50%?",
                    "How does hunting male Cendrawasih birds affect their population?",
                    "What makes the Helmeted Hornbill particularly vulnerable to ivory hunters?",
                    "What remains a persistent threat to wild Bali Starlings despite breeding programs?"
                ];
                $optionSets = [
                    ["Mangrove habitat loss from palm oil expansion", "Natural predators in the forest", "Climate change and dry weather", "Lack of food sources"],
                    ["It disrupts their natural courtship and mating cycles", "It changes their migratory behavior", "It makes them look less attractive", "It increases their survival rate"],
                    ["Their solid keratin casque is prized for carving", "Their feathers are used in traditional ceremonies", "They are easy to catch in the wild", "They have no natural defenses"],
                    ["Illegal poaching for the songbird market", "A lack of food in national parks", "Spread of infectious avian diseases", "Low birth rates in captivity"]
                ];
            } else { // intermediate
                $passages = [
                    "The Bekantan is endemic to Borneo. They live in mangrove forests and swamp areas. They are excellent swimmers and often jump from branches into the water to escape danger.",
                    "Male Cendrawasih birds use their colorful feathers to attract females. They perform elaborate dances on tree branches. Sadly, their habitat is shrinking because of forest logging.",
                    "The Helmeted Hornbill is known for its loud laughing call. It is a vital seed disperser in the rainforest. It helps new trees grow by spreading seeds across the jungle.",
                    "The Bali Starling is protected by Indonesian law. Only a few individuals are left in the wild. Conservationists are working hard to release bred starlings back into West Bali."
                ];
                $questions = [
                    "Where does the Bekantan live?",
                    "Why do male Cendrawasih birds perform elaborate dances?",
                    "What role does the Helmeted Hornbill play in the rainforest?",
                    "Where are conservationists releasing bred Bali Starlings?"
                ];
                $optionSets = [
                    ["Mangrove forests and swamp areas", "Dry deserts and rocky mountains", "Urban parks and cities", "Grasslands and savannas"],
                    ["To attract female birds", "To scare away predators", "To find hidden food", "To mark their territory"],
                    ["Spreading seeds as a vital seed disperser", "Building nests for other birds", "Hunting insect pests", "Polishing tree branches"],
                    ["West Bali National Park", "East Java rainforests", "Central Kalimantan rivers", "Papua mountains"]
                ];
            }

            $idx = ($n - 1) % 4;
            $q = $questions[$idx];
            $opts = $optionSets[$idx];
            
            // Adjust options to match $ansKey at correct index
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
                    'passage'     => $passages[$idx],
                    'question'    => $q,
                    'options'     => $rotated,
                    'answer'      => $ansKey,
                    'explanation' => "The correct answer is derived from facts about the {$animal} in the passage.",
                ],
            ];
        }

        if ($skill === 'listening') {
            if ($level === 'basic') {
                $scripts = [
                    "Listen to the guide. The Bekantan has red hair and a very big nose. It eats mangrove leaves. It is very friendly.",
                    "Look up at the branch! The Cendrawasih is dancing. It has beautiful yellow and blue feathers. It is Papua's pride.",
                    "Listen! That is the Helmeted Hornbill. It is a big bird with a red head. It lives high up in the Kalimantan trees.",
                    "Look at this white bird. It is the Bali Starling. It has white feathers and blue eyes. It is very rare now."
                ];
                $questions = [
                    "What does the Bekantan eat?",
                    "What colors are the Cendrawasih feathers in the script?",
                    "Where does the Helmeted Hornbill live according to the guide?",
                    "What is the Bali Starling's color?"
                ];
                $optionSets = [
                    ["Mangrove leaves", "Insects and bugs", "Bananas and fruits", "Fish from rivers"],
                    ["Yellow and blue", "Red and yellow", "Green and black", "Pure white"],
                    ["High up in Kalimantan trees", "On the forest floor in Sumatra", "Near Bali beaches", "In Papua swamps"],
                    ["White with blue eye patches", "Yellow with red beak", "Brown with long tail", "Green with black head"]
                ];
            } elseif ($level === 'advanced') {
                $scripts = [
                    "Welcome back to Wildlife Talk. The Bekantan, an endemic primate of Borneo, is classified as endangered. Deforestation for palm oil plantations is the primary driver of their habitat destruction, forcing them closer to human settlements.",
                    "Here is an update from Papua. The Cendrawasih's survival is threatened by persistent poaching. Collectors pay high prices for the male's spectacular plumage, which is illegally smuggled out of the country.",
                    "We are discussing the Helmeted Hornbill. Unlike other birds, its helmet is solid keratin, often called hornbill ivory. This solid casque makes them a target for international smuggling networks.",
                    "Let's focus on the Bali Starling. Due to extreme poaching for the illegal pet trade, the wild population dropped to less than fifty. Current conservation efforts rely heavily on protected forest zones."
                ];
                $questions = [
                    "What is the main driver of the Bekantan's habitat destruction?",
                    "Why is the male Cendrawasih illegally hunted?",
                    "Why do smugglers target the Helmeted Hornbill?",
                    "What caused the wild Bali Starling population to drop below fifty?"
                ];
                $optionSets = [
                    ["Deforestation for palm oil plantations", "Forest fires and dry seasons", "Introduction of new predators", "Water pollution in rivers"],
                    ["Because of its spectacular plumage", "For its song in pet competitions", "For traditional medicine casques", "Because they damage fruit crops"],
                    ["For their solid keratin hornbill ivory", "For their large yellow wings", "Because they have a loud call", "For traditional bird nests"],
                    ["Extreme poaching for the illegal pet trade", "Lack of suitable food in the wild", "Spread of infectious diseases", "Destruction of all nests by storms"]
                ];
            } else { // intermediate
                $scripts = [
                    "Listen to this. The Bekantan is a unique monkey. They can swim very well because they have partially webbed toes. They swim to escape predators.",
                    "Our lesson today covers the Cendrawasih. Known as the Bird of Paradise, its beautiful feathers are used in dances to attract mates. They live in Papua's dense forests.",
                    "Listen carefully. The Helmeted Hornbill has a loud, laughing call. It is endangered because hunters target its heavy casque to carve ornaments.",
                    "This is the Bali Starling. It is critically endangered. Poachers catch them to sell as expensive pets because of their beautiful white appearance."
                ];
                $questions = [
                    "Why does the Bekantan have webbed toes?",
                    "What is the Cendrawasih's nickname?",
                    "Why do hunters target the Helmeted Hornbill's casque?",
                    "Why do poachers catch the Bali Starling?"
                ];
                $optionSets = [
                    ["To help them swim very well", "To climb tall trees faster", "To catch insects on branches", "To walk on soft mud"],
                    ["Bird of Paradise", "Kalimantan Primates", "Bali National Symbol", "Loud Laughing Bird"],
                    ["To carve luxury ornaments", "To sell as traditional food", "Because it is made of solid gold", "To use as medicine"],
                    ["To sell them as expensive pets", "To use their bones for medicine", "For their yellow feathers", "To breed them in cities"]
                ];
            }

            $idx = ($n - 1) % 4;
            $q = $questions[$idx];
            $opts = $optionSets[$idx];
            
            // Adjust options to match $ansKey at correct index
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
                    'script'            => $scripts[$idx],
                    'language'          => 'en-US',
                    'rate'              => $rate,
                    'pitch'             => 1,
                    'max_replays'       => 2,
                    'duration_estimate' => 12,
                    'question'          => $q,
                    'options'           => $rotated,
                    'answer'            => $ansKey,
                    'explanation'       => "The audio script provides the exact facts regarding {$animal}.",
                ],
            ];
        }

        if ($skill === 'speaking') {
            $prompts = [
                "Describe the appearance and habitat of the {$animal} based on the lesson.",
                "Imagine you are Galang. Share one interesting fact about the {$animal} that you learned.",
                "Explain why the {$animal} is endangered and suggest one simple action to protect them.",
                "What is the main difference between the {$animal} and other local fauna? Explain in detail.",
            ];
            $prompt = $prompts[($n - 1) % 4];

            return [
                'type'    => 'speaking_response',
                'title'   => "Speaking Challenge {$n}",
                'prompt'  => $prompt,
                'answer'  => null,
                'rubric'  => ['relevance', 'task_completion', 'grammar', 'vocabulary', 'completeness', 'clarity_based_on_transcription'],
                'content' => $common + [
                    'scenario'          => "You are sharing a short presentation about {$animal}.",
                    'instruction'       => 'AI Speaking Feedback evaluates transcription, not pronunciation.',
                    'prompt'            => $prompt,
                    'keywords'          => [$animal, 'endemic', 'habitat', 'forest', 'threat'],
                    'minimum_words'     => $level === 'basic' ? 8 : 15,
                    'response_duration' => $level === 'advanced' ? 90 : 60,
                ],
            ];
        }

        // writing
        // Strictly framed as 5W+1H or Story-based scenarios matching the lesson topic & level
        $storyPrompts = [
            "Imagine you are a wildlife conservationist visiting Kalimantan. Write a short story about meeting a {$animal} in the wild. Your story must answer: (1) Who did you travel with? (2) What was the {$animal} doing when you found it? (3) Where exactly did you see it? (4) Why is its forest habitat in danger? (5) How can we save it?",
            "Write a story about a day in the life of a young {$animal} in Indonesia. In your narrative, make sure you answer the 5W+1H: Who is the animal's best friend? What are they eating? Where are they sleeping? When do they avoid danger? Why are they hiding from humans? How do they escape?",
            "Create a narrative about a student named Pipit who writes a blog post to save the {$animal}. Your story must explain: Who read her post? What features of the {$animal} did she describe? Where does the animal live? Why is it critically endangered? How did her classmates help her raise money?",
            "Answer the following 5W+1H questions to write a descriptive story about protecting the {$animal}: Who is cutting down the forest? What happens to the {$animal} when trees are cut? Where do they go to survive? Why should students protect them? How can school campaigns raise awareness?"
        ];
        $prompt = $storyPrompts[($n - 1) % 4];

        $minWords = $level === 'basic' ? 30 : ($level === 'advanced' ? 120 : 70);
        $maxWords = $level === 'basic' ? 90 : ($level === 'advanced' ? 260 : 160);

        return [
            'type'    => 'writing_response',
            'title'   => "Writing Challenge {$n}",
            'prompt'  => $prompt,
            'answer'  => null,
            'rubric'  => ['task_completion', 'relevance', 'grammar', 'vocabulary', 'organization', 'coherence', 'mechanics'],
            'content' => $common + [
                'context'       => "Write a level-specific contextual response about {$animal}.",
                'instruction'   => "Ensure your story/answers are between {$minWords} and {$maxWords} words.",
                'prompt'        => $prompt,
                'minimum_words' => $minWords,
                'maximum_words' => $maxWords,
            ],
        ];
    }
}
