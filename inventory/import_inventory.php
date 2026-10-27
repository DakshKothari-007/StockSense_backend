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

$items = $data["items"] ?? [];
if (!is_array($items) || count($items) === 0) {
    http_response_code(422);
    echo json_encode([
        "status" => false,
        "message" => "The 'items' array cannot be empty.",
        "data" => (object)[]
    ]);
    exit;
}

$mode = strtolower(trim($data["mode"] ?? "add_units")); // 'add_units' or 'set_units'

try {
    $pdo->beginTransaction();

    $newCount = 0;
    $updatedCount = 0;
    $errors = [];

    $checkStmt = $pdo->prepare("SELECT id, units FROM products WHERE shop_owner_id = ? AND sku = ? LIMIT 1");

    $insertStmt = $pdo->prepare("
        INSERT INTO products 
        (shop_owner_id, name, sku, category, description, price, cost_price, units, min_stock_threshold, status, icon_type)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $updateAddStmt = $pdo->prepare("
        UPDATE products 
        SET units = units + ?, price = IF(? > 0, ?, price), cost_price = IF(? > 0, ?, cost_price), name = IF(? != '', ?, name)
        WHERE id = ? AND shop_owner_id = ?
    ");

    $updateSetStmt = $pdo->prepare("
        UPDATE products 
        SET units = ?, price = IF(? > 0, ?, price), cost_price = IF(? > 0, ?, cost_price), name = IF(? != '', ?, name)
        WHERE id = ? AND shop_owner_id = ?
    ");

    foreach ($items as $index => $item) {
        $name = trim($item["name"] ?? "");
        $sku = strtoupper(trim($item["sku"] ?? ""));
        $units = isset($item["units"]) ? (int)$item["units"] : 0;
        $price = isset($item["price"]) ? (float)$item["price"] : 0.00;
        $costPrice = isset($item["cost_price"]) ? (float)$item["cost_price"] : 0.00;
        $category = trim($item["category"] ?? "General");
        $threshold = isset($item["min_stock_threshold"]) ? (int)$item["min_stock_threshold"] : 10;
        $iconType = isset($item["icon_type"]) ? (int)$item["icon_type"] : 2;

        if ($sku === "") {
            $errors[] = "Row {$index}: Missing required SKU.";
            continue;
        }

        $checkStmt->execute([$shopOwnerId, $sku]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $targetId = (int)$existing["id"];
            if ($mode === "set_units") {
                $updateSetStmt->execute([$units, $price, $price, $costPrice, $costPrice, $name, $name, $targetId, $shopOwnerId]);
            } else {
                // add_units default
                $updateAddStmt->execute([$units, $price, $price, $costPrice, $costPrice, $name, $name, $targetId, $shopOwnerId]);
            }
            $updatedCount++;
        } else {
            if ($name === "") {
                $name = "Product " . $sku;
            }
            $status = ($units <= $threshold) ? ("Low stock · Minimum " . $threshold) : null;
            $insertStmt->execute([
                $shopOwnerId,
                $name,
                $sku,
                $category,
                "Imported product batch",
                $price,
                $costPrice,
                $units,
                $threshold,
                $status,
                $iconType
            ]);
            $newCount++;
        }
    }

    $pdo->commit();

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Inventory import processed successfully.",
        "data" => [
            "total_submitted" => count($items),
            "new_products_created" => $newCount,
            "existing_products_updated" => $updatedCount,
            "mode" => $mode,
            "errors" => $errors
        ]
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Failed to import inventory: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Database error occurred during inventory batch import.",
        "data" => (object)[]
    ]);
}
?>
