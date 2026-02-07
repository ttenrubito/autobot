<?php
/**
 * Income Report - Customer Portal
 * รายงานรายรับสำหรับยื่นภาษี
 */
define('INCLUDE_CHECK', true);

$page_title = "รายงานรายรับ - AI Automation";
$current_page = "reports";

include('../../includes/customer/header.php');
include('../../includes/customer/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <div class="page-header-content">
            <div>
                <h1 class="page-title">📊 รายงานรายรับ</h1>
                <p class="page-subtitle">รายงานสำหรับสรุปรายรับและยื่นภาษี</p>
            </div>
            <div class="page-header-actions">
                <button class="btn btn-outline" onclick="exportReport('csv')" id="btnExportCSV">
                    <i class="fas fa-file-csv"></i> <span class="btn-text">CSV</span>
                </button>
                <button class="btn btn-success" onclick="exportReport('excel')" id="btnExportExcel">
                    <i class="fas fa-file-excel"></i> <span class="btn-text">Excel</span>
                </button>
                <button class="btn btn-primary" onclick="window.print()" id="btnPrint">
                    <i class="fas fa-print"></i> <span class="btn-text">พิมพ์</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Filter Panel -->
    <div class="filter-panel report-filters">
        <div class="filter-row">
            <!-- Quick Date Presets -->
            <div class="filter-group">
                <label class="filter-label">ช่วงเวลา</label>
                <div class="filter-chips">
                    <button class="filter-chip" data-preset="today" onclick="setDatePreset('today')">วันนี้</button>
                    <button class="filter-chip" data-preset="week" onclick="setDatePreset('week')">สัปดาห์นี้</button>
                    <button class="filter-chip active" data-preset="month" onclick="setDatePreset('month')">เดือนนี้</button>
                    <button class="filter-chip" data-preset="quarter" onclick="setDatePreset('quarter')">ไตรมาสนี้</button>
                    <button class="filter-chip" data-preset="year" onclick="setDatePreset('year')">ปีนี้</button>
                    <button class="filter-chip" data-preset="custom" onclick="setDatePreset('custom')">กำหนดเอง</button>
                </div>
            </div>
        </div>
        
        <div class="filter-row">
            <!-- Date Range -->
            <div class="filter-group">
                <label class="filter-label">ตั้งแต่</label>
                <input type="date" id="startDate" class="form-input" onchange="loadReport()">
            </div>
            <div class="filter-group">
                <label class="filter-label">ถึง</label>
                <input type="date" id="endDate" class="form-input" onchange="loadReport()">
            </div>
            
            <!-- Group By -->
            <div class="filter-group">
                <label class="filter-label">แสดงราย</label>
                <select id="groupBy" class="form-select" onchange="loadReport()">
                    <option value="day">รายวัน</option>
                    <option value="week">รายสัปดาห์</option>
                    <option value="month" selected>รายเดือน</option>
                    <option value="year">รายปี</option>
                </select>
            </div>
            
            <!-- Payment Type -->
            <div class="filter-group">
                <label class="filter-label">ประเภท</label>
                <select id="paymentType" class="form-select" onchange="loadReport()">
                    <option value="all">ทุกประเภท</option>
                    <option value="full">ชำระเต็ม</option>
                    <option value="installment">ผ่อนชำระ</option>
                    <option value="deposit">มัดจำ</option>
                    <option value="savings">ออมเงิน</option>
                    <option value="deposit_interest">ต่อดอกฝาก</option>
                    <option value="pawn_redemption">ไถ่ถอน</option>
                </select>
            </div>
            
            <!-- Status -->
            <div class="filter-group">
                <label class="filter-label">สถานะ</label>
                <select id="statusFilter" class="form-select" onchange="loadReport()">
                    <option value="verified" selected>ยืนยันแล้ว</option>
                    <option value="all">ทั้งหมด</option>
                    <option value="pending">รอตรวจสอบ</option>
                </select>
            </div>
            
            <!-- VAT Option -->
            <div class="filter-group">
                <label class="filter-label">
                    <input type="checkbox" id="includeVat" onchange="loadReport()"> รวม VAT 7%
                </label>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-grid report-summary">
        <div class="summary-card summary-card-primary">
            <div class="summary-icon">💰</div>
            <div class="summary-value" id="totalAmount">฿0</div>
            <div class="summary-label">รายรับรวม</div>
            <div class="summary-sub" id="totalAmountVat" style="display:none;">
                <span>ก่อน VAT: <span id="amountBeforeVat">฿0</span></span>
            </div>
        </div>
        <div class="summary-card summary-card-info">
            <div class="summary-icon">📝</div>
            <div class="summary-value" id="totalTransactions">0</div>
            <div class="summary-label">รายการทั้งหมด</div>
        </div>
        <div class="summary-card summary-card-success">
            <div class="summary-icon">📈</div>
            <div class="summary-value" id="avgAmount">฿0</div>
            <div class="summary-label">เฉลี่ยต่อรายการ</div>
        </div>
        <div class="summary-card">
            <div class="summary-icon">👥</div>
            <div class="summary-value" id="uniqueCustomers">0</div>
            <div class="summary-label">ลูกค้า</div>
        </div>
    </div>

    <!-- YTD Comparison -->
    <div class="card mt-4">
        <div class="card-header">
            <h3 class="card-title">📊 เปรียบเทียบปีต่อปี (YTD)</h3>
        </div>
        <div class="card-body">
            <div class="ytd-comparison">
                <div class="ytd-item">
                    <div class="ytd-year" id="currentYear">2026</div>
                    <div class="ytd-amount" id="currentYtd">฿0</div>
                    <div class="ytd-label">ปีนี้ (ถึงวันที่)</div>
                </div>
                <div class="ytd-arrow">
                    <span id="ytdGrowth" class="growth-badge positive">+0%</span>
                </div>
                <div class="ytd-item">
                    <div class="ytd-year" id="previousYear">2025</div>
                    <div class="ytd-amount muted" id="previousYtd">฿0</div>
                    <div class="ytd-label">ปีก่อน (ช่วงเดียวกัน)</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary by Type -->
    <div class="card mt-4">
        <div class="card-header">
            <h3 class="card-title">📋 สรุปตามประเภท</h3>
        </div>
        <div class="card-body">
            <div class="type-summary-grid" id="typeSummaryGrid">
                <!-- Dynamic content -->
            </div>
        </div>
    </div>

    <!-- Aggregated Chart & Table -->
    <div class="card mt-4">
        <div class="card-header">
            <h3 class="card-title">📈 กราฟรายรับ</h3>
        </div>
        <div class="card-body">
            <div class="chart-container" style="height: 300px;">
                <canvas id="incomeChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Aggregated Data Table -->
    <div class="card mt-4">
        <div class="card-header">
            <h3 class="card-title">📊 ตารางสรุป</h3>
            <button class="btn btn-sm btn-outline" onclick="toggleDetails()" id="btnToggleDetails">
                <i class="fas fa-list"></i> ดูรายละเอียด
            </button>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="report-table" id="aggregatedTable">
                    <thead>
                        <tr>
                            <th>ช่วงเวลา</th>
                            <th class="text-right">รายการ</th>
                            <th class="text-right">ชำระเต็ม</th>
                            <th class="text-right">ผ่อนชำระ</th>
                            <th class="text-right">มัดจำ</th>
                            <th class="text-right">ออมเงิน</th>
                            <th class="text-right">รวม</th>
                            <th class="text-right">สะสม</th>
                        </tr>
                    </thead>
                    <tbody id="aggregatedBody">
                        <tr>
                            <td colspan="8" class="text-center py-4">
                                <div class="spinner"></div>
                                กำลังโหลดข้อมูล...
                            </td>
                        </tr>
                    </tbody>
                    <tfoot id="aggregatedFooter">
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Detailed Transactions (Hidden by default) -->
    <div class="card mt-4" id="detailsCard" style="display: none;">
        <div class="card-header">
            <h3 class="card-title">📝 รายละเอียดรายการ</h3>
            <span class="badge" id="detailsCount">0 รายการ</span>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table class="report-table" id="detailsTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>เลขที่ชำระ</th>
                            <th>วันที่</th>
                            <th>ลูกค้า</th>
                            <th>ประเภท</th>
                            <th class="text-right">จำนวนเงิน</th>
                            <th>สถานะ</th>
                            <th>อ้างอิง</th>
                        </tr>
                    </thead>
                    <tbody id="detailsBody">
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <div id="detailsPagination" class="pagination-container"></div>
        </div>
    </div>
</main>

<!-- Print Styles -->
<style>
@media print {
    .sidebar, .mobile-menu-toggle, .mobile-overlay, 
    .page-header-actions, .filter-panel, 
    .btn, button, #detailsCard { 
        display: none !important; 
    }
    .main-content { 
        margin: 0 !important; 
        padding: 20px !important; 
    }
    .card { 
        break-inside: avoid; 
        box-shadow: none !important;
        border: 1px solid #ddd !important;
    }
    .summary-grid { 
        display: flex !important; 
        gap: 10px; 
    }
    .summary-card { 
        border: 1px solid #ddd !important; 
    }
}

