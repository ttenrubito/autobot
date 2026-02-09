<?php
/**
 * TransactionService - Transaction checks and management
 * 
 * Handles:
 * - Installment checking
 * - Pawn checking
 * - Repair status
 * - Savings account
 * - Order status
 * 
 * @version 1.0
 * @date 2026-01-23
 */

namespace Autobot\Bot\Services;

require_once __DIR__ . '/../../Database.php';
require_once __DIR__ . '/../../Logger.php';
require_once __DIR__ . '/BackendApiService.php';

class TransactionService
{
    protected $db;
    protected $backendApi;

    public function __construct()
    {
        $this->db = \Database::getInstance();
        $this->backendApi = new BackendApiService();
    }

    // ==================== INSTALLMENT ====================

    /**
     * Check installment status for a customer
     */
    public function checkInstallment(array $config, array $context): array
    {
        $platformUserId = $context['platform_user_id'] ?? null;
        $channelId = $context['channel']['id'] ?? null;

        if (!$platformUserId || !$channelId) {
            return $this->errorResult('missing_context');
        }

        // Try backend API first
        if ($this->isBackendEnabled($config, 'installment')) {
            $result = $this->backendApi->call($config, 'installment', [
                'platform_user_id' => $platformUserId,
                'action' => 'check'
            ], $context);

            if ($result['ok']) {
                return $this->formatInstallmentResult($result['data'], 'backend');
            }
        }

        // Fallback to local check
        return $this->checkInstallmentLocal($platformUserId, $channelId);
    }

    /**
     * Check installment in local database
     * Uses installment_contracts table which exists in production
     */
    protected function checkInstallmentLocal(string $platformUserId, int $channelId): array
    {
        try {
            // Try to find by platform_user_id first (chatbot user)
            $sql = "SELECT * FROM installment_contracts 
                    WHERE (platform_user_id = ? OR external_user_id = ?)
                    AND status IN ('active', 'overdue', 'pending')
                    ORDER BY next_due_date ASC";

            $installments = $this->db->query($sql, [$platformUserId, $platformUserId]);

            if (empty($installments)) {
                // Fallback: try to find via customer_profiles
                $customer = $this->getCustomerProfile($platformUserId, $channelId);
                if ($customer && !empty($customer['id'])) {
                    $sql = "SELECT * FROM installment_contracts 
                            WHERE customer_id = ? 
                            AND status IN ('active', 'overdue', 'pending')
                            ORDER BY next_due_date ASC";
                    $installments = $this->db->query($sql, [$customer['id']]);
                }
            }

            return $this->formatInstallmentResult(['installments' => $installments], 'local');
        } catch (\Exception $e) {
            \Logger::error("[TransactionService] Installment check failed", ['error' => $e->getMessage()]);
            return $this->errorResult($e->getMessage());
        }
    }

