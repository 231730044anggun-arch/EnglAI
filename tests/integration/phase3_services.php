<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/koneksi.php';
require_once __DIR__.'/../../vendor/autoload.php';

use EnglAI\Quiz\LiveQuizBankGenerator;
use EnglAI\Quiz\Phase3QuizService;
use EnglAI\Quiz\QuizWizardService;

$pdo=db();$failures=[];$assert=static function(bool $ok,string $label)use(&$failures):void{echo($ok?'PASS ':'FAIL ').$label.PHP_EOL;if(!$ok)$failures[]=$label;};
$classroom=(int)$pdo->query("SELECT id FROM classrooms WHERE status='active' AND EXISTS(SELECT 1 FROM classroom_lesson_plans p WHERE p.classroom_id=classrooms.id AND p.is_active=1) ORDER BY id LIMIT 1")->fetchColumn();
if(!$classroom){echo "SKIP no classroom lesson plan\n";exit(0);}
$level=(string)($pdo->query("SELECT default_level FROM classrooms WHERE id={$classroom}")->fetchColumn()?:'intermediate');
$generator=new LiveQuizBankGenerator($pdo);$banks=$generator->generateAll($classroom,$level,30);
$assert(count($banks)===4&&min(array_column($banks,'total'))>=30,'fallback bank has four skills and 30 items each');
$wizard=new QuizWizardService($pdo);
$failed=false;try{$wizard->create($classroom,'admin',['quiz_mode'=>'mixed_skills','skills'=>['reading'],'level'=>$level,'question_count'=>10]);}catch(InvalidArgumentException){$failed=true;}$assert($failed,'mixed mode requires two skills');
$finalId=$wizard->create($classroom,'admin',['quiz_mode'=>'final_challenge','level'=>$level,'question_count'=>10,'review_enabled'=>true]);
$dist=json_decode((string)$pdo->query("SELECT skill_distribution_json FROM quiz_sessions WHERE id={$finalId}")->fetchColumn(),true);
$assert(array_sum($dist)===10&&count($dist)===4,'Final Challenge distribution uses all skills');
$assert((int)$pdo->query("SELECT COUNT(*) FROM quiz_session_questions WHERE quiz_session_id={$finalId}")->fetchColumn()===10,'question snapshot stored');
$assert((int)$pdo->query("SELECT COUNT(*) FROM quiz_session_questions q JOIN live_quiz_items i ON i.id=q.source_item_id WHERE q.quiz_session_id={$finalId} AND q.source_question_id IS NULL")->fetchColumn()===10,'Self Learning bank is isolated');
$listeningId=$wizard->create($classroom,'admin',['quiz_mode'=>'single_skill','skills'=>['listening'],'level'=>$level,'question_count'=>1]);
$service=new Phase3QuizService($pdo);$service->start($listeningId,$classroom);$status=$service->status($listeningId);
$assert($status['question']['skill']==='listening'&&!array_key_exists('transcript',$status['question']['content']),'Listening transcript stays locked');
$speakingId=$wizard->create($classroom,'admin',['quiz_mode'=>'single_skill','skills'=>['speaking'],'level'=>$level,'question_count'=>1]);
$token=hash('sha256','phase3-test-'.microtime(true));$pdo->prepare('INSERT INTO classroom_members(classroom_id,session_token,display_name,avatar,last_seen_at) VALUES (?,?,"Phase3 Test","🦊",NOW())')->execute([$classroom,$token]);$member=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO quiz_participants(quiz_session_id,member_id,display_name,avatar,last_seen_at,joined_at) VALUES (?,?,"Phase3 Test","🦊",NOW(),NOW())')->execute([$speakingId,$member]);$participant=(int)$pdo->lastInsertId();
$service->start($speakingId,$classroom);$result=$service->submit($speakingId,$participant,['transcript'=>'I explain the classroom lesson with a relevant supporting detail and complete sentences.','submission_method'=>'manual_transcript']);
$assert($result['assessment_status']==='PENDING','Speaking submission enters pending assessment');
$evaluating=$service->status($speakingId,$participant);$assert($evaluating['state']==='EVALUATING','pending assessment moves quiz to EVALUATING');$finished=$service->status($speakingId,$participant);
$answer=$pdo->query("SELECT assessment_status,normalized_score FROM quiz_answers WHERE participant_id={$participant}")->fetch();
$assert(in_array($answer['assessment_status'],['COMPLETED','FALLBACK_COMPLETED'],true)&&(int)$answer['normalized_score']>=0&&(int)$answer['normalized_score']<=1000,'backend assessment completes with bounded score');
$assert($finished['state']==='FINISHED'&&($finished['leaderboard'][0]['achievement']??'')==='Speaking Hero','finished leaderboard assigns skill achievement');
$duplicate=false;try{$service->submit($speakingId,$participant,['transcript'=>'duplicate']);}catch(Throwable){$duplicate=true;}$assert($duplicate,'double submission is rejected');
$writingId=$wizard->create($classroom,'admin',['quiz_mode'=>'single_skill','skills'=>['writing'],'level'=>$level,'question_count'=>1]);$pdo->prepare('INSERT INTO quiz_participants(quiz_session_id,member_id,display_name,avatar,last_seen_at,joined_at) VALUES (?,?,"Phase3 Test","🦊",NOW(),NOW())')->execute([$writingId,$member]);$writingParticipant=(int)$pdo->lastInsertId();$service->start($writingId,$classroom);
$tooShort=false;try{$service->submit($writingId,$writingParticipant,['writing_submission'=>'Too short.']);}catch(InvalidArgumentException){$tooShort=true;}$assert($tooShort,'Writing word validation rejects short response');
$writingPrompt=$service->status($writingId,$writingParticipant);$needed=(int)($writingPrompt['question']['content']['minimum_words']??20)+2;$writing=implode(' ',array_fill(0,$needed,'evidence'));$service->submit($writingId,$writingParticipant,['writing_submission'=>$writing]);$service->status($writingId,$writingParticipant);$writingStatus=$service->status($writingId,$writingParticipant);$assert($writingStatus['state']==='FINISHED'&&($writingStatus['leaderboard'][0]['achievement']??'')==='Writing Expert','Writing assessment reaches final leaderboard');
$pdo->prepare('DELETE FROM quiz_sessions WHERE id IN (?,?,?,?)')->execute([$finalId,$listeningId,$speakingId,$writingId]);$pdo->prepare('DELETE FROM classroom_members WHERE id=?')->execute([$member]);
if($failures){fwrite(STDERR,'Failures: '.implode(', ',$failures).PHP_EOL);exit(1);}
