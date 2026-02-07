<?php
/**
 * Installment Helper - Centralized installment calculation logic
 * 
 * นโยบายร้าน ฮ.เฮง เฮง:
 * - ผ่อน 3 งวด ภายใน 60 วัน
 * - ค่าธรรมเนียม 3% ครั้งเดียว (จ่ายพร้อมงวดแรก)
 * - ผ่อนครบรับของ
 * 
 * @version 1.0
 * @date 2026-01-18
 */

class InstallmentHelper
{
    // === Constants ตามนโยบายร้าน ===
    const TOTAL_PERIODS = 3;              // จำนวนงวด
    const TOTAL_DAYS = 60;                // ระยะเวลารวม (วัน)
    const SERVICE_FEE_RATE = 0.03;        // ค่าธรรมเนียม 3%
    const SERVICE_FEE_TYPE = 'one_time';  // จ่ายครั้งเดียว (ไม่ใช่ต่อเดือน)
    
    // Due dates: งวด 1 = Day 0, งวด 2 = Day 30, งวด 3 = Day 60 (รับของ)
    const PERIOD_DAYS = [
        1 => 0,   // งวด 1 = วันเปิดบิล (Day 0)
        2 => 30,  // งวด 2 = +30 วัน (Day 30)
        3 => 60,  // งวด 3 = +60 วัน (Day 60) -> รับของ
    ];
    
    /**
     * Calculate due date for a specific period
     * 
     * @param int $periodNumber เลขงวด (1, 2, 3)
     * @param string|null $startDate วันเริ่มต้น (default = today)
     * @return string Y-m-d format
     */
    public static function calculateDueDate(int $periodNumber, ?string $startDate = null): string
    {
        $start = $startDate ?? date('Y-m-d');
        $daysToAdd = self::PERIOD_DAYS[$periodNumber] ?? 0;
        return date('Y-m-d', strtotime("+{$daysToAdd} days", strtotime($start)));
    }
    
    /**
     * Calculate end date of contract (60 days from start)
     * 
     * @param string|null $startDate
     * @return string Y-m-d format
     */
    public static function calculateEndDate(?string $startDate = null): string
    {
        $start = $startDate ?? date('Y-m-d');
        return date('Y-m-d', strtotime('+' . self::TOTAL_DAYS . ' days', strtotime($start)));
    }
    
    /**
     * Calculate service fee (3% one-time)
     * 
     * @param float $productPrice ราคาสินค้า
     * @return float ค่าธรรมเนียม
     */
    public static function calculateServiceFee(float $productPrice): float
    {
        return round($productPrice * self::SERVICE_FEE_RATE, 0);
    }
    
    /**
     * Calculate total amount (product + service fee)
     * 
     * @param float $productPrice ราคาสินค้า
     * @param float $shippingFee ค่าจัดส่ง (optional)
     * @return float ยอดรวม
     */
    public static function calculateTotalAmount(float $productPrice, float $shippingFee = 0): float
    {
        $serviceFee = self::calculateServiceFee($productPrice);
        return $productPrice + $serviceFee + $shippingFee;
    }
    
