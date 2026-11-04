<?php

/**
 * StockSense Daily Summary Cron Script.
 * 
 * Computes yesterday's sales total and current low stock count for each store owner.
 * Dispatches a push notification if the shop has daily_sales_summary toggle ON.
 * Deduplicates notifications per shop per day.
 * 
 * Usage:
 *   php cron/daily_summary.php [YYYY-MM-DD]
 */

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/PushNotifier.php";

$targetDate = isset($argv[1]) ? trim($argv[1]) : date('Y-m-d', strtotime('-1 day'));

echo "[" . date('Y-m-d H:i:s') . "] Starting StockSense Daily Summary for date: {$targetDate}\n";

try {
    $stmtOwners = $pdo->query("SELECT id, shop_name FROM owner_account ORDER BY id ASC");
    $owners = $stmtOwners->fetchAll(PDO::FETCH_ASSOC);

    $totalProcessed = 0;
    $totalSent = 0;
    $totalSkipped = 0;

    foreach ($owners as $owner) {
        $shopId = (int)$owner['id'];
        $shopName = $owner['shop_name'];
        $totalProcessed++;

        // 1. Check shop settings
        $settings = get_shop_settings($pdo, $shopId);
        if (empty($settings['daily_sales_summary'])) {
            echo " - Shop #{$shopId} ('{$shopName}'): Skipped (daily_sales_summary toggle is OFF)\n";
            $totalSkipped++;
            continue;
        }

        // 2. Compute sales total for target date
        $stmtSales = $pdo->prepare("
            SELECT COALESCE(SUM(total_amount), 0) AS total_sales, COUNT(*) AS bill_count
            FROM sales
            WHERE shop_owner_id = ?
              AND DATE(sale_date) = ?
        ");
        $stmtSales->execute([$shopId, $targetDate]);
        $salesRow = $stmtSales->fetch(PDO::FETCH_ASSOC);
        $totalSales = (float)($salesRow['total_sales'] ?? 0);
        $billCount = (int)($salesRow['bill_count'] ?? 0);

        // 3. Compute low stock count
        $stmtLow = $pdo->prepare("
            SELECT COUNT(*) AS low_count
            FROM products
            WHERE shop_owner_id = ?
              AND min_stock_threshold IS NOT NULL
              AND units <= min_stock_threshold
        ");
        $stmtLow->execute([$shopId]);
        $lowCount = (int)$stmtLow->fetchColumn();

        // 4. Construct title, body, and dedupe key
        $amountStr = PushNotifier::formatIndianCurrency($totalSales);
        $lowStockMsg = ($lowCount > 0)
            ? ($lowCount === 1 ? "1 product low on stock." : "{$lowCount} products low on stock.")
            : "All products in healthy stock.";

        $title = "Daily summary";
        $body = "Yesterday's sales: {$amountStr}. {$lowStockMsg}";
        $dedupeKey = "daily_summary:{$shopId}:{$targetDate}";
        $data = [
            'type' => 'daily_summary',
            'date' => $targetDate,
            'sales_amount' => (string)$totalSales,
            'low_stock_count' => (string)$lowCount
        ];

        // 5. Send push notification
        $res = PushNotifier::send($pdo, $shopId, 'daily_summary', $title, $body, $data, $dedupeKey);

        if (!empty($res['sent'])) {
            echo " + Shop #{$shopId} ('{$shopName}'): Sent to {$res['tokens_count']} device(s). Sales: {$amountStr}, Low stock: {$lowCount}\n";
            $totalSent++;
        } elseif (!empty($res['skipped'])) {
            echo " ~ Shop #{$shopId} ('{$shopName}'): Skipped ({$res['reason']})\n";
            $totalSkipped++;
        } else {
            echo " ! Shop #{$shopId} ('{$shopName}'): Error (" . ($res['error'] ?? 'unknown') . ")\n";
        }
    }

    echo "[" . date('Y-m-d H:i:s') . "] Finished Daily Summary. Processed: {$totalProcessed}, Sent: {$totalSent}, Skipped: {$totalSkipped}\n";

} catch (Throwable $e) {
    echo "[ERROR] Daily summary execution failed: " . $e->getMessage() . "\n";
    exit(1);
}

