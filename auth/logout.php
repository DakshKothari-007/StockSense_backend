<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../config/db.php";

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

if (!$token) {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    if (is_array($data) && !empty($data['token'])) {
        $token = trim($data['token']);
    }
}

if ($token) {
    $tokenHash = hash('sha256', $token);
    try {
        $stmt = $pdo->prepare("DELETE FROM tokens WHERE token_hash = ?");
        $stmt->execute([$tokenHash]);
    } catch (Throwable $e) {
        error_log("Logout token delete error: " . $e->getMessage());
    }
}

http_response_code(200);
echo json_encode([
    "success" => true,
    "status" => true,
    "message" => "Signed out successfully. Token revoked."
]);
