<?php
require_once __DIR__ . '/config/db.php';

$stmt = $pdo->prepare("SELECT id, shop_owner_id, name, sku, category, units, cost_price, price, min_stock_threshold FROM products WHERE sku = 'DAI-001'");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "DAI-001 rows in products:\n";
print_r($rows);

