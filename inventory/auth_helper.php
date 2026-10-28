<?php

require_once __DIR__ . "/../config/db.php";

/**
 * Authenticates the request using a secure Bearer token.
 *
 * Workflow:
 *  1. Extracts Bearer token from the Authorization header.
 *  2. Computes the SHA-256 hash of the token.
 *  3. Looks up a valid, non-expired entry in the `tokens` table.
 *  4. Returns the associated store owner account record.
 *
 * Rejects invalid tokens, expired tokens, raw user IDs, or email addresses with HTTP 401.
 *
 * @param PDO $pdo
 * @return array
 */
function authenticateShopOwner(PDO $pdo): array {
    $authHeader = '';

    // Check all possible header locations (Apache, Nginx, CLI, etc.)
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    if (empty($authHeader)) {
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
    }

    $token = null;
    if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
        $token = trim($matches[1]);
    }

    if (!$token) {
        http_response_code(401);
        echo json_encode([
            "status" => false,
            "message" => "Unauthorized: Authentication required. Provide a valid Bearer token.",
            "data" => (object)[]
        ]);
        exit;
    }

    try {
        // Hash the incoming token using SHA-256
        $tokenHash = hash('sha256', $token);

        // Find non-expired token matching this hash
        $stmt = $pdo->prepare("
            SELECT 
                o.id,
                o.full_name,
                o.shop_name,
                o.location,
                o.store_type,
                o.currency,
                o.email,
                t.expires_at
            FROM tokens t
            INNER JOIN owner_account o ON t.user_id = o.id
            WHERE t.token_hash = ? AND t.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$tokenHash]);
        $owner = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$owner) {
            http_response_code(401);
            echo json_encode([
                "status" => false,
                "message" => "Unauthorized: Invalid or expired token.",
                "data" => (object)[]
            ]);
            exit;
        }

        return $owner;

    } catch (Throwable $e) {
        error_log("Authentication verification failed: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            "status" => false,
            "message" => "Internal authentication error.",
            "data" => (object)[]
        ]);
        exit;
    }
}
