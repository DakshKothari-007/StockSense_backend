<?php

/**
 * seed_demo_data.php
 *
 * Command-line demo data seeder for StockSense backend.
 * Seeds a full demo supermarket shop owner (daksh14001@gmail.com), 30 products across
 * 8 categories, 30 days of sales history with weekday/weekend patterns, and 2 purchase orders.
 *
 * Usage:
 *   php cli/seed_demo_data.php
 *   php cli/seed_demo_data.php --reset
 *   php cli/seed_demo_data.php --dry-run
 *
 * Options:
 *   --reset     Delete any existing demo shop data for daksh14001@gmail.com and recreate fresh.
 *   --dry-run   Simulate seeding and print all summary figures without modifying the database.
 *   --help      Display this help documentation.
 */

// 1. Enforce CLI-only execution (strictly refuse to run from web/HTTP)
if (php_sapi_name() !== 'cli' || isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    echo "Access Denied: This script is restricted to CLI execution only.\n";
    exit(1);
}

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/IconClassifier.php';
require_once __DIR__ . '/../import/ColumnMatcher.php';

$pdo->exec("SET time_zone = '+05:30'");

// 2. Parse command line arguments
$cliOptions = getopt('', ['reset', 'dry-run', 'help']);
if (isset($cliOptions['help'])) {
    echo "======================================================================\n";
    echo "           STOCKSENSE DEMO DATA SEEDER (CLI ONLY)                     \n";
    echo "======================================================================\n";
    echo "Usage:\n";
    echo "  php cli/seed_demo_data.php            # Seed demo data if not already present\n";
    echo "  php cli/seed_demo_data.php --reset    # Delete demo shop data & re-seed fresh\n";
    echo "  php cli/seed_demo_data.php --dry-run  # Preview calculations without DB writes\n\n";
    exit(0);
}

$isReset = isset($cliOptions['reset']);
$isDryRun = isset($cliOptions['dry-run']);

// Demo shop owner constants
const DEMO_EMAIL = 'daksh14001@gmail.com';
const DEMO_FULL_NAME = 'Daksh Sharma';
const DEMO_SHOP_NAME = 'Daksh Supermarket';
const DEMO_LOCATION = 'Indiranagar, Bengaluru';
const DEMO_STORE_TYPE = 'Neighbourhood supermarket';
const DEMO_CURRENCY = '₹ INR / IST';

/**
 * Generates a cryptographically strong random password containing uppercase,
 * lowercase, digits, and special characters. Never stored in plaintext.
 */
function generateStrongPassword(int $length = 16): string {
    $charsUpper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $charsLower = 'abcdefghjkmnpqrstuvwxyz';
    $charsDigits = '23456789';
    $charsSpecial = '!@#$%^&*()-_=+';

    $pwd = [
        $charsUpper[random_int(0, strlen($charsUpper) - 1)],
        $charsLower[random_int(0, strlen($charsLower) - 1)],
        $charsDigits[random_int(0, strlen($charsDigits) - 1)],
        $charsSpecial[random_int(0, strlen($charsSpecial) - 1)],
    ];

    $allChars = $charsUpper . $charsLower . $charsDigits . $charsSpecial;
    for ($i = 4; $i < $length; $i++) {
        $pwd[] = $allChars[random_int(0, strlen($allChars) - 1)];
    }
    shuffle($pwd);
    return implode('', $pwd);
}

/**
 * Atomically purges all existing data belonging strictly to the demo shop owner.
 * Never touches any other user's data.
 */
