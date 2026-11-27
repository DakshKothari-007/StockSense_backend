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
require_once __DIR__ . "/slow_moving_helper.php";

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
$includeAccepted = isset($_GET["include_accepted"]) && ($_GET["include_accepted"] === "1" || strtolower($_GET["include_accepted"]) === "true");

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

    // 2. Evaluate slow-moving products using shared helper (same rule & hiding logic as Dashboard)
    $eval = evaluate_slow_moving_products($pdo, $shopOwnerId, $shopSettings, $includeDismissed, $includeAccepted);

    $items = [];
    $totalTiedUpCapital = 0.00;

    foreach ($eval["items"] as $row) {
        $pid = $row["product_id"];
        $currentStock = $row["units"];
        $sold30Days = $row["sold_30_days"];
        $dailySalesRate = $row["daily_sales_rate"];
        $stockCoverDays = $row["stock_cover_days"];
        $sellingPrice = $row["price"];
        $costPrice = $row["cost_price"];
        $ruleTriggered = $row["rule_triggered"];
        $resp = $row["response"];
        $isResponded = ($resp !== null);
        $action = $resp ? $resp["action"] : null;
        $respondedAt = $resp ? $resp["updated_at"] : null;
        $p = $row;

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
