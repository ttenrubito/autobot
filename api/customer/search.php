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
    $limit = min(20, max(1, (int) ($_GET['limit'] ?? 10)));

    if (strlen($query) < 2) {
        echo json_encode([
            'success' => true,
            'data' => [],
            'message' => 'Query too short'
        ]);
        exit;
    }

    // ✅ Get user's channel IDs for tenant isolation
    $user_id = $auth['user_id'];
    $channelStmt = $pdo->prepare("SELECT id FROM customer_channels WHERE user_id = ? AND status = 'active'");
    $channelStmt->execute([$user_id]);
    $userChannels = $channelStmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($userChannels)) {
        echo json_encode(['success' => true, 'data' => [], 'message' => 'No channels configured']);
        exit;
    }
    
    $channelPlaceholders = implode(',', array_fill(0, count($userChannels), '?'));

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

    // If no results from customers table, search in customer_profiles
    if (empty($results)) {
        try {
            // ✅ Filter by user's channel_ids
            $sql = "
                SELECT 
                    cp.id,
                    cp.platform_user_id as external_user_id,
                    COALESCE(cp.display_name, cp.full_name) as display_name,
                    cp.platform,
                    COALESCE(cp.avatar_url, cp.profile_pic_url) as avatar_url,
                    cp.phone,
                    cp.last_active_at as last_contact_at,
                    cp.created_at
                FROM customer_profiles cp
                WHERE cp.channel_id IN ($channelPlaceholders)
                AND (
                    COALESCE(cp.display_name, cp.full_name) LIKE ?
                    OR cp.platform_user_id LIKE ?
                    OR cp.phone LIKE ?
                    OR cp.email LIKE ?
                )
                ORDER BY cp.last_active_at DESC
                LIMIT ?
            ";
            $params = array_merge($userChannels, [$searchPattern, $searchPattern, $searchPattern, $searchPattern, $limit]);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $profiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($profiles as $profile) {
                $results[] = [
                    'id' => $profile['id'],
                    'external_user_id' => $profile['external_user_id'],
                    'display_name' => $profile['display_name'],
                    'platform' => $profile['platform'],
                    'avatar_url' => $profile['avatar_url'],
                    'phone' => $profile['phone'],
                    'last_contact_at' => $profile['last_contact_at'],
                    'source' => 'customer_profile'
                ];
            }
        } catch (PDOException $e) {
            // customer_profiles table issue, log and continue
            error_log("Customer search: customer_profiles query failed: " . $e->getMessage());
        }
    }

    // Also search in orders for customer info (with defensive column checking)
    if (count($results) < $limit) {
        try {
            $remaining = $limit - count($results);

            // Check which columns exist in orders table
            $orderCols = [];
            $colStmt = $pdo->query("SHOW COLUMNS FROM orders");
            while ($col = $colStmt->fetch(PDO::FETCH_ASSOC)) {
                $orderCols[$col['Field']] = true;
            }

            // Only run if customer_name or customer_phone exists
            $hasCustomerName = isset($orderCols['customer_name']);
            $hasCustomerPhone = isset($orderCols['customer_phone']);

            if ($hasCustomerName || $hasCustomerPhone) {
                // Build dynamic SELECT
                $selectParts = ['o.id'];
                if ($hasCustomerName)
                    $selectParts[] = 'o.customer_name as display_name';
                if ($hasCustomerPhone)
                    $selectParts[] = 'o.customer_phone as phone';
                if (isset($orderCols['customer_platform']))
                    $selectParts[] = 'o.customer_platform as platform';
                if (isset($orderCols['customer_avatar']))
                    $selectParts[] = 'o.customer_avatar as avatar_url';
                if (isset($orderCols['source']))
                    $selectParts[] = 'o.source';
                $selectParts[] = 'o.created_at as last_contact_at';

                // Build WHERE clause
                $whereParts = [];
                $whereParams = [];
                if ($hasCustomerName) {
                    $whereParts[] = 'o.customer_name LIKE ?';
                    $whereParams[] = $searchPattern;
                }
                if ($hasCustomerPhone) {
                    $whereParts[] = 'o.customer_phone LIKE ?';
                    $whereParams[] = $searchPattern;
                }

                if (!empty($whereParts)) {
                    $whereParams[] = $remaining;

                    $sql = "SELECT DISTINCT " . implode(', ', $selectParts) .
                        " FROM orders o WHERE " . implode(' OR ', $whereParts) .
                        " ORDER BY o.created_at DESC LIMIT ?";

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($whereParams);
                    $orderCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // Add unique ones
                    foreach ($orderCustomers as $oc) {
                        $duplicate = false;
                        foreach ($results as $r) {
                            if (
                                (isset($r['display_name']) && isset($oc['display_name']) && $r['display_name'] === $oc['display_name'] && ($r['phone'] ?? '') === ($oc['phone'] ?? '')) ||
                                (isset($oc['phone']) && $oc['phone'] && isset($r['phone']) && $r['phone'] === $oc['phone'])
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
            }
        } catch (PDOException $e) {
            // Orders table might not have customer columns
            error_log("Customer search: orders query failed: " . $e->getMessage());
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
        'message' => 'เกิดข้อผิดพลาดในการค้นหา',
        'debug_error' => $e->getMessage(),
        'debug_file' => $e->getFile(),
        'debug_line' => $e->getLine()
    ]);
}
