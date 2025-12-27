<?php
/**
 * Admin Payments Management
 */
define('INCLUDE_CHECK', true);

$page_title = "Payments Management - Admin Panel";
$current_page = "payments";

include('../../includes/admin/header.php');
include('../../includes/admin/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-money-check-alt"></i> Payments Management</h1>
        <p class="page-subtitle">ตรวจสอบและอนุมัติการชำระเงิน</p>
    </div>

    <!-- Stats -->
    <div class="row">
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon warning"><i class="fas fa-clock"></i></div>
                <div class="stat-content">
                    <div class="stat-label">รอตรวจสอบ</div>
                    <div class="stat-value" id="pendingCount">-</div>
                </div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon primary"><i class="fas fa-search"></i></div>
                <div class="stat-content">
                    <div class="stat-label">กำลังตรวจสอบ</div>
                    <div class="stat-value" id="verifyingCount">-</div>
                </div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon success"><i class="fas fa-check-circle"></i></div>
                <div class="stat-content">
                    <div class="stat-label">อนุมัติแล้ว</div>
                    <div class="stat-value" id="verifiedCount">-</div>
                </div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon danger"><i class="fas fa-times-circle"></i></div>
                <div class="stat-content">
                    <div class="stat-label">ปฏิเสธ</div>
                    <div class="stat-value" id="rejectedCount">-</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mt-4">
        <div class="card-body">
            <div class="row">
                <div class="col-3">
                    <label>สถานะ:</label>
                    <select id="statusFilter" class="form-control">
                        <option value="">ทั้งหมด</option>
                        <option value="pending" selected>รอตรวจสอบ</option>
                        <option value="verifying">กำลังตรวจสอบ</option>
                        <option value="verified">อนุมัติแล้ว</option>
                        <option value="rejected">ปฏิเสธ</option>
                    </select>
                </div>
                <div class="col-3">
                    <label>ประเภท:</label>
                    <select id="typeFilter" class="form-control">
                        <option value="">ทั้งหมด</option>
                        <option value="full">จ่ายเต็ม</option>
                        <option value="installment">ผ่อนชำระ</option>
                   </select>
                </div>
                <div class="col-3">
                    <label>ค้นหา:</label>
                    <input type="text" id="searchFilter" class="form-control" placeholder="เลขชำระ, ชื่อลูกค้า...">
                </div>
                <div class="col-3">
                    <label>&nbsp;</label>
                    <button class="btn btn-primary" onclick="loadPayments()" style="width: 100%;">
                        <i class="fas fa-search"></i> ค้นหา
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Payments List -->
    <div class="card mt-4">
        <div class="card-header">
            <h3 class="card-title">รายการชำระเงิน</h3>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>เลขชำระ</th>
                            <th>ลูกค้า</th>
                            <th>คำสั่งซื้อ</th>
                            <th>จำนวนเงิน</th>
                            <th>ประเภท</th>
                            <th>วันที่</th>
                            <th>สถานะ</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="paymentsTableBody">
                        <tr><td colspan="8" style="text-align: center; padding: 2rem;">กำลังโหลด...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Payment Details Modal -->
<div id="paymentModal" class="modal">
    <div class="modal-content" style="max-width: 900px;">
        <span class="close" onclick="closeModal()">&times;</span>
        <div id="paymentDetails"></div>
    </div>
</div>

<style>
.slip-image {
    max-width: 100%;
    max-height: 600px;
    border-radius: 8px;
    border: 2px solid var(--color-border);
    cursor: zoom-in;
}

.slip-image:active {
    cursor: zoom-out;
    transform: scale(1.5);
    transition: transform 0.3s;
}

.status-pending { background: #fbbf24; color: #78350f; }
.status-verifying { background: #60a5fa; color: #1e3a8a; }
.status-verified { background: #34d399; color: #065f46; }
.status-rejected { background: #f87171; color: #7f1d1d; }

.action-buttons {
    display: flex;
    gap: 0.5rem;
    margin-top: 1.5rem;
}
</style>

<?php
$inline_script = <<<'JAVASCRIPT'
let currentPaymentId = null;

async function loadPayments() {
    const token = localStorage.getItem('admin_token');
    const tbody = document.getElementById('paymentsTableBody');
    
    const status = document.getElementById('statusFilter').value;
    const type = document.getElementById('typeFilter').value;
    const search = document.getElementById('searchFilter').value;
    
    let url = '/api/admin/payments?limit=50';
    if (status) url += `&status=${status}`;
    if (type) url += `&payment_type=${type}`;
    if (search) url += `&search=${search}`;

    try {
        const response = await fetch(url, {
            headers: { 'Authorization': 'Bearer ' + token }
        });

        const result = await response.json();
        if (result.success && result.data.payments.length > 0) {
            tbody.innerHTML = result.data.payments.map(payment => `
                <tr>
                    <td><strong>${payment.payment_no}</strong></td>
                    <td>${payment.customer_name}<br><small>${payment.customer_email}</small></td>
                    <td>${payment.order_no}<br><small>${payment.product_name}</small></td>
                    <td>${formatMoney(payment.amount)}</td>
                    <td>${payment.payment_type === 'full' ? '💳 จ่ายเต็ม' : `📅 งวดที่ ${payment.current_period}/${payment.installment_period}`}</td>
                    <td>${formatDate(payment.payment_date || payment.created_at)}</td>
                    <td><span class="status-badge status-${payment.status}">${getStatusText(payment.status)}</span></td>
                    <td><button class="btn btn-sm btn-primary" onclick="viewPayment(${payment.id})"><i class="fas fa-eye"></i></button></td>
                </tr>
            `).join('');
            
            updateStats(result.data.stats);
        } else {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; padding: 2rem;">ไม่พบข้อมูล</td></tr>';
        }
    } catch (error) {
        console.error('Error:', error);
        tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; color: var(--color-danger);">เกิดข้อผิดพลาด</td></tr>';
    }
}

function updateStats(stats) {
    if (!stats) return;
    document.getElementById('pendingCount').textContent = stats.pending || 0;
    document.getElementById('verifyingCount').textContent = stats.verifying || 0;
    document.getElementById('verifiedCount').textContent = stats.verified || 0;
    document.getElementById('rejectedCount').textContent = stats.rejected || 0;
}

async function viewPayment(paymentId) {
    currentPaymentId = paymentId;
    const token = localStorage.getItem('admin_token');
    const modal = document.getElementById('paymentModal');
    const details = document.getElementById('paymentDetails');
    
    modal.style.display = 'block';
    details.innerHTML = '<p style="text-align: center;">กำลังโหลด...</p>';

    try {
        const response = await fetch(`/api/admin/payments/${paymentId}`, {
            headers: { 'Authorization': 'Bearer ' + token }
        });

        const result = await response.json();
        if (result.success) {
            displayPaymentDetails(result.data);
        }
    } catch (error) {
        console.error('Error:', error);
        details.innerHTML = '<p style="color: var(--color-danger);">เกิดข้อผิดพลาด</p>';
    }
}

function displayPaymentDetails(payment) {
    const details = document.getElementById('paymentDetails');
    const bankInfo = payment.payment_details?.bank_info || {};
    
    let actionButtons = '';
    if (payment.status === 'pending' || payment.status === 'verifying') {
        actionButtons = `
            <div class="action-buttons">
                <button class="btn btn-success" onclick="approvePayment(${payment.id})" style="flex: 1;">
                    <i class="fas fa-check"></i> อนุมัติ
                </button>
                <button class="btn btn-danger" onclick="rejectPayment(${payment.id})" style="flex: 1;">
                    <i class="fas fa-times"></i> ปฏิเสธ
                </button>
            </div>
        `;
    }
    
    details.innerHTML = `
        <h2>รายละเอียดการชำระเงิน</h2>
        <div style="margin-top: 1.5rem;">
            <div class="row">
                <div class="col-6">
                    <p><strong>เลขชำระ:</strong> ${payment.payment_no}</p>
                    <p><strong>ลูกค้า:</strong> ${payment.customer_name} (${payment.customer_email})</p>
                    <p><strong>คำสั่งซื้อ:</strong> ${payment.order_no}</p>
                    <p><strong>สินค้า:</strong> ${payment.product_name}</p>
                </div>
                <div class="col-6">
                    <p><strong>จำนวนเงิน:</strong> ${formatMoney(payment.amount)}</p>
                    <p><strong>ประเภท:</strong> ${payment.payment_type === 'full' ? 'จ่ายเต็มจำนวน' : `ผ่อนชำระ งวดที่ ${payment.current_period}/${payment.installment_period}`}</p>
                    <p><strong>วิธีชำระ:</strong> ${getPaymentMethod(payment.payment_method)}</p>
                    <p><strong>สถานะ:</strong> <span class="status-badge status-${payment.status}">${getStatusText(payment.status)}</span></p>
                </div>
            </div>
            
            ${bankInfo.bank_name ? `
                <hr>
                <h4>ข้อมูลการโอน</h4>
                <p><strong>ธนาคาร:</strong> ${bankInfo.bank_name}</p>
                ${bankInfo.transfer_time ? `<p><strong>เวลาโอน:</strong> ${bankInfo.transfer_time}</p>` : ''}
            ` : ''}
            
            ${payment.verified_at ? `<p><strong>ตรวจสอบเมื่อ:</strong> ${formatDateTime(payment.verified_at)}</p>` : ''}
            ${payment.rejection_reason ? `<p style="color: var(--color-danger);"><strong>เหตุผลที่ปฏิเสธ:</strong> ${payment.rejection_reason}</p>` : ''}
        </div>
        
        ${payment.slip_image ? `
            <h4 style="margin-top: 2rem;">สลิปการโอนเงิน</h4>
            <div style="margin-top: 1rem; text-align: center;">
                <img src="${payment.slip_image}" alt="สลิป" class="slip-image">
            </div>
        ` : '<p style="color: var(--color-gray); margin-top: 1rem;">ไม่มีสลิป</p>'}
        
        ${actionButtons}
    `;
}

async function approvePayment(paymentId) {
    if (!confirm('ยืนยันการอนุมัติการชำระเงินนี้?')) return;
    
    const token = localStorage.getItem('admin_token');
    
    try {
        const response = await fetch(`/api/admin/payments/${paymentId}/approve`, {
            method: 'PUT',
            headers: {
                'Authorization': 'Bearer ' + token,
                'Content-Type': 'application/json'
            }
        });

        const result = await response.json();
        if (result.success) {
            alert('อนุมัติการชำระเงินเรียบร้อย');
            closeModal();
            loadPayments();
        } else {
            alert('เกิดข้อผิดพลาด: ' + result.message);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('เกิดข้อผิดพลาด');
    }
}

async function rejectPayment(paymentId) {
    const reason = prompt('กรุณาระบุเหตุผลที่ปฏิเสธ:');
    if (!reason) return;
    
    const token = localStorage.getItem('admin_token');
    
    try {
        const response = await fetch(`/api/admin/payments/${paymentId}/reject`, {
            method: 'PUT',
            headers: {
                'Authorization': 'Bearer ' + token,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ reason })
        });

        const result = await response.json();
        if (result.success) {
            alert('ปฏิเสธการชำระเงินเรียบร้อย');
            closeModal();
            loadPayments();
        } else {
            alert('เกิดข้อผิดพลาด: ' + result.message);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('เกิดข้อผิดพลาด');
    }
}

function closeModal() {
    document.getElementById('paymentModal').style.display = 'none';
}

function getStatusText(status) {
    const statuses = {
        'pending': 'รอตรวจสอบ',
        'verifying': 'กำลังตรวจสอบ',
        'verified': 'อนุมัติแล้ว',
        'rejected': 'ปฏิเสธ'
    };
    return statuses[status] || status;
}

function getPaymentMethod(method) {
    const methods = {
        'bank_transfer': 'โอนเงินผ่านธนาคาร',
        'promptpay': 'พร้อมเพย์',
        'credit_card': 'บัตรเครดิต',
        'cash': 'เงินสด'
    };
    return methods[method] || method;
}

function formatMoney(amount) {
    return new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB' }).format(amount);
}

function formatDate(date) {
    return new Date(date).toLocaleDateString('th-TH', { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatDateTime(datetime) {
    return new Date(datetime).toLocaleDateString('th-TH', { 
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit'
    });
}

document.addEventListener('DOMContentLoaded', loadPayments);
JAVASCRIPT;

include('../../includes/admin/footer.php');
?>
