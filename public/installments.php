<?php
/**
 * Installments - Customer Portal
 * ระบบผ่อนชำระสำหรับลูกค้า
 */
define('INCLUDE_CHECK', true);

$page_title = "ผ่อนชำระ - AI Automation";
$current_page = "installments";

include('../includes/customer/header.php');
include('../includes/customer/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <div class="page-header-content">
            <div>
                <h1 class="page-title">📅 ผ่อนชำระ</h1>
                <p class="page-subtitle">จัดการแผนผ่อนชำระและติดตามการชำระเงิน</p>
            </div>
            <div class="page-header-actions">
                <button class="btn btn-outline" onclick="sendAllReminders()" id="btnSendReminders">
                    <i class="fas fa-bell"></i> <span class="btn-text">ส่งแจ้งเตือนทั้งหมด</span>
                </button>
                <button class="btn btn-primary" onclick="openCreateInstallmentModal()">
                    <i class="fas fa-plus"></i> <span class="btn-text">สร้างแผนผ่อนใหม่</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-grid">
        <div class="summary-card summary-card-success">
            <div class="summary-icon">💵</div>
            <div class="summary-value" id="totalPaid">฿0.00</div>
            <div class="summary-label">ชำระแล้ว</div>
        </div>
        <div class="summary-card summary-card-warning">
            <div class="summary-icon">📊</div>
            <div class="summary-value" id="totalRemaining">฿0.00</div>
            <div class="summary-label">คงเหลือ</div>
        </div>
        <div class="summary-card">
            <div class="summary-icon">📋</div>
            <div class="summary-value" id="activePlans">0</div>
            <div class="summary-label">แผนที่ใช้งาน</div>
        </div>
        <div class="summary-card summary-card-danger">
            <div class="summary-icon">⏰</div>
            <div class="summary-value" id="overdueCount">0</div>
            <div class="summary-label">เลยกำหนด</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">รายการแผนผ่อนชำระ</h3>
        </div>
        <div class="card-body">
            <!-- Desktop Table -->
            <div class="table-container desktop-only">
                <table>
                    <thead>
                        <tr>
                            <th>ลูกค้า / สินค้า</th>
                            <th style="text-align:right;">ยอดรวม</th>
                            <th style="text-align:right;">ชำระแล้ว</th>
                            <th>ความคืบหน้า</th>
                            <th>สถานะ</th>
                            <th>ครบกำหนด</th>
                            <th>วันที่สร้าง</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="installmentsTableBody">
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
            <div class="mobile-cards mobile-only" id="installmentsMobileCards">
                <div class="loading-placeholder">
                    <div class="spinner"></div>
                    <p>กำลังโหลดข้อมูล...</p>
                </div>
            </div>

            <!-- Pagination -->
            <div id="installmentsPagination" class="pagination-container"></div>
        </div>
    </div>
</main>

<style>
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

    /* Page Header */
    .page-header-content {
        display: flex;
        justify-content: space-between;
        align-items: center;
        width: 100%;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .page-header-actions {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
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
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        transition: transform 0.2s, box-shadow 0.2s;
    }

    .summary-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
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

    .summary-card-danger .summary-value {
        color: var(--color-danger);
    }

    .summary-card-info .summary-value {
        color: var(--color-info);
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

    /* Progress Bar */
    .progress-bar-container {
        background: var(--color-light-2);
        border-radius: 8px;
        height: 8px;
        overflow: hidden;
        margin-top: 0.5rem;
    }

    .progress-bar-fill {
        height: 100%;
        background: linear-gradient(90deg, var(--color-primary), var(--color-success));
        border-radius: 8px;
        transition: width 0.3s ease;
    }

    /* Loading */
    .loading-placeholder {
        text-align: center;
        padding: 2rem;
        color: var(--color-gray);
    }

    /* Desktop Only / Mobile Only */
    .desktop-only {
        display: block;
    }

    .mobile-only {
        display: none;
    }

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

        .desktop-only {
            display: none !important;
        }

        .mobile-only {
            display: block !important;
        }

        .btn-text {
            display: inline;
        }
    }

    @media (max-width: 480px) {
        .summary-value {
            font-size: 1.1rem;
        }

        .summary-label {
            font-size: 0.75rem;
        }
    }

    /* ✅ Calculator Styles - ร้าน ฮ.เฮง เฮง */
    .installment-calculator {
        background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
        border: 1px solid #bae6fd;
        border-radius: 12px;
        padding: 1rem;
        margin: 1rem 0;
    }

    .installment-calculator .calc-header {
        color: #0369a1;
        margin-bottom: 0.75rem;
    }

    .installment-calculator .calc-row {
        display: flex;
        justify-content: space-between;
        padding: 0.4rem 0;
        font-size: 0.9rem;
    }

    .installment-calculator .calc-row.total {
        font-weight: 700;
        font-size: 1rem;
        color: #0c4a6e;
        border-top: 1px solid #7dd3fc;
        padding-top: 0.75rem;
        margin-top: 0.5rem;
    }

    .installment-calculator .calc-schedule {
        background: white;
        border-radius: 8px;
        padding: 0.75rem;
    }

    .installment-calculator .schedule-item {
        display: flex;
        justify-content: space-between;
        padding: 0.4rem 0;
        font-size: 0.85rem;
    }

    .installment-calculator .schedule-item strong {
        color: #0369a1;
    }
</style>

<!-- Create Installment Modal - ตามนโยบายร้าน ฮ.เฮง เฮง -->
<div id="createInstallmentModal" class="modal-backdrop hidden">
    <div class="modal-content">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-calendar-alt"></i> สร้างแผนผ่อนชำระ (3 งวด)</h3>
                <button class="modal-close-btn" onclick="closeCreateInstallmentModal()"><i
                        class="fas fa-times"></i></button>
            </div>
            <div class="card-body">
                <form id="createInstallmentForm">
                    <div class="form-group">
                        <label class="form-label">ชื่อแผน/สินค้า <span style="color: red;">*</span></label>
                        <input type="text" id="installmentName" class="form-control" required
                            placeholder="เช่น Rolex Submariner">
                    </div>
                    <div class="form-group">
                        <label class="form-label">ราคาสินค้า (บาท) <span style="color: red;">*</span></label>
                        <input type="number" id="installmentTotal" class="form-control" required placeholder="30000"
                            min="1" step="1" oninput="calculateInstallmentPreview()">
                    </div>

                    <!-- ✅ Calculator Preview - ตามสูตรร้าน ฮ.เฮง เฮง -->
                    <div id="installmentPreview" class="installment-calculator" style="display:none;">
                        <div class="calc-header">
                            <strong>📊 คำนวณยอดผ่อน (3 งวด ภายใน 60 วัน)</strong>
                        </div>
                        <div class="calc-row">
                            <span>ราคาสินค้า</span>
                            <span id="previewPrice">฿0</span>
                        </div>
                        <div class="calc-row">
                            <span>ค่าธรรมเนียม (+3%)</span>
                            <span id="previewFee">฿0</span>
                        </div>
                        <div class="calc-row total">
                            <span>ยอดรวมทั้งหมด</span>
                            <span id="previewTotal">฿0</span>
                        </div>
                        <hr style="margin: 0.75rem 0; border-color: #e5e7eb;">
                        <div class="calc-schedule">
                            <div class="schedule-item">
                                <span>งวด 1 (วันนี้)</span>
                                <strong id="previewPeriod1">฿0</strong>
                            </div>
                            <div class="schedule-item">
                                <span>งวด 2 (+30 วัน)</span>
                                <strong id="previewPeriod2">฿0</strong>
                            </div>
                            <div class="schedule-item">
                                <span>งวด 3 (+60 วัน) → รับของ</span>
                                <strong id="previewPeriod3">฿0</strong>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">หมายเหตุ</label>
                        <textarea id="installmentNote" class="form-control" rows="2"
                            placeholder="หมายเหตุเพิ่มเติม"></textarea>
                    </div>

                    <div id="createInstallmentError" class="alert alert-danger" style="display: none;"></div>
                    <div id="createInstallmentSuccess" class="alert alert-success" style="display: none;"></div>
                    <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                        <button type="button" class="btn btn-primary" style="flex: 1;"
                            onclick="submitCreateInstallment()">
                            <i class="fas fa-save"></i> สร้างแผนผ่อน
                        </button>
                        <button type="button" class="btn btn-outline" style="flex: 1;"
                            onclick="closeCreateInstallmentModal()">
                            <i class="fas fa-times"></i> ยกเลิก
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Pay Installment Modal removed - use chatbot flow instead -->

<style>
    .spinner {
        width: 48px;
        height: 48px;
        border: 4px solid var(--color-border);
        border-top-color: var(--color-primary);
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
    }

    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }

    .badge-active {
        background: #dcfce7;
        color: #16a34a;
    }

    .badge-completed {
        background: #dbeafe;
        color: #2563eb;
    }

    .badge-overdue {
        background: #fee2e2;
        color: #dc2626;
    }

    .badge-paused {
        background: #fef3c7;
        color: #d97706;
    }

    /* Large Modal - Extra Wide */
    .modal-lg {
        max-width: 1200px;
        width: 95%;
    }

    /* Installment Detail Styles */
    .installment-detail-header {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.5rem;
        margin-bottom: 2rem;
        padding-bottom: 1.5rem;
        border-bottom: 1px solid var(--color-border);
    }

    .installment-info-card {
        background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
        color: white;
        border-radius: 16px;
        padding: 1.5rem;
    }

    .installment-info-card h4 {
        font-size: 1.1rem;
        margin-bottom: 0.5rem;
        opacity: 0.9;
    }

    .installment-info-card .amount-big {
        font-size: 2rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }

    .installment-info-card .sub-info {
        font-size: 0.9rem;
        opacity: 0.9;
    }

    .installment-info-card .progress-row {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-top: 1rem;
    }

    .installment-info-card .progress-bar-container {
        flex: 1;
        background: rgba(255, 255, 255, 0.3);
        height: 10px;
    }

    .installment-info-card .progress-bar-fill {
        background: white;
    }

    .installment-stats {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }

    .stat-item {
        background: var(--color-light);
        border-radius: 12px;
        padding: 1rem;
        text-align: center;
    }

    .stat-item .stat-value {
        font-size: 1.25rem;
        font-weight: 700;
        color: var(--color-dark);
    }

    .stat-item .stat-label {
        font-size: 0.8rem;
        color: var(--color-gray);
        margin-top: 0.25rem;
    }

    .stat-item.pending .stat-value {
        color: #f59e0b;
    }

    .stat-item.success .stat-value {
        color: #10b981;
    }

    .stat-item.danger .stat-value {
        color: #ef4444;
    }

    /* ===== NEW: Payment Summary Section ===== */
    .payment-summary-section {
        display: grid;
        grid-template-columns: 1.2fr 1fr;
        gap: 1.5rem;
        margin-bottom: 1.5rem;
        padding: 1.25rem;
        background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
        border: 1px solid #7dd3fc;
        border-radius: 16px;
    }

    .summary-main {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }

    .amount-display {
        display: flex;
        align-items: baseline;
        gap: 0.5rem;
    }

    .amount-paid {
        font-size: 2rem;
        font-weight: 800;
        color: #0369a1;
    }

    .amount-total {
        font-size: 1rem;
        color: #64748b;
        font-weight: 500;
    }

    .installment-formula {
        font-size: 0.9rem;
        color: #64748b;
    }

    .summary-main .progress-row {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .summary-main .progress-bar-container {
        flex: 1;
        background: rgba(0, 0, 0, 0.1);
        height: 8px;
        border-radius: 4px;
        overflow: hidden;
    }

    .summary-main .progress-bar-fill {
        height: 100%;
        background: linear-gradient(90deg, #0ea5e9, #0284c7);
        border-radius: 4px;
    }

    .progress-percent {
        font-weight: 600;
        color: #0369a1;
        font-size: 0.9rem;
    }

    .summary-stats {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
    }

    .summary-stats .stat-item {
        background: white;
        border-radius: 10px;
        padding: 0.75rem;
        text-align: center;
        border: 1px solid #e0f2fe;
    }

    .summary-stats .stat-value {
        font-size: 1.1rem;
        font-weight: 700;
        color: #1e293b;
    }

    .summary-stats .stat-label {
        font-size: 0.7rem;
        color: #64748b;
        margin-top: 0.15rem;
    }

    @media (max-width: 768px) {
        .payment-summary-section {
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .amount-paid {
            font-size: 1.75rem;
        }
    }

    /* Payment History Table */
    .payment-section h4 {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 1rem;
        color: var(--color-dark);
    }

    .payment-table {
        width: 100%;
        border-collapse: collapse;
    }

    .payment-table th,
    .payment-table td {
        padding: 0.75rem 1rem;
        text-align: left;
        border-bottom: 1px solid var(--color-border);
    }

    .payment-table th {
        background: var(--color-light);
        font-weight: 600;
        font-size: 0.85rem;
        color: var(--color-gray);
    }

    .payment-table tr:hover {
        background: var(--color-light);
    }

    .payment-table .amount {
        font-weight: 600;
        color: #10b981;
    }

    /* Payment Status Badges */
    .pay-status {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 500;
    }

    .pay-status.pending {
        background: #fef3c7;
        color: #b45309;
    }

    .pay-status.paid,
    .pay-status.verified {
        background: #d1fae5;
        color: #047857;
    }

    .pay-status.rejected {
        background: #fee2e2;
        color: #b91c1c;
    }

    .pay-status.pending_verification {
        background: #dbeafe;
        color: #1d4ed8;
    }

    /* Mobile Payment Cards */
    .pay-mobile-cards {
        display: none;
    }

    .pay-mobile-card {
        background: white;
        border: 1px solid var(--color-border);
        border-radius: 12px;
        padding: 1rem;
        margin-bottom: 0.75rem;
    }

    .pay-mobile-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 0.75rem;
    }

    .pay-mobile-term {
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--color-dark);
    }

    .pay-mobile-amount {
        font-size: 1.25rem;
        font-weight: 700;
        color: #10b981;
    }

    .pay-mobile-meta {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.5rem;
        font-size: 0.85rem;
    }

    .pay-mobile-meta span {
        color: var(--color-gray);
    }

    .pay-mobile-meta strong {
        color: var(--color-dark);
    }

    /* Empty State */
    .empty-payments {
        text-align: center;
        padding: 2rem;
        color: var(--color-gray);
    }

    .empty-payments i {
        font-size: 3rem;
        opacity: 0.3;
        margin-bottom: 1rem;
    }

    /* Contract Reference Banner */
    .contract-ref-banner {
        display: flex;
        align-items: center;
        gap: 1rem;
        background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
        border: 1px solid #fbbf24;
        border-radius: 12px;
        padding: 1rem 1.5rem;
        margin-bottom: 1.5rem;
    }

    /* ===== NEW: Contract Header Section with Product Image ===== */
    .contract-header-section {
        display: flex;
        gap: 1.5rem;
        background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
        align-items: flex-start;
    }

    .product-image-section {
        flex-shrink: 0;
    }

    .product-detail-img {
        width: 120px;
        height: 120px;
        object-fit: cover;
        border-radius: 12px;
        border: 2px solid #e2e8f0;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .contract-main-info {
        flex: 1;
        min-width: 0;
    }

    .product-title {
        font-size: 1.25rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0 0 0.5rem 0;
        line-height: 1.3;
    }

    .product-code {
        font-size: 0.85rem;
        color: #64748b;
        margin-bottom: 1rem;
    }

    .product-code i {
        margin-right: 0.35rem;
        opacity: 0.7;
    }

    .contract-refs {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
    }

    .contract-refs .ref-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        background: white;
        padding: 0.5rem 0.75rem;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
    }

    .contract-refs .ref-label {
        font-size: 0.75rem;
        color: #64748b;
        white-space: nowrap;
    }

    .contract-refs .ref-code {
        font-family: 'Consolas', 'Monaco', monospace;
        font-weight: 600;
        font-size: 0.85rem;
        color: #334155;
        background: #f1f5f9;
        padding: 0.15rem 0.5rem;
        border-radius: 4px;
    }

    @media (max-width: 576px) {
        .contract-header-section {
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .product-detail-img {
            width: 100px;
            height: 100px;
        }

        .contract-refs {
            justify-content: center;
        }
    }

    .contract-ref-banner .ref-icon {
        font-size: 2rem;
    }

    .contract-ref-banner .ref-info {
        flex: 0 0 auto;
    }

    .contract-ref-banner .ref-label {
        font-size: 0.75rem;
        color: #92400e;
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 0.5px;
    }

    .contract-ref-banner .ref-code-lg {
        font-size: 1.1rem;
        font-weight: 700;
        color: #78350f;
        font-family: 'Consolas', 'Monaco', monospace;
        background: white;
        padding: 0.25rem 0.75rem;
        border-radius: 6px;
        border: 1px solid #f59e0b;
    }

    .contract-ref-banner .ref-note {
        flex: 1;
        font-size: 0.85rem;
        color: #92400e;
        background: rgba(255, 255, 255, 0.6);
        padding: 0.75rem 1rem;
        border-radius: 8px;
        line-height: 1.4;
    }

    .contract-ref-banner .ref-note i {
        margin-right: 0.5rem;
    }

    /* How to Pay Banner */
    .how-to-pay-banner {
        background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
        border: 1px solid #10b981;
        border-radius: 12px;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
    }

    .how-to-pay-banner h5 {
        margin: 0 0 1rem 0;
        color: #065f46;
        font-size: 1rem;
    }

    .how-to-pay-banner h5 i {
        color: #f59e0b;
        margin-right: 0.5rem;
    }

    .pay-steps {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
        margin-bottom: 1rem;
    }

    .pay-step {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-size: 0.9rem;
        color: #065f46;
    }

    .pay-step .step-num {
        width: 24px;
        height: 24px;
        background: #10b981;
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
        font-weight: 600;
        flex-shrink: 0;
    }

    .pay-step strong {
        color: #047857;
    }

    .how-to-pay-banner .pay-note {
        font-size: 0.8rem;
        color: #047857;
        background: rgba(255, 255, 255, 0.5);
        padding: 0.5rem 0.75rem;
        border-radius: 6px;
    }

    .how-to-pay-banner .pay-note i {
        margin-right: 0.5rem;
    }

    .how-to-pay-banner .pay-note a {
        color: #065f46;
        font-weight: 600;
        text-decoration: underline;
    }

    /* Payment Reference Code */
    .ref-code {
        font-family: 'Consolas', 'Monaco', monospace;
        font-size: 0.75rem;
        background: #f1f5f9;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        color: #334155;
        word-break: break-all;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .installment-detail-header {
            grid-template-columns: 1fr;
        }

        .installment-info-card .amount-big {
            font-size: 1.5rem;
        }

        .payment-table-container {
            display: none;
        }

        .pay-mobile-cards {
            display: block;
        }

        .modal-lg {
            max-width: 100%;
            margin: 0.5rem;
        }

        /* Contract Reference Banner Mobile */
        .contract-ref-banner {
            flex-direction: column;
            text-align: center;
            padding: 1rem;
        }

        .contract-ref-banner .ref-note {
            font-size: 0.8rem;
        }
    }
</style>

<!-- Installment Detail Modal -->
<div id="installmentDetailModal" class="modal-backdrop hidden">
    <div class="modal-content modal-lg">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-calendar-alt"></i> รายละเอียดแผนผ่อนชำระ</h3>
                <button class="modal-close-btn" onclick="closeInstallmentDetailModal()"><i
                        class="fas fa-times"></i></button>
            </div>
            <div class="card-body" id="installmentDetailBody">
                <div class="loading-placeholder">
                    <div class="spinner"></div>
                    <p>กำลังโหลดข้อมูล...</p>
                </div>
            </div>
            <div class="card-footer" style="display: flex; gap: 1rem; justify-content: flex-end;">
                <a href="payment-history.php" id="viewSlipHistoryBtn" class="btn btn-primary">
                    <i class="fas fa-receipt"></i> ดูประวัติสลิป
                </a>
                <button class="btn btn-outline" onclick="closeInstallmentDetailModal()">
                    <i class="fas fa-times"></i> ปิด
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    let installmentsData = [];
    let currentPage = 1;
    let totalPages = 1;
    const ITEMS_PER_PAGE = 20;

    /**
     * ส่งแจ้งเตือนการชำระงวดทั้งหมด
     * - งวดที่ใกล้ครบกำหนด (3 วัน, 1 วัน)
     * - งวดที่ครบกำหนดวันนี้
     * - งวดที่เลยกำหนด (1, 3, 7, 14 วัน)
     */
    async function sendAllReminders() {
        const btn = document.getElementById('btnSendReminders');
        const originalHTML = btn.innerHTML;

        if (!confirm('ส่งแจ้งเตือนการชำระงวดไปยังลูกค้าทั้งหมดที่ครบกำหนด/เลยกำหนด?')) {
            return;
        }

        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังส่ง...';
        btn.disabled = true;

        try {
            const res = await apiCall('/api/cron/installment-reminders.php', {
                method: 'POST'
            });

            if (res.success) {
                const data = res.data || {};
                const sent = data.reminders_sent || 0;
                const processed = data.processed || 0;
                const errors = data.errors || 0;

                let message = `✅ ส่งแจ้งเตือนเรียบร้อย!\n\n`;
                message += `📊 ประมวลผล: ${processed} รายการ\n`;
                message += `📤 ส่งสำเร็จ: ${sent} รายการ\n`;
                if (errors > 0) {
                    message += `❌ ผิดพลาด: ${errors} รายการ`;
                }

                alert(message);
                showToast(`ส่งแจ้งเตือน ${sent} รายการ`, 'success');

                // Show details if any
                if (data.details && data.details.length > 0) {
                    console.log('[Reminders] Details:', data.details);
                }
            } else {
                showToast('❌ ' + (res.message || 'เกิดข้อผิดพลาด'), 'error');
            }
        } catch (e) {
            console.error('Error sending reminders:', e);
            showToast('❌ เกิดข้อผิดพลาดในการส่งแจ้งเตือน', 'error');
        } finally {
            btn.innerHTML = originalHTML;
            btn.disabled = false;
        }
    }

    /**
     * ส่งแจ้งเตือนสำหรับ contract เดียว
     */
    async function sendSingleReminder(contractId) {
        if (!confirm('ส่งแจ้งเตือนให้ลูกค้ารายนี้?')) {
            return;
        }

        try {
            const res = await apiCall(`/api/cron/installment-reminders.php?contract_id=${contractId}`, {
                method: 'POST'
            });

            if (res.success && res.data && res.data.details && res.data.details[0] && res.data.details[0].sent) {
                showToast('✅ ส่งแจ้งเตือนเรียบร้อย', 'success');
            } else if (res.data && res.data.details && res.data.details[0]) {
                showToast('⚠️ ' + (res.data.details[0].message || 'ไม่ได้ส่ง'), 'warning');
            } else {
                showToast('❌ ' + (res.message || 'เกิดข้อผิดพลาด'), 'error');
            }
        } catch (e) {
            console.error('Error sending reminder:', e);
            showToast('❌ เกิดข้อผิดพลาด', 'error');
        }
    }

    async function loadInstallments(page = 1) {
        currentPage = page;
        const tbody = document.getElementById('installmentsTableBody');
        const mobileContainer = document.getElementById('installmentsMobileCards');

        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:2rem;"><div class="spinner" style="margin:0 auto 1rem;"></div>กำลังโหลดข้อมูล...</td></tr>';
        mobileContainer.innerHTML = '<div class="loading-placeholder"><div class="spinner"></div><p>กำลังโหลดข้อมูล...</p></div>';

        try {
            const res = await apiCall(API_ENDPOINTS.CUSTOMER_INSTALLMENTS + `?page=${currentPage}&limit=${ITEMS_PER_PAGE}`);

            // API returns { success: true, data: [...], summary: {...}, pagination: {...} }
            if (!res.success || !res.data) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--color-gray);">ยังไม่มีแผนผ่อนชำระ</td></tr>';
                mobileContainer.innerHTML = '<div class="empty-state"><p>ยังไม่มีแผนผ่อนชำระ</p></div>';
                updateSummaryFromAPI({ total_paid: 0, total_remaining: 0, active_count: 0, overdue_count: 0 });
                renderPagination(0, 0);
                return;
            }

            installmentsData = Array.isArray(res.data) ? res.data : [];
            const summary = res.summary || { total_paid: 0, total_remaining: 0, active_count: 0, overdue_count: 0 };
            const pagination = res.pagination || {};
            totalPages = pagination.total_pages || 1;

            updateSummaryFromAPI(summary);
            renderPagination(pagination.total || installmentsData.length, totalPages);

            if (installmentsData.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--color-gray);">ยังไม่มีแผนผ่อนชำระ</td></tr>';
                mobileContainer.innerHTML = '<div class="empty-state"><p>ยังไม่มีแผนผ่อนชำระ</p></div>';
                return;
            }

            // Desktop Table - use API field names
            tbody.innerHTML = installmentsData.map(i => {
                const displayName = i.name || i.product_name || 'ผ่อนชำระ #' + i.id;
                const totalAmount = parseFloat(i.financed_amount || i.total_amount) || 0;
                const paidAmount = parseFloat(i.paid_amount) || 0;
                const paidPeriods = parseInt(i.paid_periods) || 0;
                const totalPeriods = parseInt(i.total_periods) || 3;
                const progress = totalAmount > 0 ? Math.min((paidAmount / totalAmount * 100), 100) : 0;
                const progressColor = progress >= 100 ? '#22c55e' : (progress > 0 ? '#f59e0b' : '#e5e7eb');

                // Customer profile badge
                const customerProfile = {
                    platform: i.customer_platform || i.platform || 'web',
                    name: i.customer_name || 'ไม่ระบุลูกค้า',
                    avatar: i.customer_avatar || null
                };
                const customerBadgeHtml = typeof renderCustomerProfileBadge === 'function'
                    ? renderCustomerProfileBadge(customerProfile)
                    : `<span>${customerProfile.name}</span>`;

                // Format created_at
                const createdDate = i.created_at ? formatDateTime(i.created_at) : '-';

                const remaining = Math.max(0, totalAmount - paidAmount);
                const progressPercent = Math.round(progress);

                return `
                <tr style="cursor: pointer;" onclick="openInstallmentDetail(${i.id})">
                    <td>
                        <div style="display:flex;flex-direction:column;gap:0.25rem;">
                            ${customerBadgeHtml}
                            <small style="color:#6b7280;">${escapeHtml(displayName)}</small>
                        </div>
                    </td>
                    <td style="text-align:right;">฿${formatNumber(totalAmount)}</td>
                    <td style="text-align:right; color:${paidAmount > 0 ? '#22c55e' : '#9ca3af'};">฿${formatNumber(paidAmount)}</td>
                    <td>
                        <div style="display:flex;flex-direction:column;gap:0.35rem;min-width:120px;">
                            <div style="display:flex;justify-content:space-between;font-size:0.75rem;">
                                <span style="color:#6b7280;">ชำระแล้ว ${progressPercent}%</span>
                                <span style="color:#6b7280;">${paidPeriods}/${totalPeriods} งวด</span>
                            </div>
                            <div style="height:8px;background:#e5e7eb;border-radius:4px;overflow:hidden;">
                                <div style="height:100%;width:${progress}%;background:${progressColor};border-radius:4px;transition:width 0.3s;"></div>
                            </div>
                            ${remaining > 0 ? `<div style="font-size:0.75rem;color:#ef4444;">คงเหลือ ฿${formatNumber(remaining)}</div>` : `<div style="font-size:0.75rem;color:#22c55e;">✓ ครบแล้ว</div>`}
                        </div>
                    </td>
                    <td><span class="badge badge-${getStatusBadge(i.status)}">${getStatusLabel(i.status)}</span></td>
                    <td style="font-size:0.9rem;">${i.next_due_date ? formatDate(i.next_due_date) : '-'}</td>
                    <td style="font-size:0.85rem;color:#6b7280;">${createdDate}</td>
                    <td>
                        <div style="display:flex;gap:0.25rem;">
                            <button class="btn btn-sm btn-outline" onclick="event.stopPropagation(); openInstallmentDetail(${i.id})" title="ดูรายละเอียด">
                                <i class="fas fa-eye"></i>
                            </button>
                            ${i.status === 'active' || i.status === 'overdue' ? `
                            <button class="btn btn-sm btn-outline" onclick="event.stopPropagation(); sendSingleReminder(${i.id})" title="ส่งแจ้งเตือน">
                                <i class="fas fa-bell"></i>
                            </button>
                            ` : ''}
                        </div>
                    </td>
                </tr>
            `;
            }).join('');

            // Mobile Cards
            mobileContainer.innerHTML = installmentsData.map(i => {
                const displayName = i.name || i.product_name || 'ผ่อนชำระ #' + i.id;
                const totalAmount = parseFloat(i.financed_amount || i.total_amount) || 0;
                const paidAmount = parseFloat(i.paid_amount) || 0;

                // ✅ FIX: Calculate correct per-period amount (งวดแรก + 3% ค่าดำเนินการ)
                const productPrice = parseFloat(i.product_price || i.total_amount) || totalAmount;
                const totalPeriods = parseInt(i.total_periods) || 3;
                const serviceFee = Math.round(productPrice * 0.03);
                const basePerPeriod = Math.floor(productPrice / totalPeriods);
                const perTermP1 = basePerPeriod + serviceFee; // งวดแรก

                const paidPeriods = parseInt(i.paid_periods) || 0;
                const progress = totalAmount > 0 ? Math.min((paidAmount / totalAmount * 100), 100) : 0;
                return `
                <div class="mobile-card">
                    <div class="mobile-card-header">
                        <div class="mobile-card-title">${escapeHtml(displayName)}</div>
                        <span class="badge badge-${getStatusBadge(i.status)}">${getStatusLabel(i.status)}</span>
                    </div>
                    <div class="mobile-card-row">
                        <span class="mobile-card-label">ยอดรวม</span>
                        <span class="mobile-card-value">฿${formatNumber(totalAmount)}</span>
                    </div>
                    <div class="mobile-card-row">
                        <span class="mobile-card-label">ชำระแล้ว</span>
                        <span class="mobile-card-value" style="color: var(--color-success);">฿${formatNumber(paidAmount)}</span>
                    </div>
                    <div class="mobile-card-row">
                        <span class="mobile-card-label">งวดแรก</span>
                        <span class="mobile-card-value">฿${formatNumber(perTermP1)} <small>(+3%)</small></span>
                    </div>
                    <div class="mobile-card-row">
                        <span class="mobile-card-label">งวด</span>
                        <span class="mobile-card-value">${paidPeriods}/${totalPeriods}</span>
                    </div>
                    <div class="progress-bar-container">
                        <div class="progress-bar-fill" style="width: ${progress}%;"></div>
                    </div>
                    <div style="text-align: center; font-size: 0.8rem; color: var(--color-gray); margin-top: 0.5rem;">
                        ${progress.toFixed(0)}% ครบกำหนดถัดไป: ${i.next_due_date ? formatDate(i.next_due_date) : '-'}
                    </div>
                    <div class="mobile-card-actions">
                        <button class="btn btn-sm btn-primary" onclick="openInstallmentDetail(${i.id})" style="width: 100%;">
                            <i class="fas fa-eye"></i> ดูรายละเอียด
                        </button>
                    </div>
                </div>
            `;
            }).join('');
        } catch (e) {
            console.error('loadInstallments error:', e);
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:red;">เกิดข้อผิดพลาดในการโหลดข้อมูล</td></tr>';
            mobileContainer.innerHTML = '<div class="empty-state" style="color: red;"><p>เกิดข้อผิดพลาดในการโหลดข้อมูล</p></div>';
        }
    }

    function getStatusBadge(status) {
        const badges = { active: 'primary', completed: 'success', overdue: 'danger', paused: 'secondary', pending_approval: 'warning', cancelled: 'secondary', defaulted: 'danger' };
        return badges[status] || 'primary';
    }

    // Update summary from API response
    function updateSummaryFromAPI(summary) {
        document.getElementById('totalPaid').textContent = '฿' + formatNumber(summary.total_paid || 0);
        document.getElementById('totalRemaining').textContent = '฿' + formatNumber(summary.total_remaining || 0);
        document.getElementById('activePlans').textContent = summary.active_count || 0;
        document.getElementById('overdueCount').textContent = summary.overdue_count || 0;
    }

    function updateSummary(data) {
        const totalPaid = data.reduce((sum, i) => sum + (parseFloat(i.paid_amount) || 0), 0);
        const totalAmount = data.reduce((sum, i) => sum + (parseFloat(i.financed_amount || i.total_amount) || 0), 0);
        const remaining = totalAmount - totalPaid;
        const activeCount = data.filter(i => ['active', 'overdue'].includes(i.status)).length;
        const overdueCount = data.filter(i => i.status === 'overdue' || i.is_overdue).length;

        document.getElementById('totalPaid').textContent = '฿' + formatNumber(totalPaid);
        document.getElementById('totalRemaining').textContent = '฿' + formatNumber(remaining);
        document.getElementById('activePlans').textContent = activeCount;
        document.getElementById('overdueCount').textContent = overdueCount;
    }

    function getStatusLabel(status) {
        const labels = { active: 'กำลังผ่อน', completed: 'ครบแล้ว', overdue: 'เลยกำหนด', paused: 'หยุดชั่วคราว', pending_approval: 'รออนุมัติ', cancelled: 'ยกเลิก', defaulted: 'ผิดนัด' };
        return labels[status] || status || 'กำลังผ่อน';
    }

    function formatNumber(num) {
        return parseFloat(num || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function formatDate(dateStr) {
        if (!dateStr) return '-';
        return new Date(dateStr).toLocaleDateString('th-TH');
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function renderPagination(total, pages) {
        const container = document.getElementById('installmentsPagination');
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
        loadInstallments(page);
    }

    function openCreateInstallmentModal() {
        document.getElementById('createInstallmentForm').reset();
        document.getElementById('createInstallmentError').style.display = 'none';
        document.getElementById('createInstallmentSuccess').style.display = 'none';
        document.getElementById('installmentPreview').style.display = 'none';
        document.getElementById('createInstallmentModal').classList.remove('hidden');
    }

    function closeCreateInstallmentModal() {
        document.getElementById('createInstallmentModal').classList.add('hidden');
    }

    // ✅ Calculator - สูตรร้าน ฮ.เฮง เฮง: 3 งวด, ค่าธรรมเนียม 3%
    function calculateInstallmentPreview() {
        const priceInput = document.getElementById('installmentTotal');
        const price = parseFloat(priceInput?.value) || 0;
        const preview = document.getElementById('installmentPreview');

        if (price > 0) {
            // สูตรตามนโยบายร้าน:
            // ค่าธรรมเนียม = ราคา × 3%
            // งวดละ = ราคา / 3
            // งวด 1 = (ราคา/3) + ค่าธรรมเนียม
            // งวด 2 = (ราคา/3)
            // งวด 3 = (ราคา/3) + เศษ
            const serviceFee = Math.round(price * 0.03);
            const basePerPeriod = Math.floor(price / 3);
            const remainder = price - (basePerPeriod * 3);

            const period1 = basePerPeriod + serviceFee;
            const period2 = basePerPeriod;
            const period3 = basePerPeriod + remainder;
            const total = price + serviceFee;

            document.getElementById('previewPrice').textContent = '฿' + formatNumber(price);
            document.getElementById('previewFee').textContent = '฿' + formatNumber(serviceFee);
            document.getElementById('previewTotal').textContent = '฿' + formatNumber(total);
            document.getElementById('previewPeriod1').textContent = '฿' + formatNumber(period1);
            document.getElementById('previewPeriod2').textContent = '฿' + formatNumber(period2);
            document.getElementById('previewPeriod3').textContent = '฿' + formatNumber(period3);

            preview.style.display = 'block';

            console.log('[INSTALLMENTS] Calculator:', { price, serviceFee, basePerPeriod, period1, period2, period3, total });
        } else {
            preview.style.display = 'none';
        }
    }

    async function submitCreateInstallment() {
        const errorBox = document.getElementById('createInstallmentError');
        const successBox = document.getElementById('createInstallmentSuccess');
        errorBox.style.display = 'none';
        successBox.style.display = 'none';

        const name = document.getElementById('installmentName').value.trim();
        const productPrice = parseFloat(document.getElementById('installmentTotal').value);
        const note = document.getElementById('installmentNote').value.trim();

        // ✅ Fixed 3 งวด ตามนโยบายร้าน ฮ.เฮง เฮง
        const totalTerms = 3;

        // คำนวณค่าธรรมเนียม 3%
        const serviceFee = Math.round(productPrice * 0.03);
        const totalAmount = productPrice + serviceFee;

        if (!name || !productPrice || productPrice <= 0) {
            errorBox.textContent = 'กรุณากรอกชื่อสินค้าและราคา';
            errorBox.style.display = 'block';
            return;
        }

        console.log('[INSTALLMENTS] Creating plan:', { name, productPrice, serviceFee, totalAmount, totalTerms });

        try {
            const res = await apiCall(API_ENDPOINTS.CUSTOMER_INSTALLMENTS, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    name,
                    product_price: productPrice,
                    service_fee: serviceFee,
                    total_amount: totalAmount,
                    total_terms: totalTerms,
                    start_date: new Date().toISOString().split('T')[0],
                    note
                })
            });

            if (!res.success) {
                errorBox.textContent = res.message || 'ไม่สามารถสร้างแผนผ่อนได้';
                errorBox.style.display = 'block';
                return;
            }

            successBox.textContent = 'สร้างแผนผ่อนสำเร็จ!';
            successBox.style.display = 'block';
            loadInstallments();
            setTimeout(() => closeCreateInstallmentModal(), 1500);
        } catch (e) {
            console.error('submitCreateInstallment error:', e);
            errorBox.textContent = 'เกิดข้อผิดพลาด';
            errorBox.style.display = 'block';
        }
    }

    // Load on page ready
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof apiCall !== 'undefined') {
            loadInstallments();
        } else {
            document.addEventListener('coreJSLoaded', loadInstallments);
        }
    });

    // ========================================
    // Installment Detail Functions
    // ========================================
    let currentDetailInstallmentId = null;

    async function openInstallmentDetail(installmentId) {
        currentDetailInstallmentId = installmentId;
        document.getElementById('installmentDetailModal').classList.remove('hidden');
        document.getElementById('installmentDetailBody').innerHTML = `
        <div class="loading-placeholder">
            <div class="spinner"></div>
            <p>กำลังโหลดข้อมูล...</p>
        </div>
    `;

        try {
            const res = await apiCall(API_ENDPOINTS.CUSTOMER_INSTALLMENT_DETAIL(installmentId));

            if (!res.success || !res.data) {
                document.getElementById('installmentDetailBody').innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> ${res.message || 'ไม่พบข้อมูล'}
                </div>
            `;
                return;
            }

            renderInstallmentDetail(res.data);
        } catch (e) {
            console.error('openInstallmentDetail error:', e);
            document.getElementById('installmentDetailBody').innerHTML = `
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> เกิดข้อผิดพลาดในการโหลดข้อมูล
            </div>
        `;
        }
    }

    function closeInstallmentDetailModal() {
        document.getElementById('installmentDetailModal').classList.add('hidden');
        currentDetailInstallmentId = null;
    }

    function renderInstallmentDetail(data) {
        const contract = data.contract || data;
        const payments = data.payments || data.schedule || [];

        const displayName = contract.name || contract.product_name || 'ผ่อนชำระ #' + contract.id;
        const contractNo = contract.contract_no || 'INS-' + contract.id;
        const orderNo = contract.order_number || null;
        const productCode = contract.product_code || contract.product_ref_id || null;
        const productImage = contract.product_image_url || null;
        const totalAmount = parseFloat(contract.financed_amount || contract.total_amount) || 0;
        const paidAmount = parseFloat(contract.paid_amount) || 0;
        const pendingAmount = parseFloat(contract.pending_amount) || 0;

        // ✅ FIX: Calculate correct per-period amounts (งวดแรก + 3% ค่าดำเนินการ)
        const productPrice = parseFloat(contract.product_price || contract.total_amount) || totalAmount;
        const totalPeriods = parseInt(contract.total_periods) || 3;
        const serviceFee = Math.round(productPrice * 0.03);
        const basePerPeriod = Math.floor(productPrice / totalPeriods);
        const remainder = productPrice - (basePerPeriod * totalPeriods);

        // Period amounts: P1 = base + fee, P2 = base, P3 = base + remainder
        const periodAmounts = {
            1: basePerPeriod + serviceFee,  // งวดแรก: รวมค่าธรรมเนียม 3%
            2: basePerPeriod,                // งวดที่ 2: ยอดฐาน
            3: basePerPeriod + remainder     // งวดที่ 3: ยอดฐาน + เศษ
        };

        const paidPeriods = parseInt(contract.paid_periods) || 0;
        const progress = totalAmount > 0 ? Math.min((paidAmount / totalAmount * 100), 100) : 0;
        const remaining = Math.max(0, totalAmount - paidAmount);

        // Count payment statuses
        const paidCount = payments.filter(p => p.status === 'paid' || p.status === 'verified').length;
        const pendingCount = payments.filter(p => p.status === 'pending' || p.status === 'pending_verification').length;
        const overdueCount = payments.filter(p => p.status === 'overdue').length;

        // Build payments table
        let paymentsHtml = '';
        if (payments.length === 0) {
            paymentsHtml = `
            <div class="empty-payments">
                <i class="fas fa-receipt"></i>
                <p>ยังไม่มีประวัติการชำระ</p>
            </div>
        `;
        } else {
            paymentsHtml = `
            <!-- Desktop Table -->
            <div class="payment-table-container">
                <table class="payment-table">
                    <thead>
                        <tr>
                            <th>งวดที่</th>
                            <th>รหัสอ้างอิง</th>
                            <th>วันครบกำหนด</th>
                            <th>วันที่ชำระ</th>
                            <th style="text-align: right;">จำนวน</th>
                            <th>สถานะ</th>
                            <th>หมายเหตุ</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${payments.map(p => {
                const paymentNo = p.payment_no || p.payment_ref || '-';
                const periodNum = p.period_number || p.installment_number || 1;
                const amountForPeriod = p.amount || p.amount_due || periodAmounts[periodNum] || basePerPeriod;
                return `
                                <tr>
                                    <td><strong>งวดที่ ${periodNum}</strong></td>
                                    <td><code class="ref-code">${escapeHtml(paymentNo)}</code></td>
                                    <td>${p.due_date ? formatDate(p.due_date) : '-'}</td>
                                    <td>${p.paid_at || p.payment_date ? formatDateTime(p.paid_at || p.payment_date) : '-'}</td>
                                    <td style="text-align: right;">
                                        <span class="amount">฿${formatNumber(amountForPeriod)}</span>
                                    </td>
                                    <td>${getPaymentStatusBadge(p.status, p.rejection_reason)}</td>
                                    <td><small>${escapeHtml(p.notes || p.rejection_reason || '-')}</small></td>
                                </tr>
                            `;
            }).join('')}
                    </tbody>
                </table>
            </div>
            
            <!-- Mobile Cards -->
            <div class="pay-mobile-cards">
                ${payments.map(p => {
                const paymentNo = p.payment_no || p.payment_ref || '-';
                return `
                        <div class="pay-mobile-card">
                            <div class="pay-mobile-header">
                                <span class="pay-mobile-term">งวดที่ ${p.period_number || p.installment_number || '-'}</span>
                                ${getPaymentStatusBadge(p.status, p.rejection_reason)}
                            </div>
                            <div class="pay-mobile-meta">
                                <span>🔖 รหัสอ้างอิง</span><code class="ref-code">${escapeHtml(paymentNo)}</code>
                                <span>📅 ครบกำหนด</span><strong>${p.due_date ? formatDate(p.due_date) : '-'}</strong>
                                <span>💵 จำนวน</span><strong class="pay-mobile-amount">฿${formatNumber(p.amount || p.amount_due || periodAmounts[p.period_number || p.installment_number || 1] || basePerPeriod)}</strong>
                                ${p.paid_at || p.payment_date ? `<span>✅ ชำระเมื่อ</span><strong>${formatDateTime(p.paid_at || p.payment_date)}</strong>` : ''}
                                ${p.notes ? `<span>📝 หมายเหตุ</span><strong>${escapeHtml(p.notes)}</strong>` : ''}
                                ${p.rejection_reason ? `<span style="color: #ef4444;">❌ เหตุผล</span><strong style="color: #ef4444;">${escapeHtml(p.rejection_reason)}</strong>` : ''}
                            </div>
                        </div>
                    `;
            }).join('')}
            </div>
        `;
        }

        // Determine next period amount for instruction
        const nextPeriodNum = paidPeriods + 1;
        const nextPeriodAmount = periodAmounts[nextPeriodNum] || basePerPeriod;

        // Build product image section
        const productImageHtml = productImage ? `
            <div class="product-image-section">
                <img src="${escapeHtml(productImage)}" alt="${escapeHtml(displayName)}" class="product-detail-img" onerror="this.style.display='none'">
            </div>
        ` : '';

        document.getElementById('installmentDetailBody').innerHTML = `
        <!-- Product & Contract Info Header -->
        <div class="contract-header-section">
            ${productImageHtml}
            <div class="contract-main-info">
                <h3 class="product-title">${escapeHtml(displayName)}</h3>
                ${productCode ? `<div class="product-code"><i class="fas fa-barcode"></i> ${escapeHtml(productCode)}</div>` : ''}
                <div class="contract-refs">
                    <div class="ref-item">
                        <span class="ref-label">📋 สัญญาผ่อน:</span>
                        <code class="ref-code">${escapeHtml(contractNo)}</code>
                    </div>
                    ${orderNo ? `
                    <div class="ref-item">
                        <span class="ref-label">🛒 คำสั่งซื้อ:</span>
                        <code class="ref-code">${escapeHtml(orderNo)}</code>
                    </div>
                    ` : ''}
                </div>
            </div>
        </div>
        
        ${contract.status !== 'completed' ? `
        <!-- How to Pay Instructions -->
        <div class="how-to-pay-banner">
            <h5><i class="fas fa-lightbulb"></i> วิธีชำระเงินงวดถัดไป (งวดที่ ${nextPeriodNum})</h5>
            <div class="pay-steps">
                <div class="pay-step">
                    <span class="step-num">1</span>
                    <span>โอนเงิน <strong>฿${formatNumber(nextPeriodAmount)}</strong> เข้าบัญชีร้าน</span>
                </div>
                <div class="pay-step">
                    <span class="step-num">2</span>
                    <span>ส่งสลิปผ่าน <strong>LINE / Facebook</strong> ของร้าน</span>
                </div>
                <div class="pay-step">
                    <span class="step-num">3</span>
                    <span>รอ Admin อนุมัติ → ยอดจะบันทึกที่นี่อัตโนมัติ</span>
                </div>
            </div>
            <div class="pay-note">
                <i class="fas fa-info-circle"></i>
                สลิปของคุณจะปรากฏใน <a href="payment-history.php">ประวัติการชำระเงิน</a> หลังจากส่งผ่าน chatbot
            </div>
        </div>
        ` : ''}
        
        <!-- Payment Summary Section -->
        <div class="payment-summary-section">
            <div class="summary-main">
                <div class="amount-display">
                    <div class="amount-paid">฿${formatNumber(paidAmount)}</div>
                    <div class="amount-total">จาก ฿${formatNumber(totalAmount)}</div>
                </div>
                <div class="installment-formula">งวดละ ~฿${formatNumber(basePerPeriod)} × ${totalPeriods} งวด</div>
                <div class="progress-row">
                    <div class="progress-bar-container">
                        <div class="progress-bar-fill" style="width: ${progress}%"></div>
                    </div>
                    <span class="progress-percent">${progress.toFixed(0)}%</span>
                </div>
            </div>
            
            <div class="summary-stats">
                <div class="stat-item success">
                    <div class="stat-value">${paidPeriods}/${totalPeriods}</div>
                    <div class="stat-label">ชำระแล้ว</div>
                </div>
                <div class="stat-item pending">
                    <div class="stat-value">${pendingCount}</div>
                    <div class="stat-label">รอตรวจสอบ</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value">฿${formatNumber(remaining)}</div>
                    <div class="stat-label">คงเหลือ</div>
                </div>
                <div class="stat-item ${overdueCount > 0 ? 'danger' : ''}">
                    <div class="stat-value">${contract.next_due_date ? formatDate(contract.next_due_date) : '-'}</div>
                    <div class="stat-label">ครบกำหนด</div>
                </div>
            </div>
        </div>
        
        <div class="payment-section">
            <h4><i class="fas fa-history"></i> ประวัติการชำระงวด (${payments.length} รายการ)</h4>
            ${paymentsHtml}
        </div>
    `;
    
        // Update "ดูประวัติสลิป" button with correct filter URL
        const viewSlipBtn = document.getElementById('viewSlipHistoryBtn');
        if (viewSlipBtn) {
            let filterUrl = 'payment-history.php?type=installment';
            if (orderNo) {
                filterUrl += '&order_no=' + encodeURIComponent(orderNo);
            }
            viewSlipBtn.href = filterUrl;
            console.log('📍 Updated slip history URL:', filterUrl);
        }
    }

    function getPaymentStatusBadge(status, reason) {
        const statusConfig = {
            'pending': { label: 'รอชำระ', icon: 'fas fa-clock', class: 'pending' },
            'pending_verification': { label: 'รอตรวจสอบ', icon: 'fas fa-hourglass-half', class: 'pending_verification' },
            'paid': { label: 'ชำระแล้ว', icon: 'fas fa-check-circle', class: 'paid' },
            'verified': { label: 'อนุมัติแล้ว', icon: 'fas fa-check-circle', class: 'verified' },
            'rejected': { label: 'ปฏิเสธ', icon: 'fas fa-times-circle', class: 'rejected' },
            'overdue': { label: 'เลยกำหนด', icon: 'fas fa-exclamation-triangle', class: 'rejected' },
            'cancelled': { label: 'ยกเลิก', icon: 'fas fa-ban', class: 'rejected' }
        };

        const config = statusConfig[status] || statusConfig['pending'];
        return `<span class="pay-status ${config.class}"><i class="${config.icon}"></i> ${config.label}</span>`;
    }

    function formatDateTime(dateStr) {
        if (!dateStr) return '-';
        const d = new Date(dateStr);
        return d.toLocaleDateString('th-TH') + ' ' + d.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
    }

</script>

<!-- Customer Profile Component -->
<link rel="stylesheet" href="<?php echo asset('css/components/customer-profile.css'); ?>?v=<?php echo time(); ?>">
<script src="<?php echo asset('js/components/customer-profile.js'); ?>?v=<?php echo time(); ?>"></script>

<?php include('../includes/customer/footer.php'); ?>