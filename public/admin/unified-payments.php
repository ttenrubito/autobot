<?php
/**
 * Unified Payment Management - Admin Panel
 * จัดการการชำระเงินทุกประเภท (ชำระเต็ม/ผ่อน/ออม) ในที่เดียว
 */
define('INCLUDE_CHECK', true);
require_once '../../config.php';

// Check admin session
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$page_title = "จัดการการชำระเงิน - Admin";
$current_page = "unified_payments";
$extra_scripts = ['js/admin/unified-payments.js'];

include('../../includes/admin/header.php');
include('../../includes/admin/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <div class="page-header-content">
            <div>
                <h1 class="page-title">💳 จัดการการชำระเงิน (Unified)</h1>
                <p class="page-subtitle">ตรวจสอบ จำแนกประเภท และอนุมัติการชำระเงินจากทุกช่องทาง</p>
            </div>
            <div class="header-actions">
                <span class="badge badge-warning" id="pendingBadge">รอตรวจสอบ: 0</span>
                <span class="badge badge-info" id="unclassifiedBadge">ยังไม่จำแนก: 0</span>
            </div>
        </div>
    </div>

    <!-- Filter Tabs -->
    <div class="card">
        <div class="card-body" style="padding: 1rem;">
            <div class="filter-tabs">
                <button class="filter-tab active" data-status="" onclick="filterPayments('')">
                    <span class="tab-icon">📋</span>
                    <span class="tab-label">ทั้งหมด</span>
                </button>
                <button class="filter-tab" data-status="pending" onclick="filterPayments('pending')">
                    <span class="tab-icon">⏳</span>
                    <span class="tab-label">รอตรวจสอบ</span>
                </button>
                <button class="filter-tab" data-status="verified" onclick="filterPayments('verified')">
                    <span class="tab-icon">✅</span>
                    <span class="tab-label">อนุมัติแล้ว</span>
                </button>
                <button class="filter-tab" data-status="rejected" onclick="filterPayments('rejected')">
                    <span class="tab-icon">❌</span>
                    <span class="tab-label">ปฏิเสธ</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Type Filter -->
    <div class="card mt-3">
        <div class="card-body" style="padding: 1rem;">
            <div class="type-filter-row">
                <label class="type-filter-label">กรองตามประเภท:</label>
                <div class="type-filter-buttons">
                    <button class="type-btn active" data-type="" onclick="filterByType('')">ทั้งหมด</button>
                    <button class="type-btn" data-type="unknown" onclick="filterByType('unknown')">❓ ยังไม่จำแนก</button>
                    <button class="type-btn" data-type="full" onclick="filterByType('full')">💳 ชำระเต็ม</button>
                    <button class="type-btn" data-type="installment" onclick="filterByType('installment')">📅 ผ่อนชำระ</button>
                    <button class="type-btn" data-type="savings" onclick="filterByType('savings')">🐷 ออมเงิน</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Payments Table -->
    <div class="card mt-4">
        <div class="card-header">
            <h3 class="card-title">รายการการชำระเงิน</h3>
            <button class="btn btn-sm btn-outline" onclick="loadPayments()">
                <i class="fas fa-refresh"></i> รีเฟรช
            </button>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>วันที่</th>
                            <th>เลขที่</th>
                            <th>ลูกค้า</th>
                            <th style="text-align:right;">จำนวน</th>
                            <th>ประเภท</th>
                            <th>อ้างอิง</th>
                            <th>AI แนะนำ</th>
                            <th>สถานะ</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="paymentsTableBody">
                        <tr>
                            <td colspan="9" style="text-align:center;padding:2rem;">
                                <div class="spinner" style="margin:0 auto 1rem;"></div>
                                กำลังโหลดข้อมูล...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Pagination -->
    <div id="pagination" class="pagination-container"></div>
</main>

<!-- Classification Modal -->
<div id="classifyModal" class="modal-backdrop hidden">
    <div class="modal-content modal-lg">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-tag"></i> จำแนกและอนุมัติการชำระเงิน</h3>
                <button class="modal-close-btn" onclick="closeClassifyModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="card-body" id="classifyModalBody">
                <div class="loading-placeholder">
                    <div class="spinner"></div>
                    <p>กำลังโหลดข้อมูล...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal-backdrop hidden">
    <div class="modal-content">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-times-circle"></i> ปฏิเสธการชำระเงิน</h3>
                <button class="modal-close-btn" onclick="closeRejectModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="card-body">
                <input type="hidden" id="rejectPaymentId">
                <div class="form-group">
                    <label class="form-label">เหตุผลในการปฏิเสธ <span style="color: red;">*</span></label>
                    <textarea id="rejectReason" class="form-control" rows="3" placeholder="ระบุเหตุผล เช่น สลิปไม่ชัด, ยอดไม่ตรง, สลิปซ้ำ..."></textarea>
                </div>
                <div id="rejectError" class="alert alert-danger" style="display: none;"></div>
                <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                    <button class="btn btn-danger" style="flex: 1;" onclick="submitReject()">
                        <i class="fas fa-times"></i> ยืนยันปฏิเสธ
                    </button>
                    <button class="btn btn-outline" style="flex: 1;" onclick="closeRejectModal()">
                        <i class="fas fa-arrow-left"></i> ยกเลิก
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Filter Tabs */
.filter-tabs {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.filter-tab {
    flex: 1;
    min-width: 120px;
    padding: 0.75rem 1rem;
    border: 2px solid #e5e7eb;
    background: white;
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    font-weight: 500;
    color: #4b5563;
}

.filter-tab:hover {
    border-color: #d1d5db;
    background: #f9fafb;
}

.filter-tab.active {
    border-color: var(--color-primary);
    background: var(--color-primary);
    color: white;
}

.tab-icon { font-size: 1.1rem; }
.tab-label { font-size: 0.85rem; }

/* Type Filter */
.type-filter-row {
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
}

.type-filter-label {
    font-weight: 500;
    color: #374151;
    white-space: nowrap;
}

.type-filter-buttons {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.type-btn {
    padding: 0.5rem 1rem;
    border: 1px solid #e5e7eb;
    background: white;
    border-radius: 20px;
    cursor: pointer;
    font-size: 0.85rem;
    transition: all 0.2s;
}

.type-btn:hover {
    background: #f3f4f6;
}

.type-btn.active {
    background: #374151;
    color: white;
    border-color: #374151;
}

/* Header Actions */
.header-actions {
    display: flex;
    gap: 0.75rem;
}

/* Payment Type Badges */
.type-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.25rem 0.6rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 500;
}

.type-badge.unknown { background: #f3f4f6; color: #6b7280; }
.type-badge.full { background: #dbeafe; color: #1d4ed8; }
.type-badge.installment { background: #fef3c7; color: #b45309; }
.type-badge.savings { background: #d1fae5; color: #047857; }

/* AI Suggestion */
.ai-suggestion {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.8rem;
}

.ai-confidence {
    background: #e5e7eb;
    padding: 0.15rem 0.4rem;
    border-radius: 4px;
    font-size: 0.7rem;
}

.ai-confidence.high { background: #d1fae5; color: #047857; }
.ai-confidence.medium { background: #fef3c7; color: #b45309; }
.ai-confidence.low { background: #fee2e2; color: #b91c1c; }

/* Status Badges */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.3rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 500;
}

.status-badge.pending { background: #fef3c7; color: #b45309; }
.status-badge.verified { background: #d1fae5; color: #047857; }
.status-badge.rejected { background: #fee2e2; color: #b91c1c; }

/* Reference Badge */
.ref-badge {
    font-size: 0.75rem;
    color: #6b7280;
}

.ref-badge code {
    background: #f3f4f6;
    padding: 0.15rem 0.4rem;
    border-radius: 4px;
    font-family: monospace;
}

/* Modal Styles */
.modal-lg {
    max-width: 1000px;
    width: 95%;
}

/* Classification Form */
.classify-section {
    margin-bottom: 1.5rem;
}

.classify-section h4 {
    font-size: 1rem;
    color: #374151;
    margin-bottom: 0.75rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

/* Payment Info Card */
.payment-info-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 16px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.payment-info-card .amount-big {
    font-size: 2.5rem;
    font-weight: 700;
}

.payment-info-card .meta {
    opacity: 0.9;
    margin-top: 0.5rem;
}

/* Slip Preview */
.slip-preview {
    max-width: 300px;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    cursor: pointer;
}

/* Type Selection */
.type-select-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
}

.type-select-card {
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 1.25rem;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
}

.type-select-card:hover {
    border-color: var(--color-primary);
    background: #f9fafb;
}

.type-select-card.selected {
    border-color: var(--color-primary);
    background: rgba(var(--color-primary-rgb), 0.1);
}

.type-select-card .icon {
    font-size: 2rem;
    margin-bottom: 0.5rem;
}

.type-select-card .label {
    font-weight: 600;
    color: #374151;
}

/* Reference Selection */
.reference-list {
    max-height: 250px;
    overflow-y: auto;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
}

.reference-item {
    display: flex;
    align-items: center;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid #f3f4f6;
    cursor: pointer;
    transition: background 0.2s;
}

.reference-item:hover {
    background: #f9fafb;
}

.reference-item.selected {
    background: #dbeafe;
}

.reference-item:last-child {
    border-bottom: none;
}

.reference-radio {
    margin-right: 0.75rem;
}

.reference-info {
    flex: 1;
}

.reference-title {
    font-weight: 500;
    color: #374151;
}

.reference-meta {
    font-size: 0.8rem;
    color: #6b7280;
}

/* Spinner */
.spinner {
    width: 40px;
    height: 40px;
    border: 3px solid #e5e7eb;
    border-top-color: var(--color-primary);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Responsive */
@media (max-width: 768px) {
    .type-select-grid {
        grid-template-columns: 1fr;
    }
    
    .filter-tabs {
        flex-direction: column;
    }
    
    .filter-tab {
        min-width: 100%;
    }
    
    .type-filter-row {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<?php include('../../includes/admin/footer.php'); ?>
