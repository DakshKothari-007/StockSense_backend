<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../import/SpreadsheetReader.php';
require_once __DIR__ . '/../import/ColumnMatcher.php';

echo "========================================================\n";
echo "       BUG 1: IMPORT COLUMN DETECTION TEST SUITE        \n";
echo "========================================================\n\n";

$allPassed = true;

// Helper to check assertion
function assertTest(string $desc, bool $condition) {
    global $allPassed;
    if ($condition) {
        echo " [PASS] {$desc}\n";
    } else {
        echo " [FAIL] {$desc}\n";
        $allPassed = false;
    }
}

// ---------------------------------------------------------
// TEST 1: CSV Detection (Demo Products)
// ---------------------------------------------------------
echo "--- TEST 1: CSV Detection (Standard Demo Products) ---\n";
$tempCsv = tempnam(sys_get_temp_dir(), 'csv_test_') . '.csv';
$csvContent = "Product Name,Category,Selling Price,Purchase Cost,Stock,Minimum Stock\n"
            . "Aashirvaad Atta 5kg,Grocery,265.00,238.00,20,5\n"
            . "Amul Butter 100g,Dairy,58.00,50.00,35,10\n"
            . "Tata Tea Gold 500g,Beverages,320.00,285.00,15,4\n";
file_put_contents($tempCsv, $csvContent);

$previewCsv = SpreadsheetReader::getHeadersAndPreview($tempCsv);
@unlink($tempCsv);

assertTest("CSV detected 6 columns", count($previewCsv['headers']) === 6);
assertTest("CSV headers contain 'Product Name'", in_array('Product Name', $previewCsv['headers']));
assertTest("CSV headers do NOT contain numbers/cell values like '265.0'", !in_array('265.0', $previewCsv['headers']) && !in_array('238.0', $previewCsv['headers']));

$matchCsv = ColumnMatcher::detect($previewCsv['headers'], $previewCsv['preview_rows'], $pdo, 1);
assertTest("CSV matched name -> Product Name", ($matchCsv['mappings']['name'] ?? '') === 'Product Name');
assertTest("CSV matched units -> Stock", ($matchCsv['mappings']['units'] ?? '') === 'Stock');
assertTest("CSV matched price -> Selling Price", ($matchCsv['mappings']['price'] ?? '') === 'Selling Price');
assertTest("CSV matched cost_price -> Purchase Cost", ($matchCsv['mappings']['cost_price'] ?? '') === 'Purchase Cost');
assertTest("CSV matched category -> Category", ($matchCsv['mappings']['category'] ?? '') === 'Category');
assertTest("CSV matched min_stock -> Minimum Stock", ($matchCsv['mappings']['minimum_stock'] ?? '') === 'Minimum Stock');
assertTest("CSV required fields matched", $matchCsv['required_matched'] === true);

// ---------------------------------------------------------
// TEST 2: XLSX Detection (Real .xlsx file from uploads or docs)
// ---------------------------------------------------------
echo "\n--- TEST 2: XLSX Detection ---\n";
$existingXlsx = glob(__DIR__ . '/../uploads/temp/*.xlsx');
if (empty($existingXlsx)) {
    $existingXlsx = glob(__DIR__ . '/../docs/*.xlsx');
}
if (!empty($existingXlsx)) {
    $testXlsxPath = $existingXlsx[0];
    echo "Using existing XLSX: " . basename($testXlsxPath) . "\n";
    $previewXlsx = SpreadsheetReader::getHeadersAndPreview($testXlsxPath);
    assertTest("XLSX has headers", !empty($previewXlsx['headers']));
    assertTest("XLSX headers contain text header names", in_array('Product Name', $previewXlsx['headers']) || in_array('Item Name', $previewXlsx['headers']) || in_array('PRODUCT', $previewXlsx['headers']) || in_array('Product', $previewXlsx['headers']));
    assertTest("XLSX headers do not contain numeric cell values (265.0)", !in_array('265.0', $previewXlsx['headers']) && !in_array('238.0', $previewXlsx['headers']));
    $matchXlsx = ColumnMatcher::detect($previewXlsx['headers'], $previewXlsx['preview_rows'], $pdo, 1);
    assertTest("XLSX required fields matched", $matchXlsx['required_matched'] === true);
} else {
    echo "No existing xlsx upload found, skipping file read.\n";
}

