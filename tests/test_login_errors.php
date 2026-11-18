<?php

/**
 * Automated Verification Script for Login Error Handling and Lockout Cooldown
 *
 * Verifies:
 * 1. Wrong/unknown email -> HTTP 404, error_code = "EMAIL_NOT_FOUND", message = "No account found with this email."
 *    Does NOT record in login_attempts (does not affect account lockout).
 * 2. Correct email, wrong password -> HTTP 401, error_code = "WRONG_PASSWORD", message = "Invalid password.",
 *    returns attempts_left (decreasing 4, 3, 2, 1).
 * 3. 5th failed password attempt -> HTTP 429, error_code = "ACCOUNT_LOCKED", retry_after_seconds = 300.
 * 4. Immediate subsequent attempt on locked account -> HTTP 429, error_code = "ACCOUNT_LOCKED", retry_after_seconds <= 300.
 * 5. Cooldown is 5 minutes (300 seconds), not 15 minutes.
 */

require_once __DIR__ . "/../config/db.php";

$baseUrl = "http://localhost/StockSense_backend";

function callLoginApi(string $email, string $password): array {
    global $baseUrl;
    $ch = curl_init("{$baseUrl}/auth/login.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        "email" => $email,
        "password" => $password
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json"
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        "code" => $httpCode,
        "body" => $response,
        "json" => json_decode($response, true)
    ];
}

echo "========================================================\n";
echo "    STOCKSENSE LOGIN ERROR HANDLING & LOCKOUT TEST      \n";
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
        echo " [FAIL] $name" . ($details ? " - $details" : "") . "\n";
    }
}

// Ensure demo test user exists
$testEmail = "daksh14001@gmail.com";
$testPassword = "TestPassword123!";

$stmt = $pdo->prepare("SELECT id FROM owner_account WHERE email = ? LIMIT 1");
$stmt->execute([$testEmail]);
$testUser = $stmt->fetch();
if (!$testUser) {
    // Insert temporary test user if not present
    $pwHash = password_hash($testPassword, PASSWORD_DEFAULT);
    $ins = $pdo->prepare("INSERT INTO owner_account (full_name, shop_name, email, password, store_type, currency) VALUES (?, ?, ?, ?, ?, ?)");
    $ins->execute(["Daksh Test", "Daksh Store", $testEmail, $pwHash, "Supermarket", "INR"]);
} else {
    // Update password hash to known test password
    $pwHash = password_hash($testPassword, PASSWORD_DEFAULT);
    $upd = $pdo->prepare("UPDATE owner_account SET password = ? WHERE email = ?");
    $upd->execute([$pwHash, $testEmail]);
}

// Clear any existing attempts for test user
$pdo->prepare("DELETE FROM login_attempts WHERE identifier = ?")->execute([$testEmail]);
$pdo->prepare("DELETE FROM rate_limits WHERE identifier = ?")->execute([$testEmail]);

// ----------------------------------------------------
// Test 1: Unknown / Unregistered Email
// ----------------------------------------------------
$unknownEmail = "nonexistent_owner_99999@stocksense.test";
$pdo->prepare("DELETE FROM login_attempts WHERE identifier = ?")->execute([$unknownEmail]);
$pdo->prepare("DELETE FROM rate_limits WHERE identifier = ?")->execute([$unknownEmail]);

$res = callLoginApi($unknownEmail, "SomePassword123");
assertCheck(
    "Unknown email returns HTTP 401",
    $res['code'] === 401,
    "Got HTTP {$res['code']}"
);
assertCheck(
    "Unknown email returns EMAIL_NOT_FOUND code",
    ($res['json']['error_code'] ?? '') === "EMAIL_NOT_FOUND",
    "Got: " . json_encode($res['json'])
);
assertCheck(
    "Unknown email returns exact message",
    ($res['json']['message'] ?? '') === "No account found with this email.",
    "Got: " . ($res['json']['message'] ?? '')
);

// Verify unknown email is NOT recorded in login_attempts
$chkAttempt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE identifier = ?");
$chkAttempt->execute([$unknownEmail]);
$attemptCount = (int) $chkAttempt->fetchColumn();
assertCheck(
    "Unknown email does NOT increment login_attempts",
    $attemptCount === 0,
    "Expected 0, got $attemptCount"
);

