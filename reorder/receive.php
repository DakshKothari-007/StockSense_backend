<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "status" => false,
        "message" => "Method Not Allowed. Only POST requests are allowed.",
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

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Invalid JSON payload.",
        "data" => (object)[]
    ]);
    exit;
}

$orderId = (int)($data["order_id"] ?? 0);
$orderNo = trim($data["order_no"] ?? "");

if ($orderId <= 0 && $orderNo === "") {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "order_id or order_no is required.",
        "data" => (object)[]
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Resolve Order ID
    if ($orderId <= 0) {
        $findStmt = $pdo->prepare("SELECT id FROM purchase_orders WHERE order_no = ? AND shop_owner_id = ? LIMIT 1");
        $findStmt->execute([$orderNo, $shopOwnerId]);
        $orderId = (int)$findStmt->fetchColumn();
    }

    // 2. Rule 4: Atomic status update first
    // UPDATE purchase_orders SET status='received', received_at=NOW() WHERE id=? AND shop_owner_id=? AND status='placed'
    $updateOrderStmt = $pdo->prepare("
        UPDATE purchase_orders 
        SET status = 'received', received_at = NOW() 
        WHERE id = ? AND shop_owner_id = ? AND status = 'placed'
    ");
    $updateOrderStmt->execute([$orderId, $shopOwnerId]);

    if ($updateOrderStmt->rowCount() === 0) {
        $pdo->rollBack();

        // Check why update failed
        $checkStmt = $pdo->prepare("SELECT id, order_no, status, received_at FROM purchase_orders WHERE id = ? AND shop_owner_id = ? LIMIT 1");
        $checkStmt->execute([$orderId, $shopOwnerId]);
        $orderInfo = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$orderInfo) {
            http_response_code(404);
            echo json_encode([
                "status" => false,
                "message" => "Purchase order not found in your store records.",
                "data" => (object)[]
            ]);
            exit;
        }

        http_response_code(400);
        $reason = ($orderInfo["status"] === "received") 
            ? "Order '{$orderInfo['order_no']}' has already been received (on {$orderInfo['received_at']}). Receiving twice is not permitted."
            : "Order '{$orderInfo['order_no']}' is currently '{$orderInfo['status']}' and cannot be received.";

        echo json_encode([
            "status" => false,
            "message" => $reason,
            "data" => [
                "order_id" => (int)$orderInfo["id"],
                "order_no" => $orderInfo["order_no"],
                "current_status" => $orderInfo["status"]
            ]
        ]);
        exit;
    }

    // 3. Add stock only after order status update succeeds, in the same transaction (Rule 4)
    $itemsStmt = $pdo->prepare("
        SELECT poi.product_id, poi.quantity, p.name, p.sku, p.units
        FROM purchase_order_items poi
        INNER JOIN products p ON poi.product_id = p.id
        WHERE poi.purchase_order_id = ? AND p.shop_owner_id = ?
    ");
    $itemsStmt->execute([$orderId, $shopOwnerId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $updateStockStmt = $pdo->prepare("
        UPDATE products 
        SET units = units + ? 
        WHERE id = ? AND shop_owner_id = ?
    ");

    $updatedStockList = [];
    foreach ($items as $it) {
        $pid = (int)$it["product_id"];
        $qty = (int)$it["quantity"];
        $prevUnits = (int)$it["units"];
        $newUnits = $prevUnits + $qty;

        $updateStockStmt->execute([$qty, $pid, $shopOwnerId]);

        $updatedStockList[] = [
            "product_id" => $pid,
            "product_name" => $it["name"],
            "sku" => $it["sku"],
            "received_units" => $qty,
            "previous_stock" => $prevUnits,
            "new_stock" => $newUnits
        ];
    }

    $pdo->commit();

    // Fetch order details for response
    $oStmt = $pdo->prepare("SELECT order_no, received_at FROM purchase_orders WHERE id = ?");
    $oStmt->execute([$orderId]);
    $orderMeta = $oStmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Purchase order successfully marked as received. Stock quantities added to inventory.",
        "data" => [
            "order_id" => $orderId,
            "order_no" => $orderMeta["order_no"],
            "status" => "received",
            "received_at" => $orderMeta["received_at"],
            "total_products_updated" => count($updatedStockList),
            "updated_products" => $updatedStockList
        ]
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Receive purchase order error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "An internal server error occurred while processing order receipt.",
        "data" => (object)[]
    ]);
}
