<?php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI execution only.\n";
    exit(1);
}

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../config/db.php';

echo "=== TEST: BUNDLE OFFERS ===\n";

$passCount = 0;
$failCount = 0;

function assertOfferTest(bool $cond, string $label, string $detail = ''): void {
    global $passCount, $failCount;
    if ($cond) {
        echo " [PASS] {$label}\n";
        $passCount++;
    } else {
        echo "![FAIL] {$label}" . ($detail !== '' ? " - {$detail}" : "") . "\n";
        $failCount++;
    }
}

function callOfferApi(string $method, string $path, string $token, ?array $body = null): array {
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
    $stmtOwner->execute(['Offer Owner A', 'Offer Shop A', "bo_a_{$uniq}@example.com", $passHash]);
    $shopAId = (int)$pdo->lastInsertId();
    $shopIds[] = $shopAId;
    $tokenA = 'tok_bo_a_' . bin2hex(random_bytes(16));
    $stmtTok->execute([$shopAId, hash('sha256', $tokenA)]);

    // Shop B
    $stmtOwner->execute(['Offer Owner B', 'Offer Shop B', "bo_b_{$uniq}@example.com", $passHash]);
    $shopBId = (int)$pdo->lastInsertId();
    $shopIds[] = $shopBId;
    $tokenB = 'tok_bo_b_' . bin2hex(random_bytes(16));
    $stmtTok->execute([$shopBId, hash('sha256', $tokenB)]);

    // Products in Shop A
    $stmtProd = $pdo->prepare("
        INSERT INTO products (shop_owner_id, name, sku, category, price, cost_price, units, min_stock_threshold, status, created_at)
        VALUES (?, ?, ?, 'Beverages', ?, ?, 100, 5, 'In stock', NOW())
    ");
    $stmtProd->execute([$shopAId, 'Tea Pack', "BOTEA_{$uniq}", 60.00, 40.00]);
    $p1 = (int)$pdo->lastInsertId();
    $stmtProd->execute([$shopAId, 'Biscuit Pack', "BOBIS_{$uniq}", 40.00, 25.00]);
    $p2 = (int)$pdo->lastInsertId();
    $stmtProd->execute([$shopAId, 'Milk Pack', "BOMLK_{$uniq}", 30.00, 20.00]);
    $p3 = (int)$pdo->lastInsertId();

    // Products in Shop B
    $stmtProd->execute([$shopBId, 'Shop B Item 1', "BOB1_{$uniq}", 50.00, 30.00]);
    $pb1 = (int)$pdo->lastInsertId();
    $stmtProd->execute([$shopBId, 'Shop B Item 2', "BOB2_{$uniq}", 50.00, 30.00]);
    $pb2 = (int)$pdo->lastInsertId();

    // Create 32 bills in Shop A so product_pairs.php qualifies (p1, p2)
    $stmtSale = $pdo->prepare("INSERT INTO sales (shop_owner_id, bill_no, total_amount, sale_date, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmtSaleItem = $pdo->prepare("INSERT INTO sales_items (sale_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?)");
    for ($i = 1; $i <= 32; $i++) {
        $dt = date('Y-m-d H:i:s', strtotime("-{$i} hours"));
        if ($i <= 6) {
            $stmtSale->execute([$shopAId, "BO{$i}-{$uniq}", 100.00, $dt]);
            $sid = (int)$pdo->lastInsertId();
            $stmtSaleItem->execute([$sid, $p1, 1, 60.00, 60.00]);
            $stmtSaleItem->execute([$sid, $p2, 1, 40.00, 40.00]);
        } else {
            $stmtSale->execute([$shopAId, "BO{$i}-{$uniq}", 30.00, $dt]);
            $sid = (int)$pdo->lastInsertId();
            $stmtSaleItem->execute([$sid, $p3, 1, 30.00, 30.00]);
        }
    }

    $minP = min($p1, $p2);
    $maxP = max($p1, $p2);
    $expectedPairId = "pair_{$minP}_{$maxP}";

    // (a) & (h): Accept creates one active offer with server price (ignoring app-sent price)
    $acc1 = callOfferApi('POST', 'insights/respond.php', $tokenA, [
        'recommendation_type' => 'product_pair',
        'product_id_1' => $p1,
        'product_id_2' => $p2,
        'action' => 'accepted',
        'offer_price' => 1.00,
        'combined_price' => 999.00
    ]);
    $offer1 = $acc1['json']['data']['offer'] ?? null;
    assertOfferTest(
        $acc1['code'] === 200 && is_array($offer1) && $offer1['status'] === 'active',
        "(a) Accept product_pair creates active offer"
    );
    assertOfferTest(
        abs((float)($offer1['combined_price'] ?? 0) - 100.0) < 0.01 &&
        abs((float)($offer1['offer_price'] ?? 0) - 90.0) < 0.01,
        "(a)(h) Server calculates combined_price=100.00 and offer_price=90.00 (ignores app-sent price)"
    );
    $offerAId = (int)($offer1['id'] ?? 0);

    // (b) & (c): Accepting twice (including reverse order) does not duplicate
    $acc2 = callOfferApi('POST', 'insights/respond.php', $tokenA, [
        'recommendation_type' => 'product_pair',
        'product_id_1' => $p2,
        'product_id_2' => $p1,
        'action' => 'accepted'
    ]);
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM bundle_offers WHERE shop_owner_id = ? AND status = 'active'");
    $countStmt->execute([$shopAId]);
    $activeCountDb = (int)$countStmt->fetchColumn();
    assertOfferTest(
        $acc2['code'] === 200 && $activeCountDb === 1 && (int)($acc2['json']['data']['offer']['id'] ?? 0) === $offerAId,
        "(b)(c) Accepting twice & in reverse order returns existing active offer without duplicating"
    );

    // (f): Pair with active offer is hidden from product_pairs.php (even with include_accepted=1)
    $pairsHidden = callOfferApi('GET', 'insights/product_pairs.php?include_accepted=1', $tokenA);
    $foundPair = false;
    foreach (($pairsHidden['json']['data']['items'] ?? []) as $it) {
        if (($it['pair_id'] ?? '') === $expectedPairId) {
            $foundPair = true;
        }
    }
    assertOfferTest(!$foundPair, "(f) Pair with active offer is hidden from product_pairs.php");

    // Shop B accepts its own pair
    $accB = callOfferApi('POST', 'insights/respond.php', $tokenB, [
        'recommendation_type' => 'product_pair',
        'product_id_1' => $pb1,
        'product_id_2' => $pb2,
        'action' => 'accepted'
    ]);
    $offerBId = (int)($accB['json']['data']['offer']['id'] ?? 0);
    assertOfferTest($accB['code'] === 200 && $offerBId > 0, "Shop B creates its own offer");

    // (d): list returns only this shop's offers
    $listA = callOfferApi('GET', 'offers/list.php', $tokenA);
    $offersA = $listA['json']['data']['offers'] ?? [];
    $onlyShopA = count($offersA) === 1 && (int)($offersA[0]['id'] ?? 0) === $offerAId;
    assertOfferTest($listA['code'] === 200 && $onlyShopA, "(d) offers/list.php returns only Shop A's offers");

    // (e): another shop cannot end Shop A's offer; Shop A can end it
    $endByB = callOfferApi('POST', 'offers/end.php', $tokenB, ['offer_id' => $offerAId]);
    assertOfferTest($endByB['code'] === 404, "(e) Shop B cannot end Shop A's offer (returns 404)");

    $endByA = callOfferApi('POST', 'offers/end.php', $tokenA, ['offer_id' => $offerAId]);
    assertOfferTest(
        $endByA['code'] === 200 && ($endByA['json']['data']['status'] ?? '') === 'ended' && !empty($endByA['json']['data']['ended_at']),
        "(e) Shop A can end its own offer"
    );

    // Ended offer respects 14-day accepted rule: hidden by default, shown with include_accepted=1
    $pairsAfterEndDefault = callOfferApi('GET', 'insights/product_pairs.php', $tokenA);
    $pairsAfterEndInclude = callOfferApi('GET', 'insights/product_pairs.php?include_accepted=1', $tokenA);
    $foundDefault = false;
    foreach (($pairsAfterEndDefault['json']['data']['items'] ?? []) as $it) {
        if (($it['pair_id'] ?? '') === $expectedPairId) $foundDefault = true;
    }
    $foundInclude = false;
    foreach (($pairsAfterEndInclude['json']['data']['items'] ?? []) as $it) {
        if (($it['pair_id'] ?? '') === $expectedPairId) $foundInclude = true;
    }
    assertOfferTest(!$foundDefault && $foundInclude, "Ended offer follows 14-day accepted rule in product_pairs.php");

    // (g): Product delete removes its offers (ON DELETE CASCADE)
    $delStmt = $pdo->prepare("DELETE FROM products WHERE id = ? AND shop_owner_id = ?");
    $delStmt->execute([$pb1, $shopBId]);
    $checkBOffer = $pdo->prepare("SELECT COUNT(*) FROM bundle_offers WHERE id = ?");
    $checkBOffer->execute([$offerBId]);
    assertOfferTest((int)$checkBOffer->fetchColumn() === 0, "(g) Deleting product cascades and removes its bundle offers");

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
