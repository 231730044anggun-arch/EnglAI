<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        "DELETE newer FROM content_questions newer
         JOIN content_questions older
           ON older.classroom_id = newer.classroom_id
          AND LOWER(TRIM(older.question)) = LOWER(TRIM(newer.question))
          AND older.id < newer.id"
    );
    $pdo->exec(
        "UPDATE content_questions
         SET question_hash = SHA2(LOWER(TRIM(question)), 256)"
    );
};
