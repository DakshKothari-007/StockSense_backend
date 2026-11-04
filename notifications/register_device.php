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
$userId = (int)$owner["id"];

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

if (strlen($fcmToken) > 255) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "fcm_token exceeds maximum allowed length of 255 characters.",
        "data" => (object)[]
    ]);
    exit;
}

$platform = trim($input["platform"] ?? "android");
if ($platform === "") {
    $platform = "android";
}

try {
    // Insert or update. If token already exists (even for another shop/user), move it to current shop/user.
    $stmt = $pdo->prepare("
        INSERT INTO device_tokens (shop_id, user_id, fcm_token, platform, last_seen_at)
        VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            shop_id = VALUES(shop_id),
            user_id = VALUES(user_id),
            platform = VALUES(platform),
            last_seen_at = NOW()
    ");
    $stmt->execute([$shopId, $userId, $fcmToken, $platform]);

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Device token registered successfully.",
        "data" => [
            "shop_id" => $shopId,
            "fcm_token" => $fcmToken,
            "platform" => $platform
        ]
    ]);

} catch (Throwable $e) {
    error_log("register_device error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Failed to register device token.",
        "data" => (object)[]
    ]);
}

