<?php
/**
 * Fix Order Status from Existing Payments
 * 
 * Run once to recalculate paid_amount and status for all orders
 * that have verified payments.
 * 
 * Usage: Access via browser with ?run=1&confirm=yes
 */

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../config.php';

// Safety check
$run = $_GET['run'] ?? '';
$confirm = $_GET['confirm'] ?? '';

if ($run !== '1' || $confirm !== 'yes') {
    echo "=== Fix Order Status Script ===\n\n";
    echo "This script will recalculate paid_amount and status for all orders.\n\n";
    echo "To run, add: ?run=1&confirm=yes\n";
    exit;
}

try {
    $pdo = getDB();

    echo "=== Starting Order Status Fix ===\n\n";

    // Get all orders that have payments
    $stmt = $pdo->query("
        SELECT 
            o.id,
            o.order_number,
            o.total_amount,
            o.paid_amount as old_paid,
            o.remaining_amount as old_remaining,
            o.status as old_status,
            COALESCE(SUM(CASE WHEN p.status = 'verified' THEN p.amount ELSE 0 END), 0) as new_paid
        FROM orders o
        LEFT JOIN payments p ON p.order_id = o.id
        GROUP BY o.id
        HAVING new_paid > 0 OR o.paid_amount > 0
        ORDER BY o.id DESC
    ");

    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($orders) . " orders with payments\n\n";

    $updated = 0;

    foreach ($orders as $order) {
        $orderId = $order['id'];
        $totalAmount = (float) $order['total_amount'];
        $newPaid = (float) $order['new_paid'];
        $newRemaining = max(0, $totalAmount - $newPaid);

        // Determine new status
        $newPaymentStatus = 'pending';
        if ($newPaid >= $totalAmount) {
            $newPaymentStatus = 'paid';
        } elseif ($newPaid > 0) {
            $newPaymentStatus = 'partial';
        }

        $newStatus = $order['old_status'];
        if ($newPaid >= $totalAmount) {
            $newStatus = 'paid';
        } elseif (in_array($order['old_status'], ['pending', 'draft', 'pending_payment']) && $newPaid > 0) {
            $newStatus = 'processing';
        }

        // Only update if something changed
        if ($order['old_paid'] != $newPaid || $order['old_status'] != $newStatus) {
            $updateStmt = $pdo->prepare("
                UPDATE orders 
                SET paid_amount = ?,
                    remaining_amount = ?,
                    payment_status = ?,
                    status = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");

            $updateStmt->execute([
                $newPaid,
                $newRemaining,
                $newPaymentStatus,
                $newStatus,
                $orderId
            ]);

            echo "✅ Order #{$orderId} ({$order['order_number']})\n";
            echo "   paid: {$order['old_paid']} → {$newPaid}\n";
            echo "   remaining: {$order['old_remaining']} → {$newRemaining}\n";
            echo "   status: {$order['old_status']} → {$newStatus}\n\n";

            $updated++;
        } else {
            echo "⏭️ Order #{$orderId} ({$order['order_number']}) - no change needed\n";
        }
    }

    echo "\n=== Complete ===\n";
    echo "Updated: {$updated} orders\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
