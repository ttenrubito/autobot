<?php
/**
 * Unit Tests for AddressService
 * 
 * Tests address parsing and detection logic
 * 
 * @version 1.0
 * @date 2026-01-31
 */

require_once __DIR__ . '/../../includes/services/AddressService.php';

use App\Services\AddressService;
use PHPUnit\Framework\TestCase;

class AddressServiceTest extends TestCase
{
    private $mockPdo;
    private AddressService $service;
    
    protected function setUp(): void
    {
        $this->mockPdo = $this->createMock(PDO::class);
        $this->service = new AddressService($this->mockPdo);
    }
    
    /**
     * Test looksLikeAddress with valid Thai address
     */
    public function testLooksLikeAddressValidAddress(): void
    {
        $text = "นายทดสอบ ใจดี 0891234567 123 ซอยลาดพร้าว 5 แขวงจอมพล เขตจตุจักร กรุงเทพฯ 10900";
        
        $this->assertTrue($this->service->looksLikeAddress($text));
    }
    
    /**
     * Test looksLikeAddress with phone + postal code
     */
    public function testLooksLikeAddressPhoneAndPostal(): void
    {
        $text = "คุณสมชาย 0812345678 บ้านเลข 99 หมู่ 5 ต.บางพลี อ.บางพลี จ.สมุทรปราการ 10540";
        
        $this->assertTrue($this->service->looksLikeAddress($text));
    }
    
    /**
     * Test looksLikeAddress rejects short text
     */
    public function testLooksLikeAddressRejectsShortText(): void
    {
        $text = "สวัสดีค่ะ";
        
        $this->assertFalse($this->service->looksLikeAddress($text));
    }
    
    /**
     * Test looksLikeAddress rejects non-address
     */
    public function testLooksLikeAddressRejectsNonAddress(): void
    {
        $text = "สอบถามราคาทองคำแท่ง 1 บาท ราคาวันนี้เท่าไหร่คะ";
        
        $this->assertFalse($this->service->looksLikeAddress($text));
    }
    
    /**
     * Test parseAddress extracts phone number
     */
    public function testParseAddressExtractsPhone(): void
    {
        $text = "นายทดสอบ 0891234567 บ้าน 123 กรุงเทพ 10900";
        
        $result = $this->service->parseAddress($text);
        
        $this->assertEquals('0891234567', $result['phone']);
    }
    
    /**
     * Test parseAddress extracts postal code
     */
    public function testParseAddressExtractsPostalCode(): void
    {
        $text = "ที่อยู่จัดส่ง กรุงเทพมหานคร 10500";
        
        $result = $this->service->parseAddress($text);
        
        $this->assertEquals('10500', $result['postal_code']);
    }
    
    /**
     * Test parseAddress extracts province - recognizes input value
     * Note: parseAddress keeps matched province as-is (no normalization)
     */
    public function testParseAddressExtractsProvinceBangkok(): void
    {
        // Test that it recognizes full Bangkok name
        $text = "ที่อยู่ 123 แขวงดินแดง เขตดินแดง กรุงเทพมหานคร 10400";
        $result = $this->service->parseAddress($text);
        $this->assertEquals('กรุงเทพมหานคร', $result['province']);
    }
    
    /**
     * Test parseAddress extracts province - other provinces
     */
    public function testParseAddressExtractsProvinceOthers(): void
    {
        $testCases = [
            'นนทบุรี' => 'นนทบุรี',
            'ปทุมธานี' => 'ปทุมธานี',
            'สมุทรปราการ' => 'สมุทรปราการ',
            'ชลบุรี' => 'ชลบุรี',
            'เชียงใหม่' => 'เชียงใหม่',
        ];
        
        foreach ($testCases as $input => $expected) {
            $text = "ที่อยู่ 123 อ.เมือง จ.{$input} 10000";
            $result = $this->service->parseAddress($text);
            $this->assertEquals($expected, $result['province'], "Failed for province: {$input}");
        }
    }
    
    /**
     * Test formatAddress produces readable string
     * Note: formatAddress uses 'recipient_name' not 'full_name'
     */
    public function testFormatAddress(): void
    {
        $addressData = [
            'recipient_name' => 'นายทดสอบ ใจดี',
            'phone' => '0891234567',
            'address_line1' => '123 ซอยลาดพร้าว 5',
            'subdistrict' => 'จอมพล',
            'district' => 'จตุจักร',
            'province' => 'กรุงเทพมหานคร',
            'postal_code' => '10900',
        ];
        
        $result = $this->service->formatAddress($addressData);
        
        $this->assertStringContainsString('นายทดสอบ ใจดี', $result);
        $this->assertStringContainsString('0891234567', $result);
        $this->assertStringContainsString('10900', $result);
    }
}
