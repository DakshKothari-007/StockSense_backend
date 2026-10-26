<?php

header("Content-Type: application/json; charset=UTF-8");

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

// Read the four signup fields.
$fullName = trim($data["full_name"] ?? "");
$shopName = trim($data["shop_name"] ?? "");
$email = strtolower(trim($data["email"] ?? ""));
$password = $data["password"] ?? "";

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
        "message" => "All four fields are required."
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
        "message" => "Check your details. Password must be 8–72 characters."
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

    // Insert the new account into the existing table.
    $stmt = $pdo->prepare(
        "INSERT INTO owner_account
        (full_name, shop_name, email, password)
        VALUES (?, ?, ?, ?)"
    );

    $stmt->execute([
        $fullName,
        $shopName,
        $email,
        $passwordHash
    ]);

    // Send a successful response.
    http_response_code(201);

    echo json_encode([
        "success" => true,
        "message" => "StockSense account created successfully.",
        "user" => [
            "id" => (int) $pdo->lastInsertId(),
            "full_name" => $fullName,
            "shop_name" => $shopName,
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