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
    if ($orderId <= 0) {
        $findStmt = $pdo->prepare("SELECT id FROM purchase_orders WHERE order_no = ? AND shop_owner_id = ? LIMIT 1");
        $findStmt->execute([$orderNo, $shopOwnerId]);
        $orderId = (int)$findStmt->fetchColumn();
    }

    // Cancel open orders only (status = 'placed')
    $cancelStmt = $pdo->prepare("
        UPDATE purchase_orders 
        SET status = 'cancelled' 
        WHERE id = ? AND shop_owner_id = ? AND status = 'placed'
    ");
    $cancelStmt->execute([$orderId, $shopOwnerId]);

    if ($cancelStmt->rowCount() === 0) {
        $checkStmt = $pdo->prepare("SELECT id, order_no, status FROM purchase_orders WHERE id = ? AND shop_owner_id = ? LIMIT 1");
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
        echo json_encode([
            "status" => false,
            "message" => "Order '{$orderInfo['order_no']}' cannot be cancelled because it is already '{$orderInfo['status']}'.",
            "data" => [
                "order_id" => (int)$orderInfo["id"],
                "order_no" => $orderInfo["order_no"],
                "current_status" => $orderInfo["status"]
            ]
        ]);
        exit;
    }

    $oStmt = $pdo->prepare("SELECT order_no FROM purchase_orders WHERE id = ?");
    $oStmt->execute([$orderId]);
    $orderNoVal = $oStmt->fetchColumn();

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Purchase order successfully cancelled.",
        "data" => [
            "order_id" => $orderId,
            "order_no" => $orderNoVal,
            "status" => "cancelled"
        ]
    ]);

} catch (Throwable $e) {
    error_log("Cancel purchase order error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Internal error cancelling order: " . $e->getMessage(),
        "data" => (object)[]
    ]);
}
