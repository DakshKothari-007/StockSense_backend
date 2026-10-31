<?php
// test_import_idempotency.php - Verify idempotency and double-import prevention

require_once __DIR__ . '/config/db.php';

echo "=== Running Idempotency Key Verification Test ===\n";

// 1. Get an existing owner account and create a valid bearer token in `tokens`
$ownerStmt = $pdo->query("SELECT id, email, full_name, shop_name FROM owner_account LIMIT 1");
$owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);
if (!$owner) {
    die("Error: No owner account found in database.\n");
}
$ownerId = (int)$owner['id'];
$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);

$tokenStmt = $pdo->prepare("INSERT INTO tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
$tokenStmt->execute([$ownerId, $tokenHash]);
$tokenId = $pdo->lastInsertId();

$headers = [
    "Authorization: Bearer {$rawToken}",
    "Content-Type: application/json"
];

// 2. Create a test CSV file in uploads/temp
$tempToken = "imp_{$ownerId}_test_idem_" . time() . ".csv";
$tempFilePath = __DIR__ . "/uploads/temp/" . $tempToken;
$testSku = "IDEM-TEST-" . time();
$csvContent = "Product Name,SKU,Quantity,Cost Price,Selling Price,Category\nIdempotency Test Item,{$testSku},25,100,150,Test Category\n";
file_put_contents($tempFilePath, $csvContent);

$idempotencyKey = "idem_key_" . uniqid() . "_" . time();

echo "1. Uploaded mock sheet with SKU '{$testSku}' and 25 units. Idempotency Key: {$idempotencyKey}\n";

// 3. First Execute Call
$payload = json_encode([
    'file_token' => $tempToken,
    'file_name' => 'test_idem.csv',
    'idempotency_key' => $idempotencyKey,
    'column_mapping' => [
        'name' => 'Product Name',
        'sku' => 'SKU',
        'units' => 'Quantity',
        'cost_price' => 'Cost Price',
        'price' => 'Selling Price',
        'category' => 'Category'
    ]
]);

$ch = curl_init("http://localhost/StockSense_backend/import/execute.php");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ["Idempotency-Key: {$idempotencyKey}"]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res1 = curl_exec($ch);
$code1 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "First Execute HTTP Status: {$code1}\n";
$data1 = json_decode($res1, true);
if ($code1 !== 200 || empty($data1['status'])) {
    echo "First execute failed: {$res1}\n";
    exit(1);
}

$firstImportId = $data1['data']['import_id'] ?? null;
echo "First Execute Success! Import ID: {$firstImportId}, Stock Added: " . ($data1['data']['stock_added'] ?? 'N/A') . "\n";

// Check product stock in database
$prodStmt = $pdo->prepare("SELECT units FROM products WHERE shop_owner_id = ? AND sku = ?");
$prodStmt->execute([$ownerId, $testSku]);
$unitsAfterFirst = (int)$prodStmt->fetchColumn();
echo "Product units in database after 1st execute: {$unitsAfterFirst} (Expected: 25)\n";

if ($unitsAfterFirst !== 25) {
    echo "FAILED: Stock after first execute is not 25!\n";
    exit(1);
}

// 4. Second Execute Call with identical Idempotency Key (Note: temp file was deleted by first execute!)
echo "\n2. Sending Second Execute Call with the SAME Idempotency Key...\n";

$ch = curl_init("http://localhost/StockSense_backend/import/execute.php");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ["Idempotency-Key: {$idempotencyKey}"]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res2 = curl_exec($ch);
$code2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Second Execute HTTP Status: {$code2}\n";
$data2 = json_decode($res2, true);

if ($code2 !== 200 || empty($data2['status'])) {
    echo "FAILED: Second execute should have returned 200 OK replay, but got: {$res2}\n";
    exit(1);
}

$secondImportId = $data2['data']['import_id'] ?? null;
echo "Second Execute returned Import ID: {$secondImportId} (Matches first: " . ($firstImportId === $secondImportId ? "YES" : "NO") . ")\n";

// Check product stock in database again
$prodStmt->execute([$ownerId, $testSku]);
$unitsAfterSecond = (int)$prodStmt->fetchColumn();
echo "Product units in database after 2nd execute: {$unitsAfterSecond} (Expected: 25, NOT 50)\n";

if ($unitsAfterSecond !== 25) {
    echo "FAILED: Double-import occurred! Stock was incremented again to {$unitsAfterSecond}\n";
    exit(1);
}

if ($firstImportId !== $secondImportId) {
    echo "FAILED: Returned import IDs do not match!\n";
    exit(1);
}

echo "\nSUCCESS! Idempotency key successfully prevented double-import.\n";

// Clean up test data
$pdo->prepare("DELETE FROM products WHERE shop_owner_id = ? AND sku = ?")->execute([$ownerId, $testSku]);
$pdo->prepare("DELETE FROM import_history WHERE shop_owner_id = ? AND idempotency_key = ?")->execute([$ownerId, $idempotencyKey]);
$pdo->prepare("DELETE FROM tokens WHERE id = ?")->execute([$tokenId]);
if (file_exists($tempFilePath)) @unlink($tempFilePath);

echo "Test cleanup complete.\n";
