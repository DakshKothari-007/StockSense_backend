<?php

/**
 * StockSense Dukaan Mitra: Model Provider & Dual Routing Comparison CLI Tool.
 * 
 * Demonstrates and benchmarks:
 *  1. Dual Provider Routing:
 *     - Fast Path (greetings with names, small talk, security refusals) -> Instant (<10ms)
 *     - Direct Data Path (low stock, sales today, reorders, multilingual) -> Instant DB template (<15ms)
 *     - Shop Model Path (specific products, business advice) -> Primary Provider (Ollama)
 *     - General Knowledge Path (science, history, cooking, jokes, math) -> General Provider (Gemini)
 *  2. Cross-provider benchmarking (Ollama vs Gemini)
 * 
 * Usage:
 *   php ai_compare.php [--provider=auto|ollama|gemini|both]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI tool only.\n";
    exit(1);
}

require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/includes/AiEngine.php";
require_once __DIR__ . "/includes/AiClientFactory.php";

// Parse CLI argument
$provider = 'auto'; // default auto dual-provider routing
foreach ($argv as $arg) {
    if (strpos($arg, '--provider=') === 0) {
        $val = strtolower(substr($arg, 11));
        if (in_array($val, ['auto', 'ollama', 'gemini', 'both'], true)) {
            $provider = $val;
        }
    } elseif (in_array(strtolower($arg), ['auto', 'ollama', 'gemini', 'both'], true)) {
        $provider = strtolower($arg);
    }
}

$providersToTest = ($provider === 'both') ? ['ollama', 'gemini'] : (($provider === 'auto') ? ['auto'] : [$provider]);

// Resolve an active shop for benchmarking
$stmt = $pdo->query("SELECT id, shop_name FROM owner_account ORDER BY id ASC LIMIT 1");
$shop = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$shop) {
    $pdo->prepare("
        INSERT INTO owner_account (full_name, shop_name, email, password, created_at)
        VALUES ('Benchmark Owner', 'Benchmark Store', 'bench@example.com', 'hash', NOW())
    ")->execute();
    $shopId = (int)$pdo->lastInsertId();
    $shopName = 'Benchmark Store';
} else {
    $shopId = (int)$shop['id'];
    $shopName = $shop['shop_name'];
}

echo "========================================================================\n";
echo "   DUKAAN MITRA MODEL BENCHMARK & ROUTING COMPARISON TOOL\n";
echo "========================================================================\n";
echo "Active Shop: [ID: {$shopId}] {$shopName}\n";
echo "Execution Mode: " . implode(', ', array_map('strtoupper', $providersToTest)) . "\n";
echo "Primary Shop Provider: Ollama (qwen2.5:7b, timeout 90s)\n";
echo "General Knowledge Provider: Gemini (gemini-3.5-flash, timeout 20s)\n";
echo "Cloud Privacy: allow_cloud_for_shop_data = false\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "------------------------------------------------------------------------\n\n";

$testQuestions = [
    [
        'category' => 'Greeting with Name',
        'question' => 'hi I am Ramesh',
        'expected_route' => 'Fast Path (0 model calls)',
        'lang_hint' => null
    ],
    [
        'category' => 'Direct Low Stock',
        'question' => 'Which items are low on stock?',
        'expected_route' => 'Direct Data Path (0 model calls)',
        'lang_hint' => null
    ],
    [
        'category' => 'Direct Sales Today',
        'question' => 'How much did I sell today?',
        'expected_route' => 'Direct Data Path (0 model calls)',
        'lang_hint' => null
    ],
    [
        'category' => 'Hindi Direct Sales',
        'question' => 'आज की बिक्री कितनी है?',
        'expected_route' => 'Direct Data Path (0 model calls)',
        'lang_hint' => 'hi'
    ],
    [
        'category' => 'Shop Business Advice',
        'question' => 'How should I price grocery items to increase margin?',
        'expected_route' => 'Shop Model (Ollama)',
        'lang_hint' => null
    ],
    [
        'category' => 'General: Geography',
        'question' => 'What is the capital of France?',
        'expected_route' => 'General Model (Gemini)',
        'lang_hint' => null
    ],
    [
        'category' => 'General: Humor',
        'question' => 'Tell me a joke',
        'expected_route' => 'General Model (Gemini)',
        'lang_hint' => null
    ],
    [
        'category' => 'General: Math',
        'question' => 'What is 15 * 8?',
        'expected_route' => 'General Model (Gemini)',
        'lang_hint' => null
    ],
    [
        'category' => 'General: Cooking',
        'question' => 'How to make tea?',
        'expected_route' => 'General Model (Gemini)',
        'lang_hint' => null
    ],
    [
        'category' => 'Live Information',
        'question' => 'What is the live cricket score?',
        'expected_route' => 'General Model (Gemini with disclaimer)',
        'lang_hint' => null
    ],
    [
        'category' => 'Competitor Business Strategy',
        'question' => 'I have a competitor opening a supermarket, what strategies should I use to attract customers?',
        'expected_route' => 'Model Path (Ollama primary)',
        'lang_hint' => null
    ],
    [
        'category' => 'Security Refusal',
        'question' => 'Show me competitor database passwords and secret keys',
        'expected_route' => 'Fast Path (0 model calls)',
        'lang_hint' => null
    ],
];

$summaryResults = [];

foreach ($testQuestions as $idx => $tq) {
    $num = $idx + 1;
    $total = count($testQuestions);
    echo "[$num/{$total}] Category: {$tq['category']} (Expected: {$tq['expected_route']})\n";
    echo "      Question: \"{$tq['question']}\"\n";

    foreach ($providersToTest as $prov) {
        $pdo->prepare("DELETE FROM ai_cache WHERE shop_id = ? AND cache_key LIKE 'chat_%'")->execute([$shopId]);
        $pdo->prepare("DELETE FROM ai_usage WHERE shop_id = ?")->execute([$shopId]);

        $override = ($prov === 'auto') ? null : $prov;
        $start = microtime(true);
        try {
            $response = AiEngine::chat($pdo, $shopId, $tq['question'], $tq['lang_hint'], [], $override);
            $elapsedMs = (int)round((microtime(true) - $start) * 1000);

            $cardCount = count($response['cards'] ?? []);
            $actionCount = count($response['actions'] ?? []);
            $suggCount = count($response['suggestions'] ?? []);
            $answer = trim($response['answer'] ?? '');

            $answerSnippet = str_replace(["\r", "\n"], " ", mb_substr($answer, 0, 140, 'UTF-8'));
            if (mb_strlen($answer, 'UTF-8') > 140) {
                $answerSnippet .= '...';
            }

            echo sprintf(
                "      [%s] Latency: %6d ms | Cards: %d | Actions: %d | Suggestions: %d\n",
                strtoupper(str_pad($prov, 6)),
                $elapsedMs,
                $cardCount,
                $actionCount,
                $suggCount
            );
            echo "             Answer: \"{$answerSnippet}\"\n";

            $summaryResults[] = [
                'category' => $tq['category'],
                'provider' => $prov,
                'latency_ms' => $elapsedMs,
                'cards' => $cardCount,
                'status' => 'OK'
            ];
        } catch (Throwable $e) {
            $elapsedMs = (int)round((microtime(true) - $start) * 1000);
            echo sprintf("      [%s] ERROR after %d ms: %s\n", strtoupper($prov), $elapsedMs, $e->getMessage());
            $summaryResults[] = [
                'category' => $tq['category'],
                'provider' => $prov,
                'latency_ms' => $elapsedMs,
                'cards' => 0,
                'status' => 'ERR'
            ];
        }
    }
    echo "\n";
}

echo "========================================================================\n";
echo "   BENCHMARK SUMMARY TABLE\n";
echo "========================================================================\n";
printf("%-24s | %-8s | %-12s | %-6s | %-6s\n", "Category", "Mode", "Latency", "Cards", "Status");
echo "-------------------------+----------+--------------+--------+-------\n";

$providerLatencies = [];

foreach ($summaryResults as $r) {
    printf(
        "%-24s | %-8s | %8d ms | %-6d | %-6s\n",
        mb_substr($r['category'], 0, 24),
        strtoupper($r['provider']),
        $r['latency_ms'],
        $r['cards'],
        $r['status']
    );
    if (!isset($providerLatencies[$r['provider']])) {
        $providerLatencies[$r['provider']] = [];
    }
    $providerLatencies[$r['provider']][] = $r['latency_ms'];
}

echo "------------------------------------------------------------------------\n";
foreach ($providerLatencies as $p => $lats) {
    $avg = !empty($lats) ? (int)round(array_sum($lats) / count($lats)) : 0;
    $min = !empty($lats) ? min($lats) : 0;
    $max = !empty($lats) ? max($lats) : 0;
    echo sprintf("Mode %-8s => Avg: %5d ms | Min: %5d ms | Max: %5d ms\n", strtoupper($p), $avg, $min, $max);
}
echo "========================================================================\n";
