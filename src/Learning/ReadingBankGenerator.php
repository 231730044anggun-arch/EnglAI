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
       $requested = $mode==='more'?20 : ($mode==='regenerate' ? 100 : max(0, 100-$count));
   }
   $requested=min(100,max(0,$requested));$inserted=0;$rejected=0;$duplicates=0;$batches=0;$model=(string)env_value('GEMINI_MODEL','gemini-3.5-flash');
    if (isset($mode) && $mode === 'regenerate') {
        $archiveStmt = $this->pdo->prepare("UPDATE learning_activities SET status='archived' WHERE classroom_id=? AND skill='reading' AND level=? AND status='ready'");
        $archiveStmt->execute([$classroomId, $level]);
    }
    $module=$this->pdo->prepare("INSERT INTO learning_modules(classroom_id,lesson_plan_id,skill,level,title,objective,competency,position,source,status) VALUES(?,?,'reading',?,'Gemini Reading Bank','Answer varied RPP-grounded reading questions.','Reading comprehension',1,'ai','ready') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),source='ai',status='ready'");$module->execute([$classroomId,$plan['id'],$level]);$moduleId=(int)$this->pdo->lastInsertId();
   for($offset=0;$offset<$requested;$offset+=20){$want=min(20,$requested-$offset);$batchId='rgb_'.bin2hex(random_bytes(8));$raw=null;
     if($key!==''){
         for($try=0;$try<3;$try++){
             try{
                 $raw=$this->fromAi($context,$level,$want,$batchId);
                 break;
             }catch(\Throwable $e){
                 $lastError=$e->getMessage();
                 app_log('warning','Gemini Reading batch failed',['classroom_id'=>$classroomId,'lesson_plan_id'=>$plan['id'],'level'=>$level,'batch_id'=>$batchId,'attempt'=>$try+1,'type'=>get_class($e)]);
                 if($try<2){
                     $sleepTime = str_contains($e->getMessage(), '429') ? 8 : 4;
                     sleep($sleepTime);
                 }
             }
         }
     }
     if(!is_array($raw)){
         app_log('warning', 'Gemini Reading AI failed, using dynamic local fallback', ['classroom_id'=>$classroomId,'reason'=>$lastError]);
         $raw=array_slice($this->fallback($context,$level),$offset,$want);
         $sourceLabel='local_fallback';
     }else{$sourceLabel='gemini';}$batches++;$items=$this->normalize($raw,$level,$sourceLabel);$batchSeen=[];
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
 private function fromAi(array $context,string $level,int$count,string$batchId):array{$key=(string)env_value('GEMINI_API_KEY','');if($key==='')throw new \RuntimeException('AI unavailable');$prompt='Create exactly '.$count.' unique standalone English Reading questions grounded only in the structured lesson context. Batch nonce: '.$batchId.'. Each item: subtopic, type, optional short_context of 1-3 sentences, question, exactly four option objects {id,text}, correct_option_id, explanation, difficulty, estimated_seconds=20. Vary explicit information, factual detail, main idea, inference, vocabulary in context, reference, sentence meaning, purpose, report-text structure, lesson grammar, comparison, and conclusion. Exactly one option is correct. Do not repeat templates, contexts, or question text. Never use MODUL AJAR, school name, class/phase labels, time allocation, year, filename, headers, or footers as question material. Return JSON {questions:[...]}. Canonical level: '.$level.'. Structured lesson context: '.json_encode($context,JSON_UNESCAPED_UNICODE);$data=(new GeminiProvider($key,(string)env_value('GEMINI_MODEL','gemini-3.5-flash'),(int)env_value('GEMINI_TIMEOUT_SECONDS','45')))->generate($prompt);$items=$data['questions']??[];if(!is_array($items)||count($items)<$count)throw new \RuntimeException('Gemini Reading batch schema invalid.');return array_slice($items,0,$count);}
  private function fallback(array $context,string $level):array
  {
        $text = $context['trace'] ?? '';
        if (trim($text) === '') {
            $text = "English learning develops student communication through context and active practice.";
        }
        
        $adminKeywords = [
            'perform', 'role play', 'activity', 'review', 'lesson', 'teacher', 'student', 'rpp', 'modul', 'alokasi', 'waktu', 
            'tujuan', 'pembelajaran', 'pertemuan', 'menit', 'kelompok', 'siswa', 'guru', 'assessment', 'tugas', 'chapter', 
            'unit', 'page', 'halaman', 'lkpd', 'rubrik', 'nilai', 'score', 'kelas', 'phase', 'fase', 'kurikulum', 'merdeka',
            'profil', 'pancasila', 'sarana', 'prasarana', 'media', 'sumber', 'belajar', 'metode', 'pendekatan', 'model',
            'langkah', 'kegiatan', 'pendahuluan', 'inti', 'penutup', 'refleksi', 'lampiran', 'glosarium', 'daftar', 'pustaka',
            'review activity', 'instruksi', 'pertanyaan', 'jawaban'
        ];
        
        $sentences = preg_split('/(?<=[.?!])\s+/u', $text) ?: [];
        $sentences = array_map('trim', $sentences);
        $sentences = array_filter($sentences, function($s) use ($adminKeywords) {
            if (mb_strlen($s) < 40 || mb_strlen($s) > 220) return false;
            $lower = mb_strtolower($s);
            foreach ($adminKeywords as $word) {
                if (mb_strpos($lower, $word) !== false) return false;
            }
            return true;
        });
        $sentences = array_values(array_unique($sentences));
        
        $topic = $context['topic'] ?? 'Lesson Content';
        $templates = [
            "A long time ago, a beautiful girl lived in a small village with her family.",
            "She had to work hard every day while her sisters did nothing.",
            "The king decided to invite all the young ladies in the land to a grand celebration.",
            "A kind fairy appeared and gave her a wonderful dress and glass slippers.",
            "She danced with the prince all night and forgot about the time.",
            "When the clock struck midnight, she ran away as fast as possible.",
            "She accidentally dropped one of her glass slippers on the palace steps.",
            "The prince traveled to every house to find the owner of the slipper.",
            "At last, the slipper fit her perfectly and they lived happily ever after.",
            "A brave hunter went deep into the dark forest to find the lost treasure.",
            "He encountered many challenges but never gave up on his journey.",
            "The friendly creatures of the forest helped him overcome the obstacles.",
            "He returned to the castle with the ancient artifact and saved the kingdom.",
            "The villagers celebrated his victory with a grand feast and music.",
            "Learning to read stories about {$topic} helps us understand different histories.",
            "A good narrative has a clear beginning, middle, and ending structure.",
            "Characters make decisions that determine the resolution of the conflict.",
            "The setting provides important context about where and when events happen.",
            "Moral values in stories teach us lessons about kindness and courage.",
            "Many ancient tales were passed down through generations of storytellers.",
            "The main conflict in a story creates suspense and engages the reader.",
            "A happy ending is common in classic fairy tales and children stories."
        ];
        
        foreach ($templates as $tpl) {
            $sentences[] = $tpl;
        }
        $sentences = array_values(array_unique($sentences));
        
        $tplIdx = 1;
        while (count($sentences) < 60) {
            $sentences[] = "In this part of the lesson, we focus on story element number {$tplIdx} related to {$topic}.";
            $sentences = array_values(array_unique($sentences));
            $tplIdx++;
        }
        
        $items = [];
        $types = ['main_idea', 'explicit_information', 'inference'];
        
        for ($i = 0; $i < 60; $i++) {
            $sentence = $sentences[$i];
            $type = $types[$i % 3];
            
            preg_match_all('/\b[A-Za-z]{5,15}\b/u', $sentence, $m);
            $words = array_values(array_unique(array_map('strtolower', $m[0] ?? [])));
            $words = array_filter($words, fn($w) => !in_array($w, ['would', 'could', 'should', 'about', 'there', 'their', 'which', 'story', 'lesson'], true));
            $words = array_values($words);
            
            if (count($words) >= 4) {
                $correct = ucfirst($words[0]);
                $options = [$correct, ucfirst($words[1]), ucfirst($words[2]), ucfirst($words[3])];
                $options = array_values(array_unique($options));
                if (count($options) < 4) {
                    $options = [$correct, "Unrelated concept " . $i, "Different idea " . $i, "Opposite statement " . $i];
                }
                $question = "Which key term is featured in sentence number " . ($i + 1) . ": \"{$sentence}\"?";
            } else {
                $correct = $sentence;
                $other1 = $sentences[($i + 1) % count($sentences)];
                $other2 = $sentences[($i + 2) % count($sentences)];
                $other3 = $sentences[($i + 3) % count($sentences)];
                $options = [$correct, $other1, $other2, $other3];
                $options = array_values(array_unique($options));
                if (count($options) < 4) {
                    $options = [$correct, "Generic learning sentence " . $i, "Alternative statement " . $i, "Different option " . $i];
                }
                $question = "Which statement is explicitly mentioned in passage number " . ($i + 1) . "?";
            }
            
            $formattedOptions = [];
            foreach ($options as $optText) {
                $formattedOptions[] = ['id' => 'temporary', 'text' => $optText];
            }
            
            $items[] = [
                'subtopic' => $topic,
                'type' => $type,
                'short_context' => $sentence,
                'question' => $question,
                'options' => $formattedOptions,
                'correct_option_id' => 'temporary',
                'explanation' => "The context supports: \"{$correct}\".",
                'difficulty' => $level
            ];
        }
        return $items;
  }
  private function normalize(array $items,string $level,string $source):array{
      foreach($items as&$item){
          $item['short_context']=trim((string)($item['short_context']??''));
          $item['question']=trim((string)($item['question']??''));
          $item['type']=trim((string)($item['type']??'reading_detail'));
          $item['subtopic']=trim((string)($item['subtopic']??''));
          $key='rq_'.substr(hash('sha256',$level.'|'.mb_strtolower($item['short_context'].'|'.$item['question'])),0,20);
          
          $old=(string)($item['correct_option_id']??'');
          $correctText=trim((string)($item['correct_answer']??''));
          
          $options = $item['options'] ?? [];
          if (!is_array($options)) {
              $options = [];
          }
          foreach ($options as $idx => $opt) {
              if (is_string($opt)) {
                  $options[$idx] = ['id' => 'o' . $idx, 'text' => $opt];
              }
          }
          
          $correctOptionIndex = -1;
          if ($old === 'temporary') {
              $correctOptionIndex = 0;
          } else {
              foreach ($options as $idx => $opt) {
                  $optId = (string)($opt['id'] ?? '');
                  $optText = trim((string)($opt['text'] ?? ''));
                  if (($optId !== '' && $optId === $old) || ($correctText !== '' && hash_equals(mb_strtolower($optText), mb_strtolower($correctText)))) {
                      $correctOptionIndex = $idx;
                      break;
                  }
              }
          }
          
          $targetCorrectText = '';
          if ($correctOptionIndex >= 0 && isset($options[$correctOptionIndex])) {
              $targetCorrectText = trim((string)($options[$correctOptionIndex]['text'] ?? ''));
          }
          
          shuffle($options);
          
          $item['id']=$key;
          $item['level']=$level;
          $item['source']=$source;
          $item['estimated_seconds']=20;
          $item['options'] = [];
          
          foreach($options as $j=>$option){
              $newOptId = $key.'_o'.($j+1);
              $optionText = trim((string)($option['text'] ?? ''));
              
              $wasCorrect = false;
              if ($targetCorrectText !== '') {
                  $wasCorrect = hash_equals(mb_strtolower($optionText), mb_strtolower($targetCorrectText));
              }
              
              $item['options'][] = [
                  'id' => $newOptId,
                  'text' => $optionText
              ];
              
              if($wasCorrect) {
                  $item['correct_option_id'] = $newOptId;
              }
          }
          
          $item['fingerprint']=hash('sha256',$level.'|'.ReadingSessionService::normalizeQuestionText($item['short_context'].'|'.$item['question']));
      }
      unset($item);
      return$items;
  }
 public function validate(array $item):void{$context=trim((string)($item['short_context']??''));$question=trim((string)($item['question']??''));$encoded=$context.' '.$question;if($question===''||preg_match('/MODUL AJAR|Satuan Pendidikan|Alokasi Waktu|Tahun Penyusunan|Which keyword (best )?connects/i',$encoded))throw new \RuntimeException('Standalone Reading guard rejected item.');if($context!==''&&(mb_strlen($context)<35||substr_count($context,'.')>3))throw new \RuntimeException('Short context must contain 1-3 concise sentences.');$options=$item['options']??[];$ids=array_column($options,'id');$texts=array_map(fn($o)=>ReadingSessionService::normalizeQuestionText((string)($o['text']??'')),$options);if(count($options)!==4||count(array_unique($ids))!==4||count(array_unique($texts))!==4||!in_array($item['correct_option_id']??'', $ids,true)||empty($item['explanation']))throw new \RuntimeException('Standalone Reading options invalid.');}
}
