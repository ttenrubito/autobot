<?php
/**
 * Payment History - Customer Portal
 */
define('INCLUDE_CHECK', true);

$page_title = "ประวัติการชำระเงิน - AI Automation";
$current_page = "payment_history";

include('../includes/customer/header.php');
include('../includes/customer/sidebar.php');
?>

<!-- Main Content -->
<main class="main-content">
    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-content">
            <div>
                <h1 class="page-title">ประวัติการชำระเงิน</h1>
                <p class="page-subtitle">ดูรายการชำระเงินและสลิปการโอน</p>
            </div>
            <button class="btn btn-primary btn-sm" onclick="openAddPaymentModal()">
                <i class="fas fa-plus"></i> <span class="btn-text">เพิ่มรายการ</span>
            </button>
        </div>
    </div>

    <!-- Unified Filter Panel -->
    <div class="filter-panel">
        <!-- Search Row -->
        <div class="filter-row filter-row-search">
            <div class="search-wrapper">
                <i class="fas fa-search"></i>
                <input type="search" id="paymentSearch" placeholder="ค้นหาเลขชำระเงิน, คำสั่งซื้อ, จำนวนเงิน..."
                    aria-label="ค้นหา">
            </div>
        </div>

        <!-- Filter Row -->
        <div class="filter-row filter-row-options">
            <!-- Status Tabs -->
            <div class="filter-group">
                <label class="filter-label">ประเภท</label>
                <div class="filter-chips">
                    <button class="filter-chip active" data-filter="" onclick="filterPayments('', event)"><i
                            class="fas fa-list"></i> ทั้งหมด</button>
                    <button class="filter-chip" data-filter="full" onclick="filterPayments('full', event)"><i
                            class="fas fa-credit-card"></i> จ่ายเต็ม</button>
                    <button class="filter-chip" data-filter="installment"
                        onclick="filterPayments('installment', event)"><i class="fas fa-calendar-alt"></i>
                        ผ่อนชำระ</button>
                    <button class="filter-chip" data-filter="savings" onclick="filterPayments('savings', event)"><i
                            class="fas fa-piggy-bank"></i> ออมเงิน</button>
                    <button class="filter-chip" data-filter="pending" onclick="filterPayments('pending', event)"><i
                            class="fas fa-clock"></i> รอตรวจสอบ</button>
                </div>
            </div>

            <!-- Date Range -->
            <div class="filter-group filter-group-date">
                <label class="filter-label">ช่วงวันที่</label>
                <div class="date-range-inline">
                    <input type="date" id="startDate" aria-label="วันเริ่มต้น">
                    <span class="date-sep">—</span>
                    <input type="date" id="endDate" aria-label="วันสิ้นสุด">
                    <button class="btn-apply" onclick="applyDateFilter()" title="กรอง">
                        <i class="fas fa-check"></i>
                    </button>
                    <button class="btn-reset" onclick="clearDateFilter()" title="ล้าง">
                        <i class="fas fa-undo"></i>
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
            <!-- Desktop Table View -->
            <div class="table-container" id="paymentsTableContainer">
                <table class="payments-table">
                    <thead>
                        <tr>
                            <th>เลขที่ชำระ</th>
                            <th>วันที่</th>
                            <th>ลูกค้า</th>
                            <th style="text-align:right;">จำนวนเงิน</th>
                            <th>ประเภท</th>
                            <th>สถานะ</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="paymentsTableBody">
                        <tr>
                            <td colspan="7" style="text-align:center;padding:2rem;">
                                <div class="spinner" style="margin:0 auto 1rem;"></div>
                                กำลังโหลดข้อมูล...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Card View (hidden on desktop) -->
            <div id="paymentsMobileContainer" class="payments-mobile-list" style="display:none;"></div>
        </div>
    </div>

    <!-- Pagination -->
    <div id="paymentPagination" class="pagination-container" style="display: none;">
        <!-- Rendered by JavaScript -->
    </div> เช่นกรณี
</main>

<!-- Payment Details Modal (Outside of main-content) -->
<div id="paymentModal" class="payment-detail-modal" style="display: none;">
    <div class="payment-modal-overlay" onclick="closePaymentModal()"></div>
    <div class="payment-modal-dialog">
        <div class="payment-modal-header">
            <h2 class="payment-modal-title">🔍 ตรวจสอบการชำระเงิน</h2>
            <button class="payment-modal-close" onclick="closePaymentModal()" aria-label="Close">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M18 6L6 18M6 6l12 12" stroke-width="2" stroke-linecap="round" />
                </svg>
            </button>
        </div>
        <div class="payment-modal-body" id="paymentDetailsContent">
            <!-- Content will be loaded here -->
        </div>
    </div>
</div>

