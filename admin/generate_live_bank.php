<?php
declare(strict_types=1);
require_once __DIR__.'/_auth.php';require_once __DIR__.'/../vendor/autoload.php';
use EnglAI\Mvp\ClassroomService;use EnglAI\Quiz\LiveQuizBankGenerator;use EnglAI\Security\Csrf;
require_admin();if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
Csrf::requireValid($_POST['csrf_token']??null);$id=(int)($_POST['classroom_id']??0);$level=(string)($_POST['level']??'intermediate');$teacher=(string)($_SESSION['admin_username']??env_value('ADMIN_USERNAME','admin'));
try{(new ClassroomService(db()))->requireOwned($id,$teacher);$skill=(string)($_POST['skill']??'all');$service=new LiveQuizBankGenerator(db());$result=$skill==='all'?$service->generateAll($id,$level):[$skill=>$service->generate($id,$skill,$level)];$total=array_sum(array_column($result,'created'));$message="Live Quiz bank {$level}: {$total} item baru tersimpan (fallback demo-safe).";}
catch(Throwable $e){$message=$e->getMessage();}
header('Location: /admin/quiz_wizard.php?classroom_id='.$id.'&message='.rawurlencode($message));exit;
