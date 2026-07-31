<?php
declare(strict_types=1);
require_once __DIR__.'/_auth.php';require_once __DIR__.'/../vendor/autoload.php';
use EnglAI\Learning\RppAnalysisService;use EnglAI\Mvp\ClassroomService;use EnglAI\Security\Csrf;
require_admin();if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}$id=(int)($_POST['classroom_id']??0);Csrf::requireValid($_POST['csrf_token']??null);$teacher=(string)($_SESSION['admin_username']??env_value('ADMIN_USERNAME','admin'));
try{(new ClassroomService(db()))->requireOwned($id,$teacher);$result=(new RppAnalysisService(db()))->analyze($id);$message='AI Analysis selesai · rekomendasi '.ucfirst($result['recommended_level']).' · source '.$result['source'].'.';}catch(Throwable $e){app_log('error','RPP analysis failed',['classroom_id'=>$id,'type'=>get_class($e),'request_id'=>request_id()]);$message='Analysis gagal dengan aman. ID laporan: '.request_id();}
header('Location: /admin/classroom.php?id='.$id.'&message='.rawurlencode($message).'#lesson-plan');exit;
