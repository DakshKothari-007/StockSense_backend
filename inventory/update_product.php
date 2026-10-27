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

$productId = (int)($data["id"] ?? 0);
$lookupSku = trim($data["sku"] ?? "");

if ($productId <= 0 && $lookupSku === "") {
    http_response_code(422);
    echo json_encode([
        "status" => false,
        "message" => "Product ID or current SKU is required to identify the item.",
        "data" => (object)[]
    ]);
    exit;
}

try {
    // 1. Verify existence and ownership
    if ($productId > 0) {
        $checkStmt = $pdo->prepare("SELECT id, sku FROM products WHERE id = ? AND shop_owner_id = ? LIMIT 1");
        $checkStmt->execute([$productId, $shopOwnerId]);
    } else {
        $checkStmt = $pdo->prepare("SELECT id, sku FROM products WHERE sku = ? AND shop_owner_id = ? LIMIT 1");
        $checkStmt->execute([$lookupSku, $shopOwnerId]);
    }

    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        http_response_code(404);
        echo json_encode([
            "status" => false,
            "message" => "Product not found in your inventory.",
            "data" => (object)[]
        ]);
        exit;
    }

    $targetId = (int)$existing["id"];

    // 2. Build dynamic update
    $fields = [];
    $params = [];

    if (isset($data["name"]) && trim($data["name"]) !== "") {
        $fields[] = "name = ?";
        $params[] = trim($data["name"]);
    }

    if (isset($data["new_sku"]) && trim($data["new_sku"]) !== "") {
        $newSku = strtoupper(trim($data["new_sku"]));
        // Ensure not in use by another product of this shop
        $skuCheck = $pdo->prepare("SELECT id FROM products WHERE sku = ? AND shop_owner_id = ? AND id != ? LIMIT 1");
        $skuCheck->execute([$newSku, $shopOwnerId, $targetId]);
        if ($skuCheck->fetch()) {
            http_response_code(409);
            echo json_encode([
                "status" => false,
                "message" => "SKU '{$newSku}' is already assigned to another product in your shop.",
                "data" => (object)[]
            ]);
            exit;
        }
        $fields[] = "sku = ?";
        $params[] = $newSku;
    }

    if (isset($data["category"])) {
        $fields[] = "category = ?";
        $params[] = trim($data["category"]);
    }

    if (isset($data["description"])) {
        $fields[] = "description = ?";
        $params[] = trim($data["description"]);
    }

    if (isset($data["price"]) && is_numeric($data["price"])) {
        $fields[] = "price = ?";
        $params[] = (float)$data["price"];
    }

    if (isset($data["cost_price"]) && is_numeric($data["cost_price"])) {
        $fields[] = "cost_price = ?";
        $params[] = (float)$data["cost_price"];
    }

    if (isset($data["units"]) && is_numeric($data["units"])) {
        $fields[] = "units = ?";
        $params[] = (int)$data["units"];
    }

    if (isset($data["min_stock_threshold"]) && is_numeric($data["min_stock_threshold"])) {
        $fields[] = "min_stock_threshold = ?";
        $params[] = (int)$data["min_stock_threshold"];
    }

    if (array_key_exists("status", $data)) {
        $fields[] = "status = ?";
        $params[] = $data["status"] !== null ? trim($data["status"]) : null;
    }

    if (isset($data["icon_type"]) && is_numeric($data["icon_type"])) {
        $fields[] = "icon_type = ?";
        $params[] = (int)$data["icon_type"];
    }

    if (empty($fields)) {
        http_response_code(422);
        echo json_encode([
            "status" => false,
            "message" => "No valid fields provided to update.",
            "data" => (object)[]
        ]);
        exit;
    }

    $params[] = $targetId;
    $params[] = $shopOwnerId;

    $sql = "UPDATE products SET " . implode(", ", $fields) . " WHERE id = ? AND shop_owner_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Fetch updated record
    $fetchStmt = $pdo->prepare("SELECT id, name, sku, category, description, price, cost_price, units, min_stock_threshold, status, icon_type, updated_at FROM products WHERE id = ?");
    $fetchStmt->execute([$targetId]);
    $updated = $fetchStmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Product updated successfully.",
        "data" => [
            "product" => [
                "id" => (string)$updated["id"],
                "name" => $updated["name"],
                "sku" => $updated["sku"],
                "category" => $updated["category"],
                "description" => $updated["description"] ?? "",
                "price" => "₹" . rtrim(rtrim(number_format((float)$updated["price"], 2), '0'), '.'),
                "raw_price" => (float)$updated["price"],
                "cost_price" => "₹" . rtrim(rtrim(number_format((float)$updated["cost_price"], 2), '0'), '.'),
                "raw_cost_price" => (float)$updated["cost_price"],
                "units" => (string)$updated["units"],
                "raw_units" => (int)$updated["units"],
                "min_stock_threshold" => (int)$updated["min_stock_threshold"],
                "status" => $updated["status"],
                "iconType" => (int)$updated["icon_type"],
                "updated_at" => $updated["updated_at"]
            ]
        ]
    ]);

} catch (PDOException $e) {
    error_log("Failed to update product: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Unable to update product.",
        "data" => (object)[]
    ]);
}
?>
