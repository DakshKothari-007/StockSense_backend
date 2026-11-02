<?php
// test_sale_without_sale_date.php
// Verifies that a sale created without sale_date defaults to current Asia/Kolkata time within 1 minute of now.

date_default_timezone_set('Asia/Kolkata');
require_once __DIR__ . '/config/db.php';

echo "=== TEST: CREATE SALE WITHOUT sale_date ===\n";

// 1. Get an existing owner and product
$prodStmt = $pdo->query("SELECT id, shop_owner_id, sku, units, price FROM products WHERE units >= 5 LIMIT 1");
$prod = $prodStmt->fetch(PDO::FETCH_ASSOC);

if (!$prod) {
    die("Error: No product with >= 5 units found in database.\n");
}

$ownerId = (int)$prod['shop_owner_id'];
$prodId = (int)$prod['id'];
$sku = $prod['sku'];
$initialUnits = (int)$prod['units'];

// 2. Generate token
$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
$tokenStmt = $pdo->prepare("INSERT INTO tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
$tokenStmt->execute([$ownerId, $tokenHash]);
$tokenId = (int)$pdo->lastInsertId();

// 3. Create sale without sale_date
$billNo = "BILL-NO-DATE-" . time();
$payload = json_encode([
    "bill_no" => $billNo,
    "notes" => "Automated test for missing sale_date",
    "items" => [
        [
            "sku" => $sku,
            "quantity" => 1
        ]
    ]
]);

$ch = curl_init("http://localhost/StockSense_backend/sales/create.php");
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

echo "HTTP Code: {$httpCode}\n";
$data = json_decode($response, true);
echo "Response: " . json_encode($data) . "\n";

$pass = true;

if ($httpCode !== 201 || empty($data['status'])) {
    echo "[FAIL] Sale creation failed!\n";
    $pass = false;
}

$saleId = (int)($data['data']['sale_id'] ?? 0);
$returnedDate = $data['data']['sale_date'] ?? null;

// 4. Query DB for stored sale_date
$saleQuery = $pdo->prepare("SELECT sale_date FROM sales WHERE id = ?");
$saleQuery->execute([$saleId]);
$storedDate = $saleQuery->fetchColumn();

echo "Stored sale_date in DB: '{$storedDate}'\n";
echo "Current server time:     '" . date('Y-m-d H:i:s') . "'\n";

if (!$storedDate) {
    echo "[FAIL] Sale not found in DB!\n";
    $pass = false;
} else {
    $now = time();
    $saleTs = strtotime($storedDate);
    $diff = abs($now - $saleTs);
    echo "Time difference in seconds: {$diff}s\n";

    if ($diff <= 60) {
        echo "[PASS] Stored sale_date is within 1 minute of current time (diff: {$diff}s).\n";
    } else {
        echo "[FAIL] Stored sale_date is more than 60s away from now (diff: {$diff}s)!\n";
        $pass = false;
    }
}

// 5. Cleanup test data
echo "\nCleaning up test records...\n";
if ($saleId > 0) {
    $pdo->prepare("DELETE FROM sales_items WHERE sale_id = ?")->execute([$saleId]);
    $pdo->prepare("DELETE FROM sales WHERE id = ?")->execute([$saleId]);
}
// Restore product units (+1)
$pdo->prepare("UPDATE products SET units = ? WHERE id = ?")->execute([$initialUnits, $prodId]);
// Delete token
$pdo->prepare("DELETE FROM tokens WHERE id = ?")->execute([$tokenId]);

// Verify cleanup
$checkUnits = (int)$pdo->query("SELECT units FROM products WHERE id = {$prodId}")->fetchColumn();
if ($checkUnits === $initialUnits) {
    echo "[PASS] Product stock restored to {$initialUnits} units.\n";
} else {
    echo "[FAIL] Product stock mismatch! Expected {$initialUnits}, got {$checkUnits}.\n";
    $pass = false;
}

if ($pass) {
    echo "=== TEST PASSED SUCCESSFULLY ===\n";
    exit(0);
} else {
    echo "=== TEST FAILED ===\n";
    exit(1);
}

