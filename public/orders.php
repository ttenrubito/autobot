<?php
/**
 * Orders - Customer Portal
 */
define('INCLUDE_CHECK', true);

$page_title = "คำสั่งซื้อ - AI Automation";
$current_page = "orders";

include('../includes/customer/header.php');
include('../includes/customer/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">📦 คำสั่งซื้อ</h1>
            <p class="page-subtitle">ตรวจสอบสถานะคำสั่งซื้อและรายการสินค้า</p>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">รายการคำสั่งซื้อ</h3>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>เลขคำสั่งซื้อ</th>
                            <th>สินค้า</th>
                            <th style="text-align:right;">จำนวนเงิน</th>
                            <th>ประเภทชำระ</th>
                            <th>สถานะ</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="ordersTableBody">
                        <tr>
                            <td colspan="6" style="text-align:center;padding:2rem;">
                                <div class="spinner" style="margin:0 auto 1rem;"></div>
                                กำลังโหลดข้อมูล...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Order Details Modal -->
<div id="orderModal" class="modal modal-ui" data-ui="modal" style="display:none;">
    <div class="modal-overlay" onclick="closeOrderModal()"></div>
    <div class="modal-dialog" style="max-width:900px;">
        <div class="modal-content-wrapper">
            <div class="modal-header-custom">
                <h2 class="modal-title-custom">รายละเอียดคำสั่งซื้อ</h2>
                <button class="modal-close-custom" onclick="closeOrderModal()">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M18 6L6 18M6 6l12 12" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
            <div class="modal-body-custom" id="orderDetailsContent">
                <!-- Content loaded by JS -->
            </div>
        </div>
    </div>
</div>

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
    to { transform: rotate(360deg); }
}

.detail-section {
    margin-bottom: 2rem;
}

.detail-section h3 {
    font-size: 1.1rem;
    font-weight: 600;
    margin-bottom: 1rem;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
}

.detail-item {
    padding: 1rem;
    background: var(--color-background);
    border-radius: 12px;
}

.detail-label {
    font-size: 0.875rem;
    color: var(--color-gray);
    margin-bottom: 0.25rem;
}

.detail-value {
    font-size: 1rem;
    font-weight: 600;
}

@media (max-width: 768px) {
    .detail-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<?php
$extra_scripts = [
    'assets/js/orders.js',
    'assets/css/modal-fixes.css'
];

include('../includes/customer/footer.php');
?>
