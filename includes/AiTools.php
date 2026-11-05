<?php

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../settings/settings_helper.php";
require_once __DIR__ . "/PushNotifier.php";

/**
 * Dukaan Mitra Backend Tools.
 * 
 * Strict multi-tenant tool executions for Gemini Function Calling.
 * Every query is hard-scoped to the authenticated $shopId.
 * No raw SQL, no client-provided shop_id, read-only operations.
 */
class AiTools {

    /**
     * Gemini Function Calling Declarations Schema for the 8 Tools.
     */
    public static function getFunctionDeclarations(): array {
        return [
            [
                'name' => 'get_product_stock',
                'description' => 'Get the current shelf stock count, price, minimum threshold, and stock status for a product by name or SKU.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'name_or_sku' => [
                            'type' => 'STRING',
                            'description' => 'The product name (e.g. "Amul Milk") or SKU/barcode (e.g. "DAI-001") to search for.'
                        ]
                    ],
                    'required' => ['name_or_sku']
                ]
            ],
            [
                'name' => 'list_low_stock',
                'description' => 'List all active products in the shop that are currently running low on stock (at or below minimum threshold) or completely out of stock.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => (object)[]
                ]
            ],
            [
                'name' => 'get_reorder_recommendations',
                'description' => 'Get intelligent purchase reorder recommendations calculated using sales velocity, lead time, safety buffer, and pending purchase orders.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => (object)[]
                ]
            ],
            [
                'name' => 'get_sales_summary',
                'description' => 'Get total revenue, bill count, units sold, and average bill amount for a given period.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'period' => [
                            'type' => 'STRING',
                            'description' => 'The time period to summarize: "today", "week" (last 7 days), or "month" (last 30 days).',
                            'enum' => ['today', 'week', 'month']
                        ]
                    ],
                    'required' => ['period']
                ]
            ],
            [
                'name' => 'top_selling_products',
                'description' => 'Get the highest-selling products by quantity and revenue for today, the last 7 days (week), or the last 30 days (month).',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'period' => [
                            'type' => 'STRING',
                            'description' => 'The time period: "today", "week", or "month".',
                            'enum' => ['today', 'week', 'month']
                        ]
                    ],
                    'required' => ['period']
                ]
            ],
            [
                'name' => 'get_slow_moving_products',
                'description' => 'List products with slow sales velocity and high stock cover days that tie up shop capital, along with suggested clearance actions.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => (object)[]
                ]
            ],
            [
                'name' => 'list_open_purchase_orders',
                'description' => 'List currently open / pending purchase orders that have been placed with suppliers but not yet received.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => (object)[]
                ]
            ],
            [
                'name' => 'get_product_info',
                'description' => 'Get comprehensive details for a specific product including category, selling price, purchase cost, stock velocity, and pending orders.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'name_or_sku' => [
                            'type' => 'STRING',
                            'description' => 'The product name or SKU.'
                        ]
                    ],
                    'required' => ['name_or_sku']
                ]
            ]
        ];
    }

    /**
     * Executes a tool by name with arguments strictly scoped to $shopId.
     * Returns an array containing ['result' => ..., 'cards' => [...], 'actions' => [...]].
     */
    public static function executeTool(PDO $pdo, int $shopId, string $toolName, array $args): array {
        switch ($toolName) {
            case 'get_product_stock':
                $query = trim((string)($args['name_or_sku'] ?? ''));
                return self::getProductStock($pdo, $shopId, $query);

            case 'list_low_stock':
                return self::listLowStock($pdo, $shopId);

            case 'get_reorder_recommendations':
                return self::getReorderRecommendations($pdo, $shopId);

            case 'get_sales_summary':
                $period = strtolower(trim((string)($args['period'] ?? 'today')));
                return self::getSalesSummary($pdo, $shopId, $period);

            case 'top_selling_products':
                $period = strtolower(trim((string)($args['period'] ?? 'week')));
                return self::topSellingProducts($pdo, $shopId, $period);

            case 'get_slow_moving_products':
                return self::getSlowMovingProducts($pdo, $shopId);

            case 'list_open_purchase_orders':
                return self::listOpenPurchaseOrders($pdo, $shopId);

            case 'get_product_info':
                $query = trim((string)($args['name_or_sku'] ?? ''));
                return self::getProductInfo($pdo, $shopId, $query);

            default:
                return [
                    'result' => ['error' => "Unknown tool '{$toolName}'."],
                    'cards' => [],
                    'actions' => []
                ];
        }
    }

    // -------------------------------------------------------------------------
    // Tool 1: get_product_stock
    // -------------------------------------------------------------------------
    public static function getProductStock(PDO $pdo, int $shopId, string $nameOrSku): array {
        if ($nameOrSku === '') {
            return [
                'result' => ['found' => false, 'message' => 'No product name or SKU specified.'],
                'cards' => [],
                'actions' => []
            ];
        }

        $like = '%' . $nameOrSku . '%';
        $stmt = $pdo->prepare("
            SELECT id, name, sku, category, units, min_stock_threshold, price, cost_price, status
            FROM products
            WHERE shop_owner_id = ?
              AND (sku = ? OR name LIKE ? OR sku LIKE ?)
              AND (status IS NULL OR (status NOT LIKE '%Archived%' AND status NOT LIKE '%Inactive%'))
            ORDER BY (sku = ?) DESC, (name = ?) DESC, (name LIKE ?) DESC
            LIMIT 5
        ");
        $stmt->execute([$shopId, $nameOrSku, $like, $like, $nameOrSku, $nameOrSku, $nameOrSku . '%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return [
                'result' => [
                    'found' => false,
                    'message' => "No product found matching '{$nameOrSku}' in your shop."
                ],
                'cards' => [],
                'actions' => [
                    ['label' => 'View Inventory', 'target' => 'inventory']
                ]
            ];
        }

        $items = [];
        $cards = [];

        foreach ($rows as $r) {
            $units = (int)$r['units'];
            $minStock = $r['min_stock_threshold'] !== null ? (int)$r['min_stock_threshold'] : null;

            $status = 'In stock';
            if ($units <= 0) {
                $status = 'Out of stock';
            } elseif ($minStock !== null && $units <= $minStock) {
                $status = 'Low stock';
            }

            $items[] = [
                'product_id' => (int)$r['id'],
                'name' => $r['name'],
                'sku' => $r['sku'],
                'category' => $r['category'] ?? 'General',
                'units' => $units,
                'min_stock_threshold' => $minStock,
                'selling_price' => PushNotifier::formatIndianCurrency($r['price'] ?? 0),
                'status' => $status
            ];

            $cards[] = [
                'product_id' => (int)$r['id'],
                'name' => $r['name'],
                'sku' => $r['sku'],
                'units_left' => $units,
                'avg_daily_sales' => self::getAvgDailySales($pdo, $shopId, (int)$r['id']),
                'status' => $status,
                'price' => (float)($r['price'] ?? 0),
                'min_stock' => $minStock
            ];
        }

        $actions = [];
        if (count($items) === 1) {
            $actions[] = ['label' => 'View product detail', 'target' => 'product_detail', 'product_id' => $items[0]['product_id']];
        } else {
            $actions[] = ['label' => 'View inventory', 'target' => 'inventory'];
        }

        return [
            'result' => [
                'found' => true,
                'count' => count($items),
                'products' => $items
            ],
            'cards' => $cards,
            'actions' => $actions,
            'suggestions' => [
                ['label' => 'Check low stock', 'message' => 'Which items are low on stock?']
            ]
        ];
    }

    // -------------------------------------------------------------------------
    // Tool 2: list_low_stock
    // -------------------------------------------------------------------------
    public static function listLowStock(PDO $pdo, int $shopId): array {
        $stmt = $pdo->prepare("
            SELECT id, name, sku, category, units, min_stock_threshold, price, cost_price
            FROM products
            WHERE shop_owner_id = ?
              AND min_stock_threshold IS NOT NULL
              AND units <= min_stock_threshold
              AND (status IS NULL OR (status NOT LIKE '%Archived%' AND status NOT LIKE '%Inactive%'))
            ORDER BY units ASC, name ASC
            LIMIT 25
        ");
        $stmt->execute([$shopId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        $cards = [];

        foreach ($rows as $r) {
            $units = (int)$r['units'];
            $min = (int)$r['min_stock_threshold'];
            $needed = max(0, $min - $units);
            $status = ($units <= 0) ? 'Out of stock' : 'Low stock';

            $items[] = [
                'product_id' => (int)$r['id'],
                'name' => $r['name'],
                'sku' => $r['sku'],
                'units' => $units,
                'min_stock_threshold' => $min,
                'units_needed' => $needed,
                'status' => $status
            ];

            $cards[] = [
                'product_id' => (int)$r['id'],
                'name' => $r['name'],
                'sku' => $r['sku'],
                'units_left' => $units,
                'avg_daily_sales' => self::getAvgDailySales($pdo, $shopId, (int)$r['id']),
                'status' => $status,
                'price' => (float)($r['price'] ?? 0),
                'min_stock' => $min
            ];
        }

        return [
            'result' => [
                'count' => count($items),
                'low_stock_products' => $items
            ],
            'cards' => $cards,
            'actions' => [
                ['label' => 'Open reorder list', 'target' => 'reorder'],
                ['label' => 'View inventory', 'target' => 'inventory']
            ],
            'suggestions' => [
                ['label' => 'What should I reorder?', 'message' => 'What should I reorder this week?'],
                ['label' => 'How much did I sell today?', 'message' => 'How much did I sell today?']
            ]
        ];
    }

    // -------------------------------------------------------------------------
    // Tool 3: get_reorder_recommendations
    // -------------------------------------------------------------------------
    public static function getReorderRecommendations(PDO $pdo, int $shopId): array {
        $settings = get_shop_settings($pdo, $shopId);
        $leadTime = (int)($settings['lead_time_days'] ?? 2);
        $safetyDays = (int)($settings['safety_days'] ?? 3);
        $coverDays = (int)($settings['cover_days'] ?? 7);

        // Fetch 30-day sales per product
        $stmtSales = $pdo->prepare("
            SELECT si.product_id, COALESCE(SUM(si.quantity), 0) AS sold_30
            FROM sales_items si
            JOIN sales s ON si.sale_id = s.id
            WHERE s.shop_owner_id = ? AND s.sale_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY si.product_id
        ");
        $stmtSales->execute([$shopId]);
        $salesMap = $stmtSales->fetchAll(PDO::FETCH_KEY_PAIR);

        // Fetch pending on-order units
        $stmtOrders = $pdo->prepare("
            SELECT poi.product_id, COALESCE(SUM(poi.quantity), 0) AS on_order
            FROM purchase_order_items poi
            JOIN purchase_orders po ON poi.purchase_order_id = po.id
            WHERE po.shop_owner_id = ? AND po.status = 'placed'
            GROUP BY poi.product_id
        ");
        $stmtOrders->execute([$shopId]);
        $onOrderMap = $stmtOrders->fetchAll(PDO::FETCH_KEY_PAIR);

        // Active products
        $stmtProds = $pdo->prepare("
            SELECT id, name, sku, category, units, min_stock_threshold, price, cost_price
            FROM products
            WHERE shop_owner_id = ?
              AND (status IS NULL OR (status NOT LIKE '%Archived%' AND status NOT LIKE '%Inactive%'))
            ORDER BY units ASC, name ASC
        ");
        $stmtProds->execute([$shopId]);
        $prods = $stmtProds->fetchAll(PDO::FETCH_ASSOC);

        $recommendations = [];
        $cards = [];

        foreach ($prods as $p) {
            $pid = (int)$p['id'];
            $stock = (int)$p['units'];
            $minStock = (int)($p['min_stock_threshold'] ?? 10);
            $sold30 = (int)($salesMap[$pid] ?? 0);
            $dailyVelocity = $sold30 / 30.0;
            $onOrder = (int)($onOrderMap[$pid] ?? 0);
            $costPrice = (float)($p['cost_price'] ?? $p['price'] ?? 0);

            $neededDays = $leadTime + $safetyDays;
            $daysOfStock = ($dailyVelocity > 0) ? ($stock / $dailyVelocity) : 999;

            $shouldReorder = false;
            $priority = 'MEDIUM';
            $reason = '';

            if ($stock <= 0) {
                $shouldReorder = true;
                $priority = 'HIGH';
                $reason = 'Product is completely out of stock.';
            } elseif ($stock <= $minStock) {
                $shouldReorder = true;
                $priority = 'HIGH';
                $reason = "Current stock ({$stock}) is at or below minimum threshold ({$minStock}).";
            } elseif ($daysOfStock <= $neededDays) {
                $shouldReorder = true;
                $priority = 'MEDIUM';
                $reason = sprintf("Stock will run out in %.1f days (lead time + safety is %d days).", $daysOfStock, $neededDays);
            }

            if ($shouldReorder) {
                $targetUnits = (int)ceil(($leadTime + $safetyDays + $coverDays) * max($dailyVelocity, 1.0));
                $suggestedQty = max($minStock, $targetUnits) - $stock - $onOrder;

                if ($suggestedQty > 0) {
                    $estCost = $suggestedQty * $costPrice;

                    $recommendations[] = [
                        'product_id' => $pid,
                        'name' => $p['name'],
                        'sku' => $p['sku'],
                        'current_stock' => $stock,
                        'suggested_quantity' => $suggestedQty,
                        'estimated_cost' => PushNotifier::formatIndianCurrency($estCost),
                        'priority' => $priority,
                        'reason' => $reason
                    ];

                    $cards[] = [
                        'product_id' => $pid,
                        'name' => $p['name'],
                        'sku' => $p['sku'],
                        'units_left' => $stock,
                        'avg_daily_sales' => round($dailyVelocity, 1),
                        'status' => ($stock <= 0) ? 'Out of stock' : 'Low stock',
                        'price' => (float)($p['price'] ?? 0),
                        'min_stock' => $minStock
                    ];
                }
            }
        }

        // Limit to top 15 recommendations
        $recommendations = array_slice($recommendations, 0, 15);
        $cards = array_slice($cards, 0, 10);

        return [
            'result' => [
                'count' => count($recommendations),
                'lead_time_days' => $leadTime,
                'safety_days' => $safetyDays,
                'recommendations' => $recommendations
            ],
            'cards' => $cards,
            'actions' => [
                ['label' => 'Open reorder list', 'target' => 'reorder'],
                ['label' => 'View purchase orders', 'target' => 'purchase_orders']
            ],
            'suggestions' => [
                ['label' => 'Check low stock', 'message' => 'Which items are low on stock?']
            ]
        ];
    }

    // -------------------------------------------------------------------------
    // Tool 4: get_sales_summary
    // -------------------------------------------------------------------------
    public static function getSalesSummary(PDO $pdo, int $shopId, string $period): array {
        $dateCondition = "";
        $params = [$shopId];

        switch ($period) {
            case 'today':
                $dateCondition = "DATE(sale_date) = CURDATE()";
                $periodLabel = "Today (" . date('d M Y') . ")";
                break;
            case 'week':
                $dateCondition = "sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND sale_date <= NOW()";
                $periodLabel = "Last 7 Days";
                break;
            case 'month':
            default:
                $period = 'month';
                $dateCondition = "sale_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND sale_date <= NOW()";
                $periodLabel = "Last 30 Days";
                break;
        }

        $stmtSales = $pdo->prepare("
            SELECT 
                COALESCE(SUM(total_amount), 0) AS total_revenue,
                COUNT(*) AS total_bills
            FROM sales
            WHERE shop_owner_id = ? AND {$dateCondition}
        ");
        $stmtSales->execute($params);
        $salesRow = $stmtSales->fetch(PDO::FETCH_ASSOC);

        $totalRevenue = (float)($salesRow['total_revenue'] ?? 0);
        $totalBills = (int)($salesRow['total_bills'] ?? 0);

        $stmtUnits = $pdo->prepare("
            SELECT COALESCE(SUM(si.quantity), 0) AS total_units
            FROM sales_items si
            JOIN sales s ON si.sale_id = s.id
            WHERE s.shop_owner_id = ? AND {$dateCondition}
        ");
        $stmtUnits->execute($params);
        $totalUnits = (int)$stmtUnits->fetchColumn();

        $avgBill = $totalBills > 0 ? round($totalRevenue / $totalBills, 2) : 0.0;

        return [
            'result' => [
                'period' => $period,
                'period_label' => $periodLabel,
                'total_revenue' => PushNotifier::formatIndianCurrency($totalRevenue),
                'total_revenue_raw' => $totalRevenue,
                'total_bills' => $totalBills,
                'total_units_sold' => $totalUnits,
                'average_bill_amount' => PushNotifier::formatIndianCurrency($avgBill)
            ],
            'cards' => [],
            'actions' => [
                ['label' => 'View sales report', 'target' => 'sales']
            ],
            'suggestions' => [
                ['label' => 'Check low stock', 'message' => 'Which items are low on stock?'],
                ['label' => 'Top sellers', 'message' => 'What are my top selling products?']
            ]
        ];
    }

    // -------------------------------------------------------------------------
    // Tool 5: top_selling_products
    // -------------------------------------------------------------------------
    public static function topSellingProducts(PDO $pdo, int $shopId, string $period): array {
        switch ($period) {
            case 'today':
                $dateCondition = "DATE(s.sale_date) = CURDATE()";
                break;
            case 'month':
                $dateCondition = "s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND s.sale_date <= NOW()";
                break;
            case 'week':
            default:
                $period = 'week';
                $dateCondition = "s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND s.sale_date <= NOW()";
                break;
        }

        $stmt = $pdo->prepare("
            SELECT 
                p.id AS product_id,
                p.name,
                p.sku,
                p.units AS current_stock,
                p.price,
                p.min_stock_threshold,
                COALESCE(SUM(si.quantity), 0) AS units_sold,
                COALESCE(SUM(si.line_total), 0) AS revenue
            FROM sales_items si
            JOIN sales s ON si.sale_id = s.id
            JOIN products p ON si.product_id = p.id
            WHERE s.shop_owner_id = ? AND {$dateCondition}
            GROUP BY p.id
            ORDER BY units_sold DESC, revenue DESC
            LIMIT 5
        ");
        $stmt->execute([$shopId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        $cards = [];

        foreach ($rows as $r) {
            $items[] = [
                'product_id' => (int)$r['product_id'],
                'name' => $r['name'],
                'sku' => $r['sku'],
                'units_sold' => (int)$r['units_sold'],
                'revenue' => PushNotifier::formatIndianCurrency($r['revenue']),
                'current_stock' => (int)$r['current_stock']
            ];

            $cards[] = [
                'product_id' => (int)$r['product_id'],
                'name' => $r['name'],
                'sku' => $r['sku'],
                'units_left' => (int)$r['current_stock'],
                'avg_daily_sales' => round(((int)$r['units_sold']) / 7.0, 1),
                'status' => 'Top seller',
                'price' => (float)$r['price'],
                'min_stock' => (int)($r['min_stock_threshold'] ?? 10)
            ];
        }

        return [
            'result' => [
                'period' => $period,
                'count' => count($items),
                'top_products' => $items,
                'products' => $items
            ],
            'cards' => $cards,
            'actions' => [
                ['label' => 'View sales report', 'target' => 'sales']
            ],
            'suggestions' => [
                ['label' => 'Check low stock', 'message' => 'Which items are low on stock?']
            ]
        ];
    }

    // -------------------------------------------------------------------------
    // Tool 6: get_slow_moving_products
    // -------------------------------------------------------------------------
    public static function getSlowMovingProducts(PDO $pdo, int $shopId): array {
        $settings = get_shop_settings($pdo, $shopId);
        $thresholdDays = (int)($settings['slow_moving_threshold_days'] ?? 60);

        // Fetch 30-day sales
        $stmtSales = $pdo->prepare("
            SELECT si.product_id, COALESCE(SUM(si.quantity), 0) AS sold_30
            FROM sales_items si
            JOIN sales s ON si.sale_id = s.id
            WHERE s.shop_owner_id = ? AND s.sale_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY si.product_id
        ");
        $stmtSales->execute([$shopId]);
        $salesMap = $stmtSales->fetchAll(PDO::FETCH_KEY_PAIR);

        $stmtProds = $pdo->prepare("
            SELECT id, name, sku, category, units, min_stock_threshold, price, cost_price
            FROM products
            WHERE shop_owner_id = ?
              AND units > 0
              AND (status IS NULL OR (status NOT LIKE '%Archived%' AND status NOT LIKE '%Inactive%'))
            ORDER BY units DESC
        ");
        $stmtProds->execute([$shopId]);
        $prods = $stmtProds->fetchAll(PDO::FETCH_ASSOC);

        $slowItems = [];
        $cards = [];

        foreach ($prods as $p) {
            $pid = (int)$p['id'];
            $stock = (int)$p['units'];
            $sold30 = (int)($salesMap[$pid] ?? 0);
            $dailyVelocity = $sold30 / 30.0;
            $coverDays = ($dailyVelocity > 0) ? (int)round($stock / $dailyVelocity) : 999;

            if ($coverDays >= $thresholdDays) {
                $cost = (float)($p['cost_price'] ?? ($p['price'] * 0.7));
                $tiedUp = $stock * $cost;

                $slowItems[] = [
                    'product_id' => $pid,
                    'name' => $p['name'],
                    'sku' => $p['sku'],
                    'units_in_stock' => $stock,
                    'units_sold_30_days' => $sold30,
                    'stock_cover_days' => $coverDays,
                    'tied_up_capital' => PushNotifier::formatIndianCurrency($tiedUp),
                    'suggested_action' => 'Offer a 10% discount or bundle with a complementary item to free up capital.'
                ];

                $cards[] = [
                    'product_id' => $pid,
                    'name' => $p['name'],
                    'sku' => $p['sku'],
                    'units_left' => $stock,
                    'avg_daily_sales' => round($dailyVelocity, 2),
                    'status' => 'Slow moving',
                    'price' => (float)($p['price'] ?? 0),
                    'min_stock' => (int)($p['min_stock_threshold'] ?? 10)
                ];
            }
        }

        $slowItems = array_slice($slowItems, 0, 10);
        $cards = array_slice($cards, 0, 5);

        return [
            'result' => [
                'count' => count($slowItems),
                'threshold_days' => $thresholdDays,
                'slow_moving_products' => $slowItems
            ],
            'cards' => $cards,
            'actions' => [
                ['label' => 'View inventory', 'target' => 'inventory']
            ],
            'suggestions' => [
                ['label' => 'How to reduce dead stock?', 'message' => 'How can I reduce dead stock?']
            ]
        ];
    }

    // -------------------------------------------------------------------------
    // Tool 7: list_open_purchase_orders
    // -------------------------------------------------------------------------
    public static function listOpenPurchaseOrders(PDO $pdo, int $shopId): array {
        $stmt = $pdo->prepare("
            SELECT po.id, po.order_no, po.status, po.created_at,
                   COALESCE(SUM(poi.quantity), 0) AS total_units,
                   COALESCE(SUM(poi.line_estimated_cost), 0) AS estimated_total
            FROM purchase_orders po
            LEFT JOIN purchase_order_items poi ON po.id = poi.purchase_order_id
            WHERE po.shop_owner_id = ? AND po.status = 'placed'
            GROUP BY po.id
            ORDER BY po.id DESC
            LIMIT 10
        ");
        $stmt->execute([$shopId]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($orders as $o) {
            $poid = (int)$o['id'];
            $stmtItems = $pdo->prepare("SELECT product_name, quantity FROM purchase_order_items WHERE purchase_order_id = ? LIMIT 3");
            $stmtItems->execute([$poid]);
            $itemsList = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            $summaryParts = [];
            foreach ($itemsList as $it) {
                $summaryParts[] = "{$it['product_name']} ({$it['quantity']} units)";
            }
            $summary = implode(', ', $summaryParts);

            $results[] = [
                'order_id' => $poid,
                'order_no' => $o['order_no'],
                'status' => 'PLACED',
                'placed_at' => $o['created_at'],
                'total_units' => (int)$o['total_units'],
                'estimated_total' => PushNotifier::formatIndianCurrency($o['estimated_total']),
                'items_summary' => $summary ?: 'No items'
            ];
        }

        return [
            'result' => [
                'count' => count($results),
                'open_orders_count' => count($results),
                'orders' => $results
            ],
            'cards' => [],
            'actions' => [
                ['label' => 'Open purchase orders', 'target' => 'purchase_orders']
            ],
            'suggestions' => [
                ['label' => 'What to reorder?', 'message' => 'What should I reorder this week?']
            ]
        ];
    }

    // -------------------------------------------------------------------------
    // Tool 8: get_product_info
    // -------------------------------------------------------------------------
    public static function getProductInfo(PDO $pdo, int $shopId, string $nameOrSku): array {
        if ($nameOrSku === '') {
            return [
                'result' => ['found' => false, 'message' => 'No product name or SKU specified.'],
                'cards' => [],
                'actions' => []
            ];
        }

        $like = '%' . $nameOrSku . '%';
        $stmt = $pdo->prepare("
            SELECT id, name, sku, category, units, min_stock_threshold, price, cost_price, status
            FROM products
            WHERE shop_owner_id = ?
              AND (sku = ? OR name LIKE ? OR sku LIKE ?)
              AND (status IS NULL OR (status NOT LIKE '%Archived%' AND status NOT LIKE '%Inactive%'))
            ORDER BY (sku = ?) DESC, (name = ?) DESC
            LIMIT 1
        ");
        $stmt->execute([$shopId, $nameOrSku, $like, $like, $nameOrSku, $nameOrSku]);
        $prod = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$prod) {
            return [
                'result' => ['found' => false, 'message' => "No product found matching '{$nameOrSku}'."],
                'cards' => [],
                'actions' => [['label' => 'View Inventory', 'target' => 'inventory']]
            ];
        }

        $pid = (int)$prod['id'];
        $units = (int)$prod['units'];
        $min = (int)($prod['min_stock_threshold'] ?? 10);

        // 30-day sales
        $stmtSales = $pdo->prepare("
            SELECT COALESCE(SUM(quantity), 0)
            FROM sales_items si
            JOIN sales s ON si.sale_id = s.id
            WHERE s.shop_owner_id = ? AND si.product_id = ? AND s.sale_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmtSales->execute([$shopId, $pid]);
        $sold30 = (int)$stmtSales->fetchColumn();
        $dailyVelocity = $sold30 / 30.0;

        // Pending on-order units
        $stmtOrder = $pdo->prepare("
            SELECT COALESCE(SUM(poi.quantity), 0)
            FROM purchase_order_items poi
            JOIN purchase_orders po ON poi.purchase_order_id = po.id
            WHERE po.shop_owner_id = ? AND po.status = 'placed' AND poi.product_id = ?
        ");
        $stmtOrder->execute([$shopId, $pid]);
        $onOrder = (int)$stmtOrder->fetchColumn();

        $status = 'In stock';
        if ($units <= 0) {
            $status = 'Out of stock';
        } elseif ($units <= $min) {
            $status = 'Low stock';
        }

        $daysOfStock = ($dailyVelocity > 0) ? (int)round($units / $dailyVelocity) : 999;

        $info = [
            'product_id' => $pid,
            'name' => $prod['name'],
            'sku' => $prod['sku'],
            'category' => $prod['category'] ?? 'General',
            'current_stock' => $units,
            'min_stock_threshold' => $min,
            'status' => $status,
            'selling_price' => PushNotifier::formatIndianCurrency($prod['price'] ?? 0),
            'cost_price' => PushNotifier::formatIndianCurrency($prod['cost_price'] ?? 0),
            'units_sold_last_30_days' => $sold30,
            'avg_daily_sales' => round($dailyVelocity, 2),
            'days_of_stock_left' => ($daysOfStock === 999) ? 'No recent sales' : "{$daysOfStock} days",
            'units_on_order' => $onOrder
        ];

        $card = [
            'product_id' => $pid,
            'name' => $prod['name'],
            'sku' => $prod['sku'],
            'units_left' => $units,
            'avg_daily_sales' => round($dailyVelocity, 1),
            'status' => $status,
            'price' => (float)($prod['price'] ?? 0),
            'min_stock' => $min
        ];

        return [
            'result' => [
                'found' => true,
                'product' => $info
            ],
            'cards' => [$card],
            'actions' => [
                ['label' => 'View product detail', 'target' => 'product_detail', 'product_id' => $pid]
            ],
            'suggestions' => [
                ['label' => 'Check low stock', 'message' => 'Which items are low on stock?']
            ]
        ];
    }

    public static function getAvgDailySales(PDO $pdo, int $shopId, int $productId): float {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(si.quantity), 0)
            FROM sales_items si
            JOIN sales s ON si.sale_id = s.id
            WHERE s.shop_owner_id = ? AND si.product_id = ? AND s.sale_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([$shopId, $productId]);
        $sold = (int)$stmt->fetchColumn();
        return round($sold / 30.0, 1);
    }
}