<!-- Add Payment Modal -->
<div id="addPaymentModal" class="payment-detail-modal" style="display: none;">
    <div class="payment-modal-overlay" onclick="closeAddPaymentModal()"></div>
    <div class="payment-modal-dialog" style="max-width: 600px;">
        <div class="payment-modal-header">
            <h2 class="payment-modal-title">➕ เพิ่มรายการชำระเงิน</h2>
            <button class="payment-modal-close" onclick="closeAddPaymentModal()" aria-label="Close">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M18 6L6 18M6 6l12 12" stroke-width="2" stroke-linecap="round" />
                </svg>
            </button>
        </div>
        <div class="payment-modal-body">
            <form id="addPaymentForm" enctype="multipart/form-data">
                <!-- Payment Type -->
                <div class="form-group">
                    <label class="form-label">ประเภทการชำระ <span class="required">*</span></label>
                    <div class="payment-type-grid">
                        <label class="payment-type-option">
                            <input type="radio" name="payment_type" value="full" checked>
                            <div class="payment-type-card">
                                <i class="fas fa-credit-card"></i>
                                <span>ชำระเต็ม</span>
                            </div>
                        </label>
                        <label class="payment-type-option">
                            <input type="radio" name="payment_type" value="installment">
                            <div class="payment-type-card">
                                <i class="fas fa-calendar-alt"></i>
                                <span>ผ่อนชำระ</span>
                            </div>
                        </label>
                        <label class="payment-type-option">
                            <input type="radio" name="payment_type" value="savings">
                            <div class="payment-type-card">
                                <i class="fas fa-piggy-bank"></i>
                                <span>ออมเงิน</span>
                            </div>
                        </label>
                        <label class="payment-type-option">
                            <input type="radio" name="payment_type" value="deposit">
                            <div class="payment-type-card">
                                <i class="fas fa-hand-holding-usd"></i>
                                <span>มัดจำ</span>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Customer Search -->
                <div class="form-group">
                    <label class="form-label">ลูกค้า <span class="required">*</span></label>
                    <div class="autocomplete-wrapper">
                        <input type="text" id="customerSearch" class="form-control"
                            placeholder="พิมพ์ชื่อ/เบอร์โทร/Platform ID..." autocomplete="off">
                        <input type="hidden" id="customerProfileId" name="customer_profile_id">
                        <div id="customerSuggestions" class="autocomplete-suggestions"></div>
                    </div>
                    <div id="selectedCustomer" class="selected-item" style="display:none;"></div>
                </div>

                <!-- Order/Reference Search (conditional) -->
                <div class="form-group" id="orderSearchGroup">
                    <label class="form-label">คำสั่งซื้อ/สัญญา</label>
                    <div class="autocomplete-wrapper">
                        <input type="text" id="orderSearch" class="form-control"
                            placeholder="พิมพ์เลขที่คำสั่งซื้อ/สัญญา..." autocomplete="off">
                        <input type="hidden" id="referenceId" name="reference_id">
                        <input type="hidden" id="referenceType" name="reference_type" value="order">
                        <div id="orderSuggestions" class="autocomplete-suggestions"></div>
                    </div>
                    <div id="selectedOrder" class="selected-item" style="display:none;"></div>
                </div>

                <!-- Amount -->
                <div class="form-row">
                    <div class="form-group" style="flex:1;">
                        <label class="form-label">จำนวนเงิน <span class="required">*</span></label>
                        <div class="input-with-suffix">
                            <input type="number" id="paymentAmount" name="amount" class="form-control" step="0.01"
                                min="0" placeholder="0.00" required>
                            <span class="input-suffix">บาท</span>
                        </div>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label class="form-label">วิธีชำระ</label>
                        <select id="paymentMethod" name="payment_method" class="form-control">
                            <option value="bank_transfer">โอนเงิน</option>
                            <option value="promptpay">พร้อมเพย์</option>
                            <option value="cash">เงินสด</option>
                            <option value="credit_card">บัตรเครดิต</option>
                        </select>
                    </div>
                </div>

                <!-- Slip Upload -->
                <div class="form-group">
                    <label class="form-label">รูปสลิป</label>
                    <div class="file-upload-area" id="slipUploadArea">
                        <input type="file" id="slipImage" name="slip_image" accept="image/*" style="display:none;">
                        <div class="upload-placeholder" onclick="document.getElementById('slipImage').click()">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <span>คลิกเพื่อเลือกรูป หรือลากไฟล์มาวาง</span>
                        </div>
                        <div class="upload-preview" id="slipPreview" style="display:none;">
                            <img id="slipPreviewImg" src="" alt="Preview">
                            <button type="button" class="remove-preview" onclick="removeSlipPreview()">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Note -->
                <div class="form-group">
                    <label class="form-label">หมายเหตุ</label>
                    <textarea id="paymentNote" name="note" class="form-control" rows="2"
                        placeholder="หมายเหตุเพิ่มเติม (ถ้ามี)"></textarea>
                </div>

                <!-- Submit -->
                <div class="form-actions">
                    <button type="button" class="btn btn-outline" onclick="closeAddPaymentModal()">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary" id="submitPaymentBtn">
                        <i class="fas fa-check"></i> บันทึกรายการ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toast Notification -->
<div id="toast" class="toast"></div>

