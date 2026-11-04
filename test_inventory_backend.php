<?php

/**
 * Automated Test Suite for StockSense Inventory Module
 * Specifically verifies:
 * 1. Missing or empty SKU -> server auto-generates stable SKU: SKU-{PREFIX}-{HASH}.
 * 2. Duplicate generated SKU -> server adds numeric suffix (-1, -2, ...) to ensure uniqueness.
 * 3. User-provided SKU that already exists -> still returns 409 Conflict duplicate error.
 * 4. Final SKU returned in response data.
 * 5. Delete product with sales -> returns 400 Bad Request with exact message:
 *    "This product appears in N sales bills, so it cannot be deleted." (no mention of archiving).
 * 6. Delete product without sales -> returns 200 OK.
 */

require_once __DIR__ . '/config/db.php';

$baseUrl = "http://localhost/StockSense_backend";

function apiCall($url, $method = 'GET', $body = null, $headers = []) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);

    if ($method === 'POST') {
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

$passCount = 0;
$failCount = 0;

function assertCheck($name, $condition, $extra = "") {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] $name\n";
        $passCount++;
    } else {
        echo "![FAIL] $name $extra\n";
        $failCount++;
    }
}

echo "========================================================\n";
echo "       STOCKSENSE INVENTORY BACKEND TEST SUITE          \n";
echo "========================================================\n\n";

// 0. Setup and Login Shop 2 (Ananya)
$defaultHash = password_hash('password123', PASSWORD_DEFAULT);
$pdo->prepare("UPDATE owner_account SET password = ? WHERE id = 2")->execute([$defaultHash]);
$login = apiCall("$baseUrl/auth/login.php", 'POST', ["email" => "ananya@example.com", "password" => "password123"]);
$token = $login['json']['token'] ?? null;
assertCheck("0. Login Shop 2 successful", $login['code'] === 200 && !empty($token));
$authHeader = ["Authorization: Bearer $token"];

// Clean up any existing test products from previous test runs (skipping any linked to sales)
$pdo->exec("DELETE FROM products WHERE shop_owner_id = 2 AND id NOT IN (SELECT product_id FROM sales_items) AND (sku LIKE 'TEST-%' OR name LIKE 'Auto Sku Product%' OR name LIKE 'Unique Sku Product%')");

// 1. Test: Add product with empty SKU -> Server auto-generates stable SKU
$resEmptySku1 = apiCall("$baseUrl/inventory/add_product.php", 'POST', [
    "name" => "Auto Sku Product Alpha",
    "sku" => "",
    "category" => "Staples",
    "price" => 120.00,
    "cost_price" => 100.00,
    "units" => 40,
    "min_stock_threshold" => 10
], $authHeader);

assertCheck("1a. Add product with empty SKU returns 201 Created", $resEmptySku1['code'] === 201, "Body: {$resEmptySku1['body']}");
$generatedSku1 = $resEmptySku1['json']['data']['sku'] ?? null;
assertCheck("1b. Returns generated stable SKU in response data", !empty($generatedSku1) && str_starts_with($generatedSku1, 'SKU-AUTO-'), "Got SKU: $generatedSku1");

// 2. Test: Add product with omitted SKU -> Server auto-generates stable SKU with duplicate numeric suffix
$resEmptySku2 = apiCall("$baseUrl/inventory/add_product.php", 'POST', [
    "name" => "Auto Sku Product Alpha", // Same name triggers the same base SKU hash
    "category" => "Staples",
    "price" => 125.00,
    "cost_price" => 105.00,
    "units" => 20,
    "min_stock_threshold" => 5
], $authHeader);

assertCheck("2a. Duplicate generated SKU returns 201 Created", $resEmptySku2['code'] === 201, "Body: {$resEmptySku2['body']}");
$generatedSku2 = $resEmptySku2['json']['data']['sku'] ?? null;
$expectedSuffix1 = $generatedSku1 . "-1";
assertCheck("2b. First duplicate generated SKU gets -1 suffix", $generatedSku2 === $expectedSuffix1, "Expected: $expectedSuffix1, Got: $generatedSku2");

