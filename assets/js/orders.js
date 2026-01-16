// Orders Page JavaScript
let allOrders = [];
let currentPage = 1;
let totalPages = 1;
const ITEMS_PER_PAGE = 20;

function getQueryParam(name) {
    try {
        return new URLSearchParams(window.location.search).get(name);
    } catch {
        return null;
    }
}

function buildPaymentHistoryLinkForOrderNo(orderNo) {
    const q = orderNo ? encodeURIComponent(String(orderNo)) : '';
    const base = (typeof PATH !== 'undefined' && typeof PATH.page === 'function')
        ? PATH.page('payment-history.php')
        : '/payment-history.php';
    return q ? `${base}?order_no=${q}` : base;
}

function buildAddressesLinkForOrder(order) {
    const addressId = order?.shipping_address_id || order?.address_id || order?.customer_address_id;
    const base = (typeof PATH !== 'undefined' && typeof PATH.page === 'function')
        ? PATH.page('addresses.php')
        : '/addresses.php';

    if (addressId) return `${base}?address_id=${encodeURIComponent(String(addressId))}`;
    return base;
}

document.addEventListener('DOMContentLoaded', () => {
    loadOrders();
});

async function loadOrders(page = 1) {
    currentPage = page;
    try {
        const result = await apiCall(API_ENDPOINTS.CUSTOMER_ORDERS + `?page=${currentPage}&limit=${ITEMS_PER_PAGE}`);

        if (result && result.success) {
            // API returns { data: { orders: [...], pagination: {...} } }
            allOrders = (result.data && Array.isArray(result.data.orders)) ? result.data.orders : (result.data || []);
            const pagination = result.data?.pagination || {};
            totalPages = pagination.total_pages || 1;

            // Support deep-links from payment-history
            const targetOrderNo = getQueryParam('order_no') || getQueryParam('payment_order_no');
            if (targetOrderNo) {
                const filtered = allOrders.filter(o => String(o.order_no) === String(targetOrderNo));
                renderOrders(filtered);
                renderPagination(0, 0); // Hide pagination when filtering

                // Auto open detail if exactly one match
                if (filtered.length === 1) {
                    viewOrderDetail(filtered[0].id);
                }
            } else {
                renderOrders(allOrders);
                renderPagination(pagination.total || allOrders.length, totalPages);
            }
        } else {
            showError('ไม่สามารถโหลดข้อมูลได้');
        }
    } catch (error) {
        console.error('Error:', error);
        showError('เกิดข้อผิดพลาดในการโหลดข้อมูล');
    }
}

function renderPagination(total, pages) {
    const container = document.getElementById('ordersPagination');
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
    loadOrders(page);
}

