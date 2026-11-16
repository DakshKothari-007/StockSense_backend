<?php

require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/import/ColumnMatcher.php";

echo "========================================================\n";
echo "      STOCK SENSE: IMPORT COLUMN DETECTION TEST SUITE    \n";
echo "========================================================\n\n";

$testStyles = [
    [
        'name' => 'Style 1: Supermarket Daily POS (Item Name, Qty, MRP, Purchase Rate)',
        'headers' => ['Item Name', 'Qty', 'MRP', 'Purchase Rate'],
        'sample_rows' => [
            ['Amul Gold 500ml', '50', '33.00', '30.50'],
            ['Aashirvaad Atta 5kg', '20', '260.00', '230.00'],
            ['Tata Tea Premium 250g', '15', '125.00', '105.00'],
        ]
    ],
    [
        'name' => 'Style 2: Minimalist Stock Sheet (PRODUCT, STOCK, PRICE)',
        'headers' => ['PRODUCT', 'STOCK', 'PRICE'],
        'sample_rows' => [
            ['Fortune Sunflower Oil 1L', '40', '145.00'],
            ['Parle-G 800g Super Saver', '60', '80.00'],
            ['Surf Excel Quick Wash 1kg', '25', '190.00'],
        ]
    ],
    [
        'name' => 'Style 3: Barcode Inventory (Description, Barcode, Qty On Hand, Rate)',
        'headers' => ['Description', 'Barcode', 'Qty On Hand', 'Rate'],
        'sample_rows' => [
            ['Maggi 2-Minute Noodles 70g', '8901058852394', '120', '14.00'],
            ['Colgate Strong Teeth 200g', '8901314010544', '35', '98.00'],
            ['Dettol Original Soap 125g', '8901396001234', '80', '42.00'],
        ]
    ],
    [
        'name' => 'Style 4: Lowercase Database Export with Underscores',
        'headers' => ['item_description', 'sku_code', 'stock_in_hand', 'buying_price', 'selling_rate', 'dept', 'reorder_level'],
        'sample_rows' => [
            ['Britannia Good Day Butter 200g', 'BIS-GD-001', '45', '32.00', '40.00', 'Bakery', '15'],
            ['Cadbury Dairy Milk Silk 60g', 'CHO-DMS-01', '30', '65.00', '80.00', 'Confectionery', '10'],
        ]
    ],
    [
        'name' => 'Style 5: ERP Export with Extra/Unknown Columns',
        'headers' => ['Product', 'Article No', 'Units', 'Unit Cost', 'Selling Price', 'Group', 'Supplier', 'Tax %', 'Shelf Location', 'Expiry Date'],
        'sample_rows' => [
            ['Nescafe Classic 50g Jar', 'ART-9901', '25', '135.00', '165.00', 'Beverages', 'Nestle India', '18', 'Aisle 3, Shelf B', '2027-12-31'],
            ['Horlicks Classic Malt 500g', 'ART-9902', '18', '210.00', '255.00', 'Health Drinks', 'HUL', '18', 'Aisle 3, Shelf C', '2027-10-15'],
        ]
    ],
    [
        'name' => 'Style 6: Typos & Abbreviations (Fuzzy Matching)',
        'headers' => ['Prodct Name', 'Qunatity', 'Sellin Price'],
        'sample_rows' => [
            ['Bournvita 500g Refill', '12', '240.00'],
            ['Kissan Mixed Fruit Jam 500g', '18', '175.00']
        ]
    ]
];

$passCount = 0;
$totalTests = count($testStyles);

foreach ($testStyles as $idx => $test) {
    $num = $idx + 1;
    echo "--- TEST {$num}: {$test['name']} ---\n";
    echo "Headers: [" . implode(', ', $test['headers']) . "]\n";

    $res = ColumnMatcher::detect($test['headers'], $test['sample_rows'], $pdo, 1);

    echo "Matched: {$res['matched_count']} of {$res['total_columns']} columns\n";
    echo "Required Fields Matched (name & units): " . ($res['required_matched'] ? "YES [PASS]" : "NO [FAIL]") . "\n";
    echo "Mappings:\n";
    foreach ($res['mappings'] as $field => $col) {
        $conf = $res['confidence'][$field] ?? 0;
        $status = $col !== null ? "-> '$col' (confidence: $conf)" : "-> [NOT MAPPED]";
        echo "  - {$field} {$status}\n";
    }

    // Verify SKU fallback if not mapped
    if ($res['mappings']['sku'] === null) {
        $stableSku = ColumnMatcher::generateStableSku(1, $test['sample_rows'][0][0]);
        echo "  - (Generated Stable SKU fallback for sample row 1: '{$stableSku}')\n";
    }

    if ($res['required_matched']) {
        $passCount++;
        echo "Result: FULL DETECTION OF REQUIRED INVENTORY FIELDS\n\n";
    } else {
        echo "Result: FAILED TO DETECT REQUIRED FIELDS\n\n";
    }
}

echo "========================================================\n";
echo "SUMMARY: {$passCount} / {$totalTests} HEADER STYLES PASSED\n";
echo "========================================================\n\n";

echo "--- TEST 7: Saved Mapping Roundtrip Persistence ---\n";
$customHeaders = ['Col A', 'Col B', 'Col C'];
$customMapping = ['name' => 'Col A', 'units' => 'Col B', 'price' => 'Col C'];
$saveOk = ColumnMatcher::saveMapping($pdo, 9999, $customHeaders, $customMapping);
echo "Saving custom mapping for shop 9999: " . ($saveOk ? "SAVED [OK]" : "FAILED") . "\n";

$savedResult = ColumnMatcher::detect($customHeaders, [], $pdo, 9999);
echo "Retrieving saved mapping: is_saved=" . ($savedResult['is_saved'] ? "TRUE [PASS]" : "FALSE [FAIL]") . "\n";
echo "Matched count: {$savedResult['matched_count']} of {$savedResult['total_columns']}\n";
echo "Mapped name: {$savedResult['mappings']['name']}, units: {$savedResult['mappings']['units']}, price: {$savedResult['mappings']['price']}\n\n";

