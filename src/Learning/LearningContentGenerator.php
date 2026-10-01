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
            $insert=$this->pdo->prepare('INSERT INTO learning_activities(module_id,classroom_id,lesson_plan_id,skill,level,activity_type,title,instruction,content_json,source_excerpt,competency,source,content_hash,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,\'ready\') ON DUPLICATE KEY UPDATE module_id=VALUES(module_id),lesson_plan_id=VALUES(lesson_plan_id),title=VALUES(title),instruction=VALUES(instruction),content_json=VALUES(content_json),source_excerpt=VALUES(source_excerpt),competency=VALUES(competency),source=VALUES(source),status=\'ready\'');
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
            $prompt="You are a professional ESL teacher creating activities for high school students.\n"
                ."Generate exactly {$currentWant} high-quality {$skill} activities at {$level} level BASED ON the lesson content below.\n"
                ."Complexity Profile: ".json_encode($profile)."\n"
                ."CRITICAL LANGUAGE REQUIREMENT:\n"
                ."- ALL output fields (title, instruction, competency, passage, script, transcript, question, options, explanation, scenario, prompt, example_response, context, example_answer) MUST BE 100% IN PROPER ENGLISH ONLY.\n"
                ."- Do NOT output or quote any Indonesian words or phrases anywhere in the JSON, even if the lesson content is in Indonesian. Translate or frame any concept into clear, natural English.\n"
                ."IMPORTANT CONTENT RULES:\n"
                ."- Base activities on English learning concepts: narrative details, characters, settings, fauna, conservation, grammar points, or vocabulary.\n"
                ."- For Writing: 'prompt' MUST be framed in English either as a **5W + 1H question series** (Who, What, Where, When, Why, How) or as a **narrative scenario / contextual writing prompt** based on the lesson themes. Do NOT make it a generic dry prompt.\n"
                ."- For Speaking: 'prompt' MUST be a specific English sentence based on the lesson themes for the student to shadow aloud. Do NOT make it a question. For Basic: 8-12 words. For Intermediate: 12-18 words. For Advanced: 16-24 words. The instruction must always be 'Listen to the native model audio and shadow the sentence aloud with clear pronunciation.'. 'example_response' must be the exact same sentence.\n"
                ."- For Listening: 'script' must be a natural 3-5 sentence English dialogue or monologue about a specific topic from the lesson.\n"
                ."- For Reading: 'passage' must be a coherent English paragraph of appropriate length.\n"
                ."- Questions must reference specific names, facts, places, or vocabulary from the English material.\n"
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
        $topic='Nature, Narrative Stories, and Conservation';
        $excerpt="Tropical rainforests and diverse ecosystems provide shelter to unique wildlife while inspiring traditional narrative stories. English learners develop reading comprehension, oral fluency, and writing skills through meaningful exploration of environmental stewardship.";

        $words=['comprehension', 'conservation', 'environment', 'narrative', 'character', 'resolution', 'community', 'discovery', 'wildlife', 'biodiversity', 'ecosystem', 'adventure', 'tradition', 'knowledge', 'exploration', 'stewardship'];

        $sentences = [
            "A young traveler embarked on a journey through the quiet forest to discover ancient ruins.",
            "The community worked together to construct a clean freshwater irrigation system for the village.",
            "Tropical rainforests shelter more than half of the world's terrestrial plant and animal species.",
            "Endemic wildlife plays a vital role in pollinating trees and dispersing seeds across great distances.",
            "Careful observation and persistent practice help students develop confidence in spoken English.",
            "When faced with unexpected obstacles, the scouts relied on teamwork and clear communication.",
            "Protecting marine ecosystems preserves delicate coral reefs from the effects of warming oceans.",
            "Traditional stories pass down enduring lessons about empathy, courage, and environmental responsibility.",
            "Sustainable agricultural practices help maintain fertile soil while reducing water consumption.",
            "The ancient stone library contained centuries of botanical records and historical manuscripts.",
            "Active listening and clear articulation enhance mutual understanding in cross-cultural dialogues.",
            "Regular physical exercise strengthens cardiac performance and sharpens daily academic focus."
        ];

        $items=[];
        for($i=0;$i<$count;$i++){
            $base=['title'=>ucfirst($skill).' Practice '.($i+1),'instruction'=>$this->instruction($skill,$level),'competency'=>ucfirst($skill).' · contextual response','source_excerpt'=>$excerpt,'level'=>$level];
            
            if($skill==='reading'){
                $readingPassages = [
                    "Rainforest ecosystems support an astonishing variety of flora and fauna. Large canopy trees provide shelter for hornbills and primates, while shaded undergrowth nurtures rare flowering plants. When humans safeguard these forests against illegal logging, they preserve essential biodiversity and help regulate the global climate.",
                    "In traditional folklore, characters often embark on meaningful journeys that test their moral integrity. By confronting difficult decisions, protagonists demonstrate the value of perseverance, honesty, and mutual respect within their communities.",
                    "Mangrove wetlands along coastal waters serve as natural barriers against heavy storms and tidal surges. Their intricate root systems anchor loose sediments, creating safe nursery environments for young fish and migratory shorebirds."
                ];
                $readingQuestions = [
                    ['q' => 'What is the primary role of large canopy trees described in the passage?', 'a' => 'Providing shelter for hornbills, primates, and forest wildlife.', 'd' => ['Clearing space for commercial timber harvesting.', 'Blocking all rainfall from reaching the forest floor.', 'Preventing migratory birds from nesting in the trees.']],
                    ['q' => 'How do folkloric journeys test the moral integrity of story characters?', 'a' => 'By forcing characters to make difficult decisions that demand honesty.', 'd' => ['By rewarding characters with unlimited gold without effort.', 'By eliminating all obstacles and conflicts from the plot.', 'By isolating characters permanently from their communities.']],
                    ['q' => 'How do mangrove root systems protect coastal environments?', 'a' => 'They anchor loose sediments and serve as natural storm barriers.', 'd' => ['They increase water temperatures in commercial harbors.', 'They prevent young fish from swimming into coastal waters.', 'They replace the need for freshwater river conservation.']]
                ];
                $idx = $i % count($readingPassages);
                $qInfo = $readingQuestions[$idx];
                $passage = $readingPassages[$idx];
                $answerVal = $qInfo['a'];
                $options = array_merge([$answerVal], $qInfo['d']);
                shuffle($options);
                $correctIndex = array_search($answerVal, $options, true);
                $ans = chr(65 + $correctIndex);

                $base += [
                    'passage' => $passage,
                    'learning_objective' => "Identify key information in a {$level} English passage.",
                    'vocabulary' => array_slice($words, ($i * 3) % count($words), 4),
                    'question' => $qInfo['q'],
                    'options' => $options,
                    'answer' => $ans,
                    'explanation' => "The passage explicitly supports this detail."
                ];
            }
            elseif($skill==='listening'){
                $idx1 = $i % count($sentences);
                $idx2 = ($i + 1) % count($sentences);
                $script = $sentences[$idx1] . " " . $sentences[$idx2];

                $listeningQuestions = [
                    ['q' => 'What central theme is highlighted in the audio statement?', 'a' => 'The positive impact of cooperation and environmental awareness.', 'd' => ['The rapid construction of modern highway networks.', 'The complete decline of traditional cultural folklore.', 'Methods for mining minerals in mountainous regions.']],
                    ['q' => 'According to the speaker, what enables communities to overcome obstacles?', 'a' => 'Relying on teamwork, patience, and clear communication.', 'd' => ['Working completely alone without consulting others.', 'Ignoring challenges until they disappear naturally.', 'Abandoning established community projects.']],
                    ['q' => 'What ecological benefit is emphasized in the recorded passage?', 'a' => 'Preserving biodiversity and protecting natural forest habitats.', 'd' => ['Expanding urban commercial developments into wetlands.', 'Clearing old-growth trees for temporary agriculture.', 'Restricting the natural migration of wild birds.']],
                    ['q' => 'Why is active listening considered crucial in language learning?', 'a' => 'It develops authentic confidence and natural conversational rhythm.', 'd' => ['It removes the need to practice speaking words aloud.', 'It guarantees passing tests without studying grammar.', 'It replaces reading comprehension and writing practice.']],
                    ['q' => 'How do solar panels contribute to sustainable community development?', 'a' => 'By generating clean energy without producing harmful emissions.', 'd' => ['By increasing reliance on coal-fired power stations.', 'By reducing the need for local forest conservation.', 'By stopping all residential electrical consumption.']],
                    ['q' => 'What role do endemic animals play in sustaining tropical rainforests?', 'a' => 'They disperse native plant seeds across the forest canopy.', 'd' => ['They prevent all tree growth in protected sanctuaries.', 'They accelerate industrial timber extraction in valleys.', 'They force migratory bird species out of natural habitats.']],
                    ['q' => 'How do coastal mangrove trees safeguard local human settlements?', 'a' => 'They absorb severe wave energy and minimize coastal erosion.', 'd' => ['They block commercial fishing boats from leaving harbors.', 'They turn salty ocean water into instant drinking water.', 'They eliminate all natural rainfall over coastal towns.']],
                    ['q' => 'What lesson can be drawn from the traditional story mentioned by the speaker?', 'a' => 'Humility, empathy, and mutual respect foster community strength.', 'd' => ['Wealth and power are more important than moral integrity.', 'Traveling alone is safer than working with neighbors.', 'Ancient traditions should be forgotten immediately.']],
                    ['q' => 'What strategy did the expedition members use when facing a blocked trail?', 'a' => 'They collaborated calmly to solve the problem step by step.', 'd' => ['They argued loudly and walked back to the starting point.', 'They waited for emergency rescue without taking action.', 'They discarded all food supplies and equipment on the path.']],
                    ['q' => 'Why does the speaker recommend daily purposeful vocabulary practice?', 'a' => 'Consistent small efforts lead to long-term language fluency.', 'd' => ['Studying vocabulary eliminates the need to practice grammar.', 'Memorizing word lists replaces listening comprehension.', 'One day of intensive study is enough for total fluency.']],
                    ['q' => 'What was the main purpose of the youth environmental workshop?', 'a' => 'To educate students on waste reduction and habitat conservation.', 'd' => ['To encourage heavy consumer spending on luxury items.', 'To promote the commercial logging of old-growth forests.', 'To replace natural science subjects in the curriculum.']],
                    ['q' => 'According to the announcement, how should students prepare for their project?', 'a' => 'By gathering factual evidence and organizing ideas logically.', 'd' => ['By copying text from unverified internet sources.', 'By delaying project work until the final submission hour.', 'By working in complete isolation without guidance.']]
                ];
                $lq = $listeningQuestions[$i % count($listeningQuestions)];
                $opts = array_merge([$lq['a']], $lq['d']);
                shuffle($opts);
                $correctIndex = array_search($lq['a'], $opts, true);
                $ans = chr(65 + $correctIndex);

                $base += [
                    'script' => $script,
                    'transcript' => $script,
                    'audio' => [
                        'provider' => 'browser_speech_synthesis',
                        'language' => 'en-US',
                        'rate' => $level === 'basic' ? 0.82 : ($level === 'advanced' ? 1.0 : 0.9),
                        'pitch' => 1.0,
                        'voice_preference' => 'Google US English',
                        'max_replays' => $level === 'basic' ? 4 : ($level === 'advanced' ? 2 : 3)
                    ],
                    'vocabulary' => array_slice($words, ($i * 2) % count($words), 4),
                    'question' => $lq['q'],
                    'options' => $opts,
                    'answer' => $ans,
                    'explanation' => "The speaker explicitly emphasizes this point in the audio."
                ];
            }
            elseif($skill==='speaking'){
                $speakingBasic = [
                    "Tropical rainforests provide shelter for hundreds of unique animal species.",
                    "The brave traveler followed the mountain trail until he found clean water.",
                    "Community members cooperated enthusiastically to restore the historic wooden bridge.",
                    "Protecting mangrove forests protects coastal villages from dangerous storm surges.",
                    "Traditional folklore reminds communities about the importance of kindness and respect.",
                    "Endemic wildlife plays an essential role in dispersing seeds across the forest.",
                    "Active listening and regular practice build authentic confidence in spoken English.",
                    "Solar panels generate clean electrical power without releasing hazardous greenhouse gases.",
                    "Careful planning and consistent daily effort allow students to achieve meaningful goals.",
                    "Conserving natural resources safeguards the delicate ecological balance for the future.",
                    "Scientific researchers observe migratory birds to track global environmental patterns.",
                    "Clear pronunciation and steady pacing make public presentations engaging and persuasive."
                ];
                $speakingIntermediate = [
                    "Tropical rainforest ecosystems support an astonishing variety of flora and fauna across diverse habitats.",
                    "When communities collaborate on local conservation initiatives, they protect endangered wildlife and secure freshwater reserves.",
                    "Practicing English shadowing every day helps language learners develop natural rhythm, accurate word stress, and conversational fluency.",
                    "Mangrove wetlands along tropical coastlines serve as critical natural buffers against intense tidal surges and ocean storms.",
                    "Traditional folk narratives preserve profound historical wisdom regarding environmental stewardship, personal integrity, and community cooperation.",
                    "By studying biodiversity in protected national parks, student researchers gain practical insight into ecological balance and wildlife conservation."
                ];
                $speakingAdvanced = [
                    "Conserving fragile tropical ecosystems requires coordinated international policies, persistent community engagement, and scientific research into sustainable resource management.",
                    "Mastering advanced spoken English fluency demands continuous shadowing practice, focused attention to connected speech, and confident articulation in professional dialogues.",
                    "Sustainable agricultural development balances immediate economic productivity with long-term ecological preservation, ensuring fertile soils and pure water tables for future generations.",
                    "Empirical scientific investigations demonstrate that preserving endemic fauna is fundamental to maintaining cross-canopy pollination cycles and overall biodiversity stability."
                ];
                $pool = $level === 'basic' ? $speakingBasic : ($level === 'advanced' ? $speakingAdvanced : $speakingIntermediate);
                $prompt = $pool[$i % count($pool)];
                $scenario = "Listen to the native model audio and shadow the English sentence aloud with clear pronunciation, proper stress, and natural rhythm.";

                $base += [
                    'scenario' => $scenario,
                    'prompt' => $prompt,
                    'example_response' => $prompt,
                    'keywords' => array_slice($words, ($i * 2) % count($words), 3),
                    'min_words' => $level === 'basic' ? 8 : ($level === 'advanced' ? 16 : 12),
                    'rubric' => ['response_relevance', 'task_completion', 'grammar', 'vocabulary', 'completeness', 'transcription_clarity']
                ];
            }
            else{
                $writingScenarios = [
                    [
                        'prompt' => "Imagine your school is organizing an Environmental Awareness Day. Write a paragraph answering: Who will participate? What conservation activities will you do? Where will it take place? Why is protecting wildlife important?",
                        'context' => "Use 5W+1H questions to describe student conservation activities and environmental stewardship.",
                        'example' => "Our school environmental club will host an Awareness Day in the central courtyard next Friday. Students will plant native trees and design posters showing how hornbills disperse seeds. Protecting wildlife is vital because healthy forests preserve clean water and air for our entire community."
                    ],
                    [
                        'prompt' => "Write a descriptive narrative about a group of travelers discovering an ancient hidden spring in the mountains. Who was in the group? What challenge did they overcome? How did they solve it?",
                        'context' => "Write a coherent narrative paragraph detailing the setting, character actions, and successful resolution.",
                        'example' => "During a weekend expedition, three students hiked up the steep ridge to find the legendary mountain spring. When a fallen tree blocked their path, they cooperated to clear the trail safely. Reaching the crystal-clear water before sunset gave the team a profound sense of achievement."
                    ],
                    [
                        'prompt' => "Write an explanatory response discussing how protecting endangered species benefits human communities. What animals are vulnerable? Where do they live? How can students contribute to conservation?",
                        'context' => "Discuss the ecological relationship between wildlife habitats and local community well-being.",
                        'example' => "Endangered species such as the proboscis monkey and hornbill maintain the delicate balance of tropical ecosystems. When their mangrove and rainforest habitats are protected, coastal areas avoid severe erosion. Students can contribute by reducing plastic waste and supporting local conservation education."
                    ],
                    [
                        'prompt' => "Describe a memorable traditional celebration or cultural festival in your hometown. When does it happen? Who takes part in the festivities? What traditional food or performance makes it special?",
                        'context' => "Describe cultural traditions, community participation, and sensory details in clear English.",
                        'example' => "Every August, our town celebrates the harvest festival in the central square. Families gather to share sweet rice cakes and listen to traditional music played on bamboo instruments. The celebration unites neighbors of all ages and preserves our cherished heritage."
                    ],
                    [
                        'prompt' => "Write a persuasive paragraph arguing why renewable energy like solar or wind power should replace coal in modern cities. What are the key benefits? How does clean energy protect public health?",
                        'context' => "Present a structured argument with clear supporting reasons and practical benefits.",
                        'example' => "Modern cities should transition to solar power because it reduces hazardous air pollution and slows climate change. By installing solar panels on public buildings, municipalities cut electricity costs and improve respiratory health. Clean energy investments build a healthier and more resilient future for everyone."
                    ],
                    [
                        'prompt' => "Narrate a story about a student who overcame anxiety before giving an important English speech. How did they prepare? Who gave them encouragement? What did they learn from this experience?",
                        'context' => "Focus on character emotions, gradual growth, and a positive thematic outcome.",
                        'example' => "Maya felt nervous whenever she spoke in front of a crowd. Her English teacher advised her to practice in front of a mirror and breathe deeply before stepping onto the stage. When she finished her presentation to enthusiastic applause, Maya realized that steady preparation conquers self-doubt."
                    ],
                    [
                        'prompt' => "Describe an exciting school science expedition into a local mangrove forest or botanical garden. What did the students observe? What scientific equipment did they use? Why was the field trip valuable?",
                        'context' => "Describe field observations, scientific inquiry, and collaborative student learning.",
                        'example' => "Our biology class visited the coastal mangrove sanctuary last Tuesday morning. Equipped with magnifying glasses and water test kits, we measured salinity and documented juvenile mudskippers swimming among root clusters. Seeing the ecosystem firsthand made textbook concepts come alive."
                    ],
                    [
                        'prompt' => "Write an opinion response on whether schools should establish a mandatory community service program for high school students. What benefits does volunteering offer? How does it build character?",
                        'context' => "Express an opinion clearly supported by civic responsibility and personal development points.",
                        'example' => "Mandatory community service fosters empathy, responsibility, and civic awareness among young learners. Volunteering at local food pantries or animal shelters teaches students practical life skills that cannot be acquired from textbooks alone. It strengthens bonds between schools and surrounding neighborhoods."
                    ],
                    [
                        'prompt' => "Describe how modern digital technology and smartphones can be used responsibly by teenagers to improve their English language skills. What apps or habits are most effective? What pitfalls should they avoid?",
                        'context' => "Offer practical recommendations and balanced insights on digital learning habits.",
                        'example' => "Smartphones offer accessible language learning tools when used with discipline. Students can listen to English podcasts during commutes and use flashcard applications to expand their active vocabulary. However, setting daily time limits prevents endless social media distractions from disrupting focused study sessions."
                    ],
                    [
                        'prompt' => "Write a descriptive paragraph about your favorite natural destination, such as a mountain, beach, or national park. What does the landscape look like? What sounds and sights make it peaceful?",
                        'context' => "Use vivid adjectives and sensory language to create an evocative scene description.",
                        'example' => "Mount Bromo at sunrise presents an awe-inspiring panorama of mist-covered volcanic plains. The cool morning breeze carries the distant rustle of pine needles while golden light illuminates the crater rim. Visiting this quiet sanctuary brings deep mental tranquility and renewed wonder for nature."
                    ],
                    [
                        'prompt' => "Discuss why learning a foreign language enhances intercultural understanding and global career opportunities. How does multilingualism broaden one's perspective in an interconnected world?",
                        'context' => "Analyze cross-cultural communication benefits and professional advantages.",
                        'example' => "Mastering a global language like English opens doors to international scholarships and cross-border careers. Beyond employment, bilingualism encourages learners to appreciate diverse cultural viewpoints with empathy and openness. It bridges differences and fosters collaborative global problem solving."
                    ],
                    [
                        'prompt' => "Imagine you are designing an eco-friendly community park. What green features will you include? Who will benefit most from this park? How will local volunteers maintain it over time?",
                        'context' => "Outline an innovative community proposal emphasizing sustainability and social inclusion.",
                        'example' => "Our proposed eco-park will feature rainwater harvesting ponds, native flowering gardens, and solar-powered walking lamps. Elderly residents will enjoy shaded benches while children play in natural wooden playgrounds. Weekend volunteer workshops will ensure the garden beds remain thriving and free of plastic litter."
                    ]
                ];
                $ws = $writingScenarios[$i % count($writingScenarios)];
                [$minW, $maxW] = match($level) {
                    'basic' => [15, 50],
                    'advanced' => [80, 200],
                    default => [35, 100],
                };

                $base += [
                    'prompt' => $ws['prompt'],
                    'context' => $ws['context'],
                    'min_words' => $minW,
                    'max_words' => $maxW,
                    'rubric' => ['task_completion', 'relevance', 'grammar', 'vocabulary', 'organization', 'coherence', 'mechanics'],
                    'example_answer' => $ws['example']
                ];
            }
            $items[]=$base;
        }
        return $items;
    }
    /** Extract a human-readable topic label from the RPP text. */
    private function extractTopicLabel(string $text): string
    {
        if(preg_match('/Chapter\s*\/\s*Topik(?:\s*Chapter\s*\/\s*Topik)?\s+([A-Za-z0-9\s,-]{5,200})/ui',$text,$m)){
            $c=trim(preg_replace('/\s+/',' ',$m[1])??'');
            if(!preg_match('/(pembelajaran|kegiatan|alokasi|waktu|modul|ajar|kurikulum)/i',$c)&&mb_strlen($c)>=5){
                return mb_substr($c,0,80);
            }
        }
        return 'Endemic Wildlife and Environmental Conservation';
    }
    private function passage(string $text,string $level,int $index): string{$words=preg_split('/\s+/u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[];$target=(int)Level::profile($level)['length'];if(!$words)return 'English learning develops communication through meaningful context.';$start=($index*13)%count($words);$rotated=array_merge(array_slice($words,$start),array_slice($words,0,$start));return implode(' ',array_slice(array_merge($rotated,$rotated,$rotated),0,$target));}
    private function instruction(string $skill,string $level): string{return match($skill){'reading'=>"Read the {$level} passage and choose the best answer.",'listening'=>"Play the Generated Listening Audio and answer before unlocking the transcript.",'speaking'=>'Read the following sentence aloud with clear pronunciation.','writing'=>"Write a {$level} response within the word-count limit."};}
    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function validateItem(string $skill,string $level,array $item): array
    {
        foreach(['title','instruction','competency','source_excerpt'] as $field)if(!isset($item[$field])||!is_string($item[$field])||trim($item[$field])==='')throw new \RuntimeException('Activity schema invalid.');
        
        $jsonEncoded = json_encode($item, JSON_UNESCAPED_UNICODE);
        if (preg_match('/\b(adalah|yang|dan|di|dari|pada|untuk|dengan|sebagai|serta|atau|dalam|ini|itu|ke|oleh|karena|tidak|bisa|dapat|membantu|mempelajari|pembelajaran|menganalisis|membandingkan|penggunaan|kemampuan|kebanggaan|membuat|menggunakan|pengetahuan|menumbuhkan|persamaan|perbedaan|satwa|endemik|tujuan|peserta|didik|siswa|guru|kegiatan|pendahuluan|penutup|materi|langkah|asesmen|soal|jawaban|pilihan|teks)\b/iu', $jsonEncoded)) {
            throw new \RuntimeException('Activity contains Indonesian words; 100% English required.');
        }

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