function cleanupDemoData(PDO $pdo, int $shopOwnerId, string $email): void {
    $pdo->prepare("DELETE si FROM sales_items si JOIN sales s ON si.sale_id = s.id WHERE s.shop_owner_id = ?")->execute([$shopOwnerId]);
    $pdo->prepare("DELETE FROM sales WHERE shop_owner_id = ?")->execute([$shopOwnerId]);
    $pdo->prepare("DELETE poi FROM purchase_order_items poi JOIN purchase_orders po ON poi.purchase_order_id = po.id WHERE po.shop_owner_id = ?")->execute([$shopOwnerId]);
    $pdo->prepare("DELETE FROM purchase_orders WHERE shop_owner_id = ?")->execute([$shopOwnerId]);
    $pdo->prepare("DELETE FROM products WHERE shop_owner_id = ?")->execute([$shopOwnerId]);
    $pdo->prepare("DELETE FROM import_history WHERE shop_owner_id = ?")->execute([$shopOwnerId]);
    $pdo->prepare("DELETE FROM import_mappings WHERE shop_owner_id = ?")->execute([$shopOwnerId]);
    $pdo->prepare("DELETE FROM recommendation_responses WHERE shop_owner_id = ?")->execute([$shopOwnerId]);
    $pdo->prepare("DELETE FROM notification_states WHERE shop_owner_id = ?")->execute([$shopOwnerId]);
    $pdo->prepare("DELETE FROM device_tokens WHERE shop_id = ? OR user_id = ?")->execute([$shopOwnerId, $shopOwnerId]);
    $pdo->prepare("DELETE FROM push_log WHERE shop_id = ?")->execute([$shopOwnerId]);
    $pdo->prepare("DELETE FROM user_settings WHERE user_id = ?")->execute([$shopOwnerId]);
    $pdo->prepare("DELETE FROM tokens WHERE user_id = ?")->execute([$shopOwnerId]);
    $pdo->prepare("DELETE FROM rate_limits WHERE identifier = ?")->execute([$email]);
    $pdo->prepare("DELETE FROM login_attempts WHERE identifier = ?")->execute([$email]);
    $pdo->prepare("DELETE FROM owner_account WHERE id = ?")->execute([$shopOwnerId]);
}

// 3. Check existing demo owner
$checkStmt = $pdo->prepare("SELECT id, email, full_name, shop_name FROM owner_account WHERE email = ? LIMIT 1");
$checkStmt->execute([DEMO_EMAIL]);
$existingOwner = $checkStmt->fetch(PDO::FETCH_ASSOC);

if ($existingOwner && !$isReset && !$isDryRun) {
    echo "======================================================================\n";
    echo "                 STOCKSENSE DEMO DATA NOTICE                          \n";
    echo "======================================================================\n";
    echo "Demo owner '" . DEMO_EMAIL . "' already exists in database (Owner ID: {$existingOwner['id']}).\n";
    echo "The database already contains demo data for this account.\n\n";
    echo "To delete and recreate fresh demo data, run with --reset:\n";
    echo "  php cli/seed_demo_data.php --reset\n\n";
    echo "To simulate seeding without changes, run with --dry-run:\n";
    echo "  php cli/seed_demo_data.php --dry-run\n";
    echo "======================================================================\n";
    exit(0);
}

