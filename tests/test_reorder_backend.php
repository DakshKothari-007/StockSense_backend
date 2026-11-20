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
    } elseif ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($body !== null) {
            $payload = is_string($body) ? $body : json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            $headers[] = "Content-Type: application/json";
        }
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

require_once __DIR__ . '/../config/db.php';

// 0. Acquire tokens for Shop 2 (Ananya) and Shop 3 (Daksh)
$login2 = apiCall("$baseUrl/auth/login.php", 'POST', ["email" => "ananya@example.com", "password" => "password123"]);
$token2 = $login2['json']['token'] ?? '';
assertCheck("0a. Login Shop 2 returns valid token", $login2['code'] === 200 && strlen($token2) === 64);

$shop3Email = $pdo->query("SELECT email FROM owner_account WHERE id = 3")->fetchColumn() ?: "daksh@example.com";
$login3 = apiCall("$baseUrl/auth/login.php", 'POST', ["email" => $shop3Email, "password" => "password123"]);
$token3 = $login3['json']['token'] ?? '';
assertCheck("0b. Login Shop 3 returns valid token", $login3['code'] === 200 && strlen($token3) === 64);

// 1. Test: No token / expired token returns 401
$resNoAuth = apiCall("$baseUrl/reorder/list.php", 'GET', null, []);
assertCheck("1a. Missing token returns 401 Unauthorized", $resNoAuth['code'] === 401);

$resExpired = apiCall("$baseUrl/reorder/list.php", 'GET', null, ["Authorization: Bearer expired_token_xyz"]);
assertCheck("1b. Invalid/expired token returns 401 Unauthorized", $resExpired['code'] === 401);

// Set up clean product test state for Shop 2
// Clean up any previous test purchase orders for Shop 2
$pdo->prepare("DELETE FROM purchase_order_items WHERE purchase_order_id IN (SELECT id FROM purchase_orders WHERE shop_owner_id = 2)")->execute();
$pdo->prepare("DELETE FROM purchase_orders WHERE shop_owner_id = 2")->execute();

// Product 1: units = 8, min_stock_threshold = 24, cost_price = 27.00
$pdo->prepare("UPDATE products SET units = 8, min_stock_threshold = 24, cost_price = 27.00, status = 'Active' WHERE id = 1 AND shop_owner_id = 2")->execute();

// Dedicated test product for zero-demand / zero-sales tests
$pdo->prepare("DELETE FROM purchase_order_items WHERE product_id IN (SELECT id FROM products WHERE sku = 'TEST-ZERO-DEMAND' AND shop_owner_id = 2)")->execute();
$pdo->prepare("DELETE FROM products WHERE sku = 'TEST-ZERO-DEMAND' AND shop_owner_id = 2")->execute();
$pdo->prepare("INSERT INTO products (shop_owner_id, name, sku, category, price, cost_price, units, min_stock_threshold, status) VALUES (2, 'Zero Demand Tea', 'TEST-ZERO-DEMAND', 'Beverages', 100.00, 0.00, 0, 10, 'Active')")->execute();
$zeroDemandId = (int)$pdo->lastInsertId();

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

// Find Zero Demand Product in list (Out of stock with cost_price null, and 0 sales in last 30 days)
$zeroDemandItem = null;
$prod1Item = null;
foreach ($items as $it) {
    if ($it['product']['id'] === $zeroDemandId) $zeroDemandItem = $it;
    if ($it['product']['id'] === 1) $prod1Item = $it;
}

// 3. Test: Cost price null returns estimated_cost = null (Rule: do not guess)
assertCheck("3a. Product with null cost_price has estimated_cost = null", $zeroDemandItem !== null && $zeroDemandItem['estimated_cost'] === null);
assertCheck("3b. Product with units = 0 has high priority with exact rule", 
    $zeroDemandItem !== null && 
    $zeroDemandItem['priority'] === 'high' && 
    $zeroDemandItem['priority_rule'] === 'Out of stock (units = 0)'
);

