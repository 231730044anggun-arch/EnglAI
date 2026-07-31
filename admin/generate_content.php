<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../vendor/autoload.php';
use EnglAI\Mvp\ClassroomService;
use EnglAI\Mvp\ContentBankGenerator;
use EnglAI\Security\Csrf;
require_admin();
$id=(int)($_POST['classroom_id']??0);if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
Csrf::requireValid($_POST['csrf_token']??null);$teacher=(string)($_SESSION['admin_username']??env_value('ADMIN_USERNAME','admin'));
try{(new ClassroomService(db()))->requireOwned($id,$teacher);$result=(new ContentBankGenerator(db()))->generate($id);
$message=sprintf('Generation %s: %d Self Learning, %d Live Quiz; %d duplikat ditolak.',$result['source'],$result['self_learning'],$result['live_quiz'],$result['duplicates']);
if($result['warning'])$message.=' '.$result['warning'];}catch(Throwable $e){app_log('error','MVP generation failed',['classroom_id'=>$id,'type'=>get_class($e)]);$message='Generation gagal dengan aman. ID laporan: '.request_id();}
header('Location: /admin/classroom.php?id='.$id.'&message='.rawurlencode($message));exit;
