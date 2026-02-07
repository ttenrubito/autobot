<?php
/**
 * FirestoreVectorService - Firebase Firestore Vector Search
 * 
 * Handles:
 * - Storing product embeddings to Firestore
 * - Vector similarity search (KNN)
 * - Embedding generation via Vertex AI
 * 
 * Prerequisites:
 * 1. Firebase project with Firestore enabled
 * 2. Service account JSON key in config/firebase-service-account.json
 * 3. Vertex AI API enabled for embeddings
 * 
 * @version 1.0
 * @date 2026-01-25
 */

namespace Autobot\Services;

require_once __DIR__ . '/../Logger.php';

class FirestoreVectorService
{
    protected $projectId;
    protected $accessToken;
    protected $firestoreBaseUrl;
    protected $similarityThreshold;
    
    // Collection name for product vectors
    const COLLECTION_NAME = 'products_vectors';
    
    // Embedding model (Vertex AI)
    const EMBEDDING_MODEL = 'text-embedding-004';
    const EMBEDDING_DIMENSION = 768;
    
    // Default similarity threshold (can be overridden in constructor)
    // Note: For multimodal (image-to-image), use lower threshold (~0.5)
    // because image embeddings from different sources have lower similarity
    const DEFAULT_SIMILARITY_THRESHOLD = 0.50;

    public function __construct(float $similarityThreshold = null)
    {
        $this->projectId = $this->getProjectId();
        $this->firestoreBaseUrl = "https://firestore.googleapis.com/v1/projects/{$this->projectId}/databases/(default)/documents";
        $this->similarityThreshold = $similarityThreshold ?? self::DEFAULT_SIMILARITY_THRESHOLD;
    }
    
    /**
     * Set similarity threshold for vector search
     */
    public function setSimilarityThreshold(float $threshold): void
    {
        $this->similarityThreshold = max(0.0, min(1.0, $threshold));
    }

    // ==================== CONFIGURATION ====================

    /**
     * Get service account data from file or environment variable
     */
    protected function getServiceAccount(): ?array
    {
        // Priority 1: Environment variable (Cloud Run secret mount)
        $envJson = getenv('FIREBASE_SERVICE_ACCOUNT');
        if ($envJson && $envJson !== 'false') {
            $data = json_decode($envJson, true);
            if ($data && isset($data['project_id'])) {
                return $data;
            }
        }

        // Priority 2: Local file
        $serviceAccountPath = __DIR__ . '/../../config/firebase-service-account.json';
        if (file_exists($serviceAccountPath)) {
            $data = json_decode(file_get_contents($serviceAccountPath), true);
            if ($data && isset($data['project_id'])) {
                return $data;
            }
        }

        return null;
    }

    /**
     * Get Firebase project ID from service account
     */
    protected function getProjectId(): string
    {
        $serviceAccount = $this->getServiceAccount();
        if ($serviceAccount) {
            return $serviceAccount['project_id'];
        }
        
        \Logger::warning('[FirestoreVector] Service account not found, using fallback');
        return getenv('FIREBASE_PROJECT_ID') ?: 'autobot-vector-search';
    }

