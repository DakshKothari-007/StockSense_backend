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
    header("Allow: POST");
    http_response_code(405);
    echo json_encode([
        "status" => false,
        "message" => "Method not allowed. Only POST requests are accepted.",
        "data" => null
    ]);
    exit;
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../inventory/auth_helper.php";
require_once __DIR__ . "/../config/rate_limiter.php";

$shopOwner = authenticateShopOwner($pdo);
$shopOwnerId = (int)$shopOwner["id"];
$ownerEmail = $shopOwner["email"];

// Rate limiting: 5 wrong attempts per email/IP per 15 minutes
enforce_rate_limit($pdo, 'delete_account', $ownerEmail, 5, 15);

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Invalid JSON payload.",
        "data" => null
    ]);
    exit;
}

$currentPassword = $data["current_password"] ?? "";
$confirmText = trim($data["confirm_text"] ?? "");

if ($currentPassword === "") {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Current password is required to delete account.",
        "data" => null
    ]);
    exit;
}

if ($confirmText !== "DELETE") {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Confirmation text must be exactly 'DELETE'.",
        "data" => null
    ]);
    exit;
}

// Verify current password against stored hash
$stmtUser = $pdo->prepare("SELECT password FROM owner_account WHERE id = ? LIMIT 1");
$stmtUser->execute([$shopOwnerId]);
$storedHash = $stmtUser->fetchColumn();

if (!$storedHash || !password_verify($currentPassword, $storedHash)) {
    record_rate_limit_attempt($pdo, 'delete_account', $ownerEmail);
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Current password is incorrect.",
        "data" => null
    ]);
    exit;
}

// Password verified: Proceed with atomic cascade deletion of all shop data
try {
    $pdo->beginTransaction();

    // 1. Delete sales items & sales
    $pdo->prepare("DELETE si FROM sales_items si JOIN sales s ON si.sale_id = s.id WHERE s.shop_owner_id = ?")->execute([$shopOwnerId]);
    $pdo->prepare("DELETE FROM sales WHERE shop_owner_id = ?")->execute([$shopOwnerId]);

    // 2. Delete purchase order items & purchase orders
    $pdo->prepare("DELETE poi FROM purchase_order_items poi JOIN purchase_orders po ON poi.purchase_order_id = po.id WHERE po.shop_owner_id = ?")->execute([$shopOwnerId]);
    $pdo->prepare("DELETE FROM purchase_orders WHERE shop_owner_id = ?")->execute([$shopOwnerId]);

    // 3. Delete products
    $pdo->prepare("DELETE FROM products WHERE shop_owner_id = ?")->execute([$shopOwnerId]);

    // 4. Delete import history
    $pdo->prepare("DELETE FROM import_history WHERE shop_owner_id = ?")->execute([$shopOwnerId]);

    // 5. Delete recommendation responses
    $pdo->prepare("DELETE FROM recommendation_responses WHERE shop_owner_id = ?")->execute([$shopOwnerId]);

    // 6. Delete notification states
    $pdo->prepare("DELETE FROM notification_states WHERE shop_owner_id = ?")->execute([$shopOwnerId]);

    // 7. Delete user settings
    $pdo->prepare("DELETE FROM user_settings WHERE user_id = ?")->execute([$shopOwnerId]);

    // 8. Delete active session tokens
    $pdo->prepare("DELETE FROM tokens WHERE user_id = ?")->execute([$shopOwnerId]);

    // 9. Clean rate limits & login attempts for this email
    $pdo->prepare("DELETE FROM rate_limits WHERE identifier = ?")->execute([$ownerEmail]);
    $pdo->prepare("DELETE FROM login_attempts WHERE identifier = ?")->execute([$ownerEmail]);

    // 10. Delete owner account
    $pdo->prepare("DELETE FROM owner_account WHERE id = ?")->execute([$shopOwnerId]);

    $pdo->commit();

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Account and all associated store data deleted permanently.",
        "data" => null
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Delete account error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "An internal server error occurred while deleting account.",
        "data" => null
    ]);
}
?>