    /**
     * Format installment result for chat reply
     * Shows detailed breakdown of each installment period
     * Matches installment_contracts table structure
     */
    protected function formatInstallmentResult(array $data, string $source): array
    {
        $installments = $data['installments'] ?? [];

        if (empty($installments)) {
            return [
                'ok' => true,
                'found' => false,
                'message' => 'ไม่พบรายการผ่อนชำระที่ค้างอยู่',
                'source' => $source
            ];
        }

        $lines = ['📋 รายการผ่อนชำระ'];
        $totalDue = 0;
        $totalNextPayment = 0;

        foreach ($installments as $i => $inst) {
            $num = $i + 1;
            $productName = $inst['product_name'] ?? 'สินค้า';
            // Truncate long product names for mobile
            if (mb_strlen($productName, 'UTF-8') > 25) {
                $productName = mb_substr($productName, 0, 22, 'UTF-8') . '...';
            }
            $contractNo = $inst['contract_no'] ?? '-';
            $contractId = $inst['id'] ?? null; // contract_id from installment_contracts
            $orderId = $inst['order_id'] ?? null;
            $financedAmount = (float) ($inst['financed_amount'] ?? 0);
            $paidAmount = (float) ($inst['paid_amount'] ?? 0);
            $remaining = $financedAmount - $paidAmount;
            $paidPeriods = (int) ($inst['paid_periods'] ?? 0);
            $totalPeriods = (int) ($inst['total_periods'] ?? 3);
            $nextDue = $inst['next_due_date'] ?? '-';
            $status = $inst['status'] ?? 'active';

            // Status icon based on contract status
            $statusIcon = $status === 'overdue' ? '🔴' : ($paidPeriods > 0 ? '🟢' : '🟡');

            $lines[] = '';
            $lines[] = "──────────────";
            $lines[] = "{$num}. {$statusIcon} {$productName}";
            $lines[] = "📄 {$contractNo}";

            // Get schedule details for this contract (from installment_payments table)
            $schedules = $this->getInstallmentSchedules($orderId, $contractNo, $contractId);

            if (!empty($schedules)) {
                foreach ($schedules as $schedule) {
                    $periodNum = $schedule['period_number'] ?? 0;
                    $dueDate = $schedule['due_date'] ?? '-';
                    $amount = (float) ($schedule['amount'] ?? 0);
                    $scheduleStatus = $schedule['status'] ?? 'pending';
                    $paidAt = $schedule['paid_at'] ?? null;

                    // Format date in Thai (short)
                    $dueDateFormatted = $this->formatThaiDate($dueDate);

                    // Status indicator - compact format
                    if ($scheduleStatus === 'paid') {
                        $statusIcon = '✅';
                    } elseif ($scheduleStatus === 'overdue') {
                        $statusIcon = '⚠️';
                        $totalNextPayment += $amount;
                    } else {
                        // Check if this is the next due
                        $today = date('Y-m-d');
                        if ($dueDate <= $today) {
                            $statusIcon = '⏳';
                        } else {
                            $statusIcon = '⏳';
                        }
                        if ($scheduleStatus !== 'paid') {
                            // First unpaid = next payment
                            static $nextPaymentSet = [];
                            if (!isset($nextPaymentSet[$contractNo])) {
                                $totalNextPayment += $amount;
                                $nextPaymentSet[$contractNo] = true;
                            }
                        }
                    }

                    // Compact format: ✅ 1: 28 ม.ค. ฿16,677
                    $lines[] = "{$statusIcon} งวด{$periodNum}: {$dueDateFormatted} ฿" . number_format($amount, 0);
                }
            } else {
                // Fallback: generate schedule from contract data (when installment_payments table not available)
                $perPeriod = $totalPeriods > 0 ? $financedAmount / $totalPeriods : $financedAmount;
                $startDate = $inst['start_date'] ?? $inst['created_at'] ?? date('Y-m-d');

                for ($p = 1; $p <= $totalPeriods; $p++) {
                    // Calculate due date for each period (monthly)
                    $periodDueDate = date('Y-m-d', strtotime($startDate . ' + ' . $p . ' months'));
                    $dueDateFormatted = $this->formatThaiDate($periodDueDate);

                    // Determine status based on paid_periods and current date
                    if ($p <= $paidPeriods) {
                        $statusIcon = '✅';
                    } elseif ($periodDueDate < date('Y-m-d')) {
                        $statusIcon = '⚠️';
                        if ($p === $paidPeriods + 1) {
                            $totalNextPayment += $perPeriod;
                        }
                    } else {
                        $statusIcon = '⏳';
                        if ($p === $paidPeriods + 1) {
                            $totalNextPayment += $perPeriod;
                        }
                    }

                    $lines[] = "{$statusIcon} งวด{$p}: {$dueDateFormatted} ฿" . number_format($perPeriod, 0);
                }
            }

            $lines[] = "💰 เหลือ: ฿" . number_format($remaining, 0);

            $totalDue += $remaining;
        }

        $lines[] = '';
        $lines[] = "──────────────";
        $lines[] = "💰 รวม: ฿" . number_format($totalDue, 0);

        if ($totalNextPayment > 0) {
            $lines[] = "📌 งวดถัดไป: ฿" . number_format($totalNextPayment, 0);
        }

        return [
            'ok' => true,
            'found' => true,
            'message' => implode("\n", $lines),
            'data' => $data,
            'source' => $source
        ];
    }

