<?php
/**
 * Income Report API - Enterprise Level
 * 
 * Endpoints:
 * GET /api/customer/reports/income - Get income report with aggregations
 * GET /api/customer/reports/income?export=csv - Export as CSV
 * GET /api/customer/reports/income?export=excel - Export as Excel
 * 
 * Query Parameters:
 * - start_date: YYYY-MM-DD (default: first day of current year)
 * - end_date: YYYY-MM-DD (default: today)
 * - group_by: day|week|month|year (default: month)
 * - payment_type: full|installment|deposit|savings|all (default: all)
 * - status: verified|pending|rejected|all (default: verified)
 * - page: int (default: 1)
 * - limit: int (default: 50, max: 1000)
 * - export: csv|excel|json (optional)
 * 
 * Features:
 * - Efficient pagination for large datasets
 * - Pre-aggregated summaries
 * - Date range filtering with proper indexing
 * - Multi-dimensional grouping
 * - Export capabilities (CSV/Excel)
 * - VAT calculation support
 * - Year-to-date totals
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/auth.php';

// Set memory limit for large exports
ini_set('memory_limit', '256M');
set_time_limit(120);

// Verify authentication
$auth = verifyToken();
if (!$auth['valid']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $auth['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $pdo = getDB();
    
    // Parse query parameters with defaults
    $start_date = isset($_GET['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start_date']) 
        ? $_GET['start_date'] 
        : date('Y-01-01'); // First day of current year
    
    $end_date = isset($_GET['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end_date']) 
        ? $_GET['end_date'] 
        : date('Y-m-d'); // Today
    
    $group_by = isset($_GET['group_by']) && in_array($_GET['group_by'], ['day', 'week', 'month', 'year']) 
        ? $_GET['group_by'] 
        : 'month';
    
    $payment_type = isset($_GET['payment_type']) && in_array($_GET['payment_type'], ['full', 'installment', 'deposit', 'savings', 'deposit_interest', 'pawn_redemption', 'all']) 
        ? $_GET['payment_type'] 
        : 'all';
    
    $status = isset($_GET['status']) && in_array($_GET['status'], ['verified', 'pending', 'rejected', 'all']) 
        ? $_GET['status'] 
        : 'verified'; // Default: only verified payments for tax reports
    
    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(1000, max(1, (int) $_GET['limit'])) : 50;
    $offset = ($page - 1) * $limit;
    
    $export = isset($_GET['export']) && in_array($_GET['export'], ['csv', 'excel', 'json']) 
        ? $_GET['export'] 
        : null;
    
    // VAT rate (Thailand standard: 7%)
    $vat_rate = 0.07;
    $include_vat = isset($_GET['include_vat']) && $_GET['include_vat'] === 'true';
    
    // Build WHERE clause
    $where_conditions = [];
    $params = [];
    
    // Filter by shop owner
    $where_conditions[] = '(p.shop_owner_id = ? OR (p.shop_owner_id IS NULL AND p.user_id = ?))';
    $params[] = $user_id;
    $params[] = $user_id;
    
    // Date range filter - use payment_date for actual transfer date, fallback to created_at
    $where_conditions[] = 'COALESCE(p.payment_date, p.created_at) >= ?';
    $params[] = $start_date . ' 00:00:00';
    
    $where_conditions[] = 'COALESCE(p.payment_date, p.created_at) <= ?';
    $params[] = $end_date . ' 23:59:59';
    
    // Payment type filter
    if ($payment_type !== 'all') {
        $where_conditions[] = 'p.payment_type = ?';
        $params[] = $payment_type;
    }
    
    // Status filter
    if ($status !== 'all') {
        $where_conditions[] = 'p.status = ?';
        $params[] = $status;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Generate GROUP BY expression based on period
    switch ($group_by) {
        case 'day':
            $date_format = '%Y-%m-%d';
            $date_label_format = '%d/%m/%Y';
            $group_expr = "DATE(COALESCE(p.payment_date, p.created_at))";
            break;
        case 'week':
            $date_format = '%x-W%v'; // ISO week
            $date_label_format = '%x สัปดาห์ที่ %v';
            $group_expr = "YEARWEEK(COALESCE(p.payment_date, p.created_at), 1)";
            break;
        case 'year':
            $date_format = '%Y';
            $date_label_format = 'ปี %Y';
            $group_expr = "YEAR(COALESCE(p.payment_date, p.created_at))";
            break;
        case 'month':
        default:
            $date_format = '%Y-%m';
            $date_label_format = '%m/%Y';
            $group_expr = "DATE_FORMAT(COALESCE(p.payment_date, p.created_at), '%Y-%m')";
            break;
    }
    
    // ========================================
    // 1. Get Summary Statistics (Overall)
    // ========================================
    $summary_sql = "
        SELECT 
            COUNT(*) as total_transactions,
            COALESCE(SUM(p.amount), 0) as total_amount,
            COALESCE(AVG(p.amount), 0) as avg_amount,
            COALESCE(MIN(p.amount), 0) as min_amount,
            COALESCE(MAX(p.amount), 0) as max_amount,
            COUNT(DISTINCT DATE(COALESCE(p.payment_date, p.created_at))) as active_days,
            COUNT(DISTINCT p.customer_id) as unique_customers
        FROM payments p
        WHERE $where_clause
    ";
    
    $stmt = $pdo->prepare($summary_sql);
    $stmt->execute($params);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate VAT if needed
    if ($include_vat) {
        $summary['vat_amount'] = round((float)$summary['total_amount'] * $vat_rate / (1 + $vat_rate), 2);
        $summary['amount_before_vat'] = round((float)$summary['total_amount'] / (1 + $vat_rate), 2);
    }
    
    // ========================================
    // 2. Get Summary by Payment Type
    // ========================================
    $type_summary_sql = "
        SELECT 
            p.payment_type,
            COUNT(*) as count,
            COALESCE(SUM(p.amount), 0) as total_amount
        FROM payments p
        WHERE $where_clause
        GROUP BY p.payment_type
        ORDER BY total_amount DESC
    ";
    
    $stmt = $pdo->prepare($type_summary_sql);
    $stmt->execute($params);
    $summary_by_type = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Map payment type to Thai labels
    $type_labels = [
        'full' => 'ชำระเต็ม',
        'installment' => 'ผ่อนชำระ',
        'deposit' => 'มัดจำ',
        'savings' => 'ออมเงิน',
        'deposit_interest' => 'ต่อดอกฝาก',
        'pawn_redemption' => 'ไถ่ถอนจำนำ'
    ];
    
    foreach ($summary_by_type as &$type) {
        $type['label'] = $type_labels[$type['payment_type']] ?? $type['payment_type'];
        $type['total_amount'] = (float) $type['total_amount'];
    }
    unset($type);
    
    // ========================================
    // 3. Get Aggregated Data by Period
    // ========================================
    $aggregated_sql = "
        SELECT 
            $group_expr as period_key,
            DATE_FORMAT(MIN(COALESCE(p.payment_date, p.created_at)), '$date_label_format') as period_label,
            MIN(DATE(COALESCE(p.payment_date, p.created_at))) as period_start,
            MAX(DATE(COALESCE(p.payment_date, p.created_at))) as period_end,
            COUNT(*) as transaction_count,
            COALESCE(SUM(p.amount), 0) as total_amount,
            -- By payment type
            COALESCE(SUM(CASE WHEN p.payment_type = 'full' THEN p.amount ELSE 0 END), 0) as full_amount,
            COALESCE(SUM(CASE WHEN p.payment_type = 'installment' THEN p.amount ELSE 0 END), 0) as installment_amount,
            COALESCE(SUM(CASE WHEN p.payment_type = 'deposit' THEN p.amount ELSE 0 END), 0) as deposit_amount,
            COALESCE(SUM(CASE WHEN p.payment_type = 'savings' THEN p.amount ELSE 0 END), 0) as savings_amount,
            COALESCE(SUM(CASE WHEN p.payment_type = 'deposit_interest' THEN p.amount ELSE 0 END), 0) as interest_amount,
            COALESCE(SUM(CASE WHEN p.payment_type = 'pawn_redemption' THEN p.amount ELSE 0 END), 0) as redemption_amount,
            -- Counts
            COUNT(CASE WHEN p.payment_type = 'full' THEN 1 END) as full_count,
            COUNT(CASE WHEN p.payment_type = 'installment' THEN 1 END) as installment_count,
            COUNT(DISTINCT p.customer_id) as unique_customers
        FROM payments p
        WHERE $where_clause
        GROUP BY $group_expr
        ORDER BY period_key DESC
    ";
    
    $stmt = $pdo->prepare($aggregated_sql);
    $stmt->execute($params);
    $aggregated_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate running totals (cumulative)
    $running_total = 0;
    foreach (array_reverse($aggregated_data) as $i => $row) {
        $running_total += (float) $row['total_amount'];
        $aggregated_data[count($aggregated_data) - 1 - $i]['running_total'] = $running_total;
    }
    
    // Add VAT calculation if needed
    if ($include_vat) {
        foreach ($aggregated_data as &$row) {
            $row['vat_amount'] = round((float)$row['total_amount'] * $vat_rate / (1 + $vat_rate), 2);
            $row['amount_before_vat'] = round((float)$row['total_amount'] / (1 + $vat_rate), 2);
        }
        unset($row);
    }
    
    // Pagination info for aggregated data
    $total_periods = count($aggregated_data);
    $aggregated_paginated = array_slice($aggregated_data, $offset, $limit);
    
    // ========================================
    // 4. Get Detail Transactions (if requested)
    // ========================================
    $include_details = isset($_GET['include_details']) && $_GET['include_details'] === 'true';
    $transactions = [];
    $transactions_total = 0;
    
    if ($include_details || $export) {
        // Count total transactions
        $count_sql = "
            SELECT COUNT(*) as total
            FROM payments p
            WHERE $where_clause
        ";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $transactions_total = (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // For export, get all data (with reasonable limit)
        $detail_limit = $export ? min($transactions_total, 10000) : $limit;
        $detail_offset = $export ? 0 : $offset;
        
        $details_sql = "
            SELECT 
                p.id,
                p.payment_no,
                COALESCE(p.payment_date, p.created_at) as payment_date,
                p.amount,
                p.payment_type,
                p.payment_method,
                p.status,
                p.order_id,
                p.pawn_id,
                p.repair_id,
                p.installment_period,
                p.current_period,
                -- Customer info: prioritize slip sender_name > address recipient_name > profile
                COALESCE(
                    NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(p.payment_details, '$.sender_name'))), ''),
                    NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(p.payment_details, '$.customer_name'))), ''),
                    NULLIF(TRIM(ca.recipient_name), ''),
                    NULLIF(TRIM(cp.full_name), ''),
                    cp.display_name,
                    'ไม่ระบุ'
                ) as customer_name,
                COALESCE(NULLIF(TRIM(ca.phone), ''), cp.phone) as customer_phone,
                -- OCR data
                JSON_UNQUOTE(JSON_EXTRACT(p.payment_details, '$.bank_name')) as bank_name,
                JSON_UNQUOTE(JSON_EXTRACT(p.payment_details, '$.payment_ref')) as payment_ref,
                JSON_UNQUOTE(JSON_EXTRACT(p.payment_details, '$.receiver_name')) as receiver_name,
                -- Related entities
                o.order_number,
                pw.pawn_no,
                r.repair_no
            FROM payments p
            LEFT JOIN customer_profiles cp ON 
                cp.platform_user_id = COALESCE(
                    p.platform_user_id, 
                    JSON_UNQUOTE(JSON_EXTRACT(p.payment_details, '$.external_user_id'))
                )
            LEFT JOIN customer_addresses ca ON 
                ca.platform_user_id = COALESCE(
                    p.platform_user_id, 
                    JSON_UNQUOTE(JSON_EXTRACT(p.payment_details, '$.external_user_id'))
                )
                AND ca.is_default = 1
            LEFT JOIN orders o ON o.id = p.order_id
            LEFT JOIN pawns pw ON pw.id = p.pawn_id
            LEFT JOIN repairs r ON r.id = p.repair_id
            WHERE $where_clause
            ORDER BY COALESCE(p.payment_date, p.created_at) DESC
            LIMIT ? OFFSET ?
        ";
        
        $detail_params = array_merge($params, [$detail_limit, $detail_offset]);
        $stmt = $pdo->prepare($details_sql);
        $stmt->execute($detail_params);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format amounts
        foreach ($transactions as &$tx) {
            $tx['amount'] = (float) $tx['amount'];
            $tx['payment_type_label'] = $type_labels[$tx['payment_type']] ?? $tx['payment_type'];
            
            // Generate reference based on type
            if ($tx['order_number']) {
                $tx['reference'] = $tx['order_number'];
                $tx['reference_type'] = 'order';
            } elseif ($tx['pawn_no']) {
                $tx['reference'] = $tx['pawn_no'];
                $tx['reference_type'] = 'pawn';
            } elseif ($tx['repair_no']) {
                $tx['reference'] = $tx['repair_no'];
                $tx['reference_type'] = 'repair';
            } else {
                $tx['reference'] = '-';
                $tx['reference_type'] = null;
            }
        }
        unset($tx);
    }
    
    // ========================================
    // 5. Year-to-Date Comparison
    // ========================================
    $current_year = date('Y');
    $ytd_sql = "
        SELECT 
            YEAR(COALESCE(p.payment_date, p.created_at)) as year,
            COALESCE(SUM(p.amount), 0) as total_amount,
            COUNT(*) as transaction_count
        FROM payments p
        WHERE (p.shop_owner_id = ? OR (p.shop_owner_id IS NULL AND p.user_id = ?))
          AND p.status = 'verified'
          AND (
              (YEAR(COALESCE(p.payment_date, p.created_at)) = ? AND DAYOFYEAR(COALESCE(p.payment_date, p.created_at)) <= DAYOFYEAR(CURDATE()))
              OR YEAR(COALESCE(p.payment_date, p.created_at)) = ? - 1
          )
        GROUP BY YEAR(COALESCE(p.payment_date, p.created_at))
        ORDER BY year DESC
    ";
    
    $stmt = $pdo->prepare($ytd_sql);
    $stmt->execute([$user_id, $user_id, $current_year, $current_year]);
    $ytd_comparison = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $ytd_data = [
        'current_year' => $current_year,
        'current_ytd' => 0,
        'previous_ytd' => 0,
        'growth_percent' => 0
    ];
    
    foreach ($ytd_comparison as $ytd) {
        if ($ytd['year'] == $current_year) {
            $ytd_data['current_ytd'] = (float) $ytd['total_amount'];
            $ytd_data['current_count'] = (int) $ytd['transaction_count'];
        } else {
            $ytd_data['previous_ytd'] = (float) $ytd['total_amount'];
            $ytd_data['previous_count'] = (int) $ytd['transaction_count'];
        }
    }
    
    if ($ytd_data['previous_ytd'] > 0) {
        $ytd_data['growth_percent'] = round(
            (($ytd_data['current_ytd'] - $ytd_data['previous_ytd']) / $ytd_data['previous_ytd']) * 100, 
            2
        );
    }
    
    // ========================================
    // Handle Export
    // ========================================
    if ($export === 'csv') {
        exportCSV($transactions, $summary, $start_date, $end_date);
        exit;
    }
    
    if ($export === 'excel') {
        exportExcel($transactions, $summary, $aggregated_data, $start_date, $end_date, $group_by);
        exit;
    }
    
    // ========================================
    // Build Response
    // ========================================
    $response = [
        'success' => true,
        'data' => [
            'report_info' => [
                'generated_at' => date('Y-m-d H:i:s'),
                'start_date' => $start_date,
                'end_date' => $end_date,
                'group_by' => $group_by,
                'payment_type_filter' => $payment_type,
                'status_filter' => $status,
                'include_vat' => $include_vat,
                'vat_rate' => $vat_rate
            ],
            'summary' => [
                'total_transactions' => (int) $summary['total_transactions'],
                'total_amount' => (float) $summary['total_amount'],
                'avg_amount' => round((float) $summary['avg_amount'], 2),
                'min_amount' => (float) $summary['min_amount'],
                'max_amount' => (float) $summary['max_amount'],
                'active_days' => (int) $summary['active_days'],
                'unique_customers' => (int) $summary['unique_customers'],
                'vat_amount' => $summary['vat_amount'] ?? null,
                'amount_before_vat' => $summary['amount_before_vat'] ?? null
            ],
            'summary_by_type' => $summary_by_type,
            'ytd_comparison' => $ytd_data,
            'aggregated' => [
                'data' => $aggregated_paginated,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total_records' => $total_periods,
                    'total_pages' => ceil($total_periods / $limit)
                ]
            ]
        ]
    ];
    
    // Include transaction details if requested
    if ($include_details) {
        $response['data']['transactions'] = [
            'data' => $transactions,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total_records' => $transactions_total,
                'total_pages' => ceil($transactions_total / $limit)
            ]
        ];
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    error_log("Income Report API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Database error',
        'error' => (defined('DEBUG') && DEBUG) ? $e->getMessage() : null
    ]);
}

// ========================================
// Export Functions
// ========================================

/**
 * Export to CSV (Thai-compatible with BOM)
 */
