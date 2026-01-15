<?php
/**
 * Fix Order Status from Existing Payments - PRODUCTION
 * 
 * Connects directly to production database and recalculates order status
 * Run from command line: php fix_order_status_prod.php
 */

// Production DB credentials
$host = '34.142.150.88';
$port = 3306;
$dbname = 'autobot';
$username = 'autobot_app';
$password = 'Password@9';

echo "=== Fix Order Status (Production DB) ===\n\n";

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 10,
    ];

    echo "Connecting to {$host}:{$port}...\n";
    $pdo = new PDO($dsn, $username, $password, $options);
    echo "✅ Connected!\n\n";

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

    $orders = $stmt->fetchAll();

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
            echo "⏭️ Order #{$orderId} ({$order['order_number']}) - no change\n";
        }
    }

    echo "\n=== Complete ===\n";
    echo "Updated: {$updated} orders\n";

} catch (PDOException $e) {
    echo "❌ Database Error: " . $e->getMessage() . "\n";
    exit(1);
}
