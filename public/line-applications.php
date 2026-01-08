<?php
/**
 * LINE Applications Monitor - Customer Portal
 */
define('INCLUDE_CHECK', true);

$page_title = "ใบสมัคร LINE - AI Automation";
$current_page = "line_applications";

include('../includes/customer/header.php');
include('../includes/customer/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">📋 ใบสมัคร LINE Application</h1>
            <p class="page-subtitle">จัดการและตรวจสอบใบสมัครจากลูกค้า</p>
        </div>
        <div>
            <button class="btn btn-primary" onclick="exportToCSV()">
                <i class="fas fa-download"></i> Export CSV
            </button>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row">
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon primary">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">ใบสมัครทั้งหมด</div>
                    <div class="stat-value" id="totalApplications">-</div>
                </div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">อนุมัติแล้ว</div>
                    <div class="stat-value" id="approvedCount">-</div>
                </div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon danger">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">ปฏิเสธ</div>
                    <div class="stat-value" id="rejectedCount">-</div>
                </div>
            </div>
        </div>
        <div class="col-3">
            <div class="stat-card">
                <div class="stat-icon warning">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">รอตรวจสอบ</div>
                    <div class="stat-value" id="pendingCount">-</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mt-4 filter-card">
        <div class="card-header">
            <h3 class="card-title" style="margin:0; font-size: 1.125rem; font-weight: 600; color: var(--color-dark);">
                <i class="fas fa-filter" style="font-size: 1rem; margin-right: 0.5rem; color: var(--color-primary);"></i>
                ตัวกรองข้อมูล
            </h3>
        </div>
        <div class="card-body">
            <!-- Search & Primary Filters -->
            <div class="filter-section">
                <div class="filter-section-header">
                    <span>ค้นหาและกรองหลัก</span>
                </div>
                <div class="row">
                    <div class="col-4">
                        <div class="form-group compact">
                            <label class="filter-label">
                                <i class="fas fa-search"></i>
                                ค้นหา
                            </label>
                            <input 
                                type="text" 
                                id="searchInput" 
                                class="form-control filter-input" 
                                placeholder="เลขใบสมัคร, ชื่อ, เบอร์โทร, อีเมล">
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="form-group compact">
                            <label class="filter-label">
                                <i class="fas fa-bullhorn"></i>
                                แคมเปญ
                            </label>
                            <select id="campaignFilter" class="form-control filter-input">
                                <option value="">ทั้งหมด</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="form-group compact">
                            <label class="filter-label">
                                <i class="fas fa-tasks"></i>
                                สถานะ
                            </label>
                            <select id="statusFilter" class="form-control filter-input">
                                <option value="">ทั้งหมด</option>
                                <option value="RECEIVED">ได้รับแล้ว</option>
                                <option value="DOC_PENDING">รอเอกสาร</option>
                                <option value="OCR_PROCESSING">กำลัง OCR</option>
                                <option value="NEED_REVIEW">ต้องตรวจสอบ</option>
                                <option value="APPROVED">อนุมัติแล้ว</option>
                                <option value="REJECTED">ปฏิเสธ</option>
                                <option value="INCOMPLETE">ขอเอกสารเพิ่ม</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Date & Priority Filters -->
            <div class="filter-section">
                <div class="filter-section-header">
                    <span>ช่วงเวลาและความสำคัญ</span>
                </div>
                <div class="row">
                    <div class="col-4">
                        <div class="form-group compact">
                            <label class="filter-label">
                                <i class="fas fa-calendar-alt"></i>
                                วันที่เริ่มต้น
                            </label>
                            <input type="date" id="dateFrom" class="form-control filter-input">
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="form-group compact">
                            <label class="filter-label">
                                <i class="fas fa-calendar-check"></i>
                                วันที่สิ้นสุด
                            </label>
                            <input type="date" id="dateTo" class="form-control filter-input">
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="form-group compact">
                            <label class="filter-label">
                                <i class="fas fa-exclamation-circle"></i>
                                ระดับความสำคัญ
                            </label>
                            <select id="priorityFilter" class="form-control filter-input">
                                <option value="">ทั้งหมด</option>
                                <option value="low">ต่ำ</option>
                                <option value="normal" selected>ปกติ</option>
                                <option value="high">สูง</option>
                                <option value="urgent">ด่วน</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="filter-actions-row">
                <button class="btn btn-primary filter-btn-action" onclick="applyFilters()">
                    <i class="fas fa-search"></i>
                    ค้นหา
                </button>
                <button class="btn btn-outline filter-btn-action" onclick="clearFilters()">
                    <i class="fas fa-redo"></i>
                    ล้างตัวกรอง
                </button>
            </div>
        </div>
    </div>

    <!-- Applications List -->
    <div class="card mt-4">
        <div class="card-header">
            <h3 class="card-title">รายการใบสมัคร</h3>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>เลขที่</th>
                            <th>แคมเปญ</th>
                            <th>ผู้สมัคร</th>
                            <th>ติดต่อ</th>
                            <th>สถานะ</th>
                            <th>เอกสาร</th>
                            <th>วันที่สมัคร</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="applicationsTableBody">
                        <tr>
                            <td colspan="8" style="text-align:center;padding:2rem;">
                                <div class="spinner" style="margin:0 auto 1rem;"></div>
                                กำลังโหลดข้อมูล...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <!-- Pagination -->
            <div id="pagination" class="mt-3" style="display:flex;justify-content:center;gap:0.5rem;"></div>
        </div>
    </div>
</main>

<!-- Detail Modal -->
<div id="detailModal" class="modal modal-ui" data-ui="modal" style="display:none;">
    <div class="modal-overlay" onclick="closeDetailModal()"></div>
    <div class="modal-dialog" style="max-width:1200px;">
        <div class="modal-content-wrapper">
            <div class="modal-header-custom">
                <h2 class="modal-title-custom">รายละเอียดใบสมัคร</h2>
                <button class="modal-close-custom" onclick="closeDetailModal()">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M18 6L6 18M6 6l12 12" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
            <div class="modal-body-custom">
                <div id="applicationDetail" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <!-- Left: Application Info -->
                    <div>
                        <div id="applicationInfo"></div>
                    </div>
                    <!-- Right: Document Viewer -->
                    <div>
                        <div id="documentViewer"></div>
                    </div>
                </div>
                <!-- Action Buttons -->
                <div style="display:flex;gap:0.5rem;margin-top:1.5rem;padding-top:1.5rem;border-top:1px solid var(--color-border);">
                    <button class="btn btn-success" onclick="approveApplication()">
                        <i class="fas fa-check"></i> อนุมัติ
                    </button>
                    <button class="btn btn-danger" onclick="rejectApplication()">
                        <i class="fas fa-times"></i> ปฏิเสธ
                    </button>
                    <button class="btn btn-warning" onclick="requestMoreDocs()">
                        <i class="fas fa-file-upload"></i> ขอเอกสารเพิ่ม
                    </button>
                    <button class="btn btn-info" onclick="setAppointment()">
                        <i class="fas fa-calendar"></i> นัดหมาย
                    </button>
                    <button class="btn btn-secondary" style="margin-left:auto;" onclick="closeDetailModal()">
                        ปิด
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Approve Modal -->
<div id="approveModal" class="modal modal-ui" data-ui="modal" style="display:none;">
    <div class="modal-overlay" onclick="closeApproveModal()"></div>
    <div class="modal-dialog" style="max-width:500px;">
        <div class="modal-content-wrapper">
            <div class="modal-header-custom">
                <h2 class="modal-title-custom">อนุมัติใบสมัคร</h2>
                <button class="modal-close-custom" onclick="closeApproveModal()">×</button>
            </div>
            <div class="modal-body-custom">
                <p>คุณต้องการอนุมัติใบสมัครนี้หรือไม่?</p>
                <label>บันทึกเพิ่มเติม (ถ้ามี):</label>
                <textarea id="approveNotes" class="form-control" rows="3"></textarea>
                <div class="mt-3">
                    <button class="btn btn-success" onclick="confirmApprove()">
                        <i class="fas fa-check"></i> ยืนยันอนุมัติ
                    </button>
                    <button class="btn btn-secondary" onclick="closeApproveModal()">ยกเลิก</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal modal-ui" data-ui="modal" style="display:none;">
    <div class="modal-overlay" onclick="closeRejectModal()"></div>
    <div class="modal-dialog" style="max-width:500px;">
        <div class="modal-content-wrapper">
            <div class="modal-header-custom">
                <h2 class="modal-title-custom">ปฏิเสธใบสมัคร</h2>
                <button class="modal-close-custom" onclick="closeRejectModal()">×</button>
            </div>
            <div class="modal-body-custom">
                <p style="color:var(--color-danger);">คุณต้องการปฏิเสธใบสมัครนี้หรือไม่?</p>
                <label>เหตุผลในการปฏิเสธ: <span style="color:red;">*</span></label>
                <textarea id="rejectReason" class="form-control" rows="3" required></textarea>
                <div class="mt-3">
                    <button class="btn btn-danger" onclick="confirmReject()">
                        <i class="fas fa-times"></i> ยืนยันปฏิเสธ
                    </button>
                    <button class="btn btn-secondary" onclick="closeRejectModal()">ยกเลิก</button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* ========================================
   FILTER SECTION STYLES
======================================== */
.filter-card {
    border: 1px solid var(--color-light-3);
}

.filter-section {
    margin-bottom: 1.75rem;
}

.filter-section:last-child {
    margin-bottom: 0;
}

.filter-section-header {
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid var(--color-light-2);
}

.filter-section-header span {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--color-dark-2);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.filter-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-dark-2);
    margin-bottom: 0.5rem;
}

