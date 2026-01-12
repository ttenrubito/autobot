<?php
/**
 * Repairs - Customer Portal
 * ระบบงานซ่อม สำหรับร้านเฮง เฮง
 */
define('INCLUDE_CHECK', true);

$page_title = "งานซ่อม - เฮง เฮง";
$current_page = "repairs";

include('../includes/customer/header.php');
include('../includes/customer/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <div class="page-header-content">
            <div>
                <h1 class="page-title">🔧 งานซ่อม</h1>
                <p class="page-subtitle">รายการงานซ่อมสินค้า</p>
            </div>
            <button class="btn btn-primary" onclick="openCreateModal()">
                <i class="fas fa-plus"></i> <span class="btn-text">รับซ่อมใหม่</span>
            </button>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-grid">
        <div class="summary-card summary-card-info">
            <div class="summary-icon">🔧</div>
            <div class="summary-value" id="activeCount">0</div>
            <div class="summary-label">กำลังดำเนินการ</div>
        </div>
        <div class="summary-card summary-card-warning">
            <div class="summary-icon">📋</div>
            <div class="summary-value" id="awaitingCount">0</div>
            <div class="summary-label">รออนุมัติ</div>
        </div>
        <div class="summary-card summary-card-success">
            <div class="summary-icon">✅</div>
            <div class="summary-value" id="completedCount">0</div>
            <div class="summary-label">เสร็จสิ้น</div>
        </div>
        <div class="summary-card">
            <div class="summary-icon">💰</div>
            <div class="summary-value" id="totalPaid">฿0</div>
            <div class="summary-label">ค่าซ่อมรวม</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">รายการซ่อม</h3>
            <div class="filter-group">
                <select id="statusFilter" class="form-select" onchange="loadRepairs()">
                    <option value="">ทั้งหมด</option>
                    <option value="pending">รอรับของ</option>
                    <option value="received">รับของแล้ว</option>
                    <option value="diagnosing">กำลังตรวจสอบ</option>
                    <option value="quoted">รออนุมัติ</option>
                    <option value="repairing">กำลังซ่อม</option>
                    <option value="completed">เสร็จสิ้น</option>
                </select>
            </div>
        </div>
        <div class="card-body">
            <!-- Desktop Table -->
            <div class="table-container desktop-only">
                <table>
                    <thead>
                        <tr>
                            <th>รหัสซ่อม</th>
                            <th>สินค้า</th>
                            <th>อาการ</th>
                            <th style="text-align:right;">ราคาซ่อม</th>
                            <th>สถานะ</th>
                            <th>ความคืบหน้า</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="repairsTableBody">
                        <tr>
                            <td colspan="7" style="text-align:center;padding:2rem;">
                                <div class="spinner" style="margin:0 auto 1rem;"></div>
                                กำลังโหลดข้อมูล...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Mobile Cards -->
            <div class="mobile-cards mobile-only" id="repairsMobileCards">
                <div class="loading-placeholder">
                    <div class="spinner"></div>
                    <p>กำลังโหลดข้อมูล...</p>
                </div>
            </div>
            
            <!-- Pagination -->
            <div id="repairsPagination" class="pagination-container"></div>
        </div>
    </div>
</main>

<!-- Detail Modal -->
<div class="modal-overlay" id="detailModal">
    <div class="modal-container modal-lg">
        <div class="modal-header">
            <h3 class="modal-title">📋 รายละเอียดงานซ่อม</h3>
            <button class="modal-close" onclick="closeDetailModal()">&times;</button>
        </div>
        <div class="modal-body" id="repairDetailContent"></div>
        <div class="modal-footer" id="detailModalFooter">
            <button class="btn btn-secondary" onclick="closeDetailModal()">ปิด</button>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal-overlay" id="paymentModal">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="modal-title">💳 ชำระค่าซ่อม</h3>
            <button class="modal-close" onclick="closePaymentModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="repairPaymentInfo"></div>
            
            <div class="bank-accounts" id="bankAccountsList"></div>
            
            <div class="form-group">
                <label class="form-label">แนบสลิปโอนเงิน</label>
                <div class="upload-area" id="slipUploadArea">
                    <input type="file" id="slipInput" accept="image/*" style="display:none;" onchange="handleSlipUpload(this)">
                    <div class="upload-placeholder" onclick="document.getElementById('slipInput').click()">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <p>คลิกเพื่ออัพโหลดสลิป</p>
                    </div>
                    <img id="slipPreview" style="display:none; max-width:100%; border-radius:8px;">
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closePaymentModal()">ยกเลิก</button>
            <button class="btn btn-primary" id="submitPaymentBtn" onclick="submitPayment()" disabled>
                <i class="fas fa-paper-plane"></i> ส่งสลิป
            </button>
        </div>
    </div>
</div>

<!-- Create Repair Modal -->
<div class="modal-overlay" id="createModal">
    <div class="modal-container modal-lg">
        <div class="modal-header">
            <h3 class="modal-title">🔧 รับซ่อมใหม่</h3>
            <button class="modal-close" onclick="closeCreateModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="createRepairForm">
                <!-- Customer Section -->
                <div class="detail-section">
                    <h4 class="detail-section-title">👤 ข้อมูลลูกค้า</h4>
                    <div class="form-group">
                        <label for="customerSearch">ค้นหาลูกค้าจากระบบ</label>
                        <div class="autocomplete-wrapper">
                            <input type="text" 
                                   id="customerSearch" 
                                   class="form-input" 
                                   placeholder="พิมพ์ชื่อ, เบอร์โทร, LINE ID..."
                                   autocomplete="off"
                                   oninput="searchCustomers(this.value)">
                            <div id="customerSearchResults" class="autocomplete-dropdown" style="display:none;"></div>
                        </div>
                        <small class="form-hint">⌨️ พิมพ์อย่างน้อย 2 ตัวอักษรเพื่อค้นหา</small>
                    </div>
                    
                    <div id="selectedCustomerCard" class="selected-customer-card" style="display:none;">
                        <div class="selected-customer-avatar" id="selectedCustomerAvatar">
                            <span id="selectedCustomerInitials">-</span>
                        </div>
                        <div class="selected-customer-info">
                            <h5 id="selectedCustomerName">-</h5>
                            <p id="selectedCustomerMeta" class="customer-meta-text">-</p>
                        </div>
                        <button type="button" class="btn-remove-customer" onclick="clearSelectedCustomer()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <input type="hidden" id="selectedCustomerId" name="customer_id">
                </div>
                
                <!-- Item Section -->
                <div class="detail-section">
                    <h4 class="detail-section-title">⌚ ข้อมูลสินค้า</h4>
                    <div class="detail-grid">
                        <div class="form-group">
                            <label for="itemType">ประเภทสินค้า <span class="required">*</span></label>
                            <select id="itemType" name="item_type" class="form-input" required>
                                <option value="">เลือกประเภท</option>
                                <option value="watch">นาฬิกา</option>
                                <option value="jewelry">เครื่องประดับ</option>
                                <option value="ring">แหวน</option>
                                <option value="necklace">สร้อย</option>
                                <option value="bracelet">กำไล</option>
                                <option value="other">อื่นๆ</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="itemName">ยี่ห้อ/รุ่น <span class="required">*</span></label>
                            <input type="text" id="itemName" name="item_name" class="form-input" required placeholder="เช่น Rolex Submariner">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="itemDescription">รายละเอียด/สภาพสินค้า</label>
                        <textarea id="itemDescription" name="item_description" class="form-input" rows="2" placeholder="รายละเอียดสินค้า"></textarea>
                    </div>
                </div>
                
                <!-- Repair Details -->
                <div class="detail-section">
                    <h4 class="detail-section-title">🔧 รายละเอียดงานซ่อม</h4>
                    <div class="form-group">
                        <label for="repairIssue">อาการ/ปัญหา <span class="required">*</span></label>
                        <textarea id="repairIssue" name="issue" class="form-input" rows="2" required placeholder="เช่น หน้าปัดแตก, เครื่องไม่เดิน"></textarea>
                    </div>
                    <div class="detail-grid">
                        <div class="form-group">
                            <label for="estimatedCost">ประเมินค่าซ่อม (บาท)</label>
                            <input type="number" id="estimatedCost" name="estimated_cost" class="form-input" placeholder="0" min="0" step="1">
                        </div>
                        <div class="form-group">
                            <label for="estimatedDate">คาดว่าเสร็จ</label>
                            <input type="date" id="estimatedDate" name="estimated_completion_date" class="form-input">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="repairNotes">หมายเหตุ</label>
                        <textarea id="repairNotes" name="notes" class="form-input" rows="2" placeholder="หมายเหตุเพิ่มเติม"></textarea>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeCreateModal()">ยกเลิก</button>
            <button class="btn btn-primary" onclick="submitCreateRepair()">
                <i class="fas fa-save"></i> บันทึก
            </button>
        </div>
    </div>
</div>

<style>
/* Modal Overlay - Fixed Position */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 1rem;
    overflow-y: auto;
}
.modal-overlay.active {
    display: flex;
}
.modal-container {
    background: white;
    border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
    width: 100%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
    animation: modalSlideIn 0.3s ease;
}
.modal-container.modal-lg {
    max-width: 700px;
}
@keyframes modalSlideIn {
    from { opacity: 0; transform: scale(0.95) translateY(-10px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid #e5e7eb;
}
.modal-title {
    font-size: 1.1rem;
    font-weight: 600;
    margin: 0;
}
.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    color: #9ca3af;
    cursor: pointer;
    padding: 0.5rem;
    line-height: 1;
}
.modal-close:hover { color: #374151; }
.modal-body {
    padding: 1.5rem;
}
.modal-footer {
    padding: 1rem 1.5rem;
    border-top: 1px solid #e5e7eb;
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
}

.page-header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    width: 100%;
    flex-wrap: wrap;
    gap: 1rem;
}

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
}

