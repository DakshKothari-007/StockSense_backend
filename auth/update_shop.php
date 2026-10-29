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
        "success" => false,
        "message" => "Only POST requests are allowed."
    ]);
    exit;
}

require_once __DIR__ . "/../config/db.php";

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => "Invalid JSON payload."
    ]);
    exit;
}

// 1. Check Bearer token from headers or JSON payload
$authHeader = '';
if (function_exists('getallheaders')) {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}
if (empty($authHeader)) {
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
}

$token = null;
if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
    $token = trim($matches[1]);
}
if (empty($token) && !empty($data['token'])) {
    $token = trim($data['token']);
}

$userId = (int)($data["user_id"] ?? $data["id"] ?? 0);
$email = strtolower(trim($data["email"] ?? $data["current_email"] ?? ""));
$shopName = trim($data["shop_name"] ?? $data["name"] ?? "");
$location = isset($data["location"]) ? trim($data["location"]) : null;
$storeType = isset($data["store_type"]) ? trim($data["store_type"]) : null;
$currency = isset($data["currency"]) ? trim($data["currency"]) : null;

// Resolve user from token if available
if ($userId <= 0 && $token) {
    $tokenHash = hash('sha256', $token);
    $tokenStmt = $pdo->prepare("
        SELECT user_id FROM tokens
        WHERE token_hash = ? AND expires_at > NOW()
        LIMIT 1
    ");
    $tokenStmt->execute([$tokenHash]);
    $tokenUserId = (int)$tokenStmt->fetchColumn();
    if ($tokenUserId > 0) {
        $userId = $tokenUserId;
    }
}

if (empty($shopName)) {
    http_response_code(422);
    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => "Shop name is required."
    ]);
    exit;
}

if ($userId <= 0 && empty($email)) {
    http_response_code(401);
    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => "User ID, email, or valid authentication token is required."
    ]);
    exit;
}

if (strlen($shopName) > 150) {
    http_response_code(422);
    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => "Shop name cannot exceed 150 characters."
    ]);
    exit;
}

try {
    if ($userId > 0) {
        $stmt = $pdo->prepare("SELECT id, shop_name, location, store_type, currency, email FROM owner_account WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
    } else {
        $stmt = $pdo->prepare("SELECT id, shop_name, location, store_type, currency, email FROM owner_account WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
    }

    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        http_response_code(404);
        echo json_encode([
            "status" => false,
            "success" => false,
            "message" => "Account not found."
        ]);
        exit;
    }

    $targetId = (int)$existing["id"];

    // Build update parameters
    $fields = ["shop_name = ?"];
    $params = [$shopName];

    if ($location !== null && $location !== "") {
        $fields[] = "location = ?";
        $params[] = $location;
    }
    if ($storeType !== null && $storeType !== "") {
        $fields[] = "store_type = ?";
        $params[] = $storeType;
    }
    if ($currency !== null && $currency !== "") {
        $fields[] = "currency = ?";
        $params[] = $currency;
    }

    $params[] = $targetId;

    $updateSql = "UPDATE owner_account SET " . implode(", ", $fields) . " WHERE id = ?";
    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute($params);

    $fetchStmt = $pdo->prepare("SELECT id, full_name, shop_name, location, store_type, currency, email, updated_at FROM owner_account WHERE id = ?");
    $fetchStmt->execute([$targetId]);
    $shop = $fetchStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => true,
        "success" => true,
        "message" => "Shop details updated successfully.",
        "shop" => $shop,
        "profile" => $shop,
        "user" => $shop,
        "data" => $shop
    ]);

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => "Database error occurred."
    ]);
}
?>
