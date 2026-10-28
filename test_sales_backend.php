<?php

/**
 * Comprehensive Automated Test Suite for StockSense Sales Module
 */

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

echo "========================================================\n";
echo "       STOCKSENSE SALES BACKEND TEST SUITE              \n";
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
        echo "![FAIL] $name - $details\n";
    }
}

// 0. Login Shop 2 (Ananya) and Shop 3 (Daksh) to acquire real 64-char Bearer tokens
$login2 = apiCall("$baseUrl/auth/login.php", 'POST', ["email" => "ananya@example.com", "password" => "password123"]);
$token2 = $login2['json']['token'] ?? '';
assertCheck("0a. Login Shop 2 successful", $login2['code'] === 200 && strlen($token2) === 64, "Token2 len: " . strlen($token2));

$login3 = apiCall("$baseUrl/auth/login.php", 'POST', ["email" => "daksh1@gmail.com", "password" => "password123"]);
$token3 = $login3['json']['token'] ?? '';
assertCheck("0b. Login Shop 3 successful", $login3['code'] === 200 && strlen($token3) === 64, "Token3 len: " . strlen($token3));

require_once __DIR__ . '/config/db.php';

// Prepare a clean test product for Shop 2
$pdo->prepare("UPDATE products SET units = 100, price = 50.00 WHERE id = 1 AND shop_owner_id = 2")->execute();

// 1. Test: No token / 401 Unauthorized
$resNoAuth = apiCall("$baseUrl/sales/list.php", 'GET', null, []);
assertCheck("1. Missing token returns 401 Unauthorized", $resNoAuth['code'] === 401);

$resBadToken = apiCall("$baseUrl/sales/list.php", 'GET', null, ["Authorization: Bearer 2"]);
assertCheck("1b. Raw User ID Bearer 2 returns 401 Unauthorized", $resBadToken['code'] === 401);

// 2. Test: Valid bill creation with atomic stock reduction & ignore client unit price (Rule 1, 2)
$uniqueBill1 = "BILL-TEST-" . time() . "-01";
$createPayload = [
    "bill_no" => $uniqueBill1,
    "sale_date" => date('Y-m-d H:i:s'),
    "notes" => "Customer cash sale",
    "items" => [
        // Client specifies unit_price 10.00, but product 1 price in DB is 50.00
        ["product_id" => 1, "quantity" => 5, "unit_price" => 10.00]
    ]
];
$resCreate = apiCall("$baseUrl/sales/create.php", 'POST', $createPayload, ["Authorization: Bearer $token2"]);
assertCheck("2a. Create bill returns 201 Created", $resCreate['code'] === 201, "Body: {$resCreate['body']}");
// Server must compute total_amount using DB price (5 * 50.00 = 250.00)
$totalAmt = $resCreate['json']['data']['total_amount'] ?? 0;
assertCheck("2b. Server computed total using DB price (250.00)", $totalAmt == 250.00, "Got total: $totalAmt");

// Verify stock reduced from 100 to 95
$stockCheck = $pdo->query("SELECT units FROM products WHERE id = 1")->fetchColumn();
assertCheck("2c. Atomic stock reduced from 100 to 95", (int)$stockCheck === 95, "Stock is: $stockCheck");

// 3. Test: Merge duplicate product lines in one bill (Rule 3)
$uniqueBill2 = "BILL-TEST-" . time() . "-02";
$mergePayload = [
    "bill_no" => $uniqueBill2,
    "sale_date" => date('Y-m-d H:i:s'),
    "items" => [
        ["product_id" => 1, "quantity" => 2],
        ["product_id" => 1, "quantity" => 3]
    ]
];
$resMerge = apiCall("$baseUrl/sales/create.php", 'POST', $mergePayload, ["Authorization: Bearer $token2"]);
assertCheck("3a. Merge duplicate lines succeeds (201)", $resMerge['code'] === 201, "Body: {$resMerge['body']}");
$mergedQty = $resMerge['json']['data']['total_quantity'] ?? 0;
assertCheck("3b. Merged quantity is 5", (int)$mergedQty === 5, "Got qty: $mergedQty");
$stockCheck2 = $pdo->query("SELECT units FROM products WHERE id = 1")->fetchColumn();
assertCheck("3c. Stock reduced by merged 5 units (now 90)", (int)$stockCheck2 === 90, "Stock: $stockCheck2");

