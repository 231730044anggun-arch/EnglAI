<?php
declare(strict_types=1);
require_once __DIR__.'/../config/koneksi.php';
require_once __DIR__.'/../vendor/autoload.php';
use EnglAI\Learning\ReadingBankGenerator;
$classroomId=(int)($argv[1]??0);$level=(string)($argv[2]??'basic');$mode=(string)($argv[3]??'target');
if($classroomId<1){$classroomId=(int)db()->query("SELECT classroom_id FROM classroom_lesson_plans WHERE is_active=1 ORDER BY classroom_id LIMIT 1")->fetchColumn();}
if($classroomId<1){fwrite(STDERR,"No classroom with an active RPP was found.\n");exit(2);}
try{$result=(new ReadingBankGenerator(db()))->generate($classroomId,$level,$mode);echo json_encode(['classroom_id'=>$classroomId,'level'=>$result['level'],'requested'=>$result['requested'],'valid'=>$result['valid'],'rejected'=>$result['rejected'],'duplicates'=>$result['duplicates'],'total_gemini'=>$result['total_gemini'],'source'=>$result['source']],JSON_UNESCAPED_SLASHES).PHP_EOL;}catch(Throwable $e){app_log('error','Gemini Reading CLI generation failed',['classroom_id'=>$classroomId,'type'=>get_class($e),'request_id'=>request_id()]);fwrite(STDERR,"Gemini Reading generation failed (".$e->getMessage()."). Request ID: ".request_id().PHP_EOL);exit(1);}
