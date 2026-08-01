<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS speaking_sessions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        classroom_id BIGINT UNSIGNED NOT NULL, member_id BIGINT UNSIGNED NOT NULL,
        lesson_plan_id BIGINT UNSIGNED NOT NULL, level ENUM('basic','intermediate','advanced') NOT NULL,
        task_snapshot_json JSON NOT NULL, current_position TINYINT UNSIGNED NOT NULL DEFAULT 0,
        status ENUM('active','completed','abandoned') NOT NULL DEFAULT 'active',
        started_at DATETIME NOT NULL, completed_at DATETIME NULL,
        KEY idx_speaking_resume(member_id,classroom_id,level,status),
        CONSTRAINT fk_sp_session_classroom FOREIGN KEY(classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
        CONSTRAINT fk_sp_session_member FOREIGN KEY(member_id) REFERENCES classroom_members(id) ON DELETE CASCADE,
        CONSTRAINT fk_sp_session_plan FOREIGN KEY(lesson_plan_id) REFERENCES classroom_lesson_plans(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS speaking_recordings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        session_id BIGINT UNSIGNED NOT NULL, task_id BIGINT UNSIGNED NOT NULL,
        member_id BIGINT UNSIGNED NOT NULL, classroom_id BIGINT UNSIGNED NOT NULL,
        idempotency_key CHAR(64) NOT NULL, storage_path VARCHAR(500) NOT NULL,
        mime_type VARCHAR(100) NOT NULL, duration_ms INT UNSIGNED NOT NULL, file_size INT UNSIGNED NOT NULL,
        raw_transcript TEXT NULL, final_transcript TEXT NULL,
        transcription_status ENUM('pending','completed','failed','needs_review') NOT NULL DEFAULT 'pending',
        assessment_status ENUM('pending','completed','failed','needs_review') NOT NULL DEFAULT 'pending',
        assessment_json JSON NULL, score DECIMAL(5,2) NULL,
        consented_at DATETIME NOT NULL, created_at DATETIME NOT NULL,
        UNIQUE KEY uq_speaking_upload(session_id,task_id), KEY idx_speaking_owner(member_id,classroom_id),
        CONSTRAINT fk_sp_record_session FOREIGN KEY(session_id) REFERENCES speaking_sessions(id) ON DELETE CASCADE,
        CONSTRAINT fk_sp_record_task FOREIGN KEY(task_id) REFERENCES learning_activities(id) ON DELETE RESTRICT,
        CONSTRAINT fk_sp_record_member FOREIGN KEY(member_id) REFERENCES classroom_members(id) ON DELETE CASCADE,
        CONSTRAINT fk_sp_record_classroom FOREIGN KEY(classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
