<?php
/**
 * Products Management - Customer Portal
 * Manage product catalog for V5 Product Search
 */
define('INCLUDE_CHECK', true);

$page_title = "จัดการสินค้า - AI Automation";
$current_page = "products";

include('../includes/customer/header.php');
include('../includes/customer/sidebar.php');
?>

<main class="main-content">
    <div class="page-header">
        <div class="page-header-content">
            <div>
                <h1 class="page-title">🏷️ จัดการสินค้า</h1>
                <p class="page-subtitle">จัดการข้อมูลสินค้าสำหรับ API Product Search</p>
            </div>
            <button class="btn btn-primary" onclick="openCreateModal()">
                <i class="fas fa-plus"></i> <span class="btn-text">เพิ่มสินค้าใหม่</span>
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div class="card" style="margin-bottom: 1rem;">
        <div class="card-body" style="padding: 1rem;">
            <div class="filters-row">
                <div class="filter-group">
                    <input type="text" id="searchInput" class="form-input" placeholder="🔍 ค้นหาชื่อ, รหัส, คำอธิบาย..."
                        style="min-width: 250px;" oninput="debounceSearch()">
                </div>
                <div class="filter-group">
                    <select id="categoryFilter" class="form-input" onchange="loadProducts()">
                        <option value="">หมวดหมู่ทั้งหมด</option>
                    </select>
                </div>
                <div class="filter-group">
                    <select id="brandFilter" class="form-input" onchange="loadProducts()">
                        <option value="">แบรนด์ทั้งหมด</option>
                    </select>
                </div>
                <div class="filter-group">
                    <select id="statusFilter" class="form-input" onchange="loadProducts()">
                        <option value="">สถานะทั้งหมด</option>
                        <option value="active">🟢 พร้อมขาย</option>
                        <option value="inactive">⚪ ปิดการขาย</option>
                        <option value="out_of_stock">🔴 หมดสต๊อก</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">รายการสินค้า <span id="productCount"
                    style="font-weight:normal;color:#6b7280;"></span></h3>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th style="width:60px;">รูป</th>
                            <th>ชื่อสินค้า</th>
                            <th>รหัส</th>
                            <th>หมวดหมู่</th>
                            <th style="text-align:right;">ราคา</th>
                            <th style="text-align:center;">สต๊อก</th>
                            <th style="text-align:center;">สถานะ</th>
                            <th style="width:100px;"></th>
                        </tr>
                    </thead>
                    <tbody id="productsTableBody">
                        <tr>
                            <td colspan="8" style="text-align:center;padding:2rem;">
                                <div class="spinner" style="margin:0 auto 1rem;"></div>
                                กำลังโหลดข้อมูล...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div id="productsPagination" class="pagination-container"></div>
        </div>
    </div>
</main>

