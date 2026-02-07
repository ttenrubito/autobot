<?php
/**
 * Unit Tests for PawnService
 * 
 * Tests business logic without chatbot dependencies
 * 
 * @version 1.0
 * @date 2026-01-31
 */

require_once __DIR__ . '/../../includes/services/PawnService.php';

use App\Services\PawnService;
use PHPUnit\Framework\TestCase;

class PawnServiceTest extends TestCase
{
    private $mockPdo;
    private $mockStmt;
    private PawnService $service;
    
    protected function setUp(): void
    {
        // Create mock PDO
        $this->mockPdo = $this->createMock(PDO::class);
        $this->mockStmt = $this->createMock(PDOStatement::class);
        
        $this->service = new PawnService($this->mockPdo);
    }
    
    /**
     * Test interest calculation with default values
     * Note: Method signature: calculateInterestPreview(appraisedValue, loanPercentage, interestRate, termDays)
     */
    public function testCalculateInterestPreviewDefaultRate(): void
    {
        $result = $this->service->calculateInterestPreview(10000); // appraised value
        
        $this->assertEquals(10000, $result['appraised_value']);
        $this->assertEquals(65, $result['loan_percentage']);
        $this->assertEquals(6500, $result['loan_amount']); // 65% of 10000
        $this->assertEquals(130, $result['monthly_interest']); // 2% of 6500
        $this->assertEquals(2.0, $result['interest_rate']);
    }
    
    /**
     * Test interest calculation for different loan percentage
     */
    public function testCalculateInterestPreviewCustomLoanPercentage(): void
    {
        $result = $this->service->calculateInterestPreview(10000, 70);
        
        $this->assertEquals(10000, $result['appraised_value']);
        $this->assertEquals(70, $result['loan_percentage']);
        $this->assertEquals(7000, $result['loan_amount']); // 70% of 10000
        $this->assertEquals(140, $result['monthly_interest']); // 2% of 7000
    }
    
    /**
     * Test interest calculation with custom rate
     */
    public function testCalculateInterestPreviewCustomRate(): void
    {
        $result = $this->service->calculateInterestPreview(10000, 65, 1.5);
        
        $this->assertEquals(10000, $result['appraised_value']);
        $this->assertEquals(6500, $result['loan_amount']); // 65% of 10000
        $this->assertEquals(97.5, $result['monthly_interest']); // 1.5% of 6500
        $this->assertEquals(1.5, $result['interest_rate']);
    }
    
    /**
     * Test zero appraised value returns zeros
     */
    public function testCalculateInterestPreviewZeroPrincipal(): void
    {
        $result = $this->service->calculateInterestPreview(0);
        
        $this->assertEquals(0, $result['appraised_value']);
        $this->assertEquals(0, $result['loan_amount']);
        $this->assertEquals(0, $result['monthly_interest']);
    }
    
    /**
     * Test constants are accessible
     */
    public function testConstantsExist(): void
    {
        $this->assertEquals(65, PawnService::DEFAULT_LOAN_PERCENTAGE);
        $this->assertEquals(70, PawnService::MAX_LOAN_PERCENTAGE);
        $this->assertEquals(2.0, PawnService::DEFAULT_INTEREST_RATE);
        $this->assertEquals(30, PawnService::DEFAULT_TERM_DAYS);
        $this->assertEquals(12, PawnService::MAX_EXTENSIONS);
    }
    
    /**
     * Test findActivePawns returns empty when no customer found
     */
    public function testFindActivePawnsNoCustomer(): void
    {
        // Setup mock to return no customer
        $this->mockStmt->method('fetch')->willReturn(false);
        $this->mockPdo->method('prepare')->willReturn($this->mockStmt);
        
        $result = $this->service->findActivePawns('nonexistent_user');
        
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
    
    /**
     * Test matchSlipToPawn returns null when empty array
     * Note: Method signature: matchSlipToPawn(array $pawns, float $slipAmount, float $tolerance)
     */
    public function testMatchSlipToPawnNoMatch(): void
    {
        // Pass empty array directly (no DB call needed)
        $result = $this->service->matchSlipToPawn([], 1000);
        
        $this->assertNull($result);
    }
    
    /**
     * Test matchSlipToPawn finds interest payment match
     */
    public function testMatchSlipToPawnMatchesInterest(): void
    {
        $pawns = [
            [
                'id' => 1,
                'pawn_no' => 'PAWN-001',
                'expected_interest_amount' => 500,
                'full_redemption_amount' => 10500,
                'pawn_amount' => 10000,
            ]
        ];
        
        // Amount matches interest (within tolerance)
        $result = $this->service->matchSlipToPawn($pawns, 500);
        
        $this->assertNotNull($result);
        $this->assertEquals('interest', $result['payment_type']);
        $this->assertEquals(1, $result['pawn']['id']);
    }
    
    /**
     * Test matchSlipToPawn finds redemption match
     */
    public function testMatchSlipToPawnMatchesRedemption(): void
    {
        $pawns = [
            [
                'id' => 2,
                'pawn_no' => 'PAWN-002',
                'expected_interest_amount' => 500,
                'full_redemption_amount' => 10500,
                'pawn_amount' => 10000,
            ]
        ];
        
        // Amount matches full redemption
        $result = $this->service->matchSlipToPawn($pawns, 10500);
        
        $this->assertNotNull($result);
        $this->assertEquals('full_redemption', $result['payment_type']);
    }
}
