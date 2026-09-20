<?php
declare(strict_types=1);
namespace EnglAI\Learning;

final class SpeakingSessionService
{
    public const TASKS=10;
    public function __construct(private readonly \PDO $pdo){}
    public function active(int $member,int $classroom,string $level): ?array {
        $q=$this->pdo->prepare("SELECT id FROM classroom_lesson_plans WHERE classroom_id=? AND is_active=1 ORDER BY version DESC LIMIT 1");$q->execute([$classroom]);$plan=(int)$q->fetchColumn();if(!$plan)return null;
        $q=$this->pdo->prepare("SELECT * FROM speaking_sessions WHERE member_id=? AND classroom_id=? AND lesson_plan_id=? AND level=? AND status='active' ORDER BY id DESC LIMIT 1");$q->execute([$member,$classroom,$plan,Level::validate($level)]);return $q->fetch()?:null;
    }
    public function start(int $member,int $classroom,string $level,bool $new=false): array {
        $level=Level::validate($level);if(!$new&&($active=$this->active($member,$classroom,$level)))return $active;
        $q=$this->pdo->prepare("SELECT id FROM classroom_lesson_plans WHERE classroom_id=? AND is_active=1 ORDER BY version DESC LIMIT 1");$q->execute([$classroom]);$plan=(int)$q->fetchColumn();if(!$plan)throw new \RuntimeException('Active lesson plan tidak tersedia.');
        $q=$this->pdo->prepare("SELECT id,title,instruction,content_json FROM learning_activities WHERE classroom_id=? AND lesson_plan_id=? AND skill='speaking' AND level=? AND status='ready' ORDER BY id");$q->execute([$classroom,$plan,$level]);$pool=$q->fetchAll();
        $unique=[];foreach($pool as $item){$c=json_decode((string)$item['content_json'],true)?:[];$prompt=trim((string)($c['prompt']??''));$key=mb_strtolower(preg_replace('/\s+/u',' ',$prompt));if($prompt!==''&&!isset($unique[$key]))$unique[$key]=$item;}
        if(count($unique)<self::TASKS)throw new \RuntimeException('Speaking content sedang dipersiapkan.');
        $items=array_values($unique);$h=$this->pdo->prepare("SELECT task_id,MAX(created_at) used FROM speaking_recordings WHERE member_id=? AND classroom_id=? GROUP BY task_id");$h->execute([$member,$classroom]);$used=array_column($h->fetchAll(),'used','task_id');foreach($items as &$item)$item['_tie']=random_int(1,PHP_INT_MAX);unset($item);usort($items,fn($a,$b)=>strcmp((string)($used[$a['id']]??''),(string)($used[$b['id']]??''))?:($a['_tie']<=>$b['_tie']));$items=array_slice($items,0,self::TASKS);shuffle($items);
        $basicInstructions=[
            'Listen to the native model audio and shadow (repeat) the sentence aloud with clear intonation.',
            'Practice the shadowing technique: listen carefully to the native pronunciation, then repeat with good rhythm.',
            'Listen to the model voice and shadow the sentence as if speaking naturally in real-life conversation.',
            'Pay attention to word stress in the audio, then shadow with clear and confident articulation.',
            'Listen to the native speaker and shadow along with a steady, natural pace.',
            'Shadow the following sentence while focusing on accurate pronunciation and rhythm.'
        ];
        $intermediateInstructions=[
            'Respond to the following question in English. Use the provided keywords if helpful.',
            'Explain your answer in English using the suggested vocabulary words.',
            'Provide a clear and concise spoken response in English based on the prompt.',
            'Summarize your ideas and answer the question in English within the time limit.',
            'Compare the ideas in the prompt and explain your perspective in English.'
        ];
        $advancedInstructions=[
            'Deliver your complete spoken response in English within the 25-second time limit.',
            'Explain your perspective and reasons clearly in spoken English.',
            'Provide actionable recommendations and practical solutions in spoken English.',
            'Analyze the causes and effects in English with natural fluency.',
            'Present a well-structured response addressing the challenge described in the prompt.'
        ];
        $hasIndo=static fn(string $s):bool=>(bool)preg_match('/\b(adalah|yang|dan|di|dari|pada|untuk|dengan|sebagai|serta|atau|dalam|ini|itu|ke|oleh|karena|tidak|bisa|dapat|membantu|mempelajari|pembelajaran|menganalisis|membandingkan|penggunaan|kemampuan|kebanggaan|membuat|menggunakan|pengetahuan|menumbuhkan|persamaan|perbedaan|satwa|endemik)\b/iu',$s);
        $snapshot=[];$seenTargets=[];foreach($items as $i=>$item){$content=json_decode((string)$item['content_json'],true)?:[];$original=trim((string)($content['example_response']??$content['prompt']??''));if($hasIndo($original))$original='';$sentences=preg_split('/(?<=[.!?])\s+/u',$original,-1,PREG_SPLIT_NO_EMPTY)?:[];$sentences=array_values(array_filter($sentences,fn($s)=>!$hasIndo($s)));$keywords=array_values(array_filter(array_map('strval',$content['keywords']??[])));$keywordText=$keywords?implode(', ',array_slice($keywords,0,3)):'the lesson topic';$candidates=[trim((string)($sentences[0]??$original)),trim((string)($sentences[1]??$sentences[0]??$original)),trim(implode(' ',array_slice($sentences,0,2))),"This lesson discusses {$keywordText}.","The words {$keywordText} are important in this lesson.","Student: I can explain {$keywordText} in English."];$candidates=array_values(array_filter($candidates,fn($c)=>trim($c)!==''));$target=$candidates[$i%count($candidates)];$key=mb_strtolower(preg_replace('/\s+/u',' ',$target)??$target);if(isset($seenTargets[$key])){$focus=$keywords[$i%max(1,count($keywords))]??'this topic';$target=rtrim($target,' .').". {$focus} is a key idea in this lesson.";$key=mb_strtolower(preg_replace('/\s+/u',' ',$target)??$target);}$seenTargets[$key]=true;$words=preg_split('/\s+/u',$target,-1,PREG_SPLIT_NO_EMPTY)?:[];if(count($words)>28)$target=implode(' ',array_slice($words,0,28)).'.';$type=$level==='basic'?'read_aloud':($level==='intermediate'?'guided_response':'spontaneous_response');$rawPrompt=(string)($content['prompt']??'');if($hasIndo($rawPrompt)){$rawPrompt=match($level){'intermediate'=>"Explain how {$keywordText} can be described using appropriate descriptive words.",'advanced'=>"Discuss the key significance and characteristics of {$keywordText} in modern context.",default=>$target};}$instruction=$level==='basic'?$basicInstructions[$i%count($basicInstructions)]:($level==='intermediate'?$intermediateInstructions[$i%count($intermediateInstructions)]:$advancedInstructions[$i%count($advancedInstructions)]);$snapshot[]=['position'=>$i,'id'=>(int)$item['id'],'title'=>$item['title'],'instruction'=>$instruction,'type'=>$type,'prompt'=>$level==='basic'?$target:$rawPrompt,'target_text'=>$level==='basic'?$target:'','guidance'=>$level==='intermediate'?implode(', ',array_slice($keywords,0,4)):'','sentence_starter'=>$level==='intermediate'?(string)($content['sentence_starter']??''):'','rubric'=>$content['rubric']??[]];}
        $this->pdo->prepare("INSERT INTO speaking_sessions(classroom_id,member_id,lesson_plan_id,level,task_snapshot_json,started_at) VALUES(?,?,?,?,?,NOW())")->execute([$classroom,$member,$plan,$level,json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$id=(int)$this->pdo->lastInsertId();return $this->get($id,$member,$classroom);
    }
    public function get(int $id,int $member,int $classroom): array {$q=$this->pdo->prepare('SELECT * FROM speaking_sessions WHERE id=? AND member_id=? AND classroom_id=?');$q->execute([$id,$member,$classroom]);$s=$q->fetch();if(!$s)throw new \RuntimeException('Speaking session tidak ditemukan.');return $s;}
    public function state(array $s): array
    {
        $tasks=json_decode((string)$s['task_snapshot_json'],true)?:[];
        $q=$this->pdo->prepare('SELECT * FROM speaking_recordings WHERE session_id=? ORDER BY id');$q->execute([$s['id']]);$records=$q->fetchAll();
        $clock=$this->pdo->prepare('SELECT UNIX_TIMESTAMP(task_started_at)*1000 started_ms,UNIX_TIMESTAMP(task_deadline_at)*1000 deadline_ms FROM speaking_sessions WHERE id=?');$clock->execute([$s['id']]);$epoch=$clock->fetch()?:[];
        $pos=(int)$s['current_position'];$deadlineMs=isset($epoch['deadline_ms'])?(int)$epoch['deadline_ms']:null;
        return ['id'=>(int)$s['id'],'level'=>$s['level'],'status'=>$s['status'],'position'=>$pos,'total'=>self::TASKS,'task'=>$s['status']==='active'?($tasks[$pos]??null):null,'task_started_epoch_ms'=>isset($epoch['started_ms'])?(int)$epoch['started_ms']:null,'task_deadline_epoch_ms'=>$deadlineMs,'deadline_at'=>$deadlineMs?intdiv($deadlineMs,1000):null,'remaining'=>$deadlineMs?max(0,min(25,(int)ceil(($deadlineMs-(int)round(microtime(true)*1000))/1000))):null,'attempt_used'=>$s['recording_started_at']!==null,'recordings'=>$s['status']==='completed'?$records:[],'tasks'=>$s['status']==='completed'?$tasks:[],'average_score'=>$records?round(array_sum(array_map(fn($r)=>(float)($r['score']??0),$records))/count($records),1):0];
    }
}
