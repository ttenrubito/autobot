<?php
/**
 * Fix Campaign Config API
 * Simple endpoint to update campaign required_documents
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';

try {
    $db = Database::getInstance()->getPdo();
    
    // Update campaign
    $requiredDocs = [
        ['type' => 'id_card', 'label' => 'บัตรประชาชน', 'required' => true, 'accept' => 'image/*'],
        ['type' => 'house_registration', 'label' => 'ทะเบียนบ้าน', 'required' => false, 'accept' => 'image/*,application/pdf']
    ];
    
    $stmt = $db->prepare("UPDATE campaigns SET required_documents = ? WHERE code = 'DEMO2026'");
    $stmt->execute([json_encode($requiredDocs, JSON_UNESCAPED_UNICODE)]);
    
    // Verify
    $stmt = $db->prepare("SELECT required_documents FROM campaigns WHERE code = 'DEMO2026'");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'message' => 'Campaign updated',
        'required_documents' => json_decode($result['required_documents'], true)
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
