<?php

require_once __DIR__ . "/../config/db.php";

echo "Running migration: 02_add_idempotency_key_to_import_history.php...\n";

try {
    // Check if column already exists
    $stmt = $pdo->query("SHOW COLUMNS FROM import_history LIKE 'idempotency_key'");
    if (!$stmt->fetch()) {
        $sql = "
            ALTER TABLE import_history
            ADD COLUMN idempotency_key VARCHAR(64) NULL AFTER shop_owner_id,
            ADD INDEX idx_owner_idempotency (shop_owner_id, idempotency_key);
        ";
        $pdo->exec($sql);
        echo "[SUCCESS] Column 'idempotency_key' and index added to 'import_history'.\n";
    } else {
        echo "[INFO] Column 'idempotency_key' already exists in 'import_history'.\n";
    }
} catch (Throwable $e) {
    echo "[ERROR] Failed to run migration: " . $e->getMessage() . "\n";
    exit(1);
}
