<?php

require_once __DIR__ . "/AiModelClient.php";

/**
 * OllamaClient: HTTP client for Ollama API (/api/chat) with Tool Calling support.
 * 
 * Configured as the default local LLM client for Dukaan Mitra.
 * Features:
 *  - 90-second execution timeout, 3-second connect timeout for fast fallback when offline.
 *  - Supports mock handler injection for hermetic unit testing without local Ollama service.
 *  - Formats standard OpenAI/Ollama tool declarations with schema normalization.
 *  - Never logs prompts, messages, or shop data.
 */
class OllamaClient implements AiModelClient {

    private static $mockHandler = null;

    /**
     * Injects a mock handler for testing: fn(array $payload, string $model): array
     */
    public static function setMockHandler(?callable $handler): void {
        self::$mockHandler = $handler;
    }

    /**
     * Call Ollama /api/chat endpoint.
     * 
     * @param array $contents The conversation contents array
     * @param string|null $systemInstruction System prompt string
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
        $ollamaUrl = rtrim($config['ollama_url'] ?? 'http://localhost:11434', '/');
        $model = $config['ollama_model'] ?? 'qwen2.5:7b';
        $timeout = (int)($config['timeout'] ?? 90);
        $numCtx = (int)($config['num_ctx'] ?? 8192);
        $keepAlive = (string)($config['keep_alive'] ?? '30m');

        // Convert contents and system prompt into Ollama messages
        $messages = [];
        if ($systemInstruction) {
            $messages[] = [
                'role' => 'system',
                'content' => $systemInstruction
            ];
        }

        foreach ($contents as $turn) {
            $role = ($turn['role'] ?? 'user') === 'model' ? 'assistant' : ($turn['role'] ?? 'user');
            $parts = $turn['parts'] ?? [];

            foreach ($parts as $p) {
                if (isset($p['text'])) {
                    $messages[] = [
                        'role' => $role,
                        'content' => $p['text']
                    ];
                } elseif (isset($p['functionCall'])) {
                    $fc = $p['functionCall'];
                    $messages[] = [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [
                            [
                                'function' => [
                                    'name' => (string)($fc['name'] ?? ''),
                                    'arguments' => is_array($fc['args'] ?? null) ? $fc['args'] : (object)[]
                                ]
                            ]
                        ]
                    ];
                } elseif (isset($p['functionResponse'])) {
                    $fr = $p['functionResponse'];
                    $responseContent = $fr['response']['content'] ?? $fr['response'] ?? [];
                    $messages[] = [
                        'role' => 'tool',
                        'content' => is_string($responseContent) ? $responseContent : json_encode($responseContent)
                    ];
                }
            }
        }

        // Format tools into Ollama schema
        $ollamaTools = [];
        if (!empty($tools)) {
            foreach ($tools as $t) {
                $parameters = $t['parameters'] ?? ['type' => 'object', 'properties' => (object)[]];
                $parameters = self::normalizeJsonSchema($parameters);

                $ollamaTools[] = [
                    'type' => 'function',
                    'function' => [
                        'name' => $t['name'] ?? '',
                        'description' => $t['description'] ?? '',
                        'parameters' => $parameters
                    ]
                ];
            }
        }

        // Build request payload
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
            'keep_alive' => $keepAlive,
            'options' => [
                'num_ctx' => $numCtx,
                'temperature' => 0.2
            ]
        ];

        if (!empty($ollamaTools)) {
            $payload['tools'] = $ollamaTools;
        }

        // Use mock handler if set
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

        $url = "{$ollamaUrl}/api/chat";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3); // Fast 3s connect timeout if Ollama daemon is down
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
     * Normalizes schema types to lowercase for Ollama compatibility.
     */
    private static function normalizeJsonSchema(array $schema): array {
        if (isset($schema['type'])) {
            $schema['type'] = strtolower((string)$schema['type']);
        }
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            if (empty($schema['properties'])) {
                $schema['properties'] = (object)[];
            } else {
                foreach ($schema['properties'] as $k => $v) {
                    if (is_array($v)) {
                        $schema['properties'][$k] = self::normalizeJsonSchema($v);
                    }
                }
            }
        }
        return $schema;
    }

    /**
     * Parses the Ollama /api/chat response into unified structure.
     */
    private static function parseResponse(?array $data, int $latencyMs, ?int $shopId): array {
        if (!$data || (!isset($data['message']) && !isset($data['candidates']))) {
            $err = isset($data['error']) ? (is_string($data['error']) ? $data['error'] : 'ollama_error') : 'empty_response';
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

        $functionCalls = [];
        $toolNames = [];
        $text = null;

        // Support Ollama format ($data['message'])
        if (isset($data['message'])) {
            $msg = $data['message'];
            if (!empty($msg['content'])) {
                $text = trim((string)$msg['content']);
            }
            if (!empty($msg['tool_calls']) && is_array($msg['tool_calls'])) {
                foreach ($msg['tool_calls'] as $tc) {
                    $fn = $tc['function'] ?? [];
                    $toolName = (string)($fn['name'] ?? '');
                    $toolArgs = $fn['arguments'] ?? [];
                    if (is_string($toolArgs)) {
                        $decodedArgs = @json_decode($toolArgs, true);
                        if (is_array($decodedArgs)) {
                            $toolArgs = $decodedArgs;
                        } else {
                            $toolArgs = [];
                        }
                    }
                    if (!is_array($toolArgs)) {
                        $toolArgs = [];
                    }

                    if ($toolName !== '') {
                        $functionCalls[] = [
                            'name' => $toolName,
                            'args' => $toolArgs
                        ];
                        $toolNames[] = $toolName;
                    }
                }
            }
        } elseif (isset($data['candidates'][0]['content']['parts'])) {
            // Also accept mock payloads formatted like candidates for backward test compatibility
            $parts = $data['candidates'][0]['content']['parts'];
            $textParts = [];
            foreach ($parts as $p) {
                if (isset($p['text'])) {
                    $textParts[] = $p['text'];
                }
                if (isset($p['functionCall'])) {
                    $fc = $p['functionCall'];
                    $tName = (string)($fc['name'] ?? '');
                    $tArgs = is_array($fc['args'] ?? null) ? $fc['args'] : [];
                    $functionCalls[] = [
                        'name' => $tName,
                        'args' => $tArgs
                    ];
                    $toolNames[] = $tName;
                }
            }
            $text = !empty($textParts) ? trim(implode("\n", $textParts)) : null;
        }

        self::logAiCall($shopId, $toolNames, $latencyMs, null);

        return [
            'success' => true,
            'text' => $text,
            'functionCalls' => $functionCalls,
            'raw' => $data,
            'error' => null,
            'latency_ms' => $latencyMs
        ];
    }

    /**
     * Secure logging: strictly shop_id, tool_names, latency_ms, error_type.
     * Never logs model prompts, raw user queries, or shop data.
     */
    private static function logAiCall(?int $shopId, array $toolNames, int $latencyMs, ?string $errorType): void {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/ai_calls.log';

        $entry = sprintf(
            "[%s] provider=ollama shop_id=%s tools=%s latency_ms=%d error=%s\n",
            date('Y-m-d H:i:s'),
            $shopId !== null ? (string)$shopId : 'unknown',
            !empty($toolNames) ? implode(',', $toolNames) : 'none',
            $latencyMs,
            $errorType ?: 'none'
        );

        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }
}
