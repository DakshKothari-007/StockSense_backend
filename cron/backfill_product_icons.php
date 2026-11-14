<?php

/**
 * CLI Backfill Script for Product Icons in StockSense
 * 
 * Classifies existing products that have an empty or null icon_key using:
 *  Step 1: Fast local keyword rules from the shared catalog.
 *  Step 2: Local Ollama AI model (with rate limiting) for unmatched names.
 * 
 * Guarantees:
 *  - Only selects products where icon_key IS NULL OR icon_key = ''.
 *  - Never overwrites an owner's manual choice (where icon_is_manual = 1).
 *  - Rate-limited AI queries to prevent local resource spikes.
 * 
 * Usage:
 *  php cli/backfill_product_icons.php [options]
 * 
 * Options:
 *  --shop_id=N      Only process products for a specific shop owner ID.
 *  --limit=N        Maximum products to process (default: 500).
 *  --dry-run        Simulate classification without updating the database.
 *  --no-ai          Skip Ollama AI calls; use keyword matching only.
 *  --delay_ms=N     Rate limit delay between AI calls in milliseconds (default: 200).
 *  --verbose        Output detailed line-by-line classification logs.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This script can only be run from the command line.\n";
    exit(1);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/IconClassifier.php';

// Parse command line arguments
$options = getopt('', [
    'shop_id:',
    'limit:',
    'dry-run',
    'no-ai',
    'delay_ms:',
    'verbose'
]);

$targetShopId = isset($options['shop_id']) ? (int)$options['shop_id'] : null;
$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 500;
$dryRun = isset($options['dry-run']);
$allowAi = !isset($options['no-ai']);
$delayMs = isset($options['delay_ms']) ? max(0, (int)$options['delay_ms']) : 200;
$verbose = isset($options['verbose']);

echo "========================================================\n";
echo "      STOCKSENSE PRODUCT ICON CLASSIFIER BACKFILL       \n";
echo "========================================================\n";
echo "Mode: " . ($dryRun ? "DRY-RUN (no DB updates)" : "LIVE UPDATE") . "\n";
echo "AI model: " . ($allowAi ? "Enabled (Rate limit: {$delayMs}ms delay)" : "Disabled (Keywords only)") . "\n";
if ($targetShopId !== null) {
    echo "Scoped to Shop Owner ID: {$targetShopId}\n";
}
echo "Batch limit: {$limit}\n";
echo "--------------------------------------------------------\n\n";

// Query products with empty icon_key and icon_is_manual = 0
$where = ["(icon_key IS NULL OR icon_key = '')", "icon_is_manual = 0"];
$params = [];

if ($targetShopId !== null && $targetShopId > 0) {
    $where[] = "shop_owner_id = ?";
    $params[] = $targetShopId;
}

$sql = "SELECT id, shop_owner_id, name, category, icon_key, icon_is_manual 
        FROM products 
        WHERE " . implode(' AND ', $where) . " 
        ORDER BY id ASC 
        LIMIT {$limit}";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalCount = count($products);
echo "Found {$totalCount} products needing icon classification.\n\n";

if ($totalCount === 0) {
    echo "All products already have an icon_key assigned. Nothing to do.\n";
    exit(0);
}

$keywordsMatched = 0;
$aiMatched = 0;
$unmatched = 0;
$updatedCount = 0;

$updateStmt = $pdo->prepare("
    UPDATE products 
    SET icon_key = :icon_key, icon_is_manual = 0, updated_at = updated_at 
    WHERE id = :id AND shop_owner_id = :shop_owner_id
");

foreach ($products as $idx => $p) {
    $id = (int)$p['id'];
    $shopId = (int)$p['shop_owner_id'];
    $name = trim($p['name']);
    $category = trim($p['category'] ?? '');

    $resolvedKey = null;
    $method = 'NONE';

    // Step 1: Keyword rules
    $kwMatch = IconClassifier::matchByKeywords($name, $category);
    if ($kwMatch !== null && !empty($kwMatch['icon_key'])) {
        $resolvedKey = $kwMatch['icon_key'];
        $method = 'KEYWORD (' . round($kwMatch['confidence'] * 100) . '%)';
        $keywordsMatched++;
    } elseif ($allowAi) {
        // Step 2: Rate-limited Ollama AI call
        if ($delayMs > 0 && ($keywordsMatched + $aiMatched + $unmatched) > 0) {
            usleep($delayMs * 1000);
        }

        $aiKey = IconClassifier::classifyWithAi($name, $category);
        if ($aiKey !== null && IconClassifier::isValidKey($aiKey)) {
            $resolvedKey = $aiKey;
            $method = 'AI';
            $aiMatched++;
        } else {
            $unmatched++;
        }
    } else {
        $unmatched++;
    }

    if ($verbose || ($idx + 1) % 25 === 0 || ($idx + 1) === $totalCount) {
        $statusStr = $resolvedKey ? "-> {$resolvedKey} [{$method}]" : "-> [NO MATCH]";
        echo sprintf("[%d/%d] #%d \"%s\" %s\n", $idx + 1, $totalCount, $id, $name, $statusStr);
    }

    // Persist to DB if valid key found and not dry-run
    if ($resolvedKey !== null && !$dryRun) {
        $updateStmt->execute([
            ':icon_key' => $resolvedKey,
            ':id' => $id,
            ':shop_owner_id' => $shopId
        ]);
        $updatedCount++;
    }
}

echo "\n========================================================\n";
echo "                   BACKFILL SUMMARY                     \n";
echo "========================================================\n";
echo "Total Products Examined : {$totalCount}\n";
echo "Keyword Rule Matches    : {$keywordsMatched}\n";
echo "AI Model Matches        : {$aiMatched}\n";
echo "Unmatched (Left Empty)  : {$unmatched}\n";
echo "Database Rows Updated   : " . ($dryRun ? "0 (DRY-RUN)" : $updatedCount) . "\n";
echo "========================================================\n";
