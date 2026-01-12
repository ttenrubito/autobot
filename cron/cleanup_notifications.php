<?php
/**
 * Cleanup Old Notifications
 * 
 * Run daily to clean up:
 * - Sent notifications older than 30 days
 * - Failed notifications older than 7 days
 */

if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

require_once __DIR__ . '/../includes/Database.php';

$db = Database::getInstance();

echo "[" . date('Y-m-d H:i:s') . "] Starting cleanup...\n";

// Delete sent notifications older than 30 days
$result = $db->execute(
    "DELETE FROM scheduled_notifications WHERE status = 'sent' AND sent_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
);
echo "Deleted old sent notifications\n";

// Delete failed notifications older than 7 days
$result = $db->execute(
    "DELETE FROM scheduled_notifications WHERE status = 'failed' AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
);
echo "Deleted old failed notifications\n";

// Reset stuck 'processing' notifications (older than 1 hour)
$result = $db->execute(
    "UPDATE scheduled_notifications SET status = 'pending' WHERE status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)"
);
echo "Reset stuck notifications\n";

echo "[" . date('Y-m-d H:i:s') . "] Cleanup complete!\n";
