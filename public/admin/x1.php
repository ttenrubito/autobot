<?php
/**
 * Admin Installments Dashboard - ระบบผ่อนชำระ
 * ร้านเฮง เฮง เฮง - Chatbot Commerce System
 */
define('INCLUDE_CHECK', true);

$page_title = "Installments - ระบบผ่อนชำระ";
$current_page = "installments";

include('../../includes/admin/header.php');
include('../../includes/admin/sidebar.php');
?>

<!-- Main Content -->
<main class="main-content">
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-calendar-alt"></i> ระบบผ่อนชำระ</h1>
        <p class="page-subtitle">จัดการรายการผ่อนชำระของลูกค้า</p>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon primary"><i class="fas fa-file-invoice-dollar"></i></div>
                <div class="stat-content">
                    <div class="stat-label">สัญญาทั้งหมด</div>
                    <div class="stat-value" id="statTotal">-</div>
                </div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon success"><i class="fas fa-check-circle"></i></div>
                <div class="stat-content">
                    <div class="stat-label">Active</div>
                    <div class="stat-value" id="statActive">-</div>
                </div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon danger"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="stat-content">
                    <div class="stat-label">เกินกำหนด</div>
                    <div class="stat-value" id="statOverdue">-</div>
                </div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-card clickable" onclick="showPendingPayments()">
                <div class="stat-icon warning"><i class="fas fa-clock"></i></div>
                <div class="stat-content">
                    <div class="stat-label">รอยืนยันการชำระ</div>
                    <div class="stat-value" id="statPendingPayments">-</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert: Overdue -->
    <div class="alert alert-danger mb-4" id="overdueAlert" style="display: none;">
        <i class="fas fa-exclamation-circle"></i>
        <span id="overdueAlertText">มีลูกค้าค้างชำระเกินกำหนด</span>
    </div>

    <!-- Alert: Pending Payments -->
    <div class="alert alert-warning mb-4" id="pendingPaymentsAlert" style="display: none;">
        <i class="fas fa-clock"></i>
        <span id="pendingPaymentsAlertText">มีการชำระรอยืนยัน</span>
        <button class="btn btn-sm btn-warning" onclick="showPendingPayments()" style="margin-left: 10px;">
            ดูรายการ
        </button>
    </div>

    <!-- Tabs -->
    <div class="tabs mb-4">
        <button class="tab-btn active" data-tab="contracts" onclick="switchTab('contracts')">
            <i class="fas fa-list"></i> รายการสัญญา
        </button>
        <button class="tab-btn" data-tab="pending-payments" onclick="switchTab('pending-payments')">
            <i class="fas fa-clock"></i> รอยืนยันการชำระ <span class="badge badge-warning" id="pendingPaymentsBadge">0</span>
        </button>
    </div>

    <!-- Tab: Contracts -->
    <div class="tab-content" id="tabContracts">
        <!-- Filter Bar -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-2">
                        <label class="form-label">สถานะ</label>
                        <select id="filterStatus" class="form-control" onchange="loadInstallments()">
                            <option value="">ทั้งหมด</option>
                            <option value="active" selected>Active</option>
                            <option value="overdue">เกินกำหนด</option>
                            <option value="completed">ชำระครบแล้ว</option>
                            <option value="pending">รออนุมัติ</option>
                            <option value="cancelled">ยกเลิก</option>
                        </select>
                    </div>
                    <div class="col-3">
                        <label class="form-label">ค้นหา</label>
                        <input type="text" id="filterSearch" class="form-control" placeholder="Order ID, ชื่อสินค้า, ลูกค้า..." onkeyup="debounceSearch()">
                    </div>
                    <div class="col-2">
                        <label class="form-label">&nbsp;</label>
                        <button class="btn btn-primary btn-block" onclick="loadInstallments()">
                            <i class="fas fa-sync"></i> รีเฟรช
                        </button>
                    </div>
                    <div class="col-3">
                        <label class="form-label">&nbsp;</label>
                        <button class="btn btn-warning btn-block" onclick="sendBatchReminders()">
                            <i class="fas fa-bell"></i> ส่งแจ้งเตือนทั้งหมด
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Installments List -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-list"></i> รายการสัญญาผ่อน</h3>
                <div class="card-actions">
                    <span id="installmentCount" class="badge badge-info">0 รายการ</span>
                </div>
            </div>
            <div class="card-body">
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th width="100">Contract ID</th>
                                <th>ลูกค้า</th>
                                <th>สินค้า</th>
                                <th width="100">ราคารวม</th>
                                <th width="100">ชำระแล้ว</th>
                                <th width="80">งวดที่</th>
                                <th width="100">งวดถัดไป</th>
                                <th width="80">สถานะ</th>
                                <th width="100">การจัดการ</th>
                            </tr>
                        </thead>
                        <tbody id="installmentTableBody">
                            <tr class="table-loading">
                                <td colspan="9">กำลังโหลด...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab: Pending Payments -->
    <div class="tab-content" id="tabPendingPayments" style="display: none;">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-clock"></i> รายการรอยืนยันการชำระ</h3>
                <div class="card-actions">
                    <button class="btn btn-sm btn-outline" onclick="loadPendingPayments()">
                        <i class="fas fa-sync"></i> รีเฟรช
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th width="80">Payment ID</th>
                                <th width="100">Contract</th>
                                <th>ลูกค้า</th>
                                <th>งวดที่</th>
                                <th width="100">จำนวนเงิน</th>
                                <th>สลิป</th>
                                <th width="120">วันที่แจ้ง</th>
                                <th width="150">การจัดการ</th>
                            </tr>
                        </thead>
                        <tbody id="pendingPaymentsTableBody">
                            <tr class="table-loading">
                                <td colspan="8">กำลังโหลด...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Installment Detail Modal -->
