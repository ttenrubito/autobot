<?php
/**
 * Login API Endpoint
 * POST /api/auth/login
 */

// Explicitly load dependencies so this endpoint works both via api/index.php and direct access
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Validator.php';
require_once __DIR__ . '/../../includes/JWT.php';
// Optional: Rate limiting and logging (kept commented in logic below)
// require_once __DIR__ . '/../../includes/RateLimiter.php';
// require_once __DIR__ . '/../../includes/Logger.php';

// Handle CORS (already handled by index.php, but keeping these for standalone compatibility)

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

// Get input
$input = json_decode(file_get_contents('php://input'), true) ?: [];

// Get client IP
$ip = Auth::getIpAddress();

// TODO: Re-enable rate limiting after fixing RateLimiter class
// Rate limiting temporarily disabled to fix login
/*
$rateLimiter = new RateLimiter();
if (!$rateLimiter->check($ip, 'login', 5, 300)) {
    $resetAt = $rateLimiter->getResetTime($ip, 'login');
    Logger::warning('Login rate limit exceeded', ['ip' => $ip, 'reset_at' => $resetAt]);
    Response::error('Too many login attempts. Please try again later.', 429, 'RATE_LIMIT_EXCEEDED', [
        'reset_at' => $resetAt
    ]);
}
*/

$validator = new Validator();

// Validate input
$email = $input['email'] ?? '';
$password = $input['password'] ?? '';

$validator->required('email', $email, 'Email');
$validator->email('email', $email, 'Email');
$validator->required('password', $password, 'Password');

if ($validator->fails()) {
    Response::validationError($validator->getErrors());
}

// Sanitize input
$email = Validator::sanitizeEmail($email);

try {
    $db = Database::getInstance();
    
    // Find user by email (using correct column name)
    $user = $db->queryOne(
        "SELECT id, email, password_hash, full_name, status 
         FROM users 
         WHERE email = ? LIMIT 1",
        [$email]
    );

    if (!$user) {
        // $rateLimiter->recordAttempt($ip, 'login', ['email' => $email, 'success' => false]);
        // Logger::info('Failed login - user not found', ['email' => $email, 'ip' => $ip]);
        Response::error('Invalid email or password', 401);
    }

    // Check if account is active
    if ($user['status'] !== 'active') {
        // $rateLimiter->recordAttempt($ip, 'login', ['email' => $email, 'success' => false, 'reason' => 'inactive']);
        // Logger::info('Failed login - account inactive', ['email' => $email, 'status' => $user['status']]);
        Response::error('Account is suspended or cancelled', 403);
    }

    // Verify password
    if (!Auth::verifyPassword($password, $user['password_hash'])) {
        // $rateLimiter->recordAttempt($ip, 'login', ['email' => $email, 'success' => false]);
        // Logger::info('Failed login - invalid password', ['email' => $email, 'ip' => $ip]);
        Response::error('Invalid email or password', 401);
    }
    
    // Success! Reset rate limit
    // $rateLimiter->reset($ip, 'login');


    // Update last login
    $db->execute(
        "UPDATE users SET last_login = NOW() WHERE id = ?",
        [$user['id']]
    );

    // Log activity
    $db->execute(
        "INSERT INTO activity_logs (user_id, action, ip_address, user_agent, created_at) 
         VALUES (?, 'login', ?, ?, NOW())",
        [$user['id'], Auth::getIpAddress(), Auth::getUserAgent()]
    );

    // Create session
    unset($user['password_hash']); // Don't store password hash in session
    Auth::login($user['id'], $user);

    // Generate JWT token
    $tokenPayload = [
        'user_id' => $user['id'],
        'email' => $user['email'],
        'full_name' => $user['full_name']
    ];
    $jwtToken = JWT::generate($tokenPayload);

    Response::success([
        'user' => $user,
        'token' => $jwtToken, // JWT token for API calls
        'csrf_token' => Auth::getCsrfToken()
    ], 'Login successful');

} catch (Exception $e) {
    error_log("Login Error: " . $e->getMessage());
    // Logger::error('Login exception', ['error' => $e->getMessage(), 'email' => $email]);
    Response::error('Login failed. Please try again.', 500);
}
