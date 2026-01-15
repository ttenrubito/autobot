<?php
/**
 * Fix customer_profiles - Add unique key and update avatars
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';

try {
    $pdo = getDB();
    $results = [];

    // 1. Check if unique key exists
    $stmt = $pdo->query("
        SELECT COUNT(*) as cnt 
        FROM information_schema.TABLE_CONSTRAINTS 
        WHERE CONSTRAINT_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'customer_profiles' 
        AND CONSTRAINT_TYPE = 'UNIQUE'
        AND CONSTRAINT_NAME LIKE '%platform%'
    ");
    $hasUnique = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'] > 0;
    $results['has_unique_key'] = $hasUnique;

    // 2. If no unique key, add it
    if (!$hasUnique) {
        try {
            // First, remove duplicates if any (keep first occurrence)
            $pdo->exec("
                DELETE cp1 FROM customer_profiles cp1
                INNER JOIN customer_profiles cp2 
                WHERE cp1.id > cp2.id 
                AND cp1.platform = cp2.platform 
                AND cp1.platform_user_id = cp2.platform_user_id
            ");
            $results['removed_duplicates'] = $pdo->rowCount();

            // Add unique key
            $pdo->exec("
                ALTER TABLE customer_profiles 
                ADD UNIQUE KEY uniq_platform_user (platform, platform_user_id)
            ");
            $results['added_unique_key'] = true;
        } catch (PDOException $e) {
            $results['unique_key_error'] = $e->getMessage();
        }
    }

    // 3. Get profiles without avatar for facebook
    $stmt = $pdo->query("
        SELECT id, platform_user_id, display_name, avatar_url 
        FROM customer_profiles 
        WHERE platform = 'facebook' AND (avatar_url IS NULL OR avatar_url = '')
    ");
    $profilesNeedAvatar = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $results['profiles_need_avatar'] = count($profilesNeedAvatar);

    // 4. Get page_access_token from channel
    $stmt = $pdo->query("
        SELECT config FROM customer_channels 
        WHERE type = 'facebook' AND status = 'active' AND is_deleted = 0 
        LIMIT 1
    ");
    $channelRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $config = json_decode($channelRow['config'] ?? '{}', true);
    $pageAccessToken = $config['page_access_token'] ?? null;

    $results['has_page_token'] = !empty($pageAccessToken);

    // 5. Update avatars from Facebook Graph API
    $updated = [];
    if ($pageAccessToken && !empty($profilesNeedAvatar)) {
        foreach ($profilesNeedAvatar as $profile) {
            $platformUserId = $profile['platform_user_id'];

            // Get profile from Facebook
            $profileUrl = "https://graph.facebook.com/v18.0/{$platformUserId}?fields=name,profile_pic&access_token={$pageAccessToken}";

            $ch = curl_init($profileUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
            ]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $fbProfile = json_decode($resp, true);
                $avatarUrl = $fbProfile['profile_pic'] ?? null;
                $name = $fbProfile['name'] ?? null;

                if ($avatarUrl) {
                    $updateStmt = $pdo->prepare("
                        UPDATE customer_profiles 
                        SET avatar_url = ?, 
                            display_name = COALESCE(?, display_name),
                            updated_at = NOW() 
                        WHERE id = ?
                    ");
                    $updateStmt->execute([$avatarUrl, $name, $profile['id']]);

                    $updated[] = [
                        'id' => $profile['id'],
                        'platform_user_id' => $platformUserId,
                        'display_name' => $name,
                        'avatar_url' => $avatarUrl
                    ];
                }
            }
        }
    }

    $results['updated_profiles'] = $updated;
    $results['success'] = true;

    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