// 4. Test: Rate 0 or no sales returns days_of_stock_left = null (never divide by zero)
assertCheck("4. Zero demand product returns days_of_stock_left = null", 
    $zeroDemandItem !== null && $zeroDemandItem['days_of_stock_left'] === null
);

// 4b. Test: Product with stock <= min and 0 sales suggests at least reaching min + safety buffer (never 0)
assertCheck("4b. Product with stock <= min and 0 sales has suggested_quantity > 0 (reaches min + safety)", 
    $zeroDemandItem !== null && $zeroDemandItem['suggested_quantity'] > 0
);
assertCheck("4c. Reason indicates minimum threshold fallback with safety buffer", 
    $zeroDemandItem !== null && strpos($zeroDemandItem['reason'], 'safety buffer') !== false
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
        ["product_id" => 1, "quantity" => 10]
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
assertCheck("7a. on_order_units reflects the placed order (10 units)", 
    $prod1After !== null && $prod1After['on_order_units'] === 10
);
assertCheck("7b. suggested_quantity subtracted on_order_units", 
    $prod1After !== null && 
    $prod1After['suggested_quantity'] === max(0, $prod1After['gross_suggested_quantity'] - 10)
);

// 8. Test: reorder/orders.php lists orders with items and product icon info
$resOrders = apiCall("$baseUrl/reorder/orders.php", 'GET', null, ["Authorization: Bearer $token2"]);
assertCheck("8a. reorder/orders.php returns 200", $resOrders['code'] === 200);
$allOrders = $resOrders['json']['data']['orders'] ?? [];
assertCheck("8b. Order has items loaded", !empty($allOrders) && !empty($allOrders[0]['items']));
assertCheck("8c. Order has first_product_name populated", !empty($allOrders[0]['first_product_name']));
assertCheck("8d. Order item contains category and icon_key fields", 
    isset($allOrders[0]['items'][0]['category']) && array_key_exists('icon_key', $allOrders[0]['items'][0])
);

// 9. Test: Isolation - Shop 3 cannot see or receive Shop 2's order
$resReceiveShop3 = apiCall("$baseUrl/reorder/receive.php", 'POST', ["order_id" => $createdOrderId], ["Authorization: Bearer $token3"]);
assertCheck("9. Second shop isolation on receive rejected with 404", $resReceiveShop3['code'] === 404);

// 10. Test: receive.php marks order received and adds stock atomically (Rule 4)
$stockBeforeReceive = (int)$pdo->query("SELECT units FROM products WHERE id = 1")->fetchColumn();
$resReceive = apiCall("$baseUrl/reorder/receive.php", 'POST', ["order_id" => $createdOrderId], ["Authorization: Bearer $token2"]);
assertCheck("10a. Receive order returns 200 OK", $resReceive['code'] === 200, "Body: {$resReceive['body']}");
$stockAfterReceive = (int)$pdo->query("SELECT units FROM products WHERE id = 1")->fetchColumn();
assertCheck("10b. Stock increased by order units (from $stockBeforeReceive to $stockAfterReceive)", 
    $stockAfterReceive === ($stockBeforeReceive + 10)
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

// 13. Test: Items fully covered by open orders are excluded from reorder/list.php
$coverOrderPayload = ["items" => [["product_id" => $zeroDemandId, "quantity" => 50]]];
$resCoverOrder = apiCall("$baseUrl/reorder/create_order.php", 'POST', $coverOrderPayload, ["Authorization: Bearer $token2"]);
$coverOrderId = (int)($resCoverOrder['json']['data']['order_id'] ?? 0);
assertCheck("13a. Create cover order for Zero Demand Product returns 201", $resCoverOrder['code'] === 201);

$resListCovered = apiCall("$baseUrl/reorder/list.php", 'GET', null, ["Authorization: Bearer $token2"]);
$zeroCovered = null;
foreach ($resListCovered['json']['data']['items'] as $it) {
    if ($it['product']['id'] === $zeroDemandId) $zeroCovered = $it;
}
assertCheck("13b. Fully covered product (needed <= 0) is excluded from reorder/list.php", $zeroCovered === null);

$resDetailCovered = apiCall("$baseUrl/inventory/get_product_detail.php?id=$zeroDemandId", 'GET', null, ["Authorization: Bearer $token2"]);
$detailData = $resDetailCovered['json']['data'] ?? [];
assertCheck("13c. get_product_detail.php returns suggested_reorder_units: 0 when covered", 
    (int)($detailData['suggested_reorder_units'] ?? -1) === 0
);
assertCheck("13d. get_product_detail.php returns on_order_units accurately", 
    (int)($detailData['on_order_units'] ?? -1) === 50
);

// Cleanup cover order and test zero-demand product
$resCancelCover = apiCall("$baseUrl/reorder/cancel.php", 'POST', ["order_id" => $coverOrderId], ["Authorization: Bearer $token2"]);
assertCheck("13e. Cleaned up cover order with cancel", $resCancelCover['code'] === 200);

$pdo->prepare("DELETE FROM purchase_order_items WHERE purchase_order_id = ?")->execute([$coverOrderId]);
$pdo->prepare("DELETE FROM purchase_orders WHERE id = ?")->execute([$coverOrderId]);
$pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$zeroDemandId]);

