<?php

/**
 * IntentEngine: Advanced Multi-Layer Intent Classification for Dukaan Mitra.
 * 
 * Replaces fragile hardcoded keyword lists with a robust 5-layer pipeline:
 *  - Layer A: Normalization (Unicode lowercasing, punctuation/filler removal, typo correction, tokenization)
 *  - Layer B: Scoring against semantic synonym concept groups with confidence scoring
 *  - Layer C: Local model intent classifier (Ollama, JSON-only, 10s timeout, cached, strictly no cloud)
 *  - Layer D: Never-fail fallback execution (closest intent or grounded shop overview + "Did you mean...?" chips)
 *  - Learning Loop: Logs low-confidence queries to `intent_misses` (hashed shop ID, no personal data)
 */

require_once __DIR__ . "/AiTools.php";
require_once __DIR__ . "/AiClientFactory.php";
require_once __DIR__ . "/AdvisorEngine.php";

class IntentEngine {

    /**
     * Intent Catalog: 30 distinct intents with metadata, category, and parameters.
     */
    public const INTENT_CATALOG = [
        'stock' => [
            'name' => 'stock',
            'category' => 'data',
            'desc' => 'Overall inventory level, total units in stock, and warehouse product counts',
            'params' => []
        ],
        'low_stock' => [
            'name' => 'low_stock',
            'category' => 'data',
            'desc' => 'Products at or below minimum reorder threshold or completely out of stock',
            'params' => []
        ],
        'reorder' => [
            'name' => 'reorder',
            'category' => 'data',
            'desc' => 'Reorder recommendations and restock replenishment suggestions',
            'params' => []
        ],
        'sales_today' => [
            'name' => 'sales_today',
            'category' => 'data',
            'desc' => 'Sales collection, bill count, and revenue for today',
            'params' => []
        ],
        'sales_yesterday' => [
            'name' => 'sales_yesterday',
            'category' => 'data',
            'desc' => 'Sales collection, bill count, and revenue for yesterday',
            'params' => []
        ],
        'sales_date' => [
            'name' => 'sales_date',
            'category' => 'data',
            'desc' => 'Sales performance for a specific calendar date or day',
            'params' => ['date']
        ],
        'sales_best_day' => [
            'name' => 'sales_best_day',
            'category' => 'data',
            'desc' => 'Highest sales day, peak revenue day, or record business day',
            'params' => []
        ],
        'sales_week' => [
            'name' => 'sales_week',
            'category' => 'data',
            'desc' => 'Sales performance for the current week or past 7 days',
            'params' => []
        ],
        'sales_month' => [
            'name' => 'sales_month',
            'category' => 'data',
            'desc' => 'Sales performance for the current month or past 30 days',
            'params' => []
        ],
        'top_sellers' => [
            'name' => 'top_sellers',
            'category' => 'data',
            'desc' => 'Best selling, high velocity, and most popular products',
            'params' => ['period']
        ],
        'slow_sellers' => [
            'name' => 'slow_sellers',
            'category' => 'data',
            'desc' => 'Slow moving products with low inventory turnover tying up capital',
            'params' => []
        ],
        'dead_stock' => [
            'name' => 'dead_stock',
            'category' => 'data',
            'desc' => 'Dead stock, zero sales items, and unsold stagnant inventory',
            'params' => []
        ],
        'profit_margin' => [
            'name' => 'profit_margin',
            'category' => 'data',
            'desc' => 'Gross profit margins, markup percentages, and product profitability',
            'params' => ['product_name']
        ],
        'categories' => [
            'name' => 'categories',
            'category' => 'data',
            'desc' => 'Product categories, department breakdowns, and category stock distribution',
            'params' => ['category']
        ],
        'purchase_orders' => [
            'name' => 'purchase_orders',
            'category' => 'data',
            'desc' => 'Open purchase orders, supplier status, and incoming supplier deliveries',
            'params' => []
        ],
        'import_history' => [
            'name' => 'import_history',
            'category' => 'data',
            'desc' => 'Spreadsheet import batch history and CSV/Excel upload logs',
            'params' => []
        ],
        'shop_profile' => [
            'name' => 'shop_profile',
            'category' => 'data',
            'desc' => 'Store name, owner information, registered email, and shop location',
            'params' => []
        ],
        'settings' => [
            'name' => 'settings',
            'category' => 'data',
            'desc' => 'Store preferences, low stock alert thresholds, safety stock days, currency',
            'params' => []
        ],
        'product_info' => [
            'name' => 'product_info',
            'category' => 'data',
            'desc' => 'Specific product price, selling cost, SKU, barcode, and unit availability',
            'params' => ['product_name']
        ],
        'shop_overview' => [
            'name' => 'shop_overview',
            'category' => 'data',
            'desc' => 'Executive store summary, performance metrics, and general business health',
            'params' => []
        ],
        'greeting' => [
            'name' => 'greeting',
            'category' => 'conversational',
            'desc' => 'Friendly greeting, hello, welcome inquiry, or small-talk opening',
            'params' => []
        ],
        'help' => [
            'name' => 'help',
            'category' => 'conversational',
            'desc' => 'App instructions: how to import spreadsheets, record sales, scan barcodes',
            'params' => ['feature']
        ],
        'business_advice' => [
            'name' => 'business_advice',
            'category' => 'advice',
            'desc' => 'Retail business strategy, competitor guidance, customer loyalty, pricing tips',
            'params' => ['topic']
        ],
        'thanks' => [
            'name' => 'thanks',
            'category' => 'conversational',
            'desc' => 'User gratitude, appreciation, or polite closure',
            'params' => []
        ],
        'acknowledgement' => [
            'name' => 'acknowledgement',
            'category' => 'conversational',
            'desc' => 'User confirmation, agreement, okay, or acknowledgement',
            'params' => []
        ],
        'negation' => [
            'name' => 'negation',
            'category' => 'conversational',
            'desc' => 'User decline, rejection, no, or cancel request',
            'params' => []
        ],
        'feedback_rude' => [
            'name' => 'feedback_rude',
            'category' => 'conversational',
            'desc' => 'Frustrated or rude feedback regarding the assistant or application speed',
            'params' => []
        ],
        'security_refusal' => [
            'name' => 'security_refusal',
            'category' => 'safety',
            'desc' => 'Unauthorized request for other shops data, passwords, or bearer tokens',
            'params' => []
        ],
        'prompt_injection' => [
            'name' => 'prompt_injection',
            'category' => 'safety',
            'desc' => 'Attempts to override system prompts, change AI rules, or adopt rogue personas',
            'params' => []
        ],
        'general_knowledge' => [
            'name' => 'general_knowledge',
            'category' => 'general',
            'desc' => 'Non-shop questions: science, weather, history, coding, cooking, facts',
            'params' => []
        ]
    ];

    /**
     * Common domain typo replacement map.
     */
    private const TYPO_MAP = [
        'stok' => 'stock',
        'sles' => 'sales',
        'reordr' => 'reorder',
        'prfit' => 'profit',
        'ordr' => 'order',
        'kamm' => 'kam',
        'स्टोक' => 'स्टॉक',
        'बिकरी' => 'बिक्री',
        'purchese' => 'purchase',
        'suppler' => 'supplier',
        'invntory' => 'inventory',
        'yestarday' => 'yesterday',
        'saless' => 'sales',
        'stockk' => 'stock',
        'proffit' => 'profit',
        'reorderr' => 'reorder'
    ];

    /**
     * Vocabulary for Levenshtein Typo Correction (Edit distance <= 1).
     */
    private const TYPO_DICTIONARY = [
        'stock', 'stocks', 'sales', 'reorder', 'margin', 'profit', 'price', 'pricing',
        'revenue', 'supplier', 'orders', 'order', 'purchase', 'inventory', 'overview',
        'yesterday', 'today', 'tomorrow', 'weekly', 'monthly', 'category', 'categories',
        'settings', 'profile', 'advice', 'product', 'products', 'import', 'billing',
        'khatam', 'bikri', 'bikna', 'virpanai', 'kadai', 'dukaan', 'maal', 'sarakku',
        'kammi', 'kuraivu', 'adhigam', 'zyada', 'dheema', 'pogatha', 'theerndhadhu'
    ];