// 4. Product catalog specification (30 products across 8 categories)
$catalogSpecs = [
    // --- 15 FAST MOVING PRODUCTS ---
    [
        'name' => 'Amul Taaza Fresh Toned Milk 500ml',
        'category' => 'Dairy',
        'price' => 27.00,
        'cost_price' => 24.00,
        'min_stock' => 20,
        'target_final_stock' => 45,
        'type' => 'fast',
        'daily_qty_range' => [7, 11]
    ],
    [
        'name' => 'Mother Dairy Cow Milk 1L',
        'category' => 'Dairy',
        'price' => 56.00,
        'cost_price' => 50.00,
        'min_stock' => 15,
        'target_final_stock' => 35,
        'type' => 'fast',
        'daily_qty_range' => [5, 8]
    ],
    [
        'name' => 'Aashirvaad Shudh Chakki Atta 5kg',
        'category' => 'Grocery',
        'price' => 245.00,
        'cost_price' => 215.00,
        'min_stock' => 15,
        'target_final_stock' => 30,
        'type' => 'fast',
        'daily_qty_range' => [4, 7]
    ],
    [
        'name' => 'Fortune Sunlite Refined Sunflower Oil 1L',
        'category' => 'Grocery',
        'price' => 135.00,
        'cost_price' => 118.00,
        'min_stock' => 15,
        'target_final_stock' => 28,
        'type' => 'fast',
        'daily_qty_range' => [4, 7]
    ],
    [
        'name' => 'Tata Salt Vacuum Evaporated 1kg',
        'category' => 'Grocery',
        'price' => 28.00,
        'cost_price' => 24.00,
        'min_stock' => 20,
        'target_final_stock' => 50,
        'type' => 'fast',
        'daily_qty_range' => [6, 9]
    ],
    [
        'name' => 'India Gate Basmati Rice Feast Rozzana 1kg',
        'category' => 'Grocery',
        'price' => 95.00,
        'cost_price' => 80.00,
        'min_stock' => 15,
        'target_final_stock' => 32,
        'type' => 'fast',
        'daily_qty_range' => [4, 7]
    ],
    [
        'name' => 'Maggi 2-Minute Masala Noodles 280g',
        'category' => 'Snacks',
        'price' => 55.00,
        'cost_price' => 46.00,
        'min_stock' => 25,
        'target_final_stock' => 60,
        'type' => 'fast',
        'daily_qty_range' => [8, 12]
    ],
    [
        'name' => 'Parle-G Original Gluco Biscuits 250g',
        'category' => 'Snacks',
        'price' => 30.00,
        'cost_price' => 25.00,
        'min_stock' => 30,
        'target_final_stock' => 65,
        'type' => 'fast',
        'daily_qty_range' => [8, 13]
    ],
    [
        'name' => "Lay's India's Magic Masala Potato Chips 50g",
        'category' => 'Snacks',
        'price' => 20.00,
        'cost_price' => 16.00,
        'min_stock' => 25,
        'target_final_stock' => 55,
        'type' => 'fast',
        'daily_qty_range' => [7, 11]
    ],
    [
        'name' => 'Tata Tea Premium Leaf Tea 500g',
        'category' => 'Beverages',
        'price' => 260.00,
        'cost_price' => 225.00,
        'min_stock' => 15,
        'target_final_stock' => 30,
        'type' => 'fast',
        'daily_qty_range' => [3, 6]
    ],
    [
        'name' => 'Surf Excel Easy Wash Detergent Powder 1kg',
        'category' => 'Household',
        'price' => 145.00,
        'cost_price' => 125.00,
        'min_stock' => 15,
        'target_final_stock' => 30,
        'type' => 'fast',
        'daily_qty_range' => [3, 6]
    ],
    [
        'name' => 'Vim Dishwash Liquid Gel Lemon 500ml',
        'category' => 'Household',
        'price' => 115.00,
        'cost_price' => 98.00,
        'min_stock' => 15,
        'target_final_stock' => 35,
        'type' => 'fast',
        'daily_qty_range' => [4, 7]
    ],
    [
        'name' => 'Modern White Sandwich Bread 400g',
        'category' => 'Bakery',
        'price' => 45.00,
        'cost_price' => 38.00,
        'min_stock' => 20,
        'target_final_stock' => 40,
        'type' => 'fast',
        'daily_qty_range' => [8, 12]
    ],
    [
        'name' => 'Farm Fresh Red Onions 1kg',
        'category' => 'Vegetables',
        'price' => 35.00,
        'cost_price' => 28.00,
        'min_stock' => 25,
        'target_final_stock' => 50,
        'type' => 'fast',
        'daily_qty_range' => [9, 14]
    ],
    [
        'name' => 'New Crop Washed Potatoes 1kg',
        'category' => 'Vegetables',
        'price' => 30.00,
        'cost_price' => 24.00,
        'min_stock' => 25,
        'target_final_stock' => 55,
        'type' => 'fast',
        'daily_qty_range' => [8, 13]
    ],

    // --- 5 LOW STOCK PRODUCTS (Units < min_stock_threshold) ---
    [
        'name' => 'Tata Sampann Unpolished Toor Dal 1kg',
        'category' => 'Grocery',
        'price' => 175.00,
        'cost_price' => 150.00,
        'min_stock' => 20,
        'target_final_stock' => 6, // 6 < 20
        'type' => 'low_stock',
        'daily_qty_range' => [3, 5]
    ],
    [
        'name' => 'Britannia Good Day Cashew Cookies 200g',
        'category' => 'Snacks',
        'price' => 45.00,
        'cost_price' => 38.00,
        'min_stock' => 25,
        'target_final_stock' => 8, // 8 < 25
        'type' => 'low_stock',
        'daily_qty_range' => [4, 6]
    ],
    [
        'name' => 'Brooke Bond Red Label Tea 250g',
        'category' => 'Beverages',
        'price' => 130.00,
        'cost_price' => 112.00,
        'min_stock' => 20,
        'target_final_stock' => 5, // 5 < 20
        'type' => 'low_stock',
        'daily_qty_range' => [3, 5]
    ],
    [
        'name' => 'Colgate Strong Teeth Dental Toothpaste 200g',
        'category' => 'Personal Care',
        'price' => 125.00,
        'cost_price' => 105.00,
        'min_stock' => 20,
        'target_final_stock' => 7, // 7 < 20
        'type' => 'low_stock',
        'daily_qty_range' => [3, 5]
    ],
    [
        'name' => 'Fresh Hybrid Tomatoes 1kg',
        'category' => 'Vegetables',
        'price' => 40.00,
        'cost_price' => 32.00,
        'min_stock' => 30,
        'target_final_stock' => 9, // 9 < 30
        'type' => 'low_stock',
        'daily_qty_range' => [6, 10]
    ],

    // --- 3 SLOW MOVING PRODUCTS (Units > 0, units > min, stock cover >= 60 days) ---
    [
        'name' => 'Colin Glass Cleaner Spray 500ml',
        'category' => 'Household',
        'price' => 105.00,
        'cost_price' => 88.00,
        'min_stock' => 5,
        'target_final_stock' => 24, // 24 > 5
        'type' => 'slow_moving',
        'exact_sales' => [24 => 1, 15 => 1, 4 => 1] // 3 units sold in 30 days -> rate 0.1/day -> cover 240 days
    ],
    [
        'name' => 'Head & Shoulders Anti-Dandruff Shampoo 180ml',
        'category' => 'Personal Care',
        'price' => 185.00,
        'cost_price' => 155.00,
        'min_stock' => 5,
        'target_final_stock' => 20, // 20 > 5
        'type' => 'slow_moving',
        'exact_sales' => [20 => 1, 7 => 1] // 2 units sold in 30 days -> rate 0.07/day -> cover 285 days
    ],
    [
        'name' => 'Real Fruit Power Mixed Fruit Juice 1L',
        'category' => 'Beverages',
        'price' => 120.00,
        'cost_price' => 100.00,
        'min_stock' => 5,
        'target_final_stock' => 22, // 22 > 5
        'type' => 'slow_moving',
        'exact_sales' => [27 => 1, 18 => 1, 11 => 1, 2 => 1] // 4 units sold in 30 days -> rate 0.13/day -> cover 165 days
    ],

    // --- 2 DEAD STOCK PRODUCTS (0 sales in 30 days, units > 0) ---
    [
        'name' => 'Nescafe Classic Instant Coffee 50g',
        'category' => 'Beverages',
        'price' => 170.00,
        'cost_price' => 145.00,
        'min_stock' => 5,
        'target_final_stock' => 18,
        'type' => 'dead_stock',
        'exact_sales' => []
    ],
    [
        'name' => 'Dettol Original Liquid Handwash 200ml',
        'category' => 'Personal Care',
        'price' => 99.00,
        'cost_price' => 82.00,
        'min_stock' => 5,
        'target_final_stock' => 25,
        'type' => 'dead_stock',
        'exact_sales' => []
    ],

    // --- 1 OUT OF STOCK PRODUCT (units = 0) ---
    [
        'name' => 'Nandini Pure Ghee 500ml',
        'category' => 'Dairy',
        'price' => 310.00,
        'cost_price' => 275.00,
        'min_stock' => 15,
        'target_final_stock' => 0, // Out of stock
        'type' => 'out_of_stock',
        'daily_qty_range' => [2, 3] // Sells out on day -5
    ],

    // --- 4 REGULAR PRODUCTS (Healthy stock > min_stock) ---
    [
        'name' => 'Amul Salted Butter 100g',
        'category' => 'Dairy',
        'price' => 58.00,
        'cost_price' => 52.00,
        'min_stock' => 10,
        'target_final_stock' => 25,
        'type' => 'regular',
        'daily_qty_range' => [2, 4]
    ],
    [
        'name' => "Haldiram's Nagpur Bhujia Sev 400g",
        'category' => 'Snacks',
        'price' => 110.00,
        'cost_price' => 92.00,
        'min_stock' => 10,
        'target_final_stock' => 28,
        'type' => 'regular',
        'daily_qty_range' => [2, 4]
    ],
    [
        'name' => 'Dove Cream Beauty Bathing Soap 100g',
        'category' => 'Personal Care',
        'price' => 62.00,
        'cost_price' => 52.00,
        'min_stock' => 12,
        'target_final_stock' => 30,
        'type' => 'regular',
        'daily_qty_range' => [2, 4]
    ],
    [
        'name' => 'Britannia Rusk Toast 300g',
        'category' => 'Bakery',
        'price' => 50.00,
        'cost_price' => 42.00,
        'min_stock' => 12,
        'target_final_stock' => 32,
        'type' => 'regular',
        'daily_qty_range' => [2, 4]
    ],
];

