<?php
/**
 * API Endpoint to cleanup old multimodal documents
 * Deletes documents that don't match current mock data ref_ids
 */

require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Logger.php';
require_once __DIR__ . '/../../includes/services/FirestoreVectorService.php';

use Autobot\Services\FirestoreVectorService;

header('Content-Type: application/json');

// Simple security check
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$expectedToken = getenv('ADMIN_API_KEY') ?: 'cleanup-multimodal-2026';

if (!str_contains($authHeader, $expectedToken) && ($_GET['key'] ?? '') !== $expectedToken) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

\Logger::info("[Cleanup] Starting multimodal collection cleanup");

// Valid ref_ids (must match mock data in api/v1/products/search.php)
$validRefIds = [
    'P-2026-000001', // Rolex Day-Date
    'P-2026-000002', // Rolex Submariner
    'P-2026-000003', // Tag Heuer Carrera
    'P-2026-000004', // Omega Seamaster
    'P-2026-000010', // แหวนเพชร
    'P-2026-000012', // แหวนทองคำ
];

// Old ref_ids to delete (from previous migration)
$toDelete = [
    'P-2026-000005',
    'P-2026-000006', 
    'P-2026-000007',
    'P-2026-000008',
];

// Get Firestore credentials
$serviceAccountJson = getenv('FIREBASE_SERVICE_ACCOUNT');
if (!$serviceAccountJson) {
    echo json_encode(['error' => 'Missing FIREBASE_SERVICE_ACCOUNT']);
    exit;
}

$serviceAccount = json_decode($serviceAccountJson, true);
$projectId = $serviceAccount['project_id'] ?? 'autobot-prod-v2';

// Get access token
$jwtHeader = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
$now = time();
$jwtClaims = base64_encode(json_encode([
    'iss' => $serviceAccount['client_email'],
    'sub' => $serviceAccount['client_email'],
    'aud' => 'https://oauth2.googleapis.com/token',
    'iat' => $now,
    'exp' => $now + 3600,
    'scope' => 'https://www.googleapis.com/auth/datastore'
]));

$privateKey = openssl_pkey_get_private($serviceAccount['private_key']);
openssl_sign(
    $jwtHeader . '.' . $jwtClaims,
    $signature,
    $privateKey,
    OPENSSL_ALGO_SHA256
);
$jwt = $jwtHeader . '.' . $jwtClaims . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

// Exchange JWT for access token
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt
    ]),
    CURLOPT_RETURNTRANSFER => true
]);
$tokenResponse = json_decode(curl_exec($ch), true);
curl_close($ch);

$accessToken = $tokenResponse['access_token'] ?? null;
if (!$accessToken) {
    echo json_encode(['error' => 'Failed to get access token']);
    exit;
}

$baseUrl = "https://firestore.googleapis.com/v1/projects/{$projectId}/databases/(default)/documents/products_vectors_multimodal";

$results = [];
$deleted = 0;
$failed = 0;

foreach ($toDelete as $refId) {
    $url = "{$baseUrl}/{$refId}";
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$accessToken}"
        ],
        CURLOPT_TIMEOUT => 10
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 || $httpCode === 204) {
        $deleted++;
        $results[] = ['ref_id' => $refId, 'status' => 'deleted'];
        \Logger::info("[Cleanup] Deleted document", ['ref_id' => $refId]);
    } elseif ($httpCode === 404) {
        $results[] = ['ref_id' => $refId, 'status' => 'not_found'];
    } else {
        $failed++;
        $results[] = ['ref_id' => $refId, 'status' => 'error', 'code' => $httpCode];
        \Logger::warning("[Cleanup] Failed to delete", ['ref_id' => $refId, 'code' => $httpCode]);
    }
}

echo json_encode([
    'ok' => true,
    'message' => 'Cleanup complete',
    'deleted' => $deleted,
    'failed' => $failed,
    'valid_ref_ids' => $validRefIds,
    'results' => $results
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