    /**
     * Get installment schedule details for a contract
     * Uses installment_payments table (linked by contract_id)
     */
    protected function getInstallmentSchedules($orderId, $contractNo, $contractId = null): array
    {
        try {
            // PRIMARY: Query from installment_payments by contract_id (most reliable)
            if ($contractId) {
                $payments = $this->db->query(
                    "SELECT period_number, amount, paid_amount, due_date, paid_date, status
                     FROM installment_payments 
                     WHERE contract_id = ? 
                     ORDER BY period_number ASC",
                    [$contractId]
                );
                if (!empty($payments)) {
                    // Map to expected format
                    return array_map(function ($p) {
                        return [
                            'period_number' => $p['period_number'],
                            'amount' => $p['amount'],
                            'due_date' => $p['due_date'],
                            'status' => $p['status'],
                            'paid_at' => $p['paid_date'],
                            'paid_amount' => $p['paid_amount'],
                        ];
                    }, $payments);
                }
            }

            // FALLBACK 1: Try installment_schedules by order_id
            if ($orderId) {
                $schedules = $this->db->query(
                    "SELECT * FROM installment_schedules 
                     WHERE order_id = ? 
                     ORDER BY period_number ASC",
                    [$orderId]
                );
                if (!empty($schedules)) {
                    return $schedules;
                }
            }

            // FALLBACK 2: Try installment_payments via contract_no
            if ($contractNo) {
                $payments = $this->db->query(
                    "SELECT p.period_number, p.amount, p.paid_amount, p.due_date, p.paid_date, p.status
                     FROM installment_payments p
                     JOIN installment_contracts c ON p.contract_id = c.id
                     WHERE c.contract_no = ?
                     ORDER BY p.period_number ASC",
                    [$contractNo]
                );
                if (!empty($payments)) {
                    return array_map(function ($p) {
                        return [
                            'period_number' => $p['period_number'],
                            'amount' => $p['amount'],
                            'due_date' => $p['due_date'],
                            'status' => $p['status'],
                            'paid_at' => $p['paid_date'],
                            'paid_amount' => $p['paid_amount'],
                        ];
                    }, $payments);
                }
            }
        } catch (\Exception $e) {
            \Logger::warning("[TransactionService] Could not fetch schedules", [
                'contract_id' => $contractId,
                'order_id' => $orderId,
                'contract_no' => $contractNo,
                'error' => $e->getMessage()
            ]);
        }

        return [];
    }

    /**
     * Format date in Thai short format (e.g., "31 ม.ค. 69")
     */
    protected function formatThaiDate($date): string
    {
        if (empty($date) || $date === '-') {
            return '-';
        }

        $thaiMonths = [
            1 => 'ม.ค.',
            2 => 'ก.พ.',
            3 => 'มี.ค.',
            4 => 'เม.ย.',
            5 => 'พ.ค.',
            6 => 'มิ.ย.',
            7 => 'ก.ค.',
            8 => 'ส.ค.',
            9 => 'ก.ย.',
            10 => 'ต.ค.',
            11 => 'พ.ย.',
            12 => 'ธ.ค.'
        ];

        try {
            $timestamp = strtotime($date);
            if ($timestamp === false) {
                return $date;
            }

            $day = date('j', $timestamp);
            $month = (int) date('n', $timestamp);
            $year = (int) date('Y', $timestamp) + 543; // Buddhist year
            $shortYear = $year % 100; // Last 2 digits

            return "{$day} {$thaiMonths[$month]} {$shortYear}";
        } catch (\Exception $e) {
            return $date;
        }
    }

    // ==================== PAWN ====================

    /**
     * Check pawn status
     */
    public function checkPawn(array $config, array $context): array
    {
        $platformUserId = $context['platform_user_id'] ?? null;
        $channelId = $context['channel']['id'] ?? null;

        if (!$platformUserId || !$channelId) {
            return $this->errorResult('missing_context');
        }

        // Try backend API first
        if ($this->isBackendEnabled($config, 'pawn')) {
            $result = $this->backendApi->call($config, 'pawn', [
                'platform_user_id' => $platformUserId,
                'action' => 'check'
            ], $context);

            if ($result['ok']) {
                return $this->formatPawnResult($result['data'], 'backend');
            }
        }

        // Fallback to local
        return $this->checkPawnLocal($platformUserId, $channelId);
    }

