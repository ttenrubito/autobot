<?php
/**
 * Knowledge Base Search API (For Chatbot Gateway)
 * Search customer knowledge base for answers
 */

define('INCLUDE_CHECK', true);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/Database.php';

header('Content-Type: application/json');

$db = Database::getInstance()->getConnection();

try {
    // Get user_id and query from request
    $userId = $_GET['user_id'] ?? $_POST['user_id'] ?? null;
    $query = $_GET['query'] ?? $_POST['query'] ?? null;
    $category = $_GET['category'] ?? $_POST['category'] ?? null;
    
    if (!$userId || !$query) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'user_id and query are required'
        ]);
        exit;
    }
    
    // Search knowledge base
    $results = searchKnowledgeBase($db, $userId, $query, $category);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'results' => $results,
            'count' => count($results),
            'query' => $query
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Knowledge Search API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage()
    ]);
}

/**
 * Search knowledge base using multiple strategies
 */
function searchKnowledgeBase($db, $userId, $query, $category = null) {
    $results = [];
    
    // Strategy 1: Exact keyword match (highest priority)
    $exactMatches = searchByKeywords($db, $userId, $query, $category);
    foreach ($exactMatches as $match) {
        $match['match_score'] = 100;
        $match['match_type'] = 'exact_keyword';
        $results[] = $match;
    }
    
    // Strategy 2: FULLTEXT search in question/answer
    if (empty($results)) {
        $fulltextMatches = searchFulltext($db, $userId, $query, $category);
        foreach ($fulltextMatches as $match) {
            $match['match_score'] = 80;
            $match['match_type'] = 'fulltext';
            $results[] = $match;
        }
    }
    
    // Strategy 3: LIKE search (fallback)
    if (empty($results)) {
        $likeMatches = searchLike($db, $userId, $query, $category);
        foreach ($likeMatches as $match) {
            $match['match_score'] = 60;
            $match['match_type'] = 'partial';
            $results[] = $match;
        }
    }
    
    // Remove duplicates and limit results
    $results = array_slice($results, 0, 5);
    
    return $results;
}

/**
 * Search by exact keyword match in JSON keywords field
 */
function searchByKeywords($db, $userId, $query, $category) {
    $sql = "SELECT * FROM customer_knowledge_base 
            WHERE user_id = ? 
            AND is_active = 1 
            AND is_deleted = 0";
    
    $params = [$userId];
    $types = 'i';
    
    if ($category) {
        $sql .= " AND category = ?";
        $params[] = $category;
        $types .= 's';
    }
    
    $sql .= " ORDER BY priority DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $matches = [];
    $queryLower = mb_strtolower($query, 'UTF-8');
    
    while ($row = $result->fetch_assoc()) {
        $keywords = json_decode($row['keywords'] ?? '[]', true);
        
        foreach ($keywords as $keyword) {
            $keywordLower = mb_strtolower($keyword, 'UTF-8');
            
            // Check if query contains this keyword
            if (mb_strpos($queryLower, $keywordLower) !== false) {
                $row['keywords'] = $keywords;
                $row['metadata'] = json_decode($row['metadata'] ?? '{}', true);
                $row['matched_keyword'] = $keyword;
                $matches[] = $row;
                break; // Found match, move to next entry
            }
        }
    }
    
    return $matches;
}

/**
 * FULLTEXT search in question and answer
 */
function searchFulltext($db, $userId, $query, $category) {
    $sql = "SELECT *, 
            MATCH(question) AGAINST(? IN NATURAL LANGUAGE MODE) as q_score,
            MATCH(answer) AGAINST(? IN NATURAL LANGUAGE MODE) as a_score
            FROM customer_knowledge_base 
            WHERE user_id = ? 
            AND is_active = 1 
            AND is_deleted = 0
            AND (
                MATCH(question) AGAINST(? IN NATURAL LANGUAGE MODE) OR
                MATCH(answer) AGAINST(? IN NATURAL LANGUAGE MODE)
            )";
    
    $params = [$query, $query, $userId, $query, $query];
    $types = 'ssiss';
    
    if ($category) {
        $sql .= " AND category = ?";
        $params[] = $category;
        $types .= 's';
    }
    
    $sql .= " ORDER BY (q_score + a_score) DESC, priority DESC LIMIT 5";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $matches = [];
    while ($row = $result->fetch_assoc()) {
        $row['keywords'] = json_decode($row['keywords'] ?? '[]', true);
        $row['metadata'] = json_decode($row['metadata'] ?? '{}', true);
        $row['relevance_score'] = $row['q_score'] + $row['a_score'];
        unset($row['q_score'], $row['a_score']);
        $matches[] = $row;
    }
    
    return $matches;
}

/**
 * LIKE search (fallback)
 */
function searchLike($db, $userId, $query, $category) {
    $sql = "SELECT * FROM customer_knowledge_base 
            WHERE user_id = ? 
            AND is_active = 1 
            AND is_deleted = 0
            AND (question LIKE ? OR answer LIKE ?)";
    
    $searchTerm = "%$query%";
    $params = [$userId, $searchTerm, $searchTerm];
    $types = 'iss';
    
    if ($category) {
        $sql .= " AND category = ?";
        $params[] = $category;
        $types .= 's';
    }
    
    $sql .= " ORDER BY priority DESC LIMIT 5";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $matches = [];
    while ($row = $result->fetch_assoc()) {
        $row['keywords'] = json_decode($row['keywords'] ?? '[]', true);
        $row['metadata'] = json_decode($row['metadata'] ?? '{}', true);
        $matches[] = $row;
    }
    
    return $matches;
}
