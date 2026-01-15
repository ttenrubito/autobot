<?php
/**
 * Debug API - Check customer_profiles for specific user
 * Temporary endpoint for debugging
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';

try {
    $pdo = getDB();

    $platform_user_id = $_GET['platform_user_id'] ?? '25403438045932467';
    $platform = $_GET['platform'] ?? 'facebook';

    // Check exact match
    $stmt = $pdo->prepare("
        SELECT 
            id,
            platform,
            platform_user_id,
            display_name,
            full_name,
            avatar_url,
            profile_pic_url,
            phone,
            created_at
        FROM customer_profiles 
        WHERE platform_user_id = ? AND platform = ?
    ");
    $stmt->execute([$platform_user_id, $platform]);
    $exact = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Check any platform_user_id match
    $stmt = $pdo->prepare("
        SELECT 
            id,
            platform,
            platform_user_id,
            display_name,
            full_name,
            avatar_url,
            profile_pic_url,
            phone,
            created_at
        FROM customer_profiles 
        WHERE platform_user_id = ?
    ");
    $stmt->execute([$platform_user_id]);
    $any_platform = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total count
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM customer_profiles");
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Sample 5 profiles
    $stmt = $pdo->query("SELECT id, platform, platform_user_id, display_name, avatar_url FROM customer_profiles ORDER BY id DESC LIMIT 5");
    $sample = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'query' => [
            'platform_user_id' => $platform_user_id,
            'platform' => $platform
        ],
        'results' => [
            'exact_match' => $exact,
            'any_platform_match' => $any_platform
        ],
        'stats' => [
            'total_profiles' => $total
        ],
        'sample_profiles' => $sample
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