    /**
     * Calculate payment amounts for each period
     * 
     * สูตรคำนวณ (ตามนโยบายร้าน ฮ.เฮง เฮง):
     * - ค่าดำเนินการ = ราคาสินค้า x 3%
     * - ยอดผ่อนต่องวด = ราคาสินค้า / 3
     * - งวดที่ 1: (ราคาสินค้า / 3) + ค่าดำเนินการ
     * - งวดที่ 2: (ราคาสินค้า / 3)
     * - งวดที่ 3: (ราคาสินค้า / 3) หรือเศษที่เหลือ
     * 
     * @param float $productPrice ราคาสินค้า
     * @param float $shippingFee ค่าจัดส่ง (optional) - ไม่รวมในการคำนวณผ่อน
     * @return array ['period_1' => amount, 'period_2' => amount, 'period_3' => amount]
     */
    public static function calculatePaymentAmounts(float $productPrice, float $shippingFee = 0): array
    {
        // ค่าธรรมเนียม 3% ของราคาสินค้า
        $serviceFee = self::calculateServiceFee($productPrice);
        
        // แบ่งราคาสินค้าเป็น 3 ส่วนเท่าๆ กัน
        $basePerPeriod = floor($productPrice / self::TOTAL_PERIODS);
        $remainder = $productPrice - ($basePerPeriod * self::TOTAL_PERIODS);
        
        // งวดที่ 1: (ราคา/3) + ค่าธรรมเนียม 3%
        $period1 = $basePerPeriod + $serviceFee;
        
        // งวดที่ 2: (ราคา/3)
        $period2 = $basePerPeriod;
        
        // งวดที่ 3: (ราคา/3) + เศษที่เหลือ (ถ้ามี)
        $period3 = $basePerPeriod + $remainder;
        
        // ยอดรวมทั้งหมด = ราคาสินค้า + ค่าธรรมเนียม
        $totalAmount = $productPrice + $serviceFee;
        
        return [
            'period_1' => round($period1, 0),
            'period_2' => round($period2, 0),
            'period_3' => round($period3, 0),
            'service_fee' => round($serviceFee, 0),
            'total_amount' => round($totalAmount, 0),
            'amount_per_period' => round($productPrice / self::TOTAL_PERIODS, 0), // ยอดเฉลี่ยต่องวด (ไม่รวมค่าธรรมเนียม)
            'shipping_fee' => $shippingFee, // แยกค่าส่ง (จ่ายตอนรับของ Day 60)
        ];
    }
    
    /**
     * Calculate all due dates
     * 
     * @param string|null $startDate
     * @return array ['period_1' => date, 'period_2' => date, 'period_3' => date, 'end_date' => date]
     */
    public static function calculateDueDates(?string $startDate = null): array
    {
        return [
            'period_1' => self::calculateDueDate(1, $startDate),
            'period_2' => self::calculateDueDate(2, $startDate),
            'period_3' => self::calculateDueDate(3, $startDate),
            'end_date' => self::calculateEndDate($startDate),
        ];
    }
    
    /**
     * Format due date for display (Thai format)
     * 
     * @param string $date Y-m-d format
     * @return string d/m/Y format
     */
    public static function formatDate(string $date): string
    {
        return date('d/m/Y', strtotime($date));
    }
    
    /**
     * Build installment schedule message for chat
     * 
     * @param float $productPrice
     * @param float $shippingFee
     * @param string|null $startDate
     * @return string
     */
    public static function buildScheduleMessage(float $productPrice, float $shippingFee = 0, ?string $startDate = null): string
    {
        $amounts = self::calculatePaymentAmounts($productPrice, $shippingFee);
        $dates = self::calculateDueDates($startDate);
        
        $msg = "📋 ตารางผ่อนชำระ:\n";
        $msg .= "งวด 1: " . number_format($amounts['period_1'], 0) . " บาท (วันนี้)\n";
        $msg .= "งวด 2: " . number_format($amounts['period_2'], 0) . " บาท (" . self::formatDate($dates['period_2']) . ")\n";
        $msg .= "งวด 3: " . number_format($amounts['period_3'], 0) . " บาท (" . self::formatDate($dates['period_3']) . ")";
        
        return $msg;
    }
    
    /**
     * Get policy summary for chatbot responses
     * 
     * @return array
     */
    public static function getPolicySummary(): array
    {
        return [
            'total_periods' => self::TOTAL_PERIODS,
            'total_days' => self::TOTAL_DAYS,
            'service_fee_rate' => self::SERVICE_FEE_RATE * 100 . '%',
            'service_fee_type' => 'ครั้งเดียว (จ่ายพร้อมงวดแรก)',
            'period_days' => self::PERIOD_DAYS,
            'receive_product' => 'ผ่อนครบรับของ',
            'documents_required' => false,
        ];
    }
}
