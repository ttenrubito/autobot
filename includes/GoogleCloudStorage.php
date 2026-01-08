<?php
/**
 * Google Cloud Storage Helper
 * 
 * Handles file uploads and downloads to/from Google Cloud Storage
 * with signed URL generation for secure access
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Logger.php';

use Google\Cloud\Storage\StorageClient;

class GoogleCloudStorage
{
    private static $instance = null;
    private $storage;
    private $bucket;
    private $bucketName;
    private $projectId;
    
    /**
     * Private constructor (Singleton pattern)
     */
    private function __construct()
    {
        // Load configuration from environment
        // Production Cloud Run is expected to run in project `autobot-prod-251215-22549`.
        // If env vars are not set, default to production to avoid cross-project bucket confusion.
        $this->projectId = getenv('GCP_PROJECT_ID') ?: 'autobot-prod-251215-22549';
        $this->bucketName = getenv('GCS_BUCKET_NAME') ?: 'autobot-documents';

        // Prefer Application Default Credentials on Cloud Run.
        // Fallback to explicit key file only for local/dev (or non-Cloud Run).
        $defaultKeyFilePath = __DIR__ . '/../config/gcp/service-account.json';
        $envKeyFilePath = getenv('GCS_KEY_FILE') ?: getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: '';
        $keyFilePath = $envKeyFilePath && is_string($envKeyFilePath) ? $envKeyFilePath : $defaultKeyFilePath;

        try {
            $isCloudRun = (bool)getenv('K_SERVICE');

            $clientOptions = [
                'projectId' => $this->projectId,
            ];

            // If not running on Cloud Run and key file exists, use it.
            // Cloud Run should use attached service account (ADC) to avoid stale/invalid key files.
            if (!$isCloudRun && $keyFilePath && file_exists($keyFilePath)) {
                $clientOptions['keyFilePath'] = $keyFilePath;
            }

            Logger::info('[GCS] Initializing StorageClient', [
                'is_cloud_run' => $isCloudRun,
                'project' => $this->projectId,
                'bucket' => $this->bucketName,
                'key_file' => (!$isCloudRun && $keyFilePath && file_exists($keyFilePath)) ? $keyFilePath : null,
            ]);

            // Initialize Storage Client
            $this->storage = new StorageClient($clientOptions);

            // Get bucket (create if not exists)
            $this->bucket = $this->storage->bucket($this->bucketName);

            // IMPORTANT:
            // - On Cloud Run, do NOT attempt to create buckets (needs storage.buckets.create).
            // - Require bucket to pre-exist and just use it.
            if (!$this->bucket->exists()) {
                if ($isCloudRun) {
                    throw new Exception("GCS bucket '{$this->bucketName}' not found or no access. Ensure bucket exists in project '{$this->projectId}' and grant Cloud Run service account storage permissions.");
                }

                Logger::warning('[GCS] Bucket does not exist, creating...', [
                    'bucket' => $this->bucketName
                ]);

                $this->bucket = $this->storage->createBucket($this->bucketName, [
                    'location' => 'ASIA-SOUTHEAST1',
                    'storageClass' => 'STANDARD'
                ]);

                Logger::info('[GCS] Bucket created successfully', [
                    'bucket' => $this->bucketName
                ]);
            }

            Logger::info('[GCS] Initialized successfully', [
                'project' => $this->projectId,
                'bucket' => $this->bucketName
            ]);

        } catch (Exception $e) {
            Logger::error('[GCS] Initialization failed', [
                'error' => $e->getMessage(),
                'project' => $this->projectId,
                'bucket' => $this->bucketName,
                'key_file' => ($keyFilePath && file_exists($keyFilePath)) ? $keyFilePath : null,
            ]);
            throw $e;
        }
    }
    
    /**
     * Get singleton instance
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Upload file to GCS
     * 
     * @param string $fileContent File content (binary or base64 decoded)
     * @param string $fileName Original filename
     * @param string $mimeType File MIME type
     * @param string $folder Folder path in bucket (e.g., 'documents', 'images')
     * @param array $metadata Additional metadata
     * @return array ['success' => bool, 'path' => string, 'url' => string, 'signed_url' => string]
     */
    public function uploadFile($fileContent, $fileName, $mimeType, $folder = 'documents', $metadata = [])
    {
        try {
            // Generate unique filename
            $fileExt = pathinfo($fileName, PATHINFO_EXTENSION);
            $uniqueFileName = uniqid('doc_') . '_' . time() . '.' . $fileExt;
            
            // Full path in bucket
            $objectPath = trim($folder, '/') . '/' . $uniqueFileName;

            // Normalize and prefix custom metadata keys for GCS
            $customMetadata = [];
            if (is_array($metadata)) {
                foreach ($metadata as $k => $v) {
                    if ($k === null || $k === '') {
                        continue;
                    }
                    // GCS custom metadata should be under metadata => metadata
                    $customMetadata[(string)$k] = is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE);
                }
            }
            
            // Upload to GCS
            $object = $this->bucket->upload($fileContent, [
                'name' => $objectPath,
                'metadata' => [
                    // Object-level contentType
                    'contentType' => $mimeType,
                    // Custom user metadata
                    'metadata' => array_merge([
                        'originalFileName' => $fileName,
                        'uploadedAt' => date('c'),
                    ], $customMetadata)
                ]
            ]);
            
            // Get public URL (if bucket is public)
            $publicUrl = sprintf(
                'https://storage.googleapis.com/%s/%s',
                $this->bucketName,
                $objectPath
            );
            
            // Generate signed URL (valid for 7 days)
            $signedUrl = $this->generateSignedUrl($objectPath, '+7 days');
            
            Logger::info('[GCS] File uploaded successfully', [
                'path' => $objectPath,
                'size' => strlen($fileContent),
                'mime_type' => $mimeType
            ]);
            
            return [
                'success' => true,
                'path' => $objectPath,
                'url' => $publicUrl,
                'signed_url' => $signedUrl,
                'bucket' => $this->bucketName
            ];
            
        } catch (Exception $e) {
            Logger::error('[GCS] Upload failed', [
                'error' => $e->getMessage(),
                'file_name' => $fileName
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Generate signed URL for secure access
     * 
     * @param string $objectPath Path to object in bucket
     * @param string $expiration Expiration time (e.g., '+1 hour', '+7 days')
     * @return string Signed URL
     */
    public function generateSignedUrl($objectPath, $expiration = '+1 hour')
    {
        try {
            $object = $this->bucket->object($objectPath);
            
            $signedUrl = $object->signedUrl(new \DateTime($expiration), [
                'version' => 'v4'
            ]);
            
            return $signedUrl;
            
        } catch (Exception $e) {
            Logger::error('[GCS] Signed URL generation failed', [
                'error' => $e->getMessage(),
                'path' => $objectPath
            ]);
            
            return '';
        }
    }
    
    /**
     * Download file from GCS
     * 
     * @param string $objectPath Path to object in bucket
     * @return array ['success' => bool, 'content' => string, 'metadata' => array]
     */
    public function downloadFile($objectPath)
    {
        try {
            $object = $this->bucket->object($objectPath);
            
            if (!$object->exists()) {
                throw new Exception('File not found');
            }
            
            $content = $object->downloadAsString();
            $info = $object->info();
            
            return [
                'success' => true,
                'content' => $content,
                'metadata' => $info['metadata'] ?? [],
                'mime_type' => $info['contentType'] ?? 'application/octet-stream'
            ];
            
        } catch (Exception $e) {
            Logger::error('[GCS] Download failed', [
                'error' => $e->getMessage(),
                'path' => $objectPath
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Delete file from GCS
     * 
     * @param string $objectPath Path to object in bucket
     * @return bool Success
     */
    public function deleteFile($objectPath)
    {
        try {
            $object = $this->bucket->object($objectPath);
            
            if ($object->exists()) {
                $object->delete();
                
                Logger::info('[GCS] File deleted', [
                    'path' => $objectPath
                ]);
                
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            Logger::error('[GCS] Delete failed', [
                'error' => $e->getMessage(),
                'path' => $objectPath
            ]);
            
            return false;
        }
    }
    
    /**
     * Check if file exists
     * 
     * @param string $objectPath Path to object in bucket
     * @return bool Exists
     */
    public function fileExists($objectPath)
    {
        try {
            $object = $this->bucket->object($objectPath);
            return $object->exists();
        } catch (Exception $e) {
            return false;
        }
    }
}
