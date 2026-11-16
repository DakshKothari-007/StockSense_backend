<?php
/**
 * seed_demo_data.php
 *
 * CLI-only tool to seed realistic 60-day demo sales data for a single shop owner.
 * Ensures Home, Sales, Reorder, and Insights endpoints return rich, realistic data.
 *
 * Usage:
 *   php seed_demo_data.php <owner-email>
 *   php seed_demo_data.php <owner-email> --remove
 */

// 1. Enforce CLI-only execution (strictly refuse to run from web/HTTP)
if (php_sapi_name() !== 'cli' || isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "Access Denied: This script is restricted to CLI execution only.\n";
    exit(1);
}

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/config/db.php';
$pdo->exec("SET time_zone = '+05:30'");

// 2. Parse command line arguments
$email = null;
$isRemoveMode = false;

for ($i = 1; $i < count($argv); $i++) {
    $arg = trim($argv[$i]);
    if ($arg === '--remove' || $arg === '-r') {
        $isRemoveMode = true;
    } elseif (strpos($arg, '--email=') === 0) {
        $email = trim(substr($arg, 8));
    } elseif (strpos($arg, '-') !== 0 && $email === null) {
        $email = $arg;
    }
}

if (empty($email)) {
    echo "=================================================================\n";
    echo " StockSense Demo Data Generator (CLI Only)\n";
    echo "=================================================================\n";
    echo "Usage:\n";
    echo "  php seed_demo_data.php <owner-email>          # Seed 60 days of sales\n";
    echo "  php seed_demo_data.php <owner-email> --remove # Remove DEMO- bills & restore stock\n\n";

    echo "Available Shop Owners in database:\n";
    $listStmt = $pdo->query("
        SELECT o.id, o.email, o.shop_name, COUNT(p.id) AS product_count 
        FROM owner_account o 
        LEFT JOIN products p ON o.id = p.shop_owner_id 
        GROUP BY o.id 
        ORDER BY o.id ASC
    ");
    while ($row = $listStmt->fetch(PDO::FETCH_ASSOC)) {
        echo sprintf("  - %-32s (%s, %d products)\n", $row['email'], $row['shop_name'], (int)$row['product_count']);
    }
    echo "=================================================================\n";
    exit(1);
}

// 3. Find shop owner
$ownerStmt = $pdo->prepare("SELECT id, full_name, shop_name, email FROM owner_account WHERE email = ? LIMIT 1");
$ownerStmt->execute([$email]);
$owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);

if (!$owner) {
    echo "Error: Shop owner with email '{$email}' not found in database.\n";
    exit(1);
}

$shopOwnerId = (int)$owner['id'];
$shopName = $owner['shop_name'];

echo "\n=================================================================\n";
echo " Target Shop: {$shopName} (Owner ID: {$shopOwnerId}, Email: {$email})\n";
echo "=================================================================\n";

/**
 * Removes DEMO- bills for the given shop and restores product stock.
 */
