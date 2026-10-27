
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
$currentEmail = strtolower(trim($data["current_email"] ?? $data["email"] ?? ""));
$fullName = trim($data["full_name"] ?? "");
$newEmail = strtolower(trim($data["new_email"] ?? $data["email"] ?? ""));

if (empty($fullName)) {
    http_response_code(422);
    echo json_encode([
        "success" => false,
        "message" => "Full name is required."
    ]);
    exit;
}

if ($userId <= 0 && empty($currentEmail)) {
    http_response_code(422);
    echo json_encode([
        "success" => false,
        "message" => "User ID or current email is required to identify the account."
    ]);
    exit;
}

if (empty($newEmail) || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode([
        "success" => false,
        "message" => "A valid email address is required."
    ]);
    exit;
}

if (strlen($fullName) > 100 || strlen($newEmail) > 150) {
    http_response_code(422);
    echo json_encode([
        "success" => false,
        "message" => "Full name (max 100) or email (max 150) exceeds the allowed length."
    ]);
    exit;
}

try {
    // 1. Identify the user
    if ($userId > 0) {
        $stmt = $pdo->prepare("SELECT id, email FROM owner_account WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
    } else {
        $stmt = $pdo->prepare("SELECT id, email FROM owner_account WHERE email = ? LIMIT 1");
        $stmt->execute([$currentEmail]);
    }

    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Account not found."
        ]);
        exit;
    }

    $targetId = (int)$existing["id"];

    // 2. Check if the new email belongs to someone else
    if ($newEmail !== strtolower($existing["email"])) {
        $dupCheck = $pdo->prepare("SELECT id FROM owner_account WHERE email = ? AND id != ? LIMIT 1");
        $dupCheck->execute([$newEmail, $targetId]);
        if ($dupCheck->fetch()) {
            http_response_code(409);
            echo json_encode([
                "success" => false,
                "message" => "This email address is already in use by another account."
            ]);
            exit;
        }
    }

    // 3. Update the record
    $updateStmt = $pdo->prepare(
        "UPDATE owner_account
         SET full_name = ?, email = ?
         WHERE id = ?"
    );
    $updateStmt->execute([$fullName, $newEmail, $targetId]);

    // 4. Return updated profile
    $getUpdated = $pdo->prepare("SELECT id, full_name, shop_name, location, store_type, currency, email, updated_at FROM owner_account WHERE id = ?");
    $getUpdated->execute([$targetId]);
    $updatedUser = $getUpdated->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "message" => "Profile updated successfully.",
        "profile" => $updatedUser
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
