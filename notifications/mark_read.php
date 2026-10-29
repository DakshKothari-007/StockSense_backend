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
    header("Allow: POST");
    http_response_code(405);
    echo json_encode([
        "status" => false,
        "message" => "Method not allowed. Only POST requests are accepted.",
        "data" => null
    ]);
    exit;
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../inventory/auth_helper.php";

$shopOwner = authenticateShopOwner($pdo);
$shopOwnerId = (int)$shopOwner["id"];

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Invalid JSON payload.",
        "data" => null
    ]);
    exit;
}

$key = trim($data["key"] ?? "");
if ($key === "") {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Notification 'key' is required.",
        "data" => null
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "INSERT INTO notification_states (shop_owner_id, notification_key, read_at)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE read_at = IFNULL(read_at, NOW())"
    );
    $stmt->execute([$shopOwnerId, $key]);

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Notification marked as read",
        "data" => [
            "key" => $key,
            "is_read" => true
        ]
    ]);

} catch (Throwable $e) {
    error_log("Mark notification read error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "An internal server error occurred while marking notification as read.",
        "data" => null
    ]);
}
?>
