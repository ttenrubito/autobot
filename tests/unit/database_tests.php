#!/usr/bin/env php
<?php
/**
 * Database Integrity Tests
 * Verifies database schema and data consistency
 */

require_once __DIR__ . '/../../config.php';

class DatabaseTests {
    private $db;
    private $passed = 0;
    private $failed = 0;
    
    public function __construct() {
        // Use app-level DB helper (returns PDO) instead of Database singleton.
        // This avoids direct construction/visibility issues and matches runtime usage.
        $this->db = getDB();
    }
    
    public function run() {
        echo "🗄️  Database Integrity Tests\n";
        echo "============================\n\n";
        
        echo "📌 Testing Schema...\n";
        $this->testTablesExist();
        $this->testIndexes();
        
        echo "\n📌 Testing Data Integrity...\n";
        $this->testForeignKeys();
        $this->testUserData();
        $this->testSubscriptionData();
        
        echo "\n📌 Testing Query Performance...\n";
        $this->testQueryPerformance();
        
        echo "\n============================\n";
        $total = $this->passed + $this->failed;
        $percentage = $total > 0 ? round(($this->passed / $total) * 100, 2) : 0;
        
        echo "Results: ";
        echo "\033[32m{$this->passed} passed\033[0m / ";
        echo "\033[31m{$this->failed} failed\033[0m\n";
        echo "Success Rate: {$percentage}%\n";
        
        return $this->failed === 0;
    }
    
    private function testTablesExist() {
        $requiredTables = [
            'users', 'subscriptions', 'subscription_plans',
            'customer_services', 'service_types',
            'bot_chat_logs', 'api_usage_logs',
            'invoices', 'invoice_items', 'transactions',
            'payment_methods', 'api_keys',
            'admin_users', 'rate_limits'
        ];
        
        foreach ($requiredTables as $table) {
            // MariaDB does not support parameter placeholders in SHOW statements reliably.
            $safeTable = str_replace('`', '', $table);
            $stmt = $this->db->query("SHOW TABLES LIKE " . $this->db->quote($safeTable));
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                $this->pass("Table '{$table}' exists");
            } else {
                $this->fail("Table '{$table}' missing");
            }
        }
    }
    
    private function testIndexes() {
        $checks = [
            ['users', 'email', 'UNI'],
            ['subscriptions', 'user_id', 'MUL'],
            ['customer_services', 'user_id', 'MUL'],
            ['api_keys', 'api_key', 'UNI']
        ];
        
        foreach ($checks as [$table, $column, $expectedKey]) {
            $stmt = $this->db->prepare("SHOW INDEX FROM `{$table}` WHERE Column_name = ?");
            $stmt->execute([$column]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && ($result['Key_name'] !== '' || $result['Non_unique'] == 0)) {
                $this->pass("Index on {$table}.{$column}");
            } else {
                $this->fail("Missing index on {$table}.{$column}");
            }
        }
    }
    
    private function testForeignKeys() {
        // Test referential integrity
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as count FROM subscriptions s 
             LEFT JOIN users u ON s.user_id = u.id 
             WHERE u.id IS NULL"
        );
        $stmt->execute();
        $orphanedSubscriptions = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($orphanedSubscriptions['count'] == 0) {
            $this->pass("No orphaned subscriptions");
        } else {
            $this->fail("Found {$orphanedSubscriptions['count']} orphaned subscriptions");
        }
    }
    
    private function testUserData() {
        $users = $this->db->query("SELECT COUNT(*) as count FROM users")->fetch(PDO::FETCH_ASSOC);
        
        if (($users['count'] ?? 0) > 0) {
            $this->pass("Users table has data ({$users['count']} users)");
        } else {
            $this->fail("Users table is empty");
        }
    }
    
    private function testSubscriptionData() {
        $activeSubscriptions = $this->db->query("SELECT COUNT(*) as count FROM subscriptions WHERE status = 'active'")->fetch(PDO::FETCH_ASSOC);
        
        if (isset($activeSubscriptions['count'])) {
            $this->pass("Active subscriptions: {$activeSubscriptions['count']}");
        } else {
            $this->fail("Error counting active subscriptions");
        }
    }
    
    private function testQueryPerformance() {
        $start = microtime(true);
        
        $this->db->query(
            "SELECT u.*, s.*, p.name FROM users u 
             LEFT JOIN subscriptions s ON u.id = s.user_id 
             LEFT JOIN subscription_plans p ON s.plan_id = p.id 
             LIMIT 10"
        )->fetchAll(PDO::FETCH_ASSOC);
        
        $duration = (microtime(true) - $start) * 1000; // ms
        
        if ($duration < 100) {
            $this->pass("Query performance: {$duration}ms");
        } else {
            $this->fail("Slow query: {$duration}ms (target < 100ms)");
        }
    }
    
    private function pass($test) {
        echo "  \033[32m✓\033[0m {$test}\n";
        $this->passed++;
    }
    
    private function fail($test) {
        echo "  \033[31m✗\033[0m {$test}\n";
        $this->failed++;
    }
}

// Run tests
try {
    $tests = new DatabaseTests();
    $success = $tests->run();
    exit($success ? 0 : 1);
} catch (Exception $e) {
    echo "\033[31mError: " . $e->getMessage() . "\033[0m\n";
    exit(1);
}
