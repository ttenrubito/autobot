<?php
/**
 * Subscription Payments History API
 * 
 * GET /api/user/subscription-payments.php
 * - Get list of user's subscription payment records
 * 
 * Required: JWT token
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Logger.php';

// Verify JWT token and get user
function verifyToken() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    
    if (empty($authHeader)) {
        return null;
    }
    
    // Extract token from "Bearer {token}"
    if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
        $token = $matches[1];
    } else {
        return null;
    }
    
    // Load JWT secret
    $securityConfig = __DIR__ . '/../../config-security.php';
    if (file_exists($securityConfig)) {
        require_once $securityConfig;
    }
    $jwtSecret = defined('JWT_SECRET') ? JWT_SECRET : (getenv('JWT_SECRET') ?: '');
    
    if (empty($jwtSecret)) {
        Logger::error('[PaymentHistory] JWT secret not configured');
        return null;
    }
    
    try {
        // Simple JWT decode (HS256)
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!$payload || !isset($payload['user_id'])) {
            return null;
        }
        
        // Verify signature
        $dataToSign = $parts[0] . '.' . $parts[1];
        $signature = base64_decode(strtr($parts[2], '-_', '+/'));
        $expectedSignature = hash_hmac('sha256', $dataToSign, $jwtSecret, true);
        
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }
        
        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }
        
        return [
            'user_id' => $payload['user_id'],
            'email' => $payload['email'] ?? null,
            'role' => $payload['role'] ?? 'user'
        ];
    } catch (Exception $e) {
        Logger::error('[PaymentHistory] JWT verify error', ['error' => $e->getMessage()]);
        return null;
    }
}

// Send JSON response
function respond($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['success' => false, 'message' => 'Method not allowed'], 405);
}

// Verify authentication
$user = verifyToken();
if (!$user) {
    respond(['success' => false, 'message' => 'Unauthorized'], 401);
}

$userId = $user['user_id'];

try {
    $db = Database::getInstance();
    
    // Get payment history ordered by newest first
    $payments = $db->query(
        "SELECT 
            id,
            amount,
            slip_url,
            status,
            days_added,
            rejection_reason,
            notes,
            created_at,
            verified_at
         FROM subscription_payments 
         WHERE user_id = ? 
         ORDER BY created_at DESC 
         LIMIT 50",
        [$userId]
    );
    
    // Format dates for display
    $formattedPayments = array_map(function($p) {
        // Status labels in Thai
        $statusLabels = [
            'pending' => ['label' => 'รอตรวจสอบ', 'color' => 'warning', 'icon' => '⏳'],
            'verified' => ['label' => 'ตรวจสอบแล้ว', 'color' => 'success', 'icon' => '✅'],
            'rejected' => ['label' => 'ถูกปฏิเสธ', 'color' => 'danger', 'icon' => '❌']
        ];
        
        $status = $p['status'] ?? 'pending';
        
        return [
            'id' => (int)$p['id'],
            'amount' => floatval($p['amount']),
            'amount_formatted' => number_format($p['amount'], 0) . ' บาท',
            'slip_url' => $p['slip_url'] ?? '',
            'status' => $status,
            'status_label' => $statusLabels[$status]['label'] ?? $status,
            'status_color' => $statusLabels[$status]['color'] ?? 'secondary',
            'status_icon' => $statusLabels[$status]['icon'] ?? '',
            'days_added' => (int)($p['days_added'] ?? 0),
            'rejection_reason' => $p['rejection_reason'] ?? null,
            'notes' => $p['notes'] ?? null,
            'created_at' => $p['created_at'],
            'created_at_formatted' => date('d/m/Y H:i', strtotime($p['created_at'])),
            'verified_at' => $p['verified_at'] ?? null,
            'verified_at_formatted' => $p['verified_at'] 
                ? date('d/m/Y H:i', strtotime($p['verified_at'])) 
                : null
        ];
    }, $payments);
    
    respond([
        'success' => true,
        'data' => [
            'payments' => $formattedPayments,
            'total' => count($formattedPayments)
        ]
    ]);
    
} catch (Exception $e) {
    Logger::error('[PaymentHistory] Error', [
        'error' => $e->getMessage(),
        'user_id' => $userId ?? null
    ]);
    
    respond([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด กรุณาลองใหม่'
    ], 500);
}
