<?php
/**
 * LINE Application System - OCR Webhook API
 * 
 * Endpoint:
 *   POST /api/lineapp/ocr-webhook.php - Receive OCR results from async job
 * 
 * This endpoint is called by the OCR processing service after document analysis is complete.
 * It updates the document and application records with OCR results and triggers notifications.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Logger.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use POST.'
    ]);
    exit;
}

try {
    $db = Database::getInstance()->getPdo();
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    if (!isset($input['document_id'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing required field: document_id'
        ]);
        exit;
    }
    
    $documentId = (int)$input['document_id'];
    $ocrText = $input['ocr_text'] ?? null;
    $ocrData = $input['ocr_data'] ?? null;
    $ocrConfidence = isset($input['ocr_confidence']) ? (float)$input['ocr_confidence'] : null;
    $ocrError = $input['ocr_error'] ?? null;
    $ocrProvider = $input['ocr_provider'] ?? 'google_vision';
    
    // Validate webhook signature/token (important for production)
    $webhookToken = $input['webhook_token'] ?? '';
    $expectedToken = getenv('OCR_WEBHOOK_TOKEN') ?: 'dev_token_12345';
    
    if ($webhookToken !== $expectedToken) {
        Logger::warning('[API_LINEAPP_OCR_WEBHOOK] Invalid webhook token', [
            'document_id' => $documentId,
            'provided_token' => substr($webhookToken, 0, 10) . '...'
        ]);
        
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid webhook token'
        ]);
        exit;
    }
    
    // Get document info
    $stmt = $db->prepare("
        SELECT d.*, a.id as application_id, a.campaign_id, c.min_ocr_confidence, c.auto_approve, c.auto_approve_confidence
        FROM application_documents d
        JOIN line_applications a ON d.application_id = a.id
        JOIN campaigns c ON a.campaign_id = c.id
        WHERE d.id = ?
    ");
    
    $stmt->execute([$documentId]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$document) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Document not found'
        ]);
        exit;
    }
    
    $applicationId = $document['application_id'];
    
    // Start transaction
    $db->beginTransaction();
    
    try {
        // Update document with OCR results
        $stmt = $db->prepare("
            UPDATE application_documents
            SET ocr_processed = 1,
                ocr_text = :ocr_text,
                ocr_data = :ocr_data,
                ocr_confidence = :ocr_confidence,
                ocr_error = :ocr_error,
                ocr_provider = :ocr_provider,
                ocr_processed_at = NOW(),
                updated_at = NOW()
            WHERE id = :document_id
        ");
        
        $stmt->execute([
            'ocr_text' => $ocrText,
            'ocr_data' => $ocrData ? json_encode($ocrData) : null,
            'ocr_confidence' => $ocrConfidence,
            'ocr_error' => $ocrError,
            'ocr_provider' => $ocrProvider,
            'document_id' => $documentId
        ]);
        
        // Get all documents for this application to build consolidated OCR results
        $stmt = $db->prepare("
            SELECT id, document_type, ocr_data, ocr_confidence, ocr_processed
            FROM application_documents
            WHERE application_id = ?
            ORDER BY uploaded_at ASC
        ");
        $stmt->execute([$applicationId]);
        $allDocuments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Build consolidated OCR results
        $consolidatedOcrResults = [];
        $totalConfidence = 0;
        $processedCount = 0;
        $allProcessed = true;
        
        foreach ($allDocuments as $doc) {
            if ($doc['ocr_processed']) {
                $processedCount++;
                $docData = json_decode($doc['ocr_data'], true);
                $consolidatedOcrResults[$doc['document_type']] = $docData;
                if ($doc['ocr_confidence']) {
                    $totalConfidence += $doc['ocr_confidence'];
                }
            } else {
                $allProcessed = false;
            }
        }
        
        $averageConfidence = $processedCount > 0 ? $totalConfidence / $processedCount : 0;
        
        // Determine new application status
        $newStatus = null;
        $needsManualReview = false;
        $statusChangeReason = '';
        
        if ($allProcessed) {
            // All documents have been processed
            $minConfidence = (float)$document['min_ocr_confidence'];
            
            if ($ocrError) {
                $newStatus = 'NEED_REVIEW';
                $needsManualReview = true;
                $statusChangeReason = 'OCR processing failed: ' . $ocrError;
                
            } elseif ($averageConfidence < $minConfidence) {
                $newStatus = 'NEED_REVIEW';
                $needsManualReview = true;
                $statusChangeReason = sprintf('OCR confidence %.2f below threshold %.2f', $averageConfidence, $minConfidence);
                
            } elseif ($document['auto_approve'] && $averageConfidence >= (float)$document['auto_approve_confidence']) {
                $newStatus = 'APPROVED';
                $statusChangeReason = sprintf('Auto-approved: OCR confidence %.2f meets threshold %.2f', $averageConfidence, (float)$document['auto_approve_confidence']);
                
            } else {
                $newStatus = 'OCR_DONE';
                $statusChangeReason = sprintf('OCR completed with average confidence %.2f', $averageConfidence);
            }
        } else {
            $newStatus = 'OCR_PROCESSING';
            $statusChangeReason = sprintf('OCR processing (%d/%d documents completed)', $processedCount, count($allDocuments));
        }
        
        // Update application
        $stmt = $db->prepare("
            SELECT status, status_history FROM line_applications WHERE id = ?
        ");
        $stmt->execute([$applicationId]);
        $currentApp = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $statusHistory = json_decode($currentApp['status_history'], true) ?? [];
        $statusHistory[] = [
            'from' => $currentApp['status'],
            'to' => $newStatus,
            'changed_by' => 'system',
            'changed_by_id' => null,
            'changed_at' => date('Y-m-d H:i:s'),
            'reason' => $statusChangeReason
        ];
        
        $stmt = $db->prepare("
            UPDATE line_applications
            SET ocr_results = :ocr_results,
                status = :status,
                needs_manual_review = :needs_manual_review,
                status_changed_at = NOW(),
                status_history = :status_history,
                updated_at = NOW()
            WHERE id = :application_id
        ");
        
        $stmt->execute([
            'ocr_results' => json_encode($consolidatedOcrResults),
            'status' => $newStatus,
            'needs_manual_review' => $needsManualReview ? 1 : 0,
            'status_history' => json_encode($statusHistory),
            'application_id' => $applicationId
        ]);
        
        // Commit transaction
        $db->commit();
        
        Logger::info('[API_LINEAPP_OCR_WEBHOOK] OCR results processed', [
            'document_id' => $documentId,
            'application_id' => $applicationId,
            'ocr_confidence' => $ocrConfidence,
            'average_confidence' => $averageConfidence,
            'new_status' => $newStatus,
            'needs_manual_review' => $needsManualReview
        ]);
        
        // TODO: Trigger LINE notification based on new status
        // For now, just log it
        Logger::info('[API_LINEAPP_OCR_WEBHOOK] Notification should be sent', [
            'application_id' => $applicationId,
            'status' => $newStatus
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'OCR results processed successfully',
            'data' => [
                'document_id' => $documentId,
                'application_id' => $applicationId,
                'new_status' => $newStatus,
                'average_confidence' => round($averageConfidence, 4),
                'needs_manual_review' => $needsManualReview,
                'all_documents_processed' => $allProcessed
            ]
        ]);
        
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
    
} catch (PDOException $e) {
    Logger::error('[API_LINEAPP_OCR_WEBHOOK] Database error', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
    ]);
    
} catch (Exception $e) {
    Logger::error('[API_LINEAPP_OCR_WEBHOOK] Error', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred'
    ]);
}
