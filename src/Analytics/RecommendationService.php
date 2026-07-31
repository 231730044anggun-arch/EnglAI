<?php
declare(strict_types=1);
namespace EnglAI\Analytics;
final class RecommendationService{
 public function __construct(private readonly \PDO $pdo){}
 public function generate(int $classroom,string $actor,?int $member=null):array{
  $metrics=$member?(new AnalyticsService($this->pdo))->student($classroom,$member):(new AnalyticsService($this->pdo))->classroom($classroom);
  $skills=$member?$metrics['skills']:$metrics['skills'];$averages=[];foreach($skills as $name=>$data)$averages[$name]=(float)($data['average_score']??$data['average']??0);
  asort($averages);$weak=array_key_first($averages)?:'reading';arsort($averages);$strong=array_key_first($averages)?:'reading';
  $hasData=array_sum(array_map(fn($v)=>(int)($v['attempts']??0),$skills))>0;
  $data=['summary'=>$hasData?"Data menunjukkan kekuatan utama pada {$strong} dan fokus perbaikan pada {$weak}.":'Belum cukup aktivitas untuk membuat analisis mendalam.','strengths'=>$hasData?["Performance {$strong} relatif paling kuat."]:[],'learning_gaps'=>$hasData?["Performance {$weak} perlu latihan lanjutan."]:[],'recommended_actions'=>$hasData?["Prioritaskan Self Learning {$weak}.","Gunakan Live Quiz {$weak} setelah remediasi."]:['Minta Student menyelesaikan aktivitas pada setiap skill.'],'recommended_skill'=>$weak,'recommended_level'=>$metrics['classroom_level']??'intermediate','confidence'=>$hasData?.72:.35];
  $hash=hash('sha256',json_encode($metrics));$q=$this->pdo->prepare("UPDATE ai_recommendations SET status='superseded' WHERE classroom_id=? AND member_id <=> ? AND status='active'");$q->execute([$classroom,$member]);
  $q=$this->pdo->prepare("INSERT INTO ai_recommendations(classroom_id,member_id,metrics_hash,recommendation_json,source,status,created_by) VALUES(?,?,?,?,'fallback','active',?)");$q->execute([$classroom,$member,$hash,json_encode($data,JSON_UNESCAPED_UNICODE),$actor]);
  (new AuditService($this->pdo))->record($classroom,$actor,'recommendation.generated','ai_recommendation',(int)$this->pdo->lastInsertId(),[],['source'=>'fallback','member_id'=>$member]);
  return $data+['source'=>'fallback'];
 }
}
