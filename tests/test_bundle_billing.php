<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI execution only.\n";
    exit(1);
}

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../config/db.php';

echo "=== TEST: BUNDLE BILLING (RECORD SALE & PREVIEW) ===\n";

$passCount = 0;
$failCount = 0;

function assertBillTest(bool $cond, string $label, string $detail = ''): void {
    global $passCount, $failCount;
    if ($cond) {
        echo " [PASS] {$label}\n";
        $passCount++;
    } else {
        echo "![FAIL] {$label}" . ($detail !== '' ? " - {$detail}" : "") . "\n";
        $failCount++;
    }
}

function callBillApi(string $method, string $path, string $token, ?array $body = null): array {
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

$shopIds = [];

try {
    $pdo->exec("SET time_zone = '+05:30'");
    $uniq = time() . '_' . bin2hex(random_bytes(3));
    $passHash = password_hash('Secret123!', PASSWORD_DEFAULT);

    $stmtOwner = $pdo->prepare("INSERT INTO owner_account (full_name, shop_name, email, password, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmtTok = $pdo->prepare("INSERT INTO tokens (user_id, token_hash, expires_at, created_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())");

    // Shop A
    $stmtOwner->execute(['Bill Owner A', 'Bill Shop A', "bb_a_{$uniq}@example.com", $passHash]);
    $shopAId = (int)$pdo->lastInsertId();
    $shopIds[] = $shopAId;
    $tokenA = 'tok_bb_a_' . bin2hex(random_bytes(16));
    $stmtTok->execute([$shopAId, hash('sha256', $tokenA)]);

    // Shop B
    $stmtOwner->execute(['Bill Owner B', 'Bill Shop B', "bb_b_{$uniq}@example.com", $passHash]);
    $shopBId = (int)$pdo->lastInsertId();
    $shopIds[] = $shopBId;
    $tokenB = 'tok_bb_b_' . bin2hex(random_bytes(16));
    $stmtTok->execute([$shopBId, hash('sha256', $tokenB)]);

    // Products in Shop A: A = 60, B = 40 (combined = 100, 10% off => discount 10 per bundle)
    $stmtProd = $pdo->prepare("
        INSERT INTO products (shop_owner_id, name, sku, category, price, cost_price, units, min_stock_threshold, status, created_at)
        VALUES (?, ?, ?, 'Beverages', ?, ?, ?, 2, 'In stock', NOW())
    ");
    $stmtProd->execute([$shopAId, 'Tea Pack', "BBTEA_{$uniq}", 60.00, 40.00, 50]);
    $pA = (int)$pdo->lastInsertId();
    $stmtProd->execute([$shopAId, 'Biscuit Pack', "BBBIS_{$uniq}", 40.00, 25.00, 50]);
    $pB = (int)$pdo->lastInsertId();

    // Products in Shop B
    $stmtProd->execute([$shopBId, 'Shop B Item 1', "BBB1_{$uniq}", 50.00, 30.00, 50]);
    $pb1 = (int)$pdo->lastInsertId();
    $stmtProd->execute([$shopBId, 'Shop B Item 2', "BBB2_{$uniq}", 50.00, 30.00, 50]);
    $pb2 = (int)$pdo->lastInsertId();

    // (j): Insert an old bill BEFORE any bundle offer exists
    $oldSale = callBillApi('POST', 'sales/create.php', $tokenA, [
        'bill_no' => "OLD-{$uniq}",
        'items' => [
            ['product_id' => $pA, 'quantity' => 1],
            ['product_id' => $pB, 'quantity' => 1]
        ]
    ]);
    assertBillTest(
        $oldSale['code'] === 201 && abs((float)($oldSale['json']['data']['total_amount'] ?? 0) - 100.0) < 0.01 && abs((float)($oldSale['json']['data']['bundle_discount'] ?? -1)) < 0.01,
        "(j) Old bill before offer has total=100.00 and bundle_discount=0.00"
    );

    // Create active bundle offer for Shop A (pA + pB: combined 100, offer 90 => 10 discount)
    $stmtOffer = $pdo->prepare("
        INSERT INTO bundle_offers (shop_owner_id, product_id_1, product_id_2, offer_price, combined_price, status, created_at)
        VALUES (?, ?, ?, ?, ?, 'active', NOW())
    ");
    $stmtOffer->execute([$shopAId, min($pA, $pB), max($pA, $pB), 90.00, 100.00]);
    $offerAId = (int)$pdo->lastInsertId();

    // Create active bundle offer for Shop B
    $stmtOffer->execute([$shopBId, min($pb1, $pb2), max($pb1, $pb2), 90.00, 100.00]);
    $offerBId = (int)$pdo->lastInsertId();

    // (b): Only one product -> no discount
    $prevOne = callBillApi('POST', 'sales/preview_offers.php', $tokenA, [
        'items' => [['product_id' => $pA, 'quantity' => 2]]
    ]);
    assertBillTest(
        $prevOne['code'] === 200 && abs((float)($prevOne['json']['data']['bundle_discount'] ?? -1)) < 0.01 && abs((float)($prevOne['json']['data']['total_amount'] ?? 0) - 120.0) < 0.01,
        "(b) Only one product -> no discount (total=120.00)"
    );

    // (a) & (c): Qty 3 of A and 2 of B -> 2 offers => subtotal 260, discount 20, total 240
    $prevBoth = callBillApi('POST', 'sales/preview_offers.php', $tokenA, [
        'items' => [
            ['product_id' => $pA, 'quantity' => 3],
            ['product_id' => $pB, 'quantity' => 2]
        ]
    ]);
    assertBillTest(
        $prevBoth['code'] === 200 &&
        abs((float)($prevBoth['json']['data']['subtotal'] ?? 0) - 260.0) < 0.01 &&
        abs((float)($prevBoth['json']['data']['bundle_discount'] ?? 0) - 20.0) < 0.01 &&
        abs((float)($prevBoth['json']['data']['total_amount'] ?? 0) - 240.0) < 0.01 &&
        (int)($prevBoth['json']['data']['applied_offers'][0]['applications'] ?? 0) === 2,
        "(a)(c) Qty 3 of A and 2 of B -> 2 offers (subtotal=260, discount=20, total=240)"
    );

    // (g): App-sent discount or price is ignored; create sale with 3 of A and 2 of B
    $saleWithOffer = callBillApi('POST', 'sales/create.php', $tokenA, [
        'bill_no' => "BO1-{$uniq}",
        'bundle_discount' => 999.00,
        'total_amount' => 1.00,
        'items' => [
            ['product_id' => $pA, 'quantity' => 3, 'unit_price' => 1.00],
            ['product_id' => $pB, 'quantity' => 2, 'unit_price' => 1.00]
        ]
    ]);
    assertBillTest(
        $saleWithOffer['code'] === 201 &&
        abs((float)($saleWithOffer['json']['data']['bundle_discount'] ?? 0) - 20.0) < 0.01 &&
        abs((float)($saleWithOffer['json']['data']['total_amount'] ?? 0) - 240.0) < 0.01,
        "(g) App-sent discount and unit_price are ignored; server charges 240.00"
    );

    // (i): Stock reduces correctly for both products (50 - 1 (old) - 3 = 46 for A; 50 - 1 - 2 = 47 for B)
    $uA = (int)$pdo->query("SELECT units FROM products WHERE id = {$pA}")->fetchColumn();
    $uB = (int)$pdo->query("SELECT units FROM products WHERE id = {$pB}")->fetchColumn();
    assertBillTest($uA === 46 && $uB === 47, "(i) Stock reduced accurately (A={$uA}, B={$uB})");

    // (d): Owner removes the offer -> no discount
    $saleRemoved = callBillApi('POST', 'sales/create.php', $tokenA, [
        'bill_no' => "BO2-{$uniq}",
        'removed_offer_ids' => [$offerAId],
        'items' => [
            ['product_id' => $pA, 'quantity' => 1],
            ['product_id' => $pB, 'quantity' => 1]
        ]
    ]);
    assertBillTest(
        $saleRemoved['code'] === 201 &&
        abs((float)($saleRemoved['json']['data']['bundle_discount'] ?? -1)) < 0.01 &&
        abs((float)($saleRemoved['json']['data']['total_amount'] ?? 0) - 100.0) < 0.01,
        "(d) Removed offer -> no discount (total=100.00)"
    );

    // (f): Another shop's offer never applies (and passing Shop B's offer ID in removed_offer_ids is rejected)
    $crossShop = callBillApi('POST', 'sales/create.php', $tokenA, [
        'bill_no' => "BO_CROSS-{$uniq}",
        'removed_offer_ids' => [$offerBId],
        'items' => [
            ['product_id' => $pA, 'quantity' => 1],
            ['product_id' => $pB, 'quantity' => 1]
        ]
    ]);
    assertBillTest($crossShop['code'] === 400, "(f) Passing another shop's offer_id is rejected (400)");

    // (h): Sales summary / analytics uses paid amount (100 + 240 + 100 = 440.00)
    $summary = callBillApi('GET', 'sales/summary.php?period=today', $tokenA);
    assertBillTest(
        $summary['code'] === 200 && abs((float)($summary['json']['data']['total_sales_amount'] ?? 0) - 440.0) < 0.01,
        "(h) sales/summary.php total_sales_amount uses net paid amount (440.00)"
    );

    // (e): Ended offer -> new bills get no discount, old bills keep theirs
    $endRes = callBillApi('POST', 'offers/end.php', $tokenA, ['offer_id' => $offerAId]);
    assertBillTest($endRes['code'] === 200, "Ended offer A");

    $saleAfterEnd = callBillApi('POST', 'sales/create.php', $tokenA, [
        'bill_no' => "BO3-{$uniq}",
        'items' => [
            ['product_id' => $pA, 'quantity' => 1],
            ['product_id' => $pB, 'quantity' => 1]
        ]
    ]);
    assertBillTest(
        $saleAfterEnd['code'] === 201 &&
        abs((float)($saleAfterEnd['json']['data']['bundle_discount'] ?? -1)) < 0.01 &&
        abs((float)($saleAfterEnd['json']['data']['total_amount'] ?? 0) - 100.0) < 0.01,
        "(e) Ended offer -> new bill has no discount (total=100.00)"
    );

    // (j): Old bill BO1 still has its 20.00 discount and 240.00 total
    $oldBillRow = $pdo->query("SELECT total_amount, bundle_discount FROM sales WHERE shop_owner_id = {$shopAId} AND bill_no = 'BO1-{$uniq}'")->fetch(PDO::FETCH_ASSOC);
    assertBillTest(
        $oldBillRow && abs((float)$oldBillRow['total_amount'] - 240.0) < 0.01 && abs((float)$oldBillRow['bundle_discount'] - 20.0) < 0.01,
        "(j) Previous discounted bill BO1 keeps total=240.00 and bundle_discount=20.00 after offer ended"
    );

} finally {
    if (!empty($shopIds)) {
        $in = implode(',', array_map('intval', $shopIds));
        $pdo->exec("DELETE FROM bundle_offers WHERE shop_owner_id IN ({$in})");
        $pdo->exec("DELETE si FROM sales_items si INNER JOIN sales s ON si.sale_id = s.id WHERE s.shop_owner_id IN ({$in})");
        $pdo->exec("DELETE FROM sales WHERE shop_owner_id IN ({$in})");
        $pdo->exec("DELETE FROM products WHERE shop_owner_id IN ({$in})");
        $pdo->exec("DELETE FROM tokens WHERE user_id IN ({$in})");
        $pdo->exec("DELETE FROM owner_account WHERE id IN ({$in})");
    }
}

echo "\nResults: {$passCount} passed, {$failCount} failed\n";
exit($failCount > 0 ? 1 : 0);