// 4. Test: Not enough stock rejects with 400 and returns requested and available (Rule 2)
$uniqueBill3 = "BILL-TEST-" . time() . "-03";
$excessivePayload = [
    "bill_no" => $uniqueBill3,
    "items" => [
        ["product_id" => 1, "quantity" => 500] // currently 90 available
    ]
];
$resExcess = apiCall("$baseUrl/sales/create.php", 'POST', $excessivePayload, ["Authorization: Bearer $token2"]);
assertCheck("4a. Insufficient stock rejected with 400", $resExcess['code'] === 400, "Body: {$resExcess['body']}");
assertCheck("4b. Response contains requested and available units", 
    isset($resExcess['json']['data']['requested_quantity']) && 
    $resExcess['json']['data']['requested_quantity'] === 500 &&
    $resExcess['json']['data']['available_units'] === 90
);
// Confirm stock remained 90 (no half deduction)
$stockCheck3 = $pdo->query("SELECT units FROM products WHERE id = 1")->fetchColumn();
assertCheck("4c. Stock untouched after rollback (still 90)", (int)$stockCheck3 === 90);

// 5. Test: Product from another shop rejects with 404
$otherShopPayload = [
    "bill_no" => "BILL-SHOP3-01",
    "items" => [
        ["product_id" => 1, "quantity" => 1] // Product 1 belongs to Shop 2
    ]
];
$resOtherShop = apiCall("$baseUrl/sales/create.php", 'POST', $otherShopPayload, ["Authorization: Bearer $token3"]);
assertCheck("5. Product from another shop rejected with 404 Not Found", $resOtherShop['code'] === 404, "Body: {$resOtherShop['body']}");

// 6. Test: Duplicate bill_no rejects with 400
$dupPayload = [
    "bill_no" => $uniqueBill1, // already used in Test 2
    "items" => [
        ["product_id" => 1, "quantity" => 1]
    ]
];
$resDup = apiCall("$baseUrl/sales/create.php", 'POST', $dupPayload, ["Authorization: Bearer $token2"]);
assertCheck("6. Duplicate bill_no rejected with 400", $resDup['code'] === 400, "Body: {$resDup['body']}");

// 7. Test: Reject future sale_date (Rule 6)
$futurePayload = [
    "bill_no" => "BILL-FUTURE-" . time(),
    "sale_date" => date('Y-m-d H:i:s', strtotime('+2 days')),
    "items" => [
        ["product_id" => 1, "quantity" => 1]
    ]
];
$resFuture = apiCall("$baseUrl/sales/create.php", 'POST', $futurePayload, ["Authorization: Bearer $token2"]);
assertCheck("7. Future sale_date rejected with 400", $resFuture['code'] === 400, "Body: {$resFuture['body']}");

// 8. Test: sales/list.php with single-query items loader (Rule 9)
$resList = apiCall("$baseUrl/sales/list.php?page=1&limit=10", 'GET', null, ["Authorization: Bearer $token2"]);
assertCheck("8a. sales/list.php returns 200", $resList['code'] === 200);
$bills = $resList['json']['data']['bills'] ?? [];
assertCheck("8b. bills list contains items array", !empty($bills) && isset($bills[0]['items']));
assertCheck("8c. Bill items loaded with name and sku", !empty($bills[0]['items'][0]['sku']));

// 9. Test: Isolation - Shop 3 cannot see Shop 2's bills
$resList3 = apiCall("$baseUrl/sales/list.php", 'GET', null, ["Authorization: Bearer $token3"]);
$billsShop3 = $resList3['json']['data']['bills'] ?? [];
assertCheck("9. Second shop isolation in sales list (Shop 3 has 0 of Shop 2's bills)", count($billsShop3) === 0);

// 10. Test: sales/summary.php aggregates metrics & hourly sales (Rule 10)
$resSummary = apiCall("$baseUrl/sales/summary.php", 'GET', null, ["Authorization: Bearer $token2"]);
assertCheck("10a. sales/summary.php returns 200", $resSummary['code'] === 200);
$totSales = $resSummary['json']['data']['total_sales_amount'] ?? 0;
assertCheck("10b. Summary shows total sales > 0", $totSales > 0, "Got: $totSales");
assertCheck("10c. Daily points generated", !empty($resSummary['json']['data']['daily_sales_points']));
assertCheck("10d. Top selling products contains Product 1", !empty($resSummary['json']['data']['top_selling_products']));
assertCheck("10e. Hourly sales today present with real timestamps", 
    isset($resSummary['json']['data']['hourly_sales_today']['has_real_timestamps']) &&
    $resSummary['json']['data']['hourly_sales_today']['has_real_timestamps'] === true
);

