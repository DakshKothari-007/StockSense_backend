-- Migration: Create rate_limits table for global endpoint throttling
-- Safe to run multiple times (idempotent)

CREATE TABLE IF NOT EXISTS rate_limits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(50) NOT NULL,
    identifier VARCHAR(191) NOT NULL,
    ip_address VARCHAR(45) NOT NULL DEFAULT '',
    attempted_at DATETIME NOT NULL,
    INDEX idx_action_id_time (action, identifier, attempted_at),
    INDEX idx_action_ip_time (action, ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
