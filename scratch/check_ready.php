<?php
require_once __DIR__ . '/../config/koneksi.php';
require_once __DIR__ . '/../vendor/autoload.php';
use EnglAI\Learning\ReadingSessionService;

$pdo = db();
$memberId = 5;
$classroomId = 2;
$planId = 4;
$level = 'basic';
$totalQuestions = 60; // since valid_count is 60

$q=$pdo->prepare("SELECT l.*,MAX(CASE WHEN rs.member_id=? THEN ra.answered_at END) last_used FROM learning_activities l LEFT JOIN reading_attempts ra ON ra.passage_id=l.id LEFT JOIN reading_sessions rs ON rs.id=ra.reading_session_id WHERE l.classroom_id=? AND l.lesson_plan_id=? AND l.skill='reading' AND l.level=? AND l.activity_type='standalone_question' AND l.source='ai' AND l.status='ready' GROUP BY l.id ORDER BY last_used IS NULL DESC,last_used ASC,RAND()");
$q->execute([$memberId,$classroomId,$planId,$level]);
$pool=[];$ids=[];$texts=[];$contexts=[];
$skipped = [];
foreach($q->fetchAll()as$row){
   $item=json_decode((string)$row['content_json'],true);
   if(!is_array($item)) {
       $skipped[] = [$row['id'], 'not array'];
       continue;
   }
   if(($item['source']??'')!=='gemini'&&($item['source']??'')!=='local_fallback') {
       $skipped[] = [$row['id'], 'source: ' . ($item['source']??'')];
       continue;
   }
   $id=(string)($item['id']??'');
   $text=ReadingSessionService::normalizeQuestionText((string)($item['question']??''));
   $context=ReadingSessionService::normalizeQuestionText((string)($item['short_context']??''));
   $optionIds=array_column($item['options']??[],'id');
   if($id==='') {
       $skipped[] = [$row['id'], 'id empty'];
       continue;
   }
   if($text==='') {
       $skipped[] = [$row['id'], 'text empty'];
       continue;
   }
   if(isset($ids[$id])) {
       $skipped[] = [$row['id'], 'id duplicate: ' . $id];
       continue;
   }
   if(isset($texts[$text])) {
       $skipped[] = [$row['id'], 'text duplicate: ' . $text];
       continue;
   }
   if($context!==''&&isset($contexts[$context])) {
       $skipped[] = [$row['id'], 'context duplicate: ' . $context];
       continue;
   }
   if(count($optionIds)!==4) {
       $skipped[] = [$row['id'], 'option count: ' . count($optionIds)];
       continue;
   }
   if(count(array_unique($optionIds))!==4) {
       $skipped[] = [$row['id'], 'option ids not unique'];
       continue;
   }
   if(!in_array($item['correct_option_id']??'',$optionIds,true)) {
       $skipped[] = [$row['id'], 'correct_option_id not in option ids'];
       continue;
   }
   $item['activity_id']=(int)$row['id'];
   $pool[]=$item;
   $ids[$id]=true;$texts[$text]=true;
   if($context!=='')$contexts[$context]=true;
   if(count($pool)===$totalQuestions)break;
}

echo "Total pool size: " . count($pool) . "\n";
echo "Skipped items count: " . count($skipped) . "\n";
print_r($skipped);
