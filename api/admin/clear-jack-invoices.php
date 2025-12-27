<?php
/**
 * Quick Admin API: Clear Invoices for jack@gmail.com
 * DELETE ALL invoice data and activate subscription
 */

require_once __DIR__ . '/includes/Database.php';

header('Content-Type: application/json');

try {
    $db = Database::getInstance();
    
    $email = 'jack@gmail.com';
    $results = [];
    
    // 1. Check before delete
    $before = $db->queryOne(
        "SELECT c.id, c.name, c.email, 
                COUNT(DISTINCT i.id) as invoice_count,
                SUM(i.total_amount) as total_amount
         FROM customers c
         LEFT JOIN invoices i ON c.id = i.customer_id
         WHERE c.email = ?
         GROUP BY c.id",
        [$email]
    );
    $results['before'] = $before;
    
    if (!$before) {
        echo json_encode(['error' => 'Customer not found']);
        exit;
    }
    
    $customerId = $before['id'];
    
    // 2. Delete payment_history
    $db->execute(
        "DELETE ph FROM payment_history ph
         INNER JOIN invoices i ON ph.invoice_id = i.id
         WHERE i.customer_id = ?",
        [$customerId]
    );
    $results['deleted_payment_history'] = $db->affectedRows();
    
    // 3. Delete invoice_items
    $db->execute(
        "DELETE ii FROM invoice_items ii
         INNER JOIN invoices i ON ii.invoice_id = i.id
         WHERE i.customer_id = ?",
        [$customerId]
    );
    $results['deleted_invoice_items'] = $db->affectedRows();
    
    // 4. Delete invoices
    $db->execute(
        "DELETE FROM invoices WHERE customer_id = ?",
        [$customerId]
    );
    $results['deleted_invoices'] = $db->affectedRows();
    
    // 5. Activate subscription
    $db->execute(
        "UPDATE customer_subscriptions 
         SET status = 'active',
             end_date = DATE_ADD(NOW(), INTERVAL 1 YEAR),
             updated_at = NOW()
         WHERE customer_id = ?",
        [$customerId]
    );
    $results['updated_subscriptions'] = $db->affectedRows();
    
    // 6. Check after
    $after = $db->queryOne(
        "SELECT c.id, c.name, c.email,
                COUNT(DISTINCT i.id) as invoice_count,
                s.status as subscription_status,
                s.end_date
         FROM customers c
         LEFT JOIN invoices i ON c.id = i.customer_id
         LEFT JOIN customer_subscriptions s ON c.id = s.customer_id
         WHERE c.email = ?
         GROUP BY c.id",
        [$email]
    );
    $results['after'] = $after;
    
    echo json_encode([
        'success' => true,
        'message' => 'All invoices cleared and subscription activated',
        'results' => $results
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
