<?php

/**
 * Automated Verification Test Suite for StockSense Reorder Backend Module
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
echo "      STOCKSENSE REORDER BACKEND TEST SUITE             \n";
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

// 0. Acquire tokens for Shop 2 (Ananya) and Shop 3 (Daksh)
$login2 = apiCall("$baseUrl/auth/login.php", 'POST', ["email" => "ananya@example.com", "password" => "password123"]);
$token2 = $login2['json']['token'] ?? '';
assertCheck("0a. Login Shop 2 returns valid token", $login2['code'] === 200 && strlen($token2) === 64);

$login3 = apiCall("$baseUrl/auth/login.php", 'POST', ["email" => "daksh1@gmail.com", "password" => "password123"]);
$token3 = $login3['json']['token'] ?? '';
assertCheck("0b. Login Shop 3 returns valid token", $login3['code'] === 200 && strlen($token3) === 64);

require_once __DIR__ . '/config/db.php';

// 1. Test: No token / expired token returns 401
$resNoAuth = apiCall("$baseUrl/reorder/list.php", 'GET', null, []);
assertCheck("1a. Missing token returns 401 Unauthorized", $resNoAuth['code'] === 401);

$resExpired = apiCall("$baseUrl/reorder/list.php", 'GET', null, ["Authorization: Bearer expired_token_xyz"]);
assertCheck("1b. Invalid/expired token returns 401 Unauthorized", $resExpired['code'] === 401);

// Set up clean product test state for Shop 2
// Product 1: units = 8, min_stock_threshold = 24, cost_price = 27.00
$pdo->prepare("UPDATE products SET units = 8, min_stock_threshold = 24, cost_price = 27.00, status = 'Active' WHERE id = 1 AND shop_owner_id = 2")->execute();
// Product 4: cost_price = null, units = 0 (out of stock)
$pdo->prepare("UPDATE products SET units = 0, min_stock_threshold = 20, cost_price = NULL, status = 'Active' WHERE id = 4 AND shop_owner_id = 2")->execute();

// Ensure Shop 2 has >= 30 days of sales history
$oldBillNo = "BILL-HIST-35DAYS";
$checkOld = $pdo->prepare("SELECT id FROM sales WHERE shop_owner_id = 2 AND bill_no = ?");
$checkOld->execute([$oldBillNo]);
if (!$checkOld->fetch()) {
    $pdo->prepare("INSERT INTO sales (shop_owner_id, bill_no, sale_date, total_amount) VALUES (2, ?, NOW() - INTERVAL 35 DAY, 100.00)")->execute([$oldBillNo]);
}

// 2. Test: reorder/list.php with explainable rule-based calculations
$resList = apiCall("$baseUrl/reorder/list.php", 'GET', null, ["Authorization: Bearer $token2"]);
assertCheck("2a. reorder/list.php returns 200", $resList['code'] === 200, "Body: {$resList['body']}");
$reorderConfig = $resList['json']['data']['reorder_config'] ?? [];
assertCheck("2b. reorder_config returned with lead_time, safety, cover days", 
    isset($reorderConfig['lead_time_days']) && 
    isset($reorderConfig['safety_days']) && 
    isset($reorderConfig['cover_days'])
);

$items = $resList['json']['data']['items'] ?? [];
assertCheck("2c. Items list returned", !empty($items));

// Find Product 4 in list (Out of stock with cost_price null, and 0 sales in last 30 days)
$prod4Item = null;
$prod1Item = null;
foreach ($items as $it) {
    if ($it['product']['id'] === 4) $prod4Item = $it;
    if ($it['product']['id'] === 1) $prod1Item = $it;
}

// 3. Test: Cost price null returns estimated_cost = null (Rule: do not guess)
assertCheck("3a. Product with null cost_price has estimated_cost = null", $prod4Item !== null && $prod4Item['estimated_cost'] === null);
assertCheck("3b. Product with units = 0 has high priority with exact rule", 
    $prod4Item !== null && 
    $prod4Item['priority'] === 'high' && 
    $prod4Item['priority_rule'] === 'Out of stock (units = 0)'
);

// 4. Test: Rate 0 or no sales returns days_of_stock_left = null (never divide by zero)
assertCheck("4. Zero demand product returns days_of_stock_left = null", 
    $prod4Item !== null && $prod4Item['days_of_stock_left'] === null
);

// 4b. Test (NEW FIX): Shop HAS >= 30 days history, product sold 0 in 30 days -> suggested_quantity = null & reason
assertCheck("4b. Product with 0 sales in 30 days has suggested_quantity = null", 
    $prod4Item !== null && $prod4Item['suggested_quantity'] === null
);
assertCheck("4c. Reason matches 'No sales in 30 days. Review before reordering.'", 
    $prod4Item !== null && $prod4Item['reason'] === 'No sales in 30 days. Review before reordering.'
);

// 5. Test: Shop 3 has no sales history -> history_warning = true and 2x-min fallback
$checkShop3Prod = $pdo->prepare("SELECT id FROM products WHERE shop_owner_id = 3 LIMIT 1");
$checkShop3Prod->execute();
if (!$checkShop3Prod->fetch()) {
    $pdo->prepare("INSERT INTO products (shop_owner_id, name, sku, category, price, cost_price, units, min_stock_threshold, status) VALUES (3, 'Shop3 Milk', 'S3-DAI-01', 'Dairy', 30.00, 25.00, 5, 20, 'Active')")->execute();
}

$resList3 = apiCall("$baseUrl/reorder/list.php", 'GET', null, ["Authorization: Bearer $token3"]);
assertCheck("5a. Shop with no sales returns history_warning = true", 
    $resList3['code'] === 200 && $resList3['json']['data']['history_warning'] === true
);
assertCheck("5b. Shop with no sales uses 2x-min fallback in reason", 
    !empty($resList3['json']['data']['items']) && 
    strpos($resList3['json']['data']['items'][0]['reason'], '2x-minimum threshold fallback') !== false
);

// 6. Test: create_order.php validates products, limits, and auto-generates order_no
$badProductPayload = [
    "items" => [
        ["product_id" => 1, "quantity" => 10] // Product 1 belongs to Shop 2, Shop 3 tries to order it
    ]
];
$resOtherShop = apiCall("$baseUrl/reorder/create_order.php", 'POST', $badProductPayload, ["Authorization: Bearer $token3"]);
assertCheck("6a. Product from another shop rejected with 404", $resOtherShop['code'] === 404);

$badQtyPayload = [
    "items" => [
        ["product_id" => 1, "quantity" => 0]
    ]
];
$resBadQty = apiCall("$baseUrl/reorder/create_order.php", 'POST', $badQtyPayload, ["Authorization: Bearer $token2"]);
assertCheck("6b. Quantity 0 rejected with 400", $resBadQty['code'] === 400);

// Create valid purchase order for Shop 2
$validOrderPayload = [
    "notes" => "Automated test replenishment",
    "items" => [
        ["product_id" => 1, "quantity" => 20]
    ]
];
$resCreateOrder = apiCall("$baseUrl/reorder/create_order.php", 'POST', $validOrderPayload, ["Authorization: Bearer $token2"]);
assertCheck("6c. Create purchase order returns 201 Created", $resCreateOrder['code'] === 201, "Body: {$resCreateOrder['body']}");
$createdOrderNo = $resCreateOrder['json']['data']['order_no'] ?? '';
$createdOrderId = (int)($resCreateOrder['json']['data']['order_id'] ?? 0);
assertCheck("6d. Server generated order_no matching PO-YYYY-NNN format", 
    preg_match('/^PO-\d{4}-\d{3}$/', $createdOrderNo) === 1, 
    "Order No: $createdOrderNo"
);
assertCheck("6e. Order initial status is 'placed'", $resCreateOrder['json']['data']['status'] === 'placed');

// 7. Test: on_order_units is deducted from suggested_quantity in reorder/list.php (Rule 3)
$resListAfterOrder = apiCall("$baseUrl/reorder/list.php", 'GET', null, ["Authorization: Bearer $token2"]);
$prod1After = null;
foreach ($resListAfterOrder['json']['data']['items'] as $it) {
    if ($it['product']['id'] === 1) $prod1After = $it;
}
assertCheck("7a. on_order_units reflects the placed order (20 units)", 
    $prod1After !== null && $prod1After['on_order_units'] === 20
);
assertCheck("7b. suggested_quantity subtracted on_order_units", 
    $prod1After !== null && 
    $prod1After['suggested_quantity'] === max(0, $prod1After['gross_suggested_quantity'] - 20)
);

// 8. Test: reorder/orders.php lists orders with items
$resOrders = apiCall("$baseUrl/reorder/orders.php", 'GET', null, ["Authorization: Bearer $token2"]);
assertCheck("8a. reorder/orders.php returns 200", $resOrders['code'] === 200);
$allOrders = $resOrders['json']['data']['orders'] ?? [];
assertCheck("8b. Order has items loaded", !empty($allOrders) && !empty($allOrders[0]['items']));

// 9. Test: Isolation - Shop 3 cannot see or receive Shop 2's order
$resReceiveShop3 = apiCall("$baseUrl/reorder/receive.php", 'POST', ["order_id" => $createdOrderId], ["Authorization: Bearer $token3"]);
assertCheck("9. Second shop isolation on receive rejected with 404", $resReceiveShop3['code'] === 404);

// 10. Test: receive.php marks order received and adds stock atomically (Rule 4)
$stockBeforeReceive = (int)$pdo->query("SELECT units FROM products WHERE id = 1")->fetchColumn();
$resReceive = apiCall("$baseUrl/reorder/receive.php", 'POST', ["order_id" => $createdOrderId], ["Authorization: Bearer $token2"]);
assertCheck("10a. Receive order returns 200 OK", $resReceive['code'] === 200, "Body: {$resReceive['body']}");
$stockAfterReceive = (int)$pdo->query("SELECT units FROM products WHERE id = 1")->fetchColumn();
assertCheck("10b. Stock increased by order units (from $stockBeforeReceive to $stockAfterReceive)", 
    $stockAfterReceive === ($stockBeforeReceive + 20)
);

// 11. Test: Receive twice rejected with 400 (Rule 4)
$resReceiveAgain = apiCall("$baseUrl/reorder/receive.php", 'POST', ["order_id" => $createdOrderId], ["Authorization: Bearer $token2"]);
assertCheck("11. Receive twice rejected with 400", $resReceiveAgain['code'] === 400);

// 12. Test: reorder/cancel.php and cannot receive cancelled orders (Rule 5)
// Create another order to test cancel
$cancelOrderPayload = ["items" => [["product_id" => 1, "quantity" => 5]]];
$resOrderToCancel = apiCall("$baseUrl/reorder/create_order.php", 'POST', $cancelOrderPayload, ["Authorization: Bearer $token2"]);
$cancelOrderId = (int)$resOrderToCancel['json']['data']['order_id'];

$resCancel = apiCall("$baseUrl/reorder/cancel.php", 'POST', ["order_id" => $cancelOrderId], ["Authorization: Bearer $token2"]);
assertCheck("12a. Cancel open order returns 200 OK", $resCancel['code'] === 200);

$resReceiveCancelled = apiCall("$baseUrl/reorder/receive.php", 'POST', ["order_id" => $cancelOrderId], ["Authorization: Bearer $token2"]);
assertCheck("12b. Attempting to receive cancelled order rejected with 400", $resReceiveCancelled['code'] === 400);

echo "\n========================================================\n";
echo "   RESULT: $passed / $tests TESTS PASSED                \n";
echo "========================================================\n";
