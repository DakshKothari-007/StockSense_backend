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
     * Gemini & Ollama Function Calling Declarations Schema for the 8 Tools.
     * When $message is provided, filters to only tools relevant to the inquiry
     * to reduce prompt token count and grammar evaluation overhead.
     */
    public static function getFunctionDeclarations(?string $message = null): array {
        $all = self::getAllFunctionDeclarations();
        if ($message === null || trim($message) === '') {
            return $all;
        }

        $lower = mb_strtolower(trim($message), 'UTF-8');

        // Check query category for selective tool provisioning
        $isProfileQuery = preg_match('/\b(shop\s+name|store\s+name|owner|registered\s+email|shop\s+details|profile|location|currency|timezone|settings|alert\s+setting|safety\s+days|cover\s+days|lead\s+time|import\s+mode|दुकान|मालिक|ईमेल|सेटिंग्स|பெயர்|உரிமையாளர்|முகவரி|அமைப்புகள்)\b/iu', $lower);
        $isProductQuery = preg_match('/\b(product|sku|item|stock\s+of|price\s+of|cost\s+of|details?\s+of|margin|profit|do\s+we\s+have|how\s+much\s+[a-z0-9_\-\s]+(in\s+stock|left|do\s+i\s+have)|alpha|beta|milk|bread|tea|biscuit|soap|oil|atta|marie|parle)\b/iu', $lower);
        $isSalesQuery = preg_match('/\b(sale|sales|revenue|income|collection|sold|selling|top\s+seller|best\s+seller|lowest\s+sales|least\s+sold|yesterday|best\s+day|बिक्री|कमाई|விற்பனை|நேற்று)\b/iu', $lower);
        $isOrderQuery = preg_match('/\b(reorder|restock|supplier|vendor|purchase\s+order|open\s+order|pending\s+order|po-\d+|delivery|ऑर्डर|ஆர்டர்)\b/iu', $lower);
        $isSlowQuery = preg_match('/\b(slow|dead\s+stock|unsold|idle|not\s+selling|धीमा|விற்காத)\b/iu', $lower);
        $isImportQuery = preg_match('/\b(import|excel|csv|upload|history|इंपोर्ट|பதிவேற்றம்)\b/iu', $lower);
        $isCategoryQuery = preg_match('/\b(category|categories|dairy|staples|snacks|beverages|total\s+inventory|all\s+items|out\s+of\s+stock|खत्म|முடிந்துவிட்டது)\b/iu', $lower);

        $selectedNames = [];
        if ($isProfileQuery) {
            $selectedNames[] = 'get_shop_profile';
            $selectedNames[] = 'get_shop_settings';
        }
        if ($isProductQuery) {
            $selectedNames[] = 'get_product_stock';
            $selectedNames[] = 'get_product_info';
            $selectedNames[] = 'get_product_price_and_margin';
        }
        if ($isSalesQuery) {
            $selectedNames[] = 'get_sales_summary';
            $selectedNames[] = 'top_selling_products';
            $selectedNames[] = 'lowest_selling_products';
        }
        if ($isOrderQuery) {
            $selectedNames[] = 'get_reorder_recommendations';
            $selectedNames[] = 'list_open_purchase_orders';
            $selectedNames[] = 'get_purchase_order_status';
        }
        if ($isSlowQuery) {
            $selectedNames[] = 'get_slow_moving_products';
            $selectedNames[] = 'get_dead_stock';
            $selectedNames[] = 'list_low_stock';
        }
        if ($isImportQuery) {
            $selectedNames[] = 'get_import_history';
        }
        if ($isCategoryQuery) {
            $selectedNames[] = 'get_category_stock_totals';
            $selectedNames[] = 'get_out_of_stock_products';
            $selectedNames[] = 'get_total_inventory_count';
            $selectedNames[] = 'list_low_stock';
        }

        if (!empty($selectedNames)) {
            $filtered = [];
            foreach ($all as $tool) {
                if (in_array($tool['name'], $selectedNames, true)) {
                    $filtered[] = $tool;
                }
            }
            return $filtered;
        }

        // For advice, retail tips, display, ideas or app usage without product context, omit tools to speed up generation
        if (preg_match('/\b(how\s+to\s+price|how\s+should\s+i\s+price|display|shelf|merchandis|advice|tip|idea|cash\s+flow|diwali|festival)\b/i', $lower) && !preg_match('/\b(on|for|of)\s+[a-z0-9]/i', $lower)) {
            return [];
        }

        return $all;
    }

    /**
     * Full declarations schema for all shop data tools with concise descriptions.
     */
    public static function getAllFunctionDeclarations(): array {
        return [
            [
                'name' => 'get_product_stock',
                'description' => 'Get current shelf stock count, price, and status for a product by name or SKU.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'name_or_sku' => [
                            'type' => 'STRING',
                            'description' => 'Product name or SKU/barcode.'
                        ]
                    ],
                    'required' => ['name_or_sku']
                ]
            ],
            [
                'name' => 'list_low_stock',
                'description' => 'List products currently running low on stock or out of stock.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => (object)[]
                ]
            ],
            [
                'name' => 'get_reorder_recommendations',
                'description' => 'Get intelligent purchase reorder recommendations based on sales velocity and lead times.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => (object)[]
                ]
            ],
            [
                'name' => 'get_sales_summary',
                'description' => 'Get total revenue, bill count, and units sold for today, yesterday, week, month, best_day, or specific date.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'period' => [
                            'type' => 'STRING',
                            'description' => 'Period: "today", "yesterday", "week", "month", "best_day", or date "YYYY-MM-DD".'
                        ],
                        'date' => [
                            'type' => 'STRING',
                            'description' => 'Specific date in YYYY-MM-DD format (optional).'
                        ],
                        'category' => [
                            'type' => 'STRING',
                            'description' => 'Optional product category name filter.'
                        ]
                    ],
                    'required' => ['period']
                ]
            ],
            [
                'name' => 'top_selling_products',
                'description' => 'Get highest-selling products by quantity and revenue for today, week, or month.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'period' => [
                            'type' => 'STRING',
                            'description' => 'Period: "today", "week", or "month".',
                            'enum' => ['today', 'week', 'month']
                        ]
                    ],
                    'required' => ['period']
                ]
            ],
            [
                'name' => 'get_slow_moving_products',
                'description' => 'List products with slow sales velocity and high stock cover days.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => (object)[]
                ]
            ],
            [
                'name' => 'list_open_purchase_orders',
                'description' => 'List pending supplier purchase orders awaiting delivery.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => (object)[]
                ]
            ],
            [
                'name' => 'get_product_info',
                'description' => 'Get comprehensive details for a product including category, price, cost, and stock.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'name_or_sku' => [
                            'type' => 'STRING',
                            'description' => 'Product name or SKU.'
                        ]
                    ],
                    'required' => ['name_or_sku']
                ]
            ],
            [
                'name' => 'get_shop_profile',
                'description' => 'Get shop name, owner name, location, store type, currency, and registered email.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => (object)[]
                ]
            ],
            [
                'name' => 'get_shop_settings',
                'description' => 'Get shop settings including low stock alerts, safety stock days, cover days, and timezone.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => (object)[]
                ]
            ],
            [
                'name' => 'get_product_price_and_margin',
                'description' => 'Get selling price, cost price, profit margin amount, and profit percentage for a product.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'name_or_sku' => [
                            'type' => 'STRING',
                            'description' => 'Product name or SKU.'
                        ]
                    ],
                    'required' => ['name_or_sku']
                ]
            ],
            [
                'name' => 'get_category_stock_totals',
                'description' => 'Get total unit counts, product counts, and stock value grouped by category or for a specific category.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'category' => [
                            'type' => 'STRING',
                            'description' => 'Optional category name (e.g. Dairy, Snacks, Staples).'
                        ]
                    ]
                ]
            ],
            [
                'name' => 'lowest_selling_products',
                'description' => 'Get active products with the lowest units sold or zero sales in the given period.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'period' => [
                            'type' => 'STRING',
                            'description' => 'Period: "today", "week", or "month".'
                        ]
                    ]
                ]
            ],
            [
                'name' => 'get_dead_stock',
                'description' => 'Get products with positive stock that have had zero sales over a threshold period (default 60 days).',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'days' => [
                            'type' => 'INTEGER',
                            'description' => 'Number of days with zero sales (default 60).'
                        ]
                    ]
                ]
            ],
            [
                'name' => 'get_purchase_order_status',
                'description' => 'Get status and details of purchase orders, optionally filtered by order number or status.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'order_no' => [
                            'type' => 'STRING',
                            'description' => 'Optional purchase order number (e.g. PO-2026-001).'
                        ],
                        'status' => [
                            'type' => 'STRING',
                            'description' => 'Optional status: "placed", "received", "cancelled".'
                        ]
                    ]
                ]
            ],
            [
                'name' => 'get_import_history',
                'description' => 'Get history and status of recent product catalog imports from Excel or CSV.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => (object)[]
                ]
            ],
            [
                'name' => 'get_out_of_stock_products',
                'description' => 'List all products that currently have 0 units in stock.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => (object)[]
                ]
            ],
            [
                'name' => 'get_total_inventory_count',
                'description' => 'Get aggregate totals across all inventory: total products, total units, out of stock, and inventory value.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => (object)[]
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
                $date = isset($args['date']) ? trim((string)$args['date']) : null;
                $cat = isset($args['category']) ? trim((string)$args['category']) : null;
                return self::getSalesSummary($pdo, $shopId, $period, $date, $cat);

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

            case 'get_shop_profile':
                return self::getShopProfile($pdo, $shopId);

            case 'get_shop_settings':
                return self::getShopSettings($pdo, $shopId);

            case 'get_product_price_and_margin':
                $query = trim((string)($args['name_or_sku'] ?? ''));
                return self::getProductPriceAndMargin($pdo, $shopId, $query);

            case 'get_category_stock_totals':
                $cat = isset($args['category']) ? trim((string)$args['category']) : null;
                return self::getCategoryStockTotals($pdo, $shopId, $cat);

            case 'lowest_selling_products':
                $period = strtolower(trim((string)($args['period'] ?? 'month')));
                return self::lowestSellingProducts($pdo, $shopId, $period);

            case 'get_dead_stock':
                $days = isset($args['days']) ? (int)$args['days'] : 60;
                return self::getDeadStock($pdo, $shopId, $days);

            case 'get_purchase_order_status':
                $orderNo = isset($args['order_no']) ? trim((string)$args['order_no']) : null;
                $status = isset($args['status']) ? trim((string)$args['status']) : null;
                return self::getPurchaseOrderStatus($pdo, $shopId, $orderNo, $status);

            case 'get_import_history':
                return self::getImportHistory($pdo, $shopId);

            case 'get_out_of_stock_products':
                return self::getOutOfStockProducts($pdo, $shopId);

            case 'get_total_inventory_count':
                return self::getTotalInventoryCount($pdo, $shopId);

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
    public static function getSalesSummary(PDO $pdo, int $shopId, string $period, ?string $date = null, ?string $category = null): array {
        $dateCondition = "";
        $params = [$shopId];

        if ($period === 'yesterday') {
            $dateCondition = "DATE(sale_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
            $periodLabel = "Yesterday (" . date('d M Y', strtotime('-1 day')) . ")";
        } elseif ($period === 'best_day') {
            $stmtBest = $pdo->prepare("
                SELECT DATE(sale_date) AS bdate, SUM(total_amount) AS rev, COUNT(*) AS bcount
                FROM sales
                WHERE shop_owner_id = ? AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                GROUP BY DATE(sale_date)
                ORDER BY rev DESC
                LIMIT 1
            ");
            $stmtBest->execute([$shopId]);
            $best = $stmtBest->fetch(PDO::FETCH_ASSOC);
            $bestDate = $best['bdate'] ?? date('Y-m-d');
            $dateCondition = "DATE(sale_date) = '{$bestDate}'";
            $periodLabel = "Best Sales Day (" . date('d M Y', strtotime($bestDate)) . ")";
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $period) || ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))) {
            $theDate = ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) ? $date : $period;
            $dateCondition = "DATE(sale_date) = ?";
            $params[] = $theDate;
            $periodLabel = "Sales on " . date('d M Y', strtotime($theDate));
        } elseif ($period === 'today') {
            $dateCondition = "DATE(sale_date) = CURDATE()";
            $periodLabel = "Today (" . date('d M Y') . ")";
        } elseif ($period === 'week') {
            $dateCondition = "sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND sale_date <= NOW()";
            $periodLabel = "Last 7 Days";
        } else {
            $period = 'month';
            $dateCondition = "sale_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND sale_date <= NOW()";
            $periodLabel = "Last 30 Days";
        }

        if ($category !== null && trim($category) !== '') {
            $catFilter = trim($category);
            $stmtSales = $pdo->prepare("
                SELECT 
                    COALESCE(SUM(si.line_total), 0) AS total_revenue,
                    COUNT(DISTINCT s.id) AS total_bills,
                    COALESCE(SUM(si.quantity), 0) AS total_units
                FROM sales s
                JOIN sales_items si ON si.sale_id = s.id
                JOIN products p ON si.product_id = p.id
                WHERE s.shop_owner_id = ? AND {$dateCondition} AND p.category LIKE ?
            ");
            $catParams = array_merge($params, ['%' . $catFilter . '%']);
            $stmtSales->execute($catParams);
            $salesRow = $stmtSales->fetch(PDO::FETCH_ASSOC);

            $totalRevenue = (float)($salesRow['total_revenue'] ?? 0);
            $totalBills = (int)($salesRow['total_bills'] ?? 0);
            $totalUnits = (int)($salesRow['total_units'] ?? 0);
            $avgBill = $totalBills > 0 ? round($totalRevenue / $totalBills, 2) : 0.0;
            $periodLabel .= " - {$catFilter}";
        } else {
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
        }

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
            $stmtItems = $pdo->prepare("
                SELECT p.name AS product_name, poi.quantity 
                FROM purchase_order_items poi 
                JOIN products p ON poi.product_id = p.id 
                WHERE poi.purchase_order_id = ? 
                LIMIT 3
            ");
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

    // -------------------------------------------------------------------------
    // Tool 9: get_shop_profile
    // -------------------------------------------------------------------------
    public static function getShopProfile(PDO $pdo, int $shopId): array {
        $stmt = $pdo->prepare("
            SELECT id, full_name, shop_name, location, store_type, currency, email, created_at
            FROM owner_account
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$shopId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'result' => ['found' => false, 'message' => 'Shop profile not found.'],
                'cards' => [],
                'actions' => [],
                'suggestions' => []
            ];
        }

        return [
            'result' => [
                'found' => true,
                'shop_name' => $row['shop_name'],
                'owner_name' => $row['full_name'],
                'location' => $row['location'],
                'store_type' => $row['store_type'],
                'currency' => $row['currency'],
                'email' => $row['email'],
                'registered_on' => date('d M Y', strtotime($row['created_at']))
            ],
            'cards' => [],
            'actions' => [
                ['label' => 'Edit profile', 'target' => 'profile']
            ],
            'suggestions' => [
                ['label' => 'Store settings', 'message' => 'What are my store settings?'],
                ['label' => 'Check low stock', 'message' => 'Which items are low on stock?']
            ]
        ];
    }

    // -------------------------------------------------------------------------
    // Tool 10: get_shop_settings
    // -------------------------------------------------------------------------
    public static function getShopSettings(PDO $pdo, int $shopId): array {
        $stmt = $pdo->prepare("
            SELECT s.*, o.currency, o.location
            FROM user_settings s
            JOIN owner_account o ON o.id = s.user_id
            WHERE s.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$shopId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'result' => ['found' => false, 'message' => 'Settings not found.'],
                'cards' => [],
                'actions' => [],
                'suggestions' => []
            ];
        }

        return [
            'result' => [
                'found' => true,
                'low_stock_alerts' => (int)($row['low_stock_alerts'] ?? 1) === 1 ? 'Enabled' : 'Disabled',
                'ai_recommendations' => (int)($row['ai_recommendations'] ?? 1) === 1 ? 'Enabled' : 'Disabled',
                'daily_sales_summary' => (int)($row['daily_sales_summary'] ?? 1) === 1 ? 'Enabled' : 'Disabled',
                'lead_time_days' => (int)($row['lead_time_days'] ?? 2),
                'safety_days' => (int)($row['safety_days'] ?? 3),
                'cover_days' => (int)($row['cover_days'] ?? 7),
                'slow_moving_threshold_days' => (int)($row['slow_moving_threshold_days'] ?? 60),
                'import_mode' => $row['import_mode'] ?? 'ADD',
                'timezone' => $row['timezone'] ?? 'Asia/Kolkata',
                'currency' => $row['currency'] ?? '₹ INR / IST'
            ],
            'cards' => [],
            'actions' => [
                ['label' => 'Open settings', 'target' => 'settings']
            ],
            'suggestions' => [
                ['label' => 'Shop details', 'message' => 'Show my shop profile details'],
                ['label' => 'Check low stock', 'message' => 'Which items are low on stock?']
            ]
        ];
    }

    // -------------------------------------------------------------------------
    // Tool 11: get_product_price_and_margin
    // -------------------------------------------------------------------------
    public static function getProductPriceAndMargin(PDO $pdo, int $shopId, string $nameOrSku): array {
        $nameOrSku = trim($nameOrSku);
        if ($nameOrSku === '') {
            return [
                'result' => ['found' => false, 'message' => 'No product specified.'],
                'cards' => [],
                'actions' => []
            ];
        }

        $like = '%' . $nameOrSku . '%';
        $stmt = $pdo->prepare("
            SELECT id, name, sku, category, units, price, cost_price, min_stock_threshold
            FROM products
            WHERE shop_owner_id = ?
              AND (sku = ? OR name LIKE ? OR sku LIKE ?)
              AND (status IS NULL OR (status NOT LIKE '%Archived%' AND status NOT LIKE '%Inactive%'))
            ORDER BY (sku = ?) DESC, (name = ?) DESC
            LIMIT 1
        ");
        $stmt->execute([$shopId, $nameOrSku, $like, $like, $nameOrSku, $nameOrSku]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'result' => ['found' => false, 'message' => "Product '{$nameOrSku}' not found."],
                'cards' => [],
                'actions' => [['label' => 'View Inventory', 'target' => 'inventory']]
            ];
        }

        $selling = (float)($row['price'] ?? 0);
        $cost = (float)($row['cost_price'] ?? 0);
        $margin = $selling - $cost;
        $marginPct = $selling > 0 ? round(($margin / $selling) * 100, 1) : 0.0;
        $units = (int)$row['units'];
        $min = (int)($row['min_stock_threshold'] ?? 10);
        $status = ($units <= 0) ? 'Out of stock' : (($units <= $min) ? 'Low stock' : 'In stock');

        return [
            'result' => [
                'found' => true,
                'product_id' => (int)$row['id'],
                'name' => $row['name'],
                'sku' => $row['sku'],
                'category' => $row['category'] ?? 'General',
                'selling_price' => PushNotifier::formatIndianCurrency($selling),
                'selling_price_raw' => $selling,
                'cost_price' => PushNotifier::formatIndianCurrency($cost),
                'cost_price_raw' => $cost,
                'profit_margin' => PushNotifier::formatIndianCurrency($margin),
                'profit_margin_raw' => $margin,
                'margin_percentage' => $marginPct,
                'current_stock' => $units,
                'status' => $status
            ],
            'cards' => [
                [
                    'product_id' => (int)$row['id'],
                    'name' => $row['name'],
                    'sku' => $row['sku'],
                    'units_left' => $units,
                    'status' => $status,
                    'price' => $selling,
                    'min_stock' => $min
                ]
            ],
            'actions' => [
                ['label' => 'View product detail', 'target' => 'product_detail', 'product_id' => (int)$row['id']]
            ],
            'suggestions' => [
                ['label' => 'Check low stock', 'message' => 'Which items are low on stock?'],
                ['label' => 'Top sellers', 'message' => 'What are my top selling products?']
            ]
        ];
    }

    // -------------------------------------------------------------------------
    // Tool 12: get_category_stock_totals
    // -------------------------------------------------------------------------
    public static function getCategoryStockTotals(PDO $pdo, int $shopId, ?string $category = null): array {
        $where = "shop_owner_id = ? AND (status IS NULL OR (status NOT LIKE '%Archived%' AND status NOT LIKE '%Inactive%'))";
        $params = [$shopId];

        if ($category !== null && trim($category) !== '') {
            $where .= " AND category LIKE ?";
            $params[] = '%' . trim($category) . '%';
        }

        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(category, 'General') AS category_name,
                COUNT(*) AS product_count,
                COALESCE(SUM(units), 0) AS total_units,
                COALESCE(SUM(units * price), 0) AS total_retail_value,
                COALESCE(SUM(units * COALESCE(cost_price, price)), 0) AS total_cost_value
            FROM products
            WHERE {$where}
            GROUP BY COALESCE(category, 'General')
            ORDER BY total_units DESC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $categories = [];
        $totalItemsAll = 0;
        $totalUnitsAll = 0;
        $totalRetailVal = 0.0;

        foreach ($rows as $r) {
            $count = (int)$r['product_count'];
            $units = (int)$r['total_units'];
            $retail = (float)$r['total_retail_value'];
            $cost = (float)$r['total_cost_value'];

            $totalItemsAll += $count;
            $totalUnitsAll += $units;
            $totalRetailVal += $retail;

            $categories[] = [
                'category' => $r['category_name'],
                'product_count' => $count,
                'total_units' => $units,
                'total_retail_value' => PushNotifier::formatIndianCurrency($retail),
                'total_cost_value' => PushNotifier::formatIndianCurrency($cost)
            ];
        }

        return [
            'result' => [
                'count' => count($categories),
                'filter_category' => $category,
                'total_products_count' => $totalItemsAll,
                'total_units_in_stock' => $totalUnitsAll,
                'total_inventory_value' => PushNotifier::formatIndianCurrency($totalRetailVal),
                'categories' => $categories
            ],
            'cards' => [],
            'actions' => [
                ['label' => 'View inventory', 'target' => 'inventory']
            ],
            'suggestions' => [
                ['label' => 'Check low stock', 'message' => 'Which items are low on stock?'],
                ['label' => 'Top sellers', 'message' => 'What are my top selling products?']
            ]
        ];
    }

    // -------------------------------------------------------------------------
    // Tool 13: lowest_selling_products
    // -------------------------------------------------------------------------
    public static function lowestSellingProducts(PDO $pdo, int $shopId, string $period = 'month'): array {
        $dateCondition = ($period === 'today') 
            ? "DATE(s.sale_date) = CURDATE()" 
            : (($period === 'week') 
                ? "s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND s.sale_date <= NOW()" 
                : "s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND s.sale_date <= NOW()");

        $stmt = $pdo->prepare("
            SELECT 
                p.id AS product_id,
                p.name,
                p.sku,
                p.category,
                p.units AS current_stock,
                p.price,
                p.min_stock_threshold,
                COALESCE(SUM(si.quantity), 0) AS units_sold,
                COALESCE(SUM(si.line_total), 0) AS revenue
            FROM products p
            LEFT JOIN sales_items si ON p.id = si.product_id
            LEFT JOIN sales s ON si.sale_id = s.id AND {$dateCondition} AND s.shop_owner_id = ?
            WHERE p.shop_owner_id = ?
              AND (p.status IS NULL OR (p.status NOT LIKE '%Archived%' AND p.status NOT LIKE '%Inactive%'))
            GROUP BY p.id
            ORDER BY units_sold ASC, p.units DESC
            LIMIT 10
        ");
        $stmt->execute([$shopId, $shopId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        $cards = [];
        foreach ($rows as $r) {
            $unitsSold = (int)$r['units_sold'];
            $stock = (int)$r['current_stock'];
            $price = (float)($r['price'] ?? 0);
            $min = (int)($r['min_stock_threshold'] ?? 10);
            $rev = (float)$r['revenue'];

            $status = ($stock <= 0) ? 'Out of stock' : (($stock <= $min) ? 'Low stock' : 'In stock');

            $results[] = [
                'product_id' => (int)$r['product_id'],
                'name' => $r['name'],
                'sku' => $r['sku'],
                'category' => $r['category'] ?? 'General',
                'units_sold' => $unitsSold,
                'revenue' => PushNotifier::formatIndianCurrency($rev),
                'current_stock' => $stock,
                'selling_price' => PushNotifier::formatIndianCurrency($price)
            ];

            $cards[] = [
                'product_id' => (int)$r['product_id'],
                'name' => $r['name'],
                'sku' => $r['sku'],
                'units_left' => $stock,
                'avg_daily_sales' => round($unitsSold / 30.0, 1),
                'status' => $status,
                'price' => $price,
                'min_stock' => $min
            ];
        }

        return [
            'result' => [
                'count' => count($results),
                'period' => $period,
                'products' => $results
            ],
            'cards' => array_slice($cards, 0, 5),
            'actions' => [
                ['label' => 'View Inventory', 'target' => 'inventory']
            ],
            'suggestions' => [
                ['label' => 'Top sellers', 'message' => 'What are my top selling products?'],
                ['label' => 'Check low stock', 'message' => 'Which items are low on stock?']
            ]
        ];
    }

    // -------------------------------------------------------------------------
    // Tool 14: get_dead_stock
    // -------------------------------------------------------------------------
    public static function getDeadStock(PDO $pdo, int $shopId, int $days = 60): array {
        $stmt = $pdo->prepare("
            SELECT 
                p.id AS product_id,
                p.name,
                p.sku,
                p.category,
                p.units AS current_stock,
                p.price,
                p.cost_price,
                p.min_stock_threshold,
                (p.units * COALESCE(p.cost_price, p.price)) AS tied_up_cost
            FROM products p
            WHERE p.shop_owner_id = ?
              AND p.units > 0
              AND (p.status IS NULL OR (p.status NOT LIKE '%Archived%' AND p.status NOT LIKE '%Inactive%'))
              AND p.id NOT IN (
                  SELECT DISTINCT si.product_id
                  FROM sales_items si
                  JOIN sales s ON si.sale_id = s.id
                  WHERE s.shop_owner_id = ? AND s.sale_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
              )
            ORDER BY tied_up_cost DESC
            LIMIT 15
        ");
        $stmt->execute([$shopId, $shopId, $days]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        $cards = [];
        $totalCostTied = 0.0;

        foreach ($rows as $r) {
            $stock = (int)$r['current_stock'];
            $cost = (float)($r['cost_price'] ?? $r['price'] ?? 0);
            $tied = (float)$r['tied_up_cost'];
            $totalCostTied += $tied;

            $results[] = [
                'product_id' => (int)$r['product_id'],
                'name' => $r['name'],
                'sku' => $r['sku'],
                'category' => $r['category'] ?? 'General',
                'units' => $stock,
                'cost_price' => PushNotifier::formatIndianCurrency($cost),
                'tied_up_cost' => PushNotifier::formatIndianCurrency($tied)
            ];

            $cards[] = [
                'product_id' => (int)$r['product_id'],
                'name' => $r['name'],
                'sku' => $r['sku'],
                'units_left' => $stock,
                'avg_daily_sales' => 0.0,
                'status' => 'Dead stock',
                'price' => (float)($r['price'] ?? 0),
                'min_stock' => (int)($r['min_stock_threshold'] ?? 10)
            ];
        }

        return [
            'result' => [
                'count' => count($results),
                'days_threshold' => $days,
                'total_tied_up_capital' => PushNotifier::formatIndianCurrency($totalCostTied),
                'products' => $results
            ],
            'cards' => array_slice($cards, 0, 5),
            'actions' => [
                ['label' => 'View Inventory', 'target' => 'inventory']
            ],
            'suggestions' => [
                ['label' => 'What to reorder', 'message' => 'What should I reorder this week?'],
                ['label' => 'Check low stock', 'message' => 'Which items are low on stock?']
            ]
        ];
    }

    // -------------------------------------------------------------------------
    // Tool 15: get_purchase_order_status
    // -------------------------------------------------------------------------
    public static function getPurchaseOrderStatus(PDO $pdo, int $shopId, ?string $orderNo = null, ?string $statusFilter = null): array {
        $where = "po.shop_owner_id = ?";
        $params = [$shopId];

        if ($orderNo !== null && trim($orderNo) !== '') {
            $where .= " AND (po.order_no = ? OR po.order_no LIKE ?)";
            $params[] = trim($orderNo);
            $params[] = '%' . trim($orderNo) . '%';
        }

        if ($statusFilter !== null && trim($statusFilter) !== '') {
            $where .= " AND LOWER(po.status) = LOWER(?)";
            $params[] = trim($statusFilter);
        }

        $stmt = $pdo->prepare("
            SELECT po.id, po.order_no, po.status, po.total_items, po.total_units, po.total_estimated_cost, po.created_at, po.received_at
            FROM purchase_orders po
            WHERE {$where}
            ORDER BY po.id DESC
            LIMIT 10
        ");
        $stmt->execute($params);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($orders as $o) {
            $poid = (int)$o['id'];
            $stmtItems = $pdo->prepare("
                SELECT p.name AS product_name, poi.quantity, poi.cost_price, poi.line_estimated_cost
                FROM purchase_order_items poi
                JOIN products p ON poi.product_id = p.id
                WHERE poi.purchase_order_id = ?
                LIMIT 5
            ");
            $stmtItems->execute([$poid]);
            $itemsList = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            $parts = [];
            foreach ($itemsList as $it) {
                $parts[] = "{$it['product_name']} ({$it['quantity']} units)";
            }

            $results[] = [
                'order_id' => $poid,
                'order_no' => $o['order_no'],
                'status' => strtoupper($o['status']),
                'total_units' => (int)$o['total_units'],
                'total_estimated_cost' => PushNotifier::formatIndianCurrency($o['total_estimated_cost'] ?? 0),
                'created_at' => $o['created_at'],
                'received_at' => $o['received_at'],
                'items_summary' => implode(', ', $parts) ?: 'No items'
            ];
        }

        return [
            'result' => [
                'count' => count($results),
                'filter_order_no' => $orderNo,
                'filter_status' => $statusFilter,
                'orders' => $results
            ],
            'cards' => [],
            'actions' => [
                ['label' => 'Open purchase orders', 'target' => 'purchase_orders']
            ],
            'suggestions' => [
                ['label' => 'What to reorder', 'message' => 'What should I reorder this week?'],
                ['label' => 'Check low stock', 'message' => 'Which items are low on stock?']
            ]
        ];
    }

    // -------------------------------------------------------------------------
    // Tool 16: get_import_history
    // -------------------------------------------------------------------------
    public static function getImportHistory(PDO $pdo, int $shopId): array {
        $stmt = $pdo->prepare("
            SELECT id, file_name, file_size, total_rows, success_count, failed_count, status, details, created_at
            FROM import_history
            WHERE shop_owner_id = ?
            ORDER BY id DESC
            LIMIT 5
        ");
        $stmt->execute([$shopId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rows as $r) {
            $results[] = [
                'id' => (int)$r['id'],
                'file_name' => $r['file_name'],
                'status' => strtoupper($r['status']),
                'total_rows' => (int)$r['total_rows'],
                'success_count' => (int)$r['success_count'],
                'failed_count' => (int)$r['failed_count'],
                'imported_at' => $r['created_at'],
                'details' => $r['details']
            ];
        }

        return [
            'result' => [
                'count' => count($results),
                'history' => $results
            ],
            'cards' => [],
            'actions' => [
                ['label' => 'Open imports', 'target' => 'import']
            ],
            'suggestions' => [
                ['label' => 'Check inventory', 'message' => 'Show my inventory count'],
                ['label' => 'Check low stock', 'message' => 'Which items are low on stock?']
            ]
        ];
    }

    // -------------------------------------------------------------------------
    // Tool 17: get_out_of_stock_products
    // -------------------------------------------------------------------------
    public static function getOutOfStockProducts(PDO $pdo, int $shopId): array {
        $stmt = $pdo->prepare("
            SELECT id, name, sku, category, units, min_stock_threshold, price
            FROM products
            WHERE shop_owner_id = ?
              AND units <= 0
              AND (status IS NULL OR (status NOT LIKE '%Archived%' AND status NOT LIKE '%Inactive%'))
            ORDER BY name ASC
            LIMIT 20
        ");
        $stmt->execute([$shopId]);
        $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        $cards = [];
        foreach ($prods as $p) {
            $results[] = [
                'product_id' => (int)$p['id'],
                'name' => $p['name'],
                'sku' => $p['sku'],
                'category' => $p['category'] ?? 'General',
                'units' => 0,
                'price' => PushNotifier::formatIndianCurrency($p['price'] ?? 0)
            ];
            $cards[] = [
                'product_id' => (int)$p['id'],
                'name' => $p['name'],
                'sku' => $p['sku'],
                'units_left' => 0,
                'avg_daily_sales' => self::getAvgDailySales($pdo, $shopId, (int)$p['id']),
                'status' => 'Out of stock',
                'price' => (float)($p['price'] ?? 0),
                'min_stock' => (int)($p['min_stock_threshold'] ?? 10)
            ];
        }

        return [
            'result' => [
                'count' => count($results),
                'out_of_stock_count' => count($results),
                'products' => $results
            ],
            'cards' => array_slice($cards, 0, 5),
            'actions' => [
                ['label' => 'Open reorder list', 'target' => 'reorder']
            ],
            'suggestions' => [
                ['label' => 'What to reorder', 'message' => 'What should I reorder this week?'],
                ['label' => 'Today\'s sales', 'message' => 'How much did I sell today?']
            ]
        ];
    }

    // -------------------------------------------------------------------------
    // Tool 18: get_total_inventory_count
    // -------------------------------------------------------------------------
    public static function getTotalInventoryCount(PDO $pdo, int $shopId): array {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_products,
                COALESCE(SUM(units), 0) AS total_units,
                COALESCE(SUM(units * price), 0) AS total_retail_value,
                COALESCE(SUM(units * COALESCE(cost_price, price)), 0) AS total_cost_value,
                SUM(CASE WHEN units <= 0 THEN 1 ELSE 0 END) AS out_of_stock_count,
                SUM(CASE WHEN units > 0 AND units <= COALESCE(min_stock_threshold, 10) THEN 1 ELSE 0 END) AS low_stock_count
            FROM products
            WHERE shop_owner_id = ?
              AND (status IS NULL OR (status NOT LIKE '%Archived%' AND status NOT LIKE '%Inactive%'))
        ");
        $stmt->execute([$shopId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'result' => [
                'total_products' => (int)($row['total_products'] ?? 0),
                'total_units' => (int)($row['total_units'] ?? 0),
                'out_of_stock_count' => (int)($row['out_of_stock_count'] ?? 0),
                'low_stock_count' => (int)($row['low_stock_count'] ?? 0),
                'total_retail_value' => PushNotifier::formatIndianCurrency($row['total_retail_value'] ?? 0),
                'total_cost_value' => PushNotifier::formatIndianCurrency($row['total_cost_value'] ?? 0)
            ],
            'cards' => [],
            'actions' => [
                ['label' => 'View inventory', 'target' => 'inventory']
            ],
            'suggestions' => [
                ['label' => 'Check low stock', 'message' => 'Which items are low on stock?'],
                ['label' => 'Category totals', 'message' => 'Show stock by category']
            ]
        ];
    }
}

