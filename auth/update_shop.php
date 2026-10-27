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
$shop_name = trim($data["shop_name"] ?? "");

if (empty($email) || empty($shop_name)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Email and shop name are required."
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

if (strlen($shop_name) > 150) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Shop name cannot exceed 150 characters."
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT id FROM owner_account WHERE email = ?"
    );
    $stmt->execute([$email]);

    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Account not found."
        ]);
        exit;
    }

    $stmt = $pdo->prepare(
        "UPDATE owner_account SET shop_name = ? WHERE email = ?"
    );
    $stmt->execute([$shop_name, $email]);

    echo json_encode([
        "success" => true,
        "message" => "Shop name updated successfully.",
        "shop" => [
            "email" => $email,
            "shop_name" => $shop_name
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error occurred."
    ]);
}
?>
```