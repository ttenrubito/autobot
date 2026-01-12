<?php
/**
 * CaseEngine Unit Tests
 * 
 * Tests case management logic with MOCK database
 * 
 * Run: /opt/lampp/bin/php vendor/bin/phpunit tests/bot/CaseEngineTest.php
 */

require_once __DIR__ . '/../bootstrap.php';

// Load CaseEngine
require_once __DIR__ . '/../../includes/bot/CaseEngine.php';

use PHPUnit\Framework\TestCase;

class CaseEngineTest extends TestCase
{
    private $mockDb;

    protected function setUp(): void
    {
        // Get mock database instance
        $this->mockDb = Database::getInstance();
        $this->mockDb->resetCalls();
        Logger::clearLogs();
    }

    /**
     * Test 1: detectCaseType - product inquiry intent
     */
    public function testDetectCaseTypeProductInquiry(): void
    {
        $config = [
            'case_flows' => [
                'product_inquiry' => [
                    'trigger_intents' => ['product_lookup_by_code', 'product_lookup_by_image', 'price_inquiry']
                ],
                'payment_full' => [
                    'trigger_intents' => ['payment_slip_verify']
                ]
            ]
        ];
        
        $context = [
            'channel' => ['id' => 1],
            'external_user_id' => 'user123'
        ];
        
        $engine = new CaseEngine($config, $context);
        
        // Test product inquiry intents
        $this->assertEquals('product_inquiry', $engine->detectCaseType('product_lookup_by_code'));
        $this->assertEquals('product_inquiry', $engine->detectCaseType('product_lookup_by_image'));
        $this->assertEquals('product_inquiry', $engine->detectCaseType('price_inquiry'));
        
        // Test payment intent
        $this->assertEquals('payment_full', $engine->detectCaseType('payment_slip_verify'));
        
        // Test unknown intent (fallback)
        $this->assertNull($engine->detectCaseType('unknown_intent'));
        
        // Test null intent
        $this->assertNull($engine->detectCaseType(null));
    }

    /**
     * Test 2: detectCaseType - savings and installment
     */
    public function testDetectCaseTypeSavingsAndInstallment(): void
    {
        $config = [
            'case_flows' => [
                'payment_installment' => [
                    'trigger_intents' => ['installment_flow', 'installment_new', 'installment_pay']
                ],
                'payment_savings' => [
                    'trigger_intents' => ['savings_new', 'savings_deposit', 'savings_inquiry']
                ]
            ]
        ];
        
        $context = [
            'channel' => ['id' => 1],
            'external_user_id' => 'user123'
        ];
        
        $engine = new CaseEngine($config, $context);
        
        // Test installment
        $this->assertEquals('payment_installment', $engine->detectCaseType('installment_flow'));
        $this->assertEquals('payment_installment', $engine->detectCaseType('installment_new'));
        
        // Test savings
        $this->assertEquals('payment_savings', $engine->detectCaseType('savings_new'));
        $this->assertEquals('payment_savings', $engine->detectCaseType('savings_deposit'));
        $this->assertEquals('payment_savings', $engine->detectCaseType('savings_inquiry'));
    }

    /**
     * Test 3: createCase - success
     */
    public function testCreateCaseSuccess(): void
    {
        $config = [];
        $context = [
            'channel' => ['id' => 1, 'platform' => 'line'],
            'external_user_id' => 'user123',
            'session_id' => 100
        ];
        
        // Mock: no existing case found
        $this->mockDb->setMockData('queryOne', null);
        $this->mockDb->setMockData('lastInsertId', 999);
        
        $engine = new CaseEngine($config, $context);
        $result = $engine->createCase('product_inquiry', ['product_code' => 'ABC123']);
        
        // Assert case created
        $this->assertNotNull($result);
        $this->assertEquals(999, $result['id']);
        $this->assertEquals('product_inquiry', $result['case_type']);
        $this->assertEquals('open', $result['status']);
        $this->assertEquals(['product_code' => 'ABC123'], $result['slots']);
        
        // Assert case_no format
        $this->assertStringStartsWith('CASE-', $result['case_no']);
        
        // Assert DB was called
        $executeCalls = $this->mockDb->getExecuteCalls();
        $this->assertGreaterThan(0, count($executeCalls));
    }

    /**
     * Test 4: getOrCreateCase - missing channel_id returns null
     */
    public function testGetOrCreateCaseMissingChannelId(): void
    {
        $config = [];
        $context = [
            'channel' => [], // No ID
            'external_user_id' => 'user123'
        ];
        
        $engine = new CaseEngine($config, $context);
        $result = $engine->getOrCreateCase('product_inquiry');
        
        // Assert failure - getOrCreateCase validates channel_id
        $this->assertNull($result);
    }

