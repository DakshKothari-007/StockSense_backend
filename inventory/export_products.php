<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Id, X-User-Email");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode([
        "status" => false,
        "message" => "Only GET requests are allowed.",
        "data" => (object)[]
    ]);
    exit;
}

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . "/auth_helper.php";
require_once __DIR__ . "/../config/rate_limiter.php";

$owner = authenticateShopOwner($pdo);
$shopOwnerId = (int)$owner["id"];

// Rate limit: 10 exports per hour (60 minutes) per shop
enforce_rate_limit($pdo, 'export_products', (string)$shopOwnerId, 10, 60);
record_rate_limit_attempt($pdo, 'export_products', (string)$shopOwnerId);

/**
 * Prevents CSV formula injection when opening in Excel/Sheets by prefixing
 * values starting with =, +, -, or @ with a single quote.
 */
function sanitizeCsvCell($value): string {
    $str = (string)($value ?? '');
    if ($str !== '' && in_array($str[0], ['=', '+', '-', '@'], true)) {
        return "'" . $str;
    }
    return $str;
}

try {
    $stmt = $pdo->prepare("
        SELECT name, sku, category, units, cost_price, price, min_stock_threshold, description, status
        FROM products
        WHERE shop_owner_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$shopOwnerId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) === 0) {
        http_response_code(404);
        echo json_encode([
            "status" => false,
            "message" => "No products to export",
            "data" => (object)[]
        ]);
        exit;
    }

    $filename = "stocksense_products_" . date('Ymd') . ".csv";

    http_response_code(200);
    header("Content-Type: text/csv; charset=UTF-8");
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

    // Emit UTF-8 BOM so Excel renders Hindi/Tamil/Unicode names properly
    echo "\xEF\xBB\xBF";

    $output = fopen("php://output", "w");
    fputcsv($output, [
        "Product Name",
        "SKU",
        "Category",
        "Quantity",
        "Cost Price",
        "Selling Price",
        "Minimum Stock",
        "Description",
        "Status"
    ]);

    foreach ($rows as $row) {
        fputcsv($output, [
            sanitizeCsvCell($row["name"] ?? ""),
            sanitizeCsvCell($row["sku"] ?? ""),
            sanitizeCsvCell($row["category"] ?? "General"),
            sanitizeCsvCell((string)(int)($row["units"] ?? 0)),
            sanitizeCsvCell(number_format((float)($row["cost_price"] ?? 0), 2, '.', '')),
            sanitizeCsvCell(number_format((float)($row["price"] ?? 0), 2, '.', '')),
            sanitizeCsvCell((string)(int)($row["min_stock_threshold"] ?? 10)),
            sanitizeCsvCell($row["description"] ?? ""),
            sanitizeCsvCell($row["status"] ?? "")
        ]);
    }

    fclose($output);
    exit;
} catch (Throwable $e) {
    error_log("Failed to export products CSV: " . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode([
            "status" => false,
            "message" => "Unable to export products at this time.",
            "data" => (object)[]
        ]);
    }
    exit;
}
