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

// Named Configuration
const PAIRS_CONFIG = [
    "window_days" => 90,
    "min_total_bills" => 30,
    "min_bills_together" => 3,
    "default_min_support" => 0.05
];

// Validate min_support parameter (Rule 6: between 0 and 1)
$minSupport = PAIRS_CONFIG["default_min_support"];
if (isset($_GET["min_support"])) {
    if (!is_numeric($_GET["min_support"])) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Invalid min_support parameter. Must be a numeric value between 0 and 1.",
            "data" => (object)[]
        ]);
        exit;
    }
    $minSupport = (float)$_GET["min_support"];
    if ($minSupport < 0.0 || $minSupport > 1.0) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "min_support must be between 0 and 1.",
            "data" => (object)[]
        ]);
        exit;
    }
}

$includeDismissed = isset($_GET["include_dismissed"]) && ($_GET["include_dismissed"] === "1" || strtolower($_GET["include_dismissed"]) === "true");

try {
    $windowDays = PAIRS_CONFIG["window_days"];
    $startDate = date('Y-m-d H:i:s', strtotime("-{$windowDays} days"));
    $endDate = date('Y-m-d H:i:s');

    // 1. Total Bills in Window
    $billsStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT s.id) AS total_bills
        FROM sales s
        WHERE s.shop_owner_id = ? AND s.sale_date >= ? AND s.sale_date <= ?
    ");
    $billsStmt->execute([$shopOwnerId, $startDate, $endDate]);
    $totalBills = (int)$billsStmt->fetchColumn();

    if ($totalBills < PAIRS_CONFIG["min_total_bills"]) {
        http_response_code(200);
        echo json_encode([
            "status" => true,
            "message" => "Store needs at least 30 bills in the last 90 days to identify reliable product pairs.",
            "data" => [
                "has_enough_sales_history" => false,
                "total_bills_analyzed" => $totalBills,
                "config" => [
                    "window_days" => $windowDays,
                    "min_total_bills" => PAIRS_CONFIG["min_total_bills"],
                    "min_bills_together" => PAIRS_CONFIG["min_bills_together"],
                    "min_support" => $minSupport
                ],
                "items" => []
            ]
        ]);
        exit;
    }

    // 2. Grouped Query: Bills per Individual Product (Rule 6)
    $singleStmt = $pdo->prepare("
        SELECT si.product_id, COUNT(DISTINCT si.sale_id) AS bill_count
        FROM sales_items si
        INNER JOIN sales s ON si.sale_id = s.id
        WHERE s.shop_owner_id = ? AND s.sale_date >= ? AND s.sale_date <= ?
        GROUP BY si.product_id
    ");
    $singleStmt->execute([$shopOwnerId, $startDate, $endDate]);
    $productBillCounts = [];
    while ($row = $singleStmt->fetch(PDO::FETCH_ASSOC)) {
        $productBillCounts[(int)$row["product_id"]] = (int)$row["bill_count"];
    }

    // 3. Pairwise Co-Occurrence Query (smaller ID first: si1.product_id < si2.product_id)
    $pairStmt = $pdo->prepare("
        SELECT 
            si1.product_id AS product_id_1,
            si2.product_id AS product_id_2,
            COUNT(DISTINCT si1.sale_id) AS bills_together
        FROM sales_items si1
        INNER JOIN sales_items si2 ON si1.sale_id = si2.sale_id AND si1.product_id < si2.product_id
        INNER JOIN sales s ON si1.sale_id = s.id
        WHERE s.shop_owner_id = ? AND s.sale_date >= ? AND s.sale_date <= ?
        GROUP BY product_id_1, product_id_2
        HAVING bills_together >= ?
        ORDER BY bills_together DESC
    ");
    $pairStmt->execute([$shopOwnerId, $startDate, $endDate, PAIRS_CONFIG["min_bills_together"]]);
    $rawPairs = $pairStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rawPairs)) {
        http_response_code(200);
        echo json_encode([
            "status" => true,
            "message" => "No product pairs met the minimum co-occurrence threshold.",
            "data" => [
                "has_enough_sales_history" => true,
                "total_bills_analyzed" => $totalBills,
                "config" => [
                    "window_days" => $windowDays,
                    "min_total_bills" => PAIRS_CONFIG["min_total_bills"],
                    "min_bills_together" => PAIRS_CONFIG["min_bills_together"],
                    "min_support" => $minSupport
                ],
                "items" => []
            ]
        ]);
        exit;
    }

    // Collect all involved product IDs
    $productIds = [];
    foreach ($rawPairs as $rp) {
        $productIds[(int)$rp["product_id_1"]] = true;
        $productIds[(int)$rp["product_id_2"]] = true;
    }
    $productIdsList = array_keys($productIds);

    // Fetch product details
    $placeholders = implode(",", array_fill(0, count($productIdsList), "?"));
    $prodDetailStmt = $pdo->prepare("
        SELECT id, name, sku, category, price, cost_price, units, status
        FROM products
        WHERE shop_owner_id = ? AND id IN ({$placeholders})
    ");
    $prodDetailStmt->execute(array_merge([$shopOwnerId], $productIdsList));
    $productMap = [];
    while ($p = $prodDetailStmt->fetch(PDO::FETCH_ASSOC)) {
        $productMap[(int)$p["id"]] = $p;
    }

    // 4. Fetch Existing Responses for Pairs
    $respStmt = $pdo->prepare("
        SELECT product_id_1, product_id_2, action, updated_at
        FROM recommendation_responses
        WHERE shop_owner_id = ? AND recommendation_type = 'product_pair'
    ");
    $respStmt->execute([$shopOwnerId]);
    $responsesMap = [];
    while ($r = $respStmt->fetch(PDO::FETCH_ASSOC)) {
        $key = (int)$r["product_id_1"] . "_" . (int)$r["product_id_2"];
        $responsesMap[$key] = [
            "action" => $r["action"],
            "updated_at" => $r["updated_at"]
        ];
    }

    // 5. Build Result Items
    $items = [];
    foreach ($rawPairs as $rp) {
        $p1 = (int)$rp["product_id_1"];
        $p2 = (int)$rp["product_id_2"];

        if (!isset($productMap[$p1]) || !isset($productMap[$p2])) {
            continue;
        }

        $prod1 = $productMap[$p1];
        $prod2 = $productMap[$p2];

        $billsTogether = (int)$rp["bills_together"];
        $billsA = $productBillCounts[$p1] ?? 0;
        $billsB = $productBillCounts[$p2] ?? 0;

        if ($billsA <= 0 || $billsB <= 0) {
            continue;
        }

        $support = round($billsTogether / $totalBills, 4);
        if ($support < $minSupport) {
            continue;
        }

        $confidenceAtoB = round($billsTogether / $billsA, 4);
        $confidenceBtoA = round($billsTogether / $billsB, 4);

        // Lift = (bills_together / total_bills) / ((bills_a / total_bills) * (bills_b / total_bills))
        //      = (bills_together * total_bills) / (bills_a * bills_b)
        $lift = round(($billsTogether * $totalBills) / ($billsA * $billsB), 2);

        // Rule 6: Only return lift > 1
        if ($lift <= 1.0) {
            continue;
        }

        // Response status
        $respKey = "{$p1}_{$p2}";
        $resp = $responsesMap[$respKey] ?? null;
        $isResponded = ($resp !== null);
        $action = $resp ? $resp["action"] : null;
        $respondedAt = $resp ? $resp["updated_at"] : null;

        if ($action === "dismissed" && !$includeDismissed) {
            continue;
        }

        $p1Price = (float)$prod1["price"];
        $p2Price = (float)$prod2["price"];
        $combinedPrice = round($p1Price + $p2Price, 2);
        $bundleDiscountPct = 10;
        $bundlePrice = round($combinedPrice * (1.0 - ($bundleDiscountPct / 100.0)), 2);

        // Neutral template (Rule 5)
        $headline = "Often bought together: " . $prod1["name"] . " + " . $prod2["name"] . " (" . $billsTogether . " bills, " . round($support * 100, 1) . "% of baskets, lift: " . $lift . "x)";

        $items[] = [
            "pair_id" => "pair_" . $p1 . "_" . $p2,
            "product_1" => [
                "product_id" => $p1,
                "name" => $prod1["name"],
                "sku" => $prod1["sku"],
                "category" => !empty($prod1["category"]) ? $prod1["category"] : "Uncategorised",
                "price" => $p1Price,
                "current_stock" => (int)$prod1["units"],
                "bills_count" => $billsA
            ],
            "product_2" => [
                "product_id" => $p2,
                "name" => $prod2["name"],
                "sku" => $prod2["sku"],
                "category" => !empty($prod2["category"]) ? $prod2["category"] : "Uncategorised",
                "price" => $p2Price,
                "current_stock" => (int)$prod2["units"],
                "bills_count" => $billsB
            ],
            "metrics" => [
                "bills_together" => $billsTogether,
                "total_store_bills" => $totalBills,
                "support" => $support,
                "confidence_1_to_2" => $confidenceAtoB,
                "confidence_2_to_1" => $confidenceBtoA,
                "lift" => $lift
            ],
            "headline" => $headline,
            "suggested_action" => [
                "action_type" => "bundle_deal",
                "bundle_discount_pct" => $bundleDiscountPct,
                "combined_regular_price" => $combinedPrice,
                "bundle_price" => $bundlePrice,
                "placement_tip" => "Place " . $prod1["name"] . " and " . $prod2["name"] . " near each other or offer a bundle discount."
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
        "message" => "Product pair recommendations fetched successfully.",
        "data" => [
            "has_enough_sales_history" => true,
            "total_bills_analyzed" => $totalBills,
            "total_pairs_count" => count($items),
            "config" => [
                "window_days" => $windowDays,
                "min_total_bills" => PAIRS_CONFIG["min_total_bills"],
                "min_bills_together" => PAIRS_CONFIG["min_bills_together"],
                "min_support" => $minSupport
            ],
            "items" => $items
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "An error occurred while fetching product pairs: " . $e->getMessage(),
        "data" => (object)[]
    ]);
}
