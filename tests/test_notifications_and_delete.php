<?php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/settings/settings_helper.php';

echo "=== STOCKSENSE NOTIFICATIONS & DELETE ACCOUNT TEST SUITE ===\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($condition, $description) {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] $description\n";
        $passCount++;
    } else {
        echo "![FAIL] $description\n";
        $failCount++;
    }
}

function makeRequest($method, $path, $token = null, $data = null, $rawPayload = null) {
    $url = "http://localhost/StockSense_backend/$path";
    $ch = curl_init($url);

    $headers = [
        "Content-Type: application/json",
        "Accept: application/json"
    ];

    if ($token !== null) {
        $headers[] = "Authorization: Bearer $token";
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($rawPayload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $rawPayload);
    } elseif ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $rawResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($rawResponse, true);
    return [
        'code' => $httpCode,
        'raw' => $rawResponse,
        'json' => $json
    ];
}

// -------------------------------------------------------------
// 1. SETUP TEST ENVIRONMENT
// -------------------------------------------------------------
echo "1. Setting up test database state...\n";

$pdo->exec("DELETE FROM rate_limits");
$pdo->exec("DELETE FROM login_attempts");

// Reset passwords to known password123
$defaultHash = password_hash('password123', PASSWORD_DEFAULT);
$pdo->prepare("UPDATE owner_account SET password = ? WHERE id = 2")->execute([$defaultHash]);
$pdo->prepare("UPDATE owner_account SET password = ? WHERE id = 3")->execute([$defaultHash]);

// Login Shop 2 (Ananya) and Shop 3 (Daksh)
$login2 = makeRequest('POST', 'auth/login.php', null, ["email" => "ananya@example.com", "password" => "password123"]);
$tokenShop2 = $login2['json']['token'] ?? null;
assertTest($login2['code'] === 200 && strlen($tokenShop2) === 64, "Login Shop 2 (Ananya) returns valid 64-char token");

$login3 = makeRequest('POST', 'auth/login.php', null, ["email" => "daksh1@gmail.com", "password" => "password123"]);
$tokenShop3 = $login3['json']['token'] ?? null;
assertTest($login3['code'] === 200 && strlen($tokenShop3) === 64, "Login Shop 3 (Daksh) returns valid 64-char token");

// -------------------------------------------------------------
// 2. DIRECT FORGOT & RESET PASSWORD FLOW
// -------------------------------------------------------------
echo "\n2. Testing Direct Forgot & Reset Password Flow...\n";

// Forgot password valid email
$resForgot = makeRequest('POST', 'auth/forgot_password.php', null, ["email" => "ananya@example.com"]);
assertTest($resForgot['code'] === 200 && $resForgot['json']['status'] === true, "Forgot password returns 200 with success status");
assertTest(!isset($resForgot['json']['reset_token']), "Forgot password does NOT return any reset token or OTP");

// Forgot password unknown email
$resForgot404 = makeRequest('POST', 'auth/forgot_password.php', null, ["email" => "nonexistent_email_12345@example.com"]);
assertTest($resForgot404['code'] === 404, "Forgot password for unknown email returns 404");

// Reset password mismatched passwords
$resMismatch = makeRequest('POST', 'auth/reset_password.php', null, [
    "email" => "ananya@example.com",
    "new_password" => "StrongPassword123!",
    "confirm_password" => "WrongMismatch123!"
]);
assertTest($resMismatch['code'] === 422, "Reset password with mismatched passwords returns 422");

// Reset password under 8 chars
$resShort = makeRequest('POST', 'auth/reset_password.php', null, [
    "email" => "ananya@example.com",
    "new_password" => "short",
    "confirm_password" => "short"
]);
assertTest($resShort['code'] === 422, "Reset password with under 8 chars returns 422");

// Reset password valid
$resReset = makeRequest('POST', 'auth/reset_password.php', null, [
    "email" => "ananya@example.com",
    "new_password" => "BrandNewPassword123!",
    "confirm_password" => "BrandNewPassword123!"
]);
assertTest($resReset['code'] === 200 && $resReset['json']['status'] === true, "Direct reset password succeeds with 200");

