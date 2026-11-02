<?php
// test_import_existing_and_new_sku.php
// Regression test: Importing a file with an existing SKU and a new SKU in one transaction

require_once __DIR__ . '/config/db.php';

echo "========================================================\n";
echo "  REGRESSION TEST: EXISTING SKU + NEW SKU IN ONE FILE   \n";
echo "========================================================\n";

// 1. Locate shop owner that owns DAI-001
$daiStmt = $pdo->prepare("SELECT id, shop_owner_id, name, category, units, cost_price, price, min_stock_threshold FROM products WHERE sku = 'DAI-001' LIMIT 1");
$daiStmt->execute();
$dai = $daiStmt->fetch(PDO::FETCH_ASSOC);

if (!$dai) {
    // If no owner has DAI-001, get owner 23 or first owner and create DAI-001
    $ownerStmt = $pdo->query("SELECT id FROM owner_account LIMIT 1");
    $ownerId = (int)$ownerStmt->fetchColumn();
    $insertDai = $pdo->prepare("
        INSERT INTO products (shop_owner_id, name, sku, category, units, cost_price, price, min_stock_threshold)
        VALUES (?, 'Amul Milk - 500 ml', 'DAI-001', 'General', 50, 27.00, 30.00, 10)
    ");
    $insertDai->execute([$ownerId]);
    $daiStmt->execute();
    $dai = $daiStmt->fetch(PDO::FETCH_ASSOC);
}

$ownerId = (int)$dai['shop_owner_id'];
$origUnits = (int)$dai['units'];
$origName = $dai['name'];
$origCategory = $dai['category'];
$origCost = (float)$dai['cost_price'];
$origPrice = (float)$dai['price'];
$origMinStock = (int)$dai['min_stock_threshold'];

echo "Shop Owner ID: {$ownerId}\n";
echo "Initial DAI-001: units={$origUnits}, name='{$origName}', category='{$origCategory}', cost={$origCost}, price={$origPrice}, min_stock={$origMinStock}\n";

// Ensure test new SKU does not exist prior to test
$testNewSku = "TST-009";
$pdo->prepare("DELETE FROM products WHERE shop_owner_id = ? AND sku = ?")->execute([$ownerId, $testNewSku]);

// 2. Authenticate shop owner
$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
$tokenStmt = $pdo->prepare("INSERT INTO tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
$tokenStmt->execute([$ownerId, $tokenHash]);
$tokenId = (int)$pdo->lastInsertId();

// 3. Create test spreadsheet in uploads/temp matching 3_existing_sku.csv
$tempToken = "imp_{$ownerId}_3_existing_sku_" . time() . ".csv";
$tempFilePath = __DIR__ . "/uploads/temp/" . $tempToken;
$csvContent = "Product Name,SKU,Category,Quantity,Cost Price,Selling Price,Minimum Stock\n" .
              "Amul Milk - 500 ml,DAI-001,Dairy,10,25,30,24\n" .
              "Test New Item,TST-009,Snacks,12,15,20,3\n";
file_put_contents($tempFilePath, $csvContent);

$idempotencyKey = "idem_test_" . uniqid() . "_" . time();

// 4. Call execute.php
$payload = json_encode([
    'file_token' => $tempToken,
    'file_name' => '3_existing_sku.csv',
    'idempotency_key' => $idempotencyKey,
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
    "Content-Type: application/json",
    "Idempotency-Key: {$idempotencyKey}"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "execute.php HTTP Code: {$httpCode}\n";
$data = json_decode($response, true);
echo "Response message: " . ($data['message'] ?? 'None') . "\n";

$pass = true;

// Verify HTTP 200 and JSON response
if ($httpCode !== 200 || empty($data['status'])) {
    echo "[FAIL] execute.php did not return 200 OK: {$response}\n";
    $pass = false;
} else {
    echo "[PASS] execute.php returned HTTP 200 OK with success status.\n";
    
    $imported = $data['data'] ?? [];
    if (($imported['products_updated'] ?? 0) !== 1) {
        echo "[FAIL] Expected products_updated = 1, got: " . ($imported['products_updated'] ?? 'null') . "\n";
        $pass = false;
    } else {
        echo "[PASS] products_updated is 1 (DAI-001 updated).\n";
    }

    if (($imported['products_added'] ?? 0) !== 1) {
        echo "[FAIL] Expected products_added = 1, got: " . ($imported['products_added'] ?? 'null') . "\n";
        $pass = false;
    } else {
        echo "[PASS] products_added is 1 (TST-009 created).\n";
    }

    if (($imported['stock_added_count'] ?? 0) !== 22) {
        echo "[FAIL] Expected stock_added_count = 22, got: " . ($imported['stock_added_count'] ?? 'null') . "\n";
        $pass = false;
    } else {
        echo "[PASS] stock_added_count is 22 (10 + 12).\n";
    }
}

// 5. Verify Database updates for existing SKU DAI-001
$checkDaiStmt = $pdo->prepare("SELECT units, name, category, cost_price, price, min_stock_threshold FROM products WHERE shop_owner_id = ? AND sku = 'DAI-001'");
$checkDaiStmt->execute([$ownerId]);
$updatedDai = $checkDaiStmt->fetch(PDO::FETCH_ASSOC);

$expectedUnits = $origUnits + 10;
if ((int)$updatedDai['units'] === $expectedUnits) {
    echo "[PASS] DAI-001 stock increased by 10 from {$origUnits} to {$expectedUnits}.\n";
} else {
    echo "[FAIL] DAI-001 stock is {$updatedDai['units']}, expected {$expectedUnits}!\n";
    $pass = false;
}

if ($updatedDai['category'] === 'Dairy') {
    echo "[PASS] DAI-001 category updated to 'Dairy'.\n";
} else {
    echo "[FAIL] DAI-001 category is '{$updatedDai['category']}', expected 'Dairy'!\n";
    $pass = false;
}

if ((float)$updatedDai['cost_price'] === 25.0) {
    echo "[PASS] DAI-001 cost price updated to 25.00.\n";
} else {
    echo "[FAIL] DAI-001 cost price is {$updatedDai['cost_price']}, expected 25.00!\n";
    $pass = false;
}

if ((float)$updatedDai['price'] === 30.0) {
    echo "[PASS] DAI-001 price updated to 30.00.\n";
} else {
    echo "[FAIL] DAI-001 price is {$updatedDai['price']}, expected 30.00!\n";
    $pass = false;
}

if ((int)$updatedDai['min_stock_threshold'] === 24) {
    echo "[PASS] DAI-001 min_stock_threshold updated to 24.\n";
} else {
    echo "[FAIL] DAI-001 min_stock_threshold is {$updatedDai['min_stock_threshold']}, expected 24!\n";
    $pass = false;
}

// 6. Verify Database insert for new SKU TST-009
$checkNewStmt = $pdo->prepare("SELECT units, name, category, cost_price, price, min_stock_threshold FROM products WHERE shop_owner_id = ? AND sku = ?");
$checkNewStmt->execute([$ownerId, $testNewSku]);
$newProd = $checkNewStmt->fetch(PDO::FETCH_ASSOC);

if ($newProd && (int)$newProd['units'] === 12 && $newProd['name'] === 'Test New Item' && $newProd['category'] === 'Snacks') {
    echo "[PASS] New SKU TST-009 was inserted properly (12 units, 'Test New Item', Snacks).\n";
} else {
    echo "[FAIL] New SKU TST-009 was not found or has incorrect data: " . json_encode($newProd) . "\n";
    $pass = false;
}

// 7. Cleanup test data
echo "\nCleaning up test records...\n";
// Restore DAI-001 to original values
$restoreStmt = $pdo->prepare("
    UPDATE products
    SET units = ?, name = ?, category = ?, cost_price = ?, price = ?, min_stock_threshold = ?
    WHERE shop_owner_id = ? AND sku = 'DAI-001'
");
$restoreStmt->execute([$origUnits, $origName, $origCategory, $origCost, $origPrice, $origMinStock, $ownerId]);

// Delete new product TST-009
$pdo->prepare("DELETE FROM products WHERE shop_owner_id = ? AND sku = ?")->execute([$ownerId, $testNewSku]);

// Delete import history record
$importId = (int)($data['data']['import_id'] ?? 0);
if ($importId > 0) {
    $pdo->prepare("DELETE FROM import_history WHERE id = ?")->execute([$importId]);
}
$pdo->prepare("DELETE FROM import_history WHERE idempotency_key = ?")->execute([$idempotencyKey]);

// Delete auth token
$pdo->prepare("DELETE FROM tokens WHERE id = ?")->execute([$tokenId]);

// Delete temporary file
if (file_exists($tempFilePath)) {
    @unlink($tempFilePath);
}

// Verify cleanup
$verifyDai = $pdo->prepare("SELECT units FROM products WHERE shop_owner_id = ? AND sku = 'DAI-001'");
$verifyDai->execute([$ownerId]);
$cleanedUnits = (int)$verifyDai->fetchColumn();

$verifyNew = $pdo->prepare("SELECT COUNT(*) FROM products WHERE shop_owner_id = ? AND sku = ?");
$verifyNew->execute([$ownerId, $testNewSku]);
$newCount = (int)$verifyNew->fetchColumn();

if ($cleanedUnits === $origUnits && $newCount === 0) {
    echo "[PASS] Cleanup verified: DAI-001 restored to {$origUnits} units, TST-009 removed, tokens and history cleaned.\n";
} else {
    echo "[FAIL] Cleanup incomplete! DAI-001 units: {$cleanedUnits}, TST-009 count: {$newCount}\n";
    $pass = false;
}

echo "\n========================================================\n";
if ($pass) {
    echo "  REGRESSION TEST PASSED SUCCESSFULLY!\n";
    echo "========================================================\n";
    exit(0);
} else {
    echo "  REGRESSION TEST FAILED!\n";
    echo "========================================================\n";
    exit(1);
}

