<?php

/**
 * StockSense Dukaan Mitra Live AI Assistant Tester.
 * 
 * Tests live chat query for an authenticated shop owner.
 * Never logs or prints private API keys.
 * 
 * Usage:
 *   php send_test_ai.php <bearer_token> "<question>" [optional_language_hint]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: This script can only be run via CLI.\n";
    exit(1);
}

if ($argc < 3 || empty(trim($argv[1])) || empty(trim($argv[2]))) {
    echo "========================================================\n";
    echo "StockSense Dukaan Mitra AI Assistant Live CLI Tester\n";
    echo "========================================================\n";
    echo "Usage:\n";
    echo "  php send_test_ai.php <bearer_token> \"<question>\" [optional_language_hint]\n\n";
    echo "Examples:\n";
    echo "  php send_test_ai.php 1f8a2... \"What is the stock of Amul Milk?\"\n";
    echo "  php send_test_ai.php 1f8a2... \"How were sales today?\"\n";
    echo "  php send_test_ai.php 1f8a2... \"Endha products low stock la irukku?\" \"Tamil\"\n";
    echo "========================================================\n";
    exit(1);
}

require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/includes/AiEngine.php";

$rawToken = trim($argv[1]);
$question = trim($argv[2]);
$languageHint = isset($argv[3]) ? trim($argv[3]) : null;

// Authenticate token directly against database
$tokenHash = hash('sha256', $rawToken);
$stmt = $pdo->prepare("
    SELECT t.user_id as shop_owner_id, o.shop_name, o.full_name as owner_name, o.email
    FROM tokens t
    JOIN owner_account o ON o.id = t.user_id
    WHERE t.token_hash = ? AND t.expires_at > NOW()
    LIMIT 1
");
$stmt->execute([$tokenHash]);
$owner = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$owner) {
    echo "Error: Invalid or expired Bearer token.\n";
    exit(1);
}

$shopId = (int)$owner['shop_owner_id'];
$storeName = $owner['shop_name'] ?? 'Shop';

$maskedToken = strlen($rawToken) > 16 
    ? substr($rawToken, 0, 6) . "..." . substr($rawToken, -6) 
    : $rawToken;

echo "========================================================\n";
echo "Dukaan Mitra AI Assistant Live Query\n";
echo "--------------------------------------------------------\n";
echo "Store Name    : {$storeName} (Shop ID: {$shopId})\n";
echo "Token         : {$maskedToken}\n";
echo "Question      : {$question}\n";
if ($languageHint) {
    echo "Language Hint : {$languageHint}\n";
}
echo "--------------------------------------------------------\n";

$startTime = microtime(true);

try {
    $response = AiEngine::chat($pdo, $shopId, $question, $languageHint);
    $elapsed = round((microtime(true) - $startTime) * 1000);

    echo "Response Time : {$elapsed} ms\n\n";
    echo "Answer:\n";
    echo $response['answer'] . "\n\n";

    if (!empty($response['cards'])) {
        echo "Cards (" . count($response['cards']) . "):\n";
        foreach ($response['cards'] as $idx => $card) {
            echo "  [" . ($idx + 1) . "] " . ($card['name'] ?? 'Product') . " | Units: " . ($card['units_left'] ?? 0) . " | Status: " . ($card['status'] ?? 'N/A') . "\n";
        }
        echo "\n";
    }

    if (!empty($response['actions'])) {
        echo "Actions (" . count($response['actions']) . "):\n";
        foreach ($response['actions'] as $act) {
            echo "  -> " . ($act['label'] ?? '') . " [Target: " . ($act['target'] ?? '') . "]\n";
        }
        echo "\n";
    }

    echo "========================================================\n";
} catch (Exception $e) {
    echo "Query Error: " . $e->getMessage() . "\n";
    exit(1);
}
