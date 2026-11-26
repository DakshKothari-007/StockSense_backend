<?php

/**
 * Verifies AiEngine::dailySummary() and GET /ai/daily_summary.php aggregate
 * yesterday's DATETIME sales across different times of day (10:15 and 18:30),
 * use index-friendly date bounds excluding today and older days, and safely
 * invalidate stale zero-bill cache entries when new yesterday sales appear.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI execution only.\n";
    exit(1);
}

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/AiEngine.php';
require_once __DIR__ . '/../includes/PushNotifier.php';

echo "=== TEST: DAILY SUMMARY YESTERDAY SALES RANGE & CACHE SAFETY ===\n";

$passCount = 0;
$failCount = 0;

function assertSummaryTest(bool $condition, string $label, string $detail = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] {$label}\n";
        $passCount++;
    } else {
        echo "![FAIL] {$label}" . ($detail !== '' ? " - {$detail}" : "") . "\n";
        $failCount++;
    }
}

$shopId = 0;
$saleIds = [];
$productIds = [];
$token = '';

try {
    // 1. Create an isolated shop and bearer token
    $unique = time() . '_' . bin2hex(random_bytes(4));
    $email = "test_daily_summary_{$unique}@example.com";
    $passHash = password_hash('Secret123!', PASSWORD_DEFAULT);

    $pdo->prepare("
        INSERT INTO owner_account (full_name, shop_name, email, password, created_at)
        VALUES ('Test Summary Owner', 'Summary Test Store', ?, ?, NOW())
    ")->execute([$email, $passHash]);
    $shopId = (int)$pdo->lastInsertId();

    $token = 'test_ds_tok_' . bin2hex(random_bytes(16));
    $pdo->prepare("
        INSERT INTO tokens (user_id, token_hash, expires_at, created_at)
        VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())
    ")->execute([$shopId, hash('sha256', $token)]);

    // 2. Create two healthy-stock products
    $stmtProd = $pdo->prepare("
        INSERT INTO products (shop_owner_id, name, sku, category, units, min_stock_threshold, price, cost_price, status, created_at)
        VALUES (?, ?, ?, 'Grocery', 50, 5, ?, ?, 'Active', NOW())
    ");
    $stmtProd->execute([$shopId, 'Dove Soap 100g', 'DS-SOAP-' . $unique, 100.00, 70.00]);
    $soapId = (int)$pdo->lastInsertId();
    $productIds[] = $soapId;

    $stmtProd->execute([$shopId, 'Mango Drink 1L', 'DS-JUICE-' . $unique, 50.00, 35.00]);
    $juiceId = (int)$pdo->lastInsertId();
    $productIds[] = $juiceId;

    // 3. Populate initial zero-sale cache first (reproduces stale empty-summary cache bug)
    $initial = AiEngine::dailySummary($pdo, $shopId);
    assertSummaryTest(
        strpos($initial['summary'] ?? '', '0 across 0 bills') !== false,
        'Initial empty summary reports 0 bills before sales exist',
        json_encode($initial)
    );

    // 4. Determine MySQL CURDATE()-relative timestamps so PHP & MySQL boundaries stay aligned
    $yestDate = $pdo->query("SELECT DATE(CURDATE() - INTERVAL 1 DAY)")->fetchColumn();
    $twoDaysAgoDate = $pdo->query("SELECT DATE(CURDATE() - INTERVAL 2 DAY)")->fetchColumn();
    $todayDate = $pdo->query("SELECT DATE(CURDATE())")->fetchColumn();

    $stmtSale = $pdo->prepare("
        INSERT INTO sales (shop_owner_id, bill_no, sale_date, total_amount, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmtItem = $pdo->prepare("
        INSERT INTO sales_items (sale_id, product_id, quantity, unit_price, line_total)
        VALUES (?, ?, ?, ?, ?)
    ");

    // Yesterday 10:15:00 -> Rs. 400 (4 x Dove Soap)
    $stmtSale->execute([$shopId, 'DS-YEST-1-' . $unique, "{$yestDate} 10:15:00", 400.00]);
    $sale1Id = (int)$pdo->lastInsertId();
    $saleIds[] = $sale1Id;
    $stmtItem->execute([$sale1Id, $soapId, 4, 100.00, 400.00]);

    // Yesterday 18:30:00 -> Rs. 350 (2 x Dove Soap + 3 x Mango Drink)
    $stmtSale->execute([$shopId, 'DS-YEST-2-' . $unique, "{$yestDate} 18:30:00", 350.00]);
    $sale2Id = (int)$pdo->lastInsertId();
    $saleIds[] = $sale2Id;
    $stmtItem->execute([$sale2Id, $soapId, 2, 100.00, 200.00]);
    $stmtItem->execute([$sale2Id, $juiceId, 3, 50.00, 150.00]);

    // Out-of-range control bills (2 days ago & today) must NOT be counted in yesterday's summary
    $stmtSale->execute([$shopId, 'DS-OLD-' . $unique, "{$twoDaysAgoDate} 23:59:59", 999.00]);
    $oldSaleId = (int)$pdo->lastInsertId();
    $saleIds[] = $oldSaleId;
    $stmtItem->execute([$oldSaleId, $juiceId, 20, 49.95, 999.00]);

    $stmtSale->execute([$shopId, 'DS-TODAY-' . $unique, "{$todayDate} 00:00:00", 888.00]);
    $todaySaleId = (int)$pdo->lastInsertId();
    $saleIds[] = $todaySaleId;
    $stmtItem->execute([$todaySaleId, $juiceId, 18, 49.33, 888.00]);

    // 5. Without manually clearing ai_cache, call dailySummary again and verify cache invalidation + exact totals
    $after = AiEngine::dailySummary($pdo, $shopId);
    $summaryText = $after['summary'] ?? '';
    $expectedRev = PushNotifier::formatIndianCurrency(750.00);

    assertSummaryTest(
        strpos($summaryText, "Yesterday's sales: {$expectedRev} across 2 bills") !== false,
        "dailySummary shows {$expectedRev} across 2 bills for 10:15 and 18:30 sales (stale cache busted)",
        $summaryText
    );
    assertSummaryTest(
        strpos($summaryText, 'Top seller: Dove Soap 100g with 6 units') !== false,
        'dailySummary identifies correct yesterday top seller across both bills',
        $summaryText
    );

    // 6. Verify cache row key reflects bill count and TTL is <= 1 hour
    $stmtCache = $pdo->prepare("
        SELECT cache_key, TIMESTAMPDIFF(SECOND, NOW(), expires_at) AS ttl_remaining
        FROM ai_cache
        WHERE shop_id = ? AND cache_type = 'daily_summary'
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmtCache->execute([$shopId]);
    $cacheRow = $stmtCache->fetch(PDO::FETCH_ASSOC);
    assertSummaryTest(
        $cacheRow && strpos($cacheRow['cache_key'], '_b2') !== false,
        'Cache key includes yesterday bill count (_b2)',
        json_encode($cacheRow)
    );
    assertSummaryTest(
        $cacheRow && (int)$cacheRow['ttl_remaining'] > 0 && (int)$cacheRow['ttl_remaining'] <= 3600,
        'Daily summary cache TTL is <= 3600 seconds (1 hour)',
        json_encode($cacheRow)
    );

    // 7. Verify HTTP GET /ai/daily_summary.php returns the same updated summary
    $ch = curl_init('http://localhost/StockSense_backend/ai/daily_summary.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"]
    ]);
    $httpBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode((string)$httpBody, true);
    $apiSummary = $decoded['data']['summary'] ?? '';
    assertSummaryTest(
        $httpCode === 200 && !empty($decoded['status']) && strpos($apiSummary, "{$expectedRev} across 2 bills") !== false,
        'GET /ai/daily_summary.php returns 2 bills and exact total',
        "HTTP {$httpCode}: {$httpBody}"
    );
} finally {
    if ($shopId > 0) {
        $pdo->prepare("DELETE FROM ai_cache WHERE shop_id = ?")->execute([$shopId]);
        if (!empty($saleIds)) {
            $placeholders = implode(',', array_fill(0, count($saleIds), '?'));
            $pdo->prepare("DELETE FROM sales_items WHERE sale_id IN ({$placeholders})")->execute($saleIds);
            $pdo->prepare("DELETE FROM sales WHERE id IN ({$placeholders})")->execute($saleIds);
        }
        if (!empty($productIds)) {
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $pdo->prepare("DELETE FROM products WHERE id IN ({$placeholders})")->execute($productIds);
        }
        $pdo->prepare("DELETE FROM tokens WHERE user_id = ?")->execute([$shopId]);
        $pdo->prepare("DELETE FROM owner_account WHERE id = ?")->execute([$shopId]);
    }
}

echo "\nTotal Passed: {$passCount}, Failed: {$failCount}\n";
exit($failCount > 0 ? 1 : 0);
