<?php
/**
 * Admin Savings Dashboard - ระบบออมเงินจองสินค้า
 * ร้านเฮง เฮง เฮง - Chatbot Commerce System
 */
define('INCLUDE_CHECK', true);

$page_title = "Savings - ระบบออมเงิน";
$current_page = "savings";

include('../../includes/admin/header.php');
include('../../includes/admin/sidebar.php');
?>

<!-- Main Content -->
<main class="main-content">
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-piggy-bank"></i> ระบบออมเงิน</h1>
        <p class="page-subtitle">จัดการบัญชีออมเงินจองสินค้าของลูกค้า</p>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon primary"><i class="fas fa-wallet"></i></div>
                <div class="stat-content">
                    <div class="stat-label">บัญชีทั้งหมด</div>
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
                <div class="stat-icon info"><i class="fas fa-coins"></i></div>
                <div class="stat-content">
                    <div class="stat-label">ยอดสะสมรวม</div>
                    <div class="stat-value" id="statTotalAmount">-</div>
                </div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon warning"><i class="fas fa-clock"></i></div>
                <div class="stat-content">
                    <div class="stat-label">ใกล้ครบกำหนด</div>
                    <div class="stat-value" id="statNearDue">-</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-2">
                    <label class="form-label">สถานะ</label>
                    <select id="filterStatus" class="form-control" onchange="loadSavings()">
                        <option value="">ทั้งหมด</option>
                        <option value="active" selected>Active</option>
                        <option value="completed">ครบแล้ว</option>
                        <option value="cancelled">ยกเลิก</option>
                        <option value="expired">หมดอายุ</option>
                    </select>
                </div>
                <div class="col-3">
                    <label class="form-label">ค้นหา</label>
                    <input type="text" id="filterSearch" class="form-control" placeholder="รหัสบัญชี, ชื่อสินค้า, ลูกค้า..." onkeyup="debounceSearch()">
                </div>
                <div class="col-2">
                    <label class="form-label">&nbsp;</label>
                    <button class="btn btn-primary btn-block" onclick="loadSavings()">
                        <i class="fas fa-sync"></i> รีเฟรช
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Savings List -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-list"></i> รายการบัญชีออมเงิน</h3>
            <div class="card-actions">
                <span id="savingsCount" class="badge badge-info">0 รายการ</span>
            </div>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th width="140">รหัสบัญชี</th>
                            <th>ลูกค้า</th>
                            <th>สินค้า (Ref ID)</th>
                            <th width="120">ราคาเป้า</th>
                            <th width="120">ยอดสะสม</th>
                            <th width="80">%</th>
                            <th width="80">สถานะ</th>
                            <th width="100">วันหมดอายุ</th>
                            <th width="100">การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody id="savingsTableBody">
                        <tr class="table-loading">
                            <td colspan="9">กำลังโหลด...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Savings Detail Modal -->
<div class="modal-overlay" id="savingsDetailModal">
    <div class="modal modal-lg">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-piggy-bank"></i> รายละเอียดบัญชีออม</h3>
            <button class="modal-close" onclick="closeSavingsModal()">&times;</button>
        </div>
        <div class="modal-body" id="savingsDetailBody">
            <!-- Content loaded dynamically -->
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeSavingsModal()">ปิด</button>
            <button class="btn btn-success" id="btnComplete" onclick="completeSavings()">
                <i class="fas fa-check"></i> ครบแล้ว - สร้าง Order
            </button>
            <button class="btn btn-danger" id="btnCancel" onclick="cancelSavings()">
                <i class="fas fa-times"></i> ยกเลิก
            </button>
        </div>
    </div>
</div>

