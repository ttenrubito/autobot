<?php
/**
 * Check Migration Status
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../config.php';

try {
    $pdo = getDB();
    
    // Check which tables exist
    $tables = ['cases', 'case_activities', 'savings_accounts', 'savings_transactions', 
               'savings_goals', 'installment_contracts', 'payments', 'orders', 'users'];
    $status = [];
    
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        $status[$table] = $stmt->rowCount() > 0 ? 'exists' : 'missing';
    }
    
    // Check columns on payments table
    $paymentsColumns = [];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM payments");
        while ($row = $stmt->fetch()) {
            $paymentsColumns[] = $row['Field'];
        }
    } catch (Exception $e) {
        $paymentsColumns = ['error' => $e->getMessage()];
    }
    $status['payments_columns'] = $paymentsColumns;
    
    // Check columns on orders table
    $ordersColumns = [];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM orders WHERE Field IN ('product_ref_id', 'deposit_amount', 'savings_account_id')");
        while ($row = $stmt->fetch()) {
            $ordersColumns[] = $row['Field'];
        }
    } catch (Exception $e) {
        $ordersColumns = ['error' => $e->getMessage()];
    }
    $status['orders_new_columns'] = $ordersColumns;
    
    // Check columns on chat_sessions table
    $sessionColumns = [];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM chat_sessions WHERE Field IN ('active_case_id', 'active_case_type')");
        while ($row = $stmt->fetch()) {
            $sessionColumns[] = $row['Field'];
        }
    } catch (Exception $e) {
        $sessionColumns = ['error' => $e->getMessage()];
    }
    $status['chat_sessions_new_columns'] = $sessionColumns;
    
    // Check columns on users table
    $usersColumns = [];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users");
        while ($row = $stmt->fetch()) {
            $usersColumns[] = $row['Field'];
        }
    } catch (Exception $e) {
        $usersColumns = ['error' => $e->getMessage()];
    }
    $status['users_columns'] = $usersColumns;
    
    // Check columns on pawns table
    $pawnsColumns = [];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM pawns");
        while ($row = $stmt->fetch()) {
            $pawnsColumns[] = $row['Field'];
        }
    } catch (Exception $e) {
        $pawnsColumns = ['error' => $e->getMessage()];
    }
    $status['pawns_columns'] = $pawnsColumns;
    
    // Check columns on deposits table
    $depositsColumns = [];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM deposits");
        while ($row = $stmt->fetch()) {
            $depositsColumns[] = $row['Field'];
        }
    } catch (Exception $e) {
        $depositsColumns = ['error' => $e->getMessage()];
    }
    $status['deposits_columns'] = $depositsColumns;
    
    // Check columns on repairs table
    $repairsColumns = [];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM repairs");
        while ($row = $stmt->fetch()) {
            $repairsColumns[] = $row['Field'];
        }
    } catch (Exception $e) {
        $repairsColumns = ['error' => $e->getMessage()];
    }
    $status['repairs_columns'] = $repairsColumns;
    
    echo json_encode([
        'success' => true,
        'migration_status' => $status
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
