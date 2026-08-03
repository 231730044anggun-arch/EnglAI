<?php
declare(strict_types=1);
namespace EnglAI\Learning;
use EnglAI\AI\GeminiProvider;
final class RppAnalysisService
{
    public function __construct(private readonly \PDO $pdo){}
    /** @return array<string,mixed> */
    public function analyze(int $classroomId): array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM classroom_lesson_plans WHERE classroom_id=? AND is_active=1 ORDER BY version DESC LIMIT 1');$stmt->execute([$classroomId]);$plan=$stmt->fetch();
        if(!$plan)throw new \RuntimeException('Upload RPP sebelum menjalankan AI Analysis.');
        $source='fallback';try{$data=$this->fromAi((string)$plan['extracted_text']);$source='ai';}catch(\Throwable $e){$data=$this->fallback((string)$plan['extracted_text'],(string)$plan['original_name']);app_log('warning','RPP analysis fallback used',['classroom_id'=>$classroomId,'reason'=>get_class($e)]);}
        $data=$this->validate($data);$this->pdo->prepare("UPDATE ai_analyses SET status='superseded' WHERE classroom_id=? AND status='valid'")->execute([$classroomId]);
        $stmt=$this->pdo->prepare('INSERT INTO ai_analyses(classroom_id,lesson_plan_id,topic,learning_objectives_json,competencies_json,vocabulary_json,grammar_json,skill_focus_json,material_complexity,recommended_level,recommendation_reason,source_excerpts_json,source) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$classroomId,$plan['id'],$data['topic'],json_encode($data['learning_objectives'],JSON_UNESCAPED_UNICODE),json_encode($data['competencies'],JSON_UNESCAPED_UNICODE),json_encode($data['vocabulary'],JSON_UNESCAPED_UNICODE),json_encode($data['grammar'],JSON_UNESCAPED_UNICODE),json_encode($data['skill_focus'],JSON_UNESCAPED_UNICODE),$data['material_complexity'],$data['recommended_level'],$data['recommendation_reason'],json_encode($data['source_excerpts'],JSON_UNESCAPED_UNICODE),$source]);
        $this->pdo->prepare('UPDATE classrooms SET recommended_level=? WHERE id=?')->execute([$data['recommended_level'],$classroomId]);
        return $data+['source'=>$source,'analysis_id'=>(int)$this->pdo->lastInsertId()];
    }
    /** @return array<string,mixed> */
    private function fromAi(string $text): array
    {
        $key=(string)env_value('GEMINI_API_KEY','');if($key==='')throw new \RuntimeException('Provider unavailable.');
        return (new GeminiProvider($key,(string)env_value('GEMINI_MODEL','gemini-3.5-flash'),(int)env_value('GEMINI_TIMEOUT_SECONDS','45')))->generate("Analyze this English lesson material or text. Return JSON only with topic string, learning_objectives array, competencies array, vocabulary array, grammar array, skill_focus array using reading/listening/speaking/writing, material_complexity string, recommended_level basic|intermediate|advanced, recommendation_reason string, source_excerpts array. Material:\n".mb_substr($text,0,24000));
    }
    /** @return array<string,mixed> */
    private function fallback(string $text,string $name): array
    {
        preg_match_all('/\b[A-Za-z]{5,}\b/u',$text,$m);$words=array_values(array_unique(array_map('strtolower',$m[0]??[])));$words=array_slice($words,0,12);$length=mb_strlen($text);$level=$length>12000?'advanced':($length>4500?'intermediate':'basic');$excerpt=trim(mb_substr(preg_replace('/\s+/u',' ',$text)??$text,0,280));
        return ['topic'=>pathinfo($name,PATHINFO_FILENAME),'learning_objectives'=>['Understand the central topic of the lesson plan','Use relevant English vocabulary in context','Respond through receptive and productive skills'],'competencies'=>['Comprehension','Contextual vocabulary','Communicative response'],'vocabulary'=>$words?:['language','reading','context','meaning','response'],'grammar'=>[$level==='basic'?'Simple sentences':'Compound and complex sentences','Context-appropriate grammar'],'skill_focus'=>['reading','listening','speaking','writing'],'material_complexity'=>"Document length {$length} characters with classroom-specific learning material.",'recommended_level'=>$level,'recommendation_reason'=>"Fallback analysis recommends {$level} based on document length, vocabulary range, and instructional density.",'source_excerpts'=>[$excerpt]];
    }
    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function validate(array $data): array
    {
        foreach(['topic','material_complexity','recommended_level','recommendation_reason'] as $field)if(!isset($data[$field])||!is_string($data[$field])||trim($data[$field])==='')throw new \RuntimeException('AI analysis schema invalid.');
        foreach(['learning_objectives','competencies','vocabulary','grammar','skill_focus','source_excerpts'] as $field)if(!isset($data[$field])||!is_array($data[$field])||$data[$field]===[])throw new \RuntimeException('AI analysis schema invalid.');
        $data['recommended_level']=Level::validate($data['recommended_level']);return $data;
    }
}
