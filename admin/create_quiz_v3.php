<?php
declare(strict_types=1);
require_once __DIR__.'/_auth.php';require_once __DIR__.'/../vendor/autoload.php';
use EnglAI\Mvp\ClassroomService;use EnglAI\Quiz\QuizWizardService;use EnglAI\Security\Csrf;
require_admin();if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
Csrf::requireValid($_POST['csrf_token']??null);$id=(int)($_POST['classroom_id']??0);$teacher=(string)($_SESSION['admin_username']??env_value('ADMIN_USERNAME','admin'));
try{(new ClassroomService(db()))->requireOwned($id,$teacher);$skills=$_POST['skills']??[];$distribution=$_POST['distribution']??[];if(array_sum(array_map('intval',is_array($distribution)?$distribution:[]))===0)$distribution=null;$timers=$_POST['timers']??[];$quiz=(new QuizWizardService(db()))->create($id,$teacher,['quiz_mode'=>$_POST['quiz_mode']??'single_skill','skills'=>$skills,'skill'=>$_POST['skill']??'reading','level'=>$_POST['level']??'intermediate','question_count'=>$_POST['question_count']??10,'distribution'=>$distribution,'timers'=>$timers,'question_order'=>$_POST['question_order']??'mixed','review_enabled'=>isset($_POST['review_enabled']),'title'=>$_POST['title']??'']);header('Location: /admin/quiz.php?id='.$quiz);exit;}
catch(Throwable $e){header('Location: /admin/quiz_wizard.php?classroom_id='.$id.'&message='.rawurlencode($e->getMessage()));exit;}
