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
        "status" => false,
        "message" => "Method Not Allowed. Only POST requests are allowed.",
        "data" => (object)[]
    ]);
    exit;
}

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../inventory/auth_helper.php";
require_once __DIR__ . "/settings_helper.php";

$pdo->exec("SET time_zone = '+05:30'");
$owner = authenticateShopOwner($pdo);
$shopOwnerId = (int)$owner["id"];

try {
    $pdo->beginTransaction();

    $resetStmt = $pdo->prepare("
        INSERT INTO user_settings (
            user_id, low_stock_alerts, ai_recommendations, daily_sales_summary, weekly_email_digest,
            theme_mode, lead_time_days, safety_days, cover_days, min_history_days,
            slow_moving_threshold_days, import_mode, timezone, updated_at
        ) VALUES (
            :user_id, 1, 1, 1, 0,
            'SYSTEM', 2, 3, 7, 30,
            60, 'ADD', 'Asia/Kolkata', NOW()
        )
        ON DUPLICATE KEY UPDATE
            low_stock_alerts = 1,
            ai_recommendations = 1,
            daily_sales_summary = 1,
            weekly_email_digest = 0,
            theme_mode = 'SYSTEM',
            lead_time_days = 2,
            safety_days = 3,
            cover_days = 7,
            min_history_days = 30,
            slow_moving_threshold_days = 60,
            import_mode = 'ADD',
            timezone = 'Asia/Kolkata',
            updated_at = NOW()
    ");

    $resetStmt->execute([":user_id" => $shopOwnerId]);
    $pdo->commit();

    $settings = get_shop_settings($pdo, $shopOwnerId);

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Settings have been reset to default values successfully.",
        "data" => [
            "account" => [
                "owner_name" => $owner["full_name"],
                "email" => $owner["email"],
                "shop_name" => $owner["shop_name"],
                "location" => $owner["location"],
                "currency" => $owner["currency"],
                "timezone" => $settings["timezone"]
            ],
            "appearance" => [
                "theme_mode" => $settings["theme_mode"]
            ],
            "notifications" => [
                "low_stock_alerts" => $settings["low_stock_alerts"],
                "ai_recommendations" => $settings["ai_recommendations"],
                "daily_sales_summary" => $settings["daily_sales_summary"],
                "weekly_email_digest" => $settings["weekly_email_digest"]
            ],
            "inventory" => [
                "import_mode" => $settings["import_mode"],
                "lead_time_days" => $settings["lead_time_days"],
                "safety_days" => $settings["safety_days"],
                "cover_days" => $settings["cover_days"],
                "min_history_days" => $settings["min_history_days"],
                "slow_moving_threshold_days" => $settings["slow_moving_threshold_days"]
            ],
            "is_customized" => true,
            "updated_at" => $settings["updated_at"]
        ]
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Settings reset_defaults error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "An internal server error occurred while resetting settings.",
        "data" => (object)[]
    ]);
}
