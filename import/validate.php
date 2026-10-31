<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-User-Id, X-User-Email");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../inventory/auth_helper.php";
require_once __DIR__ . "/SpreadsheetReader.php";
require_once __DIR__ . "/ColumnMatcher.php";

$owner = authenticateShopOwner($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "status" => false,
        "message" => "Method Not Allowed. Use POST for validation.",
        "data" => (object)[]
    ]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true) ?? $_POST;

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

$ownerId = (int)$owner['id'];
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

// Check required mappings: name, sku, units must be mapped
$missingRequired = [];
if ($colNameIdx === null) $missingRequired[] = "Product Name (name)";
if ($colSkuIdx === null) $missingRequired[] = "SKU (sku)";
if ($colUnitsIdx === null) $missingRequired[] = "Quantity (units)";

if (!empty($missingRequired)) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Validation failed: " . implode(", ", $missingRequired) . " column(s) are required and must be mapped.",
        "data" => (object)[]
    ]);
    exit;
}

// Parse corrections if supplied
// e.g. [{"row_number": 4, "field": "units", "value": 18}]
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

// Fetch existing products for shop
$skuStmt = $pdo->prepare("SELECT sku, name, units, cost_price, price FROM products WHERE shop_owner_id = ?");
$skuStmt->execute([$ownerId]);
$existingProductsMap = [];
while ($p = $skuStmt->fetch(PDO::FETCH_ASSOC)) {
    $existingProductsMap[strtoupper(trim($p['sku']))] = $p;
}

$rowsChecked = count($rows);
$errors = [];
$validatedRows = [];
$existingUpdatedCount = 0;
$newCreatedCount = 0;
$totalStockToAdd = 0;
$totalValueToAdd = 0.0;
$stockPreview = [];

