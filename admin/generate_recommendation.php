<?php
declare(strict_types=1);require_once __DIR__.'/_auth.php';require_once __DIR__.'/../vendor/autoload.php';
use EnglAI\Analytics\RecommendationService;use EnglAI\Mvp\ClassroomService;use EnglAI\Security\Csrf;
require_admin();if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}Csrf::requireValid($_POST['csrf_token']??null);$id=(int)($_POST['classroom_id']??0);$actor=(string)($_SESSION['admin_username']??'admin');(new ClassroomService(db()))->requireOwned($id,$actor);(new RecommendationService(db()))->generate($id,$actor,isset($_POST['member_id'])?(int)$_POST['member_id']:null);header('Location: '.(isset($_POST['member_id'])?'/admin/student_analytics.php?classroom_id='.$id.'&member_id='.(int)$_POST['member_id']:'/admin/analytics.php?classroom_id='.$id));exit;
