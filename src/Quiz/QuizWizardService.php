<?php
declare(strict_types=1);
namespace EnglAI\Quiz;

use EnglAI\Learning\Level;
use EnglAI\Analytics\AuditService;

final class QuizWizardService
{
    private const TIMERS=['reading'=>30,'listening'=>40,'speaking'=>75,'writing'=>180];
    public function __construct(private readonly \PDO $pdo){}
    public function create(int $classroomId,string $teacher,array $input): int
    {
        $mode=(string)($input['quiz_mode']??'single_skill');
        if(!in_array($mode,['single_skill','mixed_skills','final_challenge'],true))throw new \InvalidArgumentException('Quiz mode tidak valid.');
        $level=Level::validate((string)($input['level']??'intermediate'));$count=max(1,min(60,(int)($input['question_count']??10)));
        $skills=$this->skills($mode,$input);$distribution=$this->distribution($mode,$skills,$count,$input['distribution']??null);
        $timers=$this->timers($input['timers']??[]);$this->available($classroomId,$level,$distribution);
        $items=[];foreach($distribution as $skill=>$amount){$q=$this->pdo->prepare("SELECT * FROM live_quiz_items WHERE classroom_id=? AND skill=? AND level=? AND status='ready' ORDER BY RAND() LIMIT {$amount}");$q->execute([$classroomId,$skill,$level]);$items=array_merge($items,$q->fetchAll());}
        if(($input['question_order']??'mixed')==='mixed')shuffle($items);
        $title=trim((string)($input['title']??''))?:($mode==='final_challenge'?'EnglAI Final Challenge':ucwords(str_replace('_',' ',$mode)).' Quiz');
        $difficulty=['basic'=>'easy','intermediate'=>'medium','advanced'=>'hard'][$level];
        $estimate=0;foreach($distribution as $skill=>$amount)$estimate+=$timers[$skill]*$amount;
        $this->pdo->beginTransaction();
        try{
            $s=$this->pdo->prepare("INSERT INTO quiz_sessions (classroom_id,quiz_mode,title,selected_skills_json,skill_distribution_json,level,timer_config_json,state,question_count,difficulty,estimated_duration_seconds,review_enabled,configuration_json,created_by) VALUES (?,?,?,?,?,?,?,'LOBBY',?,?,?,?,?,?)");
            $s->execute([$classroomId,$mode,mb_substr($title,0,200),json_encode($skills),json_encode($distribution),$level,json_encode($timers),$count,$difficulty,$estimate,!empty($input['review_enabled'])?1:0,json_encode(['version'=>3,'server_authoritative'=>true]),$teacher]);
            $id=(int)$this->pdo->lastInsertId();
            $snap=$this->pdo->prepare('INSERT INTO quiz_session_questions (quiz_session_id,position,source_question_id,question,options_json,answer,explanation,difficulty,skill,question_type,content_json,max_score,timer_seconds,source_item_id,source_excerpt,provider_source) VALUES (?,?,NULL,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach($items as $position=>$item){$c=json_decode((string)$item['content_json'],true,512,JSON_THROW_ON_ERROR);$snap->execute([$id,$position,$item['prompt'],json_encode($c['options']??[]),$item['answer_key']?:'A',$c['explanation']??'',$difficulty,$item['skill'],$item['question_type'],$item['content_json'],1000,$timers[$item['skill']],$item['id'],$item['source_excerpt'],$item['provider_source']]);}
            $this->pdo->commit();(new AuditService($this->pdo))->record($classroomId,$teacher,'live_quiz.created','quiz_session',$id,[],['mode'=>$mode,'level'=>$level,'distribution'=>$distribution]);return $id;
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    private function skills(string $mode,array $input): array
    {
        if($mode==='final_challenge')return LiveQuizBankGenerator::SKILLS;
        $skills=$input['skills']??[$input['skill']??'reading'];$skills=is_array($skills)?array_values(array_unique(array_intersect(LiveQuizBankGenerator::SKILLS,$skills))):[];
        if($mode==='single_skill'&&count($skills)!==1)throw new \InvalidArgumentException('Single Skill membutuhkan tepat satu skill.');
        if($mode==='mixed_skills'&&count($skills)<2)throw new \InvalidArgumentException('Mixed Skills membutuhkan minimal dua skill.');
        return $skills;
    }
    private function distribution(string $mode,array $skills,int $count,mixed $manual): array
    {
        if($mode==='final_challenge'){
            $weights=['reading'=>.3,'listening'=>.2,'speaking'=>.2,'writing'=>.3];$r=[];$used=0;
            foreach($weights as $s=>$w){$r[$s]=max(1,(int)floor($count*$w));$used+=$r[$s];}
            while($used<$count){$r[$used%2?'writing':'reading']++;$used++;}
            while($used>$count){foreach(['writing','reading','speaking','listening'] as $s)if($used>$count&&$r[$s]>1){$r[$s]--;$used--;}}
            return $r;
        }
        if(is_array($manual)&&$manual!==[]){$r=[];foreach($skills as $s)$r[$s]=max(0,(int)($manual[$s]??0));if(array_sum($r)!==$count||min($r)<1)throw new \InvalidArgumentException('Distribusi harus sama dengan jumlah soal dan minimal satu per skill.');return $r;}
        $base=intdiv($count,count($skills));$rem=$count%count($skills);$r=[];foreach($skills as $i=>$s)$r[$s]=$base+($i<$rem?1:0);return $r;
    }
    private function timers(mixed $input): array
    { $r=self::TIMERS;if(is_array($input))foreach($r as $s=>$v){$b=$s==='writing'?[60,600]:($s==='speaking'?[30,180]:[15,120]);if(isset($input[$s]))$r[$s]=max($b[0],min($b[1],(int)$input[$s]));}return $r; }
    private function available(int $classroomId,string $level,array $distribution): void
    { $q=$this->pdo->prepare("SELECT COUNT(*) FROM live_quiz_items WHERE classroom_id=? AND skill=? AND level=? AND status='ready'");foreach($distribution as $s=>$n){$q->execute([$classroomId,$s,$level]);if((int)$q->fetchColumn()<$n)throw new \RuntimeException("Bank {$s} {$level} belum cukup. Generate Live Quiz Content.");} }
}
