
<?php

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");
    http_response_code(405);

    echo json_encode([
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
        "success" => false,
        "message" => "Invalid JSON request."
    ]);
    exit;
}

$fullName = trim($data["full_name"] ?? "");
$shopName = trim($data["shop_name"] ?? "");
$email = strtolower(trim($data["email"] ?? ""));
$password = $data["password"] ?? "";

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

if (
    strlen($fullName) > 100 ||
    strlen($shopName) > 150 ||
    strlen($email) > 255 ||
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
    $check = $pdo->prepare(
        "SELECT id FROM users WHERE email = ? LIMIT 1"
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

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare(
        "INSERT INTO users
        (full_name, shop_name, email, password_hash)
        VALUES (?, ?, ?, ?)"
    );

    $stmt->execute([
        $fullName,
        $shopName,
        $email,
        $passwordHash
    ]);

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
    error_log($e->getMessage());

    if ($e->getCode() === "23000") {
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "message" => "Email is already registered."
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Unable to create account."
        ]);
    }
}