<!-- Manual Deposit Modal -->
<div class="modal-overlay" id="depositModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-plus-circle"></i> บันทึกเงินฝาก</h3>
            <button class="modal-close" onclick="closeDepositModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">จำนวนเงิน (บาท)</label>
                <input type="number" id="depositAmount" class="form-control" placeholder="0.00" min="1">
            </div>
            <div class="form-group">
                <label class="form-label">หมายเหตุ</label>
                <input type="text" id="depositNote" class="form-control" placeholder="เช่น สลิปโอนวันที่...">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeDepositModal()">ยกเลิก</button>
            <button class="btn btn-success" onclick="submitDeposit()">
                <i class="fas fa-save"></i> บันทึก
            </button>
        </div>
    </div>
</div>

<style>
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
.progress-bar-fill.low { background: linear-gradient(90deg, #ff9800, #ffc107); }
.progress-bar-fill.high { background: linear-gradient(90deg, #4caf50, #8bc34a); }
.progress-bar-fill.complete { background: linear-gradient(90deg, #2196f3, #03a9f4); }
.status-badge {
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 500;
}
.status-badge.active { background: #e8f5e9; color: #2e7d32; }
.status-badge.completed { background: #e3f2fd; color: #1565c0; }
.status-badge.cancelled { background: #ffebee; color: #c62828; }
.status-badge.expired { background: #fafafa; color: #757575; }
.status-badge.pending { background: #fff3e0; color: #e65100; }
.transaction-list {
    max-height: 300px;
    overflow-y: auto;
}
.transaction-item {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 0.75rem;
    border-bottom: 1px solid #eee;
    gap: 1rem;
}
.transaction-item.pending {
    background: #fff8e1;
    border-left: 3px solid #ffc107;
    margin-bottom: 0.5rem;
    border-radius: 4px;
}
.transaction-item:last-child { border-bottom: none; }
.transaction-amount {
    font-weight: 600;
    color: #2e7d32;
    font-size: 1rem;
}
.transaction-amount.withdrawal { color: #c62828; }
.transaction-actions {
    display: flex;
    gap: 0.25rem;
    margin-top: 0.5rem;
}
.btn-xs {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}
</style>

<script>
let currentSavingsId = null;
let searchTimeout = null;

document.addEventListener('DOMContentLoaded', function() {
    // Check admin authentication
    const token = localStorage.getItem('admin_token');
    if (!token) {
        window.location.href = 'login.html';
        return;
    }
    loadSavings();
    loadStats();
    
    // Auto-refresh every 60 seconds
    setInterval(() => {
        loadSavings();
        loadStats();
    }, 60000);
});

function debounceSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(loadSavings, 500);
}

async function loadStats() {
    try {
        const response = await adminApiCall(PATH.api('api/admin/savings') + '?stats=1');
        if (response.success && response.data) {
            document.getElementById('statTotal').textContent = response.data.total || 0;
            document.getElementById('statActive').textContent = response.data.active || 0;
            document.getElementById('statTotalAmount').textContent = formatMoney(response.data.total_amount || 0);
            document.getElementById('statNearDue').textContent = response.data.near_due || 0;
        }
    } catch (error) {
        console.error('Error loading stats:', error);
    }
}

async function loadSavings() {
    const tbody = document.getElementById('savingsTableBody');
    tbody.innerHTML = '<tr class="table-loading"><td colspan="9">กำลังโหลด...</td></tr>';
    
    const params = new URLSearchParams();
    const status = document.getElementById('filterStatus').value;
    const search = document.getElementById('filterSearch').value;
    
    if (status) params.append('status', status);
    if (search) params.append('search', search);
    
    try {
        const url = PATH.api('api/admin/savings') + (params.toString() ? '?' + params.toString() : '');
        const response = await adminApiCall(url);
        
        if (response.success && response.data) {
            renderSavings(response.data);
            document.getElementById('savingsCount').textContent = response.data.length + ' รายการ';
        } else {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">ไม่พบข้อมูล</td></tr>';
        }
    } catch (error) {
        console.error('Error loading savings:', error);
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-danger">เกิดข้อผิดพลาด</td></tr>';
    }
}

function renderSavings(savings) {
    const tbody = document.getElementById('savingsTableBody');
    
    if (!savings || savings.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">ไม่พบข้อมูล</td></tr>';
        return;
    }
    
    tbody.innerHTML = savings.map(s => {
        const percentage = s.target_amount > 0 ? Math.round((s.current_amount / s.target_amount) * 100) : 0;
        const progressClass = percentage >= 100 ? 'complete' : percentage >= 50 ? 'high' : 'low';
        
        const statusLabels = {
            'active': 'Active',
            'completed': 'ครบแล้ว',
            'cancelled': 'ยกเลิก',
            'expired': 'หมดอายุ'
        };
        
        const expiresAt = s.target_date ? formatDate(s.target_date) : '-';
        const customerName = s.customer_name || s.external_user_id || 'ไม่ระบุ';
        
        return `
            <tr onclick="openSavingsDetail('${s.account_no || s.id}')" style="cursor: pointer;">
                <td><strong>${escapeHtml(s.account_no)}</strong></td>
                <td>${escapeHtml(customerName)}</td>
                <td>${escapeHtml(s.product_name || '-')}<br><small class="text-muted">${escapeHtml(s.ref_id || '-')}</small></td>
                <td class="text-right">${formatMoney(s.target_amount)}</td>
                <td class="text-right">${formatMoney(s.current_amount)}</td>
                <td>
                    <div class="progress-bar-container">
                        <div class="progress-bar-fill ${progressClass}" style="width: ${Math.min(percentage, 100)}%"></div>
                    </div>
                    <small>${percentage}%</small>
                </td>
                <td><span class="status-badge ${s.status}">${statusLabels[s.status] || s.status}</span></td>
                <td>${expiresAt}</td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="event.stopPropagation(); openSavingsDetail('${s.account_no || s.id}')">
                        <i class="fas fa-eye"></i>
                    </button>
                    ${s.status === 'active' ? `
                    <button class="btn btn-sm btn-success" onclick="event.stopPropagation(); openDepositModal('${s.account_no || s.id}')">
                        <i class="fas fa-plus"></i>
                    </button>
                    ` : ''}
                </td>
            </tr>
        `;
    }).join('');
}

async function openSavingsDetail(savingsId) {
    currentSavingsId = savingsId;
    document.getElementById('savingsDetailModal').classList.add('active');
    document.getElementById('savingsDetailBody').innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    
    try {
        const response = await adminApiCall(PATH.api('api/admin/savings/' + encodeURIComponent(savingsId)));
        
        if (response.success && response.data) {
            renderSavingsDetail(response.data);
        } else {
            document.getElementById('savingsDetailBody').innerHTML = '<div class="alert alert-danger">ไม่พบข้อมูล</div>';
        }
    } catch (error) {
        console.error('Error loading savings detail:', error);
        document.getElementById('savingsDetailBody').innerHTML = '<div class="alert alert-danger">เกิดข้อผิดพลาด</div>';
    }
}

function renderSavingsDetail(data) {
    const s = data.account || data;
    const transactions = data.transactions || [];
    
    const percentage = s.target_amount > 0 ? Math.round((s.current_amount / s.target_amount) * 100) : 0;
    const progressClass = percentage >= 100 ? 'complete' : percentage >= 50 ? 'high' : 'low';
    
    const statusLabels = {
        'active': 'Active',
        'completed': 'ครบแล้ว',
        'cancelled': 'ยกเลิก',
        'expired': 'หมดอายุ'
    };
    
    let transactionsHtml = '<p class="text-muted">ไม่มีรายการ</p>';
    if (transactions.length > 0) {
        transactionsHtml = '<div class="transaction-list">';
        transactions.forEach(t => {
            const isWithdrawal = t.transaction_type === 'withdrawal' || t.transaction_type === 'refund';
            const isPending = t.status === 'pending';
            const statusBadge = isPending ? '<span class="status-badge pending">รอตรวจสอบ</span>' : 
                               t.status === 'verified' ? '<span class="status-badge active">อนุมัติแล้ว</span>' :
                               t.status === 'rejected' ? '<span class="status-badge cancelled">ปฏิเสธ</span>' : '';
            
            const actionButtons = isPending ? `
                <div class="transaction-actions">
                    <button class="btn btn-xs btn-success" onclick="approveTransaction(${t.id})" title="อนุมัติ">
                        <i class="fas fa-check"></i>
                    </button>
                    <button class="btn btn-xs btn-danger" onclick="rejectTransaction(${t.id})" title="ปฏิเสธ">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            ` : '';
            
            transactionsHtml += `
                <div class="transaction-item ${isPending ? 'pending' : ''}">
                    <div>
                        <strong>${t.transaction_type}</strong> ${statusBadge}
                        <div class="text-muted" style="font-size: 0.75rem;">${formatDateTime(t.created_at)}</div>
                        ${t.notes ? `<div style="font-size: 0.75rem;">${escapeHtml(t.notes)}</div>` : ''}
                        ${t.slip_image_url ? `<a href="${t.slip_image_url}" target="_blank" class="btn btn-xs btn-outline" style="margin-top: 4px;"><i class="fas fa-image"></i> ดูสลิป</a>` : ''}
                    </div>
                    <div style="text-align: right;">
                        <div class="transaction-amount ${isWithdrawal ? 'withdrawal' : ''}">
                            ${isWithdrawal ? '-' : '+'}${formatMoney(t.amount)}
                        </div>
                        ${actionButtons}
                    </div>
                </div>
            `;
        });
        transactionsHtml += '</div>';
    }
    
    document.getElementById('savingsDetailBody').innerHTML = `
        <div class="row">
            <div class="col-6">
                <h5>ข้อมูลบัญชี</h5>
                <table class="table table-sm">
                    <tr><td><strong>รหัสบัญชี:</strong></td><td>${escapeHtml(s.account_no)}</td></tr>
                    <tr><td><strong>สถานะ:</strong></td><td><span class="status-badge ${s.status}">${statusLabels[s.status] || s.status}</span></td></tr>
                    <tr><td><strong>สินค้า:</strong></td><td>${escapeHtml(s.product_name || '-')}</td></tr>
                    <tr><td><strong>Ref ID:</strong></td><td>${escapeHtml(s.ref_id || '-')}</td></tr>
                    <tr><td><strong>ราคาเป้าหมาย:</strong></td><td>${formatMoney(s.target_amount)}</td></tr>
                    <tr><td><strong>ยอดสะสม:</strong></td><td>${formatMoney(s.current_amount)}</td></tr>
                    <tr><td><strong>ความคืบหน้า:</strong></td><td>
                        <div class="progress-bar-container" style="width: 150px; display: inline-block; vertical-align: middle;">
                            <div class="progress-bar-fill ${progressClass}" style="width: ${Math.min(percentage, 100)}%"></div>
                        </div>
                        <strong style="margin-left: 8px;">${percentage}%</strong>
                    </td></tr>
                    <tr><td><strong>วันเป้าหมาย:</strong></td><td>${s.target_date ? formatDate(s.target_date) : '-'}</td></tr>
                    <tr><td><strong>สร้างเมื่อ:</strong></td><td>${formatDateTime(s.created_at)}</td></tr>
                </table>
            </div>
            <div class="col-6">
                <h5>รายการธุรกรรม</h5>
                ${transactionsHtml}
            </div>
        </div>
    `;
    
    // Show/hide buttons based on status
    document.getElementById('btnComplete').style.display = (s.status === 'active' && percentage >= 100) ? 'inline-block' : 'none';
    document.getElementById('btnCancel').style.display = (s.status === 'active') ? 'inline-block' : 'none';
}

function closeSavingsModal() {
    document.getElementById('savingsDetailModal').classList.remove('active');
    currentSavingsId = null;
}

function openDepositModal(savingsId) {
    currentSavingsId = savingsId;
    document.getElementById('depositModal').classList.add('active');
    document.getElementById('depositAmount').value = '';
    document.getElementById('depositNote').value = '';
}

function closeDepositModal() {
    document.getElementById('depositModal').classList.remove('active');
}

async function submitDeposit() {
    const amount = parseFloat(document.getElementById('depositAmount').value);
    const notes = document.getElementById('depositNote').value.trim();
    
    if (!amount || amount <= 0) {
        showToast('กรุณากรอกจำนวนเงิน', 'warning');
        return;
    }
    
    try {
        const response = await adminApiCall(PATH.api('api/admin/savings/' + encodeURIComponent(currentSavingsId) + '/deposit'), {
            method: 'POST',
            body: JSON.stringify({ amount, notes })
        });
        
        if (response.success) {
            showToast('บันทึกเงินฝากเรียบร้อย', 'success');
            closeDepositModal();
            loadSavings();
            loadStats();
        } else {
            showToast(response.error || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (error) {
        console.error('Error submitting deposit:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

async function completeSavings() {
    if (!currentSavingsId) return;
    if (!confirm('ยืนยันว่าออมครบแล้ว? ระบบจะสร้าง Order ให้อัตโนมัติ')) return;
    
    try {
        const response = await adminApiCall(PATH.api('api/admin/savings/' + encodeURIComponent(currentSavingsId)), {
            method: 'PUT',
            body: JSON.stringify({ status: 'completed' })
        });
        
        if (response.success) {
            showToast('ปิดบัญชีออมเรียบร้อย', 'success');
            closeSavingsModal();
            loadSavings();
            loadStats();
        } else {
            showToast(response.error || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (error) {
        console.error('Error completing savings:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

async function cancelSavings() {
    if (!currentSavingsId) return;
    if (!confirm('ยืนยันยกเลิกบัญชีออม? ต้องคืนเงินให้ลูกค้าด้วยตนเอง')) return;
    
    try {
        const response = await adminApiCall(PATH.api('api/admin/savings/' + encodeURIComponent(currentSavingsId)), {
            method: 'PUT',
            body: JSON.stringify({ status: 'cancelled' })
        });
        
        if (response.success) {
            showToast('ยกเลิกบัญชีออมเรียบร้อย', 'success');
            closeSavingsModal();
            loadSavings();
            loadStats();
        } else {
            showToast(response.error || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (error) {
        console.error('Error cancelling savings:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

// Approve pending transaction
async function approveTransaction(transactionId) {
    if (!confirm('ยืนยันอนุมัติรายการนี้?')) return;
    
    try {
        const response = await adminApiCall(PATH.api('api/admin/savings/transactions/' + transactionId + '/approve'), {
            method: 'POST'
        });
        
        if (response.success) {
            showToast('อนุมัติรายการสำเร็จ! ยอดเงินถูกเพิ่มแล้ว', 'success');
            // Reload detail
            if (currentSavingsId) {
                openSavingsDetail(currentSavingsId);
            }
            loadSavings();
            loadStats();
        } else {
            showToast(response.error || response.message || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (error) {
        console.error('Error approving transaction:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

// Reject pending transaction
async function rejectTransaction(transactionId) {
    const reason = prompt('กรุณาระบุเหตุผลในการปฏิเสธ:');
    if (reason === null) return; // User cancelled
    
    try {
        const response = await adminApiCall(PATH.api('api/admin/savings/transactions/' + transactionId + '/reject'), {
            method: 'POST',
            body: JSON.stringify({ reason: reason || 'ไม่ระบุเหตุผล' })
        });
        
        if (response.success) {
            showToast('ปฏิเสธรายการสำเร็จ', 'warning');
            // Reload detail
            if (currentSavingsId) {
                openSavingsDetail(currentSavingsId);
            }
            loadSavings();
        } else {
            showToast(response.error || response.message || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (error) {
        console.error('Error rejecting transaction:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

function formatMoney(amount) {
    return new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB' }).format(amount || 0);
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
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
