<?php
/**
 * Savings - Customer Portal
 * ระบบออมเงินสำหรับลูกค้า
 */
define('INCLUDE_CHECK', true);

$page_title = "ออมเงิน - AI Automation";
$current_page = "savings";

include('../includes/customer/header.php');
include('../includes/customer/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <div class="page-header-content">
            <div>
                <h1 class="page-title">🐷 ออมเงิน</h1>
                <p class="page-subtitle">จัดการบัญชีออมเงินและเป้าหมายการออม</p>
            </div>
            <button class="btn btn-primary" onclick="openCreateSavingsModal()">
                <i class="fas fa-plus"></i> <span class="btn-text">สร้างเป้าหมายใหม่</span>
            </button>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-grid">
        <div class="summary-card summary-card-success">
            <div class="summary-icon">💰</div>
            <div class="summary-value" id="totalSaved">฿0.00</div>
            <div class="summary-label">ยอดออมรวม</div>
        </div>
        <div class="summary-card summary-card-info">
            <div class="summary-icon">🎯</div>
            <div class="summary-value" id="totalGoal">฿0.00</div>
            <div class="summary-label">เป้าหมายรวม</div>
        </div>
        <div class="summary-card">
            <div class="summary-icon">📊</div>
            <div class="summary-value" id="savingsProgress">0%</div>
            <div class="summary-label">ความคืบหน้า</div>
        </div>
        <div class="summary-card">
            <div class="summary-icon">📋</div>
            <div class="summary-value" id="activeGoals">0</div>
            <div class="summary-label">เป้าหมายที่ใช้งาน</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">รายการเป้าหมายออมเงิน</h3>
        </div>
        <div class="card-body">
            <!-- Desktop Table -->
            <div class="table-container desktop-only">
                <table>
                    <thead>
                        <tr>
                            <th>ลูกค้า</th>
                            <th>ชื่อเป้าหมาย</th>
                            <th style="text-align:right;">เป้าหมาย</th>
                            <th style="text-align:right;">ออมแล้ว</th>
                            <th>ความคืบหน้า</th>
                            <th>สถานะ</th>
                            <th>วันที่เป้าหมาย</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="savingsTableBody">
                        <tr>
                            <td colspan="8" style="text-align:center;padding:2rem;">
                                <div class="spinner" style="margin:0 auto 1rem;"></div>
                                กำลังโหลดข้อมูล...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Mobile Cards -->
            <div class="mobile-cards mobile-only" id="savingsMobileCards">
                <div class="loading-placeholder">
                    <div class="spinner"></div>
                    <p>กำลังโหลดข้อมูล...</p>
                </div>
            </div>
            
            <!-- Pagination -->
            <div id="savingsPagination" class="pagination-container"></div>
        </div>
    </div>
</main>

<style>
/* Page Header */
.page-header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    width: 100%;
    flex-wrap: wrap;
    gap: 1rem;
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
    border: 1px solid #e5e7eb;
    background: white;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
}
.btn-pagination:hover:not([disabled]) {
    background: #f3f4f6;
    border-color: #3b82f6;
}
.btn-pagination[disabled] {
    opacity: 0.5;
    cursor: not-allowed;
}
.page-indicator {
    padding: 0.5rem 1rem;
    color: #6b7280;
    font-size: 0.9rem;
}

/* Summary Grid */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.summary-card {
    background: var(--color-white);
    border-radius: 16px;
    padding: 1.25rem;
    text-align: center;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    transition: transform 0.2s, box-shadow 0.2s;
}

.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(0,0,0,0.1);
}

.summary-icon {
    font-size: 2rem;
    margin-bottom: 0.5rem;
}

.summary-value {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 0.25rem;
}

.summary-label {
    font-size: 0.85rem;
    color: var(--color-gray);
}

.summary-card-success .summary-value { color: var(--color-success); }
.summary-card-warning .summary-value { color: var(--color-warning); }
.summary-card-danger .summary-value { color: var(--color-danger); }
.summary-card-info .summary-value { color: var(--color-info); }

/* Mobile Cards */
.mobile-cards {
    display: none;
}

.mobile-card {
    background: var(--color-white);
    border: 1px solid var(--color-border);
    border-radius: 12px;
    padding: 1rem;
    margin-bottom: 1rem;
}

.mobile-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.75rem;
}

.mobile-card-title {
    font-weight: 600;
    font-size: 1rem;
    color: var(--color-dark);
}

.mobile-card-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid var(--color-light-2);
    font-size: 0.9rem;
}

.mobile-card-row:last-child {
    border-bottom: none;
}

.mobile-card-label {
    color: var(--color-gray);
}

.mobile-card-value {
    font-weight: 500;
}

.mobile-card-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid var(--color-light-2);
}

.mobile-card-actions .btn {
    flex: 1;
    justify-content: center;
}

/* Progress Bar */
.progress-bar-container {
    background: var(--color-light-2);
    border-radius: 8px;
    height: 8px;
    overflow: hidden;
    margin-top: 0.5rem;
}

.progress-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--color-primary), var(--color-success));
    border-radius: 8px;
    transition: width 0.3s ease;
}