// 5. Generate 30 days of sales structure in memory
$generatedBills = [];
$productSoldTotals = array_fill(0, count($catalogSpecs), 0);
$nowTs = time();

// Start 31 days ago to ensure full >= 30-day window across all hours
for ($daysAgo = 31; $daysAgo >= 0; $daysAgo--) {
    $dayDateStr = date('Y-m-d', strtotime("-{$daysAgo} days", $nowTs));
    $dayOfWeek = (int)date('w', strtotime($dayDateStr));
    $isWeekend = ($dayOfWeek === 0 || $dayOfWeek === 6);

    // Weekday: 2 to 3 bills; Weekend: 3 to 4 bills
    $billsCount = $isWeekend ? 3 : 2;
    if ($daysAgo === 0) {
        $billsCount = 2; // Fixed 2 bills for today earlier than current time
    }

    for ($b = 1; $b <= $billsCount; $b++) {
        $billNo = sprintf("DEMO-%s-%03d", date('Ymd', strtotime($dayDateStr)), $b);

        // Realistic timestamps during retail store hours (09:00 to 20:00)
        if ($daysAgo === 0) {
            $billHour = ($b === 1) ? 9 : 10;
            $billMin = ($b === 1) ? 15 : 25;
            $billSec = 10;
        } else {
            $billHour = 9 + ($b * 3) + (($b + $dayOfWeek) % 2);
            $billMin = 10 + (($b * 17) % 40);
            $billSec = 15;
        }
        $saleDateStr = sprintf("%s %02d:%02d:%02d", $dayDateStr, $billHour, $billMin, $billSec);

        $billItems = [];

        // Realistic Indian supermarket basket configurations
        // Basket 1: Morning breakfast essentials (Milk + Bread + Tea/Parle-G)
        // Basket 2: Cooking pantry (Atta + Oil + Salt + Dal/Rice + Onions/Potatoes)
        // Basket 3: Evening snacks (Maggi + Chips + Good Day)
        // Basket 4: Home & personal care (Detergent + Vim + Toothpaste/Soap)
        $basketType = ($b + $dayOfWeek) % 4;

        if ($basketType === 0) {
            // Breakfast: Amul Milk (0) + Bread (12) + Tata Tea (9) + Parle-G (7)
            $billItems[0] = $isWeekend ? 3 : 2;
            $billItems[12] = $isWeekend ? 3 : 2;
            $billItems[9] = $isWeekend ? 2 : 1;
            $billItems[7] = $isWeekend ? 4 : 2;
        } elseif ($basketType === 1) {
            // Cooking: Atta (2) + Oil (3) + Salt (4) + Onions (13) + Potatoes (14) + Tomatoes (19)
            $billItems[2] = $isWeekend ? 2 : 1;
            $billItems[3] = $isWeekend ? 2 : 1;
            $billItems[4] = $isWeekend ? 3 : 2;
            $billItems[13] = $isWeekend ? 3 : 2;
            $billItems[14] = $isWeekend ? 3 : 2;
            $billItems[19] = $isWeekend ? 2 : 1;
            $billItems[15] = 1; // Toor Dal
            $billItems[5] = $isWeekend ? 2 : 1; // Basmati Rice
        } elseif ($basketType === 2) {
            // Snacks: Maggi (6) + Chips (8) + Good Day (16) + Butter (26) + Parle-G (7)
            $billItems[6] = $isWeekend ? 3 : 2;
            $billItems[8] = $isWeekend ? 3 : 2;
            $billItems[16] = $isWeekend ? 2 : 1;
            $billItems[26] = $isWeekend ? 2 : 1;
            $billItems[7] = 2;
        } else {
            // Home / Personal care: Surf Excel (10) + Vim Gel (11) + Toothpaste (18) + Soap (28)
            $billItems[10] = $isWeekend ? 2 : 1;
            $billItems[11] = $isWeekend ? 2 : 1;
            $billItems[18] = 1;
            $billItems[28] = $isWeekend ? 2 : 1;
        }

        // Additional regular purchases ensuring high sales velocity for staple items
        // Mother Dairy Milk (1) in morning bills
        if ($b === 1) {
            $billItems[1] = $isWeekend ? 2 : 1;
        }

        // Modern Bread (12) & Tata Salt (4) frequent staples
        if ($b === 1 && !isset($billItems[12])) {
            $billItems[12] = 2;
        }
        if ($b === 1 && !isset($billItems[4])) {
            $billItems[4] = 2;
        }

        // Tata Tea (9) & India Gate Rice (5) on alternate bills
        if ($b === 2 && !isset($billItems[9])) {
            $billItems[9] = 1;
        }
        if ($isWeekend && !isset($billItems[5])) {
            $billItems[5] = 2;
        }

        // Red Label Tea (17) in afternoon bills
        if ($b === 2) {
            $billItems[17] = 1;
        }

        // Out of stock product: Nandini Pure Ghee (25) sells out by day -5
        if ($daysAgo >= 5 && $b === 1) {
            $billItems[25] = ($daysAgo % 2 === 0) ? 2 : 1;
        }

        // Regular products: Bhujia Sev (27), Rusk Toast (29)
        if ($b === 2) {
            $billItems[27] = $isWeekend ? 2 : 1;
        }
        if ($b === 1) {
            $billItems[29] = $isWeekend ? 2 : 1;
        }

        // Inject slow moving items only on designated days
        foreach ([20, 21, 22] as $smIdx) {
            $spec = $catalogSpecs[$smIdx];
            if (isset($spec['exact_sales'][$daysAgo]) && $b === 1) {
                $billItems[$smIdx] = $spec['exact_sales'][$daysAgo];
            }
        }

        // Calculate bill line totals
        $saleItemsList = [];
        $billTotalAmount = 0.00;

        foreach ($billItems as $pIdx => $qty) {
            if ($qty <= 0) continue;
            $pPrice = (float)$catalogSpecs[$pIdx]['price'];
            $lineTotal = round($qty * $pPrice, 2);
            $billTotalAmount += $lineTotal;
            $productSoldTotals[$pIdx] += $qty;

            $saleItemsList[] = [
                'catalog_index' => $pIdx,
                'quantity' => $qty,
                'unit_price' => $pPrice,
                'line_total' => $lineTotal
            ];
        }

        $generatedBills[] = [
            'bill_no' => $billNo,
            'sale_date' => $saleDateStr,
            'total_amount' => round($billTotalAmount, 2),
            'items' => $saleItemsList
        ];
    }
}

