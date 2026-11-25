<?php

/**
 * Automated Verification Suite for User Avatars
 *
 * Verifies:
 * 1. New account starts with avatar = null in login, profile, settings
 * 2. Saving valid open-peeps, micah, and personas avatars persists cleanly
 * 3. Login, profile, and settings endpoints all return the saved avatar code
 * 4. Remove avatar endpoint sets avatar back to null cleanly
 * 5. Strict validation rejects: invalid style, bad seed characters/length,
 *    unknown/cross-style option keys, disallowed option values, and > 1 KB options JSON
 * 6. Multi-tenant isolation: another user cannot change my avatar
 * 7. Unauthenticated requests return 401
 * 8. Delete account removes the user and all avatar data completely
 */

$baseUrl = "http://localhost/StockSense_backend";
require_once __DIR__ . "/../config/db.php";

function avatarApiCall(string $path, string $method = 'POST', $body = null, ?string $token = null, ?string $rawBody = null): array {
    global $baseUrl;
    $ch = curl_init("$baseUrl/$path");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    $headers = ["Accept: application/json"];
    if ($rawBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
        $headers[] = "Content-Type: application/json";
    } elseif ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $headers[] = "Content-Type: application/json";
    }

    if ($token !== null) {
        $headers[] = "Authorization: Bearer $token";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

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
echo "      STOCKSENSE AVATAR BACKEND TEST SUITE              \n";
echo "========================================================\n\n";

$tests = 0;
$passed = 0;

function assertAvatarCheck(string $name, bool $cond, string $details = ''): void {
    global $tests, $passed;
    $tests++;
    if ($cond) {
        echo " [PASS] $name\n";
        $passed++;
    } else {
        echo "![FAIL] $name" . ($details !== '' ? " - $details" : "") . "\n";
    }
}

$pdo->exec("DELETE FROM rate_limits");
$pdo->exec("DELETE FROM login_attempts");

$suffix = time() . "_" . random_int(100, 999);
$emailA = "avatar_user_a_{$suffix}@example.com";
$emailB = "avatar_user_b_{$suffix}@example.com";
$pw = "AvatarPass123!";

// Create User A and User B via signup
$signupA = avatarApiCall("auth/signup.php", 'POST', [
    "full_name" => "User Alpha",
    "shop_name" => "Alpha Store",
    "email" => $emailA,
    "password" => $pw
]);
$signupB = avatarApiCall("auth/signup.php", 'POST', [
    "full_name" => "User Beta",
    "shop_name" => "Beta Store",
    "email" => $emailB,
    "password" => $pw
]);

assertAvatarCheck("1a. User A signup succeeds (201)", $signupA['code'] === 201, "code={$signupA['code']}");
assertAvatarCheck("1b. User B signup succeeds (201)", $signupB['code'] === 201, "code={$signupB['code']}");

$tokenA = $signupA['json']['token'] ?? '';
$userIdA = (int)($signupA['json']['user']['id'] ?? 0);
$tokenB = $signupB['json']['token'] ?? '';
$userIdB = (int)($signupB['json']['user']['id'] ?? 0);

// Clean up any leftover failure
try {
    // 2. Unauthenticated rejection
    $unauthSave = avatarApiCall("auth/update_avatar.php", 'POST', [
        "style" => "open-peeps",
        "seed" => "seed123",
        "options" => []
    ]);
    assertAvatarCheck("2a. Unauthenticated update_avatar returns 401", $unauthSave['code'] === 401);

    $unauthRemove = avatarApiCall("auth/remove_avatar.php", 'POST', []);
    assertAvatarCheck("2b. Unauthenticated remove_avatar returns 401", $unauthRemove['code'] === 401);

    // 3. Initial null avatar on profile & settings & login
    $loginInit = avatarApiCall("auth/login.php", 'POST', ["email" => $emailA, "password" => $pw]);
    assertAvatarCheck("3a. Initial login returns avatar = null", array_key_exists('avatar', $loginInit['json']['user'] ?? []) && $loginInit['json']['user']['avatar'] === null);
    $tokenA = $loginInit['json']['token'] ?? $tokenA;

    $profInit = avatarApiCall("auth/profile.php", 'GET', null, $tokenA);
    assertAvatarCheck("3b. Initial profile returns avatar = null", array_key_exists('avatar', $profInit['json']['user'] ?? []) && $profInit['json']['user']['avatar'] === null);

    $settingsInit = avatarApiCall("settings/get.php", 'GET', null, $tokenA);
    assertAvatarCheck("3c. Initial settings account returns avatar = null", array_key_exists('avatar', $settingsInit['json']['data']['account'] ?? []) && $settingsInit['json']['data']['account']['avatar'] === null);

    // 4. Save valid avatar (open-peeps)
    $saveOpenPeeps = avatarApiCall("auth/update_avatar.php", 'POST', [
        "style" => "open-peeps",
        "seed" => "av_k9m2p7x4_1",
        "options" => [
            "skinColor" => "d08b5b",
            "headVariant" => "short1",
            "clothingColor" => "8fa7df",
            "accessoriesProbability" => "100",
            "accessoriesVariant" => "glasses",
            "backgroundColor" => "fff4dc"
        ]
    ], $tokenA);
    assertAvatarCheck("4a. Save open-peeps avatar returns 200", $saveOpenPeeps['code'] === 200, $saveOpenPeeps['body']);
    assertAvatarCheck("4b. Response returns normalized avatar style & seed", ($saveOpenPeeps['json']['avatar']['style'] ?? '') === "open-peeps" && ($saveOpenPeeps['json']['avatar']['seed'] ?? '') === "av_k9m2p7x4_1");

    // Verify login, profile, settings all return the saved avatar
    $loginSaved = avatarApiCall("auth/login.php", 'POST', ["email" => $emailA, "password" => $pw]);
    assertAvatarCheck("4c. Login returns saved avatar", ($loginSaved['json']['user']['avatar']['seed'] ?? '') === "av_k9m2p7x4_1" && ($loginSaved['json']['user']['avatar']['options']['headVariant'] ?? '') === "short1");
    $tokenA = $loginSaved['json']['token'] ?? $tokenA;

    $profSaved = avatarApiCall("auth/profile.php", 'GET', null, $tokenA);
    assertAvatarCheck("4d. Profile returns saved avatar", ($profSaved['json']['user']['avatar']['style'] ?? '') === "open-peeps" && ($profSaved['json']['user']['avatar']['options']['accessoriesVariant'] ?? '') === "glasses");

    $settingsSaved = avatarApiCall("settings/get.php", 'GET', null, $tokenA);
    assertAvatarCheck("4e. Settings returns saved avatar in account", ($settingsSaved['json']['data']['account']['avatar']['style'] ?? '') === "open-peeps" && ($settingsSaved['json']['data']['account']['avatar']['options']['skinColor'] ?? '') === "d08b5b");

    // 5. Save micah and personas avatars
    $saveMicah = avatarApiCall("auth/update_avatar.php", 'POST', [
        "style" => "micah",
        "seed" => "Micah-Seed_99",
        "options" => [
            "baseColor" => "f9c9b6",
            "hairVariant" => "fonze",
            "hairColor" => "000000",
            "eyesVariant" => "smiling",
            "clothesVariant" => "crew",
            "shirtColor" => "9287ff",
            "backgroundColor" => "dce6ff"
        ]
    ], $tokenA);
    assertAvatarCheck("5a. Save micah avatar returns 200", $saveMicah['code'] === 200, $saveMicah['body']);

    $savePersonas = avatarApiCall("auth/update_avatar.php", 'POST', [
        "style" => "personas",
        "seed" => "PsSeed_42",
        "options" => [
            "skinColor" => "e5a07e",
            "hairVariant" => "curly",
            "hairColor" => "362c47",
            "eyesVariant" => "happy",
            "clothesVariant" => "squared",
            "clothingColor" => "456dff",
            "backgroundColor" => "ffc93c"
        ]
    ], $tokenA);
    assertAvatarCheck("5b. Save personas avatar returns 200", $savePersonas['code'] === 200, $savePersonas['body']);

    // 6. Strict validation rejections
    $badStyle = avatarApiCall("auth/update_avatar.php", 'POST', [
        "style" => "avataaars",
        "seed" => "validSeed1",
        "options" => []
    ], $tokenA);
    assertAvatarCheck("6a. Invalid style returns 422", $badStyle['code'] === 422);

    $badSeedChars = avatarApiCall("auth/update_avatar.php", 'POST', [
        "style" => "micah",
        "seed" => "owner@example.com",
        "options" => []
    ], $tokenA);
    assertAvatarCheck("6b. Seed with @/email fails validation (422)", $badSeedChars['code'] === 422);

    $badSeedLength = avatarApiCall("auth/update_avatar.php", 'POST', [
        "style" => "micah",
        "seed" => str_repeat("a", 65),
        "options" => []
    ], $tokenA);
    assertAvatarCheck("6c. Seed > 64 chars returns 422", $badSeedLength['code'] === 422);

    $crossStyleOpt = avatarApiCall("auth/update_avatar.php", 'POST', [
        "style" => "open-peeps",
        "seed" => "validSeed1",
        "options" => ["eyesVariant" => "smiling"]
    ], $tokenA);
    assertAvatarCheck("6d. Cross-style unsupported option rejected (422)", $crossStyleOpt['code'] === 422);

    $badOptVal = avatarApiCall("auth/update_avatar.php", 'POST', [
        "style" => "personas",
        "seed" => "validSeed1",
        "options" => ["hairVariant" => "nonexistent_hair"]
    ], $tokenA);
    assertAvatarCheck("6e. Disallowed option value rejected (422)", $badOptVal['code'] === 422);

    $badColor = avatarApiCall("auth/update_avatar.php", 'POST', [
        "style" => "personas",
        "seed" => "validSeed1",
        "options" => ["backgroundColor" => "#ff00ff"]
    ], $tokenA);
    assertAvatarCheck("6f. Disallowed color value rejected (422)", $badColor['code'] === 422);

    // Oversized JSON (> 1 KB)
    $hugeOptions = [];
    for ($i = 0; $i < 60; $i++) {
        $hugeOptions["extraKey_{$i}"] = str_repeat("x", 20);
    }
    $tooLarge = avatarApiCall("auth/update_avatar.php", 'POST', [
        "style" => "micah",
        "seed" => "validSeed1",
        "options" => $hugeOptions
    ], $tokenA);
    assertAvatarCheck("6g. Options JSON > 1 KB rejected (422)", $tooLarge['code'] === 422);

    // 7. Multi-tenant isolation: User B cannot change User A's avatar even with user_id in payload
    $attackRes = avatarApiCall("auth/update_avatar.php", 'POST', [
        "user_id" => $userIdA,
        "style" => "open-peeps",
        "seed" => "hacked_seed",
        "options" => []
    ], $tokenB);
    assertAvatarCheck("7a. Passing spoofed user_id in body is rejected (422) or ignored for User A", $attackRes['code'] === 422);

    // Even if User B saves a valid avatar for themselves, User A keeps Personas PsSeed_42
    avatarApiCall("auth/update_avatar.php", 'POST', [
        "style" => "open-peeps",
        "seed" => "beta_seed_1",
        "options" => []
    ], $tokenB);
    $profAStill = avatarApiCall("auth/profile.php", 'GET', null, $tokenA);
    assertAvatarCheck("7b. User A avatar untouched by User B's updates", ($profAStill['json']['user']['avatar']['seed'] ?? '') === "PsSeed_42");

    // 8. Remove avatar
    $removeRes = avatarApiCall("auth/remove_avatar.php", 'POST', [], $tokenA);
    assertAvatarCheck("8a. Remove avatar returns 200 OK", $removeRes['code'] === 200 && $removeRes['json']['avatar'] === null);

    $profAfterRemove = avatarApiCall("auth/profile.php", 'GET', null, $tokenA);
    assertAvatarCheck("8b. Profile returns avatar = null after remove", $profAfterRemove['json']['user']['avatar'] === null);

    $settingsAfterRemove = avatarApiCall("settings/get.php", 'GET', null, $tokenA);
    assertAvatarCheck("8c. Settings returns avatar = null after remove", $settingsAfterRemove['json']['data']['account']['avatar'] === null);

    $loginAfterRemove = avatarApiCall("auth/login.php", 'POST', ["email" => $emailA, "password" => $pw]);
    assertAvatarCheck("8d. Login returns avatar = null after remove", $loginAfterRemove['json']['user']['avatar'] === null);
    $tokenA = $loginAfterRemove['json']['token'] ?? $tokenA;

    // 9. Re-save avatar, then Delete Account -> verifies avatar columns are completely deleted
    $resave = avatarApiCall("auth/update_avatar.php", 'POST', [
        "style" => "micah",
        "seed" => "BeforeDelete_1",
        "options" => ["backgroundColor" => "ffc93c"]
    ], $tokenA);
    assertAvatarCheck("9a. Avatar re-saved before account deletion", $resave['code'] === 200);

    $delA = avatarApiCall("auth/delete_account.php", 'POST', [
        "current_password" => $pw,
        "confirm_text" => "DELETE"
    ], $tokenA);
    assertAvatarCheck("9b. Delete account succeeds (200)", $delA['code'] === 200);

    $checkRowA = $pdo->prepare("SELECT avatar_style, avatar_seed, avatar_options FROM owner_account WHERE id = ?");
    $checkRowA->execute([$userIdA]);
    $rowAfterDel = $checkRowA->fetch(PDO::FETCH_ASSOC);
    assertAvatarCheck("9c. Account & avatar data completely removed on delete_account", $rowAfterDel === false);

} finally {
    $pdo->exec("DELETE FROM rate_limits WHERE action = 'signup'");
    // Clean up User A (if delete test failed) and User B
    if ($userIdA > 0) {
        $pdo->prepare("DELETE FROM tokens WHERE user_id = ?")->execute([$userIdA]);
        $pdo->prepare("DELETE FROM owner_account WHERE id = ?")->execute([$userIdA]);
    }
    if ($userIdB > 0) {
        $pdo->prepare("DELETE FROM tokens WHERE user_id = ?")->execute([$userIdB]);
        $pdo->prepare("DELETE FROM owner_account WHERE id = ?")->execute([$userIdB]);
    }
}

echo "\n========================================================\n";
echo "   RESULT: $passed / $tests TESTS PASSED                \n";
echo "========================================================\n";
if ($passed < $tests) {
    exit(1);
}
