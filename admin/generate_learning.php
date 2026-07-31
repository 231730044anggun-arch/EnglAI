<?php
declare(strict_types=1);
require_once __DIR__.'/_auth.php';require_once __DIR__.'/../vendor/autoload.php';
use EnglAI\Learning\LearningContentGenerator;use EnglAI\Mvp\ClassroomService;use EnglAI\Security\Csrf;
require_admin();if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}$id=(int)($_POST['classroom_id']??0);Csrf::requireValid($_POST['csrf_token']??null);$teacher=(string)($_SESSION['admin_username']??env_value('ADMIN_USERNAME','admin'));
try{(new ClassroomService(db()))->requireOwned($id,$teacher);$skill=(string)($_POST['skill']??'');$level=(string)($_POST['level']??'');$result=(new LearningContentGenerator(db()))->generate($id,$skill,$level);$message=sprintf('%s %s: %d activities dibuat, %d duplikat ditolak · %s.',ucfirst($result['skill']),ucfirst($result['level']),$result['activities'],$result['duplicates'],$result['source']);}catch(Throwable $e){app_log('error','Learning generation failed',['classroom_id'=>$id,'type'=>get_class($e),'request_id'=>request_id()]);$message='Generation gagal dengan aman. ID laporan: '.request_id();}
header('Location: /admin/classroom.php?id='.$id.'&message='.rawurlencode($message).'#self-learning');exit;