// 14. Test: Purchase Order Delete Rule Enforcements
// Rule: Open purchase orders allow delete only if nothing has been received on them.
// Rule: Partly received or received orders: never delete. Cancel instead. Stock stays correct.
// Rule: Backend must enforce this with a clear refusal message.

// 14a. Missing auth token returns 401
$resDelNoAuth = apiCall("$baseUrl/reorder/delete.php", 'POST', ["order_id" => 99999], []);
assertCheck("14a. Delete PO without auth returns 401 Unauthorized", $resDelNoAuth['code'] === 401);

// 14b. Missing order_id and order_no returns 400
$resDelNoId = apiCall("$baseUrl/reorder/delete.php", 'POST', [], ["Authorization: Bearer $token2"]);
assertCheck("14b. Delete PO with empty body returns 400", $resDelNoId['code'] === 400);

// 14c. Order belonging to another shop returns 404 (shop isolation)
$createOtherPo = apiCall("$baseUrl/reorder/create_order.php", 'POST', ["items" => [["product_id" => 1, "quantity" => 2]]], ["Authorization: Bearer $token2"]);
$otherPoId = (int)($createOtherPo['json']['data']['order_id'] ?? 0);
$resDelOtherShop = apiCall("$baseUrl/reorder/delete.php", 'POST', ["order_id" => $otherPoId], ["Authorization: Bearer $token3"]);
assertCheck("14c. Shop 3 deleting Shop 2 order returns 404 Not Found", $resDelOtherShop['code'] === 404);

// 14d. Create order, receive it -> Delete must be refused with 400
$stockBeforePo = (int)$pdo->query("SELECT units FROM products WHERE id = 1")->fetchColumn();
$createRecPo = apiCall("$baseUrl/reorder/create_order.php", 'POST', ["items" => [["product_id" => 1, "quantity" => 5]]], ["Authorization: Bearer $token2"]);
$recPoId = (int)($createRecPo['json']['data']['order_id'] ?? 0);
$resRecPo = apiCall("$baseUrl/reorder/receive.php", 'POST', ["order_id" => $recPoId], ["Authorization: Bearer $token2"]);
assertCheck("14d. PO marked as received", $resRecPo['code'] === 200);

$stockAfterRec = (int)$pdo->query("SELECT units FROM products WHERE id = 1")->fetchColumn();
assertCheck("14e. Product stock increased by 5 units", $stockAfterRec === ($stockBeforePo + 5));

// Now attempt to delete received PO -> Must be refused!
$resDelReceived = apiCall("$baseUrl/reorder/delete.php", 'POST', ["order_id" => $recPoId], ["Authorization: Bearer $token2"]);
assertCheck("14f. Deleting received purchase order is REFUSED with 400", $resDelReceived['code'] === 400);
$refusalMsg = $resDelReceived['json']['message'] ?? '';
assertCheck("14g. Refusal message informs user to cancel instead", 
    strpos($refusalMsg, 'Cannot delete purchase order with received items. Cancel the order instead to keep stock correct.') !== false,
    "Got message: $refusalMsg"
);

