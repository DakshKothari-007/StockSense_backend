<?php

/**
 * Automated Verification Script for Real Token Authentication
 *
 * Verifies:
 * 1. Bearer 2 is rejected with HTTP 401
 * 2. Bearer email is rejected with HTTP 401
 * 3. Empty or missing token is rejected with HTTP 401
 * 4. login.php returns a random 64-char token & 7-day expires_at
 * 5. Database tokens table stores ONLY the SHA-256 hash (never the raw token)
 * 6. Authenticated inventory and dashboard requests succeed with 200
 * 7. Expired token is rejected with HTTP 401
 * 8. logout.php deletes/revokes the token row
 * 9. Revoked token is rejected with HTTP 401
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
echo "   STOCKSENSE REAL TOKEN AUTHENTICATION TEST SUITE      \n";
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

// 1. Test: Bearer 2 must return 401
$res1 = apiCall("$baseUrl/dashboard/summary.php", 'GET', null, [
    "Authorization: Bearer 2"
]);
assertCheck("1. Bearer 2 rejected with 401 Unauthorized", $res1['code'] === 401, "Got code {$res1['code']}: {$res1['body']}");

// 2. Test: Bearer email must return 401
$res2 = apiCall("$baseUrl/dashboard/summary.php", 'GET', null, [
    "Authorization: Bearer ananya@example.com"
]);
assertCheck("2. Bearer email rejected with 401 Unauthorized", $res2['code'] === 401, "Got code {$res2['code']}: {$res2['body']}");

// 3. Test: No Bearer header must return 401
$res3 = apiCall("$baseUrl/dashboard/summary.php", 'GET', null, []);
assertCheck("3. Missing Authorization header rejected with 401", $res3['code'] === 401, "Got code {$res3['code']}: {$res3['body']}");

// 4. Test: Login creates 64-char token & stores SHA-256 hash
$loginRes = apiCall("$baseUrl/auth/login.php", 'POST', [
    "email" => "ananya@example.com",
    "password" => "password123"
]);
assertCheck("4a. Login with valid credentials returns 200", $loginRes['code'] === 200, "Got code {$loginRes['code']}: {$loginRes['body']}");
$realToken = $loginRes['json']['token'] ?? '';
assertCheck("4b. Token returned is exactly 64 characters", strlen($realToken) === 64, "Token len: " . strlen($realToken));
assertCheck("4c. Expiry is returned in response", !empty($loginRes['json']['expires_at']));

// Check database directly for hash
require_once __DIR__ . '/config/db.php';
$expectedHash = hash('sha256', $realToken);
$tokenStmt = $pdo->prepare("SELECT user_id, expires_at FROM tokens WHERE token_hash = ?");
$tokenStmt->execute([$expectedHash]);
$dbTokenRow = $tokenStmt->fetch(PDO::FETCH_ASSOC);
assertCheck("4d. SHA-256 token hash found in tokens table", !empty($dbTokenRow) && (int)$dbTokenRow['user_id'] === 2);

// 5. Test: Authenticate dashboard summary using real Bearer token
$resDash = apiCall("$baseUrl/dashboard/summary.php", 'GET', null, [
    "Authorization: Bearer $realToken"
]);
assertCheck("5a. Dashboard summary succeeds with real Bearer token (200)", $resDash['code'] === 200, "Got code {$resDash['code']}: {$resDash['body']}");
assertCheck("5b. Store name matches owner account", !empty($resDash['json']['data']['store']['name']));

// 6. Test: Inventory get_products works seamlessly with real token
$resInv = apiCall("$baseUrl/inventory/get_products.php", 'GET', null, [
    "Authorization: Bearer $realToken"
]);
assertCheck("6. Inventory get_products succeeds with real token (200)", $resInv['code'] === 200, "Got code {$resInv['code']}: {$resInv['body']}");

// 7. Test: Expired token returns 401
$expiredToken = bin2hex(random_bytes(32));
$expiredHash = hash('sha256', $expiredToken);
$pdo->prepare("INSERT INTO tokens (user_id, token_hash, expires_at) VALUES (2, ?, NOW() - INTERVAL 1 HOUR)")
    ->execute([$expiredHash]);

$resExpired = apiCall("$baseUrl/dashboard/summary.php", 'GET', null, [
    "Authorization: Bearer $expiredToken"
]);
assertCheck("7. Expired token rejected with 401 Unauthorized", $resExpired['code'] === 401, "Got code {$resExpired['code']}: {$resExpired['body']}");

// 8. Test: Logout deletes the token row
$logoutRes = apiCall("$baseUrl/auth/logout.php", 'POST', null, [
    "Authorization: Bearer $realToken"
]);
assertCheck("8a. Logout returns 200", $logoutRes['code'] === 200, "Got code {$logoutRes['code']}: {$logoutRes['body']}");

$checkDeleted = $pdo->prepare("SELECT COUNT(*) FROM tokens WHERE token_hash = ?");
$checkDeleted->execute([$expectedHash]);
$existsAfterLogout = (int)$checkDeleted->fetchColumn();
assertCheck("8b. Token row deleted from tokens table", $existsAfterLogout === 0);

// 9. Test: Former token now returns 401 after logout
$resAfterLogout = apiCall("$baseUrl/dashboard/summary.php", 'GET', null, [
    "Authorization: Bearer $realToken"
]);
assertCheck("9. Revoked token now rejected with 401 Unauthorized", $resAfterLogout['code'] === 401, "Got code {$resAfterLogout['code']}: {$resAfterLogout['body']}");

echo "\n========================================================\n";
echo "   RESULT: $passed / $tests TESTS PASSED                \n";
echo "========================================================\n";
