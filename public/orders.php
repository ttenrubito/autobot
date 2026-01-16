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
        <div class="page-header-content">
            <div>
                <h1 class="page-title">📦 คำสั่งซื้อ</h1>
                <p class="page-subtitle">ตรวจสอบสถานะคำสั่งซื้อและรายการสินค้า</p>
            </div>
            <button class="btn btn-primary" onclick="openCreateOrderModal()">
                <i class="fas fa-plus"></i> <span class="btn-text">สร้างคำสั่งซื้อใหม่</span>
            </button>
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
                            <th>ลูกค้า</th>
                            <th>สินค้า</th>
                            <th style="text-align:right;">จำนวนเงิน</th>
                            <th>ประเภทชำระ</th>
                            <th>สถานะ</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="ordersTableBody">
                        <tr>
                            <td colspan="7" style="text-align:center;padding:2rem;">
                                <div class="spinner" style="margin:0 auto 1rem;"></div>
                                กำลังโหลดข้อมูล...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <div id="ordersPagination" class="pagination-container"></div>
        </div>
    </div>
</main>

<!-- Order Details Modal -->
<div id="orderModal" class="order-detail-modal" style="display:none;">
    <div class="order-modal-overlay" onclick="closeOrderModal()"></div>
    <div class="order-modal-dialog">
        <div class="order-modal-header">
            <h2 class="order-modal-title">📦 รายละเอียดคำสั่งซื้อ</h2>
            <button class="order-modal-close" onclick="closeOrderModal()">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M18 6L6 18M6 6l12 12" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
        </div>
        <div class="order-modal-body" id="orderDetailsContent">
            <!-- Content loaded by JS -->
        </div>
    </div>
</div>

