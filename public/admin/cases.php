<?php
/**
 * Admin Case Inbox - Combined FB + LINE conversations
 * ร้านเฮง เฮง เฮง - Chatbot Commerce System
 */
define('INCLUDE_CHECK', true);

$page_title = "Case Inbox - ร้านเฮง เฮง เฮง";
$current_page = "cases";

include('../../includes/admin/header.php');
include('../../includes/admin/sidebar.php');
?>

<!-- Main Content -->
<main class="main-content">
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-inbox"></i> Case Inbox</h1>
        <p class="page-subtitle">จัดการเคสลูกค้าจาก Facebook Messenger และ LINE OA</p>
    </div>

    <!-- Filter Bar -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-2">
                    <label class="form-label">ช่องทาง</label>
                    <select id="filterChannel" class="form-control" onchange="loadCases()">
                        <option value="">ทั้งหมด</option>
                        <option value="facebook">Facebook</option>
                        <option value="line">LINE</option>
                    </select>
                </div>
                <div class="col-2">
                    <label class="form-label">ประเภทเคส</label>
                    <select id="filterCaseType" class="form-control" onchange="loadCases()">
                        <option value="">ทั้งหมด</option>
                        <option value="product_inquiry">สอบถามสินค้า</option>
                        <option value="payment_full">ชำระเต็มจำนวน</option>
                        <option value="payment_installment">ผ่อนชำระ</option>
                        <option value="payment_savings">ออมเงิน</option>
                    </select>
                </div>
                <div class="col-2">
                    <label class="form-label">สถานะ</label>
                    <select id="filterStatus" class="form-control" onchange="loadCases()">
                        <option value="">ทั้งหมด</option>
                        <option value="open">เปิดอยู่</option>
                        <option value="pending_admin">รอแอดมิน</option>
                        <option value="pending_payment">รอชำระ</option>
                        <option value="closed">ปิดแล้ว</option>
                    </select>
                </div>
                <div class="col-3">
                    <label class="form-label">ค้นหา</label>
                    <input type="text" id="filterSearch" class="form-control" placeholder="ชื่อ, เบอร์โทร, สินค้า..." onkeyup="debounceSearch()">
                </div>
                <div class="col-3 text-right">
                    <label class="form-label">&nbsp;</label>
                    <button class="btn btn-primary btn-block" onclick="loadCases()">
                        <i class="fas fa-sync"></i> รีเฟรช
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon warning"><i class="fas fa-exclamation-circle"></i></div>
                <div class="stat-content">
                    <div class="stat-label">รอแอดมิน</div>
                    <div class="stat-value" id="statPendingAdmin">-</div>
                </div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon info"><i class="fas fa-clock"></i></div>
                <div class="stat-content">
                    <div class="stat-label">รอชำระเงิน</div>
                    <div class="stat-value" id="statPendingPayment">-</div>
                </div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon primary"><i class="fas fa-folder-open"></i></div>
                <div class="stat-content">
                    <div class="stat-label">เคสเปิดอยู่</div>
                    <div class="stat-value" id="statOpen">-</div>
                </div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon secondary"><i class="fas fa-check-circle"></i></div>
                <div class="stat-content">
                    <div class="stat-label">ปิดวันนี้</div>
                    <div class="stat-value" id="statClosedToday">-</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Case List -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-list"></i> รายการเคส</h3>
            <div class="card-actions">
                <span id="caseCount" class="badge badge-info">0 รายการ</span>
            </div>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th width="60">ช่องทาง</th>
                            <th width="120">เคส</th>
                            <th>ลูกค้า</th>
                            <th>ประเภท</th>
                            <th>สินค้า</th>
                            <th>สถานะ</th>
                            <th width="120">อัพเดทล่าสุด</th>
                            <th width="100">การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody id="caseTableBody">
                        <tr class="table-loading">
                            <td colspan="8">กำลังโหลด...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Case Detail Modal -->
<div class="modal-overlay" id="caseDetailModal">
    <div class="modal modal-lg">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-folder-open"></i> รายละเอียดเคส</h3>
            <button class="modal-close" onclick="closeCaseModal()">&times;</button>
        </div>
        <div class="modal-body" id="caseDetailBody">
            <!-- Content loaded dynamically -->
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeCaseModal()">ปิด</button>
            <button class="btn btn-success" id="btnTakeOver" onclick="takeOverCase()">
                <i class="fas fa-hand-paper"></i> รับเคส
            </button>
            <button class="btn btn-primary" id="btnReplyChat" onclick="openReplyBox()">
                <i class="fas fa-reply"></i> ตอบกลับ
            </button>
        </div>
    </div>
