<?php
/**
 * Create PromptPay QR Code Charge
 * POST /api/payment/create-promptpay-charge
 * 
 * Creates a PromptPay source and charge, returns QR code for customer to scan
 */

require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/Response.php';
require_once __DIR__ . '/../../includes/Validator.php';
require_once __DIR__ . '/../../includes/OmiseClient.php';

Auth::require();

try {
    $db = Database::getInstance();
    $userId = Auth::id();
    
    // Read JSON body
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: [];
    
    $validator = new Validator();
    
    // Require invoice_id for invoice-based payment
    $invoiceId = $input['invoice_id'] ?? null;
    
    $validator->required('invoice_id', $invoiceId, 'Invoice ID');
    
    if ($validator->fails()) {
        Response::validationError($validator->getErrors());
    }
    
    // Get and validate invoice
    $invoice = $db->queryOne(
        "SELECT i.*, sp.name as plan_name
         FROM invoices i
         LEFT JOIN subscriptions s ON i.subscription_id = s.id
         LEFT JOIN subscription_plans sp ON s.plan_id = sp.id
         WHERE i.id = ? AND i.user_id = ?
         LIMIT 1",
        [$invoiceId, $userId]
    );
    
    if (!$invoice) {
        Response::error('Invoice not found', 404);
    }
    
    if ($invoice['status'] !== 'pending') {
        Response::error('Invoice has already been paid or is not available for payment', 400);
    }
    
    // Use invoice amount and details
    $amount = $invoice['total'];
    $currency = $invoice['currency'] ?? 'THB';
    $description = "Invoice #{$invoice['invoice_number']}";
    if ($invoice['plan_name']) {
        $description .= " - {$invoice['plan_name']}";
    }
    
    $omise = new OmiseClient();
    
    // Step 1: Create PromptPay source
    $source = $omise->createPromptPaySource($amount, $currency);
    
    if (!$source || !isset($source['id'])) {
        error_log("PromptPay Source Error: " . json_encode($source));
        Response::error('Failed to create PromptPay source', 500);
    }
    
    $sourceId = $source['id'];
    
    // Step 2: Create charge from source
    $charge = $omise->createChargeFromSource($sourceId, $amount, $currency, $description);
    
    if (!$charge || !isset($charge['id'])) {
        error_log("PromptPay Charge Error: " . json_encode($charge));
        Response::error('Failed to create charge', 500);
    }
    
    $chargeId = $charge['id'];
    $status = $charge['status'] ?? 'pending';
    
    // Extract QR code URL from source
    $qrCodeUrl = null;
    if (isset($charge['source']['scannable_code']['image']['download_uri'])) {
        $qrCodeUrl = $charge['source']['scannable_code']['image']['download_uri'];
    } elseif (isset($source['scannable_code']['image']['download_uri'])) {
        $qrCodeUrl = $source['scannable_code']['image']['download_uri'];
    }
    
    // Get expiration time
    $expiresAt = null;
    if (isset($charge['expires_at'])) {
        $expiresAt = $charge['expires_at'];
    } elseif (isset($source['expires_at'])) {
        $expiresAt = $source['expires_at'];
    }
    
    // Save transaction record linked to invoice
    $db->execute(
        "INSERT INTO transactions 
         (invoice_id, payment_method_id, omise_charge_id, amount, currency, status, metadata, created_at)
         VALUES (?, NULL, ?, ?, ?, ?, ?, NOW())",
        [
            $invoiceId,
            $chargeId,
            $amount,
            $currency,
            'pending',
            json_encode([
                'payment_type' => 'promptpay',
                'user_id' => $userId,
                'invoice_id' => $invoiceId,
                'invoice_number' => $invoice['invoice_number'],
                'description' => $description,
                'qr_code_url' => $qrCodeUrl,
                'expires_at' => $expiresAt
            ])
        ]
    );
    
    $transactionId = $db->lastInsertId();
    
    // Log activity
    $db->execute(
        "INSERT INTO activity_logs (user_id, action, resource_type, resource_id, ip_address, user_agent) 
         VALUES (?, 'create_promptpay_charge', 'transaction', ?, ?, ?)",
        [$userId, $transactionId, Auth::getIpAddress(), Auth::getUserAgent()]
    );
    
    Response::success([
        'charge_id' => $chargeId,
        'source_id' => $sourceId,
        'transaction_id' => $transactionId,
        'qr_code_url' => $qrCodeUrl,
        'amount' => $amount,
        'currency' => $currency,
        'status' => $status,
        'expires_at' => $expiresAt,
        'created_at' => $charge['created_at'] ?? date('c')
    ], 'PromptPay QR code created successfully');
    
} catch (Exception $e) {
    error_log("Create PromptPay Charge Error: " . $e->getMessage());
    Response::error($e->getMessage(), 500);
}
