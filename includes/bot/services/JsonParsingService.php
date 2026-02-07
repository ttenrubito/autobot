<?php
/**
 * JsonParsingService
 * 
 * จัดการ JSON parsing, extraction, และ repair สำหรับ LLM responses
 * 
 * @package Autobot\Bot\Services
 * @version 1.0.0
 * @date 2026-02-05
 */

namespace Autobot\Bot\Services;

use Logger;

class JsonParsingService
{
    /**
     * Extract JSON from text (handles markdown, truncated, etc.)
     * 
     * @param string $text Text containing JSON
     * @return array|null Parsed array or null
     */
    public function extractJsonFromText(string $text): ?array
    {
        // Try direct parse first
        $parsed = json_decode($text, true);
        if (is_array($parsed)) {
            return $parsed;
        }

        // Try to extract from markdown code block - use greedy match for nested braces
        if (preg_match('/```(?:json)?\s*(\{[\s\S]+\})\s*```/i', $text, $matches)) {
            $parsed = json_decode($matches[1], true);
            if (is_array($parsed)) {
                return $parsed;
            }
        }

        // Try to find JSON object with greedy match
        if (preg_match('/(\{[\s\S]+\})/m', $text, $matches)) {
            $parsed = json_decode($matches[1], true);
            if (is_array($parsed)) {
                return $parsed;
            }
            
            // Try to find balanced braces manually if json_decode failed
            $jsonStr = $this->extractBalancedJson($text);
            if ($jsonStr) {
                $parsed = json_decode($jsonStr, true);
                if (is_array($parsed)) {
                    return $parsed;
                }
            }
        }
        
        // ✅ Fallback: Try to repair truncated JSON
        $repaired = $this->repairTruncatedJson($text);
        if ($repaired) {
            $parsed = json_decode($repaired, true);
            if (is_array($parsed)) {
                Logger::info('[JSON_PARSING] Repaired truncated JSON successfully');
                return $parsed;
            }
        }
        
        // ✅ Last resort: Extract key fields with regex
        $fallback = $this->extractFieldsWithRegex($text);
        if ($fallback) {
            Logger::info('[JSON_PARSING] Extracted fields with regex fallback');
            return $fallback;
        }

        return null;
    }
    
    /**
     * Try to repair truncated JSON by closing open braces/quotes
     * 
     * @param string $text Text containing truncated JSON
     * @return string|null Repaired JSON or null
     */
    public function repairTruncatedJson(string $text): ?string
    {
        // Find JSON start
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }
        
        $json = substr($text, $start);
        
        // Count open braces and brackets
        $braceCount = 0;
        $bracketCount = 0;
        $inString = false;
        $escape = false;
        
        for ($i = 0; $i < strlen($json); $i++) {
            $char = $json[$i];
            
            if ($escape) {
                $escape = false;
                continue;
            }
            if ($char === '\\') {
                $escape = true;
                continue;
            }
            if ($char === '"') {
                $inString = !$inString;
                continue;
            }
            if (!$inString) {
                if ($char === '{') $braceCount++;
                elseif ($char === '}') $braceCount--;
                elseif ($char === '[') $bracketCount++;
                elseif ($char === ']') $bracketCount--;
            }
        }
        
        // If still in string, close it
        if ($inString) {
            $json .= '"';
        }
        
        // Close any open brackets
        $json .= str_repeat(']', $bracketCount);
        
        // Close any open braces
        $json .= str_repeat('}', $braceCount);
        
        return $json;
    }
    
    /**
     * Extract key fields using regex as last resort
     * 
     * @param string $text Text to extract from
     * @return array|null Extracted fields or null
     */
    public function extractFieldsWithRegex(string $text): ?array
    {
        $result = [];
        
        // Extract image_type
        if (preg_match('/"image_type"\s*:\s*"(payment_proof|product_image|image_generic)"/i', $text, $m)) {
            $result['image_type'] = $m[1];
        } else {
            return null; // Must have image_type
        }
        
        // Extract confidence
        if (preg_match('/"confidence"\s*:\s*([\d.]+)/i', $text, $m)) {
            $result['confidence'] = (float)$m[1];
        } else {
            $result['confidence'] = 0.7; // Default confidence
        }
        
        // Extract brand
        if (preg_match('/"brand"\s*:\s*"([^"]+)"/i', $text, $m)) {
            $result['brand'] = $m[1];
        }
        
        // Extract category
        if (preg_match('/"category"\s*:\s*"([^"]+)"/i', $text, $m)) {
            $result['category'] = $m[1];
        }
        
        // Extract description
        if (preg_match('/"description"\s*:\s*"([^"]+)"/i', $text, $m)) {
            $result['description'] = $m[1];
        }
        
        return $result;
    }
    
    /**
     * Extract JSON with balanced braces from text
     * 
     * @param string $text Text containing JSON
     * @return string|null Extracted JSON string or null
     */
    public function extractBalancedJson(string $text): ?string
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }
        
        $depth = 0;
        $inString = false;
        $escape = false;
        $len = strlen($text);
        
        for ($i = $start; $i < $len; $i++) {
            $char = $text[$i];
            
            if ($escape) {
                $escape = false;
                continue;
            }
            
            if ($char === '\\') {
                $escape = true;
                continue;
            }
            
            if ($char === '"') {
                $inString = !$inString;
                continue;
            }
            
            if (!$inString) {
                if ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return substr($text, $start, $i - $start + 1);
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Extract a specific field from JSON text
     * 
     * @param string $text Text containing JSON
     * @param string $field Field name to extract
     * @param mixed $default Default value if not found
     * @return mixed Field value or default
     */
    public function extractField(string $text, string $field, $default = null)
    {
        $pattern = '/"' . preg_quote($field, '/') . '"\s*:\s*"([^"]+)"/i';
        if (preg_match($pattern, $text, $m)) {
            return $m[1];
        }
        
        // Try numeric value
        $pattern = '/"' . preg_quote($field, '/') . '"\s*:\s*([\d.]+)/i';
        if (preg_match($pattern, $text, $m)) {
            return is_numeric($m[1]) ? (strpos($m[1], '.') !== false ? (float)$m[1] : (int)$m[1]) : $m[1];
        }
        
        // Try boolean
        $pattern = '/"' . preg_quote($field, '/') . '"\s*:\s*(true|false)/i';
        if (preg_match($pattern, $text, $m)) {
            return strtolower($m[1]) === 'true';
        }
        
        return $default;
    }
    
    /**
     * Validate JSON structure has required fields
     * 
     * @param array $data JSON data
     * @param array $requiredFields Required field names
     * @return bool True if all required fields present
     */
    public function hasRequiredFields(array $data, array $requiredFields): bool
    {
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Safely get nested value from array
     * 
     * @param array $data Data array
     * @param string $path Dot-notation path (e.g., 'user.profile.name')
     * @param mixed $default Default value if not found
     * @return mixed Value or default
     */
    public function getNestedValue(array $data, string $path, $default = null)
    {
        $keys = explode('.', $path);
        $current = $data;
        
        foreach ($keys as $key) {
            if (!is_array($current) || !isset($current[$key])) {
                return $default;
            }
            $current = $current[$key];
        }
        
        return $current;
    }
}