/* Report Specific Styles */
.report-filters {
    background: var(--color-card);
    border-radius: 12px;
    padding: 1rem;
    margin-bottom: 1.5rem;
}

.report-filters .filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    align-items: flex-end;
    margin-bottom: 1rem;
}

.report-filters .filter-row:last-child {
    margin-bottom: 0;
}

.report-filters .filter-group {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.report-filters .filter-label {
    font-size: 0.75rem;
    color: var(--color-gray);
    font-weight: 500;
}

.report-filters .form-input,
.report-filters .form-select {
    padding: 0.5rem 0.75rem;
    border-radius: 8px;
    border: 1px solid var(--color-border);
    font-size: 0.875rem;
    min-width: 140px;
}

.filter-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.filter-chip {
    padding: 0.375rem 0.75rem;
    border-radius: 20px;
    border: 1px solid var(--color-border);
    background: transparent;
    font-size: 0.8rem;
    cursor: pointer;
    transition: all 0.2s;
}

.filter-chip:hover {
    border-color: var(--color-primary);
    color: var(--color-primary);
}

.filter-chip.active {
    background: var(--color-primary);
    color: white;
    border-color: var(--color-primary);
}

/* Summary Grid */
.report-summary {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}

@media (max-width: 768px) {
    .report-summary {
        grid-template-columns: repeat(2, 1fr);
    }
}

.summary-card {
    background: var(--color-card);
    border-radius: 12px;
    padding: 1.25rem;
    text-align: center;
}

.summary-card-primary {
    background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark, #1d4ed8));
    color: white;
}

.summary-card-primary .summary-label {
    color: rgba(255,255,255,0.8);
}

.summary-icon {
    font-size: 1.5rem;
    margin-bottom: 0.5rem;
}

.summary-value {
    font-size: 1.5rem;
    font-weight: 700;
}

.summary-label {
    font-size: 0.8rem;
    color: var(--color-gray);
    margin-top: 0.25rem;
}

.summary-sub {
    font-size: 0.7rem;
    margin-top: 0.5rem;
    opacity: 0.8;
}

/* YTD Comparison */
.ytd-comparison {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 2rem;
    padding: 1rem;
}

.ytd-item {
    text-align: center;
}

.ytd-year {
    font-size: 1rem;
    font-weight: 600;
    color: var(--color-gray);
}

.ytd-amount {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--color-text);
}