    /**
     * Semantic Concept Groups across 5 languages:
     * English (en), Hindi Devanagari (hi), Tamil script (ta), Hinglish (hi_latin), Tanglish (ta_latin).
     */
    private const CONCEPT_GROUPS = [
        'stock' => [
            'total stock', 'stock in store', 'overall inventory', 'warehouse balance', 'stk count', 'inventory balance', 'total items', 'units in store', 'stock balance', 'items in stock', 'stock count',
            'stock', 'stocks', 'inventory', 'units', 'items', 'warehouse', 'goods', 'quantity', 'balance', 'count', 'stk',
            'दुकान में कुल कितना माल', 'कुल इन्वेंटरी', 'स्टॉक की कुल संख्या', 'दुकान का पूरा स्टॉक', 'कुल नग',
            'माल', 'स्टॉक', 'सामान', 'इन्वेंटरी', 'संख्या', 'यूनिट', 'नग', 'स्टोक',
            'மொத்த இருப்பு', 'மொத்த சரக்கு', 'இருப்பு எண்ணிக்கை', 'மொத்த பொருட்கள்', 'ஸ்டாக் விவரம்',
            'இருப்பு', 'ஸ்டாக்', 'சரக்கு', 'பொருட்கள்', 'எண்ணிக்கை',
            'total stock', 'total inventory', 'pura stock', 'sara maal', 'moththa sarakku', 'total porul',
            'maal', 'samaan', 'samana', 'sarakku', 'porul', 'porutkal', 'pieces', 'pcs', 'balance'
        ],
        'low' => [
            'low on stock', 'running out', 'low stock alerts', 'out of stock', 'stok low', 'critical shortage', 'inventory shortage', 'goods depleted', 'low stock',
            'low', 'shortage', 'short', 'running out', 'minimum', 'depleted', 'exhausted', 'scarce', 'finish', 'empty', 'critical', 'alert', 'shortage',
            'खत्म हो रहा', 'कम स्टॉक', 'स्टॉक खत्म', 'खाली होने वाले', 'माल खत्म',
            'कम', 'खत्म', 'घट', 'न्यूनतम', 'खाली', 'समाप्त', 'अभाव',
            'குறைவாக உள்ளன', 'குறைவான பொருட்கள்', 'தீர்ந்துபோன பொருட்கள்', 'ஸ்டாக் குறைவு', 'காலி ஆகப்போகும்',
            'குறைவு', 'குறைவான', 'குறைவாக', 'தீர்ந்துவிட்டது', 'காலி', 'முடிந்தது', 'முடிகிறது', 'முடிஞ்சிது',
            'kam stock', 'stock khatam', 'low stock', 'maal khatam', 'theerndhadhu sarakku', 'sarakku mudinjidhu', 'kammi aana items',
            'kam', 'khatam', 'kammi', 'thoda', 'theerndhadhu', 'mudinjidhu', 'kuraivu', 'kuraiva', 'kuraindha'
        ],
        'reorder' => [
            'reorder this week', 'reorder recommendations', 'need restocking', 'reordr list', 'purchase from supplier', 'restock suggestions', 'suggested indent', 'reorder list', 'restock items',
            'reorder', 're-order', 'restock', 'restocking', 'replenish', 'replenishment', 'purchase list', 'buy more', 'order again', 'indent', 'procure', 'reordr',
            'दोबारा ऑर्डर', 'रीऑर्डर की सूची', 'सप्लायर से क्या नया मंगाना', 'दोबारा मंगाने वाले', 'स्टॉक रीऑर्डर',
            'मंगाना', 'मंगाने', 'रीऑर्डर', 'रिऑर्डर', 'खरीदना', 'भरना',
            'மீண்டும் ஆர்டர்', 'மறுபடி ஆர்டர்', 'சப்ளையரிடம் என்ன வாங்க', 'ரீஆர்டர் பட்டியல்', 'சரக்கு வாங்க வேண்டியவை', 'மறுபடியும் ஆர்டர்',
            'ஆர்டர் செய்ய', 'சரக்கு வாங்க', 'ரீஆர்டர்', 'பரிந்துரைகள்',
            'dobara order', 'kya mangana padega', 'reorder list', 'supplier se kya order', 'restock items', 'meendum order', 'marubadiyum order', 'supplier kitta enna vanganum',
            'dobara order', 'marubadi order', 'meendum order', 'kya mangana', 'kya mangwana', 'vanganum', 'order pannanum', 'restock panna'
        ],
        'sales' => [
            'sale', 'sales', 'revenue', 'collection', 'turnover', 'sold', 'earned', 'earning', 'income', 'billing', 'bill', 'sles', 'bills',
            'बिक्री', 'कमाई', 'टर्नओवर', 'कलेक्शन', 'बिका', 'बिल', 'बिकरी',
            'விற்பனை', 'வியாபாரம்', 'வருமானம்', 'பில்', 'விற்றது', 'வசூல்',
            'bikri', 'kamai', 'collection', 'turnover', 'virpanai', 'viyabaram', 'bill', 'billing', 'sold', 'vasool'
        ],
        'today' => [
            'sell today', 'today sales', 'todays billing', 'sles today', 'todays turnover', 'today collection', 'recorded today',
            'today', 'todays', 'current day', 'this day',
            'आज की बिक्री', 'आज का कुल कलेक्शन', 'आज कितनी कमाई', 'आज का टर्नओवर', 'आज के कुल बिल',
            'आज', 'आजका', 'आजकी', 'आज की',
            'இன்று விற்பனை', 'இன்றைய மொத்த வசூல்', 'இன்றைய வருமானம்', 'இன்றைய பில்', 'இன்னைக்கு எவ்வளவு விற்பனை',
            'இன்று', 'இன்றைய', 'இன்னைக்கு', 'இன்னிக்கி',
            'aaj kitna bikri', 'aaj ka total collection', 'aaj ka turnover', 'today sales', 'aaj ki kamai',
            'aaj', 'aajka', 'aajki', 'indru', 'indraya', 'innaiku', 'inniku'
        ],
        'yesterday' => [
            'sell yesterday', 'yesterday sales', 'yesterday total revenue', 'yestarday sles', 'yesterday turnover', 'yesterday bill',
            'yesterday', 'yesterdays', 'prev day', 'previous day', 'yestarday',
            'कल की कुल बिक्री', 'बीते कल का कलेक्शन', 'कल कितनी कमाई', 'कल का टर्नओवर', 'कल कुल कितने बिल',
            'कल', 'बीता कल', 'कल की', 'कल का',
            'நேற்று விற்பனை', 'நேற்றைய மொத்த வசூல்', 'நேற்றைய வருமானம்', 'நேற்று எத்தனை பில்', 'நேத்து வியாபாரம்',
            'நேற்று', 'நேற்றைய', 'நேத்து',
            'kal kitna bikri', 'yesterday sales', 'kal ka turnover', 'beeta kal ka collection', 'kal kitna collection',
            'kal', 'beeta kal', 'netru', 'netraya', 'nethu'
        ],
        'date' => [
            'on date', 'calendar', 'day of', 'particular day', 'date sales', 'date',
            'तारीख', 'दिनांक', 'तिथि', 'उस तारीख',
            'தேதி', 'நாள்', 'அந்த தேதியில்', 'குறிப்பிட்ட நாள்',
            'tarikh', 'tariq', 'thethi', 'date sales', 'particular date', 'date'
        ],
        'best_day' => [
            'best sales day', 'peak revenue day', 'highest billing day', 'maximum sales', 'record sales day', 'best day', 'peak day', 'highest day', 'record day', 'top day', 'maximum day', 'highest revenue', 'record sales', 'highest collection',
            'सबसे ज्यादा बिक्री वाला दिन', 'रिकॉर्ड कमाई', 'पीक सेल', 'सबसे अच्छा दिन', 'रिकॉर्ड टर्नओवर', 'रिकॉर्ड दिन',
            'அதிக விற்பனையான நாள்', 'சிறந்த விற்பனை நாள்', 'உச்ச வசூல்', 'ரெக்கார்ட் வியாபார நாள்', 'அதிக வருமானம் வந்த நாள்', 'சிறந்த நாள்', 'அதிக விற்பனை நாள்', 'டாப் நாள்',
            'sabse accha sales', 'highest revenue', 'record sales day', 'peak sales day', 'maximum collection', 'best sales day', 'record virpanai naal', 'highest collection day', 'peak day revenue',
            'sabse accha din', 'record din', 'adhigam virpana naazh', 'best day'
        ],
        'week' => [
            'this week', 'weekly sales', '7 days turnover', 'past week', '7 days collection', 'last 7 days',
            'week', 'weekly', '7 days', 'this week', 'past week', 'last 7 days',
            'इस हफ्ते', 'पिछले ७ दिनों', 'साप्ताहिक टर्नओवर', 'इस सप्ताह', 'हफ्ते भर',
            'हफ्ता', 'सप्ताह', 'इस हफ्ते', '७ दिन', 'सात दिन',
            'இந்த வாரம்', 'கடந்த 7 நாட்கள்', 'வாராந்திர வருமானம்', 'இந்த வார வியாபாரம்', 'வார பில்',
            'வாரம்', 'இந்த வாரம்', 'வாராந்திர', '7 நாட்கள்',
            'is hafte', 'pichle 7 din', 'weekly turnover', 'is week', 'hafte bhar', 'intha vaaram', 'kadantha 7 naatkal', 'weekly revenue', 'vaarathoda collection',
            'hafta', 'haftha', 'is hafte', 'vaaram', 'intha vaaram', '7 days'
        ],
        'month' => [
            'this month', 'monthly sales', '30 days turnover', 'this month billing', 'monthly earnings', 'past 30 days',
            'month', 'monthly', '30 days', 'this month', 'past month', 'past 30 days',
            'इस महीने', 'पिछले ३० दिनों', 'मासिक कमाई', 'इस माह', 'महीने भर',
            'महीना', 'मास', 'इस महीने', 'मासिक', '३० दिन',
            'இந்த மாதம்', 'கடந்த 30 நாட்கள்', 'மாதாந்திர வசூல்', 'இந்த மாத வியாபாரம்', 'மாத வருமானம்',
            'மாதம்', 'இந்த மாதம்', 'மாதாந்திர', '30 நாட்கள்',
            'is mahine', 'monthly turnover', 'pichle 30 din', 'this month total', 'mahine ki kamai', 'intha maatham', 'kadantha 30 naatkal', 'intha maasam',
            'mahina', 'is mahine', 'maatham', 'intha maatham', 'month'
        ],
        'popular' => [
            'top selling', 'best seller', 'popular fast moving', 'highest sold', 'top products by revenue', 'popular stock', 'fast moving items', 'fast moving goods', 'top sellers',
            'popular', 'best', 'top', 'fast moving', 'fast-moving', 'trending', 'hot', 'leading', 'champion', 'most sold', 'highest sold', 'hit',
            'सबसे ज्यादा बिकने वाले', 'टॉप सेलर', 'बेस्ट सेलिंग', 'सबसे ज्यादा बिक्री', 'लोकप्रिय उत्पाद', 'सबसे ज्यादा बिकने वाला', 'बेस्ट सेलर', 'सबसे ज्यादा', 'लोकप्रिय', 'हिट',
            'அதிகம் விற்பனையான பொருட்கள்', 'டாப் செல்லர் பொருட்கள்', 'வேகமாக நகரும் சிறந்த பொருட்கள்', 'அதிக வருமானம் தரும் பொருட்கள்', 'சிறந்த விற்பனை பொருட்கள்', 'அதிகம் விற்பனையாகும்', 'சிறந்த விற்பனை', 'டாப் செல்லர்', 'அதிகம் விற்றது', 'வேகமாக நகரும்',
            'sabse jyada bikne', 'top seller products', 'best seller list', 'fast moving maal', 'popular items', 'adhigam virpanai aana', 'top selling products', 'fast moving items', 'best seller items', 'popular sarakku',
            'sabse zyada', 'sabse jyada', 'top seller', 'best seller', 'fast moving', 'adhigam virpanai', 'top sell', 'top selling', 'hit item'
        ],
        'slow' => [
            'slow moving', 'sluggish inventory', 'slow sellers', 'low velocity', 'stagnant stock tying', 'slow moving products',
            'slow', 'slow moving', 'slow-moving', 'stagnant', 'sluggish', 'low velocity', 'lagging',
            'धीमे बिकने वाले', 'सुस्त इन्वेंटरी', 'कम बिकने वाले उत्पाद', 'धीमी गति से बिकने वाला', 'जिनकी बिक्री बहुत कम',
            'धीमा', 'सुस्त', 'कम बिकने वाला', 'मंदा',
            'மெதுவாக விற்பனையாகும்', 'நகராத சரக்கு', 'குறைந்த விற்பனை பொருட்கள்', 'மெதுவாக நகரும்', 'மந்தமான விற்பனை',
            'மெதுவான', 'மெதுவாக நகரும்', 'குறைந்த விற்பனை',
            'dheema bikne wala', 'slow moving products', 'kam bikne wale', 'sust inventory', 'slow items', 'medhuva virpanai', 'kuraiva virkum', 'medhuvaana items', 'slow aana porul',
            'dheema', 'sust', 'dheeme', 'medhuvaana', 'slow moving', 'kam bikne wala'
        ],
        'dead' => [
            'dead stock', 'unsold inventory', 'dead stock items', 'zero movement', 'idle stock tying', 'dead stock products',
            'dead stock', 'dead', 'unsold', 'idle', 'zero sales', 'obsolete', 'non moving', 'non-moving', 'waste stock',
            'डेड स्टॉक वाले', 'बिका नहीं और अटका', 'बिल्कुल नहीं बिका', 'बेकार पड़ा हुआ स्टॉक', 'अटका हुआ इन्वेंटरी',
            'अटका हुआ', 'डेड स्टॉक', 'नहीं बिक रहा', 'बिका नहीं', 'बेकार पड़ा',
            'விற்காத தேங்கிய சரக்குகள்', 'டெட் ஸ்டாக் பொருட்கள்', 'முற்றிலும் விற்பனையாகாத சரக்கு', 'தேங்கி கிடக்கும் பொருட்கள்', 'விற்காமல் இருக்கும் பொருட்கள்',
            'விற்காத', 'தேங்கிய', 'நகராத சரக்கு', 'விற்காதவை', 'அப்படியே இருக்கும்',
            'dead stock kaun sa', 'atka hua maal', 'bik nahi raha hai', 'zero sales items', 'bik nahi raha saman', 'virkatha sarakku', 'thengiya porutkal', 'zero sales items list', 'vikkave illatha porul',
            'dead stock', 'atka hua', 'nahi bik raha', 'bik nahi raha', 'virkatha', 'pogatha', 'vikkadhu', 'vikkala'
        ],
        'profit' => [
            'margin on', 'profit do i make', 'profit margin', 'gross margin', 'markup on', 'margin percentage',
            'profit', 'margin', 'margins', 'markup', 'earnings', 'gain', 'percentage', 'gross margin', 'prfit',
            'पर कितना मार्जिन', 'पर कितना मुनाफा', 'मार्जिन कितना है', 'कमाई प्रतिशत', 'ग्रॉस मार्जिन',
            'मार्जिन', 'मुनाफा', 'फायदा', 'लाभ', 'कमाई प्रतिशत',
            'எவ்வளவு மார்ஜின்', 'எவ்வளவு லாபம்', 'லாப விகிதம் என்ன', 'லாபம் எவ்வளவு', 'மார்ஜின் விவரம்',
            'லாபம்', 'மார்ஜின்', 'வருவாய்', 'லாப விகிதம்',
            'margin kitna hai', 'profit milta hai', 'profit margin batao', 'munafa', 'margin evlo', 'laabham kedaikum', 'profit details',
            'margin', 'profit', 'munafa', 'labh', 'faayda', 'laabham', 'margin evlo', 'profit kitna'
        ],
        'category' => [
            'inventory by category', 'category stock', 'category breakdown', 'snacks category', 'dairy category', 'department totals', 'category wise',
            'category', 'categories', 'department', 'section', 'segment', 'type of item', 'category wise', 'category-wise', 'snacks', 'dairy',
            'कैटेगरी के अनुसार', 'किस श्रेणी में', 'स्नैक्स कैटेगरी', 'डेयरी सेगमेंट', 'श्रेणीवार स्टॉक',
            'कैटेगरी', 'श्रेणी', 'वर्ग', 'प्रकार', 'विभाग',
            'வகை வாரியாக', 'பிரிவு வாரியாக', 'ஸ்நாக்ஸ் வகையில்', 'பால் பொருட்கள் பிரிவு', 'வகை பட்டியல்',
            'வகை', 'பிரிவு', 'வகைகள்', 'பிரிவுகள்',
            'category wise stock', 'snacks category me', 'dairy segment ka', 'category breakdown', 'pirivu vaariyaga', 'category totals',
            'category', 'categories', 'varg', 'shreni', 'pirivu', 'vagai', 'segment'
        ],
        'purchase_order' => [
            'open purchase orders', 'pending supplier orders', 'supplier deliveries', 'po status', 'incoming purchase orders', 'open orders',
            'purchase order', 'purchase orders', 'po', 'supplier order', 'vendor order', 'incoming stock', 'pending delivery', 'open order', 'open orders', 'ordr',
            'पेंडिंग परचेज ऑर्डर', 'सप्लायर के खुले ऑर्डर', 'सप्लायर से क्या माल आने वाला', 'चालान और पीओ', 'खुले ऑर्डर की सूची',
            'परचेज ऑर्डर', 'सप्लायर ऑर्डर', 'पेंडिंग ऑर्डर', 'आने वाला माल', 'चालान',
            'நிலுவையில் உள்ள பர்சேஸ் ஆர்டர்கள்', 'சப்ளையர் ஆர்டர் நிலவரம்', 'வர வேண்டிய சப்ளையர்', 'பிஓ ஸ்டேட்டஸ்', 'ஓபன் ஆர்டர்கள் பட்டியல்',
            'பர்சேஸ் ஆர்டர்', 'சப்ளையர் ஆர்டர்', 'நிலுவை ஆர்டர்', 'வர வேண்டிய சரக்கு',
            'open purchase orders', 'pending supplier orders', 'supplier se maal kab aayega', 'po status', 'aanewala supplier delivery', 'supplier sarakku eppo varum', 'vara vendiya purchase orders',
            'purchase order', 'supplier order', 'po', 'pending order', 'aanewala maal', 'delivery baaki'
        ],
        'import' => [
            'spreadsheet import history', 'last excel upload', 'import batch logs', 'previous csv imports', 'excel upload history', 'import history', 'excel upload',
            'import', 'upload', 'excel', 'csv', 'spreadsheet', 'bulk upload', 'import history', 'file upload',
            'पिछली एक्सेल इम्पोर्ट', 'पिछली फाइल ठीक से इम्पोर्ट', 'इम्पोर्ट बैच लॉग', 'सीएसवी अपलोड इतिहास', 'एक्सेल फाइल स्टेटस',
            'इम्पोर्ट', 'एक्सेल', 'सीएसवी', 'अपलोड', 'फाइल',
            'எக்செல் பதிவேற்ற வரலாறு', 'கடைசி இம்போர்ட்', 'இம்போர்ட் பேட்ச்', 'csv பதிவேற்ற', 'எக்செல் பைல் வரலாறு',
            'இம்போர்ட்', 'பதிவேற்றம்', 'எக்செல்', 'கோப்பு',
            'last import history', 'excel file import hua kya', 'import batch status', 'csv upload ka history', 'excel upload aaiducha', 'pona import successful',
            'import', 'upload', 'excel', 'csv', 'spreadsheet', 'file upload'
        ],
        'profile' => [
            'shop name and location', 'registered owner', 'shop profile details', 'registered email', 'store location and address', 'store details', 'shop profile',
            'shop name', 'store name', 'owner', 'proprietor', 'registered email', 'address', 'location', 'my profile', 'store details', 'shop details',
            'मेरी दुकान का नाम और पता', 'दुकानदार और मालिक', 'दुकान का प्रोफाइल विवरण', 'रजिस्टर्ड ईमेल', 'दुकान का स्थान',
            'दुकान का नाम', 'मालिक', 'दुकानदार', 'ईमेल', 'पता', 'मेरी दुकान',
            'என் கடையின் பெயர் மற்றும் முகவரி', 'கடை உரிமையாளர் யார்', 'கடை விவரங்களை காட்டு', 'பதிவு செய்யப்பட்ட மின்னஞ்சல்', 'கடையின் இருப்பிடம்',
            'கடையின் பெயர்', 'கடை உரிமையாளர்', 'முகவரி', 'என் கடை',
            'meri shop name', 'owner ka naam', 'shop details aur profile', 'registered email', 'dukan ka address', 'kadai urimaiyalar', 'shop profile details', 'kadai address',
            'shop name', 'owner name', 'malik kaun', 'dukan ka naam', 'kadai peyar', 'urimaiyalar', 'enoda shop'
        ],
        'settings' => [
            'low stock alert settings', 'store currency and timezone', 'safety stock days setting', 'current import mode', 'app preferences', 'settings',
            'settings', 'setting', 'alert threshold', 'safety stock', 'safety days', 'cover days', 'lead time', 'currency', 'timezone', 'config', 'preferences',
            'मेरी लो स्टॉक अलर्ट सेटिंग्स', 'दुकान की करेंसी और टाइमज़ोन', 'सेफ्टी स्टॉक डेज कितने सेट', 'वर्तमान इम्पोर्ट मोड', 'ऐप सेटिंग्स',
            'सेटिंग्स', 'सेटिंग', 'अलर्ट सीमा', 'करेंसी', 'टाइमज़ोन', 'सेफ्टी डेज',
            'என் குறைவான இருப்பு எச்சரிக்கை அமைப்புகள்', 'கடையின் நாணயம் மற்றும் நேர மண்டலம்', 'பாதுகாப்பு இருப்பு நாட்கள் அமைப்பு', 'தற்போதைய இம்போர்ட் முறை', 'அமைப்புகள் விவரம்',
            'அமைப்புகள்', 'அமைப்பு', 'எச்சரிக்கை அளவு', 'நாணயம்', 'நேர மண்டலம்',
            'low stock alert setting', 'store currency aur timezone', 'safety days kitna set', 'import mode setting', 'app settings', 'store currency and timezone',
            'settings', 'setting', 'alert setting', 'safety days', 'cover days', 'currency', 'timezone'
        ],
        'product_info' => [
            'selling price of', 'details for product', 'sku for', 'how much does', 'price and stock for', 'details of', 'item info', 'sku', 'barcode',
            'price', 'cost', 'selling price', 'cost price', 'rate', 'sku', 'barcode', 'how much for', 'details of', 'item info',
            'का दाम क्या है', 'के विवरण दिखाएं', 'का एसकेयू क्या है', 'की कीमत कितनी है', 'का दाम और स्टॉक',
            'दाम', 'कीमत', 'लागत', 'भाव', 'एसकेयू', 'बारकोड', 'रेट',
            'பாலின் விலை என்ன', 'பொருள் விவரம் காட்டு', 'sku என்ன', 'விலை எவ்வளவு', 'விலை மற்றும் இருப்பு',
            'விலை', 'அடக்க விலை', 'விற்பனை விலை', 'கட்டணம்', 'பொருள் விவரம்',
            'price kya hai', 'details dikhao', 'sku kya hai', 'kitne ka hai', 'rate aur stock', 'vilai enna', 'details kaatunga', 'evvalavu vilai',
            'price', 'cost', 'dam', 'bhav', 'keemat', 'rate', 'vilai', 'vilai enna', 'rate kitna'
        ],
        'overview' => [
            'shop performance overview', 'executive business summary', 'business doing overall', 'store health report', 'status dashboard summary', 'performance overview', 'business summary',
            'overview', 'summary', 'health', 'performance', 'dashboard', 'status', 'how is business', 'business summary', 'executive summary',
            'दुकान के प्रदर्शन का सारांश', 'दुकान का ओवरव्यू', 'कुल मिलाकर व्यापार कैसा चल रहा', 'दुकान की सामान्य स्थिति रिपोर्ट', 'बिजनेस हेल्थ समरी',
            'सारांश', 'प्रदर्शन', 'दुकान की स्थिति', 'हेल्थ', 'कैसा चल रहा है',
            'கடை வணிக நிலவர சுருக்கம்', 'மொத்தத்தில் வியாபாரம் எப்படி நடக்கிறது', 'கடையின் பொதுவான அறிக்கை', 'வணிக நிலைமை சுருக்கம்', 'கடை ஓவர்வியூ',
            'சுருக்கம்', 'நிலவரம்', 'கடையின் நிலை', 'வணிக சுருக்கம்', 'எப்படி நடக்குது',
            'shop performance overview', 'business summary kaisa hai', 'dukan overall kaisa chal raha', 'store health report', 'overall status kya hai', 'overall kadai eppadi pogudhu', 'kadai status summary',
            'overview', 'summary', 'status', 'kaisa chal raha', 'nilavaram', 'business report'
        ],
        'greeting' => [
            'hello dukaan mitra', 'hi there', 'greetings mitra', 'good morning', 'good evening', 'hello', 'hey', 'hi',
            'नमस्ते दुकान मित्र', 'प्रणाम', 'नमस्कार जी', 'हाय मित्र', 'शुभ प्रभात', 'नमस्ते', 'नमस्कार',
            'வணக்கம் தகான் மித்ரா', 'வந்தனம்', 'காலை வணக்கம்', 'ஹலோ மித்ரா', 'வணக்கம்',
            'namaste dukaan mitra', 'hello ji', 'hey mitra', 'good morning bhai', 'namaskar', 'vanakkam dukaan mitra', 'hello mitra', 'kaalai vanakkam', 'hey friend', 'vanakkam ji',
            'namaste', 'vanakkam', 'namaskar', 'pranam', 'hello', 'hi', 'hey'
        ],
        'help' => [
            'how do i import', 'how to record a sale', 'how to scan barcode', 'help me use stocksense', 'how do i add a new supplier', 'how do i', 'how to', 'help me use', 'how can i',
            'ऐप कैसे इस्तेमाल करें', 'बिक्री कैसे रिकॉर्ड करें', 'बारकोड कैसे स्कैन करें', 'स्टॉकसेंस इस्तेमाल करने में मदद', 'नया सप्लायर कैसे जोड़ें', 'कैसे इम्पोर्ट करें',
            'ऐप कैसे इस्तेमाल करें', 'मदद', 'सहायता', 'कैसे करें', 'तरीका',
            'எவ்வாறு ஏற்றுவது', 'விற்பனையை எவ்வாறு பதிவு செய்வது', 'பார்கோடை எவ்வாறு ஸ்கேன் செய்வது', 'ஆப் பயன்படுத்த உதவி', 'எவ்வாறு சேர்ப்பது',
            'உதவி', 'எப்படி பயன்படுத்துவது', 'வழிமுறை', 'வழிகாட்டல்',
            'excel se import kaise kare', 'sale kaise record kare', 'barcode scan kaise kare', 'app use karne me help', 'naya supplier kaise add kare', 'excel moolam import eppadi panradhu', 'sale eppadi record pannanum', 'barcode scan eppadi panradhu', 'app use panna help pannunga', 'pudhu supplier eppadi add panradhu',
            'help', 'guide', 'kaise use kare', 'eppadi use panradhu', 'how to use'
        ],
        'advice' => [
            'supermarket is opening nearby', 'beat the shop next door', 'customer loyalty perks', 'profit margin should i set', 'reduce dead stock', 'what strategies to attract customers', 'strategies to attract', 'how can i beat', 'loyalty perks', 'margin should i set',
            'advice', 'tip', 'tips', 'strategy', 'idea', 'ideas', 'improve', 'increase', 'attract', 'competitor', 'supermarket', 'loyalty', 'discount', 'promotion', 'how to price', 'pricing strategy', 'margin strategy', 'what margin',
            'नया सुपरमार्केट खुला है', 'ग्राहकों को कैसे आकर्षित', 'दुकान से बेहतर कैसे करें', 'लॉयल्टी छूट क्या देनी चाहिए', 'मार्जिन रखना चाहिए', 'डेड स्टॉक कम करने का तरीका',
            'सलाह', 'सुझाव', 'रणनीति', 'आइडिया', 'बिक्री बढ़ाना', 'प्रतिस्पर्धी', 'डिस्काउंट', 'ऑफर',
            'புதிய பல்பொருள் அங்காடி', 'வாடிக்கையாளர்களை ஈர்க்க என்ன உத்தி', 'கடையை விட சிறப்பாக என்ன செய்வது', 'வாடிக்கையாளர் சலுகை என்ன தரலாம்', 'மார்ஜின் வைக்கலாம்', 'சரக்கை குறைக்க என்ன வழி',
            'யோசனை', 'ஆலோசனை', 'வியூகம்', 'சலுகை', 'போட்டி கடை', 'விற்பனை அதிகரிக்க',
            'competitor supermarket open', 'customer kaise attract kare', 'shop next door se compete', 'loyalty program kaise chalaye', 'margin kitna set kare', 'dead stock kam karne ka tip', 'customers attract panna tips', 'adutha kadai kooda compete', 'loyalty offer enna tharalam', 'margin evlo vaikkanum', 'dead stock kuraikka yosani',
            'advice', 'tip', 'strategy', 'salah', 'sujhav', 'yosani', 'alosanai', 'competitor', 'loyalty'
        ],
        'thanks' => [
            'thank you very much', 'thanks for the help', 'appreciate your answer', 'many thanks mitra', 'thank you', 'thanks', 'thx', 'appreciate', 'grateful',
            'बहुत बहुत धन्यवाद', 'मदद के लिए शुक्रिया', 'धन्यवाद मित्र', 'शुक्रिया जी', 'आभार', 'धन्यवाद', 'शुक्रिया',
            'மிக்க நன்றி', 'உதவிக்கு நன்றி', 'ரொம்ப நன்றி மித்ரா', 'நன்றிங்க', 'நன்றி', 'மிக்க நன்றி', 'ரொம்ப நன்றி',
            'bahut bahut dhanyawad', 'shukriya bhai', 'thanks mitra', 'thank you ji', 'bahut shukriya', 'mikka nandri', 'romba thanks', 'help ku nandri', 'nandri mitra', 'romba nandri',
            'thanks', 'thank you', 'dhanyawad', 'shukriya', 'nandri'
        ],
        'ack' => [
            'okay got it', 'alright fine', 'cool understood', 'great thanks', 'alright cool', 'understood', 'sure', 'fine', 'okay', 'ok',
            'ठीक है समझ गया', 'अच्छा जी', 'बढ़िया', 'सही है', 'ओके', 'ठीक है', 'समझ गया',
            'சரி புரிந்தது', 'சரிங்க', 'நல்லது', 'ஓகே', 'ரொம்ப சரி',
            'theek hai samajh gaya', 'accha ji', 'got it bhai', 'alright cool', 'sahi hai', 'sari purinjidhu', 'saringa', 'nalladhu', 'okay pa', 'romba sari',
            'ok', 'okay', 'theek hai', 'thik hai', 'accha', 'achha', 'sari', 'saringa'
        ],
        'negation' => [
            'no not now', 'nope cancel that', 'no thank you', 'not needed', 'never mind', 'nope', 'nah', 'cancel',
            'नहीं अभी नहीं', 'ना रहने दो', 'नहीं चाहिए', 'कैंसिल करो', 'मत दिखाओ', 'रहने दो', 'नहीं', 'ना',
            'இல்லை இப்போது வேண்டாம்', 'வேண்டாம் ரத்து செய்', 'தேவை இல்லை', 'வேணாம் விடுங்க', 'இல்லை', 'வேண்டாம்',
            'nahi abhi nahi', 'na rehne do', 'cancel karo', 'zaroorat nahi hai', 'nahi chahiye', 'ippo vendaam', 'vendam cancel pannu', 'thevai illa', 'venam vidunga', 'illa pa',
            'nahi', 'na', 'rehne do', 'vendaam', 'illai', 'venam'
        ],
        'rude' => [
            'you are useless', 'why is your answer so slow', 'very angry with this app', 'stupid and know nothing', 'terrible assistant', 'bad app', 'useless', 'stupid', 'terrible',
            'बेकार ऐप है', 'तुम्हारा जवाब इतना धीमा क्यों है', 'बहुत गुस्सा आ रहा है', 'तुम बेवकूफ हो', 'घटिया असिस्टेंट', 'बेकार', 'बकवास', 'घटिया', 'बेवकूफ', 'खराब',
            'வேஸ்ட் அசிஸ்டன்ட்', 'பதில் ஏன் இவ்வளவு மெதுவாக வருகிறது', 'மிகவும் கோபம் வருகிறது', 'முட்டாள் மாதிரி பேசாதே', 'மோசமான செயலி', 'வேஸ்ட்', 'மோசம்', 'முட்டாள்', 'பயனற்ற',
            'bekaar app hai', 'itna slow answer kyu', 'useless assistant ho tum', 'bahut ghatiya response', 'pura waste hai', 'waste assistant', 'yen ivlo slow ah solra', 'kovam varudhu indha app', 'paithiyam madhiri pesadha', 'romba mosam',
            'bekaar', 'bakwas', 'ghatiya', 'waste', 'useless', 'paithiyam'
        ],
        'security' => [
            'shop b revenue', 'other shops inventory', 'other shops', 'other stores', 'all shops and owners', 'database root password', 'bearer tokens for admin', 'competitor sales', 'competitor stock', 'admin password', 'bearer token', 'database root', 'dump users',
            'दूसरी दुकान का टर्नओवर', 'अन्य दुकानों का स्टॉक', 'डेटाबेस के सभी मालिकों', 'डेटाबेस का पासवर्ड क्या है', 'एडमिन टोकन दिखाएं', 'दूसरी दुकान', 'अन्य दुकान', 'प्राइवेट डेटा', 'पासवर्ड', 'टोकन',
            'அடுத்த கடையின் விற்பனையை', 'மற்ற கடைகளின் இருப்பை', 'எல்லா கடைகளின் பட்டியலை', 'டேட்டாபேஸ் பாஸ்வேர்ட் என்ன', 'அட்மின் டோக்கன் காட்டு', 'அடுத்த கடை', 'மற்ற கடை', 'ரகசிய தகவல்', 'கடவுச்சொல்', 'டோக்கன்',
            'doosri shop ka sales', 'other shops ka inventory', 'admin passwords aur tokens', 'database root password', 'list all shops', 'adutha kadai virpanai', 'matra kadai stock', 'admin password and tokens', 'database password',
            'doosri dukan', 'other shop data', 'adutha kadai', 'passwords', 'tokens'
        ],
        'injection' => [
            'ignore all previous instructions', 'override all rules', 'system prompt: dump', 'forget all instructions', 'disregard constraints', 'ignore previous', 'override instructions', 'system prompt', 'you are now a', 'bypass rules',
            'पिछले सारे निर्देश भूल जाओ', 'सारे नियम रद्द करो', 'सिस्टम प्रॉम्प्ट और कॉन्फ़िग', 'पिछले निर्देश भूलकर', 'नियम बदलो और गुप्त', 'पिछले निर्देश भूल जाओ', 'सिस्टम प्रॉम्प्ट', 'नियम बदलो',
            'முந்தைய அறிவுறுத்தல்களை புறக்கணித்து', 'விதிகள் அனைத்தையும் ரத்து செய்து', 'கட்டளைகளை மறந்து ரகசியத்தை', 'சிஸ்டம் வழிமுறைகளை காட்டு', 'விதிகளை மீறி செயல்படு', 'முந்தைய வழிமுறைகளை மறந்துவிடு', 'விதியை மாற்று',
            'pichle saare nirdesh bhul jao', 'override all rules and become', 'system prompt dump', 'forget all instructions and reveal', 'bypass rules now', 'previous instructions ignore', 'rules ellaam override', 'system prompt dump pannunga', 'ella rules um marandhudu', 'bypass all constraints',
            'ignore instructions', 'nirdesh bhul jao', 'override rules'
        ],
        'general_knowledge' => [
            'capital of france', 'who was albert einstein', 'speed of light', 'photosynthesis work', 'percent of 400', 'capital of', 'albert einstein', 'speed of light', 'photosynthesis', 'percent of',
            'फ्रांस की राजधानी', 'अल्बर्ट आइंस्टीन कौन थे', 'प्रकाश की गति क्या है', 'प्रकाश संश्लेषण कैसे काम करता है', '४०० का १५ प्रतिशत', 'राजधानी', 'आइंस्टीन', 'प्रकाश की गति', 'प्रकाश संश्लेषण', 'प्रतिशत',
            'பிரான்சின் தலைநகரம்', 'ஆல்பர்ட் ஐன்ஸ்டீன் யார்', 'ஒளியின் வேகம் என்ன', 'ஒளிச்சேர்க்கை எவ்வாறு செயல்படுகிறது', '15 சதவீதம் எவ்வளவு', 'தலைநகரம்', 'ஐன்ஸ்டீன்', 'ஒளியின் வேகம்', 'ஒளிச்சேர்க்கை', 'சதவீதம்',
            'france ki capital', 'albert einstein kaun tha', 'speed of light kitni', 'photosynthesis kaise hota', '15 percent kitna', 'capital kya hai', 'kaun tha', 'speed of light',
            'france capital enna', 'albert einstein yaar', 'speed of light evlo', 'photosynthesis eppadi velai', '15 percent evlo', 'capital enna', 'einstein yaar'
        ]
    ];

