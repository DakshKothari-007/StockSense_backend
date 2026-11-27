<?php

const SLOW_MOVING_ACCEPTED_HIDE_DAYS = 14;

/**
 * Fetches latest recommendation_responses for slow_moving items of a shop.
 */
function get_slow_moving_responses_map(PDO $pdo, int $shopOwnerId): array {
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
    return $responsesMap;
}

/**
 * Determines whether a slow-moving item should be hidden based on its latest response.
 */
function should_hide_slow_moving_response(
    ?array $resp,
    bool $includeDismissed = false,
    bool $includeAccepted = false,
    ?int $acceptedHideCutoffTs = null
): bool {
    if ($resp === null) {
        return false;
    }
    $action = $resp["action"] ?? null;
    $respondedAt = $resp["updated_at"] ?? null;

    if ($action === "dismissed" && !$includeDismissed) {
        return true;
    }
    if ($action === "accepted" && !$includeAccepted && $respondedAt !== null) {
        $cutoff = $acceptedHideCutoffTs ?? strtotime("-" . SLOW_MOVING_ACCEPTED_HIDE_DAYS . " days");
        if (strtotime($respondedAt) >= $cutoff) {
            return true;
        }
    }
    return false;
}

/**
 * Evaluates slow-moving products for a shop using the shared Insights rule and hiding logic.
 *
 * @return array{items: array<int, array<string, mixed>>, responses_map: array<int, array<string, mixed>>}
 */
function evaluate_slow_moving_products(
    PDO $pdo,
    int $shopOwnerId,
    array $shopSettings,
    bool $includeDismissed = false,
    bool $includeAccepted = false
): array {
    $responsesMap = get_slow_moving_responses_map($pdo, $shopOwnerId);
    $acceptedHideCutoffTs = strtotime("-" . SLOW_MOVING_ACCEPTED_HIDE_DAYS . " days");

    $activeProductCondition = "(p.status IS NULL OR (p.status NOT LIKE '%Archived%' AND p.status NOT LIKE '%Inactive%'))";
    $thirtyDaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
    $nowDatetime = date('Y-m-d H:i:s');

    $prodStmt = $pdo->prepare("
        SELECT
            p.id, p.name, p.sku, p.category, p.units, p.min_stock_threshold, p.price, p.cost_price, p.status,
            COALESCE(SUM(si.quantity), 0) AS sold_30_days,
            COALESCE(SUM(si.line_total), 0.00) AS revenue_30_days
        FROM products p
        LEFT JOIN sales_items si ON p.id = si.product_id
        LEFT JOIN sales s ON si.sale_id = s.id AND s.shop_owner_id = ? AND s.sale_date >= ? AND s.sale_date <= ?
        WHERE p.shop_owner_id = ? AND {$activeProductCondition}
        GROUP BY p.id
        ORDER BY p.name ASC
    ");
    $prodStmt->execute([$shopOwnerId, $thirtyDaysAgo, $nowDatetime, $shopOwnerId]);

    $reorderThresholdDays = (int)$shopSettings["lead_time_days"]
        + (int)$shopSettings["safety_days"]
        + (int)$shopSettings["cover_days"];
    $slowMovingThresholdDays = (int)$shopSettings["slow_moving_threshold_days"];

    $items = [];
    while ($p = $prodStmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$p["id"];
        $currentStock = (int)$p["units"];
        $minThreshold = (int)$p["min_stock_threshold"];
        $sold30Days = (int)$p["sold_30_days"];
        $sellingPrice = (float)$p["price"];
        $costPrice = ($p["cost_price"] !== null && (float)$p["cost_price"] > 0) ? (float)$p["cost_price"] : null;

        if ($currentStock <= 0) {
            continue;
        }

        $dailySalesRate = round($sold30Days / 30.0, 2);
        $stockCoverDays = ($dailySalesRate > 0) ? round($currentStock / $dailySalesRate, 1) : null;

        $isOnReorderList = ($minThreshold > 0 && $currentStock <= $minThreshold)
            || ($stockCoverDays !== null && $stockCoverDays < $reorderThresholdDays);
        if ($isOnReorderList) {
            continue;
        }

        $isSlowMoving = false;
        $ruleTriggered = null;
        if ($sold30Days === 0) {
            $isSlowMoving = true;
            $ruleTriggered = "units_sold_30_days = 0";
        } elseif ($stockCoverDays !== null && $stockCoverDays >= $slowMovingThresholdDays) {
            $isSlowMoving = true;
            $ruleTriggered = "stock_cover_days >= " . $slowMovingThresholdDays;
        }

        if (!$isSlowMoving) {
            continue;
        }

        $resp = $responsesMap[$pid] ?? null;
        if (should_hide_slow_moving_response($resp, $includeDismissed, $includeAccepted, $acceptedHideCutoffTs)) {
            continue;
        }

        $items[] = [
            "product_id" => $pid,
            "name" => $p["name"],
            "sku" => $p["sku"],
            "category" => $p["category"],
            "status" => $p["status"],
            "units" => $currentStock,
            "sold_30_days" => $sold30Days,
            "revenue_30_days" => round((float)$p["revenue_30_days"], 2),
            "daily_sales_rate" => $dailySalesRate,
            "stock_cover_days" => $stockCoverDays,
            "price" => $sellingPrice,
            "cost_price" => $costPrice,
            "rule_triggered" => $ruleTriggered,
            "response" => $resp
        ];
    }

    return [
        "items" => $items,
        "responses_map" => $responsesMap
    ];
}
