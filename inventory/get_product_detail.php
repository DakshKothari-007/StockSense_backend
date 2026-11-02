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

    // Reorder module integration for exact matching numbers
    require_once __DIR__ . "/../settings/settings_helper.php";
    $shopSettings = get_shop_settings($pdo, $shopOwnerId);
    $lead = (int)($shopSettings["lead_time_days"] ?? 3);
    $safety = (int)($shopSettings["safety_days"] ?? 2);
    $cover = (int)($shopSettings["cover_days"] ?? 7);
    $minHistoryDays = (int)($shopSettings["min_history_days"] ?? 30);
    $leadSafetyCover = $lead + $safety + $cover;

    $historyStmt = $pdo->prepare("SELECT MIN(sale_date) FROM sales WHERE shop_owner_id = ?");
    $historyStmt->execute([$shopOwnerId]);
    $firstSaleDate = $historyStmt->fetchColumn();
    $historyDays = 0;
    $historyWarning = true;
    if ($firstSaleDate) {
        $historyDays = max(0, (time() - strtotime($firstSaleDate)) / 86400.0);
        $historyWarning = ($historyDays < $minHistoryDays);
    }
    $divisor = ($historyDays >= 7) ? min(30.0, $historyDays) : 0;

    // Check open on-order units from placed purchase orders
    $openOrdersStmt = $pdo->prepare("
        SELECT COALESCE(SUM(poi.quantity), 0)
        FROM purchase_order_items poi
        INNER JOIN purchase_orders po ON poi.purchase_order_id = po.id
        WHERE po.shop_owner_id = ? AND po.status = 'placed' AND poi.product_id = ?
    ");
    $openOrdersStmt->execute([$shopOwnerId, (int)$product['id']]);
    $onOrderUnits = (int)$openOrdersStmt->fetchColumn();

    $dailySalesRate = ($divisor > 0) ? round($sold30 / $divisor, 2) : 0.0;
    $stockCoverDays = ($dailySalesRate > 0) ? round($units / $dailySalesRate, 1) : null;

    if ($historyWarning) {
        $targetFallback = $threshold * 2;
        $grossSuggested = max(0, $targetFallback - $units);
        $unitsToReorder = max(0, $grossSuggested - $onOrderUnits);
    } elseif ($sold30 === 0 || $dailySalesRate <= 0) {
        $unitsToReorder = 0;
    } else {
        $targetCoverageUnits = (int)ceil($dailySalesRate * $leadSafetyCover);
        $grossSuggested = max(0, $targetCoverageUnits - $units);
        $unitsToReorder = max(0, $grossSuggested - $onOrderUnits);
    }
    $estimatedCost = round($unitsToReorder * $costPrice, 2);

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
