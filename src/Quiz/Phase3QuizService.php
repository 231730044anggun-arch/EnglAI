<?php
declare(strict_types=1);
namespace EnglAI\Quiz;
use EnglAI\Analytics\AuditService;

final class Phase3QuizService
{
    public function __construct(private readonly \PDO $pdo){}
    public function start(int $quizId,int $classroomId): void
    {
        $timer=(int)$this->scalar('SELECT timer_seconds FROM quiz_session_questions WHERE quiz_session_id=? AND position=0',[$quizId]);
        $q=$this->pdo->prepare("UPDATE quiz_sessions SET state='ACTIVE',current_index=0,question_started_at=NOW(3),question_deadline_at=DATE_ADD(NOW(3),INTERVAL ? SECOND) WHERE id=? AND classroom_id=? AND state='LOBBY'");
        $q->execute([$timer,$quizId,$classroomId]);if($q->rowCount()!==1)throw new \RuntimeException('Quiz hanya dapat dimulai dari lobby.');(new AuditService($this->pdo))->record($classroomId,'teacher','live_quiz.started','quiz_session',$quizId);
    }
    public function close(int $quizId,int $classroomId): void
    { $q=$this->pdo->prepare("UPDATE quiz_sessions SET state='CLOSED' WHERE id=? AND classroom_id=? AND state IN ('LOBBY','FINISHED')");$q->execute([$quizId,$classroomId]);if($q->rowCount()!==1)throw new \RuntimeException('Quiz tidak dapat ditutup pada state ini.');(new AuditService($this->pdo))->record($classroomId,'teacher','live_quiz.closed','quiz_session',$quizId); }
    public function advance(int $quizId): void
    {
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT *,(question_deadline_at IS NOT NULL AND question_deadline_at<=NOW(3)) expired FROM quiz_sessions WHERE id=? FOR UPDATE');$q->execute([$quizId]);$quiz=$q->fetch();
            if(!$quiz||!in_array($quiz['state'],['ACTIVE','EVALUATING'],true)){$this->pdo->commit();return;}
            if($quiz['state']==='EVALUATING'){
                $this->pdo->commit();
                (new AssessmentJobProcessor($this->pdo))->process($quizId);
                $this->pdo->beginTransaction();
                $pending=(int)$this->scalar("SELECT COUNT(*) FROM quiz_assessment_jobs WHERE quiz_session_id=? AND status IN ('PENDING','PROCESSING')",[$quizId]);
                if($pending===0){$this->finish($quizId,$quiz);}$this->pdo->commit();return;
            }
            $participants=(int)$this->scalar('SELECT COUNT(*) FROM quiz_participants WHERE quiz_session_id=?',[$quizId]);
            $answers=(int)$this->scalar('SELECT COUNT(*) FROM quiz_answers a JOIN quiz_session_questions q ON q.id=a.session_question_id WHERE a.quiz_session_id=? AND q.position=?',[$quizId,(int)$quiz['current_index']]);
            if(!(bool)$quiz['expired']&&($participants<1||$answers<$participants)){$this->pdo->commit();return;}
            $next=(int)$quiz['current_index']+1;
            if($next>=(int)$quiz['question_count']){
                $pending=(int)$this->scalar("SELECT COUNT(*) FROM quiz_assessment_jobs WHERE quiz_session_id=? AND status IN ('PENDING','PROCESSING')",[$quizId]);
                if($pending>0)$this->pdo->prepare("UPDATE quiz_sessions SET state='EVALUATING',question_started_at=NULL,question_deadline_at=NULL WHERE id=?")->execute([$quizId]);
                else $this->finish($quizId,$quiz);
            }else{
                $timer=(int)$this->scalar('SELECT timer_seconds FROM quiz_session_questions WHERE quiz_session_id=? AND position=?',[$quizId,$next]);
                $this->pdo->prepare('UPDATE quiz_sessions SET current_index=?,question_started_at=NOW(3),question_deadline_at=DATE_ADD(NOW(3),INTERVAL ? SECOND) WHERE id=?')->execute([$next,$timer,$quizId]);
            }
            $this->pdo->commit();
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function status(int $quizId,?int $participantId=null): array
    {
        $this->advance($quizId);$q=$this->pdo->prepare('SELECT * FROM quiz_sessions WHERE id=?');$q->execute([$quizId]);$quiz=$q->fetch();if(!$quiz)throw new \RuntimeException('Quiz tidak ditemukan.');
        $question=null;
        if($quiz['state']==='ACTIVE'){
            $q=$this->pdo->prepare('SELECT id,position,question,options_json,difficulty,skill,question_type,content_json,max_score,timer_seconds FROM quiz_session_questions WHERE quiz_session_id=? AND position=?');$q->execute([$quizId,(int)$quiz['current_index']]);$question=$q->fetch();
            if($question){$content=json_decode((string)$question['content_json'],true)?:[];$question['options']=json_decode((string)$question['options_json'],true)?:[];unset($question['options_json'],$question['content_json']);
                $question['content']=$this->publicContent($content,(string)$question['question_type']);
                if($participantId){$a=$this->pdo->prepare('SELECT selected_answer,is_correct,score,assessment_status,rubric_json FROM quiz_answers WHERE participant_id=? AND session_question_id=?');$a->execute([$participantId,(int)$question['id']]);$question['submitted']=$a->fetch()?:null;}
            }
        }
        $submitted=$question?(int)$this->scalar('SELECT COUNT(*) FROM quiz_answers WHERE quiz_session_id=? AND session_question_id=?',[$quizId,(int)$question['id']]):0;
        $pending=(int)$this->scalar("SELECT COUNT(*) FROM quiz_assessment_jobs WHERE quiz_session_id=? AND status IN ('PENDING','PROCESSING')",[$quizId]);
        $server=(int)$this->scalar('SELECT FLOOR(UNIX_TIMESTAMP(NOW(3))*1000)',[]);
        $deadline=$quiz['question_deadline_at']?(int)$this->scalar('SELECT FLOOR(UNIX_TIMESTAMP(question_deadline_at)*1000) FROM quiz_sessions WHERE id=?',[$quizId]):null;
        return ['id'=>(int)$quiz['id'],'title'=>$quiz['title'],'mode'=>$quiz['quiz_mode'],'level'=>$quiz['level'],'state'=>$quiz['state'],'current_index'=>(int)$quiz['current_index'],'question_count'=>(int)$quiz['question_count'],'deadline_epoch_ms'=>$deadline,'server_epoch_ms'=>$server,'submitted_count'=>$submitted,'pending_assessments'=>$pending,'question'=>$question,'participants'=>$this->participants($quizId),'leaderboard'=>$this->leaderboard($quizId)];
    }
    public function submit(int $quizId,int $participantId,array $payload): array
    {
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT * FROM quiz_sessions WHERE id=? FOR UPDATE');$q->execute([$quizId]);$quiz=$q->fetch();if(!$quiz||$quiz['state']!=='ACTIVE')throw new \RuntimeException('Quiz tidak sedang aktif.');
            $q=$this->pdo->prepare('SELECT * FROM quiz_session_questions WHERE quiz_session_id=? AND position=?');$q->execute([$quizId,(int)$quiz['current_index']]);$item=$q->fetch();if(!$item)throw new \RuntimeException('Pertanyaan tidak tersedia.');
            $timing=$this->pdo->prepare('SELECT question_deadline_at>=NOW(3) ok,GREATEST(0,FLOOR(TIMESTAMPDIFF(MICROSECOND,question_started_at,NOW(3))/1000)) ms FROM quiz_sessions WHERE id=?');$timing->execute([$quizId]);$time=$timing->fetch();if(!$time||!(bool)$time['ok'])throw new \RuntimeException('Waktu menjawab sudah habis.');
            if(!(bool)$this->scalar('SELECT COUNT(*) FROM quiz_participants WHERE id=? AND quiz_session_id=?',[$participantId,$quizId]))throw new \RuntimeException('Peserta tidak valid.');
            $type=(string)$item['question_type'];$content=json_decode((string)$item['content_json'],true)?:[];$score=0;$correct=false;$status='NOT_REQUIRED';$source='objective';$selected=null;$transcript=null;$writing=null;$method='choice';
            if(in_array($type,['objective','listening_objective'],true)){
                $selected=strtoupper(trim((string)($payload['answer']??'')));if(!in_array($selected,['A','B','C','D'],true))throw new \InvalidArgumentException('Jawaban tidak valid.');
                $correct=hash_equals((string)$item['answer'],$selected);$duration=max(1,(int)$item['timer_seconds']*1000);$remaining=max(0,$duration-(int)$time['ms']);$base=['easy'=>700,'medium'=>750,'hard'=>800][$item['difficulty']]??750;$score=$correct?min(1000,$base+(int)round((1000-$base)*$remaining/$duration)):0;
            }elseif($type==='speaking_response'){
                $transcript=trim((string)($payload['transcript']??''));$method=($payload['submission_method']??'manual_transcript')==='speech_recognition'?'speech_recognition':'manual_transcript';if(mb_strlen($transcript)>12000)throw new \InvalidArgumentException('Transcript terlalu panjang.');$status='PENDING';$source=null;
            }else{
                $writing=trim((string)($payload['writing_submission']??''));$method='editor';$words=count(preg_split('/\s+/u',$writing,-1,PREG_SPLIT_NO_EMPTY)?:[]);$min=(int)($content['minimum_words']??1);$max=(int)($content['maximum_words']??1000);if($words<$min||$words>$max)throw new \InvalidArgumentException("Jawaban harus {$min}–{$max} kata.");if(mb_strlen($writing)>30000)throw new \InvalidArgumentException('Jawaban terlalu panjang.');$status='PENDING';$source=null;
            }
            $ins=$this->pdo->prepare('INSERT INTO quiz_answers (quiz_session_id,participant_id,session_question_id,selected_answer,is_correct,score,response_ms,answered_at,transcript,writing_submission,submission_method,assessment_status,normalized_score,assessment_source) VALUES (?,?,?,?,?,?,?,NOW(3),?,?,?,?,?,?)');
            $ins->execute([$quizId,$participantId,$item['id'],$selected,$correct?1:0,$score,(int)$time['ms'],$transcript,$writing,$method,$status,$score,$source]);$answerId=(int)$this->pdo->lastInsertId();
            if($status==='PENDING')$this->pdo->prepare("INSERT INTO quiz_assessment_jobs (quiz_session_id,participant_id,session_question_id,quiz_answer_id,skill,status,available_at) VALUES (?,?,?,?,?,'PENDING',NOW())")->execute([$quizId,$participantId,$item['id'],$answerId,$item['skill']]);
            else $this->pdo->prepare('UPDATE quiz_participants SET total_score=total_score+?,correct_answers=correct_answers+?,completion_count=completion_count+1,last_seen_at=NOW() WHERE id=?')->execute([$score,$correct?1:0,$participantId]);
            $this->pdo->commit();return ['score'=>$score,'correct'=>$correct,'response_ms'=>(int)$time['ms'],'assessment_status'=>$status];
        }catch(\PDOException $e){if($this->pdo->inTransaction())$this->pdo->rollBack();if((string)$e->getCode()==='23000')throw new \RuntimeException('Jawaban untuk pertanyaan ini sudah dikirim.');throw $e;}catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function participants(int $id): array
    { $q=$this->pdo->prepare('SELECT id,display_name,avatar,last_seen_at FROM quiz_participants WHERE quiz_session_id=? ORDER BY joined_at,id');$q->execute([$id]);return $q->fetchAll(); }
    public function leaderboard(int $id): array
    { $q=$this->pdo->prepare("SELECT p.id,p.display_name,p.avatar,p.total_score,p.correct_answers,p.completion_count,p.achievement,p.final_rank,COALESCE(AVG(CASE WHEN q.question_type IN ('objective','listening_objective') THEN a.response_ms END),999999) average_response_ms FROM quiz_participants p LEFT JOIN quiz_answers a ON a.participant_id=p.id LEFT JOIN quiz_session_questions q ON q.id=a.session_question_id WHERE p.quiz_session_id=? GROUP BY p.id ORDER BY p.total_score DESC,p.correct_answers DESC,p.rubric_performance DESC,average_response_ms ASC,p.completion_count DESC,p.display_name ASC,p.joined_at ASC");$q->execute([$id]);return $q->fetchAll(); }
    private function finish(int $id,array $quiz): void
    {
        $rows=$this->leaderboard($id);$mode=(string)$quiz['quiz_mode'];$skills=json_decode((string)$quiz['selected_skills_json'],true)?:['reading'];
        foreach($rows as $i=>$row){$achievement=null;if($i===0)$achievement=$mode==='single_skill'?['reading'=>'Reading Champion','listening'=>'Listening Star','speaking'=>'Speaking Hero','writing'=>'Writing Expert'][$skills[0]]:'English Master';$this->pdo->prepare('UPDATE quiz_participants SET final_rank=?,achievement=? WHERE id=?')->execute([$i+1,$achievement,(int)$row['id']]);}
        $this->pdo->prepare("UPDATE quiz_sessions SET state='FINISHED',question_started_at=NULL,question_deadline_at=NULL,finished_at=NOW() WHERE id=?")->execute([$id]);
    }
    private function publicContent(array $c,string $type): array
    { unset($c['answer'],$c['explanation']);if($type==='listening_objective')unset($c['transcript']);return $c; }
    private function scalar(string $sql,array $args): mixed
    { $q=$this->pdo->prepare($sql);$q->execute($args);return $q->fetchColumn(); }
}
