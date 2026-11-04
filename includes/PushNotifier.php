<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../settings/settings_helper.php";
require_once __DIR__ . "/FcmSender.php";

/**
 * StockSense Push Notification Orchestrator.
 * 
 * Manages notification dispatching, deduplication through `push_log`,
 * and strict shop settings toggles enforcement.
 * 
 * Enforced Rules:
 *  - low_stock: only sent if low_stock_alerts toggle is ON.
 *  - reorder_suggestion: only sent if ai_recommendations toggle is ON. Deduped per product per day.
 *  - daily_summary: only sent if daily_sales_summary toggle is ON.
 *  - order_placed, order_received, import_done: NO pushes.
 *  - weekly_email_digest: NO push.
 */
class PushNotifier {

    /**
     * Formats an amount with Indian numbering grouping and the ₹ currency symbol.
     * Examples:
     *   0        => ₹0
     *   1480     => ₹1,480
     *   120500   => ₹1,20,500
     *   10000000 => ₹1,00,00,000
     */
    public static function formatIndianCurrency($number): string {
        $num = round((float)$number);
        $isNegative = $num < 0;
        $numStr = (string)abs($num);
        $len = strlen($numStr);

        if ($len <= 3) {
            return ($isNegative ? '-' : '') . '₹' . $numStr;
        }

        $last3 = substr($numStr, -3);
        $remaining = substr($numStr, 0, $len - 3);

        $formattedRemaining = '';
        while (strlen($remaining) > 2) {
            $formattedRemaining = ',' . substr($remaining, -2) . $formattedRemaining;
            $remaining = substr($remaining, 0, strlen($remaining) - 2);
        }

        $formatted = $remaining . $formattedRemaining . ',' . $last3;
        return ($isNegative ? '-' : '') . '₹' . $formatted;
    }

