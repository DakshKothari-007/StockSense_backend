<?php

/**
 * Security Hardening Verification Suite:
 *  1. .htaccess blocks HTTP access to .git/, migrations/, and docs/ (HTTP 403)
 *  2. All migration files enforce php_sapi_name() === 'cli' (and HTTP 403 in non-CLI SAPI)
 *  3. HTTP endpoints do not leak $e->getMessage() to clients (chat, explain, product_history, import)
 *  4. auth/profile.php, auth/logout.php, auth/update_profile.php, auth/update_shop.php accept
 *     Bearer tokens ONLY from the Authorization header (no ?token= or body "token" fallbacks)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI execution only.\n";
    exit(1);
}

require_once __DIR__ . '/../config/db.php';

echo "=== TEST: BACKEND SECURITY HARDENING ===\n";

$passCount = 0;
$failCount = 0;

function assertSec(bool $cond, string $label, string $detail = ''): void {
    global $passCount, $failCount;
    if ($cond) {
        echo " [PASS] {$label}\n";
        $passCount++;
    } else {
        echo "![FAIL] {$label}" . ($detail !== '' ? " - {$detail}" : "") . "\n";
        $failCount++;
    }
}

function secRequest(string $path, string $method = 'GET', ?string $authToken = null, $body = null, ?array $files = null): array {
    $url = 'http://localhost/StockSense_backend/' . ltrim($path, '/');
    $ch = curl_init($url);
    $headers = [];

    if ($files !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        $postFields = is_array($body) ? $body : [];
        foreach ($files as $k => $filePath) {
            $postFields[$k] = new CURLFile($filePath, 'application/octet-stream', basename($filePath));
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    } elseif ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        $payload = is_string($body) ? $body : json_encode($body ?? (object)[]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $headers[] = 'Content-Type: application/json';
    }

    if ($authToken !== null) {
        $headers[] = "Authorization: Bearer {$authToken}";
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $raw = (string)curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code' => $code,
        'body' => $raw,
        'json' => json_decode($raw, true)
    ];
}

$shopId = 0;
$badXlsx = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sec_bad_upload_' . time() . '.xlsx';

try {
    // -------------------------------------------------------------------------
    // 1. .htaccess directory & .git blocking
    // -------------------------------------------------------------------------
    $htaccessPaths = [
        '.git',
        '.git/',
        '.git/HEAD',
        'migrations/01_create_import_mappings_table.php',
        'migrations/08_add_avatar_to_owner_account.php',
        'docs/schema.sql',
        'docs/sample_valid_import.csv'
    ];
    foreach ($htaccessPaths as $blockedPath) {
        $res = secRequest($blockedPath);
        assertSec($res['code'] === 403, ".htaccess blocks GET /{$blockedPath} with HTTP 403", "Got HTTP {$res['code']}");
    }

    // -------------------------------------------------------------------------
    // 2. CLI-only guard on every file in migrations/ & php-cgi non-CLI test
    // -------------------------------------------------------------------------
    $migrationFiles = glob(__DIR__ . '/../migrations/*.php') ?: [];
    assertSec(count($migrationFiles) >= 8, 'Found all migration files in migrations/', 'Count: ' . count($migrationFiles));

    $phpCgi = 'C:\\xampp\\php\\php-cgi.exe';
    $hasCgi = file_exists($phpCgi);

    foreach ($migrationFiles as $migFile) {
        $fname = basename($migFile);
        $src = file_get_contents($migFile) ?: '';
        assertSec(
            strpos($src, "php_sapi_name() !== 'cli'") !== false && strpos($src, 'http_response_code(403)') !== false,
            "Migration {$fname} has CLI-only 403 guard"
        );

        if ($hasCgi) {
            $realMig = realpath($migFile) ?: $migFile;
            $cmd = 'cmd /c "set REDIRECT_STATUS=200&& set SCRIPT_FILENAME=' . $realMig . '&& set REQUEST_METHOD=GET&& "' . $phpCgi . '""';
            $out = [];
            exec($cmd, $out);
            $joined = implode("\n", $out);
            assertSec(
                strpos($joined, '403') !== false && strpos($joined, 'Forbidden: CLI execution only') !== false,
                "Migration {$fname} returns 403 when run under non-CLI SAPI (php-cgi)",
                $joined
            );
        }
    }

    // Verify CLI still runs migration idempotently
    $cliOut = [];
    $cliCode = 0;
    exec('"' . PHP_BINARY . '" "' . realpath(__DIR__ . '/../migrations/08_add_avatar_to_owner_account.php') . '"', $cliOut, $cliCode);
    assertSec($cliCode === 0, 'Migration still executes normally under CLI SAPI', implode(' | ', $cliOut));

    // -------------------------------------------------------------------------
    // 3. No exception $e->getMessage() leaked to HTTP clients
    // -------------------------------------------------------------------------
    $hardenedEndpoints = [
        'ai/chat.php',
        'ai/explain.php',
        'sales/product_history.php',
        'import/upload.php',
        'import/preview.php',
        'import/validate.php',
        'import/execute.php'
    ];
    foreach ($hardenedEndpoints as $ep) {
        $fullPath = __DIR__ . '/../' . $ep;
        $code = file_get_contents($fullPath) ?: '';
        $hasLeakingEncode = (bool)preg_match('/json_encode\s*\(\s*\[[^\]]*getMessage\s*\(/s', $code);
        assertSec(!$hasLeakingEncode, "Endpoint {$ep} does not echo \$e->getMessage() in json_encode");
        assertSec(strpos($code, 'error_log(') !== false, "Endpoint {$ep} logs exceptions via error_log()");
    }

    // Setup isolated user & token for live HTTP checks (Parts 3 & 4)
    $uniq = time() . '_' . bin2hex(random_bytes(3));
    $origEmail = "sec_owner_{$uniq}@example.com";
    $origName = 'Security Test Owner';
    $origShop = 'Security Test Store';
    $passHash = password_hash('Secret123!', PASSWORD_DEFAULT);

    $pdo->prepare("INSERT INTO owner_account (full_name, shop_name, email, password, created_at) VALUES (?, ?, ?, ?, NOW())")
        ->execute([$origName, $origShop, $origEmail, $passHash]);
    $shopId = (int)$pdo->lastInsertId();

    $token = 'sec_tok_' . bin2hex(random_bytes(28));
    $tokenHash = hash('sha256', $token);
    $pdo->prepare("INSERT INTO tokens (user_id, token_hash, expires_at, created_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())")
        ->execute([$shopId, $tokenHash]);

    // Live check: ai/explain.php error returns generic message (no exception trace/internal msg leak)
    $resExplainErr = secRequest('ai/explain.php', 'POST', $token, ['type' => 'invalid_type_x', 'product_id' => 1]);
    assertSec($resExplainErr['code'] === 400, 'POST ai/explain.php returns 400 on invalid type');

    $resExplain404 = secRequest('ai/explain.php', 'POST', $token, ['type' => 'reorder', 'product_id' => 99999999]);
    assertSec($resExplain404['code'] === 404, 'POST ai/explain.php returns 404 on missing product');

    // Live check: ai/chat.php 429 rate-limit returns generic message
    $minuteKey = "rate_min_" . date('YmdHi');
    $pdo->prepare("INSERT INTO ai_cache (shop_id, cache_key, cache_type, response_json, expires_at) VALUES (?, ?, 'rate_limit', '15', DATE_ADD(NOW(), INTERVAL 60 SECOND))")
        ->execute([$shopId, $minuteKey]);
    $resChat429 = secRequest('ai/chat.php', 'POST', $token, ['message' => 'Hello']);
    assertSec(
        $resChat429['code'] === 429 && strpos((string)($resChat429['json']['message'] ?? ''), 'Too many requests') !== false,
        'POST ai/chat.php returns generic 429 message when rate-limited',
        $resChat429['body']
    );

    // Live check: import/upload.php with corrupt .xlsx does not leak internal parser paths/exception message
    file_put_contents($badXlsx, 'NOT_A_REAL_ZIP_XLSX_FILE');
    $resBadUpload = secRequest('import/upload.php', 'POST', $token, [], ['file' => $badXlsx]);
    assertSec(
        $resBadUpload['code'] === 400 &&
        ($resBadUpload['json']['message'] ?? '') === 'Unable to read the uploaded spreadsheet. Please check the file format.',
        'POST import/upload.php returns generic message on corrupt XLSX instead of exception text',
        $resBadUpload['body']
    );

    // -------------------------------------------------------------------------
    // 4. Header-only Bearer token in profile, update_profile, update_shop, logout
    // -------------------------------------------------------------------------
    // 4a. profile.php rejects ?token= and body {"token": ...} without Authorization header
    $resProfQuery = secRequest('auth/profile.php?token=' . urlencode($token), 'GET', null);
    assertSec($resProfQuery['code'] === 401, 'GET auth/profile.php?token=... rejected with HTTP 401');

    $resProfBody = secRequest('auth/profile.php', 'POST', null, ['token' => $token]);
    assertSec($resProfBody['code'] === 401, 'POST auth/profile.php with body token rejected with HTTP 401');

    $resProfHeader = secRequest('auth/profile.php', 'GET', $token);
    assertSec(
        $resProfHeader['code'] === 200 && ($resProfHeader['json']['user']['email'] ?? '') === $origEmail,
        'GET auth/profile.php with Authorization header succeeds (HTTP 200)',
        $resProfHeader['body']
    );

    // 4b. update_profile.php rejects body {"token": ...} and ?token= without Authorization header
    $resUpdProfBody = secRequest('auth/update_profile.php?token=' . urlencode($token), 'POST', null, [
        'token' => $token,
        'full_name' => 'Tampered Name'
    ]);
    assertSec($resUpdProfBody['code'] === 401, 'POST auth/update_profile.php with body/?token fallback rejected with HTTP 401');

    $dbNameCheck = $pdo->prepare('SELECT full_name FROM owner_account WHERE id = ?');
    $dbNameCheck->execute([$shopId]);
    assertSec($dbNameCheck->fetchColumn() === $origName, 'update_profile rejected without header leaving DB unchanged');

    $resUpdProfHeader = secRequest('auth/update_profile.php', 'POST', $token, [
        'full_name' => 'Updated Header Owner'
    ]);
    assertSec(
        $resUpdProfHeader['code'] === 200 && ($resUpdProfHeader['json']['user']['full_name'] ?? '') === 'Updated Header Owner',
        'POST auth/update_profile.php with Authorization header succeeds (HTTP 200)',
        $resUpdProfHeader['body']
    );

    // 4c. update_shop.php rejects body {"token": ...} and ?token= without Authorization header
    $resUpdShopBody = secRequest('auth/update_shop.php?token=' . urlencode($token), 'POST', null, [
        'token' => $token,
        'shop_name' => 'Tampered Store'
    ]);
    assertSec($resUpdShopBody['code'] === 401, 'POST auth/update_shop.php with body/?token fallback rejected with HTTP 401');

    $dbShopCheck = $pdo->prepare('SELECT shop_name FROM owner_account WHERE id = ?');
    $dbShopCheck->execute([$shopId]);
    assertSec($dbShopCheck->fetchColumn() === $origShop, 'update_shop rejected without header leaving DB unchanged');

    $resUpdShopHeader = secRequest('auth/update_shop.php', 'POST', $token, [
        'shop_name' => 'Updated Header Store'
    ]);
    assertSec(
        $resUpdShopHeader['code'] === 200 && ($resUpdShopHeader['json']['shop']['shop_name'] ?? '') === 'Updated Header Store',
        'POST auth/update_shop.php with Authorization header succeeds (HTTP 200)',
        $resUpdShopHeader['body']
    );

    // 4d. logout.php ignores body {"token": ...} when Authorization header is absent
    secRequest('auth/logout.php?token=' . urlencode($token), 'POST', null, ['token' => $token]);
    $chkStillValid = $pdo->prepare('SELECT COUNT(*) FROM tokens WHERE token_hash = ?');
    $chkStillValid->execute([$tokenHash]);
    assertSec((int)$chkStillValid->fetchColumn() === 1, 'POST auth/logout.php without Authorization header does not revoke body/?token');

    // Valid logout via Authorization header revokes token
    $resLogoutHdr = secRequest('auth/logout.php', 'POST', $token, []);
    $chkStillValid->execute([$tokenHash]);
    assertSec(
        $resLogoutHdr['code'] === 200 && (int)$chkStillValid->fetchColumn() === 0,
        'POST auth/logout.php with Authorization header revokes token cleanly'
    );
} finally {
    @unlink($badXlsx);
    if ($shopId > 0) {
        $pdo->prepare('DELETE FROM ai_cache WHERE shop_id = ?')->execute([$shopId]);
        $pdo->prepare('DELETE FROM tokens WHERE user_id = ?')->execute([$shopId]);
        $pdo->prepare('DELETE FROM owner_account WHERE id = ?')->execute([$shopId]);
    }
}

echo "\nTotal Passed: {$passCount}, Failed: {$failCount}\n";
exit($failCount > 0 ? 1 : 0);
