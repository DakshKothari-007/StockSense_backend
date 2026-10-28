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

// 1. Pagination parameters
$page = max(1, (int)($_GET["page"] ?? 1));
$limit = max(1, min(100, (int)($_GET["limit"] ?? 20)));
$offset = ($page - 1) * $limit;

// 2. Date filtering using range queries (Rule 6: sale_date >= ? AND sale_date < ?)
$fromDateRaw = isset($_GET["from"]) ? trim($_GET["from"]) : "";
$toDateRaw = isset($_GET["to"]) ? trim($_GET["to"]) : "";

$isValidDate = function(string $date): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
};

$where = ["shop_owner_id = ?"];
$params = [$shopOwnerId];

if ($fromDateRaw !== "") {
    if (!$isValidDate($fromDateRaw)) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Invalid 'from' date format. Expected YYYY-MM-DD.",
            "data" => (object)[]
        ]);
        exit;
    }
    $where[] = "sale_date >= ?";
    $params[] = $fromDateRaw . " 00:00:00";
}

if ($toDateRaw !== "") {
    if (!$isValidDate($toDateRaw)) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Invalid 'to' date format. Expected YYYY-MM-DD.",
            "data" => (object)[]
        ]);
        exit;
    }
    // Half-open range: strictly before next day midnight
    $nextDay = date('Y-m-d', strtotime($toDateRaw . ' +1 day'));
    $where[] = "sale_date < ?";
    $params[] = $nextDay . " 00:00:00";
}

if ($fromDateRaw !== "" && $toDateRaw !== "" && $fromDateRaw > $toDateRaw) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Invalid date range: 'from' ({$fromDateRaw}) cannot be later than 'to' ({$toDateRaw}).",
        "data" => (object)[]
    ]);
    exit;
}

$whereClause = implode(" AND ", $where);

try {
    // 3. Count total matching bills
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE {$whereClause}");
    $countStmt->execute($params);
    $totalBills = (int)$countStmt->fetchColumn();
    $totalPages = $totalBills > 0 ? (int)ceil($totalBills / $limit) : 1;

    // 4. Fetch bills for the current page
    $listParams = $params;
    $listParams[] = $limit;
    $listParams[] = $offset;

    $listStmt = $pdo->prepare("
        SELECT id, bill_no, sale_date, total_amount, notes, created_at
        FROM sales
        WHERE {$whereClause}
        ORDER BY sale_date DESC, id DESC
        LIMIT ? OFFSET ?
    ");
    // Bind int parameters for MySQL limit & offset
    foreach ($params as $idx => $val) {
        $listStmt->bindValue($idx + 1, $val);
    }
    $listStmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
    $listStmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
    $listStmt->execute();

    $bills = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Load items with one single query for the entire page (Rule 9: No per-bill queries)
    $saleIds = array_column($bills, "id");
    $itemsBySale = [];

    if (!empty($saleIds)) {
        $placeholders = implode(",", array_fill(0, count($saleIds), "?"));
        $itemsStmt = $pdo->prepare("
            SELECT 
                si.id,
                si.sale_id,
                si.product_id,
                si.quantity,
                si.unit_price,
                si.line_total,
                COALESCE(p.name, 'Unknown Product') AS product_name,
                COALESCE(p.sku, '') AS sku,
                COALESCE(p.category, 'Uncategorised') AS category
            FROM sales_items si
            LEFT JOIN products p ON si.product_id = p.id
            WHERE si.sale_id IN ({$placeholders})
            ORDER BY si.id ASC
        ");
        $itemsStmt->execute($saleIds);
        while ($row = $itemsStmt->fetch(PDO::FETCH_ASSOC)) {
            $itemsBySale[(int)$row["sale_id"]][] = [
                "item_id" => (int)$row["id"],
                "product_id" => (int)$row["product_id"],
                "name" => $row["product_name"],
                "sku" => $row["sku"],
                "category" => $row["category"],
                "quantity" => (int)$row["quantity"],
                "unit_price" => (float)$row["unit_price"],
                "line_total" => (float)$row["line_total"]
            ];
        }
    }

    $formattedBills = [];
    foreach ($bills as $b) {
        $sid = (int)$b["id"];
        $items = $itemsBySale[$sid] ?? [];
        $totalQty = array_sum(array_column($items, "quantity"));

        $formattedBills[] = [
            "id" => $sid,
            "bill_no" => $b["bill_no"],
            "sale_date" => $b["sale_date"],
            "total_amount" => (float)$b["total_amount"],
            "notes" => $b["notes"],
            "item_count" => count($items),
            "total_quantity" => (int)$totalQty,
            "items" => $items
        ];
    }

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Sales bills retrieved successfully.",
        "data" => [
            "pagination" => [
                "page" => $page,
                "limit" => $limit,
                "total_bills" => $totalBills,
                "total_pages" => $totalPages
            ],
            "bills" => $formattedBills
        ]
    ]);

} catch (Throwable $e) {
    error_log("Sales list query error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Internal server error while fetching sales list.",
        "data" => (object)[]
    ]);
}