function removeDemoData(PDO $pdo, int $shopOwnerId, bool $verbose = true): int {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE shop_owner_id = ? AND bill_no LIKE 'DEMO-%'");
    $countStmt->execute([$shopOwnerId]);
    $demoBillsCount = (int)$countStmt->fetchColumn();

    if ($demoBillsCount === 0) {
        if ($verbose) {
            echo "No DEMO- bills found for shop ID {$shopOwnerId}. Nothing to remove.\n";
        }
        return 0;
    }

    // Calculate total quantities per product sold in DEMO- bills
    $qtyStmt = $pdo->prepare("
        SELECT si.product_id, p.name, p.sku, SUM(si.quantity) AS total_qty
        FROM sales_items si
        JOIN sales s ON si.sale_id = s.id
        JOIN products p ON si.product_id = p.id
        WHERE s.shop_owner_id = ? AND s.bill_no LIKE 'DEMO-%'
        GROUP BY si.product_id
    ");
    $qtyStmt->execute([$shopOwnerId]);
    $restorations = $qtyStmt->fetchAll(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();
    try {
        // 1. Restore stock
        $restoreStmt = $pdo->prepare("UPDATE products SET units = units + ? WHERE id = ? AND shop_owner_id = ?");
        foreach ($restorations as $r) {
            $qty = (int)$r['total_qty'];
            $restoreStmt->execute([$qty, (int)$r['product_id'], $shopOwnerId]);
            if ($verbose) {
                echo sprintf("  [RESTORE] %s (%s): +%d units\n", $r['name'], $r['sku'], $qty);
            }
        }

        // 2. Delete sales_items
        $delItems = $pdo->prepare("
            DELETE si FROM sales_items si
            JOIN sales s ON si.sale_id = s.id
            WHERE s.shop_owner_id = ? AND s.bill_no LIKE 'DEMO-%'
        ");
        $delItems->execute([$shopOwnerId]);
        $itemsDeleted = $delItems->rowCount();

        // 3. Delete sales
        $delSales = $pdo->prepare("DELETE FROM sales WHERE shop_owner_id = ? AND bill_no LIKE 'DEMO-%'");
        $delSales->execute([$shopOwnerId]);

        // 4. Clean any demo responses
        $pdo->prepare("DELETE FROM recommendation_responses WHERE shop_owner_id = ? AND notes LIKE '%DEMO%'")->execute([$shopOwnerId]);

        $pdo->commit();

        if ($verbose) {
            echo "\nRemoval complete:\n";
            echo "  Deleted {$demoBillsCount} DEMO- bills ({$itemsDeleted} sales item rows).\n";
            echo "  Inventory stock fully restored for " . count($restorations) . " products.\n";
        }
        return $demoBillsCount;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "Error during demo data removal: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// Handle --remove flag
if ($isRemoveMode) {
    echo "Mode: REMOVE DEMO DATA\n";
    removeDemoData($pdo, $shopOwnerId, true);
    echo "=================================================================\n\n";
    exit(0);
}

// 4. Seeding Mode: check if previous demo data exists, and clean first
$checkExisting = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE shop_owner_id = ? AND bill_no LIKE 'DEMO-%'");
$checkExisting->execute([$shopOwnerId]);
$existingCount = (int)$checkExisting->fetchColumn();
if ($existingCount > 0) {
    echo "Found {$existingCount} existing DEMO- bills. Purging previous demo data first...\n";
    removeDemoData($pdo, $shopOwnerId, false);
    echo "Previous demo data cleared.\n\n";
}

// 5. Fetch shop's active products
$prodStmt = $pdo->prepare("
    SELECT id, name, sku, category, units, min_stock_threshold, price, cost_price 
    FROM products 
    WHERE shop_owner_id = ? AND (status IS NULL OR (status NOT LIKE '%Archived%' AND status NOT LIKE '%Inactive%'))
    ORDER BY id ASC
");
$prodStmt->execute([$shopOwnerId]);
$products = $prodStmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($products)) {
    echo "Error: Shop '{$shopName}' has no active products. Please import or add products first.\n";
    exit(1);
}

echo "Found " . count($products) . " active products for shop.\n";

// 6. Intelligent Product Role Classification
$assignedIds = [];
$fastProducts = [];
$slowProducts = [];
$pairProduct1 = null;
$pairProduct2 = null;
$normalProducts = [];

// A. Identify Pair candidates (e.g. Tea + Biscuits, Bread + Butter, etc.)
foreach ($products as $p) {
    $lower = strtolower($p['name'] . ' ' . $p['category']);
    if ($pairProduct1 === null && (strpos($lower, 'tea') !== false || strpos($lower, 'bread') !== false || strpos($lower, 'coffee') !== false || strpos($lower, 'biscuit') !== false)) {
        $pairProduct1 = $p;
        $assignedIds[$p['id']] = true;
        break;
    }
}
if ($pairProduct1 !== null) {
    foreach ($products as $p) {
        if (isset($assignedIds[$p['id']])) continue;
        $lower = strtolower($p['name'] . ' ' . $p['category']);
        if (strpos($lower, 'parle') !== false || strpos($lower, 'biscuit') !== false || strpos($lower, 'butter') !== false || strpos($lower, 'sugar') !== false || strpos($lower, 'rusk') !== false) {
            $pairProduct2 = $p;
            $assignedIds[$p['id']] = true;
            break;
        }
    }
}

// B. Identify Fast-selling candidates (Low-Stock Reorder items: e.g. Milk, Dairy, or Notification biscuits)
foreach ($products as $p) {
    if (isset($assignedIds[$p['id']])) continue;
    $lower = strtolower($p['name'] . ' ' . $p['category']);
    if (strpos($lower, 'milk') !== false || strpos($lower, 'dairy') !== false || strpos($lower, 'curd') !== false || strpos($lower, 'notif') !== false) {
        $fastProducts[] = $p;
        $assignedIds[$p['id']] = true;
        if (count($fastProducts) >= 2) break; // Keep up to 2 low stock items
    }
}

// C. Identify Slow-moving candidate (e.g. Specialty biscuits, high price, slow turnover)
foreach ($products as $p) {
    if (isset($assignedIds[$p['id']])) continue;
    $lower = strtolower($p['name'] . ' ' . $p['category']);
    if (strpos($lower, 'marie') !== false || strpos($lower, 'snack') !== false || strpos($lower, 'surf') !== false || strpos($lower, 'colgate') !== false) {
        $slowProducts[] = $p;
        $assignedIds[$p['id']] = true;
        break;
    }
}

// D. Fallback allocations for remaining products
foreach ($products as $p) {
    if (isset($assignedIds[$p['id']])) continue;
    if (empty($fastProducts)) {
        $fastProducts[] = $p;
        $assignedIds[$p['id']] = true;
    } elseif (empty($slowProducts)) {
        $slowProducts[] = $p;
        $assignedIds[$p['id']] = true;
    } elseif ($pairProduct1 === null) {
        $pairProduct1 = $p;
        $assignedIds[$p['id']] = true;
    } elseif ($pairProduct2 === null) {
        $pairProduct2 = $p;
        $assignedIds[$p['id']] = true;
    } else {
        $normalProducts[] = $p;
        $assignedIds[$p['id']] = true;
    }
}

// If pair 1 exists without pair 2, pick from normal products
if ($pairProduct1 !== null && $pairProduct2 === null && !empty($normalProducts)) {
    $pairProduct2 = array_shift($normalProducts);
}

echo "Role assignments:\n";
foreach ($fastProducts as $fp) {
    echo "  [FAST-SELLING / LOW STOCK] ID {$fp['id']} - {$fp['name']} (Min: {$fp['min_stock_threshold']})\n";
}
foreach ($slowProducts as $sp) {
    echo "  [SLOW-MOVING CLEARANCE]   ID {$sp['id']} - {$sp['name']} (Min: {$sp['min_stock_threshold']})\n";
}
if ($pairProduct1 && $pairProduct2) {
    echo "  [FREQUENT PAIR]           ID {$pairProduct1['id']} ({$pairProduct1['name']}) + ID {$pairProduct2['id']} ({$pairProduct2['name']})\n";
}
foreach ($normalProducts as $np) {
    echo "  [NORMAL PRODUCT]          ID {$np['id']} - {$np['name']} (Min: {$np['min_stock_threshold']})\n";
}
echo "\n";

// Target ending stock for each product
$targetEndingStock = [];
foreach ($products as $p) {
    $pid = (int)$p['id'];
    $min = max(5, (int)$p['min_stock_threshold']);

    $isFast = false;
    foreach ($fastProducts as $fp) { if ((int)$fp['id'] === $pid) { $isFast = true; break; } }

    $isSlow = false;
    foreach ($slowProducts as $sp) { if ((int)$sp['id'] === $pid) { $isSlow = true; break; } }

    if ($isFast) {
        // Must end strictly BELOW min_stock_threshold (e.g. 30-40% of min) so it appears in Low Stock & Reorder
        $targetEndingStock[$pid] = max(2, (int)floor($min * 0.35));
    } elseif ($isSlow) {
        // Must end strictly ABOVE min_stock_threshold with high stock (>= 60 cover days)
        $targetEndingStock[$pid] = max(75, $min + 45);
    } else {
        // Normal & Pair items: healthy stock comfortably above min (stock cover 20-35 days, not reorder)
        $targetEndingStock[$pid] = max(35, $min + 20);
    }
}

// 7. Simulate 60 days of bills in memory
$totalUnitsNeeded = [];
$totalUnitsSoldLast30Days = [];
foreach ($products as $p) {
    $totalUnitsNeeded[(int)$p['id']] = 0;
    $totalUnitsSoldLast30Days[(int)$p['id']] = 0;
}

$simulatedBills = [];
$nowTs = time();

// Day 59 down to 0 (60 days)
for ($day = 59; $day >= 0; $day--) {
    $billsCountToday = rand(8, 15);
    $dateStr = date('Y-m-d', strtotime("-{$day} days"));

    // Generate sorted daytime timestamps
    $dayTimestamps = [];
    if ($day === 0) {
        // Today: from 08:30 up to max 60s ago
        $nowSec = $nowTs - strtotime('today 00:00:00');
        $maxSec = max(1800, $nowSec - 60);
        $minSec = max(0, min(8 * 3600 + 30 * 60, $maxSec - 300));
        for ($b = 0; $b < $billsCountToday; $b++) {
            $dayTimestamps[] = rand($minSec, $maxSec);
        }
    } else {
        // Past days: 08:30 to 21:00
        $startSeconds = 8 * 3600 + 30 * 60;
        $endSeconds = 21 * 3600;
        for ($b = 0; $b < $billsCountToday; $b++) {
            $dayTimestamps[] = rand($startSeconds, $endSeconds);
        }
    }
    sort($dayTimestamps);

    for ($b = 0; $b < $billsCountToday; $b++) {
        $saleTimeSec = $dayTimestamps[$b];
        $saleDate = date('Y-m-d H:i:s', strtotime("{$dateStr} 00:00:00 + {$saleTimeSec} seconds"));
        $billNo = sprintf("DEMO-%s-%04d", str_replace('-', '', $dateStr), $b + 1);

        $billItems = []; // productId => qty

        // A. Frequent Pair Logic: ~18% of bills include BOTH products in the pair
        if ($pairProduct1 && $pairProduct2 && rand(1, 100) <= 18) {
            $billItems[(int)$pairProduct1['id']] = rand(1, 2);
            $billItems[(int)$pairProduct2['id']] = rand(1, 2);
        }

        // B. Fast-Selling Product Logic: ~45% of bills include fast product(s)
        if (!empty($fastProducts) && rand(1, 100) <= 45) {
            $fp = $fastProducts[array_rand($fastProducts)];
            $pid = (int)$fp['id'];
            $billItems[$pid] = ($billItems[$pid] ?? 0) + rand(1, 2);
        }

        // C. Slow-Moving Product Logic:
        //    In days 59..30: only 2-3 sales overall
        //    In days 29..0: exactly 2 sales overall across all 30 days
        if (!empty($slowProducts)) {
            foreach ($slowProducts as $sp) {
                $spId = (int)$sp['id'];
                if ($day >= 30) {
                    if (rand(1, 100) <= 2) {
                        $billItems[$spId] = ($billItems[$spId] ?? 0) + 1;
                    }
                } else {
                    // Only sell on day 22 and day 8, on the first bill of that day
                    if (($day === 22 || $day === 8) && $b === 0) {
                        $billItems[$spId] = ($billItems[$spId] ?? 0) + 1;
                    }
                }
            }
        }

        // D. Normal products: moderate sales (~18% chance each)
        $targetItemCount = rand(1, min(5, count($products)));
        if (!empty($normalProducts)) {
            foreach ($normalProducts as $np) {
                if (count($billItems) >= $targetItemCount) break;
                if (rand(1, 100) <= 18) {
                    $nid = (int)$np['id'];
                    if (!isset($billItems[$nid])) {
                        $billItems[$nid] = 1;
                    }
                }
            }
        }

        // Ensure bill has at least 1 item and at most 5 items
        if (empty($billItems)) {
            $fallback = !empty($fastProducts) ? $fastProducts[0] : $products[0];
            $billItems[(int)$fallback['id']] = 1;
        }

        // Accumulate quantities needed
        foreach ($billItems as $pid => $qty) {
            $totalUnitsNeeded[$pid] += $qty;
            if ($day < 30) {
                $totalUnitsSoldLast30Days[$pid] += $qty;
            }
        }

        $simulatedBills[] = [
            'bill_no' => $billNo,
            'sale_date' => $saleDate,
            'items' => $billItems
        ];
    }
}

echo "Simulated " . count($simulatedBills) . " bills across 60 days.\n";

// 8. Establish consistent opening stock:
//    opening_stock = target_ending_stock + total_units_to_sell
//    For normal products, ensure target_ending_stock provides >= 15 days cover so they are NOT on reorder
foreach ($products as $p) {
    $pid = (int)$p['id'];
    $isFast = false;
    foreach ($fastProducts as $fp) { if ((int)$fp['id'] === $pid) { $isFast = true; break; } }
    $isSlow = false;
    foreach ($slowProducts as $sp) { if ((int)$sp['id'] === $pid) { $isSlow = true; break; } }

    if (!$isFast && !$isSlow) {
        $sold30 = $totalUnitsSoldLast30Days[$pid] ?? 0;
        $dailyRate = $sold30 / 30.0;
        // Reorder list threshold is 12 days cover. Target >= 18 days cover for healthy stock
        $minCoverStock = (int)ceil($dailyRate * 18);
        $minThreshold = (int)$p['min_stock_threshold'];
        $targetEndingStock[$pid] = max($targetEndingStock[$pid], $minCoverStock, $minThreshold + 10);
    }
}

$pdo->beginTransaction();
try {
    $updateOpenStock = $pdo->prepare("UPDATE products SET units = ? WHERE id = ? AND shop_owner_id = ?");
    echo "\nSetting opening inventory stock:\n";
    foreach ($products as $p) {
        $pid = (int)$p['id'];
        $needed = $totalUnitsNeeded[$pid] ?? 0;
        $endTarget = $targetEndingStock[$pid];
        $opening = $endTarget + $needed;
        $updateOpenStock->execute([$opening, $pid, $shopOwnerId]);
        echo sprintf("  %-32s: Opening=%-4d Sold=%-4d Target Ending=%-3d (Min=%d)\n",
            $p['name'], $opening, $needed, $endTarget, (int)$p['min_stock_threshold']
        );
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error updating opening stock: " . $e->getMessage() . "\n";
    exit(1);
}

// Fast price lookup
$productPriceMap = [];
foreach ($products as $p) {
    $productPriceMap[(int)$p['id']] = (float)$p['price'];
}

// 9. Insert all bills in a transaction following sales/create.php rules
echo "\nWriting sales bills and deducting stock atomatically...\n";
$pdo->beginTransaction();
try {
    $insertSaleStmt = $pdo->prepare("
        INSERT INTO sales (shop_owner_id, bill_no, sale_date, total_amount, notes)
        VALUES (?, ?, ?, ?, 'DEMO bill seeded for realistic dashboard metrics')
    ");
    $insertItemStmt = $pdo->prepare("
        INSERT INTO sales_items (sale_id, product_id, quantity, unit_price, line_total)
        VALUES (?, ?, ?, ?, ?)
    ");
    $deductStockStmt = $pdo->prepare("
        UPDATE products SET units = units - ? 
        WHERE id = ? AND shop_owner_id = ? AND units >= ?
    ");

    $totalBillsInserted = 0;
    $totalItemsInserted = 0;

    foreach ($simulatedBills as $bill) {
        $billNo = $bill['bill_no'];
        $saleDate = $bill['sale_date'];
        $items = $bill['items'];

        $totalAmount = 0.00;
        $processed = [];

        foreach ($items as $pid => $qty) {
            $price = $productPriceMap[$pid] ?? 10.00;
            $lineTotal = round($qty * $price, 2);
            $totalAmount += $lineTotal;

            // Deduct stock atomically
            $deductStockStmt->execute([$qty, $pid, $shopOwnerId, $qty]);
            if ($deductStockStmt->rowCount() === 0) {
                throw new Exception("Stock deduction failed for product ID {$pid} on bill {$billNo}.");
            }

            $processed[] = [
                'product_id' => $pid,
                'quantity' => $qty,
                'unit_price' => $price,
                'line_total' => $lineTotal
            ];
        }

        $totalAmount = round($totalAmount, 2);
        $insertSaleStmt->execute([$shopOwnerId, $billNo, $saleDate, $totalAmount]);
        $saleId = (int)$pdo->lastInsertId();

        foreach ($processed as $pi) {
            $insertItemStmt->execute([$saleId, $pi['product_id'], $pi['quantity'], $pi['unit_price'], $pi['line_total']]);
            $totalItemsInserted++;
        }

        $totalBillsInserted++;
    }

    $pdo->commit();
    echo "Successfully recorded {$totalBillsInserted} sales bills ({$totalItemsInserted} items) into database.\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Fatal error recording bills: " . $e->getMessage() . "\n";
    exit(1);
}

// 10. Verify ending stock and compute statistics
$checkFinal = $pdo->prepare("SELECT id, name, sku, units, min_stock_threshold FROM products WHERE shop_owner_id = ? ORDER BY id ASC");
$checkFinal->execute([$shopOwnerId]);
$finalProducts = $checkFinal->fetchAll(PDO::FETCH_ASSOC);

$lowStockCount = 0;
foreach ($finalProducts as $fp) {
    if ((int)$fp['units'] <= (int)$fp['min_stock_threshold']) {
        $lowStockCount++;
    }
}

// 11. Generate a temporary Bearer token to test live API endpoints
$rawToken = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
$expiresAt = date('Y-m-d H:i:s', strtotime('+1 day'));
$pdo->prepare("INSERT INTO tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)")
    ->execute([$shopOwnerId, $tokenHash, $expiresAt]);

function callApiEndpoint(string $path, string $token): array {
    $url = "http://localhost/StockSense_backend/" . ltrim($path, '/');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$token}",
        "Accept: application/json"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($raw, true);
    return ['code' => $code, 'raw' => $raw, 'json' => $json];
}

echo "\n=================================================================\n";
echo " SEEDING RESULTS & COUNTS\n";
echo "=================================================================\n";
echo "Total Bills created:        {$totalBillsInserted}\n";
echo "Total Items sold:           {$totalItemsInserted}\n";
echo "Low-Stock products count:   {$lowStockCount}\n";

// Query Slow-Moving API
$resSlow = callApiEndpoint("insights/slow_moving.php", $rawToken);
$slowMovingCount = 0;
if ($resSlow['code'] === 200 && isset($resSlow['json']['data']['total_slow_moving_count'])) {
    $slowMovingCount = (int)$resSlow['json']['data']['total_slow_moving_count'];
}
echo "Slow-Moving products count: {$slowMovingCount}\n";

// Query Product Pairs API
$resPairs = callApiEndpoint("insights/product_pairs.php", $rawToken);
$pairsCount = 0;
if ($resPairs['code'] === 200 && isset($resPairs['json']['data']['total_pairs_count'])) {
    $pairsCount = (int)$resPairs['json']['data']['total_pairs_count'];
}
echo "Product Pairs count found:  {$pairsCount}\n";

echo "\n=================================================================\n";
echo " VERIFYING LIVE ENDPOINTS (API TEST CALLS)\n";
echo "=================================================================\n";

// 1. Dashboard Summary
$resDash = callApiEndpoint("dashboard/summary.php", $rawToken);
echo "1. GET /dashboard/summary.php -> HTTP {$resDash['code']}\n";
if ($resDash['code'] === 200 && isset($resDash['json']['data'])) {
    $d = $resDash['json']['data'];
    $todayRevenue = $d['sales']['today_sales_amount'] ?? 0;
    $todayBills = $d['sales']['today_bills_count'] ?? 0;
    $lowStockAlerts = count($d['alerts']['low_stock_items'] ?? []);
    $slowMovingAlerts = count($d['alerts']['slow_moving_items'] ?? []);
    echo "   [PASS] Today's Sales: ₹{$todayRevenue} across {$todayBills} bills\n";
    echo "   [PASS] Dashboard Alerts: {$lowStockAlerts} Low Stock, {$slowMovingAlerts} Slow Moving\n";
} else {
    echo "   [FAIL] Could not verify dashboard summary: " . ($resDash['json']['message'] ?? 'Unknown error') . "\n";
}

// 2. Reorder List
$resReorder = callApiEndpoint("reorder/list.php", $rawToken);
echo "\n2. GET /reorder/list.php -> HTTP {$resReorder['code']}\n";
if ($resReorder['code'] === 200 && isset($resReorder['json']['data'])) {
    $rData = $resReorder['json']['data'];
    $reorderItems = $rData['items'] ?? [];
    echo "   [PASS] Total items needing reorder: " . count($reorderItems) . "\n";
    foreach (array_slice($reorderItems, 0, 3) as $ri) {
        $pName = $ri['product']['name'] ?? 'Product';
        $curr = $ri['current_stock'] ?? 0;
        $sugg = $ri['suggested_quantity'] ?? 0;
        $prio = strtoupper($ri['priority'] ?? 'HIGH');
        echo "   [PASS] Recommendation: '{$pName}' ({$prio} priority, Stock: {$curr}, Suggested: +{$sugg} units)\n";
    }
} else {
    echo "   [FAIL] Could not verify reorder list: " . ($resReorder['json']['message'] ?? 'Unknown error') . "\n";
}

// 3. Slow Moving Insights
echo "\n3. GET /insights/slow_moving.php -> HTTP {$resSlow['code']}\n";
if ($resSlow['code'] === 200 && isset($resSlow['json']['data'])) {
    $smData = $resSlow['json']['data'];
    $hasHistory = $smData['has_enough_sales_history'] ? 'YES' : 'NO';
    $smItems = $smData['items'] ?? [];
    echo "   [PASS] Has enough history (>=30 days): {$hasHistory}\n";
    echo "   [PASS] Total slow-moving recommendations: " . count($smItems) . "\n";
    if (!empty($smItems)) {
        $firstSm = $smItems[0];
        $smName = $firstSm['name'] ?? 'Product';
        $cover = $firstSm['stock_cover_days'] ?? 0;
        $disc = $firstSm['clearance_suggestion']['discount_pct'] ?? 10;
        $suggPrice = $firstSm['clearance_suggestion']['suggested_price'] ?? 0;
        echo "   [PASS] Top Slow Item: '{$smName}' (Cover: {$cover} days, Offer: {$disc}% off at ₹{$suggPrice})\n";
    }
} else {
    echo "   [FAIL] Could not verify slow-moving insights.\n";
}

// 4. Product Pairs Insights
echo "\n4. GET /insights/product_pairs.php -> HTTP {$resPairs['code']}\n";
if ($resPairs['code'] === 200 && isset($resPairs['json']['data'])) {
    $ppData = $resPairs['json']['data'];
    $hasHistory = $ppData['has_enough_sales_history'] ? 'YES' : 'NO';
    $totalBillsAnalyzed = $ppData['total_bills_analyzed'] ?? 0;
    $ppItems = $ppData['items'] ?? [];
    echo "   [PASS] Has enough history (>=30 bills in 90 days): {$hasHistory} ({$totalBillsAnalyzed} bills analyzed)\n";
    echo "   [PASS] Total product pairs identified: " . count($ppItems) . "\n";
    if (!empty($ppItems)) {
        $firstPair = $ppItems[0];
        $headline = $firstPair['headline'] ?? 'Pair';
        $supp = round(($firstPair['metrics']['support'] ?? 0) * 100, 1);
        $lift = $firstPair['metrics']['lift'] ?? 1.0;
        echo "   [PASS] Pair Headline: {$headline}\n";
        echo "   [PASS] Pair Metrics: Support={$supp}%, Lift={$lift}x\n";
    }
} else {
    echo "   [FAIL] Could not verify product pairs insights.\n";
}

// Clean up temporary test token
$pdo->prepare("DELETE FROM tokens WHERE token_hash = ?")->execute([$tokenHash]);

echo "\n=================================================================\n";
echo " DEMO DATA SEEDING COMPLETE FOR '{$shopName}'\n";
echo " To remove demo bills and restore stock at any time, run:\n";
echo "   php seed_demo_data.php {$email} --remove\n";
echo "=================================================================\n\n";
