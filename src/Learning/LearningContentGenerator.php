<?php
declare(strict_types=1);
namespace EnglAI\Learning;
use EnglAI\AI\GeminiProvider;

final class LearningContentGenerator
{
    private const SKILLS=['reading','listening','speaking','writing'];
    public function __construct(private readonly \PDO $pdo){}
    /** @return array{skill:string,level:string,modules:int,activities:int,duplicates:int,source:string} */
    public function generate(int $classroomId,string $skill,string $level,int $count=10): array
    {
        $skill=strtolower(trim($skill));if(!in_array($skill,self::SKILLS,true))throw new \InvalidArgumentException('Skill tidak valid.');$level=Level::validate($level);
        $stmt=$this->pdo->prepare('SELECT * FROM classroom_lesson_plans WHERE classroom_id=? AND is_active=1 ORDER BY version DESC LIMIT 1');$stmt->execute([$classroomId]);$plan=$stmt->fetch();if(!$plan)throw new \RuntimeException('RPP classroom belum tersedia.');
        $source='fallback';
        $key=(string)env_value('GEMINI_API_KEY','');
        if($key!==''){
            try {
                $items=$this->fromAi($skill,$level,(string)$plan['extracted_text'],$count);
                $source='ai';
            } catch (\Throwable $e) {
                app_log('warning','Gemini AI failed, using dynamic local fallback',['classroom_id'=>$classroomId,'skill'=>$skill,'level'=>$level,'reason'=>$e->getMessage()]);
                $items=$this->fallback($skill,$level,(string)$plan['extracted_text'],$count);
                $source='fallback';
            }
        }else{
            $items=$this->fallback($skill,$level,(string)$plan['extracted_text'],$count);
        }
        $modules=0;$created=0;$duplicates=0;$this->pdo->beginTransaction();
        try{
            $archiveStmt = $this->pdo->prepare("UPDATE learning_activities SET status='archived' WHERE classroom_id=? AND skill=? AND level=? AND status='ready'");
            $archiveStmt->execute([$classroomId, $skill, $level]);
            $archiveMod = $this->pdo->prepare("UPDATE learning_modules SET status='archived' WHERE classroom_id=? AND skill=? AND level=? AND status='ready'");
            $archiveMod->execute([$classroomId, $skill, $level]);
            
            $moduleIds=[];for($i=1;$i<=3;$i++){$title=ucfirst($skill).' Module '.$i.' · '.ucfirst($level);$stmt=$this->pdo->prepare('INSERT INTO learning_modules(classroom_id,lesson_plan_id,skill,level,title,objective,competency,position,source,status) VALUES(?,?,?,?,?,?,?,?,?,\'ready\') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),title=VALUES(title),source=VALUES(source),status=\'ready\'');$stmt->execute([$classroomId,$plan['id'],$skill,$level,$title,"Develop {$skill} competency at {$level} level.",ucfirst($skill).' comprehension and response',$i,$source]);$moduleIds[$i]=(int)$this->pdo->lastInsertId();$modules++;}
            $insert=$this->pdo->prepare('INSERT INTO learning_activities(module_id,classroom_id,lesson_plan_id,skill,level,activity_type,title,instruction,content_json,source_excerpt,competency,source,content_hash,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,\'ready\')');
            foreach($items as $index=>$item){$item=$this->validateItem($skill,$level,$item);$canonical=json_encode($item,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$hash=hash('sha256',$classroomId.'|'.$skill.'|'.$level.'|'.mb_strtolower($item['title'].'|'.$canonical));$type=match($skill){'reading'=>'objective','listening'=>'listening_objective','speaking'=>'speaking_response','writing'=>'writing_response'};try{$insert->execute([$moduleIds[($index%3)+1],$classroomId,$plan['id'],$skill,$level,$type,$item['title'],$item['instruction'],$canonical,$item['source_excerpt'],$item['competency'],$source,$hash]);$created++;}catch(\PDOException $e){if((string)$e->getCode()==='23000'){$duplicates++;continue;}throw $e;}}
            $this->pdo->commit();
        }catch(\Throwable $e){$this->pdo->rollBack();throw $e;}
        return ['skill'=>$skill,'level'=>$level,'modules'=>$modules,'activities'=>$created,'duplicates'=>$duplicates,'source'=>$source];
    }    /** @return list<array<string,mixed>> */    private function fromAi(string $skill,string $level,string $text,int $count): array
    {
        $key=(string)env_value('GEMINI_API_KEY','');if($key==='')throw new \RuntimeException('Provider unavailable.');$profile=Level::profile($level);
        $body=$this->extractRppBody($text);
        
        $batchSize = 5;
        $items = [];
        $provider = new GeminiProvider($key, (string)env_value('GEMINI_MODEL', 'gemini-3.5-flash'), (int)env_value('GEMINI_TIMEOUT_SECONDS', '45'));
        
        for ($i = 0; $i < $count; $i += $batchSize) {
            $currentWant = min($batchSize, $count - $i);
            $prompt="You are a professional ESL teacher creating activities for Indonesian high school students.\n"
                ."Generate exactly {$currentWant} high-quality {$skill} activities at {$level} level BASED ON the lesson content below.\n"
                ."Complexity Profile: ".json_encode($profile)."\n"
                ."IMPORTANT RULES:\n"
                ."- Base questions on SPECIFIC content from the lesson: animals mentioned, character names, places, grammar points, vocabulary.\n"
                ."- For Writing: 'prompt' MUST be framed either as a **5W + 1H question series** (Who, What, Where, When, Why, How) or a **story-based / narrative scenario** (soal cerita) based on the lesson content. Do NOT make it a generic dry prompt.\n"
                ."- For Speaking: 'prompt' MUST be a specific English sentence of 8-15 words based on the lesson content for the student to read aloud. Do NOT make it a question. The instruction must always be 'Read the following sentence aloud with clear pronunciation.'. 'example_response' must be the exact same sentence.\n"
                ."- For Listening: 'script' must be a natural 3-5 sentence dialogue or monologue about a specific topic from the lesson.\n"
                ."- For Reading: 'passage' must be a coherent paragraph about a specific animal or topic from the lesson.\n"
                ."- Questions must reference specific names, facts, places, or vocabulary from the lesson material.\n"
                ."- DO NOT use generic prompts like 'Write about Bahasa' or 'Which keyword best connects to the lesson'.\n"
                ."Return a JSON array only. Every activity must contain: title, instruction, competency, source_excerpt.\n"
                ."- Reading/Listening fields: passage or script, transcript (for listening), question, options (4 distinct choices), answer (A/B/C/D), explanation, vocabulary array, audio (for listening: provider='browser_speech_synthesis', language='en-US', rate, pitch, max_replays).\n"
                ."- Speaking fields: scenario, prompt, example_response, keywords array, min_words, rubric array.\n"
                ."- Writing fields: prompt, context (2-3 sentence lesson summary), min_words, max_words, rubric array, example_answer.\n"
                ."LESSON CONTENT:\n".$body;

            $lastError = null;
            $batchItems = null;
            for ($attempt = 0; $attempt < 3; $attempt++) {
                try {
                    $data = $provider->generate($prompt);
                    $batchItems = array_is_list($data) ? $data : ($data['activities'] ?? []);
                    if (is_array($batchItems) && count($batchItems) >= $currentWant) {
                        break;
                    }
                    $lastError = new \RuntimeException('AI activity batch invalid.');
                } catch (\Throwable $e) {
                    $lastError = $e;
                    if ($attempt < 2) {
                        $sleepTime = str_contains($e->getMessage(), '429') ? 8 : (3 * ($attempt + 1));
                        sleep($sleepTime);
                    }
                }
            }
            if (!is_array($batchItems) || count($batchItems) < $currentWant) {
                throw new \RuntimeException('AI generation failed for batch starting at ' . $i, 0, $lastError);
            }
            $items = array_merge($items, array_slice($batchItems, 0, $currentWant));
            if ($i + $batchSize < $count) {
                sleep(2);
            }
        }
        return $items;
    }
    /** Skip RPP document header and return only the meaningful lesson body. */
    private function extractRppBody(string $text): string
    {
        // Skip header up to the first section marker
        $body=preg_replace('/^.*?(?:A\.\s*KONTEKS|KONTEKS SOSIAL|TUJUAN PEMBELAJARAN|PEMAHAMAN BERMAKNA)/uis','',$text);
        if($body!==null&&mb_strlen(trim($body))>100){
            return trim(mb_substr(preg_replace('/\s+/u',' ',$body)??$body,0,18000));
        }
        // Fallback: skip first 800 chars (usually all header)
        return trim(mb_substr(preg_replace('/\s+/u',' ',$text)??$text,800,18000));
    }
    /** @return list<array<string,mixed>> */
    private function fallback(string $skill,string $level,string $text,int $count): array
    {
        $profile=Level::profile($level);
        $body=$this->extractRppBody($text);
        $clean=trim(preg_replace('/\s+/u',' ',strip_tags($body))??$body);
        $excerpt=mb_substr($clean,0,max(180,(int)$profile['length']*3));
        $topic=$this->extractTopicLabel($text);
        
        preg_match_all('/\b[A-Za-z]{5,}\b/u',$clean,$m);
        $words=array_values(array_unique(array_map('strtolower',$m[0]??[])));
        $words=array_filter($words, fn($w) => !in_array($w, ['would', 'could', 'should', 'about', 'there', 'their', 'which'], true));
        $words=array_values($words);
        if(count($words)<8)$words=['learning','context','english','vocabulary','grammar','comprehension','lesson','practice'];

        $adminKeywords = [
            'perform', 'role play', 'activity', 'review', 'lesson', 'teacher', 'student', 'rpp', 'modul', 'alokasi', 'waktu', 
            'tujuan', 'pembelajaran', 'pertemuan', 'menit', 'kelompok', 'siswa', 'guru', 'assessment', 'tugas', 'chapter', 
            'unit', 'page', 'halaman', 'lkpd', 'rubrik', 'nilai', 'score', 'kelas', 'phase', 'fase', 'kurikulum', 'merdeka',
            'profil', 'pancasila', 'sarana', 'prasarana', 'media', 'sumber', 'belajar', 'metode', 'pendekatan', 'model',
            'langkah', 'kegiatan', 'pendahuluan', 'inti', 'penutup', 'refleksi', 'lampiran', 'glosarium', 'daftar', 'pustaka',
            'review activity', 'instruksi', 'pertanyaan', 'jawaban'
        ];
        
        $sentences = preg_split('/(?<=[.?!])\s+/u', $clean) ?: [];
        $sentences = array_map('trim', $sentences);
        $sentences = array_filter($sentences, function($s) use ($adminKeywords) {
            if (mb_strlen($s) < 40 || mb_strlen($s) > 180) return false;
            $lower = mb_strtolower($s);
            foreach ($adminKeywords as $word) {
                if (mb_strpos($lower, $word) !== false) return false;
            }
            return true;
        });
        $sentences = array_values(array_unique($sentences));
        
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

        $items=[];
        for($i=0;$i<$count;$i++){
            $key=ucfirst($words[$i%count($words)]);
            $base=['title'=>ucfirst($skill).' Practice '.($i+1),'instruction'=>$this->instruction($skill,$level),'competency'=>ucfirst($skill).' · contextual response','source_excerpt'=>$excerpt,'level'=>$level];
            
            if($skill==='reading'){
                $passage=$this->passage($excerpt,$level,$i);
                $answerVal=$key;
                $options=[$answerVal,ucfirst($words[($i+1)%count($words)]),ucfirst($words[($i+2)%count($words)]),ucfirst($words[($i+3)%count($words)])];
                $options=array_values(array_unique($options));
                if(count($options)<4)$options=[$answerVal,'Different concept','Unrelated idea','Opposite statement'];
                shuffle($options);
                $correctIndex=array_search($answerVal,$options,true);
                $ans=chr(65+$correctIndex);
                $base+=['passage'=>$passage,'learning_objective'=>"Identify {$profile['thinking']} in a {$level} passage.",'vocabulary'=>array_slice($words,$i%max(1,count($words)-5),5),'question'=>"Based on the passage, what is mentioned about {$key}?",'options'=>$options,'answer'=>$ans,'explanation'=>"The correct answer relates to how {$key} appears in the lesson passage."];
            }
            elseif($skill==='listening'){
                $idx1 = $i % count($sentences);
                $idx2 = ($i + 1) % count($sentences);
                $idx3 = ($i + 2) % count($sentences);
                
                if ($level === 'basic') {
                    $script = $sentences[$idx1];
                    $question = "Which key information is explicitly stated in this sentence?";
                } elseif ($level === 'intermediate') {
                    $script = $sentences[$idx1] . " " . $sentences[$idx2];
                    $question = "What main situation or event is described in this passage?";
                } else {
                    $script = $sentences[$idx1] . " " . $sentences[$idx2] . " " . $sentences[$idx3];
                    $question = "What is the logical conclusion based on the details in this passage?";
                }
                
                preg_match_all('/\b[A-Za-z]{5,15}\b/u', $script, $sm);
                $scriptWords = array_values(array_unique(array_map('strtolower', $sm[0] ?? [])));
                $scriptWords = array_filter($scriptWords, fn($w) => !in_array($w, ['would', 'could', 'should', 'about', 'there', 'their', 'which'], true));
                $scriptWords = array_values($scriptWords);
                
                if (count($scriptWords) >= 4) {
                    $correct = ucfirst($scriptWords[0]);
                    $opts = [$correct, ucfirst($scriptWords[1]), ucfirst($scriptWords[2]), ucfirst($scriptWords[3])];
                    $opts = array_values(array_unique($opts));
                    if (count($opts) < 4) {
                        $opts = [$correct, "Alternative detail " . $i, "Opposite statement " . $i, "Different fact " . $i];
                    }
                } else {
                    $correct = "The description of characters and events in the text.";
                    $opts = [
                        $correct,
                        "A discussion about general mathematics rules " . $i,
                        "Instructions on how to cook a meal " . $i,
                        "A lecture on geography and maps " . $i
                    ];
                }
                
                shuffle($opts);
                $correctIndex = array_search($correct, $opts, true);
                $ans = chr(65 + $correctIndex);
                
                $base += [
                    'script' => $script,
                    'transcript' => $script,
                    'audio' => [
                        'provider' => 'browser_speech_synthesis',
                        'language' => 'en-US',
                        'rate' => $level === 'basic' ? 0.8 : ($level === 'advanced' ? 1.05 : 0.92),
                        'pitch' => 1.0,
                        'voice_preference' => 'Google US English',
                        'max_replays' => $level === 'basic' ? 4 : ($level === 'advanced' ? 2 : 3)
                    ],
                    'vocabulary' => array_slice($words, 0, 5),
                    'question' => $question,
                    'options' => $opts,
                    'answer' => $ans,
                    'explanation' => "The audio states: \"{$script}\"."
                ];
            }
            elseif($skill==='speaking'){
                if ($level === 'basic') {
                    $shortSentences = array_filter($sentences, fn($s) => mb_strlen($s) < 80);
                    $shortSentences = array_values($shortSentences) ?: $sentences;
                    $prompt = $shortSentences[$i % count($shortSentences)];
                    $scenario = "Read the short sentence from the lesson text aloud with clear pronunciation.";
                    $minWords = 8;
                } elseif ($level === 'intermediate') {
                    $medSentences = array_filter($sentences, fn($s) => mb_strlen($s) >= 80 && mb_strlen($s) < 130);
                    $medSentences = array_values($medSentences) ?: $sentences;
                    $prompt = $medSentences[$i % count($medSentences)];
                    $scenario = "Read the complex sentence from the lesson text aloud, focusing on natural stress and intonation.";
                    $minWords = 15;
                } else {
                    $longSentences = array_filter($sentences, fn($s) => mb_strlen($s) >= 130);
                    $longSentences = array_values($longSentences) ?: $sentences;
                    $prompt = $longSentences[$i % count($longSentences)];
                    $scenario = "Read the detailed passage from the lesson text aloud with proper pacing and natural expression.";
                    $minWords = 25;
                }
                
                $base += [
                    'scenario' => $scenario,
                    'prompt' => $prompt,
                    'example_response' => "Based on the text, we can practice: \"{$prompt}\"",
                    'keywords' => array_slice($words, $i % max(1, count($words) - 3), 3),
                    'min_words' => $minWords,
                    'rubric' => ['response_relevance', 'task_completion', 'grammar', 'vocabulary', 'completeness', 'transcription_clarity']
                ];
            }
            else{
                $sentence = $sentences[$i % count($sentences)];
                if ($level === 'basic') {
                    $prompt = "Write a simple English sentence summarizing the main idea of this sentence: \"{$sentence}\".";
                    $context = "Focus on the characters or objects mentioned in this part of the lesson.";
                    $min = 15;
                    $max = 45;
                    $example = "This part of the lesson discusses how characters act in the story.";
                } elseif ($level === 'intermediate') {
                    $prompt = "Write two sentences describing the complication or problem related to: \"{$sentence}\".";
                    $context = "Explain the conflict and what characters do next.";
                    $min = 40;
                    $max = 90;
                    $example = "In this narrative part, characters encounter an obstacle. They need to find a solution to resolve this conflict.";
                } else {
                    $prompt = "Write a detailed response analyzing the character actions and theme in: \"{$sentence}\". Propose an alternative resolution.";
                    $context = "Analyze the sentence's grammatical structure, moral values, and plot function.";
                    $min = 100;
                    $max = 220;
                    $example = "This sentence plays a key role in the narrative. It highlights the main theme and character motivations. An alternative resolution would involve a different decision that resolves the plot sooner.";
                }
                
                $base += [
                    'prompt' => $prompt,
                    'context' => $context,
                    'min_words' => $min,
                    'max_words' => $max,
                    'rubric' => ['task_completion', 'relevance', 'grammar', 'vocabulary', 'organization', 'coherence', 'mechanics'],
                    'example_answer' => $example
                ];
            }
            $items[]=$base;
        }
        return $items;
    }
    /** Extract a human-readable topic label from the RPP text. */
    private function extractTopicLabel(string $text): string
    {
        if(preg_match('/Chapter\s*\/\s*Topik(?:\s*Chapter\s*\/\s*Topik)?\s+(.{5,200})/ui',$text,$m)){
            $c=trim(preg_replace('/\s+/',' ',$m[1])??'');
            $len=mb_strlen($c);
            for($s=(int)ceil($len/3);$s<=(int)ceil($len*2/3);$s++){$h=mb_substr($c,0,$s);if(mb_strpos($c,$h,1)!==false){$c=trim($h);break;}}
            if(mb_strlen($c)>=5)return mb_substr($c,0,80);
        }
        return 'Indonesian Endemic Animals';
    }
    private function passage(string $text,string $level,int $index): string{$words=preg_split('/\s+/u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[];$target=(int)Level::profile($level)['length'];if(!$words)return 'English learning develops communication through meaningful context.';$start=($index*13)%count($words);$rotated=array_merge(array_slice($words,$start),array_slice($words,0,$start));return implode(' ',array_slice(array_merge($rotated,$rotated,$rotated),0,$target));}
    private function instruction(string $skill,string $level): string{return match($skill){'reading'=>"Read the {$level} passage and choose the best answer.",'listening'=>"Play the Generated Listening Audio and answer before unlocking the transcript.",'speaking'=>'Read the following sentence aloud with clear pronunciation.','writing'=>"Write a {$level} response within the word-count limit."};}
    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function validateItem(string $skill,string $level,array $item): array
    {
        foreach(['title','instruction','competency','source_excerpt'] as $field)if(!isset($item[$field])||!is_string($item[$field])||trim($item[$field])==='')throw new \RuntimeException('Activity schema invalid.');
        
        if (in_array($skill, ['reading', 'listening'], true) && isset($item['options']) && is_array($item['options']) && count($item['options']) === 4 && isset($item['answer'])) {
            $correctText = '';
            $ansIndex = ord($item['answer']) - 65; // A=0, B=1, C=2, D=3
            if (isset($item['options'][$ansIndex])) {
                $correctText = $item['options'][$ansIndex];
            }
            if ($correctText !== '') {
                shuffle($item['options']);
                $newIndex = array_search($correctText, $item['options'], true);
                if ($newIndex !== false) {
                    $item['answer'] = chr(65 + $newIndex);
                }
            }
        }

        if(in_array($skill,['reading','listening'],true)){foreach(['question','answer','explanation'] as $field)if(!isset($item[$field])||!is_string($item[$field]))throw new \RuntimeException('Objective schema invalid.');if(!isset($item['options'])||!is_array($item['options'])||count($item['options'])!==4||!in_array($item['answer'],['A','B','C','D'],true))throw new \RuntimeException('Objective answer schema invalid.');if($skill==='reading'&&!isset($item['passage']))throw new \RuntimeException('Reading passage missing.');if($skill==='listening'&&(!isset($item['script'],$item['audio'])||!is_array($item['audio'])))throw new \RuntimeException('Listening schema invalid.');}
        if($skill==='speaking'&&(!isset($item['prompt'],$item['keywords'],$item['rubric'])||!is_array($item['keywords'])||!is_array($item['rubric'])))throw new \RuntimeException('Speaking schema invalid.');
        if($skill==='writing'&&(!isset($item['prompt'],$item['rubric'],$item['min_words'],$item['max_words'])||!is_array($item['rubric'])))throw new \RuntimeException('Writing schema invalid.');
        $item['level']=$level;return $item;
    }
}
