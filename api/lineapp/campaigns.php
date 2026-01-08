<?php
/**
 * LINE Application System - Campaigns API
 * 
 * Endpoints:
 *   GET  /api/lineapp/campaigns.php          - List active campaigns
 *   GET  /api/lineapp/campaigns.php?id=1     - Get campaign details
 * 
 * Returns campaign data including form_config for dynamic form rendering
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Logger.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use GET.'
    ]);
    exit;
}

try {
    $db = Database::getInstance()->getPdo();
    
    // Get campaign by ID or CODE
    if (isset($_GET['id']) || isset($_GET['code'])) {
        $campaign = null;
        
        // Query by ID
        if (isset($_GET['id'])) {
            $campaignId = (int)$_GET['id'];
            
            $stmt = $db->prepare("
                SELECT 
                    id,
                    code,
                    name,
                    description,
                    form_config,
                    required_documents,
                    ocr_enabled,
                    ocr_fields,
                    start_date,
                    end_date,
                    is_active,
                    max_applications,
                    notification_template_received,
                    liff_id,
                    created_at
                FROM campaigns
                WHERE id = ? AND is_active = 1
            ");
            
            $stmt->execute([$campaignId]);
            $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        // Query by CODE
        else if (isset($_GET['code'])) {
            $campaignCode = trim($_GET['code']);
            
            $stmt = $db->prepare("
                SELECT 
                    id,
                    code,
                    name,
                    description,
                    form_config,
                    required_documents,
                    ocr_enabled,
                    ocr_fields,
                    start_date,
                    end_date,
                    is_active,
                    max_applications,
                    notification_template_received,
                    liff_id,
                    created_at
                FROM campaigns
                WHERE code = ? AND is_active = 1
            ");
            
            $stmt->execute([$campaignCode]);
            $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        if (!$campaign) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Campaign not found or inactive'
            ]);
            exit;
        }
        
        // Check if campaign is within validity period
        $now = date('Y-m-d');
        if ($campaign['start_date'] && $campaign['start_date'] > $now) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Campaign has not started yet',
                'start_date' => $campaign['start_date']
            ]);
            exit;
        }
        
        if ($campaign['end_date'] && $campaign['end_date'] < $now) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Campaign has ended',
                'end_date' => $campaign['end_date']
            ]);
            exit;
        }
        
        // Check max applications limit
        if ($campaign['max_applications']) {
            $stmt = $db->prepare("
                SELECT COUNT(*) as app_count
                FROM line_applications
                WHERE campaign_id = ?
            ");
            $stmt->execute([$campaignId]);
            $count = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($count['app_count'] >= $campaign['max_applications']) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Campaign has reached maximum applications limit'
                ]);
                exit;
            }
        }
        
        // Decode JSON fields
        $campaign['form_config'] = json_decode($campaign['form_config'], true);
        $campaign['required_documents'] = json_decode($campaign['required_documents'], true);
        $campaign['ocr_fields'] = json_decode($campaign['ocr_fields'], true);
        
        echo json_encode([
            'success' => true,
            'data' => $campaign
        ]);
        
    } else {
        // List all active campaigns
        $stmt = $db->prepare("
            SELECT 
                id,
                code,
                name,
                description,
                start_date,
                end_date,
                is_active,
                liff_id,
                created_at,
                (
                    SELECT COUNT(*)
                    FROM line_applications la
                    WHERE la.campaign_id = campaigns.id
                ) as application_count,
                max_applications
            FROM campaigns
            WHERE is_active = 1
                AND (start_date IS NULL OR start_date <= CURDATE())
                AND (end_date IS NULL OR end_date >= CURDATE())
            ORDER BY created_at DESC
        ");
        
        $stmt->execute();
        $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Filter out campaigns that reached max applications
        $availableCampaigns = array_filter($campaigns, function($campaign) {
            if ($campaign['max_applications'] === null) {
                return true;
            }
            return $campaign['application_count'] < $campaign['max_applications'];
        });
        
        // Reset array keys
        $availableCampaigns = array_values($availableCampaigns);
        
        echo json_encode([
            'success' => true,
            'data' => $availableCampaigns,
            'count' => count($availableCampaigns)
        ]);
    }
    
} catch (PDOException $e) {
    Logger::error('[API_LINEAPP_CAMPAIGNS] Database error', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
    ]);
    
} catch (Exception $e) {
    Logger::error('[API_LINEAPP_CAMPAIGNS] Error', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred'
    ]);
}
