<?php
/**
 * Debug Cases API - Test the query
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';

$debug = [];

try {
    $pdo = getDB();
    $debug['db_connected'] = true;
    
    // 1. Check if cases table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'cases'");
    $debug['cases_table_exists'] = $stmt->rowCount() > 0;
    
    // 2. Check tenant_id column
    $stmt = $pdo->query("SHOW COLUMNS FROM cases LIKE 'tenant_id'");
    $debug['has_tenant_id'] = $stmt->rowCount() > 0;
    
    // 3. Check customer_profiles table
    $stmt = $pdo->query("SHOW TABLES LIKE 'customer_profiles'");
    $debug['customer_profiles_exists'] = $stmt->rowCount() > 0;
    
    // 4. Check customer_profiles columns
    if ($debug['customer_profiles_exists']) {
        $stmt = $pdo->query("DESCRIBE customer_profiles");
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $debug['customer_profiles_columns'] = $cols;
        
        // Check required columns
        $debug['has_display_name'] = in_array('display_name', $cols);
        $debug['has_avatar_url'] = in_array('avatar_url', $cols);
        $debug['has_profile_pic_url'] = in_array('profile_pic_url', $cols);
    }
    
    // 5. Try the actual query from getAllCases
    $tenant_id = 'default';
    $stmt = $pdo->prepare("
        SELECT 
            c.id,
            c.case_no,
            c.case_type,
            c.platform,
            c.external_user_id,
            c.subject,
            c.description,
            c.status,
            c.priority,
            c.product_ref_id,
            c.order_id,
            c.slots,
            c.assigned_to,
            c.created_at,
            c.updated_at,
            c.resolved_at,
            cp.display_name as customer_name,
            COALESCE(cp.avatar_url, cp.profile_pic_url) as customer_avatar,
            u.full_name as assigned_to_name
        FROM cases c
        LEFT JOIN customer_profiles cp ON c.external_user_id = cp.platform_user_id AND c.platform = cp.platform
        LEFT JOIN users u ON c.assigned_to = u.id
        WHERE c.tenant_id = ?
        ORDER BY c.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$tenant_id]);
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $debug['query_works'] = true;
    $debug['cases_count'] = count($cases);
    $debug['sample_cases'] = $cases;
    
    // 6. Check users table for tenant_id
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'tenant_id'");
    $debug['users_has_tenant_id'] = $stmt->rowCount() > 0;
    
    echo json_encode(['success' => true, 'debug' => $debug], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    $debug['error'] = $e->getMessage();
    $debug['error_trace'] = $e->getTraceAsString();
    echo json_encode(['success' => false, 'debug' => $debug], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
