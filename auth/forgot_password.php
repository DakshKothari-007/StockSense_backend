
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

$email = strtolower(trim($data["email"] ?? ""));
$newPassword = $data["new_password"] ?? "";
$demoSecret = $data["demo_secret"] ?? "";

/*
 * LOCAL DEMO ONLY.
 * Replace this value with a random secret for local testing.
 * Do not use this endpoint for real accounts or deploy it publicly.
 */
$expectedDemoSecret = "CHANGE_THIS_TO_A_RANDOM_LOCAL_DEMO_SECRET";

if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);

    echo json_encode([
        "success" => false,
        "message" => "Enter a valid email address."
    ]);
    exit;
}

if (!is_string($newPassword) || strlen($newPassword) < 8) {
    http_response_code(422);

    echo json_encode([
        "success" => false,
        "message" => "New password must be at least 8 characters."
    ]);
    exit;
}

if (!is_string($demoSecret) || !hash_equals($expectedDemoSecret, $demoSecret)) {
    http_response_code(403);

    echo json_encode([
        "success" => false,
        "message" => "Demo authorization failed."
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
            "message" => "No account found for this email."
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
        "message" => "Password updated successfully. You can now log in with your new password."
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
