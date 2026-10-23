
<?php

header("Content-Type: application/json; charset=UTF-8");

// Allow only POST requests
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only POST requests are allowed."
    ]);
    exit;
}

// Connect to the database
require_once __DIR__ . "/../config/db.php";

// Read JSON sent by the Android app
$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON request."
    ]);
    exit;
}

// Get email and password
$email = strtolower(trim($data["email"] ?? ""));
$password = $data["password"] ?? "";

// Validate input
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
    // Find the user by email
    $stmt = $pdo->prepare(
        "SELECT id, full_name, shop_name, email, password_hash
         FROM users
         WHERE email = ?
         LIMIT 1"
    );

    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Check the password
    if (!$user || !password_verify($password, $user["password_hash"])) {
        http_response_code(401);

        echo json_encode([
            "success" => false,
            "message" => "Invalid email or password."
        ]);
        exit;
    }

    // Send successful login response
    http_response_code(200);

    echo json_encode([
        "success" => true,
        "message" => "Login successful.",
        "user" => [
            "id" => (int) $user["id"],
            "full_name" => $user["full_name"],
            "shop_name" => $user["shop_name"],
            "email" => $user["email"]
        ]
    ]);

} catch (PDOException $e) {
    error_log($e->getMessage());

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Unable to process login."
    ]);
}
