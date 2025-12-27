<?php
/**
 * Facebook Token Auto-Refresh Script
 * 
 * Purpose: ต่ออายุ Facebook Page Access Tokens อัตโนมัติก่อนหมดอายุ
 * Schedule: รันทุกวันเวลา 03:00 ผ่าน cron job
 * 
 * Usage:
 *   php scripts/refresh_facebook_tokens.php [--dry-run] [--force]
 * 
 * Options:
 *   --dry-run  : แสดงผลลัพธ์โดยไม่บันทึกการเปลี่ยนแปลง
 *   --force    : บังคับต่ออายุทุก token (ignore expiry check)
 */

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Logger.php';

$dryRun = in_array('--dry-run', $argv ?? []);
$force = in_array('--force', $argv ?? []);

Logger::info("========== Facebook Token Refresh Started ==========");
Logger::info("Mode: " . ($dryRun ? "DRY RUN" : "LIVE") . ($force ? " (FORCE)" : ""));

try {
    $db = Database::getInstance();
    
    // หา Facebook channels ทั้งหมดที่ active
    $sql = "SELECT id, user_id, name, config, token_expires_at, token_last_refreshed_at
            FROM customer_channels
            WHERE type = 'facebook'
              AND status = 'active'
              AND is_deleted = 0
            ORDER BY token_expires_at ASC NULLS FIRST";
    
    $channels = $db->query($sql);
    
    $total = count($channels);
    $refreshed = 0;
    $skipped = 0;
    $failed = 0;
    
    Logger::info("Found {$total} active Facebook channels");
    
    foreach ($channels as $channel) {
        $channelId = $channel['id'];
        $channelName = $channel['name'] ?? "Channel-{$channelId}";
        $config = json_decode($channel['config'] ?? '{}', true);
        
        if (!is_array($config)) {
            Logger::warning("Channel {$channelId}: Invalid config JSON");
            $failed++;
            continue;
        }
        
        $currentToken = trim($config['page_access_token'] ?? '');
        $appId = trim($config['app_id'] ?? '') ?: getenv('FACEBOOK_APP_ID');
        $appSecret = trim($config['app_secret'] ?? '');
        
        if ($currentToken === '' || $appId === '' || $appSecret === '') {
            Logger::warning("Channel {$channelId} ({$channelName}): Missing credentials (token/app_id/app_secret)");
            $failed++;
            continue;
        }
        
        // เช็คว่าต้องต่ออายุหรือไม่
        $expiresAt = $channel['token_expires_at'];
        $needsRefresh = false;
        
        if ($force) {
            $needsRefresh = true;
            $reason = "FORCE mode";
        } elseif ($expiresAt === null) {
            $needsRefresh = true;
            $reason = "No expiry date set";
        } else {
            $expiresTs = strtotime($expiresAt);
            $daysLeft = ($expiresTs - time()) / 86400;
            
            if ($daysLeft < 10) {
                $needsRefresh = true;
                $reason = sprintf("Expires in %.1f days", $daysLeft);
            } else {
                $reason = sprintf("Still valid (%.1f days left)", $daysLeft);
            }
        }
        
        if (!$needsRefresh) {
            Logger::info("Channel {$channelId} ({$channelName}): SKIP - {$reason}");
            $skipped++;
            continue;
        }
        
        Logger::info("Channel {$channelId} ({$channelName}): REFRESHING - {$reason}");
        
        // เรียก Facebook API เพื่อต่ออายุ token
        $result = refreshFacebookToken($currentToken, $appId, $appSecret);
        
        if ($result === null) {
            Logger::error("Channel {$channelId} ({$channelName}): Token refresh FAILED");
            $failed++;
            continue;
        }
        
        $newToken = $result['access_token'];
        $expiresIn = $result['expires_in']; // seconds
        $newExpiryDate = date('Y-m-d H:i:s', time() + $expiresIn);
        
        Logger::info("Channel {$channelId} ({$channelName}): Token refreshed successfully (expires: {$newExpiryDate})");
        
        if (!$dryRun) {
            // อัปเดต config กับ token ใหม่
            $config['page_access_token'] = $newToken;
            $newConfigJson = json_encode($config, JSON_UNESCAPED_UNICODE);
            
            $updateSql = "UPDATE customer_channels 
                          SET config = ?, 
                              token_expires_at = ?,
                              token_last_refreshed_at = NOW()
                          WHERE id = ?";
            
            $db->execute($updateSql, [$newConfigJson, $newExpiryDate, $channelId]);
            
            Logger::info("Channel {$channelId}: Database updated");
        } else {
            Logger::info("Channel {$channelId}: DRY RUN - skipped database update");
        }
        
        $refreshed++;
    }
    
    // Summary
    Logger::info("========== Token Refresh Summary ==========");
    Logger::info("Total channels: {$total}");
    Logger::info("Refreshed: {$refreshed}");
    Logger::info("Skipped: {$skipped}");
    Logger::info("Failed: {$failed}");
    Logger::info("Mode: " . ($dryRun ? "DRY RUN" : "LIVE"));
    
    if ($failed > 0) {
        Logger::error("WARNING: {$failed} channels failed to refresh. Check logs for details.");
    }
    
} catch (Exception $e) {
    Logger::error("Fatal error: " . $e->getMessage());
    exit(1);
}

/**
 * ต่ออายุ Facebook token ผ่าน Graph API
 */
function refreshFacebookToken(string $currentToken, string $appId, string $appSecret): ?array
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
        Logger::error("cURL error: {$curlError}");
        return null;
    }
    
    if ($httpCode !== 200) {
        Logger::error("HTTP {$httpCode}: {$response}");
        return null;
    }
    
    $data = json_decode($response, true);
    
    if (!isset($data['access_token'])) {
        Logger::error("Invalid response: " . json_encode($data));
        return null;
    }
    
    return [
        'access_token' => $data['access_token'],
        'expires_in' => $data['expires_in'] ?? 5184000, // 60 days default (in seconds)
    ];
}
