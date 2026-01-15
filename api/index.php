<?php
/**
 * API Router
 * Central entry point for all API requests
 * SECURITY: CORS, Rate Limiting, Input Validation
 */

// Security Headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// CORS Configuration (adjust for production)
header('Access-Control-Allow-Origin: *'); // Change to specific domain in production
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-API-Key');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Load dependencies
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Response.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/JWT.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/AdminAuth.php';
require_once __DIR__ . '/../includes/Validator.php';
require_once __DIR__ . '/../includes/OmiseClient.php';

// Initialize session
Auth::initSession();

// Get request method and path
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Normalize path for both local (/autobot/api/*) and Cloud Run (/api/*)
$path = preg_replace('#^/autobot/api#', '', $path);  // Strip /autobot/api prefix (local)
$path = preg_replace('#^/api#', '', $path);          // Strip /api prefix (Cloud Run)
$path = preg_replace('#^/index\.php#', '', $path);   // Strip router script prefix when called as /api/index.php/...

// Remove trailing slash (except for root)
if ($path !== '/' && str_ends_with($path, '/')) {
    $path = rtrim($path, '/');
}

if ($path === '') {
    $path = '/';
}

// Parse JSON input for POST/PUT requests
$input = [];
if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?? [];

    // Also merge with $_POST for form data
    $input = array_merge($_POST, $input);
}

// Route handling
try {
    // Admin routes (public)
    if ($path === '/admin/login' && $method === 'POST') {
        require __DIR__ . '/admin/login.php';
    }

    // Admin Dashboard (protected)
    elseif ($path === '/admin/dashboard/stats' && $method === 'GET') {
        require __DIR__ . '/admin/dashboard/stats.php';
    }

    // Admin Billing (protected)
    elseif ($path === '/admin/billing/process' && $method === 'POST') {
        require __DIR__ . '/admin/billing/process.php';
    }

    // Authentication routes (public)
    elseif ($path === '/auth/login' && $method === 'POST') {
        require __DIR__ . '/auth/login.php';
    } elseif ($path === '/auth/logout' && $method === 'POST') {
        require __DIR__ . '/auth/logout.php';
    } elseif ($path === '/auth/forgot-password' && $method === 'POST') {
        require __DIR__ . '/auth/forgot-password.php';
    } elseif ($path === '/auth/me' && $method === 'GET') {
        require __DIR__ . '/auth/me.php';
    } elseif ($path === '/auth/change-password' && $method === 'POST') {
        require __DIR__ . '/auth/change-password.php';
    }

    // Dashboard routes (protected)
    elseif ($path === '/dashboard/stats' && $method === 'GET') {
        require __DIR__ . '/dashboard/stats.php';
    }

    // Services routes (protected)
    elseif ($path === '/services/list' && $method === 'GET') {
        require __DIR__ . '/services/list.php';
    } elseif (preg_match('#^/services/(\d+)$#', $path, $matches) && $method === 'GET') {
        $_GET['id'] = $matches[1];
        require __DIR__ . '/services/details.php';
    } elseif (preg_match('#^/services/(\d+)/usage$#', $path, $matches) && $method === 'GET') {
        $_GET['id'] = $matches[1];
        require __DIR__ . '/services/usage.php';
    }

    // Payment routes (protected)
    elseif ($path === '/payment/methods' && $method === 'GET') {
        require __DIR__ . '/payment/methods.php';
    } elseif ($path === '/payment/add-card' && $method === 'POST') {
        require __DIR__ . '/payment/add-card.php';
    } elseif (preg_match('#^/payment/(\d+)$#', $path, $matches) && $method === 'DELETE') {
        $_GET['id'] = $matches[1];
        require __DIR__ . '/payment/remove-card.php';
    } elseif (preg_match('#^/payment/(\d+)/set-default$#', $path, $matches) && $method === 'POST') {
        $_GET['id'] = $matches[1];
        require __DIR__ . '/payment/set-default.php';
    } elseif ($path === '/payment/create-charge' && $method === 'POST') {
        require __DIR__ . '/payment/create-charge.php';
    }

    // Test routes (no auth required - for testing only!)
    elseif ($path === '/payment/add-card-test' && $method === 'POST') {
        require __DIR__ . '/payment/add-card-test.php';
    } elseif ($path === '/payment/create-charge-test' && $method === 'POST') {
        require __DIR__ . '/payment/create-charge-test.php';
    }

    // Billing routes (protected)
    elseif ($path === '/billing/invoices' && $method === 'GET') {
        require __DIR__ . '/billing/invoices.php';
    } elseif ($path === '/billing/transactions' && $method === 'GET') {
        require __DIR__ . '/billing/transactions.php';
    } elseif (preg_match('#^/billing/invoices/(\d+)$#', $path, $matches) && $method === 'GET') {
        $_GET['id'] = $matches[1];
        require __DIR__ . '/billing/invoice-details.php';
    }

    // Webhooks (public)
    elseif ($path === '/webhooks/omise' && $method === 'POST') {
        require __DIR__ . '/webhooks/omise.php';
    }

    // Products routes (public - mock API for chatbot)
    elseif ($path === '/products/search' && $method === 'POST') {
        require __DIR__ . '/products/search.php';
    } elseif ($path === '/products/get' && $method === 'POST') {
        require __DIR__ . '/products/get.php';
    }

    // Orders routes (public - mock API for chatbot)
    elseif ($path === '/orders/status' && $method === 'POST') {
        require __DIR__ . '/orders/status.php';
    }

    // Installment routes (public - mock API for chatbot)
    elseif ($path === '/installment/calculate' && $method === 'POST') {
        require __DIR__ . '/installment/calculate.php';
    }

    // Admin Package Management routes
    elseif ($path === '/admin/packages/list' && $method === 'GET') {
        require __DIR__ . '/admin/packages/list.php';
    } elseif (preg_match('#^/admin/packages/get$#', $path) && $method === 'GET') {
        require __DIR__ . '/admin/packages/get.php';
    } elseif ($path === '/admin/packages/create' && $method === 'POST') {
        require __DIR__ . '/admin/packages/create.php';
    } elseif (preg_match('#^/admin/packages/update$#', $path) && $method === 'PUT') {
        require __DIR__ . '/admin/packages/update.php';
    } elseif (preg_match('#^/admin/packages/delete$#', $path) && $method === 'DELETE') {
        require __DIR__ . '/admin/packages/delete.php';
    }

    // Customer Portal routes (protected)
    elseif ($path === '/customer/conversations' && $method === 'GET') {
        require __DIR__ . '/customer/conversations.php';
    } elseif ($path === '/customer/addresses' && in_array($method, ['GET', 'POST', 'PUT', 'DELETE'])) {
        require __DIR__ . '/customer/addresses.php';
    } elseif (preg_match('#^/customer/addresses/(\d+)$#', $path, $matches) && in_array($method, ['GET', 'PUT', 'DELETE'])) {
        $_GET['id'] = $matches[1];
        require __DIR__ . '/customer/addresses.php';
    } elseif (preg_match('#^/customer/addresses/(\d+)/set-default$#', $path, $matches) && $method === 'PUT') {
        $_GET['id'] = $matches[1];
        $_GET['action'] = 'set_default';
        require __DIR__ . '/customer/addresses.php';
    } elseif ($path === '/customer/orders' && $method === 'GET') {
        require __DIR__ . '/customer/orders.php';
    } elseif (preg_match('#^/customer/orders/(\d+)$#', $path, $matches) && $method === 'GET') {
        $_GET['id'] = $matches[1];
        require __DIR__ . '/customer/orders.php';
    } elseif ($path === '/customer/payments' && $method === 'GET') {
        require __DIR__ . '/customer/payments.php';
    } elseif (preg_match('#^/customer/payments/(\d+)$#', $path, $matches) && $method === 'GET') {
        $_GET['id'] = $matches[1];
        require __DIR__ . '/customer/payments.php';
    } elseif (preg_match('#^/customer/payments/(\d+)/installments$#', $path, $matches) && $method === 'GET') {
        $_GET['id'] = $matches[1];
        $_GET['installments'] = true;
        require __DIR__ . '/customer/payments.php';
    } elseif ($path === '/customer/conversations' && $method === 'GET') {
        require __DIR__ . '/customer/conversations.php';
    } elseif (preg_match('#^/customer/conversations/(\d+)$#', $path, $matches) && $method === 'GET') {
        $_GET['id'] = $matches[1];
        require __DIR__ . '/customer/conversations.php';
    } elseif (preg_match('#^/customer/conversations/([^/]+)/messages$#', $path, $matches) && $method === 'GET') {
        $_GET['conversation_id'] = $matches[1];
        $_GET['messages'] = true;
        require __DIR__ . '/customer/conversations.php';
    }

    // Customer Savings routes
    elseif ($path === '/customer/savings' && $method === 'GET') {
        require __DIR__ . '/customer/savings.php';
    } elseif (preg_match('#^/customer/savings/([A-Za-z0-9\-]+)$#', $path, $matches) && $method === 'GET') {
        $_GET['id'] = $matches[1];
        require __DIR__ . '/customer/savings.php';
    } elseif (preg_match('#^/customer/savings/([A-Za-z0-9\-]+)/deposit$#', $path, $matches) && $method === 'POST') {
        $_GET['id'] = $matches[1];
        $_GET['action'] = 'deposit';
        require __DIR__ . '/customer/savings.php';
    }

    // Customer Installments routes
    elseif ($path === '/customer/installments' && $method === 'GET') {
        require __DIR__ . '/customer/installments.php';
    } elseif (preg_match('#^/customer/installments/([A-Za-z0-9\-]+)$#', $path, $matches) && $method === 'GET') {
        $_GET['id'] = $matches[1];
        require __DIR__ . '/customer/installments.php';
    } elseif (preg_match('#^/customer/installments/([A-Za-z0-9\-]+)/pay$#', $path, $matches) && $method === 'POST') {
        $_GET['id'] = $matches[1];
        $_GET['action'] = 'pay';
        require __DIR__ . '/customer/installments.php';
    }

    // Customer Cases routes
    elseif ($path === '/customer/cases' && in_array($method, ['GET', 'POST'])) {
        require __DIR__ . '/customer/cases.php';
    } elseif (preg_match('#^/customer/cases/(\d+)$#', $path, $matches) && $method === 'GET') {
        $_GET['id'] = $matches[1];
        require __DIR__ . '/customer/cases.php';
    }

    // Customer Deposits routes (มัดจำ)
    elseif ($path === '/customer/deposits' && in_array($method, ['GET', 'POST'])) {
        require __DIR__ . '/customer/deposits.php';
    } elseif (preg_match('#^/customer/deposits/(\d+)$#', $path, $matches) && $method === 'GET') {
        $_GET['id'] = $matches[1];
        require __DIR__ . '/customer/deposits.php';
    } elseif (preg_match('#^/customer/deposits/(\d+)/pay$#', $path, $matches) && $method === 'POST') {
        $_GET['id'] = $matches[1];
        $_GET['action'] = 'pay';
        require __DIR__ . '/customer/deposits.php';
    }

    // Customer Pawns routes (ฝากจำนำ)
    elseif ($path === '/customer/pawns' && in_array($method, ['GET', 'POST'])) {
        require __DIR__ . '/customer/pawns.php';
    } elseif (preg_match('#^/customer/pawns/(\d+)$#', $path, $matches) && $method === 'GET') {
        $_GET['id'] = $matches[1];
        require __DIR__ . '/customer/pawns.php';
    } elseif (preg_match('#^/customer/pawns/(\d+)/pay-interest$#', $path, $matches) && $method === 'POST') {
        $_GET['id'] = $matches[1];
        $_GET['action'] = 'pay-interest';
        require __DIR__ . '/customer/pawns.php';
    }

    // Customer Repairs routes (งานซ่อม)
    elseif ($path === '/customer/repairs' && in_array($method, ['GET', 'POST'])) {
        require __DIR__ . '/customer/repairs.php';
    } elseif (preg_match('#^/customer/repairs/(\d+)$#', $path, $matches) && $method === 'GET') {
        $_GET['id'] = $matches[1];
        require __DIR__ . '/customer/repairs.php';
    } elseif (preg_match('#^/customer/repairs/(\d+)/approve$#', $path, $matches) && $method === 'POST') {
        $_GET['id'] = $matches[1];
        $_GET['action'] = 'approve';
        require __DIR__ . '/customer/repairs.php';
    } elseif (preg_match('#^/customer/repairs/(\d+)/pay$#', $path, $matches) && $method === 'POST') {
        $_GET['id'] = $matches[1];
        $_GET['action'] = 'pay';
        require __DIR__ . '/customer/repairs.php';
    }

    // Health aliases
    elseif (($path === '/health' || $path === '/health.php') && $method === 'GET') {
        require __DIR__ . '/health.php';
    }

    // Admin Payments routes (protected)
    elseif ($path === '/admin/payments' && $method === 'GET') {
        require __DIR__ . '/admin/payments.php';
    } elseif (preg_match('#^/admin/payments/(\d+)$#', $path, $matches) && $method === 'GET') {
        $_GET['id'] = $matches[1];
        require __DIR__ . '/admin/payments.php';
    } elseif (preg_match('#^/admin/payments/(\d+)/(approve|reject)$#', $path, $matches) && $method === 'PUT') {
        $_GET['id'] = $matches[1];
        $_GET['action'] = $matches[2];
        require __DIR__ . '/admin/payments.php';
    }

    // Gateway routes (API key protected)
    elseif (preg_match('#^/gateway/vision/(.+)$#', $path) && $method === 'POST') {
        require __DIR__ . '/gateway/vision.php';
    } elseif (preg_match('#^/gateway/language/(.+)$#', $path) && $method === 'POST') {
        require __DIR__ . '/gateway/language.php';
    } elseif ($path === '/gateway/message' && $method === 'POST') {
        require __DIR__ . '/gateway/message.php';
    } elseif ($path === '/gateway/knowledge-search' && $method === 'POST') {
        require __DIR__ . '/gateway/knowledge-search.php';
    }

    // =====================================================
    // Bot API routes (for chatbot integration)
    // =====================================================

    // Bot Cases API
    elseif ($path === '/bot/cases' && $method === 'POST') {
        require __DIR__ . '/bot/cases/index.php';
    } elseif (preg_match('#^/bot/cases/(\d+)$#', $path, $matches) && $method === 'GET') {
        $_GET['case_id'] = $matches[1];
        require __DIR__ . '/bot/cases/index.php';
    } elseif (preg_match('#^/bot/cases/(\d+)/update-slot$#', $path, $matches) && $method === 'POST') {
        $_GET['case_id'] = $matches[1];
        $_GET['action'] = 'update-slot';
        require __DIR__ . '/bot/cases/index.php';
    } elseif (preg_match('#^/bot/cases/(\d+)/status$#', $path, $matches) && $method === 'POST') {
        $_GET['case_id'] = $matches[1];
        $_GET['action'] = 'status';
        require __DIR__ . '/bot/cases/index.php';
    }

    // Bot Savings API
    elseif ($path === '/bot/savings' && $method === 'POST') {
        require __DIR__ . '/bot/savings/index.php';
    } elseif ($path === '/bot/savings/by-user' && $method === 'GET') {
        $_GET['action'] = 'by-user';
        require __DIR__ . '/bot/savings/index.php';
    } elseif (preg_match('#^/bot/savings/(\d+)$#', $path, $matches) && $method === 'GET') {
        $_GET['savings_id'] = $matches[1];
        require __DIR__ . '/bot/savings/index.php';
    } elseif (preg_match('#^/bot/savings/(\d+)/status$#', $path, $matches) && $method === 'GET') {
        $_GET['savings_id'] = $matches[1];
        $_GET['action'] = 'status';
        require __DIR__ . '/bot/savings/index.php';
    } elseif (preg_match('#^/bot/savings/(\d+)/deposit$#', $path, $matches) && $method === 'POST') {
        $_GET['savings_id'] = $matches[1];
        $_GET['action'] = 'deposit';
        require __DIR__ . '/bot/savings/index.php';
    }

    // Bot Payments API
    elseif ($path === '/bot/payments/submit' && $method === 'POST') {
        $_GET['action'] = 'submit';
        require __DIR__ . '/bot/payments/index.php';
    } elseif ($path === '/bot/payments/draft-order' && $method === 'POST') {
        $_GET['action'] = 'draft-order';
        require __DIR__ . '/bot/payments/index.php';
    } elseif ($path === '/bot/payments/by-user' && $method === 'GET') {
        $_GET['action'] = 'by-user';
        require __DIR__ . '/bot/payments/index.php';
    } elseif (preg_match('#^/bot/payments/(\d+)$#', $path, $matches) && $method === 'GET') {
        $_GET['payment_id'] = $matches[1];
        require __DIR__ . '/bot/payments/index.php';
    }

    // Product Search APIs
    elseif ($path === '/products/npd-search' && $method === 'POST') {
        require __DIR__ . '/products/npd-search.php';
    } elseif ($path === '/products/image-search' && $method === 'POST') {
        require __DIR__ . '/products/image-search.php';
    }

    // =====================================================
    // Bot Installments API
    // =====================================================
    elseif ($path === '/bot/installments' && $method === 'POST') {
        require __DIR__ . '/bot/installments/index.php';
    } elseif ($path === '/bot/installments/by-user' && $method === 'GET') {
        $_GET['action'] = 'by-user';
        require __DIR__ . '/bot/installments/index.php';
    } elseif (preg_match('#^/bot/installments/(\d+)$#', $path, $matches) && $method === 'GET') {
        $_GET['contract_id'] = $matches[1];
        require __DIR__ . '/bot/installments/index.php';
    } elseif (preg_match('#^/bot/installments/(\d+)/status$#', $path, $matches) && $method === 'GET') {
        $_GET['contract_id'] = $matches[1];
        $_GET['action'] = 'status';
        require __DIR__ . '/bot/installments/index.php';
    } elseif (preg_match('#^/bot/installments/(\d+)/pay$#', $path, $matches) && $method === 'POST') {
        $_GET['contract_id'] = $matches[1];
        $_GET['action'] = 'pay';
        require __DIR__ . '/bot/installments/index.php';
    } elseif (preg_match('#^/bot/installments/(\d+)/extend$#', $path, $matches) && $method === 'POST') {
        $_GET['contract_id'] = $matches[1];
        $_GET['action'] = 'extend';
        require __DIR__ . '/bot/installments/index.php';
    }

    // =====================================================
    // Admin Cases API
    // =====================================================
    elseif ($path === '/admin/cases' && $method === 'GET') {
        require __DIR__ . '/admin/cases/index.php';
    } elseif (preg_match('#^/admin/cases/(\d+)$#', $path, $matches) && $method === 'GET') {
        $_GET['case_id'] = $matches[1];
        require __DIR__ . '/admin/cases/index.php';
    } elseif (preg_match('#^/admin/cases/(\d+)/assign$#', $path, $matches) && $method === 'PUT') {
        $_GET['case_id'] = $matches[1];
        $_GET['action'] = 'assign';
        require __DIR__ . '/admin/cases/index.php';
    } elseif (preg_match('#^/admin/cases/(\d+)/resolve$#', $path, $matches) && $method === 'PUT') {
        $_GET['case_id'] = $matches[1];
        $_GET['action'] = 'resolve';
        require __DIR__ . '/admin/cases/index.php';
    } elseif (preg_match('#^/admin/cases/(\d+)/send-message$#', $path, $matches) && $method === 'POST') {
        $_GET['case_id'] = $matches[1];
        $_GET['action'] = 'send-message';
        require __DIR__ . '/admin/cases/index.php';
    } elseif (preg_match('#^/admin/cases/(\d+)/note$#', $path, $matches) && $method === 'POST') {
        $_GET['case_id'] = $matches[1];
        $_GET['action'] = 'note';
        require __DIR__ . '/admin/cases/index.php';
    }

    // =====================================================
    // Admin Savings API
    // =====================================================
    elseif ($path === '/admin/savings' && $method === 'GET') {
        require __DIR__ . '/admin/savings/index.php';
    } elseif (preg_match('#^/admin/savings/([A-Za-z0-9\-]+)$#', $path, $matches) && $method === 'GET') {
        $_GET['savings_id'] = $matches[1];
        require __DIR__ . '/admin/savings/index.php';
    } elseif (preg_match('#^/admin/savings/(\d+)/approve-deposit$#', $path, $matches) && $method === 'POST') {
        $_GET['savings_id'] = $matches[1];
        $_GET['action'] = 'approve-deposit';
        require __DIR__ . '/admin/savings/index.php';
    } elseif (preg_match('#^/admin/savings/(\d+)/cancel$#', $path, $matches) && $method === 'POST') {
        $_GET['savings_id'] = $matches[1];
        $_GET['action'] = 'cancel';
        require __DIR__ . '/admin/savings/index.php';
    } elseif (preg_match('#^/admin/savings/(\d+)/complete$#', $path, $matches) && $method === 'POST') {
        $_GET['savings_id'] = $matches[1];
        $_GET['action'] = 'complete';
        require __DIR__ . '/admin/savings/index.php';
    } elseif (preg_match('#^/admin/savings/(\d+)/deposit$#', $path, $matches) && $method === 'POST') {
        $_GET['savings_id'] = $matches[1];
        $_GET['action'] = 'deposit';
        require __DIR__ . '/admin/savings/index.php';
    } elseif (preg_match('#^/admin/savings/([^/]+)$#', $path, $matches) && $method === 'PUT') {
        $_GET['savings_id'] = $matches[1];
        require __DIR__ . '/admin/savings/index.php';
    }
    // Savings Transactions - Approve/Reject pending deposits
    elseif (preg_match('#^/admin/savings/transactions/(\d+)/approve$#', $path, $matches) && $method === 'POST') {
        require __DIR__ . '/admin/savings/transactions.php';
    } elseif (preg_match('#^/admin/savings/transactions/(\d+)/reject$#', $path, $matches) && $method === 'POST') {
        require __DIR__ . '/admin/savings/transactions.php';
    }

    // =====================================================
    // Admin Installments API
    // =====================================================
    elseif ($path === '/admin/installments' && $method === 'GET') {
        require __DIR__ . '/admin/installments/index.php';
    } elseif (preg_match('#^/admin/installments/(\d+)$#', $path, $matches) && $method === 'GET') {
        $_GET['contract_id'] = $matches[1];
        require __DIR__ . '/admin/installments/index.php';
    } elseif (preg_match('#^/admin/installments/(\d+)/approve$#', $path, $matches) && $method === 'POST') {
        $_GET['contract_id'] = $matches[1];
        $_GET['action'] = 'approve';
        require __DIR__ . '/admin/installments/index.php';
    } elseif (preg_match('#^/admin/installments/(\d+)/verify-payment$#', $path, $matches) && $method === 'POST') {
        $_GET['contract_id'] = $matches[1];
        $_GET['action'] = 'verify-payment';
        require __DIR__ . '/admin/installments/index.php';
    } elseif (preg_match('#^/admin/installments/(\d+)/reject-payment$#', $path, $matches) && $method === 'POST') {
        $_GET['contract_id'] = $matches[1];
        $_GET['action'] = 'reject-payment';
        require __DIR__ . '/admin/installments/index.php';
    } elseif (preg_match('#^/admin/installments/(\d+)/manual-payment$#', $path, $matches) && $method === 'POST') {
        $_GET['contract_id'] = $matches[1];
        $_GET['action'] = 'manual-payment';
        require __DIR__ . '/admin/installments/index.php';
    } elseif (preg_match('#^/admin/installments/(\d+)/update-due-date$#', $path, $matches) && $method === 'POST') {
        $_GET['contract_id'] = $matches[1];
        $_GET['action'] = 'update-due-date';
        require __DIR__ . '/admin/installments/index.php';
    } elseif (preg_match('#^/admin/installments/(\d+)/cancel$#', $path, $matches) && $method === 'POST') {
        $_GET['contract_id'] = $matches[1];
        $_GET['action'] = 'cancel';
        require __DIR__ . '/admin/installments/index.php';
    }

    // =====================================================
    // Admin Payments - Verify/Reject with Push Notification
    // =====================================================
    elseif (preg_match('#^/admin/payments/(\d+)/verify$#', $path, $matches) && $method === 'POST') {
        $_GET['id'] = $matches[1];
        $_GET['action'] = 'verify';
        require __DIR__ . '/admin/payments.php';
    } elseif (preg_match('#^/admin/payments/(\d+)/reject$#', $path, $matches) && $method === 'POST') {
        $_GET['id'] = $matches[1];
        $_GET['action'] = 'reject';
        require __DIR__ . '/admin/payments.php';
    } elseif ($path === '/admin/payments/manual' && $method === 'POST') {
        $_GET['action'] = 'manual';
        require __DIR__ . '/admin/payments.php';
    }
    // Admin Fix Order Status (utility endpoint)
    elseif ($path === '/admin/fix-order-status' && $method === 'GET') {
        require __DIR__ . '/admin/fix-order-status.php';
    }

    // =====================================================
    // Unified Payment Management (Classification + Sync)
    // =====================================================
    elseif ($path === '/admin/payments/unified' && $method === 'GET') {
        require __DIR__ . '/admin/payments/unified.php';
    } elseif (preg_match('#^/admin/payments/unified/(\d+)$#', $path, $matches) && $method === 'GET') {
        $_GET['id'] = $matches[1];
        require __DIR__ . '/admin/payments/unified.php';
    } elseif (preg_match('#^/admin/payments/unified/(\d+)/classify$#', $path, $matches) && $method === 'POST') {
        $_GET['id'] = $matches[1];
        $_GET['action'] = 'classify';
        require __DIR__ . '/admin/payments/unified.php';
    } elseif (preg_match('#^/admin/payments/unified/(\d+)/reject$#', $path, $matches) && $method === 'POST') {
        $_GET['id'] = $matches[1];
        $_GET['action'] = 'reject';
        require __DIR__ . '/admin/payments/unified.php';
    }

    // =====================================================
    // Push Notification Webhook API
    // =====================================================
    elseif ($path === '/webhook/push-notify/send' && $method === 'POST') {
        $_GET['action'] = 'send';
        require __DIR__ . '/webhook/push-notify.php';
    } elseif ($path === '/webhook/push-notify/queue' && $method === 'POST') {
        $_GET['action'] = 'queue';
        require __DIR__ . '/webhook/push-notify.php';
    } elseif ($path === '/webhook/push-notify/process' && $method === 'POST') {
        $_GET['action'] = 'process';
        require __DIR__ . '/webhook/push-notify.php';
    } elseif ($path === '/webhook/push-notify/status' && $method === 'GET') {
        $_GET['action'] = 'status';
        require __DIR__ . '/webhook/push-notify.php';
    } elseif ($path === '/webhook/push-notify/stats' && $method === 'GET') {
        $_GET['action'] = 'stats';
        require __DIR__ . '/webhook/push-notify.php';
    }

    // 404 Not Found
    else {
        // Route not found
        Response::error('Route not found', 404);
    }

} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
