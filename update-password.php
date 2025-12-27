<?php
// Generate correct password hash for demo user
require_once 'config.php';

$password = 'demo1234';
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "Password: $password\n";
echo "Hash: $hash\n\n";

// Update demo user with correct hash
$pdo = getDB();

$stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE email = 'demo@aiautomation.com'");
$stmt->execute([$hash]);

echo "Demo user password updated successfully!\n";
echo "Email: demo@aiautomation.com\n";
echo "Password: demo1234\n";
