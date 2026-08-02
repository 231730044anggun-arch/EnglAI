<?php
declare(strict_types=1);
namespace EnglAI\Learning;
use EnglAI\AI\GeminiProvider;use EnglAI\LessonPlan\RppTextCleaner;

final class ReadingBankGenerator
{
 public function __construct(private readonly \PDO $pdo){}
 public function generate(int $classroomId,string $level,mixed $modeOrCount=10):array
 {
   $level=ReadingSessionService::canonicalLevel($level);$q=$this->pdo->prepare('SELECT * FROM classroom_lesson_plans WHERE classroom_id=? AND is_active=1 ORDER BY version DESC LIMIT 1');$q->execute([$classroomId]);$plan=$q->fetch();if(!$plan)throw new \RuntimeException('RPP classroom belum tersedia.');$key=(string)env_value('GEMINI_API_KEY','');$lastError='';
   $q=$this->pdo->prepare("SELECT * FROM ai_analyses WHERE classroom_id=? AND lesson_plan_id=? AND status='valid' ORDER BY id DESC LIMIT 1");$q->execute([$classroomId,$plan['id']]);$analysis=$q->fetch()?:[];$context=$this->context($analysis,(string)$plan['extracted_text']);$count=$this->geminiCount($classroomId,(int)$plan['id'],$level);
   if (is_numeric($modeOrCount)) {
       $requested = max(10, min(100, (int)$modeOrCount));
   } else {
       $mode = (string)$modeOrCount;
       $requested = $mode==='more' ? 20 : ($mode==='regenerate' ? 100 : max(0, 100-$count));
   }
   $requested=min(100,max(0,$requested));$inserted=0;$rejected=0;$duplicates=0;$batches=0;$model=(string)env_value('GEMINI_MODEL','gemini-2.5-flash');
   $module=$this->pdo->prepare("INSERT INTO learning_modules(classroom_id,lesson_plan_id,skill,level,title,objective,competency,position,source,status) VALUES(?,?,'reading',?,'Gemini Reading Bank','Answer varied RPP-grounded reading questions.','Reading comprehension',1,'ai','ready') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),source='ai',status='ready'");$module->execute([$classroomId,$plan['id'],$level]);$moduleId=(int)$this->pdo->lastInsertId();
   for($offset=0;$offset<$requested;$offset+=20){$want=min(20,$requested-$offset);$batchId='rgb_'.bin2hex(random_bytes(8));$raw=null;
    if($key!==''){for($try=0;$try<2;$try++){try{$raw=$this->fromAi($context,$level,$want,$batchId);break;}catch(\Throwable $e){$lastError=$e->getMessage();app_log('warning','Gemini Reading batch failed',['classroom_id'=>$classroomId,'lesson_plan_id'=>$plan['id'],'level'=>$level,'batch_id'=>$batchId,'attempt'=>$try+1,'type'=>get_class($e)]);}}}
    if(!is_array($raw)){$raw=array_slice($this->fallback($context,$level),$offset,$want);$sourceLabel='local_fallback';}else{$sourceLabel='gemini';}$batches++;$items=$this->normalize($raw,$level,$sourceLabel);$batchSeen=[];
    foreach($items as$item){try{$this->validate($item);if(isset($batchSeen[$item['fingerprint']])){$duplicates++;continue;}$batchSeen[$item['fingerprint']]=true;$item['generation_batch_id']=$batchId;$item['provider']=$model;$item['generated_at']=gmdate('c');$statement=$this->pdo->prepare("INSERT INTO learning_activities(module_id,classroom_id,lesson_plan_id,skill,level,activity_type,title,instruction,content_json,source_excerpt,competency,source,content_hash,status) VALUES(?,?,?,'reading',?,'standalone_question',?,?,?,?,?,'ai',?,'ready')");$statement->execute([$moduleId,$classroomId,$plan['id'],$level,$item['question'],'Read the short context when provided, then select one answer.',json_encode($item,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$context['trace'],'Reading comprehension',$item['fingerprint']]);$inserted++;}catch(\PDOException $e){if((string)$e->getCode()==='23000')$duplicates++;else throw$e;}catch(\Throwable){$rejected++;}}
   }
   $total=$this->geminiCount($classroomId,(int)$plan['id'],$level);
   $q=$this->pdo->prepare("SELECT COUNT(*) FROM learning_activities WHERE classroom_id=? AND lesson_plan_id=? AND skill='reading' AND level=? AND status='ready'");
   $q->execute([$classroomId,$plan['id'],$level]);
   $totalCount=(int)$q->fetchColumn();
   if($requested>0&&$inserted===0&&$totalCount===0)throw new \RuntimeException('Generation failed: '.($lastError!==''?$lastError:'no valid questions were produced.'));return ['skill'=>'reading','level'=>$level,'modules'=>1,'activities'=>$inserted,'questions'=>$inserted,'source'=>$inserted > 0 ? 'local_fallback' : 'gemini','requested'=>$requested,'valid'=>$inserted,'rejected'=>$rejected,'duplicates'=>$duplicates,'batches'=>$batches,'total_gemini'=>$total,'model'=>$model];
 }
 private function geminiCount(int$classroomId,int$planId,string$level):int{$q=$this->pdo->prepare("SELECT COUNT(*) FROM learning_activities WHERE classroom_id=? AND lesson_plan_id=? AND skill='reading' AND level=? AND activity_type='standalone_question' AND source='ai' AND status='ready' AND JSON_UNQUOTE(JSON_EXTRACT(content_json,'$.source'))='gemini'");$q->execute([$classroomId,$planId,$level]);return(int)$q->fetchColumn();}
 private function context(array $a,string $raw):array{$decode=fn(string $k):array=>json_decode((string)($a[$k]??'[]'),true)?:[];$topic=trim((string)($a['topic']??''));if($topic===''||preg_match('/MODUL AJAR|Satuan Pendidikan|Alokasi Waktu|Tahun Penyusunan/i',$topic))$topic='Indonesian wildlife, report text, and conservation';return ['topic'=>$topic,'objectives'=>$decode('learning_objectives_json'),'competencies'=>$decode('competencies_json'),'vocabulary'=>$decode('vocabulary_json'),'grammar'=>$decode('grammar_json'),'skill_focus'=>$decode('skill_focus_json'),'complexity'=>(string)($a['material_complexity']??''),'trace'=>mb_substr(RppTextCleaner::pedagogicalContext($raw),0,900)];}
 private function fromAi(array $context,string $level,int$count,string$batchId):array{$key=(string)env_value('GEMINI_API_KEY','');if($key==='')throw new \RuntimeException('AI unavailable');$prompt='Create exactly '.$count.' unique standalone English Reading questions grounded only in the structured lesson context. Batch nonce: '.$batchId.'. Each item: subtopic, type, optional short_context of 1-3 sentences, question, exactly four option objects {id,text}, correct_option_id, explanation, difficulty, estimated_seconds=20. Vary explicit information, factual detail, main idea, inference, vocabulary in context, reference, sentence meaning, purpose, report-text structure, lesson grammar, comparison, and conclusion. Exactly one option is correct. Do not repeat templates, contexts, or question text. Never use MODUL AJAR, school name, class/phase labels, time allocation, year, filename, headers, or footers as question material. Return JSON {questions:[...]}. Canonical level: '.$level.'. Structured lesson context: '.json_encode($context,JSON_UNESCAPED_UNICODE);$data=(new GeminiProvider($key,(string)env_value('GEMINI_MODEL','gemini-2.5-flash'),(int)env_value('GEMINI_TIMEOUT_SECONDS','45')))->generate($prompt);$items=$data['questions']??[];if(!is_array($items)||count($items)<$count)throw new \RuntimeException('Gemini Reading batch schema invalid.');return array_slice($items,0,$count);}
  private function fallback(array $context,string $level):array
  {
        $subjects = [
            ['Bali Starling', 'white feathers and blue skin around its eyes', 'habitat loss and illegal hunting', 'protected breeding and release programs'],
            ['Cendrawasih', 'bright ornamental feathers used in courtship', 'forest clearing and hunting', 'Papuan forest protection'],
            ['Helmeted Hornbill', 'a solid casque and an important seed-dispersal role', 'illegal trade and deforestation', 'anti-poaching patrols'],
            ['Javan Hawk-Eagle', 'a crest and powerful hunting vision', 'loss of mature mountain forest', 'nest monitoring'],
            ['Maleo', 'eggs buried in warm volcanic sand', 'disturbed nesting grounds', 'community nest protection'],
            ['Green Peafowl', 'long green tail feathers and loud calls', 'shrinking grassland habitat', 'protected savanna management'],
            ['Black-winged Myna', 'white plumage with black wings', 'capture for the bird trade', 'conservation breeding'],
            ['Sangihe Shrike-thrush', 'a distinctive song in dense island forest', 'an extremely limited range', 'island habitat restoration'],
            ['Flores Hawk-Eagle', 'broad wings suited to forest hunting', 'fragmented highland forest', 'forest corridor protection'],
            ['Yellow-crested Cockatoo', 'a yellow crest and strong curved beak', 'nest loss and trapping', 'nest-box monitoring'],
            ['Mangrove Birds', 'adaptations for tidal forests', 'coastal development', 'mangrove restoration'],
            ['Forest Ecosystems', 'birds that disperse seeds and control insects', 'habitat fragmentation', 'community conservation'],
            ['Javan Rhino', 'a single horn and loose armor-like skin folds', 'extremely small population size and natural disasters', 'strict habitat management in Ujung Kulon'],
            ['Sumatran Tiger', 'dark stripes and powerful predatory habits', 'poaching and human-wildlife conflict', 'anti-poaching patrol groups'],
            ['Komodo Dragon', 'massive size and venomous bite', 'climate change and habitat shrinking', 'national park protection'],
            ['Bornean Orangutan', 'high intelligence and reddish hair', 'palm oil expansion and forest fires', 'rehabilitation and rewilding initiatives'],
            ['Mountain Anoa', 'dwarf buffalo appearance and sharp horns', 'illegal hunting and agriculture expansion', 'patrolling protected forest areas'],
            ['Togean Babirusa', 'curved tusks that pierce their own snout skin', 'habitat degradation and local hunting', 'setting up community reserves'],
            ['Javan Banteng', 'white stocking legs and high endurance', 'forage competition and disease transmission', 'controlling invasive plant species'],
            ['Proboscis Monkey', 'large pendulous noses and webbed feet', 'mangrove logging and riverbank development', 'mangrove replanting projects']
        ];
        $types = ['main_idea', 'explicit_information', 'inference'];
        $itemsByTemplate = [[], [], []];
        foreach ($subjects as $si => $s) {
            [$name, $trait, $threat, $action] = $s;
            $contextText = "{$name} is known for {$trait}. Its survival is threatened by {$threat}, so {$action} is carried out by communities and conservation teams.";
            
            $sets = [
                [
                    "Which statement best expresses the main idea of this information about {$name}?",
                    "{$name} has distinctive features, faces threats, and needs conservation",
                    "{$name} is a common domestic animal",
                    "All forests are free from human pressure",
                    "Wildlife trade supports conservation"
                ],
                [
                    "Which fact is explicitly stated about {$name}?",
                    "It is known for {$trait}",
                    "It lives on every continent",
                    "It has no role in its habitat",
                    "Its population is increasing everywhere"
                ],
                [
                    "What can be inferred from the conservation effort for {$name}?",
                    "Human cooperation is important for its survival",
                    "The species no longer faces any threat",
                    "Habitat conditions do not affect wildlife",
                    "Scientific observation is unnecessary"
                ]
            ];
            
            foreach ($sets as $ti => $set) {
                $itemsByTemplate[$ti][] = [
                    'subtopic' => $name,
                    'type' => $types[$ti],
                    'short_context' => $contextText,
                    'question' => $set[0],
                    'options' => array_map(fn($text) => ['id' => 'temporary', 'text' => $text], array_slice($set, 1)),
                    'correct_option_id' => 'temporary',
                    'explanation' => 'The context supports: ' . $set[1] . '.',
                    'difficulty' => $level
                ];
            }
        }
        $items = array_merge($itemsByTemplate[0], $itemsByTemplate[1], $itemsByTemplate[2]);
        return $items;
  }
  private function normalize(array $items,string $level,string $source):array{foreach($items as&$item){$item['short_context']=trim((string)($item['short_context']??''));$item['question']=trim((string)($item['question']??''));$item['type']=trim((string)($item['type']??'reading_detail'));$item['subtopic']=trim((string)($item['subtopic']??''));$key='rq_'.substr(hash('sha256',$level.'|'.mb_strtolower($item['short_context'].'|'.$item['question'])),0,20);$old=(string)($item['correct_option_id']??'');$correctText=trim((string)($item['correct_answer']??''));$item['id']=$key;$item['level']=$level;$item['source']=$source;$item['estimated_seconds']=20;if(!isset($item['options'])||!is_array($item['options']))continue;foreach($item['options']as$j=>&$option){if(is_string($option))$option=['text'=>$option];$wasCorrect=((string)($option['id']??'')===$old)||($correctText!==''&&hash_equals(mb_strtolower(trim((string)($option['text']??''))),mb_strtolower($correctText)))||($j===0&&$old==='temporary');$option['id']=$key.'_o'.($j+1);$option['text']=trim((string)($option['text']??''));if($wasCorrect)$item['correct_option_id']=$option['id'];}unset($option);$item['fingerprint']=hash('sha256',$level.'|'.ReadingSessionService::normalizeQuestionText($item['short_context'].'|'.$item['question']));}unset($item);return$items;}
 public function validate(array $item):void{$context=trim((string)($item['short_context']??''));$question=trim((string)($item['question']??''));$encoded=$context.' '.$question;if($question===''||preg_match('/MODUL AJAR|Satuan Pendidikan|Alokasi Waktu|Tahun Penyusunan|Which keyword (best )?connects/i',$encoded))throw new \RuntimeException('Standalone Reading guard rejected item.');if($context!==''&&(mb_strlen($context)<35||substr_count($context,'.')>3))throw new \RuntimeException('Short context must contain 1-3 concise sentences.');$options=$item['options']??[];$ids=array_column($options,'id');$texts=array_map(fn($o)=>ReadingSessionService::normalizeQuestionText((string)($o['text']??'')),$options);if(count($options)!==4||count(array_unique($ids))!==4||count(array_unique($texts))!==4||!in_array($item['correct_option_id']??'', $ids,true)||empty($item['explanation']))throw new \RuntimeException('Standalone Reading options invalid.');}
}
