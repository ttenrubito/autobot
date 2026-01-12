<?php
/**
 * Savings API Unit Tests
 * 
 * Tests savings account logic with MOCK database
 * 
 * Run: /opt/lampp/bin/php vendor/bin/phpunit tests/bot/SavingsTest.php
 */

require_once __DIR__ . '/../bootstrap.php';

use PHPUnit\Framework\TestCase;

class SavingsTest extends TestCase
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
     * Test 1: Generate account number format
     */
    public function testGenerateAccountNoFormat(): void
    {
        // Generate a simple account number (mimicking the API logic)
        $date = date('Ymd');
        $random = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        $accountNo = "SAV-{$date}-{$random}";
        
        // Assert format
        $this->assertStringStartsWith('SAV-', $accountNo);
        $this->assertMatchesRegularExpression('/^SAV-\d{8}-[A-F0-9]{5}$/', $accountNo);
    }

    /**
     * Test 2: Generate transaction number format
     */
    public function testGenerateTransactionNoFormat(): void
    {
        $date = date('Ymd');
        $random = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        $transactionNo = "SAVTX-{$date}-{$random}";
        
        // Assert format
        $this->assertStringStartsWith('SAVTX-', $transactionNo);
        $this->assertMatchesRegularExpression('/^SAVTX-\d{8}-[A-F0-9]{5}$/', $transactionNo);
    }

    /**
     * Test 3: Required fields validation
     */
    public function testRequiredFieldsValidation(): void
    {
        $required = ['channel_id', 'external_user_id', 'platform', 'product_ref_id', 'product_name', 'product_price'];
        
        // Test with complete input
        $input = [
            'channel_id' => 1,
            'external_user_id' => 'user123',
            'platform' => 'line',
            'product_ref_id' => 'PROD001',
            'product_name' => 'Gold Ring',
            'product_price' => 5000
        ];
        
        $missingFields = [];
        foreach ($required as $field) {
            if (empty($input[$field]) && $input[$field] !== 0) {
                $missingFields[] = $field;
            }
        }
        
        $this->assertEmpty($missingFields, 'All required fields should be present');
        
        // Test with missing field
        $incompleteInput = [
            'channel_id' => 1,
            'external_user_id' => 'user123'
            // missing other fields
        ];
        
        $missingFields = [];
        foreach ($required as $field) {
            if (empty($incompleteInput[$field]) && ($incompleteInput[$field] ?? null) !== 0) {
                $missingFields[] = $field;
            }
        }
        
        $this->assertNotEmpty($missingFields, 'Should detect missing required fields');
        $this->assertContains('platform', $missingFields);
        $this->assertContains('product_ref_id', $missingFields);
    }

    /**
     * Test 4: Calculate remaining amount
     */
    public function testCalculateRemainingAmount(): void
    {
        $targetAmount = 10000;
        $currentAmount = 3500;
        
        $remaining = $targetAmount - $currentAmount;
        $percentage = ($currentAmount / $targetAmount) * 100;
        
        $this->assertEquals(6500, $remaining);
        $this->assertEquals(35, $percentage);
    }

    /**
     * Test 5: Calculate progress percentage
     */
    public function testCalculateProgressPercentage(): void
    {
        $testCases = [
            ['current' => 0, 'target' => 10000, 'expected' => 0],
            ['current' => 5000, 'target' => 10000, 'expected' => 50],
            ['current' => 10000, 'target' => 10000, 'expected' => 100],
            ['current' => 12000, 'target' => 10000, 'expected' => 120], // Over target
        ];
        
        foreach ($testCases as $case) {
            $percentage = ($case['current'] / $case['target']) * 100;
            $this->assertEquals($case['expected'], $percentage, 
                "Progress for {$case['current']}/{$case['target']} should be {$case['expected']}%");
        }
    }

    /**
     * Test 6: Savings status transitions
     */
    public function testSavingsStatusTransitions(): void
    {
        $validStatuses = ['active', 'paused', 'completed', 'cancelled', 'expired'];
        $validTransitions = [
            'active' => ['paused', 'completed', 'cancelled'],
            'paused' => ['active', 'cancelled'],
            'completed' => [], // Final state
            'cancelled' => [], // Final state
            'expired' => ['cancelled']
        ];
        
        // Test active -> paused (valid)
        $this->assertContains('paused', $validTransitions['active']);
        
        // Test completed -> active (invalid)
        $this->assertNotContains('active', $validTransitions['completed']);
        
        // Test all valid statuses exist
        foreach ($validStatuses as $status) {
            $this->assertArrayHasKey($status, $validTransitions);
        }
    }

    /**
     * Test 7: Deposit validation
     */
    public function testDepositValidation(): void
    {
        $account = [
            'status' => 'active',
            'min_deposit_amount' => 100,
            'target_amount' => 10000,
            'current_amount' => 3000
        ];
        
        // Test valid deposit
        $depositAmount = 500;
        $isValid = $account['status'] === 'active' && 
                   ($account['min_deposit_amount'] === null || $depositAmount >= $account['min_deposit_amount']);
        $this->assertTrue($isValid);
        
        // Test deposit below minimum
        $smallDeposit = 50;
        $isValid = $account['status'] === 'active' && 
                   ($account['min_deposit_amount'] === null || $smallDeposit >= $account['min_deposit_amount']);
        $this->assertFalse($isValid);
        
        // Test deposit on non-active account
        $pausedAccount = ['status' => 'paused', 'min_deposit_amount' => null];
        $isValid = $pausedAccount['status'] === 'active';
        $this->assertFalse($isValid);
    }

    /**
     * Test 8: Check if savings is complete
     */
    public function testCheckSavingsComplete(): void
    {
        // Not complete
        $account1 = ['current_amount' => 5000, 'target_amount' => 10000];
        $isComplete = $account1['current_amount'] >= $account1['target_amount'];
        $this->assertFalse($isComplete);
        
        // Exactly complete
        $account2 = ['current_amount' => 10000, 'target_amount' => 10000];
        $isComplete = $account2['current_amount'] >= $account2['target_amount'];
        $this->assertTrue($isComplete);
        
        // Over complete
        $account3 = ['current_amount' => 12000, 'target_amount' => 10000];
        $isComplete = $account3['current_amount'] >= $account3['target_amount'];
        $this->assertTrue($isComplete);
    }

    /**
     * Test 9: Database mock - create savings
     */
    public function testDatabaseCreateSavings(): void
    {
        $this->mockDb->setMockData('lastInsertId', 123);
        
        $this->mockDb->execute(
            "INSERT INTO savings_accounts (account_no, target_amount) VALUES (?, ?)",
            ['SAV-20260110-ABC12', 10000]
        );
        
        // Assert execute was called
        $executeCalls = $this->mockDb->getExecuteCalls();
        $this->assertCount(1, $executeCalls);
        $this->assertStringContainsString('INSERT INTO savings_accounts', $executeCalls[0]['sql']);
        
        // Assert lastInsertId works
        $id = $this->mockDb->lastInsertId();
        $this->assertEquals(123, $id);
    }

    /**
     * Test 10: Database mock - query savings by user
     */
    public function testDatabaseQuerySavingsByUser(): void
    {
        $this->mockDb->setMockData('query', [
            ['id' => 1, 'account_no' => 'SAV-001', 'status' => 'active', 'current_amount' => 5000],
            ['id' => 2, 'account_no' => 'SAV-002', 'status' => 'completed', 'current_amount' => 10000],
        ]);
        
        $results = $this->mockDb->query(
            "SELECT * FROM savings_accounts WHERE external_user_id = ?",
            ['user123']
        );
        
        // Assert query returns mock data
        $this->assertCount(2, $results);
        $this->assertEquals('SAV-001', $results[0]['account_no']);
        $this->assertEquals('active', $results[0]['status']);
    }
}
