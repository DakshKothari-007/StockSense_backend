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

if ($userId <= 0 && empty($email)) {
    http_response_code(422);
    echo json_encode([
        "success" => false,
        "message" => "user_id or email is required."
    ]);
    exit;
}

try {
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

    // Ensure settings row exists
    $ensureStmt = $pdo->prepare("INSERT IGNORE INTO user_settings (user_id) VALUES (?)");
    $ensureStmt->execute([$userId]);

    // Build update dynamically based on passed parameters
    $fields = [];
    $params = [];

    if (isset($data["low_stock_alerts"])) {
        $fields[] = "low_stock_alerts = ?";
        $params[] = $data["low_stock_alerts"] ? 1 : 0;
    }
    if (isset($data["ai_recommendations"])) {
        $fields[] = "ai_recommendations = ?";
        $params[] = $data["ai_recommendations"] ? 1 : 0;
    }
    if (isset($data["daily_sales_summary"])) {
        $fields[] = "daily_sales_summary = ?";
        $params[] = $data["daily_sales_summary"] ? 1 : 0;
    }
    if (isset($data["weekly_email_digest"])) {
        $fields[] = "weekly_email_digest = ?";
        $params[] = $data["weekly_email_digest"] ? 1 : 0;
    }
    if (isset($data["theme_mode"])) {
        $theme = strtoupper(trim($data["theme_mode"]));
        if (in_array($theme, ["LIGHT", "DARK", "SYSTEM"])) {
            $fields[] = "theme_mode = ?";
            $params[] = $theme;
        }
    }

    if (empty($fields)) {
        http_response_code(422);
        echo json_encode([
            "success" => false,
            "message" => "No settings provided to update."
        ]);
        exit;
    }

    $params[] = $userId;
    $sql = "UPDATE user_settings SET " . implode(", ", $fields) . " WHERE user_id = ?";
    $updateStmt = $pdo->prepare($sql);
    $updateStmt->execute($params);

    // Fetch updated settings
    $fetchStmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ?");
    $fetchStmt->execute([$userId]);
    $settings = $fetchStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "success" => true,
        "message" => "Settings updated successfully.",
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
