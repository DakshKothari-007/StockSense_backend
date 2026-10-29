<?php

/**
 * Client IP address resolver
 */
function get_client_ip(): string {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (strpos($ip, ',') !== false) {
        $parts = explode(',', $ip);
        $ip = trim($parts[0]);
    }
    return $ip;
}

/**
 * Enforce rate limiting with HTTP 429 response
 */
function enforce_rate_limit(PDO $pdo, string $action, string $identifier, int $maxAttempts = 5, int $windowMinutes = 15): void {
    $ip = get_client_ip();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM rate_limits
         WHERE action = ? 
           AND (identifier = ? OR (ip_address != '' AND ip_address = ?))
           AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
    );
    $stmt->execute([$action, $identifier, $ip, $windowMinutes]);
    $count = (int) $stmt->fetchColumn();

    if ($count >= $maxAttempts) {
        http_response_code(429);
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode([
            "status" => false,
            "success" => false,
            "message" => "Too many attempts for {$action}. Please try again after {$windowMinutes} minutes.",
            "data" => null
        ]);
        exit;
    }
}

/**
 * Record a rate limit attempt (e.g. failed attempt or sensitive action)
 */
function record_rate_limit_attempt(PDO $pdo, string $action, string $identifier): void {
    $ip = get_client_ip();
    $stmt = $pdo->prepare(
        "INSERT INTO rate_limits (action, identifier, ip_address, attempted_at) VALUES (?, ?, ?, NOW())"
    );
    $stmt->execute([$action, $identifier, $ip]);
}

/**
 * Clear recorded rate limit attempts
 */
function clear_rate_limit_attempts(PDO $pdo, string $action, string $identifier): void {
    $ip = get_client_ip();
    $stmt = $pdo->prepare(
        "DELETE FROM rate_limits WHERE action = ? AND (identifier = ? OR (ip_address != '' AND ip_address = ?))"
    );
    $stmt->execute([$action, $identifier, $ip]);
}
