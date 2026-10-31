<?php

/**
 * Automated Verification Script for 1_good_import.csv
 * Tests that headers:
 * "Product Name, SKU, Category, Quantity, Cost Price, Selling Price, Minimum Stock"
 * are correctly auto-detected, validated, and executed into database without
 * name being overwritten with SKU or price being overwritten with cost price.
 */

require_once __DIR__ . "/config/db.php";

$baseUrl = "http://localhost/StockSense_backend";

function apiCall($url, $method = 'GET', $body = null, $headers = [], $files = []) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);

    if (!empty($files)) {
        curl_setopt($ch, CURLOPT_POST, true);
        $postFields = $body ?: [];
        foreach ($files as $field => $filePath) {
            $postFields[$field] = new CURLFile($filePath, mime_content_type($filePath) ?: 'text/csv', basename($filePath));
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    } elseif ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        $payload = ($body !== null) ? (is_string($body) ? $body : json_encode($body)) : "{}";
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $headers[] = "Content-Type: application/json";
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code' => $code,
        'body' => $res,
        'json' => json_decode($res, true)
    ];
}

echo "========================================================\n";
echo "    TEST SUITE: 1_GOOD_IMPORT.CSV HEADER & IMPORT FIX    \n";
echo "========================================================\n\n";

$tests = 0;
$passed = 0;

function assertCheck($name, $cond, $details = '') {
    global $tests, $passed;
    $tests++;
    if ($cond) {
        echo " [PASS] $name\n";
        $passed++;
    } else {
        echo " [FAIL] $name: $details\n";
    }
}

// 1. Authenticate / Login to get valid token
$email = "ananya@example.com";
$password = "password123";

$loginRes = apiCall("$baseUrl/auth/login.php", "POST", [
    "email" => $email,
    "password" => $password
]);

$token = $loginRes['json']['token'] ?? ($loginRes['json']['data']['token'] ?? '');
assertCheck("Login succeeds", $loginRes['code'] === 200 && !empty($token), "Code: {$loginRes['code']}, Body: {$loginRes['body']}");
$authHeaders = ["Authorization: Bearer $token"];

// Get owner id for ananya
$stmt = $pdo->prepare("SELECT id FROM owner_account WHERE email = ?");
$stmt->execute([$email]);
$ownerId = (int)$stmt->fetchColumn();

// Clean up any old test products for this owner
$pdo->prepare("DELETE FROM products WHERE shop_owner_id = ? AND sku IN ('TST-001', 'TST-002')")->execute([$ownerId]);

// 2. Create 1_good_import.csv
$tempCsvPath = __DIR__ . "/uploads/temp/1_good_import.csv";
$csvContent = "Product Name, SKU, Category, Quantity, Cost Price, Selling Price, Minimum Stock\n" .
    "\"Premium Basmati Rice 5kg\", \"TST-001\", \"Grains\", \"50\", \"320.00\", \"450.00\", \"10\"\n" .
    "\"Pure Cow Ghee 1L\", \"TST-002\", \"Dairy\", \"25\", \"520.00\", \"650.00\", \"5\"\n";

file_put_contents($tempCsvPath, $csvContent);
assertCheck("Created test file 1_good_import.csv", file_exists($tempCsvPath));

// 3. Test POST /import/upload.php
$uploadRes = apiCall("$baseUrl/import/upload.php", "POST", [], $authHeaders, ['file' => $tempCsvPath]);
assertCheck("Upload endpoint returns 200", $uploadRes['code'] === 200);

$uploadData = $uploadRes['json']['data'] ?? [];
$fileToken = $uploadData['file_token'] ?? '';
$suggestedMapping = $uploadData['suggested_mapping'] ?? [];

echo " Detected Suggested Mapping:\n" . json_encode($suggestedMapping, JSON_PRETTY_PRINT) . "\n";

assertCheck("Auto-detect maps name -> 'Product Name'", ($suggestedMapping['name'] ?? '') === 'Product Name');
assertCheck("Auto-detect maps sku -> 'SKU'", ($suggestedMapping['sku'] ?? '') === 'SKU');
assertCheck("Auto-detect maps category -> 'Category'", ($suggestedMapping['category'] ?? '') === 'Category');
assertCheck("Auto-detect maps units -> 'Quantity'", ($suggestedMapping['units'] ?? '') === 'Quantity');
assertCheck("Auto-detect maps cost_price -> 'Cost Price'", ($suggestedMapping['cost_price'] ?? '') === 'Cost Price');
assertCheck("Auto-detect maps price -> 'Selling Price'", ($suggestedMapping['price'] ?? '') === 'Selling Price');
assertCheck("Auto-detect maps minimum_stock -> 'Minimum Stock'", ($suggestedMapping['minimum_stock'] ?? '') === 'Minimum Stock');