// Calculate initial starting stock for each product so that:
// starting_stock - sum(sold_quantities) == target_final_stock exactly!
$startingStock = [];
for ($i = 0; $i < count($catalogSpecs); $i++) {
    $targetFinal = $catalogSpecs[$i]['target_final_stock'];
    $sold = $productSoldTotals[$i];
    $startingStock[$i] = $targetFinal + $sold;
}

// Total sales analytics summary
$totalBillsCount = count($generatedBills);
$totalSalesAmount = 0.00;
$totalSalesItemsCount = 0;
foreach ($generatedBills as $b) {
    $totalSalesAmount += $b['total_amount'];
    $totalSalesItemsCount += count($b['items']);
}

// Count inventory segments
$lowStockCount = 0;
$slowMovingCount = 0;
$deadStockCount = 0;
$outOfStockCount = 0;
$fastMovingCount = 0;
$regularCount = 0;

for ($i = 0; $i < count($catalogSpecs); $i++) {
    $type = $catalogSpecs[$i]['type'];
    if ($type === 'low_stock') $lowStockCount++;
    elseif ($type === 'slow_moving') $slowMovingCount++;
    elseif ($type === 'dead_stock') $deadStockCount++;
    elseif ($type === 'out_of_stock') $outOfStockCount++;
    elseif ($type === 'fast') $fastMovingCount++;
    elseif ($type === 'regular') $regularCount++;
}

