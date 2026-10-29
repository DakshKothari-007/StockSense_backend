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
    $type = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
    $unreadOnly = isset($_GET['unread_only']) ? filter_var($_GET['unread_only'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : false;
    $limit = isset($_GET['limit']) ? min(max((int)$_GET['limit'], 1), 50) : 50;
    $page = isset($_GET['page']) ? max((int)$_GET['page'], 1) : 1;

    $result = get_shop_notifications($pdo, $shopOwnerId, [
        'type' => $type,
        'unread_only' => $unreadOnly ?? false,
        'limit' => $limit,
        'page' => $page
    ]);

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Notifications retrieved successfully",
        "data" => [
            "unread_count" => $result['unread_count'],
            "total_count" => $result['total_count'],
            "page" => $page,
            "limit" => $limit,
            "items" => $result['items']
        ]
    ]);

} catch (Throwable $e) {
    error_log("Notifications list error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "An internal server error occurred while retrieving notifications.",
        "data" => null
    ]);
}
?>