.summary-icon { font-size: 2rem; margin-bottom: 0.5rem; }
.summary-value { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.25rem; }
.summary-label { font-size: 0.85rem; color: var(--color-gray); }
.summary-card-success .summary-value { color: var(--color-success); }
.summary-card-warning .summary-value { color: var(--color-warning); }
.summary-card-info .summary-value { color: var(--color-info); }

.filter-group { display: flex; gap: 0.5rem; align-items: center; }
.form-select {
    padding: 0.5rem 1rem;
    border: 1px solid var(--color-border);
    border-radius: 8px;
    background: white;
    font-size: 0.9rem;
}

.mobile-cards { display: none; }
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
.mobile-card-title { font-weight: 600; font-size: 1rem; }
.mobile-card-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid var(--color-light-2);
    font-size: 0.9rem;
}
.mobile-card-row:last-child { border-bottom: none; }
.mobile-card-label { color: var(--color-gray); }
.mobile-card-value { font-weight: 500; }
.mobile-card-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid var(--color-light-2);
}
.mobile-card-actions .btn { flex: 1; justify-content: center; }

.progress-bar-container {
    background: #e5e7eb;
    border-radius: 8px;
    height: 8px;
    overflow: hidden;
    width: 100%;
    min-width: 100px;
}
.progress-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #3b82f6, #8b5cf6);
    transition: width 0.3s ease;
}
.progress-text {
    font-size: 0.8rem;
    color: var(--color-gray);
    margin-top: 0.25rem;
}

