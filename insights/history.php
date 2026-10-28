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

// Pagination parameters
$page = isset($_GET["page"]) && is_numeric($_GET["page"]) ? max(1, (int)$_GET["page"]) : 1;
$limit = isset($_GET["limit"]) && is_numeric($_GET["limit"]) ? min(100, max(1, (int)$_GET["limit"])) : 20;
$offset = ($page - 1) * $limit;

// Filter parameters
$typeFilter = isset($_GET["type"]) ? trim($_GET["type"]) : null;
$actionFilter = isset($_GET["action"]) ? trim($_GET["action"]) : null;

try {
    $where = ["rr.shop_owner_id = ?"];
    $params = [$shopOwnerId];

    if ($typeFilter !== null && $typeFilter !== "") {
        $where[] = "rr.recommendation_type = ?";
        $params[] = $typeFilter;
    }

    if ($actionFilter !== null && $actionFilter !== "") {
        $where[] = "rr.action = ?";
        $params[] = $actionFilter;
    }

    $whereClause = implode(" AND ", $where);

    // Count total items
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM recommendation_responses rr
        WHERE {$whereClause}
    ");
    $countStmt->execute($params);
    $totalItems = (int)$countStmt->fetchColumn();
    $totalPages = $totalItems > 0 ? (int)ceil($totalItems / $limit) : 1;

    // Fetch records with product details
    $dataStmt = $pdo->prepare("
        SELECT 
            rr.id,
            rr.recommendation_type,
            rr.product_id_1,
            p1.name AS product_1_name,
            p1.sku AS product_1_sku,
            p1.price AS product_1_price,
            rr.product_id_2,
            p2.name AS product_2_name,
            p2.sku AS product_2_sku,
            p2.price AS product_2_price,
            rr.action,
            rr.notes,
            rr.created_at,
            rr.updated_at
        FROM recommendation_responses rr
        LEFT JOIN products p1 ON rr.product_id_1 = p1.id
        LEFT JOIN products p2 ON rr.product_id_2 = p2.id AND rr.product_id_2 > 0
        WHERE {$whereClause}
        ORDER BY rr.updated_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $dataStmt->execute($params);

    $items = [];
    while ($row = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
        $recType = $row["recommendation_type"];
        $p1 = (int)$row["product_id_1"];
        $p2 = (int)$row["product_id_2"];

        $items[] = [
            "id" => (int)$row["id"],
            "recommendation_type" => $recType,
            "pair_id" => ($recType === "product_pair") ? "pair_{$p1}_{$p2}" : null,
            "product_1" => [
                "product_id" => $p1,
                "name" => $row["product_1_name"] ?? "Deleted Product",
                "sku" => $row["product_1_sku"],
                "price" => $row["product_1_price"] !== null ? (float)$row["product_1_price"] : null
            ],
            "product_2" => ($p2 > 0) ? [
                "product_id" => $p2,
                "name" => $row["product_2_name"] ?? "Deleted Product",
                "sku" => $row["product_2_sku"],
                "price" => $row["product_2_price"] !== null ? (float)$row["product_2_price"] : null
            ] : null,
            "action" => $row["action"],
            "notes" => $row["notes"],
            "created_at" => $row["created_at"],
            "updated_at" => $row["updated_at"]
        ];
    }

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Recommendation history fetched successfully.",
        "data" => [
            "items" => $items,
            "pagination" => [
                "page" => $page,
                "limit" => $limit,
                "total_items" => $totalItems,
                "total_pages" => $totalPages
            ]
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "An error occurred while fetching history: " . $e->getMessage(),
        "data" => (object)[]
    ]);
}
