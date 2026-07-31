<?php
declare(strict_types=1);
namespace EnglAI\Analytics;
final class AuditService{
 public function __construct(private readonly \PDO $pdo){}
 public function record(?int $classroom,string $actor,string $action,string $type,?int $id,array $before=[],array $after=[],?string $reason=null):void{
  $ip=(string)($_SERVER['REMOTE_ADDR']??'');$hash=$ip===''?null:hash('sha256',$ip.'|'.(string)env_value('APP_KEY','englai'));
  $q=$this->pdo->prepare('INSERT INTO audit_logs(classroom_id,actor,action,entity_type,entity_id,before_json,after_json,reason,request_id,ip_hash) VALUES(?,?,?,?,?,?,?,?,?,?)');
  $q->execute([$classroom,$actor,$action,$type,$id,$before?json_encode($before):null,$after?json_encode($after):null,$reason,request_id(),$hash]);
 }
}
