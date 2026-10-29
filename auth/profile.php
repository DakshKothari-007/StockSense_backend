<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET" && $_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => "Only GET or POST requests are allowed."
    ]);
    exit;
}

require_once __DIR__ . "/../config/db.php";

$data = [];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true) ?: [];
}

// 1. Check Bearer token from headers, POST data, or GET query
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
if (empty($token) && !empty($_GET['token'])) {
    $token = trim($_GET['token']);
}

$email = strtolower(trim($_GET["email"] ?? $data["email"] ?? ""));
$userId = (int)($_GET["id"] ?? $_GET["user_id"] ?? $data["id"] ?? $data["user_id"] ?? 0);

if ($userId <= 0 && empty($email) && $token) {
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

if ($userId <= 0 && empty($email)) {
    http_response_code(401);
    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => "Unauthorized: Authentication token, user ID or email is required."
    ]);
    exit;
}

if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => "Please enter a valid email address."
    ]);
    exit;
}

try {
    if ($userId > 0) {
        $stmt = $pdo->prepare(
            "SELECT id, full_name, shop_name, location, store_type, currency, email, created_at, updated_at
             FROM owner_account
             WHERE id = ?
             LIMIT 1"
        );
        $stmt->execute([$userId]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, full_name, shop_name, location, store_type, currency, email, created_at, updated_at
             FROM owner_account
             WHERE email = ?
             LIMIT 1"
        );
        $stmt->execute([$email]);
    }

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            "status" => false,
            "success" => false,
            "message" => "Account not found."
        ]);
        exit;
    }

    $userData = [
        "id" => (int) $user["id"],
        "full_name" => $user["full_name"],
        "shop_name" => $user["shop_name"],
        "location" => $user["location"] ?? "",
        "store_type" => $user["store_type"] ?? "",
        "currency" => $user["currency"] ?? "",
        "email" => $user["email"],
        "created_at" => $user["created_at"],
        "updated_at" => $user["updated_at"]
    ];

    echo json_encode([
        "status" => true,
        "success" => true,
        "message" => "Profile retrieved successfully.",
        "user" => $userData,
        "profile" => $userData,
        "data" => $userData
    ]);

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => "A database error occurred."
    ]);
}
?>
