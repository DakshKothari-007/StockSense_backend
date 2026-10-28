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

// Set timezones to Asia/Kolkata
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../inventory/auth_helper.php";

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

// 1. Validate bill_no
$billNo = trim($data["bill_no"] ?? "");
if ($billNo === "") {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "bill_no is required.",
        "data" => (object)[]
    ]);
    exit;
}

if (strlen($billNo) > 50) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "bill_no cannot exceed 50 characters.",
        "data" => (object)[]
    ]);
    exit;
}

// 2. Validate sale_date
$nowTs = time();
$saleDateStr = trim($data["sale_date"] ?? "");
if ($saleDateStr === "") {
    $saleDate = date('Y-m-d H:i:s', $nowTs);
} else {
    $parsedTs = strtotime($saleDateStr);
    if ($parsedTs === false) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Invalid sale_date format. Expected valid datetime string.",
            "data" => (object)[]
        ]);
        exit;
    }
    // Reject future sale_date (allowing 60s clock skew)
    if ($parsedTs > ($nowTs + 60)) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Future sale_date is not allowed. Provided: {$saleDateStr}.",
            "data" => (object)[]
        ]);
        exit;
    }
    $saleDate = date('Y-m-d H:i:s', $parsedTs);
}

$notes = isset($data["notes"]) ? substr(trim($data["notes"]), 0, 255) : null;

// 3. Validate items
$rawItems = $data["items"] ?? null;
if (!is_array($rawItems) || empty($rawItems)) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "The 'items' list is required and cannot be empty.",
        "data" => (object)[]
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

// 4. Merge duplicate product lines before stock check (Rule 3)
$mergedItems = [];
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

    if ($productId <= 0 && $sku === "") {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Item at index {$idx} requires a valid product_id or sku.",
            "data" => (object)[]
        ]);
        exit;
    }

    if ($qty <= 0) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Item quantity must be a positive integer greater than 0.",
            "data" => (object)[]
        ]);
        exit;
    }

    $key = $productId > 0 ? "id:{$productId}" : "sku:{$sku}";
    if (!isset($mergedItems[$key])) {
        $mergedItems[$key] = [
            "product_id" => $productId,
            "sku" => $sku,
            "quantity" => 0
        ];
    }
    $mergedItems[$key]["quantity"] += $qty;
}

try {
    $pdo->beginTransaction();

    // 5. Check duplicate bill_no for this shop
    $dupCheck = $pdo->prepare("SELECT id FROM sales WHERE shop_owner_id = ? AND bill_no = ? LIMIT 1");
    $dupCheck->execute([$shopOwnerId, $billNo]);
    if ($dupCheck->fetch()) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Duplicate bill number: Bill '{$billNo}' already exists for your store.",
            "data" => (object)[]
        ]);
        exit;
    }

    // 6. Validate products and perform atomic stock reduction
    $processedItems = [];
    $totalAmount = 0.00;

    foreach ($mergedItems as $item) {
        // Resolve product
        if ($item["product_id"] > 0) {
            $pStmt = $pdo->prepare("SELECT id, name, sku, price, units FROM products WHERE id = ? AND shop_owner_id = ? LIMIT 1");
            $pStmt->execute([$item["product_id"], $shopOwnerId]);
        } else {
            $pStmt = $pdo->prepare("SELECT id, name, sku, price, units FROM products WHERE sku = ? AND shop_owner_id = ? LIMIT 1");
            $pStmt->execute([$item["sku"], $shopOwnerId]);
        }

        $product = $pStmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            $pdo->rollBack();
            http_response_code(404);
            $identifier = $item["product_id"] > 0 ? "ID {$item['product_id']}" : "SKU {$item['sku']}";
            echo json_encode([
                "status" => false,
                "message" => "Product with {$identifier} does not exist in your store inventory.",
                "data" => (object)[]
            ]);
            exit;
        }

        $prodId = (int)$product["id"];
        $qty = $item["quantity"];

        // Rule 1: Ignore client unit_price, use products.price from DB
        $unitPrice = round((float)$product["price"], 2);
        $lineTotal = round($qty * $unitPrice, 2);
        $totalAmount += $lineTotal;

        // Rule 2: Atomic stock deduction
        // UPDATE products SET units = units - ? WHERE id=? AND shop_owner_id=? AND units >= ?
        $updateStmt = $pdo->prepare("UPDATE products SET units = units - ? WHERE id = ? AND shop_owner_id = ? AND units >= ?");
        $updateStmt->execute([$qty, $prodId, $shopOwnerId, $qty]);

        if ($updateStmt->rowCount() === 0) {
            // Insufficient stock -> Rollback and return detailed 400
            $currStmt = $pdo->prepare("SELECT units FROM products WHERE id = ? AND shop_owner_id = ?");
            $currStmt->execute([$prodId, $shopOwnerId]);
            $currentUnits = (int)$currStmt->fetchColumn();

            $pdo->rollBack();
            http_response_code(400);
            echo json_encode([
                "status" => false,
                "message" => "Insufficient stock for product '{$product['name']}' (SKU: {$product['sku']}). Requested: {$qty}, Available: {$currentUnits}.",
                "data" => [
                    "product_id" => $prodId,
                    "product_name" => $product["name"],
                    "sku" => $product["sku"],
                    "requested_quantity" => $qty,
                    "available_units" => $currentUnits
                ]
            ]);
            exit;
        }

        $processedItems[] = [
            "product_id" => $prodId,
            "product_name" => $product["name"],
            "sku" => $product["sku"],
            "quantity" => $qty,
            "unit_price" => $unitPrice,
            "line_total" => $lineTotal
        ];
    }

    $totalAmount = round($totalAmount, 2);

    // 7. Insert into sales
    $insertSale = $pdo->prepare("
        INSERT INTO sales (shop_owner_id, bill_no, sale_date, total_amount, notes)
        VALUES (?, ?, ?, ?, ?)
    ");
    $insertSale->execute([$shopOwnerId, $billNo, $saleDate, $totalAmount, $notes]);
    $saleId = (int)$pdo->lastInsertId();

    // 8. Insert into sales_items
    $insertItem = $pdo->prepare("
        INSERT INTO sales_items (sale_id, product_id, quantity, unit_price, line_total)
        VALUES (?, ?, ?, ?, ?)
    ");
    foreach ($processedItems as $pi) {
        $insertItem->execute([$saleId, $pi["product_id"], $pi["quantity"], $pi["unit_price"], $pi["line_total"]]);
    }

    $pdo->commit();

    http_response_code(201);
    echo json_encode([
        "status" => true,
        "message" => "Sale bill recorded successfully and inventory stock updated.",
        "data" => [
            "sale_id" => $saleId,
            "bill_no" => $billNo,
            "sale_date" => $saleDate,
            "total_items" => count($processedItems),
            "total_quantity" => array_sum(array_column($processedItems, "quantity")),
            "total_amount" => $totalAmount,
            "notes" => $notes,
            "items" => $processedItems
        ]
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Sale create transaction error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Internal server error while creating sale bill.",
        "data" => (object)[]
    ]);
}
