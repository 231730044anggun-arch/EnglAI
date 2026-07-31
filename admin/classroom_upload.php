<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../vendor/autoload.php';
use EnglAI\LessonPlan\RppTextCleaner;
use EnglAI\Mvp\ClassroomService;
use EnglAI\Security\Csrf;
use EnglAI\Upload\RppUploadValidator;
require_admin();
$id=(int)($_POST['classroom_id']??0);
function classroom_redirect(int $id,string $message):never{header('Location: /admin/classroom.php?id='.$id.'&message='.rawurlencode($message));exit;}
function mvp_element_text(mixed $element):string{$text=method_exists($element,'getText')?(string)$element->getText():'';foreach(['getElements','getRows','getCells'] as $method){if(method_exists($element,$method))foreach($element->$method() as $child)$text.=' '.mvp_element_text($child);}return $text;}
if($_SERVER['REQUEST_METHOD']!=='POST')classroom_redirect($id,'Gunakan form upload.');
Csrf::requireValid($_POST['csrf_token']??null);
$teacher=(string)($_SESSION['admin_username']??env_value('ADMIN_USERNAME','admin'));
try{(new ClassroomService(db()))->requireOwned($id,$teacher);$file=$_FILES['rpp_file']??[];$valid=(new RppUploadValidator())->validate($file);}
catch(Throwable $e){classroom_redirect($id,$e->getMessage());}
$stored=bin2hex(random_bytes(16)).'.'.$valid['extension'];$target=dirname(__DIR__).'/uploads/'.$stored;
if(!move_uploaded_file((string)$file['tmp_name'],$target))classroom_redirect($id,'File tidak dapat disimpan.');
try{
 if($valid['extension']==='pdf'){$text=(new \Smalot\PdfParser\Parser())->parseFile($target)->getText();}
 else{$doc=\PhpOffice\PhpWord\IOFactory::load($target);$text='';foreach($doc->getSections() as $section)foreach($section->getElements() as $element)$text.=' '.mvp_element_text($element);}
 $text=RppTextCleaner::clean($text);if($text==='')throw new RuntimeException('Dokumen tidak memiliki teks yang dapat dibaca.');
 $pdo=db();$pdo->beginTransaction();$pdo->prepare('UPDATE classroom_lesson_plans SET is_active=0 WHERE classroom_id=?')->execute([$id]);
 $version=(int)$pdo->query('SELECT COALESCE(MAX(version),0)+1 FROM classroom_lesson_plans WHERE classroom_id='.(int)$id)->fetchColumn();
 $stmt=$pdo->prepare('INSERT INTO classroom_lesson_plans(classroom_id,original_name,stored_name,file_type,extracted_text,version,is_active) VALUES(?,?,?,?,?,?,1)');
 $stmt->execute([$id,$valid['original_name'],$stored,$valid['extension'],$text,$version]);$pdo->commit();
 classroom_redirect($id,'RPP classroom berhasil diunggah.');
}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();if(is_file($target))unlink($target);app_log('error','Classroom RPP upload failed',['classroom_id'=>$id,'type'=>get_class($e)]);classroom_redirect($id,'RPP tidak dapat diproses. ID laporan: '.request_id());}
