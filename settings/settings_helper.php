<?php

/**
 * Standard default settings across StockSense.
 */
const DEFAULT_SHOP_SETTINGS = [
    'low_stock_alerts' => true,
    'ai_recommendations' => true,
    'daily_sales_summary' => true,
    'weekly_email_digest' => false,
    'theme_mode' => 'SYSTEM',
    'lead_time_days' => 2,
    'safety_days' => 3,
    'cover_days' => 7,
    'min_history_days' => 30,
    'slow_moving_threshold_days' => 60,
    'import_mode' => 'ADD',
    'timezone' => 'Asia/Kolkata'
];

/**
 * Fetch settings for a specific shop.
 *
 * Rule 4: Read-only operation. Returns defaults when no row exists without writing to database.
 * Rule 6: Single centralized helper for reorder, insights, dashboard, and import.
 *
 * @param PDO $pdo
 * @param int $shopId
 * @return array
 */
function get_shop_settings(PDO $pdo, int $shopId): array {
    $stmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ? LIMIT 1");
    $stmt->execute([$shopId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return array_merge(DEFAULT_SHOP_SETTINGS, [
            'is_customized' => false,
            'updated_at' => null,
            'failed_password_attempts' => 0,
            'password_locked_until' => null
        ]);
    }

    return [
        'low_stock_alerts' => (bool)$row['low_stock_alerts'],
        'ai_recommendations' => (bool)$row['ai_recommendations'],
        'daily_sales_summary' => (bool)$row['daily_sales_summary'],
        'weekly_email_digest' => (bool)$row['weekly_email_digest'],
        'theme_mode' => $row['theme_mode'] ?? DEFAULT_SHOP_SETTINGS['theme_mode'],
        'lead_time_days' => isset($row['lead_time_days']) ? (int)$row['lead_time_days'] : DEFAULT_SHOP_SETTINGS['lead_time_days'],
        'safety_days' => isset($row['safety_days']) ? (int)$row['safety_days'] : DEFAULT_SHOP_SETTINGS['safety_days'],
        'cover_days' => isset($row['cover_days']) ? (int)$row['cover_days'] : DEFAULT_SHOP_SETTINGS['cover_days'],
        'min_history_days' => isset($row['min_history_days']) ? (int)$row['min_history_days'] : DEFAULT_SHOP_SETTINGS['min_history_days'],
        'slow_moving_threshold_days' => isset($row['slow_moving_threshold_days']) ? (int)$row['slow_moving_threshold_days'] : DEFAULT_SHOP_SETTINGS['slow_moving_threshold_days'],
        'import_mode' => $row['import_mode'] ?? DEFAULT_SHOP_SETTINGS['import_mode'],
        'timezone' => $row['timezone'] ?? DEFAULT_SHOP_SETTINGS['timezone'],
        'is_customized' => true,
        'updated_at' => $row['updated_at'] ?? null,
        'failed_password_attempts' => isset($row['failed_password_attempts']) ? (int)$row['failed_password_attempts'] : 0,
        'password_locked_until' => $row['password_locked_until'] ?? null
    ];
}
