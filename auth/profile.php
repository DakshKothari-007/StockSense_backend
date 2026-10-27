```php
<?php
header("Content-Type: application/json");

require_once "../config/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Only GET requests are allowed."
    ]);
    exit;
}

$email = trim($_GET["email"] ?? "");

if (empty($email)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Email is required."
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

try {
    $stmt = $pdo->prepare(
        "SELECT id, full_name, shop_name, email, created_at
         FROM owner_account
         WHERE email = ?"
    );

    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Account not found."
        ]);
        exit;
    }

    echo json_encode([
        "success" => true,
        "message" => "Profile retrieved successfully.",
        "profile" => $user
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