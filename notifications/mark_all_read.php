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
require_once __DIR__ . "/notifications_helper.php";

$shopOwner = authenticateShopOwner($pdo);
$shopOwnerId = (int)$shopOwner["id"];

try {
    $notifications = get_shop_notifications($pdo, $shopOwnerId);
    $items = $notifications['items'] ?? [];

    if (!empty($items)) {
        $stmt = $pdo->prepare(
            "INSERT INTO notification_states (shop_owner_id, notification_key, read_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE read_at = IFNULL(read_at, NOW())"
        );
        foreach ($items as $item) {
            $stmt->execute([$shopOwnerId, $item['key']]);
        }
    }

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "All active notifications marked as read",
        "data" => [
            "updated_count" => count($items)
        ]
    ]);

} catch (Throwable $e) {
    error_log("Mark all notifications read error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "An internal server error occurred while marking all notifications as read.",
        "data" => null
    ]);
}
?>
