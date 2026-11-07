<?php

/**
 * StockSense AI Assistant (Dukaan Mitra) Configuration.
 * 
 * Default model provider: Ollama (local model execution).
 * Gemini API remains selectable as an alternate provider.
 * When provider is 'ollama', no Gemini API key is needed or read.
 */

return [
    // Model provider: "ollama" (primary for shop data, business, app help)
    'provider' => 'ollama',

    // General knowledge model provider: "gemini" (for general knowledge and non-shop questions)
    'general_provider' => 'gemini',

    // Cloud privacy: Gemini never receives tool results, shop names, product names, sales or stock numbers
    'allow_cloud_for_shop_data' => false,

    // Ollama configuration
    'ollama_url' => 'http://localhost:11434',
    'ollama_model' => 'qwen2.5:7b',
    'num_ctx' => 8192,               // Context window size suitable for tools and conversation context
    'keep_alive' => '30m',           // Keep local model loaded in memory for 30 minutes

    // Gemini API configuration
    'key_file_path' => 'C:\\xampp\\StockSense_private\\gemini.json',
    'model' => 'gemini-3.5-flash',

    // Public assistant identity (shown to users as the app's own AI)
    'assistant_name' => 'Dukaan Mitra',

    // cURL timeouts in seconds (90s for Ollama local models, 20s for Gemini)
    'timeout' => 90,
    'timeout_gemini' => 20,

    // Per-shop rate limit configuration
    'max_questions_per_day' => 100,
    'max_questions_per_minute' => 15,

    // Cache TTLs in seconds
    'cache_ttl_chat' => 300,            // 5 minutes for identical questions
    'cache_ttl_explain' => 86400,       // 24 hours
    'cache_ttl_daily_summary' => 86400, // 24 hours

    // Maximum function calling tool iterations per message
    'max_tool_calls' => 3,

    // Maximum user message length (characters)
    'max_message_length' => 500,
];

