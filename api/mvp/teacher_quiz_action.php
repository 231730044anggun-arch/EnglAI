<?php
declare(strict_types=1);
require_once __DIR__ . '/../../admin/_auth.php';require_once __DIR__ . '/../../vendor/autoload.php';
use EnglAI\Mvp\ClassroomService;use EnglAI\Mvp\QuizService;use EnglAI\Quiz\Phase3QuizService;use EnglAI\Security\Csrf;
require_admin();if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['success'=>false,'error'=>'Method not allowed.'],405);Csrf::requireValid($_POST['csrf_token']??null);
$id=(int)($_POST['quiz_id']??0);
try{$stmt=db()->prepare('SELECT classroom_id,quiz_mode FROM quiz_sessions WHERE id=?');$stmt->execute([$id]);$quiz=$stmt->fetch();$classroomId=(int)($quiz['classroom_id']??0);(new ClassroomService(db()))->requireOwned($classroomId,(string)($_SESSION['admin_username']??env_value('ADMIN_USERNAME','admin')));$service=!empty($quiz['quiz_mode'])?new Phase3QuizService(db()):new QuizService(db());if(($_POST['action']??'')==='start')$service->start($id,$classroomId);elseif(($_POST['action']??'')==='close')$service->close($id,$classroomId);else throw new RuntimeException('Action tidak valid.');json_response(['success'=>true,'data'=>$service->status($id)]);}
catch(Throwable $e){json_response(['success'=>false,'error'=>$e->getMessage()],409);}