<!-- Product Modal (Create/Edit) -->
<div id="productModal" class="product-modal" style="display:none;">
    <div class="product-modal-overlay" onclick="closeModal()"></div>
    <div class="product-modal-dialog">
        <div class="product-modal-header">
            <h2 class="product-modal-title" id="modalTitle">➕ เพิ่มสินค้าใหม่</h2>
            <button class="product-modal-close" onclick="closeModal()">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M18 6L6 18M6 6l12 12" stroke-width="2" stroke-linecap="round" />
                </svg>
            </button>
        </div>
        <div class="product-modal-body">
            <form id="productForm" onsubmit="return submitForm(event)">
                <input type="hidden" id="productId" name="id">

                <!-- Image Upload Section -->
                <div class="detail-section">
                    <h4 class="detail-section-title">📷 รูปภาพสินค้า</h4>
                    <div class="image-upload-container">
                        <div class="image-preview" id="imagePreview">
                            <img id="previewImg" src="" alt="Product" style="display:none;">
                            <div class="image-placeholder" id="imagePlaceholder">
                                <i class="fas fa-cloud-upload-alt" style="font-size:2.5rem;"></i>
                                <span>คลิกหรือลากไฟล์มาวาง</span>
                                <small style="color:#9ca3af;font-size:0.75rem;">PNG, JPG, GIF สูงสุด 5MB</small>
                            </div>
                        </div>
                        <input type="file" id="imageFile" accept="image/*" style="display:none;"
                            onchange="handleImageSelect(this)">
                        <input type="hidden" id="imageUrl" name="image_url">
                        <div class="image-actions">
                            <button type="button" class="btn btn-outline btn-sm"
                                onclick="document.getElementById('imageFile').click()">
                                <i class="fas fa-upload"></i> อัปโหลดรูป
                            </button>
                            <button type="button" class="btn btn-outline btn-sm" onclick="clearImage()"
                                style="display:none;" id="clearImageBtn">
                                <i class="fas fa-times"></i> ลบรูป
                            </button>
                        </div>
                        <div id="imageStatus" class="image-status" style="display:none;"></div>
                        <small class="form-hint">หรือวาง URL รูปภาพด้านล่าง</small>
                        <input type="text" id="imageUrlInput" class="form-input"
                            placeholder="https://example.com/image.jpg" onchange="loadImageFromUrl(this.value)"
                            style="margin-top:0.5rem;">
                    </div>
                </div>

                <!-- Basic Info Section -->
                <div class="detail-section">
                    <h4 class="detail-section-title">📝 ข้อมูลพื้นฐาน</h4>
                    <div class="detail-grid">
                        <div class="form-group">
                            <label for="productCode">รหัสสินค้า <span class="required">*</span></label>
                            <input type="text" id="productCode" name="product_code" class="form-input" required
                                placeholder="เช่น ROL-SUB-001">
                        </div>
                        <div class="form-group">
                            <label for="productName">ชื่อสินค้า <span class="required">*</span></label>
                            <input type="text" id="productName" name="product_name" class="form-input" required
                                placeholder="เช่น Rolex Submariner Date">
                        </div>
                        <div class="form-group">
                            <label for="productBrand">แบรนด์</label>
                            <input type="text" id="productBrand" name="brand" class="form-input"
                                placeholder="เช่น Rolex, Chanel, LV">
                        </div>
                        <div class="form-group">
                            <label for="productCategory">หมวดหมู่</label>
                            <input type="text" id="productCategory" name="category" class="form-input"
                                placeholder="เช่น watch, bag, jewelry" list="categoryList">
                            <datalist id="categoryList"></datalist>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="productDescription">คำอธิบาย</label>
                        <textarea id="productDescription" name="description" class="form-input" rows="3"
                            placeholder="รายละเอียดสินค้า, สภาพ, ปีผลิต..."></textarea>
                    </div>
                </div>

                <!-- Pricing Section -->
                <div class="detail-section">
                    <h4 class="detail-section-title">💰 ราคาและสต๊อก</h4>
                    <div class="detail-grid">
                        <div class="form-group">
                            <label for="productPrice">ราคาปกติ (บาท) <span class="required">*</span></label>
                            <input type="number" id="productPrice" name="price" class="form-input" required step="0.01"
                                min="0" placeholder="เช่น 450000">
                        </div>
                        <div class="form-group">
                            <label for="productSalePrice">ราคาขาย (บาท)</label>
                            <input type="number" id="productSalePrice" name="sale_price" class="form-input" step="0.01"
                                min="0" placeholder="ว่างไว้ถ้าไม่มีลดราคา">
                            <small class="form-hint">ใส่เฉพาะกรณีลดราคา</small>
                        </div>
                        <div class="form-group">
                            <label for="productStock">จำนวนสต๊อก</label>
                            <input type="number" id="productStock" name="stock" class="form-input" min="0" value="1"
                                placeholder="1">
                        </div>
                        <div class="form-group">
                            <label for="productStatus">สถานะ</label>
                            <select id="productStatus" name="status" class="form-input">
                                <option value="active">🟢 พร้อมขาย</option>
                                <option value="inactive">⚪ ปิดการขาย</option>
                                <option value="out_of_stock">🔴 หมดสต๊อก</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Tags Section -->
                <div class="detail-section">
                    <h4 class="detail-section-title">🏷️ แท็ก (สำหรับค้นหา)</h4>
                    <div class="form-group">
                        <input type="text" id="tagsInput" class="form-input"
                            placeholder="พิมพ์แท็กแล้วกด Enter เช่น luxury, swiss, automatic">
                        <div id="tagsList" class="tags-list"></div>
                        <input type="hidden" id="tagsValue" name="tags">
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="form-actions">
                    <button type="button" class="btn btn-outline" onclick="closeModal()">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="fas fa-save"></i> บันทึก
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="product-modal" style="display:none;">
    <div class="product-modal-overlay" onclick="closeDeleteModal()"></div>
    <div class="product-modal-dialog" style="max-width:400px;">
        <div class="product-modal-header" style="background:#fee2e2;">
            <h2 class="product-modal-title" style="color:#b91c1c;">⚠️ ยืนยันการลบ</h2>
        </div>
        <div class="product-modal-body" style="text-align:center;">
            <p style="margin-bottom:1rem;">คุณต้องการลบสินค้านี้ใช่หรือไม่?</p>
            <p id="deleteProductName" style="font-weight:600;color:#374151;margin-bottom:1.5rem;"></p>
            <div class="form-actions" style="justify-content:center;">
                <button class="btn btn-outline" onclick="closeDeleteModal()">ยกเลิก</button>
                <button class="btn btn-danger" onclick="confirmDelete()">
                    <i class="fas fa-trash"></i> ลบสินค้า
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Toast Notification -->
<div id="toast" class="toast"></div>

