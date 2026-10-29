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

$rawInput = file_get_contents("php://input");

// Rule 7: Reject empty body
if (trim($rawInput) === "") {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Request body cannot be empty.",
        "data" => (object)[]
    ]);
    exit;
}

$input = json_decode($rawInput, true);
if (!is_array($input) || empty($input)) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Request body cannot be empty and must be valid JSON.",
        "data" => (object)[]
    ]);
    exit;
}

// Rule 3: Timezone is read-only. Reject updates with 400.
if (array_key_exists("timezone", $input)) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "timezone is read-only and cannot be updated.",
        "data" => (object)[]
    ]);
    exit;
}

// Rule 5: Account fields - do not duplicate profile module
$disallowedProfileFields = ["full_name", "shop_name", "location", "currency", "store_type"];
foreach ($disallowedProfileFields as $pf) {
    if (array_key_exists($pf, $input)) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Account profile field '{$pf}' must be updated via the profile module (auth/update_profile.php or auth/update_shop.php).",
            "data" => (object)[]
        ]);
        exit;
    }
}

// Rule 7: Reject unknown keys
$allowedKeys = [
    "theme_mode",
    "low_stock_alerts",
    "ai_recommendations",
    "daily_sales_summary",
    "weekly_email_digest",
    "lead_time_days",
    "safety_days",
    "cover_days",
    "min_history_days",
    "slow_moving_threshold_days",
    "import_mode",
    "email",
    "current_password"
];

foreach ($input as $k => $v) {
    if (!in_array($k, $allowedKeys, true)) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Unknown parameter '{$k}'. Unknown keys are not permitted.",
            "data" => (object)[]
        ]);
        exit;
    }
}

// Rule 7: Strict Types & Range Validations
$booleanFields = ["low_stock_alerts", "ai_recommendations", "daily_sales_summary", "weekly_email_digest"];
foreach ($booleanFields as $bf) {
    if (array_key_exists($bf, $input)) {
        if (!is_bool($input[$bf])) {
            http_response_code(400);
            echo json_encode([
                "status" => false,
                "message" => "Field '{$bf}' must be a boolean (true or false).",
                "data" => (object)[]
            ]);
            exit;
        }
    }
}

$integerRangeRules = [
    "lead_time_days" => [0, 60],
    "safety_days" => [0, 60],
    "cover_days" => [1, 90],
    "min_history_days" => [7, 180],
    "slow_moving_threshold_days" => [14, 365]
];

foreach ($integerRangeRules as $field => $range) {
    if (array_key_exists($field, $input)) {
        if (!is_int($input[$field])) {
            http_response_code(400);
            echo json_encode([
                "status" => false,
                "message" => "Field '{$field}' must be an integer.",
                "data" => (object)[]
            ]);
            exit;
        }
        $val = $input[$field];
        if ($val < $range[0] || $val > $range[1]) {
            http_response_code(400);
            echo json_encode([
                "status" => false,
                "message" => "Field '{$field}' must be between {$range[0]} and {$range[1]}.",
                "data" => (object)[]
            ]);
            exit;
        }
    }
}

// Enum validation: theme_mode
$themeMode = null;
if (array_key_exists("theme_mode", $input)) {
    if (!is_string($input["theme_mode"])) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Field 'theme_mode' must be a string.",
            "data" => (object)[]
        ]);
        exit;
    }
    $themeMode = strtoupper(trim($input["theme_mode"]));
    if (!in_array($themeMode, ["LIGHT", "DARK", "SYSTEM"], true)) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Field 'theme_mode' must be one of: LIGHT, DARK, SYSTEM.",
            "data" => (object)[]
        ]);
        exit;
    }
}

// Enum validation: import_mode
$importMode = null;
if (array_key_exists("import_mode", $input)) {
    if (!is_string($input["import_mode"])) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Field 'import_mode' must be a string.",
            "data" => (object)[]
        ]);
        exit;
    }
    $importMode = strtoupper(trim($input["import_mode"]));
    if (!in_array($importMode, ["ADD", "REPLACE"], true)) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Field 'import_mode' must be one of: ADD, REPLACE.",
            "data" => (object)[]
        ]);
        exit;
    }
}

// Rule 5: Email update requires current_password and uniqueness check
$newEmail = null;
if (array_key_exists("email", $input)) {
    if (!is_string($input["email"])) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Field 'email' must be a string.",
            "data" => (object)[]
        ]);
        exit;
    }
    $newEmail = strtolower(trim($input["email"]));
    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Invalid email address format.",
            "data" => (object)[]
        ]);
        exit;
    }

    $currentPassword = $input["current_password"] ?? "";
    if (empty($currentPassword) || !is_string($currentPassword)) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Updating email requires 'current_password'.",
            "data" => (object)[]
        ]);
        exit;
    }

    // Verify current password against owner_account
    $pwStmt = $pdo->prepare("SELECT password FROM owner_account WHERE id = ? LIMIT 1");
    $pwStmt->execute([$shopOwnerId]);
    $currentHash = $pwStmt->fetchColumn();
    if (!$currentHash || !password_verify($currentPassword, $currentHash)) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Current password is incorrect.",
            "data" => (object)[]
        ]);
        exit;
    }

    // Check email uniqueness
    $uniqStmt = $pdo->prepare("SELECT id FROM owner_account WHERE email = ? AND id != ? LIMIT 1");
    $uniqStmt->execute([$newEmail, $shopOwnerId]);
    if ($uniqStmt->fetch()) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Email address is already in use by another account.",
            "data" => (object)[]
        ]);
        exit;
    }
}

