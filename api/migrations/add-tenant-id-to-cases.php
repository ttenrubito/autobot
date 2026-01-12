<?php
/**
 * Migration: Add tenant_id to cases table
 * Run via: curl https://autobot.boxdesign.in.th/api/migrations/add-tenant-id-to-cases.php
 */

require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

try {
    $pdo = getDB();
    
    // Check if tenant_id column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM cases LIKE 'tenant_id'");
    $exists = $stmt->fetch();
    
    if ($exists) {
        echo json_encode([
            'success' => true,
            'message' => 'tenant_id column already exists in cases table',
            'action' => 'none'
        ]);
        exit;
    }
    
    // Add tenant_id column
    $pdo->exec("ALTER TABLE cases ADD COLUMN tenant_id VARCHAR(50) NOT NULL DEFAULT 'default' AFTER case_no");
    
    // Add index
    try {
        $pdo->exec("CREATE INDEX idx_cases_tenant_id ON cases(tenant_id)");
    } catch (PDOException $e) {
        // Index might already exist
    }
    
    // Update existing cases with tenant_id from their channel's owner
    $updated = $pdo->exec("
        UPDATE cases c
        JOIN customer_channels ch ON c.channel_id = ch.id
        JOIN users u ON ch.user_id = u.id
        SET c.tenant_id = COALESCE(u.tenant_id, 'default')
        WHERE c.tenant_id = 'default'
    ");
    
    echo json_encode([
        'success' => true,
        'message' => 'tenant_id column added to cases table successfully',
        'action' => 'added',
        'updated_rows' => $updated
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
