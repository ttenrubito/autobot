<?php
/**
 * Migration: Backfill platform_user_id from existing data
 * 
 * For payments: extract external_user_id from payment_details JSON
 * For orders/repairs/pawns: use existing external_user_id column
 * 
 * Run: curl "https://autobot.boxdesign.in.th/api/migrations/backfill-platform-user-id.php?debug_key=autobot2026&execute=1"
 */

require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

if (($_GET['debug_key'] ?? '') !== 'autobot2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$execute = ($_GET['execute'] ?? '') === '1';
$pdo = getDB();
$results = [];

try {
    // 1. Backfill payments.platform_user_id from payment_details JSON
    $sql = "
        UPDATE payments 
        SET platform_user_id = JSON_UNQUOTE(JSON_EXTRACT(payment_details, '\$.external_user_id'))
        WHERE platform_user_id IS NULL 
          AND payment_details IS NOT NULL 
          AND JSON_EXTRACT(payment_details, '\$.external_user_id') IS NOT NULL
    ";
    
    if ($execute) {
        $affected = $pdo->exec($sql);
        $results['payments'] = ['status' => 'DONE', 'affected_rows' => $affected];
    } else {
        // Count how many would be affected
        $countSql = "
            SELECT COUNT(*) as cnt FROM payments 
            WHERE platform_user_id IS NULL 
              AND payment_details IS NOT NULL 
              AND JSON_EXTRACT(payment_details, '\$.external_user_id') IS NOT NULL
        ";
        $stmt = $pdo->query($countSql);
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
        $results['payments'] = ['status' => 'DRY_RUN', 'would_affect' => $count, 'sql' => $sql];
    }
    
    // 2. Backfill orders.platform_user_id from external_user_id (if column exists)
    $tables = ['orders', 'repairs', 'pawns'];
    
    foreach ($tables as $table) {
        // Check if external_user_id column exists
        $stmt = $pdo->query("DESCRIBE `{$table}`");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (in_array('external_user_id', $columns) && in_array('platform_user_id', $columns)) {
            $sql = "UPDATE `{$table}` SET platform_user_id = external_user_id WHERE platform_user_id IS NULL AND external_user_id IS NOT NULL";
            
            if ($execute) {
                $affected = $pdo->exec($sql);
                $results[$table] = ['status' => 'DONE', 'affected_rows' => $affected];
            } else {
                $countSql = "SELECT COUNT(*) as cnt FROM `{$table}` WHERE platform_user_id IS NULL AND external_user_id IS NOT NULL";
                $stmt = $pdo->query($countSql);
                $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
                $results[$table] = ['status' => 'DRY_RUN', 'would_affect' => $count];
            }
        } else {
            $results[$table] = ['status' => 'SKIP', 'reason' => 'Missing columns'];
        }
    }
    
    // 3. Verify platform_user_id is populated
    $verification = [];
    foreach (['payments', 'orders', 'repairs', 'pawns'] as $table) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN platform_user_id IS NOT NULL THEN 1 ELSE 0 END) as with_platform_user_id FROM `{$table}`");
            $verification[$table] = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $verification[$table] = ['error' => $e->getMessage()];
        }
    }
    $results['verification'] = $verification;
    
    echo json_encode([
        'success' => true,
        'execute_mode' => $execute,
        'results' => $results
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
