<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-User-Id, X-User-Email, Idempotency-Key");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Global exception handler ensuring raw 500 HTML is never leaked to the client
set_exception_handler(function(Throwable $e) use (&$pdo) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Unhandled error in execute.php: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header("Content-Type: application/json; charset=UTF-8");
    }
    echo json_encode([
        "status" => false,
        "message" => "Import execution failed: " . $e->getMessage(),
        "data" => (object)[]
    ]);
    exit;
});

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../inventory/auth_helper.php";
require_once __DIR__ . "/SpreadsheetReader.php";
require_once __DIR__ . "/ColumnMatcher.php";
require_once __DIR__ . "/../settings/settings_helper.php";
require_once __DIR__ . "/../includes/IconClassifier.php";

$owner = authenticateShopOwner($pdo);
$ownerId = (int)$owner['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "status" => false,
        "message" => "Method Not Allowed. Use POST to execute import.",
        "data" => (object)[]
    ]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true) ?? $_POST;
$idempotencyKey = trim($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($input['idempotency_key'] ?? ''));

// Idempotency guard: ignore repeated execute calls with identical key and return existing result
if (!empty($idempotencyKey)) {
    $idemStmt = $pdo->prepare("
        SELECT id, file_name, total_rows, success_count, failed_count, status, details, created_at
        FROM import_history
        WHERE shop_owner_id = ? AND idempotency_key = ? AND status IN ('completed', 'partial')
        ORDER BY id DESC
        LIMIT 1
    ");
    $idemStmt->execute([$ownerId, $idempotencyKey]);
    $cached = $idemStmt->fetch(PDO::FETCH_ASSOC);

    if ($cached) {
        $details = json_decode($cached['details'] ?? '{}', true) ?: [];
        $totalRows = (int)$cached['total_rows'];
        $successCount = (int)$cached['success_count'];
        $failedCount = (int)$cached['failed_count'];
        $productsUpdated = (int)($details['products_updated'] ?? 0);
        $productsAdded = (int)($details['products_added'] ?? 0);
        $stockAdded = (int)($details['stock_added'] ?? 0);
        $correctedRowsCount = (int)($details['corrected_rows'] ?? 0);
        $totalActiveProducts = (int)($details['total_active_products'] ?? 0);
        $newInventoryValue = (float)($details['new_inventory_value'] ?? 0.0);

        if ($totalActiveProducts === 0 && $newInventoryValue === 0.0) {
            $statsStmt = $pdo->prepare("SELECT COUNT(*) AS total_count, COALESCE(SUM(units * price), 0) AS total_val FROM products WHERE shop_owner_id = ?");
            $statsStmt->execute([$ownerId]);
            $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
            $totalActiveProducts = (int)($stats['total_count'] ?? 0);
            $newInventoryValue = (float)($stats['total_val'] ?? 0.0);
        }

        http_response_code(200);
        echo json_encode([
            "status" => true,
            "message" => "Your shelves are up to date. Import complete.",
            "data" => [
                "import_id" => (int)$cached['id'],
                "file_name" => $cached['file_name'],
                "products_updated" => $productsUpdated,
                "products_added" => $productsAdded,
                "data_rows_imported" => "{$successCount} of {$totalRows}",
                "data_rows_imported_count" => $successCount,
                "stock_added" => "{$stockAdded} units",
                "stock_added_count" => $stockAdded,
                "corrected_rows" => $correctedRowsCount,
                "skipped_rows" => $failedCount,
                "corrected_skipped_text" => "{$correctedRowsCount} / {$failedCount} rows",
                "total_active_products" => $totalActiveProducts,
                "new_inventory_value" => "₹" . number_format($newInventoryValue, 0),
                "new_inventory_value_raw" => round($newInventoryValue, 2)
            ]
        ]);
        exit;
    }
}

// Rule 2: Check shop import_mode setting
$shopSettings = get_shop_settings($pdo, $ownerId);
$shopImportMode = $shopSettings['import_mode'] ?? 'ADD';

if ($shopImportMode === 'REPLACE') {
    $confirmReplace = !empty($input['confirm_replace']) && ($input['confirm_replace'] === true || $input['confirm_replace'] === 'true' || $input['confirm_replace'] === 1 || $input['confirm_replace'] === '1');
    if (!$confirmReplace) {
        http_response_code(400);
        echo json_encode([
            "status" => false,
            "message" => "Shop import mode is set to REPLACE (overwrite existing inventory stock). Overwriting stock requires explicit 'confirm_replace: true' in request.",
            "data" => (object)[]
        ]);
        exit;
    }
}

$fileToken = trim($input['file_token'] ?? '');
if (empty($fileToken)) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Validation failed: 'file_token' parameter is required.",
        "data" => (object)[]
    ]);
    exit;
}

