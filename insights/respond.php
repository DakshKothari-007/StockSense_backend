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
        "message" => "Method Not Allowed. Only POST requests are allowed.",
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

$rawInput = file_get_contents("php://input");
$input = json_decode($rawInput, true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Invalid JSON payload.",
        "data" => (object)[]
    ]);
    exit;
}

$recType = trim($input["recommendation_type"] ?? "");
$action = strtolower(trim($input["action"] ?? ""));
$notes = isset($input["notes"]) ? trim($input["notes"]) : null;

$allowedTypes = ["slow_moving", "product_pair", "reorder"];
if (!in_array($recType, $allowedTypes, true)) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Invalid recommendation_type. Must be one of: " . implode(", ", $allowedTypes) . ".",
        "data" => (object)[]
    ]);
    exit;
}

$allowedActions = ["accepted", "dismissed"];
if (!in_array($action, $allowedActions, true)) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Invalid action. Must be 'accepted' or 'dismissed'.",
        "data" => (object)[]
    ]);
    exit;
}

if (!isset($input["product_id_1"]) || !is_numeric($input["product_id_1"]) || (int)$input["product_id_1"] <= 0) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "product_id_1 is required and must be a positive integer.",
        "data" => (object)[]
    ]);
    exit;
}

$p1Input = (int)$input["product_id_1"];
$p2Input = isset($input["product_id_2"]) && is_numeric($input["product_id_2"]) ? (int)$input["product_id_2"] : 0;

try {
    // Validate product 1 exists and belongs to this shop
    $checkP1 = $pdo->prepare("SELECT id FROM products WHERE id = ? AND shop_owner_id = ?");
    $checkP1->execute([$p1Input, $shopOwnerId]);
    if (!$checkP1->fetch()) {
        http_response_code(404);
        echo json_encode([
            "status" => false,
            "message" => "Product ID {$p1Input} not found or does not belong to your shop.",
            "data" => (object)[]
        ]);
        exit;
    }

    $finalP1 = $p1Input;
    $finalP2 = 0;

    if ($recType === "product_pair") {
        if ($p2Input <= 0) {
            http_response_code(400);
            echo json_encode([
                "status" => false,
                "message" => "product_id_2 is required and must be a positive integer for product_pair recommendations.",
                "data" => (object)[]
            ]);
            exit;
        }

        if ($p1Input === $p2Input) {
            http_response_code(400);
            echo json_encode([
                "status" => false,
                "message" => "product_id_1 and product_id_2 cannot be the same product.",
                "data" => (object)[]
            ]);
            exit;
        }

        // Validate product 2 exists and belongs to this shop
        $checkP2 = $pdo->prepare("SELECT id FROM products WHERE id = ? AND shop_owner_id = ?");
        $checkP2->execute([$p2Input, $shopOwnerId]);
        if (!$checkP2->fetch()) {
            http_response_code(404);
            echo json_encode([
                "status" => false,
                "message" => "Product ID {$p2Input} not found or does not belong to your shop.",
                "data" => (object)[]
            ]);
            exit;
        }

        // Rule 2: Always store smaller product id first
        $finalP1 = min($p1Input, $p2Input);
        $finalP2 = max($p1Input, $p2Input);
    } else {
        // Non-pair recommendations always have product_id_2 = 0
        $finalP2 = 0;
    }

    // Upsert into recommendation_responses (latest response wins)
    $upsertStmt = $pdo->prepare("
        INSERT INTO recommendation_responses
            (shop_owner_id, recommendation_type, product_id_1, product_id_2, action, notes, created_at, updated_at)
        VALUES
            (?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            action = VALUES(action),
            notes = VALUES(notes),
            updated_at = NOW()
    ");
    $upsertStmt->execute([
        $shopOwnerId,
        $recType,
        $finalP1,
        $finalP2,
        $action,
        $notes
    ]);

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Recommendation response recorded successfully.",
        "data" => [
            "recommendation_type" => $recType,
            "product_id_1" => $finalP1,
            "product_id_2" => $finalP2,
            "action" => $action,
            "notes" => $notes,
            "pair_id" => ($recType === "product_pair") ? "pair_{$finalP1}_{$finalP2}" : null
        ]
    ]);

} catch (Exception $e) {
    error_log("Insights respond error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "An internal server error occurred while saving recommendation response.",
        "data" => (object)[]
    ]);
}
