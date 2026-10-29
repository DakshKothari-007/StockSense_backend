<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-User-Id, X-User-Email");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        "status" => false,
        "message" => "Method Not Allowed. Use GET for dashboard summary.",
        "data" => (object)[]
    ]);
    exit;
}

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../inventory/auth_helper.php";
require_once __DIR__ . "/../settings/settings_helper.php";

$pdo->exec("SET time_zone = '+05:30'");
$owner = authenticateShopOwner($pdo);
$ownerId = (int)$owner['id'];

// Load Shop Settings (Dashboard Integration)
$shopSettings = get_shop_settings($pdo, $ownerId);
$slowMovingMinDaysHistory = $shopSettings['min_history_days'];
$slowMovingMaxSold30Days = 10;
$slowMovingMinStockCoverDays = $shopSettings['slow_moving_threshold_days'];
$slowMovingRuleDesc = "units > 0 AND (units_sold_30_days <= 10 OR stock_cover_days >= {$slowMovingMinStockCoverDays})";

// 1. Validate Optional Date Range (from, to). Default: last 30 days
$todayDateStr = date('Y-m-d');
$toDateRaw = isset($_GET['to']) ? trim($_GET['to']) : $todayDateStr;
$fromDateRaw = isset($_GET['from']) ? trim($_GET['from']) : date('Y-m-d', strtotime('-30 days'));

$isValidDate = function(string $date): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
};

if (!$isValidDate($fromDateRaw) || !$isValidDate($toDateRaw)) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Invalid date format. Expected YYYY-MM-DD for 'from' and 'to'.",
        "data" => (object)[]
    ]);
    exit;
}

if ($fromDateRaw > $toDateRaw) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Invalid date range: 'from' date ({$fromDateRaw}) cannot be later than 'to' date ({$toDateRaw}).",
        "data" => (object)[]
    ]);
    exit;
}

// Range boundaries (Rule 6: range queries sale_date >= ? AND sale_date < ?)
$startDatetime = $fromDateRaw . " 00:00:00";
$endNextDay = date('Y-m-d', strtotime($toDateRaw . ' +1 day'));
$endDatetime = $endNextDay . " 00:00:00";

