
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

$current_email = trim($data["current_email"] ?? "");
$full_name = trim($data["full_name"] ?? "");
$new_email = trim($data["new_email"] ?? "");

if (empty($current_email) || empty($full_name) || empty($new_email)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Current email, full name, and new email are required."
    ]);
    exit;
}

if (!filter_var($current_email, FILTER_VALIDATE_EMAIL) ||
    !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Please enter valid email addresses."
    ]);
    exit;
}

if (strlen($full_name) > 100 || strlen($new_email) > 150) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Full name or email exceeds the allowed length."
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT id FROM owner_account WHERE email = ?"
    );
    $stmt->execute([$current_email]);

    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Account not found for the current email."
        ]);
        exit;
    }

    $stmt = $pdo->prepare(
        "UPDATE owner_account
         SET full_name = ?, email = ?
         WHERE email = ?"
    );

    $stmt->execute([$full_name, $new_email, $current_email]);

    echo json_encode([
        "success" => true,
        "message" => "Profile updated successfully.",
        "profile" => [
            "full_name" => $full_name,
            "email" => $new_email
        ]
    ]);

} catch (PDOException $e) {
    if ($e->getCode() === "23000") {
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "message" => "This email address is already registered."
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "A database error occurred."
        ]);
    }
}
?>
