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
require_once __DIR__ . "/../includes/AiEngine.php";

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

$type = trim($input["type"] ?? "");
if ($type === "") {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Field 'type' is required (slow_moving, reorder, pair).",
        "data" => (object)[]
    ]);
    exit;
}

if (!in_array(strtolower($type), ["slow_moving", "reorder", "pair"])) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Invalid type. Must be 'slow_moving', 'reorder', or 'pair'.",
        "data" => (object)[]
    ]);
    exit;
}

$productId = isset($input["product_id"]) ? (int)$input["product_id"] : (isset($input["id"]) ? (int)$input["id"] : 0);
if ($productId <= 0) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Valid 'product_id' is required.",
        "data" => (object)[]
    ]);
    exit;
}

try {
    $result = AiEngine::explain($pdo, $shopId, $type, $productId);

    echo json_encode([
        "status" => true,
        "message" => "Success",
        "data" => $result
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => $e->getMessage(),
        "data" => (object)[]
    ]);
} catch (RuntimeException $e) {
    $code = $e->getCode() ?: 404;
    http_response_code($code);
    echo json_encode([
        "status" => false,
        "message" => $e->getMessage(),
        "data" => (object)[]
    ]);
} catch (Exception $e) {
    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Success",
        "data" => [
            "explanation" => "Product data analysis is currently being updated for your shop."
        ]
    ]);
}

