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
    // 1. Scope by shop from token: Find target product in owner's store. Another shop's product returns 404.
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
            "message" => "Product not found.",
            "data" => (object)[]
        ]);
        exit;
    }

    $actualId = (int)$targetProduct['id'];

    // 2. Check if product appears in an open purchase order (status = 'placed') -> 409
    $poCheck = $pdo->prepare("
        SELECT COUNT(*) 
        FROM purchase_order_items poi
        JOIN purchase_orders po ON po.id = poi.purchase_order_id
        WHERE poi.product_id = ? AND po.shop_owner_id = ? AND po.status = 'placed'
    ");
    $poCheck->execute([$actualId, $shopOwnerId]);
    $openPoCount = (int)$poCheck->fetchColumn();

    if ($openPoCount > 0) {
        http_response_code(409);
        echo json_encode([
            "status" => false,
            "message" => "This product is in an open purchase order.",
            "data" => [
                "product_id" => $actualId,
                "open_po_count" => $openPoCount
            ]
        ]);
        exit;
    }

    // 3. Check if product has sales history -> 409
    $salesCheck = $pdo->prepare("SELECT COUNT(*) FROM sales_items WHERE product_id = ?");
    $salesCheck->execute([$actualId]);
    $salesCount = (int)$salesCheck->fetchColumn();

    if ($salesCount > 0) {
        http_response_code(409);
        echo json_encode([
            "status" => false,
            "message" => "This product has sales history, so it cannot be deleted. You can set its stock to 0 or mark it inactive.",
            "data" => [
                "product_id" => $actualId,
                "sales_count" => $salesCount
            ]
        ]);
        exit;
    }

    // 4. Delete product and clean up non-open order/reco references
    $pdo->beginTransaction();

    $delReco = $pdo->prepare("DELETE FROM recommendation_responses WHERE shop_owner_id = ? AND (product_id_1 = ? OR product_id_2 = ?)");
    $delReco->execute([$shopOwnerId, $actualId, $actualId]);

    $delPoi = $pdo->prepare("DELETE FROM purchase_order_items WHERE product_id = ? AND purchase_order_id IN (SELECT id FROM purchase_orders WHERE shop_owner_id = ?)");
    $delPoi->execute([$actualId, $shopOwnerId]);

    $delProd = $pdo->prepare("DELETE FROM products WHERE id = ? AND shop_owner_id = ?");
    $delProd->execute([$actualId, $shopOwnerId]);

    $pdo->commit();

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Product deleted.",
        "data" => [
            "deleted_id" => $actualId,
            "deleted_sku" => $targetProduct['sku'] ?? $sku
        ]
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Failed to delete product: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Unable to delete product from inventory.",
        "data" => (object)[]
    ]);
}
?>