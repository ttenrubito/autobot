<?php
/**
 * AddressService - Business logic for address parsing and storage
 * 
 * @version 1.0
 * @date 2026-01-31
 */

namespace App\Services;

use PDO;
use Exception;

class AddressService
{
    private PDO $db;
    
    // Common Thai provinces (longest first for proper matching)
    private const PROVINCES = [
        'กรุงเทพมหานคร', 'กรุงเทพฯ', 'กรุงเทพ', 'กทม', 
        'นนทบุรี', 'ปทุมธานี', 'สมุทรปราการ',
        'ชลบุรี', 'เชียงใหม่', 'ขอนแก่น', 'นครราชสีมา', 'สงขลา', 'ภูเก็ต', 'ระยอง',
        'พระนครศรีอยุธยา', 'สุราษฎร์ธานี', 'เชียงราย', 'อุดรธานี', 'นครปฐม'
    ];
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? \getDB();
    }
    
    /**
     * Parse Thai shipping address from text
     * @param string $text Raw address text
     * @return array Parsed address components
     */
    public function parseAddress(string $text): array
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
        foreach (self::PROVINCES as $prov) {
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
        
        // Clean up address
        $result['address_line1'] = preg_replace('/^[,\s]+|[,\s]+$/', '', $result['address_line1']);
        
        return $result;
    }
    
    /**
     * Check if text looks like a shipping address
     * @param string $text Text to check
     * @return bool True if likely an address
     */
    public function looksLikeAddress(string $text): bool
    {
        // Too short = not address
        if (mb_strlen($text, 'UTF-8') < 20) {
            return false;
        }
        
        $score = 0;
        
        // Has phone number
        if (preg_match('/0[689]\d{8}|0[1-5]\d{7}/u', $text)) {
            $score += 3;
        }
        
        // Has postal code
        if (preg_match('/\d{5}/', $text)) {
            $score += 2;
        }
        
        // Has province keywords
        if (preg_match('/กรุงเทพ|กทม|นนทบุรี|ปทุมธานี|สมุทรปราการ|ชลบุรี|เชียงใหม่/u', $text)) {
            $score += 2;
        }
        
        // Has address keywords
        if (preg_match('/อำเภอ|เขต|ตำบล|แขวง|ซอย|ถนน|หมู่บ้าน|บ้านเลขที่/u', $text)) {
            $score += 2;
        }
        
        return $score >= 3;
    }
    
    /**
     * Save customer address to database
     * @param int|array $userIdOrData User ID (int) or parsed address data (array for legacy)
     * @param array|string $addressDataOrPlatformUserId Address data or platform user ID (legacy)
     * @param string $platform Platform (line/facebook) - only used in legacy mode
     * @return array ['success' => bool, 'address_id' => int]
     */
    public function saveAddress($userIdOrData, $addressDataOrPlatformUserId = [], string $platform = 'line'): array
    {
        // Detect call style: new (userId, addressData) vs legacy (addressData, platformUserId, platform)
        if (is_int($userIdOrData)) {
            // New style: saveAddress(userId, addressData)
            $userId = $userIdOrData;
            $addressData = $addressDataOrPlatformUserId;
            return $this->saveAddressByUserId($userId, $addressData);
        } else {
            // Legacy style: saveAddress(addressData, platformUserId, platform)
            $addressData = $userIdOrData;
            $platformUserId = (string)$addressDataOrPlatformUserId;
            return $this->saveAddressByPlatformId($addressData, $platformUserId, $platform);
        }
    }
    
    /**
     * Save address by user ID (new style)
     */
    private function saveAddressByUserId(int $userId, array $addressData): array
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO customer_addresses (
                    user_id, address_type, label,
                    full_name, phone, address_line, 
                    subdistrict, district, province, postal_code, country, 
                    is_default, created_at
                ) VALUES (?, 'shipping', ?, ?, ?, ?, ?, ?, ?, ?, 'Thailand', ?, NOW())
            ");
            $stmt->execute([
                $userId,
                $addressData['label'] ?? 'ที่อยู่จัดส่ง',
                $addressData['full_name'] ?? '',
                $addressData['phone'] ?? '',
                $addressData['address_line'] ?? '',
                $addressData['subdistrict'] ?? '',
                $addressData['district'] ?? '',
                $addressData['province'] ?? '',
                $addressData['postal_code'] ?? '',
                $addressData['is_default'] ?? false ? 1 : 0,
            ]);
            
            return ['success' => true, 'address_id' => (int)$this->db->lastInsertId()];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Save address by platform user ID (legacy style)
     */
    private function saveAddressByPlatformId(array $addressData, string $platformUserId, string $platform): array
    {
        try {
            // Get or find customer ID
            $customer = $this->getCustomerByPlatformId($platformUserId);
            $customerId = $customer ? (int)$customer['id'] : null;
            
            $stmt = $this->db->prepare("
                INSERT INTO customer_addresses (
                    customer_id, platform, platform_user_id, address_type, 
                    recipient_name, phone, address_line1, address_line2, 
                    subdistrict, district, province, postal_code, country, 
                    is_default, created_at
                ) VALUES (?, ?, ?, 'shipping', ?, ?, ?, ?, ?, ?, ?, ?, 'Thailand', 1, NOW())
            ");
            $stmt->execute([
                $customerId,
                $platform,
                $platformUserId,
                $addressData['name'] ?? '',
                $addressData['phone'] ?? '',
                $addressData['address_line1'] ?? '',
                $addressData['address_line2'] ?? '',
                $addressData['subdistrict'] ?? '',
                $addressData['district'] ?? '',
                $addressData['province'] ?? '',
                $addressData['postal_code'] ?? '',
            ]);
            
            return ['success' => true, 'address_id' => (int)$this->db->lastInsertId()];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get addresses for a customer
     * @param int|string $userIdOrPlatformUserId User ID (int) or platform user ID (string)
     * @return array List of addresses
     */
    public function getAddresses($userIdOrPlatformUserId): array
    {
        if (is_int($userIdOrPlatformUserId)) {
            // By user_id
            $stmt = $this->db->prepare("
                SELECT * FROM customer_addresses 
                WHERE user_id = ?
                ORDER BY is_default DESC, created_at DESC
            ");
            $stmt->execute([$userIdOrPlatformUserId]);
        } else {
            // By platform_user_id
            $stmt = $this->db->prepare("
                SELECT * FROM customer_addresses 
                WHERE platform_user_id = ?
                ORDER BY is_default DESC, created_at DESC
            ");
            $stmt->execute([$userIdOrPlatformUserId]);
        }
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get customer by platform user ID
     */
    private function getCustomerByPlatformId(string $platformUserId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id FROM customer_profiles 
            WHERE platform_user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$platformUserId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Format address for display
     * @param array $address Address data
     * @return string Formatted address
     */
    public function formatAddress(array $address): string
    {
        $parts = [];
        
        if (!empty($address['recipient_name'])) {
            $parts[] = $address['recipient_name'];
        }
        if (!empty($address['phone'])) {
            $parts[] = "📞 " . $address['phone'];
        }
        if (!empty($address['address_line1'])) {
            $parts[] = $address['address_line1'];
        }
        if (!empty($address['subdistrict'])) {
            $parts[] = "ต." . $address['subdistrict'];
        }
        if (!empty($address['district'])) {
            $parts[] = "อ." . $address['district'];
        }
        if (!empty($address['province'])) {
            $parts[] = "จ." . $address['province'];
        }
        if (!empty($address['postal_code'])) {
            $parts[] = $address['postal_code'];
        }
        
        return implode(' ', $parts);
    }
}