if (!preg_match('/^imp_' . $ownerId . '_[a-zA-Z0-9_]+\.(csv|xlsx)$/', $fileToken)) {
    http_response_code(403);
    echo json_encode([
        "status" => false,
        "message" => "Forbidden: You do not have permission to access this upload token.",
        "data" => (object)[]
    ]);
    exit;
}

$filePath = __DIR__ . "/../uploads/temp/" . $fileToken;
if (!file_exists($filePath)) {
    http_response_code(404);
    echo json_encode([
        "status" => false,
        "message" => "Uploaded file session not found or has expired. Please re-upload.",
        "data" => (object)[]
    ]);
    exit;
}

$fileSize = filesize($filePath);
$origFileName = trim($input['file_name'] ?? basename($filePath));

try {
    $rows = SpreadsheetReader::read($filePath);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Unable to read spreadsheet: " . $e->getMessage(),
        "data" => (object)[]
    ]);
    exit;
}

if (count($rows) < 1) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Spreadsheet contains no data rows.",
        "data" => (object)[]
    ]);
    exit;
}

$headerRow = array_shift($rows);
$headerMap = [];
foreach ($headerRow as $idx => $name) {
    $headerMap[trim($name)] = $idx;
    $headerMap[strtolower(trim($name))] = $idx;
}

$userMapping = $input['column_mapping'] ?? null;
$hasUserMapping = ($userMapping !== null && is_array($userMapping) && !empty($userMapping));

$colNameIdx = ColumnMatcher::resolveColumnIndex('name', $userMapping['name'] ?? null, $headerRow, !$hasUserMapping);
$colSkuIdx = ColumnMatcher::resolveColumnIndex('sku', $userMapping['sku'] ?? null, $headerRow, !$hasUserMapping);
$colUnitsIdx = ColumnMatcher::resolveColumnIndex('units', $userMapping['units'] ?? null, $headerRow, !$hasUserMapping);
$colCostIdx = ColumnMatcher::resolveColumnIndex('cost_price', $userMapping['cost_price'] ?? null, $headerRow, !$hasUserMapping);
$colPriceIdx = ColumnMatcher::resolveColumnIndex('price', $userMapping['price'] ?? null, $headerRow, !$hasUserMapping);
$colCategoryIdx = ColumnMatcher::resolveColumnIndex('category', $userMapping['category'] ?? null, $headerRow, !$hasUserMapping);
$colMinStockIdx = ColumnMatcher::resolveColumnIndex('minimum_stock', $userMapping['minimum_stock'] ?? null, $headerRow, !$hasUserMapping);

// Check required mappings: only name and units are strictly required
$missingRequired = [];
if ($colNameIdx === null) $missingRequired[] = "Product Name (name)";
if ($colUnitsIdx === null) $missingRequired[] = "Quantity (units)";

if (!empty($missingRequired)) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Import execution failed: " . implode(", ", $missingRequired) . " column(s) are required and must be mapped.",
        "data" => (object)[]
    ]);
    exit;
}

// If SKU is not mapped, check if a barcode column is present in headers
$colBarcodeIdx = null;
if ($colSkuIdx === null) {
    $colBarcodeIdx = ColumnMatcher::resolveColumnIndex('sku', null, $headerRow, true);
}

