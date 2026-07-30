CREATE DATABASE IF NOT EXISTS englai CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE englai;

CREATE TABLE IF NOT EXISTS rpps (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL UNIQUE,
    file_type ENUM('pdf', 'docx') NOT NULL,
    extracted_text LONGTEXT NOT NULL,
    is_selected TINYINT(1) NOT NULL DEFAULT 0,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rpps_selected (is_selected)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
