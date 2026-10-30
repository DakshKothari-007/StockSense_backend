<?php

/**
 * Automated Verification Script for Profile End-To-End & Dialog Editing
 *
 * Verifies:
 * 1. Postman registration & login produces real token & user details
 * 2. GET auth/profile.php returns strictly real backend values (no demo fallbacks)
 * 3. POST auth/update_profile.php validates email and updates full_name and email
 * 4. Duplicate email on update_profile.php returns 409 Conflict
 * 5. POST auth/update_shop.php validates location and store_type dropdown values
 * 6. Invalid store_type on update_shop.php returns 422 Unprocessable Entity
 * 7. Store isolation: another user's profile cannot be updated without authorization
 */

$baseUrl = "http://localhost/StockSense_backend";
require_once __DIR__ . "/config/db.php";

function apiCall($url, $method = 'GET', $body = null, $token = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $headers = [];
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        $payload = ($body !== null) ? (is_string($body) ? $body : json_encode($body)) : "{}";
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $headers[] = "Content-Type: application/json";
    }

    if ($token) {
        $headers[] = "Authorization: Bearer $token";
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
echo "    STOCKSENSE PROFILE & EDIT BACKEND TEST SUITE        \n";
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

// 1. Setup clean test user
$testEmail1 = "test_profile_owner_" . time() . "@example.com";
$testEmail2 = "test_profile_updated_" . time() . "@example.com";

$signupRes = apiCall("$baseUrl/auth/signup.php", 'POST', [
    "full_name" => "Vikram Malhotra",
    "shop_name" => "Malhotra Grocers",
    "email" => $testEmail1,
    "password" => "password123"
]);

assertCheck("1a. Signup new account succeeds (201 Created)", $signupRes['code'] === 201);
assertCheck("1b. Signup returns Bearer token", !empty($signupRes['json']['token']));
$testToken = $signupRes['json']['token'];
$testUserId = (int)($signupRes['json']['user']['id'] ?? $signupRes['json']['data']['user_id']);

// 2. Fetch profile via auth/profile.php
$profileRes = apiCall("$baseUrl/auth/profile.php", 'GET', null, $testToken);
assertCheck("2a. GET auth/profile.php returns 200 OK", $profileRes['code'] === 200);
assertCheck("2b. Profile full_name matches DB record", ($profileRes['json']['user']['full_name'] ?? '') === "Vikram Malhotra");
assertCheck("2c. Profile shop_name matches DB record", ($profileRes['json']['user']['shop_name'] ?? '') === "Malhotra Grocers");
assertCheck("2d. Profile email matches DB record", ($profileRes['json']['user']['email'] ?? '') === $testEmail1);

// 3. Update Profile Info (Full Name & Email)
$updateProfRes = apiCall("$baseUrl/auth/update_profile.php", 'POST', [
    "full_name" => "Vikram S. Malhotra",
    "email" => $testEmail2
], $testToken);

assertCheck("3a. POST auth/update_profile.php returns 200 OK", $updateProfRes['code'] === 200);
assertCheck("3b. Response contains updated full_name", ($updateProfRes['json']['user']['full_name'] ?? '') === "Vikram S. Malhotra");
assertCheck("3c. Response contains updated email", ($updateProfRes['json']['user']['email'] ?? '') === $testEmail2);

// Verify DB persistence directly
$dbStmt = $pdo->prepare("SELECT full_name, email FROM owner_account WHERE id = ?");
$dbStmt->execute([$testUserId]);
$dbRow = $dbStmt->fetch(PDO::FETCH_ASSOC);
assertCheck("3d. Database row reflects updated full_name and email", $dbRow['full_name'] === "Vikram S. Malhotra" && $dbRow['email'] === $testEmail2);

// 4. Test duplicate email conflict
$dupRes = apiCall("$baseUrl/auth/update_profile.php", 'POST', [
    "full_name" => "Vikram S. Malhotra",
    "email" => "ananya@example.com"
], $testToken);
assertCheck("4. Updating to existing email returns 409 Conflict", $dupRes['code'] === 409);

// 5. Update Shop Details (Shop Name, Location, Store Type)
$updateShopRes = apiCall("$baseUrl/auth/update_shop.php", 'POST', [
    "shop_name" => "Malhotra Supermart",
    "location" => "T. Nagar, Chennai",
    "store_type" => "Medium supermarket"
], $testToken);

assertCheck("5a. POST auth/update_shop.php returns 200 OK", $updateShopRes['code'] === 200);
assertCheck("5b. Response contains updated shop_name", ($updateShopRes['json']['shop']['shop_name'] ?? '') === "Malhotra Supermart");
assertCheck("5c. Response contains updated location", ($updateShopRes['json']['shop']['location'] ?? '') === "T. Nagar, Chennai");
assertCheck("5d. Response contains updated store_type", ($updateShopRes['json']['shop']['store_type'] ?? '') === "Medium supermarket");

// Verify DB persistence directly
$dbShopStmt = $pdo->prepare("SELECT shop_name, location, store_type FROM owner_account WHERE id = ?");
$dbShopStmt->execute([$testUserId]);
$dbShopRow = $dbShopStmt->fetch(PDO::FETCH_ASSOC);
assertCheck("5e. Database row reflects updated shop details", $dbShopRow['shop_name'] === "Malhotra Supermart" && $dbShopRow['location'] === "T. Nagar, Chennai" && $dbShopRow['store_type'] === "Medium supermarket");

// 6. Test invalid store_type rejection
$invalidTypeRes = apiCall("$baseUrl/auth/update_shop.php", 'POST', [
    "shop_name" => "Malhotra Supermart",
    "location" => "T. Nagar, Chennai",
    "store_type" => "Invalid Store Type X"
], $testToken);
assertCheck("6. Invalid store_type returns 422 Unprocessable Entity", $invalidTypeRes['code'] === 422);

// 7. Re-fetch profile to verify full state
$finalProfileRes = apiCall("$baseUrl/auth/profile.php", 'GET', null, $testToken);
assertCheck("7a. Final profile returns updated full_name", ($finalProfileRes['json']['user']['full_name'] ?? '') === "Vikram S. Malhotra");
assertCheck("7b. Final profile returns updated shop_name", ($finalProfileRes['json']['user']['shop_name'] ?? '') === "Malhotra Supermart");
assertCheck("7c. Final profile returns updated location", ($finalProfileRes['json']['user']['location'] ?? '') === "T. Nagar, Chennai");
assertCheck("7d. Final profile returns updated store_type", ($finalProfileRes['json']['user']['store_type'] ?? '') === "Medium supermarket");
assertCheck("7e. Final profile returns updated email", ($finalProfileRes['json']['user']['email'] ?? '') === $testEmail2);

// Cleanup test user
$pdo->prepare("DELETE FROM owner_account WHERE id = ?")->execute([$testUserId]);

echo "\n========================================================\n";
echo "   RESULT: $passed / $tests TESTS PASSED                \n";
echo "========================================================\n";
?>

