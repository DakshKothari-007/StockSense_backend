<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    header("Allow: GET");
    http_response_code(405);
    echo json_encode([
        "status" => false,
        "message" => "Method not allowed. Only GET requests are accepted.",
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
    $result = get_shop_notifications($pdo, $shopOwnerId);

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Unread count retrieved successfully",
        "data" => [
            "unread_count" => $result['unread_count']
        ]
    ]);

} catch (Throwable $e) {
    error_log("Notifications unread count error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "An internal server error occurred while retrieving unread count.",
        "data" => null
    ]);
}
?>
