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

$pdo->exec("SET time_zone = '+05:30'");
$owner = authenticateShopOwner($pdo);
$shopOwnerId = (int)$owner["id"];

// Named Configuration Constants
const REORDER_CONFIG = [
    "lead_time_days" => 2,
    "safety_days" => 3,
    "cover_days" => 7,
    "min_history_days" => 30
];

// Query Parameters
$filterPriority = isset($_GET["priority"]) ? strtolower(trim($_GET["priority"])) : "";
$filterCategory = isset($_GET["category"]) ? trim($_GET["category"]) : "";
$page = max(1, (int)($_GET["page"] ?? 1));
$limit = max(1, min(100, (int)($_GET["limit"] ?? 20)));

try {
    // 1. Check Sales History Duration
    $historyStmt = $pdo->prepare("SELECT MIN(sale_date) FROM sales WHERE shop_owner_id = ?");
    $historyStmt->execute([$shopOwnerId]);
    $firstSaleDate = $historyStmt->fetchColumn();

    $historyDays = 0;
    $historyWarning = true;
    if ($firstSaleDate) {
        $historyDays = max(0, (time() - strtotime($firstSaleDate)) / 86400.0);
        $historyWarning = ($historyDays < REORDER_CONFIG["min_history_days"]);
    }

    $divisor = ($historyDays >= 7) ? min(30.0, $historyDays) : 0;

    // 2. Fetch Open On-Order Quantities in ONE single query (Rule 3)
    $openOrdersStmt = $pdo->prepare("
        SELECT poi.product_id, COALESCE(SUM(poi.quantity), 0) AS on_order_qty
        FROM purchase_order_items poi
        INNER JOIN purchase_orders po ON poi.purchase_order_id = po.id
        WHERE po.shop_owner_id = ? AND po.status = 'placed'
        GROUP BY poi.product_id
    ");
    $openOrdersStmt->execute([$shopOwnerId]);
    $openOrdersMap = [];
    while ($row = $openOrdersStmt->fetch(PDO::FETCH_ASSOC)) {
        $openOrdersMap[(int)$row["product_id"]] = (int)$row["on_order_qty"];
    }

    // 3. Fetch Active Products and 30-Day Sales in ONE single aggregation query (Rule 10: exclude archived)
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

    $allReorderItems = [];
    $highCount = 0;
    $mediumCount = 0;
    $lowCount = 0;
    $totalEstimatedCost = 0.00;

    $lead = REORDER_CONFIG["lead_time_days"];
    $safety = REORDER_CONFIG["safety_days"];
    $cover = REORDER_CONFIG["cover_days"];
    $leadSafety = $lead + $safety;
    $leadSafetyCover = $lead + $safety + $cover; // 12 days

    while ($p = $prodStmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$p["id"];
        $currentStock = (int)$p["units"];
        $minThreshold = (int)$p["min_stock_threshold"];
        $sold30Days = (int)$p["sold_30_days"];
        $costPrice = ($p["cost_price"] !== null && (float)$p["cost_price"] > 0) ? (float)$p["cost_price"] : null;
        $onOrderUnits = $openOrdersMap[$pid] ?? 0;

        // Demand Rate & Days Left Calculation
        $dailySalesRate = 0.0;
        $daysOfStockLeft = null;
        $isUsingFallback = ($divisor <= 0);

        if (!$isUsingFallback) {
            $dailySalesRate = round($sold30Days / $divisor, 2);
            if ($dailySalesRate > 0) {
                $daysOfStockLeft = round($currentStock / $dailySalesRate, 1);
            }
        }

        // Determine Priority & Fire Matching Rule (Rule 1 & 2)
        $priority = null;
        $priorityRule = null;
        $urgencyWeight = 0;

        if ($currentStock <= 0) {
            $priority = "high";
            $priorityRule = "Out of stock (units = 0)";
            $urgencyWeight = 1000 - $currentStock;
        } elseif ($daysOfStockLeft !== null && $daysOfStockLeft <= $lead) {
            $priority = "high";
            $priorityRule = "Stockout imminent within lead time (days_of_stock_left <= {$lead})";
            $urgencyWeight = 900 - (int)($daysOfStockLeft * 10);
        } elseif ($currentStock <= $minThreshold) {
            $priority = "medium";
            $priorityRule = "Stock at or below minimum threshold (units <= min_stock_threshold)";
            $urgencyWeight = 500 - (int)(($currentStock / max(1, $minThreshold)) * 100);
        } elseif ($daysOfStockLeft !== null && $daysOfStockLeft < $leadSafety) {
            $priority = "medium";
            $priorityRule = "Stock cover below lead time + safety buffer (days_of_stock_left < {$leadSafety})";
            $urgencyWeight = 400 - (int)($daysOfStockLeft * 10);
        } elseif ($daysOfStockLeft !== null && $daysOfStockLeft < $leadSafetyCover) {
            $priority = "low";
            $priorityRule = "Stock cover below target replenishment cycle (days_of_stock_left < {$leadSafetyCover})";
            $urgencyWeight = 200 - (int)($daysOfStockLeft * 10);
        } else {
            // Product does not need reorder -> Exclude (Rule 2)
            continue;
        }

        // Suggested Quantity & Reason (Rule: 2x-min fallback ONLY when shop lacks enough history)
        $grossSuggested = null;
        $suggestedQuantity = null;
        $estimatedCost = null;
        $reason = "";

        if ($historyWarning) {
            // Whole shop lacks enough history (< 30 days) -> use 2x-minimum fallback
            $targetFallback = $minThreshold * 2;
            $grossSuggested = max(0, $targetFallback - $currentStock);
            $suggestedQuantity = max(0, $grossSuggested - $onOrderUnits);
            $estimatedCost = ($costPrice !== null) ? round($suggestedQuantity * $costPrice, 2) : null;
            $reason = "Insufficient sales history (" . round($historyDays, 1) . " days available). Reorder suggested using 2x-minimum threshold fallback (target {$targetFallback} units).";
        } elseif ($sold30Days === 0 || $dailySalesRate <= 0) {
            // Shop HAS enough history (>= 30 days) and product sold 0 in last 30 days -> do NOT suggest quantity
            $grossSuggested = null;
            $suggestedQuantity = null;
            $estimatedCost = null;
            $reason = "No sales in 30 days. Review before reordering.";
        } else {
            // Normal dynamic velocity replenishment
            $targetCoverageUnits = (int)ceil($dailySalesRate * $leadSafetyCover);
            $grossSuggested = max(0, $targetCoverageUnits - $currentStock);
            $suggestedQuantity = max(0, $grossSuggested - $onOrderUnits);
            $estimatedCost = ($costPrice !== null) ? round($suggestedQuantity * $costPrice, 2) : null;
            $reason = "Stock will run out in {$daysOfStockLeft} days at current demand rate of {$dailySalesRate} units/day (target {$leadSafetyCover} days cover).";
        }

        // Stock Cover After Reorder
        $stockCoverAfterDays = null;
        if ($dailySalesRate > 0 && $suggestedQuantity !== null) {
            $totalProjectedStock = $currentStock + $suggestedQuantity + $onOrderUnits;
            $stockCoverAfterDays = round($totalProjectedStock / $dailySalesRate, 1);
        }

        // Update Global Totals across ALL pages (Rule 10)
        if ($priority === "high") {
            $highCount++;
        } elseif ($priority === "medium") {
            $mediumCount++;
        } elseif ($priority === "low") {
            $lowCount++;
        }

        if ($estimatedCost !== null) {
            $totalEstimatedCost += $estimatedCost;
        }

        $allReorderItems[] = [
            "urgency_weight" => $urgencyWeight,
            "product" => [
                "id" => $pid,
                "name" => $p["name"],
                "sku" => $p["sku"],
                "category" => !empty(trim($p["category"] ?? "")) ? $p["category"] : "Uncategorised",
                "price" => (float)$p["price"],
                "cost_price" => $costPrice
            ],
            "current_stock" => $currentStock,
            "min_threshold" => $minThreshold,
            "sold_last_30_days" => $sold30Days,
            "daily_sales_rate" => $dailySalesRate,
            "days_of_stock_left" => $daysOfStockLeft,
            "on_order_units" => $onOrderUnits,
            "gross_suggested_quantity" => $grossSuggested,
            "suggested_quantity" => $suggestedQuantity,
            "estimated_cost" => $estimatedCost,
            "reason" => $reason,
            "priority" => $priority,
            "priority_rule" => $priorityRule,
            "stock_cover_after_reorder_days" => $stockCoverAfterDays
        ];
    }

    // Sort by Urgency Weight descending (highest priority & lowest days of stock left first)
    usort($allReorderItems, function($a, $b) {
        return $b["urgency_weight"] <=> $a["urgency_weight"];
    });

    // 4. Apply Filters (priority, category)
    $filteredItems = array_values(array_filter($allReorderItems, function($item) use ($filterPriority, $filterCategory) {
        if ($filterPriority !== "" && $item["priority"] !== $filterPriority) {
            return false;
        }
        if ($filterCategory !== "" && strcasecmp($item["product"]["category"], $filterCategory) !== 0) {
            return false;
        }
        return true;
    }));

    // 5. Pagination
    $totalMatching = count($filteredItems);
    $totalPages = $totalMatching > 0 ? (int)ceil($totalMatching / $limit) : 1;
    $offset = ($page - 1) * $limit;
    $pagedItems = array_slice($filteredItems, $offset, $limit);

    // Clean up internal sort key
    foreach ($pagedItems as &$it) {
        unset($it["urgency_weight"]);
    }
    unset($it);

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Reorder recommendations retrieved successfully.",
        "data" => [
            "reorder_config" => REORDER_CONFIG,
            "history_warning" => $historyWarning,
            "history_days_available" => round($historyDays, 1),
            "summary" => [
                "total_items_needing_reorder" => count($allReorderItems),
                "high_priority_count" => $highCount,
                "medium_priority_count" => $mediumCount,
                "low_priority_count" => $lowCount,
                "total_estimated_cost" => round($totalEstimatedCost, 2)
            ],
            "pagination" => [
                "page" => $page,
                "limit" => $limit,
                "total_matching" => $totalMatching,
                "total_pages" => $totalPages
            ],
            "items" => $pagedItems
        ]
    ]);

} catch (Throwable $e) {
    error_log("Reorder list error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Internal error compiling reorder recommendations: " . $e->getMessage(),
        "data" => (object)[]
    ]);
}