/* Loading */
.loading-placeholder {
    text-align: center;
    padding: 2rem;
    color: var(--color-gray);
}

.empty-state {
    text-align: center;
    padding: 2rem;
    color: var(--color-gray);
}

/* Desktop Only / Mobile Only */
.desktop-only { display: block; }
.mobile-only { display: none; }

/* Responsive */
@media (max-width: 1024px) {
    .summary-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .page-header-content {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .page-header-content .btn {
        width: 100%;
        justify-content: center;
    }
    
    .summary-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 0.75rem;
    }
    
    .summary-card {
        padding: 1rem;
    }
    
    .summary-icon {
        font-size: 1.5rem;
    }
    
    .summary-value {
        font-size: 1.25rem;
    }
    
    .desktop-only { display: none !important; }
    .mobile-only { display: block !important; }
}

@media (max-width: 480px) {
    .summary-value {
        font-size: 1.1rem;
    }
    
    .summary-label {
        font-size: 0.75rem;
    }
}
</style>

<!-- Create Savings Goal Modal -->
<div id="createSavingsModal" class="modal-backdrop hidden">
    <div class="modal-content">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-piggy-bank"></i> สร้างเป้าหมายออมเงิน</h3>
                <button class="modal-close-btn" onclick="closeCreateSavingsModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="card-body">
                <form id="createSavingsForm">
                    <div class="form-group">
                        <label class="form-label">ชื่อเป้าหมาย <span style="color: red;">*</span></label>
                        <input type="text" id="savingsName" class="form-control" required placeholder="เช่น ซื้อ iPhone, ท่องเที่ยว">
                    </div>
                    <div class="form-group">
                        <label class="form-label">จำนวนเงินเป้าหมาย <span style="color: red;">*</span></label>
                        <input type="number" id="savingsGoal" class="form-control" required placeholder="10000" min="1" step="0.01">
                    </div>
                    <div class="form-group">
                        <label class="form-label">วันที่เป้าหมาย</label>
                        <input type="date" id="savingsTargetDate" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">หมายเหตุ</label>
                        <textarea id="savingsNote" class="form-control" rows="2" placeholder="หมายเหตุเพิ่มเติม"></textarea>
                    </div>
                    <div id="createSavingsError" class="alert alert-danger" style="display: none;"></div>
                    <div id="createSavingsSuccess" class="alert alert-success" style="display: none;"></div>
                    <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                        <button type="button" class="btn btn-primary" style="flex: 1;" onclick="submitCreateSavings()">
                            <i class="fas fa-save"></i> สร้างเป้าหมาย
                        </button>
                        <button type="button" class="btn btn-outline" style="flex: 1;" onclick="closeCreateSavingsModal()">
                            <i class="fas fa-times"></i> ยกเลิก
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Add Deposit Modal -->
<div id="depositModal" class="modal-backdrop hidden">
    <div class="modal-content">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-plus-circle"></i> เพิ่มเงินออม</h3>
                <button class="modal-close-btn" onclick="closeDepositModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="card-body">
                <div class="alert alert-info" style="margin-bottom: 1rem; font-size: 0.9rem;">
                    <i class="fas fa-info-circle"></i> <strong>วิธีการฝากเงิน:</strong><br>
                    1. กรอกจำนวนเงินที่ต้องการฝาก<br>
                    2. โอนเงินไปยังบัญชีร้าน (แสดงหลังกด "เพิ่มเงิน")<br>
                    3. รอ Admin ตรวจสอบและอนุมัติ ยอดจะถูกเพิ่มอัตโนมัติ
                </div>
                <input type="hidden" id="depositSavingsId">
                <div style="margin-bottom: 1rem;">
                    <strong>เป้าหมาย:</strong> <span id="depositSavingsName"></span>
                </div>
                <div class="form-group">
                    <label class="form-label">จำนวนเงิน (บาท) <span style="color: red;">*</span></label>
                    <input type="number" id="depositAmount" class="form-control" required placeholder="5000" min="1" step="1">
                </div>
                <div class="form-group">
                    <label class="form-label">หมายเหตุ (ไม่บังคับ)</label>
                    <input type="text" id="depositNote" class="form-control" placeholder="เช่น โอนผ่าน SCB, PromptPay">
                </div>
                <div id="depositError" class="alert alert-danger" style="display: none;"></div>
                <div id="depositSuccess" class="alert alert-success" style="display: none;"></div>
                <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                    <button type="button" class="btn btn-success" style="flex: 1;" onclick="submitDeposit()">
                        <i class="fas fa-save"></i> บันทึกยอดฝาก
                    </button>
                    <button type="button" class="btn btn-outline" style="flex: 1;" onclick="closeDepositModal()">
                        <i class="fas fa-times"></i> ยกเลิก
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Savings Detail Modal -->
<div id="savingsDetailModal" class="modal-backdrop hidden">
    <div class="modal-content modal-lg">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-piggy-bank"></i> รายละเอียดบัญชีออม</h3>
                <button class="modal-close-btn" onclick="closeSavingsDetailModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="card-body" id="savingsDetailBody">
                <div class="loading-placeholder">
                    <div class="spinner"></div>
                    <p>กำลังโหลดข้อมูล...</p>
                </div>
            </div>
            <div class="card-footer" style="display: flex; gap: 1rem; justify-content: flex-end;">
                <button class="btn btn-success" id="btnAddDeposit" onclick="depositFromDetail()">
                    <i class="fas fa-plus"></i> เพิ่มเงินออม
                </button>
                <button class="btn btn-outline" onclick="closeSavingsDetailModal()">
                    <i class="fas fa-times"></i> ปิด
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.spinner {
    width: 48px;
    height: 48px;
    border: 4px solid var(--color-border);
    border-top-color: var(--color-primary);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.progress-bar {
    height: 8px;
    background: var(--color-border);
    border-radius: 4px;
    overflow: hidden;
    min-width: 100px;
}

.progress-fill {
    height: 100%;
    background: var(--color-success);
    transition: width 0.3s ease;
}

.badge-active { background: #dcfce7; color: #16a34a; }
.badge-completed { background: #dbeafe; color: #2563eb; }
.badge-paused { background: #fef3c7; color: #d97706; }

/* Large Modal - Extra Wide */
.modal-lg {
    max-width: 1100px;
    width: 95%;
}

/* Savings Detail Styles */
.savings-detail-header {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    margin-bottom: 2rem;
    padding-bottom: 1.5rem;
    border-bottom: 1px solid var(--color-border);
}

.savings-info-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 16px;
    padding: 1.5rem;
}

.savings-info-card h4 {
    font-size: 1.1rem;
    margin-bottom: 0.5rem;
    opacity: 0.9;
}

.savings-info-card .amount-big {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 1rem;
}

.savings-info-card .progress-row {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.savings-info-card .progress-bar-container {
    flex: 1;
    background: rgba(255,255,255,0.3);
    height: 10px;
}

.savings-info-card .progress-bar-fill {
    background: white;
}

.savings-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

.stat-item {
    background: var(--color-light);
    border-radius: 12px;
    padding: 1rem;
    text-align: center;
}

.stat-item .stat-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--color-dark);
}

.stat-item .stat-label {
    font-size: 0.8rem;
    color: var(--color-gray);
    margin-top: 0.25rem;
}

.stat-item.pending .stat-value { color: #f59e0b; }
.stat-item.success .stat-value { color: #10b981; }

/* Transaction Table */
.transaction-section h4 {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 1rem;
    color: var(--color-dark);
}

.transaction-table {
    width: 100%;
    border-collapse: collapse;
}

.transaction-table th,
.transaction-table td {
    padding: 0.75rem 1rem;
    text-align: left;
    border-bottom: 1px solid var(--color-border);
}

.transaction-table th {
    background: var(--color-light);
    font-weight: 600;
    font-size: 0.85rem;
    color: var(--color-gray);
}

.transaction-table tr:hover {
    background: var(--color-light);
}

.transaction-table .amount {
    font-weight: 600;
    color: #10b981;
}

.transaction-table .amount.negative {
    color: #ef4444;
}

/* Transaction Status Badges */
.tx-status {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 500;
}

.tx-status.pending {
    background: #fef3c7;
    color: #b45309;
}

.tx-status.verified {
    background: #d1fae5;
    color: #047857;
}

.tx-status.rejected {
    background: #fee2e2;
    color: #b91c1c;
}

/* Mobile Transaction Cards */
.tx-mobile-cards {
    display: none;
}

.tx-mobile-card {
    background: white;
    border: 1px solid var(--color-border);
    border-radius: 12px;
    padding: 1rem;
    margin-bottom: 0.75rem;
}

.tx-mobile-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.75rem;
}

.tx-mobile-amount {
    font-size: 1.25rem;
    font-weight: 700;
    color: #10b981;
}

.tx-mobile-meta {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.5rem;
    font-size: 0.85rem;
}

.tx-mobile-meta span {
    color: var(--color-gray);
}

.tx-mobile-meta strong {
    color: var(--color-dark);
}

/* Empty State */
.empty-transactions {
    text-align: center;
    padding: 2rem;
    color: var(--color-gray);
}

.empty-transactions i {
    font-size: 3rem;
    opacity: 0.3;
    margin-bottom: 1rem;
}

/* Reference Banner */
.account-ref-banner {
    display: flex;
    align-items: center;
    gap: 1rem;
    background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
    border: 1px solid #bae6fd;
    border-radius: 12px;
    padding: 1rem 1.5rem;
    margin-bottom: 1.5rem;
}

.account-ref-banner .ref-icon {
    font-size: 2rem;
}

.account-ref-banner .ref-info {
    flex: 0 0 auto;
}

.account-ref-banner .ref-label {
    font-size: 0.75rem;
    color: #0369a1;
    text-transform: uppercase;
    font-weight: 600;
    letter-spacing: 0.5px;
}

.account-ref-banner .ref-code-lg {
    font-size: 1.1rem;
    font-weight: 700;
    color: #0c4a6e;
    font-family: 'Consolas', 'Monaco', monospace;
    background: white;
    padding: 0.25rem 0.75rem;
    border-radius: 6px;
    border: 1px solid #7dd3fc;
}

.account-ref-banner .ref-note {
    flex: 1;
    font-size: 0.85rem;
    color: #0369a1;
    background: rgba(255,255,255,0.6);
    padding: 0.75rem 1rem;
    border-radius: 8px;
    line-height: 1.4;
}

.account-ref-banner .ref-note i {
    margin-right: 0.5rem;
}

/* Reference Code in Table */
.ref-code {
    font-family: 'Consolas', 'Monaco', monospace;
    font-size: 0.75rem;
    background: #f1f5f9;
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    color: #334155;
    word-break: break-all;
}

/* Responsive */
@media (max-width: 768px) {
    .savings-detail-header {
        grid-template-columns: 1fr;
    }
    
    .savings-info-card .amount-big {
        font-size: 1.5rem;
    }
    
    .transaction-table-container {
        display: none;
    }
    
    .tx-mobile-cards {
        display: block;
    }
    
    .modal-lg {
        max-width: 100%;
        margin: 0.5rem;
    }
    
    /* Reference Banner Mobile */
    .account-ref-banner {
        flex-direction: column;
        text-align: center;
        padding: 1rem;
    }
    
    .account-ref-banner .ref-note {
        font-size: 0.8rem;
    }
}
</style>

<script>
let savingsData = [];
let currentPage = 1;
let totalPages = 1;
const ITEMS_PER_PAGE = 20;

async function loadSavings(page = 1) {
    currentPage = page;
    const tbody = document.getElementById('savingsTableBody');
    const mobileContainer = document.getElementById('savingsMobileCards');
    
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:2rem;"><div class="spinner" style="margin:0 auto 1rem;"></div>กำลังโหลดข้อมูล...</td></tr>';
    mobileContainer.innerHTML = '<div class="loading-placeholder"><div class="spinner"></div><p>กำลังโหลดข้อมูล...</p></div>';
    
    try {
        const res = await apiCall(API_ENDPOINTS.CUSTOMER_SAVINGS + `?page=${currentPage}&limit=${ITEMS_PER_PAGE}`);
        
        // API returns { success: true, data: [...], summary: {...}, pagination: {...} }
        if (!res.success || !res.data) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--color-gray);">ยังไม่มีเป้าหมายออมเงิน</td></tr>';
            mobileContainer.innerHTML = '<div class="empty-state"><p>ยังไม่มีเป้าหมายออมเงิน</p></div>';
            updateSummary({ total_saved: 0, total_goal: 0, overall_progress: 0, active_count: 0 });
            renderPagination(0, 0);
            return;
        }
        
        savingsData = Array.isArray(res.data) ? res.data : [];
        const summary = res.summary || { total_saved: 0, total_goal: 0, overall_progress: 0, active_count: 0 };
        const pagination = res.pagination || {};
        totalPages = pagination.total_pages || 1;
        
        updateSummaryFromAPI(summary);
        renderPagination(pagination.total || savingsData.length, totalPages);
        
        if (savingsData.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--color-gray);">ยังไม่มีเป้าหมายออมเงิน</td></tr>';
            mobileContainer.innerHTML = '<div class="empty-state"><p>ยังไม่มีเป้าหมายออมเงิน</p></div>';
            return;
        }
        
        // Desktop Table - map API field names
        tbody.innerHTML = savingsData.map(s => {
            const goalAmount = parseFloat(s.target_amount) || 0;
            const savedAmount = parseFloat(s.current_amount) || 0;
            const progress = goalAmount > 0 ? Math.min(100, (savedAmount / goalAmount) * 100) : 0;
            const displayName = s.name || s.product_name || 'ออมเงิน #' + s.id;
            const pendingAmount = parseFloat(s.pending_amount) || 0;
            const pendingBadge = pendingAmount > 0 ? `<br><small class="badge badge-warning" style="font-size:0.7rem;">รอตรวจ ฿${formatNumber(pendingAmount)}</small>` : '';
            
            // Customer profile badge
            const customerProfile = {
                platform: s.customer_platform || 'web',
                name: s.customer_name || 'ไม่ระบุลูกค้า',
                avatar: s.customer_avatar || null
            };
            const customerBadgeHtml = typeof renderCustomerProfileBadge === 'function' 
                ? renderCustomerProfileBadge(customerProfile)
                : `<span>${customerProfile.name}</span>`;
            
            return `
                <tr style="cursor: pointer;" onclick="openSavingsDetail(${s.id})">
                    <td>${customerBadgeHtml}</td>
                    <td><strong>${escapeHtml(displayName)}</strong></td>
                    <td style="text-align:right;">฿${formatNumber(goalAmount)}</td>
                    <td style="text-align:right;">
                        ฿${formatNumber(savedAmount)}
                        ${pendingBadge}
                    </td>
                    <td>
                        <div class="progress-bar-container" style="display:inline-block;width:80px;vertical-align:middle;">
                            <div class="progress-bar-fill" style="width: ${progress}%"></div>
                        </div>
                        <small style="margin-left:0.5rem;">${progress.toFixed(0)}%</small>
                    </td>
                    <td><span class="badge badge-${getStatusBadge(s.status)}">${getStatusLabel(s.status)}</span></td>
                    <td>${s.target_date ? formatDate(s.target_date) : '-'}</td>
                    <td>
                        <button class="btn btn-sm btn-outline" onclick="event.stopPropagation(); openSavingsDetail(${s.id})" title="ดูรายละเอียด">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn btn-sm btn-success" onclick="event.stopPropagation(); openDepositModal(${s.id}, '${escapeHtml(displayName)}')" title="เพิ่มเงินออม">
                            <i class="fas fa-plus"></i>
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
        
        // Mobile Cards
        mobileContainer.innerHTML = savingsData.map(s => {
            const goalAmount = parseFloat(s.target_amount) || 0;
            const savedAmount = parseFloat(s.current_amount) || 0;
            const progress = goalAmount > 0 ? Math.min(100, (savedAmount / goalAmount) * 100) : 0;
            const remaining = Math.max(0, goalAmount - savedAmount);
            const displayName = s.name || s.product_name || 'ออมเงิน #' + s.id;
            const pendingAmount = parseFloat(s.pending_amount) || 0;
            return `
                <div class="mobile-card">
                    <div class="mobile-card-header">
                        <div class="mobile-card-title">${escapeHtml(displayName)}</div>
                        <span class="badge badge-${getStatusBadge(s.status)}">${getStatusLabel(s.status)}</span>
                    </div>
                    <div class="mobile-card-row">
                        <span class="mobile-card-label">เป้าหมาย</span>
                        <span class="mobile-card-value">฿${formatNumber(goalAmount)}</span>
                    </div>
                    <div class="mobile-card-row">
                        <span class="mobile-card-label">ออมแล้ว</span>
                        <span class="mobile-card-value" style="color: var(--color-success);">฿${formatNumber(savedAmount)}</span>
                    </div>
                    ${pendingAmount > 0 ? `
                    <div class="mobile-card-row">
                        <span class="mobile-card-label">รอตรวจสอบ</span>
                        <span class="mobile-card-value"><span class="badge badge-warning">฿${formatNumber(pendingAmount)}</span></span>
                    </div>
                    ` : ''}
                    <div class="mobile-card-row">
                        <span class="mobile-card-label">คงเหลือ</span>
                        <span class="mobile-card-value" style="color: var(--color-warning);">฿${formatNumber(remaining)}</span>
                    </div>
                    <div class="progress-bar-container">
                        <div class="progress-bar-fill" style="width: ${progress}%;"></div>
                    </div>
                    <div style="text-align: center; font-size: 0.8rem; color: var(--color-gray); margin-top: 0.5rem;">
                        ${progress.toFixed(0)}% เป้าหมาย: ${s.target_date ? formatDate(s.target_date) : 'ไม่ระบุ'}
                    </div>
                    <div class="mobile-card-actions">
                        <button class="btn btn-sm btn-outline" onclick="openSavingsDetail(${s.id})">
                            <i class="fas fa-eye"></i> รายละเอียด
                        </button>
                        <button class="btn btn-sm btn-success" onclick="openDepositModal(${s.id}, '${escapeHtml(displayName)}')">
                            <i class="fas fa-plus"></i> เพิ่มเงิน
                        </button>
                    </div>
                </div>
            `;
        }).join('');
    } catch (e) {
        console.error('loadSavings error:', e);
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:red;">เกิดข้อผิดพลาดในการโหลดข้อมูล</td></tr>';
        mobileContainer.innerHTML = '<div class="empty-state" style="color: red;"><p>เกิดข้อผิดพลาดในการโหลดข้อมูล</p></div>';
    }
}

function getStatusBadge(status) {
    const badges = { active: 'primary', completed: 'success', paused: 'secondary', cancelled: 'danger' };
    return badges[status] || 'primary';
}

// Update summary from API response
function updateSummaryFromAPI(summary) {
    document.getElementById('totalSaved').textContent = '฿' + formatNumber(summary.total_saved || 0);
    document.getElementById('totalGoal').textContent = '฿' + formatNumber(summary.total_goal || 0);
    document.getElementById('savingsProgress').textContent = (summary.overall_progress || 0).toFixed(1) + '%';
    document.getElementById('activeGoals').textContent = summary.active_count || 0;
}

function updateSummary(data) {
    const totalSaved = data.reduce((sum, s) => sum + (parseFloat(s.current_amount || s.saved_amount) || 0), 0);
    const totalGoal = data.reduce((sum, s) => sum + (parseFloat(s.target_amount || s.goal_amount) || 0), 0);
    const progress = totalGoal > 0 ? (totalSaved / totalGoal) * 100 : 0;
    const activeCount = data.filter(s => s.status === 'active').length;
    
    document.getElementById('totalSaved').textContent = '฿' + formatNumber(totalSaved);
    document.getElementById('totalGoal').textContent = '฿' + formatNumber(totalGoal);
    document.getElementById('savingsProgress').textContent = progress.toFixed(1) + '%';
    document.getElementById('activeGoals').textContent = activeCount;
}

function getStatusLabel(status) {
    const labels = { active: 'กำลังออม', completed: 'สำเร็จ', paused: 'หยุดชั่วคราว', cancelled: 'ยกเลิก', converted: 'แปลงเป็นคำสั่งซื้อ' };
    return labels[status] || status || 'กำลังออม';
}

function formatNumber(num) {
    return parseFloat(num || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
    return new Date(dateStr).toLocaleDateString('th-TH');
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function renderPagination(total, pages) {
    const container = document.getElementById('savingsPagination');
    if (!container || pages <= 1) {
        if (container) container.innerHTML = '';
        return;
    }
    
    const prevDisabled = currentPage === 1 ? 'disabled' : '';
    const nextDisabled = currentPage === pages ? 'disabled' : '';
    
    container.innerHTML = `
        <button class="btn-pagination" onclick="goToPage(${currentPage - 1})" ${prevDisabled}>
            <i class="fas fa-chevron-left"></i>
        </button>
        <span class="page-indicator">หน้า ${currentPage} / ${pages} (${total} รายการ)</span>
        <button class="btn-pagination" onclick="goToPage(${currentPage + 1})" ${nextDisabled}>
            <i class="fas fa-chevron-right"></i>
        </button>
    `;
}

function goToPage(page) {
    if (page < 1 || page > totalPages) return;
    loadSavings(page);
}

function openCreateSavingsModal() {
    document.getElementById('createSavingsForm').reset();
    document.getElementById('createSavingsError').style.display = 'none';
    document.getElementById('createSavingsSuccess').style.display = 'none';
    document.getElementById('createSavingsModal').classList.remove('hidden');
}

function closeCreateSavingsModal() {
    document.getElementById('createSavingsModal').classList.add('hidden');
}

async function submitCreateSavings() {
    const errorBox = document.getElementById('createSavingsError');
    const successBox = document.getElementById('createSavingsSuccess');
    errorBox.style.display = 'none';
    successBox.style.display = 'none';
    
    const name = document.getElementById('savingsName').value.trim();
    const goalAmount = parseFloat(document.getElementById('savingsGoal').value);
    const targetDate = document.getElementById('savingsTargetDate').value;
    const note = document.getElementById('savingsNote').value.trim();
    
    if (!name || !goalAmount || goalAmount <= 0) {
        errorBox.textContent = 'กรุณากรอกข้อมูลให้ครบถ้วน';
        errorBox.style.display = 'block';
        return;
    }
    
    try {
        const res = await apiCall(API_ENDPOINTS.CUSTOMER_SAVINGS, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name, goal_amount: goalAmount, target_date: targetDate || null, note })
        });
        
        if (!res.success) {
            errorBox.textContent = res.message || 'ไม่สามารถสร้างเป้าหมายได้';
            errorBox.style.display = 'block';
            return;
        }
        
        successBox.textContent = 'สร้างเป้าหมายสำเร็จ!';
        successBox.style.display = 'block';
        loadSavings();
        setTimeout(() => closeCreateSavingsModal(), 1500);
    } catch (e) {
        console.error('submitCreateSavings error:', e);
        errorBox.textContent = 'เกิดข้อผิดพลาด';
        errorBox.style.display = 'block';
    }
}

function openDepositModal(id, name) {
    document.getElementById('depositSavingsId').value = id;
    document.getElementById('depositSavingsName').textContent = name;
    document.getElementById('depositAmount').value = '';
    document.getElementById('depositNote').value = '';
    document.getElementById('depositError').style.display = 'none';
    document.getElementById('depositSuccess').style.display = 'none';
    document.getElementById('depositModal').classList.remove('hidden');
}

function closeDepositModal() {
    document.getElementById('depositModal').classList.add('hidden');
}

async function submitDeposit() {
    const errorBox = document.getElementById('depositError');
    const successBox = document.getElementById('depositSuccess');
    errorBox.style.display = 'none';
    successBox.style.display = 'none';
    
    const savingsId = document.getElementById('depositSavingsId').value;
    const amount = parseFloat(document.getElementById('depositAmount').value);
    const note = document.getElementById('depositNote').value.trim();
    
    if (!amount || amount <= 0) {
        errorBox.textContent = 'กรุณาระบุจำนวนเงิน';
        errorBox.style.display = 'block';
        return;
    }
    
    try {
        const res = await apiCall(API_ENDPOINTS.CUSTOMER_SAVINGS_DEPOSIT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ savings_id: savingsId, amount, note })
        });
        
        if (!res.success) {
            errorBox.textContent = res.message || 'ไม่สามารถเพิ่มเงินได้';
            errorBox.style.display = 'block';
            return;
        }
        
        // Show success with pending status message
        successBox.innerHTML = `
            <i class="fas fa-check-circle"></i> บันทึกยอดฝากเรียบร้อย!<br>
            <small style="opacity:0.8;">รหัส: ${res.data?.transaction_no || '-'}<br>
            สถานะ: <span class="badge badge-warning">รอตรวจสอบ</span><br>
            ยอดจะถูกเพิ่มหลังจาก Admin อนุมัติ</small>
        `;
        successBox.style.display = 'block';
        loadSavings();
        setTimeout(() => closeDepositModal(), 2500);
    } catch (e) {
        console.error('submitDeposit error:', e);
        errorBox.textContent = 'เกิดข้อผิดพลาด';
        errorBox.style.display = 'block';
    }
}

// Load on page ready
document.addEventListener('DOMContentLoaded', function() {
    if (typeof apiCall !== 'undefined') {
        loadSavings();
    } else {
        document.addEventListener('coreJSLoaded', loadSavings);
    }
});

// ========================================
// Savings Detail Functions
// ========================================
let currentDetailSavingsId = null;

async function openSavingsDetail(savingsId) {
    currentDetailSavingsId = savingsId;
    document.getElementById('savingsDetailModal').classList.remove('hidden');
    document.getElementById('savingsDetailBody').innerHTML = `
        <div class="loading-placeholder">
            <div class="spinner"></div>
            <p>กำลังโหลดข้อมูล...</p>
        </div>
    `;
    
    try {
        const res = await apiCall(API_ENDPOINTS.CUSTOMER_SAVINGS_DETAIL(savingsId));
        
        if (!res.success || !res.data) {
            document.getElementById('savingsDetailBody').innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> ${res.message || 'ไม่พบข้อมูล'}
                </div>
            `;
            return;
        }
        
        renderSavingsDetail(res.data);
    } catch (e) {
        console.error('openSavingsDetail error:', e);
        document.getElementById('savingsDetailBody').innerHTML = `
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> เกิดข้อผิดพลาดในการโหลดข้อมูล
            </div>
        `;
    }
}

function closeSavingsDetailModal() {
    document.getElementById('savingsDetailModal').classList.add('hidden');
    currentDetailSavingsId = null;
}

function renderSavingsDetail(data) {
    const account = data.account || data;
    const transactions = data.transactions || [];
    
    const goalAmount = parseFloat(account.target_amount) || 0;
    const savedAmount = parseFloat(account.current_amount) || 0;
    const pendingAmount = parseFloat(account.pending_amount) || 0;
    const progress = goalAmount > 0 ? Math.min(100, (savedAmount / goalAmount) * 100) : 0;
    const remaining = Math.max(0, goalAmount - savedAmount);
    const displayName = account.name || account.product_name || 'ออมเงิน #' + account.id;
    const accountNo = account.account_no || 'SAV-' + account.id;
    
    // Count transactions by status
    const verifiedCount = transactions.filter(t => t.status === 'verified').length;
    const pendingCount = transactions.filter(t => t.status === 'pending').length;
    const rejectedCount = transactions.filter(t => t.status === 'rejected').length;
    
    // Build transactions table
    let transactionsHtml = '';
    if (transactions.length === 0) {
        transactionsHtml = `
            <div class="empty-transactions">
                <i class="fas fa-receipt"></i>
                <p>ยังไม่มีประวัติการฝากเงิน</p>
            </div>
        `;
    } else {
        transactionsHtml = `
            <!-- Desktop Table -->
            <div class="transaction-table-container">
                <table class="transaction-table">
                    <thead>
                        <tr>
                            <th style="width: 140px;">วันที่</th>
                            <th style="width: 180px;">รหัสรายการ</th>
                            <th style="text-align: right; width: 120px;">จำนวน</th>
                            <th style="width: 100px;">ช่องทาง</th>
                            <th style="width: 120px;">สถานะ</th>
                            <th>หมายเหตุ</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${transactions.map(t => {
                            const isWithdrawal = t.transaction_type === 'withdrawal' || t.transaction_type === 'refund';
                            const txNo = t.transaction_no || 'SAVTX-' + t.id;
                            return `
                                <tr>
                                    <td>${formatDateTime(t.created_at)}</td>
                                    <td>
                                        <code class="ref-code" title="รหัสอ้างอิง: ${escapeHtml(txNo)}">${escapeHtml(txNo)}</code>
                                    </td>
                                    <td style="text-align: right;">
                                        <span class="amount ${isWithdrawal ? 'negative' : ''}">${isWithdrawal ? '-' : '+'}฿${formatNumber(t.amount)}</span>
                                    </td>
                                    <td>${getPaymentMethodLabel(t.payment_method)}</td>
                                    <td>${getTransactionStatusBadge(t.status, t.rejection_reason)}</td>
                                    <td><small>${escapeHtml(t.notes || '-')}</small></td>
                                </tr>
                            `;
                        }).join('')}
                    </tbody>
                </table>
            </div>
            
            <!-- Mobile Cards -->
            <div class="tx-mobile-cards">
                ${transactions.map(t => {
                    const isWithdrawal = t.transaction_type === 'withdrawal' || t.transaction_type === 'refund';
                    const txNo = t.transaction_no || 'SAVTX-' + t.id;
                    return `
                        <div class="tx-mobile-card">
                            <div class="tx-mobile-header">
                                <span class="tx-mobile-amount ${isWithdrawal ? 'negative' : ''}">${isWithdrawal ? '-' : '+'}฿${formatNumber(t.amount)}</span>
                                ${getTransactionStatusBadge(t.status, t.rejection_reason)}
                            </div>
                            <div class="tx-mobile-meta">
                                <span>📅 วันที่</span><strong>${formatDateTime(t.created_at)}</strong>
                                <span>🔖 รหัสอ้างอิง</span><code class="ref-code">${escapeHtml(txNo)}</code>
                                <span>💳 ช่องทาง</span><strong>${getPaymentMethodLabel(t.payment_method)}</strong>
                                ${t.notes ? `<span>📝 หมายเหตุ</span><strong>${escapeHtml(t.notes)}</strong>` : ''}
                                ${t.rejection_reason ? `<span style="color: #ef4444;">❌ เหตุผล</span><strong style="color: #ef4444;">${escapeHtml(t.rejection_reason)}</strong>` : ''}
                            </div>
                        </div>
                    `;
                }).join('')}
            </div>
        `;
    }
    
    document.getElementById('savingsDetailBody').innerHTML = `
        <!-- Account Reference Info -->
        <div class="account-ref-banner">
            <div class="ref-icon">🏦</div>
            <div class="ref-info">
                <div class="ref-label">เลขที่บัญชีออมเงิน</div>
                <code class="ref-code-lg">${escapeHtml(accountNo)}</code>
            </div>
            <div class="ref-note">
                <i class="fas fa-info-circle"></i>
                เมื่อคุณส่งสลิปฝากเงินทางแชท สลิปจะปรากฏใน <a href="payment-history.php" style="color: #0369a1; font-weight: 600;">ประวัติการชำระเงิน</a> และเมื่อ Admin อนุมัติจะบันทึกเข้าบัญชีออมนี้โดยอัตโนมัติ
            </div>
        </div>
        
        <div class="savings-detail-header">
            <div class="savings-info-card">
                <h4>${escapeHtml(displayName)}</h4>
                <div class="amount-big">฿${formatNumber(savedAmount)}</div>
                <div class="progress-row">
                    <div class="progress-bar-container">
                        <div class="progress-bar-fill" style="width: ${progress}%"></div>
                    </div>
                    <strong>${progress.toFixed(1)}%</strong>
                </div>
                <div style="margin-top: 0.75rem; font-size: 0.9rem; opacity: 0.9;">
                    เป้าหมาย: ฿${formatNumber(goalAmount)} | คงเหลือ: ฿${formatNumber(remaining)}
                </div>
            </div>
            
            <div class="savings-stats">
                <div class="stat-item success">
                    <div class="stat-value">${verifiedCount}</div>
                    <div class="stat-label">รายการอนุมัติ</div>
                </div>
                <div class="stat-item pending">
                    <div class="stat-value">${pendingCount}</div>
                    <div class="stat-label">รอตรวจสอบ</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value">฿${formatNumber(pendingAmount)}</div>
                    <div class="stat-label">ยอดรอตรวจสอบ</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value">${account.target_date ? formatDate(account.target_date) : '-'}</div>
                    <div class="stat-label">วันที่เป้าหมาย</div>
                </div>
            </div>
        </div>
        
        <div class="transaction-section">
            <h4><i class="fas fa-history"></i> ประวัติการฝากเงิน (${transactions.length} รายการ)</h4>
            ${transactionsHtml}
        </div>
    `;
    
    // Update add deposit button visibility
    document.getElementById('btnAddDeposit').style.display = account.status === 'active' ? 'inline-flex' : 'none';
}

function getTransactionStatusBadge(status, reason) {
    const statusConfig = {
        'pending': { label: 'รอตรวจสอบ', icon: 'fas fa-clock', class: 'pending' },
        'verified': { label: 'อนุมัติแล้ว', icon: 'fas fa-check-circle', class: 'verified' },
        'rejected': { label: 'ปฏิเสธ', icon: 'fas fa-times-circle', class: 'rejected' },
        'cancelled': { label: 'ยกเลิก', icon: 'fas fa-ban', class: 'rejected' }
    };
    
    const config = statusConfig[status] || statusConfig['pending'];
    let badge = `<span class="tx-status ${config.class}"><i class="${config.icon}"></i> ${config.label}</span>`;
    
    return badge;
}

function getPaymentMethodLabel(method) {
    const methods = {
        'bank_transfer': '🏦 โอนเงิน',
        'promptpay': '📱 PromptPay',
        'credit_card': '💳 บัตรเครดิต',
        'cash': '💵 เงินสด',
        'other': '📋 อื่นๆ'
    };
    return methods[method] || method || '-';
}

function formatDateTime(dateStr) {
    if (!dateStr) return '-';
    const d = new Date(dateStr);
    return d.toLocaleDateString('th-TH') + ' ' + d.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
}

function depositFromDetail() {
    if (currentDetailSavingsId) {
        const savings = savingsData.find(s => s.id == currentDetailSavingsId);
        const name = savings ? (savings.name || savings.product_name || 'ออมเงิน') : 'ออมเงิน';
        closeSavingsDetailModal();
        openDepositModal(currentDetailSavingsId, name);
    }
}
</script>

<!-- Customer Profile Component -->
<link rel="stylesheet" href="<?php echo asset('css/components/customer-profile.css'); ?>?v=<?php echo time(); ?>">
<script src="<?php echo asset('js/components/customer-profile.js'); ?>?v=<?php echo time(); ?>"></script>

<?php include('../includes/customer/footer.php'); ?>
