<?php
declare(strict_types=1);
namespace EnglAI\Quiz;

use EnglAI\Learning\Level;

final class LiveQuizBankGenerator
{
    public const SKILLS=['reading','listening','speaking','writing'];
    public function __construct(private readonly \PDO $pdo){}

    public function generateAll(int $classroomId,string $level,int $target=30): array
    { $out=[]; foreach(self::SKILLS as $skill)$out[$skill]=$this->generate($classroomId,$skill,$level,$target); return $out; }

    public function generate(int $classroomId,string $skill,string $level,int $target=30): array
    {
        if(!in_array($skill,self::SKILLS,true))throw new \InvalidArgumentException('Skill tidak valid.');
        $level=Level::validate($level);$target=max(10,min(60,$target));$plan=$this->plan($classroomId);
        $existing=$this->count($classroomId,$skill,$level);$created=0;
        $excerpt=trim(mb_substr((string)$plan['extracted_text'],0,600));
        $topic=trim(mb_substr(preg_replace('/\s+/u',' ',strip_tags($excerpt))?:'',0,90))?:'the classroom lesson';
        $insert=$this->pdo->prepare("INSERT IGNORE INTO live_quiz_items
          (classroom_id,lesson_plan_id,skill,level,question_type,title,prompt,content_json,answer_key,rubric_json,source_excerpt,content_hash,provider_source,status)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'ready')");
        for($i=$existing;$i<$target;$i++){
            $item=$this->item($skill,$level,$i+1,$topic);
            $hash=hash('sha256',implode('|',['live_quiz_v3',$classroomId,$skill,$level,$i+1,mb_strtolower($item['prompt'])]));
            $insert->execute([$classroomId,(int)$plan['id'],$skill,$level,$item['type'],$item['title'],$item['prompt'],
                json_encode($item['content'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$item['answer'],
                json_encode($item['rubric'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                $excerpt?:'Classroom lesson plan',$hash,'fallback']);
            $created+=$insert->rowCount();
        }
        return ['created'=>$created,'total'=>$this->count($classroomId,$skill,$level),'source'=>'fallback'];
    }
    public function count(int $classroomId,string $skill,string $level): int
    { $q=$this->pdo->prepare("SELECT COUNT(*) FROM live_quiz_items WHERE classroom_id=? AND skill=? AND level=? AND status='ready'");$q->execute([$classroomId,$skill,$level]);return (int)$q->fetchColumn(); }
    private function plan(int $id): array
    { $q=$this->pdo->prepare('SELECT * FROM classroom_lesson_plans WHERE classroom_id=? AND is_active=1 ORDER BY version DESC,id DESC LIMIT 1');$q->execute([$id]);$row=$q->fetch();if(!$row)throw new \RuntimeException('Upload RPP classroom sebelum membuat Live Quiz Content Bank.');return $row; }
    private function item(string $skill,string $level,int $n,string $topic): array
    {
        $common=['skill'=>$skill,'level'=>$level,'competency'=>'RPP-aligned competency','sequence'=>$n];
        if($skill==='reading')return ['type'=>'objective','title'=>"Reading Challenge {$n}",'prompt'=>"What is the best summary of passage {$n}?",'answer'=>'A','rubric'=>[],
            'content'=>$common+['passage'=>"Live challenge passage {$n}: {$topic}.",'question'=>"What is the best summary of passage {$n}?",'options'=>["The passage focuses on {$topic}.",'It describes an unrelated holiday.','It gives no information.','It is only a list of numbers.'],'answer'=>'A','explanation'=>'Option A matches the RPP-based passage.']];
        if($skill==='listening')return ['type'=>'listening_objective','title'=>"Listening Challenge {$n}",'prompt'=>'What is the main focus of the generated audio?','answer'=>'A','rubric'=>[],
            'content'=>$common+['script'=>"Listening challenge {$n}. The lesson focuses on {$topic}. Listen for the main idea.",'language'=>'en-US','rate'=>$level==='basic'?.85:($level==='advanced'?1.05:.95),'pitch'=>1,'max_replays'=>2,'duration_estimate'=>12,'question'=>'What is the main focus of the generated audio?','options'=>[$topic,'A sports result','A shopping list','A weather warning'],'answer'=>'A','explanation'=>'The generated audio explicitly states the focus.']];
        if($skill==='speaking')return ['type'=>'speaking_response','title'=>"Speaking Challenge {$n}",'prompt'=>"Explain one important idea about {$topic}.",'answer'=>null,
            'rubric'=>['relevance','task_completion','grammar','vocabulary','completeness','clarity_based_on_transcription'],
            'content'=>$common+['scenario'=>'Explain the lesson to a classmate.','instruction'=>'AI Speaking Feedback evaluates transcription, not pronunciation.','prompt'=>"Explain one important idea about {$topic}.",'keywords'=>array_slice(array_values(array_filter(preg_split('/\W+/u',mb_strtolower($topic))?:[],fn($w)=>mb_strlen($w)>4)),0,5),'minimum_words'=>$level==='basic'?8:15,'response_duration'=>$level==='advanced'?90:60]];
        return ['type'=>'writing_response','title'=>"Writing Challenge {$n}",'prompt'=>"Write a focused response about {$topic}.",'answer'=>null,
            'rubric'=>['task_completion','relevance','grammar','vocabulary','organization','coherence','mechanics'],
            'content'=>$common+['context'=>'Use evidence from the classroom lesson.','instruction'=>'Write within the word limit.','prompt'=>"Write a focused response about {$topic}.",'minimum_words'=>$level==='basic'?20:($level==='advanced'?80:45),'maximum_words'=>$level==='basic'?80:($level==='advanced'?220:150)]];
    }
}
