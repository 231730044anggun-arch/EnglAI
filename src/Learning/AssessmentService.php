<?php
declare(strict_types=1);
namespace EnglAI\Learning;
use EnglAI\AI\GeminiProvider;

final class AssessmentService
{
    public function __construct(private readonly \PDO $pdo){}
    /** @param array<string,mixed> $activity @return array<string,mixed> */
    public function assess(string $skill,string $submission,array $activity): array
    {
        if(!in_array($skill,['speaking','writing'],true))throw new \InvalidArgumentException('Assessment skill tidak valid.');
        $content=json_decode((string)$activity['content_json'],true);if(!is_array($content))throw new \RuntimeException('Activity content invalid.');
        if($skill==='speaking'&&($activity['level']??'')==='basic')return $this->basicReadAloud($submission,$content,(int)($activity['response_duration_ms']??0))+['source'=>'objective'];
        $source='fallback';try{$result=$this->fromAi($skill,$submission,$content,['level'=>(string)($activity['level']??''),'lesson_context'=>mb_substr((string)($activity['source_excerpt']??''),0,3000)]);$source='ai';}catch(\Throwable $e){$result=$this->fallback($skill,$submission,$content);app_log('warning','Learning assessment fallback used',['activity_id'=>(int)$activity['id'],'skill'=>$skill,'reason'=>get_class($e)]);}
        return $this->validate($result)+['source'=>$source];
    }
    /** @param array<string,mixed> $content @return array<string,mixed> */
    private function fromAi(string $skill,string $submission,array $content,array $context=[]): array
    {
        $key=(string)env_value('GEMINI_API_KEY','');if($key==='')throw new \RuntimeException('Provider unavailable.');
        $rubric=$skill==='speaking'?['relevance','grammar','vocabulary','coherence','completeness','suggested_correction']:($content['rubric']??[]);
        $prompt="Assess this {$skill} submission using text only. The transcript is de-identified. When skill=speaking, do not score or claim audio pronunciation, phoneme, clarity, stress, intonation, or accent. \n"
              . "CRITICAL GRADING CONSTRAINT: If the submission is completely irrelevant to the prompt, contains gibberish, or is written/spoken in Indonesian (e.g. Indonesian greeting like 'assalamualaikum', 'selamat pagi', 'halo' without actual English response), you MUST grade all criteria and the total_score very strictly between 0 and 5 out of 100.\n"
              . "Return JSON only: total_score 0-100, maximum_score 100, criteria array of objects name,score,max_score,feedback; strengths array; improvements array; grammar_notes array; vocabulary_notes array; suggested_revision string; confidence 0-1; status completed|needs_review. Rubric: ".json_encode($rubric)."\nDe-identified learning context: ".json_encode($context)."\nTask: ".json_encode(['prompt'=>$content['prompt']??'','keywords'=>$content['keywords']??[]])."\nDe-identified transcript:\n".mb_substr($submission,0,12000);
        $provider=new GeminiProvider($key,(string)env_value('GEMINI_MODEL','gemini-3.5-flash'),(int)env_value('GEMINI_TIMEOUT_SECONDS','45'));
        $last=null;for($attempt=0;$attempt<2;$attempt++){try{return $provider->generate($prompt);}catch(\Throwable $error){$last=$error;}}
        throw new \RuntimeException('Assessment provider unavailable.',0,$last);
    }
    /** @param array<string,mixed> $content @return array<string,mixed> */
    private function fallback(string $skill,string $submission,array $content): array
    {
        $words=preg_split('/\s+/u',trim($submission),-1,PREG_SPLIT_NO_EMPTY)?:[];$wordCount=count($words);$sentences=max(1,preg_match_all('/[.!?]+/u',$submission));$keywords=array_map('mb_strtolower',$content['keywords']??[]);$matched=0;foreach($keywords as $keyword)if($keyword!==''&&str_contains(mb_strtolower($submission),$keyword))$matched++;
        $target=(int)($content['min_words']??($skill==='speaking'?25:60));
        
        // English check helper to penalize Indonesian or garbage input
        $englishWords = ['the','a','an','is','am','are','i','you','we','they','he','she','it','to','in','on','at','for','with','about','like','my','your','this','that','there','here','have','has','do','does','can','go','see','look','good','bad','wild','animal','forest','fauna','indonesia','cendrawasih','bird','starling','peafowl','eagle','myna','monkey','horn','hello','hi','yes','no','not','and','but','or','so','if','because'];
        $hasEnglish = false;
        foreach ($words as $w) {
            $cleanW = strtolower(preg_replace('/[^a-z]/i', '', $w));
            if (in_array($cleanW, $englishWords, true) || strlen($cleanW) > 4) {
                $hasEnglish = true;
                break;
            }
        }
        
        if (!$hasEnglish || $wordCount < 4) {
            $vocabScore = 5;
            $sentenceScore = 5;
            $lengthScore = 5;
            $keywordScore = 0;
        } else {
            $lengthScore=min(100,round($wordCount/max(1,$target)*100));$keywordScore=$keywords?round($matched/count($keywords)*100):70;$sentenceScore=min(100,$sentences*25);$lexical=count(array_unique(array_map('mb_strtolower',$words)));$vocabScore=$wordCount?min(100,round($lexical/$wordCount*140)):0;
        }
        
        $names=$skill==='speaking'?['relevance','grammar','vocabulary','coherence','completeness']:['task_completion','relevance','grammar','vocabulary','organization','coherence','mechanics'];$base=[round(($keywordScore+$lengthScore)/2),$sentenceScore,$vocabScore,$sentenceScore,round(($sentenceScore+$lengthScore)/2),$sentenceScore,$sentenceScore];$criteria=[];foreach($names as $i=>$name)$criteria[]=['name'=>$name,'score'=>max(0,min(100,$base[$i]??$sentenceScore)),'max_score'=>100,'feedback'=>'Fallback heuristic based on de-identified transcript text only.'];$total=(int)round(array_sum(array_column($criteria,'score'))/count($criteria));
        return ['total_score'=>$total,'maximum_score'=>100,'criteria'=>$criteria,'strengths'=>[$matched>0?'Relevant lesson keywords were included.':'A response was submitted successfully.'],'improvements'=>[$wordCount<$target?"Expand the response to at least {$target} words.":'Add more precise details and varied sentence structures.'],'grammar_notes'=>['Review sentence boundaries and verb consistency.'],'vocabulary_notes'=>['Use more topic-specific vocabulary where appropriate.'],'suggested_revision'=>trim($submission).' Add one clear supporting detail and a concluding sentence.','confidence'=>.62,'status'=>$wordCount<$target?'needs_review':'completed'];
    }
    /** @param array<string,mixed> $content @return array<string,mixed> */
    private function basicReadAloud(string $transcript,array $content,int $durationMs): array
    {
        $target=trim((string)($content['example_response']??$content['prompt']??''));$normalize=static fn(string $v):array=>preg_split('/\s+/u',mb_strtolower(trim(preg_replace('/[^\pL\pN\s]/u',' ',$v)??$v)),-1,PREG_SPLIT_NO_EMPTY)?:[];$expected=$normalize($target);$actual=$normalize($transcript);$matches=0;foreach($actual as $i=>$word)if(isset($expected[$i])&&$expected[$i]===$word)$matches++;$accuracy=$expected?(int)round($matches/count($expected)*100):0;$completeness=$expected?(int)round(min(1,count($actual)/count($expected))*100):0;$distance=levenshtein(implode(' ',$expected),implode(' ',$actual));$den=max(1,strlen(implode(' ',$expected)));$similarity=(int)round(max(0,1-$distance/$den)*100);$durationScore=$durationMs>=500&&$durationMs<=25000?100:0;$criteria=[['name'=>'word_accuracy','score'=>$accuracy,'max_score'=>100,'feedback'=>'Compared word-by-word with the displayed target text.'],['name'=>'completeness','score'=>$completeness,'max_score'=>100,'feedback'=>'Measures how much of the target text appears in the browser transcript.'],['name'=>'transcript_similarity','score'=>$similarity,'max_score'=>100,'feedback'=>'Text similarity only; this is not pronunciation scoring.'],['name'=>'response_duration','score'=>$durationScore,'max_score'=>100,'feedback'=>'Recording stayed within the 25-second task limit.']];$total=(int)round(array_sum(array_column($criteria,'score'))/count($criteria));return ['total_score'=>$total,'maximum_score'=>100,'criteria'=>$criteria,'strengths'=>[$accuracy>=80?'Most target words were captured accurately.':'A recording and transcript were completed.'],'improvements'=>[$accuracy<80?'Read the displayed words carefully and retry in a future session.':'Maintain a steady reading pace.','Pronunciation and audio clarity: Teacher Review Required.'],'grammar_notes'=>[],'vocabulary_notes'=>[],'suggested_revision'=>$target,'confidence'=>.7,'status'=>'needs_review'];
    }
    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function validate(array $data): array
    {
        $score=max(0,min(100,(float)($data['total_score']??0)));$criteria=$data['criteria']??[];if(!is_array($criteria)||$criteria===[])throw new \RuntimeException('Assessment schema invalid.');
        foreach($criteria as &$criterion){if(!is_array($criterion))throw new \RuntimeException('Assessment criterion invalid.');$criterion['name']=trim((string)($criterion['name']??'criterion'));$criterion['score']=max(0,min(100,(float)($criterion['score']??0)));$criterion['max_score']=100;$criterion['feedback']=trim((string)($criterion['feedback']??''));}
        return ['total_score'=>$score,'maximum_score'=>100,'criteria'=>$criteria,'strengths'=>array_values(array_map('strval',is_array($data['strengths']??null)?$data['strengths']:[])),'improvements'=>array_values(array_map('strval',is_array($data['improvements']??null)?$data['improvements']:[])),'grammar_notes'=>array_values(array_map('strval',is_array($data['grammar_notes']??null)?$data['grammar_notes']:[])),'vocabulary_notes'=>array_values(array_map('strval',is_array($data['vocabulary_notes']??null)?$data['vocabulary_notes']:[])),'suggested_revision'=>trim((string)($data['suggested_revision']??'')),'confidence'=>max(0,min(1,(float)($data['confidence']??.5))),'status'=>in_array($data['status']??'completed',['completed','needs_review'],true)?$data['status']:'completed'];
    }
}