</div>

<!-- Reply Modal -->
<div class="modal-overlay" id="replyModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title"><i class="fas fa-reply"></i> ตอบกลับลูกค้า</h3>
            <button class="modal-close" onclick="closeReplyModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">ข้อความ</label>
                <textarea id="replyMessage" class="form-control" rows="4" placeholder="พิมพ์ข้อความตอบกลับ..."></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Quick Replies</label>
                <div class="quick-reply-buttons">
                    <button class="btn btn-sm btn-outline" onclick="insertQuickReply('สินค้ายังมีอยู่ค่ะ สนใจติดต่อเพิ่มเติมได้เลยนะคะ')">ยังมี</button>
                    <button class="btn btn-sm btn-outline" onclick="insertQuickReply('ขอบคุณที่สนใจค่ะ ราคาพิเศษ xxx บาท')">แจ้งราคา</button>
                    <button class="btn btn-sm btn-outline" onclick="insertQuickReply('ได้รับสลิปเรียบร้อยแล้วค่ะ กำลังตรวจสอบ')">รับสลิป</button>
                    <button class="btn btn-sm btn-outline" onclick="insertQuickReply('ยืนยันการชำระเงินเรียบร้อยค่ะ จะจัดส่งภายใน 1-2 วัน')">ยืนยันชำระ</button>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeReplyModal()">ยกเลิก</button>
            <button class="btn btn-primary" onclick="sendReply()">
                <i class="fas fa-paper-plane"></i> ส่งข้อความ
            </button>
        </div>
    </div>
</div>

