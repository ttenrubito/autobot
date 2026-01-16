<?php
/**
 * RouterV1Handler Unit Tests
 * 
 * Tests critical bot logic with MOCK database (no real API calls)
 * 
 * Run: ./vendor/bin/phpunit tests/bot/RouterV1HandlerTest.php
 */

require_once __DIR__ . '/../bootstrap.php';

use PHPUnit\Framework\TestCase;

class RouterV1HandlerTest extends TestCase
{
    private $handler;
    private $mockDb;

    protected function setUp(): void
    {
        // Create mock database
        $this->mockDb = new Database();
        
        // Create handler instance
        $this->handler = new RouterV1Handler();
        
        // Inject mock DB via reflection (because constructor uses singleton)
        $reflection = new ReflectionClass($this->handler);
        $property = $reflection->getProperty('db');
        $property->setAccessible(true);
        $property->setValue($this->handler, $this->mockDb);

        // Clear logs
        Logger::clearLogs();
        $this->mockDb->resetCalls();
    }

    /**
     * Test 1: Empty text → greeting
     */
    public function testEmptyTextReturnsGreeting(): void
    {
        $context = $this->createMockContext([
            'message' => ['text' => '', 'message_type' => 'text'],
            'bot_profile' => [
                'config' => json_encode([
                    'response_templates' => [
                        'greeting' => 'สวัสดีครับ ยินดีให้บริการ',
                        'fallback' => 'ขออภัยค่ะ'
                    ],
                    'llm' => ['enabled' => false]
                ])
            ]
        ]);

        // Mock session lookup
        $this->mockDb->setMockData('queryOne', [
            'id' => 1,
            'channel_id' => 1,
            'external_user_id' => 'user123'
        ]);

        $result = $this->handler->handleMessage($context);

        $this->assertEquals('สวัสดีครับ ยินดีให้บริการ', $result['reply_text']);
        $this->assertEquals('empty_text_use_greeting', $result['meta']['reason']);
        $this->assertEmpty($result['actions']);
    }

    /**
     * Test 2: Admin command "admin" → no reply + handoff activated
     */
    public function testAdminCommandActivatesHandoff(): void
    {
        $context = $this->createMockContext([
            'message' => ['text' => 'admin', 'message_type' => 'text'],
            // Manual admin command is only accepted when the message is FROM admin
            'is_admin' => true,
            'bot_profile' => [
                'config' => json_encode([
                    'response_templates' => ['fallback' => 'ขออภัย'],
                    'llm' => ['enabled' => false]
                ])
            ]
        ]);

        // Mock session lookup
        $this->mockDb->setMockData('queryOne', [
            'id' => 1,
            'channel_id' => 1,
            'external_user_id' => 'user123'
        ]);

        $result = $this->handler->handleMessage($context);

        // Assert no reply
        $this->assertNull($result['reply_text']);
        $this->assertEquals('admin_handoff_manual_command', $result['meta']['reason']);

        // Assert DB was called to update timestamp
        $executeCalls = $this->mockDb->getExecuteCalls();
        // Current handler performs 2 DB execute calls during manual handoff path
        $this->assertCount(2, $executeCalls);
        
        $adminUpdateCall = $executeCalls[0];
        $this->assertStringContainsString('UPDATE chat_sessions SET last_admin_message_at', $adminUpdateCall['sql']);
    }

    /**
     * Test 3: Echo message → ignored
     */
    public function testEchoMessageIgnored(): void
    {
        $context = $this->createMockContext([
            'message' => [
                'text' => 'สวัสดี',
                'message_type' => 'text',
                'is_echo' => true // Facebook echo
            ],
            'bot_profile' => [
                'config' => json_encode([
                    'response_templates' => ['greeting' => 'สวัสดี'],
                    'llm' => ['enabled' => false]
                ])
            ]
        ]);

        $result = $this->handler->handleMessage($context);

        $this->assertNull($result['reply_text']);
        $this->assertEquals('ignore_echo', $result['meta']['reason']);
    }

    /**
     * Test 4: Repeat spam → anti-spam template
     */
    public function testRepeatSpamTriggersAntiSpam(): void
    {
        $context = $this->createMockContext([
            'message' => ['text' => 'มีสินค้าไหม', 'message_type' => 'text'],
            'bot_profile' => [
                'config' => json_encode([
                    'session_policy' => [
                        'dedupe_enabled' => false
                    ],
                    'anti_spam' => [
                        'enabled' => true,
                        'repeat_threshold' => 2,
                        'window_seconds' => 30,
                        'action' => 'template',
                        'template_key' => 'repeat_detected'
                    ],
                    'response_templates' => [
                        'repeat_detected' => 'กรุณาอย่าส่งข้อความซ้ำค่ะ 😊',
                        'fallback' => 'ขออภัย'
                    ],
                    'llm' => ['enabled' => false]
                ])
            ]
        ]);

        // Mock session
        $this->mockDb->setMockData('queryOne', [
            'id' => 1,
            'channel_id' => 1,
            'external_user_id' => 'user123',
            'last_admin_message_at' => null
        ]);

        // Mock repeated messages in history
        $this->mockDb->setMockData('query', [
            ['role' => 'user', 'text' => 'มีสินค้าไหม', 'created_at' => date('Y-m-d H:i:s')],
            ['role' => 'user', 'text' => 'มีสินค้าไหม', 'created_at' => date('Y-m-d H:i:s', strtotime('-10 seconds'))]
        ]);

        $result = $this->handler->handleMessage($context);

        $this->assertEquals('กรุณาอย่าส่งข้อความซ้ำค่ะ 😊', $result['reply_text']);
        $this->assertEquals('repeat_detected', $result['meta']['reason']);
        $this->assertTrue($result['meta']['anti_spam']['enabled']);
    }