<!-- Create Order Modal -->
<div id="createOrderModal" class="order-detail-modal" style="display:none;">
    <div class="order-modal-overlay" onclick="closeCreateOrderModal()"></div>
    <div class="order-modal-dialog" style="max-width: 700px;">
        <div class="order-modal-header">
            <h2 class="order-modal-title">➕ สร้างคำสั่งซื้อใหม่</h2>
            <button class="order-modal-close" onclick="closeCreateOrderModal()">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M18 6L6 18M6 6l12 12" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
        </div>
        <div class="order-modal-body">
            <form id="createOrderForm" onsubmit="return submitCreateOrder(event)">
                <!-- Product Search Section -->
                <div class="detail-section">
                    <h4 class="detail-section-title">🔍 ค้นหาสินค้า</h4>
                    <div class="product-search-container">
                        <div class="form-group">
                            <label for="productSearch">ค้นหาด้วยชื่อ รหัส หรือ SKU</label>
                            <div class="autocomplete-wrapper">
                                <input type="text" 
                                       id="productSearch" 
                                       class="form-input" 
                                       placeholder="พิมพ์ชื่อสินค้า เช่น Rolex, Chanel, LV..."
                                       autocomplete="off"
                                       oninput="searchProducts(this.value)">
                                <div id="productSearchResults" class="autocomplete-dropdown" style="display:none;"></div>
                            </div>
                            <small class="form-hint">⌨️ พิมพ์อย่างน้อย 2 ตัวอักษรเพื่อค้นหา</small>
                        </div>
                        
                        <!-- Selected Product Display -->
                        <div id="selectedProductCard" class="selected-product-card" style="display:none;">
                            <div class="selected-product-image">
                                <img id="selectedProductImg" src="" alt="Product" 
                                     data-placeholder="images/placeholder-product.svg"
                                     onerror="this.onerror=null; var p=typeof PATH!=='undefined'?PATH.asset(this.dataset.placeholder):'/'+this.dataset.placeholder; this.src=p;">
                            </div>
                            <div class="selected-product-info">
                                <h5 id="selectedProductName">-</h5>
                                <p id="selectedProductCode" class="product-code">รหัส: -</p>
                                <p id="selectedProductPrice" class="product-price">฿0</p>
                            </div>
                            <button type="button" class="btn-remove-product" onclick="clearSelectedProduct()">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        
                        <!-- Hidden fields for selected product -->
                        <input type="hidden" id="selectedProductId" name="product_id">
                        <input type="hidden" id="selectedProductSku" name="product_sku">
                    </div>
                </div>
                
                <!-- Manual Product Entry (fallback) -->
                <div class="detail-section">
                    <h4 class="detail-section-title">📝 ข้อมูลสินค้า (กรอกเองได้)</h4>
                    <div class="detail-grid">
                        <div class="form-group">
                            <label for="productName">ชื่อสินค้า <span class="required">*</span></label>
                            <input type="text" id="productName" name="product_name" class="form-input" required 
                                   placeholder="เช่น Rolex Submariner Date">
                        </div>
                        <div class="form-group">
                            <label for="productCode">รหัสสินค้า</label>
                            <input type="text" id="productCode" name="product_code" class="form-input" 
                                   placeholder="เช่น RX-001">
                        </div>
                        <div class="form-group">
                            <label for="quantity">จำนวน <span class="required">*</span></label>
                            <input type="number" id="quantity" name="quantity" class="form-input" value="1" min="1" required>
                        </div>
                        <div class="form-group">
                            <label for="totalAmount">ยอดรวม (บาท) <span class="required">*</span></label>
                            <input type="number" id="totalAmount" name="total_amount" class="form-input" step="0.01" required
                                   placeholder="เช่น 450000">
                        </div>
                    </div>
                </div>
                
                <!-- Customer Section -->
                <div class="detail-section">
                    <h4 class="detail-section-title">👤 ข้อมูลลูกค้า</h4>
                    
                    <!-- Customer Search -->
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
                        <small class="form-hint">⌨️ พิมพ์อย่างน้อย 2 ตัวอักษรเพื่อค้นหา หรือกรอกข้อมูลเองด้านล่าง</small>
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
                        <button type="button" class="btn-remove-product" onclick="clearSelectedCustomer()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    
                    <!-- Hidden field for selected customer -->
                    <input type="hidden" id="selectedCustomerId" name="customer_id">
                    
                    <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 1rem 0;">
                    
                    <div class="detail-grid">
                        <div class="form-group">
                            <label for="customerName">ชื่อลูกค้า</label>
                            <input type="text" id="customerName" name="customer_name" class="form-input" 
                                   placeholder="เช่น คุณสมชาย">
                        </div>
                        <div class="form-group">
                            <label for="customerPhone">เบอร์โทร</label>
                            <input type="tel" id="customerPhone" name="customer_phone" class="form-input" 
                                   placeholder="เช่น 081-234-5678">
                        </div>
                        <div class="form-group full-width">
                            <label for="customerSource">ช่องทาง</label>
                            <select id="customerSource" name="source" class="form-input">
                                <option value="manual">กรอกเอง (Manual)</option>
                                <option value="line">LINE</option>
                                <option value="facebook">Facebook</option>
                                <option value="instagram">Instagram</option>
                                <option value="walk-in">Walk-in</option>
                                <option value="phone">โทรสั่ง</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Payment Type Section -->
                <div class="detail-section">
                    <h4 class="detail-section-title">💳 ประเภทการชำระ</h4>
                    <div class="payment-type-options">
                        <label class="payment-type-option" onclick="handlePaymentTypeClick(event, 'full')">
                            <input type="radio" name="payment_type" value="full" checked>
                            <span class="payment-type-card">
                                <span class="payment-type-icon">💳</span>
                                <span class="payment-type-label">จ่ายเต็ม</span>
                            </span>
                        </label>
                        <label class="payment-type-option" onclick="handlePaymentTypeClick(event, 'installment')">
                            <input type="radio" name="payment_type" value="installment">
                            <span class="payment-type-card">
                                <span class="payment-type-icon">📅</span>
                                <span class="payment-type-label">ผ่อนชำระ</span>
                            </span>
                        </label>
                        <label class="payment-type-option" onclick="handlePaymentTypeClick(event, 'savings')">
                            <input type="radio" name="payment_type" value="savings">
                            <span class="payment-type-card">
                                <span class="payment-type-icon">🐷</span>
                                <span class="payment-type-label">ออมก่อน</span>
                            </span>
                        </label>
                    </div>
                    
                    <!-- Installment Fields (hidden by default) -->
                    <div id="installmentFields" class="installment-fields" style="display:none;">
                        <div class="detail-grid" style="margin-top: 1rem;">
                            <div class="form-group">
                                <label for="installmentMonths">จำนวนงวด</label>
                                <select id="installmentMonths" name="installment_months" class="form-input">
                                    <option value="3">3 งวด</option>
                                    <option value="6">6 งวด</option>
                                    <option value="10">10 งวด</option>
                                    <option value="12">12 งวด</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="downPayment">เงินดาวน์</label>
                                <input type="number" id="downPayment" name="down_payment" class="form-input" step="0.01"
                                       placeholder="เช่น 50000">
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Notes Section -->
                <div class="detail-section">
                    <h4 class="detail-section-title">📝 หมายเหตุ</h4>
                    <div class="form-group">
                        <textarea id="orderNotes" name="notes" class="form-input" rows="3" 
                                  placeholder="หมายเหตุเพิ่มเติม เช่น รายละเอียดสินค้า, เงื่อนไขพิเศษ..."></textarea>
                    </div>
                </div>
                
                <!-- Bank Account & Push Message Section -->
                <div class="detail-section" id="pushMessageSection">
                    <h4 class="detail-section-title">💬 แจ้งลูกค้า</h4>
                    <div class="form-group">
                        <label for="bankAccount">บัญชีรับโอน</label>
                        <select id="bankAccount" name="bank_account" class="form-input" onchange="updateMessageTemplate()">
                            <option value="">-- เลือกบัญชี --</option>
                            <option value="scb_1" data-bank="ไทยพาณิชย์" data-name="บจก เพชรวิบวับ" data-number="1653014242">ไทยพาณิชย์ - 1653014242 (≤50K)</option>
                            <option value="kbank_1" data-bank="กสิกรไทย" data-name="บจก.เฮงเฮงโฮลดิ้ง" data-number="8000029282">กสิกรไทย - 8000029282</option>
                            <option value="bay_1" data-bank="กรุงศรี" data-name="บจก.เฮงเฮงโฮลดิ้ง" data-number="8000029282">กรุงศรี - 8000029282</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="customerMessage">ข้อความแจ้งลูกค้า</label>
                        <textarea id="customerMessage" name="customer_message" class="form-input" rows="6" 
                                  placeholder="ข้อความที่จะส่งให้ลูกค้า..."></textarea>
                        <small class="form-hint">💡 เลือกบัญชีเพื่อ auto-fill ข้อความ หรือพิมพ์เอง</small>
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="send_message" id="sendMessageCheckbox" checked>
                            <span>📤 ส่งข้อความแจ้งลูกค้าทันที</span>
                        </label>
                    </div>
                </div>
                
                <!-- Submit Button -->
                <div class="form-actions">
                    <button type="button" class="btn btn-outline" onclick="closeCreateOrderModal()">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary" id="submitOrderBtn">
                        <i class="fas fa-save"></i> บันทึก & ส่งข้อความ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toast Notification -->
