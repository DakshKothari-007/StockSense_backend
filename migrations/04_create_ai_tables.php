<?php

/**
 * Migration 04: Create ai_usage and ai_cache tables for Dukaan Mitra AI Assistant.
 * 
 * Tables created:
 *  - ai_usage: Tracks per-shop daily question counts for rate limiting.
 *  - ai_cache: Caches identical queries, explanations, and daily summaries.
 */

require_once __DIR__ . "/../config/db.php";

echo "Running Migration 04: Create AI usage and cache tables...\n";

try {
    // 1. Table: ai_usage
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ai_usage` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `shop_id` INT UNSIGNED NOT NULL,
            `usage_date` DATE NOT NULL,
            `request_count` INT UNSIGNED NOT NULL DEFAULT 1,
            `last_request_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_shop_day` (`shop_id`, `usage_date`),
            KEY `idx_shop_last_req` (`shop_id`, `last_request_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo " - Table 'ai_usage' verified/created.\n";

    // 2. Table: ai_cache
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ai_cache` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `shop_id` INT UNSIGNED NOT NULL,
            `cache_key` VARCHAR(191) NOT NULL,
            `cache_type` VARCHAR(32) NOT NULL DEFAULT 'chat',
            `response_json` MEDIUMTEXT NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `expires_at` DATETIME NOT NULL,
            UNIQUE KEY `unique_shop_cache` (`shop_id`, `cache_key`),
            KEY `idx_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo " - Table 'ai_cache' verified/created.\n";

    echo "Migration 04 completed successfully.\n";

} catch (PDOException $e) {
    echo "Migration 04 failed: " . $e->getMessage() . "\n";
    exit(1);
}

