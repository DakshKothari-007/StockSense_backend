<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" && $_SERVER["REQUEST_METHOD"] !== "DELETE") {
    header("Allow: POST, DELETE");
    http_response_code(405);
    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => "Method Not Allowed. Only POST or DELETE requests are allowed.",
        "data" => null
    ]);
    exit;
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../inventory/auth_helper.php";
require_once __DIR__ . "/../includes/AvatarCatalog.php";

$shopOwner = authenticateShopOwner($pdo);
$shopOwnerId = (int)$shopOwner["id"];

try {
    $updateStmt = $pdo->prepare(
        "UPDATE owner_account
         SET avatar_style = NULL, avatar_seed = NULL, avatar_options = NULL
         WHERE id = ?"
    );
    $updateStmt->execute([$shopOwnerId]);

    $fetchStmt = $pdo->prepare(
        "SELECT id, full_name, shop_name, location, store_type, currency, gstin, email,
                avatar_style, avatar_seed, avatar_options, created_at, updated_at
         FROM owner_account
         WHERE id = ?
         LIMIT 1"
    );
    $fetchStmt->execute([$shopOwnerId]);
    $row = $fetchStmt->fetch(PDO::FETCH_ASSOC);

    $userData = [
        "id" => (int)$row["id"],
        "store_id" => "GB-BLR-00" . $row["id"],
        "full_name" => $row["full_name"],
        "shop_name" => $row["shop_name"],
        "location" => $row["location"] ?? "",
        "store_type" => $row["store_type"] ?? "",
        "currency" => $row["currency"] ?? "",
        "gstin" => $row["gstin"] ?? null,
        "avatar" => null,
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
        "message" => "Avatar removed successfully.",
        "avatar" => null,
        "user" => $userData,
        "profile" => $userData,
        "data" => $userData
    ]);
} catch (PDOException $e) {
    error_log("Remove avatar error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => "A database error occurred while removing avatar.",
        "data" => null
    ]);
}
