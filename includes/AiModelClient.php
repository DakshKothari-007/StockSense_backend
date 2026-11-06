<?php

/**
 * AiModelClient: Unified interface for AI model providers (Ollama, Gemini).
 * 
 * Standardizes chat API execution with tool calling, latency logging,
 * and hermetic mock handler injection for testing.
 */
interface AiModelClient {

    /**
     * Injects a mock handler for testing: fn(array $payload, string $model): array
     * 
     * @param callable|null $handler
     */
    public static function setMockHandler(?callable $handler): void;

    /**
     * Executes content generation / chat query with optional tool calling.
     * 
     * @param array $contents Conversation contents/messages
     * @param string|null $systemInstruction System prompt string
     * @param array $tools Function declarations / tool schemas
     * @param int|null $shopId Authenticated shop ID for logging
     * @return array [
     *   'success' => bool,
     *   'text' => string|null,
     *   'functionCalls' => array, // list of ['name' => string, 'args' => array]
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
    ): array;
}

