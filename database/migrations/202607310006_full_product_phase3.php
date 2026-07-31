<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $hasColumn = static function (string $table, string $column) use ($pdo): bool {
        $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $query->execute([$table, $column]);
        return (int) $query->fetchColumn() > 0;
    };
    $add = static function (string $table, string $column, string $definition) use ($pdo, $hasColumn): void {
        if (!$hasColumn($table, $column)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    };

    $pdo->exec("ALTER TABLE quiz_sessions MODIFY state ENUM('DRAFT','GENERATING','LOBBY','COUNTDOWN','ACTIVE','BETWEEN_QUESTIONS','EVALUATING','FINISHED','CLOSED','CANCELLED','ERROR') NOT NULL DEFAULT 'DRAFT'");
    $add('quiz_sessions', 'quiz_mode', "ENUM('single_skill','mixed_skills','final_challenge') NULL AFTER classroom_id");
    $add('quiz_sessions', 'title', "VARCHAR(200) NULL AFTER quiz_mode");
    $add('quiz_sessions', 'selected_skills_json', "JSON NULL AFTER title");
    $add('quiz_sessions', 'skill_distribution_json', "JSON NULL AFTER selected_skills_json");
    $add('quiz_sessions', 'level', "ENUM('basic','intermediate','advanced') NULL AFTER skill_distribution_json");
    $add('quiz_sessions', 'timer_config_json', "JSON NULL AFTER level");
    $add('quiz_sessions', 'estimated_duration_seconds', "INT UNSIGNED NOT NULL DEFAULT 0");
    $add('quiz_sessions', 'review_enabled', "TINYINT(1) NOT NULL DEFAULT 1");
    $add('quiz_sessions', 'configuration_json', "JSON NULL");

    $add('quiz_participants', 'completion_count', "INT UNSIGNED NOT NULL DEFAULT 0");
    $add('quiz_participants', 'rubric_performance', "DECIMAL(6,2) NOT NULL DEFAULT 0");
    $add('quiz_participants', 'achievement', "VARCHAR(60) NULL");
    $add('quiz_participants', 'final_rank', "INT UNSIGNED NULL");

    $add('quiz_session_questions', 'skill', "ENUM('reading','listening','speaking','writing') NOT NULL DEFAULT 'reading'");
    $add('quiz_session_questions', 'question_type', "ENUM('objective','listening_objective','speaking_response','writing_response') NOT NULL DEFAULT 'objective'");
    $add('quiz_session_questions', 'content_json', "JSON NULL");
    $add('quiz_session_questions', 'max_score', "INT UNSIGNED NOT NULL DEFAULT 1000");
    $add('quiz_session_questions', 'timer_seconds', "INT UNSIGNED NOT NULL DEFAULT 30");
    $add('quiz_session_questions', 'source_item_id', "BIGINT UNSIGNED NULL");
    $add('quiz_session_questions', 'source_excerpt', "TEXT NULL");
    $add('quiz_session_questions', 'provider_source', "ENUM('ai','fallback') NOT NULL DEFAULT 'fallback'");

    $add('quiz_answers', 'transcript', "TEXT NULL");
    $add('quiz_answers', 'writing_submission', "MEDIUMTEXT NULL");
    $add('quiz_answers', 'submission_method', "ENUM('choice','speech_recognition','manual_transcript','editor') NOT NULL DEFAULT 'choice'");
    $add('quiz_answers', 'assessment_status', "ENUM('NOT_REQUIRED','PENDING','PROCESSING','COMPLETED','FALLBACK_COMPLETED','FAILED') NOT NULL DEFAULT 'NOT_REQUIRED'");
    $add('quiz_answers', 'rubric_json', "JSON NULL");
    $add('quiz_answers', 'normalized_score', "INT UNSIGNED NOT NULL DEFAULT 0");
    $add('quiz_answers', 'assessment_source', "ENUM('objective','ai','fallback') NULL");

    $pdo->exec("CREATE TABLE IF NOT EXISTS live_quiz_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        classroom_id BIGINT UNSIGNED NOT NULL,
        lesson_plan_id BIGINT UNSIGNED NOT NULL,
        skill ENUM('reading','listening','speaking','writing') NOT NULL,
        level ENUM('basic','intermediate','advanced') NOT NULL,
        question_type ENUM('objective','listening_objective','speaking_response','writing_response') NOT NULL,
        title VARCHAR(200) NOT NULL,
        prompt TEXT NOT NULL,
        content_json JSON NOT NULL,
        answer_key VARCHAR(255) NULL,
        rubric_json JSON NULL,
        source_excerpt TEXT NOT NULL,
        content_hash CHAR(64) NOT NULL,
        provider_source ENUM('ai','fallback') NOT NULL DEFAULT 'fallback',
        status ENUM('ready','archived','error') NOT NULL DEFAULT 'ready',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(id),
        UNIQUE KEY uq_live_item_hash(classroom_id,content_hash),
        KEY idx_live_bank(classroom_id,skill,level,status),
        CONSTRAINT fk_live_item_classroom FOREIGN KEY(classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
        CONSTRAINT fk_live_item_plan FOREIGN KEY(lesson_plan_id) REFERENCES classroom_lesson_plans(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS quiz_assessment_jobs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        quiz_session_id BIGINT UNSIGNED NOT NULL,
        participant_id BIGINT UNSIGNED NOT NULL,
        session_question_id BIGINT UNSIGNED NOT NULL,
        quiz_answer_id BIGINT UNSIGNED NOT NULL,
        skill ENUM('speaking','writing') NOT NULL,
        status ENUM('PENDING','PROCESSING','COMPLETED','FALLBACK_COMPLETED','FAILED') NOT NULL DEFAULT 'PENDING',
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        available_at DATETIME NOT NULL,
        locked_at DATETIME NULL,
        completed_at DATETIME NULL,
        error_code VARCHAR(60) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(id),
        UNIQUE KEY uq_assessment_answer(quiz_answer_id),
        KEY idx_assessment_poll(quiz_session_id,status,available_at),
        CONSTRAINT fk_job_quiz FOREIGN KEY(quiz_session_id) REFERENCES quiz_sessions(id) ON DELETE CASCADE,
        CONSTRAINT fk_job_participant FOREIGN KEY(participant_id) REFERENCES quiz_participants(id) ON DELETE CASCADE,
        CONSTRAINT fk_job_question FOREIGN KEY(session_question_id) REFERENCES quiz_session_questions(id) ON DELETE CASCADE,
        CONSTRAINT fk_job_answer FOREIGN KEY(quiz_answer_id) REFERENCES quiz_answers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
