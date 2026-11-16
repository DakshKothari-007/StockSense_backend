<?php

/**
 * StockSense Real Push Testing Script.
 * 
 * Sends a real FCM HTTP v1 notification to a specific device token for on-device testing.
 * 
 * Usage:
 *   php send_test_push.php <fcm_token> [optional_title] [optional_body]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: This script can only be run via CLI.\n";
    exit(1);
}

if ($argc < 2 || empty(trim($argv[1]))) {
    echo "========================================================\n";
    echo "StockSense FCM Real Device Test Dispatcher\n";
    echo "========================================================\n";
    echo "Usage:\n";
    echo "  php send_test_push.php <fcm_token> [optional_title] [optional_body]\n\n";
    echo "Example:\n";
    echo "  php send_test_push.php eY123abc... 'StockSense' 'Test push from server!'\n";
    echo "========================================================\n";
    exit(1);
}

require_once __DIR__ . "/config/db.php";
require_once __DIR__ . "/includes/FcmSender.php";

$fcmToken = trim($argv[1]);
$title = isset($argv[2]) && trim($argv[2]) !== '' ? trim($argv[2]) : "StockSense Alert";
$body = isset($argv[3]) && trim($argv[3]) !== '' ? trim($argv[3]) : "This is a real test notification from StockSense FCM push backend.";

$maskedToken = strlen($fcmToken) > 16 
    ? substr($fcmToken, 0, 8) . "..." . substr($fcmToken, -8) 
    : $fcmToken;

echo "========================================================\n";
echo "Sending Real FCM HTTP v1 Push Notification\n";
echo "--------------------------------------------------------\n";
echo "Target Token    : {$maskedToken}\n";
echo "Title           : {$title}\n";
echo "Body            : {$body}\n";
echo "Channel ID      : stocksense_alerts\n";
echo "--------------------------------------------------------\n";

try {
    $result = FcmSender::sendToToken(
        $pdo,
        $fcmToken,
        $title,
        $body,
        [
            'type' => 'test_push',
            'sent_at' => date('Y-m-d H:i:s')
        ]
    );

    if (!empty($result['success'])) {
        echo "[SUCCESS] FCM message delivered successfully!\n";
        echo "HTTP Status Code : " . ($result['status_code'] ?? 200) . "\n";
        if (!empty($result['response'])) {
            echo "FCM Response     : " . $result['response'] . "\n";
        }
    } else {
        echo "[FAILED] FCM message failed to deliver.\n";
        echo "Status Code      : " . ($result['status_code'] ?? 'N/A') . "\n";
        echo "Error / Response : " . ($result['error'] ?? $result['response'] ?? $result['reason'] ?? 'Unknown error') . "\n";
        if (!empty($result['deleted_token'])) {
            echo "Note             : Token was determined to be UNREGISTERED/INVALID and was removed from the database.\n";
        }
    }
} catch (Throwable $e) {
    echo "[ERROR] Exception during send: " . $e->getMessage() . "\n";
    exit(1);
}

echo "========================================================\n";

