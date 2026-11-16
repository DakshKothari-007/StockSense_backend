<?php

/**
 * test_question_type_eval.php
 * 
 * Held-Out Evaluation Suite for Question-Type Classifier (Lookup vs Advice)
 * Evaluates semantic understanding across 5 languages:
 *  - English (en)
 *  - Hindi (hi, Devanagari)
 *  - Tamil (ta, Tamil script)
 *  - Hinglish (hi_latin, Hindi in Latin script)
 *  - Tanglish (ta_latin, Tamil in Latin script)
 * 
 * Target: >= 95% accuracy on held-out dataset (at least 100 advice + 100 lookup per language = 1000+ queries).
 * Tests statements, vague phrases, typos, mixed languages, and very short phrases.
 */

require_once __DIR__ . "/../includes/IntentEngine.php";

$testData = [
    // =========================================================================
    // 1. ENGLISH (en)
    // =========================================================================
    'en' => [
        'advice' => [
            // Statements of problems / worries / goals (Requirement 3)
            "my shop is not doing well",
            "daily sales are declining rapidly",
            "customers are not coming to the shop",
            "footfall has dropped significantly this month",
            "profit is not happening lately",
            "losses are mounting every week",
            "too much stock is sitting idle in the backroom",
            "money is stuck in slow moving inventory",
            "a large supermarket opened right across the street",
            "my competitor next door is offering 20% discounts",
            "stagnant goods are eating up my working capital",
            "we keep running out of fast-selling items on weekends",
            "margins are getting squeezed by supplier price hikes",
            "sales have dropped drastically",
            "customers are complaining about high prices",
            "very few people enter the store in the evening",
            "inventory turnover has slowed to a crawl",
            "cash flow is tight because stock is not moving",
            "new supermarket opened nearby and took my customers",
            "revenue is falling day by day",
            "nobody is buying the premium biscuits",
            "shop expenses are exceeding daily earnings",
            "store traffic is down 40 percent",
            "stock is expiring before being sold",
            "sales down",
            "customers leaving",
            "profit low",
            "store struggling",
            "footfall falling",

            // Active How-To / Strategy / Planning
            "how to increase sales in my grocery store",
            "what strategy should I use against a competitor supermarket",
            "how can I improve profit margins on staples",
            "tips to reduce dead stock without heavy losses",
            "how to attract more customers during weekday afternoons",
            "should I give discounts on slow moving inventory",
            "how to negotiate better credit terms with distributors",
            "ideas to create attractive product bundles",
            "how to avoid running out of stock on fast movers",
            "best ways to boost customer loyalty and repeat visits",
            "how should I price products against modern retail chains",
            "planning for Diwali and festival season sales",
            "how to liquidate unsold seasonal items quickly",
            "suggest ways to cut down electricity and operating costs",
            "how to turn idle inventory into liquid cash",
            "should I run a weekend promotional scheme",
            "how to train store staff to upsell higher margin products",
            "how to set competitive prices without losing margin",
            "recommend ideas to increase basket size per customer",
            "what to do when daily sales are falling",
            "how to handle supplier price increases smoothly",
            "ways to promote newly added grocery categories",
            "how to build a customer loyalty points program",
            "how to prevent perishable goods from spoiling",
            "advice on setting safety stock levels for dairy",

            // Vague phrases / short queries
            "advice plz",
            "growth advice",
            "competitor strategy",
            "clear stagnant stock",
            "boost store turnover",
            "sales improvement ideas",
            "margin boost tips",
            "deal with dead stock",
            "customer retention plan",
            "supermarket battle plan",
            "ideas to grow",
            "retail growth tips",
            "footfall tricks",
            "bundle ideas",
            "discount tips",

            // Typos & colloquial
            "how to incrase sales plss",
            "tips for reducin dead stok",
            "suprtmarket opened nearby what to do",
            "cusotmers not coming to store",
            "how can i improv margin",
            "shud i give discout on biscuit",
            "best way to liqudate old stock",
            "competitor is killin my business",
            "stok out happening agian and again",
            "how to attrack new shoppers",
            "seles are dropping wht to do",
            "tips to incraese footfall",
            "stratgy to beat shop next door",
            "monye stuck in unsld goods",
            "how to improov cash flow",

            // Code-switched / mixed
            "sales is dropping and footfall kam ho gaya",
            "how to increase sales in my dukaan",
            "competitor supermarket vanthutan what strategy to use",
            "too much dead stock pada hai what to do",
            "customers nahi aa rahe how to attract them",
            "profit nahi ho raha how to improve margin",
            "how to beat pakkathu kadai modern supermarket",
            "weekend scheme podalama should I give discount",
            "inventory circulation improve panna advice",
            "unsold stock clear karne ka best solution"
        ],
        'lookup' => [
            // Exact queries from user prompt & direct metrics
            "low stock list chahiye",
            "reorder list dikhao",
            "show my sales plan",
            "sales ka plan kya hai",
            "which items increased this week",

            // Number / quantity / count lookups
            "how much did I sell today",
            "what were my total sales yesterday",
            "how many units of Amul Butter do I have in stock",
            "show me low stock items",
            "give me the list of out of stock products",
            "what was my sales total for last month",
            "how many packets of Parle-G are left",
            "what is the price of Tata Salt",
            "show selling price and cost price of Aashirvaad Atta",
            "what is the profit margin percentage on Maggi Noodles",
            "list top 5 selling items this week",
            "show slow moving products for last 30 days",
            "what is the SKU for Britannia Marie Gold",
            "show me open purchase orders",
            "what is my total stock valuation",
            "how many categories do I have",
            "list all suppliers in my account",
            "what were my sales on 15th August",
            "show best sales day this month",
            "how many items are below minimum threshold",
            "what is the current stock of Fortune Oil",
            "give me summary of today revenue",
            "display dead stock items count",
            "what is my store registered email and phone",
            "show safety stock days configuration",

            // Lookup queries containing advice-like words (plan, increase, tip, etc.)
            "show my sales plan for this quarter",
            "what is my configured sales plan target",
            "which product prices increased this month",
            "show items where margin increased",
            "list products whose sales increased recently",
            "display reorder plan list",
            "what is the safety stock plan for biscuits",
            "show monthly sales increase report",
            "give me the list of items that need a discount",
            "which items had stock decrease yesterday",
            "show the list of competitor matched products",
            "display promotional price list",
            "show loyalty point balance for customers",
            "which category had the highest increase in sales",
            "give me stock count for items in reorder plan",

            // App feature usage / help (Feature Lookups)
            "how do I import products from Excel file",
            "how to record a sale in the app",
            "how to scan a barcode using camera",
            "how do I add a new supplier",
            "how to export inventory to CSV",
            "how to change safety stock threshold in settings",
            "how to create a purchase order in StockSense",

            // Very short / abbreviations / typos
            "stk count",
            "today sale",
            "low stk",
            "reorder lst",
            "gst rept",
            "sales yest",
            "prle g stk",
            "tata salt prce",
            "top selers",
            "dead stk list",
            "out of stk",
            "bills count",
            "totl revnue",
            "shw sales",
            "lst orders",

            // Typos in data lookups
            "hw much did i sell tody",
            "shw me low stok prducts",
            "wat is my sales totl",
            "reodr list plz",
            "gve me inventory count",
            "amul buttr stock level",
            "parle-g priec and cost",
            "yesturday sales summary",
            "whch items are out of stock",
            "lst open purchse ordrs",
            "slow movng items list",
            "tody bill count",
            "totl inventry value",
            "shw margn on maggi",
            "skuu of britania biscuit",

            // Structured request phrases
            "export sales report",
            "generate inventory summary",
            "download stock ledger",
            "display weekly revenue chart",
            "get purchase order #104 status",
            "fetch supplier contact details",
            "view store profile settings",
            "list low velocity items with quantities",
            "check barcode 8901030383921 stock",
            "show batch expiry dates for dairy items"
        ]
    ],

    // =========================================================================
    // 2. HINDI (hi, Devanagari Script)
    // =========================================================================
    'hi' => [
        'advice' => [
            // Statements of problems / worries / goals (Requirement 3)
            "दुकान की बिक्री कम हो गई है",
            "ग्राहक दुकान पर नहीं आ रहे हैं",
            "मेरी दुकान ठीक से नहीं चल रही है",
            "व्यापार में मुनाफा नहीं हो रहा है",
            "सामान पड़ा हुआ है बिक नहीं रहा",
            "पैसा पुराने स्टॉक में अटका हुआ है",
            "दुकान के सामने एक नया सुपरमार्केट खुल गया है",
            "हर हफ्ते नुकसान बढ़ता जा रहा है",
            "शाम के समय दुकान में बिल्कुल ग्राहक नहीं होते",
            "डेड स्टॉक बहुत ज्यादा जमा हो गया है",
            "दुकान का खर्चा कमाई से ज्यादा निकल रहा है",
            "मार्जिन बहुत कम मिल रहा है",
            "रोज की बिक्री लगातार घट रही है",
            "दुकान पर फुटफॉल काफी गिर गया है",
            "कंपटीटर भारी डिस्काउंट देकर ग्राहक खींच रहा है",
            "ज्यादा बिकने वाला सामान बार-बार खत्म हो जाता है",
            "धीमी गति वाले सामान की वजह से कैश फ्लो रुक गया है",
            "दूध और ब्रेड जैसी चीजें एक्सपायर हो रही हैं",
            "नया प्रतिस्पर्धी आने से धंधा मंदा हो गया है",
            "दुकान की हालत अच्छी नहीं है",
            "बिक्री बहुत कम",
            "ग्राहक नहीं हैं",
            "मुनाफा बंद",
            "दुकान मंदा",
            "घाटा हो रहा है",

            // Active How-To / Strategy / Planning
            "दुकान की बिक्री कैसे बढ़ाएं",
            "नया सुपरमार्केट खुल रहा है क्या रणनीति अपनाएं",
            "प्रॉफ़िट मार्जिन कैसे सुधारें",
            "डेड स्टॉक कम करने का तरीका बताएं",
            "ग्राहकों को दुकान की तरफ आकर्षित कैसे करें",
            "क्या धीमे बिकने वाले सामान पर डिस्काउंट देना चाहिए",
            "सप्लायर से उधारी और पेमेंट की शर्तें कैसे तय करें",
            "किराने के सामान का कॉम्बो ऑफर कैसे बनाएं",
            "ज्यादा बिकने वाले सामान का स्टॉक खत्म होने से कैसे रोकें",
            "नियमित ग्राहकों की लॉयल्टी कैसे बढ़ाएं",
            "सुपरमार्केट के सामने सामान के दाम कैसे तय करें",
            "दीवाली और त्योहारों की बिक्री बढ़ाने के आइडिया",
            "जो सामान नहीं बिक रहा उसे जल्दी कैसे निकालें",
            "दुकान के बिजली और अन्य खर्चे कैसे कम करें",
            "कैश फ्लो बेहतर करने के टिप्स",
            "वीकेंड पर डिस्काउंट स्कीम चलानी चाहिए क्या",
            "स्टाफ को ज्यादा मार्जिन वाला सामान बेचने का तरीका सिखाएं",
            "बिक्री बढ़ाने के लिए किस श्रेणी पर ध्यान देना चाहिए",
            "ग्राहकों की खरीदारी का बिल साइज कैसे बढ़ाएं",
            "दैनिक बिक्री घटने पर दुकानदार को क्या करना चाहिए",
            "सप्लायर द्वारा दाम बढ़ाने पर क्या उपाय करें",
            "नए किराना उत्पादों का प्रचार कैसे करें",
            "ग्राहक लॉयल्टी प्वाइंट्स प्रोग्राम कैसे शुरू करें",
            "खराब होने वाले सामान का नुकसान कैसे रोकें",
            "दूध और दही के लिए सेफ्टी स्टॉक कितना रखना चाहिए",

            // Vague phrases / short queries
            "सलाह दीजिए",
            "बिक्री बढ़ाने के टिप्स",
            "सुपरमार्केट से मुकाबला",
            "डेड स्टॉक निकालने का उपाय",
            "दुकान का टर्नओवर बढ़ाना",
            "मार्जिन सुधारने की योजना",
            "ग्राहक बढ़ाने का तरीका",
            "त्योहारी बिक्री रणनीति",
            "कॉम्बो पैक आइडिया",
            "कैश फ्लो सुधार",
            "बिक्री बढ़ाने का नुस्खा",
            "नुकसान से बचने का उपाय",
            "कंपटीशन से निपटने की सलाह",
            "ग्राहक रोकने का तरीका",
            "बिजनेस ग्रोथ टिप्स",

            // Typos & colloquial
            "bikri kese bdhae",
            "grahak dukan pr nhi aa rhe",
            "ded stok kam krne ka tarika",
            "dukan me fottfall kese lae",
            "munafa kse sudhare",
            "suprrmarket khul gya kya kre",
            "slow samaan pr discount de kya",
            "dukan ka khrcha kse kam kre",
            "cash flow bhetar krne ke tips",
            "bikri gir rhi he kya karu",
            "nuksan rokne ka upay btao",
            "stok khtm hone se kse bache",
            "combo pak kese bnae",
            "grahak loyalty kese bdhe",
            "dukan kse aage bdhae"
        ],
        'lookup' => [
            // Exact queries from user prompt & direct metrics
            "लो स्टॉक लिस्ट चाहिए",
            "रीऑर्डर लिस्ट दिखाओ",
            "मेरा सेल्स प्लान दिखाओ",
            "सेल्स का प्लान क्या है",
            "इस हफ्ते कौनसे सामान की बिक्री बढ़ी",

            // Numbers, quantities, counts lookups in Devanagari
            "आज की बिक्री कितनी है",
            "कल का कुल कारोबार कितना था",
            "अमूल मक्खन के कितने पैकेट बचे हैं",
            "कम स्टॉक वाले सामान दिखाओ",
            "खत्म हो चुके सामानों की सूची दें",
            "पिछले महीने की कुल कमाई कितनी थी",
            "पारले जी बिस्कुट की कितनी यूनिट हैं",
            "टाटा नमक का बिक्री मूल्य क्या है",
            "आशीर्वाद आटा का लागत मूल्य और बिक्री भाव दिखाएं",
            "मैगी नूडल्स पर कितना प्रतिशत मार्जिन मिलता है",
            "इस हफ्ते सबसे ज्यादा बिकने वाले ५ सामान बताएं",
            "पिछले ३० दिनों में कम बिकने वाले सामान दिखाएं",
            "ब्रिटानिया मैरी गोल्ड का एसकेयू क्या है",
            "खुले परचेज ऑर्डर दिखाएं",
            "मेरी दुकान के कुल स्टॉक का मूल्य क्या है",
            "दुकान में कुल कितनी श्रेणियां हैं",
            "सभी सप्लायरों की सूची दिखाएं",
            "१५ अगस्त को कितनी बिक्री हुई थी",
            "इस महीने का सबसे अच्छा बिक्री वाला दिन कौन सा था",
            "न्यूनतम स्तर से नीचे वाले सामान कितने हैं",
            "फॉर्च्यून तेल का वर्तमान स्टॉक कितना है",
            "आज के राजस्व का सारांश दें",
            "डेड स्टॉक सामानों की संख्या दिखाएं",
            "दुकान का रजिस्टर्ड ईमेल और फोन नंबर क्या है",
            "सेफ्टी स्टॉक दिनों की सेटिंग दिखाएं",

            // Lookups containing plan / increase / tips in Hindi
            "इस तिमाही का सेल्स प्लान दिखाओ",
            "दुकान का तय किया गया सेल्स प्लान क्या है",
            "इस महीने किन उत्पादों के दाम बढ़े हैं",
            "जिन सामानों का मार्जिन बढ़ा है उनकी सूची दिखाएं",
            "हाल ही में जिनकी बिक्री बढ़ी उन सामानों को दिखाएं",
            "रीऑर्डर प्लान की लिस्ट दिखाओ",
            "बिस्कुट के लिए सेफ्टी स्टॉक प्लान क्या है",
            "मासिक बिक्री वृद्धि की रिपोर्ट दें",
            "जिन सामानों पर डिस्काउंट देना है उनकी सूची दिखाएं",
            "कल किन सामानों का स्टॉक घटा था",
            "प्रतियोगी से मेल खाने वाले सामानों की लिस्ट दिखाएं",
            "प्रमोशनल रेट लिस्ट दिखाओ",
            "ग्राहकों का लॉयल्टी बैलेंस दिखाएं",
            "किस कैटेगरी में सबसे ज्यादा बढ़ोतरी दर्ज हुई",
            "रीऑर्डर प्लान में शामिल सामानों का स्टॉक काउंट दें",

            // App feature help in Hindi
            "एक्सेल फाइल से सामान कैसे इम्पोर्ट करें",
            "ऐप में बिक्री कैसे रिकॉर्ड करें",
            "कैमरा से बारकोड कैसे स्कैन करें",
            "नया सप्लायर कैसे जोड़ें",
            "इन्वेंटरी को सीएसवी में कैसे एक्सपोर्ट करें",
            "सेटिंग्स में लो स्टॉक अलर्ट कैसे बदलें",
            "स्टॉकसेंस में नया ऑर्डर कैसे बनाएं",

            // Short Hindi lookups & common abbreviations
            "आज का गल्ला",
            "कल की बिक्री",
            "स्टॉक काउंट",
            "लो स्टॉक सामान",
            "रीऑर्डर सूची",
            "जीएसटी रिपोर्ट",
            "कुल मुनाफा रिपोर्ट",
            "बिल संख्या",
            "पारले जी का भाव",
            "अमूल दूध स्टॉक",
            "दुकान का हिसाब",
            "स्टॉक लिस्ट",
            "बिक्री सारांश",
            "सप्लायर लिस्ट",
            "आउट ऑफ स्टॉक लिस्ट",

            // Structured query phrasing
            "दैनिक बिक्री का ब्यौरा",
            "महीने का टर्नओवर रिपोर्ट",
            "सामानों का स्टॉक रजिस्टर",
            "ऑर्डर नंबर १०४ की स्थिति",
            "सप्लायर संपर्क विवरण",
            "दुकान प्रोफाइल सेटिंग्स",
            "धीमी गति वाले सामान की सूची मात्रा सहित",
            "बारकोड ८९०१०३०३८३९२१ का स्टॉक चेक करें",
            "डेयरी उत्पादों की एक्सपायरी डेट दिखाएं",
            "दुकान का कुल इन्वेंटरी बैलेंस"
        ]
    ],

    // =========================================================================
    // 3. TAMIL (ta, Tamil Script)
    // =========================================================================
    'ta' => [
        'advice' => [
            // Statements of problems / worries / goals (Requirement 3)
            "கடை வியாபாரம் சரியாக இல்லை",
            "விற்பனை மிகவும் குறைந்துவிட்டது",
            "வாடிக்கையாளர்கள் கடைக்கு வரவில்லை",
            "கடையின் வரத்து இந்த மாதம் சரிந்துவிட்டது",
            "வியாபாரத்தில் லாபமே கிடைக்கவில்லை",
            "ஒவ்வொரு வாரமும் நஷ்டம் கூடிக்கொண்டே போகிறது",
            "பொருட்கள் விற்காமல் தேங்கிக் கிடக்கிறது",
            "பணம் பழைய சரக்கில் முடங்கிவிட்டது",
            "எதிரே பெரிய சூப்பர் மார்க்கெட் திறந்துவிட்டார்கள்",
            "அடுத்த கடைக்காரர் 20 சதவீத தள்ளுபடி கொடுக்கிறார்",
            "தேங்கி நிற்கும் சரக்கு பணப்புழக்கத்தை பாதிக்கிறது",
            "வார இறுதியில் முக்கியமான பொருட்கள் தீர்ந்துவிடுகின்றன",
            "சப்ளையர் விலை ஏற்றியதால் லாபம் குறைந்துவிட்டது",
            "தினசரி வசூல் நாளுக்கு நாள் குறைகிறது",
            "வாடிக்கையாளர்கள் விலை அதிகம் என்று புகார் செய்கிறார்கள்",
            "மாலையில் கடையில் ஆட்களே இருப்பதில்லை",
            "சரக்கு சுழற்சி மிக மெதுவாக நடக்கிறது",
            "விற்பனை ஆகாததால் பணத்தட்டுப்பாடு ஏற்பட்டுள்ளது",
            "புதிய சூப்பர் மார்க்கெட் வந்ததால் கூட்டம் குறைந்தது",
            "வருமானம் தொடர்ந்து குறைந்து வருகிறது",
            "விலை உயர்ந்த பிஸ்கட்டுகளை யாரும் வாங்குவதில்லை",
            "கடையின் செலவு வருமானத்தை விட அதிகமாக உள்ளது",
            "கடைக்கு வரும் மக்களின் எண்ணிக்கை பாதியாக குறைந்துவிட்டது",
            "பொருட்கள் விற்பனைக்கு முன்பே காலாவதியாகின்றன",
            "விற்பனை குறைவு",
            "ஆட்கள் வரவில்லை",
            "லாபம் இல்லை",
            "கடை மந்தம்",
            "நஷ்டம் ஏற்படுகிறது",

            // Active How-To / Strategy / Planning
            "கடையின் விற்பனையை அதிகரிக்க என்ன வழி",
            "புதிய சூப்பர் மார்க்கெட் வந்தால் என்ன உத்தி பயன்படுத்த வேண்டும்",
            "மளிகை பொருட்களில் லாப வரம்பை எப்படி உயர்த்துவது",
            "நஷ்டம் இல்லாமல் பழைய சரக்கை குறைக்க என்ன ஆலோசனை",
            "மதிய வேளையில் வாடிக்கையாளர்களை கவர என்ன செய்யலாம்",
            "விற்காத பொருட்களுக்கு தள்ளுபடி கொடுக்கலாமா",
            "சப்ளையரிடம் கடன் வசதிகளை எப்படி பேசி முடிப்பது",
            "மளிகை பொருட்களுக்கு காம்போ சலுகை எப்படி உருவாக்குவது",
            "அதிகம் விற்கும் பொருட்கள் தீர்ந்துபோகாமல் தடுப்பது எப்படி",
            "வாடிக்கையாளர் விசுவாசத்தை அதிகரிக்க என்ன டிப்ஸ்",
            "சூப்பர் மார்க்கெட்டுக்கு போட்டியாக விலை வைப்பது எப்படி",
            "தீபாவளி பண்டிகை கால விற்பனைக்கு எப்படி திட்டம் போடுவது",
            "விற்பனையாகாத பொருட்களை விரைவில் விற்று தீர்ப்பது எப்படி",
            "கடையின் மின்சாரம் மற்றும் செலவுகளை குறைக்க யோசனை",
            "பணப்புழக்கத்தை சீராக்க என்ன வழி",
            "வார இறுதியில் சலுகை திட்டம் அறிவிக்கலாமா",
            "அதிக லாபம் தரும் பொருட்களை விற்க ஊழியருக்கு பயிற்சி தருவது எப்படி",
            "விற்பனை அதிகரிக்க எந்த பிரிவில் கவனம் செலுத்த வேண்டும்",
            "வாடிக்கையாளரின் பில் தொகையை அதிகரிக்க என்ன வழி",
            "தினசரி விற்பனை குறையும் போது என்ன செய்ய வேண்டும்",
            "சப்ளையர் விலை உயர்த்தினால் என்ன செய்வது",
            "புதிய மளிகை பொருட்களை விளம்பரம் செய்வது எப்படி",
            "வாடிக்கையாளர் புள்ளிகள் திட்டம் தொடங்குவது எப்படி",
            "சீக்கிரம் கெட்டுப்போகும் பொருட்களின் நஷ்டத்தை தடுப்பது எப்படி",
            "பால் மற்றும் தயிர் பொருட்களுக்கு பாதுகாப்பு இருப்பு எவ்வளவு வைக்க வேண்டும்",

            // Vague phrases / short queries
            "ஆலோசனை சொல்லுங்கள்",
            "விற்பனை உயர்த்த யோசனை",
            "சூப்பர் மார்க்கெட் போட்டி",
            "பழைய சரக்கு அகற்றம்",
            "கடை முன்னேற்ற வழி",
            "லாப வரம்பு ஆலோசனை",
            "வாடிக்கையாளர் ஈர்ப்பு திட்டம்",
            "பண்டிகை வியாபார உத்தி",
            "காம்போ பேக் யோசனை",
            "பணப்புழக்கம் டிப்ஸ்",
            "வியாபார வெற்றி சூத்திரம்",
            "நஷ்ட தடுப்பு வழி",
            "போட்டி சமாளிக்கும் வழி",
            "மக்கள் வரத்து உத்தி",
            "வணிக வளர்ச்சி யோசனை",

            // Typos & colloquial
            "virpanai adhigarikka enna vazhi",
            "kadai vyabaram sariya illa",
            "aale varala kadai ku",
            "kadai la kootam kuranjiduchu",
            "profit kedaikala enna seyyalam",
            "supermarket vanthuruchu enna panradhu",
            "vikkadha porul ku discount tharalama",
            "kadai selavu eppadi kuraikiradhu",
            "cash flow manage panna tips",
            "sales koranjuduchu enna pannanum",
            "nashtam thadukka enna yosana",
            "stock theerndhupochu epdi thadukradhu",
            "combo offer epdi create panradhu",
            "customers loyalty epdi valarkradhu",
            "kadai munnetra enna vazhi"
        ],
        'lookup' => [
            // Exact queries from user prompt & direct metrics
            "குறைந்த இருப்பு பட்டியல் வேண்டும்",
            "மறுஆர்டர் பட்டியல் காட்டுங்கள்",
            "என் விற்பனை திட்டத்தை காட்டு",
            "விற்பனை திட்டம் என்ன",
            "இந்த வாரம் எந்த பொருட்களின் விற்பனை அதிகரித்தது",

            // Numbers, quantities, counts lookups in Tamil
            "இன்று விற்பனை எவ்வளவு",
            "நேற்றைய மொத்த விற்பனை எவ்வளவு",
            "அமுல் வெண்ணெய் எவ்வளவு இருப்பு உள்ளது",
            "குறைந்த இருப்பு பொருட்கள் எவை",
            "தீர்ந்துபோன பொருட்களின் பட்டியலை காட்டு",
            "கடந்த மாதத்தின் மொத்த வருமானம் என்ன",
            "பார்லே ஜி பிஸ்கட் எத்தனை பாக்கெட்டுகள் உள்ளன",
            "டாடா உப்பின் விலை என்ன",
            "ஆசீர்வாத் கோதுமை மாவு அடக்க விலை மற்றும் விற்பனை விலை காட்டு",
            "மேகி நூடுல்ஸ் லாப சதவீதம் எவ்வளவு",
            "இந்த வாரம் அதிகம் விற்ற 5 பொருட்கள் எவை",
            "கடந்த 30 நாட்களில் குறைவாக விற்ற பொருட்களை காட்டு",
            "பிரிட்டானியா மேரி கோல்ட் எஸ்கேயூ என்ன",
            "நிலுவையில் உள்ள கொள்முதல் ஆர்டர்களை காட்டு",
            "என் கடையின் மொத்த சரக்கின் மதிப்பு என்ன",
            "கடையில் மொத்தம் எத்தனை பிரிவுகள் உள்ளன",
            "எல்லா சப்ளையர்களின் பட்டியலை காட்டு",
            "ஆகஸ்ட் 15 அன்று எவ்வளவு விற்பனை நடந்தது",
            "இந்த மாதத்தின் சிறந்த விற்பனை நாள் எது",
            "குறைந்தபட்ச அளவை விட குறைவாக உள்ள பொருட்கள் எத்தனை",
            "பார்ச்சூன் எண்ணெயின் தற்போதைய இருப்பு எவ்வளவு",
            "இன்றைய வருமான சுருக்கம் கொடுங்கள்",
            "தேங்கி நிற்கும் பொருட்களின் எண்ணிக்கை காட்டு",
            "கடையின் பதிவு செய்யப்பட்ட மின்னஞ்சல் மற்றும் தொலைபேசி எண் என்ன",
            "பாதுகாப்பு இருப்பு நாட்கள் அமைப்பு என்ன",

            // Lookups containing plan / increase / tips in Tamil
            "இந்த காலாண்டின் விற்பனை திட்டத்தை காட்டு",
            "கடைக்கு நிர்ணயிக்கப்பட்ட விற்பனை திட்டம் என்ன",
            "இந்த மாதம் எந்த பொருட்களின் விலை உயர்ந்தது",
            "லாப வரம்பு அதிகரித்த பொருட்களின் பட்டியலை காட்டு",
            "சமீபத்தில் விற்பனை அதிகரித்த பொருட்களை காட்டு",
            "மறுஆர்டர் திட்ட பட்டியலை காட்டு",
            "பிஸ்கட்டுகளுக்கான பாதுகாப்பு இருப்பு திட்டம் என்ன",
            "மாதாந்திர விற்பனை வளர்ச்சி அறிக்கையை கொடுங்கள்",
            "தள்ளுபடி தர வேண்டிய பொருட்களின் பட்டியலை காட்டு",
            "நேற்று எந்த பொருட்களின் இருப்பு குறைந்தது",
            "போட்டி கடைக்கு சமமான பொருட்களின் பட்டியலை காட்டு",
            "சிறப்பு விலை பட்டியலை காட்டு",
            "வாடிக்கையாளர்களின் விசுவாச புள்ளி இருப்பு காட்டு",
            "எந்த பிரிவில் அதிக வளர்ச்சி ஏற்பட்டுள்ளது",
            "மறுஆர்டர் திட்டத்தில் உள்ள பொருட்களின் எண்ணிக்கை கொடுங்கள்",

            // App feature help in Tamil
            "எக்செல் கோப்பிலிருந்து பொருட்களை எவ்வாறு ஏற்றுவது",
            "செயலியில் விற்பனையை எவ்வாறு பதிவு செய்வது",
            "கேமரா மூலம் பார்கோடை ஸ்கேன் செய்வது எப்படி",
            "புதிய சப்ளையரை எவ்வாறு சேர்ப்பது",
            "சரக்கு இருப்பை சிஎஸ்வி கோப்பாக ஏற்றுமதி செய்வது எப்படி",
            "அமைப்புகளில் குறைந்த இருப்பு எச்சரிக்கையை மாற்றுவது எப்படி",
            "ஸ்டாக்ஸென்ஸில் புதிய ஆர்டர் உருவாக்குவது எப்படி",

            // Short Tamil lookups
            "இன்றைய வசூல்",
            "நேற்றைய விற்பனை",
            "இருப்பு எண்ணிக்கை",
            "குறைந்த இருப்பு",
            "மறுஆர்டர் பட்டியல்",
            "ஜிஎஸ்டி அறிக்கை",
            "லாப அறிக்கை",
            "பில் எண்ணிக்கை",
            "பார்லே ஜி விலை",
            "அமுல் பால் இருப்பு",
            "கடை கணக்கு",
            "சரக்கு பட்டியல்",
            "விற்பனை சுருக்கம்",
            "சப்ளையர் பட்டியல்",
            "இருப்பு இல்லாதவை",

            // Structured Tamil queries
            "தினசரி விற்பனை அறிக்கை",
            "மாதாந்திர வருவாய் கணக்கு",
            "சரக்கு இருப்பு பதிவேடு",
            "ஆர்டர் எண் 104 நிலை",
            "சப்ளையர் தொடர்பு விவரங்கள்",
            "கடை சுயவிவர அமைப்புகள்",
            "குறைந்த வேக பொருட்களின் பட்டியல் அளவுடன்",
            "பார்கோடு 8901030383921 இருப்பு சரிபார்க்கவும்",
            "பால் பொருட்களின் காலாவதி தேதிகளை காட்டு",
            "கடையின் மொத்த சரக்கு இருப்பு"
        ]
    ],

    // =========================================================================
    // 4. HINGLISH (hi_latin, Hindi in Latin Script)
    // =========================================================================
    'hi_latin' => [
        'advice' => [
            // Statements of problems / worries / goals (Requirement 3)
            "sales kam ho gayi hai",
            "customers nahi aa rahe",
            "my shop is not doing well",
            "profit nahi ho raha",
            "dukan achhi nahi chal rahi",
            "dukan theek nahi chal rahi hai",
            "saman pada hua hai bik nahi raha",
            "paisa stock me fasa hua hai",
            "samne ek naya supermarket khul gaya hai",
            "dukan me footfall gir gaya hai",
            "losses ho rahe hain har hafte",
            "evening me bilkul grahak nahi aate",
            "dead stock bahut zyada jama ho gaya hai",
            "dukan ka kharcha kamai se zyada ho raha hai",
            "margin bohot kam mil raha hai",
            "daily sales continuously down ja rahi hai",
            "competitor heavy discount de raha hai",
            "fast moving items bar bar out of stock ho jata hai",
            "stagnant inventory ki wajah se cash flow ruk gaya",
            "bread aur milk jaisi cheeze expire ho rahi hain",
            "naya dukan khulne se customer toot gaye",
            "store traffic aadha ho gaya hai",
            "bikri bilkul thap ho gayi hai",
            "dukan ka bura haal hai",
            "sales down hai",
            "grahak nahi hain",
            "munafa gayab",
            "dukan manda",
            "ghata ho raha",

            // Active How-To / Strategy / Planning
            "trick bol sales increase karne ki",
            "dukan ki sales kaise badhaye",
            "naya supermarket khul raha hai kya strategy use kare",
            "profit margin improve kaise kare",
            "dead stock kam karne ka tarika batao",
            "customers ko dukan pe attract kaise kare",
            "kya slow moving saman pe discount dena chahiye",
            "suppliers se payment terms negotiate kaise kare",
            "grocery items ka combo bundle kaise banaye",
            "fast moving items ka stockout kaise roke",
            "regular customers ki loyalty kaise badhaye",
            "supermarket ke samne prices kaise set kare",
            "festival season me sale badhane ke ideas",
            "jo samaan nahi bik raha uska kya kare",
            "shop expenses aur bijli ka bill kaise kam kare",
            "cash flow manage karne ke tips batao",
            "weekend pe promotional offer chalana chahiye kya",
            "sales staff ko upselling sikhane ka tarika",
            "growth ke liye kis category pe focus karna chahiye",
            "customer basket size increase karne ki trick",
            "daily sales down hone pe dukan dar ko kya karna chahiye",
            "supplier ke price badhane par kya upay kare",
            "naya saman introduce karne ka promotion plan",
            "customer loyalty program kaise shuru kare",
            "perishable inventory ka wastage kaise roke",

            // Vague phrases / short queries
            "salah do bhai",
            "sales boost tips",
            "competitor se compete kaise kare",
            "dead stock clearing trick",
            "turnover increase plan",
            "margin improve ideas",
            "footfall increase trick",
            "diwali strategy",
            "combo idea",
            "cash flow guidance",
            "growth tip",
            "ghata rokne ka upay",
            "customer retain trick",
            "dukan promotion plan",
            "retail success ideas",

            // Typos & colloquial
            "sales kse bdhau",
            "costumer nhi a rhe dukan pe",
            "profitt kse improve kre",
            "ded stok clear krne ka tip",
            "super mrket samne khul gya",
            "slow produts pe discnt dena h kya",
            "bijli ka bil kse kam kre",
            "kash flow manage krna h",
            "daly sales dwn h",
            "loss s kse bche",
            "fast sellng saman out ho gya",
            "cmbo offer bnaneka idea",
            "loyality points kse chlae",
            "dukan me fotfal kse laye",
            "business grow krna h"
        ],
        'lookup' => [
            // Exact queries from user prompt & direct metrics
            "low stock list chahiye",
            "reorder list dikhao",
            "show my sales plan",
            "sales ka plan kya hai",
            "which items increased this week",

            // Numbers, quantities, counts in Hinglish
            "aaj kitna sales hua",
            "kal ka total sales kitna tha",
            "Amul Butter ke kitne packet bache hain",
            "low stock items dikhao",
            "out of stock saman ki list do",
            "pichle mahine ka total turnover kitna tha",
            "Parle-G biscuit ke kitne unit bache hain",
            "Tata Salt ka selling price kya hai",
            "Aashirvaad Atta ka cost aur selling price batao",
            "Maggi Noodles pe kitna percent margin hai",
            "is hafte ke top 5 selling items dikhao",
            "last 30 days ke slow moving products dikhao",
            "Britannia Marie Gold ka SKU kya hai",
            "open purchase orders dikhao",
            "meri dukan ka total inventory value kitna hai",
            "dukan me total kitni categories hain",
            "saare suppliers ki list dikhao",
            "15 august ko kitna sales hua tha",
            "is month ka best sales day kaunsa tha",
            "minimum limit se kam kitne items hain",
            "Fortune Oil ka current stock kitna hai",
            "aaj ki kamai ka summary dikhao",
            "dead stock items ka count kitna hai",
            "dukan ka registered email aur phone number kya hai",
            "safety stock days ki setting kya hai",

            // Lookups containing plan / increase / tips in Hinglish
            "is quarter ka sales plan dikhao",
            "dukan ka configured sales plan kya hai",
            "is mahine kaunse items ka price badha",
            "margin increased wale items ki list dikhao",
            "jin saman ki sales badhi wo dikhao",
            "reorder plan list dikhao",
            "biscuits ka safety stock plan kya hai",
            "monthly sales growth report dikhao",
            "discount wale items ki list dikhao",
            "kal kis item ka stock kam hua tha",
            "competitor matched products ki list dikhao",
            "promotional price list dikhao",
            "customers ka loyalty balance kitna hai",
            "kis category me sabse zyada growth hui",
            "reorder plan me shamil items ka stock count do",

            // App feature help in Hinglish
            "excel file se items import kaise kare",
            "app me sale kaise record kare",
            "camera se barcode scan kaise kare",
            "naya supplier kaise add kare",
            "inventory ko csv me kaise export kare",
            "settings me low stock alert kaise badle",
            "stocksense me new order kaise banaye",

            // Short Hinglish lookups
            "aaj ka galla kitna",
            "kal ki kamai",
            "stk count kitna hai",
            "low stk dikhao",
            "reorder suchi",
            "gst report dikhao",
            "profit report chahiye",
            "bill count aaj ka",
            "parle g ka rate",
            "amul milk kitna bacha",
            "dukan ka hisab",
            "stock list chahiye",
            "sales summary dikhao",
            "supplier contact list",
            "out of stock list chahiye",

            // Structured queries in Hinglish
            "daily sales hisab dikhao",
            "monthly turnover report do",
            "inventory register check karo",
            "order #104 status kya hai",
            "supplier phone number list",
            "shop profile settings dikhao",
            "slow velocity items list with quantity",
            "barcode 8901030383921 stock check",
            "dairy items ka expiry date dikhao",
            "total inventory balance kitna hai"
        ]
    ],

    // =========================================================================
    // 5. TANGLISH (ta_latin, Tamil in Latin Script)
    // =========================================================================
    'ta_latin' => [
        'advice' => [
            // Statements of problems / worries / goals (Requirement 3)
            "vyapaaram sariya illa",
            "vyaparam seri illa",
            "kadai seriya pogala",
            "virpanai koranjiduchu",
            "sales down aayiduchu",
            "customers varala",
            "aale varala kadai ku",
            "kootam romba kuranjiduchu",
            "profit varala enna seyyalam",
            "laabam kidaikala romba naala",
            "loss aagudhu ovvoru vaaramum",
            "porul vikkama kedakku",
            "saman pada kedakku",
            "paisa stock la maatikituchu",
            "pakkathula pudhu supermarket vandhuruchu",
            "adutha kadai kaaran heavy discount tharan",
            "stagnant stock nala cash flow block aachu",
            "weekend la mukkiya porul stock out aagudhu",
            "supplier price yethitan profit kuranjuduchu",
            "daily sales koranjitte pogudhu",
            "customers rate adhigam nu complain panranga",
            "evening la aale irukradhu illa",
            "inventory circulation romba slow ah irukku",
            "virpanai aagadha nala pana kashtam",
            "sales paduthuduchu",
            "customers illa",
            "profit zero",
            "kadai dull",
            "nashtam varudhu",

            // Active How-To / Strategy / Planning
            "sales increase panna trick sollunga",
            "profit margin improve panna enna seyyalam",
            "dead stock kuraikka yosani enna",
            "slow moving items ku discount tharalama",
            "pakkathula supermarket vantha enna strategy use pannanum",
            "customers adhigam vara enna pannanum",
            "cash flow manage panna tips sollunga",
            "fast moving items stock out aagama epdi pathukradhu",
            "supplier kita payment terms epdi negotiate panradhu",
            "grocery items ku combo offer epdi create panradhu",
            "vikkadha items epdi clear panradhu",
            "shop expense kuraikka enna vazhi",
            "regular customers loyalty improve panna tips",
            "supermarket competition ku pricing epdi set panradhu",
            "deepavali season ku stock plan epdi panradhu",
            "romba naala vikkadha porul ku enna pannanum",
            "idle stock ah cash ah matha enna trick",
            "weekend discount scheme podalama vendama",
            "turnover increase panna entha category focus pannanum",
            "customer basket size adhigam aaga ideas",
            "sales down aachu na enna pannanum",
            "supplier rate yethuna enna seyyalam",
            "pudhu grocery porul promote panna vazhi",
            "customer points program epdi start panradhu",
            "azhugum porul wastage epdi thadukradhu",

            // Vague phrases / short queries
            "alosanai sollunga",
            "sales boost panna vazhi",
            "supermarket competition ideas",
            "dead stock clear panna trick",
            "turnover increase plan",
            "margin improve tips",
            "footfall increase panna vazhi",
            "festival sale yosani",
            "combo pack ideas",
            "cash flow tips",
            "growth advice sollunga",
            "nashtam thadukka vazhi",
            "customer retain panna vazhi",
            "kadai munnetra idea",
            "retail growth trick",

            // Typos & colloquial
            "seles incres panrathu epdi",
            "costomer varla kadaku",
            "proft improve pana tip",
            "ded stok kuraika enna vazhi",
            "supr mrket open aaiduchu",
            "slow porul ku discnt tharlaama",
            "eb bil kuraika enna seyanum",
            "csh flow mange pana tip",
            "daly sels romba low",
            "los thaduka yosana",
            "faste selling porul theernthuduchu",
            "cmbo offer epdi podanm",
            "loyality pnts epdi kudukradhu",
            "kadayil kootm vara idea",
            "kadai nalla valara vazhi"
        ],
        'lookup' => [
            // Exact queries from user prompt & direct metrics
            "low stock list thevai",
            "reorder list kaatunga",
            "sales plan kaatunga",
            "sales plan enna",
            "entha items sales increased intha vaaram",

            // Numbers, quantities, counts in Tanglish
            "inniku sales evvalavu",
            "netru total sales evlo aachu",
            "Amul Butter ethana packet irukku",
            "low stock items kaatunga",
            "out of stock porul list kudunga",
            "pona maasam total collection evvalavu",
            "Parle-G biscuit ethana packet bachi irukku",
            "Tata Salt selling price enna",
            "Aashirvaad Atta cost and selling price kaatunga",
            "Maggi Noodles margin percentage evvalavu",
            "intha vaaram top 5 selling items sollunga",
            "last 30 days la slow moving products kaatunga",
            "Britannia Marie Gold SKU enna",
            "open purchase orders list kaatunga",
            "kadaiyoda total stock value evvalavu",
            "kadai la total ethana category irukku",
            "ella suppliers list kaatunga",
            "august 15 sales evvalavu aachu",
            "intha maasam best sales day edhu",
            "minimum limit kuraiva ethana item irukku",
            "Fortune Oil current stock evvalavu",
            "inniku revenue summary kudunga",
            "dead stock items count evlo",
            "kadai registered email and phone number enna",
            "safety stock days setting enna",

            // Lookups containing plan / increase / tips in Tanglish
            "intha quarter sales plan kaatunga",
            "kadai configured sales plan enna",
            "intha maasam entha items price yerirukku",
            "margin increase aana items list kaatunga",
            "sales increase aana porutkal kaatunga",
            "reorder plan list kaatunga",
            "biscuits ku safety stock plan enna",
            "monthly sales growth report kudunga",
            "discount kudukka vendiya items list kaatunga",
            "netru entha item stock koranjudhu",
            "competitor matched products list kaatunga",
            "promotional price list kaatunga",
            "customers loyalty balance evlo",
            "entha category la highest growth aachu",
            "reorder plan la irukra items count kudunga",

            // App feature help in Tanglish
            "excel file la irundhu items import eppadi panradhu",
            "app la sale eppadi record pannanum",
            "camera moolam barcode scan eppadi panradhu",
            "pudhu supplier eppadi add panradhu",
            "inventory ah csv la export eppadi panradhu",
            "settings la low stock alert epdi mathuradhu",
            "stocksense la new order epdi create panradhu",

            // Short Tanglish lookups
            "inniku collection",
            "netru sales evlo",
            "stk count enna",
            "low stk kaatunga",
            "reorder list thevai",
            "gst report kaatunga",
            "profit report kudunga",
            "inniku bill count",
            "parle g rate enna",
            "amul milk evlo irukku",
            "kadai hisab",
            "stock list venum",
            "sales summary kaatunga",
            "supplier details list",
            "out of stock list kudunga",

            // Structured queries in Tanglish
            "daily sales hisab sollunga",
            "monthly turnover report kudunga",
            "inventory register check pannunga",
            "order #104 status enna",
            "supplier phone number list kaatunga",
            "shop profile settings enna",
            "slow moving items list with count",
            "barcode 8901030383921 stock check",
            "dairy items expiry date kaatunga",
            "total inventory balance evvalavu"
        ]
    ]
];

