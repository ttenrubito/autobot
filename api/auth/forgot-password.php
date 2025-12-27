<?php
/**
 * Forgot Password API Endpoint
 * POST /api/auth/forgot-password
 */

$validator = new Validator();

$email = $input['email'] ?? '';

$validator->required('email', $email, 'Email');
$validator->email('email', $email, 'Email');

if ($validator->fails()) {
    Response::validationError($validator->getErrors());
}

$email = Validator::sanitizeEmail($email);

try {
    $db = Database::getInstance();
    
    // Find user
    $user = $db->queryOne(
        "SELECT id, email, full_name FROM users WHERE email = ? LIMIT 1",
        [$email]
    );

    // Always return success to prevent email enumeration
    if (!$user) {
        Response::success(null, 'If the email exists, a password reset link has been sent');
    }

    // Generate reset token
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

    // Save token
    $db->execute(
        "INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)",
        [$user['id'], $token, $expiresAt]
    );

    // TODO: Send email with reset link
    // For now, just log it
    error_log("Password reset token for {$email}: {$token}");
    
    // In production, you would send an email like this:
    // $resetLink = BASE_URL . "reset-password.html?token={$token}";
    // mail($email, "Password Reset", "Click here to reset: {$resetLink}");

    Response::success(null, 'If the email exists, a password reset link has been sent');

} catch (Exception $e) {
    error_log("Forgot Password Error: " . $e->getMessage());
    Response::error('Failed to process request', 500);
}
