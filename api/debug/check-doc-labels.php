<?php
/**
 * Simple debug: Check document_label field
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';

try {
    $db = Database::getInstance()->getPdo();
    
    // Check if column exists
    $stmt = $db->query("SHOW COLUMNS FROM application_documents LIKE 'document_label'");
    $columnExists = $stmt->fetch() !== false;
    
    // Get latest 10 documents
    $stmt = $db->query("
        SELECT 
            d.id,
            d.application_id,
            d.document_type,
            d.document_label,
            d.file_name,
            d.created_at,
            a.application_no,
            a.line_display_name
        FROM application_documents d
        LEFT JOIN line_applications a ON d.application_id = a.id
        ORDER BY d.id DESC
        LIMIT 10
    ");
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Count empty vs filled labels
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN document_label IS NULL OR document_label = '' THEN 1 ELSE 0 END) as empty_labels,
            SUM(CASE WHEN document_label IS NOT NULL AND document_label != '' THEN 1 ELSE 0 END) as filled_labels
        FROM application_documents
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'column_exists' => $columnExists,
        'stats' => $stats,
        'latest_documents' => $documents
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