<style>
    /* =====================================================
   PROFESSIONAL FILTER PANEL - Clean & Minimal Design
   ===================================================== */

    /* Page Header with Button - Fixed width for button */
    .page-header-content {
        display: flex;
        justify-content: space-between;
        align-items: center;
        width: 100%;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .page-header-content .btn {
        flex-shrink: 0;
        width: auto !important;
    }

    .page-header-content .btn-sm {
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
    }

    @media (max-width: 576px) {
        .page-header-content {
            flex-direction: row;
        }

        .page-header-content .btn .btn-text {
            display: none;
        }

        .page-header-content .btn {
            padding: 0.5rem 0.75rem;
        }
    }

    /* Add Payment Modal Styles */
    .payment-type-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 0.75rem;
    }

    .payment-type-option {
        cursor: pointer;
    }

    .payment-type-option input {
        display: none;
    }

    .payment-type-card {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.5rem;
        padding: 1rem 0.5rem;
        border: 2px solid #e5e7eb;
        border-radius: 10px;
        transition: all 0.2s ease;
        background: #fff;
    }

    .payment-type-card i {
        font-size: 1.5rem;
        color: #6b7280;
    }

    .payment-type-card span {
        font-size: 0.8rem;
        color: #374151;
        text-align: center;
    }

    .payment-type-option input:checked+.payment-type-card {
        border-color: var(--color-primary, #3b82f6);
        background: #eff6ff;
    }

    .payment-type-option input:checked+.payment-type-card i {
        color: var(--color-primary, #3b82f6);
    }

    .autocomplete-wrapper {
        position: relative;
    }

    .autocomplete-suggestions {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        max-height: 200px;
        overflow-y: auto;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-top: none;
        border-radius: 0 0 8px 8px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        z-index: 100000;
        display: none;
    }

    .autocomplete-suggestions.show {
        display: block;
    }

    .autocomplete-item {
        padding: 0.75rem 1rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        border-bottom: 1px solid #f3f4f6;
    }

    .autocomplete-item:last-child {
        border-bottom: none;
    }

    .autocomplete-item:hover {
        background: #f9fafb;
    }

    .autocomplete-item-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: #e5e7eb;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        flex-shrink: 0;
    }

    .autocomplete-item-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .autocomplete-item-info {
        flex: 1;
        min-width: 0;
    }

    .autocomplete-item-name {
        font-weight: 500;
        color: #1f2937;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .autocomplete-item-meta {
        font-size: 0.8rem;
        color: #6b7280;
    }

    .selected-item {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem 1rem;
        background: #f0f9ff;
        border: 1px solid #bae6fd;
        border-radius: 8px;
        margin-top: 0.5rem;
    }

    .selected-item .remove-btn {
        margin-left: auto;
        color: #ef4444;
        cursor: pointer;
        padding: 0.25rem;
    }

    .form-row {
        display: flex;
        gap: 1rem;
    }

    .input-with-suffix {
        position: relative;
    }

    .input-with-suffix input {
        padding-right: 3rem;
    }

    .input-suffix {
        position: absolute;
        right: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: #6b7280;
        font-size: 0.9rem;
    }

    .file-upload-area {
        border: 2px dashed #d1d5db;
        border-radius: 10px;
        overflow: hidden;
        transition: all 0.2s ease;
    }

    .file-upload-area.dragover {
        border-color: var(--color-primary, #3b82f6);
        background: #eff6ff;
    }

    .upload-placeholder {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 2rem;
        cursor: pointer;
        color: #6b7280;
        gap: 0.5rem;
    }

    .upload-placeholder i {
        font-size: 2rem;
    }

    .upload-preview {
        position: relative;
        padding: 1rem;
    }

    .upload-preview img {
        max-width: 100%;
        max-height: 200px;
        border-radius: 8px;
    }

    .remove-preview {
        position: absolute;
        top: 0.5rem;
        right: 0.5rem;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: #ef4444;
        color: white;
        border: none;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .form-actions {
        display: flex;
        gap: 1rem;
        justify-content: flex-end;
        margin-top: 1.5rem;
        padding-top: 1rem;
        border-top: 1px solid #e5e7eb;
    }

    .required {
        color: #ef4444;
    }

    @media (max-width: 576px) {
        .payment-type-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .form-row {
            flex-direction: column;
            gap: 0;
        }

        .payment-modal-dialog {
            margin: 0;
            max-height: 100vh;
            border-radius: 0;
        }
    }

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
        align-items: flex-start;
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

    .filter-group-date {
        margin-left: auto;
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

    /* Date Range Inline */
    .date-range-inline {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .date-range-inline input[type="date"] {
        padding: 0.5rem 0.75rem;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        font-size: 0.875rem;
        background: #ffffff;
        color: #111827;
        cursor: pointer;
        min-width: 140px;
    }

    .date-range-inline input[type="date"]:focus {
        outline: none;
        border-color: #6b7280;
    }

    .date-sep {
        color: #9ca3af;
        font-size: 0.875rem;
    }

    .btn-apply,
    .btn-reset {
        width: 36px;
        height: 36px;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.15s ease;
        font-size: 0.8rem;
    }

    .btn-apply {
        background: #1f2937;
        border-color: #1f2937;
        color: #ffffff;
    }

    .btn-apply:hover {
        background: #111827;
    }

    .btn-reset {
        background: #ffffff;
        color: #6b7280;
    }

    .btn-reset:hover {
        background: #f3f4f6;
        color: #374151;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .filter-row-options {
            flex-direction: column;
            gap: 1rem;
        }

        .filter-group-date {
            margin-left: 0;
            width: 100%;
        }

        .date-range-inline {
            flex-wrap: wrap;
        }

        .date-range-inline input[type="date"] {
            flex: 1;
            min-width: 120px;
        }

        .filter-chips {
            width: 100%;
        }

        .filter-chip {
            flex: 1;
            text-align: center;
            min-width: 0;
        }
    }

    /* =====================================================
   Classification UI Styles
   ===================================================== */
    .classification-section {
        background: #f9fafb;
        border-radius: 12px;
        padding: 1.25rem;
        margin-bottom: 1rem;
        border: 1px solid #e5e7eb;
    }

    .classify-row {
        margin-bottom: 1rem;
    }

    .classify-row label {
        display: block;
        font-size: 0.875rem;
        font-weight: 600;
        color: #374151;
        margin-bottom: 0.5rem;
    }

    .classify-select {
        width: 100%;
        padding: 0.75rem 1rem;
        border: 2px solid #e5e7eb;
        border-radius: 10px;
        font-size: 1rem;
        background: #ffffff;
        color: #111827;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .classify-select:hover {
        border-color: #d1d5db;
    }

    .classify-select:focus {
        outline: none;
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
    }

    .classify-input {
        width: 100%;
        padding: 0.75rem 1rem;
        border: 2px solid #e5e7eb;
        border-radius: 10px;
        font-size: 1rem;
        background: #ffffff;
        color: #111827;
        transition: all 0.2s ease;
    }

    .classify-input:focus {
        outline: none;
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
    }

    .reference-section {
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px dashed #d1d5db;
    }

    .reference-section label {
        display: block;
        font-size: 0.875rem;
        font-weight: 600;
        color: #374151;
        margin-bottom: 0.5rem;
    }

    .period-input {
        width: 100px;
        padding: 0.5rem 0.75rem;
        border: 2px solid #e5e7eb;
        border-radius: 8px;
        font-size: 1rem;
        margin-top: 0.5rem;
    }

    .period-input:focus {
        outline: none;
        border-color: #6366f1;
    }

    #periodSection-* {
        margin-top: 0.75rem;
    }

    /* Payments Grid */
    .payments-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 1.5rem;
    }

    .payment-card {
        background: var(--color-card);
        border: 1px solid var(--color-border);
        border-radius: 16px;
        padding: 1.5rem;
        transition: all 0.3s ease;
        cursor: pointer;
        position: relative;
        overflow: hidden;
    }

    .payment-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
        background: var(--color-primary);
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    .payment-card:hover::before {
        opacity: 1;
    }

    .payment-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 28px rgba(0, 0, 0, 0.12);
        border-color: var(--color-primary);
    }

    .payment-header {
        display: flex;
        justify-content: space-between;
        align-items: start;
        margin-bottom: 1rem;
    }

    .payment-no {
        font-weight: 700;
        font-size: 1.1rem;
        color: var(--color-text);
    }

    .payment-status {
        padding: 0.375rem 0.875rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .status-verified {
        background: #10b981;
        color: white;
    }

    .status-pending {
        background: #f59e0b;
        color: white;
    }

    .status-rejected {
        background: #ef4444;
        color: white;
    }

    .payment-amount {
        font-size: 1.75rem;
        font-weight: 700;
        color: var(--color-primary);
        margin: 0.75rem 0;
    }

    .payment-details {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        font-size: 0.875rem;
        color: var(--color-gray);
    }

    .payment-detail-row {
        display: flex;
        justify-content: space-between;
    }

    .payment-type-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        padding: 0.25rem 0.75rem;
        background: var(--color-background);
        border-radius: 12px;
        font-size: 0.8rem;
        font-weight: 500;
        margin-top: 0.75rem;
    }

    .payment-type-badge.type-full {
        background: #e0f2fe;
        color: #0369a1;
    }

    .payment-type-badge.type-installment {
        background: #fef3c7;
        color: #b45309;
    }

    .payment-type-badge.type-savings {
        background: #dcfce7;
        color: #15803d;
    }

    /* Loading State */
    .loading-state {
        grid-column: 1 / -1;
        text-align: center;
        padding: 3rem;
    }

    .spinner {
        width: 48px;
        height: 48px;
        border: 4px solid var(--color-border);
        border-top-color: var(--color-primary);
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
        margin: 0 auto 1rem;
    }

    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }

    /* Professional Payment Modal - PERFECTLY CENTERED */
    .payment-modal {
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        position: fixed !important;
        inset: 0 !important;
        /* top: 0, right: 0, bottom: 0, left: 0 */
        z-index: 9999 !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .payment-modal-overlay {
        position: fixed !important;
        inset: 0 !important;
        background: rgba(0, 0, 0, 0.8);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        z-index: 9998;
    }

    .payment-modal-dialog {
        position: relative !important;
        background: var(--color-card, #ffffff);
        border-radius: 0;
        /* Full screen on mobile */
        box-shadow: 0 24px 48px rgba(0, 0, 0, 0.4);
        width: 100vw;
        /* Full width on mobile */
        height: 100vh;
        /* Full height on mobile */
        max-width: 100vw;
        max-height: 100vh;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        z-index: 9999;
        animation: modalSlideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        margin: 0 !important;
        /* Remove any margin */
    }

    @media (min-width: 768px) {
        .payment-modal-dialog {
            border-radius: 20px;
            width: 90vw !important;
            max-width: 900px !important;
            height: auto !important;
            max-height: 90vh !important;
            min-width: 600px;
        }
    }

    @keyframes modalSlideUp {
        from {
            opacity: 0;
            transform: translateY(50px) scale(0.95);
        }

        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    .payment-modal-header {
        padding: 1.5rem 2rem;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #ffffff;
        /* Clean white background */
        flex-shrink: 0;
        /* Prevent header from shrinking */
    }

    .payment-modal-title {
        font-size: 1.5rem;
        font-weight: 700;
        color: #1f2937;
        /* Professional dark gray */
        margin: 0;
    }

    .payment-modal-close {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: #f3f4f6;
        /* Subtle gray background */
        border: none;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
        color: #6b7280;
        /* Medium gray icon */
    }

    .payment-modal-close:hover {
        background: #e5e7eb;
        /* Darker gray on hover */
        color: #374151;
        /* Darker icon on hover */
        transform: rotate(90deg);
    }

    .payment-modal-body {
        padding: 1rem;
        overflow-y: auto;
        overflow-x: visible;
        flex: 1;
        background: #f9fafb;
        /* Subtle light gray background */
        -webkit-overflow-scrolling: touch;
        /* Smooth scrolling on iOS */
    }

    @media (min-width: 768px) {
        .payment-modal-body {
            padding: 2rem;
        }
    }

    /* Scrollbar styling for modal body */
    .payment-modal-body::-webkit-scrollbar {
        width: 8px;
    }

    .payment-modal-body::-webkit-scrollbar-track {
        background: rgba(0, 0, 0, 0.05);
        border-radius: 4px;
    }

    .payment-modal-body::-webkit-scrollbar-thumb {
        background: rgba(0, 0, 0, 0.15);
        /* Slightly darker for visibility */
        border-radius: 4px;
    }

    .payment-modal-body::-webkit-scrollbar-thumb:hover {
        background: rgba(0, 0, 0, 0.25);
    }

    /* Single-Column Mobile-First Layout - LIKE CHAT APP */
    .slip-chat-layout {
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
        width: 100%;
        max-width: 800px;
        /* Comfortable reading width like mobile chat */
        margin: 0 auto;
    }

    .slip-chat-left,
    .slip-chat-right {
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
        width: 100%;
    }

    /* ✅ NEW: Payment Summary Card */
    .payment-summary-card {
        background: linear-gradient(135deg, #1f2937 0%, #374151 100%);
        border-radius: 16px;
        padding: 1.5rem;
        color: white;
        width: 100%;
        box-sizing: border-box;
    }

    .summary-main {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
    }

    .summary-amount {
        font-size: 2rem;
        font-weight: 700;
        letter-spacing: -0.5px;
    }

    .summary-status {
        padding: 0.5rem 1rem;
        border-radius: 20px;
        font-size: 0.875rem;
        font-weight: 600;
    }

    .summary-status.status-verified {
        background: rgba(16, 185, 129, 0.2);
        color: #10b981;
    }

    .summary-status.status-pending {
        background: rgba(245, 158, 11, 0.2);
        color: #fbbf24;
    }

    .summary-status.status-rejected {
        background: rgba(239, 68, 68, 0.2);
        color: #f87171;
    }

    .summary-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        opacity: 0.85;
        font-size: 0.875rem;
    }

    .summary-meta-item {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
    }

    .summary-meta-item i {
        font-size: 0.75rem;
        opacity: 0.7;
    }

    /* ✅ NEW: Compact Customer Info Row */
    .customer-info-row {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 0.875rem 1rem;
        width: 100%;
        box-sizing: border-box;
        flex-wrap: wrap;
    }

    .customer-mini-avatar {
        flex-shrink: 0;
    }

    .customer-mini-avatar img {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid #e5e7eb;
    }

    .avatar-placeholder-mini {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 0.875rem;
    }

    .customer-mini-info {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 0.125rem;
    }

    .customer-mini-name {
        font-weight: 600;
        color: #1f2937;
        font-size: 0.95rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .customer-mini-meta {
        font-size: 0.8rem;
        color: #6b7280;
        display: flex;
        align-items: center;
        gap: 0.375rem;
    }

    .customer-mini-meta svg {
        width: 12px;
        height: 12px;
    }

    .order-link-badge,
    .repair-link-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        padding: 0.375rem 0.75rem;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.2s ease;
        flex-shrink: 0;
    }

    .order-link-badge {
        background: #eff6ff;
        color: #2563eb;
    }

    .order-link-badge:hover {
        background: #dbeafe;
    }

    .repair-link-badge {
        background: #fff7ed;
        color: #ea580c;
    }

    .repair-link-badge:hover {
        background: #ffedd5;
    }

    @media (max-width: 576px) {
        .summary-main {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .summary-amount {
            font-size: 1.75rem;
        }

        .customer-info-row {
            flex-wrap: wrap;
        }

        .order-link-badge,
        .repair-link-badge {
            width: 100%;
            justify-content: center;
            margin-top: 0.5rem;
        }
    }

    /* Detail Section */
    .detail-section {
        background: #ffffff;
        /* Clean white cards */
        border-radius: 16px;
        padding: 1.5rem;
        border: 1px solid #e5e7eb;
        /* Subtle border */
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        /* Very subtle shadow */
    }

    .detail-section h3 {
        font-size: 1.1rem;
        font-weight: 700;
        margin: 0 0 1rem 0;
        color: #1f2937;
        /* Professional dark gray */
        word-wrap: break-word;
    }

    .detail-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
        width: 100%;
    }

    @media (max-width: 576px) {
        .detail-grid {
            grid-template-columns: 1fr;
        }
    }

    .detail-item {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        min-width: 0;
    }

    .detail-label {
        font-size: 0.8rem;
        color: #6b7280;
        /* Medium gray for labels */
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .detail-value {
        font-size: 1rem;
        color: #111827;
        /* Almost black for values - maximum readability */
        font-weight: 600;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }

    /* Customer Profile Card - Keep LINE green ONLY for customer profile */
    .customer-profile-card {
        background: linear-gradient(135deg, #06C755 0%, #00B900 100%);
        /* LINE green gradient */
        color: white;
        border: none !important;
        box-shadow: 0 4px 12px rgba(6, 199, 85, 0.2);
        /* Green shadow for depth */
    }

    .profile-header {
        display: flex;
        align-items: center;
        gap: 1.25rem;
    }

    .profile-avatar {
        flex-shrink: 0;
    }

    .profile-avatar img {
        width: 72px;
        height: 72px;
        border-radius: 50%;
        border: 3px solid rgba(255, 255, 255, 0.3);
        object-fit: cover;
        background: white;
    }

    .profile-avatar-placeholder {
        width: 72px;
        height: 72px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.25);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        font-weight: 700;
        color: white;
        border: 3px solid rgba(255, 255, 255, 0.3);
    }

    .profile-info {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .profile-name {
        font-size: 1.5rem;
        font-weight: 700;
        margin: 0;
        color: white;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .profile-phone {
        font-size: 0.95rem;
        color: rgba(255, 255, 255, 0.95);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .profile-platform {
        display: flex;
        gap: 0.5rem;
        margin-top: 0.25rem;
    }

    .platform-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        padding: 0.375rem 0.875rem;
        background: rgba(255, 255, 255, 0.25);
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        backdrop-filter: blur(10px);
    }

    .platform-badge svg {
        width: 14px;
        height: 14px;
    }

    /* Platform icon SVG in modal - inline with text */
    .platform-icon-svg {
        width: 14px;
        height: 14px;
        display: inline-block;
        vertical-align: middle;
        flex-shrink: 0;
    }

    .platform-icon-svg.line {
        color: #06c755;
    }

    .platform-icon-svg.facebook {
        color: #1877f2;
    }

    .platform-icon-svg.instagram {
        color: #e4405f;
    }

    .platform-icon-svg.web {
        color: #6b7280;
    }

    .platform-line {
        color: white;
    }

    /* LINE-style Chat Bubbles - Keep LINE green for bot messages */
    .slip-chat-box {
        background: #ffffff;
        /* White background instead of gray */
        border: 1px solid #e5e7eb;
        /* Subtle border */
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }

    .slip-chat-bubbles {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .bubble {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        max-width: 80%;
        animation: slideIn 0.3s ease-out;
    }

    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .bubble-bot {
        align-self: flex-start;
    }

    .bubble-user {
        align-self: flex-end;
    }

    .bubble-label {
        font-size: 0.75rem;
        color: #6b7280;
        /* Consistent gray */
        font-weight: 600;
        padding: 0 0.5rem;
    }

    .bubble-text {
        padding: 1rem 1.25rem;
        border-radius: 18px;
        font-size: 0.95rem;
        line-height: 1.6;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        position: relative;
    }

    .bubble-bot .bubble-text {
        background: #06c755;
        /* LINE green for bot - THE ONLY GREEN ACCENT */
        color: white;
        border-bottom-left-radius: 4px;
    }

    .bubble-user .bubble-text {
        background: #f3f4f6;
        /* Light gray instead of white */
        color: #111827;
        /* Dark text */
        border: 1px solid #e5e7eb;
        border-bottom-right-radius: 4px;
    }

    /* Slip Image Container - Clean professional style */
    .slip-image-container {
        position: relative;
        border-radius: 12px;
        overflow: hidden;
        background: #ffffff;
        /* Clean white */
        border: 2px solid #e5e7eb;
        /* Subtle border */
        padding: 1.5rem;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        /* Subtle shadow */
        transition: all 0.3s ease;
    }

    .slip-image-container:hover {
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
        /* Slightly stronger on hover */
        border-color: #d1d5db;
        /* Slightly darker gray */
    }

    .slip-image {
        width: 100%;
        height: auto;
        max-height: 600px;
        object-fit: contain;
        border-radius: 8px;
        cursor: zoom-in;
        transition: transform 0.3s ease;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        background: white;
        display: block;
    }

    .slip-image:hover {
        transform: scale(1.02);
    }

    .slip-image.zoomed {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%) !important;
        z-index: 99999;
        max-width: 90vw;
        max-height: 90vh;
        width: auto;
        height: auto;
        cursor: zoom-out;
        box-shadow: 0 24px 48px rgba(0, 0, 0, 0.4);
        border-radius: 12px;
    }

    /* Zoom backdrop (pseudo-elements on <img> are unreliable) */
    .slip-zoom-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.85);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        z-index: 99998;
        animation: fadeIn 0.3s ease-out;
    }

    /* Remove old pseudo backdrop */
    .slip-image.zoomed::after {
        content: none !important;
    }

    /* Backdrop overlay when image is zoomed */
    .slip-caption {
        margin-top: 0.75rem;
        font-size: 0.875rem;
        color: #6b7280;
        /* Consistent gray */
        text-align: center;
        line-height: 1.5;
        font-style: italic;
    }

    /* Approve Panel - Clean minimal style */
    .slip-approve-panel {
        background: #ffffff;
        /* Clean white */
        border: 2px solid #e5e7eb;
        /* Subtle gray border */
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }

    .slip-approve-panel h3 {
        color: #1f2937;
        /* Professional dark gray */
    }

    .slip-approve-panel .hint {
        font-size: 0.9rem;
        color: #4b5563;
        /* Medium gray for text */
        margin-bottom: 1rem;
        padding: 0.75rem;
        background: #f9fafb;
        /* Very light gray background */
        border-radius: 8px;
        border-left: 3px solid #9ca3af;
        /* Gray accent */
    }

    .action-row {
        display: flex;
        gap: 1rem;
        margin-top: 1rem;
    }

    .btn {
        flex: 1;
        padding: 0.875rem 1.5rem;
        border: none;
        border-radius: 12px;
        font-weight: 600;
        font-size: 1rem;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }

    .btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .btn-success {
        background: #10b981;
        /* Solid green - no gradient */
        color: white;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.2);
        /* Subtle shadow */
    }

    .btn-success:hover:not(:disabled) {
        background: #059669;
        /* Darker green on hover */
        transform: translateY(-1px);
        /* Subtle lift */
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
    }

    .btn-danger {
        background: #ef4444;
        /* Solid red - no gradient */
        color: white;
        box-shadow: 0 2px 8px rgba(239, 68, 68, 0.2);
    }

    .btn-danger:hover:not(:disabled) {
        background: #dc2626;
        /* Darker red on hover */
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25);
    }

    /* Toast Notification - Clean minimal style */
    .toast {
        position: fixed;
        bottom: 2rem;
        right: 2rem;
        background: white;
        padding: 1rem 1.5rem;
        border-radius: 12px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
        /* Cleaner shadow */
        display: none;
        align-items: center;
        gap: 0.75rem;
        z-index: 10001;
        min-width: 300px;
        animation: slideUp 0.3s ease-out;
        border: 1px solid #e5e7eb;
        /* Add subtle border */
    }

    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .toast.show {
        display: flex;
    }

    .toast.success {
        border-left: 4px solid #10b981;
    }

    .toast.error {
        border-left: 4px solid #ef4444;
    }

    .toast.info {
        border-left: 4px solid #3b82f6;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .payment-modal-dialog {
            max-width: 100%;
            max-height: 100vh;
            border-radius: 0;
            margin: 0;
        }

        .payment-modal {
            padding: 0;
        }

        .payment-modal-header {
            padding: 1rem 1.5rem;
        }

        .payment-modal-title {
            font-size: 1.25rem;
        }

        .payment-modal-body {
            padding: 1rem;
        }

        .slip-chat-layout {
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .detail-section {
            padding: 1rem;
        }

        .detail-grid {
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }

        .action-row {
            flex-direction: column;
        }

        .btn {
            width: 100%;
        }

        .filter-tabs {
            gap: 0.5rem;
        }

        .filter-tab {
            min-width: auto;
            padding: 0.75rem 1rem;
            font-size: 0.875rem;
        }

        .tab-icon {
            font-size: 1.1rem;
        }

        .payments-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .payment-card {
            padding: 1rem;
        }

        .payment-amount {
            font-size: 1.5rem;
        }
    }

    /* Dark mode support */
    @media (prefers-color-scheme: dark) {
        .payment-modal-dialog {
            box-shadow: 0 24px 48px rgba(0, 0, 0, 0.6);
        }

        .slip-image-container {
            background: linear-gradient(135deg, #1f2937 0%, #111827 100%);
        }

        .bubble-user .bubble-text {
            background: #374151;
            color: #f3f4f6;
            border-color: #4b5563;
        }
    }

    /* Search Box */
    .search-box {
        position: relative;
        width: 100%;
    }

    .search-icon {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--color-gray);
        pointer-events: none;
    }

    .search-input {
        width: 100%;
        padding: 0.75rem 1rem 0.75rem 2.75rem;
        border: 2px solid var(--color-border);
        border-radius: 12px;
        font-size: 0.95rem;
        transition: all 0.2s ease;
    }

    .search-input:focus {
        outline: none;
        border-color: var(--color-primary);
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
    }

    .keyboard-hint {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: var(--color-gray);
        font-size: 0.85rem;
    }

    .keyboard-hint kbd {
        padding: 0.25rem 0.5rem;
        background: var(--color-background);
        border: 1px solid var(--color-border);
        border-radius: 4px;
        font-family: monospace;
        font-size: 0.8rem;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }

    /* Pagination Container */
    .pagination-container {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1.5rem;
        background: var(--color-card);
        border-radius: 12px;
        margin-top: 1rem;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .pagination-info {
        color: var(--color-gray);
        font-size: 0.9rem;
    }

    .pagination-controls {
        display: flex;
        gap: 0.5rem;
        align-items: center;
    }

    .btn-pagination {
        width: 36px;
        height: 36px;
        border: 1px solid var(--color-border);
        background: var(--color-card);
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--color-text);
    }

    .btn-pagination:hover:not(:disabled) {
        border-color: var(--color-primary);
        background: var(--color-primary);
        color: white;
        transform: translateY(-2px);
    }

    .btn-pagination:disabled {
        opacity: 0.3;
        cursor: not-allowed;
    }

    .page-indicator {
        padding: 0 1rem;
        font-weight: 600;
        color: var(--color-text);
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 4rem 2rem;
        grid-column: 1 / -1;
    }

    .empty-icon {
        font-size: 4rem;
        margin-bottom: 1rem;
        opacity: 0.5;
    }

    .empty-title {
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--color-gray);
        margin-bottom: 1rem;
    }

    /* Error State */
    .error-state {
        text-align: center;
        padding: 4rem 2rem;
        grid-column: 1 / -1;
    }

    .error-icon {
        font-size: 4rem;
        margin-bottom: 1rem;
    }

    .error-title {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--color-danger);
        margin-bottom: 0.5rem;
    }

    .error-details {
        color: var(--color-gray);
        margin-bottom: 1.5rem;
    }

    /* Payment card keyboard focus */
    .payment-card:focus {
        outline: 2px solid var(--color-primary);
        outline-offset: 2px;
    }

    /* Payment History Modal (isolated from global .payment-modal in assets/css/style.css) */
    .payment-detail-modal {
        display: none;
        /* JS toggles to flex */
        position: fixed;
        inset: 0;
        z-index: 9999;
        margin: 0;
        padding: 0;
        align-items: center;
        justify-content: center;
    }

    .payment-detail-modal[style*="display: flex"],
    .payment-detail-modal.is-open {
        display: flex;
    }

    /* Keep overlay/dialog styles working under the new root class */
    .payment-detail-modal .payment-modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.8);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        z-index: 9998;
    }

    .payment-detail-modal .payment-modal-dialog {
        position: relative;
        background: var(--color-card, #fff);
        border-radius: 0;
        box-shadow: 0 24px 48px rgba(0, 0, 0, 0.4);
        width: 100vw;
        height: 100vh;
        max-width: 100vw;
        max-height: 100vh;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        z-index: 9999;
        margin: 0;
    }

    @media (min-width: 768px) {
        .payment-detail-modal .payment-modal-dialog {
            border-radius: 20px;
            width: 90vw;
            max-width: 900px;
            height: auto;
            max-height: 90vh;
            min-width: 600px;
        }
    }

    /* ============================================
   PAYMENTS TABLE STYLES - Data Table Layout
   ============================================ */
    .table-container {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .payments-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.9rem;
    }

    .payments-table thead {
        background: #f9fafb;
        border-bottom: 2px solid #e5e7eb;
    }

    .payments-table th {
        padding: 1rem 0.75rem;
        text-align: left;
        font-weight: 600;
        color: #374151;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        white-space: nowrap;
    }

    .payments-table tbody tr {
        border-bottom: 1px solid #e5e7eb;
        transition: background-color 0.2s ease;
        cursor: pointer;
    }

    .payments-table tbody tr:hover {
        background: #f3f4f6;
    }

    .payments-table td {
        padding: 0.875rem 0.75rem;
        vertical-align: middle;
        color: #374151;
    }

    /* Payment No */
    .payment-no-link {
        font-weight: 600;
        color: #1f2937;
        font-size: 0.95rem;
    }

    /* Date cell */
    .payment-date-cell {
        color: #6b7280;
        font-size: 0.85rem;
        white-space: nowrap;
    }

    /* Customer Profile Cell */
    .customer-cell {
        display: flex;
        align-items: center;
        gap: 0.625rem;
    }

    .customer-avatar-sm {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid #e5e7eb;
        flex-shrink: 0;
    }

    .customer-avatar-placeholder-sm {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 0.75rem;
        flex-shrink: 0;
    }

    .customer-info-sm {
        display: flex;
        flex-direction: column;
        gap: 0.125rem;
    }

    .customer-name-sm {
        font-weight: 500;
        color: #1f2937;
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        gap: 0.375rem;
    }

    .customer-name-sm .platform-icon {
        width: 14px;
        height: 14px;
        flex-shrink: 0;
    }

    .customer-id-sm {
        font-size: 0.75rem;
        color: #9ca3af;
    }

    /* Amount cell */
    .amount-cell {
        font-weight: 600;
        color: #059669;
        font-size: 1rem;
        text-align: right;
        white-space: nowrap;
    }

    /* Type Badge */
    .type-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        padding: 0.25rem 0.625rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 500;
        white-space: nowrap;
    }

    .type-badge.type-full {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .type-badge.type-installment {
        background: #fef3c7;
        color: #b45309;
    }

    .type-badge.type-savings {
        background: #dcfce7;
        color: #15803d;
    }

    /* Status Badge */
    .status-badge-sm {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 600;
        white-space: nowrap;
    }

    .status-badge-sm.status-verified {
        background: #dcfce7;
        color: #15803d;
    }

    .status-badge-sm.status-pending {
        background: #fef3c7;
        color: #b45309;
    }

    .status-badge-sm.status-rejected {
        background: #fee2e2;
        color: #b91c1c;
    }

    /* View Button */
    .view-btn {
        padding: 0.375rem 0.75rem;
        background: #f3f4f6;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        font-size: 0.8rem;
        color: #374151;
        cursor: pointer;
        transition: all 0.2s ease;
        white-space: nowrap;
    }

    .view-btn:hover {
        background: #e5e7eb;
        color: #111827;
    }

    /* Highlighted Row (from deep link) */
    .payments-table tbody tr.highlighted {
        background: #eef2ff;
        box-shadow: inset 0 0 0 2px rgba(99, 102, 241, 0.3);
    }

    /* Mobile Card View */
    .payments-mobile-list {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .payment-mobile-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 1rem;
        cursor: pointer;
        transition: box-shadow 0.2s ease;
    }

    .payment-mobile-card:hover {
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .payment-mobile-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 0.75rem;
    }

    .payment-mobile-no {
        font-weight: 600;
        color: #1f2937;
    }

    .payment-mobile-amount {
        font-weight: 700;
        color: #059669;
        font-size: 1.1rem;
    }

    .payment-mobile-customer {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 0.75rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid #f3f4f6;
    }

    .payment-mobile-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        align-items: center;
    }

    /* Show/Hide based on screen size */
    @media (max-width: 768px) {
        .table-container {
            display: none !important;
        }

        .payments-mobile-list {
            display: flex !important;
        }
    }

    @media (min-width: 769px) {
        .table-container {
            display: block !important;
        }

        .payments-mobile-list {
            display: none !important;
        }
    }

    /* Empty State */
    .payments-empty-state {
        text-align: center;
        padding: 3rem 2rem;
        color: #6b7280;
    }

    .payments-empty-state .empty-icon {
        font-size: 3rem;
        margin-bottom: 1rem;
    }

    .payments-empty-state .empty-title {
        font-size: 1.1rem;
        font-weight: 600;
        color: #374151;
        margin-bottom: 0.5rem;
    }

    /* Order Reference Container */
    .order-reference-container {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }

    .current-order-link {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .order-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
        color: white;
        border-radius: 8px;
        font-weight: 600;
        font-size: 0.95rem;
    }

    .order-search-inline {
        display: flex;
        gap: 0.75rem;
        align-items: flex-start;
        position: relative;
    }

    /* Allow autocomplete to overflow the modal body */
    .order-reference-container {
        position: relative;
        overflow: visible !important;
    }

    .autocomplete-wrapper {
        position: relative;
        overflow: visible !important;
    }

    .order-search-inline .form-control {
        padding: 0.625rem 1rem;
        border: 2px solid #e5e7eb;
        border-radius: 8px;
        font-size: 0.9rem;
        transition: border-color 0.2s;
    }

    .order-search-inline .form-control:focus {
        outline: none;
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
    }

    .order-search-inline .autocomplete-suggestions {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid #e5e7eb;
        border-top: none;
        border-radius: 0 0 8px 8px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        z-index: 100000;
        max-height: 200px;
        overflow-y: auto;
    }
</style>

<!-- Customer Profile Component -->
<link rel="stylesheet" href="<?php echo asset('css/components/customer-profile.css'); ?>?v=<?php echo time(); ?>">
<script src="<?php echo asset('js/components/customer-profile.js'); ?>?v=<?php echo time(); ?>"></script>

<?php
$extra_scripts = [
    'assets/js/payment-history.js'
];

include('../includes/customer/footer.php');
?>