foreach ($rows as $index => $row) {
    $rowNumber = $index + 2; // Excel row number (Row 1 is headers)
    $rowErrors = [];

    // Extract values with corrections override
    $rawSku = ($colSkuIdx !== null && isset($row[$colSkuIdx])) ? trim($row[$colSkuIdx]) : '';
    if (isset($correctionsMap[$rowNumber]['sku'])) {
        $rawSku = trim((string)$correctionsMap[$rowNumber]['sku']);
    }

    $rawName = ($colNameIdx !== null && isset($row[$colNameIdx])) ? trim($row[$colNameIdx]) : '';
    if (isset($correctionsMap[$rowNumber]['name'])) {
        $rawName = trim((string)$correctionsMap[$rowNumber]['name']);
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

    // 1. Validate SKU
    if ($rawSku === '') {
        $rowErrors[] = [
            "row_number" => $rowNumber,
            "field" => "sku",
            "error_type" => "SKU_ERROR",
            "error_badge" => "ROW {$rowNumber} · SKU MISSING",
            "product_name" => $rawName ?: "Unknown Product",
            "sku" => "",
            "imported_value" => "",
            "reason" => "Product SKU is required.",
            "suggested_correction" => null,
            "suggested_label" => null
        ];
    } elseif (strlen($rawSku) > 50) {
        $rowErrors[] = [
            "row_number" => $rowNumber,
            "field" => "sku",
            "error_type" => "SKU_ERROR",
            "error_badge" => "ROW {$rowNumber} · SKU TOO LONG",
            "product_name" => $rawName ?: "Unknown Product",
            "sku" => $rawSku,
            "imported_value" => $rawSku,
            "reason" => "Product SKU exceeds maximum length of 50 characters.",
            "suggested_correction" => substr($rawSku, 0, 50),
            "suggested_label" => "Truncate SKU"
        ];
    }

    // 2. Validate Product Name
    if ($rawName === '') {
        $rowErrors[] = [
            "row_number" => $rowNumber,
            "field" => "name",
            "error_type" => "NAME_ERROR",
            "error_badge" => "ROW {$rowNumber} · NAME MISSING",
            "product_name" => "Unnamed Product",
            "sku" => $rawSku,
            "imported_value" => "",
            "reason" => "Product name is required.",
            "suggested_correction" => null,
            "suggested_label" => null
        ];
    }

    // 3. Validate Units / Quantity
    $parsedUnits = null;
    if ($rawUnits === '') {
        $rowErrors[] = [
            "row_number" => $rowNumber,
            "field" => "units",
            "error_type" => "QUANTITY_ERROR",
            "error_badge" => "ROW {$rowNumber} · QUANTITY MISSING",
            "product_name" => $rawName,
            "sku" => $rawSku,
            "imported_value" => "",
            "reason" => "Quantity to add is required.",
            "suggested_correction" => "1",
            "suggested_label" => "1 unit"
        ];
    } elseif (!preg_match('/^\d+$/', $rawUnits)) {
        // Detect stray letters (e.g. 18O -> letter O instead of 0)
        $hasLetterO = (stripos($rawUnits, 'o') !== false);
        $digitsOnly = preg_replace('/[^0-9]/', '', $rawUnits);
        $suggestedFix = ($digitsOnly !== '') ? (int)$digitsOnly : 1;

        $reason = $hasLetterO
            ? "\"{$rawUnits}\" contains the letter O. Quantity must be a whole number greater than zero."
            : "\"{$rawUnits}\" contains invalid non-numeric characters. Quantity must be a whole number greater than zero.";

        $rowErrors[] = [
            "row_number" => $rowNumber,
            "field" => "units",
            "error_type" => "QUANTITY_ERROR",
            "error_badge" => "ROW {$rowNumber} · QUANTITY ERROR",
            "product_name" => $rawName,
            "sku" => $rawSku,
            "imported_value" => $rawUnits,
            "reason" => $reason,
            "suggested_correction" => $suggestedFix,
            "suggested_label" => "{$suggestedFix} units"
        ];
    } else {
        $intUnits = (int)$rawUnits;
        if ($intUnits <= 0) {
            $rowErrors[] = [
                "row_number" => $rowNumber,
                "field" => "units",
                "error_type" => "QUANTITY_ERROR",
                "error_badge" => "ROW {$rowNumber} · ZERO QUANTITY",
                "product_name" => $rawName,
                "sku" => $rawSku,
                "imported_value" => $rawUnits,
                "reason" => "Quantity to add must be greater than zero.",
                "suggested_correction" => 1,
                "suggested_label" => "1 unit"
            ];
        } else {
            $parsedUnits = $intUnits;
        }
    }

    // 4. Validate Cost and Price
    $cleanCost = preg_replace('/[^0-9.]/', '', $rawCost);
    $costVal = is_numeric($cleanCost) ? (float)$cleanCost : 0.0;

    $cleanPrice = preg_replace('/[^0-9.]/', '', $rawPrice);
    $priceVal = is_numeric($cleanPrice) ? (float)$cleanPrice : 0.0;

    if ($costVal < 0) {
        $rowErrors[] = [
            "row_number" => $rowNumber,
            "field" => "cost_price",
            "error_type" => "PRICE_ERROR",
            "error_badge" => "ROW {$rowNumber} · NEGATIVE COST",
            "product_name" => $rawName,
            "sku" => $rawSku,
            "imported_value" => $rawCost,
            "reason" => "Cost price cannot be negative.",
            "suggested_correction" => 0,
            "suggested_label" => "₹0"
        ];
    }

    if ($priceVal < 0) {
        $rowErrors[] = [
            "row_number" => $rowNumber,
            "field" => "price",
            "error_type" => "PRICE_ERROR",
            "error_badge" => "ROW {$rowNumber} · NEGATIVE PRICE",
            "product_name" => $rawName,
            "sku" => $rawSku,
            "imported_value" => $rawPrice,
            "reason" => "Selling price cannot be negative.",
            "suggested_correction" => 0,
            "suggested_label" => "₹0"
        ];
    }

    if (!empty($rowErrors)) {
        foreach ($rowErrors as $err) {
            $errors[] = $err;
        }
    } else {
        // Valid row
        $skuUpper = strtoupper($rawSku);
        $existingProduct = $existingProductsMap[$skuUpper] ?? null;
        $isExisting = ($existingProduct !== null);

        if ($isExisting) {
            $existingUpdatedCount++;
            $currentStock = (int)$existingProduct['units'];
        } else {
            $newCreatedCount++;
            $currentStock = 0;
        }

        $newStock = $currentStock + $parsedUnits;
        $totalStockToAdd += $parsedUnits;
        $totalValueToAdd += ($parsedUnits * $costVal);

        $stockPreview[] = [
            "name" => $rawName,
            "sku" => $rawSku,
            "current_units" => $currentStock,
            "units_to_add" => $parsedUnits,
            "new_units" => $newStock,
            "display_calculation" => "{$currentStock} + {$parsedUnits} = {$newStock}",
            "is_new" => !$isExisting,
            "cost_price" => $costVal,
            "price" => $priceVal
        ];

        $validatedRows[] = [
            "row_number" => $rowNumber,
            "sku" => $rawSku,
            "name" => $rawName,
            "units" => $parsedUnits,
            "cost_price" => $costVal,
            "price" => $priceVal,
            "is_existing" => $isExisting
        ];
    }
}

$invalidRowsCount = count($errors);
$readyRowsCount = $rowsChecked - $invalidRowsCount;
$isReadyToImport = ($invalidRowsCount === 0);

http_response_code(200);
echo json_encode([
    "status" => true,
    "message" => $isReadyToImport
        ? "Validation complete: all {$rowsChecked} rows are ready for import."
        : "Validation found {$invalidRowsCount} error(s) across rows.",
    "data" => [
        "rows_checked" => $rowsChecked,
        "ready_rows" => $readyRowsCount,
        "errors_remaining" => $invalidRowsCount,
        "ready_to_import" => $isReadyToImport,
        "errors" => $errors,
        "summary" => [
            "existing_products_to_update" => $existingUpdatedCount,
            "new_products_to_create" => $newCreatedCount,
            "total_stock_to_add" => $totalStockToAdd,
            "total_stock_to_add_formatted" => "{$totalStockToAdd} units",
            "inventory_value_to_add" => round($totalValueToAdd, 2),
            "inventory_value_to_add_formatted" => "₹" . number_format($totalValueToAdd, 0)
        ],
        "stock_preview" => $stockPreview
    ]
]);
