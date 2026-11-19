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

echo "\n========================================================\n";
echo "RESULT: " . ($allPassed ? "ALL BUG 3 CHATBOT TESTS PASSED!" : "SOME TESTS FAILED!") . "\n";
echo "========================================================\n";