<div id="toast" class="toast"></div>

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

/* Order Detail Modal - Clean Design */
.order-detail-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 9999;
    align-items: center;
    justify-content: center;
}
.order-detail-modal[style*="display: flex"] {
    display: flex;
}

.order-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    z-index: 9998;
}

.order-modal-dialog {
    position: relative;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
    width: 95vw;
    max-width: 800px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    z-index: 9999;
    animation: modalFadeIn 0.2s ease-out;
}

@keyframes modalFadeIn {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}

.order-modal-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #fff;
    flex-shrink: 0;
}

.order-modal-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1f2937;
    margin: 0;
}

.order-modal-close {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: #f3f4f6;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s;
    color: #6b7280;
}

.order-modal-close:hover {
    background: #e5e7eb;
    color: #374151;
}

.order-modal-body {
    padding: 1.5rem;
    overflow-y: auto;
    flex: 1;
    background: #f9fafb;
}

/* Detail Sections */
.detail-section {
    background: #fff;
    border-radius: 12px;
    padding: 1.25rem;
    margin-bottom: 1rem;
    border: 1px solid #e5e7eb;
}

.detail-section:last-child {
    margin-bottom: 0;
}

.detail-section-title {
    font-size: 0.875rem;
    font-weight: 600;
    color: #6b7280;
    margin: 0 0 1rem 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
}

