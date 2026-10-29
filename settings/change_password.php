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
require_once __DIR__ . "/settings_helper.php";

$pdo->exec("SET time_zone = '+05:30'");
$owner = authenticateShopOwner($pdo);
$shopOwnerId = (int)$owner["id"];

// Extract current session token hash to keep only this session active
$authHeader = '';
if (function_exists('getallheaders')) {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}
if (empty($authHeader)) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
}
$token = '';
if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
    $token = trim($matches[1]);
}
$currentTokenHash = hash('sha256', $token);

$rawInput = file_get_contents("php://input");

// Rule 7: Reject empty body
if (trim($rawInput) === "") {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Request body cannot be empty.",
        "data" => (object)[]
    ]);
    exit;
}

$input = json_decode($rawInput, true);
if (!is_array($input) || empty($input)) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Request body cannot be empty and must be valid JSON.",
        "data" => (object)[]
    ]);
    exit;
}

// Reject unknown keys
$allowedKeys = ["current_password", "new_password", "confirm_password"];
foreach ($input as $k => $v) {
    if (!in_array($k, $allowedKeys, true)) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Unknown parameter '{$k}'. Only current_password and new_password are accepted.",
            "data" => (object)[]
        ]);
        exit;
    }
}

$currentPassword = $input["current_password"] ?? null;
$newPassword = $input["new_password"] ?? null;

// Rule 7: Strict types
if (!is_string($currentPassword) || trim($currentPassword) === "") {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "current_password is required and must be a string.",
        "data" => (object)[]
    ]);
    exit;
}

if (!is_string($newPassword) || trim($newPassword) === "") {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "new_password is required and must be a string.",
        "data" => (object)[]
    ]);
    exit;
}

// Check if confirm_password was passed and matches
if (isset($input["confirm_password"])) {
    if (!is_string($input["confirm_password"]) || $input["confirm_password"] !== $newPassword) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "new_password and confirm_password do not match.",
            "data" => (object)[]
        ]);
        exit;
    }
}

try {
    // Rule 7: Limit failed current-password attempts
    $settings = get_shop_settings($pdo, $shopOwnerId);
    $lockedUntil = $settings["password_locked_until"];

    if (!empty($lockedUntil) && strtotime($lockedUntil) > time()) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Password change is temporarily locked due to too many failed attempts. Please try again after {$lockedUntil}.",
            "data" => [
                "locked_until" => $lockedUntil
            ]
        ]);
        exit;
    }

    // Fetch existing password hash
    $stmt = $pdo->prepare("SELECT password FROM owner_account WHERE id = ? LIMIT 1");
    $stmt->execute([$shopOwnerId]);
    $storedHash = $stmt->fetchColumn();

    if (!$storedHash || !password_verify($currentPassword, $storedHash)) {
        // Record failed attempt
        $attempts = ($settings["failed_password_attempts"] ?? 0) + 1;
        $newLock = null;
        if ($attempts >= 5) {
            $newLock = date('Y-m-d H:i:s', time() + 900); // 15-minute lock
        }

        $lockStmt = $pdo->prepare("
            INSERT INTO user_settings (user_id, failed_password_attempts, password_locked_until, updated_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                failed_password_attempts = VALUES(failed_password_attempts),
                password_locked_until = VALUES(password_locked_until),
                updated_at = NOW()
        ");
        $lockStmt->execute([$shopOwnerId, $attempts, $newLock]);

        http_response_code(400);
        $msg = $attempts >= 5
            ? "Current password is incorrect. Account temporarily locked for 15 minutes."
            : "Current password is incorrect.";
        echo json_encode([
            "status" => false,
            "message" => $msg,
            "data" => [
                "failed_attempts" => $attempts,
                "remaining_attempts" => max(0, 5 - $attempts)
            ]
        ]);
        exit;
    }

    // Rule 7: New password must differ from current password
    if ($newPassword === $currentPassword || password_verify($newPassword, $storedHash)) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "New password must be different from your current password.",
            "data" => (object)[]
        ]);
        exit;
    }

    // Enforce min 8 characters
    if (strlen($newPassword) < 8) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "New password must be at least 8 characters long.",
            "data" => (object)[]
        ]);
        exit;
    }

    // Success transaction
    $pdo->beginTransaction();

    // 1. Update password
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $updateStmt = $pdo->prepare("UPDATE owner_account SET password = ?, updated_at = NOW() WHERE id = ?");
    $updateStmt->execute([$newHash, $shopOwnerId]);

    // 2. Reset failed attempts
    $resetAttempts = $pdo->prepare("
        INSERT INTO user_settings (user_id, failed_password_attempts, password_locked_until, updated_at)
        VALUES (?, 0, NULL, NOW())
        ON DUPLICATE KEY UPDATE
            failed_password_attempts = 0,
            password_locked_until = NULL,
            updated_at = NOW()
    ");
    $resetAttempts->execute([$shopOwnerId]);

    // 3. Delete all OTHER tokens of this user and keep the current one
    $revokeStmt = $pdo->prepare("DELETE FROM tokens WHERE user_id = ? AND token_hash != ?");
    $revokeStmt->execute([$shopOwnerId, $currentTokenHash]);
    $revokedCount = $revokeStmt->rowCount();

    $pdo->commit();

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Password changed successfully. All other active sessions have been revoked.",
        "data" => [
            "user_id" => $shopOwnerId,
            "revoked_other_sessions_count" => $revokedCount,
            "current_session_kept" => true
        ]
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Settings change_password error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "An internal server error occurred while changing password.",
        "data" => (object)[]
    ]);
}
