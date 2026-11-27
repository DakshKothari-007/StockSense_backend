<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI execution only.\n";
    exit(1);
}

/**
 * Migration 09: Create bundle_offers table for accepted product-pair recommendations.
 *
 * Idempotent: Safe to run multiple times without modifying existing data.
 */

require_once __DIR__ . "/../config/db.php";

echo "Running Migration 09: Create bundle_offers table...\n";

try {
    $sql = "
        CREATE TABLE IF NOT EXISTS `bundle_offers` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `shop_owner_id` int(10) UNSIGNED NOT NULL,
            `product_id_1` int(10) UNSIGNED NOT NULL,
            `product_id_2` int(10) UNSIGNED NOT NULL,
            `offer_price` decimal(10,2) NOT NULL,
            `combined_price` decimal(10,2) NOT NULL,
            `status` enum('active','ended') NOT NULL DEFAULT 'active',
            `active_lock` tinyint(1) GENERATED ALWAYS AS (IF(`status` = 'active', 1, NULL)) VIRTUAL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `ended_at` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_active_bundle_offer` (`shop_owner_id`, `product_id_1`, `product_id_2`, `active_lock`),
            KEY `idx_bundle_offers_shop_status` (`shop_owner_id`, `status`),
            KEY `idx_bundle_offers_p1` (`product_id_1`),
            KEY `idx_bundle_offers_p2` (`product_id_2`),
            CONSTRAINT `fk_bundle_offers_owner` FOREIGN KEY (`shop_owner_id`) REFERENCES `owner_account` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_bundle_offers_p1` FOREIGN KEY (`product_id_1`) REFERENCES `products` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_bundle_offers_p2` FOREIGN KEY (`product_id_2`) REFERENCES `products` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    $pdo->exec($sql);
    echo " - Table 'bundle_offers' verified/created.\n";
    echo "Migration 09 completed successfully.\n";
} catch (PDOException $e) {
    echo "Migration 09 failed: " . $e->getMessage() . "\n";
    exit(1);
}