// Parse corrections
$correctionsMap = [];
if (!empty($input['corrections']) && is_array($input['corrections'])) {
    foreach ($input['corrections'] as $corr) {
        $rowNum = (int)($corr['row_number'] ?? ($corr['row_index'] !== null ? (int)$corr['row_index'] + 2 : 0));
        $field = trim($corr['field'] ?? '');
        $val = $corr['value'] ?? null;
        if ($rowNum > 0 && $field !== '' && $val !== null) {
            $correctionsMap[$rowNum][$field] = $val;
        }
    }
}

$allowPartial = !empty($input['allow_partial']);
$totalRows = count($rows);
$errors = [];
$validRowsToInsert = [];
$correctedRowsCount = count($correctionsMap);

foreach ($rows as $index => $row) {
    $rowNumber = $index + 2;

    $rawSku = ($colSkuIdx !== null && isset($row[$colSkuIdx])) ? trim($row[$colSkuIdx]) : '';
    if ($rawSku === '' && $colBarcodeIdx !== null && isset($row[$colBarcodeIdx])) {
        $rawSku = trim($row[$colBarcodeIdx]);
    }
    if (isset($correctionsMap[$rowNumber]['sku'])) {
        $rawSku = trim((string)$correctionsMap[$rowNumber]['sku']);
    }

    $rawName = ($colNameIdx !== null && isset($row[$colNameIdx])) ? trim($row[$colNameIdx]) : '';
    if (isset($correctionsMap[$rowNumber]['name'])) {
        $rawName = trim((string)$correctionsMap[$rowNumber]['name']);
    }

    // Generate stable SKU if missing and name is present
    if ($rawSku === '' && $rawName !== '') {
        $rawSku = ColumnMatcher::generateStableSku($ownerId, $rawName);
    }

    $rawUnits = ($colUnitsIdx !== null && isset($row[$colUnitsIdx])) ? trim($row[$colUnitsIdx]) : '';
    if (isset($correctionsMap[$rowNumber]['units'])) {
        $rawUnits = trim((string)$correctionsMap[$rowNumber]['units']);
    }

    $rawCost = ($colCostIdx !== null && isset($row[$colCostIdx])) ? trim($row[$colCostIdx]) : '0';
    if (isset($correctionsMap[$rowNumber]['cost_price'])) {
        $rawCost = trim((string)$correctionsMap[$rowNumber]['cost_price']);
    }

    $rawPrice = ($colPriceIdx !== null && isset($row[$colPriceIdx])) ? trim($row[$colPriceIdx]) : '0';
    if (isset($correctionsMap[$rowNumber]['price'])) {
        $rawPrice = trim((string)$correctionsMap[$rowNumber]['price']);
    }

    // Validation
    $hasRowError = false;
    if ($rawSku === '' || strlen($rawSku) > 50) {
        $hasRowError = true;
        $errors[] = ["row_number" => $rowNumber, "field" => "sku", "reason" => "Invalid SKU"];
    }

    if ($rawName === '' || strlen($rawName) > 150) {
        $hasRowError = true;
        $errors[] = ["row_number" => $rowNumber, "field" => "name", "reason" => "Invalid product name"];
    }

    if (!preg_match('/^\d+$/', $rawUnits) || (int)$rawUnits <= 0) {
        $hasRowError = true;
        $errors[] = ["row_number" => $rowNumber, "field" => "units", "reason" => "Invalid quantity: '{$rawUnits}'"];
    }

    $cleanCost = preg_replace('/[^0-9.]/', '', $rawCost);
    $costVal = is_numeric($cleanCost) ? (float)$cleanCost : 0.0;

    $cleanPrice = preg_replace('/[^0-9.]/', '', $rawPrice);
    $priceVal = is_numeric($cleanPrice) ? (float)$cleanPrice : 0.0;
    if ($priceVal <= 0.0 && $costVal > 0.0) {
        $priceVal = $costVal;
    }

    if ($costVal < 0 || $priceVal < 0) {
        $hasRowError = true;
        $errors[] = ["row_number" => $rowNumber, "field" => "price", "reason" => "Cost and price cannot be negative"];
    }

    $rawCategory = ($colCategoryIdx !== null && isset($row[$colCategoryIdx])) ? trim($row[$colCategoryIdx]) : '';
    if (isset($correctionsMap[$rowNumber]['category'])) {
        $rawCategory = trim((string)$correctionsMap[$rowNumber]['category']);
    }

    $rawMinStock = ($colMinStockIdx !== null && isset($row[$colMinStockIdx])) ? trim($row[$colMinStockIdx]) : '';
    if (isset($correctionsMap[$rowNumber]['minimum_stock'])) {
        $rawMinStock = trim((string)$correctionsMap[$rowNumber]['minimum_stock']);
    }
    $minStockVal = is_numeric($rawMinStock) ? max(0, (int)$rawMinStock) : 10;
    $categoryVal = ($rawCategory !== '') ? $rawCategory : 'General';

    if (!$hasRowError) {
        $validRowsToInsert[] = [
            'row_number' => $rowNumber,
            'sku' => $rawSku,
            'name' => $rawName,
            'category' => $categoryVal,
            'min_stock_threshold' => $minStockVal,
            'units' => (int)$rawUnits,
            'cost_price' => $costVal,
            'price' => $priceVal
        ];
    }
}

