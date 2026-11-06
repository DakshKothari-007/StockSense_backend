<?php

require_once __DIR__ . "/AiModelClient.php";
require_once __DIR__ . "/GeminiClient.php";
require_once __DIR__ . "/OllamaClient.php";

/**
 * AiClientFactory: Factory to resolve and manage AI model clients (Ollama vs Gemini).
 */
class AiClientFactory {

    /**
     * Injects mock handler into OllamaClient.
     */
    public static function setOllamaMockHandler(?callable $handler): void {
        OllamaClient::setMockHandler($handler);
    }

    /**
     * Injects mock handler into GeminiClient.
     */
    public static function setGeminiMockHandler(?callable $handler): void {
        GeminiClient::setMockHandler($handler);
    }

    /**
     * Injects mock handler into both OllamaClient and GeminiClient.
     */
    public static function setMockHandler(?callable $handler): void {
        self::setOllamaMockHandler($handler);
        self::setGeminiMockHandler($handler);
    }

    /**
     * Resolves the client class name implementing AiModelClient based on config or override.
     *
     * @param string|null $providerOverride 'ollama' or 'gemini'
     * @return string Class name implementing AiModelClient
     */
    public static function getClientClass(?string $providerOverride = null): string {
        $config = require __DIR__ . '/../config/ai.php';
        $provider = strtolower((string)($providerOverride ?: ($config['provider'] ?? 'ollama')));

        if ($provider === 'gemini') {
            return GeminiClient::class;
        }

        return OllamaClient::class;
    }

    /**
     * Helper to invoke generateContent on the resolved client.
     */
    public static function generateContent(
        array $contents,
        ?string $systemInstruction = null,
        array $tools = [],
        ?int $shopId = null,
        ?string $providerOverride = null
    ): array {
        $clientClass = self::getClientClass($providerOverride);
        return $clientClass::generateContent($contents, $systemInstruction, $tools, $shopId);
    }
}
