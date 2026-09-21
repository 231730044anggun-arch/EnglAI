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
  private function context(array $a,string $raw):array{
      $decode=fn(string $k):array=>json_decode((string)($a[$k]??'[]'),true)?:[];
      $topic=trim((string)($a['topic']??''));
      if($topic===''||preg_match('/MODUL AJAR|Satuan Pendidikan|Alokasi Waktu|Tahun Penyusunan|Pertemuan|Kelas|Fase|Semester/i',$topic)){
          $topic='English Reading Comprehension, Nature, and Conservation';
      }
      return [
          'topic'=>$topic,
          'objectives'=>$decode('learning_objectives_json'),
          'competencies'=>$decode('competencies_json'),
          'vocabulary'=>$decode('vocabulary_json'),
          'grammar'=>$decode('grammar_json'),
          'skill_focus'=>$decode('skill_focus_json'),
          'complexity'=>(string)($a['material_complexity']??''),
          'trace'=>mb_substr(RppTextCleaner::pedagogicalContext($raw),0,900)
      ];
  }

  private function fromAi(array $context,string $level,int$count,string$batchId):array{
      $key=(string)env_value('GEMINI_API_KEY','');
      if($key==='')throw new \RuntimeException('AI unavailable');
      $prompt='Create exactly '.$count.' unique standalone English Reading questions grounded in the structured lesson context. Batch nonce: '.$batchId.'. Each item: subtopic, type, optional short_context of 1-3 sentences, question, exactly four option objects {id,text}, correct_option_id, explanation, difficulty, estimated_seconds=20. Vary explicit information, factual detail, main idea, inference, vocabulary in context, reference, sentence meaning, purpose, report-text structure, lesson grammar, comparison, and conclusion. Exactly one option is correct. Do not repeat templates, contexts, or question text. Never use MODUL AJAR, school name, class/phase labels, time allocation, year, filename, headers, or footers as question material. CRITICAL LANGUAGE REQUIREMENT: All fields (short_context, question, option texts, subtopic, explanation) MUST BE 100% IN PROPER ENGLISH. Even if the structured lesson context contains Indonesian words or curriculum notes, you must translate or frame everything strictly in English. Never output any Indonesian words. Return JSON {questions:[...]}. Canonical level: '.$level.'. Structured lesson context: '.json_encode($context,JSON_UNESCAPED_UNICODE);
      $data=(new GeminiProvider($key,(string)env_value('GEMINI_MODEL','gemini-3.5-flash'),(int)env_value('GEMINI_TIMEOUT_SECONDS','45')))->generate($prompt);
      $items=$data['questions']??[];
      if(!is_array($items)||count($items)<$count)throw new \RuntimeException('Gemini Reading batch schema invalid.');
      return array_slice($items,0,$count);
  }

  private function fallback(array $context,string $level):array
  {
      $baseItems = [
          // 20 Narrative items
          [
              'subtopic' => 'Narrative Beginnings', 'type' => 'explicit_information',
              'short_context' => 'A young girl lived near a quiet forest with her family. She helped her parents gather firewood every morning before school.',
              'question' => 'What did the young girl do every morning before going to school?',
              'options' => ['She helped gather firewood for her family.', 'She studied ancient history books.', 'She sold fresh vegetables in the market.', 'She worked as a servant in the castle.'],
              'explanation' => 'The passage states that she helped her parents gather firewood every morning.'
          ],
          [
              'subtopic' => 'Character Actions', 'type' => 'explicit_information',
              'short_context' => 'The village elder asked the villagers to build a strong bridge across the river. Everyone worked together until the bridge was complete.',
              'question' => 'What did the villagers construct together across the river?',
              'options' => ['A strong bridge to cross the river.', 'A tall wooden watchtower.', 'A community meeting hall.', 'A stone wall around the village.'],
              'explanation' => 'The text explains that the villagers built a strong bridge across the river.'
          ],
          [
              'subtopic' => 'Story Plot and Conflict', 'type' => 'inference',
              'short_context' => 'Dark storm clouds gathered quickly over the valley. The farmers rushed to protect their harvested grain before the heavy rain started.',
              'question' => 'Why did the farmers rush to protect their harvested grain?',
              'options' => ['They wanted to keep the grain dry from incoming rain.', 'They were preparing to sell the grain at dawn.', 'They heard a warning about wild forest animals.', 'They were competing to see who was fastest.'],
              'explanation' => 'The farmers rushed because storm clouds gathered and heavy rain was about to start.'
          ],
          [
              'subtopic' => 'Resolution and Courage', 'type' => 'explicit_information',
              'short_context' => 'A brave traveler entered the mountain cave to search for water. He found a clean freshwater spring deep underground.',
              'question' => 'What did the traveler discover deep inside the mountain cave?',
              'options' => ['A clean freshwater spring.', 'A chest filled with silver coins.', 'An old map showing hidden trails.', 'A colony of mountain bats.'],
              'explanation' => 'The passage explicitly says he found a clean freshwater spring deep underground.'
          ],
          [
              'subtopic' => 'Folk Tale Elements', 'type' => 'main_idea',
              'short_context' => 'Traditional stories often explain natural events through interesting characters. These tales remind communities about respect and kindness.',
              'question' => 'What is the main message taught by many traditional stories?',
              'options' => ['Living with respect and kindness in the community.', 'How to earn wealth through trade.', 'The techniques of building large palaces.', 'Why people should avoid traveling alone.'],
              'explanation' => 'The context highlights that these tales remind communities about respect and kindness.'
          ],
          [
              'subtopic' => 'Problem Solving', 'type' => 'explicit_information',
              'short_context' => 'When the cart wheel broke on the road, Maya used a sturdy log to support the axle. This allowed her family to reach the town safely.',
              'question' => 'How did Maya solve the problem of the broken cart wheel?',
              'options' => ['She used a sturdy log to support the axle.', 'She hired horses from a nearby farm.', 'She abandoned the cart and walked.', 'She called a blacksmith from the next village.'],
              'explanation' => 'Maya placed a sturdy log under the axle so they could reach town.'
          ],
          [
              'subtopic' => 'Moral Lessons', 'type' => 'inference',
              'short_context' => 'The greedy merchant refused to share his bread with the hungry traveler. Later, when his wagon got stuck, no one offered to assist him.',
              'question' => 'What consequence did the merchant experience because of his selfishness?',
              'options' => ['No one offered to assist him when he needed help.', 'The guards arrested him for breaking town laws.', 'He lost his entire fortune to forest bandits.', 'He had to give away all of his remaining bread.'],
              'explanation' => 'His refusal to help earlier resulted in no one offering assistance when his wagon got stuck.'
          ],
          [
              'subtopic' => 'Setting and Atmosphere', 'type' => 'vocabulary_in_context',
              'short_context' => 'The dense mist covered the harbor as the wooden ship approached the dock. The sailors moved cautiously along the slippery deck.',
              'question' => 'In this passage, what does the word "cautiously" mean?',
              'options' => ['Carefully and with great attention to safety.', 'Quickly without looking around.', 'Angrily because of unexpected delays.', 'Loudly so everyone could hear them.'],
              'explanation' => 'Moving cautiously means acting with care and awareness of hazards.'
          ],
          [
              'subtopic' => 'Friendship and Loyalty', 'type' => 'explicit_information',
              'short_context' => 'Leo waited three hours at the library for his friend Sam. He wanted to make sure they studied for their science test together.',
              'question' => 'Why did Leo wait at the library for his friend?',
              'options' => ['To prepare for their upcoming science test together.', 'To borrow historical fiction novels.', 'To return books before the library closed.', 'To attend an after-school computer workshop.'],
              'explanation' => 'The text explains that Leo wanted to study for their science test together.'
          ],
          [
              'subtopic' => 'Mystery and Discovery', 'type' => 'inference',
              'short_context' => 'The old journal contained handwritten notes about rare botanical plants. The explorer realized the journal was written over a century ago.',
              'question' => 'What can be inferred about the old journal from the passage?',
              'options' => ['It is a valuable historical record of plant species.', 'It was newly printed by a local university.', 'It belongs to an active modern biology student.', 'It contains fictional poetry about the forest.'],
              'explanation' => 'The century-old handwritten notes about rare plants make it a valuable historical record.'
          ],
          [
              'subtopic' => 'Family Traditions', 'type' => 'explicit_information',
              'short_context' => 'Every harvest season, the family gathered to prepare traditional honey cakes. Grandmother always measured the spices by hand.',
              'question' => 'Who was responsible for measuring the spices for the honey cakes?',
              'options' => ['Grandmother measured the spices by hand.', 'The eldest daughter bought the spices.', 'The local baker prepared the mixture.', 'Father ground the spices with stones.'],
              'explanation' => 'The context directly states that Grandmother always measured the spices by hand.'
          ],
          [
              'subtopic' => 'Overcoming Challenges', 'type' => 'main_idea',
              'short_context' => 'Climbing the steep hill required immense stamina and focus. Despite tired legs, the scouts encouraged one another until everyone reached the summit.',
              'question' => 'What enabled the scouts to successfully reach the mountain summit?',
              'options' => ['Mutual encouragement and persistent effort.', 'Using modern electric transport vehicles.', 'Following an easy and paved walking trail.', 'Hiring local guides to carry their gear.'],
              'explanation' => 'The passage emphasizes that the scouts encouraged one another despite exhaustion.'
          ],
          [
              'subtopic' => 'Celebration and Joy', 'type' => 'explicit_information',
              'short_context' => 'The town square was decorated with colorful paper lanterns. Musicians played cheerful melodies while children danced around the fountain.',
              'question' => 'What decorations were hung throughout the town square?',
              'options' => ['Colorful paper lanterns.', 'Golden banners with royal crests.', 'Large wooden statues of past leaders.', 'Fresh pine wreaths along the buildings.'],
              'explanation' => 'The passage states that the town square was decorated with colorful paper lanterns.'
          ],
          [
              'subtopic' => 'Curiosity and Adventure', 'type' => 'explicit_information',
              'short_context' => 'Sara found an unusual carved stone near the ancient ruins. She took a photograph and showed it to her archaeology teacher.',
              'question' => 'What did Sara do after finding the carved stone near the ruins?',
              'options' => ['She photographed it and showed it to her teacher.', 'She hid it inside her backpack to keep it safe.', 'She attempted to sell it to an art collector.', 'She buried it deeper underground.'],
              'explanation' => 'Sara took a photograph and showed the carved stone to her archaeology teacher.'
          ],
          [
              'subtopic' => 'Animal Companion', 'type' => 'inference',
              'short_context' => 'The shepherd dog barked alertly and ran toward the rocky hillside. Within minutes, the shepherd found a lost lamb stuck in the bushes.',
              'question' => 'Why did the shepherd dog bark and run toward the hillside?',
              'options' => ['It had detected the location of the missing lamb.', 'It was frightened by a sudden thunderstorm.', 'It wanted to chase wild birds in the meadow.', 'It was responding to a stranger entering the field.'],
              'explanation' => 'The dog alerted the shepherd, leading directly to the rescue of the lost lamb.'
          ],
          [
              'subtopic' => 'Craftsmanship', 'type' => 'explicit_information',
              'short_context' => 'The master carpenter spent weeks carving intricate floral patterns on the wooden door. He polished the surface with natural beeswax.',
              'question' => 'What material did the carpenter use to polish the wooden door?',
              'options' => ['Natural beeswax.', 'Chemical varnish from the city.', 'Clear mineral oil.', 'Crushed river sand.'],
              'explanation' => 'The passage explicitly says he polished the surface with natural beeswax.'
          ],
          [
              'subtopic' => 'Generosity', 'type' => 'main_idea',
              'short_context' => 'Sharing resources during harsh winters keeps remote villages resilient. Families exchange dried fruits, firewood, and warm clothing.',
              'question' => 'What practice helps the remote village survive harsh winters?',
              'options' => ['Sharing essential supplies among community families.', 'Closing all roads to prevent outsiders from entering.', 'Relying entirely on foreign emergency aid.', 'Moving to warmer southern cities every autumn.'],
              'explanation' => 'The text explains that exchanging essential goods keeps families resilient during winter.'
          ],
          [
              'subtopic' => 'Curiosity in Science', 'type' => 'explicit_information',
              'short_context' => 'Tari observed water droplets forming on the outside of her cold glass. Her teacher explained that this process is called condensation.',
              'question' => 'What scientific process explains the water droplets on the cold glass?',
              'options' => ['Condensation.', 'Evaporation.', 'Precipitation.', 'Filtration.'],
              'explanation' => 'Her teacher explained that the formation of water droplets on the cold surface is condensation.'
          ],
          [
              'subtopic' => 'Courage Under Pressure', 'type' => 'inference',
              'short_context' => 'When smoke appeared in the hallway, Kevin immediately rang the fire alarm and guided his younger classmates to the emergency exit.',
              'question' => 'How would you describe Kevin\'s behavior during the emergency?',
              'options' => ['Responsible, calm, and proactive.', 'Confused and panicked by the alarm.', 'Indifferent to the safety of others.', 'Unsure of what action to take.'],
              'explanation' => 'Kevin acted responsibly by ringing the alarm and safely guiding younger classmates.'
          ],
          [
              'subtopic' => 'Story Climax', 'type' => 'explicit_information',
              'short_context' => 'With the sunset fading, the team found the final trail marker near the waterfall. They safely reached the base camp before nightfall.',
              'question' => 'Where did the team discover their final trail marker?',
              'options' => ['Near the waterfall.', 'At the entrance of the dark cave.', 'Beneath the ancient oak tree.', 'Inside the ranger outpost.'],
              'explanation' => 'The passage says they found the final trail marker near the waterfall.'
          ],

          // 20 Nature, Wildlife & Conservation items
          [
              'subtopic' => 'Tropical Biodiversity', 'type' => 'explicit_information',
              'short_context' => 'Rainforests cover only six percent of the planet, but they shelter more than half of all terrestrial animal and plant species.',
              'question' => 'What percentage of the Earth\'s land surface is covered by rainforests?',
              'options' => ['Approximately six percent.', 'Nearly twenty-five percent.', 'Around fifty percent.', 'More than seventy percent.'],
              'explanation' => 'The passage explicitly states that rainforests cover only six percent of the planet.'
          ],
          [
              'subtopic' => 'Endemic Primates', 'type' => 'explicit_information',
              'short_context' => 'The proboscis monkey is easily recognized by its distinctive long nose. It is native exclusively to the island of Borneo.',
              'question' => 'On which island can the proboscis monkey be found in the wild?',
              'options' => ['The island of Borneo.', 'The island of Madagascar.', 'The Galápagos Islands.', 'The island of New Guinea.'],
              'explanation' => 'The text states that the proboscis monkey is native exclusively to Borneo.'
          ],
          [
              'subtopic' => 'Bird Adaptations', 'type' => 'inference',
              'short_context' => 'The bird of paradise has vibrant plumage and performs complex courtship rituals. These visual displays help attract female partners.',
              'question' => 'What is the primary function of the colorful feathers in male birds of paradise?',
              'options' => ['To perform courtship displays and attract mates.', 'To blend into the surrounding forest leaves.', 'To frighten predators away from the nest.', 'To retain heat during chilly tropical nights.'],
              'explanation' => 'The passage states that vibrant plumage and dances help attract female partners.'
          ],
          [
              'subtopic' => 'Seed Dispersal', 'type' => 'main_idea',
              'short_context' => 'Hornbills consume a wide variety of tropical fruits and carry the seeds across long distances. This makes them crucial for forest regeneration.',
              'question' => 'Why are hornbills considered vital for the survival of rainforests?',
              'options' => ['They disperse fruit seeds over wide distances to regrow trees.', 'They hunt destructive rodents in agricultural fields.', 'They build nests that shield small mammals from rain.', 'They pollinate nocturnal flowering plants.'],
              'explanation' => 'Hornbills eat fruits and carry seeds far away, which regenerates the forest.'
          ],
          [
              'subtopic' => 'Endangered Species', 'type' => 'explicit_information',
              'short_context' => 'The Bali Starling has snowy white feathers with a striking blue mask around its eyes. Conservationists breed them to protect wild numbers.',
              'question' => 'What distinguishing physical feature does the Bali Starling have around its eyes?',
              'options' => ['A striking blue mask.', 'A bright yellow ring.', 'A dark red circle.', 'A patch of green feathers.'],
              'explanation' => 'The passage notes that the Bali Starling has a striking blue mask around its eyes.'
          ],
          [
              'subtopic' => 'Marine Ecosystems', 'type' => 'inference',
              'short_context' => 'Coral reefs provide shelter and nursery grounds for a quarter of all marine organisms. Rising ocean temperatures threaten these sensitive systems.',
              'question' => 'What is a major threat facing coral reef ecosystems today?',
              'options' => ['Rising ocean temperatures.', 'An overabundance of small fish.', 'Decreased sunlight in shallow waters.', 'Excessive freshwater rainfall.'],
              'explanation' => 'The text specifically mentions that rising ocean temperatures threaten sensitive coral systems.'
          ],
          [
              'subtopic' => 'Mangrove Forests', 'type' => 'explicit_information',
              'short_context' => 'Mangrove trees possess complex root systems that stabilize coastal mud. They protect coastal villages from heavy storm surges and tsunamis.',
              'question' => 'How do mangrove roots help protect coastal communities?',
              'options' => ['They stabilize mud and reduce the impact of storm surges.', 'They produce timber for building seawalls.', 'They lower water temperatures along the shoreline.', 'They block ocean winds from reaching inland fields.'],
              'explanation' => 'Mangrove root systems stabilize sediment and shield shorelines from storm surges.'
          ],
          [
              'subtopic' => 'Apex Predators', 'type' => 'main_idea',
              'short_context' => 'Tigers control herbivore populations, preventing overgrazing of forest vegetation. Protecting tiger habitats preserves the balance of the entire ecosystem.',
              'question' => 'How do apex predators like tigers maintain ecological balance?',
              'options' => ['By controlling herbivore numbers to prevent overgrazing.', 'By planting seeds across their hunting territory.', 'By driving small carnivores out of the forest.', 'By limiting the spread of aquatic plant species.'],
              'explanation' => 'Tigers regulate prey populations so that vegetation is not depleted by overgrazing.'
          ],
          [
              'subtopic' => 'Nocturnal Animals', 'type' => 'vocabulary_in_context',
              'short_context' => 'Owls are nocturnal hunters equipped with large eyes and silent feathers. They hunt small rodents under the cover of darkness.',
              'question' => 'What does the term "nocturnal" mean in this context?',
              'options' => ['Active primarily during the night.', 'Living exclusively in deep caves.', 'Feeding only on plant materials.', 'Migrating south during cold seasons.'],
              'explanation' => 'Nocturnal organisms are active during nighttime hours.'
          ],
          [
              'subtopic' => 'Freshwater Habitats', 'type' => 'explicit_information',
              'short_context' => 'Wetlands filter pollutants from runoff water before it reaches rivers and lakes. They act like natural sponges during seasonal flooding.',
              'question' => 'What natural function do wetlands perform during seasonal floods?',
              'options' => ['They act like sponges to absorb excess runoff water.', 'They freeze quickly to block overflowing rivers.', 'They speed up water flow into the open sea.', 'They convert river water into drinking water instantly.'],
              'explanation' => 'The text states that wetlands act like natural sponges during flooding.'
          ],
          [
              'subtopic' => 'Reptile Adaptations', 'type' => 'explicit_information',
              'short_context' => 'Komodo dragons are the largest living lizards on Earth. They use their sensitive yellow tongues to detect scent particles in the air.',
              'question' => 'Which organ does the Komodo dragon use to detect scents in the air?',
              'options' => ['Its sensitive yellow tongue.', 'Large external ear canals.', 'Broad nostrils with protective flaps.', 'Sensitive scales across its back.'],
              'explanation' => 'Komodo dragons use their yellow tongues to sample scent particles in the air.'
          ],
          [
              'subtopic' => 'Plant Pollination', 'type' => 'inference',
              'short_context' => 'Honeybees transfer pollen grains between flowers while gathering sweet nectar. Without pollinators, many commercial fruit crops would fail.',
              'question' => 'What would likely happen to many fruit crops if pollinators disappeared?',
              'options' => ['The crops would fail to produce fruit effectively.', 'The plants would grow twice as fast as before.', 'The fruits would become sweeter naturally.', 'The trees would attract different herbivorous insects.'],
              'explanation' => 'Without pollination, flowering plants cannot develop fruit properly.'
          ],
          [
              'subtopic' => 'Forest Canopy', 'type' => 'explicit_information',
              'short_context' => 'The canopy layer forms a dense ceiling of leaves eighty feet above the forest floor. Many birds and tree frogs spend their entire lives there.',
              'question' => 'Where is the rainforest canopy located?',
              'options' => ['Eighty feet above the forest floor.', 'Directly along riverbanks and streams.', 'Beneath the decaying leaf litter on the ground.', 'On the windy peaks of mountain ridges.'],
              'explanation' => 'The passage states the canopy forms a ceiling of leaves eighty feet above the ground.'
          ],
          [
              'subtopic' => 'Animal Migration', 'type' => 'main_idea',
              'short_context' => 'Migratory birds fly thousands of miles along established global flyways. They navigate using celestial cues, magnetic fields, and prominent landmarks.',
              'question' => 'What tools do migratory birds use to find their travel routes?',
              'options' => ['Celestial cues, Earth\'s magnetic fields, and landmarks.', 'Radio signals emitted by human cities.', 'Underwater ocean currents along coasts.', 'Following groups of ground-dwelling animals.'],
              'explanation' => 'The text highlights celestial cues, magnetic fields, and landmarks as navigation tools.'
          ],
          [
              'subtopic' => 'Desert Life', 'type' => 'explicit_information',
              'short_context' => 'Cactus plants store water inside thick fleshy stems to endure long droughts. Sharp spines protect the valuable moisture from thirsty animals.',
              'question' => 'What is the purpose of sharp spines on cactus plants?',
              'options' => ['To protect stored moisture from thirsty animals.', 'To absorb moisture directly from desert fog.', 'To help the plant climb rocky canyon walls.', 'To produce sweet flowers for desert birds.'],
              'explanation' => 'Sharp spines prevent animals from consuming the plant\'s stored water.'
          ],
          [
              'subtopic' => 'Soil Health', 'type' => 'inference',
              'short_context' => 'Earthworms burrow through rich soil, creating tunnels that allow air and water to penetrate. Their waste enriches the earth with valuable nutrients.',
              'question' => 'How do earthworms benefit agricultural soil?',
              'options' => ['They aerate the soil and enrich it with organic nutrients.', 'They eliminate harmful underground plant roots.', 'They pack the earth tightly to stop water movement.', 'They prevent fallen leaves from decomposing.'],
              'explanation' => 'Earthworms aerate soil through burrowing and deposit nutrient-rich castings.'
          ],
          [
              'subtopic' => 'Ocean Giants', 'type' => 'explicit_information',
              'short_context' => 'Blue whales are the largest creatures to ever exist on Earth. Despite their colossal size, they feed primarily on tiny shrimp-like krill.',
              'question' => 'What do blue whales primarily consume for nutrition?',
              'options' => ['Tiny shrimp-like organisms called krill.', 'Large schools of tuna and salmon.', 'Floating brown sea kelp near shorelines.', 'Small seals and coastal penguins.'],
              'explanation' => 'The passage says that blue whales feed primarily on tiny krill.'
          ],
          [
              'subtopic' => 'Reforestation Efforts', 'type' => 'main_idea',
              'short_context' => 'Community tree planting programs restore degraded hillsides and reduce soil erosion. Local schools often participate to teach environmental stewardship.',
              'question' => 'What is one major goal of community tree planting programs?',
              'options' => ['Restoring degraded hillsides and stopping soil erosion.', 'Creating commercial timber farms for city paper mills.', 'Clearing space for industrial highway construction.', 'Replacing native vegetation with imported grass.'],
              'explanation' => 'Tree planting restores degraded hillsides and protects the soil from erosion.'
          ],
          [
              'subtopic' => 'Camouflage in Nature', 'type' => 'vocabulary_in_context',
              'short_context' => 'The walking stick insect resembles a dry twig, making it virtually undetectable to predators. This camouflage allows it to feed undisturbed.',
              'question' => 'What does "camouflage" refer to in this passage?',
              'options' => ['Physical appearance that blends into the background.', 'The ability to run at extraordinary speeds.', 'Producing loud warning sounds when threatened.', 'Emitting poisonous chemicals from the skin.'],
              'explanation' => 'Camouflage is a physical disguise that allows organisms to match their surroundings.'
          ],
          [
              'subtopic' => 'Renewable Resources', 'type' => 'inference',
              'short_context' => 'Solar panels convert sunlight directly into clean electricity without greenhouse gases. Harnessing renewable energy reduces our dependence on fossil fuels.',
              'question' => 'What environmental benefit comes from using solar energy?',
              'options' => ['It generates clean power without emitting greenhouse gases.', 'It increases the overall temperature of the atmosphere.', 'It requires burning coal only during nighttime hours.', 'It eliminates the need for water conservation programs.'],
              'explanation' => 'Solar energy generates electricity cleanly without releasing greenhouse gases.'
          ],

          // 20 Science, Daily Life, Communication & Culture items
          [
              'subtopic' => 'Digital Communication', 'type' => 'explicit_information',
              'short_context' => 'Email allows people across the globe to send messages and documents in seconds. It has transformed both personal conversations and business transactions.',
              'question' => 'How has email impacted global communication?',
              'options' => ['It enables instant delivery of messages and documents worldwide.', 'It replaced telephone calls for all spoken conversations.', 'It made physical libraries completely obsolete.', 'It restricted business communication to local areas.'],
              'explanation' => 'Email allows instant transmission of text and files across international borders.'
          ],
          [
              'subtopic' => 'Healthy Habits', 'type' => 'main_idea',
              'short_context' => 'Regular physical exercise strengthens the heart and boosts mental focus. Health experts recommend at least thirty minutes of activity each day.',
              'question' => 'How much daily physical activity do health experts recommend?',
              'options' => ['At least thirty minutes per day.', 'More than three hours every morning.', 'Only ten minutes once a week.', 'Two full hours before every meal.'],
              'explanation' => 'Experts recommend at least thirty minutes of daily physical exercise.'
          ],
          [
              'subtopic' => 'The Water Cycle', 'type' => 'explicit_information',
              'short_context' => 'When sunlight heats lakes and oceans, water transforms into invisible vapor. This upward movement of moisture is known as evaporation.',
              'question' => 'What term describes the transformation of liquid water into vapor?',
              'options' => ['Evaporation.', 'Precipitation.', 'Solidification.', 'Sublimation.'],
              'explanation' => 'The passage states that water turning into vapor by heat is evaporation.'
          ],
          [
              'subtopic' => 'Public Transportation', 'type' => 'inference',
              'short_context' => 'Using electric buses and metro trains reduces the number of private cars on city streets. This shift significantly lowers carbon emissions and smog.',
              'question' => 'What is one major advantage of widespread public transit use?',
              'options' => ['It reduces urban traffic congestion and air pollution.', 'It guarantees that streets will never need maintenance.', 'It makes personal bicycles unnecessary for citizens.', 'It eliminates travel costs completely for everyone.'],
              'explanation' => 'Public transit lowers the count of private vehicles, decreasing emissions.'
          ],
          [
              'subtopic' => 'Nutrition and Health', 'type' => 'explicit_information',
              'short_context' => 'Citrus fruits like oranges and lemons are packed with vitamin C. This essential nutrient helps the human body fight off seasonal infections.',
              'question' => 'Which vitamin is abundantly found in citrus fruits like oranges?',
              'options' => ['Vitamin C.', 'Vitamin D.', 'Vitamin B12.', 'Vitamin K.'],
              'explanation' => 'The text explicitly states that citrus fruits are rich in vitamin C.'
          ],
          [
              'subtopic' => 'Language Learning', 'type' => 'main_idea',
              'short_context' => 'Practicing English daily through reading and speaking builds fluency over time. Consistent exposure helps learners gain confidence in diverse situations.',
              'question' => 'What is the most effective key to building language fluency?',
              'options' => ['Consistent daily practice and regular exposure.', 'Memorizing dictionary pages without speaking.', 'Avoiding all conversation until perfection is reached.', 'Studying grammar rules only once a month.'],
              'explanation' => 'Consistent daily practice and exposure lead to genuine fluency and confidence.'
          ],
          [
              'subtopic' => 'Inventions and History', 'type' => 'explicit_information',
              'short_context' => 'Johannes Gutenberg developed the movable type printing press in the fifteenth century. His invention allowed books to be duplicated quickly and affordably.',
              'question' => 'What major breakthrough did Gutenberg\'s printing press achieve?',
              'options' => ['It made book production much faster and more affordable.', 'It created the first digital computer network.', 'It invented paper manufactured from recycled cotton.', 'It translated foreign languages automatically.'],
              'explanation' => 'The printing press allowed rapid and affordable duplication of books.'
          ],
          [
              'subtopic' => 'Reading for Pleasure', 'type' => 'inference',
              'short_context' => 'Reading fiction improves our empathy by letting us experience life through different characters. It also expands vocabulary in a natural and engaging way.',
              'question' => 'How does reading fiction foster empathy in readers?',
              'options' => ['By allowing readers to experience perspectives of diverse characters.', 'By providing factual statistics about historical events.', 'By teaching readers how to win arguments in debates.', 'By testing memory through structured examinations.'],
              'explanation' => 'Fiction places readers in the shoes of different characters, cultivating empathy.'
          ],
          [
              'subtopic' => 'Hydration and Brain Function', 'type' => 'explicit_information',
              'short_context' => 'Drinking sufficient water maintains blood circulation and cognitive performance. Mild dehydration can cause headaches, fatigue, and reduced concentration.',
              'question' => 'What symptom can result from mild dehydration?',
              'options' => ['Headaches, fatigue, and reduced concentration.', 'Unusually high levels of physical energy.', 'Instant loss of long-term memory.', 'Improved ability to solve complex puzzles.'],
              'explanation' => 'The passage states that mild dehydration can trigger headaches and fatigue.'
          ],
          [
              'subtopic' => 'Recycling and Waste', 'type' => 'main_idea',
              'short_context' => 'Sorting household waste into plastics, paper, and compost reduces landfill volume. Proper recycling conserves natural resources and saves energy.',
              'question' => 'Why is sorting household waste important for the environment?',
              'options' => ['It decreases landfill waste and conserves natural resources.', 'It creates more space for city incinerators.', 'It allows people to discard single-use plastics freely.', 'It replaces the need for municipal trash collection.'],
              'explanation' => 'Waste sorting keeps materials out of landfills and conserves raw resources.'
          ],
          [
              'subtopic' => 'Teamwork in Sports', 'type' => 'explicit_information',
              'short_context' => 'Successful soccer teams rely on clear communication and tactical discipline. Individual skill is most effective when players coordinate their passes.',
              'question' => 'When is individual athletic talent most effective in team sports?',
              'options' => ['When players coordinate passes and communicate clearly.', 'When one player keeps the ball throughout the game.', 'When teams ignore strategic planning from coaches.', 'When players compete against their own teammates.'],
              'explanation' => 'The passage says talent works best when players coordinate passes together.'
          ],
          [
              'subtopic' => 'Music and Emotions', 'type' => 'inference',
              'short_context' => 'Listening to calming instrumental music can lower heart rate and reduce stress levels. Many students use classical melodies to maintain study focus.',
              'question' => 'Why do many students listen to instrumental music while studying?',
              'options' => ['It helps them stay relaxed and maintain academic focus.', 'It allows them to finish exams without reading questions.', 'It memorizes textbooks on their behalf.', 'It cancels all school assignments automatically.'],
              'explanation' => 'Calm music reduces stress and supports focused concentration.'
          ],
          [
              'subtopic' => 'Cultural Festivals', 'type' => 'explicit_information',
              'short_context' => 'Traditional dance performances showcase heritage costumes, ancient rhythms, and storytelling. They connect younger generations with their ancestral roots.',
              'question' => 'What role do traditional dance performances play in communities?',
              'options' => ['They connect younger generations with cultural heritage.', 'They replace formal history lessons in modern schools.', 'They are organized solely to sell commercial products.', 'They prevent communities from adopting modern music.'],
              'explanation' => 'Traditional dances connect youth with ancestral heritage and storytelling.'
          ],
          [
              'subtopic' => 'Sleep and Memory', 'type' => 'explicit_information',
              'short_context' => 'During deep sleep, the brain organizes and consolidates memories gathered throughout the day. Teenagers typically require eight to ten hours of rest.',
              'question' => 'How many hours of nightly sleep do teenagers typically require?',
              'options' => ['Eight to ten hours of sleep.', 'Four to five hours of sleep.', 'Exactly six hours of sleep.', 'More than twelve hours every day.'],
              'explanation' => 'The passage states that teenagers typically need eight to ten hours of rest.'
          ],
          [
              'subtopic' => 'Critical Thinking', 'type' => 'main_idea',
              'short_context' => 'Evaluating sources before sharing news online prevents the spread of misinformation. Checking facts across multiple reliable outlets ensures accuracy.',
              'question' => 'What is the best way to prevent the spread of online misinformation?',
              'options' => ['Verifying information across multiple reliable sources.', 'Sharing shocking headlines as quickly as possible.', 'Believing articles that have numerous visual images.', 'Assuming all social media posts are fully verified.'],
              'explanation' => 'Checking facts across credible outlets confirms accuracy before sharing.'
          ],
          [
              'subtopic' => 'Space Exploration', 'type' => 'explicit_information',
              'short_context' => 'Robotic rovers on Mars analyze rock chemistry to search for evidence of ancient water. Their cameras transmit high-resolution panoramic images back to Earth.',
              'question' => 'What are Mars robotic rovers primarily searching for in rock samples?',
              'options' => ['Evidence of ancient liquid water.', 'Underground deposits of gold and silver.', 'Plant fossils preserved in frozen ice.', 'Remains of ancient alien architecture.'],
              'explanation' => 'The text explains that rovers analyze rock chemistry for signs of ancient water.'
          ],
          [
              'subtopic' => 'Community Libraries', 'type' => 'inference',
              'short_context' => 'Public libraries offer free access to books, digital computers, and quiet study rooms. They serve as welcoming hubs for lifelong learning in every neighborhood.',
              'question' => 'Why are public libraries considered vital community centers?',
              'options' => ['They provide free educational resources and study spaces for all.', 'They sell commercial bestsellers at discounted prices.', 'They are reserved exclusively for university researchers.', 'They replace the function of primary and secondary schools.'],
              'explanation' => 'Libraries provide free access to educational tools and welcoming spaces.'
          ],
          [
              'subtopic' => 'Time Management', 'type' => 'main_idea',
              'short_context' => 'Breaking large projects into smaller daily tasks prevents last-minute stress. Using a calendar or checklist helps students track deadlines realistically.',
              'question' => 'What is an effective strategy for managing large school projects?',
              'options' => ['Breaking large goals into smaller, manageable daily tasks.', 'Delaying all work until the night before the deadline.', 'Asking friends to complete difficult assignments.', 'Focusing only on the easiest parts and ignoring the rest.'],
              'explanation' => 'Dividing work into smaller daily tasks avoids stressful cramming.'
          ],
          [
              'subtopic' => 'Art and Observation', 'type' => 'explicit_information',
              'short_context' => 'Sketching objects from life trains the eye to notice subtle shadows, textures, and proportions. Artists practice regularly to refine their hand coordination.',
              'question' => 'What skill is sharpened by sketching real-world objects regularly?',
              'options' => ['Noticing subtle shadows, textures, and proportions.', 'Memorizing historical dates of famous paintings.', 'Speeding up how quickly paint dries on canvas.', 'Predicting the financial value of modern artwork.'],
              'explanation' => 'Sketching from life trains artists to notice shadows, textures, and proportions.'
          ],
          [
              'subtopic' => 'Environmental Stewardship', 'type' => 'inference',
              'short_context' => 'Carrying a reusable stainless steel water bottle eliminates dozens of single-use plastic cups each month. Small daily choices lead to substantial waste reduction.',
              'question' => 'What does this passage conclude about small personal habits?',
              'options' => ['Consistent small choices can lead to substantial positive impact.', 'Individual actions have no measurable effect on environmental waste.', 'Reusable bottles require too much maintenance to be practical.', 'Single-use plastic cups are necessary for modern convenience.'],
              'explanation' => 'The passage concludes that small daily habits create substantial waste reduction.'
          ]
      ];

      $items = [];
      foreach ($baseItems as $item) {
          $formattedOptions = [];
          foreach ($item['options'] as $optText) {
              $formattedOptions[] = ['id' => 'temporary', 'text' => $optText];
          }
          $items[] = [
              'subtopic' => $item['subtopic'],
              'type' => $item['type'],
              'short_context' => $item['short_context'],
              'question' => $item['question'],
              'options' => $formattedOptions,
              'correct_option_id' => 'temporary',
              'explanation' => $item['explanation'],
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
 public function validate(array $item):void{
     $context=trim((string)($item['short_context']??''));
     $question=trim((string)($item['question']??''));
     $encoded=$context.' '.$question;
     if($question===''||preg_match('/MODUL AJAR|Satuan Pendidikan|Alokasi Waktu|Tahun Penyusunan|Which keyword (best )?connects/i',$encoded))throw new \RuntimeException('Standalone Reading guard rejected item.');
     if(preg_match('/\b(adalah|yang|dan|di|dari|pada|untuk|dengan|sebagai|serta|atau|dalam|ini|itu|ke|oleh|karena|tidak|bisa|dapat|membantu|mempelajari|pembelajaran|menganalisis|membandingkan|penggunaan|kemampuan|kebanggaan|membuat|menggunakan|pengetahuan|menumbuhkan|persamaan|perbedaan|satwa|endemik|tujuan|peserta|didik|siswa|guru|kegiatan|pendahuluan|penutup|materi|langkah|asesmen|soal|jawaban|pilihan|teks)\b/iu',$encoded))throw new \RuntimeException('Standalone Reading item contains Indonesian words; 100% English required.');
     if($context!==''&&(mb_strlen($context)<35||substr_count($context,'.')>3))throw new \RuntimeException('Short context must contain 1-3 concise sentences.');
     $options=$item['options']??[];
     $ids=array_column($options,'id');
     $texts=array_map(fn($o)=>ReadingSessionService::normalizeQuestionText((string)($o['text']??'')),$options);
     if(count($options)!==4||count(array_unique($ids))!==4||count(array_unique($texts))!==4||!in_array($item['correct_option_id']??'', $ids,true)||empty($item['explanation']))throw new \RuntimeException('Standalone Reading options invalid.');
 }
}