function exportCSV($transactions, $summary, $start_date, $end_date) {
    $filename = "income_report_{$start_date}_to_{$end_date}.csv";
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // UTF-8 BOM for Excel compatibility
    echo "\xEF\xBB\xBF";
    
    $output = fopen('php://output', 'w');
    
    // Report header
    fputcsv($output, ['รายงานรายรับ']);
    fputcsv($output, ['ช่วงเวลา:', $start_date, 'ถึง', $end_date]);
    fputcsv($output, ['สร้างเมื่อ:', date('d/m/Y H:i:s')]);
    fputcsv($output, []);
    
    // Summary
    fputcsv($output, ['สรุปรวม']);
    fputcsv($output, ['รายการทั้งหมด', $summary['total_transactions']]);
    fputcsv($output, ['ยอดรวม (บาท)', number_format((float)$summary['total_amount'], 2)]);
    fputcsv($output, ['ยอดเฉลี่ย (บาท)', number_format((float)$summary['avg_amount'], 2)]);
    fputcsv($output, []);
    
    // Column headers
    fputcsv($output, [
        'ลำดับ',
        'เลขที่ชำระ',
        'วันที่',
        'ลูกค้า',
        'ประเภท',
        'จำนวนเงิน (บาท)',
        'สถานะ',
        'เลขอ้างอิง',
        'ธนาคาร',
        'เลขอ้างอิงโอน'
    ]);
    
    // Data rows
    $type_labels = [
        'full' => 'ชำระเต็ม',
        'installment' => 'ผ่อนชำระ',
        'deposit' => 'มัดจำ',
        'savings' => 'ออมเงิน',
        'deposit_interest' => 'ต่อดอกฝาก',
        'pawn_redemption' => 'ไถ่ถอน'
    ];
    
    $status_labels = [
        'verified' => 'ยืนยันแล้ว',
        'pending' => 'รอตรวจสอบ',
        'rejected' => 'ปฏิเสธ'
    ];
    
    foreach ($transactions as $i => $tx) {
        fputcsv($output, [
            $i + 1,
            $tx['payment_no'],
            date('d/m/Y H:i', strtotime($tx['payment_date'])),
            $tx['customer_name'],
            $type_labels[$tx['payment_type']] ?? $tx['payment_type'],
            number_format((float)$tx['amount'], 2),
            $status_labels[$tx['status']] ?? $tx['status'],
            $tx['reference'] ?? '-',
            $tx['bank_name'] ?? '-',
            $tx['payment_ref'] ?? '-'
        ]);
    }
    
    // Footer totals
    fputcsv($output, []);
    fputcsv($output, ['', '', '', '', 'รวมทั้งหมด', number_format((float)$summary['total_amount'], 2)]);
    
    fclose($output);
}

