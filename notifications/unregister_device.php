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
        "message" => "Method Not Allowed. Only POST requests are accepted.",
        "data" => (object)[]
    ]);
    exit;
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../inventory/auth_helper.php";

$owner = authenticateShopOwner($pdo);
$shopId = (int)$owner["id"];

$raw = file_get_contents("php://input");
$input = json_decode($raw, true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Invalid JSON payload.",
        "data" => (object)[]
    ]);
    exit;
}

$fcmToken = trim($input["fcm_token"] ?? "");
if ($fcmToken === "") {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "fcm_token is required.",
        "data" => (object)[]
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM device_tokens WHERE fcm_token = ? AND shop_id = ?");
    $stmt->execute([$fcmToken, $shopId]);

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Device token unregistered successfully.",
        "data" => null
    ]);

} catch (Throwable $e) {
    error_log("unregister_device error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Failed to unregister device token.",
        "data" => (object)[]
    ]);
}

