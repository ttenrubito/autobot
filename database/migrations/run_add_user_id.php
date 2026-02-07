<?php
/**
 * Run migration: Add user_id to cases table
 */

require_once __DIR__ . '/../../config.php';

try {
    $pdo = getDB();
    
    // Check if column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM cases LIKE 'user_id'");
    if ($stmt->rowCount() > 0) {
        echo "Column user_id already exists\n";
        exit(0);
    }
    
    // Add column
    $pdo->exec('ALTER TABLE cases ADD COLUMN user_id INT UNSIGNED NULL AFTER customer_id');
    echo "✅ Added user_id column\n";
    
    // Add index
    try {
        $pdo->exec('ALTER TABLE cases ADD INDEX idx_cases_user_id (user_id)');
        echo "✅ Added index\n";
    } catch (Exception $e) {
        echo "⚠️ Index might already exist\n";
    }
    
    // Add foreign key (optional - might fail if users table has different id type)
    try {
        $pdo->exec('ALTER TABLE cases ADD CONSTRAINT fk_cases_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE');
        echo "✅ Added foreign key\n";
    } catch (Exception $e) {
        echo "⚠️ FK skipped: " . $e->getMessage() . "\n";
    }
    
    // Update existing web cases
    $updated = $pdo->exec("UPDATE cases c JOIN users u ON c.external_user_id = CONCAT('web_user_', u.id) SET c.user_id = u.id WHERE c.user_id IS NULL AND c.platform = 'web'");
    echo "✅ Updated {$updated} existing web cases\n";
    
    // Verify
    $result = $pdo->query('SELECT COUNT(*) as cnt FROM cases WHERE user_id IS NOT NULL')->fetch();
    echo "✅ Cases with user_id: " . $result['cnt'] . "\n";
    
    echo "\n✅ Migration completed successfully!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
