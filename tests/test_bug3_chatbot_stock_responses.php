<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/AiEngine.php';
require_once __DIR__ . '/../includes/AiTools.php';
require_once __DIR__ . '/../includes/IntentEngine.php';

echo "========================================================\n";
echo "    BUG 3: CHATBOT ZERO-PRODUCT & LOW/OUT STOCK TESTS   \n";
echo "========================================================\n\n";

$allPassed = true;

function assertTest(string $desc, bool $condition) {
    global $allPassed;
    if ($condition) {
        echo " [PASS] {$desc}\n";
    } else {
        echo " [FAIL] {$desc}\n";
        $allPassed = false;
    }
}

// ---------------------------------------------------------
// TEST 1: Empty Shop (0 products in database)
// ---------------------------------------------------------
echo "--- TEST 1: Empty Shop (0 products in DB) ---\n";
$emptyLow = AiEngine::formatLowStockResponse([], [], 'en', 0);
assertTest("Empty shop low stock says 'no products yet'", str_contains($emptyLow['answer'], 'no products yet') || str_contains($emptyLow['answer'], 'Import your stock first'));
assertTest("Empty shop low stock does NOT say 'well-stocked'", !str_contains($emptyLow['answer'], 'well-stocked'));
assertTest("Empty shop low stock has import action", !empty($emptyLow['actions']) && $emptyLow['actions'][0]['target'] === 'import');

// Hindi empty shop
$emptyLowHi = AiEngine::formatLowStockResponse([], [], 'hi', 0);
assertTest("Empty shop low stock Hindi has import guidance", str_contains($emptyLowHi['answer'], 'आयात') || str_contains($emptyLowHi['answer'], 'सामान नहीं'));

// Tamil empty shop
$emptyLowTa = AiEngine::formatLowStockResponse([], [], 'ta', 0);
assertTest("Empty shop low stock Tamil has import guidance", str_contains($emptyLowTa['answer'], 'இறக்குமதி') || str_contains($emptyLowTa['answer'], 'பொருட்கள் எதுவும் இல்லை'));

// Reorder on empty shop
$emptyReorder = AiEngine::formatReorderResponse([], [], 'en', 0);
assertTest("Empty shop reorder says 'no products yet'", str_contains($emptyReorder['answer'], 'no products yet'));
assertTest("Empty shop reorder has import action", !empty($emptyReorder['actions']) && $emptyReorder['actions'][0]['target'] === 'import');

// Slow moving on empty shop
$emptySlow = AiEngine::formatSlowMovingResponse([], [], 'en', 0);
assertTest("Empty shop slow moving says 'no products yet'", str_contains($emptySlow['answer'], 'no products yet'));
assertTest("Empty shop slow moving has import action", !empty($emptySlow['actions']) && $emptySlow['actions'][0]['target'] === 'import');

// Top sellers on empty shop
$emptyTop = AiEngine::formatTopSellersResponse([], [], 'week', 'en', 0);
assertTest("Empty shop top sellers says 'no products yet'", str_contains($emptyTop['answer'], 'no products yet'));
assertTest("Empty shop top sellers has import action", !empty($emptyTop['actions']) && $emptyTop['actions'][0]['target'] === 'import');

// ---------------------------------------------------------
// TEST 2: Out-of-stock only
// ---------------------------------------------------------
echo "\n--- TEST 2: Out-of-Stock Only Products ---\n";
$outItems = [
    ['name' => 'Tata Salt 1kg', 'units' => 0, 'status' => 'Out of stock'],
    ['name' => 'Amul Butter 500g', 'units' => 0, 'status' => 'Out of stock']
];
$respOut = AiEngine::formatLowStockResponse($outItems, [], 'en', 15);
assertTest("Lists 'Out of stock'", str_contains($respOut['answer'], 'Out of stock'));
assertTest("Includes product Tata Salt", str_contains($respOut['answer'], 'Tata Salt 1kg'));
assertTest("Mentions 0 units", str_contains($respOut['answer'], '0 units'));

// ---------------------------------------------------------
// TEST 3: Low-stock only
// ---------------------------------------------------------
echo "\n--- TEST 3: Low-Stock Only Products ---\n";
$lowItems = [
    ['name' => 'Madhur Sugar 1kg', 'units' => 3, 'status' => 'Low stock']
];
$respLow = AiEngine::formatLowStockResponse($lowItems, [], 'en', 15);
assertTest("Lists 'Low stock' or 'running low'", str_contains($respLow['answer'], 'running low') || str_contains($respLow['answer'], 'Low stock'));
assertTest("Includes product Madhur Sugar", str_contains($respLow['answer'], 'Madhur Sugar 1kg'));
assertTest("Mentions 3 units left", str_contains($respLow['answer'], '3 units left'));

// ---------------------------------------------------------
// TEST 4: Mixed (Out-of-Stock + Low-Stock)
// ---------------------------------------------------------
echo "\n--- TEST 4: Mixed (Out-of-Stock Listed First) ---\n";
$mixedItems = [
    ['name' => 'Britannia Toast 200g', 'units' => 0, 'status' => 'Out of stock'],
    ['name' => 'Fortune Oil 1L', 'units' => 2, 'status' => 'Low stock']
];
$respMixed = AiEngine::formatLowStockResponse($mixedItems, [], 'en', 15);
$posOut = strpos($respMixed['answer'], 'Britannia Toast');
$posLow = strpos($respMixed['answer'], 'Fortune Oil');
assertTest("Out-of-stock item is listed before low-stock item", $posOut !== false && $posLow !== false && $posOut < $posLow);

