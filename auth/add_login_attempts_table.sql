-- Migration: Create login_attempts table for rate limiting failed logins
-- Safe to run multiple times (idempotent)

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(191) NOT NULL,
    ip_address VARCHAR(45) NOT NULL DEFAULT '',
    attempted_at DATETIME NOT NULL,
    INDEX idx_identifier_time (identifier, attempted_at),
    INDEX idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