    /**
     * Test 5: getOrCreateCase - find existing case
     */
    public function testGetOrCreateCaseExisting(): void
    {
        $config = [];
        $context = [
            'channel' => ['id' => 1, 'platform' => 'line'],
            'external_user_id' => 'user123',
            'session_id' => 100
        ];
        
        // Mock: existing case found
        $this->mockDb->setMockData('queryOne', [
            'id' => 500,
            'case_no' => 'CASE-20260110-ABCDE',
            'case_type' => 'product_inquiry',
            'status' => 'open',
            'slots' => json_encode(['product_code' => 'XYZ'])
        ]);
        
        $engine = new CaseEngine($config, $context);
        $result = $engine->getOrCreateCase('product_inquiry');
        
        // Assert returns existing case
        $this->assertNotNull($result);
        $this->assertEquals(500, $result['id']);
        $this->assertEquals('CASE-20260110-ABCDE', $result['case_no']);
    }

    /**
     * Test 6: shouldHandoffToAdmin
     */
    public function testShouldHandoffToAdmin(): void
    {
        $config = [
            'case_management' => [
                'admin_handoff_triggers' => ['พูดคุย', 'ติดต่อ', 'สอบถาม', 'ช่วยเหลือ']
            ]
        ];
        $context = [
            'channel' => ['id' => 1],
            'external_user_id' => 'user123'
        ];
        
        $engine = new CaseEngine($config, $context);
        
        // Test: should handoff
        $this->assertTrue($engine->shouldHandoffToAdmin('อยากพูดคุยกับพนักงาน'));
        $this->assertTrue($engine->shouldHandoffToAdmin('ติดต่อแอดมิน'));
        
        // Test: should NOT handoff
        $this->assertFalse($engine->shouldHandoffToAdmin('สวัสดี'));
        $this->assertFalse($engine->shouldHandoffToAdmin('ราคาเท่าไหร่'));
        
        // Test: handoff via slots
        $this->assertTrue($engine->shouldHandoffToAdmin('test', ['handoff_to_admin' => true]));
    }

    /**
     * Test 7: updateCaseSlots
     */
    public function testUpdateCaseSlots(): void
    {
        $config = [];
        $context = [
            'channel' => ['id' => 1],
            'external_user_id' => 'user123'
        ];
        
        // Mock: existing case with slots
        $this->mockDb->setMockData('queryOne', [
            'id' => 123,
            'slots' => json_encode(['existing_key' => 'value'])
        ]);
        
        $engine = new CaseEngine($config, $context);
        $result = $engine->updateCaseSlots(123, ['new_key' => 'new_value']);
        
        // Assert success
        $this->assertTrue($result);
        
        // Assert DB execute was called with merged slots
        $executeCalls = $this->mockDb->getExecuteCalls();
        $this->assertGreaterThan(0, count($executeCalls));
    }

    /**
     * Test 8: updateCaseStatus
     */
    public function testUpdateCaseStatus(): void
    {
        $config = [];
        $context = [
            'channel' => ['id' => 1],
            'external_user_id' => 'user123'
        ];
        
        // Mock: existing case
        $this->mockDb->setMockData('queryOne', [
            'id' => 123,
            'status' => 'open'
        ]);
        
        $engine = new CaseEngine($config, $context);
        $result = $engine->updateCaseStatus(123, 'resolved');
        
        // Assert success
        $this->assertTrue($result);
        
        // Assert DB was called
        $executeCalls = $this->mockDb->getExecuteCalls();
        $this->assertGreaterThan(0, count($executeCalls));
        
        $updateCall = $executeCalls[0];
        $this->assertStringContainsString('UPDATE cases SET status', $updateCall['sql']);
    }

    /**
     * Test 9: triggerHandoff
     */
    public function testTriggerHandoff(): void
    {
        $config = [];
        $context = [
            'channel' => ['id' => 1],
            'external_user_id' => 'user123'
        ];
        
        $engine = new CaseEngine($config, $context);
        $result = $engine->triggerHandoff(123, 'customer_request');
        
        // Assert success
        $this->assertTrue($result);
        
        // Assert DB was called
        $executeCalls = $this->mockDb->getExecuteCalls();
        $this->assertGreaterThan(0, count($executeCalls));
        
        $updateCall = $executeCalls[0];
        $this->assertStringContainsString('pending_admin', $updateCall['sql']);
    }

    /**
     * Test 10: getRequiredSlots
     */
    public function testGetRequiredSlots(): void
    {
        $config = [
            'case_flows' => [
                'payment_installment' => [
                    'required_slots' => ['product_ref', 'phone', 'amount'],
                    'conditional_slots' => [
                        'new_installment' => ['id_card', 'address'],
                        'pay_installment' => ['slip_image']
                    ]
                ]
            ]
        ];
        $context = [
            'channel' => ['id' => 1],
            'external_user_id' => 'user123'
        ];
        
        $engine = new CaseEngine($config, $context);
        
        // Test: basic required slots
        $slots = $engine->getRequiredSlots('payment_installment');
        $this->assertEquals(['product_ref', 'phone', 'amount'], $slots);
        
        // Test: with action_type
        $slots = $engine->getRequiredSlots('payment_installment', 'new_installment');
        $this->assertContains('product_ref', $slots);
        $this->assertContains('id_card', $slots);
        $this->assertContains('address', $slots);
    }
}
