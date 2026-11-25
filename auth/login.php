<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// Allow only POST requests.
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");
    http_response_code(405);

    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => "Only POST requests are allowed."
    ]);
    exit;
}

// Connect to the database.
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/AvatarCatalog.php";

// Constants for lockout and rate limiting
const LOGIN_LOCKOUT_SECONDS = 300; // 5 minutes
const MAX_LOGIN_ATTEMPTS = 5;
const UNKNOWN_EMAIL_MAX_ATTEMPTS = 20;
const UNKNOWN_EMAIL_WINDOW_MINUTES = 15;

// Read the JSON request.
$data = json_decode(file_get_contents("php://input"), true);

// Check whether valid JSON was received.
if (!is_array($data)) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => "Invalid JSON request."
    ]);
    exit;
}

// Read the login fields.
$email = strtolower(trim($data["email"] ?? ""));
$password = $data["password"] ?? "";

// Validate the inputs.
if (
    $email === "" ||
    !filter_var($email, FILTER_VALIDATE_EMAIL) ||
    !is_string($password) ||
    $password === ""
) {
    http_response_code(422);

    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => "Enter a valid email address and password."
    ]);
    exit;
}

try {
    $ipAddress = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (strpos($ipAddress, ',') !== false) {
        $parts = explode(',', $ipAddress);
        $ipAddress = trim($parts[0]);
    }

    // 1. Check if account is currently locked out (Max 5 failed attempts in the last 5 minutes)
    $checkLockStmt = $pdo->prepare(
        "SELECT COUNT(*) as attempt_count,
                MAX(attempted_at) as latest_attempt,
                TIMESTAMPDIFF(SECOND, MAX(attempted_at), NOW()) as elapsed_seconds
         FROM login_attempts 
         WHERE identifier = ?
           AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)"
    );
    $checkLockStmt->execute([$email, LOGIN_LOCKOUT_SECONDS]);
    $lockData = $checkLockStmt->fetch(PDO::FETCH_ASSOC);
    $failedCount = (int) ($lockData['attempt_count'] ?? 0);

    if ($failedCount >= MAX_LOGIN_ATTEMPTS) {
        $elapsed = (int) ($lockData['elapsed_seconds'] ?? 0);
        $retryAfterSeconds = max(1, LOGIN_LOCKOUT_SECONDS - $elapsed);

        http_response_code(429);
        echo json_encode([
            "status" => false,
            "success" => false,
            "error_code" => "ACCOUNT_LOCKED",
            "message" => "Too many failed login attempts. Account locked for 5 minutes.",
            "retry_after_seconds" => $retryAfterSeconds
        ]);
        exit;
    }

    // 2. Find the account using the email address.
    $stmt = $pdo->prepare(
        "SELECT id, full_name, shop_name, location, store_type, currency, gstin,
                avatar_style, avatar_seed, avatar_options, email, password, created_at, updated_at
         FROM owner_account
         WHERE email = ?
         LIMIT 1"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // If account does not exist (unknown email):
    if (!$user) {
        // Enforce IP-based rate limit for unknown email attempts (20 attempts per 15 minutes)
        $checkIpRateStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM rate_limits
             WHERE action = 'unknown_email'
               AND ip_address = ?
               AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        $checkIpRateStmt->execute([$ipAddress, UNKNOWN_EMAIL_WINDOW_MINUTES]);
        $unknownAttempts = (int) $checkIpRateStmt->fetchColumn();

        if ($unknownAttempts >= UNKNOWN_EMAIL_MAX_ATTEMPTS) {
            http_response_code(429);
            echo json_encode([
                "status" => false,
                "success" => false,
                "error_code" => "RATE_LIMIT_EXCEEDED",
                "message" => "Too many failed attempts from this IP address. Please try again after 15 minutes."
            ]);
            exit;
        }

        // Record unknown email attempt against IP only (Do NOT count against account lockout)
        $logIpStmt = $pdo->prepare(
            "INSERT INTO rate_limits (action, identifier, ip_address, attempted_at) VALUES ('unknown_email', ?, ?, NOW())"
        );
        $logIpStmt->execute([$email, $ipAddress]);

        http_response_code(401);
        echo json_encode([
            "status" => false,
            "success" => false,
            "error_code" => "EMAIL_NOT_FOUND",
            "message" => "No account found with this email."
        ]);
        exit;
    }

    // 3. Check the password against the stored hash.
    if (!password_verify($password, $user["password"])) {
        // Record failed attempt for this account
        $logAttemptStmt = $pdo->prepare(
            "INSERT INTO login_attempts (identifier, ip_address, attempted_at) VALUES (?, ?, NOW())"
        );
        $logAttemptStmt->execute([$email, $ipAddress]);

        $newFailedCount = $failedCount + 1;
        $attemptsLeft = max(0, MAX_LOGIN_ATTEMPTS - $newFailedCount);

        if ($attemptsLeft > 0) {
            http_response_code(401);
            echo json_encode([
                "status" => false,
                "success" => false,
                "error_code" => "WRONG_PASSWORD",
                "message" => "Invalid password.",
                "attempts_left" => $attemptsLeft
            ]);
            exit;
        } else {
            // Reached max attempts - account is now locked
            http_response_code(429);
            echo json_encode([
                "status" => false,
                "success" => false,
                "error_code" => "ACCOUNT_LOCKED",
                "message" => "Too many failed login attempts. Account locked for 5 minutes.",
                "retry_after_seconds" => LOGIN_LOCKOUT_SECONDS
            ]);
            exit;
        }
    }

    // 4. Successful login: Clear failed attempts for this email
    $clearAttemptsStmt = $pdo->prepare("DELETE FROM login_attempts WHERE identifier = ?");
    $clearAttemptsStmt->execute([$email]);

    // Generate random 64-char token (32 random bytes -> 64 hex chars)
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));

    // Store only the SHA-256 hash in tokens table
    $tokenStmt = $pdo->prepare("INSERT INTO tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
    $tokenStmt->execute([(int)$user["id"], $tokenHash, $expiresAt]);

    // Login succeeded.
    http_response_code(200);

    echo json_encode([
        "success" => true,
        "status" => true,
        "message" => "Login successful.",
        "token" => $token,
        "expires_at" => $expiresAt,
        "user" => [
            "id" => (int) $user["id"],
            "full_name" => $user["full_name"],
            "shop_name" => $user["shop_name"],
            "store_id" => "GB-BLR-00" . $user["id"],
            "location" => $user["location"] ?? "",
            "store_type" => $user["store_type"] ?? "",
            "currency" => $user["currency"] ?? "",
            "gstin" => $user["gstin"] ?? null,
            "avatar" => avatar_format_from_row($user),
            "role" => "Owner",
            "plan" => "StockSense Starter",
            "data_snapshot" => $user["updated_at"] ?? $user["created_at"] ?? "",
            "email" => $user["email"]
        ]
    ]);

} catch (PDOException $e) {
    // Record technical details in the server error log.
    error_log($e->getMessage());

    http_response_code(500);

    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => "Unable to process login."
    ]);
}
?>