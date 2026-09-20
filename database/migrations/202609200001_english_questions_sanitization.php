<?php
declare(strict_types=1);

/**
 * Migration: 202609200001_english_questions_sanitization.php
 * Ensures all questions, prompts, and instructions in learning_activities are 100% English.
 */

return static function (\PDO $pdo): void {
    $hasIndo = static fn(string $s): bool => (bool)preg_match('/\b(adalah|yang|dan|di|dari|pada|untuk|dengan|sebagai|serta|atau|dalam|ini|itu|ke|oleh|karena|tidak|bisa|dapat|membantu|mempelajari|pembelajaran|menganalisis|membandingkan|penggunaan|kemampuan|kebanggaan|membuat|menggunakan|pengetahuan|menumbuhkan|persamaan|perbedaan|satwa|endemik|tujuan|peserta|didik|siswa|guru|kegiatan|pendahuluan|penutup|materi|langkah|asesmen|membaca|menulis|nyaring|kelancaran|percaya|diri|pemahaman|isi|cerita)\b/iu', $s);

    $speakingEnglishBasic = [
        "Orangutans are intelligent great apes native to the rainforests of Indonesia.",
        "Gorillas live in family groups led by a strong silverback male in Africa.",
        "Both orangutans and gorillas share many physical and social characteristics.",
        "Orangutans spend most of their time climbing and foraging in the forest canopy.",
        "Gorillas build sleeping nests on the ground or in low tree branches each evening.",
        "Protecting rainforest habitats is essential for the survival of endangered primates.",
        "An orangutan uses long, powerful arms to swing smoothly between tall trees.",
        "Young primates learn vital survival skills by closely observing their mothers.",
        "Conservation organizations work tirelessly to prevent illegal wildlife poaching.",
        "Understanding animal adaptations helps us appreciate global biodiversity."
    ];

    $speakingEnglishIntermediate = [
        "What are the main physical differences between an orangutan and a gorilla?",
        "How does the rainforest habitat influence the daily behavior of orangutans?",
        "Why is wildlife conservation important for endangered primate species?",
        "Compare how orangutans and gorillas communicate with members of their group.",
        "Explain why orangutans spend most of their time high in the forest canopy.",
        "What factors threaten the natural habitats of primates in tropical regions?",
        "How do mother orangutans teach their offspring to find food in the wild?",
        "Describe the typical diet of wild gorillas and how they forage for food.",
        "What can students do to raise awareness about rainforest preservation?",
        "Why are orangutans often referred to as 'gardeners of the forest'?"
    ];

    $speakingEnglishAdvanced = [
        "Analyze the ecological role of great apes in maintaining rainforest biodiversity.",
        "Propose actionable community strategies to protect endangered animals from habitat loss.",
        "Evaluate how human activities and deforestation impact wildlife survival in tropical forests.",
        "Discuss the physiological adaptations that help primates thrive in their native environments.",
        "Compare the conservation challenges faced by African gorillas and Indonesian orangutans.",
        "Assess the effectiveness of modern wildlife rehabilitation and release programs.",
        "Explain how climate change disrupts food security and migration patterns for forest wildlife.",
        "Present an argument for establishing international wildlife corridors in fragmented habitats.",
        "Discuss ethical considerations regarding primate conservation and eco-tourism.",
        "How can educational institutions foster environmental stewardship among younger generations?"
    ];

    $writingEnglishBasic = [
        "Write a simple English sentence describing the physical features of an orangutan.",
        "Write one sentence explaining where gorillas live in the wild.",
        "Write a sentence about why protecting endangered animals is important.",
        "Write one sentence describing how primates find food in the rainforest.",
        "Write a simple sentence about the daily life of an animal in nature."
    ];

    // 1. Sanitize Speaking Activities
    $q = $pdo->query("SELECT id, level, title, instruction, content_json FROM learning_activities WHERE skill='speaking' AND status='ready'");
    $activities = $q->fetchAll(\PDO::FETCH_ASSOC);
    $stmtUpd = $pdo->prepare("UPDATE learning_activities SET title=?, instruction=?, content_json=? WHERE id=?");

    $bIdx = 0; $iIdx = 0; $aIdx = 0;
    foreach ($activities as $act) {
        $c = json_decode((string)$act['content_json'], true) ?: [];
        $level = $act['level'];
        $rawPrompt = (string)($c['prompt'] ?? '');
        $rawTitle = (string)$act['title'];
        $rawInst = (string)$act['instruction'];

        $needsUpdate = $hasIndo($rawPrompt) || $hasIndo($rawTitle) || $hasIndo($rawInst) || $hasIndo((string)($c['example_response'] ?? ''));

        if ($needsUpdate || $level === 'basic') {
            if ($level === 'basic') {
                $newPrompt = $speakingEnglishBasic[$bIdx % count($speakingEnglishBasic)];
                $bIdx++;
                $newTitle = "Speaking Practice (Basic): " . substr($newPrompt, 0, 45) . "...";
                $newInst = "Listen to the native model audio and shadow the sentence with clear intonation.";
                $c['prompt'] = $newPrompt;
                $c['example_response'] = "Based on the text, we practice: \"{$newPrompt}\"";
                $c['scenario'] = "Read the short sentence from the lesson aloud with clear pronunciation.";
            } elseif ($level === 'intermediate') {
                $newPrompt = $speakingEnglishIntermediate[$iIdx % count($speakingEnglishIntermediate)];
                $iIdx++;
                $newTitle = "Speaking Practice (Intermediate): " . substr($newPrompt, 0, 45) . "...";
                $newInst = "Respond to the following question in English. Use the provided keywords if helpful.";
                $c['prompt'] = $newPrompt;
                $c['example_response'] = "I believe {$newPrompt} because each primate has adapted uniquely to its ecosystem.";
                $c['scenario'] = "Speak clearly in English answering the prompt within the 25-second limit.";
            } else {
                $newPrompt = $speakingEnglishAdvanced[$aIdx % count($speakingEnglishAdvanced)];
                $aIdx++;
                $newTitle = "Speaking Practice (Advanced): " . substr($newPrompt, 0, 45) . "...";
                $newInst = "Deliver your complete spoken response in English within the 25-second time limit.";
                $c['prompt'] = $newPrompt;
                $c['example_response'] = "In addressing this issue, we must consider both ecological and societal dimensions.";
                $c['scenario'] = "Provide a structured analytical response in English demonstrating advanced vocabulary.";
            }
            $stmtUpd->execute([$newTitle, $newInst, json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $act['id']]);
        }
    }

    // 2. Sanitize Writing Activities
    $qW = $pdo->query("SELECT id, level, title, instruction, content_json FROM learning_activities WHERE skill='writing' AND status='ready'");
    $wActivities = $qW->fetchAll(\PDO::FETCH_ASSOC);
    $wIdx = 0;
    foreach ($wActivities as $act) {
        $c = json_decode((string)$act['content_json'], true) ?: [];
        $rawPrompt = (string)($c['prompt'] ?? '');
        $rawCtx = (string)($c['context'] ?? '');

        if ($hasIndo($rawPrompt) || $hasIndo($rawCtx) || $hasIndo((string)$act['title'])) {
            $newPrompt = $writingEnglishBasic[$wIdx % count($writingEnglishBasic)];
            $wIdx++;
            $newTitle = "Writing Practice: " . substr($newPrompt, 0, 40) . "...";
            $newInst = "Write a clear English response within the specified word-count limit.";
            $c['prompt'] = $newPrompt;
            $c['context'] = "Focus on grammatical accuracy, correct spelling, and lesson-relevant vocabulary.";
            $stmtUpd->execute([$newTitle, $newInst, json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $act['id']]);
        }
    }

    // 3. Sanitize Reading Activities (e.g. ID 5)
    $qR = $pdo->query("SELECT id, level, title, instruction, content_json FROM learning_activities WHERE skill='reading' AND status='ready'");
    $rActivities = $qR->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($rActivities as $act) {
        $c = json_decode((string)$act['content_json'], true) ?: [];
        $rawQuestion = (string)($c['question'] ?? '');
        $rawPassage = (string)($c['passage'] ?? '');
        if ($hasIndo($rawQuestion) || $hasIndo($rawPassage) || $hasIndo((string)$act['title'])) {
            $englishPassage = "Reading aloud regularly helps English learners improve pronunciation, fluency, and reading comprehension while building speaking confidence.";
            $englishQuestion = "According to the passage, what is one major benefit of reading aloud in English?";
            $options = [
                "It improves pronunciation, fluency, and speaking confidence.",
                "It teaches learners how to perform silent speed reading.",
                "It translates written stories directly into another language.",
                "It replaces the need for listening practice completely."
            ];
            $c['passage'] = $englishPassage;
            $c['question'] = $englishQuestion;
            $c['options'] = $options;
            $c['answer'] = 'A';
            $c['explanation'] = "The text states that reading aloud helps learners improve pronunciation, fluency, and confidence.";
            $newTitle = "Reading Comprehension: " . substr($englishQuestion, 0, 45) . "...";
            $newInst = "Read the short context when provided, then select one answer.";
            $stmtUpd->execute([$newTitle, $newInst, json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $act['id']]);
        }
    }

    // 4. Reset active sessions to ensure fresh English snapshot
    try {
        $pdo->exec("DELETE FROM speaking_sessions WHERE status='active'");
        $pdo->exec("DELETE FROM writing_sessions WHERE status='active'");
    } catch (\Throwable $e) {
        // Continue if tables have constraints
    }
};
