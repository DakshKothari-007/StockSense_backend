<?php
require_once __DIR__ . '/config/db.php';

$pdo->prepare("UPDATE products SET units = 51, cost_price = 27.00, price = 30.00, category = 'General', min_stock_threshold = 10 WHERE shop_owner_id = 23 AND sku = 'DAI-001'")->execute();
$pdo->prepare("DELETE FROM products WHERE shop_owner_id = 23 AND sku = 'TST-009'")->execute();
$pdo->prepare("DELETE FROM import_history WHERE id = 60")->execute();
echo "Cleanup done.\n";