.detail-item {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.detail-label {
    font-size: 0.75rem;
    color: #9ca3af;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.detail-value {
    font-size: 0.95rem;
    color: #1f2937;
    font-weight: 500;
}

.detail-value-lg {
    font-size: 1.5rem;
    font-weight: 700;
    color: #059669;
}

/* Customer Profile Card in Modal */
.customer-section {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    background: #f9fafb;
    border-radius: 10px;
    margin-bottom: 1rem;
}

.customer-avatar-lg {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    font-weight: 600;
    color: #6b7280;
    flex-shrink: 0;
    overflow: hidden;
}

.customer-avatar-lg img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.customer-info-detail {
    flex: 1;
    min-width: 0;
}

.customer-name-lg {
    font-size: 1.1rem;
    font-weight: 600;
    color: #1f2937;
    margin: 0 0 0.25rem 0;
}

.customer-meta {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.customer-meta-item {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.85rem;
    color: #6b7280;
}

.customer-meta-item svg {
    width: 14px;
    height: 14px;
}

.platform-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.25rem 0.6rem;
    background: #f3f4f6;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 500;
    color: #374151;
}

.platform-tag.line { background: #e8f5e9; color: #06c755; }
.platform-tag.facebook { background: #e3f2fd; color: #1877f2; }
.platform-tag svg { width: 12px; height: 12px; }

/* Status Badge */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.35rem 0.75rem;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
}

.status-pending { background: #fef3c7; color: #b45309; }
.status-processing { background: #dbeafe; color: #1d4ed8; }
.status-shipped { background: #e0e7ff; color: #4338ca; }
.status-delivered { background: #d1fae5; color: #047857; }
.status-cancelled { background: #fee2e2; color: #b91c1c; }

/* Payment Type Badge */
.payment-type-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.35rem 0.75rem;
    background: #f3f4f6;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 500;
    color: #374151;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid #e5e7eb;
}

.btn-action {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.6rem 1rem;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-size: 0.875rem;
    font-weight: 500;
    color: #374151;
    cursor: pointer;
    transition: all 0.15s;
    text-decoration: none;
}

.btn-action:hover {
    background: #f9fafb;
    border-color: #d1d5db;
}

.btn-action i {
    color: #6b7280;
}

/* Installment Table */
.installment-table {
    width: 100%;
    font-size: 0.875rem;
    border-collapse: collapse;
}

.installment-table th {
    text-align: left;
    padding: 0.75rem;
    font-weight: 600;
    color: #6b7280;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
}

.installment-table td {
    padding: 0.75rem;
    border-bottom: 1px solid #f3f4f6;
    color: #374151;
}

.installment-table tr:last-child td {
    border-bottom: none;
}

.inst-status {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 500;
}

.inst-status.paid { background: #d1fae5; color: #047857; }
.inst-status.pending { background: #fef3c7; color: #b45309; }
.inst-status.overdue { background: #fee2e2; color: #b91c1c; }

/* Create Order Modal Styles */
.form-group {
    margin-bottom: 1rem;
}

.form-group.full-width {
    grid-column: 1 / -1;
}

.form-group label {
    display: block;
    font-size: 0.875rem;
    font-weight: 500;
    color: #374151;
    margin-bottom: 0.5rem;
}

.form-group label .required {
    color: #dc2626;
}

.form-input {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.95rem;
    transition: all 0.15s;
    background: #fff;
}

.form-input:focus {
    outline: none;
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.form-hint {
    display: block;
    font-size: 0.75rem;
    color: #9ca3af;
    margin-top: 0.5rem;
}

/* Product Autocomplete */
.autocomplete-wrapper {
    position: relative;
}

.autocomplete-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.15);
    max-height: 300px;
    overflow-y: auto;
    z-index: 1000;
    margin-top: 4px;
}

.autocomplete-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 0.75rem 1rem;
    cursor: pointer;
    border-bottom: 1px solid #f3f4f6;
    transition: background 0.1s;
}

.autocomplete-item:last-child {
    border-bottom: none;
}

.autocomplete-item:hover {
    background: #f9fafb;
}

.autocomplete-item-img {
    width: 48px;
    height: 48px;
    border-radius: 8px;
    object-fit: cover;
    background: #f3f4f6;
}

.autocomplete-item-info {
    flex: 1;
    min-width: 0;
}

.autocomplete-item-name {
    font-weight: 500;
    color: #1f2937;
    margin-bottom: 0.25rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.autocomplete-item-meta {
    font-size: 0.8rem;
    color: #6b7280;
}

.autocomplete-item-price {
    font-weight: 600;
    color: #059669;
    white-space: nowrap;
}

.autocomplete-loading,
.autocomplete-empty {
    padding: 1rem;
    text-align: center;
    color: #6b7280;
    font-size: 0.9rem;
}

/* Selected Product Card */
.selected-product-card {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    background: #f0fdf4;
    border: 2px solid #22c55e;
    border-radius: 10px;
    margin-top: 1rem;
}

.selected-product-image img {
    width: 64px;
    height: 64px;
    border-radius: 8px;
    object-fit: cover;
}

.selected-product-info {
    flex: 1;
}

.selected-product-info h5 {
    font-size: 1rem;
    font-weight: 600;
    color: #1f2937;
    margin: 0 0 0.25rem 0;
}

.selected-product-info .product-code {
    font-size: 0.8rem;
    color: #6b7280;
    margin: 0 0 0.25rem 0;
}

.selected-product-info .product-price {
    font-size: 1.1rem;
    font-weight: 700;
    color: #059669;
    margin: 0;
}

.btn-remove-product {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #fee2e2;
    border: none;
    color: #dc2626;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s;
}

.btn-remove-product:hover {
    background: #fecaca;
}

/* Selected Customer Card */
.selected-customer-card {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem;
    background: #eff6ff;
    border: 2px solid #3b82f6;
    border-radius: 10px;
    margin-top: 1rem;
}

.selected-customer-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: #3b82f6;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    font-weight: 600;
    flex-shrink: 0;
    overflow: hidden;
}

.selected-customer-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.selected-customer-info {
    flex: 1;
    min-width: 0;
}

.selected-customer-info h5 {
    font-size: 1rem;
    font-weight: 600;
    color: #1f2937;
    margin: 0 0 0.25rem 0;
}

.selected-customer-info .customer-meta-text {
    font-size: 0.8rem;
    color: #6b7280;
    margin: 0;
}

/* Customer Autocomplete Item */
.autocomplete-item-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #e5e7eb;
    color: #6b7280;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
    font-weight: 600;
    flex-shrink: 0;
    overflow: hidden;
}

.autocomplete-item-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.autocomplete-item-platform {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.15rem 0.5rem;
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: 500;
    margin-left: auto;
}

.autocomplete-item-platform.line { background: #e8f5e9; color: #06c755; }
.autocomplete-item-platform.facebook { background: #e3f2fd; color: #1877f2; }
.autocomplete-item-platform.instagram { background: #fce4ec; color: #c13584; }

/* Payment Type Options */
.payment-type-options {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

.payment-type-option {
    flex: 1;
    min-width: 120px;
}

.payment-type-option input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.payment-type-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    padding: 1rem;
    background: #fff;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.15s;
}

.payment-type-option input:checked + .payment-type-card {
    border-color: var(--color-primary);
    background: #f0f9ff;
}

.payment-type-icon {
    font-size: 1.5rem;
}

.payment-type-label {
    font-size: 0.9rem;
    font-weight: 500;
    color: #374151;
}

.installment-fields {
    background: #f9fafb;
    padding: 1rem;
    border-radius: 8px;
    margin-top: 1rem;
}

/* Checkbox Label */
.checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    font-weight: 500;
    color: #374151;
}

.checkbox-label input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: var(--color-primary);
}

/* Form Actions */
.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
    padding-top: 1rem;
    border-top: 1px solid #e5e7eb;
    margin-top: 1rem;
}

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

/* Page Header Content */
.page-header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    width: 100%;
    flex-wrap: wrap;
    gap: 1rem;
}

/* Page Actions */
.page-actions {
    display: flex;
    gap: 0.75rem;
}

/* Toast */
.toast {
    position: fixed;
    bottom: 2rem;
    left: 50%;
    transform: translateX(-50%) translateY(100px);
    padding: 1rem 1.5rem;
    border-radius: 10px;
    background: #1f2937;
    color: #fff;
    font-weight: 500;
    box-shadow: 0 10px 25px rgba(0,0,0,0.2);
    z-index: 99999;
    opacity: 0;
    transition: all 0.3s;
}

.toast.show {
    transform: translateX(-50%) translateY(0);
    opacity: 1;
}

.toast.success { background: #059669; }
.toast.error { background: #dc2626; }
.toast.info { background: #0284c7; }

/* Address Section */
.address-block {
    background: #f9fafb;
    padding: 1rem;
    border-radius: 8px;
    font-size: 0.9rem;
    line-height: 1.6;
    color: #374151;
}

.address-name {
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 0.25rem;
}

.address-phone {
    color: #6b7280;
    margin-bottom: 0.5rem;
}

/* Responsive */
@media (max-width: 640px) {
    .page-header-content {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .page-header-content .btn {
        width: 100%;
    }
    
    .order-modal-dialog {
        width: 100vw;
        height: 100vh;
        max-width: 100vw;
        max-height: 100vh;
        border-radius: 0;
    }
    
    .detail-grid {
        grid-template-columns: 1fr;
    }
    
    .customer-section {
        flex-direction: column;
        text-align: center;
    }
    
    .customer-meta {
        justify-content: center;
    }
    
    .action-buttons {
        flex-direction: column;
    }
    
    .btn-action {
        justify-content: center;
    }
}
</style>

<!-- Customer Profile Component CSS -->
<link rel="stylesheet" href="<?php echo asset('css/components/customer-profile.css'); ?>?v=<?php echo time(); ?>">

<!-- Customer Profile Component JS -->
<script src="<?php echo asset('js/components/customer-profile.js'); ?>?v=<?php echo time(); ?>"></script>

<?php
$extra_scripts = [
    'assets/js/orders.js'
];

include('../includes/customer/footer.php');
?>
