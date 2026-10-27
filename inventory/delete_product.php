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
$sku = trim($data["sku"] ?? "");

if ($productId <= 0 && $sku === "") {
    http_response_code(422);
    echo json_encode([
        "status" => false,
        "message" => "Product ID or SKU is required for deletion.",
        "data" => (object)[]
    ]);
    exit;
}

try {
    if ($productId > 0) {
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ? AND shop_owner_id = ?");
        $stmt->execute([$productId, $shopOwnerId]);
    } else {
        $stmt = $pdo->prepare("DELETE FROM products WHERE sku = ? AND shop_owner_id = ?");
        $stmt->execute([$sku, $shopOwnerId]);
    }

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode([
            "status" => false,
            "message" => "Product not found or already deleted from your inventory.",
            "data" => (object)[]
        ]);
        exit;
    }

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Product deleted successfully from inventory.",
        "data" => [
            "deleted_id" => $productId ?: null,
            "deleted_sku" => $sku ?: null
        ]
    ]);

} catch (PDOException $e) {
    error_log("Failed to delete product: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Unable to delete product from inventory.",
        "data" => (object)[]
    ]);
}
?>
