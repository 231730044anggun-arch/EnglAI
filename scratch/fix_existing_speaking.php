<?php
require_once __DIR__ . '/../config/koneksi.php';
$pdo = db();

$speakingPhrases = [
    "The bekantan lives in the mangrove forests of Kalimantan.",
    "Cendrawasih is known for its bright ornamental feathers.",
    "The maleo buries its eggs in the warm volcanic sand.",
    "Illegal hunting and habitat loss threaten the survival of the Javan rhino.",
    "The sumatran tiger has beautiful dark stripes on its body.",
    "The flores hawk-eagle is one of the rarest raptors in Indonesia.",
    "We must protect the helmeted hornbill from illegal trade.",
    "Sangihe shrike-thrush is a critically endangered bird.",
    "Mangrove forests protect the coastal area from erosion.",
    "Indonesia is home to thousands of unique endemic species."
];

// Fetch all speaking activities
$stmt = $pdo->prepare("SELECT id, content_json FROM learning_activities WHERE skill = 'speaking'");
$stmt->execute();
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;
foreach ($activities as $i => $act) {
    $item = json_decode((string)$act['content_json'], true);
    if (!is_array($item)) continue;
    
    // Choose a phrase
    $phrase = $speakingPhrases[$i % count($speakingPhrases)];
    
    // Update fields
    $item['prompt'] = $phrase;
    $item['example_response'] = $phrase;
    $item['scenario'] = "Read the sentence aloud with clear pronunciation.";
    $item['instruction'] = "Read the following sentence aloud with clear pronunciation.";
    
    $canonical = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    // Update database row
    $up = $pdo->prepare("UPDATE learning_activities SET title = ?, instruction = ?, content_json = ? WHERE id = ?");
    $up->execute([
        "Speaking Practice " . (($i % 10) + 1),
        "Read the following sentence aloud with clear pronunciation.",
        $canonical,
        $act['id']
    ]);
    $updated++;
}

echo "Successfully converted {$updated} speaking activities in database to Read Aloud sentences.\n";
