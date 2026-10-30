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
    if ($productId > 0) {
        $stmt = $pdo->prepare("
            SELECT id, shop_owner_id, name, sku, category, description, price, cost_price, units, min_stock_threshold, status, icon_type, created_at, updated_at
            FROM products
            WHERE id = ? AND shop_owner_id = ?
            LIMIT 1
        ");
        $stmt->execute([$productId, $shopOwnerId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT id, shop_owner_id, name, sku, category, description, price, cost_price, units, min_stock_threshold, status, icon_type, created_at, updated_at
            FROM products
            WHERE sku = ? AND shop_owner_id = ?
            LIMIT 1
        ");
        $stmt->execute([$sku, $shopOwnerId]);
    }

    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        http_response_code(404);
        echo json_encode([
            "status" => false,
            "message" => "Product not found in your shop inventory.",
            "data" => (object)[]
        ]);
        exit;
    }

    $units = (int)$product["units"];
    $threshold = (int)$product["min_stock_threshold"];
    $costPrice = (float)$product["cost_price"];
    $sellPrice = (float)$product["price"];

    // Calculate 30-day sales velocity and stock cover
    $thirtyDaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
    $salesStmt = $pdo->prepare("
        SELECT COALESCE(SUM(si.quantity), 0) 
        FROM sales_items si 
        INNER JOIN sales s ON si.sale_id = s.id 
        WHERE si.product_id = ? AND s.shop_owner_id = ? AND s.sale_date >= ?
    ");
    $salesStmt->execute([(int)$product['id'], $shopOwnerId, $thirtyDaysAgo]);
    $sold30 = (int)$salesStmt->fetchColumn();
    $stockCoverDays = ($sold30 > 0) ? round($units / ($sold30 / 30.0), 1) : null;

    // Suggested reorder computation
    $unitsToReorder = max(10, ($threshold * 2) - $units);
    if ($units > $threshold * 2) {
        $unitsToReorder = 0;
    }
    $estimatedCost = $unitsToReorder * $costPrice;

    $status = $product["status"];
    if (empty($status) && $units <= $threshold) {
        $status = "Low stock · Minimum " . $threshold;
    }

    $productData = [
        "id" => (string)$product["id"],
        "name" => $product["name"],
        "sku" => $product["sku"],
        "category" => $product["category"],
        "categoryName" => $product["category"],
        "description" => $product["description"] ?: "Fresh stock",
        "price" => "₹" . rtrim(rtrim(number_format($sellPrice, 2), '0'), '.'),
        "raw_price" => (float)$sellPrice,
        "cost_price" => "₹" . rtrim(rtrim(number_format($costPrice, 2), '0'), '.'),
        "raw_cost_price" => (float)$costPrice,
        "units" => (string)$units,
        "raw_units" => $units,
        "status" => $status,
        "statusColorHex" => 0xFF98621A,
        "iconType" => (int)$product["icon_type"],
        "minimumStockThreshold" => $threshold,
        "min_stock_threshold" => $threshold,
        "stock_cover_days" => $stockCoverDays,
        "unitsToReorder" => $unitsToReorder,
        "estimatedCost" => "₹" . number_format($estimatedCost),
        "created_at" => $product["created_at"],
        "updated_at" => $product["updated_at"]
    ];

    $data = array_merge($productData, [
        "product" => $productData
    ]);

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Product details retrieved successfully.",
        "data" => $data
    ]);

} catch (PDOException $e) {
    error_log("Failed to fetch product details: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Unable to load product details.",
        "data" => (object)[]
    ]);
}
?>
