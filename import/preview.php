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

$owner = authenticateShopOwner($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "status" => false,
        "message" => "Method Not Allowed. Use POST for import preview.",
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

// Token security: verify token pattern and shop owner isolation
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
        "message" => "Spreadsheet is empty.",
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

// Determine column mappings
$userMapping = $input['column_mapping'] ?? [];
$resolvedMapping = [
    'sku' => null,
    'name' => null,
    'units' => null,
    'cost_price' => null,
    'price' => null
];

// Helper to find column index
$resolveIndex = function($field, $defaultIdx, $regexPatterns) use ($userMapping, $headerMap, $headerRow) {
    if (!empty($userMapping[$field])) {
        $target = trim($userMapping[$field]);
        if (isset($headerMap[$target])) {
            return $headerMap[$target];
        }
        if (isset($headerMap[strtolower($target)])) {
            return $headerMap[strtolower($target)];
        }
        if (is_numeric($target) && isset($headerRow[(int)$target])) {
            return (int)$target;
        }
    }
    // Auto detect from header names
    foreach ($headerRow as $i => $h) {
        $hClean = strtolower(trim($h));
        if (preg_match($regexPatterns, $hClean)) {
            return $i;
        }
    }
    return isset($headerRow[$defaultIdx]) ? $defaultIdx : null;
};

$colSkuIdx = $resolveIndex('sku', 0, '/^(sku|code|item_id|barcode|product_code)$/i');
$colNameIdx = $resolveIndex('name', 1, '/^(product|name|title|product_name|item)$/i');
$colUnitsIdx = $resolveIndex('units', 2, '/^(quantity|units|qty|stock|units_to_add|count|add)$/i');
$colCostIdx = $resolveIndex('cost_price', 3, '/^(cost|unit_cost|cost_price|buy_price|purchase_price)$/i');
$colPriceIdx = $resolveIndex('price', 4, '/^(price|selling_price|sell_price|mrp|unit_price|rate)$/i');

$resolvedMapping = [
    'sku' => ($colSkuIdx !== null && isset($headerRow[$colSkuIdx])) ? $headerRow[$colSkuIdx] : 'SKU',
    'name' => ($colNameIdx !== null && isset($headerRow[$colNameIdx])) ? $headerRow[$colNameIdx] : 'Product',
    'units' => ($colUnitsIdx !== null && isset($headerRow[$colUnitsIdx])) ? $headerRow[$colUnitsIdx] : 'Quantity',
    'cost_price' => ($colCostIdx !== null && isset($headerRow[$colCostIdx])) ? $headerRow[$colCostIdx] : 'Cost',
    'price' => ($colPriceIdx !== null && isset($headerRow[$colPriceIdx])) ? $headerRow[$colPriceIdx] : 'Price'
];

// Fetch all existing SKUs for this shop owner in one query
$skuStmt = $pdo->prepare("SELECT sku, name, units, cost_price, price FROM products WHERE shop_owner_id = ?");
$skuStmt->execute([$ownerId]);
$existingProductsMap = [];
while ($p = $skuStmt->fetch(PDO::FETCH_ASSOC)) {
    $existingProductsMap[strtoupper(trim($p['sku']))] = $p;
}

$previewRows = [];
foreach ($rows as $index => $row) {
    $rawSku = ($colSkuIdx !== null && isset($row[$colSkuIdx])) ? trim($row[$colSkuIdx]) : '';
    $rawName = ($colNameIdx !== null && isset($row[$colNameIdx])) ? trim($row[$colNameIdx]) : '';
    $rawUnits = ($colUnitsIdx !== null && isset($row[$colUnitsIdx])) ? trim($row[$colUnitsIdx]) : '0';
    $rawCost = ($colCostIdx !== null && isset($row[$colCostIdx])) ? trim($row[$colCostIdx]) : '0';
    $rawPrice = ($colPriceIdx !== null && isset($row[$colPriceIdx])) ? trim($row[$colPriceIdx]) : '0';

    // Check if rawUnits contains errors (e.g. "18O" or stray letters)
    $isUnitsValidInt = preg_match('/^\d+$/', $rawUnits);
    $isHighlighted = !$isUnitsValidInt || empty($rawSku) || empty($rawName);

    $cleanCostNum = preg_replace('/[^0-9.]/', '', $rawCost);
    $cleanPriceNum = preg_replace('/[^0-9.]/', '', $rawPrice);

    $skuUpper = strtoupper($rawSku);
    $existingProduct = $existingProductsMap[$skuUpper] ?? null;

    $sellPriceDisplay = ($cleanPriceNum !== '') ? "₹" . number_format((float)$cleanPriceNum, 0) : "₹0";
    $costPriceDisplay = ($cleanCostNum !== '') ? "₹" . number_format((float)$cleanCostNum, 0) : "₹0";

    $previewRows[] = [
        "row_number" => $index + 2, // Excel row number (1-based, header is 1)
        "name" => $rawName ?: ($existingProduct ? $existingProduct['name'] : 'Unknown Product'),
        "sku" => $rawSku,
        "skuWithSellPrice" => ($rawSku !== '' ? $rawSku : 'NO-SKU') . " · Sell " . $sellPriceDisplay,
        "unitsToAdd" => $rawUnits,
        "costPrice" => $costPriceDisplay,
        "sellingPrice" => $sellPriceDisplay,
        "isHighlighted" => $isHighlighted,
        "isExisting" => ($existingProduct !== null),
        "currentUnits" => $existingProduct ? (int)$existingProduct['units'] : 0
    ];
}

http_response_code(200);
echo json_encode([
    "status" => true,
    "message" => "Preview generated successfully",
    "data" => [
        "mapped_columns_count" => 5,
        "mappings" => $resolvedMapping,
        "total_rows" => count($previewRows),
        "preview_rows" => $previewRows
    ]
]);
