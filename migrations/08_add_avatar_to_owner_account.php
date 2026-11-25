<?php

/**
 * Migration 08: Add nullable avatar columns (avatar_style, avatar_seed, avatar_options) to owner_account.
 *
 * Idempotent: Safe to run multiple times without modifying existing data.
 */

require_once __DIR__ . "/../config/db.php";

echo "Running Migration 08: Add avatar columns to owner_account...\n";

try {
    $columns = [
        "avatar_style" => "ALTER TABLE `owner_account` ADD COLUMN `avatar_style` VARCHAR(20) NULL DEFAULT NULL AFTER `gstin`",
        "avatar_seed" => "ALTER TABLE `owner_account` ADD COLUMN `avatar_seed` VARCHAR(64) NULL DEFAULT NULL AFTER `avatar_style`",
        "avatar_options" => "ALTER TABLE `owner_account` ADD COLUMN `avatar_options` TEXT NULL DEFAULT NULL AFTER `avatar_seed`"
    ];

    foreach ($columns as $colName => $alterSql) {
        $stmt = $pdo->query("SHOW COLUMNS FROM `owner_account` LIKE '{$colName}'");
        if (!$stmt->fetch()) {
            $pdo->exec($alterSql);
            echo " - Column '{$colName}' added to 'owner_account'.\n";
        } else {
            echo " - Column '{$colName}' already exists in 'owner_account'.\n";
        }
    }

    echo "Migration 08 completed successfully.\n";

} catch (PDOException $e) {
    echo "Migration 08 failed: " . $e->getMessage() . "\n";
    exit(1);
}
