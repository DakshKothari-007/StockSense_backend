<?php

/**
 * Friendly-Only & No-Facial-Hair Avatar Rules Test Suite
 *
 * Verifies:
 * 1. Backend rejects blocked values (rage, monster, eyepatch, sleep, frown, pacifier,
 *    surprise, down, eyelashesDown, maskVariant, maskProbability) AND all facialHair*
 *    options (facialHair, facialHairVariant, facialHairProbability, facialHairColor)
 *    with HTTP 422 and clear error
 * 2. Backend accepts all explicitly allowed friendly values across open-peeps, micah, personas
 * 3. Existing users: legacy DB rows containing facial hair or blocked values (rage,
 *    eyepatch, sleep, frown, mask) are read safely without crashing, stripping facial hair
 *    and mapping blocked values to nearest friendly equivalents (e.g. smile)
 */

$baseUrl = "http://localhost/StockSense_backend";
require_once __DIR__ . "/../config/db.php";

function friendlyApiCall(string $path, string $method = 'POST', $body = null, ?string $token = null): array {
    global $baseUrl;
    $ch = curl_init("$baseUrl/$path");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    $headers = ["Accept: application/json"];
    if ($body !== null) {
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
echo "   STOCKSENSE FRIENDLY-ONLY AVATAR TEST SUITE           \n";
echo "========================================================\n\n";

$tests = 0;
$passed = 0;

function assertFriendlyCheck(string $name, bool $cond, string $details = ''): void {
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
$email = "friendly_avatar_{$suffix}@example.com";
$pw = "FriendlyPass123!";

$signup = friendlyApiCall("auth/signup.php", 'POST', [
    "full_name" => "Friendly Shop Owner",
    "shop_name" => "Friendly Mart",
    "email" => $email,
    "password" => $pw
]);
assertFriendlyCheck("1. Signup succeeds (201)", $signup['code'] === 201, "code={$signup['code']}");
$token = $signup['json']['token'] ?? '';
$userId = (int)($signup['json']['user']['id'] ?? 0);

try {
    // 2. Reject blocked expressions, accessories, masks, and all facial hair options
    $blockedCases = [
        ["label" => "open-peeps rage", "style" => "open-peeps", "opt" => ["expressionVariant" => "rage"]],
        ["label" => "open-peeps monster", "style" => "open-peeps", "opt" => ["expressionVariant" => "monster"]],
        ["label" => "open-peeps angryWithFang", "style" => "open-peeps", "opt" => ["expressionVariant" => "angryWithFang"]],
        ["label" => "open-peeps eyepatch", "style" => "open-peeps", "opt" => ["accessoriesVariant" => "eyepatch"]],
        ["label" => "open-peeps maskVariant", "style" => "open-peeps", "opt" => ["maskVariant" => "medicalMask"]],
        ["label" => "open-peeps maskProbability", "style" => "open-peeps", "opt" => ["maskProbability" => "100"]],
        ["label" => "open-peeps facialHair", "style" => "open-peeps", "opt" => ["facialHair" => "full"]],
        ["label" => "open-peeps facialHairVariant", "style" => "open-peeps", "opt" => ["facialHairVariant" => "full"]],
        ["label" => "open-peeps facialHairProbability=0", "style" => "open-peeps", "opt" => ["facialHairProbability" => "0"]],
        ["label" => "open-peeps facialHairProbability=100", "style" => "open-peeps", "opt" => ["facialHairProbability" => "100"]],
        ["label" => "micah frown", "style" => "micah", "opt" => ["mouthVariant" => "frown"]],
        ["label" => "micah nervous", "style" => "micah", "opt" => ["mouthVariant" => "nervous"]],
        ["label" => "micah eyebrows down", "style" => "micah", "opt" => ["eyebrowsVariant" => "down"]],
        ["label" => "micah eyebrows eyelashesDown", "style" => "micah", "opt" => ["eyebrowsVariant" => "eyelashesDown"]],
        ["label" => "micah facialHair", "style" => "micah", "opt" => ["facialHair" => "beard"]],
        ["label" => "micah facialHairVariant", "style" => "micah", "opt" => ["facialHairVariant" => "beard"]],
        ["label" => "micah facialHairProbability", "style" => "micah", "opt" => ["facialHairProbability" => "0"]],
        ["label" => "personas sleep eyes", "style" => "personas", "opt" => ["eyesVariant" => "sleep"]],
        ["label" => "personas frown mouth", "style" => "personas", "opt" => ["mouthVariant" => "frown"]],
        ["label" => "personas pacifier mouth", "style" => "personas", "opt" => ["mouthVariant" => "pacifier"]],
        ["label" => "personas surprise mouth", "style" => "personas", "opt" => ["mouthVariant" => "surprise"]],
        ["label" => "personas facialHairVariant", "style" => "personas", "opt" => ["facialHairVariant" => "goatee"]],
        ["label" => "personas facialHairProbability", "style" => "personas", "opt" => ["facialHairProbability" => "100"]],
        ["label" => "personas facialHairColor", "style" => "personas", "opt" => ["facialHairColor" => "362c47"]],
    ];

    foreach ($blockedCases as $idx => $case) {
        $res = friendlyApiCall("auth/update_avatar.php", 'POST', [
            "style" => $case["style"],
            "seed" => "friendly_seed_{$idx}",
            "options" => $case["opt"]
        ], $token);
        $ok = ($res['code'] === 422 && ($res['json']['status'] ?? true) === false && !empty($res['json']['message']));
        assertFriendlyCheck("2. Rejects {$case['label']} (422)", $ok, "code={$res['code']} body={$res['body']}");
    }

    // 3. Accept friendly values across all 3 styles
    $resOp = friendlyApiCall("auth/update_avatar.php", 'POST', [
        "style" => "open-peeps",
        "seed" => "op_friendly_1",
        "options" => ["expressionVariant" => "lovingGrin1", "accessoriesProbability" => "100", "accessoriesVariant" => "glasses3"]
    ], $token);
    assertFriendlyCheck("3a. Accepts friendly open-peeps options", $resOp['code'] === 200);

    $resMicah = friendlyApiCall("auth/update_avatar.php", 'POST', [
        "style" => "micah",
        "seed" => "mc_friendly_1",
        "options" => ["mouthVariant" => "laughing", "eyesVariant" => "smilingShadow", "eyebrowsVariant" => "up"]
    ], $token);
    assertFriendlyCheck("3b. Accepts friendly micah options", $resMicah['code'] === 200);

    $resPersonas = friendlyApiCall("auth/update_avatar.php", 'POST', [
        "style" => "personas",
        "seed" => "ps_friendly_1",
        "options" => ["mouthVariant" => "bigSmile", "eyesVariant" => "wink"]
    ], $token);
    assertFriendlyCheck("3c. Accepts friendly personas options", $resPersonas['code'] === 200);

    // 4. Existing user legacy fallback (DB row already has rage, eyepatch, maskVariant, facialHair)
    $legacyOpJson = json_encode([
        "expressionVariant" => "rage",
        "accessoriesProbability" => "100",
        "accessoriesVariant" => "eyepatch",
        "facialHair" => "full",
        "facialHairVariant" => "full",
        "facialHairProbability" => "100",
        "maskVariant" => "medicalMask",
        "maskProbability" => "100",
        "skinColor" => "d08b5b"
    ]);
    $pdo->prepare("UPDATE owner_account SET avatar_style = 'open-peeps', avatar_seed = 'legacy_op_1', avatar_options = ? WHERE id = ?")
        ->execute([$legacyOpJson, $userId]);

    $profLegacyOp = friendlyApiCall("auth/profile.php", 'GET', null, $token);
    $opOpts = $profLegacyOp['json']['user']['avatar']['options'] ?? [];
    assertFriendlyCheck(
        "4a. Legacy open-peeps rage/eyepatch/mask/facialHair strips beard & mask and maps rage->smile",
        $profLegacyOp['code'] === 200
            && ($opOpts['expressionVariant'] ?? '') === 'smile'
            && !isset($opOpts['accessoriesVariant'])
            && ($opOpts['accessoriesProbability'] ?? '') === '0'
            && !isset($opOpts['maskVariant'])
            && !isset($opOpts['maskProbability'])
            && !isset($opOpts['facialHair'])
            && !isset($opOpts['facialHairVariant'])
            && !isset($opOpts['facialHairProbability'])
            && ($opOpts['skinColor'] ?? '') === 'd08b5b',
        json_encode($opOpts)
    );

    // Legacy personas sleep, frown, & facialHair -> open, smile, beard stripped
    $legacyPsJson = json_encode([
        "eyesVariant" => "sleep",
        "mouthVariant" => "frown",
        "facialHairVariant" => "goatee",
        "facialHairProbability" => "100",
        "facialHairColor" => "362c47",
        "skinColor" => "e5a07e"
    ]);
    $pdo->prepare("UPDATE owner_account SET avatar_style = 'personas', avatar_seed = 'legacy_ps_1', avatar_options = ? WHERE id = ?")
        ->execute([$legacyPsJson, $userId]);

    $profLegacyPs = friendlyApiCall("auth/profile.php", 'GET', null, $token);
    $psOpts = $profLegacyPs['json']['user']['avatar']['options'] ?? [];
    assertFriendlyCheck(
        "4b. Legacy personas sleep/frown/facialHair maps cleanly and strips facial hair",
        $profLegacyPs['code'] === 200
            && ($psOpts['eyesVariant'] ?? '') === 'open'
            && ($psOpts['mouthVariant'] ?? '') === 'smile'
            && ($psOpts['skinColor'] ?? '') === 'e5a07e'
            && !isset($psOpts['facialHairVariant'])
            && !isset($psOpts['facialHairProbability'])
            && !isset($psOpts['facialHairColor']),
        json_encode($psOpts)
    );

} finally {
    $pdo->exec("DELETE FROM rate_limits WHERE action = 'signup'");
    if ($userId > 0) {
        $pdo->prepare("DELETE FROM tokens WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare("DELETE FROM owner_account WHERE id = ?")->execute([$userId]);
    }
}

echo "\n========================================================\n";
echo "   RESULT: $passed / $tests TESTS PASSED                \n";
echo "========================================================\n";
if ($passed < $tests) {
    exit(1);
}