<style>
.quick-reply-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}
.quick-reply-buttons .btn {
    font-size: 0.75rem;
}
.channel-icon {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 0.875rem;
}
.channel-icon.facebook { background: #1877f2; }
.channel-icon.line { background: #00b900; }
.case-type-badge {
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 500;
}
.case-type-badge.product_inquiry { background: #e3f2fd; color: #1565c0; }
.case-type-badge.payment_full { background: #e8f5e9; color: #2e7d32; }
.case-type-badge.payment_installment { background: #fff3e0; color: #ef6c00; }
.case-type-badge.payment_savings { background: #fce4ec; color: #c2185b; }
.status-badge {
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 500;
}
.status-badge.open { background: #e3f2fd; color: #1565c0; }
.status-badge.pending_admin { background: #fff3e0; color: #ef6c00; }
.status-badge.pending_payment { background: #fff8e1; color: #f9a825; }
.status-badge.closed { background: #e8f5e9; color: #2e7d32; }
.activity-timeline {
    border-left: 2px solid #e0e0e0;
    margin-left: 1rem;
    padding-left: 1.5rem;
}
.activity-item {
    position: relative;
    padding-bottom: 1rem;
}
.activity-item::before {
    content: '';
    position: absolute;
    left: -1.65rem;
    top: 0.25rem;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--primary);
}
.activity-item.bot::before { background: #9e9e9e; }
.activity-item.admin::before { background: #1877f2; }
.activity-item.customer::before { background: #00b900; }
.activity-time {
    font-size: 0.75rem;
    color: #9e9e9e;
}
.activity-content {
    background: #f5f5f5;
    padding: 0.5rem 0.75rem;
    border-radius: 8px;
    margin-top: 0.25rem;
}
</style>

<script>
let currentCaseId = null;
let searchTimeout = null;

document.addEventListener('DOMContentLoaded', function() {
    // Check admin authentication
    const token = localStorage.getItem('admin_token');
    if (!token) {
        window.location.href = 'login.html';
        return;
    }
    loadCases();
    loadStats();
    
    // Auto-refresh every 30 seconds
    setInterval(() => {
        loadCases();
        loadStats();
    }, 30000);
});

function debounceSearch() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(loadCases, 500);
}

async function loadStats() {
    try {
        const response = await adminApiCall(PATH.api('api/admin/cases') + '?stats=1');
        if (response.success) {
            document.getElementById('statPendingAdmin').textContent = response.data.pending_admin || 0;
            document.getElementById('statPendingPayment').textContent = response.data.pending_payment || 0;
            document.getElementById('statOpen').textContent = response.data.open || 0;
            document.getElementById('statClosedToday').textContent = response.data.closed_today || 0;
        }
    } catch (error) {
        console.error('Error loading stats:', error);
    }
}

async function loadCases() {
    const tbody = document.getElementById('caseTableBody');
    tbody.innerHTML = '<tr class="table-loading"><td colspan="8">กำลังโหลด...</td></tr>';
    
    const params = new URLSearchParams();
    const channel = document.getElementById('filterChannel').value;
    const caseType = document.getElementById('filterCaseType').value;
    const status = document.getElementById('filterStatus').value;
    const search = document.getElementById('filterSearch').value;
    
    if (channel) params.append('platform', channel);
    if (caseType) params.append('case_type', caseType);
    if (status) params.append('status', status);
    if (search) params.append('search', search);
    
    try {
        const url = PATH.api('api/admin/cases') + (params.toString() ? '?' + params.toString() : '');
        const response = await adminApiCall(url);
        
        if (response.success && response.data) {
            renderCases(response.data);
            document.getElementById('caseCount').textContent = response.data.length + ' รายการ';
        } else {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">ไม่พบข้อมูล</td></tr>';
        }
    } catch (error) {
        console.error('Error loading cases:', error);
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">เกิดข้อผิดพลาด</td></tr>';
    }
}

function renderCases(cases) {
    const tbody = document.getElementById('caseTableBody');
    
    if (!cases || cases.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">ไม่พบข้อมูล</td></tr>';
        return;
    }
    
    tbody.innerHTML = cases.map(c => {
        const channelIcon = c.platform === 'facebook' 
            ? '<div class="channel-icon facebook"><i class="fab fa-facebook-f"></i></div>'
            : '<div class="channel-icon line"><i class="fab fa-line"></i></div>';
        
        const caseTypeLabels = {
            'product_inquiry': 'สอบถามสินค้า',
            'payment_full': 'ชำระเต็ม',
            'payment_installment': 'ผ่อนชำระ',
            'payment_savings': 'ออมเงิน'
        };
        
        const statusLabels = {
            'open': 'เปิดอยู่',
            'pending_admin': 'รอแอดมิน',
            'pending_payment': 'รอชำระ',
            'closed': 'ปิดแล้ว'
        };
        
        const productName = c.slots?.product_name || c.product_ref_id || '-';
        const customerName = c.customer_name || c.external_user_id || 'ไม่ระบุ';
        
        return `
            <tr onclick="openCaseDetail(${c.id})" style="cursor: pointer;">
                <td>${channelIcon}</td>
                <td><strong>#${c.id}</strong></td>
                <td>${escapeHtml(customerName)}</td>
                <td><span class="case-type-badge ${c.case_type}">${caseTypeLabels[c.case_type] || c.case_type}</span></td>
                <td>${escapeHtml(productName)}</td>
                <td><span class="status-badge ${c.status}">${statusLabels[c.status] || c.status}</span></td>
                <td>${formatDateTime(c.updated_at)}</td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="event.stopPropagation(); openCaseDetail(${c.id})">
                        <i class="fas fa-eye"></i>
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}

async function openCaseDetail(caseId) {
    currentCaseId = caseId;
    document.getElementById('caseDetailModal').classList.add('active');
    document.getElementById('caseDetailBody').innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    
    try {
        const response = await adminApiCall(PATH.api('api/admin/cases/' + caseId));
        
        if (response.success && response.data) {
            renderCaseDetail(response.data);
        } else {
            document.getElementById('caseDetailBody').innerHTML = '<div class="alert alert-danger">ไม่พบข้อมูลเคส</div>';
        }
    } catch (error) {
        console.error('Error loading case detail:', error);
        document.getElementById('caseDetailBody').innerHTML = '<div class="alert alert-danger">เกิดข้อผิดพลาด</div>';
    }
}

function renderCaseDetail(caseData) {
    const c = caseData.case || caseData;
    const activities = caseData.activities || [];
    
    const caseTypeLabels = {
        'product_inquiry': 'สอบถามสินค้า',
        'payment_full': 'ชำระเต็ม',
        'payment_installment': 'ผ่อนชำระ',
        'payment_savings': 'ออมเงิน'
    };
    
    const statusLabels = {
        'open': 'เปิดอยู่',
        'pending_admin': 'รอแอดมิน',
        'pending_payment': 'รอชำระ',
        'closed': 'ปิดแล้ว'
    };
    
    const channelIcon = c.platform === 'facebook' 
        ? '<i class="fab fa-facebook-f" style="color: #1877f2;"></i> Facebook'
        : '<i class="fab fa-line" style="color: #00b900;"></i> LINE';
    
    const slots = c.slots ? (typeof c.slots === 'string' ? JSON.parse(c.slots) : c.slots) : {};
    
    let slotsHtml = '';
    if (Object.keys(slots).length > 0) {
        slotsHtml = '<div class="mt-3"><strong>ข้อมูลที่เก็บได้:</strong><ul class="mt-1">';
        for (const [key, value] of Object.entries(slots)) {
            slotsHtml += `<li><strong>${escapeHtml(key)}:</strong> ${escapeHtml(String(value))}</li>`;
        }
        slotsHtml += '</ul></div>';
    }
    
    let activitiesHtml = '<p class="text-muted">ไม่มีกิจกรรม</p>';
    if (activities.length > 0) {
        activitiesHtml = '<div class="activity-timeline">';
        activities.forEach(act => {
            const actorClass = act.actor_type || 'bot';
            activitiesHtml += `
                <div class="activity-item ${actorClass}">
                    <div class="activity-time">${formatDateTime(act.created_at)} - ${act.actor_type || 'system'}</div>
                    <div class="activity-content">
                        <strong>${escapeHtml(act.activity_type)}:</strong> ${escapeHtml(act.description || '')}
                    </div>
                </div>
            `;
        });
        activitiesHtml += '</div>';
    }
    
    document.getElementById('caseDetailBody').innerHTML = `
        <div class="row">
            <div class="col-6">
                <h5>ข้อมูลเคส</h5>
                <table class="table table-sm">
                    <tr><td><strong>เคส ID:</strong></td><td>#${c.id}</td></tr>
                    <tr><td><strong>ช่องทาง:</strong></td><td>${channelIcon}</td></tr>
                    <tr><td><strong>ประเภท:</strong></td><td><span class="case-type-badge ${c.case_type}">${caseTypeLabels[c.case_type] || c.case_type}</span></td></tr>
                    <tr><td><strong>สถานะ:</strong></td><td><span class="status-badge ${c.status}">${statusLabels[c.status] || c.status}</span></td></tr>
                    <tr><td><strong>สินค้า:</strong></td><td>${escapeHtml(c.slots?.product_name || c.product_ref_id || '-')}</td></tr>
                    <tr><td><strong>ลูกค้า:</strong></td><td>${escapeHtml(c.customer_name || c.external_user_id || '-')}</td></tr>
                    <tr><td><strong>สร้างเมื่อ:</strong></td><td>${formatDateTime(c.created_at)}</td></tr>
                    <tr><td><strong>อัพเดท:</strong></td><td>${formatDateTime(c.updated_at)}</td></tr>
                </table>
                ${slotsHtml}
            </div>
            <div class="col-6">
                <h5>กิจกรรม</h5>
                ${activitiesHtml}
            </div>
        </div>
    `;
    
    // Show/hide buttons based on status
    document.getElementById('btnTakeOver').style.display = (c.status === 'pending_admin') ? 'inline-block' : 'none';
}

function closeCaseModal() {
    document.getElementById('caseDetailModal').classList.remove('active');
    currentCaseId = null;
}

async function takeOverCase() {
    if (!currentCaseId) return;
    
    try {
        const response = await adminApiCall(PATH.api('api/admin/cases/' + currentCaseId), {
            method: 'PUT',
            body: JSON.stringify({ status: 'open', assigned_admin_id: 'current' })
        });
        
        if (response.success) {
            showToast('รับเคสเรียบร้อย', 'success');
            openCaseDetail(currentCaseId);
            loadCases();
        } else {
            showToast(response.error || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (error) {
        console.error('Error taking over case:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

function openReplyBox() {
    document.getElementById('replyModal').classList.add('active');
    document.getElementById('replyMessage').value = '';
}

function closeReplyModal() {
    document.getElementById('replyModal').classList.remove('active');
}

function insertQuickReply(text) {
    document.getElementById('replyMessage').value = text;
}

async function sendReply() {
    const message = document.getElementById('replyMessage').value.trim();
    if (!message) {
        showToast('กรุณากรอกข้อความ', 'warning');
        return;
    }
    
    // TODO: Implement actual message sending via FB/LINE API
    showToast('ส่งข้อความเรียบร้อย (demo)', 'success');
    closeReplyModal();
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
    // Simple toast implementation
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
