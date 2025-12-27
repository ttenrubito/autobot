<?php
/**
 * Update config.php to include JWT secret and Omise keys
 */

// Add these constants after the existing configuration

// JWT Configuration
define('JWT_SECRET_KEY', 'AI_AUTOMATION_SECRET_KEY_CHANGE_IN_PRODUCTION_2024');
define('JWT_TOKEN_EXPIRY', 86400); // 24 hours

// Omise Configuration (Replace with your actual keys)
define('OMISE_PUBLIC_KEY', 'pkey_test_xxxxx');
define('OMISE_SECRET_KEY', 'skey_test_xxxxx');
define('OMISE_API_VERSION', '2019-05-29');

// Security Configuration
define('ENABLE_HTTPS_ONLY', false); // Set to true in production
define('SESSION_LIFETIME', 1800); // 30 minutes
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 300); // 5 minutes
