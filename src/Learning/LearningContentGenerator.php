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
        if($skill==='reading')return (new ReadingBankGenerator($this->pdo))->generate($classroomId,$level);
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
        // Skip the RPP document header — only send the meaningful content
        $body=$this->extractRppBody($text);
        $prompt="You are a professional ESL teacher creating activities for Indonesian high school students.\n"
            ."Generate exactly 10 high-quality {$skill} activities at {$level} level BASED ON the lesson content below.\n"
            ."Complexity Profile: ".json_encode($profile)."\n"
            ."IMPORTANT RULES:\n"
            ."- Base questions on SPECIFIC content from the lesson: animals mentioned, character names, places, grammar points, vocabulary.\n"
            ."- For Writing: 'prompt' must ask about a SPECIFIC animal/topic/grammar from the lesson (NOT 'Write about Bahasa'). 'context' must be a short 2-3 sentence summary of the relevant lesson content (NOT the raw RPP text).\n"
            ."- For Listening: 'script' must be a natural 3-5 sentence dialogue or monologue about a specific topic from the lesson (NOT just the RPP header). Include character names from the RPP if available.\n"
            ."- For Reading: 'passage' must be a coherent paragraph about a specific animal or topic from the lesson.\n"
            ."- Questions must reference specific names, facts, places, or vocabulary from the lesson material.\n"
            ."- DO NOT use generic prompts like 'Write about Bahasa' or 'Which keyword best connects to the lesson'.\n"
            ."Return a JSON array only. Every activity must contain: title, instruction, competency, source_excerpt.\n"
            ."- Reading/Listening fields: passage or script, transcript (for listening), question, options (4 distinct choices), answer (A/B/C/D), explanation, vocabulary array, audio (for listening: provider='browser_speech_synthesis', language='en-US', rate, pitch, max_replays).\n"
            ."- Speaking fields: scenario, prompt, example_response, keywords array, min_words, rubric array.\n"
            ."- Writing fields: prompt, context (2-3 sentence lesson summary), min_words, max_words, rubric array, example_answer.\n"
            ."LESSON CONTENT:\n".$body;
        $data=(new GeminiProvider($key,(string)env_value('GEMINI_MODEL','gemini-2.5-flash'),(int)env_value('GEMINI_TIMEOUT_SECONDS','45')))->generate($prompt);$items=array_is_list($data)?$data:($data['activities']??[]);if(!is_array($items)||count($items)<10)throw new \RuntimeException('AI activity batch invalid.');return array_slice($items,0,10);
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
    private function fallback(string $skill,string $level,string $text): array
    {
        $profile=Level::profile($level);
        // Extract meaningful body, skip header
        $body=$this->extractRppBody($text);
        $clean=trim(preg_replace('/\s+/u',' ',strip_tags($body))??$body);
        $excerpt=mb_substr($clean,0,max(180,(int)$profile['length']*3));
        // Extract topic from the RPP (Chapter/Topik line)
        $topic=$this->extractTopicLabel($text);
        preg_match_all('/\b[A-Za-z]{5,}\b/u',$clean,$m);
        $words=array_values(array_unique(array_map('strtolower',$m[0]??[])));
        if(count($words)<8)$words=['bekantan','cendrawasih','hornbill','habitat','endemic','protection','species','indonesia'];
        // Topic-specific writing/speaking prompts
        $writingPrompts=[
            "Write a short report about a specific animal mentioned in the lesson. Include its habitat, physical description, and one threat it faces.",
            "Describe what passive voice is and write three example sentences using passive voice about animals from the lesson.",
            "Explain why endemic animals like those in the lesson need protection. Give at least two specific reasons.",
            "Compare two animals from the lesson. Describe their similarities and differences in terms of habitat, appearance, and behavior.",
            "Write a paragraph about {$topic}. Include specific facts about its habitat, diet, and conservation status.",
            "Describe what you learned about Indonesian endemic animals in this lesson. Which animal is most interesting to you and why?",
            "Rewrite these sentences in passive voice: (1) Hunters hunt the Helmeted Hornbill for its casque. (2) Scientists study Cendrawasih in Papua.",
            "Write a fact file about one animal from the lesson: name, classification, habitat, diet, and threats.",
            "Explain what \"critically endangered\" means and give one example from the lesson material.",
            "Write a short conservation message about protecting Indonesian endemic birds. Use at least two animals from the lesson.",
        ];
        $speakingPrompts=[
            "Describe the Bekantan (proboscis monkey): where does it live, what does it look like, and why is it endangered?",
            "Explain what passive voice is and give one example using an animal from the lesson.",
            "Why is it important to protect endemic animals like those in {$topic}? Give two reasons.",
            "Imagine you are a wildlife photographer in Kalimantan. Describe what you see and what animals you observe.",
            "Tell your classmate one interesting fact about Indonesian birds from the lesson.",
            "Explain the difference between a report text and a descriptive text using an example from the lesson.",
            "Describe the physical appearance of one animal from the lesson in as much detail as you can.",
            "What threats do endemic animals in Indonesia face? Name at least two and explain their impact.",
            "Retell the story of one animal from the lesson as if you observed it in the wild.",
            "Why should Indonesian students care about protecting endemic animals? Share your opinion.",
        ];
        $items=[];for($i=0;$i<10;$i++){
            $key=ucfirst($words[$i%count($words)]);
            $base=['title'=>ucfirst($skill).' Practice '.($i+1),'instruction'=>$this->instruction($skill,$level),'competency'=>ucfirst($skill).' · contextual response','source_excerpt'=>$excerpt,'level'=>$level];
            if($skill==='reading'){
                $passage=$this->passage($excerpt,$level,$i);
                $answerVal=$key;
                $options=[$answerVal,ucfirst($words[($i+1)%count($words)]),ucfirst($words[($i+2)%count($words)]),ucfirst($words[($i+3)%count($words)])];
                if(count(array_unique($options))<4)$options=[$answerVal,'Different concept','Unrelated idea','Opposite statement'];
                shuffle($options);
                $correctIndex=array_search($answerVal,$options,true);
                $ans=chr(65+$correctIndex);
                $base+=['passage'=>$passage,'learning_objective'=>"Identify {$profile['thinking']} in a {$level} passage.",'vocabulary'=>array_slice($words,$i%max(1,count($words)-5),5),'question'=>"Based on the passage, what is mentioned about {$key}?",'options'=>$options,'answer'=>$ans,'explanation'=>"The correct answer relates to how {$key} appears in the lesson passage."];
            }
            elseif($skill==='listening'){
                // Build a natural-sounding script from lesson content
                $scripts=[
                    "The Bekantan, also known as the proboscis monkey, is found in the mangrove forests of Kalimantan. It has a very large nose and is only found in Borneo. Unfortunately, its habitat is being destroyed by deforestation.",
                    "The Cendrawasih, or Bird of Paradise, is one of Indonesia's most beautiful birds. It lives in the rainforests of Papua. The male bird has bright, colourful feathers that are used to attract females.",
                    "The Helmeted Hornbill is a critically endangered bird found in Kalimantan. It is hunted for its casque, which is used to make carvings. Conservation programmes are being conducted to protect this species.",
                    "The Bali Starling is the national bird of Bali. It is known for its white feathers and blue eye-ring. Poaching for the illegal songbird trade is considered a major threat to its survival.",
                    "In this lesson, we learn about passive voice. For example: 'The Bekantan is found in Kalimantan.' This sentence emphasises the subject, not the action.",
                    "Galang and his friends went birdwatching in the forest. Galang brought his binoculars, while Monita took notes in her notebook. They saw a Cendrawasih high up in the trees.",
                    "Andre asked: 'What bird has a casque on its beak?' Pipit answered: 'That's the Helmeted Hornbill!' It is one of the rarest birds in Kalimantan.",
                    "Indonesia is home to thousands of endemic species found nowhere else in the world. These animals, like the Cendrawasih and Jalak Bali, are part of Indonesia's rich natural heritage.",
                    "A report text presents factual information about a subject in general. For example, a report about Cendrawasih would include its classification, habitat, diet, and threats.",
                    "Scientists study endangered animals to understand how to protect them. The Helmeted Hornbill is studied by ornithologists who want to preserve its habitat in the forests of Kalimantan.",
                ];
                $script=$scripts[$i%count($scripts)];
                $questions=[
                    'Where does the Bekantan live?',
                    'What makes the Cendrawasih special?',
                    'Why is the Helmeted Hornbill endangered?',
                    'What is the main threat to the Bali Starling?',
                    'Which sentence uses passive voice?',
                    'What did Galang bring on the birdwatching trip?',
                    'What bird does Pipit identify?',
                    'What does \"endemic\" mean?',
                    'What is the purpose of a report text?',
                    'What do ornithologists do?',
                ];
                $optionSets=[
                    ['Kalimantan mangrove forests','The highlands of Java','The beaches of Bali','The rice fields of Sulawesi'],
                    ['Bright colourful feathers','Its large nose','Its casque','Its white feathers'],
                    ['Hunted for its casque','Habitat loss due to tourism','Pollution in rivers','Overfishing'],
                    ['Poaching for illegal trade','Habitat destruction','Climate change','Predators'],
                    ['The Bekantan is found in Kalimantan.','Galang finds the Bekantan.','Scientists study the forest.','Birds live in Indonesia.'],
                    ['Binoculars','A camera','A notebook','A map'],
                    ['The Helmeted Hornbill','The Cendrawasih','The Bali Starling','The Bekantan'],
                    ['Only found in one region','Found all over the world','A type of habitat','A conservation method'],
                    ['Present factual information generally','Tell a personal story','Describe one specific object','Give instructions'],
                    ['Study animals to protect them','Hunt endangered species','Teach English in schools','Manage national parks'],
                ];
                $correctAnswers=['A','B','C','D','A','A','A','A','A','A'];
                $q=$questions[$i%count($questions)];
                $opts=$optionSets[$i%count($optionSets)];
                $ans=$correctAnswers[$i%count($correctAnswers)];
                $base+=['script'=>$script,'transcript'=>$script,'audio'=>['provider'=>'browser_speech_synthesis','language'=>'en-US','rate'=>$level==='basic'?.85:($level==='advanced'?1.05:.95),'pitch'=>1.0,'voice_preference'=>'Google US English','max_replays'=>3],'vocabulary'=>array_slice($words,0,5),'question'=>$q,'options'=>$opts,'answer'=>$ans,'explanation'=>'Listen to the audio carefully to find the answer.'];
            }
            elseif($skill==='speaking'){
                $prompt=$speakingPrompts[$i%count($speakingPrompts)];
                $base+=['scenario'=>"Discuss the topic with your classmates.",'prompt'=>$prompt,'example_response'=>"Based on the lesson, I learned that endemic animals like the Cendrawasih and Helmeted Hornbill are very important. They need protection because their habitats are being destroyed.",'keywords'=>array_slice($words,$i%max(1,count($words)-3),3),'min_words'=>$level==='basic'?15:($level==='advanced'?60:35),'rubric'=>['response_relevance','task_completion','grammar','vocabulary','completeness','transcription_clarity']];
            }
            else{
                $wPrompt=$writingPrompts[$i%count($writingPrompts)];
                $base+=['prompt'=>$wPrompt,'context'=>"This lesson is about {$topic}. You have learned about Indonesian endemic animals such as Cendrawasih, Helmeted Hornbill, and Bali Starling, including their habitats, physical features, and threats.",'min_words'=>$level==='basic'?40:($level==='advanced'?140:80),'max_words'=>$level==='basic'?90:($level==='advanced'?280:170),'rubric'=>['task_completion','relevance','grammar','vocabulary','organization','coherence','mechanics'],'example_answer'=>"The Cendrawasih is a beautiful endemic bird found in Papua, Indonesia. It is known for its colourful feathers, which are used by the male to attract females. Unfortunately, Cendrawasih is threatened by habitat destruction and illegal hunting. Therefore, conservation efforts are very important to protect this species."];
            }
            $items[]=$base;
        }return $items;
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
