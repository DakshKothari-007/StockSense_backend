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

$offerIdRaw = $input["offer_id"] ?? ($input["id"] ?? null);
if ($offerIdRaw === null || !is_numeric($offerIdRaw) || (int)$offerIdRaw <= 0) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "offer_id is required and must be a positive integer.",
        "data" => (object)[]
    ]);
    exit;
}
$offerId = (int)$offerIdRaw;

try {
    $checkStmt = $pdo->prepare("
        SELECT id, status, ended_at
        FROM bundle_offers
        WHERE id = ? AND shop_owner_id = ?
        LIMIT 1
    ");
    $checkStmt->execute([$offerId, $shopOwnerId]);
    $offer = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$offer) {
        http_response_code(404);
        echo json_encode([
            "status" => false,
            "message" => "Offer not found.",
            "data" => (object)[]
        ]);
        exit;
    }

    if ($offer["status"] === "active") {
        $updStmt = $pdo->prepare("
            UPDATE bundle_offers
            SET status = 'ended', ended_at = NOW()
            WHERE id = ? AND shop_owner_id = ? AND status = 'active'
        ");
        $updStmt->execute([$offerId, $shopOwnerId]);
    }

    $fetchStmt = $pdo->prepare("
        SELECT id, status, ended_at
        FROM bundle_offers
        WHERE id = ? AND shop_owner_id = ?
        LIMIT 1
    ");
    $fetchStmt->execute([$offerId, $shopOwnerId]);
    $updated = $fetchStmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Bundle offer ended.",
        "data" => [
            "id" => (int)$updated["id"],
            "status" => $updated["status"],
            "ended_at" => $updated["ended_at"]
        ]
    ]);
} catch (Throwable $e) {
    error_log("Offers end error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "An internal server error occurred while ending the bundle offer.",
        "data" => (object)[]
    ]);
}
