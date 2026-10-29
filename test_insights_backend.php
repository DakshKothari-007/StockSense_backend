<?php

require_once __DIR__ . '/config/db.php';

echo "=== STOCKSENSE INSIGHTS BACKEND TEST SUITE ===\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($condition, $description) {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] $description\n";
        $passCount++;
    } else {
        echo " [FAIL] $description\n";
        $failCount++;
    }
}

function makeRequest($method, $path, $token = null, $data = null) {
    $url = "http://localhost/StockSense_backend/" . ltrim($path, '/');
    $ch = curl_init($url);
    
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    if ($token !== null) {
        $headers[] = "Authorization: Bearer $token";
    }
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $rawResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $json = json_decode($rawResponse, true);
    return [
        'code' => $httpCode,
        'raw' => $rawResponse,
        'json' => $json
    ];
}

// -------------------------------------------------------------
// 1. SETUP TEST USERS & LOGINS
// -------------------------------------------------------------
echo "1. Setting up test database state...\n";

// Login Shop 2 (Ananya, ID 2) and Shop 3 (Daksh, ID 3)
$login2 = makeRequest('POST', 'auth/login.php', null, ["email" => "ananya@example.com", "password" => "password123"]);
$tokenShop2 = $login2['json']['token'] ?? null;
assertTest($login2['code'] === 200 && strlen($tokenShop2) === 64, "Login Shop 2 (Ananya) returns valid 64-char token");

$login3 = makeRequest('POST', 'auth/login.php', null, ["email" => "daksh1@gmail.com", "password" => "password123"]);
$tokenShop3 = $login3['json']['token'] ?? null;
assertTest($login3['code'] === 200 && strlen($tokenShop3) === 64, "Login Shop 3 (Daksh) returns valid 64-char token");

$shopOwnerId = 2;
$otherOwnerId = 3;

// Clean previous responses for shop 2 & 3
$pdo->exec("DELETE FROM recommendation_responses WHERE shop_owner_id IN (2, 3)");
$pdo->exec("DELETE FROM rate_limits");
$pdo->exec("DELETE FROM login_attempts");

// Clean test sales items and sales for clean state
$pdo->exec("DELETE si FROM sales_items si JOIN sales s ON si.sale_id = s.id WHERE s.shop_owner_id = 2");
$pdo->exec("DELETE FROM sales WHERE shop_owner_id = 2");

// Ensure Shop 2 has distinct products for testing
// P1: units=50, min=10, price=100, cost=60 (normal cost)
// P2: units=30, min=5, price=200, cost=190 (high cost -> 10% discount gives 180 < 190 -> below cost!)
// P3: units=20, min=5, price=150, cost=null (null cost -> cost unknown)
// P4: units=5, min=10, price=80, cost=50 (units <= min_stock -> on reorder list -> excluded from slow moving)
// P5: units=0, min=5, price=120, cost=70 (out of stock -> excluded from slow moving)

$pdo->exec("DELETE FROM products WHERE sku LIKE 'TEST-INS-%'");