<div class="modal-overlay" id="installmentDetailModal">
    <div class="modal modal-lg">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-calendar-alt"></i> รายละเอียดสัญญาผ่อน</h3>
            <button class="modal-close" onclick="closeInstallmentModal()">&times;</button>
        </div>
        <div class="modal-body" id="installmentDetailBody">
            <!-- Content loaded dynamically -->
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeInstallmentModal()">ปิด</button>
            <button class="btn btn-info" id="btnApprove" onclick="approveContract()" style="display: none;">
                <i class="fas fa-check"></i> อนุมัติสัญญา
            </button>
            <button class="btn btn-success" id="btnRecordPayment" onclick="openPaymentModal()">
                <i class="fas fa-plus"></i> บันทึกการชำระ
            </button>
            <button class="btn btn-warning" id="btnSendReminder" onclick="sendReminder()">
                <i class="fas fa-bell"></i> ส่งแจ้งเตือน
            </button>
            <button class="btn btn-danger" id="btnCancelContract" onclick="cancelContract()">
                <i class="fas fa-times"></i> ยกเลิกสัญญา
            </button>
        </div>
    </div>
</div>

<!-- Record Payment Modal -->
<div class="modal-overlay" id="paymentModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-plus-circle"></i> บันทึกการชำระงวด</h3>
            <button class="modal-close" onclick="closePaymentModal()">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="paymentContractId">
            <div class="form-group">
                <label class="form-label">งวดที่</label>
                <input type="number" id="paymentPeriodNo" class="form-control" readonly>
            </div>
            <div class="form-group">
                <label class="form-label">จำนวนเงิน (บาท)</label>
                <input type="number" id="paymentAmount" class="form-control" placeholder="0.00">
            </div>
            <div class="form-group">
                <label class="form-label">วิธีชำระ</label>
                <select id="paymentMethod" class="form-control">
                    <option value="bank_transfer">โอนเงิน</option>
                    <option value="promptpay">PromptPay</option>
                    <option value="cash">เงินสด</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">หมายเหตุ</label>
                <input type="text" id="paymentNote" class="form-control" placeholder="เช่น สลิปโอนวันที่...">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closePaymentModal()">ยกเลิก</button>
            <button class="btn btn-success" onclick="submitManualPayment()">
                <i class="fas fa-save"></i> บันทึก
            </button>
        </div>
    </div>
</div>

