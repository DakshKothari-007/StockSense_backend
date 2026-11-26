<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI execution only.\n";
    exit(1);
}

/**
 * Migration 07: Add nullable column gstin (VARCHAR 15) to owner_account.
 * 
 * Idempotent: Fully safe to run multiple times without errors.
 */

require_once __DIR__ . "/../config/db.php";

echo "Running Migration 07: Add gstin to owner_account...\n";

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM `owner_account` LIKE 'gstin'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE `owner_account` ADD COLUMN `gstin` VARCHAR(15) NULL DEFAULT NULL AFTER `shop_name`");
        echo " - Column 'gstin' added to 'owner_account'.\n";
    } else {
        echo " - Column 'gstin' already exists in 'owner_account'.\n";
    }

    echo "Migration 07 completed successfully.\n";

} catch (PDOException $e) {
    echo "Migration 07 failed: " . $e->getMessage() . "\n";
    exit(1);
}
