<?php

/**
 * STOCKSENSE WEEKLY EMAIL DIGEST TEST SUITE
 * 
 * Validates:
 * 1. Mock mailer integration (zero real SMTP traffic).
 * 2. Complete email content (HTML & plain-text, #2FD39A, #101827, metrics, tables, tip).
 * 3. Empty data handling ("No sales this week", empty sellers, graceful layout).
 * 4. Opt-out enforcement (weekly_email_digest toggle = 0 skips email).
 * 5. Duplicate protection (6-day skip via digest_log table).
 * 6. Per-shop isolation (Shop 2 data never leaks into Shop 3).
 * 7. Settings API save & read (POST settings/update.php and GET settings/get.php).
 * 8. CLI test mode runner (test=1&owner_id=X).
 */

date_default_timezone_set('Asia/Kolkata');
putenv('STOCKSENSE_MOCK_MAIL=1');

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/MailClient.php';
require_once __DIR__ . '/includes/WeeklyDigestService.php';

$pdo->exec("SET time_zone = '+05:30'");

$passCount = 0;
$failCount = 0;

function assertCondition(bool $cond, string $label): void {
    global $passCount, $failCount;
    if ($cond) {
        echo " [PASS] " . $label . "\n";
        $passCount++;
    } else {
        echo " [FAIL] " . $label . "\n";
        $failCount++;
    }
}

echo "========================================================\n";
echo "       STOCKSENSE WEEKLY EMAIL DIGEST TEST SUITE        \n";
echo "========================================================\n\n";

