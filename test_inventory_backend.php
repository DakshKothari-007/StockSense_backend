<?php

/**
 * Automated Test Suite for StockSense Inventory Module
 * Specifically verifies:
 * 1. Empty or omitted SKU -> server auto-generates stable SKU: SKU-{PREFIX}-{HASH}.
 * 2. Supplied SKU kept -> trimmed client SKU is saved and returned.
 * 3. Duplicate in same shop -> returns 409: "A product with this SKU already exists."
 * 4. Same SKU in another shop -> succeeds with 201 Created (scoped by shop owner ID).
 * 5. Long product name -> produces valid 3-4 char prefix in SKU-{PREFIX}-{HASH}.
 * 6. Name with only symbols -> falls back to GEN prefix in SKU-GEN-{HASH}.
 * 7. Duplicate auto-generated SKU -> server regenerates unique hash within 10 attempts.
 * 8. Delete product with sales -> returns 400 Bad Request with exact message:
 *    "This product appears in N sales bills, so it cannot be deleted."
 * 9. Delete product without sales -> returns 200 OK.
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
    // curl_close is deprecated in PHP 8.5+ as handle is automatically freed

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

// 0. Setup and Login Shop 2 (Ananya) and Shop 3 (Daksh)
$defaultHash = password_hash('password123', PASSWORD_DEFAULT);
$pdo->prepare("UPDATE owner_account SET password = ? WHERE id = 2")->execute([$defaultHash]);
$pdo->prepare("UPDATE owner_account SET password = ? WHERE id = 3")->execute([$defaultHash]);

$login2 = apiCall("$baseUrl/auth/login.php", 'POST', ["email" => "ananya@example.com", "password" => "password123"]);
$token2 = $login2['json']['token'] ?? null;
assertCheck("0a. Login Shop 2 successful", $login2['code'] === 200 && !empty($token2));
$authHeader2 = ["Authorization: Bearer $token2"];

$login3 = apiCall("$baseUrl/auth/login.php", 'POST', ["email" => "daksh1@gmail.com", "password" => "password123"]);
$token3 = $login3['json']['token'] ?? null;
assertCheck("0b. Login Shop 3 successful", $login3['code'] === 200 && !empty($token3));
$authHeader3 = ["Authorization: Bearer $token3"];

// Clean up any existing test products from previous test runs (skipping any linked to sales)
$pdo->exec("DELETE FROM products WHERE id NOT IN (SELECT product_id FROM sales_items) AND (sku LIKE 'TEST-%' OR sku LIKE 'SKU-%' OR name LIKE 'Auto Sku Product%' OR name LIKE 'Super Extra Long%' OR name LIKE '@#$%^&*()%' OR name LIKE 'Clean Delete Product%')");

$createdProductIds = [];

// 1. Test: Empty SKU & Omitted SKU -> Server generates SKU in SKU-{PREFIX}-{HASH} format
$resEmptySku = apiCall("$baseUrl/inventory/add_product.php", 'POST', [
    "name" => "Auto Sku Product Alpha",
    "sku" => "",
    "category" => "Staples",
    "price" => 120.00,
    "cost_price" => 100.00,
    "units" => 40,
    "min_stock_threshold" => 10
], $authHeader2);

assertCheck("1a. Add product with empty SKU returns 201 Created", $resEmptySku['code'] === 201, "Body: {$resEmptySku['body']}");
$generatedSku1 = $resEmptySku['json']['data']['sku'] ?? '';
$createdProductIds[] = (int)($resEmptySku['json']['data']['id'] ?? 0);
assertCheck("1b. SKU matches format SKU-{PREFIX}-{HASH} (3-4 char prefix, 5 char hash)", 
    (bool)preg_match('/^SKU-[A-Z0-9]{3,4}-[A-Z0-9]{5}$/', $generatedSku1), 
    "Got SKU: $generatedSku1"
);
assertCheck("1c. Category or name prefix correctly reflected", str_starts_with($generatedSku1, 'SKU-STAP-') || str_starts_with($generatedSku1, 'SKU-AUTO-'), "Got SKU: $generatedSku1");

// 2. Test: Supplied SKU kept (trimmed)
$resSuppliedSku = apiCall("$baseUrl/inventory/add_product.php", 'POST', [
    "name" => "Supplied SKU Product",
    "sku" => "  TEST-CUSTOM-999  ",
    "category" => "Beverages",
    "price" => 55.00,
    "cost_price" => 45.00,
    "units" => 25,
    "min_stock_threshold" => 5
], $authHeader2);

assertCheck("2a. Add product with supplied SKU returns 201 Created", $resSuppliedSku['code'] === 201, "Body: {$resSuppliedSku['body']}");
$suppliedSkuReturned = $resSuppliedSku['json']['data']['sku'] ?? '';
$createdProductIds[] = (int)($resSuppliedSku['json']['data']['id'] ?? 0);
assertCheck("2b. Supplied SKU is kept and trimmed", $suppliedSkuReturned === "TEST-CUSTOM-999", "Got SKU: $suppliedSkuReturned");

// 3. Test: Duplicate SKU in same shop -> returns 409: "A product with this SKU already exists."
$resDupSameShop = apiCall("$baseUrl/inventory/add_product.php", 'POST', [
    "name" => "Duplicate SKU Product Same Shop",
    "sku" => "TEST-CUSTOM-999",
    "category" => "Snacks",
    "price" => 60.00,
    "cost_price" => 50.00,
    "units" => 10,
    "min_stock_threshold" => 5
], $authHeader2);

assertCheck("3a. Duplicate SKU in same shop returns 409 Conflict", $resDupSameShop['code'] === 409, "Body: {$resDupSameShop['body']}");
assertCheck("3b. Exact error message returned", ($resDupSameShop['json']['message'] ?? '') === "A product with this SKU already exists.", "Got message: " . ($resDupSameShop['json']['message'] ?? ''));

// 4. Test: Same SKU in another shop -> succeeds with 201 Created (scoped by shop owner ID)
$resSameSkuOtherShop = apiCall("$baseUrl/inventory/add_product.php", 'POST', [
    "name" => "Shop 3 Product With Same SKU",
    "sku" => "TEST-CUSTOM-999",
    "category" => "Beverages",
    "price" => 58.00,
    "cost_price" => 48.00,
    "units" => 30,
    "min_stock_threshold" => 5
], $authHeader3);

assertCheck("4a. Same SKU in another shop returns 201 Created", $resSameSkuOtherShop['code'] === 201, "Body: {$resSameSkuOtherShop['body']}");
$createdProductIds[] = (int)($resSameSkuOtherShop['json']['data']['id'] ?? 0);
assertCheck("4b. Returned SKU matches supplied SKU in another shop", ($resSameSkuOtherShop['json']['data']['sku'] ?? '') === "TEST-CUSTOM-999");

// 5. Test: Long product name -> generates valid 3-4 char prefix
$longName = "Super Extra Long Product Name That Has Many Words In Order To Test Long Name Truncation Safely";
$resLongName = apiCall("$baseUrl/inventory/add_product.php", 'POST', [
    "name" => $longName,
    "sku" => "",
    "category" => "General",
    "price" => 250.00,
    "cost_price" => 200.00,
    "units" => 15,
    "min_stock_threshold" => 5
], $authHeader2);

assertCheck("5a. Long product name returns 201 Created", $resLongName['code'] === 201, "Body: {$resLongName['body']}");
$longNameSku = $resLongName['json']['data']['sku'] ?? '';
$createdProductIds[] = (int)($resLongName['json']['data']['id'] ?? 0);
assertCheck("5b. Long name prefix is 3-4 characters (SKU-SUPE-XXXXX)", 
    (bool)preg_match('/^SKU-[A-Z0-9]{3,4}-[A-Z0-9]{5}$/', $longNameSku) && str_starts_with($longNameSku, 'SKU-SUPE-'),
    "Got SKU: $longNameSku"
);

// 6. Test: Name with only symbols -> falls back to GEN prefix
$resSymbolsOnly = apiCall("$baseUrl/inventory/add_product.php", 'POST', [
    "name" => "@#$%^&*()++==!!",
    "sku" => "",
    "category" => "???",
    "price" => 99.00,
    "cost_price" => 80.00,
    "units" => 5,
    "min_stock_threshold" => 2
], $authHeader2);

assertCheck("6a. Name with only symbols returns 201 Created", $resSymbolsOnly['code'] === 201, "Body: {$resSymbolsOnly['body']}");
$symbolsSku = $resSymbolsOnly['json']['data']['sku'] ?? '';
$createdProductIds[] = (int)($resSymbolsOnly['json']['data']['id'] ?? 0);
assertCheck("6b. Symbols-only name falls back to GEN prefix (SKU-GEN-XXXXX)", 
    (bool)preg_match('/^SKU-GEN-[A-Z0-9]{5}$/', $symbolsSku),
    "Got SKU: $symbolsSku"
);

// 7. Test: Collision handling & regeneration for auto-generated SKU (same name/category in same shop)
$resCollision = apiCall("$baseUrl/inventory/add_product.php", 'POST', [
    "name" => "Auto Sku Product Alpha", // Same name and category as test 1
    "sku" => "",
    "category" => "Staples",
    "price" => 135.00,
    "cost_price" => 110.00,
    "units" => 10,
    "min_stock_threshold" => 5
], $authHeader2);

assertCheck("7a. Second product with same name auto-regenerates unique SKU and returns 201", $resCollision['code'] === 201, "Body: {$resCollision['body']}");
$regeneratedSku = $resCollision['json']['data']['sku'] ?? '';
$createdProductIds[] = (int)($resCollision['json']['data']['id'] ?? 0);
assertCheck("7b. Regenerated SKU is distinct from first product SKU", $regeneratedSku !== $generatedSku1 && !empty($regeneratedSku), "Regenerated: $regeneratedSku, First: $generatedSku1");
assertCheck("7c. Regenerated SKU matches format SKU-{PREFIX}-{HASH}", (bool)preg_match('/^SKU-[A-Z0-9]{3,4}-[A-Z0-9]{5}$/', $regeneratedSku));

// 8. Test: Delete product with sales returns exact message without archiving mention
$salesCountQuery = $pdo->query("SELECT COUNT(*) FROM sales_items WHERE product_id = 1");
$salesCount = (int)$salesCountQuery->fetchColumn();

if ($salesCount > 0) {
    $resDeleteWithSales = apiCall("$baseUrl/inventory/delete_product.php", 'POST', [
        "id" => 1
    ], $authHeader2);

    assertCheck("8a. Delete product with sales returns 400 Bad Request", $resDeleteWithSales['code'] === 400);
    $expectedMsg = "This product appears in {$salesCount} sales bills, so it cannot be deleted.";
    assertCheck("8b. Error message matches exact phrasing", ($resDeleteWithSales['json']['message'] ?? '') === $expectedMsg, "Got: " . ($resDeleteWithSales['json']['message'] ?? ''));
    assertCheck("8c. No mention of archiving in message or data", 
        !str_contains(strtolower($resDeleteWithSales['json']['message'] ?? ''), 'archive') &&
        !isset($resDeleteWithSales['json']['data']['suggested_action'])
    );
} else {
    echo " [SKIP] Product 1 has no sales in database, skipping test 8\n";
}

// 9. Test: Delete product without sales succeeds (200 OK)
$resCleanProd = apiCall("$baseUrl/inventory/add_product.php", 'POST', [
    "name" => "Clean Delete Product",
    "sku" => "TEST-DELETE-CLEAN",
    "category" => "General",
    "price" => 10.00,
    "cost_price" => 8.00,
    "units" => 10,
    "min_stock_threshold" => 5
], $authHeader2);
$cleanProdId = (int)($resCleanProd['json']['data']['id'] ?? 0);

$resDeleteClean = apiCall("$baseUrl/inventory/delete_product.php", 'POST', [
    "id" => $cleanProdId
], $authHeader2);
assertCheck("9a. Delete product without sales returns 200 OK", $resDeleteClean['code'] === 200, "Body: {$resDeleteClean['body']}");

// Clean up created test products
if (!empty($createdProductIds)) {
    $placeholders = implode(',', array_fill(0, count($createdProductIds), '?'));
    $pdo->prepare("DELETE FROM products WHERE id IN ($placeholders)")->execute($createdProductIds);
}

echo "\n========================================================\n";
echo "   RESULT: {$passCount} / " . ($passCount + $failCount) . " TESTS PASSED\n";
echo "========================================================\n";

if ($failCount > 0) {
    exit(1);
}