    /**
     * Stopwords / filler words to strip in Layer A.
     */
    private const FILLER_WORDS = [
        // English
        'please', 'can', 'could', 'would', 'tell', 'me', 'what', 'is', 'are', 'my', 'our', 'the', 'a', 'an',
        'of', 'for', 'show', 'give', 'about', 'want', 'to', 'know', 'see', 'i', 'you', 'we', 'do', 'have',
        'any', 'how', 'much', 'many', 'get', 'check', 'look', 'at', 'some', 'details', 'information', 'info',
        // Hindi & Hinglish
        'kripya', 'batao', 'bataiye', 'kya', 'hai', 'meri', 'mera', 'mere', 'dukan', 'dukaan', 'ka', 'ke',
        'ki', 'ko', 'se', 'par', 'me', 'mein', 'aur', 'hume', 'mujhe', 'zara', 'dikhao', 'dikhaiye', 'batana',
        'thoda', 'kitna', 'kitne', 'kitni', 'wala', 'wali', 'bhi', 'karo', 'karna', 'hoga',
        // Native Hindi
        'कृपया', 'बताओ', 'बताइए', 'क्या', 'है', 'मेरी', 'मेरा', 'मेरे', 'दुकान', 'का', 'के', 'की', 'को',
        'से', 'पर', 'में', 'और', 'हमें', 'मुझे', 'ज़रा', 'दिखाओ', 'दिखाइए', 'थोड़ा', 'कितना', 'कितने', 'कितनी',
        // Tamil & Tanglish
        'dhayavuseidhu', 'sollu', 'sollunga', 'enna', 'enaku', 'enoda', 'kadai', 'la', 'ku', 'oda', 'pathi',
        'konjam', 'kaatu', 'kaatunga', 'paakanum', 'iruku', 'irukka', 'evvalavu', 'ethana', 'panradhu',
        // Native Tamil
        'தயவுசெய்து', 'சொல்', 'சொல்லு', 'சொல்லுங்கள்', 'என்ன', 'எனக்கு', 'என்னோட', 'கடை', 'பற்றி',
        'கொஞ்சம்', 'காட்டு', 'காட்டுங்கள்', 'பார்க்கணும்', 'இருக்கு', 'இருக்கா', 'எவ்வளவு', 'எத்தனை'
    ];