.filter-label i {
    font-size: 0.875rem;
    color: var(--color-gray);
}

.form-group.compact {
    margin-bottom: 0;
}

.filter-input {
    font-size: 0.9375rem;
    padding: 0.625rem 0.875rem;
    border: 1.5px solid var(--color-light-3);
    transition: all 0.2s ease;
}

.filter-input:hover {
    border-color: var(--color-gray-lighter);
}

.filter-input:focus {
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px rgba(29, 78, 216, 0.08);
}

/* Action Buttons Row - Centered at Bottom */
.filter-actions-row {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 0.75rem;
    margin-top: 2rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--color-light-2);
}

.filter-btn-action {
    min-width: 160px;
    padding: 0.75rem 2rem;
    font-size: 1rem;
    font-weight: 500;
    border-radius: var(--radius-md);
    transition: all 0.25s ease;
}

.filter-btn-action:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
}

.btn-outline.filter-btn-action {
    background: var(--color-white);
    border: 1.5px solid var(--color-gray-lighter);
    color: var(--color-dark-2);
}

.btn-outline.filter-btn-action:hover:not(:disabled) {
    background: var(--color-light);
    border-color: var(--color-gray);
    color: var(--color-dark);
}

/* ========================================
   UTILITY STYLES
======================================== */
.spinner {
    width: 48px;
    height: 48px;
    border: 4px solid var(--color-light-3);
    border-top-color: var(--color-primary);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* ========================================
   STATUS BADGES - Subdued Colors
======================================== */
.status-badge {
    padding: 0.375rem 0.875rem;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 600;
    white-space: nowrap;
    letter-spacing: 0.3px;
}

.status-RECEIVED { 
    background: #e2e8f0; 
    color: #475569; 
}

.status-DOC_PENDING { 
    background: #fef3c7; 
    color: #92400e; 
}

.status-OCR_PROCESSING { 
    background: #dbeafe; 
    color: #1e40af; 
}

.status-NEED_REVIEW { 
    background: #fed7aa; 
    color: #9a3412; 
}

.status-APPROVED { 
    background: #d1fae5; 
    color: #065f46; 
}

.status-REJECTED { 
    background: #fee2e2; 
    color: #991b1b; 
}

.status-INCOMPLETE { 
    background: #e9d5ff; 
    color: #6b21a8; 
}

/* ========================================
   PRIORITY BADGES - Subdued Colors
======================================== */
.priority-badge {
    padding: 0.25rem 0.625rem;
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: 600;
}

.priority-low { 
    background: #f1f5f9; 
    color: #64748b; 
}

.priority-normal { 
    background: #dbeafe; 
    color: #1d4ed8; 
}

.priority-high { 
    background: #fed7aa; 
    color: #c2410c; 
}

.priority-urgent { 
    background: #fee2e2; 
    color: #b91c1c; 
}

/* ========================================
   ADDITIONAL UI ELEMENTS
======================================== */
.user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 0.5rem;
}

