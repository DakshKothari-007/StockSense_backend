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
        "success" => false,
        "message" => "Only POST requests are allowed."
    ]);
    exit;
}

// Connect to the database.
require_once __DIR__ . "/../config/db.php";

// Read the JSON request.
$data = json_decode(file_get_contents("php://input"), true);

// Check whether valid JSON was received.
if (!is_array($data)) {
    http_response_code(400);

    echo json_encode([
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

    // Check recent failed attempts for this email or IP in the last 15 minutes (Max: 5 attempts)
    $checkRateStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM login_attempts 
         WHERE (identifier = ? OR (ip_address != '' AND ip_address = ?))
           AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
    );
    $checkRateStmt->execute([$email, $ipAddress]);
    $failedCount = (int) $checkRateStmt->fetchColumn();

    if ($failedCount >= 5) {
        http_response_code(429);
        echo json_encode([
            "status" => false,
            "success" => false,
            "message" => "Too many failed login attempts. Please try again after 15 minutes.",
            "data" => null
        ]);
        exit;
    }

    // Find the account using the email address.
    $stmt = $pdo->prepare(
        "SELECT id, full_name, shop_name, location, store_type, currency, email, password
         FROM owner_account
         WHERE email = ?
         LIMIT 1"
    );

    $stmt->execute([$email]);

    // Retrieve the matching account.
    $user = $stmt->fetch();

    // Check the password against the stored hash.
    if (
        !$user ||
        !password_verify($password, $user["password"])
    ) {
        // Record failed attempt
        $logAttemptStmt = $pdo->prepare("INSERT INTO login_attempts (identifier, ip_address, attempted_at) VALUES (?, ?, NOW())");
        $logAttemptStmt->execute([$email, $ipAddress]);

        $remaining = max(0, 5 - ($failedCount + 1));
        $msg = "Invalid email or password.";
        if ($remaining > 0) {
            $msg .= " $remaining attempts remaining before temporary account lock.";
        } else {
            $msg .= " Account locked for 15 minutes.";
        }

        http_response_code(401);

        echo json_encode([
            "status" => false,
            "success" => false,
            "message" => $msg,
            "data" => null
        ]);
        exit;
    }

    // Clear failed attempts upon successful authentication
    $clearAttemptsStmt = $pdo->prepare("DELETE FROM login_attempts WHERE identifier = ? OR (ip_address != '' AND ip_address = ?)");
    $clearAttemptsStmt->execute([$email, $ipAddress]);

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
        "success" => false,
        "message" => "Unable to process login."
    ]);
}
?>