    /**
     * Central send method strictly respecting shop settings toggles and deduplication.
     *
     * @param PDO $pdo
     * @param int $shopId
     * @param string $type Event type ('low_stock', 'reorder_suggestion', 'daily_summary')
     * @param string $title
     * @param string $body
     * @param array $data Extra payload fields for deep-linking (type, product_id)
     * @param string|null $dedupeKey Deduplication key (e.g. low_stock:12:2026-11-04)
     * @return array
     */
    public static function send(
        PDO $pdo,
        int $shopId,
        string $type,
        string $title,
        string $body,
        array $data = [],
        ?string $dedupeKey = null
    ): array {
        try {
            // 1. Explicitly disallow event types per specification:
            // Do NOT send pushes for order placed, order received, import done, or weekly_email_digest
            if (in_array($type, ['order_placed', 'order_received', 'import_done', 'weekly_email_digest'])) {
                return [
                    'sent' => false,
                    'skipped' => true,
                    'reason' => 'event_type_not_allowed_for_push',
                    'type' => $type
                ];
            }

            // 2. Read shop settings toggles using real key names from get_shop_settings()
            $settings = get_shop_settings($pdo, $shopId);

            // low_stock pushes only if Low stock alerts is ON
            if ($type === 'low_stock') {
                if (empty($settings['low_stock_alerts'])) {
                    return ['sent' => false, 'skipped' => true, 'reason' => 'low_stock_alerts_off'];
                }
            }

            // reorder-suggestion / AI recommendation push only if AI recommendations is ON
            elseif (in_array($type, ['reorder_suggestion', 'ai_recommendation', 'slow_moving'])) {
                if (empty($settings['ai_recommendations'])) {
                    return ['sent' => false, 'skipped' => true, 'reason' => 'ai_recommendations_off'];
                }
            }

            // daily_summary push only if Daily sales summary is ON
            elseif ($type === 'daily_summary') {
                if (empty($settings['daily_sales_summary'])) {
                    return ['sent' => false, 'skipped' => true, 'reason' => 'daily_sales_summary_off'];
                }
            }

            else {
                return [
                    'sent' => false,
                    'skipped' => true,
                    'reason' => 'unrecognized_push_type',
                    'type' => $type
                ];
            }

            // 3. Deduplication check via push_log table
            if ($dedupeKey !== null && trim($dedupeKey) !== '') {
                $dedupeKey = trim($dedupeKey);
                try {
                    $logStmt = $pdo->prepare("
                        INSERT INTO push_log (shop_id, dedupe_key, sent_at)
                        VALUES (?, ?, NOW())
                    ");
                    $logStmt->execute([$shopId, $dedupeKey]);
                } catch (PDOException $pdoEx) {
                    // SQLSTATE 23000: Duplicate key -> already sent
                    if ($pdoEx->getCode() == 23000 || stripos($pdoEx->getMessage(), 'Duplicate entry') !== false) {
                        return [
                            'sent' => false,
                            'skipped' => true,
                            'reason' => 'deduplicated',
                            'dedupe_key' => $dedupeKey
                        ];
                    }
                    throw $pdoEx;
                }
            }

            // 4. Fetch registered device tokens for the shop
            $stmtTokens = $pdo->prepare("SELECT fcm_token FROM device_tokens WHERE shop_id = ?");
            $stmtTokens->execute([$shopId]);
            $tokens = $stmtTokens->fetchAll(PDO::FETCH_COLUMN);

            if (empty($tokens)) {
                return [
                    'sent' => false,
                    'skipped' => true,
                    'reason' => 'no_device_tokens',
                    'shop_id' => $shopId
                ];
            }

            // 5. Ensure data has 'type' key
            if (!isset($data['type'])) {
                $data['type'] = $type;
            }

            // 6. Send to every device token
            $dispatchResult = FcmSender::sendToTokens($pdo, $tokens, $title, $body, $data);

            return [
                'sent' => true,
                'type' => $type,
                'tokens_count' => count($tokens),
                'details' => $dispatchResult
            ];

        } catch (Throwable $e) {
            error_log("PushNotifier::send error for shop {$shopId}: " . $e->getMessage());
            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Trigger low stock alert for a product.
     * Only if low_stock_alerts is ON.
     * Dedupe key: low_stock:<product_id>:<YYYY-MM-DD>
     */
    public static function triggerLowStock(PDO $pdo, int $shopId, array $product): array {
        $units = (int)($product['units'] ?? 0);
        $minStock = isset($product['min_stock_threshold']) ? (int)$product['min_stock_threshold'] : null;

        if ($minStock === null || $units > $minStock) {
            return ['sent' => false, 'skipped' => true, 'reason' => 'not_low_stock'];
        }

        $prodId = (int)$product['id'];
        $prodName = $product['name'] ?? 'Product';
        $today = date('Y-m-d');
        $dedupeKey = "low_stock:{$prodId}:{$today}";

        $title = "Low stock";
        $body = "{$prodName} has {$units} units left.";
        $data = [
            'type' => 'low_stock',
            'product_id' => (string)$prodId
        ];

        return self::send($pdo, $shopId, 'low_stock', $title, $body, $data, $dedupeKey);
    }

    /**
     * Trigger reorder-suggestion push (new AI recommendation or insight).
     * Only if ai_recommendations is ON.
     * Dedupe per product per day: reorder_suggestion:<product_id>:<YYYY-MM-DD>
     */
    public static function triggerReorderSuggestion(
        PDO $pdo,
        int $shopId,
        int $productId,
        string $productName,
        int $suggestedQty,
        ?float $estimatedCost = null
    ): array {
        $today = date('Y-m-d');
        $dedupeKey = "reorder_suggestion:{$productId}:{$today}";

        $title = "Reorder suggestion";
        $costText = ($estimatedCost !== null && $estimatedCost > 0)
            ? " (" . self::formatIndianCurrency($estimatedCost) . ")"
            : "";
        $body = "AI suggests reordering {$suggestedQty} units of {$productName}{$costText}.";

        $data = [
            'type' => 'reorder_suggestion',
            'product_id' => (string)$productId
        ];

        return self::send($pdo, $shopId, 'reorder_suggestion', $title, $body, $data, $dedupeKey);
    }

    /**
     * Trigger general AI recommendation or insight push.
     * Only if ai_recommendations is ON.
     * Dedupe per product per day: reorder_suggestion:<product_id>:<YYYY-MM-DD>
     */
    public static function triggerAiRecommendation(
        PDO $pdo,
        int $shopId,
        int $productId,
        string $title,
        string $body,
        array $data = []
    ): array {
        $today = date('Y-m-d');
        $dedupeKey = "reorder_suggestion:{$productId}:{$today}";

        $data['type'] = 'reorder_suggestion';
        $data['product_id'] = (string)$productId;

        return self::send($pdo, $shopId, 'reorder_suggestion', $title, $body, $data, $dedupeKey);
    }
}
