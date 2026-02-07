<?php
/**
 * Deposits - Customer Portal
 * ระบบรับฝากสินค้า สำหรับร้านเฮง เฮง
 */
define('INCLUDE_CHECK', true);

$page_title = "รับฝากสินค้า - เฮง เฮง";
$current_page = "deposits";

include('../includes/customer/header.php');
include('../includes/customer/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <div class="page-header-content">
            <div>
                <h1 class="page-title">� รับฝากสินค้า</h1>
                <p class="page-subtitle">รายการสินค้าที่ฝากไว้กับทางร้าน</p>
            </div>
            <button class="btn btn-primary" onclick="openCreateModal()">
                <i class="fas fa-plus"></i> <span class="btn-text">ฝากสินค้าใหม่</span>
            </button>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-grid">
        <div class="summary-card summary-card-info">
            <div class="summary-icon">📦</div>
            <div class="summary-value" id="pendingCount">0</div>
            <div class="summary-label">รายการฝาก</div>
        </div>
        <div class="summary-card summary-card-success">
            <div class="summary-icon">✅</div>
            <div class="summary-value" id="paidCount">0</div>
            <div class="summary-label">รับคืนแล้ว</div>
        </div>
        <div class="summary-card summary-card-warning">
            <div class="summary-icon">💰</div>
            <div class="summary-value" id="pendingAmount">฿0</div>
            <div class="summary-label">วงเงินฝากทั้งหมด</div>
        </div>
        <div class="summary-card">
            <div class="summary-icon">💎</div>
            <div class="summary-value" id="paidAmount">฿0</div>
            <div class="summary-label">ดอกเบี้ยสะสม</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">รายการรับฝาก</h3>
            <div class="filter-group">
                <select id="statusFilter" class="form-select" onchange="loadDeposits()">
                    <option value="">ทั้งหมด</option>
                    <option value="pending">รอชำระ</option>
                    <option value="paid">ชำระแล้ว</option>
                    <option value="converted">แปลงเป็นออเดอร์</option>
                    <option value="expired">หมดอายุ</option>
                </select>
            </div>
        </div>
        <div class="card-body">
            <!-- Desktop Table -->
            <div class="table-container desktop-only">
                <table>
                    <thead>
                        <tr>
                            <th>รหัสฝาก</th>
                            <th>สินค้า</th>
                            <th style="text-align:right;">วงเงินฝาก</th>
                            <th style="text-align:right;">ดอกเบี้ย</th>
                            <th>สถานะ</th>
                            <th>ครบกำหนด</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="depositsTableBody">
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
            <div class="mobile-cards mobile-only" id="depositsMobileCards">
                <div class="loading-placeholder">
                    <div class="spinner"></div>
                    <p>กำลังโหลดข้อมูล...</p>
                </div>
            </div>

            <!-- Pagination -->
            <div id="depositsPagination" class="pagination-container"></div>
        </div>
    </div>
</main>

<!-- Payment Modal -->
<div class="modal-overlay" id="paymentModal">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="modal-title">💳 ชำระมัดจำ</h3>
            <button class="modal-close" onclick="closePaymentModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="depositInfo"></div>

            <div class="bank-accounts" id="bankAccountsList"></div>

            <div class="form-group">
                <label class="form-label">แนบสลิปโอนเงิน</label>
                <div class="upload-area" id="slipUploadArea">
                    <input type="file" id="slipInput" accept="image/*" style="display:none;"
                        onchange="handleSlipUpload(this)">
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

<!-- Detail Modal -->
<div class="modal-overlay" id="detailModal">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="modal-title">📋 รายละเอียดการฝาก</h3>
            <button class="modal-close" onclick="closeDetailModal()">&times;</button>
        </div>
        <div class="modal-body" id="depositDetailContent"></div>
        <div class="modal-footer" id="depositDetailFooter">
            <button class="btn btn-secondary" onclick="closeDetailModal()">ปิด</button>
        </div>
    </div>
</div>

<!-- Create Deposit Modal - รับฝากสินค้า (Pawn Service) -->
<div class="modal-overlay" id="createModal">
    <div class="modal-container modal-lg">
        <div class="modal-header">
            <h3 class="modal-title">📦 รับฝากสินค้าใหม่</h3>
            <button class="modal-close" onclick="closeCreateModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="createDepositForm">
                <!-- Customer Section -->
                <div class="detail-section">
                    <h4 class="detail-section-title">👤 ข้อมูลลูกค้า</h4>
                    <div class="form-group">
                        <label for="customerSearch">ค้นหาลูกค้าจากระบบ</label>
                        <div class="autocomplete-wrapper">
                            <input type="text" id="customerSearch" class="form-input"
                                placeholder="พิมพ์ชื่อ, เบอร์โทร, LINE ID..." autocomplete="off"
                                oninput="searchCustomers(this.value)">
                            <div id="customerSearchResults" class="autocomplete-dropdown" style="display:none;"></div>
                        </div>
                        <small class="form-hint">⚠️ รับฝากเฉพาะลูกค้าที่ซื้อสินค้าจากร้านเท่านั้น</small>
                    </div>

                    <!-- Selected Customer Display -->
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
                    <h4 class="detail-section-title">💎 ข้อมูลสินค้าที่ฝาก</h4>
                    <div class="detail-grid">
                        <div class="form-group">
                            <label for="itemType">ประเภทสินค้า <span class="required">*</span></label>
                            <select id="itemType" name="item_type" class="form-input" required>
                                <option value="">เลือกประเภท</option>
                                <option value="watch">นาฬิกา</option>
                                <option value="jewelry">เครื่องประดับเพชร</option>
                                <option value="gold">ทอง/ทองคำ</option>
                                <option value="ring">แหวน</option>
                                <option value="necklace">สร้อยคอ</option>
                                <option value="bracelet">สร้อยข้อมือ/กำไล</option>
                                <option value="pendant">จี้/ตุ้มหู</option>
                                <option value="buddha">พระเครื่อง</option>
                                <option value="other">อื่นๆ</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="itemName">ชื่อสินค้า/รุ่น <span class="required">*</span></label>
                            <input type="text" id="itemName" name="item_name" class="form-input" required
                                placeholder="เช่น Rolex Submariner, แหวนเพชร 1 กะรัต">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="itemDescription">รายละเอียด / สภาพสินค้า</label>
                        <textarea id="itemDescription" name="item_description" class="form-input" rows="2"
                            placeholder="รายละเอียดเพิ่มเติม เช่น สภาพ 95% มีกล่อง ใบรับประกัน"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="warrantyNo">เลขใบรับประกัน</label>
                        <input type="text" id="warrantyNo" name="warranty_no" class="form-input"
                            placeholder="เลขใบรับประกันจากร้าน (ถ้ามี)">
                        <small class="form-hint">⚠️ ต้องมีใบรับประกันตัวจริงมาแสดงทุกครั้ง</small>
                    </div>
                </div>

                <!-- Deposit Details -->
                <div class="detail-section">
                    <h4 class="detail-section-title">💰 รายละเอียดการฝาก</h4>
                    <div class="detail-grid">
                        <div class="form-group">
                            <label for="appraisedValue">ราคาประเมิน (บาท) <span class="required">*</span></label>
                            <input type="number" id="appraisedValue" name="appraised_value" class="form-input" required
                                placeholder="100,000" min="0" step="100" oninput="calculateLoanAmount()">
                        </div>
                        <div class="form-group">
                            <label for="loanPercentage">เปอร์เซ็นต์วงเงิน (%)</label>
                            <select id="loanPercentage" name="loan_percentage" class="form-input"
                                onchange="calculateLoanAmount()">
                                <option value="65">65%</option>
                                <option value="67">67%</option>
                                <option value="70" selected>70%</option>
                            </select>
                        </div>
                    </div>
                    <div class="detail-grid">
                        <div class="form-group">
                            <label for="depositAmount">วงเงินที่ให้ (บาท) <span class="required">*</span></label>
                            <input type="number" id="depositAmount" name="deposit_amount" class="form-input" required
                                placeholder="70,000" min="0" step="100">
                            <small class="form-hint">คำนวณอัตโนมัติ: ราคาประเมิน × เปอร์เซ็นต์</small>
                        </div>
                        <div class="form-group">
                            <label for="interestRate">ดอกเบี้ย (%/เดือน)</label>
                            <input type="number" id="interestRate" name="interest_rate" class="form-input" value="2"
                                min="0" max="10" step="0.5" readonly>
                            <small class="form-hint">อัตราคงที่ 2% ต่อเดือน</small>
                        </div>
                    </div>
                    <div class="detail-grid">
                        <div class="form-group">
                            <label for="depositDate">วันที่รับฝาก <span class="required">*</span></label>
                            <input type="date" id="depositDate" name="deposit_date" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label for="dueDate">วันครบกำหนด (30 วัน)</label>
                            <input type="date" id="dueDate" name="due_date" class="form-input" readonly>
                            <small class="form-hint">คำนวณอัตโนมัติ: +30 วัน</small>
                        </div>
                    </div>

                    <!-- Loan Summary Card -->
                    <div class="loan-summary-card" id="loanSummaryCard" style="display:none;">
                        <h5>📊 สรุปรายการฝาก</h5>
                        <div class="summary-row">
                            <span>วงเงินที่ให้:</span>
                            <span id="summaryLoan">฿0</span>
                        </div>
                        <div class="summary-row">
                            <span>ดอกเบี้ย 2%/เดือน:</span>
                            <span id="summaryInterest">฿0</span>
                        </div>
                        <div class="summary-row highlight">
                            <span>ยอดต่อดอกเดือนถัดไป:</span>
                            <span id="summaryNextPayment">฿0</span>
                        </div>
                    </div>
                </div>

                <!-- Storage & Notes -->
                <div class="detail-section">
                    <h4 class="detail-section-title">📝 ตำแหน่งจัดเก็บ</h4>
                    <div class="detail-grid">
                        <div class="form-group">
                            <label for="storageLocation">ตำแหน่งในตู้เซฟ</label>
                            <input type="text" id="storageLocation" name="storage_location" class="form-input"
                                placeholder="เช่น ตู้ A-001">
                        </div>
                        <div class="form-group">
                            <label for="depositNotes">หมายเหตุ</label>
                            <textarea id="depositNotes" name="notes" class="form-input" rows="2"
                                placeholder="หมายเหตุสำหรับแอดมิน"></textarea>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeCreateModal()">ยกเลิก</button>
            <button class="btn btn-primary" onclick="submitCreateDeposit()">
                <i class="fas fa-save"></i> บันทึกรายการฝาก
            </button>
        </div>
    </div>
</div>

<!-- Toast Notification -->
<div id="toast" class="toast"></div>

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
        from {
            opacity: 0;
            transform: scale(0.95) translateY(-10px);
        }

        to {
            opacity: 1;
            transform: scale(1) translateY(0);
        }
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

    .modal-close:hover {
        color: #374151;
    }

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
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
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

    .summary-card-success .summary-value {
        color: var(--color-success);
    }

    .summary-card-warning .summary-value {
        color: var(--color-warning);
    }

    .summary-card-info .summary-value {
        color: var(--color-info);
    }

    .filter-group {
        display: flex;
        gap: 0.5rem;
        align-items: center;
    }

    .form-select {
        padding: 0.5rem 1rem;
        border: 1px solid var(--color-border);
        border-radius: 8px;
        background: white;
        font-size: 0.9rem;
    }

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

    .bank-accounts {
        margin: 1rem 0;
    }

    .bank-account-card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 1rem;
        margin-bottom: 0.75rem;
    }

    .bank-account-header {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 0.5rem;
    }

    .bank-account-name {
        font-weight: 600;
    }

    .bank-account-number {
        font-size: 1.1rem;
        font-family: monospace;
    }

    .bank-account-holder {
        color: var(--color-gray);
        font-size: 0.9rem;
    }

    .upload-area {
        margin-top: 0.5rem;
    }

    .upload-placeholder {
        border: 2px dashed #e2e8f0;
        border-radius: 12px;
        padding: 2rem;
        text-align: center;
        cursor: pointer;
        transition: all 0.2s;
    }

    .upload-placeholder:hover {
        border-color: var(--color-primary);
        background: #f8fafc;
    }

    .upload-placeholder i {
        font-size: 2rem;
        color: var(--color-gray);
        margin-bottom: 0.5rem;
    }

    .upload-placeholder p {
        color: var(--color-gray);
        margin: 0;
    }

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

    .btn-pagination:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .page-indicator {
        padding: 0.5rem 1rem;
        color: #6b7280;
        font-size: 0.9rem;
    }

    .status-badge {
        display: inline-block;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 500;
    }

    .status-pending {
        background: #fef3c7;
        color: #d97706;
    }

    .status-paid {
        background: #d1fae5;
        color: #059669;
    }

    .status-converted {
        background: #dbeafe;
        color: #2563eb;
    }

    .status-expired,
    .status-cancelled {
        background: #fee2e2;
        color: #dc2626;
    }

    .deposit-info-card {
        background: #f8fafc;
        border-radius: 12px;
        padding: 1rem;
        margin-bottom: 1rem;
    }

    .deposit-info-row {
        display: flex;
        justify-content: space-between;
        padding: 0.5rem 0;
        border-bottom: 1px solid #e2e8f0;
    }

    .deposit-info-row:last-child {
        border-bottom: none;
    }

    .deposit-info-label {
        color: var(--color-gray);
    }

    .deposit-info-value {
        font-weight: 600;
    }

    .amount-highlight {
        font-size: 1.25rem;
        color: var(--color-primary);
    }

    /* Modal Large */
    .modal-container.modal-lg {
        max-width: 700px;
    }

    /* Detail Section */
    .detail-section {
        margin-bottom: 1.5rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid #e5e7eb;
    }

    .detail-section:last-child {
        border-bottom: none;
    }

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
    .autocomplete-wrapper {
        position: relative;
    }

    .autocomplete-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
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

    .autocomplete-item:last-child {
        border-bottom: none;
    }

    .autocomplete-item:hover {
        background: #f9fafb;
    }

    .autocomplete-item.no-result {
        color: #9ca3af;
        cursor: default;
    }

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

    .autocomplete-item-info {
        flex: 1;
        min-width: 0;
    }

    .autocomplete-item-name {
        font-weight: 500;
    }

    .autocomplete-item-phone {
        font-size: 0.8rem;
        color: #9ca3af;
    }

    .autocomplete-item-platform {
        font-size: 0.75rem;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        font-weight: 500;
    }

    .autocomplete-item-platform.line {
        background: #e8f5e9;
        color: #06c755;
    }

    .autocomplete-item-platform.facebook {
        background: #e3f2fd;
        color: #1877f2;
    }

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

    .selected-customer-info {
        flex: 1;
    }

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

    .btn-remove-customer:hover {
        color: #dc2626;
    }

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

    .form-group {
        margin-bottom: 1rem;
    }

    .form-group label {
        display: block;
        font-size: 0.9rem;
        font-weight: 500;
        margin-bottom: 0.5rem;
    }

    .required {
        color: #dc2626;
    }

    .form-hint {
        display: block;
        margin-top: 0.25rem;
        color: #9ca3af;
        font-size: 0.8rem;
    }

    /* Loan Summary Card */
    .loan-summary-card {
        background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
        border: 1px solid #bae6fd;
        border-radius: 12px;
        padding: 1rem;
        margin-top: 1rem;
    }

    .loan-summary-card h5 {
        margin: 0 0 0.75rem 0;
        font-size: 0.95rem;
        color: #0369a1;
    }

    .summary-row {
        display: flex;
        justify-content: space-between;
        padding: 0.5rem 0;
        border-bottom: 1px dashed #bae6fd;
    }

    .summary-row:last-child {
        border-bottom: none;
    }

    .summary-row.highlight {
        font-weight: 600;
        color: #0284c7;
        font-size: 1.1rem;
        padding-top: 0.75rem;
    }

    /* Status badges for deposit */
    .status-deposited {
        background: #dbeafe;
        color: #2563eb;
    }

    .status-ready {
        background: #d1fae5;
        color: #059669;
    }

    .status-picked_up {
        background: #f3f4f6;
        color: #6b7280;
    }

    /* Toast Notifications */
    .toast {
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 1rem 1.5rem;
        border-radius: 10px;
        color: white;
        font-weight: 500;
        font-size: 0.95rem;
        z-index: 10000;
        opacity: 0;
        transform: translateX(100%);
        transition: all 0.3s ease;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        max-width: calc(100vw - 40px);
    }

    .toast.show {
        opacity: 1;
        transform: translateX(0);
    }

    .toast.success {
        background: linear-gradient(135deg, #059669, #10b981);
    }

    .toast.error {
        background: linear-gradient(135deg, #dc2626, #ef4444);
    }

    .toast.info {
        background: linear-gradient(135deg, #0284c7, #0ea5e9);
    }

    .toast.warning {
        background: linear-gradient(135deg, #d97706, #f59e0b);
    }

    @media (max-width: 768px) {
        .summary-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .detail-grid {
            grid-template-columns: 1fr;
        }

        .desktop-only {
            display: none !important;
        }

        .mobile-only {
            display: block !important;
        }

        .mobile-cards {
            display: block;
        }

        .filter-group {
            width: 100%;
        }

        .form-select {
            width: 100%;
        }

        .toast {
            top: 10px;
            right: 10px;
            left: 10px;
            max-width: none;
        }
    }
</style>

<script>
    let currentPage = 1;
    let selectedDepositId = null;
    let slipUrl = null;

    document.addEventListener('DOMContentLoaded', function () {
        loadDeposits();
    });

    async function loadDeposits(page = 1) {
        currentPage = page;
        const status = document.getElementById('statusFilter').value;

        try {
            let url = `/api/customer/deposits?page=${page}&limit=20`;
            if (status) url += `&status=${status}`;

            const response = await fetch(url, {
                headers: { 'Authorization': 'Bearer ' + getToken() }
            });
            
            // Check for 401 Unauthorized (session expired)
            if (!checkAuthResponse(response)) return;
            
            const data = await response.json();

            if (data.success) {
                renderDeposits(data.data);
                renderPagination(data.pagination);
                updateSummary(data.summary);
            } else {
                showError(data.message || 'ไม่สามารถโหลดข้อมูลได้');
            }
        } catch (error) {
            console.error('Error loading deposits:', error);
            showError('เกิดข้อผิดพลาดในการโหลดข้อมูล');
        }
    }

    function renderDeposits(deposits) {
        const tbody = document.getElementById('depositsTableBody');
        const mobileCards = document.getElementById('depositsMobileCards');

        if (!deposits || deposits.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:2rem;color:#6b7280;">ไม่มีรายการฝาก</td></tr>';
            mobileCards.innerHTML = '<div class="empty-state"><p>ไม่มีรายการฝาก</p></div>';
            return;
        }

        // Desktop table - แก้ไข column ให้ตรงกับ schema จริง
        tbody.innerHTML = deposits.map(d => {
            const itemName = d.product_name || d.item_name || '-';
            const interest = Math.round((d.deposit_amount || 0) * 0.02);
            return `
            <tr>
                <td><strong>${d.deposit_no || '-'}</strong></td>
                <td>${itemName}</td>
                <td style="text-align:right;">฿${formatNumber(d.deposit_amount)}</td>
                <td style="text-align:right;"><strong>฿${formatNumber(interest)}</strong>/เดือน</td>
                <td><span class="status-badge status-${d.status}">${d.status_display}</span></td>
                <td>${d.expires_at ? formatDate(d.expires_at) : '-'}</td>
                <td>
                    <div class="action-buttons">
                        <button class="btn btn-sm btn-ghost" onclick="viewDetail(${d.id})" title="ดูรายละเอียด">
                            <i class="fas fa-eye"></i>
                        </button>
                        ${d.status === 'deposited' ? `
                            <button class="btn btn-sm btn-primary" onclick="openPaymentModal(${d.id})" title="ต่อดอก">
                                <i class="fas fa-credit-card"></i>
                            </button>
                        ` : ''}
                    </div>
                </td>
            </tr>
            `;
        }).join('');

        // Mobile cards
        mobileCards.innerHTML = deposits.map(d => {
            const itemName = d.product_name || d.item_name || '-';
            const interest = Math.round((d.deposit_amount || 0) * 0.02);
            return `
            <div class="mobile-card">
                <div class="mobile-card-header">
                    <span class="mobile-card-title">${d.deposit_no || '-'}</span>
                    <span class="status-badge status-${d.status}">${d.status_display}</span>
                </div>
                <div class="mobile-card-row">
                    <span class="mobile-card-label">สินค้า</span>
                    <span class="mobile-card-value">${itemName}</span>
                </div>
                <div class="mobile-card-row">
                    <span class="mobile-card-label">วงเงินฝาก</span>
                    <span class="mobile-card-value" style="color:var(--color-primary);font-weight:700;">฿${formatNumber(d.deposit_amount)}</span>
                </div>
                <div class="mobile-card-row">
                    <span class="mobile-card-label">ดอกเบี้ย/เดือน</span>
                    <span class="mobile-card-value">฿${formatNumber(interest)}</span>
                </div>
                <div class="mobile-card-row">
                    <span class="mobile-card-label">ครบกำหนด</span>
                    <span class="mobile-card-value">${d.expires_at ? formatDate(d.expires_at) : '-'}</span>
                </div>
                <div class="mobile-card-actions">
                    <button class="btn btn-sm btn-secondary" onclick="viewDetail(${d.id})">
                        <i class="fas fa-eye"></i> ดู
                    </button>
                    ${d.status === 'deposited' ? `
                        <button class="btn btn-sm btn-primary" onclick="openPaymentModal(${d.id})">
                            <i class="fas fa-credit-card"></i> ต่อดอก
                        </button>
                    ` : ''}
                </div>
            </div>
            `;
        }).join('');
    }

    function updateSummary(summary) {
        if (!summary) return;
        // Map new field names to UI
        document.getElementById('pendingCount').textContent = summary.deposited_count || summary.pending_count || 0;
        document.getElementById('paidCount').textContent = summary.picked_up_count || summary.paid_count || 0;
        document.getElementById('pendingAmount').textContent = '฿' + formatNumber(summary.deposited_amount || summary.pending_amount || 0);
        document.getElementById('paidAmount').textContent = '฿' + formatNumber(summary.total_storage_fee || summary.paid_amount || 0);
    }

    function renderPagination(pagination) {
        if (!pagination) return;
        const container = document.getElementById('depositsPagination');
        const { page, total_pages, total } = pagination;

        if (total_pages <= 1) {
            container.innerHTML = '';
            return;
        }

        container.innerHTML = `
        <button class="btn-pagination" onclick="loadDeposits(${page - 1})" ${page <= 1 ? 'disabled' : ''}>
            <i class="fas fa-chevron-left"></i>
        </button>
        <span class="page-indicator">หน้า ${page} / ${total_pages}</span>
        <button class="btn-pagination" onclick="loadDeposits(${page + 1})" ${page >= total_pages ? 'disabled' : ''}>
            <i class="fas fa-chevron-right"></i>
        </button>
    `;
    }

    async function viewDetail(id) {
        try {
            const response = await fetch(`/api/customer/deposits?id=${id}`, {
                headers: { 'Authorization': 'Bearer ' + getToken() }
            });
            const data = await response.json();

            if (data.success) {
                const d = data.data;
                const itemName = d.product_name || d.item_name || '-';
                const interest = Math.round((d.deposit_amount || 0) * 0.02);
                const isDeposited = d.status === 'deposited';
                const totalFee = d.total_storage_fee || 0;

                document.getElementById('depositDetailContent').innerHTML = `
                <div class="deposit-info-card">
                    <div class="deposit-info-row">
                        <span class="deposit-info-label">รหัสฝาก</span>
                        <span class="deposit-info-value">${d.deposit_no || '-'}</span>
                    </div>
                    <div class="deposit-info-row">
                        <span class="deposit-info-label">สินค้า</span>
                        <span class="deposit-info-value">${itemName}</span>
                    </div>
                    <div class="deposit-info-row">
                        <span class="deposit-info-label">ประเภท</span>
                        <span class="deposit-info-value">${d.item_type || '-'}</span>
                    </div>
                    <div class="deposit-info-row">
                        <span class="deposit-info-label">รายละเอียด</span>
                        <span class="deposit-info-value">${d.item_description || '-'}</span>
                    </div>
                    <div class="deposit-info-row">
                        <span class="deposit-info-label">วงเงินที่ให้</span>
                        <span class="deposit-info-value amount-highlight">฿${formatNumber(d.deposit_amount)}</span>
                    </div>
                    <div class="deposit-info-row">
                        <span class="deposit-info-label">ดอกเบี้ย 2%/เดือน</span>
                        <span class="deposit-info-value">฿${formatNumber(interest)}</span>
                    </div>
                    <div class="deposit-info-row">
                        <span class="deposit-info-label">ดอกเบี้ยสะสม</span>
                        <span class="deposit-info-value">฿${formatNumber(totalFee)}</span>
                    </div>
                    <div class="deposit-info-row">
                        <span class="deposit-info-label">สถานะ</span>
                        <span class="status-badge status-${d.status}">${d.status_display}</span>
                    </div>
                    <div class="deposit-info-row">
                        <span class="deposit-info-label">วันที่ฝาก</span>
                        <span class="deposit-info-value">${d.deposit_date ? formatDate(d.deposit_date) : formatDateTime(d.created_at)}</span>
                    </div>
                    <div class="deposit-info-row">
                        <span class="deposit-info-label">ครบกำหนดต่อดอก</span>
                        <span class="deposit-info-value ${d.is_expired ? 'text-danger' : ''}">${d.expected_pickup_date ? formatDate(d.expected_pickup_date) : (d.expires_at ? formatDate(d.expires_at) : '-')} ${d.is_expired ? '(เกินกำหนด!)' : ''}</span>
                    </div>
                    ${d.storage_location ? `
                    <div class="deposit-info-row">
                        <span class="deposit-info-label">ตำแหน่งจัดเก็บ</span>
                        <span class="deposit-info-value">${d.storage_location}</span>
                    </div>
                    ` : ''}
                    ${d.item_condition ? `
                    <div class="deposit-info-row">
                        <span class="deposit-info-label">ข้อมูลเพิ่มเติม</span>
                        <span class="deposit-info-value">${d.item_condition}</span>
                    </div>
                    ` : ''}
                </div>
            `;

                // Update footer with action buttons
                const footerHtml = isDeposited
                    ? `<button class="btn btn-primary" onclick="openExtendModal(${d.id}, '${d.deposit_no}', ${d.deposit_amount})">
                        💵 ต่อดอก ฿${formatNumber(interest)}
                       </button>
                       <button class="btn btn-success" onclick="markPickedUp(${d.id})">
                        ✓ รับคืนแล้ว
                       </button>
                       <button class="btn btn-secondary" onclick="closeDetailModal()">ปิด</button>`
                    : `<button class="btn btn-secondary" onclick="closeDetailModal()">ปิด</button>`;

                document.getElementById('depositDetailFooter').innerHTML = footerHtml;
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

    // ========== Extend Interest (ต่อดอกฝาก) ==========
    let extendDepositId = null;
    let extendDepositNo = null;
    let extendInterestAmount = 0;

    function openExtendModal(depositId, depositNo, depositAmount) {
        extendDepositId = depositId;
        extendDepositNo = depositNo;
        extendInterestAmount = Math.round(depositAmount * 0.02);

        closeDetailModal();

        const today = new Date().toISOString().split('T')[0];

        // Show confirmation modal
        const modalHtml = `
            <div class="modal-overlay active" id="extendModal">
                <div class="modal-container">
                    <div class="modal-header">
                        <h3 class="modal-title">💵 ต่อดอกฝาก</h3>
                        <button class="modal-close" onclick="closeExtendModal()">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div class="deposit-info-card">
                            <div class="deposit-info-row">
                                <span class="deposit-info-label">รหัสฝาก</span>
                                <span class="deposit-info-value">${depositNo}</span>
                            </div>
                            <div class="deposit-info-row">
                                <span class="deposit-info-label">วงเงินฝาก</span>
                                <span class="deposit-info-value">฿${formatNumber(depositAmount)}</span>
                            </div>
                            <div class="deposit-info-row">
                                <span class="deposit-info-label">ดอกเบี้ย 2%</span>
                                <span class="deposit-info-value amount-highlight">฿${formatNumber(extendInterestAmount)}</span>
                            </div>
                        </div>
                        <div class="loan-summary-card">
                            <div style="text-align: center;">
                                <p style="margin-bottom: 0.5rem;">ต่อดอกแล้วจะขยายระยะเวลาไปอีก <strong>30 วัน</strong></p>
                                <p style="font-size: 0.85rem; color: #6b7280;">กรุณาแนบสลิปการชำระดอกเบี้ย</p>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">แนบสลิปโอนเงิน</label>
                            <div class="file-upload-box" onclick="document.getElementById('extendSlipInput').click()">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <p>คลิกเพื่ออัปโหลดสลิป</p>
                                <input type="file" id="extendSlipInput" accept="image/*" style="display:none" onchange="previewExtendSlip(event)">
                            </div>
                            <img id="extendSlipPreview" class="slip-preview" style="display:none; max-width: 200px; margin-top: 1rem;">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-primary" id="confirmExtendBtn" onclick="confirmExtend()" disabled>
                            ยืนยันต่อดอก ฿${formatNumber(extendInterestAmount)}
                        </button>
                        <button class="btn btn-secondary" onclick="closeExtendModal()">ยกเลิก</button>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHtml);
    }

    function closeExtendModal() {
        const modal = document.getElementById('extendModal');
        if (modal) modal.remove();
        extendDepositId = null;
        extendDepositNo = null;
        extendInterestAmount = 0;
    }

    function previewExtendSlip(event) {
        const file = event.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function (e) {
                const preview = document.getElementById('extendSlipPreview');
                preview.src = e.target.result;
                preview.style.display = 'block';
                document.getElementById('confirmExtendBtn').disabled = false;
            };
            reader.readAsDataURL(file);
        }
    }

    async function confirmExtend() {
        if (!extendDepositId) return;

        const btn = document.getElementById('confirmExtendBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-sm"></span> กำลังบันทึก...';

        try {
            // First, create payment record
            const paymentData = {
                payment_type: 'deposit_interest',
                amount: extendInterestAmount,
                notes: `ต่อดอกฝาก ${extendDepositNo}`
            };

            // Then extend the deposit
            const response = await fetch('/api/customer/deposits?action=extend', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + getToken()
                },
                body: JSON.stringify({
                    deposit_id: extendDepositId
                })
            });

            const data = await response.json();

            if (data.success) {
                showSuccess(`ต่อดอกเรียบร้อย! กำหนดใหม่: ${formatDate(data.data.new_due_date)}`);
                closeExtendModal();
                loadDeposits();
            } else {
                showError(data.message || 'เกิดข้อผิดพลาด');
                btn.disabled = false;
                btn.innerHTML = `ยืนยันต่อดอก ฿${formatNumber(extendInterestAmount)}`;
            }
        } catch (error) {
            console.error('Extend error:', error);
            showError('เกิดข้อผิดพลาดในการต่อดอก');
            btn.disabled = false;
            btn.innerHTML = `ยืนยันต่อดอก ฿${formatNumber(extendInterestAmount)}`;
        }
    }

    // ========== Mark Picked Up (รับคืนแล้ว) ==========
    async function markPickedUp(depositId) {
        if (!confirm('ยืนยันว่าลูกค้ารับสินค้าคืนแล้ว?')) return;

        try {
            const response = await fetch('/api/customer/deposits?action=pickup', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + getToken()
                },
                body: JSON.stringify({
                    deposit_id: depositId
                })
            });

            const data = await response.json();

            if (data.success) {
                showSuccess('บันทึกการรับคืนสินค้าเรียบร้อย');
                closeDetailModal();
                loadDeposits();
            } else {
                showError(data.message || 'เกิดข้อผิดพลาด');
            }
        } catch (error) {
            console.error('Pickup error:', error);
            showError('เกิดข้อผิดพลาดในการบันทึก');
        }
    }

    async function openPaymentModal(id) {
        selectedDepositId = id;
        slipUrl = null;
        document.getElementById('slipPreview').style.display = 'none';
        document.getElementById('submitPaymentBtn').disabled = true;

        try {
            const response = await fetch(`/api/customer/deposits?id=${id}`, {
                headers: { 'Authorization': 'Bearer ' + getToken() }
            });
            const data = await response.json();

            if (data.success) {
                const d = data.data;
                document.getElementById('depositInfo').innerHTML = `
                <div class="deposit-info-card">
                    <div class="deposit-info-row">
                        <span class="deposit-info-label">รหัสมัดจำ</span>
                        <span class="deposit-info-value">${d.deposit_no}</span>
                    </div>
                    <div class="deposit-info-row">
                        <span class="deposit-info-label">สินค้า</span>
                        <span class="deposit-info-value">${d.product_name || '-'}</span>
                    </div>
                    <div class="deposit-info-row">
                        <span class="deposit-info-label">ยอดมัดจำ</span>
                        <span class="deposit-info-value amount-highlight">฿${formatNumber(d.deposit_amount)}</span>
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
        selectedDepositId = null;
        slipUrl = null;
    }

    function handleSlipUpload(input) {
        if (input.files && input.files[0]) {
            const file = input.files[0];
            const reader = new FileReader();

            reader.onload = function (e) {
                document.getElementById('slipPreview').src = e.target.result;
                document.getElementById('slipPreview').style.display = 'block';
                document.querySelector('.upload-placeholder').style.display = 'none';

                // TODO: Upload to server and get URL
                slipUrl = e.target.result; // Temporary: use base64
                document.getElementById('submitPaymentBtn').disabled = false;
            };

            reader.readAsDataURL(file);
        }
    }

    async function submitPayment() {
        if (!selectedDepositId || !slipUrl) return;

        const btn = document.getElementById('submitPaymentBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังส่ง...';

        try {
            const response = await fetch('/api/customer/deposits?action=pay', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + getToken()
                },
                body: JSON.stringify({
                    deposit_id: selectedDepositId,
                    slip_image_url: slipUrl
                })
            });

            const data = await response.json();

            if (data.success) {
                showSuccess(data.message || 'ส่งสลิปเรียบร้อยแล้ว');
                closePaymentModal();
                loadDeposits(currentPage);
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
        const token = localStorage.getItem('auth_token');
        if (!token) {
            // No token, redirect to login
            console.warn('[Auth] No token found, redirecting to login...');
            window.location.href = '/login.html';
            return '';
        }
        return token;
    }

    // Check API response for 401 Unauthorized and redirect to login
    function checkAuthResponse(response) {
        if (response.status === 401) {
            console.warn('[Auth] Session expired (401), redirecting to login...');
            localStorage.removeItem('auth_token');
            localStorage.removeItem('user_data');
            alert('Session หมดอายุ กรุณาเข้าสู่ระบบใหม่');
            window.location.href = '/login.html';
            return false;
        }
        return true;
    }

    function showToast(message, type = 'info') {
        const toast = document.getElementById('toast');
        if (toast) {
            toast.textContent = message;
            toast.className = 'toast ' + type + ' show';
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }
    }

    function showError(msg) {
        showToast(msg, 'error');
    }

    function showSuccess(msg) {
        showToast(msg, 'success');
    }

    // ========================================
    // Create Modal Functions
    // ========================================

    function openCreateModal() {
        document.getElementById('createDepositForm').reset();
        clearSelectedCustomer();
        setDepositDate();
        document.getElementById('loanSummaryCard').style.display = 'none';
        document.getElementById('createModal').classList.add('active');
    }

    function closeCreateModal() {
        document.getElementById('createModal').classList.remove('active');
    }

    // Set deposit date and calculate due date
    function setDepositDate() {
        const today = new Date();
        const depositDateInput = document.getElementById('depositDate');
        if (depositDateInput) {
            depositDateInput.value = today.toISOString().split('T')[0];
            updateDueDate();
        }
    }

    function updateDueDate() {
        const depositDateInput = document.getElementById('depositDate');
        const dueDateInput = document.getElementById('dueDate');
        if (depositDateInput && dueDateInput && depositDateInput.value) {
            const depositDate = new Date(depositDateInput.value);
            const dueDate = new Date(depositDate);
            dueDate.setDate(dueDate.getDate() + 30);
            dueDateInput.value = dueDate.toISOString().split('T')[0];
        }
    }

    // Listen for deposit date change
    document.addEventListener('DOMContentLoaded', function () {
        const depositDateInput = document.getElementById('depositDate');
        if (depositDateInput) {
            depositDateInput.addEventListener('change', updateDueDate);
        }
    });

    // Calculate loan amount based on appraised value and percentage
    function calculateLoanAmount() {
        const appraised = parseFloat(document.getElementById('appraisedValue')?.value) || 0;
        const percentage = parseFloat(document.getElementById('loanPercentage')?.value) || 70;
        const loanAmount = Math.floor(appraised * percentage / 100);

        const depositAmountInput = document.getElementById('depositAmount');
        if (depositAmountInput) {
            depositAmountInput.value = loanAmount;
        }

        // Calculate interest
        const interestRate = 2; // 2% per month
        const monthlyInterest = Math.ceil(loanAmount * interestRate / 100);

        // Update summary
        const summaryCard = document.getElementById('loanSummaryCard');
        if (loanAmount > 0 && summaryCard) {
            summaryCard.style.display = 'block';
            document.getElementById('summaryLoan').textContent = '฿' + formatNumber(loanAmount);
            document.getElementById('summaryInterest').textContent = '฿' + formatNumber(monthlyInterest);
            document.getElementById('summaryNextPayment').textContent = '฿' + formatNumber(monthlyInterest);
        } else if (summaryCard) {
            summaryCard.style.display = 'none';
        }
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
                const response = await fetch(`/api/customer/search-customers.php?q=${encodeURIComponent(query)}&limit=10`, {
                    headers: { 'Authorization': 'Bearer ' + getToken() }
                });
                const data = await response.json();

                if (data.success && data.data && data.data.length > 0) {
                    resultsDiv.innerHTML = data.data.map(c => `
                    <div class="autocomplete-item" onclick='selectCustomer(${JSON.stringify(c).replace(/'/g, "\\'")})' >
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
                resultsDiv.innerHTML = '<div class="autocomplete-item no-result">เกิดข้อผิดพลาดในการค้นหา</div>';
                resultsDiv.style.display = 'block';
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

    async function submitCreateDeposit() {
        const customerId = document.getElementById('selectedCustomerId').value;
        const itemType = document.getElementById('itemType').value;
        const itemName = document.getElementById('itemName').value;
        const itemDescription = document.getElementById('itemDescription').value;
        const warrantyNo = document.getElementById('warrantyNo')?.value || '';
        const appraisedValue = document.getElementById('appraisedValue')?.value || 0;
        const loanPercentage = document.getElementById('loanPercentage')?.value || 70;
        const depositAmount = document.getElementById('depositAmount').value;
        const interestRate = document.getElementById('interestRate')?.value || 2;
        const depositDate = document.getElementById('depositDate')?.value || '';
        const dueDate = document.getElementById('dueDate')?.value || '';
        const storageLocation = document.getElementById('storageLocation')?.value || '';
        const notes = document.getElementById('depositNotes').value;

        if (!customerId) {
            showError('กรุณาเลือกลูกค้า');
            return;
        }

        if (!itemType || !itemName || !depositAmount) {
            showError('กรุณากรอกข้อมูลให้ครบ (ประเภท, ชื่อสินค้า, วงเงิน)');
            return;
        }

        if (!depositDate) {
            showError('กรุณาระบุวันที่รับฝาก');
            return;
        }

        try {
            const response = await fetch('/api/customer/deposits.php?action=create', {
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
                    warranty_no: warrantyNo,
                    appraised_value: parseFloat(appraisedValue) || 0,
                    loan_percentage: parseFloat(loanPercentage) || 70,
                    deposit_amount: parseFloat(depositAmount),
                    interest_rate: parseFloat(interestRate) || 2,
                    deposit_date: depositDate,
                    expected_pickup_date: dueDate,
                    storage_location: storageLocation,
                    notes: notes
                })
            });

            const data = await response.json();

            if (data.success) {
                showSuccess('บันทึกรายการฝากสำเร็จ: ' + (data.data?.deposit_no || ''));
                closeCreateModal();
                loadDeposits(1);
            } else {
                showError(data.message || 'เกิดข้อผิดพลาด');
            }
        } catch (error) {
            console.error('Error:', error);
            showError('เกิดข้อผิดพลาดในการบันทึก');
        }
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.autocomplete-wrapper')) {
            document.getElementById('customerSearchResults').style.display = 'none';
        }
    });
</script>

<?php include('../includes/customer/footer.php'); ?>