.bank-accounts { margin: 1rem 0; }
.bank-account-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1rem;
    margin-bottom: 0.75rem;
}
.bank-account-header { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem; }
.bank-account-name { font-weight: 600; }
.bank-account-number { font-size: 1.1rem; font-family: monospace; }
.bank-account-holder { color: var(--color-gray); font-size: 0.9rem; }

.upload-area { margin-top: 0.5rem; }
.upload-placeholder {
    border: 2px dashed #e2e8f0;
    border-radius: 12px;
    padding: 2rem;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
}
.upload-placeholder:hover { border-color: var(--color-primary); background: #f8fafc; }
.upload-placeholder i { font-size: 2rem; color: var(--color-gray); margin-bottom: 0.5rem; }
.upload-placeholder p { color: var(--color-gray); margin: 0; }

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
}
.btn-pagination:disabled { opacity: 0.5; cursor: not-allowed; }
.page-indicator { padding: 0.5rem 1rem; color: #6b7280; font-size: 0.9rem; }

.status-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 500;
}
.status-pending { background: #f3f4f6; color: #6b7280; }
.status-received { background: #dbeafe; color: #2563eb; }
.status-diagnosing { background: #e0e7ff; color: #4f46e5; }
.status-quoted { background: #fef3c7; color: #d97706; }
.status-approved { background: #d1fae5; color: #059669; }
.status-repairing { background: #ddd6fe; color: #7c3aed; }
.status-completed { background: #d1fae5; color: #059669; }
.status-cancelled { background: #fee2e2; color: #dc2626; }

.repair-info-card {
    background: #f8fafc;
    border-radius: 12px;
    padding: 1rem;
    margin-bottom: 1rem;
}
.repair-info-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid #e2e8f0;
}
.repair-info-row:last-child { border-bottom: none; }
.repair-info-label { color: var(--color-gray); }
.repair-info-value { font-weight: 600; }
.amount-highlight { font-size: 1.25rem; color: var(--color-primary); }

.timeline {
    margin-top: 1.5rem;
    padding-left: 1rem;
    border-left: 2px solid #e5e7eb;
}
.timeline-item {
    position: relative;
    padding-left: 1.5rem;
    padding-bottom: 1rem;
}
.timeline-item:last-child { padding-bottom: 0; }
.timeline-item::before {
    content: '';
    position: absolute;
    left: -0.5rem;
    top: 0.25rem;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #e5e7eb;
}
.timeline-item.completed::before { background: #059669; }
.timeline-item.current::before { background: #3b82f6; box-shadow: 0 0 0 4px #dbeafe; }
.timeline-event { font-weight: 500; }
.timeline-date { font-size: 0.85rem; color: var(--color-gray); }

.quote-card {
    background: #fffbeb;
    border: 1px solid #fcd34d;
    border-radius: 12px;
    padding: 1rem;
    margin: 1rem 0;
}
.quote-card h4 { margin: 0 0 0.75rem 0; color: #b45309; }
.quote-amount {
    font-size: 1.5rem;
    font-weight: 700;
    color: #d97706;
}
.quote-note { margin-top: 0.5rem; color: #92400e; font-size: 0.9rem; }
.quote-valid { margin-top: 0.5rem; color: #6b7280; font-size: 0.85rem; }

.modal-lg { max-width: 700px; }

.action-needed-banner {
    background: #fef3c7;
    border: 1px solid #fcd34d;
    border-radius: 8px;
    padding: 0.75rem 1rem;
    color: #b45309;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

/* Detail Section */
.detail-section {
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid #e5e7eb;
}
.detail-section:last-child { border-bottom: none; }
.detail-section-title {
    font-size: 0.95rem;
    font-weight: 600;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.detail-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

/* Autocomplete */
.autocomplete-wrapper { position: relative; }
.autocomplete-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 1000;
    max-height: 300px;
    overflow-y: auto;
}
.autocomplete-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    cursor: pointer;
    border-bottom: 1px solid #f3f4f6;
}
.autocomplete-item:last-child { border-bottom: none; }
.autocomplete-item:hover { background: #f9fafb; }
.autocomplete-item.no-result { color: #9ca3af; cursor: default; }
.autocomplete-item-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #e5e7eb;
    color: #6b7280;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.85rem;
    font-weight: 600;
    flex-shrink: 0;
    overflow: hidden;
}
.autocomplete-item-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.autocomplete-item-info { flex: 1; min-width: 0; }
.autocomplete-item-name { font-weight: 500; }
.autocomplete-item-phone { font-size: 0.8rem; color: #9ca3af; }
.autocomplete-item-platform {
    font-size: 0.75rem;
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    font-weight: 500;
}
.autocomplete-item-platform.line { background: #e8f5e9; color: #06c755; }
.autocomplete-item-platform.facebook { background: #e3f2fd; color: #1877f2; }

/* Selected Customer Card */
.selected-customer-card {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    background: #f0f9ff;
    border: 1px solid #bae6fd;
    border-radius: 10px;
    margin-top: 0.5rem;
}
.selected-customer-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: #0ea5e9;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 1rem;
    flex-shrink: 0;
    overflow: hidden;
}
.selected-customer-info { flex: 1; }
.selected-customer-info h5 {
    font-size: 1rem;
    font-weight: 600;
    margin: 0 0 0.25rem 0;
}
.customer-meta-text {
    font-size: 0.8rem;
    color: #6b7280;
    margin: 0;
}
.btn-remove-customer {
    background: none;
    border: none;
    color: #9ca3af;
    cursor: pointer;
    padding: 0.5rem;
}
.btn-remove-customer:hover { color: #dc2626; }

/* Form Inputs */
.form-input {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-size: 0.95rem;
}
.form-input:focus {
    outline: none;
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}
.form-group { margin-bottom: 1rem; }
.form-group label {
    display: block;
    font-size: 0.9rem;
    font-weight: 500;
    margin-bottom: 0.5rem;
}
.required { color: #dc2626; }
.form-hint { display: block; margin-top: 0.25rem; color: #9ca3af; font-size: 0.8rem; }

@media (max-width: 768px) {
    .summary-grid { grid-template-columns: repeat(2, 1fr); }
    .detail-grid { grid-template-columns: 1fr; }
    .desktop-only { display: none !important; }
    .mobile-only { display: block !important; }
    .mobile-cards { display: block; }
    .filter-group { width: 100%; }
    .form-select { width: 100%; }
}
</style>

<script>
let currentPage = 1;
let selectedRepairId = null;
let selectedRepairData = null;
let slipUrl = null;

document.addEventListener('DOMContentLoaded', function() {
    loadRepairs();
});

async function loadRepairs(page = 1) {
    currentPage = page;
    const status = document.getElementById('statusFilter').value;
    
    try {
        let url = `/api/customer/repairs?page=${page}&limit=20`;
        if (status) url += `&status=${status}`;
        
        const response = await fetch(url, {
            headers: { 'Authorization': 'Bearer ' + getToken() }
        });
        const data = await response.json();
        
        if (data.success) {
            renderRepairs(data.data);
            renderPagination(data.pagination);
            updateSummary(data.summary);
        } else {
            showError(data.message || 'ไม่สามารถโหลดข้อมูลได้');
        }
    } catch (error) {
        console.error('Error loading repairs:', error);
        showError('เกิดข้อผิดพลาดในการโหลดข้อมูล');
    }
}

function renderRepairs(repairs) {
    const tbody = document.getElementById('repairsTableBody');
    const mobileCards = document.getElementById('repairsMobileCards');
    
    if (!repairs || repairs.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:2rem;color:#6b7280;">ไม่มีรายการซ่อม</td></tr>';
        mobileCards.innerHTML = '<div class="empty-state"><p>ไม่มีรายการซ่อม</p></div>';
        return;
    }
    
    // Desktop table
    tbody.innerHTML = repairs.map(r => `
        <tr ${r.needs_approval || r.needs_payment ? 'style="background:#fffbeb;"' : ''}>
            <td><strong>${r.repair_no || '-'}</strong></td>
            <td>${r.item_description || '-'}</td>
            <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;">${r.issue_description || '-'}</td>
            <td style="text-align:right;">${r.quoted_amount ? '฿' + formatNumber(r.quoted_amount) : '-'}</td>
            <td><span class="status-badge status-${r.status}">${r.status_display}</span></td>
            <td>
                <div class="progress-bar-container">
                    <div class="progress-bar-fill" style="width:${r.progress_percent}%"></div>
                </div>
                <div class="progress-text">${r.progress_percent}%</div>
            </td>
            <td>
                <div class="action-buttons">
                    <button class="btn btn-sm btn-ghost" onclick="viewDetail(${r.id})" title="ดูรายละเอียด">
                        <i class="fas fa-eye"></i>
                    </button>
                    ${r.needs_approval ? `
                        <button class="btn btn-sm btn-warning" onclick="approveQuote(${r.id})" title="อนุมัติ">
                            <i class="fas fa-check"></i>
                        </button>
                    ` : ''}
                    ${r.needs_payment ? `
                        <button class="btn btn-sm btn-primary" onclick="openPaymentModal(${r.id})" title="ชำระเงิน">
                            <i class="fas fa-credit-card"></i>
                        </button>
                    ` : ''}
                </div>
            </td>
        </tr>
    `).join('');
    
    // Mobile cards
    mobileCards.innerHTML = repairs.map(r => `
        <div class="mobile-card" ${r.needs_approval || r.needs_payment ? 'style="border-color:#fcd34d;"' : ''}>
            ${r.needs_approval ? '<div class="action-needed-banner"><i class="fas fa-bell"></i> รออนุมัติใบเสนอราคา</div>' : ''}
            ${r.needs_payment ? '<div class="action-needed-banner"><i class="fas fa-bell"></i> รอชำระค่าซ่อม</div>' : ''}
            <div class="mobile-card-header">
                <span class="mobile-card-title">${r.repair_no || '-'}</span>
                <span class="status-badge status-${r.status}">${r.status_display}</span>
            </div>
            <div class="mobile-card-row">
                <span class="mobile-card-label">สินค้า</span>
                <span class="mobile-card-value">${r.item_description || '-'}</span>
            </div>
            <div class="mobile-card-row">
                <span class="mobile-card-label">อาการ</span>
                <span class="mobile-card-value" style="max-width:150px;overflow:hidden;text-overflow:ellipsis;">${r.issue_description || '-'}</span>
            </div>
            <div class="mobile-card-row">
                <span class="mobile-card-label">ราคาซ่อม</span>
                <span class="mobile-card-value">${r.quoted_amount ? '฿' + formatNumber(r.quoted_amount) : '-'}</span>
            </div>
            <div class="mobile-card-row">
                <span class="mobile-card-label">ความคืบหน้า</span>
                <span class="mobile-card-value">${r.progress_percent}%</span>
            </div>
            <div class="progress-bar-container" style="margin-top:0.5rem;">
                <div class="progress-bar-fill" style="width:${r.progress_percent}%"></div>
            </div>
            <div class="mobile-card-actions">
                <button class="btn btn-sm btn-secondary" onclick="viewDetail(${r.id})">
                    <i class="fas fa-eye"></i> ดู
                </button>
                ${r.needs_approval ? `
                    <button class="btn btn-sm btn-warning" onclick="approveQuote(${r.id})">
                        <i class="fas fa-check"></i> อนุมัติ
                    </button>
                ` : ''}
                ${r.needs_payment ? `
                    <button class="btn btn-sm btn-primary" onclick="openPaymentModal(${r.id})">
                        <i class="fas fa-credit-card"></i> ชำระ
                    </button>
                ` : ''}
            </div>
        </div>
    `).join('');
}

function updateSummary(summary) {
    if (!summary) return;
    document.getElementById('activeCount').textContent = summary.active_count || 0;
    document.getElementById('awaitingCount').textContent = summary.awaiting_approval_count || 0;
    document.getElementById('completedCount').textContent = summary.completed_count || 0;
    document.getElementById('totalPaid').textContent = '฿' + formatNumber(summary.total_paid || 0);
}

function renderPagination(pagination) {
    if (!pagination) return;
    const container = document.getElementById('repairsPagination');
    const { page, total_pages, total } = pagination;
    
    if (total_pages <= 1) {
        container.innerHTML = '';
        return;
    }
    
    container.innerHTML = `
        <button class="btn-pagination" onclick="loadRepairs(${page - 1})" ${page <= 1 ? 'disabled' : ''}>
            <i class="fas fa-chevron-left"></i>
        </button>
        <span class="page-indicator">หน้า ${page} / ${total_pages}</span>
        <button class="btn-pagination" onclick="loadRepairs(${page + 1})" ${page >= total_pages ? 'disabled' : ''}>
            <i class="fas fa-chevron-right"></i>
        </button>
    `;
}

async function viewDetail(id) {
    try {
        const response = await fetch(`/api/customer/repairs?id=${id}`, {
            headers: { 'Authorization': 'Bearer ' + getToken() }
        });
        const data = await response.json();
        
        if (data.success) {
            const r = data.data;
            const timeline = data.timeline || [];
            selectedRepairData = r;
            
            let html = `
                <div class="repair-info-card">
                    <div class="repair-info-row">
                        <span class="repair-info-label">รหัสซ่อม</span>
                        <span class="repair-info-value">${r.repair_no || '-'}</span>
                    </div>
                    <div class="repair-info-row">
                        <span class="repair-info-label">สินค้า</span>
                        <span class="repair-info-value">${r.item_description || '-'}</span>
                    </div>
                    ${r.brand ? `<div class="repair-info-row"><span class="repair-info-label">แบรนด์</span><span class="repair-info-value">${r.brand}</span></div>` : ''}
                    ${r.model ? `<div class="repair-info-row"><span class="repair-info-label">รุ่น</span><span class="repair-info-value">${r.model}</span></div>` : ''}
                    <div class="repair-info-row">
                        <span class="repair-info-label">อาการ</span>
                        <span class="repair-info-value">${r.issue_description || '-'}</span>
                    </div>
                    <div class="repair-info-row">
                        <span class="repair-info-label">สถานะ</span>
                        <span class="status-badge status-${r.status}">${r.status_display}</span>
                    </div>
                    <div class="repair-info-row">
                        <span class="repair-info-label">ความคืบหน้า</span>
                        <span class="repair-info-value">${r.progress_percent}%</span>
                    </div>
                </div>
            `;
            
            // Quote section
            if (r.quoted_amount) {
                html += `
                    <div class="quote-card">
                        <h4>💰 ใบเสนอราคา</h4>
                        <div class="quote-amount">฿${formatNumber(r.quoted_amount)}</div>
                        ${r.quote_note ? `<div class="quote-note">${r.quote_note}</div>` : ''}
                        ${r.quote_valid_until ? `<div class="quote-valid">ใช้ได้ถึง: ${formatDate(r.quote_valid_until)}</div>` : ''}
                    </div>
                `;
            }
            
            // Timeline
            if (timeline.length > 0) {
                html += `<h4 style="margin-top:1.5rem;">📅 ไทม์ไลน์</h4><div class="timeline">`;
                timeline.forEach((t, i) => {
                    const isLast = i === timeline.length - 1;
                    html += `
                        <div class="timeline-item ${t.completed ? 'completed' : ''} ${isLast && t.completed ? 'current' : ''}">
                            <div class="timeline-event">${t.event}</div>
                            ${t.date ? `<div class="timeline-date">${formatDateTime(t.date)}</div>` : ''}
                        </div>
                    `;
                });
                html += `</div>`;
            }
            
            // Warranty info
            if (r.warranty_until) {
                html += `
                    <div class="repair-info-card" style="margin-top:1rem;background:#d1fae5;">
                        <div class="repair-info-row">
                            <span class="repair-info-label">🛡️ รับประกัน</span>
                            <span class="repair-info-value">${r.warranty_months} เดือน (ถึง ${formatDate(r.warranty_until)})</span>
                        </div>
                    </div>
                `;
            }
            
            document.getElementById('repairDetailContent').innerHTML = html;
            
            // Footer actions
            let footerHtml = '<button class="btn btn-secondary" onclick="closeDetailModal()">ปิด</button>';
            if (r.can_approve) {
                footerHtml += `<button class="btn btn-warning" onclick="approveQuote(${r.id}); closeDetailModal();"><i class="fas fa-check"></i> อนุมัติใบเสนอราคา</button>`;
            }
            if (r.needs_payment) {
                footerHtml += `<button class="btn btn-primary" onclick="openPaymentModal(${r.id}); closeDetailModal();"><i class="fas fa-credit-card"></i> ชำระค่าซ่อม</button>`;
            }
            document.getElementById('detailModalFooter').innerHTML = footerHtml;
            
            document.getElementById('detailModal').classList.add('active');
        }
    } catch (error) {
        console.error('Error loading detail:', error);
        showError('ไม่สามารถโหลดรายละเอียดได้');
    }
}

function closeDetailModal() {
    document.getElementById('detailModal').classList.remove('active');
}

async function approveQuote(id) {
    if (!confirm('ยืนยันอนุมัติใบเสนอราคา?')) return;
    
    try {
        const response = await fetch('/api/customer/repairs?action=approve', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + getToken()
            },
            body: JSON.stringify({ repair_id: id })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showSuccess(data.message || 'อนุมัติเรียบร้อยแล้ว');
            loadRepairs(currentPage);
        } else {
            showError(data.message || 'ไม่สามารถอนุมัติได้');
        }
    } catch (error) {
        console.error('Error:', error);
        showError('เกิดข้อผิดพลาด');
    }
}

async function openPaymentModal(id) {
    selectedRepairId = id;
    slipUrl = null;
    document.getElementById('slipPreview').style.display = 'none';
    document.querySelector('.upload-placeholder').style.display = 'block';
    document.getElementById('submitPaymentBtn').disabled = true;
    
    try {
        const response = await fetch(`/api/customer/repairs?id=${id}`, {
            headers: { 'Authorization': 'Bearer ' + getToken() }
        });
        const data = await response.json();
        
        if (data.success) {
            selectedRepairData = data.data;
            const r = data.data;
            
            document.getElementById('repairPaymentInfo').innerHTML = `
                <div class="repair-info-card">
                    <div class="repair-info-row">
                        <span class="repair-info-label">รหัสซ่อม</span>
                        <span class="repair-info-value">${r.repair_no}</span>
                    </div>
                    <div class="repair-info-row">
                        <span class="repair-info-label">สินค้า</span>
                        <span class="repair-info-value">${r.item_description || '-'}</span>
                    </div>
                    <div class="repair-info-row">
                        <span class="repair-info-label">ยอดชำระ</span>
                        <span class="repair-info-value amount-highlight">฿${formatNumber(r.quoted_amount)}</span>
                    </div>
                </div>
            `;
            
            // Bank accounts
            const banks = data.bank_accounts || [];
            document.getElementById('bankAccountsList').innerHTML = banks.length > 0 ? 
                banks.map(b => `
                    <div class="bank-account-card">
                        <div class="bank-account-header">
                            <span class="bank-account-name">${b.bank_name}</span>
                        </div>
                        <div class="bank-account-number">${b.account_number}</div>
                        <div class="bank-account-holder">${b.account_name}</div>
                    </div>
                `).join('') : '<p style="color:#6b7280;">ไม่พบข้อมูลบัญชีธนาคาร</p>';
            
            document.getElementById('paymentModal').classList.add('active');
        }
    } catch (error) {
        console.error('Error:', error);
        showError('ไม่สามารถโหลดข้อมูลได้');
    }
}

function closePaymentModal() {
    document.getElementById('paymentModal').classList.remove('active');
    selectedRepairId = null;
    slipUrl = null;
}

function handleSlipUpload(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        const reader = new FileReader();
        
        reader.onload = function(e) {
            document.getElementById('slipPreview').src = e.target.result;
            document.getElementById('slipPreview').style.display = 'block';
            document.querySelector('.upload-placeholder').style.display = 'none';
            
            slipUrl = e.target.result;
            document.getElementById('submitPaymentBtn').disabled = false;
        };
        
        reader.readAsDataURL(file);
    }
}

async function submitPayment() {
    if (!selectedRepairId || !slipUrl) return;
    
    const btn = document.getElementById('submitPaymentBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังส่ง...';
    
    try {
        const response = await fetch('/api/customer/repairs?action=pay', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + getToken()
            },
            body: JSON.stringify({
                repair_id: selectedRepairId,
                slip_image_url: slipUrl
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showSuccess(data.message || 'ส่งสลิปเรียบร้อยแล้ว');
            closePaymentModal();
            loadRepairs(currentPage);
        } else {
            showError(data.message || 'ไม่สามารถส่งสลิปได้');
        }
    } catch (error) {
        console.error('Error:', error);
        showError('เกิดข้อผิดพลาด');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> ส่งสลิป';
    }
}

// Utility functions
function formatNumber(num) {
    return parseFloat(num || 0).toLocaleString('th-TH', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
    const d = new Date(dateStr);
    return d.toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}

function formatDateTime(dateStr) {
    if (!dateStr) return '-';
    const d = new Date(dateStr);
    return d.toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function getToken() {
    return localStorage.getItem('auth_token') || '';
}

function showError(msg) { alert(msg); }
function showSuccess(msg) { alert(msg); }

// ========================================
// Create Modal Functions
// ========================================

function openCreateModal() {
    document.getElementById('createRepairForm').reset();
    clearSelectedCustomer();
    // Set default estimated date (7 days from now)
    const estDate = new Date();
    estDate.setDate(estDate.getDate() + 7);
    document.getElementById('estimatedDate').value = estDate.toISOString().split('T')[0];
    document.getElementById('createModal').classList.add('active');
}

function closeCreateModal() {
    document.getElementById('createModal').classList.remove('active');
}

let searchTimeout;
async function searchCustomers(query) {
    const resultsDiv = document.getElementById('customerSearchResults');
    
    if (query.length < 2) {
        resultsDiv.style.display = 'none';
        return;
    }
    
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(async () => {
        try {
            const response = await fetch(`/api/admin/customers.php?search=${encodeURIComponent(query)}&limit=10`, {
                headers: { 'Authorization': 'Bearer ' + getToken() }
            });
            const data = await response.json();
            
            if (data.success && data.data && data.data.length > 0) {
                resultsDiv.innerHTML = data.data.map(c => `
                    <div class="autocomplete-item" onclick='selectCustomer(${JSON.stringify(c).replace(/'/g, "\\'")})''>
                        <div class="autocomplete-item-avatar">
                            ${c.avatar_url || c.profile_picture ? 
                                `<img src="${c.avatar_url || c.profile_picture}" alt="">` : 
                                getInitials(c.display_name || c.full_name || 'ไม่ระบุ')
                            }
                        </div>
                        <div class="autocomplete-item-info">
                            <div class="autocomplete-item-name">${c.display_name || c.full_name || 'ไม่ระบุชื่อ'}</div>
                            <div class="autocomplete-item-phone">${c.phone || ''}</div>
                        </div>
                        <div class="autocomplete-item-platform ${c.platform || ''}">
                            ${c.platform === 'line' ? '<i class="fab fa-line"></i> LINE' : 
                              c.platform === 'facebook' ? '<i class="fab fa-facebook"></i> FB' : 
                              c.platform || ''}
                        </div>
                    </div>
                `).join('');
                resultsDiv.style.display = 'block';
            } else {
                resultsDiv.innerHTML = '<div class="autocomplete-item no-result">ไม่พบลูกค้า</div>';
                resultsDiv.style.display = 'block';
            }
        } catch (error) {
            console.error('Search error:', error);
        }
    }, 300);
}

function selectCustomer(customer) {
    document.getElementById('selectedCustomerId').value = customer.id;
    document.getElementById('customerSearch').value = '';
    document.getElementById('customerSearchResults').style.display = 'none';
    
    document.getElementById('selectedCustomerCard').style.display = 'flex';
    document.getElementById('selectedCustomerName').textContent = customer.display_name || customer.full_name || 'ไม่ระบุ';
    
    const avatar = document.getElementById('selectedCustomerAvatar');
    if (customer.avatar_url || customer.profile_picture) {
        avatar.innerHTML = `<img src="${customer.avatar_url || customer.profile_picture}" alt="" style="width:100%;height:100%;object-fit:cover;">`;
    } else {
        document.getElementById('selectedCustomerInitials').textContent = getInitials(customer.display_name || customer.full_name || 'ไม่ระบุ');
    }
    
    let meta = [];
    if (customer.platform) meta.push(customer.platform === 'line' ? 'LINE' : customer.platform === 'facebook' ? 'Facebook' : customer.platform);
    if (customer.phone) meta.push(customer.phone);
    document.getElementById('selectedCustomerMeta').textContent = meta.join(' • ');
}

function clearSelectedCustomer() {
    document.getElementById('selectedCustomerId').value = '';
    document.getElementById('selectedCustomerCard').style.display = 'none';
    document.getElementById('customerSearch').value = '';
}

function getInitials(name) {
    if (!name) return '-';
    const parts = name.split(' ');
    if (parts.length >= 2) {
        return parts[0].charAt(0) + parts[1].charAt(0);
    }
    return name.substring(0, 2).toUpperCase();
}

async function submitCreateRepair() {
    const customerId = document.getElementById('selectedCustomerId').value;
    const itemType = document.getElementById('itemType').value;
    const itemName = document.getElementById('itemName').value;
    const itemDescription = document.getElementById('itemDescription').value;
    const issue = document.getElementById('repairIssue').value;
    const estimatedCost = document.getElementById('estimatedCost').value;
    const estimatedDate = document.getElementById('estimatedDate').value;
    const notes = document.getElementById('repairNotes').value;
    
    if (!customerId) {
        showError('กรุณาเลือกลูกค้า');
        return;
    }
    
    if (!itemType || !itemName || !issue) {
        showError('กรุณากรอกข้อมูลให้ครบ');
        return;
    }
    
    try {
        const response = await fetch('/api/admin/repairs.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + getToken()
            },
            body: JSON.stringify({
                customer_id: customerId,
                item_type: itemType,
                item_name: itemName,
                item_description: itemDescription,
                issue: issue,
                estimated_cost: estimatedCost,
                estimated_completion_date: estimatedDate,
                notes: notes
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showSuccess('บันทึกรายการซ่อมสำเร็จ');
            closeCreateModal();
            loadRepairs(1);
        } else {
            showError(data.message || 'เกิดข้อผิดพลาด');
        }
    } catch (error) {
        console.error('Error:', error);
        showError('เกิดข้อผิดพลาดในการบันทึก');
    }
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.autocomplete-wrapper')) {
        document.getElementById('customerSearchResults').style.display = 'none';
    }
});
</script>

<?php include('../includes/customer/footer.php'); ?>
