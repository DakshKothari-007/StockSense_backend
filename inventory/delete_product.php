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
    // Find target product in owner's store
    if ($productId > 0) {
        $findStmt = $pdo->prepare("SELECT id, name, sku FROM products WHERE id = ? AND shop_owner_id = ?");
        $findStmt->execute([$productId, $shopOwnerId]);
    } else {
        $findStmt = $pdo->prepare("SELECT id, name, sku FROM products WHERE sku = ? AND shop_owner_id = ?");
        $findStmt->execute([$sku, $shopOwnerId]);
    }
    $targetProduct = $findStmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetProduct) {
        http_response_code(404);
        echo json_encode([
            "status" => false,
            "message" => "Product not found or already deleted from your inventory.",
            "data" => (object)[]
        ]);
        exit;
    }

    $actualId = (int)$targetProduct['id'];

    // Check if product has sales records (Rule 8: Clear 400 error)
    $salesCheck = $pdo->prepare("SELECT COUNT(*) FROM sales_items WHERE product_id = ?");
    $salesCheck->execute([$actualId]);
    $salesCount = (int)$salesCheck->fetchColumn();

    if ($salesCount > 0) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Cannot delete product '{$targetProduct['name']}' (SKU: {$targetProduct['sku']}) because it is linked to {$salesCount} sales records. Archive the product instead to preserve sales history.",
            "data" => [
                "product_id" => $actualId,
                "sales_count" => $salesCount,
                "suggested_action" => "archive"
            ]
        ]);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM products WHERE id = ? AND shop_owner_id = ?");
    $stmt->execute([$actualId, $shopOwnerId]);

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
