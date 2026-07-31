<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $hasColumn = static function (string $table, string $column) use ($pdo): bool {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute([$table, $column]);
        return (int) $statement->fetchColumn() > 0;
    };
    foreach ([
        'recommended_level' => "ENUM('basic','intermediate','advanced') NULL",
        'default_level' => "ENUM('basic','intermediate','advanced') NOT NULL DEFAULT 'intermediate'",
        'level_confirmed_at' => 'DATETIME NULL',
    ] as $column => $definition) {
        if (!$hasColumn('classrooms', $column)) {
            $pdo->exec("ALTER TABLE classrooms ADD COLUMN {$column} {$definition}");
        }
    }

    $statements = [
        "CREATE TABLE IF NOT EXISTS ai_analyses (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            classroom_id BIGINT UNSIGNED NOT NULL,
            lesson_plan_id BIGINT UNSIGNED NOT NULL,
            topic VARCHAR(255) NOT NULL,
            learning_objectives_json JSON NOT NULL,
            competencies_json JSON NOT NULL,
            vocabulary_json JSON NOT NULL,
            grammar_json JSON NOT NULL,
            skill_focus_json JSON NOT NULL,
            material_complexity TEXT NOT NULL,
            recommended_level ENUM('basic','intermediate','advanced') NOT NULL,
            recommendation_reason TEXT NOT NULL,
            source_excerpts_json JSON NOT NULL,
            source ENUM('ai','fallback') NOT NULL,
            status ENUM('valid','superseded','error') NOT NULL DEFAULT 'valid',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            KEY idx_analysis_classroom_plan(classroom_id,lesson_plan_id,status),
            CONSTRAINT fk_analysis_classroom FOREIGN KEY(classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
            CONSTRAINT fk_analysis_plan FOREIGN KEY(lesson_plan_id) REFERENCES classroom_lesson_plans(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS learning_modules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            classroom_id BIGINT UNSIGNED NOT NULL,
            lesson_plan_id BIGINT UNSIGNED NOT NULL,
            skill ENUM('reading','listening','speaking','writing') NOT NULL,
            level ENUM('basic','intermediate','advanced') NOT NULL,
            title VARCHAR(200) NOT NULL,
            objective TEXT NOT NULL,
            competency VARCHAR(255) NOT NULL,
            position INT UNSIGNED NOT NULL,
            source ENUM('ai','fallback') NOT NULL,
            status ENUM('generating','ready','error','archived') NOT NULL DEFAULT 'ready',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY uq_module_slot(classroom_id,lesson_plan_id,skill,level,position),
            KEY idx_module_available(classroom_id,skill,level,status),
            CONSTRAINT fk_module_classroom FOREIGN KEY(classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
            CONSTRAINT fk_module_plan FOREIGN KEY(lesson_plan_id) REFERENCES classroom_lesson_plans(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS learning_activities (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            module_id BIGINT UNSIGNED NOT NULL,
            classroom_id BIGINT UNSIGNED NOT NULL,
            lesson_plan_id BIGINT UNSIGNED NOT NULL,
            skill ENUM('reading','listening','speaking','writing') NOT NULL,
            level ENUM('basic','intermediate','advanced') NOT NULL,
            activity_type ENUM('objective','listening_objective','speaking_response','writing_response') NOT NULL,
            title VARCHAR(200) NOT NULL,
            instruction TEXT NOT NULL,
            content_json JSON NOT NULL,
            source_excerpt TEXT NOT NULL,
            competency VARCHAR(255) NOT NULL,
            source ENUM('ai','fallback') NOT NULL,
            content_hash CHAR(64) NOT NULL,
            status ENUM('ready','archived','error') NOT NULL DEFAULT 'ready',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY uq_activity_hash(classroom_id,content_hash),
            KEY idx_activity_pool(classroom_id,skill,level,status),
            CONSTRAINT fk_activity_module FOREIGN KEY(module_id) REFERENCES learning_modules(id) ON DELETE CASCADE,
            CONSTRAINT fk_activity_classroom FOREIGN KEY(classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
            CONSTRAINT fk_activity_plan FOREIGN KEY(lesson_plan_id) REFERENCES classroom_lesson_plans(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS learning_attempts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            classroom_id BIGINT UNSIGNED NOT NULL,
            member_id BIGINT UNSIGNED NOT NULL,
            activity_id BIGINT UNSIGNED NOT NULL,
            idempotency_key CHAR(64) NOT NULL,
            status ENUM('started','submitted','completed','error') NOT NULL DEFAULT 'started',
            answer_json JSON NULL,
            transcript TEXT NULL,
            writing_submission MEDIUMTEXT NULL,
            score DECIMAL(5,2) NULL,
            maximum_score DECIMAL(5,2) NOT NULL DEFAULT 100,
            feedback TEXT NULL,
            assessment_source ENUM('objective','ai','fallback') NULL,
            started_at DATETIME NOT NULL,
            submitted_at DATETIME NULL,
            completed_at DATETIME NULL,
            PRIMARY KEY(id),
            UNIQUE KEY uq_attempt_idempotency(member_id,idempotency_key),
            KEY idx_attempt_progress(classroom_id,member_id,activity_id,status),
            CONSTRAINT fk_attempt_classroom FOREIGN KEY(classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
            CONSTRAINT fk_attempt_member FOREIGN KEY(member_id) REFERENCES classroom_members(id) ON DELETE CASCADE,
            CONSTRAINT fk_attempt_activity FOREIGN KEY(activity_id) REFERENCES learning_activities(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS assessment_results (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            attempt_id BIGINT UNSIGNED NOT NULL,
            skill ENUM('speaking','writing') NOT NULL,
            criteria_json JSON NOT NULL,
            total_score DECIMAL(5,2) NOT NULL,
            maximum_score DECIMAL(5,2) NOT NULL DEFAULT 100,
            strengths_json JSON NOT NULL,
            improvements_json JSON NOT NULL,
            grammar_notes_json JSON NOT NULL,
            vocabulary_notes_json JSON NOT NULL,
            suggested_revision TEXT NOT NULL,
            example_answer TEXT NOT NULL,
            confidence DECIMAL(4,3) NOT NULL,
            source ENUM('ai','fallback') NOT NULL,
            status ENUM('completed','needs_review','error') NOT NULL DEFAULT 'completed',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY uq_assessment_attempt(attempt_id),
            CONSTRAINT fk_assessment_attempt FOREIGN KEY(attempt_id) REFERENCES learning_attempts(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS student_skill_progress (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            classroom_id BIGINT UNSIGNED NOT NULL,
            member_id BIGINT UNSIGNED NOT NULL,
            skill ENUM('reading','listening','speaking','writing') NOT NULL,
            level ENUM('basic','intermediate','advanced') NOT NULL,
            completed_activities INT UNSIGNED NOT NULL DEFAULT 0,
            average_score DECIMAL(5,2) NOT NULL DEFAULT 0,
            best_score DECIMAL(5,2) NOT NULL DEFAULT 0,
            latest_activity_id BIGINT UNSIGNED NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY uq_skill_progress(classroom_id,member_id,skill,level),
            KEY idx_progress_member(member_id,skill,level),
            CONSTRAINT fk_progress_classroom FOREIGN KEY(classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
            CONSTRAINT fk_progress_member FOREIGN KEY(member_id) REFERENCES classroom_members(id) ON DELETE CASCADE,
            CONSTRAINT fk_progress_activity FOREIGN KEY(latest_activity_id) REFERENCES learning_activities(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }
};