// Atomicity check: If any errors exist and partial import is not explicitly allowed, abort immediately!
if (!empty($errors) && !$allowPartial) {
    // Record failed import in history
    try {
        $histStmt = $pdo->prepare("
            INSERT INTO import_history (shop_owner_id, file_name, file_size, total_rows, success_count, failed_count, status, details, idempotency_key)
            VALUES (?, ?, ?, ?, ?, ?, 'failed', ?, ?)
        ");
        $histStmt->execute([
            $ownerId,
            $origFileName,
            $fileSize,
            $totalRows,
            0,
            count($errors),
            json_encode(["errors" => $errors]),
            !empty($idempotencyKey) ? $idempotencyKey : null
        ]);
    } catch (Throwable $e) {
        error_log("Failed to log failed import: " . $e->getMessage());
    }

    http_response_code(422);
    echo json_encode([
        "status" => false,
        "message" => "Import aborted: invalid rows found. Valid rows are held until all rows are ready.",
        "data" => [
            "total_rows" => $totalRows,
            "ready_rows" => count($validRowsToInsert),
            "errors_remaining" => count($errors),
            "errors" => $errors
        ]
    ]);
    exit;
}

if (empty($validRowsToInsert)) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "No valid rows available to import.",
        "data" => (object)[]
    ]);
    exit;
}