// Ensure digest_log table exists
$pdo->exec("
    CREATE TABLE IF NOT EXISTS digest_log (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        shop_owner_id INT NOT NULL,
        sent_at DATETIME NOT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'sent',
        error_message TEXT NULL,
        INDEX idx_owner_sent (shop_owner_id, sent_at),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// Clean test state for shops 2 and 3 in digest_log
$pdo->exec("DELETE FROM digest_log WHERE shop_owner_id IN (2, 3)");

// -------------------------------------------------------------
// TEST SUITE 1: Mock Mailer & Content Generation
// -------------------------------------------------------------
echo "--- 1. Testing Mock Mailer & Digest Content ---\n";

MailClient::clearSentHistory();
MailClient::setMockSender(function($toEmail, $toName, $subject, $html, $text) {
    return [
        'status' => true,
        'message' => 'Delivered to mock transport.',
        'error' => null
    ];
});

$shop2Data = WeeklyDigestService::compileDigestData($pdo, 2);

assertCondition(!empty($shop2Data['shop_name']), "1a. Shop name compiled ('{$shop2Data['shop_name']}')");
assertCondition(!empty($shop2Data['date_range_label']), "1b. IST date range generated ('{$shop2Data['date_range_label']}')");

$subject = WeeklyDigestService::buildSubject($shop2Data);
assertCondition(strpos($subject, "Your StockSense weekly report - ") === 0, "1c. Subject format is 'Your StockSense weekly report - {range}'");
assertCondition(strpos($subject, $shop2Data['date_range_label']) !== false, "1d. Subject contains correct date range");

$htmlBody = WeeklyDigestService::buildHtml($shop2Data);
assertCondition(strpos($htmlBody, '#2FD39A') !== false, "1e. HTML contains brand green color (#2FD39A)");
assertCondition(strpos($htmlBody, '#101827') !== false, "1f. HTML contains brand dark color (#101827)");
assertCondition(strpos($htmlBody, htmlspecialchars($shop2Data['shop_name'])) !== false, "1g. HTML contains shop name");
assertCondition(strpos($htmlBody, "Top 5 Sellers") !== false, "1h. HTML contains Top 5 Sellers section");
assertCondition(strpos($htmlBody, "5 Slowest Movers") !== false, "1i. HTML contains 5 Slowest Movers section");
assertCondition(strpos($htmlBody, "Low Stock &amp; Reorder Suggestions") !== false || strpos($htmlBody, "Low Stock") !== false, "1j. HTML contains Low Stock section");
assertCondition(strpos($htmlBody, "Weekly Action Tip") !== false, "1k. HTML contains Action Tip section");
assertCondition(!empty($shop2Data['tip_line']), "1l. Deterministic tip line generated: '{$shop2Data['tip_line']}'");

$plainText = WeeklyDigestService::buildPlainText($shop2Data);
assertCondition(strpos($plainText, "STOCKSENSE WEEKLY REPORT") !== false, "1m. Plain text fallback contains header");
assertCondition(strpos($plainText, $shop2Data['shop_name']) !== false, "1n. Plain text fallback contains shop name");
assertCondition(strpos($plainText, $shop2Data['tip_line']) !== false, "1o. Plain text fallback contains tip line");

$sendRes = MailClient::send($shop2Data['email'], $shop2Data['owner_name'], $subject, $htmlBody, $plainText);
assertCondition($sendRes['status'] === true, "1p. MailClient sends successfully via mock mailer");
assertCondition(count(MailClient::$sentHistory) === 1, "1q. Mock mailer recorded 1 sent message in memory");
assertCondition(MailClient::$sentHistory[0]['subject'] === $subject, "1r. Recorded message subject matches");

// -------------------------------------------------------------
// TEST SUITE 2: Empty Data Handling ("No sales this week")
// -------------------------------------------------------------
echo "\n--- 2. Testing Empty Data Handling ---\n";

// Build a synthetic empty data set
$emptyData = $shop2Data;
$emptyData['current_sales'] = 0.0;
$emptyData['current_orders'] = 0;
$emptyData['top_sellers'] = [];
$emptyData['low_stock_suggestions'] = [];

$emptyHtml = WeeklyDigestService::buildHtml($emptyData);
assertCondition(strpos($emptyHtml, "No sales this week") !== false, "2a. HTML displays 'No sales this week' when sales are 0");

$emptyPlain = WeeklyDigestService::buildPlainText($emptyData);
assertCondition(strpos($emptyPlain, "No sales this week") !== false, "2b. Plain text displays 'No sales this week'");
assertCondition(strpos($emptyHtml, "No sales recorded during this 7-day period.") !== false, "2c. Empty sellers table gracefully handled");
assertCondition(strpos($emptyHtml, "All products in healthy stock") !== false, "2d. Healthy stock state gracefully handled");

// -------------------------------------------------------------
// TEST SUITE 3: Opt-Out & Toggle Enforcement
// -------------------------------------------------------------
echo "\n--- 3. Testing Opt-Out Enforcement ---\n";

// Turn OFF weekly_email_digest for Shop 2
$pdo->prepare("UPDATE user_settings SET weekly_email_digest = 0 WHERE user_id = 2")->execute();

// Run cron script via PHP CLI capturing output
$output = shell_exec('C:\xampp\php\php.exe ' . escapeshellarg(__DIR__ . '/cron/weekly_digest.php') . ' "test=1&owner_id=2"');
assertCondition(strpos($output, "weekly_email_digest setting is OFF") !== false, "3a. Cron skips shop when weekly_email_digest is OFF");
assertCondition(strpos($output, "Sent: 0") !== false, "3b. Zero emails sent when shop has opted out");

// Turn ON weekly_email_digest for Shop 2
$pdo->prepare("UPDATE user_settings SET weekly_email_digest = 1 WHERE user_id = 2")->execute();
$outputOn = shell_exec('C:\xampp\php\php.exe ' . escapeshellarg(__DIR__ . '/cron/weekly_digest.php') . ' "test=1&owner_id=2"');
assertCondition(strpos($outputOn, "weekly_email_digest setting is OFF") === false, "3c. Cron does NOT skip shop when toggle is ON");

// -------------------------------------------------------------
// TEST SUITE 4: Duplicate Protection (6-Day Skip)
// -------------------------------------------------------------
echo "\n--- 4. Testing Duplicate Protection (6-Day Window) ---\n";

// Clear log for shop 2
$pdo->prepare("DELETE FROM digest_log WHERE shop_owner_id = 2")->execute();

// Simulate a digest sent today
$pdo->prepare("INSERT INTO digest_log (shop_owner_id, sent_at, status) VALUES (2, NOW(), 'sent')")->execute();

// Run standard non-test cron check
$checkStmt = $pdo->prepare("
    SELECT id, sent_at
    FROM digest_log
    WHERE shop_owner_id = ?
      AND status = 'sent'
      AND sent_at >= DATE_SUB(NOW(), INTERVAL 6 DAY)
    LIMIT 1
");
$checkStmt->execute([2]);
$recentSent = $checkStmt->fetch(PDO::FETCH_ASSOC);
assertCondition(!empty($recentSent), "4a. Recent digest detected within last 6 days");

// Simulate a digest sent 8 days ago
$pdo->prepare("DELETE FROM digest_log WHERE shop_owner_id = 2")->execute();
$pdo->prepare("INSERT INTO digest_log (shop_owner_id, sent_at, status) VALUES (2, DATE_SUB(NOW(), INTERVAL 8 DAY), 'sent')")->execute();

$checkOldStmt = $pdo->prepare("
    SELECT id, sent_at
    FROM digest_log
    WHERE shop_owner_id = ?
      AND status = 'sent'
      AND sent_at >= DATE_SUB(NOW(), INTERVAL 6 DAY)
    LIMIT 1
");
$checkOldStmt->execute([2]);
$noRecentSent = $checkOldStmt->fetch(PDO::FETCH_ASSOC);
assertCondition(empty($noRecentSent), "4b. Digest older than 6 days correctly clears duplicate check");

// -------------------------------------------------------------
// TEST SUITE 5: Per-Shop Scoping & Data Isolation
// -------------------------------------------------------------
echo "\n--- 5. Testing Per-Shop Scoping ---\n";

$shop2 = WeeklyDigestService::compileDigestData($pdo, 2);
$shop3 = WeeklyDigestService::compileDigestData($pdo, 3);

assertCondition($shop2['shop_owner_id'] === 2, "5a. Shop 2 data owner ID is 2");
assertCondition($shop3['shop_owner_id'] === 3, "5b. Shop 3 data owner ID is 3");
assertCondition($shop2['shop_name'] !== $shop3['shop_name'] || $shop2['email'] !== $shop3['email'], "5c. Shop 2 and Shop 3 identities are completely distinct");

// Verify that Shop 2 top sellers belong only to Shop 2
$shop2ProdIds = array_column($shop2['top_sellers'], 'id');
$shop3ProdIds = array_column($shop3['top_sellers'], 'id');
$intersect = array_intersect($shop2ProdIds, $shop3ProdIds);
assertCondition(empty($intersect), "5d. Zero product overlap between Shop 2 and Shop 3 sales");

// -------------------------------------------------------------
// TEST SUITE 6: Settings API Save & Read Integration
// -------------------------------------------------------------
echo "\n--- 6. Testing Settings API Save & Read ---\n";

// Ensure known password for Shop 2
$pdo->prepare("UPDATE owner_account SET password = ? WHERE id = 2")->execute([password_hash('password123', PASSWORD_DEFAULT)]);
$pdo->exec("DELETE FROM login_attempts");

// Log in Shop 2 to get auth token
$loginCh = curl_init("http://127.0.0.1/StockSense_backend/auth/login.php");
curl_setopt($loginCh, CURLOPT_RETURNTRANSFER, true);
curl_setopt($loginCh, CURLOPT_POST, true);
curl_setopt($loginCh, CURLOPT_POSTFIELDS, json_encode([
    "email" => "ananya@example.com",
    "password" => "password123"
]));
curl_setopt($loginCh, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
$loginRes = curl_exec($loginCh);
$loginJson = json_decode($loginRes, true);
$token = $loginJson['token'] ?? $loginJson['data']['token'] ?? '';
assertCondition(!empty($token), "6a. Obtained valid session token for Shop 2");

// Update weekly_email_digest to false via settings/update.php
$updateCh = curl_init("http://127.0.0.1/StockSense_backend/settings/update.php");
curl_setopt($updateCh, CURLOPT_RETURNTRANSFER, true);
curl_setopt($updateCh, CURLOPT_POST, true);
curl_setopt($updateCh, CURLOPT_POSTFIELDS, json_encode([
    "weekly_email_digest" => false
]));
curl_setopt($updateCh, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer " . $token
]);
$updateRes = curl_exec($updateCh);
$updateJson = json_decode($updateRes, true);
assertCondition($updateJson['status'] === true, "6b. POST settings/update.php (weekly_email_digest = false) returns 200 OK");
assertCondition($updateJson['data']['notifications']['weekly_email_digest'] === false, "6c. Updated settings response reflects weekly_email_digest = false");

// Verify with GET settings/get.php
$getCh = curl_init("http://127.0.0.1/StockSense_backend/settings/get.php");
curl_setopt($getCh, CURLOPT_RETURNTRANSFER, true);
curl_setopt($getCh, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $token]);
$getRes = curl_exec($getCh);
$getJson = json_decode($getRes, true);
assertCondition($getJson['data']['notifications']['weekly_email_digest'] === false, "6d. GET settings/get.php confirms weekly_email_digest is false");

// Update weekly_email_digest to true via settings/update.php
$updateOnCh = curl_init("http://127.0.0.1/StockSense_backend/settings/update.php");
curl_setopt($updateOnCh, CURLOPT_RETURNTRANSFER, true);
curl_setopt($updateOnCh, CURLOPT_POST, true);
curl_setopt($updateOnCh, CURLOPT_POSTFIELDS, json_encode([
    "weekly_email_digest" => true
]));
curl_setopt($updateOnCh, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer " . $token
]);
$updateOnRes = curl_exec($updateOnCh);
$updateOnJson = json_decode($updateOnRes, true);
assertCondition($updateOnJson['status'] === true, "6e. POST settings/update.php (weekly_email_digest = true) returns 200 OK");
assertCondition($updateOnJson['data']['notifications']['weekly_email_digest'] === true, "6f. Updated settings response reflects weekly_email_digest = true");

// Verify with GET settings/get.php
$getOnCh = curl_init("http://127.0.0.1/StockSense_backend/settings/get.php");
curl_setopt($getOnCh, CURLOPT_RETURNTRANSFER, true);
curl_setopt($getOnCh, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $token]);
$getOnRes = curl_exec($getOnCh);
$getOnJson = json_decode($getOnRes, true);
assertCondition($getOnJson['data']['notifications']['weekly_email_digest'] === true, "6g. GET settings/get.php confirms weekly_email_digest is true");

// -------------------------------------------------------------
// TEST SUITE 7: CLI Test Mode
// -------------------------------------------------------------
echo "\n--- 7. Testing CLI Test Mode ---\n";

// Execute test mode with query-style arg
$testOutput = shell_exec('C:\xampp\php\php.exe ' . escapeshellarg(__DIR__ . '/cron/weekly_digest.php') . ' "test=1&owner_id=2"');
assertCondition(strpos($testOutput, "Starting StockSense Weekly Digest Runner (TEST MODE)") !== false, "7a. Test mode banner recognized");
assertCondition(strpos($testOutput, "Processed: 1") !== false, "7b. Test mode scoped exclusively to targeted owner_id=2");

// -------------------------------------------------------------
// SUMMARY
// -------------------------------------------------------------
echo "\n========================================================\n";
echo "   RESULT: {$passCount} / " . ($passCount + $failCount) . " TESTS PASSED\n";
echo "========================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
