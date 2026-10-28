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

// 1. Determine deduct_stock parameter (Rule 4: default false)
$deductStock = false;
if (isset($_GET["deduct_stock"])) {
    $deductStock = filter_var($_GET["deduct_stock"], FILTER_VALIDATE_BOOLEAN);
} elseif (isset($_POST["deduct_stock"])) {
    $deductStock = filter_var($_POST["deduct_stock"], FILTER_VALIDATE_BOOLEAN);
}

// 2. Parse input rows (from CSV file or JSON body)
$rows = [];
$fileName = "sales_import_" . date("Ymd_His");

if (!empty($_FILES["file"]["tmp_name"])) {
    $fileInfo = $_FILES["file"];
    $fileName = basename($fileInfo["name"]);
    $tmpPath = $fileInfo["tmp_name"];

    if (!is_uploaded_file($tmpPath)) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Invalid file upload.",
            "data" => (object)[]
        ]);
        exit;
    }

    $handle = fopen($tmpPath, "r");
    if (!$handle) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Unable to read uploaded file.",
            "data" => (object)[]
        ]);
        exit;
    }

    // Read headers
    $firstLine = fgets($handle);
    // Strip BOM
    $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine);
    $delimiter = (strpos($firstLine, "\t") !== false) ? "\t" : ((strpos($firstLine, ";") !== false) ? ";" : ",");
    $headers = str_getcsv($firstLine, $delimiter);
    $headerMap = [];
    foreach ($headers as $idx => $h) {
        $clean = strtolower(trim(str_replace([' ', '_', '-'], '', $h)));
        $headerMap[$clean] = $idx;
    }

    $rowNum = 1;
    while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
        $rowNum++;
        if (empty(array_filter($line))) {
            continue;
        }

        $billNo = isset($headerMap['billno']) ? ($line[$headerMap['billno']] ?? '') : ($line[0] ?? '');
        $sku = isset($headerMap['sku']) ? ($line[$headerMap['sku']] ?? '') : (isset($headerMap['productid']) ? ($line[$headerMap['productid']] ?? '') : ($line[1] ?? ''));
        $qty = isset($headerMap['quantity']) ? ($line[$headerMap['quantity']] ?? '') : (isset($headerMap['units']) ? ($line[$headerMap['units']] ?? '') : ($line[2] ?? ''));
        $sDate = isset($headerMap['saledate']) ? ($line[$headerMap['saledate']] ?? '') : (isset($headerMap['date']) ? ($line[$headerMap['date']] ?? '') : ($line[3] ?? ''));
        $notes = isset($headerMap['notes']) ? ($line[$headerMap['notes']] ?? '') : ($line[4] ?? '');

        $rows[] = [
            "row_index" => $rowNum,
            "bill_no" => trim($billNo),
            "sku" => trim($sku),
            "quantity" => trim($qty),
            "sale_date" => trim($sDate),
            "notes" => trim($notes)
        ];
    }
    fclose($handle);

} else {
    // Parse JSON
    $raw = file_get_contents("php://input");
    $jsonData = json_decode($raw, true);

    if (is_array($jsonData)) {
        if (isset($jsonData["deduct_stock"])) {
            $deductStock = filter_var($jsonData["deduct_stock"], FILTER_VALIDATE_BOOLEAN);
        }
        $rawRows = $jsonData["rows"] ?? $jsonData;
        if (!is_array($rawRows) || empty($rawRows)) {
            http_response_code(400);
            echo json_encode([
                "status" => false,
                "message" => "No sales rows found in payload. Provide 'rows' array or upload a CSV file.",
                "data" => (object)[]
            ]);
            exit;
        }

        foreach ($rawRows as $idx => $r) {
            $rows[] = [
                "row_index" => $idx + 1,
                "bill_no" => trim($r["bill_no"] ?? ""),
                "sku" => trim((string)($r["sku"] ?? $r["product_id"] ?? "")),
                "quantity" => $r["quantity"] ?? 0,
                "sale_date" => trim($r["sale_date"] ?? ""),
                "notes" => trim($r["notes"] ?? "")
            ];
        }
    } else {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Invalid request. Provide valid JSON payload or CSV file.",
            "data" => (object)[]
        ]);
        exit;
    }
}

// Rule 9: Max 5000 rows per import
if (count($rows) > 5000) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Import rows limit exceeded: Maximum 5000 rows allowed per import (received " . count($rows) . ").",
        "data" => (object)[]
    ]);
    exit;
}

if (empty($rows)) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Import file contains no data rows.",
        "data" => (object)[]
    ]);
    exit;
}

// 3. Pre-fetch store products for validation
$prodStmt = $pdo->prepare("SELECT id, name, sku, price, units FROM products WHERE shop_owner_id = ?");
$prodStmt->execute([$shopOwnerId]);
$storeProducts = [];
$skuToProduct = [];
$idToProduct = [];

