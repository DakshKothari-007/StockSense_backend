<?php

/**
 * StockSense FCM Sender.
 * 
 * Implements FCM HTTP v1 protocol using pure PHP (no external Composer packages).
 * Handles RS256 JWT generation with OpenSSL, OAuth2 access token exchange,
 * access token caching (~50 min), message dispatching, and invalid token cleanup.
 */
class FcmSender {

    private static ?array $config = null;
    private static $mockHandler = null;

    /**
     * Set a mock handler for testing without network requests.
     * 
     * @param callable|null $handler function(PDO $pdo, string $token, string $title, string $body, array $data): array
     */
    public static function setMockHandler(?callable $handler): void {
        self::$mockHandler = $handler;
    }

    /**
     * Load central firebase configuration.
     */
    public static function getConfig(): array {
        if (self::$config === null) {
            $configPath = __DIR__ . '/../config/firebase.php';
            if (file_exists($configPath)) {
                self::$config = require $configPath;
            } else {
                self::$config = [
                    'service_account_path' => 'C:\\xampp\\StockSense_private\\firebase-service-account.json',
                    'oauth_token_url' => 'https://oauth2.googleapis.com/token',
                    'fcm_send_url_template' => 'https://fcm.googleapis.com/v1/projects/%s/messages:send',
                    'oauth_scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                    'channel_id' => 'stocksense_alerts',
                    'curl_timeout' => 3,
                    'token_cache_ttl' => 3000
                ];
            }
        }
        return self::$config;
    }

    /**
     * Base64Url encode utility for JWT creation.
     */
    private static function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Reads service account JSON safely.
     * Returns null silently if missing or unreadable, logging exactly one line.
     */
    public static function getServiceAccount(?string $customPath = null): ?array {
        $config = self::getConfig();
        $path = $customPath ?? ($config['service_account_path'] ?? '');

        if (empty($path) || !file_exists($path) || !is_readable($path)) {
            error_log("FCM: Service account file missing or unreadable at " . ($path ?: 'EMPTY_PATH'));
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            error_log("FCM: Failed to read service account file at " . $path);
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['client_email']) || empty($data['private_key']) || empty($data['project_id'])) {
            error_log("FCM: Service account JSON missing required fields (client_email, private_key, project_id).");
            return null;
        }

