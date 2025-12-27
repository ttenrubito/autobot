<?php
// Check and setup admin users table
require_once __DIR__ . '/config.php';

try {
    $conn = getDB();
    
    // Check if admin_users table exists
    $stmt = $conn->query("SHOW TABLES LIKE 'admin_users'");
    $tableExists = $stmt->rowCount() > 0;
    
    if (!$tableExists) {
        echo "❌ admin_users table does NOT exist\n";
        echo "📋 Creating admin_users table and inserting default admin...\n\n";
        
        // Read and execute the admin schema
        $schema = file_get_contents(__DIR__ . '/database/admin_api_gateway_schema.sql');
        
        // Split by semicolons and execute each statement
        $statements = array_filter(array_map('trim', explode(';', $schema)));
        
        foreach ($statements as $sql) {
            if (!empty($sql)) {
                try {
                    $conn->exec($sql);
                } catch (PDOException $e) {
                    // Skip duplicate entry errors (404/1062) as they're expected
                    if ($e->getCode() != '23000') {
                        echo "⚠️ Warning: " . $e->getMessage() . "\n";
                    }
                }
            }
        }
        
        echo "✅ Admin schema applied successfully!\n\n";
    } else {
        echo "✅ admin_users table exists\n\n";
    }
    
    // Check if default admin exists
    $stmt = $conn->prepare("SELECT id, username, email, role, is_active FROM admin_users WHERE username = 'admin'");
    $stmt->execute();
    $admin = $stmt->fetch();
    
    if ($admin) {
        echo "✅ Default admin user exists:\n";
        echo "   ID: " . $admin['id'] . "\n";
        echo "   Username: " . $admin['username'] . "\n";
        echo "   Email: " . $admin['email'] . "\n";
        echo "   Role: " . $admin['role'] . "\n";
        echo "   Active: " . ($admin['is_active'] ? 'Yes' : 'No') . "\n\n";
        
        // Test password verification
        $stmt = $conn->prepare("SELECT password_hash FROM admin_users WHERE username = 'admin'");
        $stmt->execute();
        $result = $stmt->fetch();
        
        $testPassword = 'admin123';
        $isValid = password_verify($testPassword, $result['password_hash']);
        
        echo "🔑 Password verification test: " . ($isValid ? '✅ PASS' : '❌ FAIL') . "\n";
        echo "   Testing password: '$testPassword'\n\n";
        
        if (!$isValid) {
            echo "⚠️ Regenerating password hash...\n\n";
            $newHash = password_hash($testPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE admin_users SET password_hash = ? WHERE username = 'admin'");
            $stmt->execute([$newHash]);
            echo "✅ Password hash updated successfully!\n\n";
        }
    } else {
        echo "❌ Default admin user does NOT exist\n";
        echo "📋 Creating default admin...\n\n";
        
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $conn->prepare("
            INSERT INTO admin_users (username, password_hash, full_name, email, role) 
            VALUES ('admin', ?, 'System Administrator', 'admin@aiautomation.com', 'super_admin')
        ");
        $stmt->execute([$hash]);
        echo "✅ Default admin created successfully!\n\n";
    }
    
    echo "========================================\n";
    echo "Admin Login Credentials:\n";
    echo "========================================\n";
    echo "Username: admin\n";
    echo "Password: admin123\n";
    echo "========================================\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
