<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode([
        "status" => false,
        "message" => "Method Not Allowed. Only GET requests are allowed.",
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
$shopOwnerId = (int)$owner["id"];

// Load Shop Settings (Insights Integration)
$shopSettings = get_shop_settings($pdo, $shopOwnerId);
$insightsConfig = [
    "slow_moving_stock_cover_threshold" => $shopSettings["slow_moving_threshold_days"],
    "single_discount_pct" => 10,
    "bundle_discount_pct" => 15,
    "min_sales_history_days" => $shopSettings["min_history_days"],
    "reorder_lead_time_days" => $shopSettings["lead_time_days"],
    "reorder_safety_days" => $shopSettings["safety_days"],
    "reorder_cover_days" => $shopSettings["cover_days"]
];

$includeDismissed = isset($_GET["include_dismissed"]) && ($_GET["include_dismissed"] === "1" || strtolower($_GET["include_dismissed"]) === "true");

try {
    // 1. Check Shop Sales History
    $histStmt = $pdo->prepare("
        SELECT MIN(sale_date) AS earliest_sale, MAX(sale_date) AS latest_sale
        FROM sales
        WHERE shop_owner_id = ?
    ");
    $histStmt->execute([$shopOwnerId]);
    $hist = $histStmt->fetch(PDO::FETCH_ASSOC);

    $hasEnoughHistory = false;
    $salesHistoryDays = 0;

    if ($hist && $hist["earliest_sale"] !== null) {
        $earliestTime = strtotime($hist["earliest_sale"]);
        $diffSeconds = time() - $earliestTime;
        $salesHistoryDays = max(1, (int)floor($diffSeconds / 86400));
        $hasEnoughHistory = ($salesHistoryDays >= $insightsConfig["min_sales_history_days"]);
    }

    if (!$hasEnoughHistory) {
        http_response_code(200);
        echo json_encode([
            "status" => true,
            "message" => "Need at least 30 days of sales history to calculate slow-moving recommendations accurately.",
            "data" => [
                "has_enough_sales_history" => false,
                "sales_history_days" => $salesHistoryDays,
                "total_slow_moving_count" => 0,
                "total_tied_up_capital" => 0.00,
                "config" => [
                    "stock_cover_days_threshold" => $insightsConfig["slow_moving_stock_cover_threshold"],
                    "single_discount_pct" => $insightsConfig["single_discount_pct"],
                    "bundle_discount_pct" => $insightsConfig["bundle_discount_pct"]
                ],
                "items" => []
            ]
        ]);
        exit;
    }

    // 2. Fetch Existing Responses
    $respStmt = $pdo->prepare("
        SELECT product_id_1, action, updated_at
        FROM recommendation_responses
        WHERE shop_owner_id = ? AND recommendation_type = 'slow_moving' AND product_id_2 = 0
    ");
    $respStmt->execute([$shopOwnerId]);
    $responsesMap = [];
    while ($r = $respStmt->fetch(PDO::FETCH_ASSOC)) {
        $responsesMap[(int)$r["product_id_1"]] = [
            "action" => $r["action"],
            "updated_at" => $r["updated_at"]
        ];
    }

    // 3. Query Active Products with 30-Day Sales
    $activeProductCondition = "(p.status IS NULL OR (p.status NOT LIKE '%Archived%' AND p.status NOT LIKE '%Inactive%'))";
    $thirtyDaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
    $nowDatetime = date('Y-m-d H:i:s');

    $prodStmt = $pdo->prepare("
        SELECT 
            p.id, p.name, p.sku, p.category, p.units, p.min_stock_threshold, p.price, p.cost_price, p.status,
            COALESCE(SUM(si.quantity), 0) AS sold_30_days
        FROM products p
        LEFT JOIN sales_items si ON p.id = si.product_id
        LEFT JOIN sales s ON si.sale_id = s.id AND s.shop_owner_id = ? AND s.sale_date >= ? AND s.sale_date <= ?
        WHERE p.shop_owner_id = ? AND {$activeProductCondition}
        GROUP BY p.id
        ORDER BY p.name ASC
    ");
    $prodStmt->execute([$shopOwnerId, $thirtyDaysAgo, $nowDatetime, $shopOwnerId]);

    $reorderThresholdDays = $insightsConfig["reorder_lead_time_days"] 
        + $insightsConfig["reorder_safety_days"] 
        + $insightsConfig["reorder_cover_days"]; // 12 days

    $items = [];
    $totalTiedUpCapital = 0.00;

    while ($p = $prodStmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$p["id"];
        $currentStock = (int)$p["units"];
        $minThreshold = (int)$p["min_stock_threshold"];
        $sold30Days = (int)$p["sold_30_days"];
        $sellingPrice = (float)$p["price"];
        $costPrice = ($p["cost_price"] !== null && (float)$p["cost_price"] > 0) ? (float)$p["cost_price"] : null;

        // Rule 3: Must have units > 0
        if ($currentStock <= 0) {
            continue;
        }

        // Compute daily sales rate & stock cover days
        $dailySalesRate = round($sold30Days / 30.0, 2);
        $stockCoverDays = null;
        if ($dailySalesRate > 0) {
            $stockCoverDays = round($currentStock / $dailySalesRate, 1);
        }

        // Rule 3: Exclude products currently on the reorder list
        // Reorder list criteria: currentStock <= minThreshold OR (stockCoverDays !== null && stockCoverDays < 12)
        $isOnReorderList = ($minThreshold > 0 && $currentStock <= $minThreshold)
            || ($stockCoverDays !== null && $stockCoverDays < $reorderThresholdDays);

        if ($isOnReorderList) {
            continue;
        }

        // Rule 3: Slow-moving condition: units > 0 AND (stock_cover_days >= 60 OR units_sold_30_days = 0)
        $isSlowMoving = false;
        $ruleTriggered = null;

        if ($sold30Days === 0) {
            $isSlowMoving = true;
            $ruleTriggered = "units_sold_30_days = 0";
        } elseif ($stockCoverDays !== null && $stockCoverDays >= $insightsConfig["slow_moving_stock_cover_threshold"]) {
            $isSlowMoving = true;
            $ruleTriggered = "stock_cover_days >= " . $insightsConfig["slow_moving_stock_cover_threshold"];
        }

        if (!$isSlowMoving) {
            continue;
        }

        // Check recommendation response status
        $resp = $responsesMap[$pid] ?? null;
        $isResponded = ($resp !== null);
        $action = $resp ? $resp["action"] : null;
        $respondedAt = $resp ? $resp["updated_at"] : null;

        // Exclude dismissed unless requested
        if ($action === "dismissed" && !$includeDismissed) {
            continue;
        }

        // Tied-up capital calculation
        $tiedCapital = ($costPrice !== null) ? round($currentStock * $costPrice, 2) : round($currentStock * $sellingPrice, 2);
        $totalTiedUpCapital += $tiedCapital;

        // Rule 4: Clearance suggestions with discount percent and cost price verification
        $singleDiscPct = $insightsConfig["single_discount_pct"];
        $singlePrice = round($sellingPrice * (1.0 - ($singleDiscPct / 100.0)), 2);

        $bundleDiscPct = $insightsConfig["bundle_discount_pct"];
        $bundlePrice = round($sellingPrice * (1.0 - ($bundleDiscPct / 100.0)), 2);

        // Cost verification helper
        $checkMargin = function(float $discountedPrice, ?float $cost) {
            if ($cost === null) {
                return [
                    "margin_status" => "cost unknown",
                    "warning" => "cost unknown"
                ];
            }
            if ($discountedPrice < $cost) {
                return [
                    "margin_status" => "below cost",
                    "warning" => "below cost"
                ];
            }
            return [
                "margin_status" => "above cost",
                "warning" => null
            ];
        };

        $singleMargin = $checkMargin($singlePrice, $costPrice);
        $bundleMargin = $checkMargin($bundlePrice, $costPrice);

        $items[] = [
            "product_id" => $pid,
            "name" => $p["name"],
            "sku" => $p["sku"],
            "category" => !empty($p["category"]) ? $p["category"] : "Uncategorised",
            "current_stock" => $currentStock,
            "units_sold_30_days" => $sold30Days,
            "daily_sales_rate" => $dailySalesRate,
            "stock_cover_days" => $stockCoverDays,
            "selling_price" => $sellingPrice,
            "cost_price" => $costPrice,
            "tied_up_capital" => $tiedCapital,
            "status" => "slow_moving",
            "rule_triggered" => $ruleTriggered,
            "clearance_suggestion" => [
                "action_type" => "discount",
                "discount_pct" => $singleDiscPct,
                "suggested_price" => $singlePrice,
                "margin_status" => $singleMargin["margin_status"],
                "warning" => $singleMargin["warning"],
                "bundle_suggestion" => [
                    "action_type" => "bundle_discount",
                    "discount_pct" => $bundleDiscPct,
                    "suggested_price" => $bundlePrice,
                    "margin_status" => $bundleMargin["margin_status"],
                    "warning" => $bundleMargin["warning"]
                ]
            ],
            "response" => [
                "is_responded" => $isResponded,
                "action" => $action,
                "responded_at" => $respondedAt
            ]
        ];
    }

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Slow-moving recommendations fetched successfully.",
        "data" => [
            "has_enough_sales_history" => true,
            "sales_history_days" => $salesHistoryDays,
            "total_slow_moving_count" => count($items),
            "total_tied_up_capital" => round($totalTiedUpCapital, 2),
            "config" => [
                "stock_cover_days_threshold" => $insightsConfig["slow_moving_stock_cover_threshold"],
                "single_discount_pct" => $insightsConfig["single_discount_pct"],
                "bundle_discount_pct" => $insightsConfig["bundle_discount_pct"]
            ],
            "items" => $items
        ]
    ]);

} catch (Exception $e) {
    error_log("Insights slow_moving error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "An internal server error occurred while fetching slow-moving recommendations.",
        "data" => (object)[]
    ]);
}
