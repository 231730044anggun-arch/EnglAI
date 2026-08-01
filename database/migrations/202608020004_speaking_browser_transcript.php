<?php
declare(strict_types=1);
return static function(PDO $pdo):void{
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='speaking_recordings' AND COLUMN_NAME='transcript_confidence'");$q->execute();if(!(int)$q->fetchColumn())$pdo->exec('ALTER TABLE speaking_recordings ADD COLUMN transcript_confidence DECIMAL(5,4) NULL AFTER final_transcript');
};
