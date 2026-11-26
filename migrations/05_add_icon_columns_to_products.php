<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI execution only.\n";
    exit(1);
}

/**
 * Migration 05: Add icon_key and icon_is_manual columns to products table.
 * 
 * Columns added:
 *  - icon_key: string identifier from shared 304 supermarket icon catalog.
 *  - icon_is_manual: flag indicating whether the icon was explicitly chosen by the store owner.
 * 
 * Idempotent: Fully safe to run multiple times without errors.
 */

require_once __DIR__ . "/../config/db.php";

echo "Running Migration 05: Add icon_key and icon_is_manual to products...\n";

try {
    // 1. Check & add icon_key column
    $stmtKey = $pdo->query("SHOW COLUMNS FROM `products` LIKE 'icon_key'");
    if (!$stmtKey->fetch()) {
        $pdo->exec("ALTER TABLE `products` ADD COLUMN `icon_key` VARCHAR(100) NULL DEFAULT NULL AFTER `icon_type`");
        echo " - Column 'icon_key' added.\n";
    } else {
        echo " - Column 'icon_key' already exists.\n";
    }

    // 2. Check & add icon_is_manual column
    $stmtManual = $pdo->query("SHOW COLUMNS FROM `products` LIKE 'icon_is_manual'");
    if (!$stmtManual->fetch()) {
        $pdo->exec("ALTER TABLE `products` ADD COLUMN `icon_is_manual` TINYINT(1) NOT NULL DEFAULT 0 AFTER `icon_key`");
        echo " - Column 'icon_is_manual' added.\n";
    } else {
        echo " - Column 'icon_is_manual' already exists.\n";
    }

    // 3. Check & add index for icon_key
    $stmtIdxKey = $pdo->query("SHOW INDEX FROM `products` WHERE Key_name = 'idx_products_icon_key'");
    if (!$stmtIdxKey->fetch()) {
        $pdo->exec("ALTER TABLE `products` ADD INDEX `idx_products_icon_key` (`icon_key`)");
        echo " - Index 'idx_products_icon_key' added.\n";
    } else {
        echo " - Index 'idx_products_icon_key' already exists.\n";
    }

    // 4. Check & add index for icon_is_manual
    $stmtIdxManual = $pdo->query("SHOW INDEX FROM `products` WHERE Key_name = 'idx_products_icon_manual'");
    if (!$stmtIdxManual->fetch()) {
        $pdo->exec("ALTER TABLE `products` ADD INDEX `idx_products_icon_manual` (`icon_is_manual`)");
        echo " - Index 'idx_products_icon_manual' added.\n";
    } else {
        echo " - Index 'idx_products_icon_manual' already exists.\n";
    }

    echo "Migration 05 completed successfully.\n";

} catch (PDOException $e) {
    echo "Migration 05 failed: " . $e->getMessage() . "\n";
    exit(1);
}