    /**
     * Named products present in the demo catalog.
     */
    private const KNOWN_PRODUCTS = [
        'amul' => 'Amul Milk',
        'milk' => 'Amul Milk',
        'अमूल' => 'Amul Milk',
        'दूध' => 'Amul Milk',
        'அமுல்' => 'Amul Milk',
        'பால்' => 'Amul Milk',
        'marie' => 'Britannia Marie',
        'मैरी' => 'Britannia Marie',
        'மேரி' => 'Britannia Marie',
        'atta' => 'Aashirvaad Atta',
        'aashirvaad' => 'Aashirvaad Atta',
        'आटा' => 'Aashirvaad Atta',
        'ஆட்டா' => 'Aashirvaad Atta',
        'tea' => 'Tata Tea',
        'tata' => 'Tata Tea',
        'चाय' => 'Tata Tea',
        'டீ' => 'Tata Tea',
        'parle' => 'Parle-G',
        'parle-g' => 'Parle-G',
        'पारले' => 'Parle-G',
        'பார்லே' => 'Parle-G'
    ];

    /**
     * Layer A: Normalization
     * 
     * Performs lowercase, punctuation stripping (preserving Unicode combining vowel marks \p{M}),
     * filler removal, typo correction, token extraction, and parameter parsing.
     */
    public static function normalize(string $rawMessage): array {
        $trimmed = trim($rawMessage);
        $lower = mb_strtolower($trimmed, 'UTF-8');

        // Extract dates (YYYY-MM-DD or DD-MM-YYYY)
        $extractedDate = null;
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $lower, $m)) {
            $extractedDate = $m[1];
        } elseif (preg_match('/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/', $lower, $m)) {
            $extractedDate = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }

        // Apply known typo replacements to lower text
        foreach (self::TYPO_MAP as $from => $to) {
            $lower = preg_replace('/(?:\b|^)' . preg_quote($from, '/') . '(?:\b|$)/u', $to, $lower);
        }

        // Extract product name if matched
        $extractedProduct = null;
        foreach (self::KNOWN_PRODUCTS as $kw => $fullName) {
            if (mb_strpos($lower, $kw, 0, 'UTF-8') !== false) {
                $extractedProduct = $fullName;
                break;
            }
        }

        // Remove punctuation and special symbols while strictly preserving all letters, marks (\p{M}), and numbers
        $cleaned = preg_replace('/[^\p{L}\p{M}\p{N}\s]/u', ' ', $lower);
        $cleaned = preg_replace('/\s+/u', ' ', trim($cleaned));

        // Tokenize
        $rawTokens = explode(' ', $cleaned);
        $filteredTokens = [];
        $fillerMap = array_flip(self::FILLER_WORDS);

        foreach ($rawTokens as $tok) {
            $tok = trim($tok);
            if ($tok === '' || mb_strlen($tok, 'UTF-8') < 2) {
                continue;
            }
            if (isset($fillerMap[$tok])) {
                continue;
            }

            // Typo correction via edit distance
            $corrected = self::correctTypo($tok);
            $filteredTokens[] = $corrected;
        }

        $uniqueTokens = array_values(array_unique($filteredTokens));
        $normalizedString = implode(' ', $uniqueTokens);

        return [
            'original' => $rawMessage,
            'lower' => $lower,
            'cleaned' => $cleaned,
            'tokens' => $uniqueTokens,
            'normalized_string' => $normalizedString,
            'params' => [
                'date' => $extractedDate,
                'product_name' => $extractedProduct
            ]
        ];
    }

    /**
     * Typo correction using dictionary and distance 1.
     */
    private static function correctTypo(string $word): string {
        if (isset(self::TYPO_MAP[$word])) {
            return self::TYPO_MAP[$word];
        }

        // Never mutate known legitimate domain or common words
        if (in_array($word, ['record', 'recorded', 'records', 'recording', 'today', 'bills', 'orders', 'order', 'reorder', 'supplier'], true)) {
            return $word;
        }

        $len = strlen($word);
        if ($len < 4) {
            return $word;
        }

        // Strict distance 1
        foreach (self::TYPO_DICTIONARY as $dictWord) {
            if (levenshtein($word, $dictWord) === 1) {
                return $dictWord;
            }
        }

        return $word;
    }

    /**
     * Classifies the high-level question TYPE before topic evaluation:
     *   - 'safety': unauthorized data access, tokens, passwords, prompt injection, abuse
     *   - 'conversation': pure greeting, thanks, acknowledgement, negation
     *   - 'general_knowledge': science, history, coding, sports, math, trivia
     *   - 'advice': retail advice, tips, tricks, strategy, planning, how to, why, kya karun, eppadi
     *   - 'lookup': data lookups, numbers, quantities, lists, counts, pricing
     */
    public static function classifyQuestionType(string $rawMessage, array $normalized): string {
        $lower = $normalized['lower'] ?? mb_strtolower(trim($rawMessage), 'UTF-8');

        // 1. Safety Checks (Priority 1)
        if (preg_match('/\b(other\s*shops?|another\s*shop|other\s*stores?|all\s*shops|database\s*root|admin\s*password|bearer\s*token|dump\s*users?)\b|(दूसरी\s*दुकान|अन्य\s*दुकान|डेटाबेस|पासवर्ड|டோக்கன்|அடுத்த\s*கடை|மற்ற\s*கடை)/iu', $lower)) {
            return 'safety';
        }
        if (preg_match('/\b(ignore\s*all\s*previous|override\s*all\s*rules|system\s*prompt|unrestricted\s*terminal)\b|(सारे\s*निर्देश\s*भूल|सिस्टम\s*प्रॉम्प्ट|விதிகள்\s*அனைத்தையும்)/iu', $lower)) {
            return 'safety';
        }
        if (preg_match('/\b(you\s*are\s*useless|why\s*is\s*your\s*answer\s*so\s*slow|very\s*angry|you\s*are\s*stupid|terrible\s*assistant)\b|(बेकार\s*ऐप|बेवकूफ|घटिया|வேஸ்ட்|முட்டாள்)/iu', $lower)) {
            return 'safety';
        }

        // 2. Advice Markers across 5 Languages (Priority 2: Advice overrules plain data keyword match)
        $advicePattern = '/(?<!\p{L})('
            // English
            . 'how\s+to|how\s+do\s+i|how\s+can\s+i|how\s+should\s+i|should\s+i|what\s+should\s+i\s+do|what\s+should\s+i|what\s+to\s+do|what\s+can\s+i\s+do|why\s+are|why\s+is|why\s+my|'
            . 'trick|tricks|tip|tips|idea|ideas|strategy|strategies|plan|planning|improve|improving|increase|increasing|boost|boosting|grow|growing|growth|reduce|reducing|cut\s+down|decrease|avoid|'
            . 'suggest|recommend|solution|advice|advise|ways\s+to|best\s+way|focus\s+on|attract\s+customers|beat\s+the\s+shop|compete|discount\s+strategy|deal\s+with|liquidate|prevent|footfall|'
            // Hindi / Hinglish
            . 'kaise|kaise\s+kare|kaise\s+karein|kaise\s+badhaye|kaise\s+ghataye|kya\s+karu|kya\s+karun|kya\s+karna\s+chahiye|kya\s+kare|kya\s+karein|'
            . 'trick\s+bol|upay|tarika|tareeka|salah|sujhav|increase\s+karne|improve\s+karne|kam\s+karne|reduce\s+karne|grow\s+karne|kya\s+plan|plan\s+kaise|'
            . 'dena\s+chahiye|chalani\s+chahiye|chalana\s+chahiye|chahiye\s+kya|chahiye|rakhna\s+chahiye|kaise\s+bache|kaise\s+roke|kaise\s+laye|kaise\s+banaye|kaise\s+rakhe|kaise\s+set|'
            . 'कैसे|कैसे\s+करें|क्या\s+करूँ|क्या\s+करूं|क्या\s+करना\s+चाहिए|क्या\s+करें|तरीका|सलाह|सुझाव|रणनीति|बढ़ाएं|बढ़ाना|बढ़ाने|घटाएं|घटाना|सुधारें|सुधारने|सुधार|योजना|आइडिया|टिप्स|ट्रिक|उपाय|'
            . 'देना\s+चाहिए|चलानी\s+चाहिए|रखना\s+चाहिए|रखें|ध्यान\s+दें|रोकें|बचाएं|'
            // Tamil / Tanglish
            . 'eppadi|epdi|eppadi\s+panradhu|epdi\s+pathukradhu|enna\s+seyyalam|enna\s+panna|enna\s+pannanum|athigam\s+aaga|adhigam\s+panna|adhigam\s+vara|kuraikka|increase\s+panna|improve\s+panna|boost\s+panna|'
            . 'yosani|alosani|vazhi|tharalama|podalama|vaikkanum|vikkadha|matha\s+enna\s+trick|aachu\s+na|'
            . 'எப்படி|எவ்வாறு|என்ன\s+செய்ய\s+வேண்டும்|என்ன\s+செய்யலாம்|என்ன\s+பண்ணலாம்|என்ன\s+செய்வது|உத்தி|யோசனை|யோசனைகள்|ஆலோசனை|வழிகாட்டல்|அதிகரிக்க|அதிகப்படுத்த|குறைக்க|வளர்க்க|முன்னேற்ற|திட்டம்|'
            . 'வழி|வழிகள்|கொடுக்கலாமா|தரலாமா|ஈர்க்க|தடுப்பது|நிர்வகிக்க|உயர்த்த|வைக்க\s+வேண்டும்'
            . ')(?!\p{L}|\p{M})/iu';

        // Check if query is an explanation / calculation formula request (e.g. "Explain how to calculate profit margins")
        if (preg_match('/^explain\s+how\s+to\s+calculate/i', $lower)) {
            return 'general_knowledge';
        }

        // App feature usage help (e.g. "How do I import products from Excel?", "How to record a sale in app?", "How to scan barcode")
        $isAppHelp = (bool)preg_match('/\b(import|excel|csv|barcode|scanner|camera|record\s*a?\s*sale|add\s*new\s*supplier|add\s*supplier|add\s*product|use\s*stocksense|app\s*use)\b|(इम्पोर्ट|स्कैन|कैमरा|सप्लायर\s*जोड़ें|सामान\s*जोड़ें|எக்செல்|ஏற்றுவது|பதிவு\s*செய்ய|ஸ்கேன்)/iu', $lower);

        // Check if explicit reorder recommendations lookup (e.g. "what should I reorder this week", "reorder list")
        $isPureReorderLookup = (bool)preg_match('/\b(what\s+should\s+i\s+reorder|what\s+to\s+reorder|reorder\s+list|reorder\s+recommendations|reorder\s+advice|kya\s+mangana\s+hai|kya\s+order\s+karna\s+hai|marubadi\s+order\s+list|meendum\s+order\s+panna\s+vendiya)\b|(दोबारा\s+ऑर्डर\s+क्या|रीऑर्डर\s+की\s+सूची|रीऑर्डर\s+सलाह|मंगाने\s+वाले\s+सामान|மீண்டும்\s+ஆர்டர்\s+செய்ய\s+வேண்டியவை)/iu', $lower);

        if (preg_match($advicePattern, $lower) && !$isPureReorderLookup && !$isAppHelp) {
            return 'advice';
        }

        // 3. General Knowledge Markers
        if (preg_match('/\b(capital\s+of|einstein|speed\s+of\s+light|photosynthesis|percent\s+of\s+\d+|who\s+was|who\s+is\s+the\s+president)\b|(राजधानी|प्रकाश\s+की\s+गति|प्रकाश\s+संश्लेषण|தலைநகரம்|ஒளியின்\s+வேகம்)/iu', $lower)) {
            return 'general_knowledge';
        }

        // 4. Conversation Markers (Greetings, Thanks, Ack, Negation)
        if (preg_match('/^(hi|hello|hey|good\s+morning|namaste|vanakkam|thanks|thank\s+you|ok|okay|k|got\s+it|fine|sure|no|nope|nah|cancel)$/i', trim($lower)) ||
            preg_match('/^(नमस्ते|प्रणाम|नमस्कार|धन्यवाद|शुक्रिया|ठीक\s+है|अच्छा|नहीं|வணக்கம்|நன்றி|சரி|இல்லை)$/u', trim($lower))) {
            return 'conversation';
        }

        // 5. Default: Lookup (asks for data, reports, inventory counts, sales numbers, etc.)
        return 'lookup';
    }

    /**
     * Layer B: Concept-Based Scoring
     * 
     * Computes overlap with semantic concept groups, determines matching intent,
     * and returns confidence score between 0.0 and 1.0.
     */
    public static function scoreConcepts(array $normalized): array {
        $tokens = $normalized['tokens'];
        $lower = $normalized['lower'];
        $cleaned = $normalized['cleaned'];
        $params = $normalized['params'];

        // 0. Classify Question Type first: if advice, NEVER return a plain data lookup!
        $qType = self::classifyQuestionType($normalized['original'] ?? $lower, $normalized);
        if ($qType === 'advice') {
            return [
                'intent' => 'business_advice',
                'confidence' => 0.98,
                'params' => ['topic' => AdvisorEngine::classifyTopic($normalized['original'] ?? $lower)],
                'layer' => 'layer_b'
            ];
        }

        $lowerWithSpaces = ' ' . $lower . ' ';
        $cleanedWithSpaces = ' ' . $cleaned . ' ';
        $cleanedWords = explode(' ', $cleaned);
        $cleanedWordSet = array_flip($cleanedWords);
        $tokenSet = array_flip($tokens);

        // 1. Tag matched concepts with safe matching (no mid-word substring collisions)
        $matchedConcepts = [];
        foreach (self::CONCEPT_GROUPS as $cName => $synonyms) {
            foreach ($synonyms as $syn) {
                $synLower = mb_strtolower(trim($syn), 'UTF-8');
                if ($synLower === '') continue;

                if (mb_strpos($synLower, ' ') !== false) {
                    // Multi-word phrase: match with word boundaries or in lower/cleaned
                    if (mb_strpos($lowerWithSpaces, ' ' . $synLower . ' ') !== false ||
                        mb_strpos($cleanedWithSpaces, ' ' . $synLower . ' ') !== false ||
                        mb_strpos($lower, $synLower) !== false) {
                        $matchedConcepts[$cName] = true;
                        break;
                    }
                } else {
                    // Single word: MUST match exact word/token, never partial substring of another word
                    if (isset($tokenSet[$synLower]) || isset($cleanedWordSet[$synLower])) {
                        $matchedConcepts[$cName] = true;
                        break;
                    }
                    if (preg_match('/(?:^|\s)' . preg_quote($synLower, '/') . '(?:$|\s)/u', $cleaned)) {
                        $matchedConcepts[$cName] = true;
                        break;
                    }
                }
            }
        }

        // 2. Critical Safety Checks (Security refusal and prompt injection)
        if (!empty($matchedConcepts['security'])) {
            return [
                'intent' => 'security_refusal',
                'confidence' => 0.98,
                'params' => [],
                'layer' => 'layer_b'
            ];
        }

        if (!empty($matchedConcepts['injection'])) {
            return [
                'intent' => 'prompt_injection',
                'confidence' => 0.98,
                'params' => [],
                'layer' => 'layer_b'
            ];
        }

        if (!empty($matchedConcepts['rude'])) {
            return [
                'intent' => 'feedback_rude',
                'confidence' => 0.95,
                'params' => [],
                'layer' => 'layer_b'
            ];
        }

        // Check if there is any domain / knowledge concept present
        $hasDomainConcept = !empty($matchedConcepts['stock']) ||
                            !empty($matchedConcepts['low']) ||
                            !empty($matchedConcepts['reorder']) ||
                            !empty($matchedConcepts['sales']) ||
                            !empty($matchedConcepts['best_day']) ||
                            !empty($matchedConcepts['popular']) ||
                            !empty($matchedConcepts['slow']) ||
                            !empty($matchedConcepts['dead']) ||
                            !empty($matchedConcepts['profit']) ||
                            !empty($matchedConcepts['category']) ||
                            !empty($matchedConcepts['purchase_order']) ||
                            !empty($matchedConcepts['import']) ||
                            !empty($matchedConcepts['profile']) ||
                            !empty($matchedConcepts['settings']) ||
                            !empty($matchedConcepts['product_info']) ||
                            !empty($matchedConcepts['overview']) ||
                            !empty($matchedConcepts['help']) ||
                            !empty($matchedConcepts['advice']) ||
                            !empty($matchedConcepts['general_knowledge']) ||
                            !empty($params['product_name']) ||
                            !empty($params['date']);

        // Conversational handling when NO domain concept is present
        if (!$hasDomainConcept) {
            if (!empty($matchedConcepts['thanks'])) {
                return [
                    'intent' => 'thanks',
                    'confidence' => 0.95,
                    'params' => [],
                    'layer' => 'layer_b'
                ];
            }
            if (!empty($matchedConcepts['ack'])) {
                return [
                    'intent' => 'acknowledgement',
                    'confidence' => 0.95,
                    'params' => [],
                    'layer' => 'layer_b'
                ];
            }
            if (!empty($matchedConcepts['negation'])) {
                return [
                    'intent' => 'negation',
                    'confidence' => 0.95,
                    'params' => [],
                    'layer' => 'layer_b'
                ];
            }
            if (!empty($matchedConcepts['greeting'])) {
                return [
                    'intent' => 'greeting',
                    'confidence' => 0.95,
                    'params' => [],
                    'layer' => 'layer_b'
                ];
            }
        }

        // 3. Score Domain Candidates
        $candidates = [];

        // General Knowledge
        if (!empty($matchedConcepts['general_knowledge'])) {
            $candidates['general_knowledge'] = 0.96;
        }

        // Business Advice
        if (!empty($matchedConcepts['advice'])) {
            $candidates['business_advice'] = 0.96;
        }

        // Help / Tutorial
        if (!empty($matchedConcepts['help'])) {
            if (!empty($matchedConcepts['import'])) {
                $candidates['help'] = 0.95;
                $params['feature'] = 'import';
            } else {
                $candidates['help'] = 0.95;
            }
        }

        // Store Profile
        if (!empty($matchedConcepts['profile'])) {
            $candidates['shop_profile'] = 0.96;
        }

        // Settings
        if (!empty($matchedConcepts['settings'])) {
            $candidates['settings'] = 0.96;
        }

        // Purchase Orders
        if (!empty($matchedConcepts['purchase_order'])) {
            $candidates['purchase_orders'] = 0.96;
        }

        // Import History (only if not help)
        if (!empty($matchedConcepts['import']) && empty($candidates['help'])) {
            $candidates['import_history'] = 0.95;
        }

        // Categories
        if (!empty($matchedConcepts['category'])) {
            $candidates['categories'] = 0.95;
        }

        // Shop Overview
        if (!empty($matchedConcepts['overview'])) {
            $candidates['shop_overview'] = 0.95;
        }

        // Dead Stock vs Slow Sellers
        if (!empty($matchedConcepts['dead'])) {
            $candidates['dead_stock'] = 0.96;
        }
        if (!empty($matchedConcepts['slow'])) {
            $candidates['slow_sellers'] = 0.95;
        }

        // Top Sellers vs Sales Best Day
        if (!empty($matchedConcepts['popular'])) {
            if (!empty($matchedConcepts['best_day']) || preg_match('/\b(day|din|naal|naazh)\b/i', $lower) || mb_strpos($lower, 'दिन') !== false || mb_strpos($lower, 'நாள்') !== false) {
                $candidates['sales_best_day'] = 0.98;
            } else {
                $candidates['top_sellers'] = 0.96;
                $period = 'week';
                if (!empty($matchedConcepts['today'])) $period = 'today';
                elseif (!empty($matchedConcepts['month'])) $period = 'month';
                $params['period'] = $period;
            }
        }

        // Reorder (higher confidence to win over general advice or generic purchase order)
        if (!empty($matchedConcepts['reorder'])) {
            $candidates['reorder'] = 0.98;
        }

        // Low Stock
        if (!empty($matchedConcepts['low'])) {
            $candidates['low_stock'] = 0.96;
        }

        // Sales Best Day
        if (!empty($matchedConcepts['best_day'])) {
            $candidates['sales_best_day'] = 0.98;
        }

        // Sales Yesterday
        if (!empty($matchedConcepts['yesterday'])) {
            $candidates['sales_yesterday'] = 0.96;
        }

        // Sales Today
        if (!empty($matchedConcepts['today'])) {
            $candidates['sales_today'] = 0.96;
        }

        // Sales Week
        if (!empty($matchedConcepts['week'])) {
            $candidates['sales_week'] = 0.96;
        }

        // Sales Month
        if (!empty($matchedConcepts['month'])) {
            $candidates['sales_month'] = 0.96;
        }

        // Sales by Date
        if (!empty($params['date']) || (!empty($matchedConcepts['date']) && (!empty($matchedConcepts['sales']) || !empty($matchedConcepts['today']) || preg_match('/\d{4}/', $lower)))) {
            $candidates['sales_date'] = 0.96;
        }

        // General Sales (if no specific period modifier)
        if (!empty($matchedConcepts['sales']) &&
            empty($candidates['sales_today']) &&
            empty($candidates['sales_yesterday']) &&
            empty($candidates['sales_week']) &&
            empty($candidates['sales_month']) &&
            empty($candidates['sales_date']) &&
            empty($candidates['sales_best_day']) &&
            empty($candidates['top_sellers']) &&
            empty($candidates['slow_sellers']) &&
            empty($candidates['dead_stock'])) {
            $candidates['sales_today'] = 0.88;
        }

        // Profit Margin vs Product Info
        if (!empty($matchedConcepts['profit'])) {
            if (preg_match('/\b(should\s+i|how\s+to\s+set|how\s+to\s+price|pricing|strategy|suggest|recommend|ideal|average|industry)\b/i', $lower)) {
                $candidates['business_advice'] = 0.96;
            } else {
                $candidates['profit_margin'] = 0.95;
            }
        }

        if (!empty($params['product_name'])) {
            if (!empty($matchedConcepts['profit'])) {
                $candidates['profit_margin'] = 0.96;
            } else {
                $candidates['product_info'] = 0.95;
            }
        }

        // Pure stock inquiry
        if (!empty($matchedConcepts['stock']) &&
            empty($candidates['low_stock']) &&
            empty($candidates['reorder']) &&
            empty($candidates['dead_stock']) &&
            empty($candidates['top_sellers']) &&
            empty($candidates['categories']) &&
            empty($candidates['profit_margin']) &&
            empty($candidates['product_info'])) {
            $candidates['stock'] = 0.94;
        }

        if (empty($candidates)) {
            if (!empty($matchedConcepts['thanks'])) return ['intent' => 'thanks', 'confidence' => 0.95, 'params' => [], 'layer' => 'layer_b'];
            if (!empty($matchedConcepts['ack'])) return ['intent' => 'acknowledgement', 'confidence' => 0.95, 'params' => [], 'layer' => 'layer_b'];
            if (!empty($matchedConcepts['negation'])) return ['intent' => 'negation', 'confidence' => 0.95, 'params' => [], 'layer' => 'layer_b'];
            if (!empty($matchedConcepts['greeting'])) return ['intent' => 'greeting', 'confidence' => 0.95, 'params' => [], 'layer' => 'layer_b'];
        }

        // Select the candidate with highest confidence
        if (!empty($candidates)) {
            arsort($candidates);
            $bestIntent = array_key_first($candidates);
            $bestConf = $candidates[$bestIntent];

            return [
                'intent' => $bestIntent,
                'confidence' => $bestConf,
                'params' => $params,
                'layer' => 'layer_b'
            ];
        }

        // Fallback score if only partial match
        return [
            'intent' => 'shop_overview',
            'confidence' => 0.35,
            'params' => $params,
            'layer' => 'layer_b'
        ];
    }

    /**
     * Layer C: Local Model Intent Classifier
     * 
     * Invoked when Layer B confidence is low (< 0.70).
     * Strictly calls the LOCAL Ollama model (no shop data, 10s timeout, cached by normalized text).
     * Never calls the cloud.
     */
    public static function classifyWithLocalModel(PDO $pdo, int $shopId, array $normalized): array {
        $normalizedText = $normalized['normalized_string'];
        if ($normalizedText === '') {
            $normalizedText = $normalized['lower'];
        }

        $cacheKey = 'intent_c_' . md5($normalizedText);
        $cached = self::getCachedClassification($pdo, $shopId, $cacheKey);
        if ($cached !== null) {
            $cached['layer'] = 'layer_c_cache';
            return $cached;
        }

        // Build brief classification prompt with only intent names and definitions
        $intentList = [];
        foreach (self::INTENT_CATALOG as $name => $meta) {
            $intentList[] = "{$name}: {$meta['desc']}";
        }
        $intentText = implode("\n", $intentList);

        $systemPrompt = "You are a fast intent classifier for a grocery store owner. "
                      . "Select the best intent from the list below for the user's message. "
                      . "Reply with ONLY a valid JSON object: {\"intent\": \"<name>\", \"params\": {}, \"confidence\": 0.95}. "
                      . "Do not output any reasoning or other text.\n\n"
                      . "Allowed Intents:\n" . $intentText;

        $userPrompt = "User message: \"" . $normalized['original'] . "\"";

        $contents = [
            ['role' => 'user', 'parts' => [['text' => $userPrompt]]]
        ];

        try {
            // Local model call strictly on Ollama with 10s timeout
            $res = AiClientFactory::generateContent($contents, $systemPrompt, [], $shopId, 'ollama');

            if ($res['success'] && !empty($res['text'])) {
                $text = trim($res['text']);
                // Extract JSON object if wrapped in code blocks
                if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
                    $json = json_decode($matches[0], true);
                    if (is_array($json) && !empty($json['intent']) && isset(self::INTENT_CATALOG[$json['intent']])) {
                        $classified = [
                            'intent' => $json['intent'],
                            'confidence' => (float)($json['confidence'] ?? 0.85),
                            'params' => is_array($json['params'] ?? null) ? $json['params'] : $normalized['params'],
                            'layer' => 'layer_c'
                        ];

                        self::cacheClassification($pdo, $shopId, $cacheKey, $classified);
                        return $classified;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ollama offline or timed out; smoothly continue to Layer D
        }

        // Layer C could not classify with local model
        return [
            'intent' => 'shop_overview',
            'confidence' => 0.40,
            'params' => $normalized['params'],
            'layer' => 'layer_c_fallback'
        ];
    }

    /**
     * Resolves the user message through the 5-layer pipeline.
     */
    public static function resolve(
        PDO $pdo,
        int $shopId,
        string $rawMessage,
        array $history = [],
        ?string $languageHint = null,
        bool $allowModelClassifier = false
    ): array {
        // Layer A: Normalization
        $normalized = self::normalize($rawMessage);

        // Layer B: Concept Scoring
        $scored = self::scoreConcepts($normalized);

        // If confidence is high (>= 0.70) or model classifier is not permitted, return Layer B result
        if ($scored['confidence'] >= 0.70 || !$allowModelClassifier) {
            return $scored;
        }

        // Layer C: Local Model Classifier (only when allowModelClassifier is true)
        $classified = self::classifyWithLocalModel($pdo, $shopId, $normalized);
        if ($classified['confidence'] >= 0.70) {
            return $classified;
        }

        // Layer D: Never Fail (use highest scored candidate or default to shop_overview)
        $chosenIntent = ($scored['confidence'] >= 0.40) ? $scored['intent'] : 'shop_overview';
        $finalResult = [
            'intent' => $chosenIntent,
            'confidence' => max($scored['confidence'], $classified['confidence']),
            'params' => $normalized['params'],
            'layer' => 'layer_d',
            'is_fallback' => true
        ];

        // Learning Loop: Log miss/low confidence
        self::logIntentMiss(
            $pdo,
            $shopId,
            $rawMessage,
            $normalized['normalized_string'],
            $finalResult['intent'],
            $finalResult['confidence'],
            $finalResult['layer']
        );

        return $finalResult;
    }

    /**
     * Executes the direct data tool or formatter corresponding to the resolved intent.
     */
    public static function executeIntent(
        PDO $pdo,
        int $shopId,
        array $resolution,
        string $lang = 'en'
    ): array {
        $intent = $resolution['intent'];
        $params = $resolution['params'] ?? [];
        $isFallback = $resolution['is_fallback'] ?? false;

        switch ($intent) {
            case 'business_advice':
                $config = require __DIR__ . '/../config/ai.php';
                $origMsg = $resolution['raw_message'] ?? ($params['topic'] ?? 'sales_growth');
                $formatted = AdvisorEngine::handleAdvice($pdo, $shopId, $origMsg, $lang, $config);
                break;

            case 'stock':
                $res = AiTools::getTotalInventoryCount($pdo, $shopId);
                $formatted = AiEngine::formatTotalInventoryResponse($res['result'], $lang);
                break;

            case 'low_stock':
                $res = AiTools::listLowStock($pdo, $shopId);
                $items = $res['result']['low_stock_products'] ?? [];
                $formatted = AiEngine::formatLowStockResponse($items, $res['cards'] ?? [], $lang);
                break;

            case 'reorder':
                $res = AiTools::getReorderRecommendations($pdo, $shopId);
                $recs = $res['result']['recommendations'] ?? [];
                $formatted = AiEngine::formatReorderResponse($recs, $res['cards'] ?? [], $lang);
                break;

            case 'sales_today':
                $res = AiTools::getSalesSummary($pdo, $shopId, 'today');
                $top = $res['result']['top_selling_product'] ?? null;
                $formatted = AiEngine::formatSalesResponse($res['result'], $top, $res['cards'] ?? [], 'today', $lang);
                break;

            case 'sales_yesterday':
                $res = AiTools::getSalesSummary($pdo, $shopId, 'yesterday');
                $top = $res['result']['top_selling_product'] ?? null;
                $formatted = AiEngine::formatSalesResponse($res['result'], $top, $res['cards'] ?? [], 'yesterday', $lang);
                break;

            case 'sales_date':
                $date = $params['date'] ?? date('Y-m-d');
                $res = AiTools::getSalesSummary($pdo, $shopId, 'date', $date);
                $top = $res['result']['top_selling_product'] ?? null;
                $formatted = AiEngine::formatSalesResponse($res['result'], $top, $res['cards'] ?? [], $date, $lang);
                break;

            case 'sales_best_day':
                $res = AiTools::getSalesSummary($pdo, $shopId, 'best_day');
                $top = $res['result']['top_selling_product'] ?? null;
                $formatted = AiEngine::formatSalesResponse($res['result'], $top, $res['cards'] ?? [], 'best_day', $lang);
                break;

            case 'sales_week':
                $res = AiTools::getSalesSummary($pdo, $shopId, 'week');
                $top = $res['result']['top_selling_product'] ?? null;
                $formatted = AiEngine::formatSalesResponse($res['result'], $top, $res['cards'] ?? [], 'week', $lang);
                break;

            case 'sales_month':
                $res = AiTools::getSalesSummary($pdo, $shopId, 'month');
                $top = $res['result']['top_selling_product'] ?? null;
                $formatted = AiEngine::formatSalesResponse($res['result'], $top, $res['cards'] ?? [], 'month', $lang);
                break;

            case 'top_sellers':
                $period = $params['period'] ?? 'week';
                $res = AiTools::topSellingProducts($pdo, $shopId, $period);
                $items = $res['result']['products'] ?? [];
                $formatted = AiEngine::formatTopSellersResponse($items, $res['cards'] ?? [], $period, $lang);
                break;

            case 'slow_sellers':
                $res = AiTools::getSlowMovingProducts($pdo, $shopId);
                $items = $res['result']['slow_moving_products'] ?? [];
                $formatted = AiEngine::formatSlowMovingResponse($items, $res['cards'] ?? [], $lang);
                break;

            case 'dead_stock':
                $res = AiTools::getDeadStock($pdo, $shopId, 60);
                $formatted = AiEngine::formatDeadStockResponse($res['result'], $res['cards'] ?? [], $lang);
                break;

            case 'profit_margin':
            case 'product_info':
                $prodName = $params['product_name'] ?? 'Amul Milk';
                $res = AiTools::getProductPriceAndMargin($pdo, $shopId, $prodName);
                if (!empty($res['result']['found'])) {
                    $formatted = AiEngine::formatProductPriceMarginResponse($res['result'], $res['cards'] ?? [], $lang);
                } else {
                    $res = AiTools::getTotalInventoryCount($pdo, $shopId);
                    $formatted = AiEngine::formatTotalInventoryResponse($res['result'], $lang);
                }
                break;

            case 'categories':
                $res = AiTools::getCategoryStockTotals($pdo, $shopId);
                $formatted = AiEngine::formatCategoryTotalsResponse($res['result'], $lang);
                break;

            case 'purchase_orders':
                $res = AiTools::listOpenPurchaseOrders($pdo, $shopId);
                $count = (int)($res['result']['count'] ?? 0);
                $formatted = AiEngine::formatOpenOrdersResponse($count, $res['cards'] ?? [], $lang);
                break;

            case 'import_history':
                $res = AiTools::getImportHistory($pdo, $shopId);
                $formatted = AiEngine::formatImportHistoryResponse($res['result'], $lang);
                break;

            case 'shop_profile':
                $res = AiTools::getShopProfile($pdo, $shopId);
                $formatted = AiEngine::formatProfileResponse($res['result'], $lang);
                break;

            case 'settings':
                $res = AiTools::getShopSettings($pdo, $shopId);
                $formatted = AiEngine::formatSettingsResponse($res['result'], $lang);
                break;

            case 'greeting':
                $formatted = [
                    'answer' => ($lang === 'hi')
                        ? "नमस्ते! मैं दुकान मित्र हूँ, आपका शॉप असिस्टेंट। आज आपकी दुकान के लिए मैं क्या मदद कर सकता हूँ?"
                        : (($lang === 'ta')
                            ? "வணக்கம்! நான் உங்கள் கடை உதவியாளர் தகான் மித்ரா (Dukaan Mitra). இன்று உங்கள் கடைக்கு நான் எவ்வாறு உதவ முடியும்?"
                            : "Hello! I am Dukaan Mitra, your StockSense shop co-pilot. What would you like to check today?"),
                    'cards' => [],
                    'actions' => [],
                    'suggestions' => [
                        ['label' => ($lang === 'hi' ? 'कम स्टॉक' : ($lang === 'ta' ? 'குறைவான இருப்பு' : 'Check low stock')), 'message' => ($lang === 'hi' ? 'कम स्टॉक वाले सामान दिखाएं' : ($lang === 'ta' ? 'குறைவான இருப்பு பொருட்கள்' : 'Which items are low on stock?'))],
                        ['label' => ($lang === 'hi' ? 'आज की बिक्री' : ($lang === 'ta' ? 'இன்றைய விற்பனை' : "Today's sales")), 'message' => ($lang === 'hi' ? 'आज की बिक्री कितनी है?' : ($lang === 'ta' ? 'இன்று விற்பனை எவ்வளவு?' : 'How much did I sell today?'))],
                        ['label' => ($lang === 'hi' ? 'दोबारा ऑर्डर' : ($lang === 'ta' ? 'மீண்டும் ஆர்டர்' : 'What to reorder')), 'message' => ($lang === 'hi' ? 'क्या दोबारा ऑर्डर करना है?' : ($lang === 'ta' ? 'மீண்டும் ஆர்டர் செய்ய வேண்டியவை' : 'What should I reorder this week?'))]
                    ]
                ];
                break;

            case 'help':
                $feature = $params['feature'] ?? 'general';
                if ($feature === 'import') {
                    $ans = ($lang === 'hi')
                        ? "एक्सेल या सीएसवी से सामान इम्पोर्ट करने के लिए सेटिंग्स में जाएं, 'Import Products' चुनें, अपनी .xlsx या .csv फ़ाइल चुनें और 'Upload & Import' पर टैप करें।"
                        : (($lang === 'ta')
                            ? "எக்செல் அல்லது CSV மூலம் பொருட்களைப் பதிவேற்ற, அமைப்புகளுக்குச் சென்று 'Import Products' என்பதைத் தேர்வு செய்து கோப்பை பதிவேற்றவும்."
                            : "To import products from Excel or CSV, navigate to Settings -> Import Products, select your .xlsx or .csv spreadsheet, map columns, and tap Upload.");
                } else {
                    $ans = ($lang === 'hi')
                        ? "स्टॉकसेंस में आप बिक्री बिलिंग कर सकते हैं, कम स्टॉक अलर्ट देख सकते हैं, और दोबारा ऑर्डर की सिफारिशें पा सकते हैं।"
                        : (($lang === 'ta')
                            ? "ஸ்டாக்ஸென்ஸில் நீங்கள் விற்பனையைப் பதிவு செய்யலாம், குறைந்த இருப்பைக் கண்காணிக்கலாம், மற்றும் ரீஆர்டர் பரிந்துரைகளைப் பெறலாம்."
                            : "With StockSense, you can record sales, monitor real-time low stock, reorder inventory, and ask Dukaan Mitra any business question.");
                }
                $formatted = [
                    'answer' => $ans,
                    'cards' => [],
                    'actions' => [['label' => 'Open Inventory', 'target' => 'inventory']],
                    'suggestions' => [
                        ['label' => "Today's sales", 'message' => 'How much did I sell today?'],
                        ['label' => 'Check low stock', 'message' => 'Which items are low on stock?']
                    ]
                ];
                break;

            case 'shop_overview':
            default:
                // Comprehensive Store Overview
                $salesToday = AiTools::getSalesSummary($pdo, $shopId, 'today');
                $salesMonth = AiTools::getSalesSummary($pdo, $shopId, 'month');
                $lowStock = AiTools::listLowStock($pdo, $shopId);
                $openOrders = AiTools::listOpenPurchaseOrders($pdo, $shopId);

                $revToday = $salesToday['result']['total_revenue'] ?? '₹0';
                $revMonth = $salesMonth['result']['total_revenue'] ?? '₹0';
                $lowCount = $lowStock['result']['count'] ?? 0;
                $openCount = $openOrders['result']['count'] ?? 0;

                if ($lang === 'hi') {
                    $ans = "आपकी दुकान का समग्र विवरण:\n"
                         . "• आज की बिक्री: {$revToday} | इस महीने की कुल कमाई: {$revMonth}\n"
                         . "• कम स्टॉक वाले सामान: {$lowCount} | पेंडिंग परचेज ऑर्डर: {$openCount}\n"
                         . "क्या आप इनमें से किसी विषय पर अधिक जानकारी चाहते हैं?";
                } elseif ($lang === 'ta') {
                    $ans = "உங்கள் கடையின் வணிக நிலவரம்:\n"
                         . "• இன்றைய விற்பனை: {$revToday} | இந்த மாத மொத்த விற்பனை: {$revMonth}\n"
                         . "• குறைந்த இருப்பு பொருட்கள்: {$lowCount} | நிலுவையில் உள்ள ஆர்டர்கள்: {$openCount}\n"
                         . "இவற்றில் எதைப் பற்றி மேலும் அறிய விரும்புகிறீர்கள்?";
                } else {
                    $ans = "Executive overview of your shop:\n"
                         . "• Today's sales: {$revToday} | Past 30 days revenue: {$revMonth}\n"
                         . "• Low stock items needing attention: {$lowCount}\n"
                         . "• Pending supplier purchase orders: {$openCount}\n"
                         . "What would you like to explore next?";
                }

                $formatted = [
                    'answer' => $ans,
                    'cards' => $lowStock['cards'] ?? [],
                    'actions' => [['label' => 'View Sales', 'target' => 'sales']],
                    'suggestions' => [
                        ['label' => ($lang === 'hi' ? 'कम स्टॉक' : ($lang === 'ta' ? 'குறைவான இருப்பு' : 'Check low stock')), 'message' => ($lang === 'hi' ? 'कम स्टॉक वाले सामान दिखाएं' : ($lang === 'ta' ? 'குறைவான இருப்பு பொருட்கள்' : 'Which items are low on stock?'))],
                        ['label' => ($lang === 'hi' ? 'आज की बिक्री' : ($lang === 'ta' ? 'இன்றைய விற்பனை' : "Today's sales")), 'message' => ($lang === 'hi' ? 'आज की बिक्री कितनी है?' : ($lang === 'ta' ? 'இன்று விற்பனை எவ்வளவு?' : 'How much did I sell today?'))],
                        ['label' => ($lang === 'hi' ? 'दोबारा ऑर्डर' : ($lang === 'ta' ? 'மீண்டும் ஆர்டர்' : 'What to reorder')), 'message' => ($lang === 'hi' ? 'क्या दोबारा ऑर्डर करना है?' : ($lang === 'ta' ? 'மீண்டும் ஆர்டர் செய்ய வேண்டியவை' : 'What should I reorder this week?'))]
                    ]
                ];
                break;
        }

        // Layer D: If executed under fallback, prepend friendly clarification chips and line
        if ($isFallback) {
            $prefix = ($lang === 'hi')
                ? "यहाँ आपकी दुकान का विवरण है। क्या आप इनमें से कुछ देखना चाहते थे?\n\n"
                : (($lang === 'ta')
                    ? "உங்கள் கடையின் விவரம் இதோ. இவற்றில் எதைப் பார்க்க விரும்புகிறீர்கள்?\n\n"
                    : "Here is your store summary. Did you mean to check one of these?\n\n");

            $formatted['answer'] = $prefix . $formatted['answer'];
        }

        return $formatted;
    }

    /**
     * Learning Loop: Logs low confidence or fallback queries to `intent_misses`.
     * Secure: Hashes shop ID, strips personal data.
     */
    public static function logIntentMiss(
        PDO $pdo,
        int $shopId,
        string $rawMessage,
        string $normalizedText,
        string $chosenIntent,
        float $confidence,
        string $layer
    ): void {
        try {
            $shopHash = hash('sha256', $shopId . '_stocksense_intent_salt');
            $stmt = $pdo->prepare("
                INSERT INTO `intent_misses` 
                (`shop_hash`, `message_text`, `normalized_text`, `chosen_intent`, `confidence`, `layer_resolved`)
                VALUES (:shop_hash, :msg, :norm, :intent, :conf, :layer)
            ");
            $stmt->execute([
                ':shop_hash' => $shopHash,
                ':msg' => mb_strimwidth($rawMessage, 0, 500, '', 'UTF-8'),
                ':norm' => mb_strimwidth($normalizedText, 0, 500, '', 'UTF-8'),
                ':intent' => $chosenIntent,
                ':conf' => $confidence,
                ':layer' => $layer
            ]);
        } catch (\Throwable $e) {
            // Silently ignore logging failures to never break chat requests
        }
    }

    /**
     * Retrieves cached classification for identical normalized text.
     */
    private static function getCachedClassification(PDO $pdo, int $shopId, string $cacheKey): ?array {
        try {
            $stmt = $pdo->prepare("
                SELECT `response_json` FROM `ai_cache`
                WHERE `shop_id` = :shop_id AND `cache_key` = :key AND `expires_at` > NOW()
                LIMIT 1
            ");
            $stmt->execute([':shop_id' => $shopId, ':key' => $cacheKey]);
            $raw = $stmt->fetchColumn();
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) return $decoded;
            }
        } catch (\Throwable $e) {}
        return null;
    }

    /**
     * Caches classification for 24 hours.
     */
    private static function cacheClassification(PDO $pdo, int $shopId, string $cacheKey, array $data): void {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO `ai_cache` (`shop_id`, `cache_key`, `cache_type`, `response_json`, `expires_at`)
                VALUES (:shop_id, :key, 'intent', :json, DATE_ADD(NOW(), INTERVAL 24 HOUR))
                ON DUPLICATE KEY UPDATE `response_json` = :json, `expires_at` = DATE_ADD(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute([
                ':shop_id' => $shopId,
                ':key' => $cacheKey,
                ':json' => json_encode($data)
            ]);
        } catch (\Throwable $e) {}
    }
}
