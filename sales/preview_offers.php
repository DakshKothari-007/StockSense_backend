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
require_once __DIR__ . "/bundle_discount_helper.php";

$pdo->exec("SET time_zone = '+05:30'");
$owner = authenticateShopOwner($pdo);
$shopOwnerId = (int)$owner["id"];

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Invalid JSON payload.",
        "data" => (object)[]
    ]);
    exit;
}

$rawItems = $data["items"] ?? [];
if (!is_array($rawItems)) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "The 'items' field must be an array.",
        "data" => (object)[]
    ]);
    exit;
}

if (empty($rawItems)) {
    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "No items in cart.",
        "data" => [
            "subtotal" => 0.0,
            "bundle_discount" => 0.0,
            "total_amount" => 0.0,
            "applied_offers" => []
        ]
    ]);
    exit;
}

if (count($rawItems) > 100) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Items limit exceeded: A bill cannot have more than 100 items.",
        "data" => (object)[]
    ]);
    exit;
}

try {
    $removedOfferIds = validate_removed_offer_ids($pdo, $shopOwnerId, $data["removed_offer_ids"] ?? null);

    $qtyByProductId = [];
    $priceByProductId = [];

    foreach ($rawItems as $idx => $item) {
        if (!is_array($item)) {
            http_response_code(400);
            echo json_encode([
                "status" => false,
                "message" => "Item at index {$idx} must be an object.",
                "data" => (object)[]
            ]);
            exit;
        }
        $productId = (int)($item["product_id"] ?? 0);
        $sku = trim($item["sku"] ?? "");
        $qty = (int)($item["quantity"] ?? 0);
        if (($productId <= 0 && $sku === "") || $qty <= 0) {
            continue;
        }

        if ($productId > 0) {
            $pStmt = $pdo->prepare("SELECT id, price FROM products WHERE id = ? AND shop_owner_id = ? LIMIT 1");
            $pStmt->execute([$productId, $shopOwnerId]);
        } else {
            $pStmt = $pdo->prepare("SELECT id, price FROM products WHERE sku = ? AND shop_owner_id = ? LIMIT 1");
            $pStmt->execute([$sku, $shopOwnerId]);
        }
        $product = $pStmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            continue;
        }

        $pid = (int)$product["id"];
        $qtyByProductId[$pid] = ($qtyByProductId[$pid] ?? 0) + $qty;
        $priceByProductId[$pid] = round((float)$product["price"], 2);
    }

    $calc = calculate_bundle_discounts_for_items($pdo, $shopOwnerId, $qtyByProductId, $priceByProductId, $removedOfferIds);

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Bundle offers previewed successfully.",
        "data" => $calc
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => $e->getMessage(),
        "data" => (object)[]
    ]);
} catch (Throwable $e) {
    error_log("Preview offers error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Internal server error while previewing bundle offers.",
        "data" => (object)[]
    ]);
}