<!-- Verify Payment Modal -->
<div class="modal-overlay" id="verifyPaymentModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-check-circle"></i> ยืนยันการชำระเงิน</h3>
            <button class="modal-close" onclick="closeVerifyModal()">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="verifyPaymentId">
            <input type="hidden" id="verifyContractId">
            
            <div class="form-group">
                <label class="form-label">หลักฐานการชำระ (สลิป)</label>
                <div id="slipPreview" class="slip-preview text-center">
                    <img id="slipImage" src="" style="max-width: 100%; max-height: 300px; border-radius: 8px;">
                </div>
            </div>
            
            <div class="row">
                <div class="col-6">
                    <div class="form-group">
                        <label class="form-label">งวดที่</label>
                        <input type="text" id="verifyPeriodNo" class="form-control" readonly>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-group">
                        <label class="form-label">จำนวนเงิน</label>
                        <input type="text" id="verifyAmount" class="form-control" readonly>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">หมายเหตุ (ถ้ามี)</label>
                <input type="text" id="verifyNote" class="form-control" placeholder="หมายเหตุ...">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeVerifyModal()">ยกเลิก</button>
            <button class="btn btn-danger" onclick="rejectPayment()">
                <i class="fas fa-times"></i> ปฏิเสธ
            </button>
            <button class="btn btn-success" onclick="confirmVerifyPayment()">
                <i class="fas fa-check"></i> ยืนยัน
            </button>
        </div>
    </div>
</div>