    /**
     * Check pawn in local database
     * Uses pawns table which exists in production
     */
    protected function checkPawnLocal(string $platformUserId, int $channelId): array
    {
        try {
            // Try to find by platform_user_id first
            $sql = "SELECT * FROM pawns 
                    WHERE platform_user_id = ? 
                    AND status IN ('active', 'extended', 'expired')
                    ORDER BY due_date ASC";

            $pawns = $this->db->query($sql, [$platformUserId]);

            if (empty($pawns)) {
                // Fallback via customer
                $customer = $this->getCustomerProfile($platformUserId, $channelId);
                if ($customer && !empty($customer['id'])) {
                    $sql = "SELECT * FROM pawns 
                            WHERE customer_id = ? 
                            AND status IN ('active', 'extended', 'expired')
                            ORDER BY due_date ASC";
                    $pawns = $this->db->query($sql, [$customer['id']]);
                }
            }

            return $this->formatPawnResult(['pawns' => $pawns], 'local');
        } catch (\Exception $e) {
            return $this->errorResult($e->getMessage());
        }
    }

    /**
     * Format pawn result
     * Matches pawns table structure
     */
    protected function formatPawnResult(array $data, string $source): array
    {
        $pawns = $data['pawns'] ?? [];

        if (empty($pawns)) {
            return [
                'ok' => true,
                'found' => false,
                'message' => 'ไม่พบรายการจำนำที่ค้างอยู่',
                'source' => $source
            ];
        }

        $lines = ['🏷️ รายการจำนำของคุณ:', ''];

        foreach ($pawns as $i => $pawn) {
            $num = $i + 1;
            $ticketNo = $pawn['ticket_no'] ?? $pawn['pawn_no'] ?? '-';
            $itemName = $pawn['item_name'] ?? 'สินค้า';
            $loanAmount = (float) ($pawn['loan_amount'] ?? 0);
            $accruedInterest = (float) ($pawn['accrued_interest'] ?? 0);
            $totalDue = (float) ($pawn['total_due'] ?? ($loanAmount + $accruedInterest));
            $dueDate = $pawn['due_date'] ?? '-';
            $status = $pawn['status'] ?? 'active';

            $statusIcon = in_array($status, ['expired', 'extended']) ? '⚠️' : '📌';

            $lines[] = "{$num}. {$statusIcon} ตั๋ว #{$ticketNo}";
            $lines[] = "   รายการ: {$itemName}";
            $lines[] = "   เงินต้น: ฿" . number_format($loanAmount, 0);
            $lines[] = "   ดอกเบี้ยสะสม: ฿" . number_format($accruedInterest, 0);
            $lines[] = "   ยอดไถ่: ฿" . number_format($totalDue, 0);
            $lines[] = "   หมดอายุ: {$dueDate}";
            $lines[] = '';
        }

        return [
            'ok' => true,
            'found' => true,
            'message' => implode("\n", $lines),
            'data' => $data,
            'source' => $source
        ];
    }

    // ==================== REPAIR ====================

    /**
     * Check repair status
     */
    public function checkRepair(array $config, array $context): array
    {
        $platformUserId = $context['platform_user_id'] ?? null;
        $channelId = $context['channel']['id'] ?? null;

        if (!$platformUserId || !$channelId) {
            return $this->errorResult('missing_context');
        }

        // Try backend API first
        if ($this->isBackendEnabled($config, 'repair')) {
            $result = $this->backendApi->call($config, 'repair', [
                'platform_user_id' => $platformUserId,
                'action' => 'check'
            ], $context);

            if ($result['ok']) {
                return $this->formatRepairResult($result['data'], 'backend');
            }
        }

        // Fallback to local
        return $this->checkRepairLocal($platformUserId, $channelId);
    }

