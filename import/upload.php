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
        "message" => "Method Not Allowed. Use POST for file upload.",
        "data" => (object)[]
    ]);
    exit;
}

if (!isset($_FILES['file']) || empty($_FILES['file']['name'])) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Validation failed: 'file' parameter is required.",
        "data" => (object)[]
    ]);
    exit;
}

$file = $_FILES['file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE => "File exceeds upload_max_filesize directive in php.ini.",
        UPLOAD_ERR_FORM_SIZE => "File exceeds MAX_FILE_SIZE specified in form.",
        UPLOAD_ERR_PARTIAL => "File was only partially uploaded.",
        UPLOAD_ERR_NO_FILE => "No file was uploaded.",
        UPLOAD_ERR_NO_TMP_DIR => "Missing temporary upload folder on server.",
        UPLOAD_ERR_CANT_WRITE => "Failed to write file to disk.",
        UPLOAD_ERR_EXTENSION => "A PHP extension stopped the file upload."
    ];
    $msg = $uploadErrors[$file['error']] ?? "Unknown upload error occurred.";
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Upload error: " . $msg,
        "data" => (object)[]
    ]);
    exit;
}

// 1. File size limit: 5 MB (5 * 1024 * 1024 bytes)
$maxSizeBytes = 5 * 1024 * 1024;
if ($file['size'] > $maxSizeBytes) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "File size exceeds the 5 MB limit. Selected file size: " . round($file['size'] / 1024 / 1024, 2) . " MB.",
        "data" => (object)[]
    ]);
    exit;
}

// 2. Validate file extension and MIME type
$origName = basename($file['name']);
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
$allowedExts = ['csv', 'xlsx', 'xls'];

if (!in_array($ext, $allowedExts)) {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Invalid file format: .{$ext}. Only .csv, .xlsx, and .xls files are supported.",
        "data" => (object)[]
    ]);
    exit;
}

// 3. Ensure uploads/temp directory exists
$uploadDir = __DIR__ . "/../uploads/temp";
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0777, true)) {
        http_response_code(500);
        echo json_encode([
            "status" => false,
            "message" => "Server error: Unable to create temporary upload directory.",
            "data" => (object)[]
        ]);
        exit;
    }
}

// Generate unique safe file token
$safeExt = ($ext === 'xls') ? 'xlsx' : $ext;
$fileToken = "imp_" . $owner['id'] . "_" . bin2hex(random_bytes(8)) . "_" . time() . "." . $safeExt;
$targetPath = $uploadDir . "/" . $fileToken;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Server error: Failed to store uploaded file.",
        "data" => (object)[]
    ]);
    exit;
}

// 4. Parse spreadsheet headers and initial preview
try {
    $parsed = SpreadsheetReader::getHeadersAndPreview($targetPath, 5);
} catch (Throwable $e) {
    @unlink($targetPath);
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Unable to read spreadsheet: " . $e->getMessage(),
        "data" => (object)[]
    ]);
    exit;
}

$headers = $parsed['headers'];
$previewRows = $parsed['preview_rows'];
$totalRows = $parsed['total_rows'];

if (empty($headers)) {
    @unlink($targetPath);
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Spreadsheet contains no header row or is empty.",
        "data" => (object)[]
    ]);
    exit;
}

// 5. Intelligent suggested mapping based on headers
$suggestedMapping = [
    "sku" => null,
    "name" => null,
    "units" => null,
    "cost_price" => null,
    "price" => null
];

foreach ($headers as $header) {
    $cleanH = strtolower(trim($header));

    if (!$suggestedMapping['sku'] && preg_match('/^(sku|code|item_id|barcode|product_code|item_code)$/i', $cleanH)) {
        $suggestedMapping['sku'] = $header;
    } elseif (!$suggestedMapping['name'] && preg_match('/^(product|name|item|product_name|title|description)$/i', $cleanH)) {
        $suggestedMapping['name'] = $header;
    } elseif (!$suggestedMapping['units'] && preg_match('/^(quantity|units|qty|stock|units_to_add|count|add)$/i', $cleanH)) {
        $suggestedMapping['units'] = $header;
    } elseif (!$suggestedMapping['cost_price'] && preg_match('/^(cost|cost_price|unit_cost|purchase_price|buy_price)$/i', $cleanH)) {
        $suggestedMapping['cost_price'] = $header;
    } elseif (!$suggestedMapping['price'] && preg_match('/^(price|selling_price|sell_price|mrp|unit_price|rate)$/i', $cleanH)) {
        $suggestedMapping['price'] = $header;
    }
}

// Fallback to position if headers are standard 5 columns
if (!$suggestedMapping['sku'] && isset($headers[0])) $suggestedMapping['sku'] = $headers[0];
if (!$suggestedMapping['name'] && isset($headers[1])) $suggestedMapping['name'] = $headers[1];
if (!$suggestedMapping['units'] && isset($headers[2])) $suggestedMapping['units'] = $headers[2];
if (!$suggestedMapping['cost_price'] && isset($headers[3])) $suggestedMapping['cost_price'] = $headers[3];
if (!$suggestedMapping['price'] && isset($headers[4])) $suggestedMapping['price'] = $headers[4];

// Format human-readable file size
$formattedSize = ($file['size'] >= 1048576)
    ? round($file['size'] / 1048576, 2) . " MB"
    : max(1, round($file['size'] / 1024)) . " KB";

http_response_code(200);
echo json_encode([
    "status" => true,
    "message" => "File uploaded and parsed successfully",
    "data" => [
        "file_token" => $fileToken,
        "file_name" => $origName,
        "file_size" => $formattedSize,
        "file_size_bytes" => (int)$file['size'],
        "total_rows_detected" => $totalRows,
        "headers" => $headers,
        "preview_rows" => $previewRows,
        "suggested_mapping" => $suggestedMapping
    ]
]);
