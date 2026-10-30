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
        "message" => "Only GET requests are allowed.",
        "data" => (object)[]
    ]);
    exit;
}

require_once __DIR__ . "/auth_helper.php";
$owner = authenticateShopOwner($pdo);
$shopOwnerId = (int)$owner["id"];

// Parse search and filter parameters
$search = trim($_GET["search"] ?? $_GET["q"] ?? "");
$filter = strtolower(trim($_GET["filter"] ?? "0"));
$category = trim($_GET["category"] ?? "");
$page = max(1, (int)($_GET["page"] ?? 1));
$limit = isset($_GET["limit"]) ? max(0, min(100, (int)$_GET["limit"])) : 0;

$whereClauses = ["shop_owner_id = ?"];
$params = [$shopOwnerId];

// 1. Search Query (name or SKU)
if ($search !== "") {
    $whereClauses[] = "(name LIKE ? OR sku LIKE ?)";
    $like = "%" . $search . "%";
    $params[] = $like;
    $params[] = $like;
}

// 2. Filter (0: All, 1: Low stock, 2: Slow moving)
if ($filter === "1" || $filter === "low_stock" || $filter === "lowstock") {
    $whereClauses[] = "(units <= min_stock_threshold OR status LIKE '%Low stock%')";
} elseif ($filter === "2" || $filter === "slow_moving" || $filter === "slowmoving") {
    $whereClauses[] = "(status LIKE '%Slow moving%')";
}

// 3. Category Filter
if ($category !== "" && strtolower($category) !== "all") {
    $whereClauses[] = "category = ?";
    $params[] = $category;
}

try {
    // Total count for pagination
    $countSql = "SELECT COUNT(*) FROM products WHERE " . implode(" AND ", $whereClauses);
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = (int)$countStmt->fetchColumn();

    $sql = "SELECT id, shop_owner_id, name, sku, category, description, price, cost_price, units, min_stock_threshold, status, icon_type, created_at, updated_at
            FROM products
            WHERE " . implode(" AND ", $whereClauses) . "
            ORDER BY id ASC";

    if ($limit > 0) {
        $offset = ($page - 1) * $limit;
        $sql .= " LIMIT {$limit} OFFSET {$offset}";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedProducts = [];
    foreach ($rows as $row) {
        $units = (int)$row["units"];
        $threshold = (int)$row["min_stock_threshold"];
        $status = $row["status"];
        $statusColorHex = 0x00000000;

        // Auto compute status text and color if not custom
        if (empty($status) && $units <= $threshold) {
            $status = "Low stock · Minimum " . $threshold;
            $statusColorHex = 0xFF98621A;
        } elseif (!empty($status)) {
            $statusColorHex = 0xFF98621A;
        }

        $formattedProducts[] = [
            "id" => (string)$row["id"],
            "name" => $row["name"],
            "sku" => $row["sku"],
            "category" => $row["category"],
            "description" => $row["description"] ?? "",
            "price" => "₹" . rtrim(rtrim(number_format((float)$row["price"], 2), '0'), '.'),
            "raw_price" => (float)$row["price"],
            "cost_price" => "₹" . rtrim(rtrim(number_format((float)$row["cost_price"], 2), '0'), '.'),
            "raw_cost_price" => (float)$row["cost_price"],
            "units" => (string)$units,
            "raw_units" => $units,
            "min_stock_threshold" => $threshold,
            "status" => $status,
            "statusColorHex" => $statusColorHex,
            "iconType" => (int)$row["icon_type"],
            "created_at" => $row["created_at"],
            "updated_at" => $row["updated_at"]
        ];
    }

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Inventory products retrieved successfully.",
        "data" => [
            "shop_name" => $owner["shop_name"] ?? "",
            "total_products" => $totalCount,
            "total_count" => $totalCount,
            "page" => $page,
            "limit" => $limit,
            "filter" => $filter,
            "category" => $category,
            "search" => $search,
            "products" => $formattedProducts
        ]
    ]);

} catch (PDOException $e) {
    error_log("Failed to fetch inventory products: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Unable to load inventory products.",
        "data" => (object)[]
    ]);
}
?>