    /**
     * Check repair in local database
     * Uses repairs table which exists in production
     */
    protected function checkRepairLocal(string $platformUserId, int $channelId): array
    {
        try {
            // Try to find by platform_user_id first
            $sql = "SELECT * FROM repairs 
                    WHERE platform_user_id = ? 
                    AND status NOT IN ('completed', 'cancelled', 'delivered')
                    ORDER BY created_at DESC";

            $repairs = $this->db->query($sql, [$platformUserId]);

            if (empty($repairs)) {
                // Fallback via customer
                $customer = $this->getCustomerProfile($platformUserId, $channelId);
                if ($customer && !empty($customer['id'])) {
                    $sql = "SELECT * FROM repairs 
                            WHERE customer_id = ? 
                            AND status NOT IN ('completed', 'cancelled', 'delivered')
                            ORDER BY created_at DESC";
                    $repairs = $this->db->query($sql, [$customer['id']]);
                }
            }

            return $this->formatRepairResult(['repairs' => $repairs], 'local');
        } catch (\Exception $e) {
            return $this->errorResult($e->getMessage());
        }
    }

    /**
     * Format repair result
     * Matches repairs table structure
     */
    protected function formatRepairResult(array $data, string $source): array
    {
        $repairs = $data['repairs'] ?? [];

        if (empty($repairs)) {
            return [
                'ok' => true,
                'found' => false,
                'message' => 'ไม่พบรายการซ่อมที่กำลังดำเนินการ',
                'source' => $source
            ];
        }

        $statusMap = [
            'pending' => '⏳ รอดำเนินการ',
            'in_progress' => '🔧 กำลังซ่อม',
            'diagnosing' => '🔍 ตรวจสอบ',
            'waiting_parts' => '📦 รอชิ้นส่วน',
            'ready' => '✅ พร้อมรับ',
            'completed' => '✅ เสร็จสิ้น'
        ];

        $lines = ['🔧 สถานะงานซ่อมของคุณ:', ''];

        foreach ($repairs as $i => $repair) {
            $num = $i + 1;
            $repairNo = $repair['repair_no'] ?? $repair['id'] ?? '-';
            $itemType = $repair['item_type'] ?? 'สินค้า';
            $itemDescription = $repair['item_description'] ?? '';
            $status = $repair['status'] ?? 'pending';
            $statusText = $statusMap[$status] ?? $status;
            $estimatedDate = $repair['estimated_completion'] ?? $repair['estimated_date'] ?? '-';
            $estimatedCost = (float) ($repair['estimated_cost'] ?? 0);

            $lines[] = "{$num}. งาน #{$repairNo}";
            $lines[] = "   รายการ: {$itemType}";
            if ($itemDescription) {
                $lines[] = "   รายละเอียด: {$itemDescription}";
            }
            $lines[] = "   สถานะ: {$statusText}";
            if ($estimatedCost > 0) {
                $lines[] = "   ค่าซ่อมโดยประมาณ: ฿" . number_format($estimatedCost, 0);
            }
            if ($status !== 'ready' && $status !== 'completed' && $estimatedDate !== '-') {
                $lines[] = "   คาดว่าเสร็จ: {$estimatedDate}";
            }
            $lines[] = '';
        }

        return [
            'ok' => true,
            'found' => true,
            'message' => implode("\n", $lines),
            'data' => $data,
            'source' => $source
        ];
    }

    // ==================== SAVINGS ====================

    /**
     * Check savings account
     */
    public function checkSavings(array $config, array $context): array
    {
        $platformUserId = $context['platform_user_id'] ?? null;
        $channelId = $context['channel']['id'] ?? null;

        if (!$platformUserId || !$channelId) {
            return $this->errorResult('missing_context');
        }

        // Try backend API first
        if ($this->isBackendEnabled($config, 'savings')) {
            $result = $this->backendApi->call($config, 'savings', [
                'platform_user_id' => $platformUserId,
                'action' => 'check'
            ], $context);

            if ($result['ok']) {
                return $this->formatSavingsResult($result['data'], 'backend');
            }
        }

        // Fallback to local
        return $this->checkSavingsLocal($platformUserId, $channelId);
    }

