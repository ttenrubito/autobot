<?php
/**
 * Backfill customer_profiles from existing cases
 * This will fetch profile data from Facebook for all external_user_ids that don't have profiles yet
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';

set_time_limit(120);

$results = [
    'total_cases_checked' => 0,
    'missing_profiles' => 0,
    'profiles_created' => 0,
    'profiles_failed' => 0,
    'errors' => []
];

try {
    $pdo = getDB();
    
    // Get all unique external_user_ids from cases that don't have profiles yet
    $stmt = $pdo->query("
        SELECT DISTINCT c.external_user_id, c.platform, ch.config as channel_config
        FROM cases c
        LEFT JOIN customer_profiles cp ON c.external_user_id = cp.platform_user_id AND c.platform = cp.platform
        LEFT JOIN channels ch ON c.channel_id = ch.id
        WHERE c.external_user_id IS NOT NULL 
          AND c.external_user_id != ''
          AND cp.id IS NULL
          AND c.platform = 'facebook'
        LIMIT 50
    ");
    
    $missing = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $results['total_cases_checked'] = count($missing);
    $results['missing_profiles'] = count($missing);
    
    foreach ($missing as $row) {
        $platformUserId = $row['external_user_id'];
        $platform = $row['platform'];
        $channelConfig = json_decode($row['channel_config'] ?? '{}', true);
        $pageAccessToken = $channelConfig['page_access_token'] ?? null;
        
        if (!$pageAccessToken) {
            $results['errors'][] = "No token for user: {$platformUserId}";
            $results['profiles_failed']++;
            continue;
        }
        
        // Fetch profile from Facebook
        $profileUrl = "https://graph.facebook.com/v18.0/{$platformUserId}?fields=name,profile_pic&access_token={$pageAccessToken}";
        
        $ch = curl_init($profileUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            $results['errors'][] = "Failed to get profile for {$platformUserId}: HTTP {$httpCode}";
            $results['profiles_failed']++;
            continue;
        }
        
        $profile = json_decode($resp, true);
        $displayName = $profile['name'] ?? null;
        $avatarUrl = $profile['profile_pic'] ?? null;
        
        if (!$displayName && !$avatarUrl) {
            $results['errors'][] = "Empty profile for {$platformUserId}";
            $results['profiles_failed']++;
            continue;
        }
        
        // Insert into customer_profiles
        $insertStmt = $pdo->prepare("
            INSERT INTO customer_profiles (platform, platform_user_id, display_name, avatar_url, first_seen_at, last_active_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, NOW(), NOW(), NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                display_name = COALESCE(VALUES(display_name), display_name),
                avatar_url = COALESCE(VALUES(avatar_url), avatar_url),
                last_active_at = NOW(),
                updated_at = NOW()
        ");
        $insertStmt->execute([$platform, $platformUserId, $displayName, $avatarUrl]);
        $results['profiles_created']++;
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Backfill completed",
        'results' => $results
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'results' => $results
    ]);
}
