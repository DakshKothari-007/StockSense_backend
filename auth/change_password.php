
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
        "message" => "Invalid JSON payload."
    ]);
    exit;
}

$userId = (int)($data["user_id"] ?? $data["id"] ?? 0);
$email = strtolower(trim($data["email"] ?? ""));
$currentPassword = $data["current_password"] ?? "";
$newPassword = $data["new_password"] ?? "";
$confirmPassword = $data["confirm_password"] ?? "";

if (
    ($userId <= 0 && empty($email)) ||
    empty($currentPassword) ||
    empty($newPassword) ||
    empty($confirmPassword)
) {
    http_response_code(422);
    echo json_encode([
        "success" => false,
        "message" => "All fields (email/id, current password, new password, confirm password) are required."
    ]);
    exit;
}

if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode([
        "success" => false,
        "message" => "Please enter a valid email address."
    ]);
    exit;
}

if (strlen($newPassword) < 8) {
    http_response_code(422);
    echo json_encode([
        "success" => false,
        "message" => "New password must be at least 8 characters long."
    ]);
    exit;
}

if ($newPassword !== $confirmPassword) {
    http_response_code(422);
    echo json_encode([
        "success" => false,
        "message" => "New password and confirm password do not match."
    ]);
    exit;
}

try {
    if ($userId > 0) {
        $stmt = $pdo->prepare("SELECT id, password FROM owner_account WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
    } else {
        $stmt = $pdo->prepare("SELECT id, password FROM owner_account WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
    }

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($currentPassword, $user["password"])) {
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "message" => "Current password is incorrect."
        ]);
        exit;
    }

    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

    $update = $pdo->prepare("UPDATE owner_account SET password = ? WHERE id = ?");
    $update->execute([$hashedPassword, $user["id"]]);

    echo json_encode([
        "success" => true,
        "message" => "Password changed successfully."
    ]);

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "A database error occurred."
    ]);
}
?>
