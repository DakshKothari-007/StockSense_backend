<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI execution only.\n";
    exit(1);
}

/**
 * Migration 10: Add bundle_discount and applied_offers_json to sales table.
 *
 * Idempotent: Safe to run multiple times without modifying existing data.
 */

require_once __DIR__ . "/../config/db.php";

echo "Running Migration 10: Add bundle_discount columns to sales...\n";

try {
    $columns = [
        "bundle_discount" => "ALTER TABLE `sales` ADD COLUMN `bundle_discount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `total_amount`",
        "applied_offers_json" => "ALTER TABLE `sales` ADD COLUMN `applied_offers_json` TEXT NULL DEFAULT NULL AFTER `bundle_discount`"
    ];

    foreach ($columns as $colName => $alterSql) {
        $stmt = $pdo->query("SHOW COLUMNS FROM `sales` LIKE '{$colName}'");
        if (!$stmt->fetch()) {
            $pdo->exec($alterSql);
            echo " - Column '{$colName}' added to 'sales'.\n";
        } else {
            echo " - Column '{$colName}' already exists in 'sales'.\n";
        }
    }

    echo "Migration 10 completed successfully.\n";
} catch (PDOException $e) {
    echo "Migration 10 failed: " . $e->getMessage() . "\n";
    exit(1);
}
