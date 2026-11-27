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

$statusFilter = isset($_GET["status"]) ? strtolower(trim($_GET["status"])) : null;
if ($statusFilter !== null && $statusFilter !== "" && !in_array($statusFilter, ["active", "ended"], true)) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Invalid status filter. Must be 'active' or 'ended'.",
        "data" => (object)[]
    ]);
    exit;
}

try {
    $sql = "
        SELECT
            bo.id,
            bo.product_id_1,
            bo.product_id_2,
            bo.offer_price,
            bo.combined_price,
            bo.status,
            bo.created_at,
            bo.ended_at,
            p1.name AS p1_name,
            p1.sku AS p1_sku,
            p1.category AS p1_category,
            p1.icon_key AS p1_icon_key,
            p2.name AS p2_name,
            p2.sku AS p2_sku,
            p2.category AS p2_category,
            p2.icon_key AS p2_icon_key
        FROM bundle_offers bo
        INNER JOIN products p1 ON bo.product_id_1 = p1.id AND p1.shop_owner_id = bo.shop_owner_id
        INNER JOIN products p2 ON bo.product_id_2 = p2.id AND p2.shop_owner_id = bo.shop_owner_id
        WHERE bo.shop_owner_id = ?
    ";
    $params = [$shopOwnerId];

    if ($statusFilter === "active" || $statusFilter === "ended") {
        $sql .= " AND bo.status = ?";
        $params[] = $statusFilter;
    }

    $sql .= " ORDER BY CASE WHEN bo.status = 'active' THEN 0 ELSE 1 END ASC, bo.created_at DESC, bo.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $offers = [];
    $activeCount = 0;
    $endedCount = 0;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $offerPrice = round((float)$row["offer_price"], 2);
        $combinedPrice = round((float)$row["combined_price"], 2);
        $savings = round(max(0.0, $combinedPrice - $offerPrice), 2);
        $status = $row["status"];

        if ($status === "active") {
            $activeCount++;
        } else {
            $endedCount++;
        }

        $offers[] = [
            "id" => (int)$row["id"],
            "product_1" => [
                "product_id" => (int)$row["product_id_1"],
                "name" => $row["p1_name"],
                "sku" => $row["p1_sku"],
                "category" => !empty($row["p1_category"]) ? $row["p1_category"] : "Uncategorised",
                "icon_key" => $row["p1_icon_key"]
            ],
            "product_2" => [
                "product_id" => (int)$row["product_id_2"],
                "name" => $row["p2_name"],
                "sku" => $row["p2_sku"],
                "category" => !empty($row["p2_category"]) ? $row["p2_category"] : "Uncategorised",
                "icon_key" => $row["p2_icon_key"]
            ],
            "offer_price" => $offerPrice,
            "combined_price" => $combinedPrice,
            "savings" => $savings,
            "status" => $status,
            "created_at" => $row["created_at"],
            "ended_at" => $row["ended_at"]
        ];
    }

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Bundle offers retrieved successfully.",
        "data" => [
            "active_count" => $activeCount,
            "ended_count" => $endedCount,
            "offers" => $offers
        ]
    ]);
} catch (Throwable $e) {
    error_log("Offers list error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "An internal server error occurred while loading bundle offers.",
        "data" => (object)[]
    ]);
}
