<?php
// Test file to verify API access
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'message' => 'API is accessible!',
    'path' => __FILE__,
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'
]);
