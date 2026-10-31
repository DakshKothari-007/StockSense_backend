<?php

require_once __DIR__ . "/../config/db.php";

echo "Running migration: 01_create_import_mappings_table.php...\n";

try {
    $sql = "
        CREATE TABLE IF NOT EXISTS import_mappings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            shop_owner_id INT UNSIGNED NOT NULL,
            headers_hash VARCHAR(64) NOT NULL,
            raw_headers TEXT NOT NULL,
            mapping JSON NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_owner_headers (shop_owner_id, headers_hash),
            KEY idx_owner (shop_owner_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    $pdo->exec($sql);
    echo "[SUCCESS] Table 'import_mappings' created or already exists.\n";
} catch (Throwable $e) {
    echo "[ERROR] Failed to run migration: " . $e->getMessage() . "\n";
    exit(1);
}
