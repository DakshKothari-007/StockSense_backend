<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-User-Id, X-User-Email");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../inventory/auth_helper.php";

$owner = authenticateShopOwner($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        "status" => false,
        "message" => "Method Not Allowed. Use GET to view import history.",
        "data" => (object)[]
    ]);
    exit;
}

$ownerId = (int)$owner['id'];
$limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 20;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM import_history WHERE shop_owner_id = ?");
    $countStmt->execute([$ownerId]);
    $totalCount = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT id, shop_owner_id, file_name, file_size, total_rows, success_count, failed_count, status, details, created_at
        FROM import_history
        WHERE shop_owner_id = ?
        ORDER BY created_at DESC, id DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bindValue(1, $ownerId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $history = [];
    foreach ($rows as $r) {
        $fileSizeBytes = (int)$r['file_size'];
        $formattedSize = ($fileSizeBytes >= 1048576)
            ? round($fileSizeBytes / 1048576, 2) . " MB"
            : max(1, round($fileSizeBytes / 1024)) . " KB";

        $details = null;
        if (!empty($r['details'])) {
            $decoded = json_decode($r['details'], true);
            $details = is_array($decoded) ? $decoded : $r['details'];
        }

        $history[] = [
            "id" => (int)$r['id'],
            "file_name" => $r['file_name'],
            "file_size" => $formattedSize,
            "file_size_bytes" => $fileSizeBytes,
            "total_rows" => (int)$r['total_rows'],
            "success_count" => (int)$r['success_count'],
            "failed_count" => (int)$r['failed_count'],
            "status" => $r['status'],
            "details" => $details,
            "created_at" => $r['created_at']
        ];
    }

    http_response_code(200);
    echo json_encode([
        "status" => true,
        "message" => "Import history retrieved successfully",
        "data" => [
            "total_imports" => $totalCount,
            "count" => count($history),
            "limit" => $limit,
            "offset" => $offset,
            "history" => $history
        ]
    ]);

} catch (Throwable $e) {
    error_log("Failed to fetch import history: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Internal server error while fetching import history.",
        "data" => (object)[]
    ]);
}