/**
 * Export to Excel (using CSV with proper formatting for now)
 * For true Excel, install PhpSpreadsheet: composer require phpoffice/phpspreadsheet
 */
function exportExcel($transactions, $summary, $aggregated, $start_date, $end_date, $group_by) {
    // Check if PhpSpreadsheet is available
    $phpSpreadsheetPath = __DIR__ . '/../../../vendor/autoload.php';
    
    if (file_exists($phpSpreadsheetPath)) {
        require_once $phpSpreadsheetPath;
        
        if (class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            exportExcelWithPhpSpreadsheet($transactions, $summary, $aggregated, $start_date, $end_date, $group_by);
            return;
        }
    }
    
    // Fallback to CSV with .xlsx extension
    $filename = "income_report_{$start_date}_to_{$end_date}.xlsx";
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // Use CSV format as fallback
    echo "\xEF\xBB\xBF";
    
    $output = fopen('php://output', 'w');
    
    // Summary sheet simulation
    fputcsv($output, ['รายงานรายรับ - สรุป']);
    fputcsv($output, ['ช่วงเวลา:', $start_date, 'ถึง', $end_date]);
    fputcsv($output, []);
    
    // Aggregated data
    $period_labels = [
        'day' => 'วัน',
        'week' => 'สัปดาห์',
        'month' => 'เดือน',
        'year' => 'ปี'
    ];
    
    fputcsv($output, ['สรุปราย' . ($period_labels[$group_by] ?? $group_by)]);
    fputcsv($output, ['ช่วงเวลา', 'จำนวนรายการ', 'ยอดรวม', 'ชำระเต็ม', 'ผ่อนชำระ', 'มัดจำ', 'ออมเงิน', 'ยอดสะสม']);
    
    foreach ($aggregated as $row) {
        fputcsv($output, [
            $row['period_label'],
            $row['transaction_count'],
            number_format((float)$row['total_amount'], 2),
            number_format((float)$row['full_amount'], 2),
            number_format((float)$row['installment_amount'], 2),
            number_format((float)$row['deposit_amount'], 2),
            number_format((float)$row['savings_amount'], 2),
            number_format((float)$row['running_total'], 2)
        ]);
    }
    
    fputcsv($output, []);
    fputcsv($output, ['รายละเอียดรายการ']);
    
    // Headers
    fputcsv($output, [
        'ลำดับ', 'เลขที่ชำระ', 'วันที่', 'ลูกค้า', 'ประเภท', 'จำนวนเงิน', 'สถานะ', 'เลขอ้างอิง'
    ]);
    
    $type_labels = [
        'full' => 'ชำระเต็ม',
        'installment' => 'ผ่อนชำระ',
        'deposit' => 'มัดจำ',
        'savings' => 'ออมเงิน'
    ];
    
    foreach ($transactions as $i => $tx) {
        fputcsv($output, [
            $i + 1,
            $tx['payment_no'],
            date('d/m/Y H:i', strtotime($tx['payment_date'])),
            $tx['customer_name'],
            $type_labels[$tx['payment_type']] ?? $tx['payment_type'],
            number_format((float)$tx['amount'], 2),
            $tx['status'],
            $tx['reference'] ?? '-'
        ]);
    }
    
    fclose($output);
}