// Verify order still exists in DB and stock has not been corrupted
$checkPoExists = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE id = ?");
$checkPoExists->execute([$recPoId]);
assertCheck("14h. Received purchase order still exists in DB", (int)$checkPoExists->fetchColumn() === 1);
$stockAfterRefusedDel = (int)$pdo->query("SELECT units FROM products WHERE id = 1")->fetchColumn();
assertCheck("14i. Inventory stock unchanged and stays correct", $stockAfterRefusedDel === $stockAfterRec);

// 14j. Cancel the received order instead -> Must succeed, and stock stays correct!
$resCancelRec = apiCall("$baseUrl/reorder/cancel.php", 'POST', ["order_id" => $recPoId], ["Authorization: Bearer $token2"]);
assertCheck("14j. Cancel received PO returns 200 OK", $resCancelRec['code'] === 200);
$stockAfterCancelRec = (int)$pdo->query("SELECT units FROM products WHERE id = 1")->fetchColumn();
assertCheck("14k. Stock stays correct after cancelling received PO", $stockAfterCancelRec === $stockAfterRec);

// 14l. Attempting to delete the cancelled PO that had received items is STILL refused
$resDelCancelledWithRec = apiCall("$baseUrl/reorder/delete.php", 'POST', ["order_id" => $recPoId], ["Authorization: Bearer $token2"]);
assertCheck("14l. Deleting cancelled order that had received items is refused with 400", $resDelCancelledWithRec['code'] === 400);

// Clean up the received test order manually (and revert the 5 test units so test isolation is maintained)
$pdo->prepare("DELETE FROM purchase_order_items WHERE purchase_order_id = ?")->execute([$recPoId]);
$pdo->prepare("DELETE FROM purchase_orders WHERE id = ?")->execute([$recPoId]);
$pdo->prepare("UPDATE products SET units = units - 5 WHERE id = 1")->execute();

// 14m. Open purchase order with nothing received -> Delete is allowed and succeeds cleanly
$resDelOpen = apiCall("$baseUrl/reorder/delete.php", 'POST', ["order_id" => $otherPoId], ["Authorization: Bearer $token2"]);
assertCheck("14m. Deleting open purchase order with 0 received returns 200 OK", $resDelOpen['code'] === 200, "Body: {$resDelOpen['body']}");

// Verify open PO was deleted from DB and line items deleted
$checkOpenDeleted = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE id = ?");
$checkOpenDeleted->execute([$otherPoId]);
assertCheck("14n. Open purchase order removed from DB", (int)$checkOpenDeleted->fetchColumn() === 0);

$checkOpenItemsDeleted = $pdo->prepare("SELECT COUNT(*) FROM purchase_order_items WHERE purchase_order_id = ?");
$checkOpenItemsDeleted->execute([$otherPoId]);
assertCheck("14o. Purchase order items removed from DB", (int)$checkOpenItemsDeleted->fetchColumn() === 0);

// 14p. Deleting already deleted PO returns 404
$resDelAgain = apiCall("$baseUrl/reorder/delete.php", 'POST', ["order_id" => $otherPoId], ["Authorization: Bearer $token2"]);
assertCheck("14p. Deleting non-existent PO returns 404 Not Found", $resDelAgain['code'] === 404);

// 14q. Delete via HTTP DELETE method with query param
$createPoForHttpDel = apiCall("$baseUrl/reorder/create_order.php", 'POST', ["items" => [["product_id" => 1, "quantity" => 1]]], ["Authorization: Bearer $token2"]);
$httpDelId = (int)($createPoForHttpDel['json']['data']['order_id'] ?? 0);
$resHttpDel = apiCall("$baseUrl/reorder/delete.php?order_id=$httpDelId", 'DELETE', null, ["Authorization: Bearer $token2"]);
assertCheck("14q. HTTP DELETE method deletes open PO cleanly", $resHttpDel['code'] === 200);

echo "\n========================================================\n";
echo "   RESULT: $passed / $tests TESTS PASSED                \n";
echo "========================================================\n";