        return $data;
    }

    /**
     * Retrieve or generate a Google OAuth2 access token with ~50 minute caching.
     */
    public static function getAccessToken(?string $customKeyPath = null): ?string {
        try {
            $config = self::getConfig();
            $serviceAccount = self::getServiceAccount($customKeyPath);
            if (!$serviceAccount) {
                return null;
            }

            // Check file-based cache in system temp directory
            $cacheKey = md5($serviceAccount['client_email'] . '|' . $serviceAccount['project_id']);
            $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stocksense_fcm_' . $cacheKey . '.json';

            if (file_exists($cacheFile)) {
                $cached = json_decode(@file_get_contents($cacheFile), true);
                if (is_array($cached) && !empty($cached['access_token']) && !empty($cached['expires_at'])) {
                    if (time() < (int)$cached['expires_at']) {
                        return $cached['access_token'];
                    }
                }
            }

            // Generate new RS256 signed JWT
            $now = time();
            $header = [
                'alg' => 'RS256',
                'typ' => 'JWT'
            ];
            $claims = [
                'iss' => $serviceAccount['client_email'],
                'sub' => $serviceAccount['client_email'],
                'aud' => $config['oauth_token_url'],
                'iat' => $now,
                'exp' => $now + 3600,
                'scope' => $config['oauth_scope']
            ];

            $jwtUnsigned = self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)) . '.' .
                           self::base64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES));

            $privateKey = openssl_pkey_get_private($serviceAccount['private_key']);
            if (!$privateKey) {
                error_log("FCM: Unable to parse private key from service account.");
                return null;
            }

            $signature = '';
            $signResult = openssl_sign($jwtUnsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);
            if (!$signResult) {
                error_log("FCM: openssl_sign failed while signing assertion JWT.");
                return null;
            }

            $jwt = $jwtUnsigned . '.' . self::base64UrlEncode($signature);

            // Exchange assertion for OAuth2 Bearer token
            $postFields = http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt
            ]);

            $ch = curl_init($config['oauth_token_url']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postFields,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => (int)($config['curl_timeout'] ?? 3),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded'
                ]
            ]);

            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                error_log("FCM: OAuth token request failed with cURL error: " . $curlErr);
                return null;
            }

            if ($httpCode !== 200 || empty($resp)) {
                error_log("FCM: OAuth token request failed with HTTP " . $httpCode . ": " . substr((string)$resp, 0, 255));
                return null;
            }

            $tokenData = json_decode($resp, true);
            $accessToken = $tokenData['access_token'] ?? null;
            if (!$accessToken) {
                error_log("FCM: OAuth token response did not contain access_token.");
                return null;
            }

            // Cache token for ~50 minutes (3000 seconds)
            $ttl = (int)($config['token_cache_ttl'] ?? 3000);
            @file_put_contents($cacheFile, json_encode([
                'access_token' => $accessToken,
                'expires_at' => $now + $ttl
            ], JSON_UNESCAPED_SLASHES), LOCK_EX);

            return $accessToken;

        } catch (Throwable $e) {
            error_log("FCM: Unexpected error in getAccessToken: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Send a single FCM HTTP v1 notification to a device token.
     *
     * @param PDO $pdo
     * @param string $fcmToken
     * @param string $title
     * @param string $body
     * @param array $data Additional key-value pairs (type, product_id, order_id)
     * @param string|null $customKeyPath
     * @return array ['success' => bool, 'status_code' => int, 'response' => string, 'deleted_token' => bool]
     */
    public static function sendToToken(
        PDO $pdo,
        string $fcmToken,
        string $title,
        string $body,
        array $data = [],
        ?string $customKeyPath = null
    ): array {
        // Allow mock handler interception for testing
        if (self::$mockHandler !== null && is_callable(self::$mockHandler)) {
            return call_user_func(self::$mockHandler, $pdo, $fcmToken, $title, $body, $data);
        }

        try {
            $config = self::getConfig();
            $serviceAccount = self::getServiceAccount($customKeyPath);
            if (!$serviceAccount) {
                return ['success' => false, 'reason' => 'missing_or_invalid_service_account'];
            }

            $accessToken = self::getAccessToken($customKeyPath);
            if (!$accessToken) {
                return ['success' => false, 'reason' => 'failed_to_acquire_oauth_token'];
            }

            $projectId = $serviceAccount['project_id'];
            $url = sprintf($config['fcm_send_url_template'], $projectId);

            // FCM v1 requires all data values to be strings
            $stringData = [];
            foreach ($data as $k => $v) {
                if ($v !== null) {
                    $stringData[(string)$k] = (string)$v;
                }
            }

            $payload = [
                'message' => [
                    'token' => $fcmToken,
                    'notification' => [
                        'title' => (string)$title,
                        'body' => (string)$body
                    ],
                    'data' => $stringData,
                    'android' => [
                        'notification' => [
                            'channel_id' => $config['channel_id'] ?? 'stocksense_alerts'
                        ]
                    ]
                ]
            ];

            $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $jsonPayload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => (int)($config['curl_timeout'] ?? 3),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json; UTF-8'
                ]
            ]);

            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                error_log("FCM: Send cURL error for token: " . $curlErr);
                return ['success' => false, 'error' => $curlErr];
            }

            if ($httpCode === 200) {
                return ['success' => true, 'status_code' => 200, 'response' => $resp];
            }

            // Check if token is invalid or unregistered
            $respText = (string)$resp;
            $isUnregistered = (
                $httpCode === 404 ||
                stripos($respText, 'UNREGISTERED') !== false ||
                stripos($respText, 'INVALID_ARGUMENT') !== false ||
                stripos($respText, 'NOT_FOUND') !== false
            );

            $deletedToken = false;
            if ($isUnregistered) {
                try {
                    $delStmt = $pdo->prepare("DELETE FROM device_tokens WHERE fcm_token = ?");
                    $delStmt->execute([$fcmToken]);
                    $deletedToken = ($delStmt->rowCount() > 0);
                    error_log("FCM: Invalid or unregistered token removed from database: " . substr($fcmToken, 0, 16) . "...");
                } catch (Throwable $delEx) {
                    error_log("FCM: Failed to delete invalid token: " . $delEx->getMessage());
                }
            }

            return [
                'success' => false,
                'status_code' => $httpCode,
                'response' => $respText,
                'deleted_token' => $deletedToken
            ];

        } catch (Throwable $e) {
            error_log("FCM: Exception during sendToToken: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send FCM notification to all provided tokens for a shop.
     *
     * @param PDO $pdo
     * @param array $tokens Array of fcm_token strings
     * @param string $title
     * @param string $body
     * @param array $data
     * @return array
     */
    public static function sendToTokens(
        PDO $pdo,
        array $tokens,
        string $title,
        string $body,
        array $data = []
    ): array {
        $successCount = 0;
        $failCount = 0;
        $results = [];

        foreach ($tokens as $token) {
            $tokenStr = trim((string)$token);
            if ($tokenStr === '') continue;

            $res = self::sendToToken($pdo, $tokenStr, $title, $body, $data);
            if (!empty($res['success'])) {
                $successCount++;
            } else {
                $failCount++;
            }
            $results[] = $res;
        }

        return [
            'total' => count($tokens),
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'results' => $results
        ];
    }
}

