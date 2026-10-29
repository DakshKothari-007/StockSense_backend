<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../settings/settings_helper.php";

date_default_timezone_set('Asia/Kolkata');

/**
 * Generates live notifications from current store data, cleans up resolved state keys,
 * and merges with user read/dismissed states.
 *
 * @param PDO $pdo
 * @param int $shopOwnerId
 * @param array $options ['type' => string, 'unread_only' => bool, 'limit' => int, 'page' => int]
 * @return array ['items' => array, 'unread_count' => int, 'total_count' => int]
 */
function get_shop_notifications(PDO $pdo, int $shopOwnerId, array $options = []): array {
    $typeFilter = $options['type'] ?? '';
    $unreadOnly = !empty($options['unread_only']);
    $limit = isset($options['limit']) ? min(max((int)$options['limit'], 1), 50) : 50;
    $page = isset($options['page']) ? max((int)$options['page'], 1) : 1;
    $offset = ($page - 1) * $limit;

    // Fetch dynamic shop settings
    $settings = get_shop_settings($pdo, $shopOwnerId);
    $lowStockEnabled = !empty($settings['low_stock_alerts']);
    $aiRecsEnabled = !empty($settings['ai_recommendations']);
    $dailySalesEnabled = !empty($settings['daily_sales_summary']);

    $leadDays = (int)($settings['lead_time_days'] ?? 2);
    $safetyDays = (int)($settings['safety_days'] ?? 3);
    $coverDays = (int)($settings['cover_days'] ?? 7);
    $slowMovingThreshold = (int)($settings['slow_moving_threshold_days'] ?? 60);
    $targetCoverDays = $leadDays + $safetyDays + $coverDays; // e.g. 12 days

    $notifications = [];
    $activeLowStockKeys = [];
    $activeSlowMovingKeys = [];
    $currentDate = date('Y-m-d');

    // -------------------------------------------------------------
    // 1. LIVE LOW-STOCK NOTIFICATIONS
    // -------------------------------------------------------------
    if ($lowStockEnabled) {
        $stmtLow = $pdo->prepare(
            "SELECT p.id, p.name, p.units, p.min_stock_threshold, p.cost_price, p.price,
                    COALESCE(SUM(si.quantity), 0) AS sold_30_days
             FROM products p
             LEFT JOIN sales_items si ON p.id = si.product_id
             LEFT JOIN sales s ON si.sale_id = s.id AND s.shop_owner_id = ? AND s.sale_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             WHERE p.shop_owner_id = ?
               AND p.min_stock_threshold IS NOT NULL
               AND p.units <= p.min_stock_threshold
             GROUP BY p.id
             ORDER BY p.units ASC, p.id ASC"
        );
        $stmtLow->execute([$shopOwnerId, $shopOwnerId]);
        $lowProducts = $stmtLow->fetchAll(PDO::FETCH_ASSOC);

        foreach ($lowProducts as $prod) {
            $prodId = (int)$prod['id'];
            $key = "low_stock:{$prodId}";
            $activeLowStockKeys[] = $key;

            $units = (int)$prod['units'];
            $minThreshold = (int)$prod['min_stock_threshold'];
            $sold30 = (int)$prod['sold_30_days'];

            // Compute suggested reorder qty & cost
            if ($sold30 > 0) {
                $unitsPerDay = $sold30 / 30.0;
                $targetStock = (int)ceil($unitsPerDay * $targetCoverDays);
                $suggestedQty = max($targetStock - $units, 1);
            } else {
                $suggestedQty = max($minThreshold * 2, 10);
            }

            $costPrice = $prod['cost_price'] !== null ? (float)$prod['cost_price'] : null;
            $estimatedCost = $costPrice !== null ? round($suggestedQty * $costPrice, 2) : null;

            if ($units === 0) {
                $title = "{$prod['name']} is out of stock";
                $body = "Zero units left in stock. AI suggests reordering {$suggestedQty} units" . ($estimatedCost !== null ? " for ₹" . number_format($estimatedCost, 2) . "." : ".");
            } else {
                $title = "{$prod['name']} is running low";
                $body = "Only {$units} units left. AI suggests reordering {$suggestedQty} units" . ($estimatedCost !== null ? " for ₹" . number_format($estimatedCost, 2) . "." : ".");
            }

            $notifications[] = [
                'key' => $key,
                'type' => 'low_stock',
                'title' => $title,
                'body' => $body,
                'numbers_used' => [
                    'units' => $units,
                    'min_stock_threshold' => $minThreshold,
                    'suggested_quantity' => $suggestedQty,
                    'estimated_cost' => $estimatedCost
                ],
                'created_for_date' => $currentDate,
                'product_id' => $prodId,
                'priority' => 1
            ];
        }
    }

    // Clean up cleared low-stock read states (if product was restocked, condition cleared)
    try {
        $stmtCleanLow = $pdo->prepare("SELECT notification_key FROM notification_states WHERE shop_owner_id = ? AND notification_key LIKE 'low_stock:%'");
        $stmtCleanLow->execute([$shopOwnerId]);
        $savedLowKeys = $stmtCleanLow->fetchAll(PDO::FETCH_COLUMN);

        $keysToDelete = array_diff($savedLowKeys, $activeLowStockKeys);
        if (!empty($keysToDelete)) {
            $inClause = implode(',', array_fill(0, count($keysToDelete), '?'));
            $deleteParams = array_merge([$shopOwnerId], array_values($keysToDelete));
            $pdo->prepare("DELETE FROM notification_states WHERE shop_owner_id = ? AND notification_key IN ($inClause)")->execute($deleteParams);
        }
    } catch (Throwable $e) {
        error_log("Failed to clean cleared low-stock states: " . $e->getMessage());
    }

    // -------------------------------------------------------------
    // 2. LIVE SLOW-MOVING NOTIFICATIONS
    // -------------------------------------------------------------
    if ($aiRecsEnabled) {
        // Query products that are slow-moving (units > 0, not on reorder list)
        $stmtSlow = $pdo->prepare(
            "SELECT p.id, p.name, p.units, p.cost_price, p.price,
                    COALESCE(SUM(si.quantity), 0) AS units_sold_30_days
             FROM products p
             LEFT JOIN sales_items si ON p.id = si.product_id
             LEFT JOIN sales s ON si.sale_id = s.id AND s.shop_owner_id = ? AND s.sale_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             WHERE p.shop_owner_id = ?
               AND p.units > 0
               AND (p.min_stock_threshold IS NULL OR p.units > p.min_stock_threshold)
             GROUP BY p.id
             ORDER BY p.units DESC"
        );
        $stmtSlow->execute([$shopOwnerId, $shopOwnerId]);
        $candidateProducts = $stmtSlow->fetchAll(PDO::FETCH_ASSOC);

        foreach ($candidateProducts as $prod) {
            $prodId = (int)$prod['id'];
            $units = (int)$prod['units'];
            $sold30 = (int)$prod['units_sold_30_days'];

            $isSlow = false;
            $stockCoverDays = null;

            if ($sold30 === 0) {
                $isSlow = true;
            } else {
                $dailyRate = $sold30 / 30.0;
                $stockCoverDays = round($units / $dailyRate, 1);
                if ($stockCoverDays >= $slowMovingThreshold) {
                    $isSlow = true;
                }
            }

            if ($isSlow) {
                $key = "slow_moving:{$prodId}";
                $activeSlowMovingKeys[] = $key;

                $notifications[] = [
                    'key' => $key,
                    'type' => 'slow_moving',
                    'title' => "{$prod['name']} needs attention",
                    'body' => "{$units} packs in stock, just {$sold30} sold in 30 days. Review a clearance or bundle offer.",
                    'numbers_used' => [
                        'units' => $units,
                        'units_sold_30_days' => $sold30,
                        'stock_cover_days' => $stockCoverDays
                    ],
                    'created_for_date' => $currentDate,
                    'product_id' => $prodId,
                    'priority' => 2
                ];
            }
        }
    }

    // Clean up cleared slow-moving read states
    try {
        $stmtCleanSlow = $pdo->prepare("SELECT notification_key FROM notification_states WHERE shop_owner_id = ? AND notification_key LIKE 'slow_moving:%'");
        $stmtCleanSlow->execute([$shopOwnerId]);
        $savedSlowKeys = $stmtCleanSlow->fetchAll(PDO::FETCH_COLUMN);

        $keysToDeleteSlow = array_diff($savedSlowKeys, $activeSlowMovingKeys);
        if (!empty($keysToDeleteSlow)) {
            $inClause = implode(',', array_fill(0, count($keysToDeleteSlow), '?'));
            $deleteParams = array_merge([$shopOwnerId], array_values($keysToDeleteSlow));
            $pdo->prepare("DELETE FROM notification_states WHERE shop_owner_id = ? AND notification_key IN ($inClause)")->execute($deleteParams);
        }
    } catch (Throwable $e) {
        error_log("Failed to clean cleared slow-moving states: " . $e->getMessage());
    }

    // -------------------------------------------------------------
    // 3. LIVE SALES PERFORMANCE NOTIFICATION
    // -------------------------------------------------------------
    if ($dailySalesEnabled) {
        $currentTime = date('H:i:s');
        $todayStart = "{$currentDate} 00:00:00";
        $nowDateTime = date('Y-m-d H:i:s');

        $yesterdayDate = date('Y-m-d', strtotime('-1 day'));
        $yesterdayStart = "{$yesterdayDate} 00:00:00";
        $yesterdayComparableEnd = "{$yesterdayDate} {$currentTime}";

        // Today so far
        $stmtToday = $pdo->prepare(
            "SELECT COALESCE(SUM(total_amount), 0) AS total_sales, COUNT(*) AS bills_count
             FROM sales
             WHERE shop_owner_id = ? AND sale_date >= ? AND sale_date <= ?"
        );
        $stmtToday->execute([$shopOwnerId, $todayStart, $nowDateTime]);
        $todayRow = $stmtToday->fetch(PDO::FETCH_ASSOC);
        $todaySales = (float)$todayRow['total_sales'];
        $todayBills = (int)$todayRow['bills_count'];

        // Yesterday up to the same time
        $stmtYest = $pdo->prepare(
            "SELECT COALESCE(SUM(total_amount), 0) AS total_sales, COUNT(*) AS bills_count
             FROM sales
             WHERE shop_owner_id = ? AND sale_date >= ? AND sale_date <= ?"
        );
        $stmtYest->execute([$shopOwnerId, $yesterdayStart, $yesterdayComparableEnd]);
        $yestRow = $stmtYest->fetch(PDO::FETCH_ASSOC);
        $yestSales = (float)$yestRow['total_sales'];
        $yestBills = (int)$yestRow['bills_count'];

        // Only generate sales notification if there is sales activity either today or yesterday
        if ($todayBills > 0 || $yestBills > 0) {
            $key = "sales:{$currentDate}";
            $pctChange = null;

            if ($yestSales > 0) {
                $pctChange = (int)round((($todaySales - $yestSales) / $yestSales) * 100);
                if ($pctChange >= 0) {
                    $title = "Sales are up {$pctChange}% so far today";
                } else {
                    $title = "Sales are down " . abs($pctChange) . "% so far today";
                }
            } else {
                $title = "Sales summary so far today";
            }

            $body = "₹" . number_format($todaySales, 2) . " in sales from {$todayBills} bills so far today. Yesterday up to this time was ₹" . number_format($yestSales, 2) . ".";

            $notifications[] = [
                'key' => $key,
                'type' => 'sales',
                'title' => $title,
                'body' => $body,
                'numbers_used' => [
                    'today_sales' => $todaySales,
                    'today_bills' => $todayBills,
                    'yesterday_comparable_sales' => $yestSales,
                    'yesterday_comparable_bills' => $yestBills,
                    'percentage_change' => $pctChange
                ],
                'created_for_date' => $currentDate,
                'product_id' => null,
                'priority' => 3
            ];
        }
    }

    // -------------------------------------------------------------
    // 4. LIVE IMPORT STATUS NOTIFICATION
    // -------------------------------------------------------------
    $stmtImport = $pdo->prepare(
        "SELECT id, file_name, total_rows, success_count, failed_count, created_at
         FROM import_history
         WHERE shop_owner_id = ?
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmtImport->execute([$shopOwnerId]);
    $latestImport = $stmtImport->fetch(PDO::FETCH_ASSOC);

    if ($latestImport) {
        $importId = (int)$latestImport['id'];
        $importKey = "import:{$importId}";
        $totalRows = (int)$latestImport['total_rows'];
        $successCount = (int)$latestImport['success_count'];
        $failedCount = (int)$latestImport['failed_count'];
        $fileName = $latestImport['file_name'];
        $importDate = substr($latestImport['created_at'], 0, 10);

        $notifications[] = [
            'key' => $importKey,
            'type' => 'import',
            'title' => "Inventory import completed",
            'body' => "{$totalRows} items processed from {$fileName} ({$successCount} successful, {$failedCount} failed).",
            'numbers_used' => [
                'total_rows' => $totalRows,
                'success_count' => $successCount,
                'failed_count' => $failedCount
            ],
            'created_for_date' => $importDate,
            'product_id' => null,
            'priority' => 4
        ];
    }

    // -------------------------------------------------------------
    // 5. MERGE SAVED USER STATES (read_at & dismissed_at)
    // -------------------------------------------------------------
    $stmtStates = $pdo->prepare(
        "SELECT notification_key, read_at, dismissed_at
         FROM notification_states
         WHERE shop_owner_id = ?"
    );
    $stmtStates->execute([$shopOwnerId]);
    $savedStates = [];
    while ($row = $stmtStates->fetch(PDO::FETCH_ASSOC)) {
        $savedStates[$row['notification_key']] = $row;
    }

    $finalItems = [];
    $unreadCount = 0;

    foreach ($notifications as $item) {
        $key = $item['key'];
        $state = $savedStates[$key] ?? null;

        // Skip dismissed notifications
        if ($state && $state['dismissed_at'] !== null) {
            continue;
        }

        $isRead = ($state && $state['read_at'] !== null);
        if (!$isRead) {
            $unreadCount++;
        }

        // Apply type filter if requested
        if ($typeFilter !== '' && $typeFilter !== 'all' && $item['type'] !== $typeFilter) {
            continue;
        }

        // Apply unread-only filter if requested
        if ($unreadOnly && $isRead) {
            continue;
        }

        $item['is_read'] = $isRead;
        $item['read_at'] = $state ? $state['read_at'] : null;
        $finalItems[] = $item;
    }

    // Sort: unread first, then by priority, then by key
    usort($finalItems, function($a, $b) {
        if ($a['is_read'] !== $b['is_read']) {
            return $a['is_read'] ? 1 : -1;
        }
        if ($a['priority'] !== $b['priority']) {
            return $a['priority'] <=> $b['priority'];
        }
        return strcmp($a['key'], $b['key']);
    });

    $totalFiltered = count($finalItems);
    $pagedItems = array_slice($finalItems, $offset, $limit);

    // Remove internal 'priority' sorting key from output
    foreach ($pagedItems as &$it) {
        unset($it['priority']);
    }
    unset($it);

    return [
        'items' => $pagedItems,
        'unread_count' => $unreadCount,
        'total_count' => $totalFiltered
    ];
}
