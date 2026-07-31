<?php
declare(strict_types=1);
require_once __DIR__ . '/../../admin/_auth.php';require_once __DIR__ . '/../../vendor/autoload.php';
use EnglAI\Mvp\ClassroomService;use EnglAI\Mvp\QuizService;use EnglAI\Quiz\Phase3QuizService;
require_admin();$id=(int)($_GET['id']??0);
try{$stmt=db()->prepare('SELECT classroom_id,quiz_mode FROM quiz_sessions WHERE id=?');$stmt->execute([$id]);$quiz=$stmt->fetch();$classroomId=(int)($quiz['classroom_id']??0);(new ClassroomService(db()))->requireOwned($classroomId,(string)($_SESSION['admin_username']??env_value('ADMIN_USERNAME','admin')));$service=!empty($quiz['quiz_mode'])?new Phase3QuizService(db()):new QuizService(db());json_response(['success'=>true,'data'=>$service->status($id)]);}
catch(Throwable $e){json_response(['success'=>false,'error'=>$e->getMessage()],404);}
