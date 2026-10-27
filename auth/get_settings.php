<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET" && $_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Only GET or POST requests are allowed."
    ]);
    exit;
}

require_once __DIR__ . "/../config/db.php";

$data = [];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true) ?: [];
}

$userId = (int)($_GET["user_id"] ?? $_GET["id"] ?? $data["user_id"] ?? $data["id"] ?? 0);
$email = strtolower(trim($_GET["email"] ?? $data["email"] ?? ""));

if ($userId <= 0 && empty($email)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "user_id or email is required."
    ]);
    exit;
}

try {
    // Resolve user ID if email provided
    if ($userId <= 0) {
        $userStmt = $pdo->prepare("SELECT id FROM owner_account WHERE email = ? LIMIT 1");
        $userStmt->execute([$email]);
        $owner = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$owner) {
            http_response_code(404);
            echo json_encode([
                "success" => false,
                "message" => "Account not found."
            ]);
            exit;
        }
        $userId = (int)$owner["id"];
    }

    // Fetch settings or create default
    $stmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        $insertStmt = $pdo->prepare("INSERT IGNORE INTO user_settings (user_id) VALUES (?)");
        $insertStmt->execute([$userId]);

        $stmt->execute([$userId]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        "success" => true,
        "message" => "Settings retrieved successfully.",
        "settings" => [
            "user_id" => (int)$settings["user_id"],
            "low_stock_alerts" => (bool)$settings["low_stock_alerts"],
            "ai_recommendations" => (bool)$settings["ai_recommendations"],
            "daily_sales_summary" => (bool)$settings["daily_sales_summary"],
            "weekly_email_digest" => (bool)$settings["weekly_email_digest"],
            "theme_mode" => $settings["theme_mode"],
            "updated_at" => $settings["updated_at"]
        ]
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
