<?php
declare(strict_types=1);
namespace EnglAI\Analytics;
final class OverrideService{
 public function __construct(private readonly \PDO $pdo){}
 public function overrideLiveQuiz(int $classroom,int $answerId,float $newScore,array $criteria,string $reason,string $reviewer):int{
  $reason=trim($reason);if(mb_strlen($reason)<5)throw new \InvalidArgumentException('Alasan override minimal 5 karakter.');$newScore=max(0,min(1000,$newScore));
  $q=$this->pdo->prepare("SELECT a.*,q.classroom_id,s.skill FROM quiz_answers a JOIN quiz_sessions q ON q.id=a.quiz_session_id JOIN quiz_session_questions s ON s.id=a.session_question_id WHERE a.id=? AND q.classroom_id=? AND s.skill IN('speaking','writing')");$q->execute([$answerId,$classroom]);$answer=$q->fetch();if(!$answer)throw new \RuntimeException('Assessment tidak ditemukan pada Classroom ini.');
  $original=(float)$answer['normalized_score'];$previous=(float)$answer['score'];$this->pdo->beginTransaction();
  try{$q=$this->pdo->prepare("INSERT INTO teacher_reviews(classroom_id,assessment_context,assessment_id,member_id,reviewer,comment,status) SELECT ?,'live_quiz',?,p.member_id,?,?, 'revised' FROM quiz_participants p WHERE p.id=?");$q->execute([$classroom,$answerId,$reviewer,$reason,(int)$answer['participant_id']]);$review=(int)$this->pdo->lastInsertId();
   $q=$this->pdo->prepare("INSERT INTO score_overrides(teacher_review_id,classroom_id,assessment_context,assessment_id,original_score,previous_final_score,new_score,criterion_changes_json,reason,reviewer,request_id) VALUES(?,?,'live_quiz',?,?,?,?,?,?,?,?)");$q->execute([$review,$classroom,$answerId,$original,$previous,$newScore,json_encode($criteria),$reason,$reviewer,request_id()]);$override=(int)$this->pdo->lastInsertId();
   $delta=$newScore-$previous;$q=$this->pdo->prepare("UPDATE quiz_answers SET score=?,normalized_score=?,rubric_json=JSON_SET(COALESCE(rubric_json,JSON_OBJECT()),'$.teacher_reviewed',true,'$.teacher_criteria',CAST(? AS JSON)) WHERE id=?");$q->execute([$newScore,$newScore,json_encode($criteria),$answerId]);$this->pdo->prepare('UPDATE quiz_participants SET total_score=GREATEST(0,total_score+?) WHERE id=?')->execute([$delta,(int)$answer['participant_id']]);
   (new AuditService($this->pdo))->record($classroom,$reviewer,'score.overridden','quiz_answer',$answerId,['score'=>$previous],['score'=>$newScore,'override_id'=>$override],$reason);$this->pdo->commit();return $override;
  }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
 }
}
