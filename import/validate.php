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
require_once __DIR__ . "/../settings/settings_helper.php";

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
    $allRows = SpreadsheetReader::read($filePath);
    $extracted = SpreadsheetReader::extractHeaderAndRows($allRows);
    $headerRow = $extracted['headers'];
    $rows = $extracted['rows'];
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Unable to read spreadsheet: " . $e->getMessage(),
        "data" => (object)[]
    ]);
    exit;
}

if (count($headerRow) < 1 || count($rows) < 1) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Spreadsheet contains no data rows.",
        "data" => (object)[]
    ]);
    exit;
}

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
        "message" => "Validation failed: " . implode(", ", $missingRequired) . " column(s) are required and must be mapped.",
        "data" => (object)[]
    ]);
    exit;
}

// If SKU is not mapped, check if a barcode column is present in the headers
$colBarcodeIdx = null;
if ($colSkuIdx === null) {
    $colBarcodeIdx = ColumnMatcher::resolveColumnIndex('sku', null, $headerRow, true);
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

// Fetch existing products for shop and shop import mode setting
$shopSettings = get_shop_settings($pdo, $ownerId);
$shopImportMode = strtoupper(trim($input['import_mode'] ?? ($shopSettings['import_mode'] ?? 'ADD')));

$skuStmt = $pdo->prepare("SELECT sku, name, units, cost_price, price FROM products WHERE shop_owner_id = ?");
$skuStmt->execute([$ownerId]);
$existingProductsMap = [];
$existingProductsByNameMap = [];
while ($p = $skuStmt->fetch(PDO::FETCH_ASSOC)) {
    $existingProductsMap[strtoupper(trim($p['sku']))] = $p;
    $existingProductsByNameMap[strtolower(trim($p['name']))] = $p;
}

$rowsChecked = count($rows);
$errors = [];
$validatedRows = [];
$skippedRowsCount = 0;
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

    // Check if row is explicitly marked to be skipped via corrections
    $isSkippedRow = false;
    if (isset($correctionsMap[$rowNumber]['action']) && $correctionsMap[$rowNumber]['action'] === 'skip') {
        $isSkippedRow = true;
    } elseif (isset($correctionsMap[$rowNumber]['units']) && $correctionsMap[$rowNumber]['units'] === 'skip') {
        $isSkippedRow = true;
    }
    if ($isSkippedRow) {
        $skippedRowsCount++;
        continue;
    }

    $skuUpper = strtoupper($rawSku);
    $nameLower = strtolower($rawName);
    $existingProduct = $existingProductsMap[$skuUpper] ?? ($existingProductsByNameMap[$nameLower] ?? null);
    $isExisting = ($existingProduct !== null);

    $rawUnits = ($colUnitsIdx !== null && isset($row[$colUnitsIdx])) ? trim($row[$colUnitsIdx]) : '';
    $forceSetZero = false;
    if (isset($correctionsMap[$rowNumber]['units'])) {
        $corrUnits = $correctionsMap[$rowNumber]['units'];
        if ($corrUnits === 0 || $corrUnits === '0' || $corrUnits === 'set_zero') {
            $forceSetZero = true;
            $rawUnits = '0';
        } else {
            $rawUnits = trim((string)$corrUnits);
        }
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
            "reason" => "Quantity is required.",
            "suggested_correction" => null,
            "suggested_label" => null
        ];
    } elseif (!is_numeric($rawUnits) || (float)$rawUnits != (int)(float)$rawUnits) {
        // Detect stray letters (e.g. 18O -> letter O instead of 0)
        $hasLetterO = (stripos($rawUnits, 'o') !== false);
        $digitsOnly = preg_replace('/[^0-9]/', '', $rawUnits);
        $suggestedFix = ($digitsOnly !== '') ? (int)$digitsOnly : null;

        $reason = $hasLetterO
            ? "\"{$rawUnits}\" contains the letter O. Quantity must be a whole number."
            : "\"{$rawUnits}\" contains invalid non-numeric characters. Quantity must be a whole number.";

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
            "suggested_label" => ($suggestedFix !== null) ? "{$suggestedFix} units" : null
        ];
    } else {
        $intUnits = (int)(float)$rawUnits;
        if ($intUnits < 0) {
            $rowErrors[] = [
                "row_number" => $rowNumber,
                "field" => "units",
                "error_type" => "QUANTITY_ERROR",
                "error_badge" => "ROW {$rowNumber} · NEGATIVE QUANTITY",
                "product_name" => $rawName,
                "sku" => $rawSku,
                "imported_value" => $rawUnits,
                "reason" => "Quantity cannot be negative.",
                "suggested_correction" => null,
                "suggested_label" => null
            ];
        } elseif ($intUnits === 0) {
            // Quantity 0 is valid when the product is new (or in set-stock/REPLACE mode, or if forced to set zero).
            // Only flag as error if it is an existing product in additive mode.
            if ($isExisting && $shopImportMode === 'ADD' && !$forceSetZero) {
                $rowErrors[] = [
                    "row_number" => $rowNumber,
                    "field" => "units",
                    "error_type" => "QUANTITY_ZERO_EXISTING",
                    "error_badge" => "ROW {$rowNumber} · ZERO QUANTITY",
                    "product_name" => $rawName,
                    "sku" => $rawSku,
                    "imported_value" => "0",
                    "reason" => "Quantity to add must be more than 0 for existing products",
                    "suggested_correction" => 0,
                    "suggested_label" => "Import with 0 units (out of stock)",
                    "actions" => [
                        [
                            "action" => "set_zero",
                            "label" => "Import with 0 units (out of stock)",
                            "value" => 0
                        ],
                        [
                            "action" => "skip_row",
                            "label" => "Skip this row",
                            "value" => "skip"
                        ]
                    ]
                ];
            } else {
                $parsedUnits = 0;
            }
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
        if ($isExisting) {
            $existingUpdatedCount++;
            $currentStock = (int)$existingProduct['units'];
        } else {
            $newCreatedCount++;
            $currentStock = 0;
        }

        $newStock = ($forceSetZero || $shopImportMode === 'REPLACE') ? $parsedUnits : ($currentStock + $parsedUnits);
        $totalStockToAdd += $parsedUnits;
        $totalValueToAdd += ($parsedUnits * $costVal);

        $displayCalc = ($forceSetZero || $shopImportMode === 'REPLACE')
            ? "Set to {$parsedUnits}"
            : "{$currentStock} + {$parsedUnits} = {$newStock}";

        $stockPreview[] = [
            "name" => $rawName,
            "sku" => $rawSku,
            "current_units" => $currentStock,
            "units_to_add" => $parsedUnits,
            "new_units" => $newStock,
            "display_calculation" => $displayCalc,
            "is_new" => !$isExisting,
            "is_out_of_stock" => ($newStock === 0),
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
$readyRowsCount = $rowsChecked - $invalidRowsCount - $skippedRowsCount;
$isReadyToImport = ($invalidRowsCount === 0);

http_response_code(200);
echo json_encode([
    "status" => true,
    "message" => $isReadyToImport
        ? "Validation complete: all {$readyRowsCount} valid rows are ready for import."
        : "Validation found {$invalidRowsCount} error(s) across rows.",
    "data" => [
        "rows_checked" => $rowsChecked,
        "ready_rows" => $readyRowsCount,
        "errors_remaining" => $invalidRowsCount,
        "skipped_rows" => $skippedRowsCount,
        "can_skip_invalid" => ($invalidRowsCount > 0 && $readyRowsCount > 0),
        "ready_to_import" => $isReadyToImport,
        "errors" => $errors,
        "summary" => [
            "existing_products_to_update" => $existingUpdatedCount,
            "new_products_to_create" => $newCreatedCount,
            "skipped_rows" => $skippedRowsCount,
            "total_stock_to_add" => $totalStockToAdd,
            "total_stock_to_add_formatted" => "{$totalStockToAdd} units",
            "inventory_value_to_add" => round($totalValueToAdd, 2),
            "inventory_value_to_add_formatted" => "₹" . number_format($totalValueToAdd, 0)
        ],
        "stock_preview" => $stockPreview
    ]
]);
