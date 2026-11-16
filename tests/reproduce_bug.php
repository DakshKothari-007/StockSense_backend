<?php
// reproduce_bug.php

require_once __DIR__ . '/config/db.php';

echo "=== REPRODUCING IMPORT BUG FOR 3_existing_sku.csv ===\n";

$ownerId = 23;

// Ensure DAI-001 exists for shop 23
$checkDai = $pdo->prepare("SELECT id, units, cost_price, price, category, min_stock_threshold FROM products WHERE shop_owner_id = ? AND sku = 'DAI-001'");
$checkDai->execute([$ownerId]);
$dai = $checkDai->fetch(PDO::FETCH_ASSOC);
echo "Initial DAI-001 in DB for shop {$ownerId}: " . json_encode($dai) . "\n";

// Create auth token
$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
$tokenStmt = $pdo->prepare("INSERT INTO tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
$tokenStmt->execute([$ownerId, $tokenHash]);
$tokenId = $pdo->lastInsertId();

// Create the temporary file with 3_existing_sku.csv content
$tempToken = "imp_{$ownerId}_3_existing_sku_" . time() . ".csv";
$tempFilePath = __DIR__ . "/uploads/temp/" . $tempToken;
$csvContent = "Product Name,SKU,Category,Quantity,Cost Price,Selling Price,Minimum Stock\n" .
              "Amul Milk - 500 ml,DAI-001,Dairy,10,25,30,24\n" .
              "Test New Item,TST-009,Snacks,12,15,20,3\n";
file_put_contents($tempFilePath, $csvContent);

echo "Created test upload file: {$tempFilePath}\n";

// Execute request payload
$payload = json_encode([
    'file_token' => $tempToken,
    'file_name' => '3_existing_sku.csv',
    'idempotency_key' => 'idem_repro_' . time(),
    'column_mapping' => [
        'name' => 'Product Name',
        'sku' => 'SKU',
        'category' => 'Category',
        'units' => 'Quantity',
        'cost_price' => 'Cost Price',
        'price' => 'Selling Price',
        'minimum_stock' => 'Minimum Stock'
    ]
]);

$ch = curl_init("http://localhost/StockSense_backend/import/execute.php");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer {$rawToken}",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "\n--- HTTP RESPONSE FROM execute.php ---\n";
echo "HTTP Status Code: {$httpCode}\n";
echo "Response Body: {$response}\n";

// Direct PDO execution test to capture exact Exception details
echo "\n--- DIRECT ISOLATED REPRODUCTION OF THE SQL EXECUTION ---\n";
try {
    $unitsUpdateExpr = "units + :units";
    $sql = "
        UPDATE products
        SET units = {$unitsUpdateExpr},
            cost_price = :cost_price,
            price = :price,
            name = :name,
            category = CASE WHEN :category != '' THEN :category ELSE category END,
            min_stock_threshold = CASE WHEN :min_stock_threshold > 0 THEN :min_stock_threshold ELSE min_stock_threshold END,
            updated_at = CURRENT_TIMESTAMP
        WHERE shop_owner_id = :shop_owner_id AND sku = :sku
    ";
    
    $updateStmt = $pdo->prepare($sql);
    $params = [
        ':units' => 10,
        ':cost_price' => 25.0,
        ':price' => 30.0,
        ':name' => 'Amul Milk - 500 ml',
        ':category' => 'Dairy',
        ':min_stock_threshold' => 24,
        ':shop_owner_id' => $ownerId,
        ':sku' => 'DAI-001'
    ];
    $updateStmt->execute($params);
    echo "Direct execution succeeded unexpectedly.\n";
} catch (PDOException $e) {
    echo "EXACT EXCEPTION CAUGHT:\n";
    echo "Class: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "Code: " . $e->getCode() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "Failing SQL:\n" . $sql . "\n";
    echo "Bound Parameters (" . count($params) . " items):\n";
    print_r($params);
}

// Cleanup token and temp file
$pdo->prepare("DELETE FROM tokens WHERE id = ?")->execute([$tokenId]);
if (file_exists($tempFilePath)) @unlink($tempFilePath);

echo "\n=== REPRODUCTION SCRIPT END ===\n";