try {
    $pdo->beginTransaction();

    // 1. Update email if requested
    if ($newEmail !== null) {
        $updateEmailStmt = $pdo->prepare("UPDATE owner_account SET email = ?, updated_at = NOW() WHERE id = ?");
        $updateEmailStmt->execute([$newEmail, $shopOwnerId]);
        $owner["email"] = $newEmail;
    }

    // 2. Fetch current settings to prepare merged upsert (Rule 4: upsert on first update)
    $current = get_shop_settings($pdo, $shopOwnerId);

    $finalLowStock = array_key_exists("low_stock_alerts", $input) ? ($input["low_stock_alerts"] ? 1 : 0) : ($current["low_stock_alerts"] ? 1 : 0);
    $finalAiRec = array_key_exists("ai_recommendations", $input) ? ($input["ai_recommendations"] ? 1 : 0) : ($current["ai_recommendations"] ? 1 : 0);
    $finalDailySales = array_key_exists("daily_sales_summary", $input) ? ($input["daily_sales_summary"] ? 1 : 0) : ($current["daily_sales_summary"] ? 1 : 0);
    $finalWeeklyDigest = array_key_exists("weekly_email_digest", $input) ? ($input["weekly_email_digest"] ? 1 : 0) : ($current["weekly_email_digest"] ? 1 : 0);
    $finalTheme = $themeMode !== null ? $themeMode : $current["theme_mode"];
    $finalLead = array_key_exists("lead_time_days", $input) ? (int)$input["lead_time_days"] : (int)$current["lead_time_days"];
    $finalSafety = array_key_exists("safety_days", $input) ? (int)$input["safety_days"] : (int)$current["safety_days"];
    $finalCover = array_key_exists("cover_days", $input) ? (int)$input["cover_days"] : (int)$current["cover_days"];
    $finalMinHist = array_key_exists("min_history_days", $input) ? (int)$input["min_history_days"] : (int)$current["min_history_days"];
    $finalSlowMoving = array_key_exists("slow_moving_threshold_days", $input) ? (int)$input["slow_moving_threshold_days"] : (int)$current["slow_moving_threshold_days"];
    $finalImportMode = $importMode !== null ? $importMode : $current["import_mode"];

    $upsertStmt = $pdo->prepare("
        INSERT INTO user_settings (
            user_id, low_stock_alerts, ai_recommendations, daily_sales_summary, weekly_email_digest,
            theme_mode, lead_time_days, safety_days, cover_days, min_history_days,
            slow_moving_threshold_days, import_mode, timezone, updated_at
        ) VALUES (
            :user_id, :low_stock_alerts, :ai_recommendations, :daily_sales_summary, :weekly_email_digest,
            :theme_mode, :lead_time_days, :safety_days, :cover_days, :min_history_days,
            :slow_moving_threshold_days, :import_mode, 'Asia/Kolkata', NOW()
        )
        ON DUPLICATE KEY UPDATE
            low_stock_alerts = VALUES(low_stock_alerts),
            ai_recommendations = VALUES(ai_recommendations),
            daily_sales_summary = VALUES(daily_sales_summary),
            weekly_email_digest = VALUES(weekly_email_digest),
            theme_mode = VALUES(theme_mode),
            lead_time_days = VALUES(lead_time_days),
            safety_days = VALUES(safety_days),
            cover_days = VALUES(cover_days),
            min_history_days = VALUES(min_history_days),
            slow_moving_threshold_days = VALUES(slow_moving_threshold_days),
            import_mode = VALUES(import_mode),
            updated_at = NOW()
    ");

    $upsertStmt->execute([
        ":user_id" => $shopOwnerId,
        ":low_stock_alerts" => $finalLowStock,
        ":ai_recommendations" => $finalAiRec,
        ":daily_sales_summary" => $finalDailySales,
        ":weekly_email_digest" => $finalWeeklyDigest,
        ":theme_mode" => $finalTheme,
        ":lead_time_days" => $finalLead,
        ":safety_days" => $finalSafety,
        ":cover_days" => $finalCover,
        ":min_history_days" => $finalMinHist,
        ":slow_moving_threshold_days" => $finalSlowMoving,
        ":import_mode" => $finalImportMode
    ]);

    $pdo->commit();

    // Re-fetch updated settings
    $updatedSettings = get_shop_settings($pdo, $shopOwnerId);

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Settings updated successfully.",
        "data" => [
            "account" => [
                "owner_name" => $owner["full_name"],
                "email" => $owner["email"],
                "shop_name" => $owner["shop_name"],
                "location" => $owner["location"],
                "currency" => $owner["currency"],
                "timezone" => $updatedSettings["timezone"]
            ],
            "appearance" => [
                "theme_mode" => $updatedSettings["theme_mode"]
            ],
            "notifications" => [
                "low_stock_alerts" => $updatedSettings["low_stock_alerts"],
                "ai_recommendations" => $updatedSettings["ai_recommendations"],
                "daily_sales_summary" => $updatedSettings["daily_sales_summary"],
                "weekly_email_digest" => $updatedSettings["weekly_email_digest"]
            ],
            "inventory" => [
                "import_mode" => $updatedSettings["import_mode"],
                "lead_time_days" => $updatedSettings["lead_time_days"],
                "safety_days" => $updatedSettings["safety_days"],
                "cover_days" => $updatedSettings["cover_days"],
                "min_history_days" => $updatedSettings["min_history_days"],
                "slow_moving_threshold_days" => $updatedSettings["slow_moving_threshold_days"]
            ],
            "is_customized" => true,
            "updated_at" => $updatedSettings["updated_at"]
        ]
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Settings update error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "An internal server error occurred while updating settings.",
        "data" => (object)[]
    ]);
}
