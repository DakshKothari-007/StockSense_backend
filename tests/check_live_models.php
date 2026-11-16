<?php

require_once __DIR__ . "/../includes/AiModelClient.php";
require_once __DIR__ . "/../includes/OllamaClient.php";
require_once __DIR__ . "/../includes/GeminiClient.php";
require_once __DIR__ . "/../includes/AiClientFactory.php";

echo "=== REAL MODEL LIVE CONNECTIVITY VERIFICATION ===\n\n";

// Ensure mock handlers are cleared
AiClientFactory::setMockHandler(null);

// 1. Test Real Ollama Call
echo "1. Testing Real Ollama (Local Model: qwen2.5:7b on http://localhost:11434)...\n";
$t0 = microtime(true);
$promptOllama = [
    ['role' => 'user', 'parts' => [['text' => 'Respond in one short sentence: What is 2 + 2?']]]
];
$resOllama = OllamaClient::generateContent($promptOllama, 'You are a concise assistant.');
$timeOllama = round(microtime(true) - $t0, 2);

if ($resOllama['success']) {
    echo " [SUCCESS] Ollama responded in {$timeOllama}s: \"" . trim($resOllama['text']) . "\"\n";
} else {
    echo " [FAILED] Ollama call failed in {$timeOllama}s: " . ($resOllama['error'] ?? 'Unknown error') . "\n";
    if (!empty($resOllama['raw'])) {
        echo " Raw error body: " . json_encode($resOllama['raw']) . "\n";
    }
}

echo "\n";

// 2. Test Real Gemini Call
echo "2. Testing Real Gemini (Cloud Model: gemini-2.5-flash via C:\\xampp\\StockSense_private\\gemini.json)...\n";
$t1 = microtime(true);
$promptGemini = [
    ['role' => 'user', 'parts' => [['text' => 'Respond in one short sentence: What is the capital of France?']]]
];
$resGemini = GeminiClient::generateContent($promptGemini, 'You are a concise assistant.');
$timeGemini = round(microtime(true) - $t1, 2);

if ($resGemini['success']) {
    echo " [SUCCESS] Gemini responded in {$timeGemini}s: \"" . trim($resGemini['text']) . "\"\n";
} else {
    echo " [FAILED] Gemini call failed in {$timeGemini}s: " . ($resGemini['error'] ?? 'Unknown error') . "\n";
}

echo "\n=================================================\n";
if ($resOllama['success'] && $resGemini['success']) {
    echo "RESULT: Both real models are active, healthy, and communicating properly!\n";
    exit(0);
} else {
    echo "RESULT: One or more models encountered an issue.\n";
    exit(1);
}
