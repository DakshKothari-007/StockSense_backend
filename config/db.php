
<?php

// Security: Prevent leaking PHP warnings/errors to API clients
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

$host = "localhost";
$dbname = "stocksense";
$username = "root";
$password = ""; // Default XAMPP configuration

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    error_log("Database connection failure: " . $e->getMessage());

    http_response_code(500);
    header("Content-Type: application/json; charset=UTF-8");

    echo json_encode([
        "status" => false,
        "success" => false,
        "message" => "An internal database error occurred.",
        "data" => null
    ]);
    exit;
}

