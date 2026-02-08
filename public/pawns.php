<?php
/**
 * Pawns - Customer Portal
 * ระบบจำนำ สำหรับร้านเฮง เฮง
 */
define('INCLUDE_CHECK', true);

$page_title = "จำนำ - เฮง เฮง";
$current_page = "pawns";

include('../includes/customer/header.php');
include('../includes/customer/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <div class="page-header-content">
            <div>
                <h1 class="page-title">💎 จำนำ</h1>
                <p class="page-subtitle">รายการจำนำและต่อดอกเบี้ย</p>
            </div>
            <div class="btn-group" style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                <button class="btn btn-warning" onclick="sendReminders()" title="ส่งแจ้งเตือนลูกค้าใกล้ครบกำหนด">
                    <i class="fas fa-bell"></i> <span class="btn-text">ส่งแจ้งเตือน</span>
                </button>
                <button class="btn btn-primary" onclick="openCreateModal()">
                    <i class="fas fa-plus"></i> <span class="btn-text">รับจำนำใหม่</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-grid">
        <div class="summary-card summary-card-info">
            <div class="summary-icon">📦</div>
            <div class="summary-value" id="activeCount">0</div>
            <div class="summary-label">กำลังดำเนินการ</div>
        </div>
        <div class="summary-card summary-card-upcoming" style="cursor:pointer;" onclick="filterDueSoon()">
            <div class="summary-icon">⏰</div>
            <div class="summary-value" id="dueSoonCount">0</div>
            <div class="summary-label">ใกล้ครบกำหนด</div>
        </div>
        <div class="summary-card summary-card-warning" style="cursor:pointer;" onclick="filterOverdue()">
            <div class="summary-icon">⚠️</div>
            <div class="summary-value" id="overdueCount">0</div>
            <div class="summary-label">เกินกำหนด</div>
        </div>
        <div class="summary-card">
            <div class="summary-icon">💰</div>
            <div class="summary-value" id="totalPrincipal">฿0</div>
            <div class="summary-label">เงินต้นรวม</div>
        </div>
        <div class="summary-card summary-card-success">
            <div class="summary-icon">✅</div>
            <div class="summary-value" id="totalRedeemed">฿0</div>
            <div class="summary-label">ไถ่ถอนแล้ว</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">รายการจำนำ</h3>
            <div class="filter-group">
                <select id="statusFilter" class="form-select" onchange="loadPawns()">
                    <option value="">ทั้งหมด</option>
                    <option value="active">กำลังดำเนินการ</option>
                    <option value="due_soon">ใกล้ครบกำหนด (1-3 วัน)</option>
                    <option value="overdue">เกินกำหนด</option>
                    <option value="redeemed">ไถ่ถอนแล้ว</option>
                </select>
            </div>
        </div>
        <div class="card-body">
            <!-- Desktop Table -->
            <div class="table-container desktop-only">
                <table>
                    <thead>
                        <tr>
                            <th>รหัสจำนำ</th>
                            <th>ลูกค้า</th>
                            <th>สินค้า</th>
                            <th style="text-align:right;">เงินต้น</th>
                            <th style="text-align:right;">ดอกเบี้ย/เดือน</th>
                            <th style="text-align:right;">จ่ายดอกแล้ว</th>
                            <th>ครบกำหนด</th>
                            <th>สถานะ</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="pawnsTableBody">
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
            <div class="mobile-cards mobile-only" id="pawnsMobileCards">
                <div class="loading-placeholder">
                    <div class="spinner"></div>
                    <p>กำลังโหลดข้อมูล...</p>
                </div>
            </div>

            <!-- Pagination -->
            <div id="pawnsPagination" class="pagination-container"></div>
        </div>
    </div>
</main>

<!-- Detail Modal -->
<div class="modal-overlay" id="detailModal">
    <div class="modal-container modal-lg">
        <div class="modal-header">
            <h3 class="modal-title">📋 รายละเอียดจำนำ</h3>
            <button class="modal-close" onclick="closeDetailModal()">&times;</button>
        </div>
        <div class="modal-body" id="pawnDetailContent"></div>
        <div class="modal-footer" id="pawnDetailFooter">
            <!-- ✅ Action buttons จะแสดงตาม status -->
            <button class="btn btn-success" id="btnRedeemFromDetail" onclick="openRedeemModal()" style="display:none;">
                <i class="fas fa-undo"></i> ไถ่ถอน
            </button>
            <button class="btn btn-primary" id="btnPayFromDetail" onclick="payFromDetail()" style="display:none;">
                <i class="fas fa-credit-card"></i> ต่อดอก
            </button>
            <button class="btn btn-secondary" onclick="closeDetailModal()">ปิด</button>
        </div>
    </div>
</div>

<!-- ✅ Redeem Modal (ไถ่ถอน) -->
<div class="modal-overlay" id="redeemModal">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="modal-title">💰 ไถ่ถอน</h3>
            <button class="modal-close" onclick="closeRedeemModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="redeemInfo"></div>

            <div class="redeem-summary">
                <div class="redeem-row">
                    <span>เงินต้น</span>
                    <span id="redeemPrincipal">฿0</span>
                </div>
                <div class="redeem-row">
                    <span>ดอกเบี้ยค้าง</span>
                    <span id="redeemInterest">฿0</span>
                </div>
                <div class="redeem-row total">
                    <span>ยอดไถ่ถอนรวม</span>
                    <span id="redeemTotal">฿0</span>
                </div>
            </div>

            <div class="info-box"
                style="background:#fef3c7; border:1px solid #fcd34d; border-radius:8px; padding:1rem; margin-top:1rem;">
                <p style="margin:0; color:#92400e;">
                    <i class="fas fa-exclamation-triangle"></i>
                    กรุณาติดต่อเจ้าของร้านเพื่อยืนยันยอดไถ่ถอนและดำเนินการชำระเงิน
                </p>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeRedeemModal()">ปิด</button>
            <button class="btn btn-success" onclick="goToPaymentHistoryForRedeem()">
                <i class="fas fa-credit-card"></i> สร้างรายการชำระเงิน
            </button>
        </div>
    </div>
</div>

<!-- Create Pawn Modal -->
<div class="modal-overlay" id="createModal">
    <div class="modal-container modal-lg">
        <div class="modal-header">
            <h3 class="modal-title">💎 รับจำนำใหม่</h3>
            <button class="modal-close" onclick="closeCreateModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="createPawnForm">
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

                <!-- ✅ Order Selection Section (Hybrid A+) -->
                <div class="detail-section" id="orderSelectionSection" style="display:none;">
                    <h4 class="detail-section-title">🛍️ เลือกสินค้าที่เคยซื้อ</h4>
                    <p class="form-hint" style="margin-bottom:1rem;">💡
                        สินค้าที่สามารถนำมาจำนำได้ต้องเป็นสินค้าที่ซื้อจากร้านและชำระเงินแล้ว</p>
                    <div id="eligibleItemsLoading" class="loading-placeholder" style="display:none;">
                        <div class="spinner"></div>
                        <p>กำลังโหลดรายการสินค้า...</p>
                    </div>
                    <div id="eligibleItemsList" class="eligible-items-list"></div>
                    <input type="hidden" id="selectedOrderId" name="order_id">
                    <input type="hidden" id="selectedOriginalPrice" name="original_price">
                </div>

                <!-- Item Section -->
                <div class="detail-section">
                    <h4 class="detail-section-title">💍 ข้อมูลสินค้าจำนำ</h4>
                    <div class="detail-grid">
                        <div class="form-group">
                            <label for="itemType">ประเภทสินค้า <span class="required">*</span></label>
                            <select id="itemType" name="item_type" class="form-input" required>
                                <option value="">เลือกประเภท</option>
                                <option value="gold">ทองคำ</option>
                                <option value="jewelry">เพชร/พลอย</option>
                                <option value="watch">นาฬิกา</option>
                                <option value="diamond">เพชร</option>
                                <option value="other">อื่นๆ</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="itemName">ชื่อ/รายละเอียดสินค้า <span class="required">*</span></label>
                            <input type="text" id="itemName" name="item_name" class="form-input" required
                                placeholder="เช่น สร้อยคอทองคำ 2 บาท" oninput="updatePawnMessageTemplate()">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="itemDescription">สภาพ/หมายเหตุสินค้า</label>
                        <textarea id="itemDescription" name="item_description" class="form-input" rows="2"
                            placeholder="สภาพสินค้าและหมายเหตุ"></textarea>
                    </div>
                    <div class="detail-grid">
                        <div class="form-group">
                            <label for="warrantyNo">เลขที่ใบรับประกัน</label>
                            <input type="text" id="warrantyNo" name="warranty_no" class="form-input"
                                placeholder="เช่น WTY-2026-0001">
                            <small class="form-hint">⚠️ ต้องมีใบรับประกันจากร้านประกอบ</small>
                        </div>
                        <div class="form-group">
                            <label for="productRefId">รหัสสินค้า</label>
                            <input type="text" id="productRefId" name="product_ref_id" class="form-input"
                                placeholder="เช่น P-2026-000001">
                        </div>
                    </div>
                </div>

                <!-- Loan Details -->
                <div class="detail-section">
                    <h4 class="detail-section-title">💰 รายละเอียดเงินกู้</h4>
                    <div class="detail-grid">
                        <div class="form-group">
                            <label for="loanAmount">เงินต้น (บาท) <span class="required">*</span></label>
                            <input type="number" id="loanAmount" name="loan_amount" class="form-input" required
                                placeholder="10000" min="0" step="1" oninput="updatePawnMessageTemplate()">
                        </div>
                        <div class="form-group">
                            <label for="interestRate">อัตราดอกเบี้ย (% ต่อเดือน) <span class="required">*</span></label>
                            <input type="number" id="interestRate" name="interest_rate" class="form-input" required
                                placeholder="2" min="0" step="0.1" value="2" oninput="updatePawnMessageTemplate()">
                        </div>
                    </div>
                    <div class="detail-grid">
                        <div class="form-group">
                            <label for="pawnPeriod">ระยะเวลา (เดือน)</label>
                            <select id="pawnPeriod" name="period_months" class="form-input" onchange="updateDueDateFromPeriod()">
                                <option value="1">1 เดือน</option>
                                <option value="2">2 เดือน</option>
                                <option value="3" selected>3 เดือน</option>
                                <option value="6">6 เดือน</option>
                                <option value="12">12 เดือน</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="dueDate">วันครบกำหนด</label>
                            <input type="date" id="dueDate" name="due_date" class="form-input" onchange="updatePawnMessageTemplate()">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="pawnNotes">หมายเหตุ</label>
                        <textarea id="pawnNotes" name="notes" class="form-input" rows="2"
                            placeholder="หมายเหตุเพิ่มเติม"></textarea>
                    </div>
                </div>

                <!-- Bank Account & Push Message Section -->
                <div class="detail-section" id="pawnPushMessageSection">
                    <h4 class="detail-section-title">💬 แจ้งลูกค้า</h4>
                    <div class="form-group">
                        <label for="pawnBankAccount">บัญชีรับโอน</label>
                        <select id="pawnBankAccount" name="bank_account" class="form-input"
                            onchange="updatePawnMessageTemplate()">
                            <option value="">-- เลือกบัญชี --</option>
                            <option value="scb_1" data-bank="ไทยพาณิชย์" data-name="บจก เพชรวิบวับ"
                                data-number="1653014242">ไทยพาณิชย์ - 1653014242</option>
                            <option value="kbank_1" data-bank="กสิกรไทย" data-name="บจก.เฮงเฮงโฮลดิ้ง"
                                data-number="8000029282">กสิกรไทย - 8000029282</option>
                            <option value="bay_1" data-bank="กรุงศรี" data-name="บจก.เฮงเฮงโฮลดิ้ง"
                                data-number="8000029282">กรุงศรี - 8000029282</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="pawnCustomerMessage">ข้อความแจ้งลูกค้า</label>
                        <textarea id="pawnCustomerMessage" name="customer_message" class="form-input" rows="8"
                            placeholder="ข้อความที่จะส่งให้ลูกค้า..."></textarea>
                        <small class="form-hint">💡 เลือกบัญชีเพื่อ auto-fill ข้อความ หรือพิมพ์เอง</small>
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="send_pawn_message" id="sendPawnMessageCheckbox" checked
                                onchange="updatePawnSubmitButtonText()">
                            <span>📤 ส่งข้อความแจ้งลูกค้าทันที</span>
                        </label>
                        <small id="sendPawnMessageWarning" class="form-hint" style="color: #f59e0b; display: none;">
                            ⚠️ ต้องเลือกลูกค้าจากระบบที่มี LINE/Facebook ก่อนส่งได้
                        </small>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeCreateModal()">ยกเลิก</button>
            <button class="btn btn-primary" id="submitPawnBtn" onclick="submitCreatePawn()">
                <i class="fas fa-save"></i> บันทึก & ส่งข้อความ
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

    .summary-card-upcoming {
        border-left: 4px solid #f97316;
    }

    .summary-card-upcoming .summary-value {
        color: #f97316;
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

    .interest-summary {
        background: #f0f9ff;
        border-radius: 12px;
        padding: 1rem;
        margin: 1rem 0;
    }

    .interest-row {
        display: flex;
        justify-content: space-between;
        padding: 0.5rem 0;
    }

    .interest-row.total {
        border-top: 1px solid #bae6fd;
        margin-top: 0.5rem;
        padding-top: 1rem;
        font-weight: 700;
        font-size: 1.1rem;
        color: var(--color-primary);
    }

    /* ✅ Redeem Summary Styles */
    .redeem-summary {
        background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
        border: 1px solid #6ee7b7;
        border-radius: 12px;
        padding: 1rem;
        margin: 1rem 0;
    }

    .redeem-row {
        display: flex;
        justify-content: space-between;
        padding: 0.4rem 0;
    }

    .redeem-row.total {
        border-top: 1px solid #6ee7b7;
        margin-top: 0.5rem;
        padding-top: 0.75rem;
        font-weight: 700;
        font-size: 1.1rem;
        color: #047857;
    }

    /* ✅ Eligible Items List (Hybrid A+) */
    .eligible-items-list {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        max-height: 300px;
        overflow-y: auto;
    }

    .eligible-item-card {
        background: #f8fafc;
        border: 2px solid #e2e8f0;
        border-radius: 12px;
        padding: 1rem;
        cursor: pointer;
        transition: all 0.2s;
    }

    .eligible-item-card:hover {
        border-color: var(--color-primary);
        background: #f0f9ff;
    }

    .eligible-item-card.selected {
        border-color: var(--color-primary);
        background: #dbeafe;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
    }

    .eligible-item-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 0.5rem;
    }

    .eligible-item-code {
        font-weight: 600;
        color: var(--color-primary);
    }

    .eligible-item-date {
        font-size: 0.8rem;
        color: var(--color-gray);
    }

    .eligible-item-details {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 0.9rem;
    }

    .eligible-item-price {
        font-weight: 600;
        color: #059669;
    }

    .eligible-item-loan {
        font-size: 0.85rem;
        color: var(--color-gray);
    }

    .no-eligible-items {
        text-align: center;
        padding: 2rem;
        color: var(--color-gray);
        background: #f9fafb;
        border-radius: 12px;
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

    .status-active {
        background: #dbeafe;
        color: #2563eb;
    }

    .status-overdue {
        background: #fee2e2;
        color: #dc2626;
    }

    .status-redeemed {
        background: #d1fae5;
        color: #059669;
    }

    .status-forfeited {
        background: #f3f4f6;
        color: #6b7280;
    }

    .pawn-info-card {
        background: #f8fafc;
        border-radius: 12px;
        padding: 1rem;
        margin-bottom: 1rem;
    }

    .pawn-info-row {
        display: flex;
        justify-content: space-between;
        padding: 0.5rem 0;
        border-bottom: 1px solid #e2e8f0;
    }

    .pawn-info-row:last-child {
        border-bottom: none;
    }

    .pawn-info-label {
        color: var(--color-gray);
    }

    .pawn-info-value {
        font-weight: 600;
    }

    .amount-highlight {
        font-size: 1.25rem;
        color: var(--color-primary);
    }

    .overdue-warning {
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: 8px;
        padding: 0.75rem 1rem;
        color: #dc2626;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .payment-history {
        margin-top: 1.5rem;
    }

    .payment-history h4 {
        margin-bottom: 1rem;
        color: var(--color-dark);
    }

    .payment-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem;
        background: #f8fafc;
        border-radius: 8px;
        margin-bottom: 0.5rem;
    }

    .payment-item-date {
        color: var(--color-gray);
        font-size: 0.85rem;
    }

    .payment-item-amount {
        font-weight: 600;
    }

    .modal-lg {
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

    @media (max-width: 768px) {

        /* Prevent horizontal overflow from sidebar */
        html,
        body {
            overflow-x: hidden !important;
        }

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

        /* Hide button text on mobile, show only icon */
        .btn-text {
            display: none;
        }

        .page-header-content .btn {
            min-width: 48px;
            padding: 0.75rem;
        }

        /* ✅ Stack page header on mobile */
        .page-header-content {
            flex-direction: column !important;
            align-items: stretch !important;
            gap: 0.75rem;
        }

        .page-header-content>div:first-child {
            text-align: left;
        }

        .page-header-content .btn.btn-primary {
            width: 100% !important;
            max-width: 100% !important;
            justify-content: center;
            padding: 0.75rem 1rem;
        }

        /* Show button text on mobile for primary action */
        .page-header-content .btn.btn-primary .btn-text {
            display: inline !important;
        }

        /* Full-screen modal on mobile */
        .modal-overlay {
            padding: 0 !important;
            align-items: flex-start;
        }

        .modal-container,
        .modal-container.modal-lg,
        #detailModal .modal-container,
        #createModal .modal-container,
        #redeemModal .modal-container {
            width: 100% !important;
            max-width: 100% !important;
            min-height: 100vh !important;
            max-height: 100vh !important;
            margin: 0 !important;
            border-radius: 0 !important;
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            position: sticky;
            top: 0;
            z-index: 10;
            background: white;
            flex-shrink: 0;
        }

        .modal-body {
            flex: 1;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            padding: 1rem;
        }

        .modal-footer {
            position: sticky;
            bottom: 0;
            z-index: 10;
            background: white;
            flex-shrink: 0;
            flex-wrap: wrap;
        }

        .modal-footer .btn {
            flex: 1;
            min-width: 45%;
        }

        .modal-close {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
        }

        /* Adjust page header for mobile */
        .page-header {
            margin-top: 4rem;
        }

        .page-title {
            font-size: 1.25rem;
        }

        /* Mobile card adjustments */
        .mobile-card-actions {
            flex-wrap: wrap;
        }

        .mobile-card-actions .btn {
            font-size: 0.85rem;
            padding: 0.5rem 0.75rem;
        }
    }

    @media (max-width: 480px) {
        .summary-grid {
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
        }

        .summary-card {
            padding: 0.75rem;
        }

        .summary-icon {
            font-size: 1.5rem;
        }

        .summary-value {
            font-size: 1.1rem;
        }

        .modal-footer .btn {
            font-size: 0.85rem;
            padding: 0.5rem 0.75rem;
        }
    }
</style>

<script>
    let currentPage = 1;
    let selectedPawnId = null;
    let selectedPawnData = null;

    // ========================================
    // ✅ Modal Functions (must be defined early for onclick handlers)
    // ========================================

    function openCreateModal() {
        document.getElementById('createPawnForm').reset();
        if (typeof clearSelectedCustomer === 'function') clearSelectedCustomer();
        if (typeof clearSelectedOrder === 'function') clearSelectedOrder();
        // Set default due date (3 months from now)
        const dueDate = new Date();
        dueDate.setMonth(dueDate.getMonth() + 3);
        const dueDateEl = document.getElementById('dueDate');
        if (dueDateEl) dueDateEl.value = dueDate.toISOString().split('T')[0];
        document.getElementById('createModal').classList.add('active');
        // Reset message template
        const msgEl = document.getElementById('pawnCustomerMessage');
        if (msgEl) msgEl.value = '';
        const bankEl = document.getElementById('pawnBankAccount');
        if (bankEl) bankEl.value = '';
    }

    function closeCreateModal() {
        document.getElementById('createModal').classList.remove('active');
        if (typeof clearSelectedOrder === 'function') clearSelectedOrder();
    }

    function getInitials(name) {
        if (!name) return '-';
        const parts = name.split(' ');
        if (parts.length >= 2) {
            return parts[0].charAt(0) + parts[1].charAt(0);
        }
        return name.substring(0, 2).toUpperCase();
    }

    document.addEventListener('DOMContentLoaded', function () {
        loadPawns();
    });

    async function loadPawns(page = 1) {
        currentPage = page;
        const status = document.getElementById('statusFilter').value;

        try {
            let url = `/api/customer/pawns?page=${page}&limit=20`;
            if (status) url += `&status=${status}`;

            const response = await fetch(url, {
                headers: { 'Authorization': 'Bearer ' + getToken() }
            });

            // Check for 401 Unauthorized (session expired)
            if (!checkAuthResponse(response)) return;

            const data = await response.json();

            if (data.success) {
                renderPawns(data.data);
                renderPagination(data.pagination);
                updateSummary(data.summary);
            } else {
                showError(data.message || 'ไม่สามารถโหลดข้อมูลได้');
            }
        } catch (error) {
            console.error('Error loading pawns:', error);
            showError('เกิดข้อผิดพลาดในการโหลดข้อมูล');
        }
    }

    function renderPawns(pawns) {
        const tbody = document.getElementById('pawnsTableBody');
        const mobileCards = document.getElementById('pawnsMobileCards');

        if (!pawns || pawns.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:2rem;color:#6b7280;">ไม่มีรายการจำนำ</td></tr>';
            mobileCards.innerHTML = '<div class="empty-state"><p>ไม่มีรายการจำนำ</p></div>';
            return;
        }

        // Desktop table
        tbody.innerHTML = pawns.map(p => {
            // Determine row style based on status
            const isDueSoon = p.days_until_due >= 1 && p.days_until_due <= 3 && p.status === 'active';
            let rowStyle = '';
            if (p.is_overdue) rowStyle = 'background:#fef2f2;'; // red tint
            else if (isDueSoon) rowStyle = 'background:#fffbeb;'; // yellow tint
            
            // Due date display with indicator
            let dueDateHtml = p.next_interest_due ? formatDate(p.next_interest_due) : '-';
            if (p.days_until_due < 0) {
                dueDateHtml += ` <span style="color:#dc2626;font-weight:600;">(เกิน ${Math.abs(p.days_until_due)} วัน)</span>`;
            } else if (isDueSoon) {
                dueDateHtml += ` <span style="color:#f97316;font-weight:600;">(อีก ${p.days_until_due} วัน)</span>`;
            }
            
            return `
        <tr style="${rowStyle}">
            <td><strong>${p.pawn_no || '-'}</strong></td>
            <td>
                <div style="display:flex;align-items:center;gap:0.5rem;">
                    ${p.customer_avatar ? `<img src="${p.customer_avatar}" style="width:32px;height:32px;border-radius:50%;object-fit:cover;">` : '<div style="width:32px;height:32px;border-radius:50%;background:#e5e7eb;display:flex;align-items:center;justify-content:center;"><i class="fas fa-user" style="color:#9ca3af;"></i></div>'}
                    <span>${p.customer_display_name || p.customer_name || '-'}</span>
                </div>
            </td>
            <td>${p.item_name || p.item_description || '-'}</td>
            <td style="text-align:right;">฿${formatNumber(p.principal_amount)}</td>
            <td style="text-align:right;">฿${formatNumber(p.monthly_interest)} (${p.interest_rate_percent}%)</td>
            <td style="text-align:right;">${p.total_interest_paid > 0 ? '<span style="color:#16a34a;">฿' + formatNumber(p.total_interest_paid) + '</span> <small>(' + p.interest_payment_count + ' ครั้ง)</small>' : '<span style="color:#9ca3af;">-</span>'}</td>
            <td>${dueDateHtml}</td>
            <td><span class="status-badge status-${p.status}">${p.status_display}</span></td>
            <td>
                <div class="action-buttons">
                    <button class="btn btn-sm btn-ghost" onclick="viewDetail(${p.id})" title="ต่อดอก">
                        <i class="fas fa-credit-card"></i>
                    </button>           
                </div>
            </td>
        </tr>
    `}).join('');

        // Mobile cards
        mobileCards.innerHTML = pawns.map(p => `
        <div class="mobile-card" ${p.is_overdue ? 'style="border-color:#fecaca;"' : ''}>
            <div class="mobile-card-header">
                <span class="mobile-card-title">${p.pawn_no || '-'}</span>
                <span class="status-badge status-${p.status}">${p.status_display}</span>
            </div>
            <!-- Customer info -->
            <div class="mobile-card-row" style="padding:0.5rem 0;border-bottom:1px solid #e5e7eb;margin-bottom:0.5rem;">
                <div style="display:flex;align-items:center;gap:0.5rem;">
                    ${p.customer_avatar ? `<img src="${p.customer_avatar}" style="width:28px;height:28px;border-radius:50%;object-fit:cover;">` : '<div style="width:28px;height:28px;border-radius:50%;background:#e5e7eb;display:flex;align-items:center;justify-content:center;"><i class="fas fa-user" style="color:#9ca3af;font-size:0.75rem;"></i></div>'}
                    <span style="font-weight:500;">${p.customer_display_name || p.customer_name || '-'}</span>
                </div>
            </div>
            ${p.is_overdue ? '<div class="overdue-warning"><i class="fas fa-exclamation-triangle"></i> เกินกำหนดชำระ</div>' : ''}
            <div class="mobile-card-row">
                <span class="mobile-card-label">สินค้า</span>
                <span class="mobile-card-value">${p.item_name || p.item_description || '-'}</span>
            </div>
            <div class="mobile-card-row">
                <span class="mobile-card-label">เงินต้น</span>
                <span class="mobile-card-value">฿${formatNumber(p.principal_amount)}</span>
            </div>
            <div class="mobile-card-row">
                <span class="mobile-card-label">ดอกเบี้ย/เดือน</span>
                <span class="mobile-card-value">฿${formatNumber(p.monthly_interest)} (${p.interest_rate_percent}%)</span>
            </div>
            <div class="mobile-card-row">
                <span class="mobile-card-label">จ่ายดอกแล้ว</span>
                <span class="mobile-card-value" style="${p.total_interest_paid > 0 ? 'color:#16a34a;' : 'color:#9ca3af;'}">${p.total_interest_paid > 0 ? '฿' + formatNumber(p.total_interest_paid) + ' (' + p.interest_payment_count + ' ครั้ง)' : '-'}</span>
            </div>
            <div class="mobile-card-row">
                <span class="mobile-card-label">ครบกำหนด</span>
                <span class="mobile-card-value" ${p.days_until_due < 0 ? 'style="color:#dc2626;"' : ''}>${p.next_interest_due ? formatDate(p.next_interest_due) : '-'}</span>
            </div>
            <div class="mobile-card-actions">
                <button class="btn btn-sm btn-secondary" onclick="viewDetail(${p.id})">
                    <i class="fas fa-eye"></i> ดู
                </button>
                ${['active', 'overdue'].includes(p.status) ? `
                    <button class="btn btn-sm btn-primary" onclick="openPaymentModal(${p.id})">
                        <i class="fas fa-credit-card"></i> ต่อดอก
                    </button>
                ` : ''}
            </div>
        </div>
    `).join('');
    }

    function updateSummary(summary) {
        if (!summary) return;
        document.getElementById('activeCount').textContent = summary.active_count || 0;
        document.getElementById('overdueCount').textContent = summary.overdue_count || 0;
        document.getElementById('dueSoonCount').textContent = summary.due_soon_count || 0;
        document.getElementById('totalPrincipal').textContent = '฿' + formatNumber(summary.total_principal || 0);
        document.getElementById('totalRedeemed').textContent = '฿' + formatNumber(summary.total_redeemed || 0);
    }

    // Filter shortcuts
    function filterDueSoon() {
        document.getElementById('statusFilter').value = 'due_soon';
        loadPawns();
    }

    function filterOverdue() {
        document.getElementById('statusFilter').value = 'overdue';
        loadPawns();
    }

    // ✅ Manual send reminders to customers with due_soon/overdue pawns
    async function sendReminders() {
        if (!confirm('ต้องการส่งแจ้งเตือนผ่าน LINE/Facebook ไปยังลูกค้าที่ใกล้ครบกำหนด (1-3 วัน) หรือไม่?')) return;

        try {
            const response = await fetch('/api/admin/pawns.php?action=send-reminders', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + getToken()
                }
            });

            if (!checkAuthResponse(response)) return;

            const data = await response.json();

            if (data.success) {
                showSuccess(data.message);
                loadPawns(); // Reload to update overdue status
            } else {
                showError(data.message || 'เกิดข้อผิดพลาด');
            }
        } catch (error) {
            console.error('Error sending reminders:', error);
            showError('เกิดข้อผิดพลาดในการส่งแจ้งเตือน');
        }
    }

    function renderPagination(pagination) {
        if (!pagination) return;
        const container = document.getElementById('pawnsPagination');
        const { page, total_pages, total } = pagination;

        if (total_pages <= 1) {
            container.innerHTML = '';
            return;
        }

        container.innerHTML = `
        <button class="btn-pagination" onclick="loadPawns(${page - 1})" ${page <= 1 ? 'disabled' : ''}>
            <i class="fas fa-chevron-left"></i>
        </button>
        <span class="page-indicator">หน้า ${page} / ${total_pages}</span>
        <button class="btn-pagination" onclick="loadPawns(${page + 1})" ${page >= total_pages ? 'disabled' : ''}>
            <i class="fas fa-chevron-right"></i>
        </button>
    `;
    }

    async function viewDetail(id) {
        try {
            const response = await fetch(`/api/customer/pawns?id=${id}`, {
                headers: { 'Authorization': 'Bearer ' + getToken() }
            });
            const data = await response.json();

            if (data.success) {
                const p = data.data;
                const payments = data.payments || [];

                let html = `
                ${p.is_overdue ? '<div class="overdue-warning"><i class="fas fa-exclamation-triangle"></i> รายการนี้เกินกำหนดชำระดอกเบี้ย</div>' : ''}
                
                <!-- Customer Info -->
                <div class="pawn-info-card" style="display:flex;align-items:center;gap:1rem;padding:1rem;background:#f0f9ff;border-radius:12px;margin-bottom:1rem;">
                    ${p.customer_avatar ? `<img src="${p.customer_avatar}" style="width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid #60a5fa;">` : '<div style="width:48px;height:48px;border-radius:50%;background:#e5e7eb;display:flex;align-items:center;justify-content:center;border:2px solid #60a5fa;"><i class="fas fa-user" style="color:#9ca3af;font-size:1.25rem;"></i></div>'}
                    <div>
                        <div style="font-weight:600;color:#1e40af;">${p.customer_display_name || p.customer_name || 'ไม่ระบุชื่อ'}</div>
                        <div style="font-size:0.85rem;color:#6b7280;">${p.customer_platform ? '<i class="fab fa-' + (p.customer_platform === 'facebook' ? 'facebook' : p.customer_platform === 'line' ? 'line' : 'globe') + '" style="margin-right:4px;"></i>' + p.customer_platform : ''}</div>
                    </div>
                </div>
                
                <div class="pawn-info-card">
                    <div class="pawn-info-row">
                        <span class="pawn-info-label">รหัสจำนำ</span>
                        <span class="pawn-info-value">${p.pawn_no || '-'}</span>
                    </div>
                    <div class="pawn-info-row">
                        <span class="pawn-info-label">สินค้า</span>
                        <span class="pawn-info-value">${p.item_name || p.item_description || '-'}</span>
                    </div>
                    <div class="pawn-info-row">
                        <span class="pawn-info-label">ราคาประเมิน</span>
                        <span class="pawn-info-value">฿${formatNumber(p.appraisal_value)}</span>
                    </div>
                    <div class="pawn-info-row">
                        <span class="pawn-info-label">เงินต้น</span>
                        <span class="pawn-info-value amount-highlight">฿${formatNumber(p.principal_amount)}</span>
                    </div>
                    <div class="pawn-info-row">
                        <span class="pawn-info-label">ดอกเบี้ย</span>
                        <span class="pawn-info-value">${p.interest_rate_percent}% / เดือน (฿${formatNumber(p.monthly_interest)})</span>
                    </div>
                    <div class="pawn-info-row">
                        <span class="pawn-info-label">ครบกำหนดต่อดอก</span>
                        <span class="pawn-info-value" ${p.is_overdue ? 'style="color:#dc2626;"' : ''}>${p.next_interest_due ? formatDate(p.next_interest_due) : '-'}</span>
                    </div>
                    <div class="pawn-info-row">
                        <span class="pawn-info-label">ยอดไถ่ถอน</span>
                        <span class="pawn-info-value" style="color:#059669;font-weight:700;">฿${formatNumber(p.redemption_amount)}</span>
                    </div>
                    <div class="pawn-info-row">
                        <span class="pawn-info-label">สถานะ</span>
                        <span class="status-badge status-${p.status}">${p.status_display}</span>
                    </div>
                </div>
            `;

                if (payments.length > 0) {
                    html += `
                    <div class="payment-history">
                        <h4>ประวัติการชำระ</h4>
                        ${payments.map(pay => `
                            <div class="payment-item">
                                <div>
                                    <div>${pay.payment_type === 'interest' ? 'ชำระดอกเบี้ย' : 'ไถ่ถอน'}</div>
                                    <div class="payment-item-date">${formatDate(pay.created_at)}</div>
                                </div>
                                <div style="text-align:right;">
                                    <div class="payment-item-amount">฿${formatNumber(pay.amount)}</div>
                                    <span class="status-badge status-${pay.verified_at ? 'active' : 'pending'}">${pay.status_display}</span>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                `;
                }

                document.getElementById('pawnDetailContent').innerHTML = html;

                // ✅ แสดง/ซ่อนปุ่มตาม status
                const isActiveOrOverdue = ['active', 'overdue'].includes(p.status);
                document.getElementById('btnRedeemFromDetail').style.display = isActiveOrOverdue ? 'inline-flex' : 'none';
                document.getElementById('btnPayFromDetail').style.display = isActiveOrOverdue ? 'inline-flex' : 'none';

                // เก็บ selectedPawnId สำหรับ action buttons
                selectedPawnId = id;
                selectedPawnData = p;

                console.log('[PAWNS] Detail loaded:', { id, status: p.status, isActiveOrOverdue });

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

    function goToPaymentHistoryForRedeem() {
        if (!selectedPawnData) {
            showError('ไม่พบข้อมูลจำนำ');
            return;
        }
        const amount = parseFloat(selectedPawnData.redemption_amount) || 0;
        const params = new URLSearchParams({
            action: 'create',
            type: 'pawn_redemption',
            pawn_id: selectedPawnId,
            pawn_no: selectedPawnData.pawn_no || '',
            amount: amount,
            description: `ไถ่ถอนจำนำ ${selectedPawnData.pawn_no || ''}`,
            customer_profile_id: selectedPawnData.customer_profile_id || ''
        });
        window.location.href = '/payment-history.php?' + params.toString();
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

    function showError(msg) { alert(msg); }
    function showSuccess(msg) { alert(msg); }

    // ✅ Highlight field with error
    function highlightField(fieldId, hasError) {
        const field = document.getElementById(fieldId);
        if (!field) return;

        if (hasError) {
            field.style.borderColor = '#ef4444';
            field.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.2)';
        } else {
            field.style.borderColor = '';
            field.style.boxShadow = '';
        }
    }

    // ========================================
    // ✅ Bank Account & Push Message Functions
    // ========================================

    function updatePawnMessageTemplate() {
        const select = document.getElementById('pawnBankAccount');
        const textarea = document.getElementById('pawnCustomerMessage');
        const customerName = document.getElementById('selectedCustomerName')?.textContent?.trim() || 'ลูกค้า';
        const loanAmount = document.getElementById('loanAmount')?.value || '0';
        const interestRate = document.getElementById('interestRate')?.value || '2';
        const itemName = document.getElementById('itemName')?.value?.trim() || 'สินค้าจำนำ';
        const periodMonths = document.getElementById('pawnPeriod')?.value || '3';

        if (!select || !textarea) return;

        const selectedOption = select.options[select.selectedIndex];
        if (!selectedOption || !selectedOption.value) {
            textarea.value = '';
            textarea.placeholder = 'เลือกบัญชีเพื่อสร้างข้อความอัตโนมัติ...';
            return;
        }

        const bankName = selectedOption.dataset.bank || '';
        const accountName = selectedOption.dataset.name || '';
        const accountNumber = selectedOption.dataset.number || '';
        const loanNum = parseFloat(loanAmount) || 0;
        const monthlyInterest = Math.round(loanNum * (parseFloat(interestRate) || 2) / 100);
        const dueDate = document.getElementById('dueDate')?.value || '';
        const dueDateFormatted = dueDate ? new Date(dueDate).toLocaleDateString('th-TH', { day: 'numeric', month: 'long', year: 'numeric' }) : '';

        const template = `💎 รับจำนำเรียบร้อยแล้วค่ะ

สวัสดีค่ะ คุณ${customerName} 🙏

📋 รหัส: {{PAWN_NUMBER}}
📦 สินค้า: ${itemName}
💰 เงินต้น: ${formatNumber(loanNum)} บาท
📊 ดอกเบี้ย: ${interestRate}% ต่อเดือน (${formatNumber(monthlyInterest)} บาท/เดือน)
📅 ครบกำหนด: ${dueDateFormatted}

🏦 ข้อมูลการโอนเงิน
ธนาคาร: ${bankName}
ชื่อบัญชี: ${accountName}
เลขบัญชี: ${accountNumber}

ชำระดอกเบี้ยแล้วส่งสลิปมาได้เลยนะคะ 🙏`;

        textarea.value = template;
    }

    function updatePawnSubmitButtonText() {
        const checkbox = document.getElementById('sendPawnMessageCheckbox');
        const btn = document.getElementById('submitPawnBtn');
        if (checkbox && btn) {
            if (checkbox.checked) {
                btn.innerHTML = '<i class="fas fa-save"></i> บันทึก & ส่งข้อความ';
            } else {
                btn.innerHTML = '<i class="fas fa-save"></i> บันทึก';
            }
        }
    }

    // ✅ Update due date based on period selection
    function updateDueDateFromPeriod() {
        const periodMonths = parseInt(document.getElementById('pawnPeriod')?.value) || 3;
        const dueDate = new Date();
        dueDate.setMonth(dueDate.getMonth() + periodMonths);
        const dueDateEl = document.getElementById('dueDate');
        if (dueDateEl) {
            dueDateEl.value = dueDate.toISOString().split('T')[0];
        }
        // Update message template to reflect new due date
        updatePawnMessageTemplate();
    }

    // ========================================
    // ✅ Hybrid A+ - Eligible Items from Orders
    // ========================================

    let selectedOrderData = null;

    async function loadEligibleItems(customerId) {
        if (!customerId) {
            document.getElementById('orderSelectionSection').style.display = 'none';
            return;
        }

        document.getElementById('orderSelectionSection').style.display = 'block';
        document.getElementById('eligibleItemsLoading').style.display = 'block';
        document.getElementById('eligibleItemsList').innerHTML = '';

        try {
            const res = await fetch(`/api/customer/pawns?action=eligible&customer_id=${customerId}`, {
                headers: { 'Authorization': 'Bearer ' + getToken() }
            });
            const data = await res.json();

            document.getElementById('eligibleItemsLoading').style.display = 'none';

            if (data.success && data.data && data.data.length > 0) {
                const html = data.data.map(item => `
                    <div class="eligible-item-card" onclick="selectEligibleItem(${item.order_id}, this)" data-order='${JSON.stringify(item).replace(/'/g, "&#39;")}'>
                        <div style="display:flex;gap:0.75rem;align-items:flex-start;">
                            ${item.product_image ? `<img src="${item.product_image}" alt="" style="width:60px;height:60px;object-fit:cover;border-radius:8px;flex-shrink:0;">` : '<div style="width:60px;height:60px;background:#f3f4f6;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="fas fa-box" style="color:#9ca3af;"></i></div>'}
                            <div style="flex:1;min-width:0;">
                                <div class="eligible-item-header">
                                    <span class="eligible-item-code">${item.product_code || item.order_no}</span>
                                    <span class="eligible-item-date">${formatDate(item.purchase_date)}</span>
                                </div>
                                <div style="font-weight:600;margin:0.25rem 0;color:#111827;">${item.product_name || 'ไม่ระบุชื่อสินค้า'}</div>
                                <div class="eligible-item-details">
                                    <span class="eligible-item-price">ราคาซื้อ: ฿${formatNumber(item.unit_price)}</span>
                                    <span class="eligible-item-loan">วงเงินกู้: ฿${formatNumber(item.suggested_loan)}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                `).join('');
                document.getElementById('eligibleItemsList').innerHTML = html;
            } else {
                document.getElementById('eligibleItemsList').innerHTML = `
                    <div class="no-eligible-items">
                        <i class="fas fa-box-open" style="font-size:2rem;margin-bottom:0.5rem;"></i>
                        <p>ไม่พบสินค้าที่สามารถนำมาจำนำได้</p>
                        <small>ลูกค้าต้องซื้อสินค้าจากร้านและชำระเงินแล้วก่อน</small>
                    </div>
                `;
            }
        } catch (e) {
            console.error('Error loading eligible items:', e);
            document.getElementById('eligibleItemsLoading').style.display = 'none';
            document.getElementById('eligibleItemsList').innerHTML = `
                <div class="no-eligible-items">
                    <p>เกิดข้อผิดพลาดในการโหลดข้อมูล</p>
                </div>
            `;
        }
    }

    function selectEligibleItem(orderId, element) {
        // Remove selection from all
        document.querySelectorAll('.eligible-item-card').forEach(el => el.classList.remove('selected'));
        // Add selection to clicked
        element.classList.add('selected');

        // Get order data
        selectedOrderData = JSON.parse(element.dataset.order);
        document.getElementById('selectedOrderId').value = orderId;
        document.getElementById('selectedOriginalPrice').value = selectedOrderData.unit_price;

        // ✅ Auto-fill item details - FIXED: product_name ลง itemName, product_code ลง productRefId
        document.getElementById('itemName').value = selectedOrderData.product_name || selectedOrderData.product_code || '';
        document.getElementById('productRefId').value = selectedOrderData.product_code || '';
        document.getElementById('loanAmount').value = Math.round(selectedOrderData.suggested_loan);

        // ✅ Auto-calculate due date based on pawnPeriod selection
        updateDueDateFromPeriod();
        
        // ✅ Update message template
        updatePawnMessageTemplate();
    }

    function clearSelectedOrder() {
        selectedOrderData = null;
        document.getElementById('selectedOrderId').value = '';
        document.getElementById('selectedOriginalPrice').value = '';
        document.getElementById('orderSelectionSection').style.display = 'none';
        document.getElementById('eligibleItemsList').innerHTML = '';
        document.querySelectorAll('.eligible-item-card').forEach(el => el.classList.remove('selected'));
    }

    // ✅ Redeem Functions (ไถ่ถอน)
    function openRedeemModal() {
        if (!selectedPawnData) {
            showError('กรุณาเลือกรายการจำนำก่อน');
            return;
        }

        const p = selectedPawnData;

        // คำนวณยอดไถ่ถอน
        const principal = parseFloat(p.principal_amount) || 0;
        const interest = parseFloat(p.redemption_amount) - principal || 0;
        const total = parseFloat(p.redemption_amount) || 0;

        document.getElementById('redeemPrincipal').textContent = '฿' + formatNumber(principal);
        document.getElementById('redeemInterest').textContent = '฿' + formatNumber(interest);
        document.getElementById('redeemTotal').textContent = '฿' + formatNumber(total);

        // Show pawn info
        document.getElementById('redeemInfo').innerHTML = `
            <div class="pawn-info-card">
                <div class="pawn-info-row">
                    <span class="pawn-info-label">รหัสจำนำ</span>
                    <span class="pawn-info-value">${p.pawn_no}</span>
                </div>
                <div class="pawn-info-row">
                    <span class="pawn-info-label">สินค้า</span>
                    <span class="pawn-info-value">${p.item_description || '-'}</span>
                </div>
            </div>
        `;

        console.log('[PAWNS] Opening redeem modal:', { pawn_id: selectedPawnId, principal, interest, total });

        document.getElementById('redeemModal').classList.add('active');
    }

    function closeRedeemModal() {
        document.getElementById('redeemModal').classList.remove('active');
    }

    function payFromDetail() {
        if (!selectedPawnId || !selectedPawnData) {
            showError('ไม่พบข้อมูลจำนำ');
            return;
        }

        const p = selectedPawnData;
        const amount = p.monthly_interest || 0;

        // Go directly to payment-history with all auto-complete params
        const params = new URLSearchParams({
            action: 'create',
            type: 'deposit_interest',
            pawn_id: selectedPawnId,
            pawn_no: p.pawn_no || '',
            amount: amount,
            description: `ต่อดอกจำนำ ${p.pawn_no || ''} (1 เดือน)`,
            customer_profile_id: p.customer_profile_id || ''
        });

        window.location.href = '/payment-history.php?' + params.toString();
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
                // ✅ เปลี่ยนเป็นเรียก customer-profiles.php แทน (ค้นหาลูกค้าแชท LINE/FB)
                const response = await fetch(`/api/customer-profiles.php?search=${encodeURIComponent(query)}&limit=10`, {
                    headers: { 'Authorization': 'Bearer ' + getToken() }
                });
                const data = await response.json();

                if (data.success && data.data && data.data.length > 0) {
                    resultsDiv.innerHTML = data.data.map(c => `
                    <div class="autocomplete-item" onclick='selectCustomer(${JSON.stringify(c).replace(/'/g, "\\'")})'>
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

        // ✅ Hybrid A+: Load eligible items for this customer
        loadEligibleItems(customer.id);
    }

    function clearSelectedCustomer() {
        document.getElementById('selectedCustomerId').value = '';
        document.getElementById('selectedCustomerCard').style.display = 'none';
        document.getElementById('customerSearch').value = '';
        // ✅ Also clear eligible items
        clearSelectedOrder();
    }

    async function submitCreatePawn() {
        const customerId = document.getElementById('selectedCustomerId').value;
        const itemType = document.getElementById('itemType').value;
        const itemName = document.getElementById('itemName').value;
        const itemDescription = document.getElementById('itemDescription').value;
        const warrantyNo = document.getElementById('warrantyNo').value;
        const productRefId = document.getElementById('productRefId').value;
        const loanAmount = document.getElementById('loanAmount').value;
        const interestRate = document.getElementById('interestRate').value;
        const periodMonths = document.getElementById('pawnPeriod').value;
        const dueDate = document.getElementById('dueDate').value;
        const notes = document.getElementById('pawnNotes').value;

        // ✅ Push message fields
        const bankAccount = document.getElementById('pawnBankAccount').value;
        const customerMessage = document.getElementById('pawnCustomerMessage').value;
        const sendMessage = document.getElementById('sendPawnMessageCheckbox').checked;

        // ✅ Enhanced Validation with clear error messages
        const errors = [];

        if (!customerId) {
            errors.push('❌ กรุณาเลือกลูกค้า');
            highlightField('customerSearch', true);
        } else {
            highlightField('customerSearch', false);
        }

        // ✅ Hybrid A+: Check if order is selected (preferred) or manual entry
        const orderId = document.getElementById('selectedOrderId').value;
        const originalPrice = document.getElementById('selectedOriginalPrice').value;

        // If no order selected, require manual fields
        if (!orderId) {
            if (!itemType) {
                errors.push('❌ กรุณาเลือกประเภทสินค้า');
                highlightField('itemType', true);
            } else {
                highlightField('itemType', false);
            }

            if (!itemName) {
                errors.push('❌ กรุณาระบุชื่อสินค้า');
                highlightField('itemName', true);
            } else {
                highlightField('itemName', false);
            }

            if (!loanAmount || parseFloat(loanAmount) <= 0) {
                errors.push('❌ กรุณาระบุเงินต้น (ยอดเงินกู้)');
                highlightField('loanAmount', true);
            } else {
                highlightField('loanAmount', false);
            }
        }

        // Show all errors at once
        if (errors.length > 0) {
            showError('กรุณากรอกข้อมูลให้ครบถ้วน:\n\n' + errors.join('\n'));
            return;
        }

        try {
            // ✅ Use different endpoint based on order selection
            const endpoint = orderId ? '/api/customer/pawns?action=create' : '/api/admin/pawns.php';

            const payload = orderId ? {
                // Hybrid A+ - Create from order
                order_id: orderId,
                customer_id: customerId,
                appraised_value: originalPrice,
                loan_percentage: 65,
                interest_rate: interestRate || 2,
                item_description: itemDescription || notes,
                // ✅ Push message fields
                bank_account: bankAccount,
                customer_message: customerMessage,
                send_message: sendMessage
            } : {
                // Traditional - Manual entry
                customer_id: customerId,
                item_type: itemType,
                item_name: itemName,
                item_description: itemDescription,
                warranty_no: warrantyNo,
                product_ref_id: productRefId,
                loan_amount: loanAmount,
                interest_rate: interestRate || 2,
                period_months: periodMonths,
                due_date: dueDate,
                notes: notes,
                // ✅ Push message fields
                bank_account: bankAccount,
                customer_message: customerMessage,
                send_message: sendMessage
            };

            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + getToken()
                },
                body: JSON.stringify(payload)
            });

            // ✅ Debug: Log response status
            console.log('[PAWNS] Response status:', response.status);

            const data = await response.json();
            console.log('[PAWNS] Response data:', data);

            if (data.success) {
                let successMsg = `บันทึกรายการจำนำสำเร็จ\nรหัส: ${data.data.pawn_no}\nครบกำหนด: ${data.data.due_date}`;
                if (data.message_sent) {
                    successMsg += '\n✅ ส่งข้อความแจ้งลูกค้าแล้ว';
                }
                showSuccess(successMsg);
                closeCreateModal();
                loadPawns(1);
            } else {
                // ✅ Show detailed error message
                const errorMsg = data.message || 'เกิดข้อผิดพลาด';
                console.error('[PAWNS] API Error:', errorMsg);
                showError(errorMsg);
            }
        } catch (error) {
            console.error('[PAWNS] Error:', error);
            showError('เกิดข้อผิดพลาดในการบันทึก: ' + error.message);
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