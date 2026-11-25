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
        "success" => false,
        "message" => "Method Not Allowed. Only POST requests are allowed.",
        "data" => null
    ]);
    exit;
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../inventory/auth_helper.php";
require_once __DIR__ . "/../includes/AvatarCatalog.php";

$shopOwner = authenticateShopOwner($pdo);
$shopOwnerId = (int)$shopOwner["id"];

$rawBody = file_get_contents("php://input");
if (!is_string($rawBody) || trim($rawBody) === "") {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => "Request body cannot be empty.",
        "data" => null
    ]);
    exit;
}

// Guard against abnormally large bodies early (even before decoding deep options)
if (strlen($rawBody) > 4096) {
    http_response_code(422);
    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => "Avatar payload exceeds maximum allowed size.",
        "data" => null
    ]);
    exit;
}

$decoded = json_decode($rawBody, true);
if (!is_array($decoded)) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => "Invalid JSON payload.",
        "data" => null
    ]);
    exit;
}

// Support either flat {style, seed, options} or wrapped {avatar: {style, seed, options}}
if (array_key_exists("avatar", $decoded) && count($decoded) === 1) {
    $payload = $decoded["avatar"];
} else {
    $payload = $decoded;
}

$validation = avatar_validate_payload($payload);
if (!$validation["ok"]) {
    http_response_code(422);
    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => $validation["error"],
        "data" => null
    ]);
    exit;
}

try {
    $updateStmt = $pdo->prepare(
        "UPDATE owner_account
         SET avatar_style = ?, avatar_seed = ?, avatar_options = ?
         WHERE id = ?"
    );
    $updateStmt->execute([
        $validation["style"],
        $validation["seed"],
        $validation["options_json"],
        $shopOwnerId
    ]);

    $fetchStmt = $pdo->prepare(
        "SELECT id, full_name, shop_name, location, store_type, currency, gstin, email,
                avatar_style, avatar_seed, avatar_options, created_at, updated_at
         FROM owner_account
         WHERE id = ?
         LIMIT 1"
    );
    $fetchStmt->execute([$shopOwnerId]);
    $row = $fetchStmt->fetch(PDO::FETCH_ASSOC);

    $avatarCode = avatar_format_from_row($row);
    $userData = [
        "id" => (int)$row["id"],
        "store_id" => "GB-BLR-00" . $row["id"],
        "full_name" => $row["full_name"],
        "shop_name" => $row["shop_name"],
        "location" => $row["location"] ?? "",
        "store_type" => $row["store_type"] ?? "",
        "currency" => $row["currency"] ?? "",
        "gstin" => $row["gstin"] ?? null,
        "avatar" => $avatarCode,
        "email" => $row["email"],
        "role" => "Owner",
        "plan" => "StockSense Starter",
        "data_snapshot" => $row["updated_at"] ?? $row["created_at"] ?? "",
        "created_at" => $row["created_at"],
        "updated_at" => $row["updated_at"]
    ];

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "success" => true,
        "message" => "Avatar updated successfully.",
        "avatar" => $avatarCode,
        "user" => $userData,
        "profile" => $userData,
        "data" => $userData
    ]);
} catch (PDOException $e) {
    error_log("Update avatar error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => "A database error occurred while updating avatar.",
        "data" => null
    ]);
}
