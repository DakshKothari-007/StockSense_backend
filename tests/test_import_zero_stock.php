<?php
// test_import_zero_stock.php
// Automated Test Suite for Zero Stock Products in Import and Skip Invalid Rows

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../import/SpreadsheetReader.php';

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

    return [
        'code' => $code,
        'body' => $res,
        'json' => json_decode($res, true)
    ];
}

$tests = 0;
$passed = 0;

function assertCheck($name, $cond, $details = '') {
    global $tests, $passed;
    $tests++;
    if ($cond) {
        echo " [PASS] {$name}\n";
        $passed++;
    } else {
        echo " [FAIL] {$name}: {$details}\n";
    }
}

echo "========================================================\n";
echo "       ZERO-STOCK AND SKIP INVALID ROWS TEST SUITE      \n";
echo "========================================================\n\n";

// Helper to create a throwaway test owner with valid token
function createThrowawayOwner($pdo, $prefix = 'zerotest') {
    $email = $prefix . '_' . uniqid() . '_' . time() . '@stocksense.test';
    $pwdHash = password_hash('Pass@1234', PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT INTO owner_account (shop_name, full_name, email, password) VALUES (?, ?, ?, ?)");
    $stmt->execute(["Zero Store " . uniqid(), "Test Owner", $email, $pwdHash]);
    $ownerId = (int)$pdo->lastInsertId();

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $tokStmt = $pdo->prepare("INSERT INTO tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 2 HOUR))");
    $tokStmt->execute([$ownerId, $tokenHash]);

    return [
        'id' => $ownerId,
        'email' => $email,
        'token' => $rawToken,
        'headers' => ["Authorization: Bearer {$rawToken}"]
    ];
}

// ------------------------------------------------------------------
// TEST 1: New product with 0 stock is valid and imports as Out of Stock
// ------------------------------------------------------------------
echo "--- TEST 1: New product with 0 stock ---\n";
$user1 = createThrowawayOwner($pdo, 'test1_zero_new');
$token1 = "imp_{$user1['id']}_new_zero_" . time() . ".csv";
$filePath1 = __DIR__ . "/../uploads/temp/" . $token1;
$csv1 = "Product Name,Category,Quantity,Cost Price,Selling Price,Minimum Stock\n"
      . "Real Mango Juice 1 L,Beverages,0,98,110,10\n";
file_put_contents($filePath1, $csv1);

$valRes1 = apiCall("$baseUrl/import/validate.php", "POST", [
    'file_token' => $token1,
    'column_mapping' => [
        'name' => 'Product Name',
        'category' => 'Category',
        'units' => 'Quantity',
        'cost_price' => 'Cost Price',
        'price' => 'Selling Price',
        'minimum_stock' => 'Minimum Stock'
    ]
], $user1['headers']);

assertCheck("Validation succeeds with HTTP 200", $valRes1['code'] === 200);
assertCheck("Zero stock for new product is ready_to_import", ($valRes1['json']['data']['ready_to_import'] ?? false) === true);
assertCheck("Zero stock for new product has 0 errors", ($valRes1['json']['data']['errors_remaining'] ?? -1) === 0);
assertCheck("Preview shows new product with 0 units", ($valRes1['json']['data']['stock_preview'][0]['new_units'] ?? -1) === 0);

$execRes1 = apiCall("$baseUrl/import/execute.php", "POST", [
    'file_token' => $token1,
    'column_mapping' => [
        'name' => 'Product Name',
        'category' => 'Category',
        'units' => 'Quantity',
        'cost_price' => 'Cost Price',
        'price' => 'Selling Price',
        'minimum_stock' => 'Minimum Stock'
    ]
], $user1['headers']);

assertCheck("Execute succeeds with HTTP 200", $execRes1['code'] === 200);
assertCheck("Execute created 1 product", ($execRes1['json']['data']['products_added'] ?? 0) === 1);
assertCheck("Execute returns out_of_stock_products list containing Mango Juice", !empty($execRes1['json']['data']['out_of_stock_products']));
$mangoInDb = $pdo->prepare("SELECT units, status FROM products WHERE shop_owner_id = ? AND name LIKE '%Mango%'");
$mangoInDb->execute([$user1['id']]);
$mangoRow = $mangoInDb->fetch(PDO::FETCH_ASSOC);
assertCheck("Mango Juice in DB has exactly 0 units", $mangoRow !== false && (int)$mangoRow['units'] === 0);

// ------------------------------------------------------------------
// TEST 2: Existing product with 0 stock in ADD mode triggers error
// ------------------------------------------------------------------
echo "\n--- TEST 2: Existing product with 0 stock in ADD mode ---\n";
$user2 = createThrowawayOwner($pdo, 'test2_zero_exist');
// Insert existing product with 50 units
$pdo->prepare("INSERT INTO products (shop_owner_id, name, sku, category, units, cost_price, price, min_stock_threshold) VALUES (?, 'Amul Milk 500 ml', 'DAI-EXIST', 'Dairy', 50, 27, 30, 10)")
    ->execute([$user2['id']]);

$token2 = "imp_{$user2['id']}_exist_zero_" . time() . ".csv";
$filePath2 = __DIR__ . "/../uploads/temp/" . $token2;
$csv2 = "Product Name,SKU,Quantity,Cost Price,Selling Price\n"
      . "Amul Milk 500 ml,DAI-EXIST,0,27,30\n";
file_put_contents($filePath2, $csv2);

$valRes2 = apiCall("$baseUrl/import/validate.php", "POST", [
    'file_token' => $token2,
    'column_mapping' => [
        'name' => 'Product Name',
        'sku' => 'SKU',
        'units' => 'Quantity',
        'cost_price' => 'Cost Price',
        'price' => 'Selling Price'
    ]
], $user2['headers']);

assertCheck("Validation returns 200 with error data", $valRes2['code'] === 200);
assertCheck("Validation ready_to_import is false", ($valRes2['json']['data']['ready_to_import'] ?? true) === false);
assertCheck("Validation found 1 error", ($valRes2['json']['data']['errors_remaining'] ?? 0) === 1);

$errObj = $valRes2['json']['data']['errors'][0] ?? [];
assertCheck("Error reason matches exact requirement: 'Quantity to add must be more than 0 for existing products'",
    ($errObj['reason'] ?? '') === "Quantity to add must be more than 0 for existing products");
assertCheck("Error badge is ROW 2 · ZERO QUANTITY", ($errObj['error_badge'] ?? '') === "ROW 2 · ZERO QUANTITY");
assertCheck("Suggested correction never invents stock (value is 0)", ($errObj['suggested_correction'] ?? -1) === 0);
assertCheck("Suggested label offers 'Import with 0 units (out of stock)'",
    ($errObj['suggested_label'] ?? '') === "Import with 0 units (out of stock)");

// ------------------------------------------------------------------
// TEST 3: Correction 'set_zero' on existing product overrides stock to 0
// ------------------------------------------------------------------
echo "\n--- TEST 3: Correction 'set_zero' on existing product ---\n";
$valRes3 = apiCall("$baseUrl/import/validate.php", "POST", [
    'file_token' => $token2,
    'column_mapping' => [
        'name' => 'Product Name',
        'sku' => 'SKU',
        'units' => 'Quantity',
        'cost_price' => 'Cost Price',
        'price' => 'Selling Price'
    ],
    'corrections' => [
        ['row_number' => 2, 'field' => 'units', 'value' => 'set_zero']
    ]
], $user2['headers']);

assertCheck("Re-validation with set_zero succeeds", ($valRes3['json']['data']['ready_to_import'] ?? false) === true);

$execRes3 = apiCall("$baseUrl/import/execute.php", "POST", [
    'file_token' => $token2,
    'column_mapping' => [
        'name' => 'Product Name',
        'sku' => 'SKU',
        'units' => 'Quantity',
        'cost_price' => 'Cost Price',
        'price' => 'Selling Price'
    ],
    'corrections' => [
        ['row_number' => 2, 'field' => 'units', 'value' => 'set_zero']
    ]
], $user2['headers']);

assertCheck("Execute with set_zero succeeds with 200", $execRes3['code'] === 200);
$milkInDb = $pdo->prepare("SELECT units FROM products WHERE shop_owner_id = ? AND sku = 'DAI-EXIST'");
$milkInDb->execute([$user2['id']]);
assertCheck("Amul Milk in DB updated to 0 units", (int)$milkInDb->fetchColumn() === 0);

// ------------------------------------------------------------------
// TEST 4: Skip invalid rows option & 29 of 30 import
// ------------------------------------------------------------------
echo "\n--- TEST 4: Skip invalid rows & 29 of 30 import ---\n";
$user4 = createThrowawayOwner($pdo, 'test4_skip_invalid');
$token4 = "imp_{$user4['id']}_skip_inv_" . time() . ".csv";
$filePath4 = __DIR__ . "/../uploads/temp/" . $token4;

// Build a 30-row CSV where row 15 has an invalid negative quantity
$csv4Lines = ["Product Name,Category,Quantity,Cost Price,Selling Price,Minimum Stock"];
for ($i = 1; $i <= 30; $i++) {
    $qty = ($i === 15) ? -10 : 20; // Row 15 is invalid negative
    $csv4Lines[] = "Item {$i},General,{$qty},10,15,5";
}
file_put_contents($filePath4, implode("\n", $csv4Lines));

// Standard execute without skip_invalid_rows aborts with 422
$execFail = apiCall("$baseUrl/import/execute.php", "POST", [
    'file_token' => $token4,
    'column_mapping' => [
        'name' => 'Product Name',
        'category' => 'Category',
        'units' => 'Quantity',
        'cost_price' => 'Cost Price',
        'price' => 'Selling Price',
        'minimum_stock' => 'Minimum Stock'
    ]
], $user4['headers']);

assertCheck("Execute without skip_invalid_rows aborts with 422", $execFail['code'] === 422);

// Re-create temp file for skip execution (if unlinked or keep file)
if (!file_exists($filePath4)) {
    file_put_contents($filePath4, implode("\n", $csv4Lines));
}

// Execute WITH skip_invalid_rows: true
$execSkip = apiCall("$baseUrl/import/execute.php", "POST", [
    'file_token' => $token4,
    'skip_invalid_rows' => true,
    'column_mapping' => [
        'name' => 'Product Name',
        'category' => 'Category',
        'units' => 'Quantity',
        'cost_price' => 'Cost Price',
        'price' => 'Selling Price',
        'minimum_stock' => 'Minimum Stock'
    ]
], $user4['headers']);

assertCheck("Execute with skip_invalid_rows returns 200 OK", $execSkip['code'] === 200);
assertCheck("Execute reports exactly 29 rows imported", ($execSkip['json']['data']['data_rows_imported'] ?? '') === "29 of 30");
assertCheck("Execute reports exactly 1 skipped row", ($execSkip['json']['data']['skipped_rows'] ?? 0) === 1);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE shop_owner_id = ?");
$countStmt->execute([$user4['id']]);
assertCheck("Database has exactly 29 products created", (int)$countStmt->fetchColumn() === 29);

// ------------------------------------------------------------------
// TEST 5: Full stocksense_demo_products.csv on throwaway account
// ------------------------------------------------------------------
echo "\n--- TEST 5: Full stocksense_demo_products.csv on throwaway account ---\n";
$demoCsvPath = "C:/Users/SCAD LAB/Downloads/stocksense_demo_products.csv";
if (!file_exists($demoCsvPath)) {
    echo " [SKIP] stocksense_demo_products.csv not found at $demoCsvPath\n";
} else {
    $userCsv = createThrowawayOwner($pdo, 'demo_csv_test');
    $tokenCsv = "imp_{$userCsv['id']}_demo_csv_" . time() . ".csv";
    copy($demoCsvPath, __DIR__ . "/../uploads/temp/" . $tokenCsv);

    $valCsv = apiCall("$baseUrl/import/validate.php", "POST", [
        'file_token' => $tokenCsv
    ], $userCsv['headers']);

    assertCheck("Demo CSV validation succeeds with 200", $valCsv['code'] === 200);
    assertCheck("Demo CSV validates all 30 rows as ready", ($valCsv['json']['data']['ready_rows'] ?? 0) === 30);
    assertCheck("Demo CSV zero errors remaining", ($valCsv['json']['data']['errors_remaining'] ?? -1) === 0);

    $execCsv = apiCall("$baseUrl/import/execute.php", "POST", [
        'file_token' => $tokenCsv
    ], $userCsv['headers']);

    assertCheck("Demo CSV execute returns 200", $execCsv['code'] === 200);
    assertCheck("Demo CSV 30 products added", ($execCsv['json']['data']['products_added'] ?? 0) === 30);
    assertCheck("Demo CSV 30 of 30 data rows imported", ($execCsv['json']['data']['data_rows_imported'] ?? '') === "30 of 30");
    assertCheck("Demo CSV 0 rows skipped", ($execCsv['json']['data']['skipped_rows'] ?? -1) === 0);

    $mangoStmt = $pdo->prepare("SELECT units FROM products WHERE shop_owner_id = ? AND name LIKE '%Mango Juice%'");
    $mangoStmt->execute([$userCsv['id']]);
    $mangoUnits = $mangoStmt->fetchColumn();
    assertCheck("Real Mango Juice 1 L created with exactly 0 units", $mangoUnits !== false && (int)$mangoUnits === 0);

    // Verify out_of_stock_products payload contains Mango Juice
    $oosList = $execCsv['json']['data']['out_of_stock_products'] ?? [];
    $hasMangoOos = false;
    foreach ($oosList as $oosItem) {
        if (stripos($oosItem['name'], 'Mango') !== false) {
            $hasMangoOos = true;
            break;
        }
    }
    assertCheck("Execute out_of_stock_products lists Real Mango Juice 1 L", $hasMangoOos);
}

// ------------------------------------------------------------------
// TEST 6: Demo products .xlsx on throwaway account
// ------------------------------------------------------------------
echo "\n--- TEST 6: Demo products .xlsx on throwaway account ---\n";
$demoXlsxSource = __DIR__ . "/../uploads/temp/imp_269_011ddf2cae870712_1795056842.xlsx";
if (!file_exists($demoXlsxSource)) {
    echo " [SKIP] Demo XLSX not found\n";
} else {
    $userXlsx = createThrowawayOwner($pdo, 'demo_xlsx_test');
    $tokenXlsx = "imp_{$userXlsx['id']}_demo_xlsx_" . time() . ".xlsx";
    copy($demoXlsxSource, __DIR__ . "/../uploads/temp/" . $tokenXlsx);

    // Also copy to Downloads so user has the .xlsx there as well
    @copy($demoXlsxSource, "C:/Users/SCAD LAB/Downloads/stocksense_demo_products.xlsx");

    $valXlsx = apiCall("$baseUrl/import/validate.php", "POST", [
        'file_token' => $tokenXlsx
    ], $userXlsx['headers']);

    assertCheck("Demo XLSX validation succeeds with 200", $valXlsx['code'] === 200);
    assertCheck("Demo XLSX validates all 30 rows as ready", ($valXlsx['json']['data']['ready_rows'] ?? 0) === 30);
    assertCheck("Demo XLSX zero errors remaining", ($valXlsx['json']['data']['errors_remaining'] ?? -1) === 0);

    $execXlsx = apiCall("$baseUrl/import/execute.php", "POST", [
        'file_token' => $tokenXlsx
    ], $userXlsx['headers']);

    assertCheck("Demo XLSX execute returns 200", $execXlsx['code'] === 200);
    assertCheck("Demo XLSX 30 products added", ($execXlsx['json']['data']['products_added'] ?? 0) === 30);
    assertCheck("Demo XLSX 30 of 30 data rows imported", ($execXlsx['json']['data']['data_rows_imported'] ?? '') === "30 of 30");

    $mangoXlsxStmt = $pdo->prepare("SELECT units FROM products WHERE shop_owner_id = ? AND name LIKE '%Mango Juice%'");
    $mangoXlsxStmt->execute([$userXlsx['id']]);
    $mangoXlsxUnits = $mangoXlsxStmt->fetchColumn();
    assertCheck("XLSX: Real Mango Juice 1 L created with exactly 0 units", $mangoXlsxUnits !== false && (int)$mangoXlsxUnits === 0);
}

echo "\n========================================================\n";
echo "SUMMARY: {$passed} of {$tests} assertions passed.\n";
if ($passed === $tests) {
    echo "ALL ZERO-STOCK & SKIP TESTS PASSED!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}
