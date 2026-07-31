<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../vendor/autoload.php';
use EnglAI\Mvp\ClassroomService;
use EnglAI\Mvp\QuizService;
use EnglAI\Security\Csrf;
require_admin();if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
Csrf::requireValid($_POST['csrf_token']??null);$id=(int)($_POST['classroom_id']??0);$teacher=(string)($_SESSION['admin_username']??env_value('ADMIN_USERNAME','admin'));
try{(new ClassroomService(db()))->requireOwned($id,$teacher);$quizId=(new QuizService(db()))->create($id,$teacher,(int)($_POST['question_count']??10),(string)($_POST['difficulty']??'medium'));header('Location: /admin/quiz.php?id='.$quizId);exit;}
catch(Throwable $e){header('Location: /admin/classroom.php?id='.$id.'&message='.rawurlencode($e->getMessage()));exit;}
