<?php

/**
 * IconClassifier: Server-side product icon classification for supermarket retail products.
 * 
 * Pipeline:
 *  Step 1: In-memory keyword and token matching against the shared product icon catalog (304 items).
 *  Step 2: If unmatched or low confidence (< 0.50), queries the local Ollama AI model with strict prompt.
 * 
 * Guarantees:
 *  - Strict 8-second execution timeout on AI calls.
 *  - High-privacy: only product name and category are sent to Ollama; never shop, owner, sales, or stock data.
 *  - Validates all AI replies against the allowed catalog keys list; rejects hallucinations/garbage.
 *  - Never blocks imports or product adds: on AI failure, gracefully leaves icon_key empty (null).
 *  - Never overwrites an owner's manual choice (guarded by icon_is_manual and empty-only classification).
 */
class IconClassifier {

    private static ?array $catalog = null;
    private static ?array $allowedKeys = null;
    private static $mockHandler = null;

    /**
     * Set a custom mock handler for unit testing: fn(string $name, ?string $category, array $allowedKeys): ?string
     */
    public static function setMockHandler(?callable $handler): void {
        self::$mockHandler = $handler;
    }

    public static function getMockHandler(): ?callable {
        return self::$mockHandler;
    }

    /**
     * Set file-backed mock responses for integration and HTTP tests: ['pattern' => 'icon_key' | 'none']
     */
    public static function setMockResponses(?array $responses): void {
        $mockFile = sys_get_temp_dir() . '/stocksense_icon_ai_mock.json';
        if ($responses === null) {
            if (file_exists($mockFile)) {
                @unlink($mockFile);
            }
        } else {
            file_put_contents($mockFile, json_encode($responses));
        }
    }

