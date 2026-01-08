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
            <h1 class="page-title">📍 ที่อยู่จัดส่ง</h1>
            <p class="page-subtitle">จัดการที่อยู่สำหรับจัดส่งสินค้า</p>
        </div>
        <button class="btn btn-primary" onclick="showAddressForm()">
            <i class="fas fa-plus"></i> เพิ่มที่อยู่ใหม่
        </button>
    </div>

    <div class="card" style="margin-bottom:1rem;">
        <div class="card-body">
            <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;justify-content:space-between;">
                <div style="flex:1;min-width:240px;display:flex;gap:.5rem;align-items:center;">
                    <input id="addressSearch" class="form-control" type="text" placeholder="ค้นหาชื่อผู้รับ/เบอร์/จังหวัด/อำเภอ/รหัสไปรษณีย์..." style="max-width:520px;">
                    <button class="btn btn-outline" type="button" onclick="clearAddressFilters()">ล้าง</button>
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
                    <button class="btn btn-outline" type="button" onclick="filterAddresses('')">ทั้งหมด</button>
                    <button class="btn btn-outline" type="button" onclick="filterAddresses('default')">ที่อยู่หลัก</button>
                </div>
            </div>
            <div id="addressFilterHint" style="margin-top:.5rem;color:var(--color-gray);font-size:.9rem;"></div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div id="addressesContainer" class="addresses-grid">
                <div style="grid-column:1/-1;text-align:center;padding:3rem;">
                    <div class="spinner" style="margin:0 auto 1rem;"></div>
                    <p>กำลังโหลดข้อมูล...</p>
                </div>
            </div>
            
            <!-- Pagination -->
            <div id="addressesPagination" class="pagination-container"></div>
        </div>
    </div>
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

.addresses-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1.5rem;
}

.address-card {
    background: var(--color-card);
    border: 2px solid var(--color-border);
    border-radius: 12px;
    padding: 1.5rem;
    position: relative;
    transition: all 0.3s ease;
}

.address-card:hover {
    border-color: var(--color-primary);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.address-card.address-default {
    border-color: var(--color-success);
    background: linear-gradient(to bottom right, var(--color-card), #f0fdf4);
}

.default-badge {
    position: absolute;
    top: -10px;
    right: 1rem;
    background: var(--color-success);
    color: white;
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
}

.address-header {
    margin-bottom: 1rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--color-border);
}

.address-recipient {
    font-weight: 700;
    font-size: 1.1rem;
    margin-bottom: 0.25rem;
}

.address-phone {
    color: var(--color-gray);
    font-size: 0.9rem;
}

.address-details {
    line-height: 1.6;
    color: var(--color-text);
    margin-bottom: 1rem;
}

.address-note {
    background: var(--color-background);
    padding: 0.75rem;
    border-radius: 8px;
    font-size: 0.875rem;
    margin-bottom: 1rem;
}

.address-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

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

@media (max-width: 768px) {
    .addresses-grid {
        grid-template-columns: 1fr;
    }
}

.address-card.is-highlighted {
    outline: 2px solid rgba(59, 130, 246, 0.7);
    box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.14);
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
