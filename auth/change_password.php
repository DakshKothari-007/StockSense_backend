```php
<?php
header("Content-Type: application/json");

require_once "../config/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Only POST requests are allowed."
    ]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

$email = trim($data["email"] ?? "");
$current_password = $data["current_password"] ?? "";
$new_password = $data["new_password"] ?? "";
$confirm_password = $data["confirm_password"] ?? "";

if (
    empty($email) ||
    empty($current_password) ||
    empty($new_password) ||
    empty($confirm_password)
) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "All fields are required."
    ]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Please enter a valid email address."
    ]);
    exit;
}

if (strlen($new_password) < 8) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "New password must be at least 8 characters long."
    ]);
    exit;
}

if ($new_password !== $confirm_password) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "New password and confirm password do not match."
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT password FROM owner_account WHERE email = ?"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($current_password, $user["password"])) {
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "message" => "Email or current password is incorrect."
        ]);
        exit;
    }

    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare(
        "UPDATE owner_account SET password = ? WHERE email = ?"
    );
    $stmt->execute([$hashed_password, $email]);

    echo json_encode([
        "success" => true,
        "message" => "Password changed successfully."
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "A database error occurred."
    ]);
}
?>
```