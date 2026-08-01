<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/vendor/autoload.php';require_once dirname(__DIR__).'/config/koneksi.php';require_once dirname(__DIR__).'/src/LessonPlan/RppTextCleaner.php';
$failures=[];function check(bool$c,string$m):void{global$failures;if(!$c)$failures[]=$m;}
$generator=file_get_contents(dirname(__DIR__).'/src/Learning/ReadingBankGenerator.php');$session=file_get_contents(dirname(__DIR__).'/src/Learning/ReadingSessionService.php');$handler=file_get_contents(dirname(__DIR__).'/admin/generate_learning.php');
check(str_contains($generator,'$offset+=20')&&str_contains($generator,'min(20,'),'Gemini generation batch harus maksimum 20.');
check(str_contains($generator,"'total_gemini'")&&str_contains($generator,"mode==='more'?20"),'Target dan Generate More harus tersedia.');
check(str_contains($session,"l.source='ai'"),'Student selector harus memfilter source database AI.');
check(str_contains($session,'assertGeminiSnapshot')&&str_contains($session,"source']??'')!=='gemini'"),'Fallback tidak boleh masuk session production.');
check(str_contains($handler,"new ReadingBankGenerator")&&str_contains($handler,"reading_mode"),'Teacher handler harus memakai Gemini Reading generator.');
check(!preg_match('/GEMINI_API_KEY|AIza[0-9A-Za-z_-]+/',file_get_contents(dirname(__DIR__).'/student/self_learning.php').file_get_contents(dirname(__DIR__).'/assets/js/self-learning.js')),'Frontend tidak boleh memuat Gemini key.');
if($failures){fwrite(STDERR,"Reading Gemini targeted failed:\n- ".implode("\n- ",$failures)."\n");exit(1);}echo "Reading Gemini targeted OK.\n";