$insertProd = $pdo->prepare("
    INSERT INTO products (shop_owner_id, name, sku, category, units, min_stock_threshold, price, cost_price, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')
");

$insertProd->execute([$shopOwnerId, "Slow Prod Normal Cost", "TEST-INS-01", "Stationery", 50, 10, 100.00, 60.00]);
$p1Id = (int)$pdo->lastInsertId();

$insertProd->execute([$shopOwnerId, "Slow Prod High Cost", "TEST-INS-02", "Stationery", 30, 5, 200.00, 190.00]);
$p2Id = (int)$pdo->lastInsertId();

$insertProd->execute([$shopOwnerId, "Slow Prod Unknown Cost", "TEST-INS-03", "Stationery", 20, 5, 150.00, 0.00]);
$p3Id = (int)$pdo->lastInsertId();

$insertProd->execute([$shopOwnerId, "Reorder Prod At Min", "TEST-INS-04", "Stationery", 5, 10, 80.00, 50.00]);
$p4Id = (int)$pdo->lastInsertId();

$insertProd->execute([$shopOwnerId, "Out Of Stock Prod", "TEST-INS-05", "Stationery", 0, 5, 120.00, 70.00]);
$p5Id = (int)$pdo->lastInsertId();

// Shop 3 Product (Cross-shop isolation)
$insertProd->execute([$otherOwnerId, "Shop 3 Exclusive Prod", "TEST-INS-S3", "Electronics", 40, 5, 500.00, 300.00]);
$pShop3Id = (int)$pdo->lastInsertId();

echo "Seeded products for Shop 2: P1=$p1Id, P2=$p2Id, P3=$p3Id, P4=$p4Id, P5=$p5Id, Shop 3=$pShop3Id\n\n";

// -------------------------------------------------------------
// 2. TEST AUTHENTICATION & METHOD VALIDATION
// -------------------------------------------------------------
echo "2. Testing Auth & HTTP Methods...\n";

$resNoToken = makeRequest('GET', 'insights/slow_moving.php');
assertTest($resNoToken['code'] === 401, "GET insights/slow_moving.php without token returns 401");

$resPostSlow = makeRequest('POST', 'insights/slow_moving.php', $tokenShop2, []);
assertTest($resPostSlow['code'] === 405, "POST insights/slow_moving.php returns 405 Method Not Allowed");

$resGetRespond = makeRequest('GET', 'insights/respond.php', $tokenShop2);
assertTest($resGetRespond['code'] === 405, "GET insights/respond.php returns 405 Method Not Allowed");

// -------------------------------------------------------------
// 3. TEST SLOW-MOVING RECOMMENDATIONS
// -------------------------------------------------------------
echo "\n3. Testing Slow-Moving Recommendations...\n";

// Seed sales history for Shop 2: one sale 45 days ago to satisfy >= 30 days history requirement
$pastDate = date('Y-m-d H:i:s', strtotime('-45 days'));
$stmtSale = $pdo->prepare("INSERT INTO sales (shop_owner_id, bill_no, sale_date, total_amount) VALUES (?, ?, ?, ?)");
$stmtSale->execute([$shopOwnerId, "TEST-INS-OLD", $pastDate, 100.00]);
$oldSaleId = (int)$pdo->lastInsertId();
$stmtItem = $pdo->prepare("INSERT INTO sales_items (sale_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?)");
$stmtItem->execute([$oldSaleId, $p1Id, 1, 100.00, 100.00]);

$resSlow = makeRequest('GET', 'insights/slow_moving.php', $tokenShop2);
assertTest($resSlow['code'] === 200, "GET insights/slow_moving.php returns 200 OK");
assertTest($resSlow['json']['data']['has_enough_sales_history'] === true, "has_enough_sales_history is true for shop with >= 30 days history");

$slowItems = $resSlow['json']['data']['items'];
$foundP1 = false;
$foundP2 = false;
$foundP3 = false;
$foundP4 = false;
$foundP5 = false;

foreach ($slowItems as $it) {
    if ($it['product_id'] === $p1Id) {
        $foundP1 = $it;
    }
    if ($it['product_id'] === $p2Id) {
        $foundP2 = $it;
    }
    if ($it['product_id'] === $p3Id) {
        $foundP3 = $it;
    }
    if ($it['product_id'] === $p4Id) {
        $foundP4 = true;
    }
    if ($it['product_id'] === $p5Id) {
        $foundP5 = true;
    }
}

assertTest($foundP1 !== false, "Product P1 with 0 sales in 30 days is marked slow-moving");
assertTest($foundP4 === false, "Product P4 (on reorder list because units <= min_stock_threshold) is EXCLUDED from slow-moving");
assertTest($foundP5 === false, "Product P5 (units = 0) is EXCLUDED from slow-moving");

// Check clearance suggestion rules for P1 (above cost), P2 (below cost), P3 (cost unknown)
assertTest(
    $foundP1 && $foundP1['clearance_suggestion']['discount_pct'] === 10 && $foundP1['clearance_suggestion']['suggested_price'] == 90.00 && $foundP1['clearance_suggestion']['margin_status'] === 'above cost',
    "P1 (price 100, cost 60): single discount 10% gives ₹90, margin_status is 'above cost'"
);

assertTest(
    $foundP2 && $foundP2['clearance_suggestion']['suggested_price'] == 180.00 && $foundP2['clearance_suggestion']['margin_status'] === 'below cost' && $foundP2['clearance_suggestion']['warning'] === 'below cost',
    "P2 (price 200, cost 190): single discount 10% gives ₹180 < cost 190, margin_status is 'below cost' with warning"
);

assertTest(
    $foundP3 && $foundP3['clearance_suggestion']['margin_status'] === 'cost unknown' && $foundP3['clearance_suggestion']['warning'] === 'cost unknown',
    "P3 (cost_price null): margin_status is 'cost unknown' with warning"
);

// -------------------------------------------------------------
// 4. TEST PRODUCT PAIRS & BASKET ANALYSIS
// -------------------------------------------------------------
echo "\n4. Testing Product Pairs...\n";

// Validate min_support parameter validation
$resInvalidSup = makeRequest('GET', 'insights/product_pairs.php?min_support=1.5', $tokenShop2);
assertTest($resInvalidSup['code'] === 400, "min_support > 1 returns 400 Bad Request");

$resInvalidSupNeg = makeRequest('GET', 'insights/product_pairs.php?min_support=-0.1', $tokenShop2);
assertTest($resInvalidSupNeg['code'] === 400, "min_support < 0 returns 400 Bad Request");

// Test shop with < 30 bills
$resPairsFew = makeRequest('GET', 'insights/product_pairs.php', $tokenShop2);
assertTest($resPairsFew['code'] === 200 && $resPairsFew['json']['data']['has_enough_sales_history'] === false, "Store with < 30 bills returns has_enough_sales_history=false");

// Now seed 32 bills for Shop 2 in last 90 days
echo "Seeding 32 bills with co-purchased items for Shop 2...\n";
$stmtBill = $pdo->prepare("INSERT INTO sales (shop_owner_id, bill_no, sale_date, total_amount) VALUES (?, ?, ?, ?)");
$stmtItem = $pdo->prepare("INSERT INTO sales_items (sale_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?)");

$recentDate = date('Y-m-d H:i:s', strtotime('-10 days'));

for ($b = 1; $b <= 32; $b++) {
    $stmtBill->execute([$shopOwnerId, "TEST-INS-B{$b}", $recentDate, 300.00]);
    $saleId = (int)$pdo->lastInsertId();

    // In 10 bills, both P1 and P2 are bought together
    if ($b <= 10) {
        $stmtItem->execute([$saleId, $p1Id, 1, 100.00, 100.00]);
        $stmtItem->execute([$saleId, $p2Id, 1, 200.00, 200.00]);
    } elseif ($b <= 15) {
        // In 5 additional bills, only P1
        $stmtItem->execute([$saleId, $p1Id, 1, 100.00, 100.00]);
    } elseif ($b <= 20) {
        // In 5 additional bills, only P2
        $stmtItem->execute([$saleId, $p2Id, 1, 200.00, 200.00]);
    } else {
        // In remaining bills, only P3
        $stmtItem->execute([$saleId, $p3Id, 1, 150.00, 150.00]);
    }
}

$resPairs = makeRequest('GET', 'insights/product_pairs.php', $tokenShop2);
assertTest($resPairs['code'] === 200, "GET insights/product_pairs.php returns 200 OK after seeding 32 bills");
assertTest($resPairs['json']['data']['has_enough_sales_history'] === true, "has_enough_sales_history is true with 32 bills");

$pairItems = $resPairs['json']['data']['items'];
$foundPair12 = false;

$smallerId = min($p1Id, $p2Id);
$largerId = max($p1Id, $p2Id);
$expectedPairId = "pair_{$smallerId}_{$largerId}";

foreach ($pairItems as $pair) {
    if ($pair['pair_id'] === $expectedPairId) {
        $foundPair12 = $pair;
    }
}

assertTest($foundPair12 !== false, "Pair {$expectedPairId} found in product pairs recommendations");
assertTest($foundPair12 && $foundPair12['product_1']['product_id'] === $smallerId, "Pair product_1 has smaller product ID ({$smallerId})");
assertTest($foundPair12 && $foundPair12['product_2']['product_id'] === $largerId, "Pair product_2 has larger product ID ({$largerId})");
assertTest($foundPair12 && $foundPair12['metrics']['lift'] > 1.0, "Pair lift is > 1.0 (lift = " . ($foundPair12 ? $foundPair12['metrics']['lift'] : 'N/A') . ")");
assertTest(
    $foundPair12 && strpos($foundPair12['headline'], "Often bought together: ") === 0,
    "Headline matches neutral template: 'Often bought together: {A} + {B} (...)'"
);

// -------------------------------------------------------------
// 5. TEST RECOMMENDATION RESPONSES (POST insights/respond.php)
// -------------------------------------------------------------
echo "\n5. Testing Recommendation Responses...\n";

// Reject cross-shop product
$resCross = makeRequest('POST', 'insights/respond.php', $tokenShop2, [
    "recommendation_type" => "slow_moving",
    "product_id_1" => $pShop3Id,
    "action" => "accepted"
]);
assertTest($resCross['code'] === 404, "Responding with another shop's product ID returns 404");

// Respond for pair with inverted ID order (larger first)
$resInvert = makeRequest('POST', 'insights/respond.php', $tokenShop2, [
    "recommendation_type" => "product_pair",
    "product_id_1" => $largerId,
    "product_id_2" => $smallerId,
    "action" => "accepted",
    "notes" => "Bundle created at cashier desk"
]);
assertTest($resInvert['code'] === 200, "POST insights/respond.php returns 200 OK");
assertTest(
    $resInvert['json']['data']['product_id_1'] === $smallerId && $resInvert['json']['data']['product_id_2'] === $largerId,
    "Pair response correctly stores smaller ID first ({$smallerId}, {$largerId})"
);

// Check pair response in product_pairs.php
$resPairsAfterAccept = makeRequest('GET', 'insights/product_pairs.php', $tokenShop2);
$acceptedPair = null;
foreach ($resPairsAfterAccept['json']['data']['items'] as $it) {
    if ($it['pair_id'] === $expectedPairId) {
        $acceptedPair = $it;
    }
}
assertTest($acceptedPair && $acceptedPair['response']['is_responded'] === true && $acceptedPair['response']['action'] === 'accepted', "Pair recommendation reflects 'accepted' status in product_pairs.php");

// Now update response: dismiss the pair (latest response wins)
$resDismiss = makeRequest('POST', 'insights/respond.php', $tokenShop2, [
    "recommendation_type" => "product_pair",
    "product_id_1" => $smallerId,
    "product_id_2" => $largerId,
    "action" => "dismissed",
    "notes" => "Out of shelf space"
]);
assertTest($resDismiss['code'] === 200, "Updating response to 'dismissed' succeeds (latest response wins)");

// Check that dismissed pair is hidden by default
$resPairsAfterDismiss = makeRequest('GET', 'insights/product_pairs.php', $tokenShop2);
$foundDismissedInDefault = false;
foreach ($resPairsAfterDismiss['json']['data']['items'] as $it) {
    if ($it['pair_id'] === $expectedPairId) {
        $foundDismissedInDefault = true;
    }
}
assertTest($foundDismissedInDefault === false, "Dismissed pair is hidden by default from product_pairs.php");

// Check that dismissed pair is included with include_dismissed=1
$resPairsWithDismissed = makeRequest('GET', 'insights/product_pairs.php?include_dismissed=1', $tokenShop2);
$foundDismissedWithFlag = false;
foreach ($resPairsWithDismissed['json']['data']['items'] as $it) {
    if ($it['pair_id'] === $expectedPairId) {
        $foundDismissedWithFlag = true;
    }
}
assertTest($foundDismissedWithFlag === true, "Dismissed pair is returned when ?include_dismissed=1 is specified");

// Test slow moving response & dismissal
$resSlowResp = makeRequest('POST', 'insights/respond.php', $tokenShop2, [
    "recommendation_type" => "slow_moving",
    "product_id_1" => $p1Id,
    "action" => "dismissed",
    "notes" => "Keeping seasonal stock"
]);
assertTest($resSlowResp['code'] === 200, "Dismissing slow-moving item P1 succeeds");

$resSlowAfterDismiss = makeRequest('GET', 'insights/slow_moving.php', $tokenShop2);
$foundP1AfterDismiss = false;
foreach ($resSlowAfterDismiss['json']['data']['items'] as $it) {
    if ($it['product_id'] === $p1Id) {
        $foundP1AfterDismiss = true;
    }
}
assertTest($foundP1AfterDismiss === false, "Dismissed slow-moving item is hidden by default");

$resSlowWithDismiss = makeRequest('GET', 'insights/slow_moving.php?include_dismissed=1', $tokenShop2);
$foundP1WithDismiss = false;
foreach ($resSlowWithDismiss['json']['data']['items'] as $it) {
    if ($it['product_id'] === $p1Id) {
        $foundP1WithDismiss = true;
    }
}
assertTest($foundP1WithDismiss === true, "Dismissed slow-moving item is included with ?include_dismissed=1");

// -------------------------------------------------------------
// 6. TEST RECOMMENDATION HISTORY (GET insights/history.php)
// -------------------------------------------------------------
echo "\n6. Testing Recommendation History...\n";

$resHist = makeRequest('GET', 'insights/history.php', $tokenShop2);
assertTest($resHist['code'] === 200, "GET insights/history.php returns 200 OK");
assertTest($resHist['json']['data']['pagination']['total_items'] >= 2, "History contains the 2 recorded responses");

$histItems = $resHist['json']['data']['items'];
assertTest(
    count($histItems) >= 2 && $histItems[0]['updated_at'] >= $histItems[1]['updated_at'],
    "History is sorted newest first by updated_at"
);

// Filter by type
$resHistFilter = makeRequest('GET', 'insights/history.php?type=product_pair', $tokenShop2);
$allPairsInFilter = true;
foreach ($resHistFilter['json']['data']['items'] as $it) {
    if ($it['recommendation_type'] !== 'product_pair') {
        $allPairsInFilter = false;
    }
}
assertTest($allPairsInFilter && count($resHistFilter['json']['data']['items']) > 0, "History ?type=product_pair filters correctly");

// -------------------------------------------------------------
// 7. TEST SHOP ISOLATION
// -------------------------------------------------------------
echo "\n7. Testing Multi-Shop Isolation...\n";

$resShop3Hist = makeRequest('GET', 'insights/history.php', $tokenShop3);
assertTest(
    $resShop3Hist['code'] === 200 && $resShop3Hist['json']['data']['pagination']['total_items'] === 0,
    "Shop 3 cannot see Shop 2's recommendation responses (strict isolation)"
);

// -------------------------------------------------------------
// SUMMARY
// -------------------------------------------------------------
echo "\n============================================\n";
echo "TEST RESULTS: Total Passed: $passCount, Failed: $failCount\n";
echo "============================================\n";

if ($failCount > 0) {
    exit(1);
} else {
    exit(0);
}
