<?php
/**
 * Slip Verification API Configuration
 * 
 * Configure your slip verification API credentials here.
 * 
 * Supported APIs:
 * - SlipOK (https://slipok.com)
 * - EasySlip (https://easyslip.com)
 * - Custom API
 * 
 * @date 2026-02-08
 */

// Enable/Disable slip verification
// Set to true once you have configured the API
define('SLIP_VERIFY_ENABLED', false);

// API Endpoint
// Examples:
// SlipOK: https://api.slipok.com/api/line/apikey/YOUR_BRANCH_ID
// EasySlip: https://developer.easyslip.com/api/v1/verify
// Custom: Your own API endpoint
define('SLIP_VERIFY_API_ENDPOINT', '');

// API Key / Authorization Token
// This will be sent as:
// - Authorization: Bearer {key}
// - x-authorization: {key}
define('SLIP_VERIFY_API_KEY', '');

// Optional: Bank account to validate against
// If set, verification will check if receiver matches
define('SLIP_VERIFY_EXPECTED_ACCOUNT', '123-456-7890');
define('SLIP_VERIFY_EXPECTED_BANK', 'SCB');
