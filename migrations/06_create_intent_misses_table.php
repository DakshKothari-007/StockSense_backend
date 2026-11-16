<?php

/**
 * Migration 06: Create intent_misses table for AI learning loop.
 * 
 * Logs low-confidence or unmatched queries (message text only, shop ID hashed,
 * no personal data) to continuously improve concept synonym groups.
 * 
 * Safe to run multiple times (idempotent).
 */

require_once __DIR__ . "/../config/db.php";

echo "Running Migration 06: Create intent_misses table...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `intent_misses` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `shop_hash` CHAR(64) NOT NULL,
            `message_text` VARCHAR(500) NOT NULL,
            `normalized_text` VARCHAR(500) NOT NULL,
            `chosen_intent` VARCHAR(64) NOT NULL,
            `confidence` DECIMAL(4,3) NOT NULL,
            `layer_resolved` VARCHAR(16) NOT NULL DEFAULT 'layer_b',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_intent_conf` (`chosen_intent`, `confidence`),
            KEY `idx_normalized_text` (`normalized_text`(191)),
            KEY `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo " - Table 'intent_misses' verified/created.\n";
    echo "Migration 06 completed successfully.\n";

} catch (PDOException $e) {
    echo "Migration 06 failed: " . $e->getMessage() . "\n";
    exit(1);
}
