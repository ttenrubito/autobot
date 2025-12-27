<?php
/**
 * Backward-compatible auth helpers for legacy endpoints.
 *
 * Some newer API endpoints under /api/customer/* were written to include
 * `includes/auth.php` and call verifyToken(). The codebase's primary auth
 * implementation is `includes/Auth.php`.
 *
 * This shim provides verifyToken() by delegating to Auth::checkJWT().
 */

require_once __DIR__ . '/Auth.php';

if (!function_exists('verifyToken')) {
    /**
     * Verify JWT bearer token.
     * @return array{valid:bool,user_id?:int|string,payload?:array}
     */
    function verifyToken() {
        try {
            $payload = Auth::checkJWT();
            if (!$payload || !is_array($payload)) {
                return ['valid' => false];
            }

            // Common payload keys observed in this project
            $userId = $payload['user_id'] ?? ($payload['sub'] ?? ($payload['id'] ?? null));

            if ($userId === null || $userId === '') {
                return ['valid' => false, 'payload' => $payload];
            }

            return ['valid' => true, 'user_id' => $userId, 'payload' => $payload];
        } catch (Throwable $e) {
            return ['valid' => false, 'error' => $e->getMessage()];
        }
    }
}
