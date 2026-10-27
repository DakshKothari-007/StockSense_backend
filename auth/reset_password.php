
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

$email = strtolower(trim($data["email"] ?? ""));
$newPassword = $data["new_password"] ?? "";
$confirmPassword = $data["confirm_password"] ?? "";

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
    http_response_code(422);

    echo json_encode([
        "success" => false,
        "message" => "Please enter a valid email address."
    ]);
    exit;
}

if (!is_string($newPassword) || strlen($newPassword) < 8) {
    http_response_code(422);

    echo json_encode([
        "success" => false,
        "message" => "Password must be at least 8 characters long."
    ]);
    exit;
}

if (!is_string($confirmPassword) || $newPassword !== $confirmPassword) {
    http_response_code(422);

    echo json_encode([
        "success" => false,
        "message" => "Passwords do not match."
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT id FROM owner_account WHERE email = ? LIMIT 1"
    );

    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);

        echo json_encode([
            "success" => false,
            "message" => "No account found with this email address."
        ]);
        exit;
    }

    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

    $update = $pdo->prepare(
        "UPDATE owner_account SET password = ? WHERE id = ?"
    );

    $update->execute([$passwordHash, $user["id"]]);

    echo json_encode([
        "success" => true,
        "message" => "Password updated successfully. Please log in with your new password."
    ]);

} catch (Throwable $e) {
    error_log($e->getMessage());
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Unable to reset password right now."
    ]);
}
?>