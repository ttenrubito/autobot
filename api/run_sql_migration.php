<?php
/**
 * SQL Migration Runner
 * Run via: /api/run_sql_migration.php?key=migrate2025
 */

// Security: Only allow from specific IPs or with secret key
$secret = $_GET['key'] ?? '';
if ($secret !== 'migrate2025') {
    die('Unauthorized');
}

require_once __DIR__ . '/../config.php';
$pdo = getDB();

$migrations = [];

// 1. Add pawn_redemption to payment_type enum
try {
    $pdo->exec("ALTER TABLE payments MODIFY COLUMN payment_type 
        ENUM('full','installment','deposit','savings','deposit_interest','deposit_savings','pawn_redemption') 
        NOT NULL DEFAULT 'full'");
    $migrations[] = ['migration' => 'payment_type enum', 'status' => 'success'];
} catch (Exception $e) {
    $migrations[] = ['migration' => 'payment_type enum', 'status' => 'skipped', 'reason' => $e->getMessage()];
}

// 2. Update pawn_payments that have no verified_at to be verified (legacy data fix)
try {
    $result = $pdo->exec("UPDATE pawn_payments SET verified_at = created_at WHERE verified_at IS NULL");
    $migrations[] = ['migration' => 'pawn_payments verified_at fix', 'status' => 'success', 'rows_updated' => $result];
} catch (Exception $e) {
    $migrations[] = ['migration' => 'pawn_payments verified_at fix', 'status' => 'failed', 'reason' => $e->getMessage()];
}

// 3. Add last_reminder_sent column to pawns for overdue notifications
try {
    $pdo->exec("ALTER TABLE pawns ADD COLUMN last_reminder_sent TIMESTAMP NULL DEFAULT NULL");
    $migrations[] = ['migration' => 'pawns last_reminder_sent column', 'status' => 'success'];
} catch (Exception $e) {
    // Column may already exist
    $migrations[] = ['migration' => 'pawns last_reminder_sent column', 'status' => 'skipped', 'reason' => $e->getMessage()];
}

header('Content-Type: application/json');
echo json_encode(['success' => true, 'migrations' => $migrations]);
