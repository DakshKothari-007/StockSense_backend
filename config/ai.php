<?php

/**
 * StockSense AI Assistant (Dukaan Mitra) Configuration.
 * 
 * Secure configuration for Gemini API integration.
 * The API key is stored outside htdocs and is never exposed or logged.
 */

return [
    // Gemini API key JSON file path (located outside htdocs)
    'key_file_path' => 'C:\\xampp\\StockSense_private\\gemini.json',

    // Default Gemini Flash model (configurable, e.g. gemini-2.5-flash)
    'model' => 'gemini-2.5-flash',

    // Public assistant identity (shown to users as the app's own AI)
    'assistant_name' => 'Dukaan Mitra',

    // cURL timeout in seconds
    'timeout' => 15,

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

