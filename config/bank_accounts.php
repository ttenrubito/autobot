<?php
/**
 * Bank Accounts Configuration
 * 
 * Static config สำหรับบัญชีธนาคาร (Phase 1)
 * Phase 2 จะย้ายไป database table
 * 
 * @usage:
 *   $bankAccounts = require 'config/bank_accounts.php';
 *   $selectedBank = $bankAccounts['scb_1'];
 * 
 * @created 2026-01-16
 */

return [
    'scb_1' => [
        'id' => 'scb_1',
        'bank_code' => 'SCB',
        'bank_name' => 'ไทยพาณิชย์',
        'account_name' => 'บจก เพชรวิบวับ',
        'account_number' => '1653014242',
        'max_per_slip' => 50000,      // ≤50K ต่อสลิป
        'monthly_limit' => 300000,    // 300K ต่อเดือน
        'is_active' => true,
        'priority' => 1,              // ใช้ก่อน (ยอดเล็ก)
        'note' => 'สำหรับยอดไม่เกิน 50,000 บาท ต่อสลิป',
        'display_text' => 'ไทยพาณิชย์ - บจก เพชรวิบวับ - 1653014242'
    ],
    'kbank_1' => [
        'id' => 'kbank_1',
        'bank_code' => 'KBANK',
        'bank_name' => 'กสิกรไทย',
        'account_name' => 'บจก.เฮงเฮงโฮลดิ้ง',
        'account_number' => '8000029282',
        'max_per_slip' => null,       // ไม่จำกัด
        'monthly_limit' => null,
        'is_active' => true,
        'priority' => 2,
        'note' => 'สำหรับยอดใหญ่ ไม่จำกัดวงเงิน',
        'display_text' => 'กสิกรไทย - บจก.เฮงเฮงโฮลดิ้ง - 8000029282'
    ],
    'bay_1' => [
        'id' => 'bay_1',
        'bank_code' => 'BAY',
        'bank_name' => 'กรุงศรี',
        'account_name' => 'บจก.เฮงเฮงโฮลดิ้ง',
        'account_number' => '8000029282',
        'max_per_slip' => null,
        'monthly_limit' => null,
        'is_active' => true,
        'priority' => 3,
        'note' => 'สำหรับยอดใหญ่ ไม่จำกัดวงเงิน',
        'display_text' => 'กรุงศรี - บจก.เฮงเฮงโฮลดิ้ง - 8000029282'
    ]
];