// ---------------------------------------------------------
// TEST 3: Title Row Above Headers (Metadata / Title Row)
// ---------------------------------------------------------
echo "\n--- TEST 3: Title Row Above Headers ---\n";
$titleCsv = tempnam(sys_get_temp_dir(), 'title_test_') . '.csv';
$titleCsvContent = "StockSense Supermarket Demo Inventory Export - November 2026\n"
                 . "Product Name,Category,Selling Price,Purchase Cost,Stock,Minimum Stock\n"
                 . "Fortune Sunflower Oil 1L,Grocery,145.00,128.00,40,8\n"
                 . "Parle-G 800g,Snacks,80.00,68.00,60,15\n";
file_put_contents($titleCsv, $titleCsvContent);

$previewTitle = SpreadsheetReader::getHeadersAndPreview($titleCsv);
@unlink($titleCsv);

assertTest("Title row skipped: headers contain 'Product Name'", in_array('Product Name', $previewTitle['headers']));
assertTest("Title string is NOT a header", !in_array('StockSense Supermarket Demo Inventory Export - November 2026', $previewTitle['headers']));
$matchTitle = ColumnMatcher::detect($previewTitle['headers'], $previewTitle['preview_rows'], $pdo, 1);
assertTest("Title CSV required fields matched", $matchTitle['required_matched'] === true);

// ---------------------------------------------------------
// TEST 4: Renamed / Alternate Column Names
// ---------------------------------------------------------
echo "\n--- TEST 4: Renamed Columns (Item Name, Dept, MRP, Cost, Quantity, Min Threshold) ---\n";
$renamedCsv = tempnam(sys_get_temp_dir(), 'renamed_test_') . '.csv';
$renamedContent = "Item Name,Dept,MRP,Cost,Quantity,Min Threshold\n"
                . "Britannia Good Day 200g,Bakery,40.00,32.00,45,10\n"
                . "Cadbury Silk 60g,Confectionery,80.00,65.00,30,5\n";
file_put_contents($renamedCsv, $renamedContent);

$previewRenamed = SpreadsheetReader::getHeadersAndPreview($renamedCsv);
@unlink($renamedCsv);

$matchRenamed = ColumnMatcher::detect($previewRenamed['headers'], $previewRenamed['preview_rows'], $pdo, 1);
assertTest("Renamed matched name -> Item Name", ($matchRenamed['mappings']['name'] ?? '') === 'Item Name');
assertTest("Renamed matched units -> Quantity", ($matchRenamed['mappings']['units'] ?? '') === 'Quantity');
assertTest("Renamed matched price -> MRP", ($matchRenamed['mappings']['price'] ?? '') === 'MRP');
assertTest("Renamed matched cost_price -> Cost", ($matchRenamed['mappings']['cost_price'] ?? '') === 'Cost');
assertTest("Renamed matched category -> Dept", ($matchRenamed['mappings']['category'] ?? '') === 'Dept');
assertTest("Renamed matched min_stock -> Min Threshold", ($matchRenamed['mappings']['minimum_stock'] ?? '') === 'Min Threshold');
assertTest("Renamed required fields matched", $matchRenamed['required_matched'] === true);

// ---------------------------------------------------------
// TEST 5: Header Detection Failure Graceful Handling
// ---------------------------------------------------------
echo "\n--- TEST 5: Pure Number Spreadsheet (Header Detection Failure) ---\n";
$badRows = [
    [100.0, 200.0, 300.0],
    [101.0, 201.0, 301.0],
    [102.0, 202.0, 302.0]
];
try {
    SpreadsheetReader::extractHeaderAndRows($badRows);
    assertTest("Pure numeric table should throw header detection error", false);
} catch (\RuntimeException $e) {
    assertTest("Header detection throws clear exception: " . $e->getMessage(), true);
}

echo "\n========================================================\n";
echo "RESULT: " . ($allPassed ? "ALL BUG 1 IMPORT TESTS PASSED!" : "SOME TESTS FAILED!") . "\n";
echo "========================================================\n";
