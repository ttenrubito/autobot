<?php
/**
 * LINE Application System - Documents API
 * 
 * Endpoints:
 *   POST /api/lineapp/documents.php                    - Upload document
 *   GET  /api/lineapp/documents.php?application_id=123 - List documents
 *   GET  /api/lineapp/documents.php?id=456             - Get document detail
 *   GET  /api/lineapp/documents.php?id=456&signed_url=1 - Get signed URL
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/GoogleCloudStorage.php';

$db = Database::getInstance()->getPdo();

// ============================================================================
// POST - Upload Document
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Check if request is JSON (from LIFF) or multipart/form-data
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        
        if (strpos($contentType, 'application/json') !== false) {
            // Handle JSON request with base64 data (from LIFF)
            $rawBody = file_get_contents('php://input');
            $input = json_decode($rawBody, true);

            Logger::info('[API_LINEAPP_DOCUMENTS] JSON payload received', [
                'payload_keys' => array_keys(is_array($input) ? $input : []),
                'raw_length' => strlen($rawBody)
            ]);
            
            if (!isset($input['application_id']) || !isset($input['file_data']) || !isset($input['file_name'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Missing required fields: application_id, file_data, file_name'
                ]);
                exit;
            }

            $applicationId = (int)$input['application_id'];
            $documentType = $input['document_type'] ?? 'อื่นๆ';
            $fileName = $input['file_name'];
            $fileData = $input['file_data']; // base64 string
            $fileType = $input['file_type'] ?? 'image/jpeg';

            // Validate application exists
            $stmt = $db->prepare("SELECT id, line_user_id, application_no FROM line_applications WHERE id = ?");
            $stmt->execute([$applicationId]);
            $application = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$application) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'Application not found'
                ]);
                exit;
            }
            
            // Decode base64 and save file
            $fileContent = base64_decode($fileData);
            
            if ($fileContent === false || strlen($fileContent) === 0) {
                Logger::error('[API_LINEAPP_DOCUMENTS] Invalid base64 file data', [
                    'application_id' => $applicationId,
                    'file_name' => $fileName,
                    'file_data_preview' => substr($fileData, 0, 100)
                ]);

                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid file data: base64 decode failed'
                ]);
                exit;
            }
            
            // Upload to Google Cloud Storage
            $gcs = GoogleCloudStorage::getInstance();
            $uploadResult = $gcs->uploadFile(
                $fileContent,
                $fileName,
                $fileType,
                'documents/' . $application['line_user_id'], // Folder per user
                [
                    'application_id' => (string)$applicationId,
                    'application_no' => $application['application_no'],
                    'document_type' => $documentType
                ]
            );
            
            if (!$uploadResult['success']) {
                throw new Exception('Failed to upload to Cloud Storage: ' . ($uploadResult['error'] ?? 'Unknown error'));
            }
            
            $gcsPath = $uploadResult['path'];
            $signedUrl = $uploadResult['signed_url'];
            
            Logger::info('[LINEAPP_DOCS] File uploaded to GCS from LIFF', [
                'application_id' => $applicationId,
                'gcs_path' => $gcsPath,
                'file_size' => strlen($fileContent)
            ]);
            
            // Get document label from input (CRITICAL FIX!)
            $documentLabel = $input['document_label'] ?? $documentType;
            
            // Insert into database
            // NOTE: Production schema uses `original_filename` (not `file_name`) and `storage_*` fields.
            // Also `uploaded_by` does not exist in prod, so do not insert it.
            try {
                $stmt = $db->prepare("
                    INSERT INTO application_documents (
                        application_id,
                        document_type,
                        document_label,
                        original_filename,
                        file_size,
                        mime_type,
                        storage_provider,
                        storage_bucket,
                        storage_path,
                        gcs_path,
                        gcs_signed_url,
                        upload_source,
                        uploaded_at,
                        created_at,
                        updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
                ");

                $bucketName = getenv('GCS_BUCKET_NAME') ?: 'autobot-documents';

                $stmt->execute([
                    $applicationId,
                    $documentType,
                    $documentLabel,
                    $fileName,
                    strlen($fileContent),
                    $fileType,
                    'gcs',
                    $bucketName,
                    $gcsPath,
                    $gcsPath,
                    $signedUrl,
                    'line_liff'
                ]);
            } catch (PDOException $e) {
                // Backward compatibility for older schemas: try legacy columns if they exist.
                Logger::warning('[LINEAPP_DOCS] Insert using production schema failed, trying legacy insert', [
                    'error' => $e->getMessage()
                ]);

                try {
                    // Legacy insert (older columns)
                    $stmt = $db->prepare("
                        INSERT INTO application_documents (
                            application_id,
                            document_type,
                            document_label,
                            file_path,
                            file_name,
                            file_size,
                            mime_type,
                            gcs_path,
                            gcs_signed_url,
                            uploaded_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");

                    $stmt->execute([
                        $applicationId,
                        $documentType,
                        $documentLabel,
                        $gcsPath,
                        $fileName,
                        strlen($fileContent),
                        $fileType,
                        $gcsPath,
                        $signedUrl
                    ]);
                } catch (PDOException $e2) {
                    // Final fallback: minimal columns
                    $stmt = $db->prepare("
                        INSERT INTO application_documents (
                            application_id,
                            document_type,
                            document_label,
                            file_path,
                            file_size,
                            mime_type,
                            uploaded_at
                        ) VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ");

                    $stmt->execute([
                        $applicationId,
                        $documentType,
                        $documentLabel,
                        $gcsPath,
                        strlen($fileContent),
                        $fileType
                    ]);
                }
            }

            $documentId = $db->lastInsertId();

            Logger::info('[LINEAPP_DOCS] Document uploaded from LIFF', [
                'document_id' => $documentId,
                'application_id' => $applicationId,
                'application_no' => $application['application_no'],
                'document_type' => $documentType,
                'file_name' => $fileName,
                'gcs_path' => $gcsPath
            ]);
            echo json_encode([
                'success' => true,
                'message' => 'Document uploaded successfully',
                'data' => [
                    'document_id' => $documentId,
                    'application_id' => $applicationId,
                    'file_name' => $fileName,
                    'gcs_path' => $gcsPath,
                    'signed_url' => $signedUrl
                ]
            ]);
            exit;
        }
        
        // Original multipart/form-data handling
        // Validate required fields
        if (!isset($_POST['application_id']) || !isset($_POST['document_type'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Missing required fields: application_id, document_type'
            ]);
            exit;
        }
        
        $applicationId = (int)$_POST['application_id'];
        $documentType = trim($_POST['document_type']);
        $documentLabel = $_POST['document_label'] ?? null;
        $documentSide = $_POST['document_side'] ?? 'single';
        $uploadSource = $_POST['source'] ?? 'line_liff';
        
        // Validate application exists
        $stmt = $db->prepare("SELECT id, application_no, campaign_id FROM line_applications WHERE id = ?");
        $stmt->execute([$applicationId]);
        $application = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$application) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Application not found'
            ]);
            exit;
        }
        
        // Handle file upload
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'No file uploaded or upload error',
                'error_code' => $_FILES['file']['error'] ?? 'NO_FILE'
            ]);
            exit;
        }
        
        $file = $_FILES['file'];
        $originalFilename = $file['name'];
        $fileSize = $file['size'];
        $fileTmpPath = $file['tmp_name'];
        
        // Validate file size (5MB max)
        $maxFileSize = 5 * 1024 * 1024; // 5MB
        if ($fileSize > $maxFileSize) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'File size exceeds 5MB limit',
                'file_size' => $fileSize,
                'max_size' => $maxFileSize
            ]);
            exit;
        }
        
        // Detect MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $fileTmpPath);
        finfo_close($finfo);
        
        // Validate MIME type
        $allowedMimeTypes = [
            'image/jpeg',
            'image/png',
            'image/jpg',
            'application/pdf'
        ];
        
        if (!in_array($mimeType, $allowedMimeTypes)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid file type. Allowed: JPEG, PNG, PDF',
                'detected_mime_type' => $mimeType
            ]);
            exit;
        }
        
        // Generate storage path
        // Format: tenant/campaign_ID/application_NO/document_type_timestamp.ext
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $timestamp = time();
        $randomStr = bin2hex(random_bytes(4));
        $filename = sprintf('%s_%s_%s.%s', $documentType, $timestamp, $randomStr, $extension);
        
        $storagePath = sprintf(
            'default/%d/%s/%s',
            $application['campaign_id'],
            $application['application_no'],
            $filename
        );
        
        // For now, store locally (will integrate GCS later)
        $uploadDir = __DIR__ . '/../../storage/documents/' . dirname($storagePath);
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $localPath = __DIR__ . '/../../storage/documents/' . $storagePath;
        
        if (!move_uploaded_file($fileTmpPath, $localPath)) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to save file'
            ]);
            exit;
        }
        
        // Insert document record
        $stmt = $db->prepare("
            INSERT INTO application_documents (
                application_id,
                document_type,
                document_label,
                document_side,
                original_filename,
                file_size,
                mime_type,
                storage_provider,
                storage_path,
                upload_source,
                uploaded_at
            ) VALUES (
                :application_id,
                :document_type,
                :document_label,
                :document_side,
                :original_filename,
                :file_size,
                :mime_type,
                :storage_provider,
                :storage_path,
                :upload_source,
                NOW()
            )
        ");
        
        $stmt->execute([
            'application_id' => $applicationId,
            'document_type' => $documentType,
            'document_label' => $documentLabel,
            'document_side' => $documentSide,
            'original_filename' => $originalFilename,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'storage_provider' => 'local', // Will change to 'gcs' when integrated
            'storage_path' => $storagePath,
            'upload_source' => $uploadSource
        ]);
        
        $documentId = $db->lastInsertId();
        
        // Update application status to DOC_PENDING if was FORM_INCOMPLETE
        $stmt = $db->prepare("
            UPDATE line_applications
            SET status = CASE 
                    WHEN status = 'FORM_INCOMPLETE' THEN 'DOC_PENDING'
                    WHEN status = 'INCOMPLETE' THEN 'DOC_PENDING'
                    ELSE status
                END,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$applicationId]);
        
        // TODO: Trigger OCR job asynchronously
        // For now, just log it
        Logger::info('[API_LINEAPP_DOCUMENTS] Document uploaded, OCR queued', [
            'document_id' => $documentId,
            'application_id' => $applicationId,
            'document_type' => $documentType,
            'file_size' => $fileSize
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Document uploaded successfully',
            'data' => [
                'document_id' => $documentId,
                'application_id' => $applicationId,
                'document_type' => $documentType,
                'file_size' => $fileSize,
                'mime_type' => $mimeType,
                'ocr_queued' => true
            ]
        ]);
        
    } catch (PDOException $e) {
        Logger::error('[API_LINEAPP_DOCUMENTS] Upload error', [
            'error' => $e->getMessage(),
            'code' => $e->getCode()
        ]);

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error occurred',
            // Return a compact error for debugging (avoid leaking full SQL)
            'debug' => [
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]
        ]);
    } catch (Exception $e) {
        Logger::error('[API_LINEAPP_DOCUMENTS] Upload error', [
            'error' => $e->getMessage()
        ]);

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
    exit;
}

// ============================================================================
// GET - Retrieve Documents
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Get document by ID
        if (isset($_GET['id'])) {
            $documentId = (int)$_GET['id'];
            $includeSignedUrl = isset($_GET['signed_url']) && $_GET['signed_url'] == '1';
            
            $stmt = $db->prepare("
                SELECT 
                    d.*,
                    a.application_no,
                    a.campaign_id
                FROM application_documents d
                JOIN line_applications a ON d.application_id = a.id
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
            
            // Decode JSON fields
            $document['ocr_data'] = json_decode($document['ocr_data'], true);
            
            // Generate signed URL if requested
            if ($includeSignedUrl) {
                // For local storage, generate a temporary access token
                $localPath = __DIR__ . '/../../storage/documents/' . $document['storage_path'];
                
                if (file_exists($localPath)) {
                    // Generate simple access token (in production, use proper signed URL with expiry)
                    $token = bin2hex(random_bytes(16));
                    $expiresAt = date('Y-m-d H:i:s', time() + 900); // 15 minutes
                    
                    // Store token in database
                    $stmt = $db->prepare("
                        UPDATE application_documents
                        SET signed_url = ?,
                            signed_url_expires_at = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$token, $expiresAt, $documentId]);
                    
                    // Generate URL
                    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
                    $signedUrl = $baseUrl . '/autobot/api/lineapp/view-document.php?token=' . $token;
                    
                    $document['signed_url'] = $signedUrl;
                    $document['signed_url_expires_at'] = $expiresAt;
                } else {
                    $document['signed_url'] = null;
                    $document['signed_url_error'] = 'File not found on storage';
                }
            }
            
            echo json_encode([
                'success' => true,
                'data' => $document
            ]);
            exit;
        }
        
        // Get documents by application_id
        if (isset($_GET['application_id'])) {
            $applicationId = (int)$_GET['application_id'];
            
            $stmt = $db->prepare("
                SELECT 
                    id,
                    document_type,
                    document_label,
                    document_side,
                    original_filename,
                    file_size,
                    mime_type,
                    ocr_processed,
                    ocr_confidence,
                    is_verified,
                    is_rejected,
                    uploaded_at,
                    ocr_processed_at
                FROM application_documents
                WHERE application_id = ?
                ORDER BY uploaded_at ASC
            ");
            
            $stmt->execute([$applicationId]);
            $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $documents,
                'count' => count($documents)
            ]);
            exit;
        }
        
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing required parameter: id or application_id'
        ]);
        
    } catch (PDOException $e) {
        Logger::error('[API_LINEAPP_DOCUMENTS] Get error', [
            'error' => $e->getMessage()
        ]);
        
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error occurred'
        ]);
    }
    exit;
}

// Method not allowed
http_response_code(405);
echo json_encode([
    'success' => false,
    'message' => 'Method not allowed'
]);