<style>
.clickable { cursor: pointer; transition: transform 0.2s; }
.clickable:hover { transform: scale(1.02); }
.progress-bar-container {
    width: 100%;
    height: 8px;
    background: #e0e0e0;
    border-radius: 4px;
    overflow: hidden;
}
.progress-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #4caf50, #8bc34a);
    border-radius: 4px;
    transition: width 0.3s ease;
}
.status-badge {
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 500;
}
.status-badge.active { background: #e8f5e9; color: #2e7d32; }
.status-badge.overdue { background: #ffebee; color: #c62828; }
.status-badge.completed { background: #e3f2fd; color: #1565c0; }
.status-badge.cancelled { background: #fafafa; color: #757575; }
.status-badge.pending { background: #fff3e0; color: #ef6c00; }
.installment-schedule {
    max-height: 300px;
    overflow-y: auto;
}
.schedule-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem;
    border: 1px solid #eee;
    border-radius: 8px;
    margin-bottom: 0.5rem;
}
.schedule-item.paid { background: #e8f5e9; border-color: #c8e6c9; }
.schedule-item.overdue { background: #ffebee; border-color: #ffcdd2; }
.schedule-item.upcoming { background: #fff8e1; border-color: #ffecb3; }
.schedule-item.pending_verification { background: #e3f2fd; border-color: #bbdefb; }
.schedule-number {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1565c0;
    width: 40px;
}
.schedule-info {
    flex: 1;
    padding: 0 1rem;
}
.schedule-amount {
    font-weight: 600;
    font-size: 1rem;
}
.schedule-status {
    font-size: 0.75rem;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
}
.schedule-status.paid { background: #4caf50; color: white; }
.schedule-status.pending { background: #ff9800; color: white; }
.schedule-status.overdue { background: #f44336; color: white; }
.schedule-status.pending_verification { background: #2196f3; color: white; }
.tabs {
    display: flex;
    gap: 0.5rem;
    border-bottom: 2px solid #e0e0e0;
    padding-bottom: 0;
}
.tab-btn {
    padding: 0.75rem 1.5rem;
    border: none;
    background: none;
    cursor: pointer;
    font-size: 0.875rem;
    font-weight: 500;
    color: #666;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: all 0.2s;
}
.tab-btn:hover { color: #1565c0; }
.tab-btn.active { color: #1565c0; border-bottom-color: #1565c0; }
.slip-preview {
    background: #f5f5f5;
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
}
</style>

<script>
let currentContractId = null;
let currentInstallmentData = null;
let searchTimeout = null;

document.addEventListener('DOMContentLoaded', function() {
    // Check admin authentication
    const token = localStorage.getItem('admin_token');
    if (!token) {
        window.location.href = 'login.html';
        return;
    }
    loadInstallments();
    loadStats();
    
    // Auto-refresh every 60 seconds
    setInterval(() => {
        loadStats();
    }, 60000);
});

function debounceSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(loadInstallments, 500);
}

function switchTab(tab) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelector(`[data-tab="${tab}"]`).classList.add('active');
    
    if (tab === 'contracts') {
        document.getElementById('tabContracts').style.display = 'block';
        document.getElementById('tabPendingPayments').style.display = 'none';
    } else {
        document.getElementById('tabContracts').style.display = 'none';
        document.getElementById('tabPendingPayments').style.display = 'block';
        loadPendingPayments();
    }
}

function showPendingPayments() {
    switchTab('pending-payments');
}

async function loadStats() {
    try {
        const response = await adminApiCall(PATH.ADMIN_INSTALLMENTS_API + '?stats=1');
        if (response.success && response.data) {
            const stats = response.data;
            document.getElementById('statTotal').textContent = stats.total || 0;
            document.getElementById('statActive').textContent = stats.active || 0;
            document.getElementById('statOverdue').textContent = stats.overdue || 0;
            document.getElementById('statPendingPayments').textContent = stats.pending_payments || 0;
            
            // Update badge
            document.getElementById('pendingPaymentsBadge').textContent = stats.pending_payments || 0;
            
            // Show alerts
            if (stats.overdue > 0) {
                document.getElementById('overdueAlert').style.display = 'block';
                document.getElementById('overdueAlertText').textContent = `มี ${stats.overdue} สัญญาค้างชำระเกินกำหนด`;
            } else {
                document.getElementById('overdueAlert').style.display = 'none';
            }
            
            if (stats.pending_payments > 0) {
                document.getElementById('pendingPaymentsAlert').style.display = 'block';
                document.getElementById('pendingPaymentsAlertText').textContent = `มี ${stats.pending_payments} รายการรอยืนยันการชำระ`;
            } else {
                document.getElementById('pendingPaymentsAlert').style.display = 'none';
            }
        }
    } catch (error) {
        console.error('Error loading stats:', error);
    }
}

async function loadInstallments() {
    const tbody = document.getElementById('installmentTableBody');
    tbody.innerHTML = '<tr class="table-loading"><td colspan="9">กำลังโหลด...</td></tr>';
    
    const params = new URLSearchParams();
    const status = document.getElementById('filterStatus').value;
    const search = document.getElementById('filterSearch').value;
    
    if (status) params.append('status', status);
    if (search) params.append('search', search);
    
    try {
        const url = PATH.ADMIN_INSTALLMENTS_API + '?' + params.toString();
        const response = await adminApiCall(url);
        
        if (response.success && response.data && response.data.length > 0) {
            renderInstallments(response.data);
            document.getElementById('installmentCount').textContent = response.data.length + ' รายการ';
        } else {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">ไม่พบข้อมูล</td></tr>';
            document.getElementById('installmentCount').textContent = '0 รายการ';
        }
    } catch (error) {
        console.error('Error loading installments:', error);
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-danger">เกิดข้อผิดพลาด</td></tr>';
    }
}

function renderInstallments(contracts) {
    const tbody = document.getElementById('installmentTableBody');
    
    const statusLabels = {
        'active': 'Active',
        'overdue': 'เกินกำหนด',
        'completed': 'ครบแล้ว',
        'cancelled': 'ยกเลิก',
        'pending': 'รออนุมัติ'
    };
    
    tbody.innerHTML = contracts.map(c => {
        const paidPeriods = c.paid_periods || 0;
        const totalPeriods = c.total_periods || 0;
        
        return `
            <tr onclick="openInstallmentDetail(${c.id})" style="cursor: pointer;">
                <td><strong>#${c.id}</strong></td>
                <td>${escapeHtml(c.customer_name || 'ไม่ระบุ')}<br><small class="text-muted">${escapeHtml(c.platform || '')}</small></td>
                <td>${escapeHtml(c.product_name || '-')}<br><small class="text-muted">Ref: ${escapeHtml(c.product_ref || '-')}</small></td>
                <td class="text-right">${formatMoney(c.total_amount)}</td>
                <td class="text-right">${formatMoney(c.paid_amount || 0)}</td>
                <td>${paidPeriods}/${totalPeriods}</td>
                <td>${formatDate(c.next_due_date)}</td>
                <td><span class="status-badge ${c.status}">${statusLabels[c.status] || c.status}</span></td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="event.stopPropagation(); openInstallmentDetail(${c.id})">
                        <i class="fas fa-eye"></i>
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}

async function loadPendingPayments() {
    const tbody = document.getElementById('pendingPaymentsTableBody');
    tbody.innerHTML = '<tr class="table-loading"><td colspan="8">กำลังโหลด...</td></tr>';
    
    try {
        const response = await adminApiCall(PATH.ADMIN_INSTALLMENTS_API + '?pending_payments=1');
        
        if (response.success && response.data && response.data.length > 0) {
            renderPendingPayments(response.data);
        } else {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">ไม่มีรายการรอยืนยัน</td></tr>';
        }
    } catch (error) {
        console.error('Error loading pending payments:', error);
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">เกิดข้อผิดพลาด</td></tr>';
    }
}

function renderPendingPayments(payments) {
    const tbody = document.getElementById('pendingPaymentsTableBody');
    
    tbody.innerHTML = payments.map(p => {
        const slipHtml = p.slip_url 
            ? `<a href="${p.slip_url}" target="_blank" class="btn btn-sm btn-outline"><i class="fas fa-image"></i> ดูสลิป</a>`
            : '<span class="text-muted">-</span>';
            
        return `
            <tr>
                <td><strong>#${p.id}</strong></td>
                <td><a href="#" onclick="openInstallmentDetail(${p.contract_id}); return false;">#${p.contract_id}</a></td>
                <td>${escapeHtml(p.customer_name || 'ไม่ระบุ')}</td>
                <td>งวดที่ ${p.period_number}</td>
                <td class="text-right">${formatMoney(p.amount)}</td>
                <td>${slipHtml}</td>
                <td>${formatDateTime(p.created_at)}</td>
                <td>
                    <button class="btn btn-sm btn-success" onclick="openVerifyModal(${p.id}, ${p.contract_id}, ${p.period_number}, ${p.amount}, '${p.slip_url || ''}')">
                        <i class="fas fa-check"></i> ยืนยัน
                    </button>
                    <button class="btn btn-sm btn-danger" onclick="quickReject(${p.id}, ${p.contract_id})">
                        <i class="fas fa-times"></i>
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}

async function openInstallmentDetail(contractId) {
    currentContractId = contractId;
    document.getElementById('installmentDetailModal').classList.add('active');
    document.getElementById('installmentDetailBody').innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    
    try {
        const response = await adminApiCall(PATH.ADMIN_INSTALLMENTS_API + '?id=' + contractId);
        
        if (response.success && response.data) {
            currentInstallmentData = response.data;
            renderInstallmentDetail(response.data);
        } else {
            document.getElementById('installmentDetailBody').innerHTML = '<div class="alert alert-danger">ไม่พบข้อมูล</div>';
        }
    } catch (error) {
        console.error('Error loading installment detail:', error);
        document.getElementById('installmentDetailBody').innerHTML = '<div class="alert alert-danger">เกิดข้อผิดพลาด</div>';
    }
}

function renderInstallmentDetail(contract) {
    const statusLabels = {
        'active': 'Active',
        'overdue': 'เกินกำหนด',
        'completed': 'ครบแล้ว',
        'cancelled': 'ยกเลิก',
        'pending': 'รออนุมัติ'
    };
    
    // Render payment schedule
    const payments = contract.payments || [];
    let scheduleHtml = '<p class="text-muted">ไม่มีตารางผ่อน</p>';
    if (contract.total_periods > 0) {
        // ✅ FIX: Calculate correct per-period amounts (งวดแรก + 3% ค่าดำเนินการ)
        const productPrice = parseFloat(contract.product_price || contract.total_amount || 0);
        const serviceFeeRate = 0.03;
        const serviceFee = Math.round(productPrice * serviceFeeRate);
        const basePerPeriod = Math.floor(productPrice / contract.total_periods);
        const remainder = productPrice - (basePerPeriod * contract.total_periods);
        
        // Period amounts: P1 = base + fee, P2 = base, P3 = base + remainder
        const periodAmounts = {
            1: basePerPeriod + serviceFee,  // งวดแรก: รวมค่าธรรมเนียม 3%
            2: basePerPeriod,                // งวดที่ 2: ยอดฐาน
            3: basePerPeriod + remainder     // งวดที่ 3: ยอดฐาน + เศษ
        };
        
        scheduleHtml = '<div class="installment-schedule">';
        for (let i = 1; i <= contract.total_periods; i++) {
            const payment = payments.find(p => p.period_number === i);
            const isPaid = payment && payment.status === 'verified';
            const isPending = payment && payment.status === 'pending_verification';
            const dueDate = calculateDueDate(contract.start_date, i, contract.period_days || 30);
            const isOverdue = !isPaid && !isPending && new Date(dueDate) < new Date();
            
            let statusClass = isPaid ? 'paid' : isPending ? 'pending_verification' : isOverdue ? 'overdue' : 'upcoming';
            let statusText = isPaid ? 'ชำระแล้ว' : isPending ? 'รอยืนยัน' : isOverdue ? 'เกินกำหนด' : 'รอชำระ';
            
            // ✅ FIX: Use correct amount for this period (from payment or calculated)
            const amountForPeriod = payment?.amount || periodAmounts[i] || basePerPeriod;
            
            scheduleHtml += `
                <div class="schedule-item ${statusClass}">
                    <div class="schedule-number">${i}</div>
                    <div class="schedule-info">
                        <div class="schedule-amount">${formatMoney(amountForPeriod)}</div>
                        <div class="text-muted" style="font-size: 0.75rem;">กำหนด: ${formatDate(dueDate)}</div>
                        ${payment?.paid_at ? `<div class="text-muted" style="font-size: 0.75rem;">ชำระ: ${formatDate(payment.paid_at)}</div>` : ''}
                    </div>
                    <div class="schedule-status ${isPaid ? 'paid' : isPending ? 'pending_verification' : isOverdue ? 'overdue' : 'pending'}">${statusText}</div>
                </div>
            `;
        }
        scheduleHtml += '</div>';
    }
    
    const paidAmount = contract.paid_amount || 0;
    const percentage = contract.total_amount > 0 ? Math.round((paidAmount / contract.total_amount) * 100) : 0;
    
    document.getElementById('installmentDetailBody').innerHTML = `
        <div class="row">
            <div class="col-6">
                <h5>ข้อมูลสัญญา</h5>
                <table class="table table-sm">
                    <tr><td><strong>Contract ID:</strong></td><td>#${contract.id}</td></tr>
                    <tr><td><strong>ลูกค้า:</strong></td><td>${escapeHtml(contract.customer_name || '-')}</td></tr>
                    <tr><td><strong>Platform:</strong></td><td>${escapeHtml(contract.platform || '-')}</td></tr>
                    <tr><td><strong>สถานะ:</strong></td><td><span class="status-badge ${contract.status}">${statusLabels[contract.status] || contract.status}</span></td></tr>
                    <tr><td><strong>สินค้า:</strong></td><td>${escapeHtml(contract.product_name || '-')}</td></tr>
                    <tr><td><strong>Ref:</strong></td><td>${escapeHtml(contract.product_ref || '-')}</td></tr>
                    <tr><td><strong>ราคารวม:</strong></td><td>${formatMoney(contract.total_amount)}</td></tr>
                    <tr><td><strong>เงินดาวน์:</strong></td><td>${formatMoney(contract.down_payment || 0)}</td></tr>
                    <tr><td><strong>ชำระแล้ว:</strong></td><td>${formatMoney(paidAmount)}</td></tr>
                    <tr><td><strong>ยอดคงเหลือ:</strong></td><td>${formatMoney(contract.remaining_amount || (contract.total_amount - paidAmount))}</td></tr>
                    <tr><td><strong>ความคืบหน้า:</strong></td><td>
                        <div class="progress-bar-container" style="width: 150px; display: inline-block; vertical-align: middle;">
                            <div class="progress-bar-fill" style="width: ${percentage}%"></div>
                        </div>
                        <strong style="margin-left: 8px;">${percentage}%</strong>
                    </td></tr>
                    <tr><td><strong>สร้างเมื่อ:</strong></td><td>${formatDateTime(contract.created_at)}</td></tr>
                </table>
            </div>
            <div class="col-6">
                <h5>ตารางผ่อนชำระ (${contract.paid_periods || 0}/${contract.total_periods} งวด)</h5>
                ${scheduleHtml}
            </div>
        </div>
    `;
    
    // Show buttons based on status
    const isPending = contract.status === 'pending';
    const isActive = contract.status === 'active' || contract.status === 'overdue';
    const isCompleted = contract.status === 'completed';
    const isCancelled = contract.status === 'cancelled';
    
    document.getElementById('btnApprove').style.display = isPending ? 'inline-block' : 'none';
    document.getElementById('btnRecordPayment').style.display = isActive ? 'inline-block' : 'none';
    document.getElementById('btnSendReminder').style.display = isActive ? 'inline-block' : 'none';
    document.getElementById('btnCancelContract').style.display = (isPending || isActive) && !isCompleted && !isCancelled ? 'inline-block' : 'none';
}

function calculateDueDate(startDate, periodNo, periodDays) {
    const start = new Date(startDate);
    start.setDate(start.getDate() + (periodNo * periodDays));
    return start.toISOString().split('T')[0];
}

function closeInstallmentModal() {
    document.getElementById('installmentDetailModal').classList.remove('active');
    currentContractId = null;
    currentInstallmentData = null;
}

function openPaymentModal() {
    if (!currentInstallmentData) return;
    
    const contract = currentInstallmentData;
    const paidPeriods = contract.paid_periods || 0;
    const nextPeriod = paidPeriods + 1;
    
    document.getElementById('paymentContractId').value = contract.id;
    document.getElementById('paymentPeriodNo').value = nextPeriod;
    document.getElementById('paymentAmount').value = contract.amount_per_period || 0;
    document.getElementById('paymentNote').value = '';
    document.getElementById('paymentModal').classList.add('active');
}

function closePaymentModal() {
    document.getElementById('paymentModal').classList.remove('active');
}

async function submitManualPayment() {
    const contractId = document.getElementById('paymentContractId').value;
    const periodNo = parseInt(document.getElementById('paymentPeriodNo').value);
    const amount = parseFloat(document.getElementById('paymentAmount').value);
    const method = document.getElementById('paymentMethod').value;
    const notes = document.getElementById('paymentNote').value.trim();
    
    if (!amount || amount <= 0) {
        showToast('กรุณากรอกจำนวนเงิน', 'warning');
        return;
    }
    
    try {
        const response = await adminApiCall(PATH.ADMIN_INSTALLMENT_MANUAL_PAYMENT(contractId), {
            method: 'POST',
            body: JSON.stringify({
                period_number: periodNo,
                amount: amount,
                payment_method: method,
                notes: notes
            })
        });
        
        if (response.success) {
            showToast('บันทึกการชำระเรียบร้อย', 'success');
            closePaymentModal();
            openInstallmentDetail(contractId);
            loadInstallments();
            loadStats();
        } else {
            showToast(response.message || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (error) {
        console.error('Error submitting payment:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

function openVerifyModal(paymentId, contractId, periodNo, amount, slipUrl) {
    document.getElementById('verifyPaymentId').value = paymentId;
    document.getElementById('verifyContractId').value = contractId;
    document.getElementById('verifyPeriodNo').value = 'งวดที่ ' + periodNo;
    document.getElementById('verifyAmount').value = formatMoney(amount);
    document.getElementById('verifyNote').value = '';
    
    if (slipUrl) {
        document.getElementById('slipImage').src = slipUrl;
        document.getElementById('slipPreview').style.display = 'block';
    } else {
        document.getElementById('slipPreview').style.display = 'none';
    }
    
    document.getElementById('verifyPaymentModal').classList.add('active');
}

function closeVerifyModal() {
    document.getElementById('verifyPaymentModal').classList.remove('active');
}

async function confirmVerifyPayment() {
    const paymentId = document.getElementById('verifyPaymentId').value;
    const contractId = document.getElementById('verifyContractId').value;
    const notes = document.getElementById('verifyNote').value.trim();
    
    try {
        const response = await adminApiCall(PATH.ADMIN_INSTALLMENT_VERIFY_PAYMENT(contractId), {
            method: 'POST',
            body: JSON.stringify({
                payment_id: paymentId,
                notes: notes
            })
        });
        
        if (response.success) {
            showToast('ยืนยันการชำระเรียบร้อย', 'success');
            closeVerifyModal();
            loadPendingPayments();
            loadStats();
        } else {
            showToast(response.message || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (error) {
        console.error('Error verifying payment:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

async function rejectPayment() {
    const paymentId = document.getElementById('verifyPaymentId').value;
    const contractId = document.getElementById('verifyContractId').value;
    const reason = prompt('กรุณาระบุเหตุผลที่ปฏิเสธ:');
    
    if (reason === null) return;
    
    try {
        const response = await adminApiCall(PATH.ADMIN_INSTALLMENT_REJECT_PAYMENT(contractId), {
            method: 'POST',
            body: JSON.stringify({
                payment_id: paymentId,
                reason: reason
            })
        });
        
        if (response.success) {
            showToast('ปฏิเสธการชำระเรียบร้อย', 'success');
            closeVerifyModal();
            loadPendingPayments();
            loadStats();
        } else {
            showToast(response.message || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (error) {
        console.error('Error rejecting payment:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

async function quickReject(paymentId, contractId) {
    if (!confirm('ยืนยันปฏิเสธการชำระนี้?')) return;
    
    const reason = prompt('กรุณาระบุเหตุผลที่ปฏิเสธ:');
    if (reason === null) return;
    
    try {
        const response = await adminApiCall(PATH.ADMIN_INSTALLMENT_REJECT_PAYMENT(contractId), {
            method: 'POST',
            body: JSON.stringify({
                payment_id: paymentId,
                reason: reason
            })
        });
        
        if (response.success) {
            showToast('ปฏิเสธการชำระเรียบร้อย', 'success');
            loadPendingPayments();
            loadStats();
        } else {
            showToast(response.message || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (error) {
        console.error('Error rejecting payment:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

async function approveContract() {
    if (!currentContractId) return;
    if (!confirm('ยืนยันอนุมัติสัญญานี้?')) return;
    
    try {
        const response = await adminApiCall(PATH.ADMIN_INSTALLMENT_APPROVE(currentContractId), {
            method: 'POST'
        });
        
        if (response.success) {
            showToast('อนุมัติสัญญาเรียบร้อย', 'success');
            openInstallmentDetail(currentContractId);
            loadInstallments();
            loadStats();
        } else {
            showToast(response.message || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (error) {
        console.error('Error approving contract:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

async function cancelContract() {
    if (!currentContractId) return;
    
    const reason = prompt('กรุณาระบุเหตุผลที่ยกเลิก:');
    if (reason === null) return;
    
    try {
        const response = await adminApiCall(PATH.ADMIN_INSTALLMENT_CANCEL(currentContractId), {
            method: 'POST',
            body: JSON.stringify({
                reason: reason
            })
        });
        
        if (response.success) {
            showToast('ยกเลิกสัญญาเรียบร้อย', 'success');
            closeInstallmentModal();
            loadInstallments();
            loadStats();
        } else {
            showToast(response.message || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (error) {
        console.error('Error cancelling contract:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

async function sendReminder() {
    if (!currentContractId || !currentInstallmentData) return;
    
    if (!confirm('ส่งแจ้งเตือนถึงลูกค้า?')) return;
    
    try {
        // Use installment reminders API for single contract
        const response = await adminApiCall(PATH.api('api/cron/installment-reminders.php'), {
            method: 'POST',
            body: JSON.stringify({
                contract_id: currentContractId
            })
        });
        
        if (response.success) {
            showToast(`ส่งแจ้งเตือนเรียบร้อย (${response.sent || 0} รายการ)`, 'success');
        } else {
            showToast(response.error || 'ส่งแจ้งเตือนไม่สำเร็จ', 'error');
        }
    } catch (error) {
        console.error('Error sending reminder:', error);
        showToast('เกิดข้อผิดพลาดในการส่งแจ้งเตือน', 'error');
    }
}

// Send batch reminders to all due contracts
async function sendBatchReminders() {
    if (!confirm('ส่งแจ้งเตือนถึงลูกค้าทั้งหมดที่ถึงกำหนดหรือเกินกำหนดชำระ?')) return;
    
    try {
        showToast('กำลังส่งแจ้งเตือน...', 'info');
        
        // Call batch reminder API
        const response = await adminApiCall(PATH.api('api/cron/installment-reminders.php'), {
            method: 'POST',
            body: JSON.stringify({
                batch: true
            })
        });
        
        if (response.success) {
            let message = `ส่งแจ้งเตือนสำเร็จ ${response.sent || 0} รายการ`;
            if (response.failed > 0) {
                message += ` (ล้มเหลว ${response.failed} รายการ)`;
            }
            if (response.skipped > 0) {
                message += ` (ข้าม ${response.skipped} รายการ)`;
            }
            showToast(message, response.failed > 0 ? 'warning' : 'success');
        } else {
            showToast(response.error || 'ส่งแจ้งเตือนไม่สำเร็จ', 'error');
        }
    } catch (error) {
        console.error('Error sending batch reminders:', error);
        showToast('เกิดข้อผิดพลาดในการส่งแจ้งเตือน', 'error');
    }
}

function formatMoney(amount) {
    return new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB' }).format(amount || 0);
}

function formatDate(dateStr) {
    if (!dateStr || dateStr === '-') return '-';
    const d = new Date(dateStr);
    return d.toLocaleDateString('th-TH');
}

function formatDateTime(dateStr) {
    if (!dateStr) return '-';
    const d = new Date(dateStr);
    return d.toLocaleDateString('th-TH') + ' ' + d.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    toast.style.cssText = 'position: fixed; top: 20px; right: 20px; padding: 1rem 1.5rem; border-radius: 8px; color: white; z-index: 9999; animation: fadeIn 0.3s;';
    toast.style.background = type === 'success' ? '#2e7d32' : type === 'error' ? '#c62828' : type === 'warning' ? '#ef6c00' : '#1565c0';
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}
</script>

<?php include('../../includes/admin/footer.php'); ?>