// Generate random strong password (printed once to terminal, never stored in plaintext)
$plainPassword = generateStrongPassword(16);
$passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);

// 6. Execute DB Changes (if not --dry-run)
$createdShopOwnerId = null;

if (!$isDryRun) {
    try {
        $pdo->beginTransaction();

        // If --reset was requested and owner exists, purge old demo data
        if ($isReset && $existingOwner) {
            cleanupDemoData($pdo, (int)$existingOwner['id'], DEMO_EMAIL);
        }

        // Insert Demo Owner Account
        $insertOwner = $pdo->prepare("
            INSERT INTO owner_account (full_name, shop_name, location, store_type, currency, email, password)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $insertOwner->execute([
            DEMO_FULL_NAME,
            DEMO_SHOP_NAME,
            DEMO_LOCATION,
            DEMO_STORE_TYPE,
            DEMO_CURRENCY,
            DEMO_EMAIL,
            $passwordHash
        ]);
        $createdShopOwnerId = (int)$pdo->lastInsertId();

        // Ensure user settings row exists
        $settingsStmt = $pdo->prepare("INSERT IGNORE INTO user_settings (user_id) VALUES (?)");
        $settingsStmt->execute([$createdShopOwnerId]);

        // Insert Products with calculated starting stock
        $insertProd = $pdo->prepare("
            INSERT INTO products 
            (shop_owner_id, name, sku, category, description, price, cost_price, units, min_stock_threshold, status, icon_type, icon_key, icon_is_manual)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 0)
        ");

        $dbProductIds = [];
        for ($i = 0; $i < count($catalogSpecs); $i++) {
            $spec = $catalogSpecs[$i];
            $sku = ColumnMatcher::generateUniqueSku($pdo, $createdShopOwnerId, $spec['name'], $spec['category'], 10);
            if ($sku === null) {
                $sku = "SKU-" . strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $spec['category']), 0, 4)) . "-" . sprintf("%04X", $i + 1);
            }
            $iconKey = IconClassifier::classify($spec['name'], $spec['category'], false);
            $initialUnits = $startingStock[$i];

            $insertProd->execute([
                $createdShopOwnerId,
                $spec['name'],
                $sku,
                $spec['category'],
                "Authentic {$spec['name']} for neighbourhood retail customers.",
                $spec['price'],
                $spec['cost_price'],
                $initialUnits,
                $spec['min_stock'],
                'In stock',
                $iconKey
            ]);

            $dbProductIds[$i] = (int)$pdo->lastInsertId();
        }

        // Insert 30 Days of Sales and atomically reduce stock
        $insertSale = $pdo->prepare("
            INSERT INTO sales (shop_owner_id, bill_no, sale_date, total_amount, notes)
            VALUES (?, ?, ?, ?, ?)
        ");
        $insertSaleItem = $pdo->prepare("
            INSERT INTO sales_items (sale_id, product_id, quantity, unit_price, line_total)
            VALUES (?, ?, ?, ?, ?)
        ");
        $reduceStockStmt = $pdo->prepare("
            UPDATE products SET units = units - ? WHERE id = ? AND shop_owner_id = ?
        ");

        foreach ($generatedBills as $b) {
            $insertSale->execute([
                $createdShopOwnerId,
                $b['bill_no'],
                $b['sale_date'],
                $b['total_amount'],
                "Point of Sale transaction"
            ]);
            $saleId = (int)$pdo->lastInsertId();

            foreach ($b['items'] as $it) {
                $pIdx = $it['catalog_index'];
                $dbPid = $dbProductIds[$pIdx];
                $qty = $it['quantity'];

                $insertSaleItem->execute([
                    $saleId,
                    $dbPid,
                    $qty,
                    $it['unit_price'],
                    $it['line_total']
                ]);

                $reduceStockStmt->execute([$qty, $dbPid, $createdShopOwnerId]);
            }
        }

        // Update final status on products (Out of stock, Low stock, In stock)
        $statusStmt = $pdo->prepare("
            UPDATE products 
            SET status = CASE 
                WHEN units <= 0 THEN 'Out of stock'
                WHEN units <= min_stock_threshold THEN CONCAT('Low stock · Minimum ', min_stock_threshold)
                ELSE 'In stock'
            END
            WHERE shop_owner_id = ?
        ");
        $statusStmt->execute([$createdShopOwnerId]);

        // Insert 2 Purchase Orders (1 placed/open, 1 received)
        // PO 1: Placed (open) for out-of-stock Nandini Ghee (idx 25) & low-stock Toor Dal (idx 15)
        $po1PidGhee = $dbProductIds[25];
        $po1PidDal = $dbProductIds[15];
        $po1CostGhee = (float)$catalogSpecs[25]['cost_price'];
        $po1CostDal = (float)$catalogSpecs[15]['cost_price'];
        $po1QtyGhee = 30;
        $po1QtyDal = 25;
        $po1TotalEstCost = round(($po1QtyGhee * $po1CostGhee) + ($po1QtyDal * $po1CostDal), 2);

        $insertPo = $pdo->prepare("
            INSERT INTO purchase_orders (shop_owner_id, order_no, status, total_estimated_cost, total_items, total_units, received_at, notes, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $po1Created = date('Y-m-d 14:00:00', strtotime('-2 days', $nowTs));
        $insertPo->execute([
            $createdShopOwnerId,
            'PO-2026-001',
            'placed',
            $po1TotalEstCost,
            2,
            $po1QtyGhee + $po1QtyDal,
            null,
            'Urgent replenishment order for low and out-of-stock items',
            $po1Created
        ]);
        $po1Id = (int)$pdo->lastInsertId();

        $insertPoItem = $pdo->prepare("
            INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, cost_price, line_estimated_cost)
            VALUES (?, ?, ?, ?, ?)
        ");
        $insertPoItem->execute([$po1Id, $po1PidGhee, $po1QtyGhee, $po1CostGhee, round($po1QtyGhee * $po1CostGhee, 2)]);
        $insertPoItem->execute([$po1Id, $po1PidDal, $po1QtyDal, $po1CostDal, round($po1QtyDal * $po1CostDal, 2)]);

        // PO 2: Received 8 days ago for Atta (idx 2), Oil (idx 3), Salt (idx 4)
        $po2PidAtta = $dbProductIds[2];
        $po2PidOil = $dbProductIds[3];
        $po2PidSalt = $dbProductIds[4];
        $po2CostAtta = (float)$catalogSpecs[2]['cost_price'];
        $po2CostOil = (float)$catalogSpecs[3]['cost_price'];
        $po2CostSalt = (float)$catalogSpecs[4]['cost_price'];
        $po2QtyAtta = 20;
        $po2QtyOil = 20;
        $po2QtySalt = 30;
        $po2TotalEstCost = round(($po2QtyAtta * $po2CostAtta) + ($po2QtyOil * $po2CostOil) + ($po2QtySalt * $po2CostSalt), 2);

        $po2Created = date('Y-m-d 11:00:00', strtotime('-10 days', $nowTs));
        $po2Received = date('Y-m-d 16:30:00', strtotime('-8 days', $nowTs));

        $insertPo->execute([
            $createdShopOwnerId,
            'PO-2026-002',
            'received',
            $po2TotalEstCost,
            3,
            $po2QtyAtta + $po2QtyOil + $po2QtySalt,
            $po2Received,
            'Monthly inventory restock from Metro Cash & Carry',
            $po2Created
        ]);
        $po2Id = (int)$pdo->lastInsertId();

        $insertPoItem->execute([$po2Id, $po2PidAtta, $po2QtyAtta, $po2CostAtta, round($po2QtyAtta * $po2CostAtta, 2)]);
        $insertPoItem->execute([$po2Id, $po2PidOil, $po2QtyOil, $po2CostOil, round($po2QtyOil * $po2CostOil, 2)]);
        $insertPoItem->execute([$po2Id, $po2PidSalt, $po2QtySalt, $po2CostSalt, round($po2QtySalt * $po2CostSalt, 2)]);

        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "Error seeding demo data: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// 7. Terminal Report
echo "======================================================================\n";
echo "           STOCKSENSE DEMO DATA SEEDER REPORT                         \n";
echo "======================================================================\n";
echo "Mode:              " . ($isDryRun ? "DRY-RUN (Simulated, no database changes)" : "LIVE SEED (Committed to database)") . "\n";
if ($createdShopOwnerId !== null) {
    echo "Shop Owner ID:     {$createdShopOwnerId}\n";
}
echo "Demo Login Email:  " . DEMO_EMAIL . "\n";
if (!$isDryRun) {
    echo "Demo Password:     {$plainPassword}\n";
    echo "                   (Generated once; never stored in code, docs, or logs)\n";
} else {
    echo "Demo Password:     [Will be generated and shown during live run]\n";
}
echo "Store:             " . DEMO_SHOP_NAME . " (" . DEMO_LOCATION . ")\n";
echo "Currency:          " . DEMO_CURRENCY . "\n";
echo "----------------------------------------------------------------------\n";
echo "Products Created:  " . count($catalogSpecs) . "\n";
echo "  - Fast-Moving:   {$fastMovingCount} (steady daily sales, units > minimum)\n";
echo "  - Low Stock:     {$lowStockCount} (stock below minimum threshold)\n";
echo "  - Slow Moving:   {$slowMovingCount} (low sales velocity, stock cover >= 60d)\n";
echo "  - Dead Stock:    {$deadStockCount} (0 sales in past 30 days)\n";
echo "  - Out of Stock:  {$outOfStockCount} (0 units remaining in stock)\n";
echo "  - Regular Stock: {$regularCount} (steady turnover, units > minimum)\n";
echo "Categories:        8 (Dairy, Grocery, Snacks, Beverages, Household,\n";
echo "                      Personal Care, Bakery, Vegetables)\n";
echo "----------------------------------------------------------------------\n";
echo "Sales History:     30 days (" . date('Y-m-d', strtotime('-31 days', $nowTs)) . " to " . date('Y-m-d', $nowTs) . ")\n";
echo "Sales Rows (Bills):{$totalBillsCount} bills\n";
echo "Sales Items:       {$totalSalesItemsCount} item line entries\n";
echo "Total Sales:       ₹" . number_format($totalSalesAmount, 2) . "\n";
echo "Stock Reduction:   All sales reduced stock atomically (initial = final + sold)\n";
echo "----------------------------------------------------------------------\n";
echo "Purchase Orders:   2\n";
echo "  - PO-2026-001:   placed (open) · 2 items · 55 units · ₹12,000.00\n";
echo "  - PO-2026-002:   received (delivered 8 days ago) · 3 items · 70 units · ₹7,380.00\n";
echo "======================================================================\n";
echo "Home weekly chart, Insights recommendations, and AI chatbot ready!\n";
echo "======================================================================\n";
