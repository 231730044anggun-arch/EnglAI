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
        $source='fallback';try{$result=$this->fromAi($skill,$submission,$content);$source='ai';}catch(\Throwable $e){$result=$this->fallback($skill,$submission,$content);app_log('warning','Learning assessment fallback used',['activity_id'=>(int)$activity['id'],'skill'=>$skill,'reason'=>get_class($e)]);}
        return $this->validate($result)+['source'=>$source];
    }
    /** @param array<string,mixed> $content @return array<string,mixed> */
    private function fromAi(string $skill,string $submission,array $content): array
    {
        $key=(string)env_value('GEMINI_API_KEY','');if($key==='')throw new \RuntimeException('Provider unavailable.');
        $rubric=$content['rubric']??[];$prompt="Assess this {$skill} submission. This is transcription-based speaking feedback when skill=speaking; do not claim phoneme, stress, intonation, accent, or pronunciation accuracy. Return JSON only: total_score 0-100, maximum_score 100, criteria array of objects name,score,max_score,feedback; strengths array; improvements array; grammar_notes array; vocabulary_notes array; suggested_revision string; confidence 0-1; status completed|needs_review. Rubric: ".json_encode($rubric)."\nTask: ".json_encode(['prompt'=>$content['prompt']??'','keywords'=>$content['keywords']??[]])."\nSubmission:\n".mb_substr($submission,0,12000);
        $provider=new GeminiProvider($key,(string)env_value('GEMINI_MODEL','gemini-2.5-flash'),(int)env_value('GEMINI_TIMEOUT_SECONDS','45'));
        $last=null;for($attempt=0;$attempt<2;$attempt++){try{return $provider->generate($prompt);}catch(\Throwable $error){$last=$error;}}
        throw new \RuntimeException('Assessment provider unavailable.',0,$last);
    }
    /** @param array<string,mixed> $content @return array<string,mixed> */
    private function fallback(string $skill,string $submission,array $content): array
    {
        $words=preg_split('/\s+/u',trim($submission),-1,PREG_SPLIT_NO_EMPTY)?:[];$wordCount=count($words);$sentences=max(1,preg_match_all('/[.!?]+/u',$submission));$keywords=array_map('mb_strtolower',$content['keywords']??[]);$matched=0;foreach($keywords as $keyword)if($keyword!==''&&str_contains(mb_strtolower($submission),$keyword))$matched++;
        $target=(int)($content['min_words']??($skill==='speaking'?25:60));$lengthScore=min(100,round($wordCount/max(1,$target)*100));$keywordScore=$keywords?round($matched/count($keywords)*100):70;$sentenceScore=min(100,$sentences*25);$lexical=count(array_unique(array_map('mb_strtolower',$words)));$vocabScore=$wordCount?min(100,round($lexical/$wordCount*140)):0;
        $names=$skill==='speaking'?['response_relevance','task_completion','grammar','vocabulary','completeness','transcription_clarity']:['task_completion','relevance','grammar','vocabulary','organization','coherence','mechanics'];$base=[round(($keywordScore+$lengthScore)/2),$lengthScore,$sentenceScore,$vocabScore,round(($sentenceScore+$lengthScore)/2),$sentenceScore,$sentenceScore];$criteria=[];foreach($names as $i=>$name)$criteria[]=['name'=>$name,'score'=>max(0,min(100,$base[$i]??$sentenceScore)),'max_score'=>100,'feedback'=>'Fallback heuristic based on transcript/text length, required concepts, sentence completeness, and lexical variety.'];$total=(int)round(array_sum(array_column($criteria,'score'))/count($criteria));
        return ['total_score'=>$total,'maximum_score'=>100,'criteria'=>$criteria,'strengths'=>[$matched>0?'Relevant lesson keywords were included.':'A response was submitted successfully.'],'improvements'=>[$wordCount<$target?"Expand the response to at least {$target} words.":'Add more precise details and varied sentence structures.'],'grammar_notes'=>['Review sentence boundaries and verb consistency.'],'vocabulary_notes'=>['Use more topic-specific vocabulary where appropriate.'],'suggested_revision'=>trim($submission).' Add one clear supporting detail and a concluding sentence.','confidence'=>.62,'status'=>$wordCount<$target?'needs_review':'completed'];
    }
    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function validate(array $data): array
    {
        $score=max(0,min(100,(float)($data['total_score']??0)));$criteria=$data['criteria']??[];if(!is_array($criteria)||$criteria===[])throw new \RuntimeException('Assessment schema invalid.');
        foreach($criteria as &$criterion){if(!is_array($criterion))throw new \RuntimeException('Assessment criterion invalid.');$criterion['name']=trim((string)($criterion['name']??'criterion'));$criterion['score']=max(0,min(100,(float)($criterion['score']??0)));$criterion['max_score']=100;$criterion['feedback']=trim((string)($criterion['feedback']??''));}
        return ['total_score'=>$score,'maximum_score'=>100,'criteria'=>$criteria,'strengths'=>array_values(array_map('strval',is_array($data['strengths']??null)?$data['strengths']:[])),'improvements'=>array_values(array_map('strval',is_array($data['improvements']??null)?$data['improvements']:[])),'grammar_notes'=>array_values(array_map('strval',is_array($data['grammar_notes']??null)?$data['grammar_notes']:[])),'vocabulary_notes'=>array_values(array_map('strval',is_array($data['vocabulary_notes']??null)?$data['vocabulary_notes']:[])),'suggested_revision'=>trim((string)($data['suggested_revision']??'')),'confidence'=>max(0,min(1,(float)($data['confidence']??.5))),'status'=>in_array($data['status']??'completed',['completed','needs_review'],true)?$data['status']:'completed'];
    }
}
