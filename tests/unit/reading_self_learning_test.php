<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config/koneksi.php';

use EnglAI\Learning\ReadingBankGenerator;
use EnglAI\Learning\ReadingSessionService;
use EnglAI\LessonPlan\RppTextCleaner;

if (!function_exists('check')) {
    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}

$clean = RppTextCleaner::pedagogicalContext("MODUL AJAR\nSatuan Pendidikan: SMP\nAlokasi Waktu: 4 JP\nIndonesian Birds and Conservation\nPassive voice.");
check(!preg_match('/MODUL AJAR|Satuan Pendidikan|Alokasi Waktu/i', $clean), 'Metadata administratif harus dibuang.');
check(ReadingSessionService::canonicalLevel('Basic') === 'basic' && ReadingSessionService::canonicalLevel('beginner') === 'basic' && ReadingSessionService::canonicalLevel('INTERMEDIATE') === 'intermediate', 'Level legacy harus dinormalisasi.');
$generator=new ReadingBankGenerator(db());$fallback=new ReflectionMethod(ReadingBankGenerator::class,'fallback');$normalize=new ReflectionMethod(ReadingBankGenerator::class,'normalize');$items=$fallback->invoke($generator,['topic'=>'Indonesian Birds and Conservation','objectives'=>[],'competencies'=>[],'vocabulary'=>[],'grammar'=>['passive voice'],'trace'=>$clean],'basic');$items=$normalize->invoke($generator,$items,'basic','local_fallback');check(count($items)===60,'Fallback per level harus 60 standalone questions.');$ids=[];$texts=[];foreach($items as$item){try{$generator->validate($item);}catch(Throwable$e){check(false,'Fallback invalid: '.$e->getMessage());}$ids[]=$item['id'];$texts[]=ReadingSessionService::normalizeQuestionText($item['question']);}check(count(array_unique($ids))===60&&count(array_unique($texts))===60,'Bank harus memiliki 60 ID dan teks unik.');try{ReadingSessionService::assertSnapshot(['questions'=>array_slice($items,0,20)]);}catch(Throwable$e){check(false,'Snapshot 20 standalone ditolak.');}$positions=ReadingSessionService::balancedPositions();foreach([0,1,2,3]as$p)check(count(array_filter($positions,fn($v)=>$v===$p))===5,'Distribusi A-D harus 5/5/5/5.');for($i=2;$i<20;$i++)check(!($positions[$i]===$positions[$i-1]&&$positions[$i]===$positions[$i-2]),'Maksimal dua posisi sama berurutan.');
$page=file_get_contents(dirname(__DIR__,2).'/student/self_learning.php');$js=file_get_contents(dirname(__DIR__,2).'/assets/js/self-learning.js');$skill=file_get_contents(dirname(__DIR__,2).'/student/skill.php');$dashboard=file_get_contents(dirname(__DIR__,2).'/student/dashboard.php');check(!preg_match('/Start Questions|View Passage|data-passage/i',$page.$js),'Passage phase harus dihapus.');check(str_contains($page,'Choose your level')&&str_contains($page,'Preparing')&&str_contains($page,'valid_count'),'Level selector harus memakai status database.');check(!preg_match('/data-music-volume|data-music-toggle|reading-volume|reading-muted|localStorage|type=["\']range["\']/i',$page.$js),'Kontrol/preference volume Reading harus dihapus.');check(str_contains($js,'master.gain.value=1')&&str_contains($js,'fetch('),'Audio penuh dan AJAX harus tersedia.');check(str_contains($skill,'/student/activity.php')&&str_contains($dashboard,'/student/skill.php'),'Start/Continue harus menuju activity.php / skill.php.');check(!preg_match('/GEMINI_API_KEY|AIza[0-9A-Za-z_-]+|innerHTML/',$page.$js),'Frontend Reading tidak boleh mengekspos secret/unsafe HTML.');