// Verify active sessions revoked
$resOldToken = makeRequest('GET', 'settings/get.php', $tokenShop2);
assertTest($resOldToken['code'] === 401, "All old sessions revoked after password reset (returns 401)");

// Login with new password
$relogin = makeRequest('POST', 'auth/login.php', null, ["email" => "ananya@example.com", "password" => "BrandNewPassword123!"]);
$tokenShop2 = $relogin['json']['token'] ?? null;
assertTest($relogin['code'] === 200 && strlen($tokenShop2) === 64, "Login with new password succeeds (200)");

// Reset back to password123 for other suites
$pdo->prepare("UPDATE owner_account SET password = ? WHERE id = 2")->execute([$defaultHash]);
$reloginOriginal = makeRequest('POST', 'auth/login.php', null, ["email" => "ananya@example.com", "password" => "password123"]);
$tokenShop2 = $reloginOriginal['json']['token'] ?? null;

// -------------------------------------------------------------
// 3. NOTIFICATIONS MODULE
// -------------------------------------------------------------
echo "\n3. Testing Notifications Module...\n";

// Auth checks
$noTokenRes = makeRequest('GET', 'notifications/list.php');
assertTest($noTokenRes['code'] === 401, "GET notifications/list.php without token returns 401");

$wrongMethod = makeRequest('POST', 'notifications/list.php', $tokenShop2, []);
assertTest($wrongMethod['code'] === 405, "POST notifications/list.php returns 405 Method Not Allowed");

$noTokenUnread = makeRequest('GET', 'notifications/unread_count.php');
assertTest($noTokenUnread['code'] === 401, "GET notifications/unread_count.php without token returns 401");

// Seed clean notifications state
$pdo->exec("DELETE FROM notification_states WHERE shop_owner_id = 2");
$pdo->exec("DELETE FROM products WHERE shop_owner_id = 2 AND sku LIKE 'NOTIF-%'");