    /**
     * Check savings in local database
     * Uses savings_accounts table which exists in production
     * Note: savings_accounts uses external_user_id and channel_id
     */
    protected function checkSavingsLocal(string $platformUserId, int $channelId): array
    {
        try {
            // savings_accounts uses external_user_id and channel_id
            $sql = "SELECT * FROM savings_accounts 
                    WHERE external_user_id = ? 
                    AND channel_id = ?
                    AND status = 'active'
                    ORDER BY created_at DESC";

            $accounts = $this->db->query($sql, [$platformUserId, $channelId]);

            return $this->formatSavingsResult(['accounts' => $accounts], 'local');
        } catch (\Exception $e) {
            return $this->errorResult($e->getMessage());
        }
    }

    /**
     * Format savings result
     * Matches savings_accounts table structure
     */
    protected function formatSavingsResult(array $data, string $source): array
    {
        $accounts = $data['accounts'] ?? [];

        if (empty($accounts)) {
            return [
                'ok' => true,
                'found' => false,
                'message' => 'ไม่พบบัญชีออมทอง กรุณาติดต่อแอดมินเพื่อเปิดบัญชี',
                'source' => $source
            ];
        }

        $lines = ['💰 บัญชีออมทองของคุณ:', ''];

        foreach ($accounts as $i => $account) {
            $num = $i + 1;
            $accountNo = $account['savings_account_no'] ?? $account['account_no'] ?? '-';
            $totalDeposits = (float) ($account['total_deposits'] ?? $account['balance'] ?? 0);
            $goldWeight = (float) ($account['gold_weight_grams'] ?? $account['gold_weight'] ?? 0);
            $paymentsMade = (int) ($account['payments_made'] ?? 0);
            $status = $account['status'] ?? 'active';

            $lines[] = "{$num}. บัญชี #{$accountNo}";
            $lines[] = "   ยอดเงินสะสม: ฿" . number_format($totalDeposits, 0);
            if ($goldWeight > 0) {
                $lines[] = "   น้ำหนักทอง: " . number_format($goldWeight, 2) . " กรัม";
            }
            if ($paymentsMade > 0) {
                $lines[] = "   จำนวนงวดที่ชำระ: {$paymentsMade} งวด";
            }
            $lines[] = '';
        }

        return [
            'ok' => true,
            'found' => true,
            'message' => implode("\n", $lines),
            'data' => $data,
            'source' => $source
        ];
    }

    // ==================== ORDER CHECK ====================

    /**
     * Check order status
     */
    public function checkOrder(array $config, array $context, ?string $orderNo = null): array
    {
        $platformUserId = $context['platform_user_id'] ?? null;
        $channelId = $context['channel']['id'] ?? null;

        if (!$platformUserId || !$channelId) {
            return $this->errorResult('missing_context');
        }

        // Try backend API first
        if ($this->isBackendEnabled($config, 'orders')) {
            $result = $this->backendApi->call($config, 'orders', [
                'platform_user_id' => $platformUserId,
                'order_no' => $orderNo,
                'action' => 'check'
            ], $context);

            if ($result['ok']) {
                return $this->formatOrderResult($result['data'], 'backend');
            }
        }

        // Fallback to local
        return $this->checkOrderLocal($platformUserId, $channelId, $orderNo);
    }

