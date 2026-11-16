<?php
/**
 * ai_diagnose.php - Diagnosis tool for Gemini integration
 * Strictly never exposes the raw API key.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/GeminiClient.php';
require_once __DIR__ . '/includes/AiTools.php';
require_once __DIR__ . '/includes/AiEngine.php';

echo "=== DUKAAN MITRA / GEMINI DIAGNOSIS ===\n";

$config = require __DIR__ . '/config/ai.php';
$model = $config['model'] ?? '';
echo "1. Configured Model: $model\n";

$apiKey = GeminiClient::getApiKey();
echo "2. API Key Loaded: " . ($apiKey ? "YES (length=" . strlen($apiKey) . ")" : "NO") . "\n";

$tools = AiTools::getFunctionDeclarations();
echo "3. Declared Tools Count: " . count($tools) . "\n";

echo "4. Testing GeminiClient::generateContent with Hindi question: 'आज की बिक्री कितनी है?'...\n";
$contents = [
    [
        'role' => 'user',
        'parts' => [
            ['text' => 'आज की बिक्री कितनी है?']
        ]
    ]
];
$systemInstruction = AiEngine::getSystemInstruction();

$res = GeminiClient::generateContent($contents, $systemInstruction, $tools, 9999);

echo "   Result Success: " . ($res['success'] ? 'YES' : 'NO') . "\n";
echo "   Latency: {$res['latency_ms']} ms\n";
if (!$res['success']) {
    echo "   Error: {$res['error']}\n";
    if (!empty($res['raw'])) {
        echo "   Raw details: " . json_encode($res['raw']) . "\n";
    }
} else {
    if (!empty($res['functionCalls'])) {
        echo "   Function Calls:\n";
        foreach ($res['functionCalls'] as $fc) {
            echo "     - Tool: {$fc['name']} with args: " . json_encode($fc['args']) . "\n";
        }
    }
    if (!empty($res['text'])) {
        echo "   Text: {$res['text']}\n";
    }
}
