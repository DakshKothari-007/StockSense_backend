<?php

/**
 * Automated verification for GET inventory/export_products.php:
 *  - Auth required (missing/invalid Bearer token rejected with 401)
 *  - Empty shop returns JSON 404 "No products to export" (not an empty CSV)
 *  - Multi-tenant isolation: only logged-in shop's products exported, never another shop's
 *  - Content-Type text/csv; charset=UTF-8, Content-Disposition attachment filename stocksense_products_YYYYMMDD.csv, UTF-8 BOM
 *  - Exact import-compatible column headers/order + re-import ColumnMatcher roundtrip
 *  - Inactive & Archived products included
 *  - CSV injection protection (=, +, -, @ prefixed with single quote)
 *  - Per-shop rate limit (10 exports/hour -> 11th returns 429)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI execution only.\n";
    exit(1);
}

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../import/SpreadsheetReader.php';
require_once __DIR__ . '/../import/ColumnMatcher.php';

echo "=== TEST: INVENTORY PRODUCT CSV EXPORT ===\n";

$passCount = 0;
$failCount = 0;

function assertExportTest(bool $cond, string $label, string $detail = ''): void {
    global $passCount, $failCount;
    if ($cond) {
        echo " [PASS] {$label}\n";
        $passCount++;
    } else {
        echo "![FAIL] {$label}" . ($detail !== '' ? " - {$detail}" : "") . "\n";
        $failCount++;
    }
}

function callExportEndpoint(?string $token): array {
    $ch = curl_init('http://localhost/StockSense_backend/inventory/export_products.php');
    $headers = [];
    if ($token !== null) {
        $headers[] = "Authorization: Bearer {$token}";
    }
    $respHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($curl, $headerLine) use (&$respHeaders) {
            $len = strlen($headerLine);
            $parts = explode(':', $headerLine, 2);
            if (count($parts) === 2) {
                $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $len;
        }
    ]);
    $body = (string)curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code' => $code,
        'headers' => $respHeaders,
        'body' => $body,
        'json' => json_decode($body, true)
    ];
}

$shopIds = [];
$tempCsv = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'export_roundtrip_' . time() . '_' . random_int(100, 999) . '.csv';

try {
    $pdo->exec("DELETE FROM rate_limits WHERE action = 'export_products'");

    // Create Shop A (initially empty), Shop B (with secret items), Shop C (populated + injection + Unicode + Archived)
    $uniq = time() . '_' . bin2hex(random_bytes(3));
    $passHash = password_hash('Secret123!', PASSWORD_DEFAULT);

    $stmtOwner = $pdo->prepare("INSERT INTO owner_account (full_name, shop_name, email, password, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmtTok = $pdo->prepare("INSERT INTO tokens (user_id, token_hash, expires_at, created_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())");

    // Empty shop
    $stmtOwner->execute(['Empty Owner', 'Empty Shop', "empty_exp_{$uniq}@example.com", $passHash]);
    $emptyShopId = (int)$pdo->lastInsertId();
    $shopIds[] = $emptyShopId;
    $emptyToken = 'tok_empty_' . bin2hex(random_bytes(16));
    $stmtTok->execute([$emptyShopId, hash('sha256', $emptyToken)]);

    // Shop A
    $stmtOwner->execute(['Owner A', 'Shop A Store', "shopa_exp_{$uniq}@example.com", $passHash]);
    $shopAId = (int)$pdo->lastInsertId();
    $shopIds[] = $shopAId;
    $tokenA = 'tok_a_' . bin2hex(random_bytes(16));
    $stmtTok->execute([$shopAId, hash('sha256', $tokenA)]);

    // Shop B
    $stmtOwner->execute(['Owner B', 'Shop B Store', "shopb_exp_{$uniq}@example.com", $passHash]);
    $shopBId = (int)$pdo->lastInsertId();
    $shopIds[] = $shopBId;
    $tokenB = 'tok_b_' . bin2hex(random_bytes(16));
    $stmtTok->execute([$shopBId, hash('sha256', $tokenB)]);

    $stmtProd = $pdo->prepare("
        INSERT INTO products (shop_owner_id, name, sku, category, description, price, cost_price, units, min_stock_threshold, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    // Seed Shop A: normal + Hindi/Tamil UTF-8, Archived/Inactive, and CSV injection formulas (=, +, -, @)
    $stmtProd->execute([
        $shopAId,
        'आशीर्वाद आटा / அரிசி 5kg',
        'EXP-UNI-01',
        'Staples',
        'Whole wheat flour, premium pack',
        265.50,
        220.00,
        24,
        5,
        'Active'
    ]);
    $stmtProd->execute([
        $shopAId,
        'Old Discontinued Soap',
        'EXP-ARC-02',
        'Personal Care',
        'Archived seasonal item',
        45.00,
        32.00,
        0,
        10,
        'Archived'
    ]);
    $stmtProd->execute([
        $shopAId,
        'Inactive Tea Pack',
        'EXP-INA-03',
        'Beverages',
        'Temporarily paused',
        120.00,
        95.00,
        4,
        8,
        'Inactive'
    ]);
    $stmtProd->execute([
        $shopAId,
        "=CMD|'/C calc'!A0",
        '+INJECT-SKU',
        '-FORMULA-CAT',
        '@SUM(A1:A2)',
        99.00,
        70.00,
        12,
        3,
        '=HYPERLINK("http://evil")'
    ]);

    // Seed Shop B secret product (must never leak into Shop A export)
    $stmtProd->execute([
        $shopBId,
        'Top Secret Shop B Diamond Rice',
        'SECRET-B-999',
        'SecretCat',
        'Belongs only to B',
        999.00,
        800.00,
        50,
        10,
        'Active'
    ]);

    // 1. No token / Invalid token rejected
    $resNoAuth = callExportEndpoint(null);
    assertExportTest($resNoAuth['code'] === 401, 'Missing Bearer token rejected with HTTP 401', "HTTP {$resNoAuth['code']}");

    $resBadAuth = callExportEndpoint('invalid_token_xyz_123');
    assertExportTest($resBadAuth['code'] === 401, 'Invalid Bearer token rejected with HTTP 401', "HTTP {$resBadAuth['code']}");

    // 2. Empty shop returns JSON error "No products to export"
    $resEmpty = callExportEndpoint($emptyToken);
    assertExportTest(
        $resEmpty['code'] === 404 &&
        ($resEmpty['json']['status'] ?? true) === false &&
        ($resEmpty['json']['message'] ?? '') === 'No products to export',
        'Empty shop returns JSON 404 with "No products to export"',
        "HTTP {$resEmpty['code']}: {$resEmpty['body']}"
    );

    // 3. Shop A CSV export succeeds with proper HTTP headers, BOM, headers, Unicode, Archived, injection escaping, isolation
    $resA = callExportEndpoint($tokenA);
    $expectedFile = 'stocksense_products_' . date('Ymd') . '.csv';
    $ct = $resA['headers']['content-type'] ?? '';
    $cd = $resA['headers']['content-disposition'] ?? '';

    assertExportTest($resA['code'] === 200, 'Shop A CSV export returns HTTP 200', "HTTP {$resA['code']}");
    assertExportTest(
        stripos($ct, 'text/csv') !== false && stripos($ct, 'utf-8') !== false,
        'Content-Type is text/csv; charset=UTF-8',
        "Got: {$ct}"
    );
    assertExportTest(
        stripos($cd, 'attachment') !== false && strpos($cd, $expectedFile) !== false,
        "Content-Disposition is attachment with filename {$expectedFile}",
        "Got: {$cd}"
    );
    assertExportTest(
        substr($resA['body'], 0, 3) === "\xEF\xBB\xBF",
        'CSV response starts with UTF-8 BOM'
    );

    file_put_contents($tempCsv, $resA['body']);

    // Parse CSV directly (stripping BOM on first check)
    $csvNoBom = substr($resA['body'], 3);
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $csvNoBom);
    rewind($fh);
    $parsedRows = [];
    while (($r = fgetcsv($fh)) !== false) {
        $parsedRows[] = $r;
    }
    fclose($fh);

    $expectedHeaders = [
        'Product Name',
        'SKU',
        'Category',
        'Quantity',
        'Cost Price',
        'Selling Price',
        'Minimum Stock',
        'Description',
        'Status'
    ];
    assertExportTest(
        isset($parsedRows[0]) && $parsedRows[0] === $expectedHeaders,
        'CSV headers match import template columns and order',
        json_encode($parsedRows[0] ?? [])
    );
    assertExportTest(
        count($parsedRows) === 5,
        'CSV contains header + all 4 Shop A products (including Archived and Inactive)',
        'Row count: ' . count($parsedRows)
    );
    assertExportTest(
        strpos($resA['body'], 'आशीर्वाद आटा / அரிசி 5kg') !== false,
        'Hindi & Tamil UTF-8 characters preserved intact'
    );
    assertExportTest(
        strpos($resA['body'], 'EXP-ARC-02') !== false && strpos($resA['body'], 'EXP-INA-03') !== false,
        'Archived and Inactive products included in export'
    );
    assertExportTest(
        strpos($resA['body'], 'Top Secret Shop B Diamond Rice') === false && strpos($resA['body'], 'SECRET-B-999') === false,
        'Multi-tenant isolation: Shop B data never appears in Shop A export'
    );

    // Injection row checks (row index 4)
    $injRow = $parsedRows[4] ?? [];
    assertExportTest(($injRow[0] ?? '') === "'=CMD|'/C calc'!A0", 'Cell starting with = is prefixed with single quote', json_encode($injRow[0] ?? ''));
    assertExportTest(($injRow[1] ?? '') === "'+INJECT-SKU", 'Cell starting with + is prefixed with single quote', json_encode($injRow[1] ?? ''));
    assertExportTest(($injRow[2] ?? '') === "'-FORMULA-CAT", 'Cell starting with - is prefixed with single quote', json_encode($injRow[2] ?? ''));
    assertExportTest(($injRow[7] ?? '') === "'@SUM(A1:A2)", 'Cell starting with @ is prefixed with single quote', json_encode($injRow[7] ?? ''));
    assertExportTest(($injRow[8] ?? '') === "'=HYPERLINK(\"http://evil\")", 'Status starting with = is prefixed with single quote', json_encode($injRow[8] ?? ''));

    // Roundtrip compatibility with SpreadsheetReader + ColumnMatcher
    $preview = SpreadsheetReader::getHeadersAndPreview($tempCsv);
    $detected = ColumnMatcher::detect($preview['headers'], $preview['preview_rows'], $pdo, $shopAId);
    $m = $detected['mappings'] ?? [];
    assertExportTest(
        ($detected['required_matched'] ?? false) === true &&
        ($m['name'] ?? '') === 'Product Name' &&
        ($m['sku'] ?? '') === 'SKU' &&
        ($m['category'] ?? '') === 'Category' &&
        ($m['units'] ?? '') === 'Quantity' &&
        ($m['cost_price'] ?? '') === 'Cost Price' &&
        ($m['price'] ?? '') === 'Selling Price' &&
        ($m['minimum_stock'] ?? '') === 'Minimum Stock',
        'Exported CSV is cleanly auto-detected by import ColumnMatcher for re-import',
        json_encode($m)
    );

    // 4. Rate limit enforcement: 10 exports/hour allowed, 11th returns 429
    $pdo->exec("DELETE FROM rate_limits WHERE action = 'export_products'");
    $allTenOk = true;
    for ($i = 1; $i <= 10; $i++) {
        $r = callExportEndpoint($tokenB);
        if ($r['code'] !== 200) {
            $allTenOk = false;
            echo "  Unexpected failure on export attempt #{$i}: HTTP {$r['code']}\n";
            break;
        }
    }
    assertExportTest($allTenOk, 'First 10 export requests within an hour succeed with HTTP 200');

    $res11 = callExportEndpoint($tokenB);
    assertExportTest(
        $res11['code'] === 429 && ($res11['json']['status'] ?? true) === false,
        '11th export request within an hour is rate-limited with HTTP 429',
        "HTTP {$res11['code']}: {$res11['body']}"
    );
} finally {
    @unlink($tempCsv);
    $pdo->exec("DELETE FROM rate_limits WHERE action = 'export_products'");
    if (!empty($shopIds)) {
        $ph = implode(',', array_fill(0, count($shopIds), '?'));
        $pdo->prepare("DELETE FROM products WHERE shop_owner_id IN ({$ph})")->execute($shopIds);
        $pdo->prepare("DELETE FROM tokens WHERE user_id IN ({$ph})")->execute($shopIds);
        $pdo->prepare("DELETE FROM owner_account WHERE id IN ({$ph})")->execute($shopIds);
    }
}

echo "\nTotal Passed: {$passCount}, Failed: {$failCount}\n";
exit($failCount > 0 ? 1 : 0);