    /**
     * Check order in local database
     * Uses orders table which exists in production
     * Note: orders uses platform_user_id
     */
    protected function checkOrderLocal(string $platformUserId, int $channelId, ?string $orderNo): array
    {
        try {
            // First try by platform_user_id
            $sql = "SELECT * FROM orders WHERE platform_user_id = ?";
            $params = [$platformUserId];

            if ($orderNo) {
                $sql .= " AND order_no = ?";
                $params[] = $orderNo;
            } else {
                $sql .= " AND status NOT IN ('completed', 'cancelled', 'delivered')";
            }

            $sql .= " ORDER BY created_at DESC LIMIT 5";

            $orders = $this->db->query($sql, $params);

            // Fallback via customer_id if no direct match
            if (empty($orders)) {
                $customer = $this->getCustomerProfile($platformUserId, $channelId);
                if ($customer && !empty($customer['id'])) {
                    $sql = "SELECT * FROM orders WHERE customer_id = ?";
                    $params = [$customer['id']];

                    if ($orderNo) {
                        $sql .= " AND order_no = ?";
                        $params[] = $orderNo;
                    } else {
                        $sql .= " AND status NOT IN ('completed', 'cancelled', 'delivered')";
                    }

                    $sql .= " ORDER BY created_at DESC LIMIT 5";
                    $orders = $this->db->query($sql, $params);
                }
            }

            return $this->formatOrderResult(['orders' => $orders], 'local');
        } catch (\Exception $e) {
            return $this->errorResult($e->getMessage());
        }
    }

    /**
     * Format order result
     */
    protected function formatOrderResult(array $data, string $source): array
    {
        $orders = $data['orders'] ?? [];

        if (empty($orders)) {
            return [
                'ok' => true,
                'found' => false,
                'message' => 'ไม่พบรายการสั่งซื้อที่กำลังดำเนินการ',
                'source' => $source
            ];
        }

        $statusMap = [
            'pending' => '⏳ รอชำระเงิน',
            'paid' => '💳 ชำระแล้ว',
            'processing' => '📦 กำลังจัดเตรียม',
            'shipped' => '🚚 จัดส่งแล้ว',
            'ready_pickup' => '✅ พร้อมรับสินค้า',
            'completed' => '✅ เสร็จสมบูรณ์',
            'cancelled' => '❌ ยกเลิก'
        ];

        $lines = ['📦 รายการสั่งซื้อของคุณ:', ''];

        foreach ($orders as $i => $order) {
            $num = $i + 1;
            $orderNo = $order['order_no'] ?? '-';
            $total = (float) ($order['total_amount'] ?? 0);
            $status = $order['status'] ?? 'pending';
            $statusText = $statusMap[$status] ?? $status;
            $createdAt = $order['created_at'] ?? '-';

            $lines[] = "{$num}. ออเดอร์ #{$orderNo}";
            $lines[] = "   ยอดรวม: ฿" . number_format($total, 0);
            $lines[] = "   สถานะ: {$statusText}";
            $lines[] = "   วันที่สั่ง: {$createdAt}";
            $lines[] = '';
        }

        return [
            'ok' => true,
            'found' => true,
            'message' => implode("\n", $lines),
            'data' => $data,
            'source' => $source
        ];
    }

    // ==================== TRADE-IN ====================

