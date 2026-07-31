<?php
declare(strict_types=1);
namespace EnglAI\Analytics;
final class ExportService{
 public function __construct(private readonly \PDO $pdo){}
 public static function safeCell(mixed $value):string{$s=(string)$value;return preg_match('/^[=+\-@]/',$s)?"'".$s:$s;}
 public function classroomCsv(int $id,string $actor):string{
  $m=(new AnalyticsService($this->pdo))->classroom($id);$stream=fopen('php://temp','r+');fwrite($stream,"\xEF\xBB\xBF");fputcsv($stream,['Metric','Value']);foreach(['students','active_students','self_learning_attempts','live_quiz_sessions','self_learning_average','live_quiz_average','completion_rate','classroom_level'] as $k)fputcsv($stream,[self::safeCell($k),self::safeCell($m[$k])]);foreach($m['skills'] as $skill=>$v)fputcsv($stream,[self::safeCell($skill.' average'),self::safeCell($v['average'])]);rewind($stream);$csv=stream_get_contents($stream);fclose($stream);$this->record($id,'classroom_csv',$actor,'englai-classroom-'.$id.'-'.date('Ymd').'.csv');return $csv;
 }
 public function studentCsv(int $classroom,int $member,string $actor):string{$m=(new AnalyticsService($this->pdo))->student($classroom,$member);$s=fopen('php://temp','r+');fwrite($s,"\xEF\xBB\xBF");fputcsv($s,['Student','Skill','Attempts','Average']);$name=self::safeCell($m['member']['display_name']?:'Student #'.$member);if(!$m['skills'])fputcsv($s,[$name,'Insufficient data',0,0]);foreach($m['skills'] as $skill=>$v)fputcsv($s,[$name,$skill,$v['attempts'],$v['average_score']]);rewind($s);$csv=stream_get_contents($s);fclose($s);$this->record($classroom,'student_csv',$actor,'englai-student-'.$member.'-'.date('Ymd').'.csv',$member);return $csv;}
 private function record(int $classroom,string $type,string $actor,string $filename,?int $member=null):void{$q=$this->pdo->prepare("INSERT INTO export_jobs(classroom_id,export_type,member_id,requested_by,status,filename) VALUES(?,?,?,?,'completed',?)");$q->execute([$classroom,$type,$member,$actor,$filename]);(new AuditService($this->pdo))->record($classroom,$actor,'report.exported','export_job',(int)$this->pdo->lastInsertId(),[],['type'=>$type,'filename'=>$filename]);}
}
