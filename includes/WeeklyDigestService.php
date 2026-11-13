<?php

/**
 * StockSense WeeklyDigestService.
 *
 * Compiles real 7-day shop data, computes week-over-week comparisons,
 * identifies top sellers, slowest movers, low stock reorders, and open PO counts.
 * Renders high-quality responsive HTML and plain-text emails in brand colors (#2FD39A, #101827).
 */

require_once __DIR__ . '/../settings/settings_helper.php';
require_once __DIR__ . '/MailClient.php';

class WeeklyDigestService {

    /**
     * Compute digest data for a specific shop owner.
     *
     * @param PDO $pdo
     * @param int $shopOwnerId
     * @param string|null $overrideEndDate Optional anchor date (YYYY-MM-DD), defaults to today
     * @return array
     */
    public static function compileDigestData(PDO $pdo, int $shopOwnerId, ?string $overrideEndDate = null): array {
        $tz = new DateTimeZone('Asia/Kolkata');
        $endDt = $overrideEndDate ? DateTime::createFromFormat('Y-m-d', $overrideEndDate, $tz) : new DateTime('now', $tz);
        if (!$endDt) {
            $endDt = new DateTime('now', $tz);
        }

        // Current 7-day period (inclusive)
        $endDate = $endDt->format('Y-m-d');
        $startDt = clone $endDt;
        $startDt->modify('-6 days');
        $startDate = $startDt->format('Y-m-d');

        // Previous 7-day period
        $prevEndDt = clone $startDt;
        $prevEndDt->modify('-1 day');
        $prevEndDate = $prevEndDt->format('Y-m-d');
        $prevStartDt = clone $prevEndDt;
        $prevStartDt->modify('-6 days');
        $prevStartDate = $prevStartDt->format('Y-m-d');

        // Formatted date range: e.g. "7 Nov - 13 Nov 2026"
        $rangeLabel = $startDt->format('j M') . ' - ' . $endDt->format('j M Y');

        // 1. Fetch Shop & Owner details
        $ownerStmt = $pdo->prepare("SELECT id, full_name, shop_name, email, currency FROM owner_account WHERE id = ?");
        $ownerStmt->execute([$shopOwnerId]);
        $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);
        if (!$owner) {
            throw new RuntimeException("Shop owner ID {$shopOwnerId} does not exist.");
        }

        $shopName = !empty($owner['shop_name']) ? trim($owner['shop_name']) : 'My Shop';
        $ownerName = !empty($owner['full_name']) ? trim($owner['full_name']) : 'Store Owner';
        $recipientEmail = trim($owner['email'] ?? '');

