<?php
declare(strict_types=1);
require_once __DIR__.'/_auth.php';require_once __DIR__.'/../vendor/autoload.php';
use EnglAI\Learning\Level;use EnglAI\Mvp\ClassroomService;use EnglAI\Security\Csrf;
require_admin();if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}$id=(int)($_POST['classroom_id']??0);Csrf::requireValid($_POST['csrf_token']??null);$teacher=(string)($_SESSION['admin_username']??env_value('ADMIN_USERNAME','admin'));
try{(new ClassroomService(db()))->requireOwned($id,$teacher);$level=Level::validate((string)($_POST['level']??''));db()->prepare('UPDATE classrooms SET default_level=?,level_confirmed_at=NOW() WHERE id=?')->execute([$level,$id]);$message='Default level dikonfirmasi: '.ucfirst($level).'.';}catch(Throwable $e){$message=$e->getMessage();}
header('Location: /admin/classroom.php?id='.$id.'&message='.rawurlencode($message).'#lesson-plan');exit;
