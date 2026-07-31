<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "DELETE newer FROM content_questions newer
         JOIN content_questions older
           ON older.classroom_id = newer.classroom_id
          AND older.question = newer.question
          AND CAST(older.options_json AS CHAR) = CAST(newer.options_json AS CHAR)
          AND older.id < newer.id"
    );
    $pdo->exec(
        "UPDATE content_questions
         SET question_hash = SHA2(
             CONCAT(LOWER(TRIM(question)), '|', CAST(options_json AS CHAR)),
             256
         )"
    );
};