        // 2. Current Week Sales & Order Count
        $salesStmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_amount), 0) AS total_sales, COUNT(*) AS total_orders
            FROM sales
            WHERE shop_owner_id = ? AND DATE(sale_date) BETWEEN ? AND ?
        ");
        $salesStmt->execute([$shopOwnerId, $startDate, $endDate]);
        $currentSalesRow = $salesStmt->fetch(PDO::FETCH_ASSOC);
        $currentSales = (float)($currentSalesRow['total_sales'] ?? 0.0);
        $currentOrders = (int)($currentSalesRow['total_orders'] ?? 0);

        // 3. Previous Week Sales & Order Count
        $prevSalesStmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_amount), 0) AS total_sales, COUNT(*) AS total_orders
            FROM sales
            WHERE shop_owner_id = ? AND DATE(sale_date) BETWEEN ? AND ?
        ");
        $prevSalesStmt->execute([$shopOwnerId, $prevStartDate, $prevEndDate]);
        $prevSalesRow = $prevSalesStmt->fetch(PDO::FETCH_ASSOC);
        $prevSales = (float)($prevSalesRow['total_sales'] ?? 0.0);
        $prevOrders = (int)($prevSalesRow['total_orders'] ?? 0);

        $salesChange = self::calcPctChange($currentSales, $prevSales);
        $ordersChange = self::calcPctChange((float)$currentOrders, (float)$prevOrders);

        // 4. Top 5 Sellers (Last 7 Days)
        $topStmt = $pdo->prepare("
            SELECT 
                p.id, p.name, p.sku,
                COALESCE(SUM(si.quantity), 0) AS units_sold,
                COALESCE(SUM(si.line_total), 0) AS revenue
            FROM sales_items si
            JOIN sales s ON si.sale_id = s.id
            JOIN products p ON si.product_id = p.id
            WHERE s.shop_owner_id = ? AND DATE(s.sale_date) BETWEEN ? AND ?
            GROUP BY p.id, p.name, p.sku
            ORDER BY units_sold DESC, revenue DESC
            LIMIT 5
        ");
        $topStmt->execute([$shopOwnerId, $startDate, $endDate]);
        $topSellers = $topStmt->fetchAll(PDO::FETCH_ASSOC);

        // 5. 5 Slowest Movers (Active products in stock with lowest units sold in 7 days)
        $slowStmt = $pdo->prepare("
            SELECT 
                p.id, p.name, p.sku, p.units, p.price, p.cost_price,
                COALESCE(sold.qty, 0) AS units_sold
            FROM products p
            LEFT JOIN (
                SELECT si.product_id, SUM(si.quantity) AS qty
                FROM sales_items si
                JOIN sales s ON si.sale_id = s.id
                WHERE s.shop_owner_id = ? AND DATE(s.sale_date) BETWEEN ? AND ?
                GROUP BY si.product_id
            ) sold ON p.id = sold.product_id
            WHERE p.shop_owner_id = ?
              AND (p.status IS NULL OR (p.status NOT LIKE '%Archived%' AND p.status NOT LIKE '%Inactive%'))
              AND p.units > 0
            ORDER BY units_sold ASC, p.units DESC
            LIMIT 5
        ");
        $slowStmt->execute([$shopOwnerId, $startDate, $endDate, $shopOwnerId]);
        $slowMovers = $slowStmt->fetchAll(PDO::FETCH_ASSOC);

        // 6. Low Stock Items & Reorder Suggestions (Top 5)
        $lowStmt = $pdo->prepare("
            SELECT 
                p.id, p.name, p.sku, p.units, p.min_stock_threshold, p.cost_price, p.price,
                COALESCE(open_po.on_order_qty, 0) AS on_order_qty
            FROM products p
            LEFT JOIN (
                SELECT poi.product_id, SUM(poi.quantity) AS on_order_qty
                FROM purchase_order_items poi
                JOIN purchase_orders po ON poi.purchase_order_id = po.id
                WHERE po.shop_owner_id = ? AND po.status = 'placed'
                GROUP BY poi.product_id
            ) open_po ON p.id = open_po.product_id
            WHERE p.shop_owner_id = ?
              AND (p.status IS NULL OR (p.status NOT LIKE '%Archived%' AND p.status NOT LIKE '%Inactive%'))
              AND p.min_stock_threshold IS NOT NULL
              AND p.units <= p.min_stock_threshold
            ORDER BY (p.units - p.min_stock_threshold) ASC, p.units ASC
            LIMIT 5
        ");
        $lowStmt->execute([$shopOwnerId, $shopOwnerId]);
        $lowStockRows = $lowStmt->fetchAll(PDO::FETCH_ASSOC);

        $lowStockSuggestions = [];
        foreach ($lowStockRows as $row) {
            $currentUnits = (int)$row['units'];
            $minThreshold = (int)$row['min_stock_threshold'];
            $onOrder = (int)$row['on_order_qty'];

            $suggested = max(10, ($minThreshold * 2) - $currentUnits - $onOrder);
            if ($suggested <= 0) {
                $suggested = $minThreshold;
            }

            $lowStockSuggestions[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'sku' => $row['sku'],
                'units' => $currentUnits,
                'min_stock_threshold' => $minThreshold,
                'on_order_qty' => $onOrder,
                'suggested_reorder' => $suggested,
                'cost_price' => (float)($row['cost_price'] ?? 0.0)
            ];
        }

        // 7. Open Purchase Orders Count
        $poStmt = $pdo->prepare("
            SELECT COUNT(*) AS open_count
            FROM purchase_orders
            WHERE shop_owner_id = ? AND status = 'placed'
        ");
        $poStmt->execute([$shopOwnerId]);
        $openPoCount = (int)$poStmt->fetchColumn();

        // 8. One Tip Line (Deterministic from real shop data)
        $tipLine = self::generateTip($currentSales, $salesChange, $topSellers, $slowMovers, $lowStockSuggestions, $openPoCount);

        return [
            'shop_owner_id' => $shopOwnerId,
            'shop_name' => $shopName,
            'owner_name' => $ownerName,
            'email' => $recipientEmail,
            'date_range_label' => $rangeLabel,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'prev_start_date' => $prevStartDate,
            'prev_end_date' => $prevEndDate,
            'current_sales' => $currentSales,
            'current_orders' => $currentOrders,
            'prev_sales' => $prevSales,
            'prev_orders' => $prevOrders,
            'sales_change' => $salesChange,
            'orders_change' => $ordersChange,
            'top_sellers' => $topSellers,
            'slow_movers' => $slowMovers,
            'low_stock_suggestions' => $lowStockSuggestions,
            'open_po_count' => $openPoCount,
            'tip_line' => $tipLine
        ];
    }

    /**
     * Compute percentage change and human-readable direction.
     */
    private static function calcPctChange(float $current, float $previous): array {
        if ($previous <= 0.0) {
            if ($current > 0.0) {
                return ['pct' => 100.0, 'dir' => 'up', 'text' => '+100% vs prev week'];
            }
            return ['pct' => 0.0, 'dir' => 'none', 'text' => '0% vs prev week'];
        }
        $diff = (($current - $previous) / $previous) * 100.0;
        $rounded = round($diff, 1);
        if ($rounded > 0) {
            return ['pct' => $rounded, 'dir' => 'up', 'text' => "+{$rounded}% vs prev week"];
        } elseif ($rounded < 0) {
            return ['pct' => abs($rounded), 'dir' => 'down', 'text' => "{$rounded}% vs prev week"];
        } else {
            return ['pct' => 0.0, 'dir' => 'none', 'text' => "0% vs prev week"];
        }
    }

    /**
     * Deterministic insight tip derived from existing numbers.
     */
    private static function generateTip(
        float $sales,
        array $salesChange,
        array $topSellers,
        array $slowMovers,
        array $lowStock,
        int $openPoCount
    ): string {
        if (!empty($lowStock)) {
            $count = count($lowStock);
            $names = $lowStock[0]['name'];
            if ($count === 1) {
                return "1 item ('{$names}') is at or below its minimum threshold. Create a purchase order to prevent a stockout.";
            }
            return "{$count} items (including '{$names}') are low on stock. Review reorders early to ensure shelf availability.";
        }

        if ($salesChange['dir'] === 'up' && $salesChange['pct'] >= 10.0 && !empty($topSellers)) {
            $topName = $topSellers[0]['name'];
            $topUnits = (int)$topSellers[0]['units_sold'];
            return "Strong week! '{$topName}' led sales with {$topUnits} units sold. Ensure supplier lead times cover next week's demand.";
        }

        if (!empty($slowMovers)) {
            $slowest = $slowMovers[0];
            if ((int)$slowest['units_sold'] === 0 && (int)$slowest['units'] > 15) {
                return "'{$slowest['name']}' has {$slowest['units']} units in stock with 0 sales this week. Consider a 10% discount or front-shelf placement.";
            }
        }

        if ($openPoCount > 0) {
            return "You have {$openPoCount} open purchase order" . ($openPoCount > 1 ? "s" : "") . " placed. Once delivered, mark them received in StockSense to update stock immediately.";
        }

        if ($sales <= 0.0) {
            return "No sales recorded this week. Recording bills in StockSense keeps your real-time analytics and inventory alerts accurate.";
        }

        return "Maintain your minimum stock thresholds in Product Details to receive timely alerts and automated reorder calculations.";
    }

    /**
     * Format Indian Currency string (e.g. ₹1,250.00).
     */
    public static function formatRupees(float $amount): string {
        $clean = number_format(abs($amount), 2, '.', ',');
        // Convert to Indian grouping if amount >= 1000
        $parts = explode('.', $clean);
        $num = $parts[0];
        $dec = $parts[1];

        if (strlen($num) > 3) {
            $lastThree = substr($num, -3);
            $remaining = substr($num, 0, -3);
            $num = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $remaining) . ',' . $lastThree;
        }

        return '₹' . ($amount < 0 ? '-' : '') . $num . '.' . $dec;
    }

    /**
     * Build the subject line.
     */
    public static function buildSubject(array $data): string {
        return "Your StockSense weekly report - " . $data['date_range_label'];
    }

    /**
     * Build the responsive HTML email body.
     */
    public static function buildHtml(array $data): string {
        $shopName = htmlspecialchars($data['shop_name'], ENT_QUOTES, 'UTF-8');
        $dateRange = htmlspecialchars($data['date_range_label'], ENT_QUOTES, 'UTF-8');
        $currentSales = $data['current_sales'];
        $currentOrders = $data['current_orders'];
        $salesChange = $data['sales_change'];
        $ordersChange = $data['orders_change'];
        $openPoCount = $data['open_po_count'];
        $tipLine = htmlspecialchars($data['tip_line'], ENT_QUOTES, 'UTF-8');

        // Brand Palette
        $bgDark = '#101827';
        $brandGreen = '#2FD39A';
        $brandGreenDark = '#059669';
        $textDark = '#111827';
        $textMuted = '#6B7280';
        $cardBg = '#FFFFFF';
        $pageBg = '#F3F4F6';

        // Sales pill styling
        $salesBadgeBg = ($salesChange['dir'] === 'up') ? '#ECFDF5' : (($salesChange['dir'] === 'down') ? '#FEF2F2' : '#F3F4F6');
        $salesBadgeColor = ($salesChange['dir'] === 'up') ? '#059669' : (($salesChange['dir'] === 'down') ? '#DC2626' : '#4B5563');
        $salesArrow = ($salesChange['dir'] === 'up') ? '▲ ' : (($salesChange['dir'] === 'down') ? '▼ ' : '');

        // Orders pill styling
        $ordersBadgeBg = ($ordersChange['dir'] === 'up') ? '#ECFDF5' : (($ordersChange['dir'] === 'down') ? '#FEF2F2' : '#F3F4F6');
        $ordersBadgeColor = ($ordersChange['dir'] === 'up') ? '#059669' : (($ordersChange['dir'] === 'down') ? '#DC2626' : '#4B5563');
        $ordersArrow = ($ordersChange['dir'] === 'up') ? '▲ ' : (($ordersChange['dir'] === 'down') ? '▼ ' : '');

        // Format sales amount or "No sales this week"
        $salesDisplay = ($currentSales > 0.0) ? self::formatRupees($currentSales) : 'No sales this week';
        $ordersDisplay = $currentOrders . ' order' . ($currentOrders === 1 ? '' : 's');

        // Top Sellers Rows
        $topSellersHtml = '';
        if (empty($data['top_sellers'])) {
            $topSellersHtml = '<tr><td colspan="4" style="padding:14px; text-align:center; color:#6B7280; font-size:13px;">No sales recorded during this 7-day period.</td></tr>';
        } else {
            $rank = 1;
            foreach ($data['top_sellers'] as $item) {
                $name = htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8');
                $sku = !empty($item['sku']) ? htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8') : '—';
                $units = (int)$item['units_sold'];
                $rev = self::formatRupees((float)$item['revenue']);

                $topSellersHtml .= "
                <tr style=\"border-bottom:1px solid #F3F4F6;\">
                    <td style=\"padding:10px 12px; font-weight:700; color:{$brandGreenDark}; font-size:13px;\">#{$rank}</td>
                    <td style=\"padding:10px 12px; font-size:13px; color:#1F2937;\">
                        <strong>{$name}</strong><br>
                        <span style=\"font-size:11px; color:#9CA3AF;\">SKU: {$sku}</span>
                    </td>
                    <td style=\"padding:10px 12px; text-align:right; font-weight:600; font-size:13px; color:#1F2937;\">{$units}</td>
                    <td style=\"padding:10px 12px; text-align:right; font-weight:600; font-size:13px; color:#1F2937;\">{$rev}</td>
                </tr>";
                $rank++;
            }
        }

        // Slow Movers Rows
        $slowMoversHtml = '';
        if (empty($data['slow_movers'])) {
            $slowMoversHtml = '<tr><td colspan="3" style="padding:14px; text-align:center; color:#6B7280; font-size:13px;">No slow-moving products currently in stock.</td></tr>';
        } else {
            foreach ($data['slow_movers'] as $item) {
                $name = htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8');
                $sku = !empty($item['sku']) ? htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8') : '—';
                $units = (int)$item['units'];
                $sold = (int)$item['units_sold'];

                $slowMoversHtml .= "
                <tr style=\"border-bottom:1px solid #F3F4F6;\">
                    <td style=\"padding:10px 12px; font-size:13px; color:#1F2937;\">
                        <strong>{$name}</strong><br>
                        <span style=\"font-size:11px; color:#9CA3AF;\">SKU: {$sku}</span>
                    </td>
                    <td style=\"padding:10px 12px; text-align:right; font-weight:600; font-size:13px; color:#D97706;\">{$units} in stock</td>
                    <td style=\"padding:10px 12px; text-align:right; font-weight:600; font-size:13px; color:#6B7280;\">{$sold} sold</td>
                </tr>";
            }
        }

        // Low Stock & Reorder Suggestions Rows
        $lowStockHtml = '';
        if (empty($data['low_stock_suggestions'])) {
            $lowStockHtml = '<tr><td colspan="3" style="padding:14px; text-align:center; color:#059669; font-size:13px; font-weight:600;">All products in healthy stock. No reorders needed!</td></tr>';
        } else {
            foreach ($data['low_stock_suggestions'] as $item) {
                $name = htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8');
                $sku = !empty($item['sku']) ? htmlspecialchars($item['sku'], ENT_QUOTES, 'UTF-8') : '—';
                $units = (int)$item['units'];
                $min = (int)$item['min_stock_threshold'];
                $reorder = (int)$item['suggested_reorder'];
                $onOrder = (int)$item['on_order_qty'];

                $onOrderBadge = $onOrder > 0 ? "<br><span style=\"font-size:11px; color:#059669;\">({$onOrder} on order)</span>" : '';

                $lowStockHtml .= "
                <tr style=\"border-bottom:1px solid #F3F4F6;\">
                    <td style=\"padding:10px 12px; font-size:13px; color:#1F2937;\">
                        <strong>{$name}</strong><br>
                        <span style=\"font-size:11px; color:#9CA3AF;\">SKU: {$sku}</span>
                    </td>
                    <td style=\"padding:10px 12px; text-align:right; font-weight:600; font-size:13px; color:#DC2626;\">
                        {$units} / min {$min}{$onOrderBadge}
                    </td>
                    <td style=\"padding:10px 12px; text-align:right; font-weight:700; font-size:13px; color:{$brandGreenDark};\">
                        +{$reorder} units
                    </td>
                </tr>";
            }
        }

        $openPoBadge = $openPoCount > 0
            ? "<span style=\"display:inline-block; padding:4px 10px; background:#EFF6FF; color:#1D4ED8; font-weight:700; font-size:12px; border-radius:12px;\">{$openPoCount} open order" . ($openPoCount > 1 ? 's' : '') . "</span>"
            : "<span style=\"display:inline-block; padding:4px 10px; background:#F3F4F6; color:#6B7280; font-weight:600; font-size:12px; border-radius:12px;\">0 open orders</span>";

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StockSense Weekly Report</title>
</head>
<body style="margin:0; padding:0; background-color:{$pageBg}; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color:{$textDark}; line-height:1.5;">

    <!-- Wrapper Table -->
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:{$pageBg}; padding:24px 12px;">
        <tr>
            <td align="center">

                <!-- Main Container -->
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px; background-color:{$cardBg}; border-radius:14px; overflow:hidden; box-shadow:0 4px 14px rgba(0,0,0,0.06);">

                    <!-- Header -->
                    <tr>
                        <td style="background-color:{$bgDark}; padding:28px 24px; text-align:left;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td>
                                        <div style="display:inline-block; padding:4px 10px; background-color:rgba(47,211,154,0.15); border:1px solid {$brandGreen}; border-radius:6px; color:{$brandGreen}; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">
                                            StockSense
                                        </div>
                                        <h1 style="margin:10px 0 4px 0; color:#FFFFFF; font-size:22px; font-weight:800;">
                                            Weekly Report
                                        </h1>
                                        <p style="margin:0; color:#9CA3AF; font-size:13px;">
                                            {$shopName} &bull; {$dateRange} (IST)
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td style="padding:24px;">

                            <!-- Sales & Orders Summary Grid -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-bottom:20px;">
                                <tr>
                                    <td width="48%" style="background:#F9FAFB; border:1px solid #E5E7EB; border-radius:10px; padding:16px; vertical-align:top;">
                                        <div style="font-size:11px; text-transform:uppercase; font-weight:700; color:{$textMuted}; letter-spacing:0.5px;">Total Sales (7 Days)</div>
                                        <div style="font-size:20px; font-weight:800; color:{$bgDark}; margin:8px 0 6px 0;">{$salesDisplay}</div>
                                        <div style="display:inline-block; padding:3px 8px; background:{$salesBadgeBg}; color:{$salesBadgeColor}; font-size:11px; font-weight:700; border-radius:6px;">
                                            {$salesArrow}{$salesChange['text']}
                                        </div>
                                    </td>
                                    <td width="4%"></td>
                                    <td width="48%" style="background:#F9FAFB; border:1px solid #E5E7EB; border-radius:10px; padding:16px; vertical-align:top;">
                                        <div style="font-size:11px; text-transform:uppercase; font-weight:700; color:{$textMuted}; letter-spacing:0.5px;">Orders (7 Days)</div>
                                        <div style="font-size:20px; font-weight:800; color:{$bgDark}; margin:8px 0 6px 0;">{$ordersDisplay}</div>
                                        <div style="display:inline-block; padding:3px 8px; background:{$ordersBadgeBg}; color:{$ordersBadgeColor}; font-size:11px; font-weight:700; border-radius:6px;">
                                            {$ordersArrow}{$ordersChange['text']}
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <!-- Purchase Orders Row -->
                            <div style="background:#F9FAFB; border:1px solid #E5E7EB; border-radius:10px; padding:12px 16px; margin-bottom:24px; display:flex; justify-content:space-between; align-items:center;">
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                    <tr>
                                        <td style="font-size:13px; color:#374151; font-weight:600;">
                                            Open Purchase Orders
                                        </td>
                                        <td align="right">
                                            {$openPoBadge}
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            <!-- Section 1: Top 5 Sellers -->
                            <h2 style="font-size:15px; font-weight:800; color:{$bgDark}; margin:0 0 10px 0; text-transform:uppercase; letter-spacing:0.3px;">
                                &#127942; Top 5 Sellers
                            </h2>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid #E5E7EB; border-radius:10px; overflow:hidden; margin-bottom:24px;">
                                <tr style="background:#F9FAFB; border-bottom:1px solid #E5E7EB;">
                                    <th style="padding:10px 12px; text-align:left; font-size:11px; text-transform:uppercase; color:#6B7280; font-weight:700; width:36px;">#</th>
                                    <th style="padding:10px 12px; text-align:left; font-size:11px; text-transform:uppercase; color:#6B7280; font-weight:700;">Product</th>
                                    <th style="padding:10px 12px; text-align:right; font-size:11px; text-transform:uppercase; color:#6B7280; font-weight:700;">Units</th>
                                    <th style="padding:10px 12px; text-align:right; font-size:11px; text-transform:uppercase; color:#6B7280; font-weight:700;">Revenue</th>
                                </tr>
                                {$topSellersHtml}
                            </table>

                            <!-- Section 2: 5 Slowest Movers -->
                            <h2 style="font-size:15px; font-weight:800; color:{$bgDark}; margin:0 0 10px 0; text-transform:uppercase; letter-spacing:0.3px;">
                                &#9203; 5 Slowest Movers
                            </h2>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid #E5E7EB; border-radius:10px; overflow:hidden; margin-bottom:24px;">
                                <tr style="background:#F9FAFB; border-bottom:1px solid #E5E7EB;">
                                    <th style="padding:10px 12px; text-align:left; font-size:11px; text-transform:uppercase; color:#6B7280; font-weight:700;">Product</th>
                                    <th style="padding:10px 12px; text-align:right; font-size:11px; text-transform:uppercase; color:#6B7280; font-weight:700;">Available</th>
                                    <th style="padding:10px 12px; text-align:right; font-size:11px; text-transform:uppercase; color:#6B7280; font-weight:700;">7d Sales</th>
                                </tr>
                                {$slowMoversHtml}
                            </table>

                            <!-- Section 3: Low Stock & Reorders -->
                            <h2 style="font-size:15px; font-weight:800; color:{$bgDark}; margin:0 0 10px 0; text-transform:uppercase; letter-spacing:0.3px;">
                                &#9888; Low Stock &amp; Reorder Suggestions
                            </h2>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid #E5E7EB; border-radius:10px; overflow:hidden; margin-bottom:24px;">
                                <tr style="background:#F9FAFB; border-bottom:1px solid #E5E7EB;">
                                    <th style="padding:10px 12px; text-align:left; font-size:11px; text-transform:uppercase; color:#6B7280; font-weight:700;">Product</th>
                                    <th style="padding:10px 12px; text-align:right; font-size:11px; text-transform:uppercase; color:#6B7280; font-weight:700;">Stock / Min</th>
                                    <th style="padding:10px 12px; text-align:right; font-size:11px; text-transform:uppercase; color:#6B7280; font-weight:700;">Suggested</th>
                                </tr>
                                {$lowStockHtml}
                            </table>

                            <!-- Actionable Tip Callout -->
                            <div style="background-color:#F0FDF4; border:1px solid #BBF7D0; border-left:4px solid {$brandGreen}; border-radius:8px; padding:16px; margin-bottom:16px;">
                                <div style="font-size:13px; font-weight:700; color:{$brandGreenDark}; margin-bottom:4px;">
                                    Weekly Action Tip
                                </div>
                                <div style="font-size:13px; color:#166534; line-height:1.5;">
                                    {$tipLine}
                                </div>
                            </div>

                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color:#F9FAFB; border-top:1px solid #E5E7EB; padding:20px 24px; text-align:center;">
                            <p style="margin:0 0 6px 0; font-size:12px; font-weight:700; color:{$bgDark};">
                                StockSense &bull; Smarter Stock. Better Business.
                            </p>
                            <p style="margin:0; font-size:11px; color:#9CA3AF;">
                                This weekly report was generated for {$shopName} based on your shop's notification preferences.
                            </p>
                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>

</body>
</html>
HTML;
    }

    /**
     * Build the clean plain-text fallback email body.
     */
    public static function buildPlainText(array $data): string {
        $shopName = $data['shop_name'];
        $dateRange = $data['date_range_label'];
        $currentSales = $data['current_sales'];
        $currentOrders = $data['current_orders'];
        $salesChange = $data['sales_change'];
        $ordersChange = $data['orders_change'];
        $openPoCount = $data['open_po_count'];
        $tipLine = $data['tip_line'];

        $salesDisplay = ($currentSales > 0.0) ? self::formatRupees($currentSales) : 'No sales this week';

        $out = [];
        $out[] = "============================================================";
        $out[] = "STOCKSENSE WEEKLY REPORT";
        $out[] = "============================================================";
        $out[] = "Shop: {$shopName}";
        $out[] = "Period: {$dateRange} (IST)";
        $out[] = "";
        $out[] = "--- 1. SALES & ORDERS (LAST 7 DAYS) ---";
        $out[] = "Total Sales: {$salesDisplay} ({$salesChange['text']})";
        $out[] = "Total Orders: {$currentOrders} orders ({$ordersChange['text']})";
        $out[] = "Open Purchase Orders: {$openPoCount} awaiting delivery";
        $out[] = "";

        $out[] = "--- 2. TOP 5 SELLERS ---";
        if (empty($data['top_sellers'])) {
            $out[] = "No sales recorded during this 7-day period.";
        } else {
            $rank = 1;
            foreach ($data['top_sellers'] as $item) {
                $sku = !empty($item['sku']) ? " [{$item['sku']}]" : "";
                $rev = self::formatRupees((float)$item['revenue']);
                $out[] = "#{$rank} {$item['name']}{$sku} - {$item['units_sold']} units, {$rev}";
                $rank++;
            }
        }
        $out[] = "";

        $out[] = "--- 3. 5 SLOWEST MOVERS ---";
        if (empty($data['slow_movers'])) {
            $out[] = "No slow-moving products currently in stock.";
        } else {
            foreach ($data['slow_movers'] as $item) {
                $sku = !empty($item['sku']) ? " [{$item['sku']}]" : "";
                $out[] = "- {$item['name']}{$sku}: {$item['units']} units in stock ({$item['units_sold']} sold in 7d)";
            }
        }
        $out[] = "";

        $out[] = "--- 4. LOW STOCK & REORDER SUGGESTIONS ---";
        if (empty($data['low_stock_suggestions'])) {
            $out[] = "All products in healthy stock. No reorders needed!";
        } else {
            foreach ($data['low_stock_suggestions'] as $item) {
                $sku = !empty($item['sku']) ? " [{$item['sku']}]" : "";
                $onOrder = $item['on_order_qty'] > 0 ? " ({$item['on_order_qty']} on order)" : "";
                $out[] = "- {$item['name']}{$sku}: {$item['units']}/min {$item['min_stock_threshold']}{$onOrder} -> Reorder +{$item['suggested_reorder']} units";
            }
        }
        $out[] = "";

        $out[] = "--- 5. WEEKLY ACTION TIP ---";
        $out[] = $tipLine;
        $out[] = "";
        $out[] = "============================================================";
        $out[] = "StockSense - Smarter Stock. Better Business.";
        $out[] = "You received this weekly report because Weekly Email Digest is enabled in your Settings.";

        return implode("\n", $out);
    }
}