    /**
     * Loads the shared JSON product icon catalog (304 supermarket items).
     */
    public static function getCatalog(): array {
        if (self::$catalog !== null) {
            return self::$catalog;
        }

        $paths = [
            __DIR__ . '/../config/product_icon_catalog.json',
            dirname(__DIR__, 4) . '/StudioProjects/Stock-Sense/app/src/main/assets/product_icon_catalog.json'
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                $raw = file_get_contents($path);
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    self::$catalog = $decoded;
                    return self::$catalog;
                }
            }
        }

        self::$catalog = [];
        return self::$catalog;
    }

    /**
     * Returns an associative lookup map of allowed catalog keys: ['apple' => true, ...]
     */
    public static function getAllowedKeys(): array {
        if (self::$allowedKeys !== null) {
            return self::$allowedKeys;
        }

        $catalog = self::getCatalog();
        $keys = [];
        foreach ($catalog as $item) {
            if (!empty($item['icon_key'])) {
                $keys[trim(strtolower($item['icon_key']))] = true;
            }
        }

        self::$allowedKeys = $keys;
        return self::$allowedKeys;
    }

    /**
     * Checks if a given icon_key string is valid in the shared catalog.
     */
    public static function isValidKey(?string $key): bool {
        if ($key === null || trim($key) === '') {
            return false;
        }
        $allowed = self::getAllowedKeys();
        return isset($allowed[trim(strtolower($key))]);
    }

    /**
     * Normalizes a product name by removing units, packaging words, numbers, and symbols.
     */
    public static function normalize(string $name): string {
        $s = mb_strtolower(trim($name), 'UTF-8');
        if ($s === '') {
            return '';
        }

        // Units regex
        $unitRegex = '/\b\d+(\.\d+)?\s*(g|gm|gms|gram|grams|kg|kgs|kilo|kilos|ml|l|ltr|ltrs|liter|liters|litre|litres|pc|pcs|piece|pieces|pack|packs|pk|pkt|pkts|bundle|combo|family\s*pack|super\s*saver|pouch|can|tin|bottle|box|sachet|unit|units|doz|dozen|bunch|meter|m|cm|mm|pages|page|w|watt|watts|v|volt|volts|mg|tablet|tablets|capsule|capsules|किलो|ग्राम|लीटर|கிலோ|கிராம்|லிட்டர்)\b/ui';
        // Packaging words
        $packRegex = '/\b(family\s*pack|super\s*saver|pack|packs|pkt|pkts|combo|pouch|sachet|bottle|tin|can|box|bundle)\b/ui';
        // Numbers
        $numRegex = '/\b\d+\b/u';

        $s = preg_replace($unitRegex, ' ', $s);
        $s = preg_replace($packRegex, ' ', $s);
        // Replace symbols/punctuation while preserving letters, combining marks (Hindi/Tamil matras), and numbers
        $s = preg_replace('/[^\p{L}\p{M}\p{N}\s]/u', ' ', $s);
        $s = preg_replace($numRegex, ' ', $s);
        $s = trim(preg_replace('/\s+/u', ' ', $s));

        return $s;
    }

    /**
     * Step 1: In-memory keyword rules and token matching from the shared catalog.
     * Returns ['icon_key' => string, 'confidence' => float] or null.
     */
    public static function matchByKeywords(string $name, ?string $category = null): ?array {
        $normalized = self::normalize($name);
        if ($normalized === '') {
            return null;
        }

        $catalog = self::getCatalog();
        if (empty($catalog)) {
            return null;
        }

        $cleanCategory = $category !== null ? mb_strtolower(trim($category), 'UTF-8') : '';
        $tokens = array_filter(explode(' ', $normalized), fn($t) => mb_strlen($t, 'UTF-8') > 1);

        $bestItem = null;
        $bestScore = 0.0;

        foreach ($catalog as $item) {
            $score = 0.0;
            $keywords = $item['keywords'] ?? [];
            $dept = mb_strtolower($item['department'] ?? '', 'UTF-8');

            // 1. Exact match against full normalized string
            foreach ($keywords as $kw) {
                $kwClean = mb_strtolower(trim($kw), 'UTF-8');
                if ($kwClean === $normalized) {
                    $score = max($score, 1.0);
                    break;
                }
                // Multi-word phrase containment
                if (mb_strpos($kwClean, ' ') !== false && mb_strpos($normalized, $kwClean) !== false) {
                    $lenRatio = min(1.0, mb_strlen($kwClean, 'UTF-8') / max(1, mb_strlen($normalized, 'UTF-8')));
                    $score = max($score, 0.85 + ($lenRatio * 0.15));
                }
            }

            // 2. Token overlap matching
            if ($score < 0.90 && !empty($tokens)) {
                $tokenHits = 0;
                $maxTokenScore = 0.0;
                foreach ($tokens as $token) {
                    foreach ($keywords as $kw) {
                        $kwClean = mb_strtolower(trim($kw), 'UTF-8');
                        if ($kwClean === $token) {
                            $tokenHits++;
                            $tokenLen = mb_strlen($token, 'UTF-8');
                            $maxTokenScore = max($maxTokenScore, 0.55 + min(0.35, $tokenLen / 20.0));
                            break;
                        }
                        // Typo tolerance (Levenshtein distance <= 1 for words of 5+ letters)
                        if (mb_strlen($token, 'UTF-8') >= 5 && mb_strlen($kwClean, 'UTF-8') >= 5) {
                            if (levenshtein($token, $kwClean) <= 1) {
                                $tokenHits++;
                                $maxTokenScore = max($maxTokenScore, 0.52);
                                break;
                            }
                        }
                    }
                }

                if ($tokenHits > 0) {
                    $score = max($score, $maxTokenScore);
                }
            }

            // 3. Category / Department affinity boost
            if ($score > 0.0 && !empty($cleanCategory) && !empty($dept)) {
                if (mb_strpos($cleanCategory, $dept) !== false || mb_strpos($dept, $cleanCategory) !== false) {
                    $score += 0.05;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestItem = $item;
            }
        }

        $clampedScore = min(1.0, $bestScore);
        if ($bestItem !== null && $clampedScore >= 0.50) {
            return [
                'icon_key' => $bestItem['icon_key'],
                'confidence' => $clampedScore
            ];
        }

        return null;
    }

    /**
     * Step 2: Queries the local Ollama AI model with strict prompt and 8-second timeout.
     * Only product name and category are sent. Never shop data.
     * Validates reply against allowed keys; returns null on failure or "none".
     */
    public static function classifyWithAi(string $name, ?string $category = null): ?string {
        $allowed = self::getAllowedKeys();
        if (empty($allowed)) {
            return null;
        }

        // Test mock handler injection
        if (self::$mockHandler !== null) {
            try {
                $reply = call_user_func(self::$mockHandler, $name, $category, array_keys($allowed));
                return self::validateAiReply($reply);
            } catch (Throwable $e) {
                error_log("IconClassifier mock error: " . $e->getMessage());
                return null;
            }
        }

        // File-backed mock for HTTP tests
        $mockFile = sys_get_temp_dir() . '/stocksense_icon_ai_mock.json';
        if (file_exists($mockFile)) {
            $mockData = json_decode(file_get_contents($mockFile), true);
            if (is_array($mockData)) {
                foreach ($mockData as $pattern => $key) {
                    if ($pattern === '*' || stripos($name, $pattern) !== false) {
                        return self::validateAiReply($key);
                    }
                }
            }
        }

        // Read AI configuration
        $configPath = __DIR__ . '/../config/ai.php';
        $config = file_exists($configPath) ? require $configPath : [];
        $ollamaUrl = rtrim($config['ollama_url'] ?? 'http://localhost:11434', '/');
        $model = $config['ollama_model'] ?? 'qwen2.5:7b';
        $numCtx = (int)($config['num_ctx'] ?? 4096);

        $allowedListStr = implode(', ', array_keys($allowed));
        $cleanCategory = !empty($category) ? trim($category) : 'General';

        $systemPrompt = "Choose the best ONE icon_key from this list for this supermarket product. Reply with only the key or none.";
        $userPrompt = "Product name: {$name}\nCategory: {$cleanCategory}\n\nAllowed keys:\n{$allowedListStr}";

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt]
            ],
            'stream' => false,
            'options' => [
                'temperature' => 0.0,
                'num_ctx' => $numCtx
            ]
        ];

        // Check if OllamaClient has a mock handler registered
        if (class_exists('OllamaClient')) {
            $clientMock = (new ReflectionClass('OllamaClient'))->getProperty('mockHandler');
            $clientMock->setAccessible(true);
            $handler = $clientMock->getValue();
            if ($handler !== null) {
                try {
                    $res = call_user_func($handler, $payload, $model);
                    $text = $res['message']['content'] ?? ($res['text'] ?? '');
                    return self::validateAiReply($text);
                } catch (Throwable $e) {
                    error_log("IconClassifier OllamaClient mock error: " . $e->getMessage());
                    return null;
                }
            }
        }

        $url = "{$ollamaUrl}/api/chat";
        try {
            $response = self::postJson($url, $payload, 8);
            if ($response === null) {
                return null;
            }

            $json = json_decode($response, true);
            $rawReply = $json['message']['content'] ?? ($json['response'] ?? null);

            return self::validateAiReply($rawReply);
        } catch (Throwable $e) {
            error_log("IconClassifier AI exception: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Batch AI classification for up to 20 unmatched products during import execute.
     * Returns map: ['sku_or_id' => 'icon_key' | null].
     * Never throws; on failure, leaves icon keys null.
     */
    public static function batchClassifyWithAi(array $items): array {
        if (empty($items)) {
            return [];
        }

        $allowed = self::getAllowedKeys();
        $batch = array_slice($items, 0, 20);
        $results = [];

        // If mock handler is set, use it per item
        if (self::$mockHandler !== null) {
            foreach ($batch as $it) {
                $identifier = $it['key'] ?? ($it['sku'] ?? ($it['name'] ?? ''));
                $key = self::classifyWithAi($it['name'] ?? '', $it['category'] ?? null);
                $results[$identifier] = $key;
            }
            return $results;
        }

        // File-backed mock for HTTP tests
        $mockFile = sys_get_temp_dir() . '/stocksense_icon_ai_mock.json';
        if (file_exists($mockFile)) {
            $mockData = json_decode(file_get_contents($mockFile), true);
            if (is_array($mockData)) {
                foreach ($batch as $it) {
                    $identifier = $it['key'] ?? ($it['sku'] ?? ($it['name'] ?? ''));
                    $name = $it['name'] ?? '';
                    $matchedKey = null;
                    foreach ($mockData as $pattern => $key) {
                        if ($pattern === '*' || stripos($name, $pattern) !== false) {
                            $matchedKey = ($key === 'none') ? null : $key;
                            break;
                        }
                    }
                    if ($matchedKey !== null && self::isValidKey($matchedKey)) {
                        $results[$identifier] = $matchedKey;
                    }
                }
                return $results;
            }
        }

        $configPath = __DIR__ . '/../config/ai.php';
        $config = file_exists($configPath) ? require $configPath : [];
        $ollamaUrl = rtrim($config['ollama_url'] ?? 'http://localhost:11434', '/');
        $model = $config['ollama_model'] ?? 'qwen2.5:7b';
        $numCtx = (int)($config['num_ctx'] ?? 4096);

        $allowedListStr = implode(', ', array_keys($allowed));
        $productListStr = "";
        $indexMap = [];
        foreach ($batch as $idx => $it) {
            $name = trim($it['name'] ?? '');
            $cat = trim($it['category'] ?? 'General');
            $productListStr .= ($idx + 1) . ". Name: \"{$name}\" (Category: \"{$cat}\")\n";
            $identifier = $it['key'] ?? ($it['sku'] ?? ($it['name'] ?? (string)$idx));
            $indexMap[$idx + 1] = $identifier;
        }

        $systemPrompt = "Choose the best ONE icon_key from this list for each supermarket product. Reply with only valid JSON mapping product number to its icon_key or none: {\"1\": \"key\", \"2\": \"none\"}.";
        $userPrompt = "Allowed keys:\n{$allowedListStr}\n\nProducts:\n{$productListStr}";

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt]
            ],
            'stream' => false,
            'format' => 'json',
            'options' => [
                'temperature' => 0.0,
                'num_ctx' => $numCtx
            ]
        ];

        // Check for OllamaClient mock
        if (class_exists('OllamaClient')) {
            $clientMock = (new ReflectionClass('OllamaClient'))->getProperty('mockHandler');
            $clientMock->setAccessible(true);
            $handler = $clientMock->getValue();
            if ($handler !== null) {
                try {
                    $res = call_user_func($handler, $payload, $model);
                    $text = $res['message']['content'] ?? ($res['text'] ?? '');
                    return self::parseBatchAiReply($text, $indexMap);
                } catch (Throwable $e) {
                    error_log("IconClassifier batch mock error: " . $e->getMessage());
                    return [];
                }
            }
        }

        $url = "{$ollamaUrl}/api/chat";
        try {
            $response = self::postJson($url, $payload, 8);
            if ($response === null) {
                return [];
            }

            $json = json_decode($response, true);
            $rawReply = $json['message']['content'] ?? ($json['response'] ?? null);

            return self::parseBatchAiReply($rawReply, $indexMap);
        } catch (Throwable $e) {
            error_log("IconClassifier batch AI exception: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Parses and validates a batch JSON response from AI.
     */
    private static function parseBatchAiReply(?string $raw, array $indexMap): array {
        if (empty($raw)) {
            return [];
        }

        if (preg_match('/\{[\s\S]*\}/', $raw, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                $results = [];
                foreach ($decoded as $num => $key) {
                    $cleanNum = (int)preg_replace('/\D/', '', (string)$num);
                    if (isset($indexMap[$cleanNum])) {
                        $identifier = $indexMap[$cleanNum];
                        $validatedKey = self::validateAiReply(is_string($key) ? $key : null);
                        $results[$identifier] = $validatedKey;
                    }
                }
                return $results;
            }
        }

        return [];
    }

    /**
     * Validates an AI reply string:
     * - Checks for 'none' -> null
     * - Trims, strips markdown backticks / quotes
     * - Validates against allowed keys list
     * - Returns valid key string or null
     */
    public static function validateAiReply(?string $reply): ?string {
        if ($reply === null) {
            return null;
        }

        $clean = trim($reply);
        // Remove markdown backticks or quotes
        $clean = trim($clean, "`'\" \n\r\t.");
        $clean = strtolower($clean);

        if ($clean === '' || $clean === 'none' || $clean === 'null') {
            return null;
        }

        // Direct exact match
        if (self::isValidKey($clean)) {
            return $clean;
        }

        // Extract first valid identifier token from reply
        if (preg_match('/\b([a-z0-9_]+)\b/', $clean, $m)) {
            $candidate = $m[1];
            if (self::isValidKey($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Classifies a product name:
     * Step 1: Keyword rules (instant, local).
     * Step 2: Local Ollama AI (strict prompt, 8s timeout).
     * 
     * @param string $name Product name
     * @param string|null $category Product category
     * @param bool $allowAi Whether to fall back to AI if Step 1 is unmatched
     * @return string|null The resolved valid icon_key, or null if unclassifiable
     */
    public static function classify(string $name, ?string $category = null, bool $allowAi = true): ?string {
        // Step 1: Keyword rules
        $keywordMatch = self::matchByKeywords($name, $category);
        if ($keywordMatch !== null && !empty($keywordMatch['icon_key'])) {
            return $keywordMatch['icon_key'];
        }

        // Step 2: Ollama AI model (if unmatched or low confidence)
        if ($allowAi) {
            $aiKey = self::classifyWithAi($name, $category);
            if ($aiKey !== null && self::isValidKey($aiKey)) {
                return $aiKey;
            }
        }

        return null;
    }

    /**
     * Performs an HTTP POST request with an 8-second execution timeout.
     * Uses cURL if available, otherwise falls back to native stream context.
     * Never throws; returns string body on 200 OK or null on failure.
     */
    private static function postJson(string $url, array $payload, int $timeoutSeconds = 8): ?string {
        $jsonPayload = json_encode($payload);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);

            try {
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($response !== false && $httpCode === 200) {
                    return $response;
                }
                return null;
            } catch (Throwable $e) {
                error_log("IconClassifier cURL error: " . $e->getMessage());
                return null;
            }
        }

        // Native stream context fallback
        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                    'content' => $jsonPayload,
                    'timeout' => (float)$timeoutSeconds,
                    'ignore_errors' => true
                ]
            ]);

            $response = @file_get_contents($url, false, $context);
            if ($response !== false && isset($http_response_header) && is_array($http_response_header)) {
                $statusLine = $http_response_header[0] ?? '';
                if (strpos($statusLine, '200') !== false) {
                    return $response;
                }
            }
            return null;
        } catch (Throwable $e) {
            error_log("IconClassifier stream error: " . $e->getMessage());
            return null;
        }
    }
}
