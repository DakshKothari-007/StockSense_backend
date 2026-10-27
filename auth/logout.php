<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// In stateless / mobile auth, logout cleans up client session or tokens.
http_response_code(200);
echo json_encode([
    "success" => true,
    "message" => "Signed out successfully."
]);
?>