while ($p = $prodStmt->fetch(PDO::FETCH_ASSOC)) {
    $storeProducts[$p['id']] = $p;
    $skuToProduct[strtoupper($p['sku'])] = $p;
    $idToProduct[$p['id']] = $p;
}

// 4. Validate every row and group by bill_no (Rule 5: All-or-nothing, reports every bad row)
$nowTs = time();
$errors = [];
$bills = []; // bill_no => ['sale_date' => ..., 'notes' => ..., 'items' => [prodId => qty]]

foreach ($rows as $r) {
    $rIdx = $r["row_index"];
    $billNo = $r["bill_no"];
    $skuOrId = $r["sku"];
    $qtyRaw = $r["quantity"];
    $dateRaw = $r["sale_date"];

    // Validate bill_no
    if ($billNo === "") {
        $errors[] = ["row" => $rIdx, "field" => "bill_no", "reason" => "Missing bill number."];
    } elseif (strlen($billNo) > 50) {
        $errors[] = ["row" => $rIdx, "field" => "bill_no", "reason" => "Bill number exceeds 50 characters."];
    }

    // Validate quantity
    if (!is_numeric($qtyRaw) || (int)$qtyRaw <= 0 || (int)$qtyRaw != $qtyRaw) {
        $errors[] = ["row" => $rIdx, "field" => "quantity", "reason" => "Quantity must be a positive whole number (received '{$qtyRaw}')."];
    }
    $qty = (int)$qtyRaw;

    // Validate product
    $matchedProduct = null;
    if (is_numeric($skuOrId) && isset($idToProduct[(int)$skuOrId])) {
        $matchedProduct = $idToProduct[(int)$skuOrId];
    } elseif (isset($skuToProduct[strtoupper($skuOrId)])) {
        $matchedProduct = $skuToProduct[strtoupper($skuOrId)];
    }

    if (!$matchedProduct) {
        $errors[] = ["row" => $rIdx, "field" => "sku", "reason" => "Product '{$skuOrId}' does not exist in your store inventory."];
    }

    // Validate sale_date
    $saleDate = date('Y-m-d H:i:s', $nowTs);
    if ($dateRaw !== "") {
        $ts = strtotime($dateRaw);
        if ($ts === false) {
            $errors[] = ["row" => $rIdx, "field" => "sale_date", "reason" => "Invalid date format '{$dateRaw}'."];
        } elseif ($ts > ($nowTs + 60)) {
            $errors[] = ["row" => $rIdx, "field" => "sale_date", "reason" => "Future sale date '{$dateRaw}' is not allowed."];
        } else {
            $saleDate = date('Y-m-d H:i:s', $ts);
        }
    }

    if ($billNo !== "" && $matchedProduct && $qty > 0) {
        $pid = (int)$matchedProduct["id"];
        if (!isset($bills[$billNo])) {
            $bills[$billNo] = [
                "sale_date" => $saleDate,
                "notes" => $r["notes"] ?: "Imported bill",
                "items" => []
            ];
        }
        // Merge duplicate product lines within bill (Rule 3)
        $bills[$billNo]["items"][$pid] = ($bills[$billNo]["items"][$pid] ?? 0) + $qty;
    }
}

// Check items per bill limit (Rule 9: max 100 items per bill)
foreach ($bills as $bNo => $bData) {
    if (count($bData["items"]) > 100) {
        $errors[] = [
            "row" => 0,
            "field" => "bill_no",
            "reason" => "Bill '{$bNo}' exceeds maximum limit of 100 items (has " . count($bData["items"]) . " items)."
        ];
    }
}

// Check duplicate bills already in database for this store
if (!empty($bills)) {
    $billNumbers = array_keys($bills);
    $placeholders = implode(",", array_fill(0, count($billNumbers), "?"));
    $dupStmt = $pdo->prepare("SELECT bill_no FROM sales WHERE shop_owner_id = ? AND bill_no IN ({$placeholders})");
    $dupParams = array_merge([$shopOwnerId], $billNumbers);
    $dupStmt->execute($dupParams);
    $existingBills = $dupStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($existingBills)) {
        foreach ($existingBills as $eb) {
            $errors[] = [
                "row" => 0,
                "field" => "bill_no",
                "reason" => "Bill number '{$eb}' already exists in your store database. Duplicate bills are not permitted."
            ];
        }
    }
}