    /**
     * Test 5: Admin active timeout → bot paused
     */
    public function testAdminActiveTimeoutPausesBot(): void
    {
        $context = $this->createMockContext([
            'message' => ['text' => 'สวัสดี', 'message_type' => 'text'],
            'bot_profile' => [
                'config' => json_encode([
                    'response_templates' => ['greeting' => 'สวัสดี'],
                    'llm' => ['enabled' => false],
                    'admin_handoff' => ['timeout_seconds' => 600] // 10 min timeout
                ])
            ]
        ]);

        // Admin sent message 2 minutes ago (within 10 minute timeout)
        $recentAdminTime = date('Y-m-d H:i:s', strtotime('-2 minutes'));
        
        // Session data with recent admin message
        $sessionData = [
            'id' => 1,
            'channel_id' => 1,
            'external_user_id' => 'user123',
            'last_admin_message_at' => $recentAdminTime,
            'last_intent' => null,
            'last_slots_json' => null
        ];
        
        // Row for admin timeout check
        $adminCheckRow = [
            'last_admin_message_at' => $recentAdminTime
        ];

        // Mock multiple queryOne calls
        $mockDb = $this->createMock(Database::class);
        $mockDb->method('queryOne')
            ->willReturnOnConsecutiveCalls(
                $sessionData, // 1st: findOrCreateSession lookup
                $adminCheckRow // 2nd: admin handoff check query
            );
        $mockDb->method('execute')->willReturn(true);

        $reflection = new ReflectionClass($this->handler);
        $property = $reflection->getProperty('db');
        $property->setAccessible(true);
        $property->setValue($this->handler, $mockDb);

        $result = $this->handler->handleMessage($context);

        $this->assertNull($result['reply_text']);
        $this->assertEquals('admin_handoff_active', $result['meta']['reason']);
        $this->assertArrayHasKey('admin_timeout_remaining_sec', $result['meta']);
    }

    /**
     * Test 6: Admin command variations (/admin, #admin, ADMIN)
     */
    public function testAdminCommandVariations(): void
    {
        $commands = ['admin', '/admin', '#admin', 'ADMIN', '  admin  '];

        foreach ($commands as $cmd) {
            $this->setUp(); // Reset for each test

            $context = $this->createMockContext([
                'message' => ['text' => $cmd, 'message_type' => 'text'],
                // Manual admin command is only accepted when the message is FROM admin
                'is_admin' => true,
                'bot_profile' => [
                    'config' => json_encode([
                        'response_templates' => ['fallback' => 'ขออภัย'],
                        'llm' => ['enabled' => false]
                    ])
                ]
            ]);

            $this->mockDb->setMockData('queryOne', [
                'id' => 1,
                'channel_id' => 1,
                'external_user_id' => 'user123'
            ]);

            $result = $this->handler->handleMessage($context);

            $this->assertNull($result['reply_text'], "Command '{$cmd}' should trigger admin handoff");
            $this->assertEquals('admin_handoff_manual_command', $result['meta']['reason']);
        }
    }

    /**
     * Test 7: Short acknowledgement → bypasses anti-spam
     */
    public function testShortAcknowledgementBypassesAntiSpam(): void
    {
        $context = $this->createMockContext([
            'message' => ['text' => 'ค่ะ', 'message_type' => 'text'],
            'bot_profile' => [
                'config' => json_encode([
                    'anti_spam' => [
                        'enabled' => true,
                        'repeat_threshold' => 2,
                        'bypass_short_length' => 3
                    ],
                    'response_templates' => [
                        'greeting' => 'สวัสดี',
                        'fallback' => 'ขออภัย'
                    ],
                    'llm' => ['enabled' => false]
                ])
            ]
        ]);

        $this->mockDb->setMockData('queryOne', [
            'id' => 1,
            'channel_id' => 1,
            'external_user_id' => 'user123',
            'last_admin_message_at' => null
        ]);

        $result = $this->handler->handleMessage($context);

        // Should NOT trigger anti-spam (should go through normal flow)
        $this->assertNotEquals('repeat_detected', $result['meta']['reason'] ?? null);
    }

    /**
     * Helper: Create mock context
     */
    private function createMockContext(array $overrides = []): array
    {
        $default = [
            'trace_id' => 'test_' . bin2hex(random_bytes(4)),
            'channel' => ['id' => 1, 'platform' => 'facebook'],
            'external_user_id' => 'user123',
            'user' => ['external_user_id' => 'user123'],
            'message' => ['text' => '', 'message_type' => 'text'],
            'bot_profile' => [
                'id' => 1,
                'name' => 'TestBot',
                'config' => json_encode([
                    'response_templates' => [
                        'greeting' => 'สวัสดี',
                        'fallback' => 'ขออภัย'
                    ],
                    'llm' => ['enabled' => false]
                ])
            ],
            'integrations' => [],
            'platform' => 'facebook'
        ];

        return array_merge($default, $overrides);
    }

    /**
     * Helper: Assert log contains message
     */
    protected function assertLogContains(string $message): void
    {
        $logs = Logger::getLogs();
        $found = false;

        foreach ($logs as $log) {
            if (is_string($log['message']) && stripos($log['message'], $message) !== false) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, "Log does not contain: {$message}");
    }
}
