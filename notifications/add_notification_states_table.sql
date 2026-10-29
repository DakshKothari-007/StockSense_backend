-- Migration: Create notification_states table
-- Safe to run multiple times (idempotent)

CREATE TABLE IF NOT EXISTS notification_states (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_owner_id INT UNSIGNED NOT NULL,
    notification_key VARCHAR(100) NOT NULL,
    read_at DATETIME DEFAULT NULL,
    dismissed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_owner_notif (shop_owner_id, notification_key),
    INDEX idx_owner_read (shop_owner_id, read_at),
    CONSTRAINT fk_notif_owner FOREIGN KEY (shop_owner_id) REFERENCES owner_account(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