// Create 1 low stock product (units = 3, min = 10)
$stmtProd = $pdo->prepare("INSERT INTO products (shop_owner_id, name, sku, category, units, min_stock_threshold, price, cost_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$stmtProd->execute([2, "Notification Low Stock Biscuit", "NOTIF-01", "Biscuits", 3, 10, 50.00, 30.00]);
$notifProdId = (int)$pdo->lastInsertId();

// Create 1 import history log
$pdo->prepare("INSERT INTO import_history (shop_owner_id, file_name, file_size, total_rows, success_count, failed_count) VALUES (?, ?, ?, ?, ?, ?)")
    ->execute([2, "test_notif.csv", 1024, 25, 20, 5]);

// Fetch notifications list
$listRes = makeRequest('GET', 'notifications/list.php', $tokenShop2);
assertTest($listRes['code'] === 200, "GET notifications/list.php returns 200 OK");
assertTest(isset($listRes['json']['data']['unread_count']), "Response contains unread_count");
assertTest($listRes['json']['data']['unread_count'] >= 1, "Unread count reflects live generated notifications");

$items = $listRes['json']['data']['items'];
$lowStockFound = false;
$importFound = false;
foreach ($items as $item) {
    if ($item['key'] === "low_stock:{$notifProdId}") {
        $lowStockFound = true;
        assertTest($item['type'] === 'low_stock', "Low stock item type is 'low_stock'");
        assertTest(str_contains($item['title'], "Notification Low Stock Biscuit"), "Low stock title contains product name");
        assertTest(isset($item['numbers_used']['units']) && $item['numbers_used']['units'] === 3, "Numbers used has exact units (3)");
        assertTest($item['is_read'] === false, "Generated notification starts as unread");
    }
    if (str_starts_with($item['key'], "import:")) {
        $importFound = true;
        assertTest(str_contains($item['body'], "test_notif.csv"), "Import notification body mentions file name");
    }
}
assertTest($lowStockFound, "Low stock notification generated for low product");
assertTest($importFound, "Import status notification generated for latest import");

// Unread count endpoint
$countRes = makeRequest('GET', 'notifications/unread_count.php', $tokenShop2);
assertTest($countRes['code'] === 200, "GET notifications/unread_count.php returns 200 OK");
$initialUnread = (int)$countRes['json']['data']['unread_count'];
assertTest($initialUnread >= 1, "Unread count matches active unread notifications");

// Mark single notification read
$markRes = makeRequest('POST', 'notifications/mark_read.php', $tokenShop2, ["key" => "low_stock:{$notifProdId}"]);
assertTest($markRes['code'] === 200, "POST notifications/mark_read.php returns 200 OK");
assertTest($markRes['json']['data']['is_read'] === true, "Mark read returns is_read = true");

// Verify unread count decremented
$countAfterRead = makeRequest('GET', 'notifications/unread_count.php', $tokenShop2);
assertTest((int)$countAfterRead['json']['data']['unread_count'] === $initialUnread - 1, "Unread count decremented after marking single item read");

// Marking read twice is idempotent
$markRes2 = makeRequest('POST', 'notifications/mark_read.php', $tokenShop2, ["key" => "low_stock:{$notifProdId}"]);
assertTest($markRes2['code'] === 200, "Marking read twice returns 200 (idempotent)");

// Dismiss notification
$dismissRes = makeRequest('POST', 'notifications/dismiss.php', $tokenShop2, ["key" => "low_stock:{$notifProdId}"]);
assertTest($dismissRes['code'] === 200, "POST notifications/dismiss.php returns 200 OK");

// Dismissed notification is omitted from list
$listAfterDismiss = makeRequest('GET', 'notifications/list.php', $tokenShop2);
$foundDismissed = false;
foreach ($listAfterDismiss['json']['data']['items'] as $it) {
    if ($it['key'] === "low_stock:{$notifProdId}") {
        $foundDismissed = true;
    }
}
assertTest(!$foundDismissed, "Dismissed notification is omitted from list.php");

// Condition cleared removes state: replenish stock
$pdo->prepare("UPDATE products SET units = 50 WHERE id = ?")->execute([$notifProdId]);
// Calling list re-evaluates and cleans up resolved read/dismissed state
makeRequest('GET', 'notifications/list.php', $tokenShop2);
$checkState = $pdo->prepare("SELECT id FROM notification_states WHERE shop_owner_id = 2 AND notification_key = ?");
$checkState->execute(["low_stock:{$notifProdId}"]);
assertTest(!$checkState->fetch(), "Condition cleared: saved state was removed when product restocked");

// Mark all read
$markAllRes = makeRequest('POST', 'notifications/mark_all_read.php', $tokenShop2, []);
assertTest($markAllRes['code'] === 200, "POST notifications/mark_all_read.php returns 200 OK");
$countAfterAll = makeRequest('GET', 'notifications/unread_count.php', $tokenShop2);
assertTest((int)$countAfterAll['json']['data']['unread_count'] === 0, "All notifications marked read: unread_count is 0");

// Second-shop isolation: Shop 3 sees 0 of Shop 2's notifications
$shop3List = makeRequest('GET', 'notifications/list.php', $tokenShop3);
assertTest($shop3List['code'] === 200, "Shop 3 notifications/list.php returns 200 OK");
$shop3HasShop2Data = false;
foreach ($shop3List['json']['data']['items'] as $it) {
    if (isset($it['product_id']) && $it['product_id'] === $notifProdId) {
        $shop3HasShop2Data = true;
    }
}
assertTest(!$shop3HasShop2Data, "Strict store isolation: Shop 3 sees none of Shop 2's notifications");

// -------------------------------------------------------------
// 4. DELETE ACCOUNT MODULE
// -------------------------------------------------------------
echo "\n4. Testing Delete Account Module...\n";

// Create temporary disposable user for delete account test
$disposableEmail = "delete_me_" . time() . "@example.com";
$signupRes = makeRequest('POST', 'auth/signup.php', null, [
    "full_name" => "Disposable User",
    "shop_name" => "Disposable Mart",
    "email" => $disposableEmail,
    "password" => "DeleteMePass123!"
]);
assertTest($signupRes['code'] === 201, "Created disposable shop owner for delete test");
$disposableId = (int)($signupRes['json']['user']['id'] ?? $signupRes['json']['data']['user_id'] ?? 0);

// Login disposable owner
$dispLogin = makeRequest('POST', 'auth/login.php', null, ["email" => $disposableEmail, "password" => "DeleteMePass123!"]);
$dispToken = $dispLogin['json']['token'];
assertTest($dispLogin['code'] === 200 && strlen($dispToken) === 64, "Disposable owner logged in with valid token");

// Seed data across tables for disposable user to test full cascade
$pdo->prepare("INSERT INTO products (shop_owner_id, name, sku, units, price) VALUES (?, ?, ?, ?, ?)")
    ->execute([$disposableId, "Disposable Prod", "DISP-01", 10, 100.00]);
$dispProdId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO sales (shop_owner_id, bill_no, sale_date, total_amount) VALUES (?, ?, NOW(), ?)")
    ->execute([$disposableId, "DISP-BILL-01", 100.00]);
$dispSaleId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO sales_items (sale_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?)")
    ->execute([$dispSaleId, $dispProdId, 1, 100.00, 100.00]);

$pdo->prepare("INSERT INTO purchase_orders (shop_owner_id, order_no, notes) VALUES (?, ?, ?)")
    ->execute([$disposableId, "PO-2026-999", "Test Dist"]);
$dispPoId = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity) VALUES (?, ?, ?)")
    ->execute([$dispPoId, $dispProdId, 5]);