    /**
     * Calculate trade-in value
     * Business rules (configurable via Store Config):
     * - Exchange: default 10% deduction
     * - Return: default 15% deduction
     * - Rolex: default 35% deduction
     * 
     * @param float $originalPrice Original purchase price
     * @param array|null $rates Optional custom rates from Store Config
     */
    public function calculateTradeIn(float $originalPrice, ?array $rates = null): array
    {
        if ($originalPrice <= 0) {
            return [
                'ok' => false,
                'message' => "กรุณาระบุราคาที่ซื้อไปค่ะ เช่น \"คำนวณเทิร์น 50000\" 😊"
            ];
        }

        // V6: Use configurable rates or defaults
        $exchangeDeduct = $rates['exchange_rate'] ?? 0.10; // 10%
        $returnDeduct = $rates['return_rate'] ?? 0.15;     // 15%
        $rolexDeduct = $rates['special_brands']['Rolex'] ?? 0.35; // 35%

        $exchangeCredit = $originalPrice * (1 - $exchangeDeduct);
        $returnAmount = $originalPrice * (1 - $returnDeduct);
        $rolexAmount = $originalPrice * (1 - $rolexDeduct);

        $lines = [];
        $lines[] = "🧮 **ผลคำนวณยอดเทิร์น**";
        $lines[] = "";
        $lines[] = "💰 ราคาซื้อเดิม: ฿" . number_format($originalPrice, 0);
        $lines[] = "";
        $lines[] = "📊 **ยอดที่จะได้รับ:**";
        $lines[] = "• เปลี่ยนสินค้า (หัก " . ($exchangeDeduct * 100) . "%): ฿" . number_format($exchangeCredit, 0);
        $lines[] = "• คืนสินค้า (หัก " . ($returnDeduct * 100) . "%): ฿" . number_format($returnAmount, 0);
        $lines[] = "• Rolex (หัก " . ($rolexDeduct * 100) . "%): ฿" . number_format($rolexAmount, 0);
        $lines[] = "";
        $lines[] = "📌 รับเปลี่ยน/คืนเฉพาะสินค้าที่ซื้อจากร้านเท่านั้นนะคะ";
        $lines[] = "";
        $lines[] = "💬 สนใจเทิร์น/เปลี่ยนสินค้า พิมพ์ \"คุยแอดมิน\" ได้เลยค่ะ 😊";

        \Logger::info('[TransactionService] Trade-in calculated', [
            'original_price' => $originalPrice,
            'exchange_credit' => $exchangeCredit,
            'return_amount' => $returnAmount,
            'custom_rates' => $rates !== null,
        ]);

        return [
            'ok' => true,
            'message' => implode("\n", $lines),
            'data' => [
                'original_price' => $originalPrice,
                'exchange_credit' => $exchangeCredit,
                'return_amount' => $returnAmount,
                'rolex_amount' => $rolexAmount,
            ]
        ];
    }
    /**
     * Get trade-in policy information
     */
    public function getTradeInPolicy(): string
    {
        $lines = [];
        $lines[] = "🔄 **เงื่อนไขเทิร์น/เปลี่ยน/คืนสินค้า**";
        $lines[] = "";
        $lines[] = "📌 รับเปลี่ยน/เทิร์นเฉพาะสินค้าที่ซื้อจากร้านเท่านั้นนะคะ";
        $lines[] = "";
        $lines[] = "💰 **อัตราหักส่วนต่าง:**";
        $lines[] = "• เปลี่ยนสินค้า: หัก 10%";
        $lines[] = "• คืนสินค้า: หัก 15%";
        $lines[] = "• นาฬิกา Rolex: หัก 35%";
        $lines[] = "";
        $lines[] = "📝 **ตัวอย่างการคำนวณ:**";
        $lines[] = "ซื้อไป 100,000 บาท";
        $lines[] = "• เปลี่ยน → รับเครดิต 90,000 บาท";
        $lines[] = "• คืน → รับเงิน 85,000 บาท";
        $lines[] = "";
        $lines[] = "💬 พิมพ์ \"คำนวณเทิร์น [ราคาที่ซื้อ]\" เพื่อคำนวณยอดได้เลยค่ะ";
        $lines[] = "เช่น: \"คำนวณเทิร์น 50000\"";

        return implode("\n", $lines);
    }

    // ==================== HELPERS ====================

    /**
     * Get customer profile by platform user ID
     * Note: customer_profiles uses platform_user_id and platform (not customer_service_id)
     */
    protected function getCustomerProfile(string $platformUserId, int $channelId): ?array
    {
        try {
            // First get platform from channel
            $channel = $this->db->queryOne(
                "SELECT platform FROM customer_channels WHERE id = ? LIMIT 1",
                [$channelId]
            );
            $platform = $channel['platform'] ?? 'facebook';

            $sql = "SELECT * FROM customer_profiles
                    WHERE platform_user_id = ? 
                    AND platform = ?
                    LIMIT 1";

            $result = $this->db->queryOne($sql, [$platformUserId, $platform]);
            return $result ?: null; // Convert false to null
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check if backend endpoint is enabled
     */
    protected function isBackendEnabled(array $config, string $endpoint): bool
    {
        return !empty($config['backend_api']['enabled']) &&
            !empty($config['backend_api']['endpoints'][$endpoint]);
    }

    /**
     * Create error result
     */
    protected function errorResult(string $error): array
    {
        return [
            'ok' => false,
            'found' => false,
            'message' => 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง',
            'error' => $error
        ];
    }
}
