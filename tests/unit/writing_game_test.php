<?php
declare(strict_types=1);$root=dirname(__DIR__,2);$service=file_get_contents($root.'/src/Learning/WritingSessionService.php');$page=file_get_contents($root.'/student/writing.php');$js=file_get_contents($root.'/assets/js/writing-game.js');$migration=file_get_contents($root.'/database/migrations/202608020006_writing_game_sessions.php').file_get_contents($root.'/database/migrations/202608020007_writing_session_fingerprint.php');$assert=static function(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);};
$assert(str_contains($service,'public const TASKS=10')&&str_contains($service,'array_slice($pool,0,10)'),'Writing session must contain 10 tasks.');
$assert(str_contains($service,"'sentence_writing'")&&str_contains($service,"'guided_writing'")&&str_contains($service,"'short_open_response'"),'Level task modes missing.');
$assert(str_contains($service,'$unique')&&str_contains($service,'lesson_plan_id=?')&&str_contains($service,'sequence_fingerprint'),'Unique active-RPP selection missing.');
$assert(str_contains($service,'private function normalize')&&str_contains($service,'private function fingerprint')&&str_contains($service,'describe|describing|sentence'),'Semantic duplicate guard missing.');
$assert(str_contains($page,'INTERVAL 30 SECOND')&&str_contains($service,'UNIX_TIMESTAMP(task_deadline_at)*1000'),'Backend deadline missing.');
$assert(str_contains($js,'Math.max(0,Math.min(30,raw))')&&str_contains($js,'timer.replaceChildren'),'Bounded integer timer missing.');
$assert(str_contains($js,'timed_out:timed?"1":"0"')&&str_contains($page,'$answer===\'\'?\'no_response\''),'Timeout/no-response flow missing.');
$assert(str_contains($js,'localStorage.setItem(draftKey(),area.value)')&&str_contains($js,'700'),'Debounced draft missing.');
$assert(str_contains($migration,'UNIQUE KEY uq_writing_task(session_id,task_id)'),'Idempotency constraint missing.');
$assert(str_contains($js,'Review Writing')&&str_contains($js,'Versi yang diperbaiki')&&str_contains($js,'Contoh jawaban yang lebih baik')&&str_contains($js,'rubric-chip'),'Structured final review missing.');
$assert(str_contains($js,'WRITING_MUSIC_MASTER_GAIN=1.0')&&str_contains($js,'createDynamicsCompressor')&&str_contains($js,'stopMusic'),'Writing music contract missing.');
$assert(!str_contains($js,'"Correct"')&&!str_contains($js,'"Incorrect"')&&!str_contains($js,'innerHTML'),'Gameplay exposes mid-game correction or unsafe DOM.');
$assert(!preg_match('/GEMINI_API_KEY|AIza[0-9A-Za-z_-]+/',$js),'Frontend secret found.');echo "Writing game targeted OK.\n";
