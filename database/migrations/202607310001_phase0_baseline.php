<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $tableExists = (bool) $pdo->query(
        "SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'rpps'"
    )->fetchColumn();

    if (!$tableExists) {
        $pdo->exec(
            "CREATE TABLE rpps (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                original_name VARCHAR(255) NOT NULL,
                stored_name VARCHAR(255) NOT NULL,
                file_type ENUM('pdf','docx') NOT NULL,
                extracted_text LONGTEXT NOT NULL,
                is_selected TINYINT(1) NOT NULL DEFAULT 0,
                uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY stored_name (stored_name),
                KEY idx_rpps_selected (is_selected)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
};
