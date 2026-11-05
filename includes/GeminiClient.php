<?php

/**
 * GeminiClient: Secure HTTP client for Gemini API with Function Calling support.
 * 
 * Strict multi-tenant security:
 *  - Key is read strictly from private storage, never exposed or logged.
 *  - Supports mock handler injection for hermetic unit testing.
 *  - Strips prompts/keys from logs; logs only shop_id, tool_names, latency_ms, error_type.
 */
class GeminiClient {

    private static $mockHandler = null;

    /**
     * Set a mock handler for testing: fn(array $payload, string $model): array
     */
    public static function setMockHandler(?callable $handler): void {
        self::$mockHandler = $handler;
    }

    /**
     * Get the API key securely from config path.
     */
    public static function getApiKey(): ?string {
        $config = require __DIR__ . '/../config/ai.php';
        $keyPath = $config['key_file_path'] ?? '';
        if (!$keyPath || !file_exists($keyPath) || !is_readable($keyPath)) {
            return null;
        }

        $content = @file_get_contents($keyPath);
        if (!$content) {
            return null;
        }

        $json = @json_decode($content, true);
        return !empty($json['api_key']) ? (string)$json['api_key'] : null;
    }

    /**
     * Call generateContent API.
     * 
     * @param array $contents The conversation contents array
     * @param array $systemInstruction System prompt string or parts
     * @param array $tools Function declarations
     * @param int|null $shopId Authenticated shop ID for logging
     * @return array [
     *   'success' => bool,
     *   'text' => string|null,
     *   'functionCalls' => array, // list of ['name' => ..., 'args' => ...]
     *   'raw' => array,
     *   'error' => string|null,
     *   'latency_ms' => int
     * ]
     */
    public static function generateContent(
        array $contents,
        ?string $systemInstruction = null,
        array $tools = [],
        ?int $shopId = null
    ): array {
        $startTime = microtime(true);
        $config = require __DIR__ . '/../config/ai.php';
        $model = $config['model'] ?? 'gemini-3.5-flash';
        $timeout = (int)($config['timeout'] ?? 15);

        // Build request payload
        $payload = [
            'contents' => $contents
        ];

        if ($systemInstruction) {
            $payload['system_instruction'] = [
                'parts' => [
                    ['text' => $systemInstruction]
                ]
            ];
        }

        if (!empty($tools)) {
            $formattedTools = [];
            foreach ($tools as $t) {
                if (isset($t['parameters']) && is_array($t['parameters'])) {
                    if (isset($t['parameters']['properties']) && empty((array)$t['parameters']['properties'])) {
                        $t['parameters']['properties'] = (object)[];
                    }
                }
                $formattedTools[] = $t;
            }
            $payload['tools'] = [
                [
                    'function_declarations' => $formattedTools
                ]
            ];
        }

        // If mock handler is set, use it
        if (self::$mockHandler !== null) {
            try {
                $mockRes = call_user_func(self::$mockHandler, $payload, $model);
                $latencyMs = (int)round((microtime(true) - $startTime) * 1000);
                return self::parseResponse($mockRes, $latencyMs, $shopId);
            } catch (Throwable $e) {
                $latencyMs = (int)round((microtime(true) - $startTime) * 1000);
                self::logAiCall($shopId, [], $latencyMs, 'mock_exception: ' . $e->getMessage());
                return [
                    'success' => false,
                    'text' => null,
                    'functionCalls' => [],
                    'raw' => [],
                    'error' => 'Mock error',
                    'latency_ms' => $latencyMs
                ];
            }
        }

        $apiKey = self::getApiKey();
        if (!$apiKey) {
            $latencyMs = (int)round((microtime(true) - $startTime) * 1000);
            self::logAiCall($shopId, [], $latencyMs, 'missing_api_key');
            return [
                'success' => false,
                'text' => null,
                'functionCalls' => [],
                'raw' => [],
                'error' => 'API key missing',
                'latency_ms' => $latencyMs
            ];
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $latencyMs = (int)round((microtime(true) - $startTime) * 1000);

        if ($curlError || $httpCode !== 200) {
            $errType = $curlError ? "curl_{$curlError}" : "http_{$httpCode}";
            self::logAiCall($shopId, [], $latencyMs, $errType);
            return [
                'success' => false,
                'text' => null,
                'functionCalls' => [],
                'raw' => @json_decode($response, true) ?: [],
                'error' => $curlError ?: "HTTP {$httpCode}",
                'latency_ms' => $latencyMs
            ];
        }

        $resJson = json_decode($response, true);
        return self::parseResponse($resJson, $latencyMs, $shopId);
    }

    /**
     * Parses the Gemini generateContent response into normalized structure.
     */
    private static function parseResponse(?array $data, int $latencyMs, ?int $shopId): array {
        if (!$data || !isset($data['candidates'][0]['content']['parts'])) {
            $err = isset($data['error']['message']) ? 'api_error' : 'empty_response';
            self::logAiCall($shopId, [], $latencyMs, $err);
            return [
                'success' => false,
                'text' => null,
                'functionCalls' => [],
                'raw' => $data ?: [],
                'error' => $err,
                'latency_ms' => $latencyMs
            ];
        }

        $parts = $data['candidates'][0]['content']['parts'];
        $textParts = [];
        $functionCalls = [];
        $toolNames = [];

        foreach ($parts as $p) {
            if (isset($p['text'])) {
                $textParts[] = $p['text'];
            }
            if (isset($p['functionCall'])) {
                $fc = $p['functionCall'];
                $toolName = (string)($fc['name'] ?? '');
                $toolArgs = is_array($fc['args'] ?? null) ? $fc['args'] : [];
                $functionCalls[] = [
                    'name' => $toolName,
                    'args' => $toolArgs
                ];
                $toolNames[] = $toolName;
            }
        }

        self::logAiCall($shopId, $toolNames, $latencyMs, null);

        $combinedText = !empty($textParts) ? trim(implode("\n", $textParts)) : null;

        return [
            'success' => true,
            'text' => $combinedText,
            'functionCalls' => $functionCalls,
            'raw' => $data,
            'error' => null,
            'latency_ms' => $latencyMs
        ];
    }

    /**
     * Secure logging: strictly shop_id, tool_names, latency_ms, error_type.
     * Never logs API key, raw messages, prompts, or shop data.
     */
    private static function logAiCall(?int $shopId, array $toolNames, int $latencyMs, ?string $errorType): void {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/ai_calls.log';

        $entry = sprintf(
            "[%s] shop_id=%s tools=%s latency_ms=%d error=%s\n",
            date('Y-m-d H:i:s'),
            $shopId !== null ? (string)$shopId : 'unknown',
            !empty($toolNames) ? implode(',', $toolNames) : 'none',
            $latencyMs,
            $errorType ?: 'none'
        );

        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }
}

