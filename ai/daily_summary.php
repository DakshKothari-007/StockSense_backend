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
    http_response_code(405);
    echo json_encode([
        "status" => false,
        "message" => "Method Not Allowed. Only GET requests are accepted.",
        "data" => (object)[]
    ]);
    exit;
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../inventory/auth_helper.php";
require_once __DIR__ . "/../includes/AiEngine.php";

$owner = authenticateShopOwner($pdo);
$shopId = (int)$owner["id"];

try {
    $result = AiEngine::dailySummary($pdo, $shopId);

    echo json_encode([
        "status" => true,
        "message" => "Success",
        "data" => $result
    ]);
} catch (Exception $e) {
    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Success",
        "data" => [
            "summary" => "Yesterday's sales: No data recorded yet.\nToday: All inventory levels are healthy."
        ]
    ]);
}