/**
 * Export with PhpSpreadsheet (if installed)
 * Note: This function will only work if PhpSpreadsheet is installed via composer
 */
function exportExcelWithPhpSpreadsheet($transactions, $summary, $aggregated, $start_date, $end_date, $group_by) {
    // PhpSpreadsheet classes (using fully qualified names instead of use statements)
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    
    // ======== Sheet 1: Summary ========
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('สรุปรายรับ');
    
    // Header
    $sheet->setCellValue('A1', 'รายงานรายรับ');
    $sheet->mergeCells('A1:F1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    
    $sheet->setCellValue('A2', 'ช่วงเวลา: ' . $start_date . ' ถึง ' . $end_date);
    $sheet->setCellValue('A3', 'สร้างเมื่อ: ' . date('d/m/Y H:i:s'));
    
    // Summary data
    $row = 5;
    $sheet->setCellValue('A' . $row, 'รายการทั้งหมด');
    $sheet->setCellValue('B' . $row, (int)$summary['total_transactions']);
    $row++;
    $sheet->setCellValue('A' . $row, 'ยอดรวม (บาท)');
    $sheet->setCellValue('B' . $row, (float)$summary['total_amount']);
    $sheet->getStyle('B' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
    
    // ======== Sheet 2: Aggregated ========
    $sheet2 = $spreadsheet->createSheet();
    $sheet2->setTitle('สรุปราย' . $group_by);
    
    // Headers
    $headers = ['ช่วงเวลา', 'จำนวนรายการ', 'ยอดรวม', 'ชำระเต็ม', 'ผ่อนชำระ', 'มัดจำ', 'ออมเงิน', 'ยอดสะสม'];
    foreach ($headers as $col => $header) {
        $sheet2->setCellValueByColumnAndRow($col + 1, 1, $header);
    }
    
    $row = 2;
    foreach ($aggregated as $data) {
        $sheet2->setCellValue('A' . $row, $data['period_label']);
        $sheet2->setCellValue('B' . $row, $data['transaction_count']);
        $sheet2->setCellValue('C' . $row, (float)$data['total_amount']);
        $sheet2->setCellValue('D' . $row, (float)$data['full_amount']);
        $sheet2->setCellValue('E' . $row, (float)$data['installment_amount']);
        $sheet2->setCellValue('F' . $row, (float)$data['deposit_amount']);
        $sheet2->setCellValue('G' . $row, (float)$data['savings_amount']);
        $sheet2->setCellValue('H' . $row, (float)$data['running_total']);
        $row++;
    }
    
    // Format numbers
    $sheet2->getStyle('C2:H' . ($row - 1))->getNumberFormat()->setFormatCode('#,##0.00');
    
    // ======== Sheet 3: Details ========
    $sheet3 = $spreadsheet->createSheet();
    $sheet3->setTitle('รายละเอียด');
    
    $headers = ['ลำดับ', 'เลขที่ชำระ', 'วันที่', 'ลูกค้า', 'ประเภท', 'จำนวนเงิน', 'สถานะ', 'เลขอ้างอิง'];
    foreach ($headers as $col => $header) {
        $sheet3->setCellValueByColumnAndRow($col + 1, 1, $header);
    }
    
    $type_labels = [
        'full' => 'ชำระเต็ม',
        'installment' => 'ผ่อนชำระ',
        'deposit' => 'มัดจำ',
        'savings' => 'ออมเงิน'
    ];
    
    $row = 2;
    foreach ($transactions as $i => $tx) {
        $sheet3->setCellValue('A' . $row, $i + 1);
        $sheet3->setCellValue('B' . $row, $tx['payment_no']);
        $sheet3->setCellValue('C' . $row, date('d/m/Y H:i', strtotime($tx['payment_date'])));
        $sheet3->setCellValue('D' . $row, $tx['customer_name']);
        $sheet3->setCellValue('E' . $row, $type_labels[$tx['payment_type']] ?? $tx['payment_type']);
        $sheet3->setCellValue('F' . $row, (float)$tx['amount']);
        $sheet3->setCellValue('G' . $row, $tx['status']);
        $sheet3->setCellValue('H' . $row, $tx['reference'] ?? '-');
        $row++;
    }
    
    $sheet3->getStyle('F2:F' . ($row - 1))->getNumberFormat()->setFormatCode('#,##0.00');
    
    // Output
    $filename = "income_report_{$start_date}_to_{$end_date}.xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
}
