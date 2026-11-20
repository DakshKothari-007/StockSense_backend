<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" && $_SERVER["REQUEST_METHOD"] !== "DELETE") {
    http_response_code(405);
    echo json_encode([
        "status" => false,
        "message" => "Method Not Allowed. Only POST and DELETE requests are allowed.",
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

$orderId = 0;
$orderNo = "";

if (is_array($data)) {
    $orderId = (int)($data["order_id"] ?? $data["id"] ?? 0);
    $orderNo = trim($data["order_no"] ?? "");
}

// Support query parameters for DELETE requests or fallback
if ($orderId <= 0 && $orderNo === "") {
    $orderId = (int)($_GET["order_id"] ?? $_GET["id"] ?? 0);
    $orderNo = trim($_GET["order_no"] ?? "");
}

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
    if ($orderId > 0) {
        $findStmt = $pdo->prepare("SELECT id, order_no, status, received_at FROM purchase_orders WHERE id = ? AND shop_owner_id = ? LIMIT 1");
        $findStmt->execute([$orderId, $shopOwnerId]);
    } else {
        $findStmt = $pdo->prepare("SELECT id, order_no, status, received_at FROM purchase_orders WHERE order_no = ? AND shop_owner_id = ? LIMIT 1");
        $findStmt->execute([$orderNo, $shopOwnerId]);
    }
    $orderInfo = $findStmt->fetch(PDO::FETCH_ASSOC);

    if (!$orderInfo) {
        http_response_code(404);
        echo json_encode([
            "status" => false,
            "message" => "Purchase order not found in your store records.",
            "data" => (object)[]
        ]);
        exit;
    }

    $orderId = (int)$orderInfo["id"];
    $orderNo = $orderInfo["order_no"];
    $status = strtolower($orderInfo["status"] ?? "");
    $receivedAt = $orderInfo["received_at"];

    // Rule: Open purchase orders allow delete only if nothing has been received on them.
    // Partly received or received orders: never delete. Cancel instead. Stock stays correct.
    $hasReceivedItems = (
        $status === "received" ||
        $status === "partially_received" ||
        !empty($receivedAt)
    );

    if ($hasReceivedItems) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Cannot delete purchase order with received items. Cancel the order instead to keep stock correct.",
            "data" => [
                "order_id" => $orderId,
                "order_no" => $orderNo,
                "current_status" => $orderInfo["status"]
            ]
        ]);
        exit;
    }

    // Nothing was received: safely delete order items and purchase order record
    $pdo->beginTransaction();

    $delItems = $pdo->prepare("DELETE FROM purchase_order_items WHERE purchase_order_id = ?");
    $delItems->execute([$orderId]);

    $delOrder = $pdo->prepare("DELETE FROM purchase_orders WHERE id = ? AND shop_owner_id = ?");
    $delOrder->execute([$orderId, $shopOwnerId]);

    $pdo->commit();

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Purchase order '{$orderNo}' successfully deleted.",
        "data" => [
            "order_id" => $orderId,
            "order_no" => $orderNo
        ]
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Delete purchase order error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "An internal server error occurred while deleting the order.",
        "data" => (object)[]
    ]);
}
