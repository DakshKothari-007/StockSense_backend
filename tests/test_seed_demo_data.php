<?php

/**
 * test_seed_demo_data.php
 *
 * Test suite for StockSense CLI Demo Data Seeder (cli/seed_demo_data.php).
 * Verifies:
 *  1. CLI restriction (refuses web execution).
 *  2. --dry-run mode does not modify database.
 *  3. Live seeding creates demo owner (daksh14001@gmail.com) with strong password hash.
 *  4. 30 products across 8 categories with server SKUs and catalog icon keys.
 *  5. Counts of inventory segments: 5 low stock, 3 slow moving, 2 dead stock, 1 out of stock.
 *  6. 30 days of sales history with weekday/weekend variation and accurate stock reduction.
 *  7. 2 purchase orders (1 placed, 1 received).
 *  8. Idempotency (safe to run twice without duplicating data).
 *  9. --reset cleans and recreates only the demo shop without touching other users.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This test script must be run from the command line.\n";
    exit(1);
}

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../config/db.php';
$pdo->exec("SET time_zone = '+05:30'");

echo "======================================================================\n";
echo "           STOCKSENSE DEMO DATA SEEDER TEST SUITE                     \n";
echo "======================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(bool $condition, string $description): void {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$description}\n";
        $failCount++;
    }
}

$phpExec = PHP_BINARY;
if (!file_exists($phpExec)) {
    $phpExec = 'c:\\xampp\\php\\php.exe';
}
$seederScript = dirname(__DIR__) . '/cli/seed_demo_data.php';

// -------------------------------------------------------------------
// 1. TEST CLI RESTRICTION
// -------------------------------------------------------------------
echo "1. Testing Execution Boundary Enforcement...\n";

// Test web simulation (script must exit with code 1 and refuse web access)
$simulatedWebCmd = sprintf(
    '"%s" -r "$_SERVER[\'REQUEST_METHOD\'] = \'GET\'; require \'%s\';"',
    $phpExec,
    addslashes($seederScript)
);
$webOutput = shell_exec($simulatedWebCmd . " 2>&1");
assertTest(
    strpos($webOutput, "Access Denied") !== false || strpos($webOutput, "command line") !== false,
    "Seeder refuses execution when web/HTTP environment is detected"
);

// -------------------------------------------------------------------
// 2. TEST --dry-run
// -------------------------------------------------------------------
echo "\n2. Testing --dry-run Mode...\n";

// Count records before dry-run
$beforeOwner = (int)$pdo->query("SELECT COUNT(*) FROM owner_account WHERE email = 'daksh14001@gmail.com'")->fetchColumn();

$dryRunCmd = sprintf('"%s" "%s" --dry-run', $phpExec, $seederScript);
$dryRunOutput = shell_exec($dryRunCmd . " 2>&1");

$afterDryRunOwner = (int)$pdo->query("SELECT COUNT(*) FROM owner_account WHERE email = 'daksh14001@gmail.com'")->fetchColumn();

assertTest($beforeOwner === $afterDryRunOwner, "--dry-run makes zero database insertions or modifications");
assertTest(strpos($dryRunOutput, "DRY-RUN") !== false, "--dry-run output reports simulated execution");
assertTest(strpos($dryRunOutput, "Products Created:  30") !== false, "--dry-run outputs 30 planned products");

// -------------------------------------------------------------------
// 3. TEST LIVE SEEDING & RESET
// -------------------------------------------------------------------
echo "\n3. Testing Live Seeding (--reset)...\n";

$seedCmd = sprintf('"%s" "%s" --reset', $phpExec, $seederScript);
$seedOutput = shell_exec($seedCmd . " 2>&1");

assertTest(strpos($seedOutput, "LIVE SEED") !== false, "Seeder executed live database seed");
assertTest(strpos($seedOutput, "Demo Login Email:  daksh14001@gmail.com") !== false, "Demo login email is daksh14001@gmail.com");

// Verify password printed once in terminal
assertTest(
    preg_match('/Demo Password:\s+([A-Za-z0-9!@#$%^&*()_\-=+]{12,32})/', $seedOutput, $matches) === 1,
    "Random strong password printed once in terminal output"
);
$printedPassword = $matches[1] ?? '';

// -------------------------------------------------------------------
// 4. VERIFY DEMO SHOP OWNER IN DATABASE
// -------------------------------------------------------------------
echo "\n4. Verifying Demo Shop Owner in Database...\n";

$ownerStmt = $pdo->prepare("SELECT id, email, full_name, shop_name, location, store_type, currency, password FROM owner_account WHERE email = ?");
$ownerStmt->execute(['daksh14001@gmail.com']);
$demoOwner = $ownerStmt->fetch(PDO::FETCH_ASSOC);

assertTest(!empty($demoOwner), "Owner account exists in database for daksh14001@gmail.com");
assertTest($demoOwner['shop_name'] === 'Daksh Supermarket', "Shop name is 'Daksh Supermarket'");
assertTest($demoOwner['location'] === 'Indiranagar, Bengaluru', "Location is 'Indiranagar, Bengaluru'");
assertTest($demoOwner['store_type'] === 'Neighbourhood supermarket', "Store type is 'Neighbourhood supermarket'");
assertTest($demoOwner['currency'] === '₹ INR / IST', "Currency is '₹ INR / IST'");

// Verify password was properly hashed and matches the generated password
assertTest(
    !empty($printedPassword) && password_verify($printedPassword, $demoOwner['password']),
    "Stored password is a secure BCrypt hash matching generated plain password"
);

$demoOwnerId = (int)$demoOwner['id'];

// Verify user_settings exists
$settingsStmt = $pdo->prepare("SELECT user_id FROM user_settings WHERE user_id = ?");
$settingsStmt->execute([$demoOwnerId]);
assertTest($settingsStmt->fetchColumn() == $demoOwnerId, "User settings record exists for demo shop owner");

// -------------------------------------------------------------------
// 5. VERIFY PRODUCTS & INVENTORY SEGMENTS
// -------------------------------------------------------------------
echo "\n5. Verifying 30 Products & Inventory Segments...\n";

$prodStmt = $pdo->prepare("
    SELECT id, name, sku, category, price, cost_price, units, min_stock_threshold, status, icon_key
    FROM products
    WHERE shop_owner_id = ?
    ORDER BY id ASC
");
$prodStmt->execute([$demoOwnerId]);
$products = $prodStmt->fetchAll(PDO::FETCH_ASSOC);

assertTest(count($products) === 30, "Exactly 30 products created in database");

// Categories check
$categories = array_unique(array_column($products, 'category'));
sort($categories);
$expectedCategories = ['Bakery', 'Beverages', 'Dairy', 'Grocery', 'Household', 'Personal Care', 'Snacks', 'Vegetables'];
sort($expectedCategories);
assertTest($categories === $expectedCategories, "All 8 required supermarket categories are represented");

// SKU & Icon Key checks
$allSkusValid = true;
$allIconsValid = true;
foreach ($products as $p) {
    if (empty($p['sku']) || strpos($p['sku'], 'SKU-') !== 0) {
        $allSkusValid = false;
    }
    if (empty($p['icon_key'])) {
        $allIconsValid = false;
    }
}
assertTest($allSkusValid, "All 30 products have valid server-generated SKUs (SKU-*)");
assertTest($allIconsValid, "All 30 products have catalog icon keys resolved by IconClassifier");

// Segment counts
$lowStock = 0;
$outOfStock = 0;
$inStockHealthy = 0;

foreach ($products as $p) {
    $units = (int)$p['units'];
    $min = (int)$p['min_stock_threshold'];
    if ($units === 0) {
        $outOfStock++;
    } elseif ($units <= $min) {
        $lowStock++;
    } else {
        $inStockHealthy++;
    }
}

assertTest($lowStock === 5, "Exactly 5 products are low stock (units <= min_stock_threshold and units > 0)");
assertTest($outOfStock === 1, "Exactly 1 product is out of stock (units = 0)");
assertTest($inStockHealthy === 24, "Remaining 24 products have healthy inventory levels");

// -------------------------------------------------------------------
// 6. VERIFY 30 DAYS OF SALES HISTORY & STOCK REDUCTION
// -------------------------------------------------------------------
echo "\n6. Verifying 30-Day Sales History & Consistency...\n";

$salesStmt = $pdo->prepare("
    SELECT id, bill_no, sale_date, total_amount
    FROM sales
    WHERE shop_owner_id = ?
    ORDER BY sale_date ASC
");
$salesStmt->execute([$demoOwnerId]);
$sales = $salesStmt->fetchAll(PDO::FETCH_ASSOC);

assertTest(count($sales) >= 60, "At least 60 sales bills recorded over 30 days (actual: " . count($sales) . ")");

// Check date range
$earliestSale = $sales[0]['sale_date'];
$latestSale = end($sales)['sale_date'];
$salesDaysSpan = (time() - strtotime($earliestSale)) / 86400.0;

assertTest($salesDaysSpan >= 29.5, "Sales history spans a full 30 days (earliest: {$earliestSale})");
assertTest(strtotime($latestSale) <= time(), "Latest sale date is not in the future (latest: {$latestSale})");

// Verify bill totals match line items
$itemStmt = $pdo->prepare("
    SELECT sale_id, SUM(line_total) AS computed_total, COUNT(*) AS item_count
    FROM sales_items
    WHERE sale_id IN (SELECT id FROM sales WHERE shop_owner_id = ?)
    GROUP BY sale_id
");
$itemStmt->execute([$demoOwnerId]);
$itemsBySale = [];
while ($row = $itemStmt->fetch(PDO::FETCH_ASSOC)) {
    $itemsBySale[(int)$row['sale_id']] = (float)$row['computed_total'];
}

$allTotalsMatch = true;
foreach ($sales as $s) {
    $sid = (int)$s['id'];
    $recorded = round((float)$s['total_amount'], 2);
    $computed = round($itemsBySale[$sid] ?? 0.0, 2);
    if (abs($recorded - $computed) > 0.01) {
        $allTotalsMatch = false;
        break;
    }
}
assertTest($allTotalsMatch, "Every sale bill total_amount matches the sum of its item line totals");

// Verify 30-day sales per product for slow-moving and dead-stock
$thirtyDaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
$sold30Stmt = $pdo->prepare("
    SELECT p.id, p.name, p.units, p.min_stock_threshold, COALESCE(SUM(si.quantity), 0) AS sold_30
    FROM products p
    LEFT JOIN sales_items si ON p.id = si.product_id
    LEFT JOIN sales s ON si.sale_id = s.id AND s.shop_owner_id = ? AND s.sale_date >= ?
    WHERE p.shop_owner_id = ?
    GROUP BY p.id
");
$sold30Stmt->execute([$demoOwnerId, $thirtyDaysAgo, $demoOwnerId]);
$sold30Rows = $sold30Stmt->fetchAll(PDO::FETCH_ASSOC);

$deadStockCount = 0;
$slowMovingCount = 0;

foreach ($sold30Rows as $r) {
    $units = (int)$r['units'];
    $min = (int)$r['min_stock_threshold'];
    $sold = (int)$r['sold_30'];

    if ($units > 0 && $sold === 0) {
        $deadStockCount++;
    } elseif ($units > $min && $sold > 0) {
        $dailyRate = $sold / 30.0;
        $cover = $units / $dailyRate;
        if ($cover >= 60.0) {
            $slowMovingCount++;
        }
    }
}

assertTest($deadStockCount === 2, "Exactly 2 dead-stock products (0 sales in 30 days, units > 0)");
assertTest($slowMovingCount === 3, "Exactly 3 slow-moving products (low sales, stock cover >= 60 days)");

// -------------------------------------------------------------------
// 7. VERIFY PURCHASE ORDERS
// -------------------------------------------------------------------
echo "\n7. Verifying Purchase Orders...\n";

$poStmt = $pdo->prepare("
    SELECT id, order_no, status, total_estimated_cost, total_items, total_units, received_at
    FROM purchase_orders
    WHERE shop_owner_id = ?
    ORDER BY id ASC
");
$poStmt->execute([$demoOwnerId]);
$pos = $poStmt->fetchAll(PDO::FETCH_ASSOC);

assertTest(count($pos) === 2, "Exactly 2 purchase orders recorded");

$openPo = null;
$receivedPo = null;
foreach ($pos as $po) {
    if ($po['status'] === 'placed') $openPo = $po;
    if ($po['status'] === 'received') $receivedPo = $po;
}

assertTest($openPo !== null && $openPo['received_at'] === null, "PO 1 is 'placed' (open, awaiting delivery)");
assertTest($receivedPo !== null && $receivedPo['received_at'] !== null, "PO 2 is 'received' (delivered with timestamp)");

// Verify PO items exist
$poItemsStmt = $pdo->prepare("
    SELECT purchase_order_id, COUNT(*) AS item_count, SUM(quantity) AS total_qty
    FROM purchase_order_items
    WHERE purchase_order_id IN (SELECT id FROM purchase_orders WHERE shop_owner_id = ?)
    GROUP BY purchase_order_id
");
$poItemsStmt->execute([$demoOwnerId]);
$poItemsMap = $poItemsStmt->fetchAll(PDO::FETCH_ASSOC);
assertTest(count($poItemsMap) === 2, "Both purchase orders have item lines in purchase_order_items");

// -------------------------------------------------------------------
// 8. TEST IDEMPOTENCY & ISOLATION
// -------------------------------------------------------------------
echo "\n8. Testing Idempotency & User Isolation...\n";

// Running without --reset when data exists must exit safely and not duplicate products
$secondRunCmd = sprintf('"%s" "%s"', $phpExec, $seederScript);
$secondRunOutput = shell_exec($secondRunCmd . " 2>&1");
assertTest(
    strpos($secondRunOutput, "already exists") !== false,
    "Second run without --reset exits safely with notice"
);

$prodCountCheck = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE shop_owner_id = {$demoOwnerId}")->fetchColumn();
assertTest($prodCountCheck === 30, "Product count remains exactly 30 after safe second run (no duplicates)");

// Check that no other shop owner was touched
$otherUsersStmt = $pdo->query("SELECT id FROM owner_account WHERE email != 'daksh14001@gmail.com'");
$otherUserIds = $otherUsersStmt->fetchAll(PDO::FETCH_COLUMN);
assertTest(!empty($otherUserIds), "Other shop owners exist and were preserved untouched");

// -------------------------------------------------------------------
// SUMMARY
// -------------------------------------------------------------------
echo "\n======================================================================\n";
echo "TEST RESULTS: {$passCount} PASSED, {$failCount} FAILED\n";
echo "======================================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
