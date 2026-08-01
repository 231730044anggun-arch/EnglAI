<?php
declare(strict_types=1);
namespace EnglAI\Learning;
final class ProgressService
{
    public const ADVANCE_THRESHOLD=80;
    public function __construct(private readonly \PDO $pdo){}
    public function refresh(int $classroomId,int $memberId,string $skill,string $level,?int $activityId): void
    {
        $stmt=$this->pdo->prepare("SELECT COUNT(DISTINCT activity_id) completed,COALESCE(AVG(score),0) average_score,COALESCE(MAX(score),0) best_score FROM learning_attempts a JOIN learning_activities l ON l.id=a.activity_id WHERE a.classroom_id=? AND a.member_id=? AND l.skill=? AND l.level=? AND a.status='completed'");$stmt->execute([$classroomId,$memberId,$skill,$level]);$data=$stmt->fetch();
        $stmt=$this->pdo->prepare('INSERT INTO student_skill_progress(classroom_id,member_id,skill,level,completed_activities,average_score,best_score,latest_activity_id) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE completed_activities=VALUES(completed_activities),average_score=VALUES(average_score),best_score=VALUES(best_score),latest_activity_id=VALUES(latest_activity_id)');
        $stmt->execute([$classroomId,$memberId,$skill,$level,(int)$data['completed'],(float)$data['average_score'],(float)$data['best_score'],$activityId]);
    }
    public function summary(int $classroomId,int $memberId): array
    {
        $stmt=$this->pdo->prepare("SELECT l.skill,l.level,COUNT(*) available,COUNT(DISTINCT CASE WHEN a.status='completed' THEN a.activity_id END) completed,COALESCE(AVG(CASE WHEN a.status='completed' THEN a.score END),0) average_score,COALESCE(SUM(CASE WHEN a.status='completed' AND a.score >= 70 THEN 1 ELSE 0 END),0) correct_count,COALESCE(SUM(CASE WHEN a.status='completed' AND a.score < 70 THEN 1 ELSE 0 END),0) incorrect_count,MAX(a.completed_at) latest_at FROM learning_activities l LEFT JOIN learning_attempts a ON a.activity_id=l.id AND a.member_id=? WHERE l.classroom_id=? AND l.status='ready' GROUP BY l.skill,l.level ORDER BY FIELD(l.skill,'reading','listening','speaking','writing'),FIELD(l.level,'basic','intermediate','advanced')");$stmt->execute([$memberId,$classroomId]);$rows=$stmt->fetchAll();$lowest=null;foreach($rows as $row)if($row['completed']<(int)$row['available']&&($lowest===null||(float)$row['average_score']<(float)$lowest['average_score']))$lowest=$row;
        return ['rows'=>$rows,'recommended'=>$lowest,'advance_threshold'=>self::ADVANCE_THRESHOLD];
    }
}