$pdo->prepare("INSERT INTO import_history (shop_owner_id, file_name, file_size, total_rows) VALUES (?, ?, ?, ?)")
    ->execute([$disposableId, "disp.csv", 1024, 10]);

// Delete with missing confirm_text
$delNoConfirm = makeRequest('POST', 'auth/delete_account.php', $dispToken, ["current_password" => "DeleteMePass123!", "confirm_text" => "NO"]);
assertTest($delNoConfirm['code'] === 400, "Delete account with invalid confirm_text returns 400");

// Delete with wrong current password
$delWrongPw = makeRequest('POST', 'auth/delete_account.php', $dispToken, ["current_password" => "WrongPassword!", "confirm_text" => "DELETE"]);
assertTest($delWrongPw['code'] === 400, "Delete account with wrong password returns 400");

// Valid delete account
$delSuccess = makeRequest('POST', 'auth/delete_account.php', $dispToken, ["current_password" => "DeleteMePass123!", "confirm_text" => "DELETE"]);
assertTest($delSuccess['code'] === 200 && $delSuccess['json']['status'] === true, "Valid delete account returns 200 OK");

// Old token is revoked
$oldTokenCheck = makeRequest('GET', 'settings/get.php', $dispToken);
assertTest($oldTokenCheck['code'] === 401, "Deleted owner's token immediately returns 401 Unauthorized");

// Deleted user cannot log in
$deletedLogin = makeRequest('POST', 'auth/login.php', null, ["email" => $disposableEmail, "password" => "DeleteMePass123!"]);
assertTest($deletedLogin['code'] === 401, "Deleted owner cannot log in (returns 401)");

// Zero orphan rows in any table
$cOwner = (int)$pdo->query("SELECT COUNT(*) FROM owner_account WHERE id = $disposableId")->fetchColumn();
$cProd = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE shop_owner_id = $disposableId")->fetchColumn();
$cSales = (int)$pdo->query("SELECT COUNT(*) FROM sales WHERE shop_owner_id = $disposableId")->fetchColumn();
$cItems = (int)$pdo->query("SELECT COUNT(*) FROM sales_items WHERE sale_id = $dispSaleId")->fetchColumn();
$cPo = (int)$pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE shop_owner_id = $disposableId")->fetchColumn();
$cPoi = (int)$pdo->query("SELECT COUNT(*) FROM purchase_order_items WHERE purchase_order_id = $dispPoId")->fetchColumn();
$cImp = (int)$pdo->query("SELECT COUNT(*) FROM import_history WHERE shop_owner_id = $disposableId")->fetchColumn();
$cTok = (int)$pdo->query("SELECT COUNT(*) FROM tokens WHERE user_id = $disposableId")->fetchColumn();

$totalOrphans = $cOwner + $cProd + $cSales + $cItems + $cPo + $cPoi + $cImp + $cTok;
assertTest($totalOrphans === 0, "Zero orphan rows left across all tables after atomic delete");

echo "\n============================================\n";
echo "TEST RESULTS: Total Passed: $passCount, Failed: $failCount\n";
echo "============================================\n";