.ytd-amount.muted {
    color: var(--color-gray);
}

.ytd-label {
    font-size: 0.75rem;
    color: var(--color-gray);
}

.ytd-arrow {
    display: flex;
    flex-direction: column;
    align-items: center;
}

.growth-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-weight: 600;
    font-size: 0.9rem;
}

.growth-badge.positive {
    background: var(--color-success-bg, #dcfce7);
    color: var(--color-success, #16a34a);
}

.growth-badge.negative {
    background: var(--color-danger-bg, #fee2e2);
    color: var(--color-danger, #dc2626);
}

/* Type Summary Grid */
.type-summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
}

.type-summary-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem;
    background: var(--color-background);
    border-radius: 8px;
}

.type-summary-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
}

.type-summary-icon.full { background: #dbeafe; }
.type-summary-icon.installment { background: #fef3c7; }
.type-summary-icon.deposit { background: #ddd6fe; }
.type-summary-icon.savings { background: #dcfce7; }
.type-summary-icon.deposit_interest { background: #ffedd5; }
.type-summary-icon.pawn_redemption { background: #fce7f3; }

.type-summary-info {
    flex: 1;
}

.type-summary-label {
    font-size: 0.75rem;
    color: var(--color-gray);
}

.type-summary-value {
    font-size: 1rem;
    font-weight: 600;
}

.type-summary-count {
    font-size: 0.7rem;
    color: var(--color-gray);
}

/* Report Table */
.report-table {
    width: 100%;
    border-collapse: collapse;
}

.report-table th,
.report-table td {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--color-border);
    font-size: 0.875rem;
}

.report-table th {
    background: var(--color-background);
    font-weight: 600;
    text-align: left;
    white-space: nowrap;
}

.report-table .text-right {
    text-align: right;
}

.report-table tbody tr:hover {
    background: var(--color-background);
}

.report-table tfoot {
    font-weight: 600;
    background: var(--color-background);
}

/* Chart Container */
.chart-container {
    position: relative;
}

/* Responsive */
@media (max-width: 1024px) {
    .report-summary {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .type-summary-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .page-header-content {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
    }
    
    .page-header-actions {
        width: 100%;
        justify-content: flex-start;
        flex-wrap: wrap;
    }
    
    .page-header-actions .btn {
        flex: 1;
        min-width: 80px;
        padding: 0.5rem;
        font-size: 0.8rem;
    }
    
    .page-header-actions .btn .btn-text {
        display: none;
    }
    
    .report-summary {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .type-summary-grid {
        grid-template-columns: 1fr;
    }
    
    .summary-value {
        font-size: 1.25rem;
    }
    
    .ytd-amount {
        font-size: 1.25rem;
    }
    
    /* Table responsive - horizontal scroll */
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        margin: 0 -1rem;
        padding: 0 1rem;
    }
    
    .report-table {
        min-width: 600px;
    }
    
    .report-table th,
    .report-table td {
        padding: 0.5rem 0.75rem;
        font-size: 0.8rem;
    }
}

@media (max-width: 640px) {
    .report-filters .filter-row {
        flex-direction: column;
    }
    
    .report-filters .form-input,
    .report-filters .form-select {
        width: 100%;
    }
    
    .ytd-comparison {
        flex-direction: column;
        gap: 1rem;
    }
    
    .ytd-arrow {
        transform: rotate(90deg);
    }
    
    .report-summary {
        grid-template-columns: 1fr 1fr;
        gap: 0.5rem;
    }
    
    .summary-card {
        padding: 0.75rem;
    }
    
    .summary-icon {
        font-size: 1.25rem;
    }
    
    .summary-value {
        font-size: 1rem;
    }
    
    .summary-label {
        font-size: 0.7rem;
    }
    
    .filter-chips {
        justify-content: flex-start;
        overflow-x: auto;
        flex-wrap: nowrap;
        padding-bottom: 0.5rem;
    }
    
    .filter-chip {
        flex-shrink: 0;
    }
    
    .card-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
    }
    
    .card-header .btn {
        width: 100%;
    }
}

@media (max-width: 480px) {
    .page-title {
        font-size: 1.25rem;
    }
    
    .page-subtitle {
        font-size: 0.8rem;
    }
    
    .summary-card-primary .summary-value {
        font-size: 1.1rem;
    }
    
    .chart-container {
        height: 200px !important;
    }
}

/* Pagination */
.pagination-container {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 0.5rem;
    padding: 1rem;
    margin-top: 1rem;
}

.btn-pagination {
    padding: 0.5rem 1rem;
    border: 1px solid var(--color-border);
    background: var(--color-card);
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-pagination:hover:not([disabled]) {
    background: var(--color-background);
    border-color: var(--color-primary);
}

.btn-pagination[disabled] {
    opacity: 0.5;
    cursor: not-allowed;
}

.page-indicator {
    padding: 0.5rem 1rem;
    color: var(--color-gray);
    font-size: 0.9rem;
}

/* Status Badge */
.status-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 500;
}

.status-badge.verified {
    background: #dcfce7;
    color: #16a34a;
}

.status-badge.pending {
    background: #fef3c7;
    color: #d97706;
}

.status-badge.rejected {
    background: #fee2e2;
    color: #dc2626;
}

.mt-4 { margin-top: 1.5rem; }
.py-4 { padding-top: 1rem; padding-bottom: 1rem; }
.text-center { text-align: center; }
</style>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
// ========================================
// Global State
// ========================================
let reportData = null;
let incomeChart = null;
let detailsPage = 1;
let showDetails = false;

// ========================================
// Date Presets
// ========================================
function setDatePreset(preset) {
    const today = new Date();
    let startDate, endDate;
    
    switch (preset) {
        case 'today':
            startDate = endDate = formatDate(today);
            break;
        case 'week':
            const weekStart = new Date(today);
            weekStart.setDate(today.getDate() - today.getDay());
            startDate = formatDate(weekStart);
            endDate = formatDate(today);
            break;
        case 'month':
            startDate = formatDate(new Date(today.getFullYear(), today.getMonth(), 1));
            endDate = formatDate(today);
            break;
        case 'quarter':
            const quarter = Math.floor(today.getMonth() / 3);
            startDate = formatDate(new Date(today.getFullYear(), quarter * 3, 1));
            endDate = formatDate(today);
            break;
        case 'year':
            startDate = formatDate(new Date(today.getFullYear(), 0, 1));
            endDate = formatDate(today);
            break;
        case 'custom':
            // Don't change dates, just enable editing
            break;
    }
    
    // Update active chip
    document.querySelectorAll('.filter-chip[data-preset]').forEach(chip => {
        chip.classList.toggle('active', chip.dataset.preset === preset);
    });
    
    if (preset !== 'custom') {
        document.getElementById('startDate').value = startDate;
        document.getElementById('endDate').value = endDate;
        loadReport();
    }
}

function formatDate(date) {
    return date.toISOString().split('T')[0];
}

function formatCurrency(amount) {
    return '฿' + Number(amount || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatNumber(num) {
    return Number(num || 0).toLocaleString('th-TH');
}

// ========================================
// Load Report Data
// ========================================
async function loadReport() {
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    const groupBy = document.getElementById('groupBy').value;
    const paymentType = document.getElementById('paymentType').value;
    const status = document.getElementById('statusFilter').value;
    const includeVat = document.getElementById('includeVat').checked;
    
    if (!startDate || !endDate) {
        console.warn('Missing date range');
        return;
    }
    
    const params = new URLSearchParams({
        start_date: startDate,
        end_date: endDate,
        group_by: groupBy,
        payment_type: paymentType,
        status: status,
        include_vat: includeVat ? 'true' : 'false',
        include_details: showDetails ? 'true' : 'false',
        page: detailsPage,
        limit: 50
    });
    
    try {
        const apiUrl = PATH.api('api/customer/reports/income.php');
        const response = await fetch(`${apiUrl}?${params}`, {
            headers: {
                'Authorization': `Bearer ${getAuthToken()}`
            }
        });
        
        const result = await response.json();
        
        if (result.success) {
            reportData = result.data;
            renderSummary(result.data.summary);
            renderTypeSummary(result.data.summary_by_type);
            renderYTD(result.data.ytd_comparison);
            renderAggregatedTable(result.data.aggregated);
            renderChart(result.data.aggregated.data);
            
            if (showDetails && result.data.transactions) {
                renderDetails(result.data.transactions);
            }
        } else {
            console.error('Report API error:', result.message);
            showToast('เกิดข้อผิดพลาดในการโหลดรายงาน', 'error');
        }
    } catch (error) {
        console.error('Failed to load report:', error);
        showToast('ไม่สามารถโหลดรายงานได้', 'error');
    }
}

// ========================================
// Render Functions
// ========================================
function renderSummary(summary) {
    document.getElementById('totalAmount').textContent = formatCurrency(summary.total_amount);
    document.getElementById('totalTransactions').textContent = formatNumber(summary.total_transactions);
    document.getElementById('avgAmount').textContent = formatCurrency(summary.avg_amount);
    document.getElementById('uniqueCustomers').textContent = formatNumber(summary.unique_customers);
    
    // VAT info
    const vatEl = document.getElementById('totalAmountVat');
    const beforeVatEl = document.getElementById('amountBeforeVat');
    
    if (summary.amount_before_vat) {
        vatEl.style.display = 'block';
        beforeVatEl.textContent = formatCurrency(summary.amount_before_vat);
    } else {
        vatEl.style.display = 'none';
    }
}

function renderTypeSummary(types) {
    const container = document.getElementById('typeSummaryGrid');
    const icons = {
        full: '💳',
        installment: '📅',
        deposit: '🏦',
        savings: '🐷',
        deposit_interest: '💵',
        pawn_redemption: '🔄'
    };
    
    container.innerHTML = types.map(type => `
        <div class="type-summary-item">
            <div class="type-summary-icon ${type.payment_type}">
                ${icons[type.payment_type] || '💰'}
            </div>
            <div class="type-summary-info">
                <div class="type-summary-label">${type.label}</div>
                <div class="type-summary-value">${formatCurrency(type.total_amount)}</div>
                <div class="type-summary-count">${formatNumber(type.count)} รายการ</div>
            </div>
        </div>
    `).join('');
}

function renderYTD(ytd) {
    document.getElementById('currentYear').textContent = ytd.current_year;
    document.getElementById('previousYear').textContent = ytd.current_year - 1;
    document.getElementById('currentYtd').textContent = formatCurrency(ytd.current_ytd);
    document.getElementById('previousYtd').textContent = formatCurrency(ytd.previous_ytd);
    
    const growthEl = document.getElementById('ytdGrowth');
    const growth = ytd.growth_percent || 0;
    growthEl.textContent = (growth >= 0 ? '+' : '') + growth.toFixed(1) + '%';
    growthEl.className = 'growth-badge ' + (growth >= 0 ? 'positive' : 'negative');
}

function renderAggregatedTable(aggregated) {
    const tbody = document.getElementById('aggregatedBody');
    const tfoot = document.getElementById('aggregatedFooter');
    
    if (!aggregated.data || aggregated.data.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="8" class="text-center py-4">
                    ไม่พบข้อมูลในช่วงเวลาที่เลือก
                </td>
            </tr>
        `;
        tfoot.innerHTML = '';
        return;
    }
    
    // Calculate totals
    let totals = {
        count: 0,
        full: 0,
        installment: 0,
        deposit: 0,
        savings: 0,
        total: 0
    };
    
    tbody.innerHTML = aggregated.data.map(row => {
        totals.count += parseInt(row.transaction_count) || 0;
        totals.full += parseFloat(row.full_amount) || 0;
        totals.installment += parseFloat(row.installment_amount) || 0;
        totals.deposit += parseFloat(row.deposit_amount) || 0;
        totals.savings += parseFloat(row.savings_amount) || 0;
        totals.total += parseFloat(row.total_amount) || 0;
        
        return `
            <tr>
                <td>${row.period_label}</td>
                <td class="text-right">${formatNumber(row.transaction_count)}</td>
                <td class="text-right">${formatCurrency(row.full_amount)}</td>
                <td class="text-right">${formatCurrency(row.installment_amount)}</td>
                <td class="text-right">${formatCurrency(row.deposit_amount)}</td>
                <td class="text-right">${formatCurrency(row.savings_amount)}</td>
                <td class="text-right"><strong>${formatCurrency(row.total_amount)}</strong></td>
                <td class="text-right">${formatCurrency(row.running_total)}</td>
            </tr>
        `;
    }).join('');
    
    tfoot.innerHTML = `
        <tr>
            <td><strong>รวมทั้งหมด</strong></td>
            <td class="text-right"><strong>${formatNumber(totals.count)}</strong></td>
            <td class="text-right"><strong>${formatCurrency(totals.full)}</strong></td>
            <td class="text-right"><strong>${formatCurrency(totals.installment)}</strong></td>
            <td class="text-right"><strong>${formatCurrency(totals.deposit)}</strong></td>
            <td class="text-right"><strong>${formatCurrency(totals.savings)}</strong></td>
            <td class="text-right"><strong>${formatCurrency(totals.total)}</strong></td>
            <td class="text-right">-</td>
        </tr>
    `;
}

function renderChart(data) {
    const ctx = document.getElementById('incomeChart').getContext('2d');
    
    // Reverse to show oldest first
    const chartData = [...data].reverse();
    
    // Destroy existing chart
    if (incomeChart) {
        incomeChart.destroy();
    }
    
    incomeChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: chartData.map(d => d.period_label),
            datasets: [
                {
                    label: 'ชำระเต็ม',
                    data: chartData.map(d => parseFloat(d.full_amount) || 0),
                    backgroundColor: 'rgba(59, 130, 246, 0.8)',
                    borderRadius: 4
                },
                {
                    label: 'ผ่อนชำระ',
                    data: chartData.map(d => parseFloat(d.installment_amount) || 0),
                    backgroundColor: 'rgba(245, 158, 11, 0.8)',
                    borderRadius: 4
                },
                {
                    label: 'มัดจำ',
                    data: chartData.map(d => parseFloat(d.deposit_amount) || 0),
                    backgroundColor: 'rgba(139, 92, 246, 0.8)',
                    borderRadius: 4
                },
                {
                    label: 'ออมเงิน',
                    data: chartData.map(d => parseFloat(d.savings_amount) || 0),
                    backgroundColor: 'rgba(34, 197, 94, 0.8)',
                    borderRadius: 4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + formatCurrency(context.parsed.y);
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: true
                },
                y: {
                    stacked: true,
                    ticks: {
                        callback: function(value) {
                            return '฿' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });
}

function renderDetails(transactions) {
    const tbody = document.getElementById('detailsBody');
    const countEl = document.getElementById('detailsCount');
    
    countEl.textContent = `${formatNumber(transactions.pagination.total_records)} รายการ`;
    
    const statusLabels = {
        verified: { text: 'ยืนยัน', class: 'verified' },
        pending: { text: 'รอตรวจ', class: 'pending' },
        rejected: { text: 'ปฏิเสธ', class: 'rejected' }
    };
    
    tbody.innerHTML = transactions.data.map((tx, i) => {
        const status = statusLabels[tx.status] || { text: tx.status, class: '' };
        return `
            <tr>
                <td>${(transactions.pagination.current_page - 1) * 50 + i + 1}</td>
                <td>${tx.payment_no}</td>
                <td>${formatDateTime(tx.payment_date)}</td>
                <td>${tx.customer_name || '-'}</td>
                <td>${tx.payment_type_label || tx.payment_type}</td>
                <td class="text-right">${formatCurrency(tx.amount)}</td>
                <td><span class="status-badge ${status.class}">${status.text}</span></td>
                <td>${tx.reference || '-'}</td>
            </tr>
        `;
    }).join('');
    
    // Pagination
    renderPagination(transactions.pagination, 'detailsPagination', (page) => {
        detailsPage = page;
        loadReport();
    });
}

function renderPagination(pagination, containerId, onPageChange) {
    const container = document.getElementById(containerId);
    
    if (pagination.total_pages <= 1) {
        container.innerHTML = '';
        return;
    }
    
    container.innerHTML = `
        <button class="btn-pagination" ${pagination.current_page <= 1 ? 'disabled' : ''} 
                onclick="(${onPageChange.toString()})(${pagination.current_page - 1})">
            <i class="fas fa-chevron-left"></i>
        </button>
        <span class="page-indicator">
            หน้า ${pagination.current_page} / ${pagination.total_pages}
        </span>
        <button class="btn-pagination" ${pagination.current_page >= pagination.total_pages ? 'disabled' : ''}
                onclick="(${onPageChange.toString()})(${pagination.current_page + 1})">
            <i class="fas fa-chevron-right"></i>
        </button>
    `;
}

function formatDateTime(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    return date.toLocaleDateString('th-TH', { 
        day: '2-digit', 
        month: '2-digit', 
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// ========================================
// Toggle Details
// ========================================
function toggleDetails() {
    showDetails = !showDetails;
    const card = document.getElementById('detailsCard');
    const btn = document.getElementById('btnToggleDetails');
    
    if (showDetails) {
        card.style.display = 'block';
        btn.innerHTML = '<i class="fas fa-times"></i> ซ่อนรายละเอียด';
        loadReport();
    } else {
        card.style.display = 'none';
        btn.innerHTML = '<i class="fas fa-list"></i> ดูรายละเอียด';
    }
}

// ========================================
// Export Functions
// ========================================
async function exportReport(format) {
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    const groupBy = document.getElementById('groupBy').value;
    const paymentType = document.getElementById('paymentType').value;
    const status = document.getElementById('statusFilter').value;
    
    const params = new URLSearchParams({
        start_date: startDate,
        end_date: endDate,
        group_by: groupBy,
        payment_type: paymentType,
        status: status,
        export: format,
        include_details: 'true'
    });
    
    // Open download in new window/tab
    const apiUrl = PATH.api('api/customer/reports/income.php');
    const url = `${apiUrl}?${params}`;
    
    try {
        const response = await fetch(url, {
            headers: {
                'Authorization': `Bearer ${getAuthToken()}`
            }
        });
        
        if (response.ok) {
            const blob = await response.blob();
            const downloadUrl = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = downloadUrl;
            a.download = `income_report_${startDate}_to_${endDate}.${format === 'excel' ? 'xlsx' : format}`;
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(downloadUrl);
            a.remove();
            showToast('ดาวน์โหลดรายงานสำเร็จ', 'success');
        } else {
            showToast('ไม่สามารถดาวน์โหลดรายงานได้', 'error');
        }
    } catch (error) {
        console.error('Export error:', error);
        showToast('เกิดข้อผิดพลาดในการดาวน์โหลด', 'error');
    }
}

// ========================================
// Toast Notification
// ========================================
function showToast(message, type = 'info') {
    // Simple toast - can be enhanced with a proper toast library
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    toast.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        padding: 1rem 1.5rem;
        border-radius: 8px;
        background: ${type === 'success' ? '#16a34a' : type === 'error' ? '#dc2626' : '#3b82f6'};
        color: white;
        font-weight: 500;
        z-index: 10000;
        animation: slideIn 0.3s ease;
    `;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// ========================================
// Initialize
// ========================================
document.addEventListener('DOMContentLoaded', function() {
    // Set default date to current month
    setDatePreset('month');
});
</script>

<?php include('../../includes/customer/footer.php'); ?>
