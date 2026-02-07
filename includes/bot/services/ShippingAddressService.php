<?php
/**
 * ShippingAddressService
 * 
 * จัดการ parsing และ storage ของที่อยู่จัดส่ง
 * 
 * @package Autobot\Bot\Services
 * @version 1.0.0
 * @date 2026-02-05
 */

namespace Autobot\Bot\Services;

use Database;
use Logger;

class ShippingAddressService
{
    private $db;
    
    // Common Thai provinces
    private $provinces = [
        'กรุงเทพ', 'กรุงเทพฯ', 'กทม', 'นนทบุรี', 'ปทุมธานี', 'สมุทรปราการ', 
        'ชลบุรี', 'เชียงใหม่', 'ขอนแก่น', 'นครราชสีมา', 'สงขลา', 'ภูเก็ต', 'ระยอง',
        'นครปฐม', 'สมุทรสาคร', 'พระนครศรีอยุธยา', 'ลพบุรี', 'สระบุรี', 'เชียงราย',
        'พิษณุโลก', 'อุดรธานี', 'นครสวรรค์', 'สุราษฎร์ธานี', 'หาดใหญ่'
    ];
    
    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }
    
    /**
     * Parse shipping address from free-form text
     * 
     * @param string $text Address text
     * @return array Parsed address components
     */
    public function parseShippingAddress(string $text): array
    {
        $result = [
            'name' => '',
            'phone' => '',
            'address_line1' => '',
            'address_line2' => '',
            'subdistrict' => '',
            'district' => '',
            'province' => '',
            'postal_code' => '',
        ];

        // Clean up text
        $text = preg_replace('/\s+/', ' ', trim($text));
        $text = preg_replace('/\n+/', ' ', $text);

        // Extract phone number (10 digits, starting with 0)
        if (preg_match('/(?:0[689]\d{8}|0[1-5]\d{7})/u', $text, $phoneMatch)) {
            $result['phone'] = $phoneMatch[0];
            $text = str_replace($phoneMatch[0], '', $text);
        }

        // Extract postal code (5 digits)
        if (preg_match('/\b(\d{5})\b/', $text, $postalMatch)) {
            $result['postal_code'] = $postalMatch[1];
            $text = str_replace($postalMatch[0], '', $text);
        }

        // Extract province
        foreach ($this->provinces as $prov) {
            if (mb_stripos($text, $prov) !== false) {
                $result['province'] = $prov === 'กทม' ? 'กรุงเทพฯ' : $prov;
                $text = str_ireplace($prov, '', $text);
                break;
            }
        }

        // Extract district (อำเภอ/เขต)
        if (preg_match('/(?:อ\.?|อำเภอ|เขต)\s*([ก-๙a-zA-Z]+)/u', $text, $districtMatch)) {
            $result['district'] = $districtMatch[1];
            $text = str_replace($districtMatch[0], '', $text);
        }

        // Extract subdistrict (ตำบล/แขวง)
        if (preg_match('/(?:ต\.?|ตำบล|แขวง)\s*([ก-๙a-zA-Z]+)/u', $text, $subdistMatch)) {
            $result['subdistrict'] = $subdistMatch[1];
            $text = str_replace($subdistMatch[0], '', $text);
        }

        // Clean remaining text
        $text = preg_replace('/\s+/', ' ', trim($text));
        $parts = preg_split('/[,\n\s]{2,}/u', $text, 2);

        if (count($parts) >= 2) {
            $result['name'] = trim($parts[0]);
            $result['address_line1'] = trim($parts[1]);
        } else {
            // Try to extract Thai name (first 2-4 words)
            if (preg_match('/^([ก-๙]+\s+[ก-๙]+(?:\s+[ก-๙]+)?)/u', $text, $nameMatch)) {
                $result['name'] = trim($nameMatch[1]);
                $result['address_line1'] = trim(str_replace($nameMatch[1], '', $text));
            } else {
                $result['address_line1'] = $text;
            }
        }

        // Clean up
        $result['address_line1'] = preg_replace('/^[,\s]+|[,\s]+$/', '', $result['address_line1']);

        return $result;
    }

    /**
     * Check if text looks like a shipping address
     * 
     * @param string $text Text to check
     * @return bool True if looks like address
     */
    public function looksLikeAddress(string $text): bool
    {
        $text = trim($text);
        
        // Must be reasonably long
        if (mb_strlen($text) < 20) {
            return false;
        }
        
        $score = 0;
        
        // Has phone number
        if (preg_match('/0[689]\d{8}|0[1-5]\d{7}/', $text)) {
            $score += 2;
        }
        
        // Has postal code
        if (preg_match('/\b\d{5}\b/', $text)) {
            $score += 2;
        }
        
        // Has province
        foreach ($this->provinces as $prov) {
            if (mb_stripos($text, $prov) !== false) {
                $score += 2;
                break;
            }
        }
        
        // Has address indicators
        $addressIndicators = ['บ้านเลขที่', 'ซอย', 'ถนน', 'หมู่', 'ม.', 'ซ.', 'ถ.', 'แขวง', 'เขต', 'ตำบล', 'อำเภอ'];
        foreach ($addressIndicators as $ind) {
            if (mb_stripos($text, $ind) !== false) {
                $score++;
            }
        }
        
        return $score >= 3;
    }
    
    /**
     * Save customer address to database
     * 
     * @param int $userId User ID
     * @param array $addressData Address data
     * @return array Result with success status and address_id
     */
    public function saveAddress(int $userId, array $addressData): array
    {
        try {
            $fullName = $addressData['full_name'] ?? $addressData['name'] ?? '';
            $phone = $addressData['phone'] ?? '';
            $addressLine = $addressData['address_line'] ?? trim(($addressData['address_line1'] ?? '') . ' ' . ($addressData['address_line2'] ?? ''));
            $subdistrict = $addressData['subdistrict'] ?? '';
            $district = $addressData['district'] ?? '';
            $province = $addressData['province'] ?? '';
            $postalCode = $addressData['postal_code'] ?? '';
            $isDefault = !empty($addressData['is_default']);
            
            // If setting as default, unset other defaults first
            if ($isDefault) {
                $this->db->execute(
                    "UPDATE customer_addresses SET is_default = 0, updated_at = NOW() WHERE user_id = ?",
                    [$userId]
                );
            }
            
            // Check if address already exists (by phone + postal code)
            $existing = $this->db->queryOne(
                "SELECT id FROM customer_addresses WHERE user_id = ? AND phone = ? AND postal_code = ? LIMIT 1",
                [$userId, $phone, $postalCode]
            );
            
            if ($existing) {
                // Update existing
                $this->db->execute(
                    "UPDATE customer_addresses SET 
                        full_name = ?, address_line = ?, subdistrict = ?, 
                        district = ?, province = ?, is_default = ?, updated_at = NOW()
                    WHERE id = ?",
                    [$fullName, $addressLine, $subdistrict, $district, $province, $isDefault ? 1 : 0, $existing['id']]
                );
                
                return [
                    'success' => true,
                    'address_id' => $existing['id'],
                    'action' => 'updated',
                ];
            }
            
            // Insert new
            $this->db->execute(
                "INSERT INTO customer_addresses 
                    (user_id, full_name, phone, address_line, subdistrict, district, province, postal_code, is_default, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [$userId, $fullName, $phone, $addressLine, $subdistrict, $district, $province, $postalCode, $isDefault ? 1 : 0]
            );
            
            $addressId = $this->db->lastInsertId();
            
            Logger::info('[SHIPPING_ADDRESS] Address saved', [
                'user_id' => $userId,
                'address_id' => $addressId,
            ]);
            
            return [
                'success' => true,
                'address_id' => $addressId,
                'action' => 'created',
            ];
        } catch (\Exception $e) {
            Logger::error('[SHIPPING_ADDRESS] Failed to save address', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Get customer's addresses
     * 
     * @param int $userId User ID
     * @return array List of addresses
     */
    public function getAddresses(int $userId): array
    {
        try {
            return $this->db->query(
                "SELECT * FROM customer_addresses WHERE user_id = ? ORDER BY is_default DESC, updated_at DESC",
                [$userId]
            );
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * Get default address for customer
     * 
     * @param int $userId User ID
     * @return array|null Default address or null
     */
    public function getDefaultAddress(int $userId): ?array
    {
        try {
            return $this->db->queryOne(
                "SELECT * FROM customer_addresses WHERE user_id = ? ORDER BY is_default DESC, updated_at DESC LIMIT 1",
                [$userId]
            );
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Format address for display
     * 
     * @param array $address Address data
     * @return string Formatted address
     */
    public function formatAddress(array $address): string
    {
        $parts = [];
        
        if (!empty($address['full_name'])) {
            $parts[] = $address['full_name'];
        }
        if (!empty($address['phone'])) {
            $parts[] = 'โทร: ' . $address['phone'];
        }
        if (!empty($address['address_line'])) {
            $parts[] = $address['address_line'];
        }
        if (!empty($address['subdistrict'])) {
            $parts[] = 'แขวง/ตำบล ' . $address['subdistrict'];
        }
        if (!empty($address['district'])) {
            $parts[] = 'เขต/อำเภอ ' . $address['district'];
        }
        if (!empty($address['province'])) {
            $parts[] = 'จังหวัด ' . $address['province'];
        }
        if (!empty($address['postal_code'])) {
            $parts[] = $address['postal_code'];
        }
        
        return implode(' ', $parts);
    }
}
