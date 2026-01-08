<?php
/**
 * Admin Authentication Helper
 */

require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/JWT.php';

class AdminAuth {
    /**
     * Verify admin token and return admin data (returns null if invalid)
     * Use this when you want to handle auth failure yourself
     */
    public static function verify() {
        $token = self::getToken();
        
        if (!$token) {
            return null;
        }
        
        try {
            $decoded = JWT::verify($token);
            
            if (!$decoded) {
                return null;
            }
            
            // Check if it's an admin token
            if (!isset($decoded['type']) || $decoded['type'] !== 'admin') {
                return null;
            }
            
            if (!isset($decoded['admin_id'])) {
                return null;
            }
            
            // Return admin data with normalized id field
            return [
                'id' => $decoded['admin_id'],
                'admin_id' => $decoded['admin_id'],
                'role' => $decoded['role'] ?? null,
                'email' => $decoded['email'] ?? null,
                'tenant_id' => $decoded['tenant_id'] ?? 'default'
            ];
            
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Check if current user is an admin (exits on failure)
     */
    public static function require() {
        $token = self::getToken();
        
        if (!$token) {
            Response::error('Unauthorized - No token provided', 401);
        }
        
        try {
            // Use JWT::verify (the implemented method) instead of non-existent decode()
            $decoded = JWT::verify($token);
            
            if (!$decoded) {
                Response::error('Unauthorized - Invalid or expired token', 401);
            }
            
            // Check if it's an admin token
            if (!isset($decoded['type']) || $decoded['type'] !== 'admin') {
                Response::error('Unauthorized - Invalid token type', 401);
            }
            
            if (!isset($decoded['admin_id'])) {
                Response::error('Unauthorized - Invalid token', 401);
            }
            
            // Store admin data in global for access in API
            $GLOBALS['admin'] = $decoded;
            
        } catch (Exception $e) {
            Response::error('Unauthorized - ' . $e->getMessage(), 401);
        }
    }
    
    /**
     * Get current admin ID
     */
    public static function id() {
        return $GLOBALS['admin']['admin_id'] ?? null;
    }
    
    /**
     * Get current admin role
     */
    public static function role() {
        return $GLOBALS['admin']['role'] ?? null;
    }
    
    /**
     * Check if admin has specific role
     */
    public static function hasRole($role) {
        return self::role() === $role;
    }
    
    /**
     * Require specific role
     */
    public static function requireRole($role) {
        if (!self::hasRole($role)) {
            Response::error('Forbidden - Insufficient permissions', 403);
        }
    }
    
    /**
     * Get token from request header
     */
    private static function getToken() {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return $matches[1];
        }
        
        return null;
    }
}