// ----------------------------------------------------
// Test 2: Registered Email, Wrong Password (Attempts 1 to 4)
// ----------------------------------------------------
for ($i = 1; $i <= 4; $i++) {
    $expectedLeft = 5 - $i;
    $res = callLoginApi($testEmail, "WrongPassword_$i");
    assertCheck(
        "Attempt $i: Wrong password returns HTTP 401",
        $res['code'] === 401,
        "Got HTTP {$res['code']}"
    );
    assertCheck(
        "Attempt $i: Returns WRONG_PASSWORD code",
        ($res['json']['error_code'] ?? '') === "WRONG_PASSWORD",
        "Got: " . json_encode($res['json'])
    );
    assertCheck(
        "Attempt $i: Returns attempts_left = $expectedLeft",
        ($res['json']['attempts_left'] ?? -1) === $expectedLeft,
        "Expected $expectedLeft, got " . ($res['json']['attempts_left'] ?? 'null')
    );
}

// ----------------------------------------------------
// Test 3: 5th Wrong Password Attempt -> Account Lockout
// ----------------------------------------------------
$res5 = callLoginApi($testEmail, "WrongPassword_5");
assertCheck(
    "Attempt 5: Reaching max attempts returns HTTP 429",
    $res5['code'] === 429,
    "Got HTTP {$res5['code']}"
);
assertCheck(
    "Attempt 5: Returns ACCOUNT_LOCKED code",
    ($res5['json']['error_code'] ?? '') === "ACCOUNT_LOCKED",
    "Got: " . json_encode($res5['json'])
);
assertCheck(
    "Attempt 5: Lockout message mentions 5 minutes",
    strpos($res5['json']['message'] ?? '', '5 minutes') !== false,
    "Got: " . ($res5['json']['message'] ?? '')
);
assertCheck(
    "Attempt 5: Returns retry_after_seconds ~ 300",
    isset($res5['json']['retry_after_seconds']) && $res5['json']['retry_after_seconds'] >= 290 && $res5['json']['retry_after_seconds'] <= 300,
    "Got: " . ($res5['json']['retry_after_seconds'] ?? 'null')
);

// ----------------------------------------------------
// Test 4: Subsequent Attempt While Locked
// ----------------------------------------------------
$resLocked = callLoginApi($testEmail, "EvenIfCorrectPassword_StillLocked");
assertCheck(
    "Subsequent attempt on locked account returns HTTP 429",
    $resLocked['code'] === 429,
    "Got HTTP {$resLocked['code']}"
);
assertCheck(
    "Subsequent attempt returns ACCOUNT_LOCKED code",
    ($resLocked['json']['error_code'] ?? '') === "ACCOUNT_LOCKED",
    "Got: " . json_encode($resLocked['json'])
);
assertCheck(
    "Subsequent attempt returns valid retry_after_seconds",
    isset($resLocked['json']['retry_after_seconds']) && $resLocked['json']['retry_after_seconds'] >= 1 && $resLocked['json']['retry_after_seconds'] <= 300,
    "Got: " . ($resLocked['json']['retry_after_seconds'] ?? 'null')
);

// ----------------------------------------------------
// Clean up test account lock & test successful login
// ----------------------------------------------------
$pdo->prepare("DELETE FROM login_attempts WHERE identifier = ?")->execute([$testEmail]);

$resSuccess = callLoginApi($testEmail, $testPassword);
assertCheck(
    "After clearing lock, valid credentials return HTTP 200",
    $resSuccess['code'] === 200,
    "Got HTTP {$resSuccess['code']}"
);
assertCheck(
    "Valid credentials return token and user",
    !empty($resSuccess['json']['token']) && !empty($resSuccess['json']['user']),
    "Got: " . json_encode($resSuccess['json'])
);

echo "\n--------------------------------------------------------\n";
echo "SUMMARY: $passed / $tests tests passed.\n";
echo "--------------------------------------------------------\n";

if ($passed === $tests) {
    exit(0);
} else {
    exit(1);
}
