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

$statusFilter = isset($_GET["status"]) ? strtolower(trim($_GET["status"])) : "";
$page = max(1, (int)($_GET["page"] ?? 1));
$limit = max(1, min(100, (int)($_GET["limit"] ?? 20)));
$offset = ($page - 1) * $limit;

$where = ["shop_owner_id = ?"];
$params = [$shopOwnerId];

if ($statusFilter !== "") {
    if (!in_array($statusFilter, ["placed", "received", "cancelled"])) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Invalid status filter. Allowed: placed, received, cancelled.",
            "data" => (object)[]
        ]);
        exit;
    }
    $where[] = "status = ?";
    $params[] = $statusFilter;
}

$whereClause = implode(" AND ", $where);

try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE {$whereClause}");
    $countStmt->execute($params);
    $totalOrders = (int)$countStmt->fetchColumn();
    $totalPages = $totalOrders > 0 ? (int)ceil($totalOrders / $limit) : 1;

    $listStmt = $pdo->prepare("
        SELECT id, order_no, status, total_estimated_cost, total_items, total_units, received_at, notes, created_at
        FROM purchase_orders
        WHERE {$whereClause}
        ORDER BY id DESC
        LIMIT ? OFFSET ?
    ");
    foreach ($params as $idx => $val) {
        $listStmt->bindValue($idx + 1, $val);
    }
    $listStmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
    $listStmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
    $listStmt->execute();

    $orders = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    // Single-query item loader for all orders on the page
    $orderIds = array_column($orders, "id");
    $itemsByOrder = [];

    if (!empty($orderIds)) {
        $placeholders = implode(",", array_fill(0, count($orderIds), "?"));
        $itemsStmt = $pdo->prepare("
            SELECT 
                poi.id,
                poi.purchase_order_id,
                poi.product_id,
                poi.quantity,
                poi.cost_price,
                poi.line_estimated_cost,
                p.name AS product_name,
                p.sku,
                COALESCE(p.category, 'Uncategorised') AS category
            FROM purchase_order_items poi
            INNER JOIN products p ON poi.product_id = p.id
            WHERE poi.purchase_order_id IN ({$placeholders})
            ORDER BY poi.id ASC
        ");
        $itemsStmt->execute($orderIds);
        while ($row = $itemsStmt->fetch(PDO::FETCH_ASSOC)) {
            $itemsByOrder[(int)$row["purchase_order_id"]][] = [
                "item_id" => (int)$row["id"],
                "product_id" => (int)$row["product_id"],
                "name" => $row["product_name"],
                "sku" => $row["sku"],
                "category" => $row["category"],
                "quantity" => (int)$row["quantity"],
                "cost_price" => ($row["cost_price"] !== null) ? (float)$row["cost_price"] : null,
                "line_estimated_cost" => ($row["line_estimated_cost"] !== null) ? (float)$row["line_estimated_cost"] : null
            ];
        }
    }

    $formattedOrders = [];
    foreach ($orders as $o) {
        $oid = (int)$o["id"];
        $items = $itemsByOrder[$oid] ?? [];

        $formattedOrders[] = [
            "id" => $oid,
            "order_no" => $o["order_no"],
            "status" => $o["status"],
            "total_estimated_cost" => ($o["total_estimated_cost"] !== null) ? (float)$o["total_estimated_cost"] : null,
            "total_items" => (int)$o["total_items"],
            "total_units" => (int)$o["total_units"],
            "received_at" => $o["received_at"],
            "notes" => $o["notes"],
            "created_at" => $o["created_at"],
            "items" => $items
        ];
    }

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Purchase orders retrieved successfully.",
        "data" => [
            "pagination" => [
                "page" => $page,
                "limit" => $limit,
                "total_orders" => $totalOrders,
                "total_pages" => $totalPages
            ],
            "orders" => $formattedOrders
        ]
    ]);

} catch (Throwable $e) {
    error_log("Orders list error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Internal error fetching purchase orders: " . $e->getMessage(),
        "data" => (object)[]
    ]);
}
