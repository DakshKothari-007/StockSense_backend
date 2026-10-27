<?php

require_once __DIR__ . "/../config/db.php";

/**
 * Authenticates the request and returns the shop owner record.
 * Supports:
 *  1. Authorization: Bearer <token_or_id_or_email>
 *  2. Custom Header: X-User-Id or X-User-Email
 *  3. Query param: user_id or email
 *  4. JSON Body: user_id or email
 *
 * Ensures all actions and data queries are strictly scoped to the authenticated owner.
 */
function authenticateShopOwner(PDO $pdo): array {
    $token = null;

    // 1. Check HTTP Authorization header
    $headers = [];
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } else {
        foreach ($_SERVER as $key => $value) {
            if (substr($key, 0, 5) === 'HTTP_') {
                $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
                $headers[$header] = $value;
            }
        }
    }

    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
        $token = trim($matches[1]);
    }

    // 2. Custom headers
    if (!$token) {
        $token = $headers['X-User-Id'] ?? $headers['x-user-id'] ?? $headers['X-User-Email'] ?? $headers['x-user-email'] ?? null;
    }

    // 3. GET parameter
    if (!$token) {
        $token = $_GET['user_id'] ?? $_GET['id'] ?? $_GET['email'] ?? null;
    }

    // 4. POST JSON body
    if (!$token && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents("php://input");
        $body = json_decode($raw, true);
        if (is_array($body)) {
            $token = $body['user_id'] ?? $body['id'] ?? $body['email'] ?? null;
        }
    }

    if (!$token) {
        http_response_code(401);
        echo json_encode([
            "status" => false,
            "message" => "Unauthorized: Authentication required. Provide Bearer token or user identifier.",
            "data" => (object)[]
        ]);
        exit;
    }

    try {
        if (is_numeric($token)) {
            $stmt = $pdo->prepare("SELECT id, full_name, shop_name, email FROM owner_account WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$token]);
        } else {
            $stmt = $pdo->prepare("SELECT id, full_name, shop_name, email FROM owner_account WHERE email = ? LIMIT 1");
            $stmt->execute([strtolower(trim($token))]);
        }

        $owner = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$owner) {
            http_response_code(401);
            echo json_encode([
                "status" => false,
                "message" => "Unauthorized: Invalid credentials or shop account not found.",
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