// ---------------------------------------------------------
// TEST 5: Healthy Shop (Products > 0, Low/Out = 0)
// ---------------------------------------------------------
echo "\n--- TEST 5: Healthy Shop (At least 1 product, none low) ---\n";
$respHealthy = AiEngine::formatLowStockResponse([], [], 'en', 25);
assertTest("Healthy shop says 'All products in your shop are currently well-stocked'", str_contains($respHealthy['answer'], 'well-stocked'));

// ---------------------------------------------------------
// TEST 6: Stock Value Intent - "how much money is stuck in low stock"
// ---------------------------------------------------------
echo "\n--- TEST 6: 'how much money is stuck in low stock' ---\n";
$queryLow = "how much money is stuck in low stock";
$directLow = AiEngine::tryDirectDataPath($pdo, 2, $queryLow);
assertTest("Direct path handles 'how much money is stuck in low stock'", $directLow !== null);
assertTest("Returns rupee value for low stock items at cost", str_contains($directLow['answer'] ?? '', "Low stock items are worth ₹"));
assertTest("Returns slow-moving stock value", str_contains($directLow['answer'] ?? '', "Slow-moving stock: ₹"));
assertTest("Returns dead stock value", str_contains($directLow['answer'] ?? '', "Dead stock: ₹"));
assertTest("Appends 'Most money is stuck in slow and dead stock.' when asked about low stock", str_contains($directLow['answer'] ?? '', "Most money is stuck in slow and dead stock."));
assertTest("Reports missing cost price honestly without inventing numbers", str_contains($directLow['answer'] ?? '', "Cost price is missing for"));

// Also test IntentEngine resolution
$resolvedLow = IntentEngine::resolve($pdo, 2, $queryLow, [], null, false);
assertTest("IntentEngine resolves to 'stock_value'", ($resolvedLow['intent'] ?? '') === 'stock_value');
assertTest("IntentEngine identifies target as 'low'", ($resolvedLow['params']['target'] ?? '') === 'low');
$executedLow = IntentEngine::executeIntent($pdo, 2, $resolvedLow, 'en');
assertTest("IntentEngine execution contains expected low stock value answer", str_contains($executedLow['answer'] ?? '', "Low stock items are worth ₹"));

// ---------------------------------------------------------
// TEST 7: Stock Value Intent - "how much money is stuck in slow stock"
// ---------------------------------------------------------
echo "\n--- TEST 7: 'how much money is stuck in slow stock' ---\n";
$querySlow = "how much money is stuck in slow stock";
$directSlow = AiEngine::tryDirectDataPath($pdo, 2, $querySlow);
assertTest("Direct path handles 'how much money is stuck in slow stock'", $directSlow !== null);
assertTest("Returns rupee value for low stock items at cost", str_contains($directSlow['answer'] ?? '', "Low stock items are worth ₹"));
assertTest("Returns slow-moving stock value", str_contains($directSlow['answer'] ?? '', "Slow-moving stock: ₹"));
assertTest("Returns dead stock value", str_contains($directSlow['answer'] ?? '', "Dead stock: ₹"));
assertTest("Does NOT append 'Most money is stuck in slow and dead stock.' when asked about slow stock", !str_contains($directSlow['answer'] ?? '', "Most money is stuck in slow and dead stock."));

// Also test IntentEngine resolution
$resolvedSlow = IntentEngine::resolve($pdo, 2, $querySlow, [], null, false);
assertTest("IntentEngine resolves 'how much money is stuck in slow stock' to 'stock_value'", ($resolvedSlow['intent'] ?? '') === 'stock_value');
assertTest("IntentEngine identifies target as 'slow'", ($resolvedSlow['params']['target'] ?? '') === 'slow');

// ---------------------------------------------------------
// TEST 8: Stock Value Intent - "stock value" with an empty shop
// ---------------------------------------------------------
echo "\n--- TEST 8: 'stock value' with an empty shop ---\n";
$emptyShopId = 999999;
$emptyStockVal = AiTools::getStockValue($pdo, $emptyShopId);
assertTest("AiTools::getStockValue reports has_data = false for empty shop", ($emptyStockVal['result']['has_data'] ?? true) === false);

$directEmpty = AiEngine::tryDirectDataPath($pdo, $emptyShopId, "stock value");
assertTest("Direct path handles 'stock value' for empty shop", $directEmpty !== null);
assertTest("Empty shop response contains 'no data'", stripos($directEmpty['answer'] ?? '', 'no data') !== false);

$resolvedEmpty = IntentEngine::resolve($pdo, $emptyShopId, "stock value", [], null, false);
assertTest("IntentEngine resolves 'stock value' to 'stock_value'", ($resolvedEmpty['intent'] ?? '') === 'stock_value');
$executedEmpty = IntentEngine::executeIntent($pdo, $emptyShopId, $resolvedEmpty, 'en');
assertTest("IntentEngine execution for empty shop says 'no data'", stripos($executedEmpty['answer'] ?? '', 'no data') !== false);

echo "\n========================================================\n";
echo "RESULT: " . ($allPassed ? "ALL BUG 3 CHATBOT TESTS PASSED!" : "SOME TESTS FAILED!") . "\n";
echo "========================================================\n";
