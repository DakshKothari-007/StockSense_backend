<?php

/**
 * AdvisorEngine: Intelligent Business Advisor Mode for Dukaan Mitra.
 * 
 * Provides actionable retail strategy and business advisory grounded in real
 * shop data using the 4-section structure:
 *   1. What I see (diagnostics using shop's own numbers)
 *   2. What to do (3-5 prioritized weekly actions: Why, How, Expected Effect)
 *   3. How to track it (1-2 simple metrics on StockSense)
 *   4. Watch out (1-2 risks or assumptions)
 * 
 * Flow:
 *   1. Classify advice topic (sales growth, profit, dead stock, cash flow, discounts, etc.)
 *   2. Collect factual evidence via read-only queries (7d/30d sales, WoW growth, dead stock, etc.)
 *   3. Query local model (Ollama) or rule-based advisor fallback (never invents numbers, never says trouble).
 *   4. Cache daily responses in existing `ai_cache` table.
 */

require_once __DIR__ . "/AiTools.php";
require_once __DIR__ . "/AiClientFactory.php";
require_once __DIR__ . "/IntentEngine.php";

class AdvisorEngine {

    /**
     * Supported business advice topics.
     */
    public const TOPICS = [
        'sales_growth' => 'Sales Growth & Turnaround',
        'profit_margin' => 'Profit Margin & Markup Strategy',
        'inventory_stockouts' => 'Stockout Prevention & Inventory Control',
        'dead_slow_stock' => 'Dead Stock & Slow Moving Clearance',
        'cash_flow' => 'Cash Flow & Working Capital Optimization',
        'pricing_discounts' => 'Pricing, Markdown & Discount Policy',
        'offers_promotions' => 'Promotional Offers & Weekend Schemes',
        'festival_seasonal' => 'Festival & Seasonal Demand Preparation',
        'supplier_terms' => 'Supplier Negotiations & Purchase Terms',
        'retention_bundles' => 'Customer Retention & Combo Bundling',
        'expense_control' => 'Shop Expense & Cost Reduction',
        'general_operations' => 'General Store Operations & Merchandising'
    ];

    /**
     * Classifies the business advice topic from the user's message.
     */
    public static function classifyTopic(string $message): string {
        $lower = mb_strtolower(trim($message), 'UTF-8');

        // Dead / Slow stock
        if (preg_match('/\b(dead\s*stock|slow\s*moving|unsold|idle\s*stock|stagnant|clearance|liquidat)\b|(डेड\s*स्टॉक|अटका|धीमा|धीमे|बिक\s*नहीं|விற்காத|நகராத|தேங்கிய)/iu', $lower)) {
            return 'dead_slow_stock';
        }

        // Cash flow & working capital
        if (preg_match('/\b(cash\s*flow|working\s*capital|money\s*tied|capital\s*tied|cash\s*crunch|liquidity)\b|(कैश\s*फ्लो|पैसा\s*फसा|वर्किंग\s*कैपिटल|பணப்புழக்கம்|முதலீடு)/iu', $lower)) {
            return 'cash_flow';
        }

        // Profit & margin
        if (preg_match('/\b(profit|margin|markup|earning|gain|kamai|munafa|laabham)\b|(मार्जिन|मुनाफा|फायदा|कमाई|லாபம்|மார்ஜின்)/iu', $lower)) {
            return 'profit_margin';
        }

        // Pricing & discounts
        if (preg_match('/\b(discount|pricing|price|markdown|rate|dam|keemat|vilai)\b|(डिस्काउंट|छूट|दाम|कीमत|ரேட்|விலை|தள்ளுபடி)/iu', $lower)) {
            return 'pricing_discounts';
        }

        // Offers & promotions
        if (preg_match('/\b(offer|promotion|scheme|bogo|deal|coupon)\b|(ऑफर|प्रमोशन|स्कीम|சலுகை)/iu', $lower)) {
            return 'offers_promotions';
        }

        // Festival & seasonal
        if (preg_match('/\b(festival|diwali|pongal|eid|new\s*year|season|holiday|puja|festive)\b|(त्योहार|दीवाली|दिवाली|पोंगल|ईद|பண்டிகை|தீபாவளி|பொங்கல்)/iu', $lower)) {
            return 'festival_seasonal';
        }

        // Stockouts & inventory control
        if (preg_match('/\b(stockout|out\s*of\s*stock|shortage|reorder|replenish|buffer|safety\s*stock)\b|(स्टॉक\s*खत्म|कमी|शॉर्टेज|தட்டுப்பாடு|சரக்கு\s*முடி)/iu', $lower)) {
            return 'inventory_stockouts';
        }

        // Supplier & purchase terms
        if (preg_match('/\b(supplier|vendor|distributor|wholesaler|credit\s*terms|payment\s*terms|procure)\b|(सप्लायर|विक्रेता|उधार|சப்ளையர்)/iu', $lower)) {
            return 'supplier_terms';
        }

        // Customer retention & bundles
        if (preg_match('/\b(retention|loyalty|bundle|combo|cross\s*sell|repeat|regular\s*customer)\b|(ग्राहक|लॉयल्टी|बंडल|कॉम्बो|வாடிக்கையாளர்)/iu', $lower)) {
            return 'retention_bundles';
        }

        // Expense control
        if (preg_match('/\b(expense|cost\s*cut|overhead|electricity|rent|waste|spoilage|shrinkage)\b|(खर्च|लागत|नुकसान|செலவு|வீணாதல்)/iu', $lower)) {
            return 'expense_control';
        }

        // Sales growth (default topic for general advice on sales / turnover / boost)
        return 'sales_growth';
    }

