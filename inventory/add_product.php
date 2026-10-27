<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Id, X-User-Email");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "status" => false,
        "message" => "Only POST requests are allowed.",
        "data" => (object)[]
    ]);
    exit;
}

require_once __DIR__ . "/auth_helper.php";
$owner = authenticateShopOwner($pdo);
$shopOwnerId = (int)$owner["id"];

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Invalid JSON payload.",
        "data" => (object)[]
    ]);
    exit;
}

// Field extraction and validation
$name = trim($data["name"] ?? "");
$sku = strtoupper(trim($data["sku"] ?? ""));
$category = trim($data["category"] ?? "General");
$description = trim($data["description"] ?? "");
$price = isset($data["price"]) ? (float)$data["price"] : -1;
$costPrice = isset($data["cost_price"]) ? (float)$data["cost_price"] : 0.00;
$units = isset($data["units"]) ? (int)$data["units"] : -1;
$minStockThreshold = isset($data["min_stock_threshold"]) ? (int)$data["min_stock_threshold"] : 10;
$status = isset($data["status"]) ? trim($data["status"]) : null;
$iconType = isset($data["icon_type"]) ? (int)$data["icon_type"] : 0;

if ($name === "" || strlen($name) > 150) {
    http_response_code(422);
    echo json_encode([
        "status" => false,
        "message" => "Product name is required (max 150 characters).",
        "data" => (object)[]
    ]);
    exit;
}

if ($sku === "" || strlen($sku) > 50) {
    http_response_code(422);
    echo json_encode([
        "status" => false,
        "message" => "SKU is required (max 50 characters).",
        "data" => (object)[]
    ]);
    exit;
}

if ($price < 0) {
    http_response_code(422);
    echo json_encode([
        "status" => false,
        "message" => "Valid selling price (>= 0) is required.",
        "data" => (object)[]
    ]);
    exit;
}

if ($units < 0) {
    http_response_code(422);
    echo json_encode([
        "status" => false,
        "message" => "Valid units quantity (>= 0) is required.",
        "data" => (object)[]
    ]);
    exit;
}

// Auto set status if not provided
if (empty($status) && $units <= $minStockThreshold) {
    $status = "Low stock · Minimum " . $minStockThreshold;
}

try {
    // 1. Check for duplicate SKU in this shop
    $dupCheck = $pdo->prepare("SELECT id FROM products WHERE shop_owner_id = ? AND sku = ? LIMIT 1");
    $dupCheck->execute([$shopOwnerId, $sku]);
    if ($dupCheck->fetch()) {
        http_response_code(409);
        echo json_encode([
            "status" => false,
            "message" => "A product with SKU '{$sku}' already exists in your shop.",
            "data" => (object)[]
        ]);
        exit;
    }

    // 2. Insert product
    $insertSql = "INSERT INTO products 
        (shop_owner_id, name, sku, category, description, price, cost_price, units, min_stock_threshold, status, icon_type)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($insertSql);
    $stmt->execute([
        $shopOwnerId,
        $name,
        $sku,
        $category,
        $description ?: null,
        $price,
        $costPrice,
        $units,
        $minStockThreshold,
        $status ?: null,
        $iconType
    ]);

    $newId = (int)$pdo->lastInsertId();

    http_response_code(201);
    echo json_encode([
        "status" => true,
        "message" => "Product added to inventory successfully.",
        "data" => [
            "id" => (string)$newId,
            "name" => $name,
            "sku" => $sku,
            "category" => $category,
            "description" => $description,
            "price" => "₹" . rtrim(rtrim(number_format($price, 2), '0'), '.'),
            "raw_price" => $price,
            "cost_price" => "₹" . rtrim(rtrim(number_format($costPrice, 2), '0'), '.'),
            "raw_cost_price" => $costPrice,
            "units" => (string)$units,
            "min_stock_threshold" => $minStockThreshold,
            "status" => $status,
            "iconType" => $iconType
        ]
    ]);

} catch (PDOException $e) {
    error_log("Failed to insert product: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Unable to add product to inventory.",
        "data" => (object)[]
    ]);
}
?>
