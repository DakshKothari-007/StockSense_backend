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

// 1. Validate items array
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

// Rule 10: Limit 100 items per order
if (count($rawItems) > 100) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Limit exceeded: An order cannot contain more than 100 items.",
        "data" => (object)[]
    ]);
    exit;
}

$notes = isset($data["notes"]) ? substr(trim($data["notes"]), 0, 255) : null;

// 2. Validate & Merge duplicate product items
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

    // Rule 10: Limit 10000 units per item
    if ($qty <= 0) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Item quantity must be a positive integer greater than 0.",
            "data" => (object)[]
        ]);
        exit;
    }

    if ($qty > 10000) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Quantity exceeds maximum allowable limit of 10,000 units per item.",
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
    if ($mergedItems[$key]["quantity"] > 10000) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Merged quantity for item exceeds maximum limit of 10,000 units.",
            "data" => (object)[]
        ]);
        exit;
    }
}

try {
    $pdo->beginTransaction();

    // 3. Generate order_no on the server (Rule 6: PO-YYYY-NNN per shop)
    $year = date("Y");
    $prefix = "PO-{$year}-%";
    $seqStmt = $pdo->prepare("
        SELECT order_no 
        FROM purchase_orders 
        WHERE shop_owner_id = ? AND order_no LIKE ? 
        ORDER BY id DESC 
        LIMIT 1
    ");
    $seqStmt->execute([$shopOwnerId, $prefix]);
    $lastOrderNo = $seqStmt->fetchColumn();

    $nextSeq = 1;
    if ($lastOrderNo && preg_match('/^PO-\d{4}-(\d+)$/', $lastOrderNo, $m)) {
        $nextSeq = (int)$m[1] + 1;
    }
    $orderNo = sprintf("PO-%s-%03d", $year, $nextSeq);

    // 4. Validate products against shop inventory (Rule 10: exclude archived)
    $activeProductCondition = "(status IS NULL OR (status NOT LIKE '%Archived%' AND status NOT LIKE '%Inactive%'))";
    $processedItems = [];
    $totalUnits = 0;
    $totalEstimatedCost = 0.00;
    $hasAnyCost = false;

    foreach ($mergedItems as $item) {
        if ($item["product_id"] > 0) {
            $pStmt = $pdo->prepare("SELECT id, name, sku, price, cost_price FROM products WHERE id = ? AND shop_owner_id = ? AND {$activeProductCondition} LIMIT 1");
            $pStmt->execute([$item["product_id"], $shopOwnerId]);
        } else {
            $pStmt = $pdo->prepare("SELECT id, name, sku, price, cost_price FROM products WHERE sku = ? AND shop_owner_id = ? AND {$activeProductCondition} LIMIT 1");
            $pStmt->execute([$item["sku"], $shopOwnerId]);
        }

        $prod = $pStmt->fetch(PDO::FETCH_ASSOC);
        if (!$prod) {
            $pdo->rollBack();
            http_response_code(404);
            $ident = $item["product_id"] > 0 ? "ID {$item['product_id']}" : "SKU {$item['sku']}";
            echo json_encode([
                "status" => false,
                "message" => "Product with {$ident} does not exist or is archived in your store inventory.",
                "data" => (object)[]
            ]);
            exit;
        }

        $pid = (int)$prod["id"];
        $qty = $item["quantity"];
        $costPrice = ($prod["cost_price"] !== null && (float)$prod["cost_price"] > 0) ? (float)$prod["cost_price"] : null;
        $lineCost = null;

        if ($costPrice !== null) {
            $lineCost = round($qty * $costPrice, 2);
            $totalEstimatedCost += $lineCost;
            $hasAnyCost = true;
        }

        $totalUnits += $qty;
        $processedItems[] = [
            "product_id" => $pid,
            "product_name" => $prod["name"],
            "sku" => $prod["sku"],
            "quantity" => $qty,
            "cost_price" => $costPrice,
            "line_estimated_cost" => $lineCost
        ];
    }

    $finalTotalCost = $hasAnyCost ? round($totalEstimatedCost, 2) : null;

    // 5. Insert into purchase_orders (Rule 7: status is 'placed' on creation)
    $insertOrder = $pdo->prepare("
        INSERT INTO purchase_orders (shop_owner_id, order_no, status, total_estimated_cost, total_items, total_units, notes)
        VALUES (?, ?, 'placed', ?, ?, ?, ?)
    ");
    $insertOrder->execute([$shopOwnerId, $orderNo, $finalTotalCost, count($processedItems), $totalUnits, $notes]);
    $orderId = (int)$pdo->lastInsertId();

    // 6. Insert into purchase_order_items
    $insertItem = $pdo->prepare("
        INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity, cost_price, line_estimated_cost)
        VALUES (?, ?, ?, ?, ?)
    ");
    foreach ($processedItems as $pi) {
        $insertItem->execute([$orderId, $pi["product_id"], $pi["quantity"], $pi["cost_price"], $pi["line_estimated_cost"]]);
    }

    $pdo->commit();

    http_response_code(201);
    echo json_encode([
        "status" => true,
        "message" => "Purchase order placed successfully.",
        "data" => [
            "order_id" => $orderId,
            "order_no" => $orderNo,
            "status" => "placed",
            "total_items" => count($processedItems),
            "total_units" => $totalUnits,
            "total_estimated_cost" => $finalTotalCost,
            "notes" => $notes,
            "items" => $processedItems
        ]
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Create purchase order error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Internal error creating purchase order: " . $e->getMessage(),
        "data" => (object)[]
    ]);
}
