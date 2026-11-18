<?php

require_once __DIR__ . '/../config/db.php';

echo "=== STOCKSENSE NOTIFICATION PRODUCT ICONS TEST SUITE ===\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($condition, $description) {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] $description\n";
        $passCount++;
    } else {
        echo "![FAIL] $description\n";
        $failCount++;
    }
}

function makeRequest($method, $path, $token = null, $data = null) {
    $url = "http://localhost/StockSense_backend/$path";
    $ch = curl_init($url);

    $headers = [
        "Content-Type: application/json",
        "Accept: application/json"
    ];

    if ($token !== null) {
        $headers[] = "Authorization: Bearer $token";
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $rawResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'raw' => $rawResponse,
        'json' => json_decode($rawResponse, true)
    ];
}

// 1. Setup Auth
$pdo->exec("DELETE FROM rate_limits");
$pdo->exec("DELETE FROM login_attempts");
$defaultHash = password_hash('password123', PASSWORD_DEFAULT);
$pdo->prepare("UPDATE owner_account SET password = ? WHERE id = 2")->execute([$defaultHash]);
$pdo->prepare("UPDATE owner_account SET password = ? WHERE id = 3")->execute([$defaultHash]);

$login2 = makeRequest('POST', 'auth/login.php', null, ["email" => "ananya@example.com", "password" => "password123"]);
$tokenShop2 = $login2['json']['token'] ?? null;
assertTest($login2['code'] === 200 && strlen($tokenShop2) === 64, "Login Shop 2 (Ananya) returns valid token");

$shop3Email = $pdo->query("SELECT email FROM owner_account WHERE id = 3")->fetchColumn() ?: "daksh@example.com";
$login3 = makeRequest('POST', 'auth/login.php', null, ["email" => $shop3Email, "password" => "password123"]);
$tokenShop3 = $login3['json']['token'] ?? null;
assertTest($login3['code'] === 200 && strlen($tokenShop3) === 64, "Login Shop 3 returns valid token");

// Enable toggles for Shop 2
$pdo->prepare("UPDATE user_settings SET low_stock_alerts = 1, ai_recommendations = 1, daily_sales_summary = 1 WHERE user_id = 2")->execute();

// 2. Clean previous test notifications
$pdo->prepare("DELETE FROM notification_states WHERE shop_owner_id = 2")->execute();
$pdo->prepare("DELETE FROM products WHERE shop_owner_id = 2 AND sku LIKE 'TEST-ICON-%'")->execute();

