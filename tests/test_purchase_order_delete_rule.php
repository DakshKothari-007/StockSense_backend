<?php

/**
 * Dedicated Automated Test Suite: Purchase Order Delete & Cancel Rules
 * 
 * Rules:
 * - Open purchase orders: allow delete only if nothing has been received on them.
 * - Partly received or received orders: never delete. Cancel instead. Stock stays correct.
 * - The backend must enforce this, not only the app.
 * - Show a clear message when delete is refused.
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
echo "   PURCHASE ORDER DELETE & CANCEL RULE TEST SUITE       \n";
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

// Acquire token for Shop 2
$login = apiCall("$baseUrl/auth/login.php", 'POST', ["email" => "ananya@example.com", "password" => "password123"]);
$token = $login['json']['token'] ?? '';
assertCheck("1. Login Shop 2 returns valid token", $login['code'] === 200 && strlen($token) === 64);
$authHeader = ["Authorization: Bearer $token"];

// Ensure product 1 exists
$p1 = $pdo->query("SELECT id, units FROM products WHERE id = 1 AND shop_owner_id = 2")->fetch(PDO::FETCH_ASSOC);
if (!$p1) {
    $pdo->query("INSERT INTO products (id, shop_owner_id, name, sku, category, price, cost_price, units, min_stock_threshold) VALUES (1, 2, 'Test Product', 'Grocery', 50, 40, 10, 5)");
    $initialStock = 10;
} else {
    $initialStock = (int)$p1['units'];
}

// Test 2: Missing auth token
$resNoAuth = apiCall("$baseUrl/reorder/delete.php", 'POST', ["order_id" => 1], []);
assertCheck("2. Delete PO without auth returns 401 Unauthorized", $resNoAuth['code'] === 401);

// Test 3: Missing order id and no
$resNoId = apiCall("$baseUrl/reorder/delete.php", 'POST', [], $authHeader);
assertCheck("3. Delete PO with missing order_id/order_no returns 400", $resNoId['code'] === 400);

// Test 4: Delete non-existent PO returns 404
$resNotFound = apiCall("$baseUrl/reorder/delete.php", 'POST', ["order_id" => 999999], $authHeader);
assertCheck("4. Delete non-existent PO returns 404 Not Found", $resNotFound['code'] === 404);

// Test 5: Open PO with 0 received -> Delete allowed
$createOpen = apiCall("$baseUrl/reorder/create_order.php", 'POST', ["items" => [["product_id" => 1, "quantity" => 4]]], $authHeader);
$openPoId = (int)($createOpen['json']['data']['order_id'] ?? 0);
assertCheck("5a. Open PO created with status 'placed'", $openPoId > 0 && ($createOpen['json']['data']['status'] ?? '') === 'placed');

$stockBeforeDelete = (int)$pdo->query("SELECT units FROM products WHERE id = 1")->fetchColumn();
$resDeleteOpen = apiCall("$baseUrl/reorder/delete.php", 'POST', ["order_id" => $openPoId], $authHeader);
assertCheck("5b. Open PO deleted successfully (200 OK)", $resDeleteOpen['code'] === 200);

$stockAfterDelete = (int)$pdo->query("SELECT units FROM products WHERE id = 1")->fetchColumn();
assertCheck("5c. Stock remains unchanged when open PO is deleted", $stockAfterDelete === $stockBeforeDelete);

$poCount = (int)$pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE id = $openPoId")->fetchColumn();
$itemCount = (int)$pdo->query("SELECT COUNT(*) FROM purchase_order_items WHERE purchase_order_id = $openPoId")->fetchColumn();
assertCheck("5d. Purchase order record and line items completely removed", $poCount === 0 && $itemCount === 0);

// Test 6: Received PO -> Delete must be refused
$createForReceive = apiCall("$baseUrl/reorder/create_order.php", 'POST', ["items" => [["product_id" => 1, "quantity" => 6]]], $authHeader);
$recOrderId = (int)($createForReceive['json']['data']['order_id'] ?? 0);
$resReceive = apiCall("$baseUrl/reorder/receive.php", 'POST', ["order_id" => $recOrderId], $authHeader);
assertCheck("6a. Purchase order received (stock updated)", $resReceive['code'] === 200);

$stockAfterReceive = (int)$pdo->query("SELECT units FROM products WHERE id = 1")->fetchColumn();
assertCheck("6b. Stock correctly increased by 6 units", $stockAfterReceive === ($stockBeforeDelete + 6));

// Attempt to delete received PO -> Refused!
$resDelRefused = apiCall("$baseUrl/reorder/delete.php", 'POST', ["order_id" => $recOrderId], $authHeader);
assertCheck("6c. Delete received PO refused with 400 Bad Request", $resDelRefused['code'] === 400);

$refusalMessage = $resDelRefused['json']['message'] ?? '';
assertCheck("6d. Clear refusal message tells user to cancel instead", 
    $refusalMessage === "Cannot delete purchase order with received items. Cancel the order instead to keep stock correct.",
    "Got: $refusalMessage"
);

// Stock must not be corrupted
$stockAfterRefusal = (int)$pdo->query("SELECT units FROM products WHERE id = 1")->fetchColumn();
assertCheck("6e. Stock remains intact and correct", $stockAfterRefusal === $stockAfterReceive);

// Test 7: Cancel received PO -> Stock stays correct
$resCancelRec = apiCall("$baseUrl/reorder/cancel.php", 'POST', ["order_id" => $recOrderId], $authHeader);
assertCheck("7a. Cancel received PO returns 200 OK", $resCancelRec['code'] === 200);

$cancelledStatus = $pdo->query("SELECT status FROM purchase_orders WHERE id = $recOrderId")->fetchColumn();
assertCheck("7b. Order status is now 'cancelled'", $cancelledStatus === 'cancelled');

$stockAfterCancel = (int)$pdo->query("SELECT units FROM products WHERE id = 1")->fetchColumn();
assertCheck("7c. Stock stays correct after cancelling received PO (no units removed)", $stockAfterCancel === $stockAfterReceive);

// Test 8: Deleting cancelled PO that had received items is STILL refused
$resDelCancelledRec = apiCall("$baseUrl/reorder/delete.php", 'POST', ["order_id" => $recOrderId], $authHeader);
assertCheck("8. Deleting cancelled PO that had received items is refused with 400", $resDelCancelledRec['code'] === 400);

// Cleanup test units
$pdo->prepare("DELETE FROM purchase_order_items WHERE purchase_order_id = ?")->execute([$recOrderId]);
$pdo->prepare("DELETE FROM purchase_orders WHERE id = ?")->execute([$recOrderId]);
$pdo->prepare("UPDATE products SET units = ? WHERE id = 1")->execute([$initialStock]);

// Test 9: Open PO cancelled with 0 received -> Delete is allowed
$createCancelPo = apiCall("$baseUrl/reorder/create_order.php", 'POST', ["items" => [["product_id" => 1, "quantity" => 2]]], $authHeader);
$cancelPoId = (int)($createCancelPo['json']['data']['order_id'] ?? 0);
apiCall("$baseUrl/reorder/cancel.php", 'POST', ["order_id" => $cancelPoId], $authHeader);

$resDelCancelOpen = apiCall("$baseUrl/reorder/delete.php", 'POST', ["order_id" => $cancelPoId], $authHeader);
assertCheck("9. Deleting cancelled PO where nothing was received succeeds (200 OK)", $resDelCancelOpen['code'] === 200);

echo "\n========================================================\n";
echo "   RESULT: $passed / $tests TESTS PASSED                \n";
echo "========================================================\n";

if ($passed === $tests) {
    exit(0);
} else {
    exit(1);
}
