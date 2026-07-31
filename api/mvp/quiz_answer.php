<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/koneksi.php';require_once __DIR__ . '/../../vendor/autoload.php';
use EnglAI\Mvp\QuizService;use EnglAI\Mvp\StudentSession;use EnglAI\Quiz\Phase3QuizService;use EnglAI\Security\Csrf;
$member=StudentSession::requireMember(db());if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['success'=>false,'error'=>'Method not allowed.'],405);Csrf::requireValid($_POST['csrf_token']??null);$id=(int)($_POST['quiz_id']??0);
try{$stmt=db()->prepare('SELECT p.id,q.quiz_mode FROM quiz_participants p JOIN quiz_sessions q ON q.id=p.quiz_session_id WHERE p.quiz_session_id=? AND p.member_id=? AND q.classroom_id=?');$stmt->execute([$id,(int)$member['id'],(int)$member['classroom_id']]);$row=$stmt->fetch();$participantId=(int)($row['id']??0);if(!$participantId)throw new RuntimeException('Peserta tidak valid.');$result=!empty($row['quiz_mode'])?(new Phase3QuizService(db()))->submit($id,$participantId,['answer'=>$_POST['answer']??'','transcript'=>$_POST['transcript']??'','writing_submission'=>$_POST['writing_submission']??'','submission_method'=>$_POST['submission_method']??'']):(new QuizService(db()))->submit($id,$participantId,(string)($_POST['answer']??''));json_response(['success'=>true,'data'=>$result]);}
catch(Throwable $e){json_response(['success'=>false,'error'=>$e->getMessage()],409);}
