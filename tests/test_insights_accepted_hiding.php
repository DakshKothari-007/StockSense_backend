<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI execution only.\n";
    exit(1);
}

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../config/db.php';

echo "=== TEST: INSIGHTS ACCEPTED HIDING (14 DAYS) & ISOLATION ===\n";

$passCount = 0;
$failCount = 0;

function assertInsightsTest(bool $cond, string $label, string $detail = ''): void {
    global $passCount, $failCount;
    if ($cond) {
        echo " [PASS] {$label}\n";
        $passCount++;
    } else {
        echo "![FAIL] {$label}" . ($detail !== '' ? " - {$detail}" : "") . "\n";
        $failCount++;
    }
}

function callEndpoint(string $method, string $path, string $token, ?array $body = null): array {
    $ch = curl_init('http://localhost/StockSense_backend/' . ltrim($path, '/'));
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = (string)curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [
        'code' => $code,
        'raw' => $raw,
        'json' => json_decode($raw, true)
    ];
}

function containsProduct(array $items, int $productId): bool {
    foreach ($items as $it) {
        if ((int)($it['product_id'] ?? 0) === $productId) {
            return true;
        }
    }
    return false;
}

function containsPair(array $items, string $pairId): bool {
    foreach ($items as $it) {
        if (($it['pair_id'] ?? '') === $pairId) {
            return true;
        }
    }
    return false;
}

$shopIds = [];