    /**
     * Get OAuth2 access token from service account
     */
    protected function getAccessToken(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $serviceAccount = $this->getServiceAccount();
        
        if (!$serviceAccount) {
            throw new \Exception("Firebase service account not found (check env or config file)");
        }
        
        // Create JWT
        $now = time();
        $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = base64_encode(json_encode([
            'iss' => $serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/datastore https://www.googleapis.com/auth/cloud-platform',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600
        ]));
        
        $signature = '';
        openssl_sign("{$header}.{$payload}", $signature, $serviceAccount['private_key'], OPENSSL_ALGO_SHA256);
        $jwt = "{$header}.{$payload}." . base64_encode($signature);
        
        // Exchange JWT for access token
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt
            ])
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new \Exception("Failed to get Firebase access token: HTTP {$httpCode}");
        }
        
        $data = json_decode($response, true);
        $this->accessToken = $data['access_token'] ?? '';
        
        return $this->accessToken;
    }

    // ==================== EMBEDDING GENERATION ====================

    /**
     * Generate text embedding using Vertex AI
     * 
     * @param string $text Text to embed
     * @return array Vector embedding (768 dimensions)
     */
    public function generateEmbedding(string $text): array
    {
        $text = trim($text);
        if (empty($text)) {
            return [];
        }

        $accessToken = $this->getAccessToken();
        $region = 'asia-southeast1';
        $endpoint = "https://{$region}-aiplatform.googleapis.com/v1/projects/{$this->projectId}/locations/{$region}/publishers/google/models/" . self::EMBEDDING_MODEL . ":predict";

        $payload = [
            'instances' => [
                ['content' => $text]
            ],
            'parameters' => [
                'outputDimensionality' => self::EMBEDDING_DIMENSION
            ]
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer {$accessToken}"
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || $httpCode !== 200) {
            \Logger::error('[FirestoreVector] Embedding generation failed', [
                'http_code' => $httpCode,
                'error' => $curlError,
                'response' => substr($response, 0, 500)
            ]);
            return [];
        }

        $data = json_decode($response, true);
        $embedding = $data['predictions'][0]['embeddings']['values'] ?? [];

        \Logger::info('[FirestoreVector] Embedding generated', [
            'text_length' => strlen($text),
            'embedding_size' => count($embedding)
        ]);

        return $embedding;
    }

    /**
     * Generate image embedding using Vertex AI Multimodal Embedding
     * 
     * Uses multimodalembedding@001 model to create embeddings directly from images
     * This is more accurate than text description because it embeds the actual visual features
     * 
     * @param string $imageUrl URL of the image to embed
     * @return array Vector embedding (1408 dimensions for multimodal, or empty on failure)
     */
    public function generateImageEmbedding(string $imageUrl): array
    {
        \Logger::info('[FirestoreVector] Generating image embedding', ['url' => substr($imageUrl, 0, 100)]);
        
        try {
            // Download image and convert to base64
            $imageData = $this->downloadImage($imageUrl);
            if (empty($imageData)) {
                \Logger::error('[FirestoreVector] Failed to download image');
                return [];
            }
            
            $base64Image = base64_encode($imageData);
            $mimeType = $this->detectMimeType($imageData);
            
            $accessToken = $this->getAccessToken();
            $region = 'asia-southeast1';
            
            // Use multimodalembedding model
            $endpoint = "https://{$region}-aiplatform.googleapis.com/v1/projects/{$this->projectId}/locations/{$region}/publishers/google/models/multimodalembedding@001:predict";
            
            $payload = [
                'instances' => [
                    [
                        'image' => [
                            'bytesBase64Encoded' => $base64Image
                        ]
                    ]
                ]
            ];
            
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    "Authorization: Bearer {$accessToken}"
                ],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_TIMEOUT => 30
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError || $httpCode !== 200) {
                \Logger::error('[FirestoreVector] Multimodal embedding failed', [
                    'http_code' => $httpCode,
                    'error' => $curlError,
                    'response' => substr($response, 0, 500)
                ]);
                return [];
            }
            
            $data = json_decode($response, true);
            $embedding = $data['predictions'][0]['imageEmbedding'] ?? [];
            
            \Logger::info('[FirestoreVector] Image embedding generated', [
                'embedding_size' => count($embedding)
            ]);
            
            return $embedding;
            
        } catch (\Exception $e) {
            \Logger::error('[FirestoreVector] Image embedding exception', ['error' => $e->getMessage()]);
            return [];
        }
    }
    
    /**
     * Download image from URL
     */
    protected function downloadImage(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: image/webp,image/apng,image/*,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9'
            ]
        ]);
        
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode !== 200 || empty($data)) {
            \Logger::warning('[FirestoreVector] Image download failed', [
                'url' => substr($url, 0, 100),
                'http_code' => $httpCode,
                'curl_error' => $curlError,
                'data_length' => strlen($data ?? '')
            ]);
            return '';
        }
        
        \Logger::info('[FirestoreVector] Image downloaded', [
            'url' => substr($url, 0, 60),
            'size' => strlen($data)
        ]);
        
        return $data;
    }
    
    /**
     * Detect MIME type from image data
     */
    protected function detectMimeType(string $data): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        return $finfo->buffer($data) ?: 'image/jpeg';
    }

    /**
     * Search products using image embedding (Multimodal)
     * 
     * This method:
     * 1. Creates an image embedding using multimodal model
     * 2. Searches against text embeddings in Firestore (cross-modal search)
     * 
     * Note: For best results, product embeddings should also be multimodal
     * but text-to-image cross-modal search still works reasonably well
     * 
     * @param string $imageUrl URL of the image
     * @param int $limit Max results
     * @return array Search results with product IDs and scores
     */
    public function searchByImage(string $imageUrl, int $limit = 5): array
    {
        \Logger::info('[FirestoreVector] Image search started', ['url' => substr($imageUrl, 0, 100)]);
        
        // Generate image embedding using multimodal model
        $imageEmbedding = $this->generateImageEmbedding($imageUrl);
        
        if (empty($imageEmbedding)) {
            \Logger::warning('[FirestoreVector] Failed to generate image embedding, will fallback to text');
            return ['ok' => false, 'error' => 'Image embedding failed', 'product_ids' => []];
        }
        
        // Note: multimodalembedding produces 1408-dim vectors
        // If products are stored with text-embedding-004 (768-dim), we need separate collection
        // or we need to use multimodal for both products and queries
        
        // For now, search in a separate multimodal collection if it exists
        // Otherwise return error to fallback to text description
        
        return $this->searchWithEmbedding($imageEmbedding, $limit, 'products_vectors_multimodal');
    }
    
    /**
     * Search with a pre-computed embedding vector
     * 
     * @param array $embedding The embedding vector
     * @param int $limit Max results  
     * @param string $collection Collection to search in
     * @return array Search results
     */
    public function searchWithEmbedding(array $embedding, int $limit = 5, string $collection = null): array
    {
        $collectionName = $collection ?? self::COLLECTION_NAME;
        
        \Logger::info('[FirestoreVector] Embedding search started', [
            'collection' => $collectionName,
            'embedding_size' => count($embedding)
        ]);
        
        $accessToken = $this->getAccessToken();
        
        $structuredQuery = [
            'structuredQuery' => [
                'from' => [['collectionId' => $collectionName]],
                'findNearest' => [
                    'vectorField' => ['fieldPath' => 'embedding'],
                    'queryVector' => [
                        'mapValue' => [
                            'fields' => [
                                '__type__' => ['stringValue' => '__vector__'],
                                'value' => [
                                    'arrayValue' => [
                                        'values' => array_map(fn($v) => ['doubleValue' => (float)$v], $embedding)
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'limit' => $limit,
                    'distanceMeasure' => 'COSINE',
                    'distanceResultField' => 'vector_distance'
                ]
            ]
        ];
        
        $url = "https://firestore.googleapis.com/v1/projects/{$this->projectId}/databases/(default)/documents:runQuery";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer {$accessToken}"
            ],
            CURLOPT_POSTFIELDS => json_encode($structuredQuery),
            CURLOPT_TIMEOUT => 15
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            \Logger::error('[FirestoreVector] Embedding search failed', [
                'http_code' => $httpCode,
                'response' => substr($response, 0, 500)
            ]);
            return ['ok' => false, 'error' => "Search failed: HTTP {$httpCode}", 'product_ids' => []];
        }
        
        $results = json_decode($response, true);
        $productIds = [];
        $scores = [];
        $allScores = []; // Debug: track all scores
        
        foreach ($results as $result) {
            if (!isset($result['document'])) continue;
            
            $fields = $result['document']['fields'] ?? [];
            $refId = $fields['ref_id']['stringValue'] ?? null;
            $name = $fields['product_name']['stringValue'] ?? $fields['name']['stringValue'] ?? '';
            
            // Get distance from the document fields
            $distance = null;
            if (isset($fields['vector_distance']['doubleValue'])) {
                $distance = (float)$fields['vector_distance']['doubleValue'];
            }
            
            // COSINE distance: 0 = identical, 2 = opposite
            // Similarity = 1 - distance
            $similarity = $distance !== null ? (1 - $distance) : 0;
            
            // Track all results for debugging
            $allScores[] = [
                'ref_id' => $refId,
                'name' => substr($name, 0, 40),
                'distance' => round($distance ?? 0, 4),
                'similarity' => round($similarity, 4)
            ];
            
            if ($refId && $similarity >= $this->similarityThreshold) {
                $productIds[] = $refId;
                $scores[$refId] = round($similarity, 4);
                
                \Logger::info('[FirestoreVector] Image search match', [
                    'ref_id' => $refId,
                    'name' => substr($name, 0, 50),
                    'similarity' => round($similarity * 100, 1) . '%'
                ]);
            }
        }
        
        // Log all results for debugging
        \Logger::info('[FirestoreVector] Embedding search completed', [
            'collection' => $collectionName,
            'threshold' => $this->similarityThreshold,
            'all_results_count' => count($allScores),
            'all_scores' => $allScores,
            'filtered_count' => count($productIds)
        ]);
        
        return [
            'ok' => true,
            'product_ids' => $productIds,
            'scores' => $scores,
            'source' => 'image_embedding_search'
        ];
    }

    // ==================== FIRESTORE OPERATIONS ====================

    /**
     * Store product embedding in Firestore
     * 
     * @param array $product Product data with ref_id, name, description, etc.
     * @return bool Success
     */
    public function storeProductEmbedding(array $product): bool
    {
        $refId = $product['ref_id'] ?? null;
        if (!$refId) {
            return false;
        }

        // Build text for embedding
        $textForEmbedding = implode(' ', array_filter([
            $product['product_name'] ?? $product['name'] ?? '',
            $product['brand'] ?? '',
            $product['category'] ?? '',
            $product['description'] ?? ''
        ]));

        // Generate embedding
        $embedding = $this->generateEmbedding($textForEmbedding);
        if (empty($embedding)) {
            return false;
        }

        // Prepare Firestore document
        // Note: embedding must be stored as mapValue with __type__: __vector__ 
        // to be queryable via find_nearest
        $document = [
            'fields' => [
                'ref_id' => ['stringValue' => $refId],
                'product_code' => ['stringValue' => $product['product_code'] ?? $product['code'] ?? ''],
                'product_name' => ['stringValue' => $product['product_name'] ?? $product['name'] ?? ''],
                'brand' => ['stringValue' => $product['brand'] ?? ''],
                'category' => ['stringValue' => $product['category'] ?? ''],
                'description' => ['stringValue' => $product['description'] ?? ''],
                'embedding' => [
                    'mapValue' => [
                        'fields' => [
                            '__type__' => ['stringValue' => '__vector__'],
                            'value' => [
                                'arrayValue' => [
                                    'values' => array_map(fn($v) => ['doubleValue' => (float)$v], $embedding)
                                ]
                            ]
                        ]
                    ]
                ],
                'updated_at' => ['timestampValue' => date('c')]
            ]
        ];

        $accessToken = $this->getAccessToken();
        $url = "{$this->firestoreBaseUrl}/" . self::COLLECTION_NAME . "/{$refId}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer {$accessToken}"
            ],
            CURLOPT_POSTFIELDS => json_encode($document),
            CURLOPT_TIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $success = $httpCode === 200;
        
        \Logger::info('[FirestoreVector] Product embedding stored', [
            'ref_id' => $refId,
            'success' => $success,
            'http_code' => $httpCode
        ]);

        return $success;
    }

    /**
     * Store product with MULTIMODAL embedding (using product image)
     * This enables image-to-image search for better accuracy
     * 
     * @param array $product Product data with ref_id, name, image_url, etc.
     * @return bool Success
     */
    public function storeProductMultimodalEmbedding(array $product): bool
    {
        $refId = $product['ref_id'] ?? null;
        $imageUrl = $product['image_url'] ?? $product['primary_image'] ?? null;
        
        if (!$refId) {
            \Logger::warning('[FirestoreVector] Missing ref_id for multimodal embedding');
            return false;
        }
        
        if (!$imageUrl) {
            \Logger::warning('[FirestoreVector] Missing image_url for multimodal embedding', ['ref_id' => $refId]);
            return false;
        }
        
        \Logger::info('[FirestoreVector] Generating multimodal embedding for product', [
            'ref_id' => $refId,
            'image_url' => substr($imageUrl, 0, 100)
        ]);
        
        // Generate multimodal embedding from product image
        $embedding = $this->generateImageEmbedding($imageUrl);
        if (empty($embedding)) {
            \Logger::error('[FirestoreVector] Failed to generate multimodal embedding', ['ref_id' => $refId]);
            return false;
        }
        
        // Store in separate multimodal collection
        $collectionName = 'products_vectors_multimodal';
        
        $document = [
            'fields' => [
                'ref_id' => ['stringValue' => $refId],
                'product_code' => ['stringValue' => $product['product_code'] ?? $product['code'] ?? ''],
                'product_name' => ['stringValue' => $product['product_name'] ?? $product['name'] ?? ''],
                'brand' => ['stringValue' => $product['brand'] ?? ''],
                'category' => ['stringValue' => $product['category'] ?? ''],
                'image_url' => ['stringValue' => $imageUrl],
                'embedding' => [
                    'mapValue' => [
                        'fields' => [
                            '__type__' => ['stringValue' => '__vector__'],
                            'value' => [
                                'arrayValue' => [
                                    'values' => array_map(fn($v) => ['doubleValue' => (float)$v], $embedding)
                                ]
                            ]
                        ]
                    ]
                ],
                'embedding_type' => ['stringValue' => 'multimodal'],
                'embedding_dimension' => ['integerValue' => count($embedding)],
                'updated_at' => ['timestampValue' => date('c')]
            ]
        ];
        
        $accessToken = $this->getAccessToken();
        $url = "{$this->firestoreBaseUrl}/{$collectionName}/{$refId}";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer {$accessToken}"
            ],
            CURLOPT_POSTFIELDS => json_encode($document),
            CURLOPT_TIMEOUT => 15
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $success = $httpCode === 200;
        
        \Logger::info('[FirestoreVector] Product multimodal embedding stored', [
            'ref_id' => $refId,
            'success' => $success,
            'http_code' => $httpCode,
            'embedding_size' => count($embedding)
        ]);
        
        return $success;
    }

    /**
     * Batch store multiple product embeddings
     * 
     * @param array $products Array of product data
     * @return array ['success' => int, 'failed' => int]
     */
    public function batchStoreEmbeddings(array $products): array
    {
        $success = 0;
        $failed = 0;

        foreach ($products as $product) {
            if ($this->storeProductEmbedding($product)) {
                $success++;
            } else {
                $failed++;
            }
            
            // Rate limiting - avoid quota issues
            usleep(100000); // 100ms delay between requests
        }

        return ['success' => $success, 'failed' => $failed];
    }

    // ==================== VECTOR SEARCH ====================

    /**
     * Search for similar products using vector similarity
     * 
     * NOTE: Firestore Vector Search requires a specific query format.
     * Using REST API with findNearest query.
     * 
     * @param string $query Search query text
     * @param int $limit Max results
     * @return array ['ok' => bool, 'product_ids' => array, 'scores' => array]
     */
    public function searchSimilar(string $query, int $limit = 5): array
    {
        \Logger::info('[FirestoreVector] Vector search started', ['query' => substr($query, 0, 100)]);

        // Generate embedding for query
        $queryEmbedding = $this->generateEmbedding($query);
        if (empty($queryEmbedding)) {
            return ['ok' => false, 'error' => 'Failed to generate query embedding', 'product_ids' => []];
        }

        // Firestore Vector Search (requires vector index)
        // Using REST API with findNearest query
        $accessToken = $this->getAccessToken();
        
        // Build structured query with vector search
        // Note: REST API uses camelCase for field names
        // queryVector format: Firestore Value object with vector data
        $structuredQuery = [
            'structuredQuery' => [
                'from' => [['collectionId' => self::COLLECTION_NAME]],
                'findNearest' => [
                    'vectorField' => ['fieldPath' => 'embedding'],
                    'queryVector' => [
                        'mapValue' => [
                            'fields' => [
                                '__type__' => ['stringValue' => '__vector__'],
                                'value' => [
                                    'arrayValue' => [
                                        'values' => array_map(fn($v) => ['doubleValue' => (float)$v], $queryEmbedding)
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'limit' => $limit,
                    'distanceMeasure' => 'COSINE',
                    'distanceResultField' => 'vector_distance'
                ]
            ]
        ];

        $url = "https://firestore.googleapis.com/v1/projects/{$this->projectId}/databases/(default)/documents:runQuery";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer {$accessToken}"
            ],
            CURLOPT_POSTFIELDS => json_encode($structuredQuery),
            CURLOPT_TIMEOUT => 15
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            \Logger::error('[FirestoreVector] Vector search failed', [
                'http_code' => $httpCode,
                'response' => substr($response, 0, 500)
            ]);
            return ['ok' => false, 'error' => "Search failed: HTTP {$httpCode}", 'product_ids' => []];
        }

        // Parse results
        $results = json_decode($response, true);
        
        // Debug: log first result structure including document fields
        if (!empty($results[0])) {
            $fieldKeys = array_keys($results[0]['document']['fields'] ?? []);
            \Logger::info('[FirestoreVector] RAW API first result keys', [
                'result_keys' => array_keys($results[0]),
                'document_field_keys' => $fieldKeys,
                'has_vector_distance' => in_array('vector_distance', $fieldKeys),
                'first_result_sample' => json_encode(array_diff_key($results[0], ['document' => 1]))
            ]);
        }
        
        $productIds = [];
        $scores = [];
        $allScores = []; // For debugging

        try {
        foreach ($results as $idx => $result) {
            if (!isset($result['document'])) continue;

            $fields = $result['document']['fields'] ?? [];
            $refId = $fields['ref_id']['stringValue'] ?? null;
            $productName = $fields['product_name']['stringValue'] ?? 'unknown';
            
            // Distance is stored in the document fields as 'vector_distance' (as requested in query)
            // Try both doubleValue and integerValue formats
            $distanceField = $fields['vector_distance'] ?? null;
            $distance = null;
            if ($distanceField !== null) {
                $distance = $distanceField['doubleValue'] ?? $distanceField['integerValue'] ?? null;
            }
            
            // Convert distance to similarity (1 - cosine_distance for COSINE)
            // Note: distance can be null if not returned by API
            $similarity = ($distance !== null) ? (1 - (float)$distance) : 0;
            
            // Log all results for debugging
            $allScores[] = [
                'ref_id' => $refId,
                'name' => substr($productName, 0, 30),
                'distance_field' => json_encode($distanceField),
                'distance' => $distance,
                'similarity' => round($similarity, 4)
            ];

            if ($refId && $similarity >= $this->similarityThreshold) {
                $productIds[] = $refId;
                $scores[$refId] = $similarity;
            }
        }
        } catch (\Exception $e) {
            \Logger::error('[FirestoreVector] Error in parsing loop', ['error' => $e->getMessage()]);
        }

        \Logger::info('[FirestoreVector] Vector search completed', [
            'query' => substr($query, 0, 50),
            'threshold' => $this->similarityThreshold,
            'all_results_count' => count($allScores),
            'all_results' => array_slice($allScores, 0, 3),  // Only first 3 for brevity
            'filtered_count' => count($productIds)
        ]);

        return [
            'ok' => true,
            'product_ids' => $productIds,
            'scores' => $scores,
            'source' => 'firestore_vector_search'
        ];
    }

    // ==================== HEALTH CHECK ====================

    /**
     * Check if Firestore is accessible
     */
    public function healthCheck(): array
    {
        try {
            $accessToken = $this->getAccessToken();
            
            // Try to list documents (limit 1)
            $url = "{$this->firestoreBaseUrl}/" . self::COLLECTION_NAME . "?pageSize=1";
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer {$accessToken}"
                ],
                CURLOPT_TIMEOUT => 5
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return [
                'ok' => $httpCode === 200,
                'project_id' => $this->projectId,
                'collection' => self::COLLECTION_NAME,
                'http_code' => $httpCode
            ];
        } catch (\Exception $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
