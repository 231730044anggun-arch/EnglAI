<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/koneksi.php';require_once __DIR__ . '/../../vendor/autoload.php';
use EnglAI\Mvp\QuizService;use EnglAI\Mvp\StudentSession;use EnglAI\Quiz\Phase3QuizService;
$member=StudentSession::requireMember(db());$id=(int)($_GET['id']??0);
try{$stmt=db()->prepare('SELECT p.id,q.quiz_mode FROM quiz_participants p JOIN quiz_sessions q ON q.id=p.quiz_session_id WHERE p.quiz_session_id=? AND p.member_id=? AND q.classroom_id=?');$stmt->execute([$id,(int)$member['id'],(int)$member['classroom_id']]);$row=$stmt->fetch();$participantId=(int)($row['id']??0);if(!$participantId)throw new RuntimeException('Peserta tidak terdaftar.');db()->prepare('UPDATE quiz_participants SET last_seen_at=NOW() WHERE id=?')->execute([$participantId]);$service=!empty($row['quiz_mode'])?new Phase3QuizService(db()):new QuizService(db());json_response(['success'=>true,'data'=>$service->status($id,$participantId)]);}
catch(Throwable $e){json_response(['success'=>false,'error'=>$e->getMessage()],404);}