.document-preview {
    max-width: 100%;
    border-radius: 8px;
    margin-top: 0.5rem;
}

.ocr-field {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem;
    background: var(--color-light);
    border-radius: 4px;
    margin-bottom: 0.5rem;
}

.ocr-confidence {
    font-weight: 600;
}

.confidence-high { color: var(--color-success); }
.confidence-medium { color: var(--color-warning); }
.confidence-low { color: var(--color-danger); }

/* ========================================
   SUCCESS & DANGER STAT ICONS - Subdued
======================================== */
.stat-icon.success {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
}

.stat-icon.danger {
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
}

/* ========================================
   BUTTON VARIANTS - Professional Colors
======================================== */
.btn-success {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    color: var(--color-white);
}

.btn-success:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(5, 150, 105, 0.25);
}

.btn-danger {
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
    color: var(--color-white);
}

.btn-danger:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(220, 38, 38, 0.25);
}

.btn-warning {
    background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
    color: var(--color-white);
}

.btn-warning:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(217, 119, 6, 0.25);
}

.btn-info {
    background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
    color: var(--color-white);
}

.btn-info:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(2, 132, 199, 0.25);
}
</style>

<?php
$extra_scripts = [
    '../assets/js/customer/line-applications.js'
];

include('../includes/customer/footer.php');
?>
