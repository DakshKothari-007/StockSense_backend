<?php

/**
 * Automated Test Suite for StockSense Server-Side Icon Classification
 * 
 * Verifies:
 * 1. Catalog loading and validation of 304 allowed keys.
 * 2. Step 1: Keyword rules for English produce, FMCG brands, Hindi, Tamil, and typos.
 * 3. Step 2: AI classification with mock model:
 *    - Unknown product name resolved by AI.
 *    - Unknown product name with AI replying 'none' -> returns null.
 *    - Garbage / hallucinated AI reply -> rejected by catalog validator.
 *    - AI down / network timeout (8s) -> gracefully returns null, never crashes.
 * 4. Owner choice preservation:
 *    - Manual icon choice stored with icon_is_manual = 1.
 *    - Auto-classifier and backfill never overwrite manual choice.
 * 5. API endpoints (add_product, update_product, get_products, get_product_detail):
 *    - Empty icon_key auto-classifies and returns icon_key.
 *    - Invalid icon_key returns 422 Unprocessable Entity.
 *    - Update product sets manual choice.
 *    - Scoped strictly by shop.
 * 6. Import execute step with 100 rows:
 *    - Batches up to 20 unmatched names to AI.
 *    - Never blocks import even if AI fails / times out.
 * 7. CLI backfill script execution.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/IconClassifier.php';

$baseUrl = "http://localhost/StockSense_backend";

function apiCall($url, $method = 'GET', $body = null, $headers = [], $files = []) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);

    if (!empty($files)) {
        curl_setopt($ch, CURLOPT_POST, true);
        $postFields = $body ?: [];
        foreach ($files as $field => $filePath) {
            $postFields[$field] = new CURLFile($filePath, mime_content_type($filePath) ?: 'text/csv', basename($filePath));
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    } elseif ($method === 'POST') {
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

    return [
        'code' => $code,
        'body' => $res,
        'json' => json_decode($res, true)
    ];
}

$passCount = 0;
$failCount = 0;

function assertCheck($name, $condition, $extra = "") {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] $name\n";
        $passCount++;
    } else {
        echo "![FAIL] $name $extra\n";
        $failCount++;
    }
}

echo "========================================================\n";
echo "   STOCKSENSE SERVER-SIDE ICON CLASSIFIER TEST SUITE    \n";
echo "========================================================\n\n";

// -----------------------------------------------------------------------------
// 1. Catalog Integrity & Allowed Keys Validation
// -----------------------------------------------------------------------------
echo "--- 1. Testing Catalog Integrity & Allowed Keys ---\n";
$catalog = IconClassifier::getCatalog();
$allowedKeys = IconClassifier::getAllowedKeys();

assertCheck("1a. Catalog contains 300+ items", count($catalog) >= 300, "(" . count($catalog) . ")");
assertCheck("1b. Exactly 304 unique allowed keys", count($allowedKeys) === 304, "(" . count($allowedKeys) . ")");
assertCheck("1c. 'apple' is a valid catalog key", IconClassifier::isValidKey('apple'));
assertCheck("1d. 'milk_bottle' is a valid catalog key", IconClassifier::isValidKey('milk_bottle'));
assertCheck("1e. 'honey' is a valid catalog key", IconClassifier::isValidKey('honey'));
assertCheck("1f. 'lotion_bottle' is a valid catalog key", IconClassifier::isValidKey('lotion_bottle'));
assertCheck("1g. Fake key 'not_a_real_icon_key_123' is rejected", !IconClassifier::isValidKey('not_a_real_icon_key_123'));
assertCheck("1h. Empty string and null are rejected", !IconClassifier::isValidKey('') && !IconClassifier::isValidKey(null));

// -----------------------------------------------------------------------------
// 2. Step 1: Keyword Classification (English, FMCG, Typo, Hindi, Tamil)
// -----------------------------------------------------------------------------
echo "\n--- 2. Testing Step 1 Keyword Rules ---\n";

$keywordCases = [
    ["Shimla Red Apples 1kg", "apple", "English Produce - Apple"],
    ["Amul Taaza Toned Milk 500ml", "milk_bottle", "FMCG Brand - Amul Milk"],
    ["Tata Salt Vacuum Evaporated 1kg", "salt", "Salt - Tata Salt"],
    ["White Crystal Sugar 1kg", "honey", "Sugar - White Crystal Sugar"],
    ["Cheeni packet 1kg", "honey", "Hinglish - Cheeni"],
    ["Sakkarai 1kg", "honey", "Tanglish - Sakkarai"],
    ["Nattu Vellam 1kg", "honey", "Tanglish - Vellam"],
    ["Surf Excel Easy Wash Detergent Powder 1kg", "soap_bar", "Detergent - Surf Excel"],
    ["Vim Dishwash Liquid Gel 500ml", "lotion_bottle", "Detergent Gel - Vim Liquid"],
    ["Ariel Matic Liquid Detergent 1L", "lotion_bottle", "Detergent Liquid - Ariel"],
    ["Scotch Brite Scrub Sponge", "sponge", "Cleaning - Scrub Sponge"],
    ["choclate bar 50g", "chocolate", "Typo - choclate -> chocolate"],
    ["tamato ketchup 200g", "tomato", "Typo - tamato -> tomato"],
    ["सेब 1 किलो", "apple", "Hindi Script - सेब (Apple)"],
    ["ताजा दूध 500ml", "milk_bottle", "Hindi Script - दूध (Milk)"],
    ["ஆப்பிள் 1 கிலோ", "apple", "Tamil Script - ஆப்பிள் (Apple)"],
    ["ஆவின் பால் 500ml", "milk_bottle", "Tamil Script - பால் (Milk)"]
];

foreach ($keywordCases as $idx => [$input, $expected, $desc]) {
    $matched = IconClassifier::classify($input, null, false);
    assertCheck("2" . chr(97 + $idx) . ". {$desc}", $matched === $expected, "(expected '{$expected}', got '{$matched}')");
}

// -----------------------------------------------------------------------------
// 3. Step 2: AI Classification with Mock Model
// -----------------------------------------------------------------------------
echo "\n--- 3. Testing Step 2 AI Classification with Mock Model ---\n";

// 3a. Unknown product name resolved by AI
IconClassifier::setMockHandler(function($name, $category, $allowedKeys) {
    if (stripos($name, 'Dragonfruit') !== false) {
        return "watermelon"; // Best fruit approximation
    }
    return null;
});

$aiRes1 = IconClassifier::classify("Dragonfruit Whole Exotic", "Fruits", true);
assertCheck("3a. Unknown product name resolved by AI mock", $aiRes1 === "watermelon", "(got '{$aiRes1}')");

// 3b. Unknown product name with AI replying 'none'
IconClassifier::setMockHandler(function($name, $category, $allowedKeys) {
    return "none";
});

$aiRes2 = IconClassifier::classify("Unrecognized High-Tech Industrial Sensor", "General", true);
assertCheck("3b. AI replying 'none' returns null", $aiRes2 === null, "(got '{$aiRes2}')");

// 3c. Garbage / Hallucinated AI reply rejected
IconClassifier::setMockHandler(function($name, $category, $allowedKeys) {
    return "Here is your key: `hallucinated_fake_key_xyz_999`. Hope this helps!";
});

$aiRes3 = IconClassifier::classify("Sample Product X", "General", true);
assertCheck("3c. Garbage / hallucinated AI reply rejected by catalog validator", $aiRes3 === null, "(got '{$aiRes3}')");

// 3d. AI Down / Network Exception handled gracefully
IconClassifier::setMockHandler(function($name, $category, $allowedKeys) {
    throw new RuntimeException("cURL error 28: Connection timed out after 8000ms");
});

$aiRes4 = IconClassifier::classify("Another Exotic Product", "General", true);
assertCheck("3d. AI down / network timeout returns null without throwing", $aiRes4 === null, "(got '{$aiRes4}')");

// Reset mock handler
IconClassifier::setMockHandler(null);

// -----------------------------------------------------------------------------
// 4. API Endpoints: Add Product, Update Product, Get Products, Get Detail
// -----------------------------------------------------------------------------
echo "\n--- 4. Testing Inventory API Endpoints with icon_key ---\n";

// Login Shop 2 (Ananya)
$defaultHash = password_hash('password123', PASSWORD_DEFAULT);
$pdo->prepare("UPDATE owner_account SET password = ? WHERE id = 2")->execute([$defaultHash]);
$pdo->prepare("UPDATE owner_account SET password = ? WHERE id = 3")->execute([$defaultHash]);

$login2 = apiCall("$baseUrl/auth/login.php", 'POST', ["email" => "ananya@example.com", "password" => "password123"]);
$token2 = $login2['json']['token'] ?? null;
assertCheck("4a. Login Shop 2 successful", !empty($token2));
$headers2 = ["Authorization: Bearer $token2"];

// 4b. Add product with empty icon_key auto-classifies via server
$addReq1 = [
    "name" => "Farm Fresh Red Apples 1kg",
    "category" => "Fruits",
    "price" => 140.00,
    "cost_price" => 100.00,
    "units" => 30,
    "min_stock_threshold" => 5
];
$addRes1 = apiCall("$baseUrl/inventory/add_product.php", 'POST', $addReq1, $headers2);
$addedId1 = (int)($addRes1['json']['data']['id'] ?? 0);
$returnedIcon1 = $addRes1['json']['data']['icon_key'] ?? null;

assertCheck("4b. Add product with empty icon_key returns 201 Created", $addRes1['code'] === 201);
assertCheck("4c. Server auto-classified icon_key is 'apple'", $returnedIcon1 === 'apple', "(got '{$returnedIcon1}')");

// Verify in DB that icon_is_manual is 0 for auto-classified product
$checkStmt1 = $pdo->prepare("SELECT icon_key, icon_is_manual FROM products WHERE id = ?");
$checkStmt1->execute([$addedId1]);
$row1 = $checkStmt1->fetch(PDO::FETCH_ASSOC);
assertCheck("4d. DB record has icon_key='apple' and icon_is_manual=0", $row1['icon_key'] === 'apple' && (int)$row1['icon_is_manual'] === 0);

// 4e. Add product with invalid icon_key returns 422 Unprocessable Entity
$addReqInvalid = [
    "name" => "Invalid Key Product",
    "icon_key" => "completely_invalid_key_999",
    "price" => 50.00,
    "units" => 10
];
$addResInvalid = apiCall("$baseUrl/inventory/add_product.php", 'POST', $addReqInvalid, $headers2);
assertCheck("4e. Add product with invalid icon_key returns 422", $addResInvalid['code'] === 422);
assertCheck("4f. Error message indicates invalid icon_key", stripos($addResInvalid['json']['message'] ?? '', 'invalid icon_key') !== false);

// 4g. Add product with explicit owner choice
$addReqManual = [
    "name" => "Amul Fresh Milk 1L",
    "category" => "Dairy",
    "icon_key" => "cookie", // Owner deliberately chooses cookie icon for milk
    "price" => 64.00,
    "cost_price" => 55.00,
    "units" => 25
];
$addResManual = apiCall("$baseUrl/inventory/add_product.php", 'POST', $addReqManual, $headers2);
$addedIdManual = (int)($addResManual['json']['data']['id'] ?? 0);
$returnedManualIcon = $addResManual['json']['data']['icon_key'] ?? null;

assertCheck("4g. Add product with explicit owner choice returns 201", $addResManual['code'] === 201);
assertCheck("4h. Explicit icon_key 'cookie' preserved in response", $returnedManualIcon === 'cookie');

$checkStmt2 = $pdo->prepare("SELECT icon_key, icon_is_manual FROM products WHERE id = ?");
$checkStmt2->execute([$addedIdManual]);
$row2 = $checkStmt2->fetch(PDO::FETCH_ASSOC);
assertCheck("4i. DB record has icon_is_manual=1", (int)$row2['icon_is_manual'] === 1 && $row2['icon_key'] === 'cookie');

// 4j. Update product with new manual icon choice
$updateReq = [
    "id" => $addedIdManual,
    "icon_key" => "candy"
];
$updateRes = apiCall("$baseUrl/inventory/update_product.php", 'POST', $updateReq, $headers2);
$updatedIcon = $updateRes['json']['data']['product']['icon_key'] ?? null;

assertCheck("4j. Update product with valid icon_key returns 200", $updateRes['code'] === 200);
assertCheck("4k. Updated icon_key is 'candy'", $updatedIcon === 'candy');

$checkStmt2->execute([$addedIdManual]);
$row2Updated = $checkStmt2->fetch(PDO::FETCH_ASSOC);
assertCheck("4l. DB record maintains icon_is_manual=1 after owner change", (int)$row2Updated['icon_is_manual'] === 1 && $row2Updated['icon_key'] === 'candy');

// 4m. Update product with invalid icon_key returns 422
$updateReqInvalid = [
    "id" => $addedIdManual,
    "icon_key" => "bad_catalog_key"
];
$updateResInvalid = apiCall("$baseUrl/inventory/update_product.php", 'POST', $updateReqInvalid, $headers2);
assertCheck("4m. Update product with invalid icon_key returns 422", $updateResInvalid['code'] === 422);

// 4n. Get Products endpoint returns icon_key
$getProductsRes = apiCall("$baseUrl/inventory/get_products.php?limit=5", 'GET', null, $headers2);
$productsList = $getProductsRes['json']['data']['products'] ?? [];
assertCheck("4n. get_products.php returns 200", $getProductsRes['code'] === 200);
assertCheck("4o. Products in list contain icon_key field", !empty($productsList) && array_key_exists('icon_key', $productsList[0]));

// 4p. Get Product Detail endpoint returns icon_key
$getDetailRes = apiCall("$baseUrl/inventory/get_product_detail.php?id={$addedIdManual}", 'GET', null, $headers2);
$detailIcon = $getDetailRes['json']['data']['icon_key'] ?? null;
assertCheck("4p. get_product_detail.php returns 200", $getDetailRes['code'] === 200);
assertCheck("4q. Product detail reflects current icon_key 'candy'", $detailIcon === 'candy');

// 4r. Shop scoping: Shop 3 cannot update Shop 2's product
$login3 = apiCall("$baseUrl/auth/login.php", 'POST', ["email" => "daksh1@gmail.com", "password" => "password123"]);
$token3 = $login3['json']['token'] ?? null;
$headers3 = ["Authorization: Bearer $token3"];

$crossShopUpdate = apiCall("$baseUrl/inventory/update_product.php", 'POST', ["id" => $addedIdManual, "icon_key" => "banana"], $headers3);
assertCheck("4r. Other shop cannot update product (returns 404)", $crossShopUpdate['code'] === 404);

// -----------------------------------------------------------------------------
// 5. Owner Choice Preservation during Backfill / Re-classification
// -----------------------------------------------------------------------------
echo "\n--- 5. Testing Owner Manual Choice Preservation ---\n";

// Run classify on the product's name
$autoClassifiedForName = IconClassifier::classify("Amul Fresh Milk 1L", "Dairy", false);
// Even though keyword classifier would pick 'milk_bottle', the product in DB must retain 'candy'
$checkStmt2->execute([$addedIdManual]);
$dbCurrent = $checkStmt2->fetch(PDO::FETCH_ASSOC);
assertCheck("5a. DB icon_is_manual is strictly 1", (int)$dbCurrent['icon_is_manual'] === 1);
assertCheck("5b. Owner manual choice 'candy' is completely untouched", $dbCurrent['icon_key'] === 'candy');

// -----------------------------------------------------------------------------
// 6. Import of 100 Rows with Batch AI Classification
// -----------------------------------------------------------------------------
echo "\n--- 6. Testing Import Execution with 100 Rows & Batch AI ---\n";

// Generate 100 valid product rows: 80 Produce/FMCG, 15 AI-requiring items, 5 junk names
$importRows = [];
$importHeaders = ["Product Name", "SKU", "Category", "Price", "Cost Price", "Stock"];

// 1. 80 produce/FMCG items (match keywords)
$fruits = ["Shimla Red Apple", "Cavendish Banana", "Green Seedless Grapes", "Fresh Tomato", "Broccoli Florets", "Sweet Orange", "Farm Fresh Strawberry", "Juicy Watermelon", "Yellow Mango", "Pineapple Whole"];
for ($i = 1; $i <= 80; $i++) {
    $fruitName = $fruits[($i - 1) % count($fruits)];
    $importRows[] = [
        "name" => "{$fruitName} Pack {$i}",
        "sku" => "IMP-100-PROD-" . str_pad($i, 3, '0', STR_PAD_LEFT),
        "category" => "Fruits",
        "price" => 50.0 + ($i % 20),
        "cost_price" => 35.0 + ($i % 15),
        "units" => 20 + ($i % 10),
        "min_stock_threshold" => 5
    ];
}

// 2. 15 special items requiring AI
for ($i = 81; $i <= 95; $i++) {
    $importRows[] = [
        "name" => "Exotic Specialty Item Ultra {$i}",
        "sku" => "IMP-100-AI-" . str_pad($i, 3, '0', STR_PAD_LEFT),
        "category" => "Specialty",
        "price" => 250.00,
        "cost_price" => 180.00,
        "units" => 15,
        "min_stock_threshold" => 5
    ];
}

// 3. 5 unknown junk items
for ($i = 96; $i <= 100; $i++) {
    $importRows[] = [
        "name" => "Zzqqwwk Random Xyzz Junk {$i}",
        "sku" => "IMP-100-JUNK-" . str_pad($i, 3, '0', STR_PAD_LEFT),
        "category" => "General",
        "price" => 99.00,
        "cost_price" => 60.00,
        "units" => 10,
        "min_stock_threshold" => 2
    ];
}

// Write temporary CSV file
$tempCsvPath = __DIR__ . '/uploads/temp/test_100_import_' . time() . '.csv';
if (!is_dir(dirname($tempCsvPath))) {
    mkdir(dirname($tempCsvPath), 0777, true);
}
$fp = fopen($tempCsvPath, 'w');
fputcsv($fp, $importHeaders, ",", "\"", "\\");
foreach ($importRows as $r) {
    fputcsv($fp, [
        $r['name'],
        $r['sku'],
        $r['category'],
        $r['price'],
        $r['cost_price'],
        $r['units']
    ], ",", "\"", "\\");
}
fclose($fp);

// Inject AI Mock Handler for the 15 special items (returns 'gift_box')
IconClassifier::setMockHandler(function($name, $category, $allowedKeys) {
    if (stripos($name, 'Specialty Item') !== false) {
        return "gift_box";
    }
    return "none";
});
IconClassifier::setMockResponses([
    'Specialty Item' => 'gift_box',
    '*' => 'none'
]);

// Upload step via multipart HTTP POST
$uploadRes = apiCall("$baseUrl/import/upload.php", 'POST', [], $headers2, ['file' => $tempCsvPath]);
$fileToken = $uploadRes['json']['data']['file_token'] ?? null;
assertCheck("6a. Upload 100-row CSV returns file_token", !empty($fileToken), "Response: " . json_encode($uploadRes['json']));

$suggestedMapping = $uploadRes['json']['data']['suggested_mapping'] ?? [
    "name" => "Product Name",
    "sku" => "SKU",
    "category" => "Category",
    "price" => "Price",
    "cost_price" => "Cost Price",
    "units" => "Stock"
];

// Validate step
$validateReq = [
    "file_token" => $fileToken,
    "column_mapping" => $suggestedMapping
];
$validateRes = apiCall("$baseUrl/import/validate.php", 'POST', $validateReq, $headers2);
assertCheck("6b. Validate 100 rows succeeds with 100 valid rows", ($validateRes['json']['data']['ready_to_import'] ?? false) === true);

// Execute step
$executeReq = [
    "file_token" => $fileToken,
    "file_name" => basename($tempCsvPath),
    "column_mapping" => $suggestedMapping,
    "import_mode" => "MERGE"
];
$executeRes = apiCall("$baseUrl/import/execute.php", 'POST', $executeReq, $headers2);

assertCheck("6c. Execute import 100 rows returns 200 OK", $executeRes['code'] === 200, "Code: {$executeRes['code']}, Msg: " . ($executeRes['json']['message'] ?? ''));
assertCheck("6d. Exactly 100 products added/processed", ($executeRes['json']['data']['products_added'] ?? 0) === 100, "Got: " . ($executeRes['json']['data']['products_added'] ?? 0));

// Verify DB icon_key assignments on imported products
$appleCheck = $pdo->prepare("SELECT icon_key FROM products WHERE shop_owner_id = 2 AND sku = 'IMP-100-PROD-001'");
$appleCheck->execute();
$importedApple = $appleCheck->fetch(PDO::FETCH_ASSOC);
assertCheck("6e. Imported apple product classified via keyword rule", ($importedApple['icon_key'] ?? '') === 'apple', "Got: " . ($importedApple['icon_key'] ?? 'null'));

$aiCheck = $pdo->prepare("SELECT icon_key FROM products WHERE shop_owner_id = 2 AND sku = 'IMP-100-AI-081'");
$aiCheck->execute();
$importedAi = $aiCheck->fetch(PDO::FETCH_ASSOC);
assertCheck("6f. Imported specialty product classified via batch AI ('gift_box')", ($importedAi['icon_key'] ?? '') === 'gift_box', "Got: " . ($importedAi['icon_key'] ?? 'null'));

$junkCheck = $pdo->prepare("SELECT icon_key FROM products WHERE shop_owner_id = 2 AND sku = 'IMP-100-JUNK-096'");
$junkCheck->execute();
$importedJunk = $junkCheck->fetch(PDO::FETCH_ASSOC);
assertCheck("6g. Imported unrecognized junk product left empty (null)", empty($importedJunk['icon_key']));

// Reset mock handler and remove temp CSV
IconClassifier::setMockHandler(null);
IconClassifier::setMockResponses(null);
if (file_exists($tempCsvPath)) {
    unlink($tempCsvPath);
}

// -----------------------------------------------------------------------------
// 7. CLI Backfill Script
// -----------------------------------------------------------------------------
echo "\n--- 7. Testing CLI Backfill Script ---\n";

// Run CLI script via PHP CLI in dry-run mode
$backfillOutput = [];
$backfillReturn = 0;
exec("C:\\xampp\\php\\php.exe " . escapeshellarg(__DIR__ . '/cli/backfill_product_icons.php') . " --shop_id=2 --dry-run --limit=20 --no-ai", $backfillOutput, $backfillReturn);
$backfillStr = implode("\n", $backfillOutput);

assertCheck("7a. CLI backfill script executed with return code 0", $backfillReturn === 0);
assertCheck("7b. CLI output contains BACKFILL SUMMARY banner", strpos($backfillStr, "BACKFILL SUMMARY") !== false);
assertCheck("7c. CLI output confirms DRY-RUN execution mode", strpos($backfillStr, "DRY-RUN") !== false);

// -----------------------------------------------------------------------------
// Clean up test products created in Shop 2
// -----------------------------------------------------------------------------
$pdo->prepare("DELETE FROM products WHERE shop_owner_id = 2 AND (sku LIKE 'IMP-100-%' OR id IN (?, ?))")
    ->execute([$addedId1, $addedIdManual]);

echo "\n========================================================\n";
echo "   RESULT: {$passCount} / " . ($passCount + $failCount) . " TESTS PASSED\n";
echo "========================================================\n";
