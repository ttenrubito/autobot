<?php
// Test admin login dependencies on production
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing admin login dependencies...\n\n";

// Test 1: Load config.php
echo "1. Loading config.php... ";
try {
    require_once __DIR__ . '/config.php';
    echo "✅ OK\n";
    echo "   DB_HOST: " . DB_HOST . "\n";
    echo "   DB_NAME: " . DB_NAME . "\n";
} catch (Exception $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 2: Load Database.php
echo "\n2. Loading Database.php... ";
try {
    require_once __DIR__ . '/includes/Database.php';
    echo "✅ OK\n";
} catch (Exception $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 3: Get Database instance
echo "\n3. Getting Database instance... ";
try {
    $db = Database::getInstance();
    echo "✅ OK\n";
} catch (Exception $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 4: Load JWT.php
echo "\n4. Loading JWT.php... ";
try {
    require_once __DIR__ . '/includes/JWT.php';
    echo "✅ OK\n";
} catch (Exception $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 5: Query admin_users table
echo "\n5. Querying admin_users table... ";
try {
    $admin = $db->queryOne("SELECT COUNT(*) as cnt FROM admin_users");
    echo "✅ OK (found " . $admin['cnt'] . " admins)\n";
} catch (Exception $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✅ ALL TESTS PASSED!\n";
echo "Admin login should work if all dependencies load correctly.\n";
