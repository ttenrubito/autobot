<?php
/**
 * Cronjob Management API
 * 
 * Endpoints:
 * GET  /api/admin/cronjobs.php                - List all cronjobs
 * GET  /api/admin/cronjobs.php?logs=1         - Get execution logs
 * POST /api/admin/cronjobs.php?action=run     - Manually run a cronjob
 * 
 * @version 1.0
 * @date 2026-01-29
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/auth.php';

// Verify authentication
$auth = verifyToken();
if (!$auth['valid']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = Database::getInstance();

    if ($method === 'GET') {
        if (isset($_GET['logs']) && $_GET['logs'] === '1') {
            // Get execution logs
            getCronjobLogs($db);
        } else {
            // List all cronjobs
            listCronjobs($db);
        }
    } elseif ($method === 'POST') {
        $action = $_GET['action'] ?? null;
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        
        if ($action === 'run') {
            runCronjob($db, $input);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }

} catch (Exception $e) {
    Logger::error('Cronjobs API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}

/**
 * List all configured cronjobs
 */
function listCronjobs($db) {
    // Define all cronjobs in the system
    $cronjobs = [
        [
            'id' => 'installment-reminders',
            'name' => 'แจ้งเตือนผ่อนชำระ',
            'description' => 'ส่งแจ้งเตือนลูกค้าที่มีงวดผ่อนครบกำหนด หรือเลยกำหนด',
            'schedule' => '0 9 * * *',
            'schedule_text' => 'ทุกวัน 9:00 น.',
            'endpoint' => '/api/cron/installment-reminders.php',
            'method' => 'POST',
            'status' => 'enabled',
            'cloud_scheduler' => true,
            'features' => [
                '3 วันก่อนครบกำหนด',
                '1 วันก่อนครบกำหนด',
                'วันครบกำหนด',
                'เลยกำหนด 1, 3, 7, 14 วัน'
            ]
        ],
        [
            'id' => 'order-status-check',
            'name' => 'ตรวจสถานะออเดอร์',
            'description' => 'ตรวจสอบออเดอร์ที่รอชำระเกิน 24 ชม. และแจ้งเตือน',
            'schedule' => '0 10 * * *',
            'schedule_text' => 'ทุกวัน 10:00 น.',
            'endpoint' => '/api/cron/order-status-check.php',
            'method' => 'POST',
            'status' => 'planned',
            'cloud_scheduler' => false,
            'features' => [
                'ตรวจออเดอร์รอชำระเกิน 24 ชม.',
                'แจ้งเตือนลูกค้า',
                'ยกเลิกออเดอร์อัตโนมัติ (หลัง 7 วัน)'
            ]
        ],
        [
            'id' => 'daily-report',
            'name' => 'รายงานประจำวัน',
            'description' => 'สรุปยอดขาย/รับชำระ ส่งให้ admin ทุกเช้า',
            'schedule' => '0 8 * * *',
            'schedule_text' => 'ทุกวัน 8:00 น.',
            'endpoint' => '/api/cron/daily-report.php',
            'method' => 'POST',
            'status' => 'planned',
            'cloud_scheduler' => false,
            'features' => [
                'สรุปยอดขายวันก่อน',
                'สรุปการชำระเงิน',
                'ลูกค้าใหม่',
                'ส่งผ่าน LINE Notify'
            ]
        ],
        [
            'id' => 'subscription-check',
            'name' => 'ตรวจสอบ Subscription',
            'description' => 'ตรวจสอบ subscription ที่ใกล้หมดอายุ',
            'schedule' => '0 7 * * *',
            'schedule_text' => 'ทุกวัน 7:00 น.',
            'endpoint' => '/api/cron/subscription-check.php',
            'method' => 'POST',
            'status' => 'planned',
            'cloud_scheduler' => false,
            'features' => [
                'แจ้งเตือน 7 วันก่อนหมดอายุ',
                'แจ้งเตือน 3 วันก่อนหมดอายุ',
                'แจ้งเตือนวันหมดอายุ'
            ]
        ],
        [
            'id' => 'cleanup-temp-files',
            'name' => 'ล้างไฟล์ชั่วคราว',
            'description' => 'ลบไฟล์ temp ที่เก่าเกิน 7 วัน',
            'schedule' => '0 3 * * 0',
            'schedule_text' => 'ทุกวันอาทิตย์ 3:00 น.',
            'endpoint' => '/api/cron/cleanup-temp.php',
            'method' => 'POST',
            'status' => 'planned',
            'cloud_scheduler' => false,
            'features' => [
                'ลบไฟล์ temp',
                'ลบ session หมดอายุ',
                'ลบ log เก่าเกิน 30 วัน'
            ]
        ]
    ];

    // Get last execution for each cronjob
    foreach ($cronjobs as &$job) {
        $lastExec = $db->queryOne(
            "SELECT * FROM cronjob_logs WHERE job_id = ? ORDER BY executed_at DESC LIMIT 1",
            [$job['id']]
        );
        
        if ($lastExec) {
            $job['last_executed'] = $lastExec['executed_at'];
            $job['last_status'] = $lastExec['status'];
            $job['last_result'] = json_decode($lastExec['result'] ?? '{}', true);
        } else {
            $job['last_executed'] = null;
            $job['last_status'] = null;
            $job['last_result'] = null;
        }
    }

    echo json_encode([
        'success' => true,
        'data' => $cronjobs,
        'server_time' => date('Y-m-d H:i:s'),
        'timezone' => 'Asia/Bangkok'
    ]);
}

