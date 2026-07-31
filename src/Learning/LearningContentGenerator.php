<?php
declare(strict_types=1);
namespace EnglAI\Learning;
use EnglAI\AI\GeminiProvider;

final class LearningContentGenerator
{
    private const SKILLS=['reading','listening','speaking','writing'];
    public function __construct(private readonly \PDO $pdo){}
    /** @return array{skill:string,level:string,modules:int,activities:int,duplicates:int,source:string} */
    public function generate(int $classroomId,string $skill,string $level): array
    {
        $skill=strtolower(trim($skill));if(!in_array($skill,self::SKILLS,true))throw new \InvalidArgumentException('Skill tidak valid.');$level=Level::validate($level);
        $stmt=$this->pdo->prepare('SELECT * FROM classroom_lesson_plans WHERE classroom_id=? AND is_active=1 ORDER BY version DESC LIMIT 1');$stmt->execute([$classroomId]);$plan=$stmt->fetch();if(!$plan)throw new \RuntimeException('RPP classroom belum tersedia.');
        $source='fallback';try{$items=$this->fromAi($skill,$level,(string)$plan['extracted_text']);$source='ai';}catch(\Throwable $e){$items=$this->fallback($skill,$level,(string)$plan['extracted_text']);app_log('warning','Learning generation fallback used',['classroom_id'=>$classroomId,'skill'=>$skill,'level'=>$level,'reason'=>get_class($e)]);}
        $modules=0;$created=0;$duplicates=0;$this->pdo->beginTransaction();
        try{
            $moduleIds=[];for($i=1;$i<=3;$i++){$title=ucfirst($skill).' Module '.$i.' · '.ucfirst($level);$stmt=$this->pdo->prepare('INSERT INTO learning_modules(classroom_id,lesson_plan_id,skill,level,title,objective,competency,position,source,status) VALUES(?,?,?,?,?,?,?,?,?,\'ready\') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),title=VALUES(title),source=VALUES(source),status=\'ready\'');$stmt->execute([$classroomId,$plan['id'],$skill,$level,$title,"Develop {$skill} competency at {$level} level.",ucfirst($skill).' comprehension and response',$i,$source]);$moduleIds[$i]=(int)$this->pdo->lastInsertId();$modules++;}
            $insert=$this->pdo->prepare('INSERT INTO learning_activities(module_id,classroom_id,lesson_plan_id,skill,level,activity_type,title,instruction,content_json,source_excerpt,competency,source,content_hash,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,\'ready\')');
            foreach($items as $index=>$item){$item=$this->validateItem($skill,$level,$item);$canonical=json_encode($item,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$hash=hash('sha256',$classroomId.'|'.$skill.'|'.$level.'|'.mb_strtolower($item['title'].'|'.$canonical));$type=match($skill){'reading'=>'objective','listening'=>'listening_objective','speaking'=>'speaking_response','writing'=>'writing_response'};try{$insert->execute([$moduleIds[($index%3)+1],$classroomId,$plan['id'],$skill,$level,$type,$item['title'],$item['instruction'],$canonical,$item['source_excerpt'],$item['competency'],$source,$hash]);$created++;}catch(\PDOException $e){if((string)$e->getCode()==='23000'){$duplicates++;continue;}throw $e;}}
            $this->pdo->commit();
        }catch(\Throwable $e){$this->pdo->rollBack();throw $e;}
        return ['skill'=>$skill,'level'=>$level,'modules'=>$modules,'activities'=>$created,'duplicates'=>$duplicates,'source'=>$source];
    }
    /** @return list<array<string,mixed>> */
    private function fromAi(string $skill,string $level,string $text): array
    {
        $key=(string)env_value('GEMINI_API_KEY','');if($key==='')throw new \RuntimeException('Provider unavailable.');$profile=Level::profile($level);
        $prompt="Generate exactly 10 {$skill} self-learning activities at {$level} level from this RPP. Complexity: ".json_encode($profile).". Return JSON array only. Every item requires title,instruction,competency,source_excerpt. Reading/listening require question,options exactly 4,answer A-D,explanation,vocabulary array; reading also passage; listening also script,audio object language/rate/pitch/max_replays. Speaking requires scenario,prompt,example_response,keywords array,min_words,rubric array. Writing requires prompt,context,min_words,max_words,rubric array,example_answer. RPP:\n".mb_substr($text,0,22000);
        $data=(new GeminiProvider($key,(string)env_value('GEMINI_MODEL','gemini-2.5-flash'),(int)env_value('GEMINI_TIMEOUT_SECONDS','45')))->generate($prompt);$items=array_is_list($data)?$data:($data['activities']??[]);if(!is_array($items)||count($items)<10)throw new \RuntimeException('AI activity batch invalid.');return array_slice($items,0,10);
    }
    /** @return list<array<string,mixed>> */
    private function fallback(string $skill,string $level,string $text): array
    {
        $profile=Level::profile($level);$clean=trim(preg_replace('/\s+/u',' ',strip_tags($text))??$text);$excerpt=mb_substr($clean,0,max(180,(int)$profile['length']*3));preg_match_all('/\b[A-Za-z]{5,}\b/u',$clean,$m);$words=array_values(array_unique(array_map('strtolower',$m[0]??[])));if(count($words)<8)$words=['language','context','reading','meaning','learning','response','example','classroom'];
        $items=[];for($i=0;$i<10;$i++){$key=ucfirst($words[$i%count($words)]);$base=['title'=>ucfirst($skill).' Practice '.($i+1),'instruction'=>$this->instruction($skill,$level),'competency'=>ucfirst($skill).' · contextual response','source_excerpt'=>$excerpt,'level'=>$level];
            if($skill==='reading'){$passage=$this->passage($excerpt,$level,$i);$base+=['passage'=>$passage,'learning_objective'=>"Identify {$profile['thinking']} in a {$level} passage.",'vocabulary'=>array_slice($words,$i%max(1,count($words)-5),5),'question'=>"Which keyword best connects to the lesson passage in Reading Practice ".($i+1)."?",'options'=>[$key,ucfirst($words[($i+1)%count($words)]),ucfirst($words[($i+2)%count($words)]),ucfirst($words[($i+3)%count($words)])],'answer'=>'A','explanation'=>"{$key} is taken from the classroom lesson-plan context."];}
            elseif($skill==='listening'){$script=$this->passage("Listen carefully. ".$excerpt,$level,$i);$base+=['script'=>$script,'transcript'=>$script,'audio'=>['provider'=>'browser_speech_synthesis','language'=>'en-US','rate'=>$level==='basic'?.85:($level==='advanced'?1.05:.95),'pitch'=>1.0,'voice_preference'=>'any English voice','max_replays'=>3],'vocabulary'=>array_slice($words,0,5),'question'=>"Which word is emphasized in Listening Practice ".($i+1)."?",'options'=>[$key,ucfirst($words[($i+1)%count($words)]),ucfirst($words[($i+2)%count($words)]),ucfirst($words[($i+3)%count($words)])],'answer'=>'A','explanation'=>"The generated listening script includes {$key}."];}
            elseif($skill==='speaking'){$base+=['scenario'=>"You are discussing the classroom topic at {$level} level.",'prompt'=>"Explain how {$key} relates to the lesson topic. Give ".($level==='basic'?'two simple sentences':($level==='advanced'?'a clear analytical response':'a short connected response')).".",'example_response'=>"In this lesson, {$key} is important because it supports the main idea and helps learners communicate clearly.",'keywords'=>array_slice($words,$i%max(1,count($words)-3),3),'min_words'=>$level==='basic'?15:($level==='advanced'?60:35),'rubric'=>['response_relevance','task_completion','grammar','vocabulary','completeness','transcription_clarity']];}
            else{$base+=['prompt'=>"Write about {$key} in relation to the classroom lesson topic.",'context'=>$excerpt,'min_words'=>$level==='basic'?40:($level==='advanced'?140:80),'max_words'=>$level==='basic'?90:($level==='advanced'?280:170),'rubric'=>['task_completion','relevance','grammar','vocabulary','organization','coherence','mechanics'],'example_answer'=>"The lesson presents {$key} as part of its central context. A strong response explains the connection, supports it with relevant details, and uses clear organization."];}
            $items[]=$base;
        }return $items;
    }
    private function passage(string $text,string $level,int $index): string{$words=preg_split('/\s+/u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[];$target=(int)Level::profile($level)['length'];if(!$words)return 'English learning develops communication through meaningful context.';$start=($index*13)%count($words);$rotated=array_merge(array_slice($words,$start),array_slice($words,0,$start));return implode(' ',array_slice(array_merge($rotated,$rotated,$rotated),0,$target));}
    private function instruction(string $skill,string $level): string{return match($skill){'reading'=>"Read the {$level} passage and choose the best answer.",'listening'=>"Play the Generated Listening Audio and answer before unlocking the transcript.",'speaking'=>'Record or type a transcript, review it, then request AI Speaking Feedback.','writing'=>"Write a {$level} response within the word-count limit."};}
    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function validateItem(string $skill,string $level,array $item): array
    {
        foreach(['title','instruction','competency','source_excerpt'] as $field)if(!isset($item[$field])||!is_string($item[$field])||trim($item[$field])==='')throw new \RuntimeException('Activity schema invalid.');
        if(in_array($skill,['reading','listening'],true)){foreach(['question','answer','explanation'] as $field)if(!isset($item[$field])||!is_string($item[$field]))throw new \RuntimeException('Objective schema invalid.');if(!isset($item['options'])||!is_array($item['options'])||count($item['options'])!==4||!in_array($item['answer'],['A','B','C','D'],true))throw new \RuntimeException('Objective answer schema invalid.');if($skill==='reading'&&!isset($item['passage']))throw new \RuntimeException('Reading passage missing.');if($skill==='listening'&&(!isset($item['script'],$item['audio'])||!is_array($item['audio'])))throw new \RuntimeException('Listening schema invalid.');}
        if($skill==='speaking'&&(!isset($item['prompt'],$item['keywords'],$item['rubric'])||!is_array($item['keywords'])||!is_array($item['rubric'])))throw new \RuntimeException('Speaking schema invalid.');
        if($skill==='writing'&&(!isset($item['prompt'],$item['rubric'],$item['min_words'],$item['max_words'])||!is_array($item['rubric'])))throw new \RuntimeException('Writing schema invalid.');
        $item['level']=$level;return $item;
    }
}
