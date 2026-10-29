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
        "message" => "Only POST requests are allowed.",
        "data" => null
    ]);
    exit;
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/rate_limiter.php";

$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    http_response_code(400);

    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => "Invalid JSON request.",
        "data" => null
    ]);
    exit;
}

$email = strtolower(trim($data["email"] ?? ""));

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
    http_response_code(422);

    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => "Please enter a valid email address.",
        "data" => null
    ]);
    exit;
}

// Enforce rate limiting: 5 requests per 15 minutes per email/IP
enforce_rate_limit($pdo, 'forgot_password', $email, 5, 15);
record_rate_limit_attempt($pdo, 'forgot_password', $email);

try {
    $stmt = $pdo->prepare(
        "SELECT id FROM owner_account WHERE email = ? LIMIT 1"
    );

    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);

        echo json_encode([
            "status" => false,
            "success" => false,
            "message" => "No account found with this email address.",
            "data" => null
        ]);
        exit;
    }

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "success" => true,
        "message" => "Email found. You can proceed to the new password screen.",
        "data" => [
            "email" => $email
        ]
    ]);

} catch (Throwable $e) {
    error_log("Forgot password error: " . $e->getMessage());
    http_response_code(500);

    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => "An internal error occurred. Please try again.",
        "data" => null
    ]);
}
?>
