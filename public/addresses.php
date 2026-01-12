<?php
/**
 * addresses - Customer Portal  
 */
define('INCLUDE_CHECK', true);

$page_title = "ที่อยู่จัดส่ง - AI Automation";
$current_page = "addresses";

include('../includes/customer/header.php');
include('../includes/customer/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">ที่อยู่จัดส่ง</h1>
            <p class="page-subtitle">จัดการที่อยู่สำหรับจัดส่งสินค้า</p>
        </div>
        <button class="btn btn-primary" onclick="showAddressForm()">
            <i class="fas fa-plus"></i> เพิ่มที่อยู่
        </button>
    </div>

    <!-- Unified Filter Panel -->
    <div class="filter-panel">
        <!-- Search Row -->
        <div class="filter-row filter-row-search">
            <div class="search-wrapper">
                <i class="fas fa-search"></i>
                <input 
                    type="search" 
                    id="addressSearch" 
                    placeholder="ค้นหาชื่อผู้รับ, เบอร์โทร, จังหวัด, อำเภอ, รหัสไปรษณีย์..."
                    aria-label="ค้นหา"
                >
            </div>
        </div>
        
        <!-- Filter Row -->
        <div class="filter-row filter-row-options">
            <div class="filter-group">
                <label class="filter-label">ตัวกรอง</label>
                <div class="filter-chips">
                    <button class="filter-chip active" data-filter="" onclick="filterAddresses('')"><i class="fas fa-list"></i> ทั้งหมด</button>
                    <button class="filter-chip" data-filter="default" onclick="filterAddresses('default')"><i class="fas fa-star"></i> ที่อยู่หลัก</button>
                </div>
            </div>
            
            <div class="filter-summary" id="addressFilterHint">
                <!-- Filter summary will be shown here -->
            </div>
        </div>
    </div>

    <!-- Addresses List -->
    <div class="card">
        <div class="card-body" style="padding: 0;">
            <!-- Desktop Table View -->
            <div class="table-container" id="addressesTableContainer">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;"></th>
                            <th>ผู้รับ / เบอร์โทร</th>
                            <th>ที่อยู่</th>
                            <th>จังหวัด</th>
                            <th>รหัสไปรษณีย์</th>
                            <th style="width: 140px;"></th>
                        </tr>
                    </thead>
                    <tbody id="addressesTableBody">
                        <tr>
                            <td colspan="6" style="text-align:center;padding:3rem;">
                                <div class="spinner" style="margin:0 auto 1rem;"></div>
                                กำลังโหลดข้อมูล...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Mobile Card View -->
            <div id="addressesMobileContainer" class="addresses-mobile-list" style="display:none;"></div>
        </div>
    </div>
    
    <!-- Pagination -->
    <div id="addressesPagination" class="pagination-container"></div>
</main>

<!-- Address Form Modal -->
<div id="addressModal" class="modal modal-ui" data-ui="modal" style="display:none;">
    <div class="modal-overlay" onclick="closeAddressModal()"></div>
    <div class="modal-dialog">
        <div class="modal-content-wrapper">
            <div class="modal-header-custom">
                <h2 id="modalTitle" class="modal-title-custom">เพิ่มที่อยู่ใหม่</h2>
                <button class="modal-close-custom" onclick="closeAddressModal()">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M18 6L6 18M6 6l12 12" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
            <div class="modal-body-custom">
                <form id="addressForm">
                    <input type="hidden" id="addressId">
                    
                    <div class="row">
                        <div class="col-6">
                            <div class="form-group">
                                <label>ชื่อผู้รับ <span style="color:red;">*</span></label>
                                <input type="text" id="recipientName" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-group">
                                <label>เบอร์โทร <span style="color:red;">*</span></label>
                                <input type="tel" id="phone" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>ที่อยู่ (บ้านเลขที่ ถนน) <span style="color:red;">*</span></label>
                        <input type="text" id="addressLine1" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label>ที่อยู่เพิ่มเติม (อาคาร ชั้น ห้อง)</label>
                        <input type="text" id="addressLine2" class="form-control">
                    </div>
                    
                    <div class="row">
                        <div class="col-6">
                            <div class="form-group">
                                <label>ตำบล/แขวง</label>
                                <input type="text" id="subdistrict" class="form-control">
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-group">
                                <label>อำเภอ/เขต <span style="color:red;">*</span></label>
                                <input type="text" id="district" class="form-control" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-6">
                            <div class="form-group">
                                <label>จังหวัด <span style="color:red;">*</span></label>
                                <input type="text" id="province" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-group">
                                <label>รหัสไปรษณีย์ <span style="color:red;">*</span></label>
                                <input type="text" id="postalCode" class="form-control" pattern="[0-9]{5}" required>
                            </div>
                        </div>
                    </div>
                    
                    <div style="margin-top:1.5rem;display:flex;gap:1rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> บันทึก
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeAddressModal()">ยกเลิก</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
/* =====================================================
   PAGE HEADER - Flexbox for button alignment
   ===================================================== */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.page-header .btn {
    white-space: nowrap;
}

@media (max-width: 576px) {
    .page-header {
        flex-direction: column;
        align-items: stretch;
    }
    
    .page-header .btn {
        width: 100%;
        justify-content: center;
    }
}

/* =====================================================
   PROFESSIONAL FILTER PANEL - Clean & Minimal Design
   ===================================================== */
.filter-panel {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    margin-bottom: 1.5rem;
    overflow: hidden;
}

.filter-row {
    padding: 1rem 1.25rem;
}

.filter-row-search {
    border-bottom: 1px solid #f3f4f6;
}

.filter-row-options {
    display: flex;
    flex-wrap: wrap;
    gap: 1.5rem;
    align-items: center;
}

/* Search Input */
.search-wrapper {
    position: relative;
    width: 100%;
}

.search-wrapper i {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: #9ca3af;
    font-size: 0.9rem;
}

.search-wrapper input {
    width: 100%;
    padding: 0.75rem 1rem 0.75rem 2.75rem;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-size: 0.95rem;
    background: #f9fafb;
    color: #111827;
    transition: all 0.2s ease;
}

.search-wrapper input:focus {
    outline: none;
    background: #ffffff;
    border-color: #6b7280;
    box-shadow: 0 0 0 3px rgba(107, 114, 128, 0.1);
}

.search-wrapper input::placeholder {
    color: #9ca3af;
}

/* Filter Groups */
.filter-group {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.filter-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Filter Chips */
.filter-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.filter-chip {
    padding: 0.5rem 1rem;
    border: 1px solid #e5e7eb;
    background: #ffffff;
    border-radius: 6px;
    font-size: 0.875rem;
    font-weight: 500;
    color: #4b5563;
    cursor: pointer;
    transition: all 0.15s ease;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.filter-chip i {
    font-size: 0.8rem;
    opacity: 0.7;
}

.filter-chip:hover {
    background: #f9fafb;
    border-color: #d1d5db;
}

.filter-chip.active {
    background: #1f2937;
    border-color: #1f2937;
    color: #ffffff;
}

.filter-chip.active i {
    opacity: 1;
}

/* Filter Summary */
.filter-summary {
    margin-left: auto;
    font-size: 0.875rem;
    color: #6b7280;
}

/* =====================================================
   DATA TABLE - Professional & Compact
   ===================================================== */
.table-container {
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}

.data-table thead {
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
}

.data-table th {
    padding: 0.875rem 1rem;
    text-align: left;
    font-weight: 600;
    color: #374151;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    white-space: nowrap;
}

.data-table td {
    padding: 1rem;
    border-bottom: 1px solid #f3f4f6;
    vertical-align: middle;
    color: #1f2937;
}

.data-table tbody tr:hover {
    background: #f9fafb;
}

.data-table tbody tr.is-highlighted {
    background: #eef2ff;
    box-shadow: inset 0 0 0 2px rgba(99, 102, 241, 0.3);
}

/* Default Badge */
.default-badge-sm {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    background: #10b981;
    color: white;
    border-radius: 50%;
    font-size: 0.7rem;
}

/* Recipient Cell */
.recipient-cell {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
}

.recipient-name {
    font-weight: 600;
    color: #111827;
}

.recipient-phone {
    font-size: 0.8rem;
    color: #6b7280;
}

/* Address Cell */
.address-cell {
    max-width: 300px;
    line-height: 1.4;
    color: #4b5563;
    font-size: 0.85rem;
}

/* Action Buttons */
.action-btns {
    display: flex;
    gap: 0.375rem;
}

.btn-action {
    padding: 0.375rem 0.625rem;
    border: 1px solid #e5e7eb;
    background: #ffffff;
    border-radius: 6px;
    font-size: 0.75rem;
    color: #6b7280;
    cursor: pointer;
    transition: all 0.15s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

.btn-action:hover {
    background: #f3f4f6;
    border-color: #d1d5db;
    color: #374151;
}

.btn-action.btn-primary-action {
    background: #1f2937;
    border-color: #1f2937;
    color: #ffffff;
}

.btn-action.btn-primary-action:hover {
    background: #111827;
}

.btn-action.btn-danger-action {
    color: #dc2626;
    border-color: #fecaca;
}

.btn-action.btn-danger-action:hover {
    background: #fef2f2;
    border-color: #fca5a5;
}

/* =====================================================
   MOBILE CARD VIEW
   ===================================================== */
.addresses-mobile-list {
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.address-mobile-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 1rem;
    position: relative;
}

.address-mobile-card.is-default {
    border-color: #10b981;
    background: linear-gradient(to bottom right, #ffffff, #f0fdf4);
}

.address-mobile-card.is-default::before {
    content: '✓ ที่อยู่หลัก';
    position: absolute;
    top: -8px;
    right: 1rem;
    background: #10b981;
    color: white;
    padding: 0.125rem 0.5rem;
    border-radius: 10px;
    font-size: 0.7rem;
    font-weight: 600;
}

.address-mobile-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.75rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid #f3f4f6;
}

.address-mobile-recipient {
    font-weight: 600;
    color: #111827;
}

.address-mobile-phone {
    font-size: 0.85rem;
    color: #6b7280;
    margin-top: 0.125rem;
}

.address-mobile-body {
    font-size: 0.9rem;
    line-height: 1.5;
    color: #4b5563;
    margin-bottom: 0.75rem;
}

.address-mobile-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

/* Responsive */
@media (max-width: 768px) {
    .table-container {
        display: none !important;
    }
    
    .addresses-mobile-list {
        display: flex !important;
    }
    
    .filter-row-options {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
    }
    
    .filter-summary {
        margin-left: 0;
    }
}

@media (min-width: 769px) {
    .table-container {
        display: block !important;
    }
    
    .addresses-mobile-list {
        display: none !important;
    }
}

/* =====================================================
   PAGINATION
   ===================================================== */
.pagination-container {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 0.5rem;
    padding: 1rem;
    margin-top: 0.5rem;
}

.btn-pagination {
    padding: 0.5rem 1rem;
    border: 1px solid #e5e7eb;
    background: white;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    color: #374151;
}

.btn-pagination:hover:not([disabled]) {
    background: #f3f4f6;
    border-color: #6b7280;
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

/* =====================================================
   SPINNER
   ===================================================== */
.spinner {
    width: 48px;
    height: 48px;
    border: 4px solid #e5e7eb;
    border-top-color: #3b82f6;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* =====================================================
   PROFESSIONAL MODAL - Clean & Minimal Design
   ===================================================== */
.modal-ui {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
}

.modal-dialog {
    position: relative;
    z-index: 10000;
    width: 100%;
    max-width: 560px;
    max-height: 90vh;
    margin: 1rem;
    animation: modalFadeIn 0.2s ease-out;
}

@keyframes modalFadeIn {
    from {
        opacity: 0;
        transform: translateY(-20px) scale(0.98);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.modal-content-wrapper {
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    max-height: 90vh;
}

.modal-header-custom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid #e5e7eb;
    background: #f9fafb;
}

.modal-title-custom {
    font-size: 1.125rem;
    font-weight: 600;
    color: #111827;
    margin: 0;
}

.modal-close-custom {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: transparent;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #6b7280;
    transition: all 0.15s ease;
}

.modal-close-custom:hover {
    background: #e5e7eb;
    color: #111827;
}

.modal-body-custom {
    padding: 1.5rem;
    overflow-y: auto;
    flex: 1;
}

/* Form Styling in Modal */
.modal-body-custom .form-group {
    margin-bottom: 1rem;
}

.modal-body-custom label {
    display: block;
    font-size: 0.875rem;
    font-weight: 500;
    color: #374151;
    margin-bottom: 0.375rem;
}

.modal-body-custom .form-control {
    width: 100%;
    padding: 0.625rem 0.875rem;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-size: 0.95rem;
    color: #111827;
    background: #ffffff;
    transition: all 0.15s ease;
}

.modal-body-custom .form-control:focus {
    outline: none;
    border-color: #6b7280;
    box-shadow: 0 0 0 3px rgba(107, 114, 128, 0.1);
}

.modal-body-custom .form-control::placeholder {
    color: #9ca3af;
}

/* Row/Column in Modal */
.modal-body-custom .row {
    display: flex;
    gap: 1rem;
    margin-bottom: 0;
}

.modal-body-custom .col-6 {
    flex: 1;
    min-width: 0;
}

/* Buttons in Modal */
.modal-body-custom .btn {
    padding: 0.625rem 1.25rem;
    border-radius: 8px;
    font-size: 0.95rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    border: 1px solid transparent;
}

.modal-body-custom .btn-primary {
    background: #1f2937;
    color: #ffffff;
    border-color: #1f2937;
}

.modal-body-custom .btn-primary:hover {
    background: #111827;
}

.modal-body-custom .btn-secondary {
    background: #ffffff;
    color: #4b5563;
    border-color: #e5e7eb;
}

.modal-body-custom .btn-secondary:hover {
    background: #f3f4f6;
    border-color: #d1d5db;
}

/* Responsive Modal */
@media (max-width: 576px) {
    .modal-dialog {
        margin: 0;
        max-width: 100%;
        max-height: 100%;
    }
    
    .modal-content-wrapper {
        border-radius: 0;
        max-height: 100vh;
        height: 100%;
    }
    
    .modal-body-custom .row {
        flex-direction: column;
        gap: 0;
    }
    
    .modal-body-custom .col-6 {
        width: 100%;
    }
}

/* =====================================================
   CONFIRM DIALOG - JavaScript confirm() replacement
   ===================================================== */
.confirm-dialog-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
    z-index: 99999;
    display: flex;
    align-items: center;
    justify-content: center;
    animation: fadeIn 0.15s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.confirm-dialog-box {
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
    max-width: 400px;
    width: 90%;
    padding: 1.5rem;
    animation: modalFadeIn 0.2s ease-out;
}

.confirm-dialog-icon {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: #fef3c7;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1rem;
    font-size: 1.5rem;
}

.confirm-dialog-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #111827;
    text-align: center;
    margin-bottom: 0.5rem;
}

.confirm-dialog-message {
    font-size: 0.95rem;
    color: #6b7280;
    text-align: center;
    margin-bottom: 1.5rem;
    line-height: 1.5;
}

.confirm-dialog-buttons {
    display: flex;
    gap: 0.75rem;
    justify-content: center;
}

.confirm-dialog-btn {
    padding: 0.625rem 1.5rem;
    border-radius: 8px;
    font-size: 0.95rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s ease;
    border: 1px solid transparent;
    min-width: 100px;
}

.confirm-dialog-btn.btn-cancel {
    background: #ffffff;
    color: #4b5563;
    border-color: #e5e7eb;
}

.confirm-dialog-btn.btn-cancel:hover {
    background: #f3f4f6;
}

.confirm-dialog-btn.btn-danger {
    background: #dc2626;
    color: #ffffff;
}

.confirm-dialog-btn.btn-danger:hover {
    background: #b91c1c;
}

.confirm-dialog-btn.btn-confirm {
    background: #1f2937;
    color: #ffffff;
}

.confirm-dialog-btn.btn-confirm:hover {
    background: #111827;
}
</style>

<!-- Customer Profile Component -->
<link rel="stylesheet" href="<?php echo asset('css/components/customer-profile.css'); ?>?v=<?php echo time(); ?>">
<script src="<?php echo asset('js/components/customer-profile.js'); ?>?v=<?php echo time(); ?>"></script>

<?php
$extra_scripts = [
    'assets/js/addresses.js'
];

include('../includes/customer/footer.php');
?>
