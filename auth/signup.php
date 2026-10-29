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
require_once __DIR__ . "/../config/rate_limiter.php";

// Rate limiting: Max 5 signup attempts per IP per 15 minutes
enforce_rate_limit($pdo, 'signup', get_client_ip(), 5, 15);

// Read the JSON request from Android or Postman.
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

// Read the signup fields.
$fullName = trim($data["full_name"] ?? "");
$shopName = trim($data["shop_name"] ?? "");
$email = strtolower(trim($data["email"] ?? ""));
$password = $data["password"] ?? "";
$location = trim($data["location"] ?? "Indiranagar, Bengaluru");
$storeType = trim($data["store_type"] ?? "Neighbourhood supermarket");
$currency = trim($data["currency"] ?? "₹ INR / IST");

// Validate the required fields.
if (
    $fullName === "" ||
    $shopName === "" ||
    $email === "" ||
    !is_string($password) ||
    $password === ""
) {
    http_response_code(422);

    echo json_encode([
        "success" => false,
        "message" => "All four fields (full_name, shop_name, email, password) are required."
    ]);
    exit;
}

// Validate field lengths and email format.
if (
    strlen($fullName) > 100 ||
    strlen($shopName) > 150 ||
    strlen($email) > 150 ||
    !filter_var($email, FILTER_VALIDATE_EMAIL) ||
    strlen($password) < 8 ||
    strlen($password) > 72
) {
    http_response_code(422);

    echo json_encode([
        "success" => false,
        "message" => "Check your details. Password must be 8–72 characters and email must be valid."
    ]);
    exit;
}

try {

    // Check whether this email is already registered.
    $check = $pdo->prepare(
        "SELECT id
         FROM owner_account
         WHERE email = ?
         LIMIT 1"
    );

    $check->execute([$email]);

    if ($check->fetch()) {
        http_response_code(409);

        echo json_encode([
            "success" => false,
            "message" => "Email is already registered."
        ]);
        exit;
    }

    // Hash the password before saving it.
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    // Insert the new account into owner_account.
    $stmt = $pdo->prepare(
        "INSERT INTO owner_account
        (full_name, shop_name, location, store_type, currency, email, password)
        VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    $stmt->execute([
        $fullName,
        $shopName,
        $location,
        $storeType,
        $currency,
        $email,
        $passwordHash
    ]);

    $newUserId = (int)$pdo->lastInsertId();

    // Automatically create default user_settings row
    try {
        $settingsStmt = $pdo->prepare("INSERT IGNORE INTO user_settings (user_id) VALUES (?)");
        $settingsStmt->execute([$newUserId]);
    } catch (Throwable $ignore) {
        // Non-blocking
    }

    // Record rate limit attempt
    record_rate_limit_attempt($pdo, 'signup', get_client_ip());

    // Send a successful response.
    http_response_code(201);

    echo json_encode([
        "success" => true,
        "message" => "StockSense account created successfully.",
        "user" => [
            "id" => $newUserId,
            "full_name" => $fullName,
            "shop_name" => $shopName,
            "location" => $location,
            "store_type" => $storeType,
            "currency" => $currency,
            "email" => $email
        ]
    ]);

} catch (PDOException $e) {

    // Keep technical details in the server error log.
    error_log($e->getMessage());

    // Handle duplicate email errors.
    if ($e->getCode() === "23000") {
        http_response_code(409);

        echo json_encode([
            "success" => false,
            "message" => "This email is already registered or the account data violates a database constraint."
        ]);
    } else {
        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Unable to create account."
        ]);
    }
}
?>