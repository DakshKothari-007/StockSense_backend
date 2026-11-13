<?php

/**
 * StockSense Weekly Email Digest Cron Script.
 * 
 * Scheduled to run every Sunday at 8:00 PM IST (Asia/Kolkata).
 * Iterates through all shop owners who have weekly_email_digest toggle ON and a valid email.
 * Compiles 7-day sales, week-over-week comparisons, top sellers, slowest movers,
 * low stock reorders, open purchase orders count, and a deterministic tip.
 * 
 * Deduplication: Skips any shop if a digest was already sent in the last 6 days.
 * Resilient: Logs failures to digest_log table and continues execution without crashing.
 * 
 * CLI Test Mode:
 *   php cron/weekly_digest.php?test=1&owner_id=2
 *   php cron/weekly_digest.php "test=1&owner_id=2"
 *   php cron/weekly_digest.php --test=1 --owner_id=2
 */

// 1. Strict CLI-only enforcement
if (php_sapi_name() !== 'cli' && !defined('STDIN')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'status' => false,
        'message' => 'Access denied. The weekly digest runner can only be executed via CLI.'
    ]);
    exit(1);
}

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/MailClient.php';
require_once __DIR__ . '/../includes/WeeklyDigestService.php';

$pdo->exec("SET time_zone = '+05:30'");

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

// 2. Parse CLI arguments and test mode
$cliParams = [];
if (isset($argv) && is_array($argv)) {
    foreach (array_slice($argv, 1) as $arg) {
        $cleanArg = ltrim($arg, '-');
        // Handle argument formatted like "weekly_digest.php?test=1&owner_id=2" or "?test=1"
        if (strpos($cleanArg, '?') !== false) {
            $cleanArg = substr($cleanArg, strpos($cleanArg, '?') + 1);
        }
        if (strpos($cleanArg, '=') !== false) {
            parse_str($cleanArg, $parsed);
            $cliParams = array_merge($cliParams, $parsed);
        }
    }
}

// Also check query string in case passed via CLI environment
if (!empty($_SERVER['QUERY_STRING'])) {
    parse_str($_SERVER['QUERY_STRING'], $qsParsed);
    $cliParams = array_merge($cliParams, $qsParsed);
}

$isTestMode = !empty($cliParams['test']) && ($cliParams['test'] == '1' || strtolower($cliParams['test']) === 'true');
$filterOwnerId = !empty($cliParams['owner_id']) ? (int)$cliParams['owner_id'] : null;
$forceSend = !empty($cliParams['force']) && ($cliParams['force'] == '1' || strtolower($cliParams['force']) === 'true');
$isMock = !empty($cliParams['mock']) && ($cliParams['mock'] == '1' || strtolower($cliParams['mock']) === 'true');

if ($isMock || getenv('STOCKSENSE_MOCK_MAIL') === '1') {
    MailClient::setMockSender(function($toEmail, $toName, $subject, $htmlBody, $textBody) {
        return [
            'status' => true,
            'message' => 'Delivered to mock transport.',
            'error' => null
        ];
    });
}

echo "[" . date('Y-m-d H:i:s') . "] Starting StockSense Weekly Digest Runner" . ($isTestMode ? " (TEST MODE)" : "") . "...\n";