<style>
    /* Filters Row */
    .filters-row {
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
        align-items: center;
    }

    .filter-group {
        flex: 1;
        min-width: 150px;
    }

    /* Product image in table */
    .product-thumb {
        width: 48px;
        height: 48px;
        border-radius: 8px;
        object-fit: cover;
        background: #f3f4f6;
    }

    .product-thumb-placeholder {
        width: 48px;
        height: 48px;
        border-radius: 8px;
        background: #f3f4f6;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #9ca3af;
        font-size: 1.2rem;
    }

    /* Product Modal */
    .product-modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 9999;
        align-items: flex-start;
        justify-content: center;
        overflow-y: auto;
        padding: 2rem 1rem;
    }

    .product-modal[style*="display: flex"],
    .product-modal[style*="display:flex"] {
        display: flex !important;
    }

    .product-modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.6);
        backdrop-filter: blur(4px);
        z-index: 9998;
    }

    .product-modal-dialog {
        position: relative;
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        width: 95vw;
        max-width: 700px;
        max-height: none;
        display: flex;
        flex-direction: column;
        z-index: 9999;
        animation: modalFadeIn 0.2s ease-out;
        margin: auto;
    }

    @keyframes modalFadeIn {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .product-modal-header {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #fff;
        flex-shrink: 0;
    }

    .product-modal-title {
        font-size: 1.25rem;
        font-weight: 700;
        color: #1f2937;
        margin: 0;
    }

    .product-modal-close {
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

    .product-modal-close:hover {
        background: #e5e7eb;
        color: #374151;
    }

    .product-modal-body {
        padding: 1.5rem;
        overflow-y: auto;
        flex: 1;
        background: #f9fafb;
    }

    /* Detail Sections (same as orders.php) */
    .detail-section {
        background: #fff;
        border-radius: 12px;
        padding: 1.25rem;
        margin-bottom: 1rem;
        border: 1px solid #e5e7eb;
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

    /* Form Elements */
    .form-group {
        margin-bottom: 1rem;
    }

    .form-group:last-child {
        margin-bottom: 0;
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

    /* Image Upload */
    .image-upload-container {
        text-align: center;
    }

    .image-preview {
        width: 180px;
        height: 180px;
        border: 2px dashed #d1d5db;
        border-radius: 12px;
        margin: 0 auto 1rem;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s;
        overflow: hidden;
        background: #f9fafb;
    }

    .image-preview:hover {
        border-color: var(--color-primary);
        background: #f0f4ff;
    }

    .image-preview img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .image-placeholder {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        color: #9ca3af;
        gap: 0.25rem;
        padding: 1rem;
        width: 100%;
        height: 100%;
    }

    .image-placeholder i {
        font-size: 2.5rem;
        display: block;
        color: #3b82f6;
        margin-bottom: 0.5rem;
    }

    .image-placeholder span {
        font-size: 0.85rem;
        color: #6b7280;
    }

    .image-placeholder small {
        font-size: 0.75rem;
        color: #9ca3af;
    }

    .image-actions {
        display: flex;
        gap: 0.5rem;
        justify-content: center;
        margin-bottom: 0.5rem;
    }

    .image-status {
        text-align: center;
        padding: 0.5rem;
        border-radius: 6px;
        font-size: 0.85rem;
        margin-top: 0.5rem;
    }

    .image-status.uploading {
        background: #fef3c7;
        color: #92400e;
    }

    .image-status.success {
        background: #d1fae5;
        color: #047857;
    }

    .image-status.error {
        background: #fee2e2;
        color: #b91c1c;
    }

    /* Tags */
    .tags-list {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-top: 0.75rem;
    }

    .tag-item {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.35rem 0.75rem;
        background: #e0e7ff;
        color: #3730a3;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 500;
    }

    .tag-item button {
        background: none;
        border: none;
        cursor: pointer;
        color: #6366f1;
        padding: 0;
        font-size: 0.9rem;
    }

    .tag-item button:hover {
        color: #dc2626;
    }

    /* Form Actions */
    .form-actions {
        display: flex;
        gap: 0.75rem;
        justify-content: flex-end;
        margin-top: 1.5rem;
        padding-top: 1rem;
        border-top: 1px solid #e5e7eb;
    }

    /* Status Badge */
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.25rem 0.6rem;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
    }

    .status-active {
        background: #d1fae5;
        color: #047857;
    }

    .status-inactive {
        background: #f3f4f6;
        color: #6b7280;
    }

    .status-out_of_stock {
        background: #fee2e2;
        color: #b91c1c;
    }

    /* Action Buttons */
    .btn-icon {
        width: 32px;
        height: 32px;
        border-radius: 6px;
        border: none;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all 0.15s;
        background: #f3f4f6;
        color: #374151;
    }

    .btn-icon:hover {
        background: #e5e7eb;
    }

    .btn-icon.btn-edit:hover {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .btn-icon.btn-delete:hover {
        background: #fee2e2;
        color: #b91c1c;
    }

    /* Spinner */
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

    /* Toast */
    .toast {
        position: fixed;
        bottom: 2rem;
        right: 2rem;
        padding: 1rem 1.5rem;
        background: #1f2937;
        color: #fff;
        border-radius: 8px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        z-index: 10000;
        opacity: 0;
        transform: translateY(20px);
        transition: all 0.3s;
    }

    .toast.show {
        opacity: 1;
        transform: translateY(0);
    }

    .toast.success {
        background: #059669;
    }

    .toast.error {
        background: #dc2626;
    }

    /* Button styles */
    .btn-sm {
        padding: 0.4rem 0.75rem;
        font-size: 0.8rem;
    }

    .btn-danger {
        background: #dc2626;
        color: #fff;
        border: none;
    }

    .btn-danger:hover {
        background: #b91c1c;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .filters-row {
            flex-direction: column;
        }

        .filter-group {
            width: 100%;
        }

        .detail-grid {
            grid-template-columns: 1fr;
        }
        
        .product-modal-dialog {
            width: 100vw;
            max-width: 100%;
            max-height: 100vh;
            border-radius: 0;
            margin: 0;
        }
        
        .product-modal-body {
            padding: 1rem;
        }
        
        .detail-section {
            padding: 1rem;
        }
        
        .form-actions {
            flex-direction: column;
        }
        
        .form-actions .btn {
            width: 100%;
        }
    }
    
    /* Fix for very wide forms - ensure inputs don't break layout */
    .form-input,
    textarea.form-input {
        box-sizing: border-box;
        max-width: 100%;
    }
</style>

<script>
    // State
    let currentPage = 1;
    let products = [];
    let tags = [];
    let deleteProductId = null;
    let searchTimeout = null;

    // API helper
    async function apiCall(method, params = {}) {
        const token = localStorage.getItem('auth_token');
        const apiUrl = (typeof PATH !== 'undefined' && PATH.api)
            ? PATH.api('api/customer/products.php')
            : '/api/customer/products.php';

        let url = apiUrl;
        if (method === 'GET' || method === 'DELETE') {
            const query = new URLSearchParams(params).toString();
            if (query) url += '?' + query;
        }

        const options = {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            }
        };

        if (method === 'POST' || method === 'PUT') {
            options.body = JSON.stringify(params);
            if (params.id) url += '?id=' + params.id;
        }

        const response = await fetch(url, options);
        return await response.json();
    }

    // Load products
    async function loadProducts(page = 1) {
        currentPage = page;
        const tbody = document.getElementById('productsTableBody');
        tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:2rem;">
        <div class="spinner" style="margin:0 auto 1rem;"></div>กำลังโหลด...</td></tr>`;

        try {
            const result = await apiCall('GET', {
                page: page,
                limit: 20,
                search: document.getElementById('searchInput').value,
                category: document.getElementById('categoryFilter').value,
                brand: document.getElementById('brandFilter').value,
                status: document.getElementById('statusFilter').value
            });

            if (result.success) {
                products = result.data.products;
                renderProducts(products);
                renderPagination(result.data.pagination);
                updateFilters(result.data.filters);
                document.getElementById('productCount').textContent =
                    `(${result.data.pagination.total} รายการ)`;
            } else {
                tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:2rem;color:#dc2626;">
                ${result.message}</td></tr>`;
            }
        } catch (e) {
            console.error('Load products error:', e);
            tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:2rem;color:#dc2626;">
            เกิดข้อผิดพลาด</td></tr>`;
        }
    }

    // Render products table
    function renderProducts(products) {
        const tbody = document.getElementById('productsTableBody');

        if (!products.length) {
            tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:2rem;color:#6b7280;">
            ไม่พบสินค้า</td></tr>`;
            return;
        }

        tbody.innerHTML = products.map(p => {
            const imgHtml = p.image_url
                ? `<img src="${p.image_url}" class="product-thumb" onerror="this.outerHTML='<div class=product-thumb-placeholder>🏷️</div>'">`
                : `<div class="product-thumb-placeholder">🏷️</div>`;

            const price = (p.sale_price && p.sale_price < p.price)
                ? `<span style="text-decoration:line-through;color:#9ca3af;font-size:0.85rem;">฿${formatNumber(p.price)}</span><br>฿${formatNumber(p.sale_price)}`
                : `฿${formatNumber(p.price)}`;

            const statusMap = {
                'active': { label: 'พร้อมขาย', class: 'status-active' },
                'inactive': { label: 'ปิดการขาย', class: 'status-inactive' },
                'out_of_stock': { label: 'หมดสต๊อก', class: 'status-out_of_stock' }
            };
            const status = statusMap[p.status] || statusMap['active'];

            return `
            <tr>
                <td>${imgHtml}</td>
                <td>
                    <div style="font-weight:500;">${escapeHtml(p.product_name)}</div>
                    ${p.brand ? `<div style="font-size:0.8rem;color:#6b7280;">${escapeHtml(p.brand)}</div>` : ''}
                </td>
                <td><code style="background:#f3f4f6;padding:0.2rem 0.5rem;border-radius:4px;font-size:0.85rem;">${escapeHtml(p.product_code)}</code></td>
                <td>${p.category ? escapeHtml(p.category) : '-'}</td>
                <td style="text-align:right;font-weight:500;color:#059669;">${price}</td>
                <td style="text-align:center;">${p.stock}</td>
                <td style="text-align:center;"><span class="status-badge ${status.class}">${status.label}</span></td>
                <td>
                    <button class="btn-icon btn-edit" onclick="editProduct(${p.id})" title="แก้ไข">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn-icon btn-delete" onclick="deleteProduct(${p.id}, '${escapeHtml(p.product_name)}')" title="ลบ">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>
        `;
        }).join('');
    }

    // Render pagination
    function renderPagination(pagination) {
        const container = document.getElementById('productsPagination');
        if (pagination.total_pages <= 1) {
            container.innerHTML = '';
            return;
        }

        let html = '<div class="pagination">';

        if (pagination.page > 1) {
            html += `<button class="page-btn" onclick="loadProducts(${pagination.page - 1})">‹ ก่อนหน้า</button>`;
        }

        for (let i = 1; i <= pagination.total_pages; i++) {
            if (i === pagination.page) {
                html += `<button class="page-btn active">${i}</button>`;
            } else if (Math.abs(i - pagination.page) <= 2 || i === 1 || i === pagination.total_pages) {
                html += `<button class="page-btn" onclick="loadProducts(${i})">${i}</button>`;
            } else if (Math.abs(i - pagination.page) === 3) {
                html += `<span class="page-dots">...</span>`;
            }
        }

        if (pagination.page < pagination.total_pages) {
            html += `<button class="page-btn" onclick="loadProducts(${pagination.page + 1})">ถัดไป ›</button>`;
        }

        html += '</div>';
        container.innerHTML = html;
    }

    // Update filter dropdowns
    function updateFilters(filters) {
        const catSelect = document.getElementById('categoryFilter');
        const brandSelect = document.getElementById('brandFilter');

        // Keep current values
        const currentCat = catSelect.value;
        const currentBrand = brandSelect.value;

        // Update categories
        let catHtml = '<option value="">หมวดหมู่ทั้งหมด</option>';
        filters.categories.forEach(c => {
            catHtml += `<option value="${c}" ${c === currentCat ? 'selected' : ''}>${c}</option>`;
        });
        catSelect.innerHTML = catHtml;

        // Update brands  
        let brandHtml = '<option value="">แบรนด์ทั้งหมด</option>';
        filters.brands.forEach(b => {
            brandHtml += `<option value="${b}" ${b === currentBrand ? 'selected' : ''}>${b}</option>`;
        });
        brandSelect.innerHTML = brandHtml;

        // Update datalist for category input
        let datalistHtml = '';
        filters.categories.forEach(c => {
            datalistHtml += `<option value="${c}">`;
        });
        document.getElementById('categoryList').innerHTML = datalistHtml;
    }

    // Debounced search
    function debounceSearch() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => loadProducts(1), 300);
    }

    // Open create modal
    function openCreateModal() {
        document.getElementById('modalTitle').textContent = '➕ เพิ่มสินค้าใหม่';
        document.getElementById('productForm').reset();
        document.getElementById('productId').value = '';
        clearImage();
        tags = [];
        renderTags();
        document.getElementById('productModal').style.display = 'flex';
    }

    // Edit product
    async function editProduct(id) {
        document.getElementById('modalTitle').textContent = '✏️ แก้ไขสินค้า';
        document.getElementById('productModal').style.display = 'flex';

        const product = products.find(p => p.id === id);
        if (!product) return;

        document.getElementById('productId').value = product.id;
        document.getElementById('productCode').value = product.product_code;
        document.getElementById('productName').value = product.product_name;
        document.getElementById('productBrand').value = product.brand || '';
        document.getElementById('productCategory').value = product.category || '';
        document.getElementById('productDescription').value = product.description || '';
        document.getElementById('productPrice').value = product.price;
        document.getElementById('productSalePrice').value = product.sale_price || '';
        document.getElementById('productStock').value = product.stock;
        document.getElementById('productStatus').value = product.status;

        // Image
        if (product.image_url) {
            document.getElementById('imageUrl').value = product.image_url;
            document.getElementById('imageUrlInput').value = product.image_url;
            document.getElementById('previewImg').src = product.image_url;
            document.getElementById('previewImg').style.display = 'block';
            document.getElementById('imagePlaceholder').style.display = 'none';
            document.getElementById('clearImageBtn').style.display = 'inline-flex';
        } else {
            clearImage();
        }

        // Tags
        tags = product.tags || [];
        renderTags();
    }

    // Close modal
    function closeModal() {
        document.getElementById('productModal').style.display = 'none';
    }

    // Submit form
    async function submitForm(e) {
        e.preventDefault();

        const form = document.getElementById('productForm');
        const id = document.getElementById('productId').value;
        const imageValue = document.getElementById('imageUrl').value;

        // Check if it's base64 or URL
        const isBase64 = imageValue && imageValue.startsWith('data:');
        console.log('[DEBUG] Image value type:', isBase64 ? 'base64' : (imageValue ? 'url' : 'empty'));
        if (isBase64) {
            console.log('[DEBUG] Image base64 length:', imageValue.length);
        }

        const data = {
            product_code: document.getElementById('productCode').value,
            product_name: document.getElementById('productName').value,
            brand: document.getElementById('productBrand').value,
            category: document.getElementById('productCategory').value,
            description: document.getElementById('productDescription').value,
            price: parseFloat(document.getElementById('productPrice').value),
            sale_price: document.getElementById('productSalePrice').value || null,
            stock: parseInt(document.getElementById('productStock').value) || 0,
            status: document.getElementById('productStatus').value,
            tags: tags
        };

        // Send as image_base64 for file upload, image_url for direct URLs
        if (isBase64) {
            data.image_base64 = imageValue;
        } else if (imageValue) {
            data.image_url = imageValue;
        }

        if (id) data.id = parseInt(id);
        
        console.log('[DEBUG] Submitting product data:', {
            ...data,
            image_base64: data.image_base64 ? `(base64 ${data.image_base64.length} chars)` : undefined
        });

        const submitBtn = document.getElementById('submitBtn');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังอัปโหลดรูป...';

        try {
            const method = id ? 'PUT' : 'POST';
            console.log('[DEBUG] API call:', method, 'id:', id);
            const result = await apiCall(method, data);
            console.log('[DEBUG] API response:', result);

            if (result.success) {
                showToast(result.message, 'success');
                closeModal();
                loadProducts(currentPage);
            } else {
                showToast(result.message || 'เกิดข้อผิดพลาด', 'error');
                console.error('[DEBUG] API error:', result);
            }
        } catch (err) {
            console.error('[DEBUG] Exception:', err);
            showToast('เกิดข้อผิดพลาด: ' + err.message, 'error');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-save"></i> บันทึก';
        }
    }

    // Delete product
    function deleteProduct(id, name) {
        deleteProductId = id;
        document.getElementById('deleteProductName').textContent = name;
        document.getElementById('deleteModal').style.display = 'flex';
    }

    function closeDeleteModal() {
        document.getElementById('deleteModal').style.display = 'none';
        deleteProductId = null;
    }

    async function confirmDelete() {
        if (!deleteProductId) return;

        try {
            const result = await apiCall('DELETE', { id: deleteProductId });
            if (result.success) {
                showToast(result.message, 'success');
                closeDeleteModal();
                loadProducts(currentPage);
            } else {
                showToast(result.message, 'error');
            }
        } catch (e) {
            showToast('เกิดข้อผิดพลาด', 'error');
        }
    }

    // Image handling
    function handleImageSelect(input) {
        const file = input.files[0];
        if (!file) return;

        // Validate file size (max 5MB)
        if (file.size > 5 * 1024 * 1024) {
            showImageStatus('❌ ไฟล์ใหญ่เกินไป (สูงสุด 5MB)', 'error');
            return;
        }

        // Show loading status
        showImageStatus('📤 กำลังโหลดรูป...', 'uploading');

        const reader = new FileReader();
        reader.onload = function (e) {
            document.getElementById('previewImg').src = e.target.result;
            document.getElementById('previewImg').style.display = 'block';
            document.getElementById('imagePlaceholder').style.display = 'none';
            document.getElementById('clearImageBtn').style.display = 'inline-flex';

            // Store base64 - API will handle upload to GCS
            document.getElementById('imageUrl').value = e.target.result;

            // Show success status with file info
            const sizeKB = Math.round(file.size / 1024);
            showImageStatus(`✅ เลือกรูป: ${file.name} (${sizeKB} KB) - จะอัปโหลดไป Cloud เมื่อบันทึก`, 'success');
        };
        reader.onerror = function () {
            showImageStatus('❌ ไม่สามารถอ่านไฟล์ได้', 'error');
        };
        reader.readAsDataURL(file);
    }

    function showImageStatus(message, type) {
        const status = document.getElementById('imageStatus');
        status.textContent = message;
        status.className = 'image-status ' + type;
        status.style.display = 'block';
    }

    function hideImageStatus() {
        document.getElementById('imageStatus').style.display = 'none';
    }

    function loadImageFromUrl(url) {
        if (!url) return;
        document.getElementById('previewImg').src = url;
        document.getElementById('previewImg').style.display = 'block';
        document.getElementById('imagePlaceholder').style.display = 'none';
        document.getElementById('clearImageBtn').style.display = 'inline-flex';
        document.getElementById('imageUrl').value = url;
    }

    function clearImage() {
        document.getElementById('previewImg').src = '';
        document.getElementById('previewImg').style.display = 'none';
        document.getElementById('imagePlaceholder').style.display = 'flex';
        document.getElementById('clearImageBtn').style.display = 'none';
        document.getElementById('imageUrl').value = '';
        document.getElementById('imageUrlInput').value = '';
        document.getElementById('imageFile').value = '';
        hideImageStatus();
    }

    // Click on preview to upload
    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('imagePreview').addEventListener('click', () => {
            document.getElementById('imageFile').click();
        });

        // Tags input
        document.getElementById('tagsInput').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                const value = e.target.value.trim();
                if (value && !tags.includes(value)) {
                    tags.push(value);
                    renderTags();
                }
                e.target.value = '';
            }
        });

        // Load products on page load
        loadProducts();
    });

    // Tags management
    function renderTags() {
        const container = document.getElementById('tagsList');
        container.innerHTML = tags.map((tag, i) => `
        <span class="tag-item">
            ${escapeHtml(tag)}
            <button type="button" onclick="removeTag(${i})">×</button>
        </span>
    `).join('');
        document.getElementById('tagsValue').value = JSON.stringify(tags);
    }

    function removeTag(index) {
        tags.splice(index, 1);
        renderTags();
    }

    // Utility functions
    function formatNumber(num) {
        return new Intl.NumberFormat('th-TH').format(num);
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>"']/g, m => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[m]));
    }

    function showToast(message, type = 'info') {
        const toast = document.getElementById('toast');
        toast.textContent = message;
        toast.className = 'toast show ' + type;
        setTimeout(() => { toast.className = 'toast'; }, 3000);
    }
</script>

<?php include('../includes/customer/footer.php'); ?>