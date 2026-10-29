<?php

require_once __DIR__ . '/config/db.php';

echo "=== STOCKSENSE SETTINGS BACKEND TEST SUITE ===\n\n";

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

function makeRequest($method, $path, $token = null, $data = null, $rawPayload = null) {
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
// 1. SETUP TEST USERS & LOGINS
// -------------------------------------------------------------
echo "1. Setting up test database state...\n";

// Reset password for Shop 2 (Ananya, ID 2) to known password 'password123'
$defaultHash = password_hash('password123', PASSWORD_DEFAULT);
$pdo->prepare("UPDATE owner_account SET password = ? WHERE id = 2")->execute([$defaultHash]);
$pdo->exec("DELETE FROM login_attempts");

// Login Shop 2 (Ananya) and Shop 3 (Daksh)
$login2 = makeRequest('POST', 'auth/login.php', null, ["email" => "ananya@example.com", "password" => "password123"]);
$tokenShop2 = $login2['json']['token'] ?? null;
assertTest($login2['code'] === 200 && strlen($tokenShop2) === 64, "Login Shop 2 (Ananya) returns valid 64-char token");

$login3 = makeRequest('POST', 'auth/login.php', null, ["email" => "daksh1@gmail.com", "password" => "password123"]);
$tokenShop3 = $login3['json']['token'] ?? null;
assertTest($login3['code'] === 200 && strlen($tokenShop3) === 64, "Login Shop 3 (Daksh) returns valid 64-char token");

// Delete existing settings row for Shop 2 to test Rule 4 (GET must not write; return defaults)
$pdo->exec("DELETE FROM user_settings WHERE user_id = 2");

// Verify row is deleted
$checkRow = $pdo->query("SELECT id FROM user_settings WHERE user_id = 2")->fetch();
assertTest(!$checkRow, "Clean state: Shop 2 has no saved user_settings row");

// -------------------------------------------------------------
// 2. AUTHENTICATION & METHOD CHECKS
// -------------------------------------------------------------
echo "\n2. Testing Authentication & Method Restrictions...\n";

$resNoToken = makeRequest('GET', 'settings/get.php');
assertTest($resNoToken['code'] === 401, "GET settings/get.php without token returns 401 Unauthorized");

$resInvalidToken = makeRequest('GET', 'settings/get.php', "invalid_random_token_12345");
assertTest($resInvalidToken['code'] === 401, "GET settings/get.php with invalid token returns 401 Unauthorized");

$resPostGet = makeRequest('POST', 'settings/get.php', $tokenShop2, []);
assertTest($resPostGet['code'] === 405, "POST settings/get.php returns 405 Method Not Allowed");

$resGetUpdate = makeRequest('GET', 'settings/update.php', $tokenShop2);
assertTest($resGetUpdate['code'] === 405, "GET settings/update.php returns 405 Method Not Allowed");

// -------------------------------------------------------------
// 3. GET DEFAULTS WITHOUT WRITING (Rule 4)
// -------------------------------------------------------------
echo "\n3. Testing GET Defaults Without Writing...\n";

$resGet = makeRequest('GET', 'settings/get.php', $tokenShop2);
assertTest($resGet['code'] === 200, "GET settings/get.php returns 200 OK");
assertTest($resGet['json']['data']['appearance']['theme_mode'] === 'SYSTEM', "Default theme_mode is SYSTEM");
assertTest($resGet['json']['data']['inventory']['lead_time_days'] === 2, "Default lead_time_days is 2");
assertTest($resGet['json']['data']['inventory']['safety_days'] === 3, "Default safety_days is 3");
assertTest($resGet['json']['data']['inventory']['cover_days'] === 7, "Default cover_days is 7");
assertTest($resGet['json']['data']['inventory']['min_history_days'] === 30, "Default min_history_days is 30");
assertTest($resGet['json']['data']['inventory']['slow_moving_threshold_days'] === 60, "Default slow_moving_threshold_days is 60");
assertTest($resGet['json']['data']['inventory']['import_mode'] === 'ADD', "Default import_mode is ADD");
assertTest($resGet['json']['data']['account']['timezone'] === 'Asia/Kolkata', "Default timezone is Asia/Kolkata");
assertTest($resGet['json']['data']['is_customized'] === false, "is_customized is false for unwritten default settings");

// Rule 4: Verify DB still has NO row for user_id = 2
$checkRowAfterGet = $pdo->query("SELECT id FROM user_settings WHERE user_id = 2")->fetch();
assertTest(!$checkRowAfterGet, "Rule 4: GET settings/get.php did NOT write any row to user_settings table");

// -------------------------------------------------------------
// 4. VALIDATION & REJECTIONS (Empty body, Unknown keys, Types, Ranges)
// -------------------------------------------------------------
echo "\n4. Testing Input Validations & Rejections...\n";

// Reject empty body
$resEmpty = makeRequest('POST', 'settings/update.php', $tokenShop2, null, "");
assertTest($resEmpty['code'] === 400, "Empty payload returns 400 Bad Request");

$resEmptyObj = makeRequest('POST', 'settings/update.php', $tokenShop2, []);
assertTest($resEmptyObj['code'] === 400, "Empty JSON object {} returns 400 Bad Request");

// Reject unknown key
$resUnknown = makeRequest('POST', 'settings/update.php', $tokenShop2, ["unrecognized_setting" => "value"]);
assertTest($resUnknown['code'] === 400, "Unknown key returns 400 Bad Request");

// Reject timezone update (Rule 3: timezone is read-only)
$resTimezone = makeRequest('POST', 'settings/update.php', $tokenShop2, ["timezone" => "America/New_York"]);
assertTest($resTimezone['code'] === 400, "Updating read-only timezone returns 400 Bad Request");

// Reject account profile field update (Rule 5: must use profile module)
$resProfile = makeRequest('POST', 'settings/update.php', $tokenShop2, ["shop_name" => "Brand New Name"]);
assertTest($resProfile['code'] === 400, "Updating shop_name in settings returns 400 (profile module required)");

// Strict types: boolean fields
$resTypeBool = makeRequest('POST', 'settings/update.php', $tokenShop2, ["low_stock_alerts" => "true"]);
assertTest($resTypeBool['code'] === 400, "Passing string 'true' for boolean field returns 400 Strict Type error");

// Strict types: integer fields
$resTypeInt = makeRequest('POST', 'settings/update.php', $tokenShop2, ["lead_time_days" => "5"]);
assertTest($resTypeInt['code'] === 400, "Passing string '5' for integer field returns 400 Strict Type error");

// Out of range: lead_time_days > 60
$resRangeLead = makeRequest('POST', 'settings/update.php', $tokenShop2, ["lead_time_days" => 65]);
assertTest($resRangeLead['code'] === 400, "lead_time_days = 65 (>60) returns 400 Range error");

// Out of range: safety_days < 0
$resRangeSafety = makeRequest('POST', 'settings/update.php', $tokenShop2, ["safety_days" => -1]);
assertTest($resRangeSafety['code'] === 400, "safety_days = -1 (<0) returns 400 Range error");

// Out of range: cover_days < 1
$resRangeCover = makeRequest('POST', 'settings/update.php', $tokenShop2, ["cover_days" => 0]);
assertTest($resRangeCover['code'] === 400, "cover_days = 0 (<1) returns 400 Range error");

// Out of range: min_history_days < 7
$resRangeHist = makeRequest('POST', 'settings/update.php', $tokenShop2, ["min_history_days" => 5]);
assertTest($resRangeHist['code'] === 400, "min_history_days = 5 (<7) returns 400 Range error");

// Out of range: slow_moving_threshold_days < 14
$resRangeSlow = makeRequest('POST', 'settings/update.php', $tokenShop2, ["slow_moving_threshold_days" => 10]);
assertTest($resRangeSlow['code'] === 400, "slow_moving_threshold_days = 10 (<14) returns 400 Range error");

// Invalid theme_mode
$resInvalidTheme = makeRequest('POST', 'settings/update.php', $tokenShop2, ["theme_mode" => "BLUE"]);
assertTest($resInvalidTheme['code'] === 400, "theme_mode = 'BLUE' returns 400 Invalid Enum error");

// Invalid import_mode
$resInvalidImport = makeRequest('POST', 'settings/update.php', $tokenShop2, ["import_mode" => "OVERWRITE"]);
assertTest($resInvalidImport['code'] === 400, "import_mode = 'OVERWRITE' returns 400 Invalid Enum error");

// -------------------------------------------------------------
// 5. VALID UPDATE (Rule 4: Upsert on First Update)
// -------------------------------------------------------------
echo "\n5. Testing Valid Settings Update (First Upsert)...\n";

$updatePayload = [
    "theme_mode" => "DARK",
    "low_stock_alerts" => false,
    "lead_time_days" => 5,
    "safety_days" => 4,
    "cover_days" => 14,
    "min_history_days" => 45,
    "slow_moving_threshold_days" => 90,
    "import_mode" => "REPLACE"
];

$resUpdate = makeRequest('POST', 'settings/update.php', $tokenShop2, $updatePayload);
assertTest($resUpdate['code'] === 200, "POST settings/update.php returns 200 OK");
assertTest($resUpdate['json']['data']['appearance']['theme_mode'] === 'DARK', "theme_mode updated to DARK");
assertTest($resUpdate['json']['data']['notifications']['low_stock_alerts'] === false, "low_stock_alerts updated to false");
assertTest($resUpdate['json']['data']['inventory']['lead_time_days'] === 5, "lead_time_days updated to 5");
assertTest($resUpdate['json']['data']['inventory']['safety_days'] === 4, "safety_days updated to 4");
assertTest($resUpdate['json']['data']['inventory']['cover_days'] === 14, "cover_days updated to 14");
assertTest($resUpdate['json']['data']['inventory']['min_history_days'] === 45, "min_history_days updated to 45");
assertTest($resUpdate['json']['data']['inventory']['slow_moving_threshold_days'] === 90, "slow_moving_threshold_days updated to 90");
assertTest($resUpdate['json']['data']['inventory']['import_mode'] === 'REPLACE', "import_mode updated to REPLACE");

// Verify DB row now exists
$checkRowAfterUpdate = $pdo->query("SELECT id, lead_time_days, theme_mode FROM user_settings WHERE user_id = 2")->fetch();
assertTest($checkRowAfterUpdate && (int)$checkRowAfterUpdate['lead_time_days'] === 5, "Rule 4: Upsert created row in user_settings on first update");

// -------------------------------------------------------------
// 6. REORDER & INSIGHTS INTEGRATION (Reorder reads dynamic settings)
// -------------------------------------------------------------
echo "\n6. Testing Reorder & Insights Dynamic Integration...\n";

// Call reorder/list.php with Shop 2 token -> should reflect lead_time_days = 5, safety_days = 4, cover_days = 14
$resReorder = makeRequest('GET', 'reorder/list.php', $tokenShop2);
assertTest($resReorder['code'] === 200, "GET reorder/list.php returns 200 OK");
$reorderCfg = $resReorder['json']['data']['reorder_config'] ?? [];
assertTest($reorderCfg['lead_time_days'] === 5, "reorder/list.php dynamically used saved lead_time_days = 5");
assertTest($reorderCfg['safety_days'] === 4, "reorder/list.php dynamically used saved safety_days = 4");
assertTest($reorderCfg['cover_days'] === 14, "reorder/list.php dynamically used saved cover_days = 14");
assertTest($reorderCfg['min_history_days'] === 45, "reorder/list.php dynamically used saved min_history_days = 45");

// -------------------------------------------------------------
// 7. SECOND SHOP ISOLATION
// -------------------------------------------------------------
echo "\n7. Testing Multi-Shop Isolation...\n";

$resShop3 = makeRequest('GET', 'settings/get.php', $tokenShop3);
assertTest($resShop3['code'] === 200, "GET settings/get.php for Shop 3 returns 200 OK");
assertTest(
    $resShop3['json']['data']['inventory']['lead_time_days'] === 2,
    "Shop 3 has default lead_time_days = 2 (unaffected by Shop 2's custom settings of 5)"
);

// -------------------------------------------------------------
// 8. IMPORT MODULE INTEGRATION (Rule 2: REPLACE requires confirm_replace)
// -------------------------------------------------------------
echo "\n8. Testing Import Module Integration with REPLACE mode...\n";

// With import_mode = REPLACE for Shop 2, calling execute without confirm_replace fails with 400
$resImpNoConfirm = makeRequest('POST', 'import/execute.php', $tokenShop2, ["file_token" => "imp_2_dummy.csv"]);
assertTest(
    $resImpNoConfirm['code'] === 400 && strpos($resImpNoConfirm['json']['message'], "confirm_replace") !== false,
    "Import execute under REPLACE mode rejects request missing 'confirm_replace: true'"
);

// -------------------------------------------------------------
// 9. CHANGE PASSWORD ENDPOINT & TOKEN REVOCATION
// -------------------------------------------------------------
echo "\n9. Testing Change Password, Security & Token Revocation...\n";

// Create a second active token for Shop 2 (to simulate another active device/session)
$secondTokenRaw = bin2hex(random_bytes(32));
$secondTokenHash = hash('sha256', $secondTokenRaw);
$pdo->prepare("INSERT INTO tokens (user_id, token_hash, expires_at) VALUES (2, ?, NOW() + INTERVAL 7 DAY)")->execute([$secondTokenHash]);

// Verify both tokens are currently valid in DB
$tokenCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM tokens WHERE user_id = 2")->fetchColumn();
assertTest($tokenCountBefore >= 2, "Shop 2 has multiple active tokens before password change ($tokenCountBefore tokens)");

// 9a. Wrong current password -> 400
$resWrongPw = makeRequest('POST', 'settings/change_password.php', $tokenShop2, [
    "current_password" => "wrong_password_xyz",
    "new_password" => "brandNewPassword123!"
]);
assertTest($resWrongPw['code'] === 400, "Wrong current password returns 400 Bad Request");

// 9b. New password same as current password -> 400 (Rule 7)
$resSamePw = makeRequest('POST', 'settings/change_password.php', $tokenShop2, [
    "current_password" => "password123",
    "new_password" => "password123"
]);
assertTest($resSamePw['code'] === 400, "New password same as current password returns 400 Bad Request");

// 9c. New password under 8 characters -> 400
$resShortPw = makeRequest('POST', 'settings/change_password.php', $tokenShop2, [
    "current_password" => "password123",
    "new_password" => "short"
]);
assertTest($resShortPw['code'] === 400, "New password under 8 chars returns 400 Bad Request");

// 9d. Valid password change
$resGoodPw = makeRequest('POST', 'settings/change_password.php', $tokenShop2, [
    "current_password" => "password123",
    "new_password" => "NewSecretPassword2026!"
]);
assertTest($resGoodPw['code'] === 200, "Valid password change returns 200 OK");

// 9e. Verify OLD other tokens revoked
$resOldSession = makeRequest('GET', 'settings/get.php', $secondTokenRaw);
assertTest($resOldSession['code'] === 401, "Other session's token was revoked and now returns 401 Unauthorized");

// 9f. Verify CURRENT token still works
$resCurrentSession = makeRequest('GET', 'settings/get.php', $tokenShop2);
assertTest($resCurrentSession['code'] === 200, "Current session token was preserved and continues to work (200 OK)");

// Reset password back to password123 for other tests
$pdo->prepare("UPDATE owner_account SET password = ? WHERE id = 2")->execute([$defaultHash]);

// -------------------------------------------------------------
// 10. RESET DEFAULTS ENDPOINT
// -------------------------------------------------------------
echo "\n10. Testing POST settings/reset_defaults.php...\n";

$resReset = makeRequest('POST', 'settings/reset_defaults.php', $tokenShop2, []);
assertTest($resReset['code'] === 200, "POST settings/reset_defaults.php returns 200 OK");
assertTest($resReset['json']['data']['appearance']['theme_mode'] === 'SYSTEM', "theme_mode restored to SYSTEM");
assertTest($resReset['json']['data']['inventory']['lead_time_days'] === 2, "lead_time_days restored to 2");
assertTest($resReset['json']['data']['inventory']['safety_days'] === 3, "safety_days restored to 3");
assertTest($resReset['json']['data']['inventory']['cover_days'] === 7, "cover_days restored to 7");
assertTest($resReset['json']['data']['inventory']['import_mode'] === 'ADD', "import_mode restored to ADD");

// -------------------------------------------------------------
// 11. THIN WRAPPERS IN auth/ COMPATIBILITY (Rule 1)
// -------------------------------------------------------------
echo "\n11. Testing auth/ Backward Compatibility Wrappers...\n";

$resAuthGet = makeRequest('GET', 'auth/get_settings.php', $tokenShop2);
assertTest($resAuthGet['code'] === 200, "auth/get_settings.php delegates to settings/get.php (200 OK)");

$resAuthUpdate = makeRequest('POST', 'auth/update_settings.php', $tokenShop2, ["lead_time_days" => 3]);
assertTest($resAuthUpdate['code'] === 200, "auth/update_settings.php delegates to settings/update.php (200 OK)");

// Reset back to defaults for clean state
makeRequest('POST', 'settings/reset_defaults.php', $tokenShop2, []);

// -------------------------------------------------------------
// SUMMARY
// -------------------------------------------------------------
echo "\n============================================\n";
echo "TEST RESULTS: Total Passed: $passCount, Failed: $failCount\n";
echo "============================================\n";

if ($failCount > 0) {
    exit(1);
} else {
    exit(0);
}
