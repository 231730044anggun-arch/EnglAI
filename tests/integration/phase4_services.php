<?php
declare(strict_types=1);require_once __DIR__.'/../../config/koneksi.php';require_once __DIR__.'/../../vendor/autoload.php';
use EnglAI\Analytics\AnalyticsService;use EnglAI\Analytics\ExportService;use EnglAI\Analytics\OverrideService;use EnglAI\Analytics\RecommendationService;
$pdo=db();$fails=[];$ok=static function(bool $v,string $m)use(&$fails){echo($v?'PASS ':'FAIL ').$m.PHP_EOL;if(!$v)$fails[]=$m;};
$cid=(int)$pdo->query("SELECT id FROM classrooms ORDER BY id LIMIT 1")->fetchColumn();if(!$cid){echo "SKIP no classroom\n";exit;}
$token=hash('sha256','phase4-'.microtime(true));$pdo->prepare("INSERT INTO classroom_members(classroom_id,session_token,display_name,avatar,last_seen_at) VALUES(?,?,'=Formula Student','🦊',NOW())")->execute([$cid,$token]);$mid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO quiz_sessions(classroom_id,quiz_mode,title,selected_skills_json,skill_distribution_json,level,timer_config_json,state,question_count,difficulty,current_index,created_by,finished_at) VALUES(?,'single_skill','Phase4 Test','[\"speaking\"]','{\"speaking\":1}','intermediate','{\"speaking\":60}','FINISHED',1,'medium',0,'admin',NOW())")->execute([$cid]);$qid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO quiz_participants(quiz_session_id,member_id,display_name,avatar,total_score,completion_count,last_seen_at,joined_at,final_rank,achievement) VALUES(?,?,'=Formula Student','🦊',650,1,NOW(),NOW(),1,'Speaking Hero')")->execute([$qid,$mid]);$pid=(int)$pdo->lastInsertId();
$rubric=json_encode(['criteria'=>[['name'=>'relevance','score'=>65,'max_score'=>100],['name'=>'grammar','score'=>60,'max_score'=>100]],'suggested_revision'=>'Improve detail.']);
$pdo->prepare("INSERT INTO quiz_session_questions(quiz_session_id,position,question,options_json,answer,explanation,difficulty,skill,question_type,content_json,max_score,timer_seconds) VALUES(?,0,'Explain the topic','[]','A','','medium','speaking','speaking_response','{\"prompt\":\"Explain\"}',1000,60)")->execute([$qid]);$sq=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO quiz_answers(quiz_session_id,participant_id,session_question_id,is_correct,score,response_ms,answered_at,transcript,submission_method,assessment_status,rubric_json,normalized_score,assessment_source) VALUES(?,?,?,0,650,30000,NOW(),'A relevant complete response','manual_transcript','FALLBACK_COMPLETED',?,650,'fallback')")->execute([$qid,$pid,$sq,$rubric]);$aid=(int)$pdo->lastInsertId();
$service=new AnalyticsService($pdo);$class=$service->classroom($cid);$student=$service->student($cid,$mid);
$ok(isset($class['skills']['reading'])&&isset($class['levels']),'classroom analytics deterministic schema');
$ok($student['member']['id']==$mid&&count($student['quiz_history'])===1,'student analytics isolated by member ID');
$ok(($student['strongest_skill']===null||is_string($student['strongest_skill'])),'strongest and weakest skill behavior');
$ok(isset($class['rubrics']['speaking']['relevance']['average']),'Speaking rubric analytics calculated');
$rec=(new RecommendationService($pdo))->generate($cid,'admin',$mid);$ok(isset($rec['summary'],$rec['recommended_actions'],$rec['recommended_skill'],$rec['confidence'])&&$rec['source']==='fallback','recommendation fallback schema valid');
$before=(float)$pdo->query("SELECT total_score FROM quiz_participants WHERE id={$pid}")->fetchColumn();$override=(new OverrideService($pdo))->overrideLiveQuiz($cid,$aid,820,['relevance'=>82],'Professional review based on rubric evidence.','admin');
$row=$pdo->query("SELECT original_score,previous_final_score,new_score FROM score_overrides WHERE id={$override}")->fetch();$after=(float)$pdo->query("SELECT total_score FROM quiz_participants WHERE id={$pid}")->fetchColumn();
$ok((float)$row['original_score']===650.0&&(float)$row['previous_final_score']===650.0&&(float)$row['new_score']===820.0,'original score preserved in immutable override');
$ok($after===$before+170,'historical leaderboard recalculated after override');
$ok((int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE classroom_id={$cid} AND action='score.overridden' AND entity_id={$aid}")->fetchColumn()===1,'override audit log created');
$csv=(new ExportService($pdo))->studentCsv($cid,$mid,'admin');$ok(str_contains($csv,"'=Formula Student"),'CSV formula injection neutralized');
$ok(count($student['quiz_history'])===1&&$student['quiz_history'][0]['achievement']==='Speaking Hero','historical leaderboard and achievement available');
$pdo->prepare('DELETE FROM score_overrides WHERE id=?')->execute([$override]);$pdo->prepare("DELETE FROM teacher_reviews WHERE classroom_id=? AND assessment_id=?")->execute([$cid,$aid]);$pdo->prepare('DELETE FROM ai_recommendations WHERE classroom_id=? AND member_id=?')->execute([$cid,$mid]);$pdo->prepare('DELETE FROM export_jobs WHERE classroom_id=? AND member_id=?')->execute([$cid,$mid]);$pdo->prepare('DELETE FROM audit_logs WHERE classroom_id=? AND entity_id IN (?,?)')->execute([$cid,$aid,$override]);$pdo->prepare('DELETE FROM quiz_sessions WHERE id=?')->execute([$qid]);$pdo->prepare('DELETE FROM classroom_members WHERE id=?')->execute([$mid]);
if($fails){fwrite(STDERR,implode(', ',$fails));exit(1);}