// Product 1: Low stock with explicit icon_key 'milk'
$stmt1 = $pdo->prepare("
    INSERT INTO products (shop_owner_id, name, sku, category, units, min_stock_threshold, price, cost_price, icon_key)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt1->execute([2, "Amul Taaza Milk 500ml", "TEST-ICON-01", "Dairy", 2, 10, 30.00, 24.00, "milk"]);
$p1Id = (int)$pdo->lastInsertId();

// Product 2: Low stock with null icon_key (legacy product)
$stmt2 = $pdo->prepare("
    INSERT INTO products (shop_owner_id, name, sku, category, units, min_stock_threshold, price, cost_price, icon_key)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt2->execute([2, "Legacy Atta 5kg", "TEST-ICON-02", "Grocery", 1, 5, 250.00, 200.00, null]);
$p2Id = (int)$pdo->lastInsertId();

// Product 3: Slow moving with explicit icon_key 'shampoo'
$stmt3 = $pdo->prepare("
    INSERT INTO products (shop_owner_id, name, sku, category, units, min_stock_threshold, price, cost_price, icon_key)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt3->execute([2, "Clinic Plus Shampoo 180ml", "TEST-ICON-03", "Personal Care", 30, 10, 120.00, 95.00, "shampoo"]);
$p3Id = (int)$pdo->lastInsertId();

// Non-product item: Import history
$pdo->prepare("INSERT INTO import_history (shop_owner_id, file_name, file_size, total_rows, success_count, failed_count) VALUES (?, ?, ?, ?, ?, ?)")
    ->execute([2, "test_icon_import.csv", 2048, 50, 45, 5]);

// 3. Test GET notifications/list.php
echo "\n3. Testing notifications/list.php response payload...\n";
$listRes = makeRequest('GET', 'notifications/list.php', $tokenShop2);
assertTest($listRes['code'] === 200, "GET notifications/list.php returns 200 OK");

$items = $listRes['json']['data']['items'] ?? [];
assertTest(!empty($items), "Notifications list is not empty");

$foundP1 = false;
$foundP2 = false;
$foundP3 = false;
$foundImport = false;

foreach ($items as $it) {
    if ($it['key'] === "low_stock:{$p1Id}") {
        $foundP1 = true;
        assertTest($it['product_id'] === $p1Id, "P1 notification has product_id = $p1Id");
        assertTest($it['product_name'] === "Amul Taaza Milk 500ml", "P1 notification has product_name 'Amul Taaza Milk 500ml'");
        assertTest($it['category'] === "Dairy", "P1 notification has category 'Dairy'");
        assertTest($it['icon_key'] === "milk", "P1 notification has icon_key 'milk'");
    }
    if ($it['key'] === "low_stock:{$p2Id}") {
        $foundP2 = true;
        assertTest($it['product_id'] === $p2Id, "P2 notification has product_id = $p2Id");
        assertTest($it['product_name'] === "Legacy Atta 5kg", "P2 notification has product_name 'Legacy Atta 5kg'");
        assertTest($it['category'] === "Grocery", "P2 notification has category 'Grocery'");
        assertTest($it['icon_key'] === null, "P2 notification with no icon has icon_key = null (triggers fallback)");
    }
    if ($it['key'] === "slow_moving:{$p3Id}") {
        $foundP3 = true;
        assertTest($it['product_id'] === $p3Id, "P3 slow-moving notification has product_id = $p3Id");
        assertTest($it['product_name'] === "Clinic Plus Shampoo 180ml", "P3 slow-moving notification has product_name");
        assertTest($it['category'] === "Personal Care", "P3 slow-moving notification has category");
        assertTest($it['icon_key'] === "shampoo", "P3 slow-moving notification has icon_key 'shampoo'");
    }
    if (str_starts_with($it['key'], "import:")) {
        $foundImport = true;
        assertTest($it['product_id'] === null, "Non-product notification has product_id = null");
        assertTest($it['product_name'] === null, "Non-product notification has product_name = null");
        assertTest($it['category'] === null, "Non-product notification has category = null");
        assertTest($it['icon_key'] === null, "Non-product notification has icon_key = null");
    }
}

assertTest($foundP1, "Found low-stock notification for P1");
assertTest($foundP2, "Found low-stock notification for P2 (legacy)");
assertTest($foundP3, "Found slow-moving notification for P3");
assertTest($foundImport, "Found non-product import notification");

// 4. Shop Isolation
echo "\n4. Testing strict store isolation for product notification join...\n";
$shop3List = makeRequest('GET', 'notifications/list.php', $tokenShop3);
assertTest($shop3List['code'] === 200, "Shop 3 notifications list returns 200");
$shop3Items = $shop3List['json']['data']['items'] ?? [];
$leakFound = false;
foreach ($shop3Items as $it) {
    if (in_array($it['product_id'], [$p1Id, $p2Id, $p3Id])) {
        $leakFound = true;
    }
}
assertTest(!$leakFound, "Shop 3 cannot see or join Shop 2's products in notifications");

// Cleanup
$pdo->prepare("DELETE FROM products WHERE shop_owner_id = 2 AND sku LIKE 'TEST-ICON-%'")->execute();
$pdo->prepare("DELETE FROM notification_states WHERE shop_owner_id = 2")->execute();

echo "\n============================================\n";
echo "TEST RESULTS: Total Passed: $passCount, Failed: $failCount\n";
echo "============================================\n";

if ($failCount > 0) {
    exit(1);
}