function renderOrders(orders) {
    const tbody = document.getElementById('ordersTableBody');

    const targetOrderNo = getQueryParam('order_no');

    if (!orders || orders.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--color-gray);">ไม่พบคำสั่งซื้อ</td></tr>';
        return;
    }

    tbody.innerHTML = orders.map(order => {
        // Map status to badge class (supports both old and new status names)
        const statusClass = {
            'draft': 'secondary',
            'pending': 'warning',
            'pending_payment': 'warning',
            'paid': 'success',
            'processing': 'info',
            'shipped': 'primary',
            'delivered': 'success',
            'cancelled': 'danger',
            'refunded': 'danger'
        }[order.status] || 'secondary';

        const statusText = {
            'draft': 'ร่าง',
            'pending': 'รอดำเนินการ',
            'pending_payment': 'รอชำระเงิน',
            'paid': 'ชำระแล้ว',
            'processing': 'กำลังเตรียม',
            'shipped': 'จัดส่งแล้ว',
            'delivered': 'ส่งถึงแล้ว',
            'cancelled': 'ยกเลิก',
            'refunded': 'คืนเงิน'
        }[order.status] || order.status;

        const isHighlighted = targetOrderNo && String(order.order_no) === String(targetOrderNo);
        const rowStyle = isHighlighted ? 'background: rgba(59, 130, 246, 0.08);' : '';

        // Prepare customer profile
        const customerProfile = {
            platform: order.customer_platform || 'web',
            name: order.customer_name || 'ไม่ระบุลูกค้า',
            avatar: order.customer_avatar || null
        };
        const customerBadgeHtml = typeof renderCustomerProfileBadge === 'function'
            ? renderCustomerProfileBadge(customerProfile)
            : `<span>${customerProfile.name}</span>`;

        // Map order_type to display (supports both old and new names)
        const paymentType = order.payment_type || order.order_type || 'full_payment';
        const isFullPayment = paymentType === 'full' || paymentType === 'full_payment';
        const isInstallment = paymentType === 'installment';
        const installmentMonths = order.installment_months || 0;

        return `
            <tr onclick="viewOrderDetail(${order.id})" style="cursor:pointer;${rowStyle}">
                <td><strong>${order.order_no || order.order_number || '-'}</strong></td>
                <td>${customerBadgeHtml}</td>
                <td>
                    ${order.product_name || '-'}<br>
                    <small style="color:var(--color-gray);">${order.product_code || ''}</small>
                </td>
                <td style="text-align:right;"><strong>฿${formatNumber(order.total_amount)}</strong></td>
                <td>
                    <span class="badge badge-${isFullPayment ? 'success' : 'info'}">
                        ${isFullPayment ? '💳 จ่ายเต็ม' : (isInstallment ? '📅 ผ่อน ' + installmentMonths + ' งวด' : '💰 ออมครบ')}
                    </span>
                </td>
                <td><span class="badge badge-${statusClass}">${statusText}</span></td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="event.stopPropagation(); viewOrderDetail(${order.id});">
                        <i class="fas fa-eye"></i> ดู
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}

async function viewOrderDetail(orderId) {
    const modal = document.getElementById('orderModal');
    const content = document.getElementById('orderDetailsContent');

    modal.style.display = 'flex';
    content.innerHTML = '<p style="text-align:center;padding:2rem;">กำลังโหลด...</p>';

    try {
        const result = await apiCall(API_ENDPOINTS.CUSTOMER_ORDER_DETAIL(orderId));

        if (result && result.success) {
            const order = result.data || {};

            // Normalize API field name for installments
            if (!order.installments && Array.isArray(order.installment_schedule)) {
                order.installments = order.installment_schedule;
            }

            content.innerHTML = renderOrderDetails(order);
        } else {
            content.innerHTML = '<p style="color:var(--color-danger);text-align:center;">ไม่สามารถโหลดข้อมูลได้</p>';
        }
    } catch (error) {
        console.error('Error:', error);
        content.innerHTML = '<p style="color:var(--color-danger);text-align:center;">เกิดข้อผิดพลาด</p>';
    }
}

function renderOrderDetails(order) {
    const statusClass = order.status || 'pending';
    const statusText = {
        'pending': 'รอดำเนินการ',
        'processing': 'กำลังเตรียม',
        'shipped': 'จัดส่งแล้ว',
        'delivered': 'ส่งถึงแล้ว',
        'cancelled': 'ยกเลิก'
    }[order.status] || order.status;

    // Build customer profile section
    const customerHtml = buildCustomerSection(order);

    // Build address section
    const addressHtml = buildAddressSection(order);

    // Build installment section
    const installmentHtml = buildInstallmentSection(order);

    return `
        <!-- Customer Profile -->
        ${customerHtml}
        
        <!-- Order Info -->
        <div class="detail-section">
            <div class="detail-section-title">ข้อมูลคำสั่งซื้อ</div>
            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-label">เลขที่คำสั่งซื้อ</div>
                    <div class="detail-value">${order.order_no || order.order_number || '-'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">สถานะ</div>
                    <div class="detail-value">
                        <span class="status-badge status-${statusClass}">${statusText}</span>
                    </div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">วันที่สั่งซื้อ</div>
                    <div class="detail-value">${formatDateTime(order.created_at)}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">แหล่งที่มา</div>
                    <div class="detail-value">${order.source || '-'}</div>
                </div>
            </div>
        </div>
        
        <!-- Product Info -->
        <div class="detail-section">
            <div class="detail-section-title">รายละเอียดสินค้า</div>
            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-label">ชื่อสินค้า</div>
                    <div class="detail-value">${order.product_name || '-'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">รหัสสินค้า</div>
                    <div class="detail-value">${order.product_code || '-'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">จำนวน</div>
                    <div class="detail-value">${order.quantity || 1} ชิ้น</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">ยอดรวม</div>
                    <div class="detail-value detail-value-lg">฿${formatNumber(order.total_amount)}</div>
                </div>
            </div>
        </div>
        
        <!-- Payment Info -->
        <div class="detail-section">
            <div class="detail-section-title">การชำระเงิน</div>
            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-label">ประเภทการชำระ</div>
                    <div class="detail-value">
                        <span class="payment-type-tag">
                            ${(order.payment_type === 'full' || order.order_type === 'full_payment' || !order.payment_type) ? '💳 ชำระเต็มจำนวน' : '📅 ผ่อนชำระ ' + (order.installment_months || 0) + ' งวด'}
                        </span>
                    </div>
                </div>
                ${order.payments && order.payments.length > 0 ? `
                <div class="detail-item">
                    <div class="detail-label">การชำระล่าสุด</div>
                    <div class="detail-value">${order.payments[0].payment_no || order.payments[0].id || '-'} - ฿${formatNumber(order.payments[0].amount)}</div>
                </div>
                ` : ''}
            </div>
            
            <div class="action-buttons">
                <button class="btn-action btn-edit" onclick="openEditOrderModal(${order.id})">
                    <i class="fas fa-edit"></i> แก้ไขคำสั่งซื้อ
                </button>
                <a class="btn-action" href="${buildPaymentHistoryLinkForOrderNo(order.order_no || order.order_number)}">
                    <i class="fas fa-receipt"></i> ดูประวัติการชำระเงิน
                </a>
                <a class="btn-action" href="${buildAddressesLinkForOrder(order)}">
                    <i class="fas fa-map-marker-alt"></i> ดูที่อยู่จัดส่ง
                </a>
            </div>
        </div>
        
        <!-- Shipping Address -->
        ${addressHtml}
        
        <!-- Installment Schedule -->
        ${installmentHtml}
    `;
}

function buildCustomerSection(order) {
    const name = order.customer_name || 'ไม่ระบุลูกค้า';
    const platform = order.customer_platform || 'web';
    const avatar = validateAvatarUrl(order.customer_avatar);
    const phone = order.phone || order.recipient_phone || null;

    const initials = name.split(' ').map(n => n.charAt(0)).join('').substring(0, 2).toUpperCase();

    const avatarHtml = avatar
        ? `<img src="${avatar}" alt="${name}" onerror="this.style.display='none';this.parentElement.innerHTML='${initials}';">`
        : initials;

    const platformIcon = getPlatformIconSvg(platform);
    const platformName = { 'line': 'LINE', 'facebook': 'Facebook', 'instagram': 'Instagram', 'web': 'Web' }[platform] || platform;

    return `
        <div class="detail-section">
            <div class="detail-section-title">ข้อมูลลูกค้า</div>
            <div class="customer-section">
                <div class="customer-avatar-lg">${avatarHtml}</div>
                <div class="customer-info-detail">
                    <h4 class="customer-name-lg">${name}</h4>
                    <div class="customer-meta">
                        <span class="platform-tag ${platform}">
                            ${platformIcon} ${platformName}
                        </span>
                        ${phone ? `
                        <span class="customer-meta-item">
                            <i class="fas fa-phone"></i> ${phone}
                        </span>
                        ` : ''}
                    </div>
                </div>
            </div>
        </div>
    `;
}

function getPlatformIconSvg(platform) {
    const icons = {
        'line': '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.627-.63h2.386c.349 0 .63.285.63.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.627-.63.349 0 .631.285.631.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.349 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.281.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/></svg>',
        'facebook': '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
        'instagram': '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C8.74 0 8.333.015 7.053.072 5.775.132 4.905.333 4.14.63c-.789.306-1.459.717-2.126 1.384S.935 3.35.63 4.14C.333 4.905.131 5.775.072 7.053.012 8.333 0 8.74 0 12s.015 3.667.072 4.947c.06 1.277.261 2.148.558 2.913.306.788.717 1.459 1.384 2.126.667.666 1.336 1.079 2.126 1.384.766.296 1.636.499 2.913.558C8.333 23.988 8.74 24 12 24s3.667-.015 4.947-.072c1.277-.06 2.148-.262 2.913-.558.788-.306 1.459-.718 2.126-1.384.666-.667 1.079-1.335 1.384-2.126.296-.765.499-1.636.558-2.913.06-1.28.072-1.687.072-4.947s-.015-3.667-.072-4.947c-.06-1.277-.262-2.149-.558-2.913-.306-.789-.718-1.459-1.384-2.126C21.319 1.347 20.651.935 19.86.63c-.765-.297-1.636-.499-2.913-.558C15.667.012 15.26 0 12 0zm0 2.16c3.203 0 3.585.016 4.85.071 1.17.055 1.805.249 2.227.415.562.217.96.477 1.382.896.419.42.679.819.896 1.381.164.422.36 1.057.413 2.227.057 1.266.07 1.646.07 4.85s-.015 3.585-.074 4.85c-.061 1.17-.256 1.805-.421 2.227-.224.562-.479.96-.899 1.382-.419.419-.824.679-1.38.896-.42.164-1.065.36-2.235.413-1.274.057-1.649.07-4.859.07-3.211 0-3.586-.015-4.859-.074-1.171-.061-1.816-.256-2.236-.421-.569-.224-.96-.479-1.379-.899-.421-.419-.69-.824-.9-1.38-.165-.42-.359-1.065-.42-2.235-.045-1.26-.061-1.649-.061-4.844 0-3.196.016-3.586.061-4.861.061-1.17.255-1.814.42-2.234.21-.57.479-.96.9-1.381.419-.419.81-.689 1.379-.898.42-.166 1.051-.361 2.221-.421 1.275-.045 1.65-.06 4.859-.06l.045.03zm0 3.678c-3.405 0-6.162 2.76-6.162 6.162 0 3.405 2.76 6.162 6.162 6.162 3.405 0 6.162-2.76 6.162-6.162 0-3.405-2.757-6.162-6.162-6.162zM12 16c-2.21 0-4-1.79-4-4s1.79-4 4-4 4 1.79 4 4-1.79 4-4 4zm7.846-10.405c0 .795-.646 1.44-1.44 1.44-.795 0-1.44-.646-1.44-1.44 0-.794.646-1.439 1.44-1.439.793-.001 1.44.645 1.44 1.439z"/></svg>'
    };
    return icons[platform] || '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/></svg>';
}

function validateAvatarUrl(url) {
    if (!url) return null;
    const invalidPatterns = ['default_avatar', 'placeholder', 'no-image', 'no_image'];
    const urlLower = url.toLowerCase();
    for (const pattern of invalidPatterns) {
        if (urlLower.includes(pattern)) return null;
    }
    if (!url.startsWith('http://') && !url.startsWith('https://') && !url.startsWith('/')) return null;
    if (url.length > 500) return null;
    return url;
}

function buildAddressSection(order) {
    // Check if we have address info
    if (!order.recipient_name && !order.address_line1) {
        return '';
    }

    const addressParts = [
        order.address_line1,
        order.address_line2,
        order.subdistrict,
        order.district,
        order.province,
        order.postal_code
    ].filter(Boolean);

    return `
        <div class="detail-section">
            <div class="detail-section-title">ที่อยู่จัดส่ง</div>
            <div class="address-block">
                <div class="address-name">${order.recipient_name || '-'}</div>
                <div class="address-phone">${order.phone || '-'}</div>
                <div>${addressParts.join(' ') || '-'}</div>
            </div>
        </div>
    `;
}

function buildInstallmentSection(order) {
    if (!order.installments || order.installments.length === 0) {
        return '';
    }

    return `
        <div class="detail-section">
            <div class="detail-section-title">ตารางผ่อนชำระ (${order.installment_months || order.installments.length} งวด)</div>
            <table class="installment-table">
                <thead>
                    <tr>
                        <th>งวดที่</th>
                        <th>วันครบกำหนด</th>
                        <th style="text-align:right;">จำนวนเงิน</th>
                        <th>สถานะ</th>
                    </tr>
                </thead>
                <tbody>
                    ${order.installments.map(inst => `
                        <tr>
                            <td><strong>${inst.period_number}</strong></td>
                            <td>${formatDate(inst.due_date)}</td>
                            <td style="text-align:right;">฿${formatNumber(inst.amount)}</td>
                            <td>
                                <span class="inst-status ${inst.status}">
                                    ${inst.status === 'paid' ? '✓ ชำระแล้ว' : inst.status === 'overdue' ? '⚠ เกินกำหนด' : '⏳ รอชำระ'}
                                </span>
                            </td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>
    `;
}

function closeOrderModal() {
    document.getElementById('orderModal').style.display = 'none';
}

function formatNumber(num) {
    const n = Number(num);
    if (!Number.isFinite(n)) return '0.00';
    return n.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatDate(date) {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('th-TH');
}

function formatDateTime(date) {
    if (!date) return '-';
    return new Date(date).toLocaleString('th-TH');
}

function showError(message) {
    const tbody = document.getElementById('ordersTableBody');
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--color-danger);">${message}</td></tr>`;
}

// =========================================================
// CREATE ORDER MODAL FUNCTIONS
// =========================================================

let productSearchTimeout = null;
let customerSearchTimeout = null;
let selectedProduct = null;
let selectedCustomer = null;

/**
 * Open Create Order Modal
 */
function openCreateOrderModal() {
    const modal = document.getElementById('createOrderModal');
    if (modal) {
        modal.style.display = 'flex';
        resetCreateOrderForm();
    }
}

/**
 * Close Create Order Modal
 */
function closeCreateOrderModal() {
    const modal = document.getElementById('createOrderModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

/**
 * Reset Create Order Form
 */
function resetCreateOrderForm() {
    const form = document.getElementById('createOrderForm');
    if (form) {
        form.reset();
    }
    selectedProduct = null;
    selectedCustomer = null;
    clearSelectedProduct();
    clearSelectedCustomer();
    document.getElementById('productSearchResults').style.display = 'none';
    const customerResults = document.getElementById('customerSearchResults');
    if (customerResults) customerResults.style.display = 'none';
    toggleInstallmentFields();
}

/**
 * Search Products via API
 * @param {string} query - Search query
 */
async function searchProducts(query) {
    const resultsContainer = document.getElementById('productSearchResults');

    // Clear timeout if exists
    if (productSearchTimeout) {
        clearTimeout(productSearchTimeout);
    }

    // Require minimum 2 characters
    if (!query || query.trim().length < 2) {
        resultsContainer.style.display = 'none';
        return;
    }

    // Debounce search (300ms)
    productSearchTimeout = setTimeout(async () => {
        try {
            resultsContainer.innerHTML = '<div class="autocomplete-loading"><i class="fas fa-spinner fa-spin"></i> กำลังค้นหา...</div>';
            resultsContainer.style.display = 'block';

            // Call Product Search API
            const apiUrl = (typeof API_ENDPOINTS !== 'undefined' && API_ENDPOINTS.PRODUCTS_SEARCH)
                ? API_ENDPOINTS.PRODUCTS_SEARCH
                : '/api/products/search';

            const result = await apiCall(`${apiUrl}?q=${encodeURIComponent(query.trim())}&limit=10`);

            if (result && result.success && result.data && result.data.length > 0) {
                renderProductSearchResults(result.data);
            } else if (result && result.ok && result.data && result.data.products) {
                // Alternative response format from productSearch API
                renderProductSearchResults(result.data.products);
            } else {
                resultsContainer.innerHTML = `
                    <div class="autocomplete-empty">
                        <p>ไม่พบสินค้าที่ตรงกับ "${escapeHtml(query)}"</p>
                        <small>ลองค้นหาด้วยคำอื่น หรือกรอกข้อมูลเองด้านล่าง</small>
                    </div>
                `;
            }
        } catch (error) {
            console.error('Product search error:', error);
            resultsContainer.innerHTML = `
                <div class="autocomplete-empty">
                    <p>⚠️ ไม่สามารถค้นหาได้ (API ยังไม่พร้อม)</p>
                    <small>กรุณากรอกข้อมูลสินค้าเองด้านล่าง</small>
                </div>
            `;
        }
    }, 300);
}

/**
 * Render Product Search Results
 * @param {Array} products - Array of products
 */
function renderProductSearchResults(products) {
    const resultsContainer = document.getElementById('productSearchResults');

    if (!products || products.length === 0) {
        resultsContainer.innerHTML = '<div class="autocomplete-empty">ไม่พบสินค้า</div>';
        return;
    }

    resultsContainer.innerHTML = products.map(product => {
        const name = product.name || product.title || product.product_name || 'สินค้า';
        const code = product.sku || product.code || product.product_code || '';
        const price = product.price || product.selling_price || 0;
        const placeholderImg = typeof PATH !== 'undefined' ? PATH.asset('images/placeholder-product.svg') : '/images/placeholder-product.svg';
        const image = product.image_url || product.thumbnail || product.images?.[0] || placeholderImg;
        const brand = product.brand || '';

        return `
            <div class="autocomplete-item" onclick="selectProduct(${JSON.stringify(product).replace(/"/g, '&quot;')})">
                <img src="${escapeHtml(image)}" alt="${escapeHtml(name)}" class="autocomplete-item-img" 
                     onerror="this.onerror=null; this.src='${placeholderImg}'">
                <div class="autocomplete-item-info">
                    <div class="autocomplete-item-name">${escapeHtml(name)}</div>
                    <div class="autocomplete-item-meta">
                        ${code ? `รหัส: ${escapeHtml(code)}` : ''}
                        ${brand ? ` • ${escapeHtml(brand)}` : ''}
                    </div>
                </div>
                <div class="autocomplete-item-price">฿${formatNumber(price)}</div>
            </div>
        `;
    }).join('');

    resultsContainer.style.display = 'block';
}

/**
 * Select a product from search results
 * @param {Object} product - Selected product object
 */
function selectProduct(product) {
    selectedProduct = product;

    const name = product.name || product.title || product.product_name || 'สินค้า';
    const code = product.sku || product.code || product.product_code || '';
    const price = product.price || product.selling_price || 0;
    const placeholderImg = typeof PATH !== 'undefined' ? PATH.asset('images/placeholder-product.svg') : '/images/placeholder-product.svg';
    const image = product.image_url || product.thumbnail || product.images?.[0] || placeholderImg;

    // Show selected product card
    document.getElementById('selectedProductCard').style.display = 'flex';
    document.getElementById('selectedProductImg').src = image;
    document.getElementById('selectedProductName').textContent = name;
    document.getElementById('selectedProductCode').textContent = `รหัส: ${code || '-'}`;
    document.getElementById('selectedProductPrice').textContent = `฿${formatNumber(price)}`;

    // Fill form fields
    document.getElementById('productName').value = name;
    document.getElementById('productCode').value = code;
    document.getElementById('totalAmount').value = price;

    // Set hidden fields
    document.getElementById('selectedProductId').value = product.id || product.product_id || '';
    document.getElementById('selectedProductSku').value = code;

    // Hide search results and clear search input
    document.getElementById('productSearchResults').style.display = 'none';
    document.getElementById('productSearch').value = '';
}

/**
 * Clear selected product
 */
function clearSelectedProduct() {
    selectedProduct = null;
    document.getElementById('selectedProductCard').style.display = 'none';
    document.getElementById('selectedProductId').value = '';
    document.getElementById('selectedProductSku').value = '';
}

/**
 * Handle payment type click - prevents scroll issues
 * @param {Event} event - Click event
 * @param {string} value - Payment type value
 */
function handlePaymentTypeClick(event, value) {
    // Prevent default label behavior that causes scroll
    event.preventDefault();
    event.stopPropagation();
    
    // Save current scroll position of modal body
    const modalBody = document.querySelector('#createOrderModal .order-modal-body');
    const scrollTop = modalBody ? modalBody.scrollTop : 0;
    
    // Check the radio button
    const radio = document.querySelector(`input[name="payment_type"][value="${value}"]`);
    if (radio) {
        radio.checked = true;
    }
    
    // Toggle installment fields
    toggleInstallmentFields();
    
    // Restore scroll position after a short delay
    if (modalBody) {
        requestAnimationFrame(() => {
            modalBody.scrollTop = scrollTop;
        });
    }
}

/**
 * Toggle installment fields visibility
 */
function toggleInstallmentFields() {
    const paymentType = document.querySelector('input[name="payment_type"]:checked')?.value || 'full';
    const installmentFields = document.getElementById('installmentFields');

    if (installmentFields) {
        installmentFields.style.display = paymentType === 'installment' ? 'block' : 'none';
    }
}

/**
 * Update message template when bank account is selected
 */
function updateMessageTemplate() {
    const select = document.getElementById('bankAccount');
    const textarea = document.getElementById('customerMessage');
    const customerName = document.getElementById('customerName')?.value?.trim() || 'ลูกค้า';
    const totalAmount = document.getElementById('totalAmount')?.value || '0';
    const productName = document.getElementById('productName')?.value?.trim() || 'สินค้า';
    
    if (!select || !textarea) return;
    
    const selectedOption = select.options[select.selectedIndex];
    if (!selectedOption || !selectedOption.value) {
        textarea.value = '';
        return;
    }
    
    const bankName = selectedOption.dataset.bank || '';
    const accountName = selectedOption.dataset.name || '';
    const accountNumber = selectedOption.dataset.number || '';
    
    const template = `ขอบพระคุณค่ะ คุณ${customerName} 
ที่ไว้วางใจเลือกซื้อ ${productName} จากร้าน ฮ.เฮง เฮง 💎

💰 ยอดชำระ: ${formatNumber(parseFloat(totalAmount) || 0)} บาท

🏦 ข้อมูลการโอนเงิน
ธนาคาร: ${bankName}
ชื่อบัญชี: ${accountName}
เลขบัญชี: ${accountNumber}

หากคุณ${customerName} ชำระแล้วแจ้งสลิปให้แอดมินได้เลยนะคะ ขอบพระคุณค่ะ 🙏`;
    
    textarea.value = template;
}

/**
 * Submit Create Order Form
 * @param {Event} event - Form submit event
 */
async function submitCreateOrder(event) {
    event.preventDefault();

    const form = document.getElementById('createOrderForm');
    const submitBtn = document.getElementById('submitOrderBtn');

    // Validate required fields
    const productName = document.getElementById('productName').value.trim();
    const totalAmount = document.getElementById('totalAmount').value;
    const quantity = document.getElementById('quantity').value;

    if (!productName) {
        showToast('กรุณาระบุชื่อสินค้า', 'error');
        document.getElementById('productName').focus();
        return false;
    }

    if (!totalAmount || parseFloat(totalAmount) <= 0) {
        showToast('กรุณาระบุยอดเงิน', 'error');
        document.getElementById('totalAmount').focus();
        return false;
    }

    // Disable submit button
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังบันทึก...';

    try {
        // Build order data
        const paymentType = document.querySelector('input[name="payment_type"]:checked')?.value || 'full';

        const orderData = {
            product_name: productName,
            product_code: document.getElementById('productCode').value.trim(),
            product_id: document.getElementById('selectedProductId').value || null,
            quantity: parseInt(quantity) || 1,
            total_amount: parseFloat(totalAmount),
            payment_type: paymentType,
            source: document.getElementById('customerSource').value,
            customer_name: document.getElementById('customerName').value.trim() || null,
            customer_phone: document.getElementById('customerPhone').value.trim() || null,
            customer_id: document.getElementById('selectedCustomerId').value || null,
            notes: document.getElementById('orderNotes').value.trim() || null,
            // Push message fields
            bank_account: document.getElementById('bankAccount')?.value || null,
            customer_message: document.getElementById('customerMessage')?.value?.trim() || null,
            send_message: document.getElementById('sendMessageCheckbox')?.checked || false
        };

        // Add installment fields if applicable
        if (paymentType === 'installment') {
            orderData.installment_months = parseInt(document.getElementById('installmentMonths').value) || 3;
            orderData.down_payment = parseFloat(document.getElementById('downPayment').value) || 0;
        }

        // Call API to create order
        const apiUrl = (typeof API_ENDPOINTS !== 'undefined' && API_ENDPOINTS.CUSTOMER_ORDERS)
            ? API_ENDPOINTS.CUSTOMER_ORDERS
            : '/api/customer/orders';

        const result = await apiCall(apiUrl, {
            method: 'POST',
            body: orderData
        });

        if (result && result.success) {
            // Show different message based on whether push message was sent
            const messageSent = result.data?.message_sent;
            if (messageSent) {
                showToast('✅ สร้างคำสั่งซื้อ & ส่งข้อความแจ้งลูกค้าแล้ว', 'success');
            } else {
                showToast('✅ สร้างคำสั่งซื้อเรียบร้อย', 'success');
            }
            closeCreateOrderModal();

            // Reload orders list
            await loadOrders();

            // Open the new order detail if we have the ID
            if (result.data && result.data.id) {
                setTimeout(() => {
                    viewOrderDetail(result.data.id);
                }, 500);
            }
        } else {
            showToast('❌ ' + (result?.message || 'ไม่สามารถสร้างคำสั่งซื้อได้'), 'error');
        }
    } catch (error) {
        console.error('Create order error:', error);
        showToast('❌ เกิดข้อผิดพลาดในการสร้างคำสั่งซื้อ', 'error');
    } finally {
        // Re-enable submit button
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-save"></i> บันทึก & ส่งข้อความ';
    }

    return false;
}

/**
 * Show toast notification
 */
function showToast(message, type = 'info') {
    const toast = document.getElementById('toast');
    if (!toast) return;

    toast.textContent = message;
    toast.className = `toast ${type} show`;

    setTimeout(() => {
        toast.classList.remove('show');
    }, 3000);
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}

// =========================================================
// CUSTOMER SEARCH FUNCTIONS
// =========================================================

/**
 * Search Customers via API
 * @param {string} query - Search query
 */
async function searchCustomers(query) {
    const resultsContainer = document.getElementById('customerSearchResults');

    // Clear timeout if exists
    if (customerSearchTimeout) {
        clearTimeout(customerSearchTimeout);
    }

    // Require minimum 2 characters
    if (!query || query.trim().length < 2) {
        resultsContainer.style.display = 'none';
        return;
    }

    // Debounce search (300ms)
    customerSearchTimeout = setTimeout(async () => {
        try {
            resultsContainer.innerHTML = '<div class="autocomplete-loading"><i class="fas fa-spinner fa-spin"></i> กำลังค้นหา...</div>';
            resultsContainer.style.display = 'block';

            // Call Customer Search API (conversations-based for now)
            const apiUrl = '/api/customer/search.php';

            const result = await apiCall(`${apiUrl}?q=${encodeURIComponent(query.trim())}&limit=10`);

            if (result && result.success && result.data && result.data.length > 0) {
                renderCustomerSearchResults(result.data);
            } else {
                resultsContainer.innerHTML = `
                    <div class="autocomplete-empty">
                        <p>ไม่พบลูกค้าที่ตรงกับ "${escapeHtml(query)}"</p>
                        <small>กรอกข้อมูลลูกค้าใหม่ด้านล่าง</small>
                    </div>
                `;
            }
        } catch (error) {
            console.error('Customer search error:', error);
            resultsContainer.innerHTML = `
                <div class="autocomplete-empty">
                    <p>⚠️ ไม่สามารถค้นหาได้</p>
                    <small>กรุณากรอกข้อมูลลูกค้าเองด้านล่าง</small>
                </div>
            `;
        }
    }, 300);
}

/**
 * Render Customer Search Results
 * @param {Array} customers - Array of customers
 */
function renderCustomerSearchResults(customers) {
    const resultsContainer = document.getElementById('customerSearchResults');

    if (!customers || customers.length === 0) {
        resultsContainer.innerHTML = '<div class="autocomplete-empty">ไม่พบลูกค้า</div>';
        return;
    }

    resultsContainer.innerHTML = customers.map(customer => {
        const name = customer.display_name || customer.platform_user_name || customer.full_name || 'ลูกค้า';
        const phone = customer.phone || '';
        const platform = customer.platform || customer.source || 'web';
        const avatar = customer.avatar_url || customer.line_picture_url || customer.facebook_picture_url || null;
        const initials = name.split(' ').map(n => n.charAt(0)).join('').substring(0, 2).toUpperCase();

        const avatarHtml = avatar
            ? `<img src="${escapeHtml(avatar)}" alt="${escapeHtml(name)}" onerror="this.style.display='none';this.parentElement.innerHTML='${initials}';">`
            : initials;

        const platformLabel = {
            'line': 'LINE',
            'facebook': 'Facebook',
            'instagram': 'Instagram',
            'web': 'Web'
        }[platform] || platform;

        return `
            <div class="autocomplete-item" onclick='selectCustomer(${JSON.stringify(customer).replace(/'/g, "\\'")})'>
                <div class="autocomplete-item-avatar">${avatarHtml}</div>
                <div class="autocomplete-item-info">
                    <div class="autocomplete-item-name">${escapeHtml(name)}</div>
                    <div class="autocomplete-item-meta">
                        ${phone ? `📞 ${escapeHtml(phone)}` : ''}
                        ${customer.external_user_id ? ` • ID: ${escapeHtml(customer.external_user_id.substring(0, 10))}...` : ''}
                    </div>
                </div>
                <span class="autocomplete-item-platform ${platform}">${platformLabel}</span>
            </div>
        `;
    }).join('');

    resultsContainer.style.display = 'block';
}

/**
 * Select a customer from search results
 * @param {Object} customer - Selected customer object
 */
function selectCustomer(customer) {
    selectedCustomer = customer;

    const name = customer.display_name || customer.platform_user_name || customer.full_name || 'ลูกค้า';
    const phone = customer.phone || '';
    const platform = customer.platform || customer.source || 'web';
    const avatar = customer.avatar_url || customer.line_picture_url || customer.facebook_picture_url || null;
    const initials = name.split(' ').map(n => n.charAt(0)).join('').substring(0, 2).toUpperCase();

    // Show selected customer card
    document.getElementById('selectedCustomerCard').style.display = 'flex';

    const avatarContainer = document.getElementById('selectedCustomerAvatar');
    if (avatar) {
        avatarContainer.innerHTML = `<img src="${escapeHtml(avatar)}" alt="${escapeHtml(name)}" onerror="this.style.display='none';this.parentElement.innerHTML='${initials}';">`;
    } else {
        avatarContainer.innerHTML = initials;
    }

    document.getElementById('selectedCustomerName').textContent = name;

    const platformLabel = {
        'line': 'LINE',
        'facebook': 'Facebook',
        'instagram': 'Instagram',
        'web': 'Web'
    }[platform] || platform;
    document.getElementById('selectedCustomerMeta').textContent = `${platformLabel}${phone ? ' • ' + phone : ''}`;

    // Fill form fields
    document.getElementById('customerName').value = name;
    if (phone) document.getElementById('customerPhone').value = phone;

    // Set source dropdown
    const sourceSelect = document.getElementById('customerSource');
    if (sourceSelect) {
        const platformOption = sourceSelect.querySelector(`option[value="${platform}"]`);
        if (platformOption) {
            sourceSelect.value = platform;
        }
    }

    // Set hidden field
    document.getElementById('selectedCustomerId').value = customer.id || customer.customer_id || customer.external_user_id || '';

    // Hide search results and clear search input
    document.getElementById('customerSearchResults').style.display = 'none';
    document.getElementById('customerSearch').value = '';
}

/**
 * Clear selected customer
 */
function clearSelectedCustomer() {
    selectedCustomer = null;
    const card = document.getElementById('selectedCustomerCard');
    if (card) card.style.display = 'none';
    const hiddenField = document.getElementById('selectedCustomerId');
    if (hiddenField) hiddenField.value = '';
}

// Close modals on ESC key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeCreateOrderModal();
        closeOrderModal();
    }
});

// Close dropdowns when clicking outside
document.addEventListener('click', (e) => {
    // Product search dropdown
    const productSearchWrapper = document.querySelector('#productSearch')?.closest('.autocomplete-wrapper');
    const productResultsContainer = document.getElementById('productSearchResults');
    if (productSearchWrapper && productResultsContainer && !productSearchWrapper.contains(e.target)) {
        productResultsContainer.style.display = 'none';
    }

    // Customer search dropdown
    const customerSearchWrapper = document.querySelector('#customerSearch')?.closest('.autocomplete-wrapper');
    const customerResultsContainer = document.getElementById('customerSearchResults');
    if (customerSearchWrapper && customerResultsContainer && !customerSearchWrapper.contains(e.target)) {
        customerResultsContainer.style.display = 'none';
    }
});

// =========================================================
// EDIT ORDER FUNCTIONS
// =========================================================

let currentEditOrderId = null;
let currentEditOrderData = null;

/**
 * Open Edit Order Modal
 */
async function openEditOrderModal(orderId) {
    currentEditOrderId = orderId;

    // Close view modal first
    closeOrderModal();

    // Fetch order data
    try {
        const result = await apiCall(API_ENDPOINTS.CUSTOMER_ORDER_DETAIL(orderId));

        if (result && result.success && result.data) {
            currentEditOrderData = result.data;
            showEditOrderForm(result.data);
        } else {
            showToast('ไม่สามารถโหลดข้อมูลคำสั่งซื้อได้', 'error');
        }
    } catch (error) {
        console.error('Error loading order:', error);
        showToast('เกิดข้อผิดพลาดในการโหลดข้อมูล', 'error');
    }
}

/**
 * Show Edit Order Form in Modal
 */
function showEditOrderForm(order) {
    const modal = document.getElementById('orderModal');
    const content = document.getElementById('orderDetailsContent');

    modal.style.display = 'flex';

    const statusOptions = [
        { value: 'draft', label: 'ร่าง' },
        { value: 'pending', label: 'รอดำเนินการ' },
        { value: 'pending_payment', label: 'รอชำระเงิน' },
        { value: 'paid', label: 'ชำระแล้ว' },
        { value: 'processing', label: 'กำลังเตรียม' },
        { value: 'shipped', label: 'จัดส่งแล้ว' },
        { value: 'delivered', label: 'ส่งถึงแล้ว' },
        { value: 'cancelled', label: 'ยกเลิก' },
        { value: 'refunded', label: 'คืนเงิน' }
    ];

    const currentStatus = order.status || 'pending';

    content.innerHTML = `
        <div class="edit-order-form">
            <h3 style="margin-bottom: 1.5rem; color: var(--color-primary);">
                <i class="fas fa-edit"></i> แก้ไขคำสั่งซื้อ #${order.order_no || order.order_number || order.id}
            </h3>
            
            <form id="editOrderForm" onsubmit="submitEditOrder(event)">
                <input type="hidden" id="editOrderId" value="${order.id}">
                
                <div class="form-section">
                    <h4>ข้อมูลสินค้า</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="editProductName">ชื่อสินค้า</label>
                            <input type="text" id="editProductName" name="product_name" 
                                   value="${escapeHtml(order.product_name || '')}" class="form-input">
                        </div>
                        <div class="form-group">
                            <label for="editProductCode">รหัสสินค้า</label>
                            <input type="text" id="editProductCode" name="product_code" 
                                   value="${escapeHtml(order.product_code || '')}" class="form-input">
                        </div>
                        <div class="form-group">
                            <label for="editQuantity">จำนวน</label>
                            <input type="number" id="editQuantity" name="quantity" min="1"
                                   value="${order.quantity || 1}" class="form-input">
                        </div>
                        <div class="form-group">
                            <label for="editTotalAmount">ยอดรวม (บาท)</label>
                            <input type="number" id="editTotalAmount" name="total_amount" step="0.01" min="0"
                                   value="${order.total_amount || 0}" class="form-input">
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h4>สถานะ</h4>
                    <div class="form-group">
                        <label for="editStatus">สถานะคำสั่งซื้อ</label>
                        <select id="editStatus" name="status" class="form-input form-select">
                            ${statusOptions.map(opt =>
        `<option value="${opt.value}" ${currentStatus === opt.value ? 'selected' : ''}>${opt.label}</option>`
    ).join('')}
                        </select>
                    </div>
                </div>
                
                <div class="form-section">
                    <h4>ข้อมูลลูกค้า</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="editCustomerName">ชื่อลูกค้า</label>
                            <input type="text" id="editCustomerName" name="customer_name" 
                                   value="${escapeHtml(order.customer_name || '')}" class="form-input">
                        </div>
                        <div class="form-group">
                            <label for="editCustomerPhone">เบอร์โทร</label>
                            <input type="text" id="editCustomerPhone" name="customer_phone" 
                                   value="${escapeHtml(order.customer_phone || '')}" class="form-input">
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h4>การจัดส่ง</h4>
                    <div class="form-group">
                        <label for="editTrackingNumber">เลขพัสดุ (Tracking Number)</label>
                        <input type="text" id="editTrackingNumber" name="tracking_number" 
                               value="${escapeHtml(order.tracking_number || '')}" class="form-input"
                               placeholder="กรอกเลขพัสดุ">
                    </div>
                </div>
                
                <div class="form-section">
                    <h4>หมายเหตุ</h4>
                    <div class="form-group">
                        <textarea id="editNotes" name="notes" class="form-input form-textarea" rows="3"
                                  placeholder="หมายเหตุเพิ่มเติม">${escapeHtml(order.notes || order.note || '')}</textarea>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeOrderModal()">
                        <i class="fas fa-times"></i> ยกเลิก
                    </button>
                    <button type="submit" class="btn btn-primary" id="submitEditBtn">
                        <i class="fas fa-save"></i> บันทึกการแก้ไข
                    </button>
                </div>
            </form>
        </div>
    `;
}

/**
 * Submit Edit Order Form
 */
async function submitEditOrder(event) {
    event.preventDefault();

    const submitBtn = document.getElementById('submitEditBtn');
    const orderId = document.getElementById('editOrderId').value;

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังบันทึก...';

    try {
        // Build form data
        const formData = new FormData();
        formData.append('id', orderId);
        formData.append('action', 'update');
        formData.append('product_name', document.getElementById('editProductName').value.trim());
        formData.append('product_code', document.getElementById('editProductCode').value.trim());
        formData.append('quantity', document.getElementById('editQuantity').value);
        formData.append('total_amount', document.getElementById('editTotalAmount').value);
        formData.append('status', document.getElementById('editStatus').value);
        formData.append('customer_name', document.getElementById('editCustomerName').value.trim());
        formData.append('customer_phone', document.getElementById('editCustomerPhone').value.trim());
        formData.append('tracking_number', document.getElementById('editTrackingNumber').value.trim());
        formData.append('notes', document.getElementById('editNotes').value.trim());

        const apiUrl = (typeof API_ENDPOINTS !== 'undefined' && API_ENDPOINTS.CUSTOMER_ORDERS)
            ? API_ENDPOINTS.CUSTOMER_ORDERS + '?action=update'
            : '/api/customer/orders?action=update';

        const response = await fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${localStorage.getItem('auth_token')}`
            },
            body: formData
        });

        const result = await response.json();

        if (result && result.success) {
            showToast('✅ บันทึกการแก้ไขเรียบร้อย', 'success');
            closeOrderModal();
            await loadOrders(currentPage);
        } else {
            showToast('❌ ' + (result?.message || 'ไม่สามารถบันทึกได้'), 'error');
        }
    } catch (error) {
        console.error('Update order error:', error);
        showToast('❌ เกิดข้อผิดพลาดในการบันทึก', 'error');
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-save"></i> บันทึกการแก้ไข';
    }

    return false;
}
