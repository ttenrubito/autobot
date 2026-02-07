<?php
/**
 * Unit Tests for InstallmentService
 * 
 * Tests installment calculation and matching logic
 * 
 * @version 1.0
 * @date 2026-01-31
 */

require_once __DIR__ . '/../../includes/services/InstallmentService.php';

use App\Services\InstallmentService;
use PHPUnit\Framework\TestCase;

class InstallmentServiceTest extends TestCase
{
    private $mockPdo;
    private $mockStmt;
    private InstallmentService $service;
    
    protected function setUp(): void
    {
        $this->mockPdo = $this->createMock(PDO::class);
        $this->mockStmt = $this->createMock(PDOStatement::class);
        
        $this->service = new InstallmentService($this->mockPdo);
    }
    
    /**
     * Test calculatePlanPreview with 0% interest (interest-free)
     * Note: Method returns 'installment_amount' not 'monthly_payment'
     */
    public function testCalculatePlanPreviewZeroInterest(): void
    {
        $result = $this->service->calculatePlanPreview(30000, 3, 0);
        
        $this->assertEquals(30000, $result['total_amount']);
        $this->assertEquals(30000, $result['total_with_interest']); // No interest
        $this->assertEquals(0, $result['total_interest']);
        $this->assertEquals(10000, $result['installment_amount']); // 30000/3
        $this->assertEquals(3, $result['periods']);
        $this->assertCount(3, $result['schedule']); // 3 installments
    }
    
    /**
     * Test calculatePlanPreview with interest
     */
    public function testCalculatePlanPreviewWithInterest(): void
    {
        $result = $this->service->calculatePlanPreview(10000, 5, 2);
        
        $this->assertEquals(10000, $result['total_amount']);
        $this->assertEquals(1000, $result['total_interest']); // 10% total (2% x 5 periods)
        $this->assertEquals(11000, $result['total_with_interest']);
        $this->assertEquals(2200, $result['installment_amount']); // 11000/5
        $this->assertEquals(5, $result['periods']);
    }
    
    /**
     * Test schedule includes due dates
     * Note: Method uses 'period' not 'installment_number'
     */
    public function testCalculatePlanPreviewScheduleHasDueDates(): void
    {
        $result = $this->service->calculatePlanPreview(12000, 2, 0);
        
        $this->assertCount(2, $result['schedule']);
        
        foreach ($result['schedule'] as $item) {
            $this->assertArrayHasKey('period', $item);
            $this->assertArrayHasKey('amount', $item);
            $this->assertArrayHasKey('due_date', $item);
        }
        
        // Each installment should be 6000
        $this->assertEquals(6000, $result['schedule'][0]['amount']);
        $this->assertEquals(6000, $result['schedule'][1]['amount']);
        
        // Period numbers should be 1 and 2
        $this->assertEquals(1, $result['schedule'][0]['period']);
        $this->assertEquals(2, $result['schedule'][1]['period']);
    }
    
    /**
     * Test findActiveInstallments returns empty when no customer
     */
    public function testFindActiveInstallmentsNoCustomer(): void
    {
        $this->mockStmt->method('fetch')->willReturn(false);
        $this->mockPdo->method('prepare')->willReturn($this->mockStmt);
        
        $result = $this->service->findActiveInstallments('nonexistent');
        
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
    
    /**
     * Test matchSlipToInstallment returns null when empty array
     * Note: Method signature: matchSlipToInstallment(array $contracts, float $slipAmount)
     */
    public function testMatchSlipToInstallmentNoMatch(): void
    {
        // Pass empty array directly
        $result = $this->service->matchSlipToInstallment([], 5000);
        
        $this->assertNull($result);
    }
    
    /**
     * Test matchSlipToInstallment finds matching contract
     */
    public function testMatchSlipToInstallmentMatch(): void
    {
        $contracts = [
            [
                'id' => 1,
                'contract_no' => 'IC-001',
                'installment_amount' => 5000,
            ]
        ];
        
        $result = $this->service->matchSlipToInstallment($contracts, 5000);
        
        $this->assertNotNull($result);
        $this->assertEquals(1, $result['contract']['id']);
    }
}