// 4. Test POST /import/preview.php with suggested mapping
$previewRes = apiCall("$baseUrl/import/preview.php", "POST", [
    "file_token" => $fileToken,
    "column_mapping" => $suggestedMapping
], $authHeaders);

assertCheck("Preview endpoint returns 200", $previewRes['code'] === 200);
$previewRows = $previewRes['json']['data']['preview_rows'] ?? [];

assertCheck("Preview returns 2 rows", count($previewRows) === 2);
$firstRow = $previewRows[0] ?? [];

assertCheck("Preview row 1 name is 'Premium Basmati Rice 5kg' (NOT SKU)", ($firstRow['name'] ?? '') === 'Premium Basmati Rice 5kg', "Got: " . ($firstRow['name'] ?? ''));
assertCheck("Preview row 1 sku is 'TST-001'", ($firstRow['sku'] ?? '') === 'TST-001');
assertCheck("Preview row 1 unitsToAdd is '50'", ($firstRow['unitsToAdd'] ?? '') === '50');
assertCheck("Preview row 1 costPrice is '₹320'", ($firstRow['costPrice'] ?? '') === '₹320');
assertCheck("Preview row 1 sellingPrice is '₹450'", ($firstRow['sellingPrice'] ?? '') === '₹450');

// 5. Test Validation Block when required field is missing
$badValidateRes = apiCall("$baseUrl/import/validate.php", "POST", [
    "file_token" => $fileToken,
    "column_mapping" => [
        "name" => null,
        "sku" => "SKU",
        "units" => "Quantity"
    ]
], $authHeaders);
assertCheck("Validation blocks import when 'name' is unmapped (HTTP 400)", $badValidateRes['code'] === 400);

// 6. Test Validating with correct mapping
$validateRes = apiCall("$baseUrl/import/validate.php", "POST", [
    "file_token" => $fileToken,
    "column_mapping" => $suggestedMapping
], $authHeaders);

assertCheck("Validation endpoint returns 200", $validateRes['code'] === 200);
assertCheck("Validation ready_to_import is true", ($validateRes['json']['data']['ready_to_import'] ?? false) === true);
assertCheck("Validation errors_remaining is 0", ($validateRes['json']['data']['errors_remaining'] ?? -1) === 0);

// 7. Test POST /import/execute.php
$executeRes = apiCall("$baseUrl/import/execute.php", "POST", [
    "file_token" => $fileToken,
    "file_name" => "1_good_import.csv",
    "column_mapping" => $suggestedMapping
], $authHeaders);

assertCheck("Execute endpoint returns 200", $executeRes['code'] === 200);
assertCheck("Execute products_added is 2", ($executeRes['json']['data']['products_added'] ?? 0) === 2);

// 8. Query database to verify values were saved correctly
$stmt = $pdo->prepare("SELECT name, sku, category, price, cost_price, units, min_stock_threshold FROM products WHERE shop_owner_id = ? AND sku = 'TST-001'");
$stmt->execute([$ownerId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

assertCheck("DB product exists", !empty($product));
assertCheck("DB product name is 'Premium Basmati Rice 5kg' (NOT SKU)", ($product['name'] ?? '') === 'Premium Basmati Rice 5kg', "Got: " . ($product['name'] ?? ''));
assertCheck("DB selling price is 450.00 (NOT cost price)", (float)($product['price'] ?? 0) == 450.00, "Got: " . ($product['price'] ?? ''));
assertCheck("DB cost price is 320.00", (float)($product['cost_price'] ?? 0) == 320.00, "Got: " . ($product['cost_price'] ?? ''));
assertCheck("DB units is 50", (int)($product['units'] ?? 0) === 50, "Got: " . ($product['units'] ?? ''));
assertCheck("DB category is 'Grains'", ($product['category'] ?? '') === 'Grains', "Got: " . ($product['category'] ?? ''));
assertCheck("DB min_stock_threshold is 10", (int)($product['min_stock_threshold'] ?? 0) === 10, "Got: " . ($product['min_stock_threshold'] ?? ''));

// Clean up
@unlink($tempCsvPath);
$pdo->prepare("DELETE FROM products WHERE shop_owner_id = ? AND sku IN ('TST-001', 'TST-002')")->execute([$ownerId]);

echo "\n========================================================\n";
echo "   RESULTS: $passed / $tests PASSED\n";
echo "========================================================\n";

if ($passed === $tests) {
    exit(0);
} else {
    exit(1);
}
