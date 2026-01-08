<?php
/**
 * Customer Search API
 * 
 * GET /api/customer/search?q=xxx - Search customers by name, phone, external_user_id
 * 
 * This searches from conversations table (which stores chat customer data)
 * and optionally from customers table if it exists.
 * 
 * @version 1.0
 * @date 2026-01-08
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Database.php';

// Verify authentication
$auth = verifyToken();
if (!$auth['valid']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $db = Database::getInstance();
    $pdo = getDB();
    
    $query = trim($_GET['q'] ?? '');
    $limit = min(20, max(1, (int)($_GET['limit'] ?? 10)));
    
    if (strlen($query) < 2) {
        echo json_encode([
            'success' => true,
            'data' => [],
            'message' => 'Query too short'
        ]);
        exit;
    }
    
    $results = [];
    $searchPattern = '%' . $query . '%';
    
    // First, try to search in customers table if it exists
    try {
        $stmt = $pdo->prepare("
            SELECT 
                c.id,
                c.customer_no,
                c.display_name,
                c.full_name,
                c.phone,
                c.email,
                c.avatar_url,
                c.line_user_id as external_user_id,
                CASE 
                    WHEN c.line_user_id IS NOT NULL THEN 'line'
                    WHEN c.facebook_user_id IS NOT NULL THEN 'facebook'
                    ELSE 'web'
                END as platform,
                c.tags,
                c.status,
                c.last_contact_at
            FROM customers c
            WHERE c.status != 'blocked'
              AND (
                  c.display_name LIKE ?
                  OR c.full_name LIKE ?
                  OR c.phone LIKE ?
                  OR c.customer_no LIKE ?
                  OR c.line_user_id LIKE ?
                  OR c.facebook_user_id LIKE ?
              )
            ORDER BY c.last_contact_at DESC
            LIMIT ?
        ");
        $stmt->execute([
            $searchPattern, 
            $searchPattern, 
            $searchPattern, 
            $searchPattern,
            $searchPattern,
            $searchPattern,
            $limit
        ]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // customers table doesn't exist, continue with conversations
    }
    
    // If no results from customers table, search in conversations
    if (empty($results)) {
        $stmt = $pdo->prepare("
            SELECT 
                conv.id,
                conv.conversation_id,
                conv.external_user_id,
                conv.platform_user_name as display_name,
                ch.platform,
                JSON_UNQUOTE(JSON_EXTRACT(conv.metadata, '$.line_profile_url')) as avatar_url,
                JSON_UNQUOTE(JSON_EXTRACT(conv.metadata, '$.user_phone')) as phone,
                conv.last_message_at,
                conv.created_at
            FROM conversations conv
            JOIN channels ch ON conv.channel_id = ch.id
            WHERE conv.platform_user_name LIKE ?
               OR conv.external_user_id LIKE ?
               OR JSON_UNQUOTE(JSON_EXTRACT(conv.metadata, '$.user_phone')) LIKE ?
            ORDER BY conv.last_message_at DESC
            LIMIT ?
        ");
        $stmt->execute([$searchPattern, $searchPattern, $searchPattern, $limit]);
        $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // De-duplicate by external_user_id (same customer on same platform)
        $seen = [];
        foreach ($conversations as $conv) {
            $key = $conv['platform'] . ':' . $conv['external_user_id'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $results[] = [
                    'id' => $conv['id'],
                    'external_user_id' => $conv['external_user_id'],
                    'display_name' => $conv['platform_user_name'] ?? $conv['display_name'],
                    'platform' => $conv['platform'],
                    'avatar_url' => $conv['avatar_url'],
                    'phone' => $conv['phone'],
                    'last_contact_at' => $conv['last_message_at'],
                    'source' => 'conversation'
                ];
            }
        }
    }
    
    // Also search in orders for customer info
    if (count($results) < $limit) {
        $remaining = $limit - count($results);
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                o.customer_id as id,
                o.customer_name as display_name,
                o.customer_phone as phone,
                o.customer_platform as platform,
                o.customer_avatar as avatar_url,
                o.source,
                o.created_at as last_contact_at
            FROM orders o
            WHERE o.customer_name LIKE ?
               OR o.customer_phone LIKE ?
            ORDER BY o.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$searchPattern, $searchPattern, $remaining]);
        $orderCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add unique ones
        foreach ($orderCustomers as $oc) {
            $duplicate = false;
            foreach ($results as $r) {
                if (
                    ($r['display_name'] === $oc['display_name'] && $r['phone'] === $oc['phone']) ||
                    ($oc['phone'] && $r['phone'] === $oc['phone'])
                ) {
                    $duplicate = true;
                    break;
                }
            }
            if (!$duplicate) {
                $oc['source'] = 'order';
                $results[] = $oc;
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => array_slice($results, 0, $limit),
        'count' => count($results),
        'query' => $query
    ]);
    
} catch (Exception $e) {
    error_log("Customer Search API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาดในการค้นหา'
    ]);
}