/**
 * Get cronjob execution logs
 */
function getCronjobLogs($db) {
    $jobId = $_GET['job_id'] ?? null;
    $limit = min((int)($_GET['limit'] ?? 50), 200);
    
    if ($jobId) {
        $logs = $db->query(
            "SELECT * FROM cronjob_logs WHERE job_id = ? ORDER BY executed_at DESC LIMIT ?",
            [$jobId, $limit]
        );
    } else {
        $logs = $db->query(
            "SELECT * FROM cronjob_logs ORDER BY executed_at DESC LIMIT ?",
            [$limit]
        );
    }
    
    // Parse JSON results
    foreach ($logs as &$log) {
        $log['result'] = json_decode($log['result'] ?? '{}', true);
    }

    echo json_encode([
        'success' => true,
        'data' => $logs
    ]);
}

/**
 * Manually run a cronjob
 */
function runCronjob($db, $input) {
    $jobId = $input['job_id'] ?? null;
    
    if (!$jobId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'job_id is required']);
        return;
    }

    $startTime = microtime(true);
    $result = null;
    $status = 'success';
    $error = null;

    try {
        switch ($jobId) {
            case 'installment-reminders':
                // Call the API internally
                $result = callInternalApi('/api/cron/installment-reminders.php', 'POST');
                break;
                
            case 'order-status-check':
            case 'daily-report':
            case 'subscription-check':
            case 'cleanup-temp-files':
                $status = 'skipped';
                $error = 'This cronjob is not yet implemented';
                break;
                
            default:
                $status = 'error';
                $error = 'Unknown job_id: ' . $jobId;
        }
    } catch (Exception $e) {
        $status = 'error';
        $error = $e->getMessage();
    }

    $duration = round((microtime(true) - $startTime) * 1000); // ms

    // Log execution
    $db->execute(
        "INSERT INTO cronjob_logs (job_id, status, result, error_message, duration_ms, executed_at, created_at) 
         VALUES (?, ?, ?, ?, ?, NOW(), NOW())",
        [
            $jobId,
            $status,
            json_encode($result),
            $error,
            $duration
        ]
    );

    echo json_encode([
        'success' => $status === 'success',
        'job_id' => $jobId,
        'status' => $status,
        'result' => $result,
        'error' => $error,
        'duration_ms' => $duration
    ]);
}

/**
 * Call internal API
 */
function callInternalApi($endpoint, $method = 'GET', $data = null) {
    $url = 'https://autobot.boxdesign.in.th' . $endpoint;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-API-KEY: autobot-cron-secret-2026'
    ]);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode >= 400) {
        throw new Exception("API returned HTTP $httpCode");
    }
    
    return json_decode($response, true);
}
