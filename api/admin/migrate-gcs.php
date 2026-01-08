<?php
/**
 * Database Migration API
 * Run this ONCE to add GCS columns
 * 
 * URL: /api/admin/migrate-gcs.php
 * 
 * ⚠️ DANGEROUS - Only run if you know what you're doing!
 */

header('Content-Type: application/json; charset=utf-8');

// Simple security check
$secret = $_GET['secret'] ?? '';
if ($secret !== 'migrate-gcs-2026-01-04') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid secret. Use: ?secret=migrate-gcs-2026-01-04'
    ]);
    exit;
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Logger.php';

try {
    $db = Database::getInstance()->getPdo();
    $results = [];
    
    // Step 1: Add gcs_path column
    try {
        $db->exec("
            ALTER TABLE application_documents 
            ADD COLUMN IF NOT EXISTS gcs_path VARCHAR(500) COMMENT 'Path in GCS bucket'
        ");
        $results[] = '✅ Added gcs_path column';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            $results[] = '⏭️  gcs_path already exists';
        } else {
            throw $e;
        }
    }
    
    // Step 2: Add gcs_signed_url column
    try {
        $db->exec("
            ALTER TABLE application_documents 
            ADD COLUMN IF NOT EXISTS gcs_signed_url TEXT COMMENT 'GCS signed URL'
        ");
        $results[] = '✅ Added gcs_signed_url column';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            $results[] = '⏭️  gcs_signed_url already exists';
        } else {
            throw $e;
        }
    }
    
    // Step 3: Add gcs_signed_url_expires_at column
    try {
        $db->exec("
            ALTER TABLE application_documents 
            ADD COLUMN IF NOT EXISTS gcs_signed_url_expires_at DATETIME COMMENT 'URL expiration'
        ");
        $results[] = '✅ Added gcs_signed_url_expires_at column';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            $results[] = '⏭️  gcs_signed_url_expires_at already exists';
        } else {
            throw $e;
        }
    }
    
    // Step 4: Add index
    try {
        $db->exec("CREATE INDEX idx_gcs_path ON application_documents(gcs_path)");
        $results[] = '✅ Created index on gcs_path';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            $results[] = '⏭️  Index already exists';
        } else {
            throw $e;
        }
    }
    
    // Step 5: Update campaign config
    $stmt = $db->prepare("
        UPDATE campaigns
        SET required_documents = ?
        WHERE code = 'DEMO2026'
    ");
    
    $newConfig = json_encode([
        ['type' => 'id_card', 'label' => 'บัตรประชาชน', 'required' => true, 'accept' => 'image/*'],
        ['type' => 'house_registration', 'label' => 'ทะเบียนบ้าน', 'required' => false, 'accept' => 'image/*,application/pdf']
    ]);
    
    $stmt->execute([$newConfig]);
    $results[] = '✅ Updated DEMO2026 campaign config';
    
    // Step 6: Verify
    $stmt = $db->query("SHOW COLUMNS FROM application_documents LIKE 'gcs%'");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    Logger::info('[MIGRATION] GCS migration completed successfully', [
        'columns_added' => count($columns),
        'results' => $results
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Migration completed successfully!',
        'results' => $results,
        'columns_verified' => $columns,
        'next_steps' => [
            '1. Test LIFF form: https://liff.line.me/2008812786-PsaYJSep?campaign=DEMO2026',
            '2. Upload a test document',
            '3. Check admin panel to see if documents appear',
            '4. DELETE THIS FILE for security: api/admin/migrate-gcs.php'
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    Logger::error('[MIGRATION] Migration failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Migration failed: ' . $e->getMessage(),
        'results' => $results ?? []
    ], JSON_PRETTY_PRINT);
}
