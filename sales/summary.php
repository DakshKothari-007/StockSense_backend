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

// 1. Validate Optional Date Range (Rule 6: range queries sale_date >= ? AND sale_date < ?)
$todayDateStr = date('Y-m-d');
$defaultFrom = date('Y-m-d', strtotime('-30 days'));

$fromDateRaw = isset($_GET["from"]) ? trim($_GET["from"]) : $defaultFrom;
$toDateRaw = isset($_GET["to"]) ? trim($_GET["to"]) : $todayDateStr;

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
        "message" => "Invalid date range: 'from' ({$fromDateRaw}) cannot be later than 'to' ({$toDateRaw}).",
        "data" => (object)[]
    ]);
    exit;
}

// Half-open range boundaries
$startDatetime = $fromDateRaw . " 00:00:00";
$endNextDay = date('Y-m-d', strtotime($toDateRaw . ' +1 day'));
$endDatetime = $endNextDay . " 00:00:00";

try {
    // 2. Aggregate Sales Totals in date range
    $totStmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total_bills_count,
            COALESCE(SUM(total_amount), 0.00) AS total_sales_amount
        FROM sales
        WHERE shop_owner_id = ? AND sale_date >= ? AND sale_date < ?
    ");
    $totStmt->execute([$shopOwnerId, $startDatetime, $endDatetime]);
    $totRow = $totStmt->fetch(PDO::FETCH_ASSOC);

    $totalBills = (int)$totRow["total_bills_count"];
    $totalSales = round((float)$totRow["total_sales_amount"], 2);
    $avgBill = $totalBills > 0 ? round($totalSales / $totalBills, 2) : 0.00;

    // 3. Daily Sales Points
    $dailyStmt = $pdo->prepare("
        SELECT 
            DATE(sale_date) AS s_date,
            COUNT(*) AS bills_count,
            COALESCE(SUM(total_amount), 0.00) AS daily_amount
        FROM sales
        WHERE shop_owner_id = ? AND sale_date >= ? AND sale_date < ?
        GROUP BY DATE(sale_date)
        ORDER BY s_date ASC
    ");
    $dailyStmt->execute([$shopOwnerId, $startDatetime, $endDatetime]);
    $dailyMap = [];
    while ($dr = $dailyStmt->fetch(PDO::FETCH_ASSOC)) {
        $dailyMap[$dr["s_date"]] = [
            "bills_count" => (int)$dr["bills_count"],
            "amount" => round((float)$dr["daily_amount"], 2)
        ];
    }

    // Build contiguous daily array
    $dailyPoints = [];
    $cur = strtotime($fromDateRaw);
    $endTs = strtotime($toDateRaw);
    $maxDailyAmount = 0.00;

    while ($cur <= $endTs) {
        $dStr = date('Y-m-d', $cur);
        $dLabel = date('j M', $cur);
        $amt = $dailyMap[$dStr]["amount"] ?? 0.00;
        $cnt = $dailyMap[$dStr]["bills_count"] ?? 0;

        if ($amt > $maxDailyAmount) {
            $maxDailyAmount = $amt;
        }

        $dailyPoints[] = [
            "date" => $dStr,
            "day_label" => $dLabel,
            "sales_amount" => $amt,
            "bills_count" => $cnt
        ];
        $cur = strtotime('+1 day', $cur);
    }

    // Add height_ratio for UI charts if needed
    foreach ($dailyPoints as &$dp) {
        $dp["height_ratio"] = ($maxDailyAmount > 0) ? round($dp["sales_amount"] / $maxDailyAmount, 2) : 0.00;
        $dp["is_current_day"] = ($dp["date"] === $todayDateStr);
    }
    unset($dp);

    // 4. Top Selling Products in date range (by quantity sold)
    $topStmt = $pdo->prepare("
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
        LIMIT 10
    ");
    $topStmt->execute([$shopOwnerId, $startDatetime, $endDatetime]);
    $topSellers = [];
    while ($ts = $topStmt->fetch(PDO::FETCH_ASSOC)) {
        $topSellers[] = [
            "product_id" => (int)$ts["product_id"],
            "name" => $ts["name"],
            "sku" => $ts["sku"],
            "category" => $ts["category"],
            "units_sold" => (int)$ts["units_sold"],
            "revenue" => (float)$ts["revenue"]
        ];
    }

    // 5. Category Breakdown
    $catStmt = $pdo->prepare("
        SELECT 
            COALESCE(NULLIF(TRIM(p.category), ''), 'Uncategorised') AS category_name,
            SUM(si.quantity) AS units_sold,
            ROUND(SUM(si.line_total), 2) AS category_revenue
        FROM sales_items si
        INNER JOIN sales s ON si.sale_id = s.id
        INNER JOIN products p ON si.product_id = p.id
        WHERE s.shop_owner_id = ? AND s.sale_date >= ? AND s.sale_date < ?
        GROUP BY category_name
        ORDER BY category_revenue DESC
    ");
    $catStmt->execute([$shopOwnerId, $startDatetime, $endDatetime]);
    $categories = [];
    while ($cr = $catStmt->fetch(PDO::FETCH_ASSOC)) {
        $cRev = (float)$cr["category_revenue"];
        $pct = ($totalSales > 0) ? round(($cRev / $totalSales) * 100, 1) : 0.0;
        $categories[] = [
            "category" => $cr["category_name"],
            "units_sold" => (int)$cr["units_sold"],
            "revenue" => $cRev,
            "percentage" => $pct
        ];
    }

    // 6. Hourly Sales for Today only when real timestamps exist (Rule 10)
    $todayStart = $todayDateStr . " 00:00:00";
    $todayEnd = date('Y-m-d', strtotime($todayDateStr . ' +1 day')) . " 00:00:00";

    $hourlyCheckStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM sales
        WHERE shop_owner_id = ? AND sale_date >= ? AND sale_date < ? AND TIME(sale_date) != '00:00:00'
    ");
    $hourlyCheckStmt->execute([$shopOwnerId, $todayStart, $todayEnd]);
    $realTimestampCount = (int)$hourlyCheckStmt->fetchColumn();

    $hourlySales = null;
    if ($realTimestampCount > 0) {
        $hourStmt = $pdo->prepare("
            SELECT 
                HOUR(sale_date) AS sale_hour,
                COUNT(*) AS bills_count,
                COALESCE(SUM(total_amount), 0.00) AS hourly_amount
            FROM sales
            WHERE shop_owner_id = ? AND sale_date >= ? AND sale_date < ?
            GROUP BY sale_hour
            ORDER BY sale_hour ASC
        ");
        $hourStmt->execute([$shopOwnerId, $todayStart, $todayEnd]);
        $hourData = [];
        $peakHour = null;
        $peakAmount = 0.00;

        while ($hr = $hourStmt->fetch(PDO::FETCH_ASSOC)) {
            $h = (int)$hr["sale_hour"];
            $amt = round((float)$hr["hourly_amount"], 2);
            $label = date('g A', strtotime("{$todayDateStr} {$h}:00:00"));

            if ($amt > $peakAmount) {
                $peakAmount = $amt;
                $nextH = ($h + 1) % 24;
                $nextLabel = date('g A', strtotime("{$todayDateStr} {$nextH}:00:00"));
                $peakHour = "{$label}–{$nextLabel}";
            }

            $hourData[] = [
                "hour" => $h,
                "label" => $label,
                "amount" => $amt,
                "bills_count" => (int)$hr["bills_count"]
            ];
        }

        $hourlySales = [
            "has_real_timestamps" => true,
            "peak_hour" => $peakHour,
            "peak_amount" => $peakAmount,
            "hourly_points" => $hourData
        ];
    }

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Sales summary retrieved successfully.",
        "data" => [
            "date_range" => [
                "from" => $fromDateRaw,
                "to" => $toDateRaw
            ],
            "total_sales_amount" => $totalSales,
            "total_bills_count" => $totalBills,
            "average_bill_amount" => $avgBill,
            "daily_sales_points" => $dailyPoints,
            "top_selling_products" => $topSellers,
            "category_breakdown" => $categories,
            "hourly_sales_today" => $hourlySales
        ]
    ]);

} catch (Throwable $e) {
    error_log("Sales summary error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Internal server error while compiling sales summary.",
        "data" => (object)[]
    ]);
}