// Execute database operations inside a strict PDO transaction
try {
    $pdo->beginTransaction();

    $productsUpdated = 0;
    $productsAdded = 0;
    $stockAdded = 0;

    // Pre-classify product icons (Step 1 keywords, batch up to 20 unmatched to AI)
    $skuToIconKey = [];
    $unmatchedForAi = [];

    foreach ($validRowsToInsert as $item) {
        $sku = $item['sku'];
        $name = $item['name'] ?? '';
        $cat = $item['category'] ?? 'General';

        $kwMatch = IconClassifier::matchByKeywords($name, $cat);
        if ($kwMatch !== null && !empty($kwMatch['icon_key'])) {
            $skuToIconKey[$sku] = $kwMatch['icon_key'];
        } else {
            $unmatchedForAi[] = [
                'sku' => $sku,
                'name' => $name,
                'category' => $cat
            ];
        }
    }

    if (!empty($unmatchedForAi)) {
        try {
            $aiBatch = array_slice($unmatchedForAi, 0, 20);
            $aiResults = IconClassifier::batchClassifyWithAi($aiBatch);
            foreach ($aiResults as $sku => $iconKey) {
                if (!empty($iconKey) && IconClassifier::isValidKey($iconKey)) {
                    $skuToIconKey[$sku] = $iconKey;
                }
            }
        } catch (Throwable $e) {
            error_log("Icon classification AI error during import: " . $e->getMessage());
            // Never block the import; on failure, leave empty
        }
    }

    $checkStmt = $pdo->prepare("SELECT id, name, category, units, cost_price, price, min_stock_threshold, icon_key, icon_is_manual FROM products WHERE shop_owner_id = ? AND sku = ? LIMIT 1 FOR UPDATE");
    $unitsUpdateExpr = ($shopImportMode === 'REPLACE') ? ":units" : "units + :units";
    $updateStmt = $pdo->prepare("
        UPDATE products
        SET units = {$unitsUpdateExpr},
            cost_price = :cost_price,
            price = :price,
            name = :name,
            category = :category,
            min_stock_threshold = :min_stock_threshold,
            icon_key = :icon_key,
            updated_at = CURRENT_TIMESTAMP
        WHERE shop_owner_id = :shop_owner_id AND sku = :sku
    ");
    $insertStmt = $pdo->prepare("
        INSERT INTO products (shop_owner_id, name, sku, category, description, price, cost_price, units, min_stock_threshold, status, icon_type, icon_key, icon_is_manual)
        VALUES (:shop_owner_id, :name, :sku, :category, 'Imported product', :price, :cost_price, :units, :min_stock_threshold, NULL, 0, :icon_key, 0)
    ");

    foreach ($validRowsToInsert as $item) {
        $checkStmt->execute([$ownerId, $item['sku']]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $updateCost = ($colCostIdx !== null && $item['cost_price'] > 0) ? $item['cost_price'] : (float)$existing['cost_price'];
            $updatePrice = ($colPriceIdx !== null && $item['price'] > 0) ? $item['price'] : (float)$existing['price'];
            $updateName = (!empty($item['name'])) ? $item['name'] : $existing['name'];
            $updateCategory = (!empty($item['category'])) ? $item['category'] : (!empty($existing['category']) ? $existing['category'] : 'General');
            $updateMinStock = ($colMinStockIdx !== null && $item['min_stock_threshold'] > 0) ? $item['min_stock_threshold'] : (int)($existing['min_stock_threshold'] ?? 10);

            // Never overwrite owner's manual choice or existing icon
            $finalIconKey = $existing['icon_key'];
            if ((empty($existing['icon_key']) || $existing['icon_key'] === null) && (int)($existing['icon_is_manual'] ?? 0) === 0) {
                if (isset($skuToIconKey[$item['sku']])) {
                    $finalIconKey = $skuToIconKey[$item['sku']];
                }
            }

            $updateStmt->execute([
                ':units' => $item['units'],
                ':cost_price' => $updateCost,
                ':price' => $updatePrice,
                ':name' => $updateName,
                ':category' => $updateCategory,
                ':min_stock_threshold' => $updateMinStock,
                ':icon_key' => $finalIconKey,
                ':shop_owner_id' => $ownerId,
                ':sku' => $item['sku']
            ]);
            $productsUpdated++;
        } else {
            $finalIconKey = $skuToIconKey[$item['sku']] ?? null;
            $insertStmt->execute([
                ':shop_owner_id' => $ownerId,
                ':name' => $item['name'],
                ':sku' => $item['sku'],
                ':category' => $item['category'] ?? 'General',
                ':price' => $item['price'],
                ':cost_price' => $item['cost_price'],
                ':units' => $item['units'],
                ':min_stock_threshold' => $item['min_stock_threshold'] ?? 10,
                ':icon_key' => $finalIconKey
            ]);
            $productsAdded++;
        }

        $stockAdded += $item['units'];
    }

    // Query updated active products and total inventory value
    $statsStmt = $pdo->prepare("SELECT COUNT(*) AS total_count, COALESCE(SUM(units * price), 0) AS total_val FROM products WHERE shop_owner_id = ?");
    $statsStmt->execute([$ownerId]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    $totalActiveProducts = (int)($stats['total_count'] ?? 0);
    $newInventoryValue = (float)($stats['total_val'] ?? 0.0);

    $successCount = count($validRowsToInsert);
    $failedCount = count($errors);
    $importStatus = ($failedCount === 0) ? 'completed' : 'partial';

    $detailsPayload = json_encode([
        "products_updated" => $productsUpdated,
        "products_added" => $productsAdded,
        "stock_added" => $stockAdded,
        "corrected_rows" => $correctedRowsCount,
        "skipped_rows" => $failedCount,
        "total_active_products" => $totalActiveProducts,
        "new_inventory_value" => $newInventoryValue
    ]);

    // Save import history
    $histStmt = $pdo->prepare("
        INSERT INTO import_history (shop_owner_id, file_name, file_size, total_rows, success_count, failed_count, status, details, idempotency_key)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $histStmt->execute([
        $ownerId,
        $origFileName,
        $fileSize,
        $totalRows,
        $successCount,
        $failedCount,
        $importStatus,
        $detailsPayload,
        !empty($idempotencyKey) ? $idempotencyKey : null
    ]);
    $importId = (int)$pdo->lastInsertId();

    $pdo->commit();

    // Post-commit low stock push notifications if any imported products are at or below minimum stock
    try {
        require_once __DIR__ . "/../includes/PushNotifier.php";
        $stmtLowImp = $pdo->prepare("
            SELECT id, name, units, min_stock_threshold 
            FROM products 
            WHERE shop_owner_id = ? 
              AND min_stock_threshold IS NOT NULL 
              AND units <= min_stock_threshold
        ");
        $stmtLowImp->execute([$ownerId]);
        $lowProducts = $stmtLowImp->fetchAll(PDO::FETCH_ASSOC);
        foreach ($lowProducts as $prodRow) {
            PushNotifier::triggerLowStock($pdo, $ownerId, $prodRow);
        }
    } catch (Throwable $pushEx) {
        error_log("Import execute push notification error: " . $pushEx->getMessage());
    }

    // Persist final column mapping for this shop owner so identical sheets are auto-mapped
    $resolvedMapping = [
        'name' => ($colNameIdx !== null && isset($headerRow[$colNameIdx])) ? $headerRow[$colNameIdx] : null,
        'sku' => ($colSkuIdx !== null && isset($headerRow[$colSkuIdx])) ? $headerRow[$colSkuIdx] : null,
        'units' => ($colUnitsIdx !== null && isset($headerRow[$colUnitsIdx])) ? $headerRow[$colUnitsIdx] : null,
        'cost_price' => ($colCostIdx !== null && isset($headerRow[$colCostIdx])) ? $headerRow[$colCostIdx] : null,
        'price' => ($colPriceIdx !== null && isset($headerRow[$colPriceIdx])) ? $headerRow[$colPriceIdx] : null,
        'category' => ($colCategoryIdx !== null && isset($headerRow[$colCategoryIdx])) ? $headerRow[$colCategoryIdx] : null,
        'minimum_stock' => ($colMinStockIdx !== null && isset($headerRow[$colMinStockIdx])) ? $headerRow[$colMinStockIdx] : null
    ];
    $mappingToSave = !empty($userMapping) ? $userMapping : array_filter($resolvedMapping);
    ColumnMatcher::saveMapping($pdo, $ownerId, $headerRow, $mappingToSave);

    // Clean up temporary file
    @unlink($filePath);

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Your shelves are up to date. Import complete.",
        "data" => [
            "import_id" => $importId,
            "file_name" => $origFileName,
            "products_updated" => $productsUpdated,
            "products_added" => $productsAdded,
            "data_rows_imported" => "{$successCount} of {$totalRows}",
            "data_rows_imported_count" => $successCount,
            "stock_added" => "{$stockAdded} units",
            "stock_added_count" => $stockAdded,
            "corrected_rows" => $correctedRowsCount,
            "skipped_rows" => $failedCount,
            "corrected_skipped_text" => "{$correctedRowsCount} / {$failedCount} rows",
            "total_active_products" => $totalActiveProducts,
            "new_inventory_value" => "₹" . number_format($newInventoryValue, 0),
            "new_inventory_value_raw" => round($newInventoryValue, 2)
        ]
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Import execution transaction error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header("Content-Type: application/json; charset=UTF-8");
    }
    echo json_encode([
        "status" => false,
        "message" => "Import transaction failed: " . $e->getMessage(),
        "data" => (object)[]
    ]);
    exit;
}