echo "========================================================================\n";
echo "DUKAAN MITRA QUESTION-TYPE CLASSIFIER EVALUATION SUITE\n";
echo "Semantic Understanding Benchmark (Lookup vs Advice)\n";
echo "========================================================================\n\n";

$overallTP = 0;
$overallTN = 0;
$overallFP = 0;
$overallFN = 0;

$langMetrics = [];

foreach ($testData as $lang => $categories) {
    $tp = 0; // Actual advice, predicted advice
    $fn = 0; // Actual advice, predicted lookup
    $tn = 0; // Actual lookup, predicted lookup
    $fp = 0; // Actual lookup, predicted advice

    $falsePositives = []; // Lookups wrongly classified as Advice
    $falseNegatives = []; // Advice wrongly classified as Lookup

    // 1. Evaluate Advice queries
    foreach ($categories['advice'] as $q) {
        $norm = IntentEngine::normalize($q);
        $res = IntentEngine::classifyQuestionTypeWithConfidence($q, $norm);
        $predType = $res['type'];

        if ($predType === 'advice') {
            $tp++;
        } else {
            $fn++;
            $falseNegatives[] = [
                'query' => $q,
                'predicted' => $predType,
                'conf' => $res['confidence'] ?? 0.0,
                'source' => $res['source'] ?? 'unknown'
            ];
        }
    }

    // 2. Evaluate Lookup queries
    foreach ($categories['lookup'] as $q) {
        $norm = IntentEngine::normalize($q);
        $res = IntentEngine::classifyQuestionTypeWithConfidence($q, $norm);
        $predType = $res['type'];

        if ($predType === 'lookup' || $predType === 'general_knowledge' || $predType === 'conversation') {
            // Non-advice is correctly not treated as advice
            $tn++;
        } else {
            $fp++;
            $falsePositives[] = [
                'query' => $q,
                'predicted' => $predType,
                'conf' => $res['confidence'] ?? 0.0,
                'source' => $res['source'] ?? 'unknown'
            ];
        }
    }

    $totalAdvice = count($categories['advice']);
    $totalLookup = count($categories['lookup']);
    $totalLang = $totalAdvice + $totalLookup;
    $accuracy = (($tp + $tn) / $totalLang) * 100.0;
    $precision = ($tp + $fp) > 0 ? ($tp / ($tp + $fp)) * 100.0 : 0.0;
    $recall = ($tp + $fn) > 0 ? ($tp / ($tp + $fn)) * 100.0 : 0.0;

    $langMetrics[$lang] = [
        'total' => $totalLang,
        'advice' => $totalAdvice,
        'lookup' => $totalLookup,
        'tp' => $tp,
        'tn' => $tn,
        'fp' => $fp,
        'fn' => $fn,
        'accuracy' => $accuracy,
        'precision' => $precision,
        'recall' => $recall,
        'fps' => $falsePositives,
        'fns' => $falseNegatives
    ];

    $overallTP += $tp;
    $overallTN += $tn;
    $overallFP += $fp;
    $overallFN += $fn;

    echo "------------------------------------------------------------------------\n";
    echo sprintf("LANGUAGE: %-10s (Total Queries: %d | Advice: %d, Lookup: %d)\n", strtoupper($lang), $totalLang, $totalAdvice, $totalLookup);
    echo "------------------------------------------------------------------------\n";
    echo "Confusion Matrix (Lookup vs Advice):\n";
    echo sprintf("                  Pred Lookup  |  Pred Advice\n");
    echo sprintf("  Actual Lookup :     %4d      |      %4d    (FP: %d)\n", $tn, $fp, $fp);
    echo sprintf("  Actual Advice :     %4d      |      %4d    (FN: %d)\n", $fn, $tp, $fn);
    echo sprintf("\n  Accuracy  : %6.2f%% (Target: >= 95.00%%) -> %s\n", $accuracy, $accuracy >= 95.0 ? "[PASSED]" : "[FAILED]");
    echo sprintf("  Precision : %6.2f%%\n", $precision);
    echo sprintf("  Recall    : %6.2f%%\n", $recall);

    if (!empty($falsePositives)) {
        echo "\n  False Positives (Lookup predicted as Advice):\n";
        foreach ($falsePositives as $item) {
            echo sprintf("    - [FP] \"%s\" -> %s (conf: %.2f, src: %s)\n", $item['query'], $item['predicted'], $item['conf'], $item['source']);
        }
    } else {
        echo "  False Positives : 0 (None)\n";
    }

    if (!empty($falseNegatives)) {
        echo "\n  False Negatives (Advice predicted as Lookup):\n";
        foreach ($falseNegatives as $item) {
            echo sprintf("    - [FN] \"%s\" -> %s (conf: %.2f, src: %s)\n", $item['query'], $item['predicted'], $item['conf'], $item['source']);
        }
    } else {
        echo "  False Negatives : 0 (None)\n";
    }
    echo "\n";
}