try {
    $pdo->exec("SET time_zone = '+05:30'");
    $uniq = time() . '_' . bin2hex(random_bytes(3));
    $passHash = password_hash('Secret123!', PASSWORD_DEFAULT);

    $stmtOwner = $pdo->prepare("INSERT INTO owner_account (full_name, shop_name, email, password, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmtTok = $pdo->prepare("INSERT INTO tokens (user_id, token_hash, expires_at, created_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())");

    // Shop A
    $stmtOwner->execute(['Owner A', 'Shop A Insights', "ins_a_{$uniq}@example.com", $passHash]);
    $shopAId = (int)$pdo->lastInsertId();
    $shopIds[] = $shopAId;
    $tokenA = 'tok_ins_a_' . bin2hex(random_bytes(16));
    $stmtTok->execute([$shopAId, hash('sha256', $tokenA)]);

    // Shop B (for multi-tenant isolation test)
    $stmtOwner->execute(['Owner B', 'Shop B Insights', "ins_b_{$uniq}@example.com", $passHash]);
    $shopBId = (int)$pdo->lastInsertId();
    $shopIds[] = $shopBId;
    $tokenB = 'tok_ins_b_' . bin2hex(random_bytes(16));
    $stmtTok->execute([$shopBId, hash('sha256', $tokenB)]);

    // Create products in Shop A:
    // - pSlow1, pSlow2: slow-moving (0 sold in last 30 days, units > min_stock_threshold)
    // - pPair1, pPair2, pPair3: active selling products for product pairs
    $stmtProd = $pdo->prepare("
        INSERT INTO products (shop_owner_id, name, sku, category, price, cost_price, units, min_stock_threshold, status, created_at)
        VALUES (?, ?, ?, 'Snacks', ?, ?, ?, ?, 'In stock', NOW())
    ");

    $stmtProd->execute([$shopAId, 'Slow Item 1', "SLOW1_{$uniq}", 100.00, 70.00, 50, 5]);
    $pSlow1 = (int)$pdo->lastInsertId();

    $stmtProd->execute([$shopAId, 'Slow Item 2', "SLOW2_{$uniq}", 120.00, 80.00, 40, 5]);
    $pSlow2 = (int)$pdo->lastInsertId();

    $stmtProd->execute([$shopAId, 'Tea Pack', "TEA_{$uniq}", 50.00, 35.00, 100, 5]);
    $pPair1 = (int)$pdo->lastInsertId();

    $stmtProd->execute([$shopAId, 'Sugar Pack', "SUG_{$uniq}", 40.00, 28.00, 100, 5]);
    $pPair2 = (int)$pdo->lastInsertId();

    $stmtProd->execute([$shopAId, 'Milk Pack', "MLK_{$uniq}", 30.00, 20.00, 100, 5]);
    $pPair3 = (int)$pdo->lastInsertId();

    // Create 1 old sale (35 days ago) so Shop A has >= 30 days sales history for slow_moving
    $stmtSale = $pdo->prepare("
        INSERT INTO sales (shop_owner_id, bill_no, total_amount, sale_date, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmtSaleItem = $pdo->prepare("
        INSERT INTO sales_items (sale_id, product_id, quantity, unit_price, line_total)
        VALUES (?, ?, ?, ?, ?)
    ");

    $oldDate = date('Y-m-d H:i:s', strtotime('-35 days'));
    $stmtSale->execute([$shopAId, "OLD-{$uniq}", 50.00, $oldDate]);
    $oldSaleId = (int)$pdo->lastInsertId();
    $stmtSaleItem->execute([$oldSaleId, $pPair1, 1, 50.00, 50.00]);

    // Create 32 recent bills in Shop A so product_pairs meets min_total_bills = 30
    // 6 bills with (pPair1 + pPair2), 26 bills with pPair3 only => strong lift > 1 and support > 0.05
    for ($i = 1; $i <= 32; $i++) {
        $dt = date('Y-m-d H:i:s', strtotime("-{$i} hours"));
        $billNo = "B{$i}-{$uniq}";
        if ($i <= 6) {
            $stmtSale->execute([$shopAId, $billNo, 90.00, $dt]);
            $sid = (int)$pdo->lastInsertId();
            $stmtSaleItem->execute([$sid, $pPair1, 1, 50.00, 50.00]);
            $stmtSaleItem->execute([$sid, $pPair2, 1, 40.00, 40.00]);
        } else {
            $stmtSale->execute([$shopAId, $billNo, 30.00, $dt]);
            $sid = (int)$pdo->lastInsertId();
            $stmtSaleItem->execute([$sid, $pPair3, 1, 30.00, 30.00]);
        }
    }

    $minP = min($pPair1, $pPair2);
    $maxP = max($pPair1, $pPair2);
    $expectedPairId = "pair_{$minP}_{$maxP}";

    // Baseline: both pSlow1, pSlow2 and expectedPairId appear, and dashboard slow_moving_count matches slow_moving.php
    $baseSlow = callEndpoint('GET', 'insights/slow_moving.php', $tokenA);
    $baseDash = callEndpoint('GET', 'dashboard/summary.php', $tokenA);
    $basePairs = callEndpoint('GET', 'insights/product_pairs.php', $tokenA);
    assertInsightsTest(
        $baseSlow['code'] === 200 && containsProduct($baseSlow['json']['data']['items'] ?? [], $pSlow1),
        "Baseline slow_moving includes pSlow1"
    );
    assertInsightsTest(
        $baseDash['code'] === 200 && (int)($baseDash['json']['data']['inventory']['slow_moving_count'] ?? -1) === count($baseSlow['json']['data']['items'] ?? []),
        "Baseline dashboard slow_moving_count equals slow_moving.php item count"
    );
    assertInsightsTest(
        $basePairs['code'] === 200 && containsPair($basePairs['json']['data']['items'] ?? [], $expectedPairId),
        "Baseline product_pairs includes {$expectedPairId}"
    );

    // Case (e): Shop B response on its own products never affects Shop A
    $stmtProd->execute([$shopBId, 'Shop B Slow', "SLOWB_{$uniq}", 100.00, 70.00, 50, 5]);
    $pSlowB = (int)$pdo->lastInsertId();
    $respB = callEndpoint('POST', 'insights/respond.php', $tokenB, [
        'recommendation_type' => 'slow_moving',
        'product_id_1' => $pSlowB,
        'action' => 'accepted'
    ]);
    assertInsightsTest($respB['code'] === 200, "Shop B can accept its own slow_moving item");
    // Also insert a rogue row or verify Shop A still sees pSlow1
    $afterBSlow = callEndpoint('GET', 'insights/slow_moving.php', $tokenA);
    assertInsightsTest(
        containsProduct($afterBSlow['json']['data']['items'] ?? [], $pSlow1),
        "(e) Shop B response never hides Shop A's pSlow1"
    );

    // Case (a): Accepted today -> hidden (both slow_moving and product_pairs)
    $accSlow = callEndpoint('POST', 'insights/respond.php', $tokenA, [
        'recommendation_type' => 'slow_moving',
        'product_id_1' => $pSlow1,
        'action' => 'accepted'
    ]);
    $accPair = callEndpoint('POST', 'insights/respond.php', $tokenA, [
        'recommendation_type' => 'product_pair',
        'product_id_1' => $pPair1,
        'product_id_2' => $pPair2,
        'action' => 'accepted'
    ]);
    assertInsightsTest($accSlow['code'] === 200 && $accPair['code'] === 200, "Accepting slow_moving & product_pair succeeds");

    $slowAfterAcc = callEndpoint('GET', 'insights/slow_moving.php', $tokenA);
    $dashAfterAcc = callEndpoint('GET', 'dashboard/summary.php', $tokenA);
    $pairsAfterAcc = callEndpoint('GET', 'insights/product_pairs.php', $tokenA);
    assertInsightsTest(
        !containsProduct($slowAfterAcc['json']['data']['items'] ?? [], $pSlow1),
        "(a) slow_moving accepted today -> hidden"
    );
    assertInsightsTest(
        $dashAfterAcc['code'] === 200 &&
        (int)($dashAfterAcc['json']['data']['inventory']['slow_moving_count'] ?? -1) === count($slowAfterAcc['json']['data']['items'] ?? []) &&
        (int)($dashAfterAcc['json']['data']['inventory']['slow_moving_count'] ?? -1) === (int)($baseDash['json']['data']['inventory']['slow_moving_count'] ?? 0) - 1,
        "(a) dashboard slow_moving_count drops by 1 after accept and matches slow_moving.php"
    );
    assertInsightsTest(
        !containsPair($pairsAfterAcc['json']['data']['items'] ?? [], $expectedPairId),
        "(a) product_pair accepted today -> hidden"
    );

    // End the active bundle offer created by accepting product_pair so we test the 14-day accepted rule
    $endPairOfferStmt = $pdo->prepare("UPDATE bundle_offers SET status = 'ended', ended_at = NOW() WHERE shop_owner_id = ?");
    $endPairOfferStmt->execute([$shopAId]);

    // Case (d): include_accepted=1 -> shown
    $slowWithAcc = callEndpoint('GET', 'insights/slow_moving.php?include_accepted=1', $tokenA);
    $pairsWithAcc = callEndpoint('GET', 'insights/product_pairs.php?include_accepted=1', $tokenA);
    assertInsightsTest(
        containsProduct($slowWithAcc['json']['data']['items'] ?? [], $pSlow1),
        "(d) slow_moving with include_accepted=1 -> shown"
    );
    assertInsightsTest(
        containsPair($pairsWithAcc['json']['data']['items'] ?? [], $expectedPairId),
        "(d) product_pair with include_accepted=1 -> shown"
    );

    // Case (b): Accepted 15 days ago -> shown again
    $upd15 = $pdo->prepare("
        UPDATE recommendation_responses
        SET updated_at = DATE_SUB(NOW(), INTERVAL 15 DAY)
        WHERE shop_owner_id = ?
    ");
    $upd15->execute([$shopAId]);

    $slowAfter15 = callEndpoint('GET', 'insights/slow_moving.php', $tokenA);
    $pairsAfter15 = callEndpoint('GET', 'insights/product_pairs.php', $tokenA);
    assertInsightsTest(
        containsProduct($slowAfter15['json']['data']['items'] ?? [], $pSlow1),
        "(b) slow_moving accepted 15 days ago -> shown again"
    );
    assertInsightsTest(
        containsPair($pairsAfter15['json']['data']['items'] ?? [], $expectedPairId),
        "(b) product_pair accepted 15 days ago -> shown again"
    );

    // Case (c): Dismissed -> hidden (unchanged)
    $disSlow = callEndpoint('POST', 'insights/respond.php', $tokenA, [
        'recommendation_type' => 'slow_moving',
        'product_id_1' => $pSlow2,
        'action' => 'dismissed'
    ]);
    assertInsightsTest($disSlow['code'] === 200, "Dismissing pSlow2 succeeds");

    $slowAfterDis = callEndpoint('GET', 'insights/slow_moving.php', $tokenA);
    $slowWithAccOnly = callEndpoint('GET', 'insights/slow_moving.php?include_accepted=1', $tokenA);
    $slowWithDis = callEndpoint('GET', 'insights/slow_moving.php?include_dismissed=1', $tokenA);
    assertInsightsTest(
        !containsProduct($slowAfterDis['json']['data']['items'] ?? [], $pSlow2),
        "(c) dismissed slow_moving -> hidden by default"
    );
    assertInsightsTest(
        !containsProduct($slowWithAccOnly['json']['data']['items'] ?? [], $pSlow2),
        "(c) dismissed slow_moving stays hidden when only include_accepted=1"
    );
    assertInsightsTest(
        containsProduct($slowWithDis['json']['data']['items'] ?? [], $pSlow2),
        "(c) dismissed slow_moving is shown when include_dismissed=1"
    );

} finally {
    if (!empty($shopIds)) {
        $in = implode(',', array_map('intval', $shopIds));
        $pdo->exec("DELETE FROM bundle_offers WHERE shop_owner_id IN ({$in})");
        $pdo->exec("DELETE FROM recommendation_responses WHERE shop_owner_id IN ({$in})");
        $pdo->exec("DELETE si FROM sales_items si INNER JOIN sales s ON si.sale_id = s.id WHERE s.shop_owner_id IN ({$in})");
        $pdo->exec("DELETE FROM sales WHERE shop_owner_id IN ({$in})");
        $pdo->exec("DELETE FROM products WHERE shop_owner_id IN ({$in})");
        $pdo->exec("DELETE FROM tokens WHERE user_id IN ({$in})");
        $pdo->exec("DELETE FROM owner_account WHERE id IN ({$in})");
    }
}

echo "\nResults: {$passCount} passed, {$failCount} failed\n";
exit($failCount > 0 ? 1 : 0);
