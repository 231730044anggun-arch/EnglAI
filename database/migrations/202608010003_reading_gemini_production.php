<?php
declare(strict_types=1);
return static function(PDO $pdo):void{
    $pdo->exec("UPDATE learning_activities SET status='archived' WHERE skill='reading' AND activity_type='standalone_question' AND source='fallback' AND status='ready'");
};
