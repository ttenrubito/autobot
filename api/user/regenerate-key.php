<?php
/**
 * Regenerate API Key
 * POST /api/user/regenerate-key
 */

require_once '../../includes/Database.php';
require_once '../../includes/Auth.php';
require_once '../../includes/Response.php';

Auth::require();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

try {
    $db = Database::getInstance();
    $userId = Auth::id();
    
    // Deactivate old keys
    $db->execute(
        "UPDATE api_keys SET is_active = FALSE WHERE user_id = ?",
        [$userId]
    );
    
    // Generate new API key
    $newApiKey = 'ak_' . bin2hex(random_bytes(24)); // 48 chars
    
    // Insert new key
    $db->execute(
        "INSERT INTO api_keys (user_id, api_key, name, is_active)
         VALUES (?, ?, ?, TRUE)",
        [$userId, $newApiKey, 'API Key']
    );
    
    Response::success([
        'api_key' => $newApiKey
    ], 'API key regenerated successfully');
    
} catch (Exception $e) {
    error_log("Regenerate API Key Error: " . $e->getMessage());
    Response::error('Failed to regenerate API key', 500);
}