try {
    // 3. Fetch candidate shop owners
    if ($filterOwnerId !== null && $filterOwnerId > 0) {
        $ownerStmt = $pdo->prepare("SELECT id, full_name, shop_name, email FROM owner_account WHERE id = ?");
        $ownerStmt->execute([$filterOwnerId]);
    } else {
        $ownerStmt = $pdo->query("SELECT id, full_name, shop_name, email FROM owner_account ORDER BY id ASC");
    }

    $candidates = $ownerStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($candidates)) {
        echo "No shop owners found to process.\n";
        exit(0);
    }

    $totalProcessed = 0;
    $totalSent = 0;
    $totalSkipped = 0;
    $totalFailed = 0;

    foreach ($candidates as $owner) {
        $shopId = (int)$owner['id'];
        $shopName = trim($owner['shop_name'] ?? 'Shop');
        $email = trim($owner['email'] ?? '');
        $totalProcessed++;

        // 4. Validate email address format
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo " - Shop #{$shopId} ('{$shopName}'): Skipped (no valid email address registered: '{$email}')\n";
            $totalSkipped++;
            continue;
        }

        // 5. Check if weekly_email_digest toggle is ON
        $settings = get_shop_settings($pdo, $shopId);
        if (empty($settings['weekly_email_digest'])) {
            echo " - Shop #{$shopId} ('{$shopName}'): Skipped (weekly_email_digest setting is OFF)\n";
            $totalSkipped++;
            continue;
        }

        // 6. Deduplication: Check if digest was sent in the last 6 days
        // (In test mode, allow send unless duplicate check is requested)
        if (!$isTestMode && !$forceSend) {
            $dedupeStmt = $pdo->prepare("
                SELECT id, sent_at
                FROM digest_log
                WHERE shop_owner_id = ?
                  AND status = 'sent'
                  AND sent_at >= DATE_SUB(NOW(), INTERVAL 6 DAY)
                ORDER BY sent_at DESC
                LIMIT 1
            ");
            $dedupeStmt->execute([$shopId]);
            $recent = $dedupeStmt->fetch(PDO::FETCH_ASSOC);

            if ($recent) {
                $lastSent = $recent['sent_at'];
                echo " ~ Shop #{$shopId} ('{$shopName}'): Skipped (Digest already sent in last 6 days on {$lastSent})\n";
                $totalSkipped++;
                continue;
            }
        }

        // 7. Compile digest and send email (wrapped in try-catch to never crash the whole run)
        try {
            $data = WeeklyDigestService::compileDigestData($pdo, $shopId);
            $subject = WeeklyDigestService::buildSubject($data);
            $htmlBody = WeeklyDigestService::buildHtml($data);
            $textBody = WeeklyDigestService::buildPlainText($data);

            $sendResult = MailClient::send($email, $data['owner_name'], $subject, $htmlBody, $textBody);

            if ($sendResult['status']) {
                // Record success in digest_log
                $logStmt = $pdo->prepare("
                    INSERT INTO digest_log (shop_owner_id, sent_at, status, error_message)
                    VALUES (?, NOW(), 'sent', NULL)
                ");
                $logStmt->execute([$shopId]);

                $salesFmt = WeeklyDigestService::formatRupees($data['current_sales']);
                echo " + Shop #{$shopId} ('{$shopName}'): Sent to {$email}. Sales: {$salesFmt}, Orders: {$data['current_orders']}\n";
                $totalSent++;
            } else {
                $errMsg = $sendResult['error'] ?? 'Unknown mail failure';
                // Record failure in digest_log
                $logStmt = $pdo->prepare("
                    INSERT INTO digest_log (shop_owner_id, sent_at, status, error_message)
                    VALUES (?, NOW(), 'failed', ?)
                ");
                $logStmt->execute([$shopId, substr($errMsg, 0, 500)]);

                echo " ! Shop #{$shopId} ('{$shopName}'): Failed to send - {$errMsg}\n";
                $totalFailed++;
            }

        } catch (Throwable $e) {
            $safeError = "Digest compilation error: " . $e->getMessage();
            error_log("weekly_digest error for shop {$shopId}: " . $safeError);

            $logStmt = $pdo->prepare("
                INSERT INTO digest_log (shop_owner_id, sent_at, status, error_message)
                VALUES (?, NOW(), 'failed', ?)
            ");
            $logStmt->execute([$shopId, substr($safeError, 0, 500)]);

            echo " ! Shop #{$shopId} ('{$shopName}'): Error - {$safeError}\n";
            $totalFailed++;
        }
    }

    echo "[" . date('Y-m-d H:i:s') . "] Finished Weekly Digest Run. Processed: {$totalProcessed}, Sent: {$totalSent}, Skipped: {$totalSkipped}, Failed: {$totalFailed}\n";

} catch (Throwable $e) {
    echo "[CRITICAL] Weekly digest cron runner encountered an error: " . $e->getMessage() . "\n";
    exit(1);
}