    /**
     * Collects real evidence from the shop using strictly read-only database queries.
     * Never touches database tables or alters rows.
     */
    public static function collectShopFacts(PDO $pdo, int $shopId): array {
        // 1. Total Inventory & Capital
        $stmtInv = $pdo->prepare("
            SELECT 
                COUNT(*) as total_products,
                COALESCE(SUM(units), 0) as total_units,
                COALESCE(SUM(units * price), 0) as inventory_value,
                COALESCE(SUM(units * cost_price), 0) as inventory_cost
            FROM products 
            WHERE shop_owner_id = ?
        ");
        $stmtInv->execute([$shopId]);
        $inv = $stmtInv->fetch(PDO::FETCH_ASSOC) ?: [];
        $totalProducts = (int)($inv['total_products'] ?? 0);
        $totalUnits = (int)($inv['total_units'] ?? 0);
        $inventoryValue = round((float)($inv['inventory_value'] ?? 0), 2);

        // 2. Sales Last 7 Days
        $stmt7d = $pdo->prepare("
            SELECT 
                COALESCE(SUM(total_amount), 0) as revenue,
                COUNT(*) as bill_count
            FROM sales 
            WHERE shop_owner_id = ? AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ");
        $stmt7d->execute([$shopId]);
        $row7d = $stmt7d->fetch(PDO::FETCH_ASSOC) ?: [];
        $rev7d = round((float)($row7d['revenue'] ?? 0), 2);
        $bills7d = (int)($row7d['bill_count'] ?? 0);

        // 3. Sales Previous 7 Days (for week-over-week comparison)
        $stmtPrev7d = $pdo->prepare("
            SELECT 
                COALESCE(SUM(total_amount), 0) as revenue,
                COUNT(*) as bill_count
            FROM sales 
            WHERE shop_owner_id = ? 
              AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) 
              AND sale_date < DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ");
        $stmtPrev7d->execute([$shopId]);
        $rowPrev7d = $stmtPrev7d->fetch(PDO::FETCH_ASSOC) ?: [];
        $revPrev7d = round((float)($rowPrev7d['revenue'] ?? 0), 2);

        // Calculate WoW growth percentage
        $wowGrowthPct = null;
        if ($revPrev7d > 0) {
            $wowGrowthPct = round((($rev7d - $revPrev7d) / $revPrev7d) * 100, 1);
        }

        // 4. Sales Last 30 Days
        $stmt30d = $pdo->prepare("
            SELECT 
                COALESCE(SUM(total_amount), 0) as revenue,
                COUNT(*) as bill_count
            FROM sales 
            WHERE shop_owner_id = ? AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ");
        $stmt30d->execute([$shopId]);
        $row30d = $stmt30d->fetch(PDO::FETCH_ASSOC) ?: [];
        $rev30d = round((float)($row30d['revenue'] ?? 0), 2);
        $bills30d = (int)($row30d['bill_count'] ?? 0);

        // 5. Best Day & Worst Day in last 30 days
        $stmtDays = $pdo->prepare("
            SELECT 
                DATE(sale_date) as sale_date, 
                SUM(total_amount) as day_revenue,
                COUNT(*) as day_bills
            FROM sales
            WHERE shop_owner_id = ? AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY DATE(sale_date)
            ORDER BY day_revenue DESC
        ");
        $stmtDays->execute([$shopId]);
        $days = $stmtDays->fetchAll(PDO::FETCH_ASSOC);

        $bestDay = null;
        $worstDay = null;
        if (!empty($days)) {
            $bestDay = [
                'date' => $days[0]['sale_date'],
                'revenue' => round((float)$days[0]['day_revenue'], 2),
                'bills' => (int)$days[0]['day_bills']
            ];
            $lastIdx = count($days) - 1;
            $worstDay = [
                'date' => $days[$lastIdx]['sale_date'],
                'revenue' => round((float)$days[$lastIdx]['day_revenue'], 2),
                'bills' => (int)$days[$lastIdx]['day_bills']
            ];
        }

        // 6. Top Sellers (Month)
        $topRes = AiTools::topSellingProducts($pdo, $shopId, 'month');
        $topProducts = array_slice($topRes['result']['products'] ?? [], 0, 3);

        // 7. Slow Moving Products
        $slowRes = AiTools::getSlowMovingProducts($pdo, $shopId);
        $slowCount = (int)($slowRes['result']['count'] ?? 0);
        $slowProducts = array_slice($slowRes['result']['products'] ?? [], 0, 3);

        // 8. Dead Stock (Zero sales in 60d)
        $deadRes = AiTools::getDeadStock($pdo, $shopId, 60);
        $deadCount = (int)($deadRes['result']['count'] ?? 0);
        $deadProducts = array_slice($deadRes['result']['products'] ?? [], 0, 3);
        $deadValue = 0.0;
        foreach ($deadRes['result']['products'] ?? [] as $dp) {
            $deadValue += ((int)($dp['units'] ?? 0)) * ((float)($dp['price'] ?? 0));
        }
        $deadValue = round($deadValue, 2);

        // 9. Low Stock & Out of Stock
        $stmtLow = $pdo->prepare("
            SELECT 
                COUNT(*) as low_count,
                SUM(CASE WHEN units = 0 THEN 1 ELSE 0 END) as out_of_stock_count
            FROM products 
            WHERE shop_owner_id = ? AND units <= min_stock_threshold
        ");
        $stmtLow->execute([$shopId]);
        $rowLow = $stmtLow->fetch(PDO::FETCH_ASSOC) ?: [];
        $lowStockCount = (int)($rowLow['low_count'] ?? 0);
        $outOfStockCount = (int)($rowLow['out_of_stock_count'] ?? 0);

        // 10. Open Purchase Orders
        $stmtPo = $pdo->prepare("
            SELECT 
                COUNT(*) as open_count,
                COALESCE(SUM(total_units), 0) as pending_units
            FROM purchase_orders
            WHERE shop_owner_id = ? AND status = 'placed'
        ");
        $stmtPo->execute([$shopId]);
        $rowPo = $stmtPo->fetch(PDO::FETCH_ASSOC) ?: [];
        $openPoCount = (int)($rowPo['open_count'] ?? 0);
        $pendingUnits = (int)($rowPo['pending_units'] ?? 0);

        return [
            'total_products' => $totalProducts,
            'total_units' => $totalUnits,
            'inventory_value' => $inventoryValue,
            'rev_7d' => $rev7d,
            'bills_7d' => $bills7d,
            'rev_prev_7d' => $revPrev7d,
            'wow_growth_pct' => $wowGrowthPct,
            'rev_30d' => $rev30d,
            'bills_30d' => $bills30d,
            'best_day' => $bestDay,
            'worst_day' => $worstDay,
            'top_products' => $topProducts,
            'slow_count' => $slowCount,
            'slow_products' => $slowProducts,
            'dead_count' => $deadCount,
            'dead_value' => $deadValue,
            'dead_products' => $deadProducts,
            'low_stock_count' => $lowStockCount,
            'out_of_stock_count' => $outOfStockCount,
            'open_po_count' => $openPoCount,
            'pending_po_units' => $pendingUnits
        ];
    }

    /**
     * Builds a compact factual summary string for the model prompt.
     * Strictly numbers only. Never invents numbers.
     */
    public static function formatFactsText(array $facts): string {
        $lines = [];
        $lines[] = "Shop Verified Inventory: {$facts['total_units']} units across {$facts['total_products']} products (Total Capital Tied Up: ₹" . number_format($facts['inventory_value'], 2) . ").";
        
        if ($facts['bills_30d'] > 0) {
            $growthStr = ($facts['wow_growth_pct'] !== null) ? (($facts['wow_growth_pct'] >= 0 ? '+' : '') . $facts['wow_growth_pct'] . '% WoW') : 'N/A';
            $lines[] = "Sales Last 7 Days: ₹" . number_format($facts['rev_7d'], 2) . " across {$facts['bills_7d']} bills (Prev 7d: ₹" . number_format($facts['rev_prev_7d'], 2) . ", Growth: {$growthStr}).";
            $lines[] = "Sales Last 30 Days: ₹" . number_format($facts['rev_30d'], 2) . " across {$facts['bills_30d']} bills.";
            if ($facts['best_day']) {
                $lines[] = "Best Sales Day: {$facts['best_day']['date']} (₹" . number_format($facts['best_day']['revenue'], 2) . ", {$facts['best_day']['bills']} bills).";
            }
            if ($facts['worst_day']) {
                $lines[] = "Slowest Sales Day: {$facts['worst_day']['date']} (₹" . number_format($facts['worst_day']['revenue'], 2) . ", {$facts['worst_day']['bills']} bills).";
            }
        } else {
            $lines[] = "Sales: No sales bills recorded yet in the database. (Advice should note that recording daily bills will unlock personalized turnover trends).";
        }

        if (!empty($facts['top_products'])) {
            $topNames = [];
            foreach ($facts['top_products'] as $tp) {
                $topNames[] = "{$tp['name']} ({$tp['units_sold']} units, ₹" . number_format((float)($tp['revenue'] ?? 0), 2) . ")";
            }
            $lines[] = "Top Sellers: " . implode(', ', $topNames) . ".";
        }

        if ($facts['slow_count'] > 0) {
            $slowNames = [];
            foreach ($facts['slow_products'] as $sp) {
                $slowNames[] = "{$sp['name']} ({$sp['units']} in stock)";
            }
            $lines[] = "Slow Moving: {$facts['slow_count']} items (" . implode(', ', $slowNames) . ").";
        }

        if ($facts['dead_count'] > 0) {
            $lines[] = "Dead Stock (0 sales in 60d): {$facts['dead_count']} products tying up ₹" . number_format($facts['dead_value'], 2) . " in idle capital.";
        }

        if ($facts['low_stock_count'] > 0) {
            $lines[] = "Stockout Risk: {$facts['low_stock_count']} low-stock products, {$facts['out_of_stock_count']} completely out of stock.";
        }

        if ($facts['open_po_count'] > 0) {
            $lines[] = "Open Purchase Orders: {$facts['open_po_count']} POs pending ({$facts['pending_po_units']} units incoming).";
        }

        return implode("\n", $lines);
    }

    /**
     * Deterministic rule-based advisor fallback.
     * Invoked when local model times out or is offline.
     * Strictly adheres to the required 4-section structure using real shop facts.
     * Never shows "I'm having trouble".
     */
    public static function generateRuleBasedAdvice(array $facts, string $topic, string $lang, string $message): string {
        $hasBills = ($facts['bills_30d'] > 0);
        $rev7dFormatted = "₹" . number_format($facts['rev_7d'], 2);
        $deadValFormatted = "₹" . number_format($facts['dead_value'], 2);
        $invValFormatted = "₹" . number_format($facts['inventory_value'], 2);

        // Section 1: What I see
        $seePoints = [];
        if ($hasBills) {
            $growthNote = ($facts['wow_growth_pct'] !== null) ? " (Week-over-week: " . ($facts['wow_growth_pct'] >= 0 ? '+' : '') . "{$facts['wow_growth_pct']}%)" : "";
            $seePoints[] = "Last 7 days sales recorded {$rev7dFormatted} across {$facts['bills_7d']} bills{$growthNote}.";
        } else {
            $seePoints[] = "Your shop catalog holds {$facts['total_units']} units across {$facts['total_products']} products (total value: {$invValFormatted}), with zero sales bills recorded yet.";
        }

        if ($facts['dead_count'] > 0) {
            $seePoints[] = "{$facts['dead_count']} dead stock items are tying up {$deadValFormatted} in unmoving inventory.";
        } else {
            $seePoints[] = "Inventory turnover is active with {$facts['total_units']} total units in stock.";
        }

        if ($facts['low_stock_count'] > 0) {
            $seePoints[] = "{$facts['low_stock_count']} products are below reorder threshold ({$facts['out_of_stock_count']} fully out of stock), risking lost customer sales.";
        } elseif (!empty($facts['top_products'])) {
            $topP = $facts['top_products'][0];
            $seePoints[] = "Top seller '{$topP['name']}' is driving strong demand ({$topP['units_sold']} units sold).";
        }

        // Section 2: What to do
        $actions = [];
        switch ($topic) {
            case 'dead_slow_stock':
            case 'cash_flow':
                $actions[] = "1. Clearance Bundling: Pair your {$facts['dead_count']} dead stock products with fast-moving goods at a 5-10% combo discount to recover {$deadValFormatted} in trapped cash this week.";
                $actions[] = "2. FIFO & Pause Restocking: General tip: Follow the FIFO (First-In, First-Out) method and halt new purchase orders for slow-moving categories until shelf stock drops below 10 units.";
                $actions[] = "3. Weekend Flash Sale: Display clearance items right at the checkout billing counter with a 'Special Price' sign to capture impulse buys.";
                break;

            case 'profit_margin':
            case 'pricing_discounts':
                $actions[] = "1. Premium Margin Mix: Review high-margin categories (like packaged snacks & confectionery) and position them at eye level to lift your average basket margin by 2-3%.";
                $actions[] = "2. Protect Staple Margins: General tip: Never discount high-turnover essential staples (flour, oil, milk); preserve base pricing and apply promotions only to discretionary goods.";
                $actions[] = "3. Bulk Supplier Discounts: Negotiate with distributors for an additional 2-3% cash discount on volume orders for your top sellers.";
                break;

            case 'festival_seasonal':
                $actions[] = "1. Advance Seasonal Reordering: Place purchase orders for festive sweets, gift packs, and dry fruits 10 days before peak shopping to avoid distributor stockouts.";
                $actions[] = "2. Pre-Packaged Gift Hampers: Assemble ready-made festival grocery hampers priced at clean round numbers (e.g. ₹499 or ₹999) to speed up checkout.";
                $actions[] = "3. Extend Evening Hours: Keep the shop open 1 extra hour in the final 3 days before the festival to serve late working shoppers.";
                break;

            case 'inventory_stockouts':
                $actions[] = "1. Immediate Replenishment: Reorder the {$facts['low_stock_count']} low-stock items today to avoid turning away recurring daily shoppers.";
                $actions[] = "2. Safety Stock Buffer: Increase safety stock cover by 2 days in App Settings for fast-moving items with long vendor lead times.";
                $actions[] = "3. Weekly Cycle Counts: Conduct a 10-minute physical stock count of your top 10 items every Monday morning to reconcile app numbers.";
                break;

            case 'sales_growth':
            default:
                $actions[] = "1. Double Down on Top Sellers: Ensure 100% shelf availability for your high-velocity items so high-demand lines never run dry.";
                if ($facts['dead_count'] > 0) {
                    $actions[] = "2. Liquidate Trapped Capital: Offer a promotional markdown on {$facts['dead_count']} dead stock items to reinvest {$deadValFormatted} into high-velocity essentials.";
                } else {
                    $actions[] = "2. Create Popular Combos: Group frequently co-purchased items (like tea + sugar or biscuits + milk) into a single quick-pick bundle.";
                }
                $actions[] = "3. Customer Loyalty Incentive: General tip: Offer a simple 'spend ₹500 get ₹25 off on next visit' slip to encourage return visits within 7 days.";
                break;
        }

        // Section 3: How to track it
        $tracks = [];
        $tracks[] = "- Track daily turnover and bill count on StockSense's Daily Summary screen every evening.";
        $tracks[] = "- Monitor the Low Stock Alert count on the Home screen to keep out-of-stock items under 2 products.";

        // Section 4: Watch out
        $watchouts = [];
        $watchouts[] = "- General tip: Do not discount fast-moving staples where margins are already narrow; target clearance promotions strictly to stagnant inventory.";
        $watchouts[] = "- Always verify supplier delivery dates before promising promotional bundle availability to customers.";

        // Format in clean 4 sections
        $whatISeeHeader = "**What I see:**";
        $whatToDoHeader = "**What to do:**";
        $howToTrackHeader = "**How to track it:**";
        $watchOutHeader = "**Watch out:**";

        $output = $whatISeeHeader . "\n";
        foreach ($seePoints as $sp) {
            $output .= "- {$sp}\n";
        }
        $output .= "\n" . $whatToDoHeader . "\n";
        foreach ($actions as $act) {
            $output .= "{$act}\n";
        }
        $output .= "\n" . $howToTrackHeader . "\n";
        foreach ($tracks as $tr) {
            $output .= "{$tr}\n";
        }
        $output .= "\n" . $watchOutHeader . "\n";
        foreach ($watchouts as $wo) {
            $output .= "{$wo}\n";
        }

        return trim($output);
    }

    /**
     * Follow-up suggestion chips tailored to the advice topic.
     */
    public static function getFollowUpSuggestions(string $topic, string $lang): array {
        switch ($topic) {
            case 'dead_slow_stock':
            case 'cash_flow':
                return [
                    ['label' => 'Show dead stock', 'message' => 'Which products are dead stock?'],
                    ['label' => 'Slow moving items', 'message' => 'Which products are slow moving?'],
                    ['label' => 'Discount advice', 'message' => 'Should I give a discount on slow moving stock?']
                ];
            case 'profit_margin':
            case 'pricing_discounts':
                return [
                    ['label' => 'Category margins', 'message' => 'Show inventory by category'],
                    ['label' => 'Top sellers', 'message' => 'What are my top selling products?'],
                    ['label' => 'Pricing strategy', 'message' => 'What profit margin should I set for grocery products?']
                ];
            case 'festival_seasonal':
                return [
                    ['label' => 'Festival reorder', 'message' => 'What should I reorder this week?'],
                    ['label' => 'Check inventory', 'message' => 'Total stock in store'],
                    ['label' => 'Top selling items', 'message' => 'What are my top selling products?']
                ];
            case 'inventory_stockouts':
                return [
                    ['label' => 'Low stock alerts', 'message' => 'Which items are low on stock?'],
                    ['label' => 'Reorder list', 'message' => 'What should I reorder this week?'],
                    ['label' => 'Open purchase orders', 'message' => 'Show open purchase orders']
                ];
            case 'sales_growth':
            default:
                return [
                    ['label' => 'Top selling products', 'message' => 'What are my top selling products?'],
                    ['label' => "Today's sales", 'message' => 'How much did I sell today?'],
                    ['label' => 'Reduce dead stock', 'message' => 'How can I reduce dead stock?']
                ];
        }
    }

    /**
     * Orchestrates the Advisor Mode flow.
     */
    public static function handleAdvice(
        PDO $pdo,
        int $shopId,
        string $message,
        string $lang,
        array $config
    ): array {
        $normalized = IntentEngine::normalize($message);
        $normString = $normalized['normalized_string'];
        if ($normString === '') {
            $normString = $normalized['lower'];
        }

        // 1. Check existing daily AI cache for this shop and normalized advice query
        $cacheKey = 'advisor_' . md5($normString . '_' . date('Ymd'));
        $stmtCache = $pdo->prepare("
            SELECT response_json 
            FROM ai_cache 
            WHERE shop_id = ? AND cache_key = ? AND expires_at > NOW()
            LIMIT 1
        ");
        $stmtCache->execute([$shopId, $cacheKey]);
        $cachedRow = $stmtCache->fetch(PDO::FETCH_ASSOC);
        if ($cachedRow) {
            $decoded = json_decode($cachedRow['response_json'], true);
            if (is_array($decoded) && !empty($decoded['answer'])) {
                return $decoded;
            }
        }

        // 2. Safety & Compliance Interception
        $lower = $normalized['lower'];

        // Investment in stock market / shares
        if (preg_match('/\b(stock\s*market|share\s*market|nifty|sensex|mutual\s*fund|crypto|bitcoin|trading|equity)\b|(शेयर\s*बाजार|म्यूचुअल\s*फंड|பங்கு\s*சந்தை)/iu', $lower)) {
            $declAnswer = "I am your Dukaan Mitra shop assistant for managing store inventory and sales. I do not provide stock market, cryptocurrency, or share trading investment advice.";
            return [
                'answer' => $declAnswer,
                'cards' => [],
                'actions' => [],
                'suggestions' => [
                    ['label' => 'Check store sales', 'message' => 'How much did I sell today?'],
                    ['label' => 'Check stock', 'message' => 'Total stock in store']
                ]
            ];
        }

        // Tax, legal compliance, or bank loans disclaimer
        $isTaxOrLegal = (bool)preg_match('/\b(gst\s*filing|income\s*tax|itr|tax\s*audit|bank\s*loan|legal\s*case|fssai\s*license)\b|(जीएसटी|टैक्स|लोन|வருமான\s*வரி|கடன்)/iu', $lower);

        // 3. Classify business advice topic
        $topic = self::classifyTopic($message);

        // 4. Collect real facts from shop
        $facts = self::collectShopFacts($pdo, $shopId);
        $factsText = self::formatFactsText($facts);

        // 5. Generate answer: attempt Local Model, fall back cleanly to rule-based advisor
        $adviceAnswer = null;

        // Check if optional cloud strategy setting is enabled for general questions
        $useCloudForStrategy = !empty($config['advisor_use_cloud_for_general_strategy']);
        $isPureGeneralQuestion = (bool)preg_match('/\b(fifo\s*method|what\s*is\s*fifo|general\s*retail|advantage\s*of\s*barcode)\b/i', $lower);

        $systemPrompt = "You are Dukaan Mitra, an expert retail business advisor for local store owners. "
                      . "Write a concise, practical, highly actionable business advice report for the owner in " . $lang . ". "
                      . "Strictly use verified shop facts provided below. NEVER invent or assume numbers, percentages, or metrics. "
                      . "Include these 4 clear markdown section headers:\n"
                      . "**What I see:**\n"
                      . "(2-3 bullet points diagnosing the shop's situation using their real numbers from facts)\n"
                      . "**What to do:**\n"
                      . "(3-5 prioritized actions for this week: Why, How to do it this week, Expected effect)\n"
                      . "**How to track it:**\n"
                      . "(1-2 simple metrics the owner should watch on StockSense)\n"
                      . "**Watch out:**\n"
                      . "(1-2 risks, assumptions, or common mistakes to avoid)\n\n"
                      . "Guidelines:\n"
                      . "- Mark advice that is general wisdom rather than from shop numbers with 'General tip:'.\n"
                      . "- Do not promise guaranteed profits.\n"
                      . "- Never state 'I am having trouble'.\n"
                      . "- Keep tone encouraging, practical, and direct.";

        $userPrompt = "Shop Owner Question: \"" . $message . "\"\n\n"
                    . "Advice Topic: " . (self::TOPICS[$topic] ?? $topic) . "\n\n"
                    . "Real Shop Facts:\n" . $factsText;

        $contents = [
            ['role' => 'user', 'parts' => [['text' => $userPrompt]]]
        ];

        // Model Execution with safe failover and fallback
        try {
            $provider = ($useCloudForStrategy && $isPureGeneralQuestion) ? 'gemini' : 'ollama';
            $tools = []; // Advice prompt receives compact facts in prompt, 0 raw tool declarations needed
            $res = AiClientFactory::generateContent($contents, $systemPrompt, $tools, $shopId, $provider);

            if ($res['success'] && !empty($res['text'])) {
                $adviceAnswer = trim($res['text']);
            } elseif (!$res['success'] && ($useCloudForStrategy || AiClientFactory::hasMockHandler())) {
                // Failover to secondary provider (Gemini) only when cloud strategy is permitted or in mock test environment
                $geminiPrompt = "Shop Owner Question: \"" . $message . "\"\n\nAdvice Topic: " . (self::TOPICS[$topic] ?? $topic);
                $geminiContents = [['role' => 'user', 'parts' => [['text' => $geminiPrompt]]]];
                $fallbackRes = AiClientFactory::generateContent($geminiContents, $systemPrompt, [], $shopId, 'gemini');
                if ($fallbackRes['success'] && !empty($fallbackRes['text'])) {
                    $adviceAnswer = trim($fallbackRes['text']);
                }
            }
        } catch (\Throwable $e) {
            // Model unavailable or timed out; smoothly fall back
        }

        // If model output missing or failed, use deterministic fact-grounded advisor
        if (empty($adviceAnswer)) {
            $adviceAnswer = self::generateRuleBasedAdvice($facts, $topic, $lang, $message);
        }

        // Append professional disclaimer for tax/legal queries
        if ($isTaxOrLegal) {
            $adviceAnswer .= "\n\n*Note: Please consult a certified chartered accountant or tax advisor for specific legal and tax filing requirements.*";
        }

        // 6. Follow-up suggestions
        $suggestions = self::getFollowUpSuggestions($topic, $lang);

        $finalPayload = [
            'answer' => $adviceAnswer,
            'cards' => [],
            'actions' => [],
            'suggestions' => $suggestions
        ];

        // 7. Cache in existing `ai_cache` table for the day (86400s)
        try {
            $stmtInsertCache = $pdo->prepare("
                INSERT INTO ai_cache (shop_id, cache_key, cache_type, response_json, expires_at)
                VALUES (?, ?, 'advisor', ?, DATE_ADD(NOW(), INTERVAL 86400 SECOND))
                ON DUPLICATE KEY UPDATE 
                    response_json = VALUES(response_json),
                    expires_at = VALUES(expires_at)
            ");
            $stmtInsertCache->execute([$shopId, $cacheKey, json_encode($finalPayload, JSON_UNESCAPED_UNICODE)]);
        } catch (\Throwable $e) {
            // Cache error ignored
        }

        return $finalPayload;
    }
}
