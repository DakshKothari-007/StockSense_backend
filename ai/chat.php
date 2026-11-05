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

$message = trim($input["message"] ?? "");
if ($message === "") {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Field 'message' is required.",
        "data" => (object)[]
    ]);
    exit;
}

$languageHint = isset($input["language_hint"]) ? trim((string)$input["language_hint"]) : null;
$history = isset($input["history"]) && is_array($input["history"]) ? $input["history"] : [];

try {
    $result = AiEngine::chat($pdo, $shopId, $message, $languageHint, $history);

    echo json_encode([
        "status" => true,
        "message" => "Success",
        "data" => $result
    ]);
} catch (Exception $e) {
    if ($e->getCode() === 429) {
        http_response_code(429);
        echo json_encode([
            "status" => false,
            "message" => $e->getMessage(),
            "data" => (object)[]
        ]);
        exit;
    }

    // Never return HTTP 500 for AI chat - serve fallback
    $fallback = AiEngine::executeRuleBasedFallback($pdo, $shopId, $message, $history, $languageHint);
    echo json_encode([
        "status" => true,
        "message" => "Success",
        "data" => $fallback
    ]);
}

