<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Id, X-User-Email");

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

$productId = (int)($_GET["id"] ?? 0);
$sku = trim($_GET["sku"] ?? "");

if ($productId <= 0 && $sku === "") {
    http_response_code(422);
    echo json_encode([
        "status" => false,
        "message" => "Product ID or SKU is required.",
        "data" => (object)[]
    ]);
    exit;
}

try {
    // 1. Locate Product belonging to this shop owner (by ID or fallback to SKU)
    $product = null;
    if ($productId > 0) {
        $stmt = $pdo->prepare("
            SELECT id, name, sku, units, price, cost_price 
            FROM products 
            WHERE id = ? AND shop_owner_id = ? 
            LIMIT 1
        ");
        $stmt->execute([$productId, $shopOwnerId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (empty($product) && $sku !== "") {
        $stmt = $pdo->prepare("
            SELECT id, name, sku, units, price, cost_price 
            FROM products 
            WHERE sku = ? AND shop_owner_id = ? 
            LIMIT 1
        ");
        $stmt->execute([$sku, $shopOwnerId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$product) {
        http_response_code(404);
        echo json_encode([
            "status" => false,
            "message" => "Product not found in your shop inventory.",
            "data" => (object)[]
        ]);
        exit;
    }

    $actualPid = (int)$product["id"];

    // 2. Query last 7 days of daily sales for this product
    $startDatetime = date('Y-m-d 00:00:00', strtotime('-6 days'));
    $endDatetime = date('Y-m-d 23:59:59');

    $salesStmt = $pdo->prepare("
        SELECT 
            DATE(s.sale_date) AS s_date,
            COALESCE(SUM(si.quantity), 0) AS daily_units,
            COALESCE(SUM(si.line_total), 0.00) AS daily_revenue
        FROM sales_items si
        INNER JOIN sales s ON si.sale_id = s.id
        WHERE s.shop_owner_id = ? AND si.product_id = ? AND s.sale_date >= ? AND s.sale_date <= ?
        GROUP BY DATE(s.sale_date)
        ORDER BY s_date ASC
    ");
    $salesStmt->execute([$shopOwnerId, $actualPid, $startDatetime, $endDatetime]);

    $dailySalesMap = [];
    while ($row = $salesStmt->fetch(PDO::FETCH_ASSOC)) {
        $dailySalesMap[$row["s_date"]] = [
            "units" => (int)$row["daily_units"],
            "revenue" => round((float)$row["daily_revenue"], 2)
        ];
    }

    // 3. Build contiguous 7-day points (Day -6 to Day 0)
    $todayStr = date('Y-m-d');
    $historyPoints = [];
    $totalUnitsSold = 0;
    $totalRevenue = 0.00;
    $maxDailyUnits = 0;

    for ($i = 6; $i >= 0; $i--) {
        $ts = strtotime("-{$i} days");
        $dStr = date('Y-m-d', $ts);
        $dayNumber = (string)(7 - $i);
        $dayLabel = date('j M', $ts);
        $dayOfWeek = date('D', $ts);

        $units = $dailySalesMap[$dStr]["units"] ?? 0;
        $rev = $dailySalesMap[$dStr]["revenue"] ?? 0.00;

        $totalUnitsSold += $units;
        $totalRevenue += $rev;
        if ($units > $maxDailyUnits) {
            $maxDailyUnits = $units;
        }

        $historyPoints[] = [
            "date" => $dStr,
            "day_number" => $dayNumber,
            "day_label" => $dayLabel,
            "day_of_week" => $dayOfWeek,
            "units_sold" => $units,
            "revenue" => $rev,
            "is_today" => ($dStr === $todayStr)
        ];
    }

    // Calculate height_ratio (normalized between 0.18f min if sold > 0 and 1.0f max)
    foreach ($historyPoints as &$hp) {
        if ($maxDailyUnits > 0 && $hp["units_sold"] > 0) {
            $rawRatio = $hp["units_sold"] / $maxDailyUnits;
            $hp["height_ratio"] = round(max(0.18, $rawRatio), 2);
        } else {
            $hp["height_ratio"] = 0.00;
        }
    }
    unset($hp);

    $hasSales = ($totalUnitsSold > 0);
    $avgDailyUnits = round($totalUnitsSold / 7.0, 1);

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Product sales history retrieved successfully.",
        "data" => [
            "product_id" => $actualPid,
            "product_name" => $product["name"],
            "sku" => $product["sku"],
            "days_count" => 7,
            "total_units_sold" => $totalUnitsSold,
            "total_revenue" => round($totalRevenue, 2),
            "average_daily_units" => $avgDailyUnits,
            "has_sales" => $hasSales,
            "history" => $historyPoints
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Database error: " . $e->getMessage(),
        "data" => (object)[]
    ]);
}

