<?php

/**
 * StockSense Firebase Cloud Messaging (FCM) Configuration.
 * 
 * Secure configuration for FCM HTTP v1 API.
 * The service account JSON is located outside htdocs and is never exposed or logged.
 */

return [
    // Service account JSON path outside htdocs
    'service_account_path' => 'C:\\xampp\\StockSense_private\\firebase-service-account.json',

    // FCM HTTP v1 endpoint template
    'fcm_send_url_template' => 'https://fcm.googleapis.com/v1/projects/%s/messages:send',

    // Google OAuth2 Token Exchange URL
    'oauth_token_url' => 'https://oauth2.googleapis.com/token',

    // OAuth2 scope for Firebase Cloud Messaging
    'oauth_scope' => 'https://www.googleapis.com/auth/firebase.messaging',

    // Default Android Notification Channel ID
    'channel_id' => 'stocksense_alerts',

    // cURL timeout in seconds
    'curl_timeout' => 3,

    // Token Cache TTL (~50 minutes)
    'token_cache_ttl' => 3000
];

