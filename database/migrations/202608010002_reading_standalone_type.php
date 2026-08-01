<?php
declare(strict_types=1);
return static function(PDO $pdo):void{
    $column=$pdo->query("SHOW COLUMNS FROM learning_activities LIKE 'activity_type'")->fetch();
    if($column&&!str_contains((string)$column['Type'],"'standalone_question'")){
        $pdo->exec("ALTER TABLE learning_activities MODIFY activity_type ENUM('objective','listening_objective','speaking_response','writing_response','standalone_question') NOT NULL");
    }
};
