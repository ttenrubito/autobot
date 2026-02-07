<?php
require_once __DIR__ . '/includes/Database.php';

// Set Cloud SQL environment  
putenv('K_SERVICE=autobot');
putenv('GOOGLE_CLOUD_PROJECT=autobot-prod-251215-22549');

$db = Database::getInstance();

echo "=== customer_profiles columns ===\n";
$cols = $db->query("SHOW COLUMNS FROM customer_profiles");
foreach ($cols as $col) {
    echo "- {$col['Field']} ({$col['Type']})\n";
}

echo "\n=== cases columns ===\n";
$cols = $db->query("SHOW COLUMNS FROM cases");
foreach ($cols as $col) {
    echo "- {$col['Field']} ({$col['Type']})\n";
}

echo "\n=== chat_messages columns ===\n";
$cols = $db->query("SHOW COLUMNS FROM chat_messages");
foreach ($cols as $col) {
    echo "- {$col['Field']} ({$col['Type']})\n";
}