$grandTotal = $overallTP + $overallTN + $overallFP + $overallFN;
$overallAccuracy = (($overallTP + $overallTN) / $grandTotal) * 100.0;
$overallPrecision = ($overallTP + $overallFP) > 0 ? ($overallTP / ($overallTP + $overallFP)) * 100.0 : 0.0;
$overallRecall = ($overallTP + $overallFN) > 0 ? ($overallTP / ($overallTP + $overallFN)) * 100.0 : 0.0;

echo "========================================================================\n";
echo "OVERALL BENCHMARK RESULTS (ALL 5 LANGUAGES)\n";
echo "========================================================================\n";
echo "Overall Confusion Matrix:\n";
echo sprintf("                  Pred Lookup  |  Pred Advice\n");
echo sprintf("  Actual Lookup :     %4d      |      %4d    (Total FP: %d)\n", $overallTN, $overallFP, $overallFP);
echo sprintf("  Actual Advice :     %4d      |      %4d    (Total FN: %d)\n", $overallFN, $overallTP, $overallFN);
echo "------------------------------------------------------------------------\n";
echo sprintf("Total Evaluated Queries : %d\n", $grandTotal);
echo sprintf("Overall Accuracy        : %6.2f%% (Target: >= 95.00%%) -> %s\n", $overallAccuracy, $overallAccuracy >= 95.0 ? "[PASSED]" : "[FAILED]");
echo sprintf("Overall Precision       : %6.2f%%\n", $overallPrecision);
echo sprintf("Overall Recall          : %6.2f%%\n", $overallRecall);
echo "========================================================================\n";
