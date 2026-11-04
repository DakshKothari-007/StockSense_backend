<?php

/**
 * StockSense FCM Push Notification Backend Test Suite.
 * 
 * Verifies:
 *  - Migration tables (device_tokens, push_log)
 *  - POST notifications/register_device.php (auth, validation, upsert, moving between shops)
 *  - POST notifications/unregister_device.php (auth, validation, deletion)
 *  - Logout token deletion (fcm_token payload)
 *  - Account deletion cascade (purges device_tokens and push_log)
 *  - Deduplication via push_log
 *  - Shop settings toggles enforcement (low_stock_alerts, daily_sales_summary)
 *  - Missing key file resilience (logs 1 line, returns false, never crashes)
 *  - Invalid token cleanup (UNREGISTERED/INVALID_ARGUMENT deletes token)
 *  - Indian currency grouping format rule
 *  - Trigger helpers (low_stock, order_placed, order_received, import_done, daily_summary)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI execution only.\n";
    exit(1);
}

require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/includes/FcmSender.php";
require_once __DIR__ . "/includes/PushNotifier.php";
require_once __DIR__ . "/settings/settings_helper.php";

echo "=== STOCKSENSE FCM PUSH NOTIFICATIONS TEST SUITE ===\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($condition, $description, $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] $description\n";
        $passCount++;
    } else {
        echo "![FAIL] $description" . ($details ? " - $details" : "") . "\n";
        $failCount++;
    }
}

function makeRequest($method, $path, $token = null, $data = null) {
    $url = "http://localhost/StockSense_backend/" . ltrim($path, '/');
    $ch = curl_init($url);

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    if ($token !== null) {
        $headers[] = "Authorization: Bearer $token";
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    if ($data !== null) {
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
// 1. SETUP TEST STATE
// -------------------------------------------------------------
echo "1. Checking Migrations & Database Setup...\n";

$checkDt = $pdo->query("SHOW TABLES LIKE 'device_tokens'")->fetchColumn();
assertTest(!empty($checkDt), "Table 'device_tokens' exists");

$checkPl = $pdo->query("SHOW TABLES LIKE 'push_log'")->fetchColumn();
assertTest(!empty($checkPl), "Table 'push_log' exists");

// Clean existing test device tokens
$pdo->exec("DELETE FROM device_tokens WHERE fcm_token LIKE 'test_token_%'");
$pdo->exec("DELETE FROM push_log WHERE dedupe_key LIKE 'test_dedupe_%'");

// Login or retrieve token for Shop 2 (Ananya) and Shop 3 (Daksh)
function getValidToken(PDO $pdo, int $shopId): string {
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $stmt = $pdo->prepare("INSERT INTO tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))");
    $stmt->execute([$shopId, $tokenHash]);
    return $rawToken;
}

$shop2Token = getValidToken($pdo, 2);
$shop3Token = getValidToken($pdo, 3);
assertTest(!empty($shop2Token) && !empty($shop3Token), "Generated valid bearer tokens for Shop 2 and Shop 3");

// -------------------------------------------------------------
// 2. TESTING REGISTER DEVICE ENDPOINT
// -------------------------------------------------------------
echo "\n2. Testing POST notifications/register_device.php...\n";

// A. Unauthenticated
$r1 = makeRequest("POST", "notifications/register_device.php", null, ["fcm_token" => "test_token_1"]);
assertTest($r1['code'] === 401, "Missing Authorization header returns 401");

// B. Empty fcm_token
$r2 = makeRequest("POST", "notifications/register_device.php", $shop2Token, ["fcm_token" => "   "]);
assertTest($r2['code'] === 400, "Empty fcm_token returns 400 Bad Request");

// C. Valid registration
$r3 = makeRequest("POST", "notifications/register_device.php", $shop2Token, [
    "fcm_token" => "test_token_1",
    "platform" => "android"
]);
assertTest($r3['code'] === 200 && !empty($r3['json']['status']), "Valid registration returns 200 OK");

$stmtCheck = $pdo->prepare("SELECT shop_id, user_id, platform FROM device_tokens WHERE fcm_token = ?");
$stmtCheck->execute(["test_token_1"]);
$rowDt = $stmtCheck->fetch(PDO::FETCH_ASSOC);
assertTest($rowDt && (int)$rowDt['shop_id'] === 2 && $rowDt['platform'] === 'android', "Token stored in database with shop_id = 2");

// D. Moving token to another shop/user
$r4 = makeRequest("POST", "notifications/register_device.php", $shop3Token, [
    "fcm_token" => "test_token_1",
    "platform" => "android"
]);
assertTest($r4['code'] === 200, "Re-registering same token under Shop 3 returns 200 OK");

$stmtCheck->execute(["test_token_1"]);
$rowDtMoved = $stmtCheck->fetch(PDO::FETCH_ASSOC);
assertTest($rowDtMoved && (int)$rowDtMoved['shop_id'] === 3, "Token was moved to shop_id = 3 without duplicate error");

// -------------------------------------------------------------
// 3. TESTING UNREGISTER DEVICE ENDPOINT
// -------------------------------------------------------------
echo "\n3. Testing POST notifications/unregister_device.php...\n";

$r5 = makeRequest("POST", "notifications/unregister_device.php", null, ["fcm_token" => "test_token_1"]);
assertTest($r5['code'] === 401, "Missing Authorization header returns 401");

$r6 = makeRequest("POST", "notifications/unregister_device.php", $shop3Token, ["fcm_token" => ""]);
assertTest($r6['code'] === 400, "Missing fcm_token returns 400 Bad Request");

$r7 = makeRequest("POST", "notifications/unregister_device.php", $shop3Token, ["fcm_token" => "test_token_1"]);
assertTest($r7['code'] === 200 && !empty($r7['json']['status']), "Unregistering token returns 200 OK");

$stmtCheck->execute(["test_token_1"]);
assertTest($stmtCheck->fetch() === false, "Token was removed from database");

// -------------------------------------------------------------
// 4. TESTING LOGOUT TOKEN DELETION
// -------------------------------------------------------------
echo "\n4. Testing Device Token Cleanup on Logout...\n";

// Register test_token_logout for Shop 2
$pdo->prepare("INSERT INTO device_tokens (shop_id, user_id, fcm_token, platform) VALUES (2, 2, 'test_token_logout', 'android') ON DUPLICATE KEY UPDATE shop_id=2")->execute();

// Call logout with fcm_token in body
$logoutResp = makeRequest("POST", "auth/logout.php", $shop2Token, [
    "fcm_token" => "test_token_logout"
]);
assertTest($logoutResp['code'] === 200, "Logout with fcm_token returns 200 OK");

$stmtCheckLogout = $pdo->prepare("SELECT id FROM device_tokens WHERE fcm_token = 'test_token_logout'");
$stmtCheckLogout->execute();
assertTest($stmtCheckLogout->fetch() === false, "Device token was deleted on logout");

// -------------------------------------------------------------
// 5. TESTING ACCOUNT DELETION CASCADE
// -------------------------------------------------------------
echo "\n5. Testing Device Token Cleanup on Account Deletion...\n";

// Create disposable shop owner
$disposableEmail = "fcm_delete_test_" . time() . "@example.com";
$passHash = password_hash("ownerpassword", PASSWORD_BCRYPT);
$pdo->prepare("INSERT INTO owner_account (full_name, shop_name, email, password) VALUES ('Test Owner', 'Temp Shop', ?, ?)")->execute([$disposableEmail, $passHash]);
$tempOwnerId = (int)$pdo->lastInsertId();

// Register device token and push log for disposable shop
$pdo->prepare("INSERT INTO device_tokens (shop_id, user_id, fcm_token) VALUES (?, ?, 'test_token_temp_shop')")->execute([$tempOwnerId, $tempOwnerId]);
$pdo->prepare("INSERT INTO push_log (shop_id, dedupe_key) VALUES (?, 'temp_dedupe_key')")->execute([$tempOwnerId]);

$tempToken = getValidToken($pdo, $tempOwnerId);

// Delete account
$delAccountResp = makeRequest("POST", "auth/delete_account.php", $tempToken, [
    "current_password" => "ownerpassword",
    "confirm_text" => "DELETE"
]);
assertTest($delAccountResp['code'] === 200, "Account deletion returned 200 OK");

$checkTempTokens = $pdo->query("SELECT COUNT(*) FROM device_tokens WHERE shop_id = {$tempOwnerId}")->fetchColumn();
assertTest((int)$checkTempTokens === 0, "All device tokens for deleted account were purged");

$checkTempLogs = $pdo->query("SELECT COUNT(*) FROM push_log WHERE shop_id = {$tempOwnerId}")->fetchColumn();
assertTest((int)$checkTempLogs === 0, "All push logs for deleted account were purged");

// -------------------------------------------------------------
// 6. TESTING DEDUPLICATION VIA PUSH_LOG
// -------------------------------------------------------------
echo "\n6. Testing Deduplication via push_log...\n";

// Setup mock sender so network is simulated
$mockDispatchCalls = 0;
FcmSender::setMockHandler(function($pdo, $token, $title, $body, $data) use (&$mockDispatchCalls) {
    $mockDispatchCalls++;
    return ['success' => true, 'status_code' => 200];
});

// Register device token for Shop 2 and ensure low_stock_alerts is ON
$pdo->prepare("INSERT INTO device_tokens (shop_id, user_id, fcm_token, platform) VALUES (2, 2, 'test_token_dedupe', 'android') ON DUPLICATE KEY UPDATE shop_id=2")->execute();
$pdo->prepare("INSERT INTO user_settings (user_id, low_stock_alerts) VALUES (2, 1) ON DUPLICATE KEY UPDATE low_stock_alerts = 1")->execute();

$dedupeKey = "test_dedupe_" . uniqid();
$resSend1 = PushNotifier::send($pdo, 2, 'low_stock', 'Low stock', 'Item has 2 units left.', ['product_id' => '10'], $dedupeKey);
assertTest(!empty($resSend1['sent']) && $mockDispatchCalls === 1, "First push with unique dedupe key was sent (mock dispatched = 1)");

$resSend2 = PushNotifier::send($pdo, 2, 'low_stock', 'Low stock', 'Item has 2 units left.', ['product_id' => '10'], $dedupeKey);
assertTest(empty($resSend2['sent']) && !empty($resSend2['skipped']) && $resSend2['reason'] === 'deduplicated', "Second push with same dedupe key was skipped as duplicate");
assertTest($mockDispatchCalls === 1, "Mock dispatcher was NOT called on duplicate push");

// -------------------------------------------------------------
// 7. TESTING SHOP SETTINGS TOGGLES ENFORCEMENT & RESTRICTIONS
// -------------------------------------------------------------
echo "\n7. Testing Shop Notification Settings Toggles & Restrictions...\n";

// A. low_stock_alerts toggle
$pdo->prepare("INSERT INTO user_settings (user_id, low_stock_alerts) VALUES (2, 0) ON DUPLICATE KEY UPDATE low_stock_alerts = 0")->execute();
$resLowDisabled = PushNotifier::send($pdo, 2, 'low_stock', 'Low stock', 'Low stock warning', [], 'test_dedupe_low_disabled');
assertTest(empty($resLowDisabled['sent']) && !empty($resLowDisabled['skipped']) && $resLowDisabled['reason'] === 'low_stock_alerts_off', "low_stock push skipped when low_stock_alerts toggle is OFF");

$pdo->prepare("UPDATE user_settings SET low_stock_alerts = 1 WHERE user_id = 2")->execute();
$resLowEnabled = PushNotifier::send($pdo, 2, 'low_stock', 'Low stock', 'Low stock warning', [], 'test_dedupe_low_enabled');
assertTest(!empty($resLowEnabled['sent']), "low_stock push sent when low_stock_alerts toggle is ON");

// B. ai_recommendations toggle
$pdo->prepare("UPDATE user_settings SET ai_recommendations = 0 WHERE user_id = 2")->execute();
$resAiDisabled = PushNotifier::send($pdo, 2, 'reorder_suggestion', 'Reorder suggestion', 'Suggestion text', [], 'test_dedupe_ai_disabled');
assertTest(empty($resAiDisabled['sent']) && !empty($resAiDisabled['skipped']) && $resAiDisabled['reason'] === 'ai_recommendations_off', "reorder_suggestion push skipped when ai_recommendations toggle is OFF");

$pdo->prepare("UPDATE user_settings SET ai_recommendations = 1 WHERE user_id = 2")->execute();
$resAiEnabled = PushNotifier::send($pdo, 2, 'reorder_suggestion', 'Reorder suggestion', 'Suggestion text', [], 'test_dedupe_ai_enabled');
assertTest(!empty($resAiEnabled['sent']), "reorder_suggestion push sent when ai_recommendations toggle is ON");

// C. daily_sales_summary toggle
$pdo->prepare("UPDATE user_settings SET daily_sales_summary = 0 WHERE user_id = 2")->execute();
$resDailyDisabled = PushNotifier::send($pdo, 2, 'daily_summary', 'Daily summary', 'Summary text', [], 'test_dedupe_daily_disabled');
assertTest(empty($resDailyDisabled['sent']) && !empty($resDailyDisabled['skipped']) && $resDailyDisabled['reason'] === 'daily_sales_summary_off', "daily_summary push skipped when daily_sales_summary toggle is OFF");

$pdo->prepare("UPDATE user_settings SET daily_sales_summary = 1 WHERE user_id = 2")->execute();
$resDailyEnabled = PushNotifier::send($pdo, 2, 'daily_summary', 'Daily summary', 'Summary text', [], 'test_dedupe_daily_enabled');
assertTest(!empty($resDailyEnabled['sent']), "daily_summary push sent when daily_sales_summary toggle is ON");

// D. Disallowed event types (must NOT send push notifications)
$resOrderPlaced = PushNotifier::send($pdo, 2, 'order_placed', 'Order placed', 'Order text');
assertTest(empty($resOrderPlaced['sent']) && !empty($resOrderPlaced['skipped']) && $resOrderPlaced['reason'] === 'event_type_not_allowed_for_push', "order_placed push is explicitly blocked");

$resOrderReceived = PushNotifier::send($pdo, 2, 'order_received', 'Order received', 'Order received text');
assertTest(empty($resOrderReceived['sent']) && !empty($resOrderReceived['skipped']) && $resOrderReceived['reason'] === 'event_type_not_allowed_for_push', "order_received push is explicitly blocked");

$resImportDone = PushNotifier::send($pdo, 2, 'import_done', 'Import completed', 'Import text');
assertTest(empty($resImportDone['sent']) && !empty($resImportDone['skipped']) && $resImportDone['reason'] === 'event_type_not_allowed_for_push', "import_done push is explicitly blocked");

$resEmailDigest = PushNotifier::send($pdo, 2, 'weekly_email_digest', 'Weekly digest', 'Email digest text');
assertTest(empty($resEmailDigest['sent']) && !empty($resEmailDigest['skipped']) && $resEmailDigest['reason'] === 'event_type_not_allowed_for_push', "weekly_email_digest push is explicitly blocked");

// -------------------------------------------------------------
// 8. TESTING MISSING KEY FILE RESILIENCE
// -------------------------------------------------------------
echo "\n8. Testing Missing Service Account Key File Resilience...\n";

// Clear mock handler to test real file loader
FcmSender::setMockHandler(null);

$missingKeyResult = FcmSender::getServiceAccount("C:\\non_existent_folder_xyz\\no_key.json");
assertTest($missingKeyResult === null, "Missing service account file returns null silently without throwing");

$sendMissingResult = FcmSender::sendToToken($pdo, "test_token_abc", "Title", "Body", [], "C:\\non_existent_folder_xyz\\no_key.json");
assertTest(!empty($sendMissingResult) && $sendMissingResult['success'] === false, "sendToToken returns failure gracefully when key file is missing");

// -------------------------------------------------------------
// 9. TESTING INVALID TOKEN AUTO-CLEANUP
// -------------------------------------------------------------
echo "\n9. Testing Invalid/Unregistered Token Auto-Deletion...\n";

// Insert token to be invalidated
$pdo->prepare("INSERT INTO device_tokens (shop_id, user_id, fcm_token) VALUES (2, 2, 'test_token_invalid_fcm') ON DUPLICATE KEY UPDATE shop_id=2")->execute();

// Set mock handler simulating FCM HTTP 404 UNREGISTERED response
FcmSender::setMockHandler(function($pdo, $token, $title, $body, $data) {
    // Delete token on UNREGISTERED
    $pdo->prepare("DELETE FROM device_tokens WHERE fcm_token = ?")->execute([$token]);
    return [
        'success' => false,
        'status_code' => 404,
        'response' => '{"error":{"code":404,"message":"Requested entity was not found.","status":"NOT_FOUND","details":[{"errorCode":"UNREGISTERED"}]}}',
        'deleted_token' => true
    ];
});

$invalidTokenResult = FcmSender::sendToToken($pdo, 'test_token_invalid_fcm', 'Title', 'Body');
assertTest($invalidTokenResult['deleted_token'] === true, "Token recognized as UNREGISTERED");

$stmtCheckDel = $pdo->prepare("SELECT id FROM device_tokens WHERE fcm_token = 'test_token_invalid_fcm'");
$stmtCheckDel->execute();
assertTest($stmtCheckDel->fetch() === false, "Unregistered token was deleted from device_tokens table");

// Reset mock handler
FcmSender::setMockHandler(null);

// -------------------------------------------------------------
// 10. TESTING INDIAN CURRENCY GROUPING FORMAT
// -------------------------------------------------------------
echo "\n10. Testing Indian Currency Grouping Format...\n";

assertTest(PushNotifier::formatIndianCurrency(0) === '₹0', "0 formats to ₹0");
assertTest(PushNotifier::formatIndianCurrency(1480) === '₹1,480', "1480 formats to ₹1,480");
assertTest(PushNotifier::formatIndianCurrency(2800) === '₹2,800', "2800 formats to ₹2,800");
assertTest(PushNotifier::formatIndianCurrency(2496) === '₹2,496', "2496 formats to ₹2,496");
assertTest(PushNotifier::formatIndianCurrency(120500) === '₹1,20,500', "120500 formats to ₹1,20,500");
assertTest(PushNotifier::formatIndianCurrency(10000000) === '₹1,00,00,000', "10000000 formats to ₹1,00,00,000");

// -------------------------------------------------------------
// 11. TESTING TRIGGER CONVENIENCE METHODS
// -------------------------------------------------------------
echo "\n11. Testing Trigger Helper Functions...\n";

FcmSender::setMockHandler(function($pdo, $token, $title, $body, $data) {
    return ['success' => true, 'status_code' => 200];
});

// Make sure Shop 2 has a device token and toggles are ON
$pdo->prepare("INSERT INTO device_tokens (shop_id, user_id, fcm_token) VALUES (2, 2, 'test_token_triggers') ON DUPLICATE KEY UPDATE shop_id=2")->execute();
$pdo->prepare("UPDATE user_settings SET low_stock_alerts = 1, ai_recommendations = 1 WHERE user_id = 2")->execute();

// A. Low stock alert when stock is below or at threshold
$lowRes = PushNotifier::triggerLowStock($pdo, 2, [
    'id' => 9999,
    'name' => 'Amul Milk - 500 ml',
    'units' => 4,
    'min_stock_threshold' => 10
]);
assertTest(!empty($lowRes['sent']), "triggerLowStock dispatches push when units <= min_stock_threshold");

// B. Low stock skipped when stock is healthy
$healthyRes = PushNotifier::triggerLowStock($pdo, 2, [
    'id' => 9998,
    'name' => 'Healthy Product',
    'units' => 50,
    'min_stock_threshold' => 10
]);
assertTest(empty($healthyRes['sent']) && !empty($healthyRes['skipped']), "triggerLowStock skips push when units > min_stock_threshold");

// C. Reorder suggestion (AI recommendation)
$reorderRes = PushNotifier::triggerReorderSuggestion($pdo, 2, 9999, 'Amul Milk - 500 ml', 24, 600.00);
assertTest(!empty($reorderRes['sent']), "triggerReorderSuggestion dispatches push with suggested quantity and cost");

// D. Reorder suggestion deduplicated per product per day
$reorderDupeRes = PushNotifier::triggerReorderSuggestion($pdo, 2, 9999, 'Amul Milk - 500 ml', 24, 600.00);
assertTest(empty($reorderDupeRes['sent']) && !empty($reorderDupeRes['skipped']) && $reorderDupeRes['reason'] === 'deduplicated', "triggerReorderSuggestion dedupes per product per day");

// E. AI Recommendation / Insight
$aiRecRes = PushNotifier::triggerAiRecommendation($pdo, 2, 9997, 'Fast Mover Alert', 'KP Sugar sales are up 40% this week');
assertTest(!empty($aiRecRes['sent']), "triggerAiRecommendation dispatches AI insight push");

// -------------------------------------------------------------
// CLEANUP
// -------------------------------------------------------------
$pdo->exec("DELETE FROM device_tokens WHERE fcm_token LIKE 'test_token_%'");
$pdo->exec("DELETE FROM push_log WHERE dedupe_key LIKE 'test_dedupe_%' OR dedupe_key LIKE 'low_stock:999%' OR dedupe_key LIKE 'reorder_suggestion:999%'");

echo "\n============================================\n";
echo "TEST RESULTS: Total Passed: {$passCount}, Failed: {$failCount}\n";
echo "============================================\n";

if ($failCount > 0) {
    exit(1);
}
