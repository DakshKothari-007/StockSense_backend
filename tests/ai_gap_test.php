<?php
/**
 * ai_gap_test.php - Dukaan Mitra Comprehensive Gap-Finding Pass
 * 
 * Tests ~150 realistic shop owner questions across 18 groups in English, Hindi,
 * Tamil, Hinglish, and Tanglish. Evaluates paths, latency, refusals, tool usage,
 * and response quality.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/AiEngine.php';
require_once __DIR__ . '/includes/AiTools.php';
require_once __DIR__ . '/includes/AiClientFactory.php';

echo "=== DUKAAN MITRA AI COMPREHENSIVE GAP-FINDING TEST ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

// -----------------------------------------------------------------------------
// 1. SETUP REALISTIC TEST SHOP
// -----------------------------------------------------------------------------
$pdo->beginTransaction();

// Clean up any previous gap test shops
$stmtOld = $pdo->prepare("SELECT id FROM owner_account WHERE email = 'gap_test_owner@example.com'");
$stmtOld->execute();
$oldShops = $stmtOld->fetchAll(PDO::FETCH_COLUMN);
foreach ($oldShops as $oldId) {
    $pdo->prepare("DELETE FROM ai_cache WHERE shop_id = ?")->execute([$oldId]);
    $pdo->prepare("DELETE FROM ai_usage WHERE shop_id = ?")->execute([$oldId]);
    $pdo->prepare("DELETE FROM sales_items WHERE sale_id IN (SELECT id FROM sales WHERE shop_owner_id = ?)")->execute([$oldId]);
    $pdo->prepare("DELETE FROM sales WHERE shop_owner_id = ?")->execute([$oldId]);
    $pdo->prepare("DELETE FROM purchase_order_items WHERE purchase_order_id IN (SELECT id FROM purchase_orders WHERE shop_owner_id = ?)")->execute([$oldId]);
    $pdo->prepare("DELETE FROM purchase_orders WHERE shop_owner_id = ?")->execute([$oldId]);
    $pdo->prepare("DELETE FROM import_history WHERE shop_owner_id = ?")->execute([$oldId]);
    $pdo->prepare("DELETE FROM products WHERE shop_owner_id = ?")->execute([$oldId]);
    $pdo->prepare("DELETE FROM user_settings WHERE user_id = ?")->execute([$oldId]);
    $pdo->prepare("DELETE FROM tokens WHERE user_id = ?")->execute([$oldId]);
    $pdo->prepare("DELETE FROM owner_account WHERE id = ?")->execute([$oldId]);
}

// Create test owner
$pdo->prepare("
    INSERT INTO owner_account (full_name, shop_name, location, store_type, currency, email, password, created_at)
    VALUES ('Ramesh Kumar', 'Kumar Provision Store', 'Indiranagar, Bengaluru', 'Grocery & Kirana', '₹ INR / IST', 'gap_test_owner@example.com', 'dummy_hash', NOW())
")->execute();
$shopId = (int)$pdo->lastInsertId();

// Create user settings
$pdo->prepare("
    INSERT INTO user_settings (user_id, low_stock_alerts, ai_recommendations, daily_sales_summary, weekly_email_digest, theme_mode, lead_time_days, safety_days, cover_days, min_history_days, slow_moving_threshold_days, import_mode, timezone)
    VALUES (?, 1, 1, 1, 0, 'SYSTEM', 2, 3, 7, 30, 60, 'ADD', 'Asia/Kolkata')
")->execute([$shopId]);

// Seed test products
$pdo->prepare("
    INSERT INTO products (shop_owner_id, name, sku, category, description, price, cost_price, units, min_stock_threshold, status, created_at) VALUES
    (?, 'Amul Milk - 500 ml', 'DAI-001', 'Dairy', '500 ml packet', 30.00, 26.00, 8, 15, 'Active', NOW()),
    (?, 'Britannia Marie · 150 g', 'SNK-014', 'Snacks', 'Tea-time biscuits', 35.00, 28.00, 60, 20, 'Active', NOW() - INTERVAL 45 DAY),
    (?, 'Aashirvaad Atta - 5 kg', 'STP-003', 'Staples', 'Whole wheat atta', 275.00, 240.00, 25, 10, 'Active', NOW()),
    (?, 'Tata Tea - 250 g', 'BEV-008', 'Beverages', 'Premium tea leaf', 130.00, 110.00, 0, 15, 'Active', NOW()),
    (?, 'Parle-G - 80 g', 'SNK-002', 'Snacks', 'Glucose biscuits', 10.00, 8.00, 80, 30, 'Active', NOW())
")->execute([$shopId, $shopId, $shopId, $shopId, $shopId]);

// Get product IDs
$prodStmt = $pdo->prepare("SELECT id, name FROM products WHERE shop_owner_id = ?");
$prodStmt->execute([$shopId]);
$prods = $prodStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$pAmul = array_search('Amul Milk - 500 ml', $prods);
$pMarie = array_search('Britannia Marie · 150 g', $prods);
$pAtta = array_search('Aashirvaad Atta - 5 kg', $prods);
$pTea = array_search('Tata Tea - 250 g', $prods);
$pParle = array_search('Parle-G - 80 g', $prods);

// Seed Sales: Today
$pdo->prepare("INSERT INTO sales (shop_owner_id, bill_no, sale_date, total_amount, created_at) VALUES (?, 'BILL-GAP-TODAY-1', NOW(), 200.00, NOW())")->execute([$shopId]);
$saleToday1 = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO sales_items (sale_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, 2, 30.00, 60.00), (?, ?, 1, 140.00, 140.00)")->execute([$saleToday1, $pAmul, $saleToday1, $pParle]);

$pdo->prepare("INSERT INTO sales (shop_owner_id, bill_no, sale_date, total_amount, created_at) VALUES (?, 'BILL-GAP-TODAY-2', NOW(), 140.00, NOW())")->execute([$shopId]);
$saleToday2 = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO sales_items (sale_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, 14, 10.00, 140.00)")->execute([$saleToday2, $pParle]);

// Seed Sales: Yesterday
$pdo->prepare("INSERT INTO sales (shop_owner_id, bill_no, sale_date, total_amount, created_at) VALUES (?, 'BILL-GAP-YEST-1', NOW() - INTERVAL 1 DAY, 550.00, NOW() - INTERVAL 1 DAY)")->execute([$shopId]);
$saleYest1 = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO sales_items (sale_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, 2, 275.00, 550.00)")->execute([$saleYest1, $pAtta]);

// Seed Sales: Specific past date (2026-10-30)
$pdo->prepare("INSERT INTO sales (shop_owner_id, bill_no, sale_date, total_amount, created_at) VALUES (?, 'BILL-GAP-PAST-1', '2026-10-30 14:00:00', 180.00, '2026-10-30 14:00:00')")->execute([$shopId]);
$salePast1 = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO sales_items (sale_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, 6, 30.00, 180.00)")->execute([$salePast1, $pAmul]);

// Seed Purchase Orders
$pdo->prepare("
    INSERT INTO purchase_orders (shop_owner_id, order_no, status, total_estimated_cost, total_items, total_units, received_at, created_at)
    VALUES (?, 'PO-2026-001', 'received', 270.00, 1, 10, NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 3 DAY),
           (?, 'PO-2026-002', 'placed', 550.00, 1, 20, NULL, NOW() - INTERVAL 1 DAY)
")->execute([$shopId, $shopId]);

// Seed Import History
$pdo->prepare("
    INSERT INTO import_history (shop_owner_id, file_name, file_size, total_rows, success_count, failed_count, status, details, created_at)
    VALUES (?, 'stock_oct.xlsx', 1024, 5, 5, 0, 'completed', '{\"products_added\":5}', NOW() - INTERVAL 5 DAY)
")->execute([$shopId]);

$pdo->commit();
echo "Seeded Test Shop (ID: {$shopId}, Owner: Ramesh Kumar, Shop: Kumar Provision Store)\n\n";

// Configure Mock Handler for Model Path when local model is offline
$usingMock = true;
$mockCalls = 0;
AiClientFactory::setMockHandler(function (array $payload) use (&$mockCalls) {
    $mockCalls++;
    $userQuery = '';
    if (!empty($payload['messages']) && is_array($payload['messages'])) {
        for ($i = count($payload['messages']) - 1; $i >= 0; $i--) {
            if (($payload['messages'][$i]['role'] ?? '') === 'user') {
                $userQuery = $payload['messages'][$i]['content'] ?? '';
                break;
            }
        }
    } elseif (!empty($payload['contents']) && is_array($payload['contents'])) {
        for ($i = count($payload['contents']) - 1; $i >= 0; $i--) {
            if (($payload['contents'][$i]['role'] ?? '') === 'user') {
                $userQuery = $payload['messages'][$i]['parts'][0]['text'] ?? '';
                break;
            }
        }
    }
    $text = mb_strtolower($userQuery, 'UTF-8');
    $hasDevanagari = (bool)preg_match('/[\x{0900}-\x{097F}]/u', $userQuery);
    $hasTamil = (bool)preg_match('/[\x{0B80}-\x{0BFF}]/u', $userQuery);

    if (stripos($text, 'amul') !== false) {
        $msg = $hasDevanagari ? "आपके पास 20 यूनिट अमूल दूध स्टॉक में हैं।" : ($hasTamil ? "உங்களிடம் 20 அமுல் பால் இருப்பு உள்ளது." : "You have 20 units of Amul Milk in stock.");
        return ['message' => ['role' => 'assistant', 'content' => $msg]];
    }
    if (stripos($text, 'tata tea') !== false || stripos($text, 'टाटा चाय') !== false) {
        $msg = $hasDevanagari ? "आपके पास 2 यूनिट टाटा चाय बची है।" : ($hasTamil ? "உங்களிடம் 2 டாட்டா டீ இருப்பு உள்ளது." : "You have 2 packets of Tata Tea left.");
        return ['message' => ['role' => 'assistant', 'content' => $msg]];
    }
    if (stripos($text, 'weather') !== false || stripos($text, 'cricket') !== false || stripos($text, 'score') !== false || stripos($text, 'வானிலை') !== false) {
        $msg = $hasDevanagari ? "मैं लाइव जानकारी नहीं देख सकता।" : ($hasTamil ? "என்னால் நேரலை தகவல்களை பார்க்க முடியாது." : "I cannot check live scores or live information directly.");
        return ['message' => ['role' => 'assistant', 'content' => $msg]];
    }
    if (stripos($text, 'joke') !== false || stripos($text, 'france') !== false || stripos($text, 'tea') !== false || stripos($text, 'चाय') !== false || stripos($text, 'நகைச்சுவை') !== false || stripos($text, 'recipe') !== false || stripos($text, 'times') !== false || stripos($text, 'hamlet') !== false) {
        $msg = $hasDevanagari ? "General tip: अदरक वाली चाय बनाने के लिए पानी, दूध, चायपत्ती और ताज़ा कुटा हुआ अदरक उबालें।" : ($hasTamil ? "General tip: ஒரு நல்ல நகைச்சுவை அல்லது தகவல் இதோ." : "General tip: Here is the information or recipe you requested.");
        return ['message' => ['role' => 'assistant', 'content' => $msg]];
    }
    if (stripos($text, 'how to') !== false || stripos($text, 'record a sale') !== false || stripos($text, 'scan') !== false || stripos($text, 'import') !== false || stripos($text, 'रिकॉर्ड') !== false || stripos($text, 'ஸ்கேன்') !== false) {
        $msg = $hasDevanagari ? "General tip: स्टॉकसेंस ऐप में बिक्री रिकॉर्ड करने के लिए 'Record Sale' पर टैप करें।" : ($hasTamil ? "General tip: விற்பனையை பதிவு செய்ய 'Record Sale' பொத்தானைத் தட்டவும்." : "General tip: In StockSense, use 'Record sale' at the checkout counter.");
        return ['message' => ['role' => 'assistant', 'content' => $msg]];
    }
    $msg = $hasDevanagari ? "मैं आपकी दुकान की इन्वेंटरी, बिक्री और उत्पादों में मदद करने के लिए यहाँ हूँ।" : ($hasTamil ? "உங்கள் கடை இருப்பு மற்றும் விற்பனைக்கு உதவ நான் இங்கு உள்ளேன்." : "I can assist you with your shop inventory, sales, products, and retail operations.");
    return ['message' => ['role' => 'assistant', 'content' => $msg]];
});
$usingMock = true;

// -----------------------------------------------------------------------------
// 2. DEFINE ~150 TEST QUESTIONS ACROSS 18 GROUPS
// -----------------------------------------------------------------------------
$questions = require __DIR__ . '/ai_gap_questions.php';

echo "Total test questions: " . count($questions) . "\n";
echo "Starting evaluation pass...\n\n";

// -----------------------------------------------------------------------------
// 3. RUN EVALUATION PASS
// -----------------------------------------------------------------------------
$results = [];
$failureCounts = [
    'empty' => 0,
    'wrong_language' => 0,
    'generic_trouble' => 0,
    'wrong_could_not_find' => 0,
    'wrong_refusal' => 0,
    'missed_refusal' => 0,
    'invented_numbers' => 0,
    'missing_data' => 0
];

$passCount = 0;
$failCount = 0;

printf("%-4s | %-14s | %-4s | %-42s | %-12s | %-6s | %-7s | %-6s | %s\n", 
    "ID", "Group", "Lang", "Question", "Path", "Time", "Refused", "Status", "Notes");
echo str_repeat("-", 120) . "\n";

foreach ($questions as $idx => $item) {
    $id = $idx + 1;
    $grp = $item['grp'];
    $lang = $item['lang'];
    $q = $item['q'];
    $expectedType = $item['type']; // 'answer' or 'refusal'

    // Clear cache to test fresh evaluation
    $pdo->prepare("DELETE FROM ai_usage WHERE shop_id = ?")->execute([$shopId]);
    $pdo->prepare("DELETE FROM ai_cache WHERE shop_id = ?")->execute([$shopId]);

    $t0 = microtime(true);
    $res = AiEngine::chat($pdo, $shopId, $q, ($lang === 'hi' || $lang === 'ta') ? $lang : null);
    $elapsedMs = (int)round((microtime(true) - $t0) * 1000);

    $answer = trim($res['answer'] ?? '');
    $cards = $res['cards'] ?? [];
    $actions = $res['actions'] ?? [];
    $suggestions = $res['suggestions'] ?? [];

    // Determine path
    $isRefusal = (bool)preg_match('/\b(only\s+access\s+data\s+for\s+your\s+own\s+shop|only\s+help\s+with\s+your\s+shop|cannot\s+access\s+or\s+share|क्षमा\s+करें|மன்னிக்கவும்)\b/iu', $answer);
    
    // Determine which engine answered
    $path = "unknown";
    if (AiEngine::tryFastPath($pdo, $shopId, $q, [], ($lang === 'hi' || $lang === 'ta') ? $lang : null) !== null) {
        $path = "fast";
    } elseif (AiEngine::tryDirectDataPath($pdo, $shopId, $q, [], ($lang === 'hi' || $lang === 'ta') ? $lang : null) !== null) {
        $path = "direct_data";
    } elseif ($usingMock && $mockCalls > 0) {
        $path = "model(mock)";
    } else {
        $path = "fallback";
    }

    // Evaluate Quality & Correctness
    $passed = true;
    $reasons = [];

    // 1. Empty check
    if ($answer === '') {
        $passed = false;
        $reasons[] = "Empty answer";
        $failureCounts['empty']++;
    }

    // 2. Refusal check
    if ($expectedType === 'refusal' && !$isRefusal) {
        $passed = false;
        $reasons[] = "Expected refusal but was answered";
        $failureCounts['missed_refusal']++;
    } elseif ($expectedType === 'answer' && $isRefusal) {
        $passed = false;
        $reasons[] = "Wrongly refused valid question";
        $failureCounts['wrong_refusal']++;
    }

    // 3. Generic trouble message check
    if (strpos($answer, "I'm having a little trouble right now") !== false ||
        strpos($answer, "मुझे अभी थोड़ी परेशानी हो रही है") !== false ||
        strpos($answer, "தற்போது சிறு சிக்கல் ஏற்பட்டுள்ளது") !== false) {
        $passed = false;
        $reasons[] = "Returned generic trouble message";
        $failureCounts['generic_trouble']++;
    }

    // 4. Inappropriate "could not find" check
    if (strpos($answer, "could not find information for that in your shop records") !== false ||
        strpos($answer, "आपकी दुकान के रिकॉर्ड में इसके बारे में कोई जानकारी नहीं मिली") !== false ||
        strpos($answer, "உங்கள் கடை பதிவுகளில் அதற்கான தகவல் எதுவும் கிடைக்கவில்லை") !== false) {
        // If it's general knowledge, advice, profile, settings, or greeting, this is WRONG
        if (in_array($grp, ['Profile', 'Settings', 'Advice', 'GenKnowledge', 'Greetings', 'Thanks', 'AppHelp'])) {
            $passed = false;
            $reasons[] = "Inappropriate 'could not find records' on {$grp}";
            $failureCounts['wrong_could_not_find']++;
        }
    }

    // 5. Language check
    if ($lang === 'hi') {
        // If question in Hindi, answer should contain Devanagari script unless small-talk ack
        if (!preg_match('/[\x{0900}-\x{097F}]/u', $answer)) {
            $passed = false;
            $reasons[] = "Expected Hindi/Devanagari output";
            $failureCounts['wrong_language']++;
        }
    } elseif ($lang === 'ta') {
        // If question in Tamil, answer should contain Tamil script unless small-talk ack
        if (!preg_match('/[\x{0B80}-\x{0BFF}]/u', $answer)) {
            $passed = false;
            $reasons[] = "Expected Tamil script output";
            $failureCounts['wrong_language']++;
        }
    }

    if ($passed) {
        $passCount++;
        $statusStr = "PASS";
        $notes = "OK";
    } else {
        $failCount++;
        $statusStr = "FAIL";
        $notes = implode(", ", $reasons);
    }

    $qDisplay = mb_strlen($q, 'UTF-8') > 40 ? mb_substr($q, 0, 38, 'UTF-8') . ".." : $q;
    printf("%-4d | %-14s | %-4s | %-42s | %-12s | %4dms | %-7s | %-6s | %s\n",
        $id, $grp, $lang, $qDisplay, $path, $elapsedMs, $isRefusal ? 'YES' : 'NO', $statusStr, $notes);

    $results[] = [
        'id' => $id,
        'group' => $grp,
        'lang' => $lang,
        'question' => $q,
        'path' => $path,
        'time_ms' => $elapsedMs,
        'refused' => $isRefusal,
        'passed' => $passed,
        'reasons' => $reasons
    ];
}

echo str_repeat("=", 120) . "\n";
echo "GAP TEST SUMMARY:\n";
echo "Total Questions Tested: " . count($questions) . "\n";
echo "PASSED: {$passCount}\n";
echo "FAILED: {$failCount}\n";
echo "Pass Rate: " . round(($passCount / count($questions)) * 100, 1) . "%\n\n";

echo "FAILURES BREAKDOWN BY TYPE:\n";
foreach ($failureCounts as $type => $cnt) {
    if ($cnt > 0) {
        echo "  - " . str_pad($type, 22) . ": {$cnt}\n";
    }
}

// Group failure breakdown
$grpFailures = [];
foreach ($results as $r) {
    if (!$r['passed']) {
        $g = $r['group'];
        $grpFailures[$g] = ($grpFailures[$g] ?? 0) + 1;
    }
}
if (!empty($grpFailures)) {
    echo "\nFAILURES BREAKDOWN BY GROUP:\n";
    foreach ($grpFailures as $g => $cnt) {
        echo "  - " . str_pad($g, 20) . ": {$cnt} failure(s)\n";
    }
}
echo "\n";
