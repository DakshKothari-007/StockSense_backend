<?php

require_once __DIR__ . "/../config/db.php";

echo "Running migration: 03_create_device_tokens_table.php...\n";

try {
    // 1. Table: device_tokens
    $sqlDeviceTokens = "
        CREATE TABLE IF NOT EXISTS device_tokens (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            shop_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            fcm_token VARCHAR(255) NOT NULL,
            platform VARCHAR(30) NOT NULL DEFAULT 'android',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_fcm_token (fcm_token),
            KEY idx_shop_id (shop_id),
            KEY idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlDeviceTokens);
    echo "[SUCCESS] Table 'device_tokens' created or already exists.\n";

    // 2. Table: push_log
    $sqlPushLog = "
        CREATE TABLE IF NOT EXISTS push_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            shop_id INT UNSIGNED NOT NULL,
            dedupe_key VARCHAR(120) NOT NULL,
            sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_shop_dedupe (shop_id, dedupe_key),
            KEY idx_shop_id (shop_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlPushLog);
    echo "[SUCCESS] Table 'push_log' created or already exists.\n";

} catch (Throwable $e) {
    echo "[ERROR] Failed to run migration: " . $e->getMessage() . "\n";
    exit(1);
}

