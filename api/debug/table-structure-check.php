<?php
/**
 * Debug: Check all table structures for tenant_id/user_id columns
 */

require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

if (($_GET['debug_key'] ?? '') !== 'autobot2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pdo = getDB();
$results = [];

$tables = ['orders', 'payments', 'customer_profiles', 'chat_sessions', 'chat_messages', 'cases', 'bot_channels', 'users', 'repairs', 'pawns'];

foreach ($tables as $table) {
    try {
        $stmt = $pdo->query("DESCRIBE `{$table}`");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $hasUserId = false;
        $hasTenantId = false;
        $columnNames = [];
        
        foreach ($columns as $col) {
            $columnNames[] = $col['Field'];
            if ($col['Field'] === 'user_id') $hasUserId = true;
            if ($col['Field'] === 'tenant_id') $hasTenantId = true;
        }
        
        // Get sample count
        $countStmt = $pdo->query("SELECT COUNT(*) as total FROM `{$table}`");
        $count = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Check if tenant_id has different values
        $tenantDistribution = [];
        if ($hasTenantId) {
            $distStmt = $pdo->query("SELECT tenant_id, COUNT(*) as cnt FROM `{$table}` GROUP BY tenant_id");
            $tenantDistribution = $distStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        $results[$table] = [
            'has_user_id' => $hasUserId,
            'has_tenant_id' => $hasTenantId,
            'total_rows' => $count,
            'tenant_distribution' => $tenantDistribution,
            'columns' => $columnNames
        ];
        
    } catch (Exception $e) {
        $results[$table] = ['error' => $e->getMessage()];
    }
}

echo json_encode(['success' => true, 'data' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
