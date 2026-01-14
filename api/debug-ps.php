<?php
/**
 * Debug: Check PaymentService version in production
 */
header('Content-Type: application/json');

$key = $_GET['key'] ?? '';
if ($key !== 'debug-ps-2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$file = __DIR__ . '/../includes/services/PaymentService.php';
$content = file_get_contents($file);

// Check what columns are in INSERT statement
preg_match('/INSERT INTO payments \((.*?)\) VALUES/s', $content, $matches);
$columns = $matches[1] ?? 'NOT FOUND';

// Check if user_id is in the file
$hasUserId = strpos($content, "'user_id'") !== false || strpos($content, ":user_id") !== false;

// Get line count
$lineCount = count(explode("\n", $content));

// Get lines 130-180 (INSERT statement area)
$lines = explode("\n", $content);
$insertArea = array_slice($lines, 130, 50);

// Get checkDuplicate area (lines 225-250)
$checkDuplicateArea = array_slice($lines, 225, 30);

// Get findCustomer area (lines 250-280)
$findCustomerArea = array_slice($lines, 250, 30);

echo json_encode([
    'success' => true,
    'file_exists' => file_exists($file),
    'line_count' => $lineCount,
    'has_user_id_param' => $hasUserId,
    'insert_columns' => trim($columns),
    'insert_area_lines_131_180' => implode("\n", $insertArea),
    'checkDuplicate_lines_226_255' => implode("\n", $checkDuplicateArea),
    'findCustomer_lines_251_280' => implode("\n", $findCustomerArea)
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