// Check stock if deduct_stock is enabled
if ($deductStock && empty($errors)) {
    $neededStock = [];
    foreach ($bills as $bData) {
        foreach ($bData["items"] as $pid => $q) {
            $neededStock[$pid] = ($neededStock[$pid] ?? 0) + $q;
        }
    }

    foreach ($neededStock as $pid => $reqQty) {
        $available = (int)$storeProducts[$pid]["units"];
        if ($reqQty > $available) {
            $pName = $storeProducts[$pid]["name"];
            $pSku = $storeProducts[$pid]["sku"];
            $errors[] = [
                "row" => 0,
                "field" => "stock",
                "reason" => "Insufficient stock for product '{$pName}' (SKU: {$pSku}). Total required across import: {$reqQty}, Available: {$available}."
            ];
        }
    }
}

// 5. All-or-Nothing handling (Rule 5)
if (!empty($errors)) {
    // Record failed import in import_history
    try {
        $logStmt = $pdo->prepare("
            INSERT INTO import_history (shop_owner_id, file_name, file_size, total_rows, success_count, failed_count, status, details)
            VALUES (?, ?, ?, ?, ?, ?, 'failed', ?)
        ");
        $logStmt->execute([
            $shopOwnerId,
            $fileName,
            strlen($raw ?? ''),
            count($rows),
            0,
            count($errors),
            json_encode(["errors" => $errors])
        ]);
    } catch (Throwable $e) {
        error_log("Failed to write import history log: " . $e->getMessage());
    }

    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Import validation failed. " . count($errors) . " error(s) found across import data. No changes were applied.",
        "data" => [
            "total_rows" => count($rows),
            "error_count" => count($errors),
            "errors" => $errors
        ]
    ]);
    exit;
}

// 6. Execute atomic insertion & stock deduction
try {
    $pdo->beginTransaction();

    $insertSale = $pdo->prepare("
        INSERT INTO sales (shop_owner_id, bill_no, sale_date, total_amount, notes)
        VALUES (?, ?, ?, ?, ?)
    ");

    $insertItem = $pdo->prepare("
        INSERT INTO sales_items (sale_id, product_id, quantity, unit_price, line_total)
        VALUES (?, ?, ?, ?, ?)
    ");

    $updateStock = $pdo->prepare("
        UPDATE products SET units = units - ? WHERE id = ? AND shop_owner_id = ? AND units >= ?
    ");

    $totalImportedBills = 0;
    $totalImportedItems = 0;
    $totalImportedAmount = 0.00;

    foreach ($bills as $billNo => $bData) {
        $billTotal = 0.00;
        $itemsToInsert = [];

        foreach ($bData["items"] as $pid => $qty) {
            $p = $storeProducts[$pid];
            $unitPrice = round((float)$p["price"], 2);
            $lineTotal = round($qty * $unitPrice, 2);
            $billTotal += $lineTotal;

            if ($deductStock) {
                $updateStock->execute([$qty, $pid, $shopOwnerId, $qty]);
                if ($updateStock->rowCount() === 0) {
                    throw new RuntimeException("Concurrent stock conflict for product '{$p['name']}'");
                }
            }

            $itemsToInsert[] = [
                "product_id" => $pid,
                "quantity" => $qty,
                "unit_price" => $unitPrice,
                "line_total" => $lineTotal
            ];
            $totalImportedItems++;
        }

        $billTotal = round($billTotal, 2);
        $totalImportedAmount += $billTotal;

        $insertSale->execute([$shopOwnerId, $billNo, $bData["sale_date"], $billTotal, $bData["notes"]]);
        $saleId = (int)$pdo->lastInsertId();

        foreach ($itemsToInsert as $it) {
            $insertItem->execute([$saleId, $it["product_id"], $it["quantity"], $it["unit_price"], $it["line_total"]]);
        }

        $totalImportedBills++;
    }

    // 7. Write success record to import_history
    $logStmt = $pdo->prepare("
        INSERT INTO import_history (shop_owner_id, file_name, file_size, total_rows, success_count, failed_count, status, details)
        VALUES (?, ?, ?, ?, ?, 0, 'completed', ?)
    ");
    $logStmt->execute([
        $shopOwnerId,
        $fileName,
        strlen($raw ?? ''),
        count($rows),
        count($rows),
        json_encode([
            "bills_created" => $totalImportedBills,
            "items_created" => $totalImportedItems,
            "total_sales_amount" => round($totalImportedAmount, 2),
            "deduct_stock" => $deductStock
        ])
    ]);

    $pdo->commit();

    http_response_code(201);
    echo json_encode([
        "status" => true,
        "message" => "Sales data successfully imported. {$totalImportedBills} bills created.",
        "data" => [
            "total_rows_processed" => count($rows),
            "bills_created" => $totalImportedBills,
            "items_created" => $totalImportedItems,
            "total_sales_amount" => round($totalImportedAmount, 2),
            "stock_deducted" => $deductStock
        ]
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Sales import execution error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Internal error while executing sales import: " . $e->getMessage(),
        "data" => (object)[]
    ]);
}
