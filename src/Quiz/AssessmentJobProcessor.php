<?php
declare(strict_types=1);
namespace EnglAI\Quiz;

use EnglAI\Learning\AssessmentService;

final class AssessmentJobProcessor
{
    public function __construct(private readonly \PDO $pdo){}
    public function process(int $quizId,int $limit=8): int
    {
        $q=$this->pdo->prepare("SELECT j.*,a.transcript,a.writing_submission,s.content_json,s.max_score
          FROM quiz_assessment_jobs j JOIN quiz_answers a ON a.id=j.quiz_answer_id
          JOIN quiz_session_questions s ON s.id=j.session_question_id
          WHERE j.quiz_session_id=? AND j.status='PENDING' AND j.available_at<=NOW() ORDER BY j.id LIMIT {$limit}");
        $q->execute([$quizId]);$done=0;
        foreach($q->fetchAll() as $job)$done+=$this->one($job)?1:0;
        return $done;
    }
    private function one(array $job): bool
    {
        $claim=$this->pdo->prepare("UPDATE quiz_assessment_jobs SET status='PROCESSING',attempts=attempts+1,locked_at=NOW() WHERE id=? AND status='PENDING'");
        $claim->execute([(int)$job['id']]);if($claim->rowCount()!==1)return false;
        $submission=$job['skill']==='speaking'?(string)$job['transcript']:(string)$job['writing_submission'];
        try{
            $result=(new AssessmentService($this->pdo))->assess((string)$job['skill'],$submission,['id'=>(int)$job['session_question_id'],'content_json'=>$job['content_json']]);
            $score=$submission===''?0:(int)round(max(0,min(100,(float)$result['total_score']))*10);
            $status=$result['source']==='ai'?'COMPLETED':'FALLBACK_COMPLETED';
            $this->pdo->beginTransaction();
            $this->pdo->prepare('UPDATE quiz_answers SET score=?,normalized_score=?,rubric_json=?,assessment_status=?,assessment_source=? WHERE id=?')
                ->execute([$score,$score,json_encode($result,JSON_UNESCAPED_UNICODE),$status,$result['source'],(int)$job['quiz_answer_id']]);
            $this->pdo->prepare('UPDATE quiz_participants SET total_score=total_score+?,rubric_performance=rubric_performance+?,completion_count=completion_count+1 WHERE id=?')
                ->execute([$score,(float)$result['total_score'],(int)$job['participant_id']]);
            $this->pdo->prepare('UPDATE quiz_assessment_jobs SET status=?,completed_at=NOW(),error_code=NULL WHERE id=?')->execute([$status,(int)$job['id']]);
            $this->pdo->commit();return true;
        }catch(\Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            $this->pdo->prepare("UPDATE quiz_assessment_jobs SET status='FAILED',completed_at=NOW(),error_code='assessment_error' WHERE id=?")->execute([(int)$job['id']]);
            return false;
        }
    }
}