// 3. Test: Add third product with the same name -> gets -2 suffix
$resEmptySku3 = apiCall("$baseUrl/inventory/add_product.php", 'POST', [
    "name" => "Auto Sku Product Alpha",
    "sku" => "   ", // whitespace only, should also be treated as empty
    "category" => "Staples",
    "price" => 130.00,
    "cost_price" => 110.00,
    "units" => 15,
    "min_stock_threshold" => 5
], $authHeader);

assertCheck("3a. Third duplicate generated SKU returns 201 Created", $resEmptySku3['code'] === 201, "Body: {$resEmptySku3['body']}");
$generatedSku3 = $resEmptySku3['json']['data']['sku'] ?? null;
$expectedSuffix2 = $generatedSku1 . "-2";
assertCheck("3b. Second duplicate generated SKU gets -2 suffix", $generatedSku3 === $expectedSuffix2, "Expected: $expectedSuffix2, Got: $generatedSku3");

// 4. Test: User-provided SKU that already exists still gives 409 Conflict
$resExplicit = apiCall("$baseUrl/inventory/add_product.php", 'POST', [
    "name" => "Explicit SKU Product",
    "sku" => "TEST-EXPLICIT-01",
    "category" => "General",
    "price" => 50.00,
    "cost_price" => 40.00,
    "units" => 30,
    "min_stock_threshold" => 5
], $authHeader);
assertCheck("4a. Add product with explicit SKU returns 201", $resExplicit['code'] === 201);
$explicitProdId = (int)($resExplicit['json']['data']['id'] ?? 0);

$resDupExplicit = apiCall("$baseUrl/inventory/add_product.php", 'POST', [
    "name" => "Different Product With Same Explicit SKU",
    "sku" => "TEST-EXPLICIT-01",
    "category" => "General",
    "price" => 60.00,
    "cost_price" => 45.00,
    "units" => 10,
    "min_stock_threshold" => 5
], $authHeader);
assertCheck("4b. User SKU that already exists returns 409 Conflict", $resDupExplicit['code'] === 409, "Body: {$resDupExplicit['body']}");
assertCheck("4c. 409 response contains duplicate SKU message", str_contains($resDupExplicit['json']['message'] ?? '', "already exists in your shop"));

// 5. Test: Delete product with sales returns exact message without archiving mention
// Product 1 has sales records from test_sales_backend.php
$salesCountQuery = $pdo->query("SELECT COUNT(*) FROM sales_items WHERE product_id = 1");
$salesCount = (int)$salesCountQuery->fetchColumn();

if ($salesCount > 0) {
    $resDeleteWithSales = apiCall("$baseUrl/inventory/delete_product.php", 'POST', [
        "id" => 1
    ], $authHeader);

    assertCheck("5a. Delete product with sales returns 400 Bad Request", $resDeleteWithSales['code'] === 400);
    $expectedMsg = "This product appears in {$salesCount} sales bills, so it cannot be deleted.";
    assertCheck("5b. Error message matches exact phrasing", ($resDeleteWithSales['json']['message'] ?? '') === $expectedMsg, "Got: " . ($resDeleteWithSales['json']['message'] ?? ''));
    assertCheck("5c. No mention of archiving in message or data", 
        !str_contains(strtolower($resDeleteWithSales['json']['message'] ?? ''), 'archive') &&
        !isset($resDeleteWithSales['json']['data']['suggested_action'])
    );
} else {
    echo " [SKIP] Product 1 has no sales in database, skipping test 5\n";
}

// 6. Test: Delete product without sales succeeds (200 OK)
$resDeleteClean = apiCall("$baseUrl/inventory/delete_product.php", 'POST', [
    "id" => $explicitProdId
], $authHeader);
assertCheck("6a. Delete product without sales returns 200 OK", $resDeleteClean['code'] === 200, "Body: {$resDeleteClean['body']}");

// Clean up generated products
$pdo->prepare("DELETE FROM products WHERE id IN (?, ?, ?)")->execute([
    (int)($resEmptySku1['json']['data']['id'] ?? 0),
    (int)($resEmptySku2['json']['data']['id'] ?? 0),
    (int)($resEmptySku3['json']['data']['id'] ?? 0)
]);

echo "\n========================================================\n";
echo "   RESULT: {$passCount} / " . ($passCount + $failCount) . " TESTS PASSED\n";
echo "========================================================\n";

if ($failCount > 0) {
    exit(1);
}
