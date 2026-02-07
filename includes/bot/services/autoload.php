<?php
/**
 * Service Autoloader - Load all services from /includes/bot/services
 */

// Base services
require_once __DIR__ . '/BackendApiService.php';
require_once __DIR__ . '/ChatService.php';
require_once __DIR__ . '/IntentService.php';
require_once __DIR__ . '/ProductService.php';
require_once __DIR__ . '/TransactionService.php';
require_once __DIR__ . '/CheckoutService.php';

// New refactored services (2026-02-05)
require_once __DIR__ . '/TextProcessingService.php';
require_once __DIR__ . '/JsonParsingService.php';
require_once __DIR__ . '/AdminHandoffService.php';
require_once __DIR__ . '/PaymentLinkingService.php';
require_once __DIR__ . '/ShippingAddressService.php';
