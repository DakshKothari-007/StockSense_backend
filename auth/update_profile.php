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
$fullName = trim($data["full_name"] ?? $data["name"] ?? "");
$currentEmail = strtolower(trim($data["current_email"] ?? ""));

// Identify new email:
// If new_email is explicitly provided, use it.
// If not, check if 'email' was passed.
$newEmail = "";
if (!empty($data["new_email"])) {
    $newEmail = strtolower(trim($data["new_email"]));
} elseif (!empty($data["email"])) {
    $newEmail = strtolower(trim($data["email"]));
}

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

if ($userId <= 0 && empty($currentEmail) && empty($newEmail)) {
    http_response_code(401);
    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => "User ID, current email, or valid authentication token is required."
    ]);
    exit;
}

if (empty($fullName) && empty($newEmail)) {
    http_response_code(422);
    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => "Full name or email address is required to update profile."
    ]);
    exit;
}

if (!empty($newEmail) && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => "A valid email address is required."
    ]);
    exit;
}

if (strlen($fullName) > 100 || strlen($newEmail) > 150) {
    http_response_code(422);
    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => "Full name (max 100) or email (max 150) exceeds the allowed length."
    ]);
    exit;
}

try {
    // 1. Identify the user
    if ($userId > 0) {
        $stmt = $pdo->prepare("SELECT id, full_name, email FROM owner_account WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
    } elseif (!empty($currentEmail)) {
        $stmt = $pdo->prepare("SELECT id, full_name, email FROM owner_account WHERE email = ? LIMIT 1");
        $stmt->execute([$currentEmail]);
    } else {
        $stmt = $pdo->prepare("SELECT id, full_name, email FROM owner_account WHERE email = ? LIMIT 1");
        $stmt->execute([$newEmail]);
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
    $finalFullName = !empty($fullName) ? $fullName : $existing["full_name"];
    $finalEmail = !empty($newEmail) ? $newEmail : $existing["email"];

    // 2. Check if the new email belongs to another account
    if (strtolower($finalEmail) !== strtolower($existing["email"])) {
        $dupCheck = $pdo->prepare("SELECT id FROM owner_account WHERE email = ? AND id != ? LIMIT 1");
        $dupCheck->execute([$finalEmail, $targetId]);
        if ($dupCheck->fetch()) {
            http_response_code(409);
            echo json_encode([
                "status" => false,
                "success" => false,
                "message" => "This email address is already in use by another account."
            ]);
            exit;
        }
    }

    // 3. Update the record
    $updateStmt = $pdo->prepare(
        "UPDATE owner_account
         SET full_name = ?, email = ?
         WHERE id = ?"
    );
    $updateStmt->execute([$finalFullName, $finalEmail, $targetId]);

    // 4. Return updated profile
    $getUpdated = $pdo->prepare("SELECT id, full_name, shop_name, location, store_type, currency, email, updated_at FROM owner_account WHERE id = ?");
    $getUpdated->execute([$targetId]);
    $updatedUser = $getUpdated->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => true,
        "success" => true,
        "message" => "Profile updated successfully.",
        "profile" => $updatedUser,
        "user" => $updatedUser,
        "data" => $updatedUser
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
