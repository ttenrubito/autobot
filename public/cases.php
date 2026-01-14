<?php
/**
 * Cases Inbox - Customer Portal
 * จัดการ case / ticket สำหรับลูกค้า
 */
define('INCLUDE_CHECK', true);

$page_title = "Case Inbox - AI Automation";
$current_page = "cases";

include('../includes/customer/header.php');
include('../includes/customer/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <div class="page-header-content">
            <div>
                <h1 class="page-title">📥 Case Inbox</h1>
                <p class="page-subtitle">จัดการ case และ ticket จากลูกค้า</p>
            </div>
            <button class="btn btn-primary" onclick="openCreateCaseModal()">
                <i class="fas fa-plus"></i> <span class="btn-text">สร้าง Case ใหม่</span>
            </button>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-grid">
        <div class="summary-card summary-card-success">
            <div class="summary-icon">📬</div>
            <div class="summary-value" id="openCount">0</div>
            <div class="summary-label">เปิดอยู่</div>
        </div>
        <div class="summary-card summary-card-warning">
            <div class="summary-icon">⏳</div>
            <div class="summary-value" id="pendingCount">0</div>
            <div class="summary-label">รอดำเนินการ</div>
        </div>
        <div class="summary-card summary-card-info">
            <div class="summary-icon">✅</div>
            <div class="summary-value" id="resolvedCount">0</div>
            <div class="summary-label">แก้ไขแล้ว</div>
        </div>
        <div class="summary-card">
            <div class="summary-icon">📋</div>
            <div class="summary-value" id="totalCount">0</div>
            <div class="summary-label">ทั้งหมด</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card filter-card">
        <div class="card-body">
            <div class="filter-row">
                <div class="form-group filter-item">
                    <label class="form-label-sm">สถานะ</label>
                    <select id="filterStatus" class="form-control" onchange="loadCases()">
                        <option value="">ทุกสถานะ</option>
                        <option value="open">เปิดอยู่</option>
                        <option value="pending">รอดำเนินการ</option>
                        <option value="resolved">แก้ไขแล้ว</option>
                        <option value="closed">ปิดแล้ว</option>
                    </select>
                </div>
                <div class="form-group filter-item">
                    <label class="form-label-sm">ความสำคัญ</label>
                    <select id="filterPriority" class="form-control" onchange="loadCases()">
                        <option value="">ทุกระดับ</option>
                        <option value="high">สูง</option>
                        <option value="medium">ปานกลาง</option>
                        <option value="low">ต่ำ</option>
                    </select>
                </div>
                <button class="btn btn-outline filter-btn" onclick="loadCases()">
                    <i class="fas fa-sync"></i> <span class="btn-text-sm">รีเฟรช</span>
                </button>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">รายการ Cases</h3>
        </div>
        <div class="card-body">
            <!-- Desktop Table -->
            <div class="table-container desktop-only">
                <table>
                    <thead>
                        <tr>
                            <th>Case ID</th>
                            <th>หัวข้อ</th>
                            <th>ลูกค้า</th>
                            <th>ความสำคัญ</th>
                            <th>สถานะ</th>
                            <th>วันที่สร้าง</th>
                            <th>อัพเดทล่าสุด</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="casesTableBody">
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
            <div class="mobile-cards mobile-only" id="casesMobileCards">
                <div class="loading-placeholder">
                    <div class="spinner"></div>
                    <p>กำลังโหลดข้อมูล...</p>
                </div>
            </div>
            
            <!-- Pagination -->
            <div id="casesPagination" class="pagination-container"></div>
        </div>
    </div>
</main>

<!-- Create Case Modal -->
<div id="createCaseModal" class="modal-backdrop hidden">
    <div class="modal-content">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-plus"></i> สร้าง Case ใหม่</h3>
                <button class="modal-close-btn" onclick="closeCreateCaseModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="card-body">
                <form id="createCaseForm">
                    <div class="form-group">
                        <label class="form-label">หัวข้อ <span style="color: red;">*</span></label>
                        <input type="text" id="caseSubject" class="form-control" required placeholder="ระบุหัวข้อ case">
                    </div>
                    <div class="form-group">
                        <label class="form-label">รายละเอียด</label>
                        <textarea id="caseDescription" class="form-control" rows="4" placeholder="อธิบายรายละเอียดของ case"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">ความสำคัญ</label>
                        <select id="casePriority" class="form-control">
                            <option value="low">ต่ำ</option>
                            <option value="medium" selected>ปานกลาง</option>
                            <option value="high">สูง</option>
                        </select>
                    </div>
                    <div id="createCaseError" class="alert alert-danger" style="display: none;"></div>
                    <div id="createCaseSuccess" class="alert alert-success" style="display: none;"></div>
                    <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                        <button type="button" class="btn btn-primary" style="flex: 1;" onclick="submitCreateCase()">
                            <i class="fas fa-save"></i> สร้าง Case
                        </button>
                        <button type="button" class="btn btn-outline" style="flex: 1;" onclick="closeCreateCaseModal()">
                            <i class="fas fa-times"></i> ยกเลิก
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- View Case Detail Modal -->
<div id="viewCaseModal" class="modal-backdrop hidden">
    <div class="modal-content" style="max-width: 700px;">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-file-alt"></i> รายละเอียด Case <span id="viewCaseNo"></span></h3>
                <button class="modal-close-btn" onclick="closeViewCaseModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="card-body" id="viewCaseBody">
                <div class="loading-placeholder">
                    <div class="spinner"></div>
                    <p>กำลังโหลดข้อมูล...</p>
                </div>
            </div>
        </div>
    </div>
</div>

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

/* Filter Card */
.filter-card {
    margin-bottom: 1.5rem;
}

.filter-row {
    display: flex;
    gap: 1rem;
    align-items: flex-end;
    flex-wrap: wrap;
}

.filter-item {
    flex: 1;
    min-width: 150px;
    margin-bottom: 0;
}

.filter-btn {
    height: 42px;
}

.form-label-sm {
    font-size: 0.8rem;
    color: var(--color-gray);
    margin-bottom: 0.25rem;
    display: block;
}

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

.mobile-card-id {
    font-size: 0.8rem;
    color: var(--color-gray);
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

/* Spinner */
.spinner {
    width: 48px;
    height: 48px;
    border: 4px solid var(--color-border);
    border-top-color: var(--color-primary);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    margin: 0 auto;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Badges */
.badge-high { background: #fee2e2; color: #dc2626; }
.badge-medium { background: #fef3c7; color: #d97706; }
.badge-low { background: #dbeafe; color: #2563eb; }
.badge-open { background: #dcfce7; color: #16a34a; }
.badge-pending { background: #fef3c7; color: #d97706; }
.badge-resolved { background: #dbeafe; color: #2563eb; }
.badge-closed { background: #f3f4f6; color: #6b7280; }

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
    
    .filter-row {
        flex-direction: column;
    }
    
    .filter-item {
        width: 100%;
    }
    
    .filter-btn {
        width: 100%;
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

/* Case Detail Modal Styles */
.case-detail-section {
    margin-bottom: 1rem;
}

.case-detail-label {
    display: block;
    font-size: 0.8rem;
    color: var(--color-gray);
    margin-bottom: 0.25rem;
    font-weight: 500;
}

.case-detail-value {
    color: var(--color-text);
}

.loading-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 3rem;
    color: var(--color-gray);
}

.badge-light {
    background: var(--color-light);
    color: var(--color-text);
}

.badge-secondary {
    background: var(--color-gray);
    color: white;
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
</style>

<script>
let casesData = [];
let currentPage = 1;
let totalPages = 1;
const ITEMS_PER_PAGE = 20;

async function loadCases(page = 1) {
    currentPage = page;
    const tbody = document.getElementById('casesTableBody');
    const mobileContainer = document.getElementById('casesMobileCards');
    
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:2rem;"><div class="spinner"></div><p style="margin-top:1rem;">กำลังโหลดข้อมูล...</p></td></tr>';
    mobileContainer.innerHTML = '<div class="loading-placeholder"><div class="spinner"></div><p>กำลังโหลดข้อมูล...</p></div>';
    
    try {
        const status = document.getElementById('filterStatus').value;
        const priority = document.getElementById('filterPriority').value;
        
        let url = API_ENDPOINTS.CUSTOMER_CASES + `?page=${currentPage}&limit=${ITEMS_PER_PAGE}&`;
        if (status) url += `status=${status}&`;
        if (priority) url += `priority=${priority}&`;
        
        const res = await apiCall(url);
        
        if (!res.success || !res.data || !res.data.cases) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--color-gray);">ยังไม่มี case</td></tr>';
            mobileContainer.innerHTML = '<div class="empty-state"><p>ยังไม่มี case</p></div>';
            updateSummary({});
            renderPagination(0, 0);
            return;
        }
        
        casesData = res.data.cases;
        const pagination = res.data.pagination || {};
        totalPages = pagination.total_pages || 1;
        
        updateSummary(res.data.summary || {});
        renderPagination(pagination.total || 0, totalPages);
        
        if (casesData.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--color-gray);">ยังไม่มี case</td></tr>';
            mobileContainer.innerHTML = '<div class="empty-state"><p>ยังไม่มี case</p></div>';
            return;
        }
        
        // Desktop Table
        tbody.innerHTML = casesData.map(c => {
            // Customer profile badge
            const customerProfile = {
                platform: c.customer_platform || 'web',
                name: c.customer_name || 'ไม่ระบุลูกค้า',
                avatar: c.customer_avatar || null
            };
            const customerBadgeHtml = typeof renderCustomerProfileBadge === 'function' 
                ? renderCustomerProfileBadge(customerProfile)
                : `<span>${escapeHtml(customerProfile.name)}</span>`;
            
            return `
            <tr>
                <td><strong>#${c.id}</strong></td>
                <td>${escapeHtml(c.subject || '-')}</td>
                <td>${customerBadgeHtml}</td>
                <td><span class="badge badge-${c.priority || 'medium'}">${getPriorityLabel(c.priority)}</span></td>
                <td><span class="badge badge-${c.status || 'open'}">${getStatusLabel(c.status)}</span></td>
                <td>${formatDate(c.created_at)}</td>
                <td>${formatDate(c.updated_at)}</td>
                <td>
                    <button class="btn btn-sm btn-outline" onclick="viewCase(${c.id})">
                        <i class="fas fa-eye"></i>
                    </button>
                </td>
            </tr>
        `}).join('');
        
        // Mobile Cards
        mobileContainer.innerHTML = casesData.map(c => {
            const customerProfile = {
                platform: c.customer_platform || 'web',
                name: c.customer_name || 'ไม่ระบุลูกค้า',
                avatar: c.customer_avatar || null
            };
            const customerBadgeHtml = typeof renderCustomerProfileBadge === 'function' 
                ? renderCustomerProfileBadge(customerProfile)
                : `<span>${escapeHtml(customerProfile.name)}</span>`;
            
            return `
            <div class="mobile-card">
                <div class="mobile-card-header">
                    <div>
                        <div class="mobile-card-id">#${c.id}</div>
                        <div class="mobile-card-title">${escapeHtml(c.subject || '-')}</div>
                    </div>
                    <span class="badge badge-${c.status || 'open'}">${getStatusLabel(c.status)}</span>
                </div>
                <div class="mobile-card-row">
                    <span class="mobile-card-label">ลูกค้า</span>
                    <span class="mobile-card-value">${customerBadgeHtml}</span>
                </div>
                <div class="mobile-card-row">
                    <span class="mobile-card-label">ความสำคัญ</span>
                    <span class="mobile-card-value"><span class="badge badge-${c.priority || 'medium'}">${getPriorityLabel(c.priority)}</span></span>
                </div>
                <div class="mobile-card-row">
                    <span class="mobile-card-label">วันที่สร้าง</span>
                    <span class="mobile-card-value">${formatDate(c.created_at)}</span>
                </div>
                <div class="mobile-card-row">
                    <span class="mobile-card-label">อัพเดทล่าสุด</span>
                    <span class="mobile-card-value">${formatDate(c.updated_at)}</span>
                </div>
                <div class="mobile-card-actions">
                    <button class="btn btn-sm btn-primary" onclick="viewCase(${c.id})">
                        <i class="fas fa-eye"></i> ดูรายละเอียด
                    </button>
                </div>
            </div>
        `}).join('');
    } catch (e) {
        console.error('loadCases error:', e);
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:red;">เกิดข้อผิดพลาดในการโหลดข้อมูล</td></tr>';
        mobileContainer.innerHTML = '<div class="empty-state" style="color: red;"><p>เกิดข้อผิดพลาดในการโหลดข้อมูล</p></div>';
    }
}

function updateSummary(summary) {
    // summary now comes from API as object with counts
    const openCount = summary.open || 0;
    const pendingCount = (summary.pending_admin || 0) + (summary.pending_customer || 0) + (summary.in_progress || 0);
    const resolvedCount = summary.resolved || 0;
    const totalCount = summary.total || 0;
    
    document.getElementById('openCount').textContent = openCount;
    document.getElementById('pendingCount').textContent = pendingCount;
    document.getElementById('resolvedCount').textContent = resolvedCount;
    document.getElementById('totalCount').textContent = totalCount;
}

function renderPagination(total, pages) {
    const container = document.getElementById('casesPagination');
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
    loadCases(page);
}

function getPriorityLabel(priority) {
    const labels = { high: 'สูง', medium: 'ปานกลาง', low: 'ต่ำ' };
    return labels[priority] || priority || 'ปานกลาง';
}

function getStatusLabel(status) {
    const labels = { open: 'เปิดอยู่', pending: 'รอดำเนินการ', resolved: 'แก้ไขแล้ว', closed: 'ปิดแล้ว' };
    return labels[status] || status || 'เปิดอยู่';
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
    return new Date(dateStr).toLocaleString('th-TH', { dateStyle: 'short', timeStyle: 'short' });
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function openCreateCaseModal() {
    document.getElementById('createCaseForm').reset();
    document.getElementById('createCaseError').style.display = 'none';
    document.getElementById('createCaseSuccess').style.display = 'none';
    document.getElementById('createCaseModal').classList.remove('hidden');
}

function closeCreateCaseModal() {
    document.getElementById('createCaseModal').classList.add('hidden');
}

async function submitCreateCase() {
    const errorBox = document.getElementById('createCaseError');
    const successBox = document.getElementById('createCaseSuccess');
    errorBox.style.display = 'none';
    successBox.style.display = 'none';
    
    const subject = document.getElementById('caseSubject').value.trim();
    const description = document.getElementById('caseDescription').value.trim();
    const priority = document.getElementById('casePriority').value;
    
    if (!subject) {
        errorBox.textContent = 'กรุณาระบุหัวข้อ';
        errorBox.style.display = 'block';
        return;
    }
    
    try {
        const res = await apiCall(API_ENDPOINTS.CUSTOMER_CASES, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ subject, description, priority })
        });
        
        if (!res.success) {
            errorBox.textContent = res.message || 'ไม่สามารถสร้าง case ได้';
            errorBox.style.display = 'block';
            return;
        }
        
        successBox.textContent = 'สร้าง case สำเร็จ!';
        successBox.style.display = 'block';
        loadCases();
        setTimeout(() => closeCreateCaseModal(), 1500);
    } catch (e) {
        console.error('submitCreateCase error:', e);
        errorBox.textContent = 'เกิดข้อผิดพลาด';
        errorBox.style.display = 'block';
    }
}

async function viewCase(id) {
    // Show modal
    document.getElementById('viewCaseModal').classList.remove('hidden');
    document.getElementById('viewCaseNo').textContent = '#' + id;
    document.getElementById('viewCaseBody').innerHTML = `
        <div class="loading-placeholder">
            <div class="spinner"></div>
            <p>กำลังโหลดข้อมูล...</p>
        </div>
    `;
    
    try {
        const res = await apiCall(API_ENDPOINTS.CUSTOMER_CASE_DETAIL(id));
        
        if (!res.success || !res.data) {
            document.getElementById('viewCaseBody').innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> ไม่พบข้อมูล Case
                </div>
            `;
            return;
        }
        
        const c = res.data;
        const statusColors = {
            open: 'primary',
            pending_admin: 'warning',
            pending_customer: 'info',
            in_progress: 'info',
            resolved: 'success',
            cancelled: 'danger'
        };
        const priorityColors = {
            low: 'success',
            normal: 'primary',
            high: 'warning',
            urgent: 'danger'
        };
        
        document.getElementById('viewCaseBody').innerHTML = `
            <div class="case-detail">
                <!-- Customer Profile Section -->
                <div class="case-detail-customer" style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem; padding: 1rem; background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border-radius: 12px;">
                    <div class="customer-avatar" style="width: 60px; height: 60px; border-radius: 50%; overflow: hidden; flex-shrink: 0; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                        ${c.customer_avatar ? 
                            `<img src="${escapeHtml(c.customer_avatar)}" alt="Avatar" style="width: 100%; height: 100%; object-fit: cover;">` :
                            `<div style="width: 100%; height: 100%; background: linear-gradient(135deg, #6c757d, #495057); display: flex; align-items: center; justify-content: center; color: white; font-size: 1.5rem; font-weight: 600;">${(c.customer_name || 'ลูกค้า').charAt(0).toUpperCase()}</div>`
                        }
                    </div>
                    <div class="customer-info" style="flex: 1;">
                        <div style="font-weight: 600; font-size: 1.1rem; color: var(--color-dark);">
                            ${escapeHtml(c.customer_name || 'ลูกค้า')}
                        </div>
                        <div style="font-size: 0.85rem; color: var(--color-gray); display: flex; align-items: center; gap: 0.5rem; margin-top: 0.25rem;">
                            <i class="fab fa-${c.platform === 'line' ? 'line' : c.platform === 'facebook' ? 'facebook-messenger' : 'globe'}" style="color: ${c.platform === 'line' ? '#00B900' : c.platform === 'facebook' ? '#0084FF' : '#6c757d'};"></i>
                            <span>${c.platform === 'line' ? 'LINE' : c.platform === 'facebook' ? 'Facebook' : c.platform || 'Web'}</span>
                            ${c.external_user_id ? `<span style="color: #adb5bd;">• ID: ${escapeHtml(c.external_user_id.substring(0, 12))}...</span>` : ''}
                        </div>
                    </div>
                </div>
                
                <!-- Header Info -->
                <div class="case-detail-header" style="margin-bottom: 1.5rem;">
                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <span class="badge badge-${statusColors[c.status] || 'secondary'}">${getStatusLabel(c.status)}</span>
                        <span class="badge badge-${priorityColors[c.priority] || 'secondary'}">${getPriorityLabel(c.priority)}</span>
                        <span class="badge badge-light"><i class="fas fa-${c.platform === 'line' ? 'comment' : 'facebook'}"></i> ${c.platform || 'web'}</span>
                    </div>
                </div>
                
                <!-- Subject -->
                <div class="case-detail-section">
                    <label class="case-detail-label">หัวข้อ</label>
                    <div class="case-detail-value" style="font-size: 1.1rem; font-weight: 600;">
                        ${escapeHtml(c.subject || '-')}
                    </div>
                </div>
                
                <!-- Description / Chat History -->
                <div class="case-detail-section">
                    <label class="case-detail-label"><i class="fas fa-comments"></i> ข้อความจากลูกค้า</label>
                    <div class="case-detail-value" style="background: var(--color-light); padding: 1rem; border-radius: 8px; white-space: pre-wrap; max-height: 300px; overflow-y: auto; font-family: inherit; line-height: 1.6;">
                        ${escapeHtml(c.description || 'ยังไม่มีข้อความ')}
                    </div>
                </div>
                
                <!-- Product Info (if any) -->
                ${c.product_ref_id ? `
                <div class="case-detail-section">
                    <label class="case-detail-label">สินค้าที่เกี่ยวข้อง</label>
                    <div class="case-detail-value">
                        <i class="fas fa-box"></i> ${escapeHtml(c.product_ref_id)}
                    </div>
                    </div>
                </div>
                ` : ''}
                
                <!-- Case Type -->
                <div class="case-detail-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="case-detail-section">
                        <label class="case-detail-label">ประเภท Case</label>
                        <div class="case-detail-value">${getCaseTypeLabel(c.case_type)}</div>
                    </div>
                    <div class="case-detail-section">
                        <label class="case-detail-label">Case No.</label>
                        <div class="case-detail-value">${escapeHtml(c.case_no || '-')}</div>
                    </div>
                </div>
                
                <!-- Timestamps -->
                <div class="case-detail-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 1rem;">
                    <div class="case-detail-section">
                        <label class="case-detail-label"><i class="fas fa-calendar-plus"></i> สร้างเมื่อ</label>
                        <div class="case-detail-value">${formatDateTime(c.created_at)}</div>
                    </div>
                    <div class="case-detail-section">
                        <label class="case-detail-label"><i class="fas fa-calendar-check"></i> อัพเดทล่าสุด</label>
                        <div class="case-detail-value">${formatDateTime(c.updated_at)}</div>
                    </div>
                </div>
                
                ${c.resolved_at ? `
                <div class="case-detail-section" style="margin-top: 1rem;">
                    <label class="case-detail-label"><i class="fas fa-check-circle" style="color: var(--color-success);"></i> แก้ไขเมื่อ</label>
                    <div class="case-detail-value">${formatDateTime(c.resolved_at)}</div>
                </div>
                ` : ''}
                
                ${c.resolution_notes ? `
                <div class="case-detail-section" style="margin-top: 1rem;">
                    <label class="case-detail-label">บันทึกการแก้ไข</label>
                    <div class="case-detail-value" style="background: var(--color-success-bg); padding: 1rem; border-radius: 8px; border-left: 4px solid var(--color-success);">
                        ${escapeHtml(c.resolution_notes)}
                    </div>
                </div>
                ` : ''}
            </div>
            
            <div style="margin-top: 1.5rem; display: flex; gap: 1rem;">
                <button class="btn btn-outline" style="flex: 1;" onclick="closeViewCaseModal()">
                    <i class="fas fa-times"></i> ปิด
                </button>
            </div>
        `;
    } catch (e) {
        console.error('viewCase error:', e);
        document.getElementById('viewCaseBody').innerHTML = `
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> เกิดข้อผิดพลาดในการโหลดข้อมูล
            </div>
        `;
    }
}

function closeViewCaseModal() {
    document.getElementById('viewCaseModal').classList.add('hidden');
}

function getCaseTypeLabel(type) {
    const labels = {
        product_inquiry: '🛍️ สอบถามสินค้า',
        payment_full: '💰 ชำระเต็มจำนวน',
        payment_installment: '📅 ผ่อนชำระ',
        payment_savings: '🐷 ออมเงิน',
        general_inquiry: '❓ สอบถามทั่วไป',
        complaint: '⚠️ ร้องเรียน',
        other: '📋 อื่นๆ'
    };
    return labels[type] || type || 'ไม่ระบุ';
}

function formatDateTime(dateStr) {
    if (!dateStr) return '-';
    try {
        const d = new Date(dateStr);
        return d.toLocaleDateString('th-TH', { 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    } catch (e) {
        return dateStr;
    }
}

// Load on page ready
document.addEventListener('DOMContentLoaded', function() {
    if (typeof apiCall !== 'undefined') {
        loadCases();
    } else {
        document.addEventListener('coreJSLoaded', loadCases);
    }
});
</script>

<!-- Customer Profile Component -->
<link rel="stylesheet" href="<?php echo asset('css/components/customer-profile.css'); ?>?v=<?php echo time(); ?>">
<script src="<?php echo asset('js/components/customer-profile.js'); ?>?v=<?php echo time(); ?>"></script>

<?php include('../includes/customer/footer.php'); ?>