try {
    // 2. Fetch Store Profile details
    $storeStmt = $pdo->prepare("SELECT shop_name, location, currency FROM owner_account WHERE id = ? LIMIT 1");
    $storeStmt->execute([$ownerId]);
    $storeInfo = $storeStmt->fetch(PDO::FETCH_ASSOC);

    // 3. Aggregate Inventory Metrics (Active products only)
    $activeProductCondition = "(status IS NULL OR (status NOT LIKE '%Archived%' AND status NOT LIKE '%Inactive%'))";

    $aggStmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total_active_products,
            COALESCE(SUM(units * price), 0) AS total_stock_value,
            COALESCE(SUM(CASE WHEN units > 0 AND min_stock_threshold > 0 AND units <= min_stock_threshold THEN 1 ELSE 0 END), 0) AS low_stock_count,
            COALESCE(SUM(CASE WHEN units = 0 THEN 1 ELSE 0 END), 0) AS out_of_stock_count
        FROM products
        WHERE shop_owner_id = ? AND {$activeProductCondition}
    ");
    $aggStmt->execute([$ownerId]);
    $agg = $aggStmt->fetch(PDO::FETCH_ASSOC);

    $totalActiveProducts = (int)$agg['total_active_products'];
    $totalStockValue = round((float)$agg['total_stock_value'], 2);
    $lowStockCount = (int)$agg['low_stock_count'];
    $outOfStockCount = (int)$agg['out_of_stock_count'];

    // 4. Low Stock Items List (units > 0 AND units <= min_stock_threshold AND min_stock_threshold > 0)
    $lowStockStmt = $pdo->prepare("
        SELECT id, name, sku, category, units, min_stock_threshold, price, cost_price, status
        FROM products
        WHERE shop_owner_id = ? AND units > 0 AND min_stock_threshold > 0 AND units <= min_stock_threshold AND {$activeProductCondition}
        ORDER BY units ASC, min_stock_threshold DESC
        LIMIT 10
    ");
    $lowStockStmt->execute([$ownerId]);
    $lowStockItems = [];
    while ($r = $lowStockStmt->fetch(PDO::FETCH_ASSOC)) {
        $lowStockItems[] = [
            "id" => (int)$r['id'],
            "name" => $r['name'],
            "sku" => $r['sku'],
            "category" => !empty(trim($r['category'])) ? $r['category'] : "Uncategorised",
            "units" => (int)$r['units'],
            "min_stock_threshold" => (int)$r['min_stock_threshold'],
            "price" => (float)$r['price'],
            "cost_price" => (float)$r['cost_price'],
            "status" => $r['status']
        ];
    }

    // 5. Out of Stock Items List (units = 0)
    $outOfStockStmt = $pdo->prepare("
        SELECT id, name, sku, category, units, min_stock_threshold, price, cost_price, status
        FROM products
        WHERE shop_owner_id = ? AND units = 0 AND {$activeProductCondition}
        ORDER BY name ASC
        LIMIT 10
    ");
    $outOfStockStmt->execute([$ownerId]);
    $outOfStockItems = [];
    while ($r = $outOfStockStmt->fetch(PDO::FETCH_ASSOC)) {
        $outOfStockItems[] = [
            "id" => (int)$r['id'],
            "name" => $r['name'],
            "sku" => $r['sku'],
            "category" => !empty(trim($r['category'])) ? $r['category'] : "Uncategorised",
            "units" => 0,
            "min_stock_threshold" => (int)$r['min_stock_threshold'],
            "price" => (float)$r['price'],
            "cost_price" => (float)$r['cost_price'],
            "status" => $r['status']
        ];
    }

    // 6. Real Slow-Moving Calculation using 30-day Sales (Rule 7)
    // Check if store has at least 30 days of sales history
    $firstSaleStmt = $pdo->prepare("SELECT MIN(sale_date) FROM sales WHERE shop_owner_id = ?");
    $firstSaleStmt->execute([$ownerId]);
    $firstSaleDate = $firstSaleStmt->fetchColumn();

    $hasEnoughSalesHistory = false;
    $historyDays = 0;
    if ($firstSaleDate) {
        $historyDays = (time() - strtotime($firstSaleDate)) / 86400.0;
        $hasEnoughSalesHistory = ($historyDays >= $slowMovingMinDaysHistory);
    }

    $slowMovingItems = [];
    $slowMovingStatusMessage = "";

    if (!$hasEnoughSalesHistory) {
        $slowMovingStatusMessage = "Not enough sales history (minimum {$slowMovingMinDaysHistory} days required to accurately evaluate slow-moving inventory).";
    } else {
        // Query products with units > 0 and calculate their units sold in the last 30 days
        $thirtyDaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
        $nowDatetime = date('Y-m-d H:i:s');

        $slowStmt = $pdo->prepare("
            SELECT 
                p.id, p.name, p.sku, p.category, p.units, p.min_stock_threshold, p.price, p.cost_price, p.status,
                COALESCE(SUM(si.quantity), 0) AS units_sold_30_days,
                COALESCE(SUM(si.line_total), 0.00) AS revenue_30_days
            FROM products p
            LEFT JOIN sales_items si ON p.id = si.product_id
            LEFT JOIN sales s ON si.sale_id = s.id AND s.shop_owner_id = ? AND s.sale_date >= ? AND s.sale_date <= ?
            WHERE p.shop_owner_id = ? AND p.units > 0 AND {$activeProductCondition}
            GROUP BY p.id
            ORDER BY p.units DESC, units_sold_30_days ASC
        ");
        $slowStmt->execute([$ownerId, $thirtyDaysAgo, $nowDatetime, $ownerId]);

        while ($r = $slowStmt->fetch(PDO::FETCH_ASSOC)) {
            $units = (int)$r['units'];
            $sold30 = (int)$r['units_sold_30_days'];
            $costPrice = (float)$r['cost_price'];
            $price = (float)$r['price'];

            // Calculate stock cover at current pace
            // stock_cover_days = null when sold is 0 (Rule 7)
            $stockCoverDays = ($sold30 > 0) ? (int)round($units / ($sold30 / 30.0)) : null;

            // Check slow-moving criteria: sold <= 10 or cover >= threshold
            $isSlow = ($sold30 <= $slowMovingMaxSold30Days) || ($stockCoverDays !== null && $stockCoverDays >= $slowMovingMinStockCoverDays);

            if ($isSlow) {
                $cashTiedUp = round($units * ($costPrice > 0 ? $costPrice : $price), 2);
                $slowMovingItems[] = [
                    "id" => (int)$r['id'],
                    "name" => $r['name'],
                    "sku" => $r['sku'],
                    "category" => !empty(trim($r['category'])) ? $r['category'] : "Uncategorised",
                    "current_stock" => $units,
                    "units" => $units,
                    "sold_last_30_days" => $sold30,
                    "revenue_last_30_days" => round((float)$r['revenue_30_days'], 2),
                    "cash_tied_up_at_cost" => $cashTiedUp,
                    "stock_cover_days" => $stockCoverDays,
                    "price" => $price,
                    "cost_price" => $costPrice,
                    "status" => $r['status']
                ];
            }
        }
    }

    $slowMovingCount = count($slowMovingItems);

    // 7. AI Priorities (Reorder and Clearance based on real data)
    $aiPriorities = [];

    // Reorder recommendation (from highest priority low-stock product)
    if (!empty($lowStockItems)) {
        $topLow = $lowStockItems[0];
        $targetStock = $topLow['min_stock_threshold'] * 2;
        $orderUnits = max(1, $targetStock - $topLow['units']);
        $unitCost = (float)$topLow['cost_price'];
        
        $hasCost = ($unitCost > 0);
        $estCost = $hasCost ? round($orderUnits * $unitCost, 2) : null;

        $subtitle = "Current stock: {$topLow['units']} · Minimum: {$topLow['min_stock_threshold']}";
        if ($hasCost) {
            $subtitle .= " · Est. cost: {$estCost}";
        }
        $subtitle .= " · Rule: order up to 2x minimum";

        $aiPriorities[] = [
            "type" => "reorder",
            "title" => "Reorder {$orderUnits} {$topLow['name']} units",
            "subtitle" => $subtitle,
            "product_id" => $topLow['id'],
            "sku" => $topLow['sku'],
            "units_to_order" => $orderUnits,
            "estimated_cost" => $estCost,
            "rule" => "order up to 2x minimum"
        ];
    }

    // Slow moving clearance priority (from top slow moving product)
    if (!empty($slowMovingItems)) {
        $topSlow = $slowMovingItems[0];
        $sub = "{$topSlow['name']} · {$topSlow['current_stock']} in stock, only {$topSlow['sold_last_30_days']} sold in 30 days";
        if ($topSlow['stock_cover_days'] !== null) {
            $sub .= " · {$topSlow['stock_cover_days']} days cover";
        }

        $aiPriorities[] = [
            "type" => "clearance",
            "title" => "Free up slow-moving stock",
            "subtitle" => $sub,
            "product_id" => $topSlow['id'],
            "sku" => $topSlow['sku'],
            "units" => $topSlow['current_stock'],
            "cash_tied_up" => $topSlow['cash_tied_up_at_cost'],
            "rule" => "clearance promotion"
        ];
    }

    // 8. Category Breakdown
    $catStmt = $pdo->prepare("
        SELECT 
            COALESCE(NULLIF(TRIM(category), ''), 'Uncategorised') AS category_name,
            COUNT(*) AS product_count,
            COALESCE(SUM(units), 0) AS total_units,
            COALESCE(SUM(units * price), 0) AS category_value
        FROM products
        WHERE shop_owner_id = ? AND {$activeProductCondition}
        GROUP BY category_name
        ORDER BY category_value DESC
    ");
    $catStmt->execute([$ownerId]);
    $categories = [];
    while ($c = $catStmt->fetch(PDO::FETCH_ASSOC)) {
        $catVal = round((float)$c['category_value'], 2);
        $share = ($totalStockValue > 0) ? round(($catVal / $totalStockValue) * 100, 1) : 0.0;
        $categories[] = [
            "category" => $c['category_name'],
            "product_count" => (int)$c['product_count'],
            "total_units" => (int)$c['total_units'],
            "category_value" => $catVal,
            "value_percentage" => $share
        ];
    }

    // 9. Last Import Record
    $importStmt = $pdo->prepare("
        SELECT file_name, file_size, total_rows, success_count, failed_count, status, created_at
        FROM import_history
        WHERE shop_owner_id = ?
        ORDER BY created_at DESC, id DESC
        LIMIT 1
    ");
    $importStmt->execute([$ownerId]);
    $lastImportRow = $importStmt->fetch(PDO::FETCH_ASSOC);

    $lastImport = null;
    if ($lastImportRow) {
        $lastImport = [
            "file_name" => $lastImportRow['file_name'],
            "file_size" => (int)$lastImportRow['file_size'],
            "total_rows" => (int)$lastImportRow['total_rows'],
            "success_count" => (int)$lastImportRow['success_count'],
            "failed_count" => (int)$lastImportRow['failed_count'],
            "status" => $lastImportRow['status'],
            "date" => $lastImportRow['created_at']
        ];
    }

    // 10. REAL SALES AGGREGATION FROM sales AND sales_items TABLES
    // Today's Sales:
    $todayStart = $todayDateStr . " 00:00:00";
    $todayEnd = date('Y-m-d', strtotime($todayDateStr . ' +1 day')) . " 00:00:00";

    $todaySalesStmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS today_bills_count,
            COALESCE(SUM(total_amount), 0.00) AS today_sales_amount
        FROM sales
        WHERE shop_owner_id = ? AND sale_date >= ? AND sale_date < ?
    ");
    $todaySalesStmt->execute([$ownerId, $todayStart, $todayEnd]);
    $todaySalesRow = $todaySalesStmt->fetch(PDO::FETCH_ASSOC);

    $todaySalesAmount = round((float)$todaySalesRow['today_sales_amount'], 2);
    $todayBillsCount = (int)$todaySalesRow['today_bills_count'];

    // Weekly Sales Total (past 7 days):
    $sevenDaysAgoStart = date('Y-m-d', strtotime('-7 days')) . " 00:00:00";
    $weeklySalesStmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount), 0.00) AS weekly_sales_total
        FROM sales
        WHERE shop_owner_id = ? AND sale_date >= ? AND sale_date < ?
    ");
    $weeklySalesStmt->execute([$ownerId, $sevenDaysAgoStart, $todayEnd]);
    $weeklySalesTotal = round((float)$weeklySalesStmt->fetchColumn(), 2);

    // Daily Sales Points in the requested date range:
    $dailySalesStmt = $pdo->prepare("
        SELECT 
            DATE(sale_date) AS s_date,
            COUNT(*) AS bills_count,
            COALESCE(SUM(total_amount), 0.00) AS daily_amount
        FROM sales
        WHERE shop_owner_id = ? AND sale_date >= ? AND sale_date < ?
        GROUP BY DATE(sale_date)
        ORDER BY s_date ASC
    ");
    $dailySalesStmt->execute([$ownerId, $startDatetime, $endDatetime]);
    $dailySalesMap = [];
    while ($dr = $dailySalesStmt->fetch(PDO::FETCH_ASSOC)) {
        $dailySalesMap[$dr['s_date']] = [
            "bills_count" => (int)$dr['bills_count'],
            "amount" => round((float)$dr['daily_amount'], 2)
        ];
    }

    $dailySalesPoints = [];
    $curTs = strtotime($fromDateRaw);
    $endRangeTs = strtotime($toDateRaw);
    while ($curTs <= $endRangeTs) {
        $dStr = date('Y-m-d', $curTs);
        $dailySalesPoints[] = [
            "date" => $dStr,
            "day_label" => date('j M', $curTs),
            "amount" => $dailySalesMap[$dStr]['amount'] ?? 0.00,
            "bills_count" => $dailySalesMap[$dStr]['bills_count'] ?? 0,
            "is_current_day" => ($dStr === $todayDateStr)
        ];
        $curTs = strtotime('+1 day', $curTs);
    }

    // Top Selling Products in requested date range:
    $topSellingStmt = $pdo->prepare("
        SELECT 
            p.id AS product_id,
            p.name,
            p.sku,
            COALESCE(p.category, 'Uncategorised') AS category,
            SUM(si.quantity) AS units_sold,
            ROUND(SUM(si.line_total), 2) AS revenue
        FROM sales_items si
        INNER JOIN sales s ON si.sale_id = s.id
        INNER JOIN products p ON si.product_id = p.id
        WHERE s.shop_owner_id = ? AND s.sale_date >= ? AND s.sale_date < ?
        GROUP BY p.id
        ORDER BY units_sold DESC, revenue DESC
        LIMIT 5
    ");
    $topSellingStmt->execute([$ownerId, $startDatetime, $endDatetime]);
    $topSellingProducts = [];
    while ($ts = $topSellingStmt->fetch(PDO::FETCH_ASSOC)) {
        $topSellingProducts[] = [
            "product_id" => (int)$ts['product_id'],
            "name" => $ts['name'],
            "sku" => $ts['sku'],
            "category" => $ts['category'],
            "units_sold" => (int)$ts['units_sold'],
            "revenue" => (float)$ts['revenue']
        ];
    }

    // 11. Assemble Final Response
    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Dashboard summary retrieved successfully",
        "data" => [
            "store" => [
                "name" => $storeInfo['shop_name'] ?? "",
                "location" => $storeInfo['location'] ?? "",
                "currency" => $storeInfo['currency'] ?? "₹ INR / IST"
            ],
            "date_range" => [
                "from" => $fromDateRaw,
                "to" => $toDateRaw
            ],
            "sales" => [
                "today_sales_amount" => $todaySalesAmount,
                "today_bills_count" => $todayBillsCount,
                "weekly_sales_total" => $weeklySalesTotal,
                "daily_sales_points" => $dailySalesPoints
            ],
            "inventory" => [
                "total_active_products" => $totalActiveProducts,
                "total_stock_value" => $totalStockValue,
                "low_stock_count" => $lowStockCount,
                "out_of_stock_count" => $outOfStockCount,
                "slow_moving_count" => $slowMovingCount
            ],
            "top_selling_products" => $topSellingProducts,
            "alerts" => [
                "low_stock_items" => $lowStockItems,
                "out_of_stock_items" => $outOfStockItems,
                "slow_moving_items" => $slowMovingItems,
                "slow_moving_metadata" => [
                    "has_enough_sales_history" => $hasEnoughSalesHistory,
                    "history_days_available" => round($historyDays, 1),
                    "rule" => $slowMovingRuleDesc,
                    "message" => $slowMovingStatusMessage ?: "Calculated from real sales velocity over past 30 days."
                ]
            ],
            "ai_priorities" => $aiPriorities,
            "category_breakdown" => $categories,
            "last_import" => $lastImport
        ]
    ]);

} catch (Throwable $e) {
    error_log("Dashboard summary query error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "An internal server error occurred while generating dashboard summary.",
        "data" => (object)[]
    ]);
}
