<?php
/**
 * Upload Subscription Payment Slip API
 * 
 * POST /api/user/upload-subscription-slip.php
 * - Upload slip image to GCS
 * - Create pending payment record
 * 
 * Required: JWT token, slip image file, amount
 */

// Prevent direct access to this file
if (basename($_SERVER['PHP_SELF']) !== 'upload-subscription-slip.php') {
    // Redirect if accessed incorrectly
}

// CORS headers for file upload
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/GoogleCloudStorage.php';
require_once __DIR__ . '/../../includes/SlipVerificationService.php';

// Verify JWT token and get user
function verifyToken()
{
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
        Logger::error('[UploadSlip] JWT secret not configured');
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
        Logger::error('[UploadSlip] JWT verify error', ['error' => $e->getMessage()]);
        return null;
    }
}

// Send JSON response
function respond($data, $statusCode = 200)
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed'], 405);
}

// Verify authentication
$user = verifyToken();
if (!$user) {
    respond(['success' => false, 'message' => 'Unauthorized'], 401);
}

$userId = $user['user_id'];

Logger::info('[UploadSlip] Upload request', ['user_id' => $userId]);

try {
    // Check for file upload
    if (!isset($_FILES['slip']) || $_FILES['slip']['error'] !== UPLOAD_ERR_OK) {
        $errorCode = $_FILES['slip']['error'] ?? UPLOAD_ERR_NO_FILE;
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'ไฟล์มีขนาดใหญ่เกินไป',
            UPLOAD_ERR_FORM_SIZE => 'ไฟล์มีขนาดใหญ่เกินไป',
            UPLOAD_ERR_PARTIAL => 'อัพโหลดไม่สำเร็จ กรุณาลองใหม่',
            UPLOAD_ERR_NO_FILE => 'กรุณาเลือกไฟล์สลิป',
            UPLOAD_ERR_NO_TMP_DIR => 'เกิดข้อผิดพลาดของเซิร์ฟเวอร์',
            UPLOAD_ERR_CANT_WRITE => 'เกิดข้อผิดพลาดของเซิร์ฟเวอร์',
        ];
        respond([
            'success' => false,
            'message' => $errorMessages[$errorCode] ?? 'เกิดข้อผิดพลาดในการอัพโหลด'
        ], 400);
    }

    $file = $_FILES['slip'];

    // Validate file type (images only)
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        respond([
            'success' => false,
            'message' => 'รองรับเฉพาะไฟล์รูปภาพ (JPG, PNG, WebP)'
        ], 400);
    }

    // Validate file size (max 10MB)
    $maxSize = 10 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        respond([
            'success' => false,
            'message' => 'ไฟล์มีขนาดใหญ่เกินไป (สูงสุด 10MB)'
        ], 400);
    }

    // Get amount from request (now optional - can be auto-detected by slip verification)
    $manualAmount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
    $amount = $manualAmount; // Will be updated if slip verification succeeds

    // Read file content
    $fileContent = file_get_contents($file['tmp_name']);
    if ($fileContent === false) {
        respond([
            'success' => false,
            'message' => 'ไม่สามารถอ่านไฟล์ได้'
        ], 500);
    }

    // Upload to GCS
    $gcs = GoogleCloudStorage::getInstance();
    $folder = 'subscription-slips/' . $userId;

    $uploadResult = $gcs->uploadFile(
        $fileContent,
        $file['name'],
        $mimeType,
        $folder,
        [
            'user_id' => $userId,
            'amount' => $amount,
            'type' => 'subscription_payment'
        ]
    );

    if (!$uploadResult['success']) {
        Logger::error('[UploadSlip] GCS upload failed', ['result' => $uploadResult]);
        respond([
            'success' => false,
            'message' => 'ไม่สามารถอัพโหลดไฟล์ได้'
        ], 500);
    }

    // ✅ Slip Verification - try to auto-extract amount and verify
    $slipVerifier = SlipVerificationService::getInstance();
    $verificationResult = null;
    $isVerified = false;
    $verifiedAmount = null;

    if ($slipVerifier->isEnabled()) {
        Logger::info('[UploadSlip] Calling slip verification API');

        $slipUrl = $uploadResult['signed_url'] ?? $uploadResult['url'] ?? '';
        $verificationResult = $slipVerifier->verifySlip($slipUrl);

        if ($verificationResult['success'] && $verificationResult['verified']) {
            $isVerified = true;
            $verifiedAmount = $verificationResult['amount'];

            // Use verified amount if manual amount not provided
            if ($manualAmount <= 0 && $verifiedAmount > 0) {
                $amount = $verifiedAmount;
            }

            Logger::info('[UploadSlip] Slip verified successfully', [
                'verified_amount' => $verifiedAmount,
                'ref_no' => $verificationResult['ref_no'] ?? null
            ]);
        } else {
            Logger::warning('[UploadSlip] Slip verification failed', [
                'error' => $verificationResult['error'] ?? 'Unknown'
            ]);
        }
    }

    // ✅ Validate: must have amount from manual input OR verification
    if ($amount <= 0) {
        // If verification is not enabled, require manual amount
        if (!$slipVerifier->isEnabled()) {
            respond([
                'success' => false,
                'message' => 'กรุณาระบุจำนวนเงินที่โอน'
            ], 400);
        } else {
            // Verification enabled but failed to extract amount
            respond([
                'success' => false,
                'message' => 'ไม่สามารถอ่านจำนวนเงินจากสลิปได้ กรุณาระบุจำนวนเงินที่โอน'
            ], 400);
        }
    }

    // Determine status based on verification
    $status = $isVerified ? 'verified' : 'pending';

    // Save to database
    $db = Database::getInstance();

    $db->execute(
        "INSERT INTO subscription_payments (user_id, amount, slip_url, gcs_path, status, verified_amount, verification_ref, verification_data, created_at) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
        [
            $userId,
            $amount,
            $uploadResult['url'] ?? $uploadResult['signed_url'] ?? '',
            $uploadResult['path'] ?? '',
            $status,
            $verifiedAmount,
            $verificationResult['ref_no'] ?? null,
            $verificationResult ? json_encode($verificationResult['raw_response'] ?? []) : null
        ]
    );

    $paymentId = $db->lastInsertId();

    Logger::info('[UploadSlip] Payment record created', [
        'payment_id' => $paymentId,
        'user_id' => $userId,
        'amount' => $amount,
        'is_verified' => $isVerified,
        'gcs_path' => $uploadResult['path'] ?? ''
    ]);

    // Build response message
    $message = $isVerified
        ? 'อัพโหลดและตรวจสอบสลิปสำเร็จ!'
        : 'อัพโหลดสลิปสำเร็จ รอการตรวจสอบจากทีมงาน';

    respond([
        'success' => true,
        'message' => $message,
        'data' => [
            'payment_id' => $paymentId,
            'amount' => $amount,
            'status' => $status,
            'is_verified' => $isVerified,
            'verified_amount' => $verifiedAmount,
            'slip_url' => $uploadResult['signed_url'] ?? $uploadResult['url'] ?? ''
        ]
    ]);

} catch (Exception $e) {
    Logger::error('[UploadSlip] Error', [
        'error' => $e->getMessage(),
        'user_id' => $userId ?? null
    ]);

    respond([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด กรุณาลองใหม่'
    ], 500);
}
