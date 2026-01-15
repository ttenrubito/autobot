<?php
/**
 * Update customer_profiles avatars from Facebook/LINE API
 * Run via: curl "https://autobot.boxdesign.in.th/api/migrations/update-customer-avatars.php?debug_key=autobot2026"
 */

require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

// Security check
$debugKey = $_GET['debug_key'] ?? '';
if ($debugKey !== 'autobot2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pdo = getDB();
$results = [];

try {
    // Get Facebook profiles without avatar
    $stmt = $pdo->query("
        SELECT cp.id, cp.platform_user_id, cp.display_name, bc.access_token
        FROM customer_profiles cp
        LEFT JOIN bot_channels bc ON bc.platform = 'facebook' AND bc.status = 'active'
        WHERE cp.platform = 'facebook' 
          AND (cp.avatar_url IS NULL OR cp.avatar_url = '')
        LIMIT 50
    ");
    $profiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $results['total_to_update'] = count($profiles);
    $results['updated'] = [];
    
    foreach ($profiles as $profile) {
        if (empty($profile['access_token'])) {
            $results['skipped'][] = ['id' => $profile['id'], 'reason' => 'No access token'];
            continue;
        }
        
        // Call Facebook Graph API
        $url = "https://graph.facebook.com/v18.0/{$profile['platform_user_id']}?fields=name,profile_pic&access_token={$profile['access_token']}";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            $results['failed'][] = [
                'id' => $profile['id'],
                'http_code' => $httpCode,
                'response' => $response
            ];
            continue;
        }
        
        $data = json_decode($response, true);
        $avatarUrl = $data['profile_pic'] ?? null;
        $displayName = $data['name'] ?? null;
        
        if ($avatarUrl || $displayName) {
            // Update customer_profiles
            $updateSql = "UPDATE customer_profiles SET ";
            $params = [];
            $sets = [];
            
            if ($avatarUrl) {
                $sets[] = "avatar_url = ?";
                $params[] = $avatarUrl;
            }
            if ($displayName && empty($profile['display_name'])) {
                $sets[] = "display_name = ?";
                $params[] = $displayName;
            }
            $sets[] = "updated_at = NOW()";
            
            $updateSql .= implode(', ', $sets) . " WHERE id = ?";
            $params[] = $profile['id'];
            
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute($params);
            
            $results['updated'][] = [
                'id' => $profile['id'],
                'platform_user_id' => $profile['platform_user_id'],
                'avatar_url' => $avatarUrl,
                'display_name' => $displayName
            ];
        } else {
            $results['no_data'][] = [
                'id' => $profile['id'],
                'response' => $data
            ];
        }
        
        // Small delay to avoid rate limiting
        usleep(100000); // 100ms
    }
    
    echo json_encode(['success' => true, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
