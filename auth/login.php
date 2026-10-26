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

    // Find the account using the email address.
    $stmt = $pdo->prepare(
        "SELECT id, full_name, shop_name, email, password
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
        http_response_code(401);

        echo json_encode([
            "success" => false,
            "message" => "Invalid email or password."
        ]);
        exit;
    }

    // Login succeeded.
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

    // Record technical details in the server error log.
    error_log($e->getMessage());

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Unable to process login."
    ]);
}
?>