// 11. Test: sales/import.php all-or-nothing validation (Rule 4, 5)
$badImportPayload = [
    "rows" => [
        ["bill_no" => "IMP-001", "sku" => "DAI-001", "quantity" => 2],
        ["bill_no" => "IMP-002", "sku" => "NON-EXISTENT-SKU", "quantity" => 1] // bad row
    ]
];
$resBadImport = apiCall("$baseUrl/sales/import.php", 'POST', $badImportPayload, ["Authorization: Bearer $token2"]);
assertCheck("11a. Import with bad row rejected with 400", $resBadImport['code'] === 400);
$importErrors = $resBadImport['json']['data']['errors'] ?? [];
assertCheck("11b. Reports bad row number and reason", !empty($importErrors) && $importErrors[0]['row'] === 2);

// Check import history record for failed import
$lastHistory = $pdo->query("SELECT status, failed_count FROM import_history WHERE shop_owner_id = 2 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCheck("11c. Failed import logged in import_history table", $lastHistory['status'] === 'failed' && (int)$lastHistory['failed_count'] > 0);

// Test: Successful sales import with deduct_stock = false (default)
$goodBill = "IMP-GOOD-" . time();
$goodImportPayload = [
    "deduct_stock" => false,
    "rows" => [
        ["bill_no" => $goodBill, "sku" => "DAI-001", "quantity" => 3, "sale_date" => date('Y-m-d H:i:s')]
    ]
];
$stockBeforeGoodImport = (int)$pdo->query("SELECT units FROM products WHERE id = 1")->fetchColumn();
$resGoodImport = apiCall("$baseUrl/sales/import.php", 'POST', $goodImportPayload, ["Authorization: Bearer $token2"]);
assertCheck("11d. Successful import returns 201", $resGoodImport['code'] === 201);
$stockAfterGoodImport = (int)$pdo->query("SELECT units FROM products WHERE id = 1")->fetchColumn();
assertCheck("11e. deduct_stock = false leaves stock unchanged", $stockBeforeGoodImport === $stockAfterGoodImport);

// 12. Test: Product delete when sales exist returns clear 400 (Rule 8)
$resDelete = apiCall("$baseUrl/inventory/delete_product.php", 'POST', ["id" => 1], ["Authorization: Bearer $token2"]);
assertCheck("12. Delete product with sales rejected with 400", $resDelete['code'] === 400, "Body: {$resDelete['body']}");
assertCheck("12b. Suggests archive action", isset($resDelete['json']['data']['suggested_action']) && $resDelete['json']['data']['suggested_action'] === 'archive');

// 13. Test: dashboard/summary.php reflects real sales & slow-moving metadata (Rule 7)
$resDash = apiCall("$baseUrl/dashboard/summary.php", 'GET', null, ["Authorization: Bearer $token2"]);
assertCheck("13a. dashboard/summary.php returns 200", $resDash['code'] === 200);
$dashSalesToday = $resDash['json']['data']['sales']['today_sales_amount'] ?? 0;
assertCheck("13b. Dashboard sales today reflects real sales (> 0)", $dashSalesToday > 0, "Got: $dashSalesToday");
$dashBillsToday = $resDash['json']['data']['sales']['today_bills_count'] ?? 0;
assertCheck("13c. Dashboard today bills count > 0", $dashBillsToday > 0, "Got: $dashBillsToday");
assertCheck("13d. Dashboard top selling products populated", !empty($resDash['json']['data']['top_selling_products']));
$slowMeta = $resDash['json']['data']['alerts']['slow_moving_metadata'] ?? [];
assertCheck("13e. Slow-moving metadata contains rule and history flag", isset($slowMeta['rule']) && isset($slowMeta['has_enough_sales_history']));

echo "\n========================================================\n";
echo "   RESULT: $passed / $tests TESTS PASSED                \n";
echo "========================================================\n";
