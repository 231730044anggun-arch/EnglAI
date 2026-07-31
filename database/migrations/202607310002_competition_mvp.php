<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $statements = [
        "CREATE TABLE IF NOT EXISTS classrooms (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            teacher_key VARCHAR(100) NOT NULL,
            code VARCHAR(16) NOT NULL,
            name VARCHAR(150) NOT NULL,
            status ENUM('active','archived') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_classrooms_code (code),
            KEY idx_classrooms_teacher_status (teacher_key, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS classroom_members (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            classroom_id BIGINT UNSIGNED NOT NULL,
            session_token CHAR(64) NOT NULL,
            display_name VARCHAR(60) NULL,
            avatar VARCHAR(32) NULL,
            last_seen_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_classroom_member_token (classroom_id, session_token),
            KEY idx_members_classroom_seen (classroom_id, last_seen_at),
            CONSTRAINT fk_members_classroom FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS classroom_lesson_plans (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            classroom_id BIGINT UNSIGNED NOT NULL,
            legacy_rpp_id INT UNSIGNED NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            file_type ENUM('pdf','docx') NOT NULL,
            extracted_text LONGTEXT NOT NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_lesson_plan_version (classroom_id, version),
            UNIQUE KEY uq_lesson_plan_legacy (legacy_rpp_id),
            KEY idx_lesson_plan_active (classroom_id, is_active),
            CONSTRAINT fk_lesson_plan_classroom FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS content_questions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            classroom_id BIGINT UNSIGNED NOT NULL,
            lesson_plan_id BIGINT UNSIGNED NULL,
            content_type ENUM('self_learning','live_quiz') NOT NULL,
            skill ENUM('reading') NOT NULL DEFAULT 'reading',
            difficulty ENUM('easy','medium','hard') NOT NULL DEFAULT 'medium',
            question TEXT NOT NULL,
            options_json JSON NOT NULL,
            answer CHAR(1) NOT NULL,
            explanation TEXT NOT NULL,
            question_hash CHAR(64) NOT NULL,
            source ENUM('ai','fallback') NOT NULL DEFAULT 'fallback',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_content_question_hash (classroom_id, question_hash),
            KEY idx_content_bank (classroom_id, content_type, difficulty),
            CONSTRAINT fk_content_classroom FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
            CONSTRAINT fk_content_lesson_plan FOREIGN KEY (lesson_plan_id) REFERENCES classroom_lesson_plans(id) ON DELETE SET NULL,
            CONSTRAINT chk_content_answer CHECK (answer IN ('A','B','C','D'))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS student_learning_sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            classroom_id BIGINT UNSIGNED NOT NULL,
            member_id BIGINT UNSIGNED NOT NULL,
            status ENUM('active','completed') NOT NULL DEFAULT 'active',
            current_index INT UNSIGNED NOT NULL DEFAULT 0,
            score INT UNSIGNED NOT NULL DEFAULT 0,
            total_questions INT UNSIGNED NOT NULL DEFAULT 10,
            question_payload_json JSON NOT NULL,
            started_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_learning_member (member_id, status, started_at),
            CONSTRAINT fk_learning_classroom FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
            CONSTRAINT fk_learning_member FOREIGN KEY (member_id) REFERENCES classroom_members(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS student_learning_answers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            learning_session_id BIGINT UNSIGNED NOT NULL,
            question_id BIGINT UNSIGNED NULL,
            selected_answer CHAR(1) NULL,
            is_correct TINYINT(1) NOT NULL DEFAULT 0,
            score INT UNSIGNED NOT NULL DEFAULT 0,
            answered_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_learning_answer (learning_session_id, question_id),
            KEY idx_learning_question (question_id),
            CONSTRAINT fk_learning_answer_session FOREIGN KEY (learning_session_id) REFERENCES student_learning_sessions(id) ON DELETE CASCADE,
            CONSTRAINT fk_learning_answer_question FOREIGN KEY (question_id) REFERENCES content_questions(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS quiz_sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            classroom_id BIGINT UNSIGNED NOT NULL,
            state ENUM('DRAFT','LOBBY','ACTIVE','FINISHED','CLOSED') NOT NULL DEFAULT 'DRAFT',
            question_count INT UNSIGNED NOT NULL,
            difficulty ENUM('easy','medium','hard') NOT NULL DEFAULT 'medium',
            current_index INT UNSIGNED NOT NULL DEFAULT 0,
            question_started_at DATETIME(3) NULL,
            question_deadline_at DATETIME(3) NULL,
            created_by VARCHAR(100) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            finished_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_quiz_classroom_state (classroom_id, state, created_at),
            CONSTRAINT fk_quiz_classroom FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS quiz_participants (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            quiz_session_id BIGINT UNSIGNED NOT NULL,
            member_id BIGINT UNSIGNED NOT NULL,
            display_name VARCHAR(60) NOT NULL,
            avatar VARCHAR(32) NOT NULL,
            total_score INT UNSIGNED NOT NULL DEFAULT 0,
            correct_answers INT UNSIGNED NOT NULL DEFAULT 0,
            last_seen_at DATETIME NOT NULL,
            joined_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_quiz_participant (quiz_session_id, member_id),
            KEY idx_quiz_leaderboard (quiz_session_id, total_score, correct_answers),
            CONSTRAINT fk_participant_quiz FOREIGN KEY (quiz_session_id) REFERENCES quiz_sessions(id) ON DELETE CASCADE,
            CONSTRAINT fk_participant_member FOREIGN KEY (member_id) REFERENCES classroom_members(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS quiz_session_questions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            quiz_session_id BIGINT UNSIGNED NOT NULL,
            position INT UNSIGNED NOT NULL,
            source_question_id BIGINT UNSIGNED NULL,
            question TEXT NOT NULL,
            options_json JSON NOT NULL,
            answer CHAR(1) NOT NULL,
            explanation TEXT NOT NULL,
            difficulty ENUM('easy','medium','hard') NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_quiz_question_position (quiz_session_id, position),
            CONSTRAINT fk_session_question_quiz FOREIGN KEY (quiz_session_id) REFERENCES quiz_sessions(id) ON DELETE CASCADE,
            CONSTRAINT fk_session_question_source FOREIGN KEY (source_question_id) REFERENCES content_questions(id) ON DELETE SET NULL,
            CONSTRAINT chk_session_answer CHECK (answer IN ('A','B','C','D'))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS quiz_answers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            quiz_session_id BIGINT UNSIGNED NOT NULL,
            participant_id BIGINT UNSIGNED NOT NULL,
            session_question_id BIGINT UNSIGNED NOT NULL,
            selected_answer CHAR(1) NULL,
            is_correct TINYINT(1) NOT NULL DEFAULT 0,
            score INT UNSIGNED NOT NULL DEFAULT 0,
            response_ms INT UNSIGNED NOT NULL,
            answered_at DATETIME(3) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_quiz_answer (participant_id, session_question_id),
            KEY idx_quiz_answers_session (quiz_session_id, session_question_id),
            CONSTRAINT fk_answer_quiz FOREIGN KEY (quiz_session_id) REFERENCES quiz_sessions(id) ON DELETE CASCADE,
            CONSTRAINT fk_answer_participant FOREIGN KEY (participant_id) REFERENCES quiz_participants(id) ON DELETE CASCADE,
            CONSTRAINT fk_answer_question FOREIGN KEY (session_question_id) REFERENCES quiz_session_questions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    $pdo->exec(
        "INSERT INTO classrooms (teacher_key, code, name)
         SELECT 'admin', 'ENG-DEMO', 'EnglAI Demo Classroom'
         WHERE EXISTS (SELECT 1 FROM rpps)
           AND NOT EXISTS (SELECT 1 FROM classrooms)"
    );

    $demoId = $pdo->query("SELECT id FROM classrooms ORDER BY id LIMIT 1")->fetchColumn();
    if ($demoId !== false) {
        $statement = $pdo->prepare(
            "INSERT INTO classroom_lesson_plans
                (classroom_id, legacy_rpp_id, original_name, stored_name, file_type, extracted_text, version, is_active)
             SELECT ?, r.id, r.original_name, r.stored_name, r.file_type, r.extracted_text,
                    (SELECT COUNT(*) FROM rpps r2 WHERE r2.id <= r.id), r.is_selected
             FROM rpps r
             LEFT JOIN classroom_lesson_plans lp ON lp.legacy_rpp_id = r.id
             WHERE lp.id IS NULL
             ORDER BY r.id"
        );
        $statement->execute([(int) $demoId]);
    }
};
