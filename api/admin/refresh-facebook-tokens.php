<?php
/**
 * API: Manual Facebook Token Refresh
 * Endpoint สำหรับ refresh Facebook tokens ผ่าน Admin UI
 */

require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';
require_once __DIR__ . '/../../includes/AdminAuth.php';

AdminAuth::require();

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    Response::error('Method not allowed', 405);
}

$db = Database::getInstance();

function json_input() {
    $raw = file_get_contents('php://input');
    return $raw ? json_decode($raw, true) : [];
}

try {
    $input = json_input();
    
    $channelId = isset($input['channel_id']) ? (int)$input['channel_id'] : null;
    $forceAll = !empty($input['force_all']);
    
    $results = [];
    $summary = [
        'total' => 0,
        'refreshed' => 0,
        'skipped' => 0,
        'failed' => 0,
    ];
    
    if ($channelId) {
        // Refresh specific channel
        $channels = $db->query(
            'SELECT id, user_id, name, config, token_expires_at 
             FROM customer_channels 
             WHERE id = ? AND type = ? AND status = ? AND is_deleted = 0',
            [$channelId, 'facebook', 'active']
        );
    } else {
        // Refresh all Facebook channels
        $channels = $db->query(
            'SELECT id, user_id, name, config, token_expires_at 
             FROM customer_channels 
             WHERE type = ? AND status = ? AND is_deleted = 0
             ORDER BY token_expires_at ASC',
            ['facebook', 'active']
        );
    }
    
    $summary['total'] = count($channels);
    
    foreach ($channels as $channel) {
        $result = refreshChannelToken($channel, $forceAll, $db);
        $results[] = $result;
        
        if ($result['success']) {
            $summary['refreshed']++;
        } elseif ($result['skipped']) {
            $summary['skipped']++;
        } else {
            $summary['failed']++;
        }
    }
    
    Response::success([
        'summary' => $summary,
        'results' => $results,
    ]);
    
} catch (Exception $e) {
    error_log('Manual token refresh error: ' . $e->getMessage());
    Response::error('Server error: ' . $e->getMessage(), 500);
}

/**
 * Refresh token สำหรับ channel เดียว
 */
function refreshChannelToken(array $channel, bool $force, $db): array
{
    $channelId = $channel['id'];
    $channelName = $channel['name'] ?? "Channel-{$channelId}";
    
    $config = json_decode($channel['config'] ?? '{}', true);
    if (!is_array($config)) {
        return [
            'channel_id' => $channelId,
            'channel_name' => $channelName,
            'success' => false,
            'skipped' => false,
            'message' => 'Invalid config JSON',
        ];
    }
    
    $currentToken = trim($config['page_access_token'] ?? '');
    $appId = trim($config['app_id'] ?? '');
    $appSecret = trim($config['app_secret'] ?? '');
    
    // ถ้าไม่มีใน config -> ลอง env variable
    if (empty($appId)) {
        $appId = getenv('FACEBOOK_APP_ID') ?: '';
    }
    if (empty($appSecret)) {
        $appSecret = getenv('FACEBOOK_APP_SECRET') ?: '';
    }
    
    // ✅ ถ้าไม่มี credentials = skip (ไม่ใช่ failed)
    if (empty($currentToken) || empty($appId) || empty($appSecret)) {
        return [
            'channel_id' => $channelId,
            'channel_name' => $channelName,
            'success' => false,
            'skipped' => true, // ✅ เปลี่ยนเป็น skip
            'message' => sprintf(
                'Skipped - missing credentials: token=%s, app_id=%s, app_secret=%s',
                empty($currentToken) ? 'NO' : 'YES',
                empty($appId) ? 'NO' : 'YES',
                empty($appSecret) ? 'NO' : 'YES'
            ),
        ];
    }
    
    // เช็คว่าต้อง refresh หรือไม่
    $expiresAt = $channel['token_expires_at'];
    $needsRefresh = false;
    $reason = '';
    
    if ($force) {
        $needsRefresh = true;
        $reason = 'Force refresh';
    } elseif ($expiresAt === null) {
        $needsRefresh = true;
        $reason = 'No expiry date set';
    } else {
        $expiresTs = strtotime($expiresAt);
        $daysLeft = ($expiresTs - time()) / 86400;
        
        if ($daysLeft < 10) {
            $needsRefresh = true;
            $reason = sprintf('Expires in %.1f days', $daysLeft);
        } else {
            $reason = sprintf('Still valid (%.1f days left)', $daysLeft);
        }
    }
    
    if (!$needsRefresh) {
        return [
            'channel_id' => $channelId,
            'channel_name' => $channelName,
            'success' => false,
            'skipped' => true,
            'message' => $reason,
            'days_left' => isset($daysLeft) ? round($daysLeft, 1) : null,
        ];
    }
    
    // เรียก Facebook API
    $refreshResult = callFacebookTokenRefreshAPI($currentToken, $appId, $appSecret);
    
    if ($refreshResult === null) {
        return [
            'channel_id' => $channelId,
            'channel_name' => $channelName,
            'success' => false,
            'skipped' => false,
            'message' => 'Facebook API call failed - check error logs for details',
        ];
    }
    
    $newToken = $refreshResult['access_token'];
    $expiresIn = $refreshResult['expires_in'];
    $newExpiryDate = date('Y-m-d H:i:s', time() + $expiresIn);
    
    // อัปเดต database
    $config['page_access_token'] = $newToken;
    $newConfigJson = json_encode($config, JSON_UNESCAPED_UNICODE);
    
    $updateSql = "UPDATE customer_channels 
                  SET config = ?, 
                      token_expires_at = ?,
                      token_last_refreshed_at = NOW()
                  WHERE id = ?";
    
    $db->execute($updateSql, [$newConfigJson, $newExpiryDate, $channelId]);
    
    return [
        'channel_id' => $channelId,
        'channel_name' => $channelName,
        'success' => true,
        'skipped' => false,
        'message' => 'Token refreshed successfully',
        'new_expiry' => $newExpiryDate,
        'days_added' => round($expiresIn / 86400, 1),
    ];
}

/**
 * เรียก Facebook Graph API เพื่อ refresh token
 */
function callFacebookTokenRefreshAPI(string $currentToken, string $appId, string $appSecret): ?array
{
    $url = 'https://graph.facebook.com/oauth/access_token';
    $params = http_build_query([
        'grant_type' => 'fb_exchange_token',
        'client_id' => $appId,
        'client_secret' => $appSecret,
        'fb_exchange_token' => $currentToken,
    ]);
    
    $fullUrl = "{$url}?{$params}";
    
    $ch = curl_init($fullUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        error_log("Facebook API cURL error: {$curlError}");
        return null;
    }
    
    if ($httpCode !== 200) {
        error_log("Facebook API HTTP {$httpCode}: {$response}");
        return null;
    }
    
    $data = json_decode($response, true);
    
    if (!isset($data['access_token'])) {
        error_log("Invalid Facebook API response: " . json_encode($data));
        return null;
    }
    
    return [
        'access_token' => $data['access_token'],
        'expires_in' => $data['expires_in'] ?? 5184000, // 60 days default
    ];
}
