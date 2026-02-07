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

/**
 * Render payment progress bar showing % paid
 * For installment orders, use financed_amount (includes 3% fee) as the base
 */
function renderPaymentProgress(order) {
    // ✅ For installment orders, use financed_amount (product + 3% fee)
    const isInstallment = order.order_type === 'installment' || order.payment_type === 'installment';
    const financedAmount = parseFloat(order.financed_amount) || 0;
    const productPrice = parseFloat(order.product_price) || parseFloat(order.total_amount) || 0;
    const serviceFee = parseFloat(order.service_fee) || (financedAmount - productPrice);
    
    // Use financed_amount for installment, else total_amount
    const total = isInstallment && financedAmount > 0 ? financedAmount : (parseFloat(order.total_amount) || 0);
    
    // For installment, prefer contract_paid_amount over order.paid_amount
    const paid = isInstallment && order.contract_paid_amount !== undefined 
        ? parseFloat(order.contract_paid_amount) || 0 
        : parseFloat(order.paid_amount) || 0;

    if (total <= 0) return '';

    const percent = Math.min(100, Math.round((paid / total) * 100));
    const remaining = total - paid;

    // Color based on progress
    let color = '#dc2626'; // red - not paid
    if (percent >= 100) color = '#059669'; // green - fully paid
    else if (percent >= 50) color = '#0284c7'; // blue - half paid
    else if (percent > 0) color = '#d97706'; // orange - partial

    return `
        <div style="margin-top:4px;">
            <div style="display:flex;justify-content:space-between;font-size:0.75rem;color:#6b7280;">
                <span>ชำระแล้ว ฿${formatNumber(paid)}</span>
                <span style="font-weight:600;color:${color}">${percent}%</span>
            </div>
            <div style="background:#e5e7eb;border-radius:4px;height:6px;margin-top:2px;overflow:hidden;">
                <div style="background:${color};height:100%;width:${percent}%;transition:width 0.3s;"></div>
            </div>
            ${remaining > 0 ? `<div style="font-size:0.7rem;color:#9ca3af;margin-top:2px;">คงเหลือ ฿${formatNumber(remaining)}</div>` : ''}
        </div>
    `;
}

document.addEventListener('DOMContentLoaded', () => {
    loadOrders();

    // Check if coming from Case with prefill data
    const fromCase = getQueryParam('from_case');
    const createMode = getQueryParam('create');

    if (fromCase || createMode === '1') {
        // Open create modal and prefill from URL params
        setTimeout(() => {
            openCreateOrderModal();
            prefillOrderFromUrlParams();
        }, 300);
    }
});

/**
 * Prefill order form from URL parameters (e.g., from Case page)
 */
function prefillOrderFromUrlParams() {
    const params = new URLSearchParams(window.location.search);

    // Product info
    const productName = params.get('product_name');
    const productCode = params.get('product_code');
    const totalAmount = params.get('total_amount');
    const downPayment = params.get('down_payment');
    const productImage = params.get('product_image');

    // Customer info
    const customerName = params.get('customer_name');
    const customerPhone = params.get('customer_phone');
    const customerId = params.get('customer_id');
    const source = params.get('source');
    const externalUserId = params.get('external_user_id');

    // Payment type
    const paymentType = params.get('payment_type');

    // Shipping method
    const shippingMethod = params.get('shipping_method');

    // Notes
    const notes = params.get('notes');

    // Case reference
    const fromCase = params.get('from_case');

    // Fill product fields
    if (productName) {
        const el = document.getElementById('productName');
        if (el) el.value = productName;
    }
    if (productCode) {
        const el = document.getElementById('productCode');
        if (el) el.value = productCode;
    }
    if (totalAmount) {
        const el = document.getElementById('totalAmount');
        if (el) el.value = totalAmount;
    }
    if (productImage) {
        const el = document.getElementById('productImageUrl');
        if (el) el.value = productImage;
        console.log('[prefillOrder] Set productImageUrl:', productImage);

        // Also display the product image in the UI
        const imgEl = document.getElementById('selectedProductImg');
        if (imgEl) {
            imgEl.src = productImage;
            console.log('[prefillOrder] Set product image src:', productImage);
        }

        // Show the product card (it's hidden by default)
        const cardEl = document.getElementById('selectedProductCard');
        if (cardEl) {
            cardEl.style.display = 'flex';
            console.log('[prefillOrder] Show selectedProductCard');
        }
    }

    // Fill customer fields
    if (customerName) {
        const el = document.getElementById('customerName');
        if (el) el.value = customerName;
    }
    if (customerPhone) {
        const el = document.getElementById('customerPhone');
        if (el) el.value = customerPhone;
    }
    if (customerId) {
        const el = document.getElementById('selectedCustomerId');
        if (el) el.value = customerId;
    }
    if (source) {
        const el = document.getElementById('customerSource');
        if (el) el.value = source;
    }
    if (externalUserId) {
        // Store for push message later
        const el = document.getElementById('externalUserId');
        if (el) el.value = externalUserId;
    }

    // Select payment type
    if (paymentType) {
        const radio = document.querySelector(`input[name="payment_type"][value="${paymentType}"]`);
        if (radio) {
            radio.checked = true;
            // Always call toggleInstallmentFields to show/hide deposit or installment fields
            toggleInstallmentFields();
        }
    }

    // Select shipping method
    if (shippingMethod) {
        const shippingSelect = document.getElementById('shippingMethod');
        if (shippingSelect) {
            shippingSelect.value = shippingMethod;
            console.log('[prefillOrder] Set shippingMethod:', shippingMethod);
            // ✅ FIX: Call toggleShippingFields directly instead of dispatchEvent
            // dispatchEvent doesn't work with onchange attribute in HTML
            if (typeof toggleShippingFields === 'function') {
                toggleShippingFields();
            }
        }
    }

    // ✅ NEW: Fill shipping address from case checkout flow
    const shippingAddress = params.get('shipping_address');
    const recipientName = params.get('recipient_name');
    const recipientPhone = params.get('recipient_phone');
    const addressLine1 = params.get('address_line1');
    const district = params.get('district');
    const province = params.get('province');
    const postalCode = params.get('postal_code');

    // ✅ FIX: If we have shipping_address, make sure to show the address fields first
    if (shippingAddress || addressLine1) {
        const addressFields = document.getElementById('shippingAddressFields');
        if (addressFields) {
            addressFields.style.display = 'block';
            console.log('[prefillOrder] Force showing shippingAddressFields for address data');
        }
    }

    if (shippingAddress) {
        const el = document.getElementById('shippingAddress');
        if (el) {
            el.value = shippingAddress;
            console.log('[prefillOrder] Set shippingAddress:', shippingAddress);
        }
    }
    if (recipientName && !customerName) {
        // Use recipient name as customer name if no customer name
        const el = document.getElementById('customerName');
        if (el && !el.value) el.value = recipientName;
    }
    if (recipientPhone && !customerPhone) {
        // Use recipient phone as customer phone if no customer phone
        const el = document.getElementById('customerPhone');
        if (el && !el.value) el.value = recipientPhone;
    }

    // Fill down payment for installment
    if (downPayment) {
        const el = document.getElementById('downPayment');
        if (el) el.value = downPayment;
    }

    // Fill notes with case reference
    if (notes || fromCase) {
        const el = document.getElementById('orderNotes');
        if (el) {
            el.value = notes || `จากเคส #${fromCase}`;
        }
    }

    // Store from_case_id for reference
    if (fromCase) {
        const el = document.getElementById('fromCaseId');
        if (el) el.value = fromCase;
    }

    // =========================================================================
    // Auto-fetch and select product from product catalog
    // =========================================================================
    if (productCode || productName) {
        autoSelectProductFromParams({
            product_code: productCode,
            product_name: productName,
            total_amount: totalAmount
        });
    }

    // =========================================================================
    // Auto-fetch and select customer from customer_profiles
    // =========================================================================
    if (customerId || externalUserId || customerName) {
        autoSelectCustomerFromParams({
            customer_id: customerId,
            external_user_id: externalUserId,
            customer_name: customerName,
            customer_phone: customerPhone,
            source: source
        });
    }

    // Show info toast
    if (fromCase) {
        showToast(`กำลังสร้าง Order จากเคส #${fromCase}`, 'info');
    }

    // Clean URL without reloading
    if (window.history.replaceState) {
        const cleanUrl = window.location.pathname;
        window.history.replaceState({}, document.title, cleanUrl);
    }
}

/**
 * Auto-fetch and select product from URL params
 * Tries to find product in catalog by product_code
 */
async function autoSelectProductFromParams(params) {
    const { product_code, product_name, total_amount } = params;

    try {
        // Try to search by product_code first (most reliable)
        let searchQuery = product_code || product_name;

        if (searchQuery) {
            const apiUrl = (typeof API_ENDPOINTS !== 'undefined' && API_ENDPOINTS.PRODUCTS_SEARCH_V1)
                ? API_ENDPOINTS.PRODUCTS_SEARCH_V1
                : '/api/v1/products/search';

            const searchBody = product_code
                ? { product_code: product_code, page: { limit: 5 } }
                : { keyword: product_name, page: { limit: 5 } };

            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(searchBody)
            });
            const result = await response.json();

            if (result && result.data && result.data.length > 0) {
                // Find best match - prefer exact product_code match
                let bestMatch = result.data[0];

                if (product_code) {
                    const exactMatch = result.data.find(p =>
                        p.product_code === product_code ||
                        p.ref_id === product_code
                    );
                    if (exactMatch) {
                        bestMatch = exactMatch;
                        console.log('[autoSelectProduct] Found exact match by product_code:', bestMatch);
                    }
                }

                // Auto-select this product
                selectProduct(bestMatch);
                console.log('[autoSelectProduct] Auto-selected product:', bestMatch);
            } else {
                console.log('[autoSelectProduct] No product found, using manual input');
            }
        }
    } catch (error) {
        console.error('[autoSelectProduct] Error:', error);
        // Fallback: keep the manual input values (already filled)
    }
}

/**
 * Auto-fetch and select customer from URL params
 * Tries to find customer in customer_profiles by id, external_user_id, or name
 */
async function autoSelectCustomerFromParams(params) {
    const { customer_id, external_user_id, customer_name, customer_phone, source } = params;

    try {
        // Try to search by external_user_id first (most reliable from chat)
        let searchQuery = external_user_id || customer_name;

        if (searchQuery) {
            // Use the same search API as manual customer search
            const apiUrl = '/api/customer/search.php';
            const result = await apiCall(`${apiUrl}?q=${encodeURIComponent(searchQuery)}&limit=10`);

            if (result && result.success && result.data && result.data.length > 0) {
                // Find best match - prefer exact external_user_id match
                let bestMatch = result.data[0];

                if (external_user_id) {
                    const exactMatch = result.data.find(c =>
                        c.external_user_id === external_user_id ||
                        c.platform_user_id === external_user_id ||
                        c.line_user_id === external_user_id ||
                        c.facebook_user_id === external_user_id
                    );
                    if (exactMatch) {
                        bestMatch = exactMatch;
                        console.log('[autoSelectCustomer] Found exact match by external_user_id:', bestMatch);
                    }
                }

                // If no external_user_id match, try customer_id
                if (customer_id && !bestMatch._matched) {
                    const idMatch = result.data.find(c =>
                        String(c.id) === String(customer_id) ||
                        String(c.customer_id) === String(customer_id)
                    );
                    if (idMatch) {
                        bestMatch = idMatch;
                        console.log('[autoSelectCustomer] Found match by customer_id:', bestMatch);
                    }
                }

                selectCustomer(bestMatch);
                console.log('[autoSelectCustomer] Selected customer from search:', bestMatch.display_name || bestMatch.platform_user_name);
                return;
            }
        }

        // No existing customer found - create temporary customer object for display
        if (customer_name) {
            const tempCustomer = {
                id: customer_id || null,
                display_name: customer_name,
                phone: customer_phone || '',
                platform: source || 'line',
                external_user_id: external_user_id || null,
                avatar_url: null,
                _is_temporary: true // Flag to indicate this is not from DB
            };
            selectCustomer(tempCustomer);
            console.log('[autoSelectCustomer] Created temporary customer from params:', tempCustomer);
        }

    } catch (error) {
        console.error('[autoSelectCustomer] Error fetching customer:', error);

        // Fallback: create temporary customer object
        if (customer_name) {
            selectCustomer({
                id: customer_id || null,
                display_name: customer_name,
                phone: customer_phone || '',
                platform: source || 'line',
                external_user_id: external_user_id || null
            });
        }
    }
}

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
    const mobileContainer = document.getElementById('ordersMobileCards');

    const targetOrderNo = getQueryParam('order_no');

    if (!orders || orders.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--color-gray);">ไม่พบคำสั่งซื้อ</td></tr>';
        if (mobileContainer) mobileContainer.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--color-gray);">ไม่พบคำสั่งซื้อ</div>';
        return;
    }

    // Render desktop table
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

        // Prepare customer profile (use new API field names)
        const customerProfile = {
            platform: order.cp_platform || order.customer_platform || 'web',
            name: order.customer_display_name || order.customer_name || 'ไม่ระบุลูกค้า',
            avatar: order.customer_avatar_url || order.customer_avatar || null
        };
        const customerBadgeHtml = typeof renderCustomerProfileBadge === 'function'
            ? renderCustomerProfileBadge(customerProfile)
            : `<span>${customerProfile.name}</span>`;

        // Map order_type to display (supports both old and new names)
        const paymentType = order.payment_type || order.order_type || 'full_payment';
        const isFullPayment = paymentType === 'full' || paymentType === 'full_payment';
        const isInstallment = paymentType === 'installment';
        const isDeposit = paymentType === 'deposit';
        const isSavings = paymentType === 'savings' || paymentType === 'savings_completion';
        const installmentMonths = order.installment_months || 0;

        // Format created_at for display
        const createdDate = order.created_at ? formatDateTime(order.created_at) : '-';

        // ✅ For installment, show financed_amount (includes 3% fee) with breakdown
        const financedAmount = parseFloat(order.financed_amount) || 0;
        const productPrice = parseFloat(order.product_price) || parseFloat(order.total_amount) || 0;
        const serviceFee = parseFloat(order.service_fee) || (financedAmount - productPrice);
        const displayAmount = isInstallment && financedAmount > 0 ? financedAmount : order.total_amount;
        
        // Show fee breakdown for installment orders
        const amountHtml = isInstallment && serviceFee > 0 
            ? `<strong>฿${formatNumber(displayAmount)}</strong>
               <div style="font-size:0.7rem;color:#d97706;">(สินค้า ฿${formatNumber(productPrice)} + ${Math.round((serviceFee/productPrice)*100)}%)</div>`
            : `<strong>฿${formatNumber(order.total_amount)}</strong>`;

        return `
            <tr onclick="viewOrderDetail(${order.id})" style="cursor:pointer;${rowStyle}">
                <td><strong>${order.order_no || order.order_number || '-'}</strong></td>
                <td>${customerBadgeHtml}</td>
                <td>
                    ${order.product_name || '-'}<br>
                    <small style="color:var(--color-gray);">${order.product_code || ''}</small>
                </td>
                <td style="text-align:right;">
                    ${amountHtml}
                    ${renderPaymentProgress(order)}
                </td>
                <td>
                    <span class="badge badge-${isFullPayment ? 'success' : (isDeposit ? 'warning' : 'info')}">
                        ${isFullPayment ? '💳 จ่ายเต็ม' : (isInstallment ? '📅 ผ่อน ' + installmentMonths + ' งวด' : (isDeposit ? '💎 มัดจำ' : '💰 ออมครบ'))}
                    </span>
                </td>
                <td><span class="badge badge-${statusClass}">${statusText}</span></td>
                <td style="font-size: 0.85rem; color: var(--color-gray);">${createdDate}</td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="event.stopPropagation(); viewOrderDetail(${order.id});">
                        <i class="fas fa-eye"></i> ดู
                    </button>
                </td>
            </tr>
        `;
    }).join('');

    // Render mobile cards
    if (mobileContainer) {
        mobileContainer.innerHTML = orders.map(order => {
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

            const customerName = order.customer_display_name || order.customer_name || 'ไม่ระบุ';
            const customerAvatar = order.customer_avatar_url || order.customer_avatar;
            const customerInitial = customerName.charAt(0).toUpperCase();

            const paymentType = order.payment_type || order.order_type || 'full_payment';
            const isFullPayment = paymentType === 'full' || paymentType === 'full_payment';
            const isInstallment = paymentType === 'installment';
            const isDeposit = paymentType === 'deposit';
            const installmentMonths = order.installment_months || 0;

            const paymentTypeText = isFullPayment ? '💳 จ่ายเต็ม'
                : (isInstallment ? '📅 ผ่อน ' + installmentMonths + ' งวด'
                    : (isDeposit ? '💎 มัดจำ' : '💰 ออมครบ'));

            const createdDate = order.created_at ? formatDateTime(order.created_at) : '-';

            // ✅ For installment, show financed_amount
            const financedAmount = parseFloat(order.financed_amount) || 0;
            const mobileDisplayAmount = isInstallment && financedAmount > 0 ? financedAmount : order.total_amount;

            return `
                <div class="order-mobile-card" onclick="viewOrderDetail(${order.id})">
                    <div class="order-mobile-header">
                        <span class="order-mobile-id">${order.order_no || '-'}</span>
                        <span class="order-mobile-amount">฿${formatNumber(mobileDisplayAmount)}</span>
                    </div>
                    <div class="order-mobile-product">${order.product_name || '-'}</div>
                    <div class="order-mobile-row">
                        <span>ลูกค้า</span>
                        <div class="order-mobile-customer">
                            ${customerAvatar
                    ? `<img src="${customerAvatar}" alt="${customerName}" onerror="this.style.display='none'">`
                    : `<span class="avatar-placeholder-sm">${customerInitial}</span>`
                }
                            <span>${customerName}</span>
                        </div>
                    </div>
                    <div class="order-mobile-row">
                        <span>วันที่สั่ง</span>
                        <span>${createdDate}</span>
                    </div>
                    <div class="order-mobile-badges">
                        <span class="badge badge-${isFullPayment ? 'success' : (isDeposit ? 'warning' : 'info')}">${paymentTypeText}</span>
                        <span class="badge badge-${statusClass}">${statusText}</span>
                    </div>
                </div>
            `;
        }).join('');
    }
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

    // Build deposit section
    const depositHtml = buildDepositSection(order);

    return `
        <!-- Customer Profile -->
        ${customerHtml}
        
        <!-- Order Info -->
        <div class="detail-section">
            <div class="detail-section-title">ข้อมูลคำสั่งซื้อ</div>
            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-label">เลขที่คำสั่งซื้อ</div>
                    <div class="detail-value" style="display:flex;align-items:center;gap:0.5rem;">
                        <span id="orderNo-${order.id}">${order.order_no || order.order_number || '-'}</span>
                        <button onclick="copyOrderNo('${order.order_no || order.order_number}')" style="background:none;border:none;cursor:pointer;padding:0.25rem;color:#6b7280;" title="คัดลอก">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
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
                    <div class="detail-label">อัปเดตล่าสุด</div>
                    <div class="detail-value">${formatDateTime(order.updated_at || order.created_at)}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">แหล่งที่มา</div>
                    <div class="detail-value">${order.source || '-'}</div>
                </div>
                ${order.case_id ? `
                <div class="detail-item">
                    <div class="detail-label">🔗 จาก Case</div>
                    <div class="detail-value">
                        <a href="/cases.php?case_id=${order.case_id}" style="color:#3b82f6;text-decoration:none;">ดู Case #${order.case_id} →</a>
                    </div>
                </div>
                ` : ''}
            </div>
        </div>
        
        <!-- Product Info -->
        <div class="detail-section">
            <div class="detail-section-title">รายละเอียดสินค้า</div>
            ${order.product_image_url || (order.items && order.items[0]?.product_image_url) ? `
            <div style="margin-bottom: 1rem; text-align: center;">
                <img src="${order.product_image_url || order.items[0]?.product_image_url}" 
                     alt="${order.product_name || 'สินค้า'}" 
                     style="max-width: 200px; max-height: 200px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);"
                     onerror="this.style.display='none';">
            </div>
            ` : ''}
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
            </div>
        </div>
        
        <!-- Payment Info -->
        <div class="detail-section">
            <div class="detail-section-title">การชำระเงิน</div>
            ${buildPaymentProgressSection(order)}
            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-label">ประเภทการชำระ</div>
                    <div class="detail-value">
                        <span class="payment-type-tag">
                            ${getPaymentTypeLabel(order)}
                        </span>
                    </div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">สถานะชำระ</div>
                    <div class="detail-value">
                        <span class="status-badge status-${getPaymentStatusClass(order.payment_status)}">${getPaymentStatusLabel(order.payment_status)}</span>
                    </div>
                </div>
            </div>
            ${depositHtml}
            ${buildPaymentHistorySection(order)}
        </div>
        
        <!-- Shipping Address -->
        ${addressHtml}
        
        <!-- Notes -->
        ${order.notes ? `
        <div class="detail-section">
            <div class="detail-section-title">📝 หมายเหตุ</div>
            <div style="padding: 0.75rem 1rem; background: #fefce8; border-radius: 8px; border: 1px solid #fef08a; color: #854d0e;">
                ${order.notes}
            </div>
        </div>
        ` : ''}
        
        <!-- Installment Schedule -->
        ${installmentHtml}
        
        <!-- Action Buttons - ย้ายมาล่างสุด -->
        <div class="detail-section">
            <div class="action-buttons">
                <button class="btn-action btn-edit" onclick="openEditOrderModal(${order.id})">
                    <i class="fas fa-edit"></i> แก้ไขคำสั่งซื้อ
                </button>
                <a class="btn-action" href="${buildPaymentHistoryLinkForOrderNo(order.order_no || order.order_number)}">
                    <i class="fas fa-receipt"></i> ดูประวัติการชำระเงิน
                </a>
                ${order.status !== 'cancelled' && order.status !== 'delivered' ? `
                <button class="btn-action btn-cancel" onclick="confirmCancelOrder(${order.id}, '${order.order_no || order.order_number}')" style="background:#fee2e2;color:#dc2626;border-color:#fecaca;">
                    <i class="fas fa-times-circle"></i> ยกเลิกคำสั่งซื้อ
                </button>
                ` : ''}
            </div>
        </div>
    `;
}

function buildCustomerSection(order) {
    // Try customer_profile from API join first, then fall back to direct fields
    const profile = order.customer_profile || {};
    const name = profile.display_name || profile.full_name || order.customer_display_name || order.customer_name || 'ไม่ระบุลูกค้า';
    const platform = profile.platform || order.cp_platform || order.customer_platform || order.platform || 'web';
    const avatar = validateAvatarUrl(profile.avatar_url || order.customer_avatar_url || order.customer_avatar);
    const phone = profile.phone || order.phone || order.shipping_phone || null;

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
    // ถ้าเป็นรับที่ร้าน ไม่ต้องแสดง section ที่อยู่
    const shippingMethod = order.shipping_method || 'pickup';
    if (shippingMethod === 'pickup') {
        return `
            <div class="detail-section">
                <div class="detail-section-title">📍 ช่องทางรับสินค้า</div>
                <div class="address-block" style="background: #ecfdf5; border: 1px solid #a7f3d0;">
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <span style="font-size: 1.5rem;">🏪</span>
                        <div>
                            <div style="font-weight: 600; color: #059669;">รับที่ร้าน</div>
                            <div style="font-size: 0.85rem; color: #6b7280;">นัดหมายรับสินค้าที่หน้าร้าน</div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    // Check if we have shipping_address_detail from API (from customer_addresses join)
    const addr = order.shipping_address_detail || {};

    // Check if we have address info - either from join or from direct fields
    const recipientName = addr.recipient_name || order.shipping_name || order.recipient_name;
    const phone = addr.phone || order.shipping_phone || order.phone;
    const addressLine1 = addr.address_line1 || order.address_line1;

    // Link to addresses page if we have shipping_address_id
    const addressLink = order.shipping_address_id
        ? buildAddressesLinkForOrder(order)
        : null;

    if (!recipientName && !addressLine1 && !order.shipping_address) {
        return '';
    }

    // If we have shipping_address as text (legacy), use it
    if (!addressLine1 && order.shipping_address) {
        return `
            <div class="detail-section">
                <div class="detail-section-title">📦 ที่อยู่จัดส่ง (${getShippingMethodLabel(shippingMethod)})</div>
                <div class="address-block">
                    <div class="address-name">${order.shipping_name || '-'}</div>
                    <div class="address-phone">${order.shipping_phone || '-'}</div>
                    <div>${order.shipping_address}</div>
                </div>
            </div>
        `;
    }

    const addressParts = [
        addr.address_line1 || order.address_line1,
        addr.address_line2 || order.address_line2,
        addr.subdistrict || order.subdistrict,
        addr.district || order.district,
        addr.province || order.province,
        addr.postal_code || order.postal_code
    ].filter(Boolean);

    // Build tracking number section
    const trackingNo = order.tracking_number || order.tracking_no;
    const trackingHtml = trackingNo ? `
        <div style="margin-top: 0.75rem; padding: 0.5rem 0.75rem; background: #eff6ff; border-radius: 6px; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <span style="font-size: 0.75rem; color: #6b7280;">📦 เลข Tracking:</span>
                <span style="font-weight: 600; color: #1d4ed8; margin-left: 0.5rem;" id="tracking-${order.id}">${trackingNo}</span>
            </div>
            <button onclick="copyTrackingNo('${trackingNo}')" style="background:#3b82f6;color:#fff;border:none;padding:0.25rem 0.5rem;border-radius:4px;cursor:pointer;font-size:0.75rem;">
                <i class="fas fa-copy"></i> คัดลอก
            </button>
        </div>
    ` : '';

    // Build link button if we have address_id
    const viewAddressBtn = addressLink
        ? `<a href="${addressLink}" class="btn btn-sm btn-outline" style="margin-top: 0.75rem; display: inline-flex; align-items: center; gap: 0.25rem;">
            <i class="fas fa-external-link-alt"></i> ดูรายละเอียดที่อยู่
           </a>`
        : '';

    return `
        <div class="detail-section">
            <div class="detail-section-title">📦 ที่อยู่จัดส่ง (${getShippingMethodLabel(shippingMethod)})</div>
            <div class="address-block">
                <div class="address-name">${recipientName || '-'}</div>
                <div class="address-phone">${phone || '-'}</div>
                <div>${addressParts.join(' ') || '-'}</div>
                ${trackingHtml}
                ${viewAddressBtn}
            </div>
        </div>
    `;
}

function buildInstallmentSection(order) {
    // Check for installment_schedule (from API) or installments
    const installments = order.installment_schedule || order.installments || [];
    
    // Get installment fee info from installment_info
    const installmentInfo = order.installment_info || {};
    const financedAmount = parseFloat(installmentInfo.financed_amount) || 0;
    const productPrice = parseFloat(installmentInfo.product_price) || parseFloat(order.total_amount) || 0;
    
    // ✅ Use interest_rate from API if available (stored at contract creation)
    // Fallback to calculation for backward compatibility
    const apiInterestRate = parseFloat(installmentInfo.interest_rate);
    const apiFeeAmount = parseFloat(installmentInfo.total_interest);
    
    let feeRate, feeAmount;
    if (!isNaN(apiInterestRate) && apiInterestRate > 0) {
        // Use stored values from database
        feeRate = apiInterestRate;
        feeAmount = !isNaN(apiFeeAmount) ? apiFeeAmount : (productPrice * feeRate / 100);
    } else {
        // Fallback: Calculate from amounts (for old contracts without interest_rate)
        feeAmount = financedAmount > 0 && productPrice > 0 ? financedAmount - productPrice : 0;
        feeRate = productPrice > 0 && feeAmount > 0 ? Math.round((feeAmount / productPrice) * 100) : 0;
    }
    const hasInstallmentFee = feeAmount > 0;

    if (!installments || installments.length === 0) {
        // If order is installment type but no schedule yet, show info
        if (order.payment_type === 'installment' || order.order_type === 'installment') {
            const totalPeriods = order.installment_months || 3;
            return `
                <div class="detail-section">
                    <div class="detail-section-title">📅 แผนผ่อนชำระ (${totalPeriods} งวด)</div>
                    <div style="padding: 1rem; background: #fef3c7; border-radius: 8px; text-align: center; color: #92400e;">
                        <i class="fas fa-clock"></i> กำลังสร้างตารางผ่อนชำระ กรุณาติดต่อแอดมิน
                    </div>
                </div>
            `;
        }
        return '';
    }

    // Calculate installment progress
    const paidCount = installments.filter(i => i.status === 'paid').length;
    const totalCount = installments.length;
    const installmentProgress = totalCount > 0 ? Math.round((paidCount / totalCount) * 100) : 0;
    
    // Build fee breakdown HTML if applicable
    const feeBreakdownHtml = hasInstallmentFee ? `
        <div style="margin-bottom: 1rem; padding: 0.75rem 1rem; background: #fffbeb; border-radius: 8px; border: 1px solid #fcd34d;">
            <div style="font-size: 0.85rem; color: #92400e; font-weight: 500; margin-bottom: 0.5rem;">
                <i class="fas fa-info-circle"></i> รายละเอียดยอดผ่อน
            </div>
            <div style="display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.9rem;">
                <div style="display: flex; justify-content: space-between; color: #78716c;">
                    <span>ราคาสินค้า</span>
                    <span>฿${formatNumber(productPrice)}</span>
                </div>
                <div style="display: flex; justify-content: space-between; color: #d97706;">
                    <span>ค่าธรรมเนียมผ่อน ${feeRate}%</span>
                    <span>+฿${formatNumber(feeAmount)}</span>
                </div>
                <div style="display: flex; justify-content: space-between; font-weight: 600; color: #1f2937; border-top: 1px dashed #e5e7eb; padding-top: 0.5rem; margin-top: 0.25rem;">
                    <span>ยอดผ่อนรวม</span>
                    <span>฿${formatNumber(financedAmount)}</span>
                </div>
            </div>
        </div>
    ` : '';

    return `
        <div class="detail-section">
            <div class="detail-section-title">📅 ตารางผ่อนชำระ</div>
            
            ${feeBreakdownHtml}
            
            <!-- Installment Progress Bar -->
            <div style="margin-bottom: 1rem; padding: 1rem; background: #f0f9ff; border-radius: 8px; border: 1px solid #bae6fd;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <span style="font-size: 0.9rem; font-weight: 600; color: #0369a1;">
                        <i class="fas fa-calendar-check"></i> ความคืบหน้า: ${paidCount}/${totalCount} งวด
                    </span>
                    <span style="font-weight: 600; color: ${paidCount === totalCount ? '#22c55e' : '#0369a1'};">${installmentProgress}%</span>
                </div>
                <div style="height: 12px; background: #e0f2fe; border-radius: 6px; overflow: hidden;">
                    <div style="height: 100%; width: ${installmentProgress}%; background: ${paidCount === totalCount ? '#22c55e' : '#0ea5e9'}; transition: width 0.3s ease; border-radius: 6px;"></div>
                </div>
            </div>
            
            <table class="installment-table">
                <thead>
                    <tr>
                        <th>งวดที่</th>
                        <th>วันครบกำหนด</th>
                        <th style="text-align:right;">จำนวนเงิน</th>
                        <th style="text-align:right;">ชำระแล้ว</th>
                        <th>สถานะ</th>
                    </tr>
                </thead>
                <tbody>
                    ${installments.map(inst => {
        const paidAmt = parseFloat(inst.paid_amount) || 0;
        const dueAmt = parseFloat(inst.amount) || 0;
        const isFullyPaid = paidAmt >= dueAmt;
        return `
                            <tr style="${inst.status === 'overdue' ? 'background: #fef2f2;' : inst.status === 'paid' ? 'background: #f0fdf4;' : ''}">
                                <td><strong>${inst.period_number}</strong></td>
                                <td>${formatDate(inst.due_date)}</td>
                                <td style="text-align:right;">฿${formatNumber(dueAmt)}</td>
                                <td style="text-align:right; color: ${paidAmt > 0 ? '#22c55e' : '#9ca3af'};">฿${formatNumber(paidAmt)}</td>
                                <td>
                                    <span class="inst-status ${inst.status}">
                                        ${inst.status === 'paid' ? '✓ ชำระแล้ว' : inst.status === 'overdue' ? '⚠ เกินกำหนด' : inst.status === 'partial' ? '◐ ชำระบางส่วน' : '⏳ รอชำระ'}
                                    </span>
                                </td>
                            </tr>
                        `;
    }).join('')}
                </tbody>
            </table>
        </div>
    `;
}

/**
 * Build Deposit Section - แสดงข้อมูลมัดจำ
 * @param {Object} order - Order data from API
 * @returns {string} HTML for deposit section
 */
function buildDepositSection(order) {
    const paymentType = order.payment_type || order.order_type || 'full';

    // Only show for deposit orders
    if (paymentType !== 'deposit') {
        return '';
    }

    const depositAmount = parseFloat(order.deposit_amount) || 0;
    const totalAmount = parseFloat(order.total_amount) || 0;
    const paidAmount = parseFloat(order.paid_amount) || 0;
    const remainingAmount = Math.max(0, totalAmount - paidAmount);
    const depositExpiry = order.deposit_expiry;

    // Check if deposit is expired
    let isExpired = false;
    let daysRemaining = null;
    if (depositExpiry) {
        const expiryDate = new Date(depositExpiry);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        expiryDate.setHours(0, 0, 0, 0);
        const diffTime = expiryDate - today;
        daysRemaining = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        isExpired = daysRemaining < 0;
    }

    // Determine status for deposit
    let statusColor = '#f59e0b'; // warning/orange
    let statusBg = '#fef3c7';
    let statusIcon = '💎';
    let statusText = 'รอชำระมัดจำ';

    if (paidAmount > 0 && paidAmount < totalAmount) {
        statusColor = '#0ea5e9'; // info/blue
        statusBg = '#e0f2fe';
        statusIcon = '✓';
        statusText = 'มัดจำแล้ว รอชำระส่วนที่เหลือ';
    } else if (paidAmount >= totalAmount) {
        statusColor = '#22c55e'; // success/green
        statusBg = '#dcfce7';
        statusIcon = '✓✓';
        statusText = 'ชำระครบแล้ว';
    } else if (isExpired) {
        statusColor = '#ef4444'; // danger/red
        statusBg = '#fef2f2';
        statusIcon = '⚠';
        statusText = 'หมดอายุกันสินค้า';
    }

    // ไม่แสดง progress bar ซ้ำ - ใช้ buildPaymentProgressSection แทน
    // แสดงเฉพาะข้อมูลเฉพาะของ deposit: สถานะมัดจำ, ยอดมัดจำที่กำหนด, วันหมดอายุ

    return `
        <!-- Deposit-specific Info -->
        <div style="margin-top: 1rem; padding: 1rem; background: ${statusBg}; border-radius: 8px; border: 1px solid ${statusColor}33;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                <span style="font-weight: 600; color: ${statusColor};">
                    <span style="margin-right: 0.5rem;">${statusIcon}</span>${statusText}
                </span>
                ${depositExpiry ? `
                <span style="font-size: 0.85rem; color: ${isExpired ? '#ef4444' : '#6b7280'};">
                    📅 กันสินค้า${isExpired ? 'หมดอายุ' : 'ถึง'}: ${formatDate(depositExpiry)}
                    ${!isExpired && daysRemaining !== null ? `(อีก ${daysRemaining} วัน)` : ''}
                </span>
                ` : ''}
            </div>
            ${depositAmount > 0 ? `
            <div style="margin-top: 0.75rem; font-size: 0.9rem; color: #374151;">
                💰 ยอดมัดจำที่กำหนด: <strong style="color: #7c3aed;">฿${formatNumber(depositAmount)}</strong>
            </div>
            ` : ''}
        </div>
    `;
}

/**
 * Build Payment Progress Bar Section
 * แสดง Progress Bar และยอดคงเหลือ รวมถึงยอดรอตรวจสอบ
 */
function buildPaymentProgressSection(order) {
    // For installment orders, use financed_amount (includes fee) instead of total_amount
    const isInstallment = order.order_type === 'installment' || order.payment_type === 'installment';
    const installmentInfo = order.installment_info || {};
    const financedAmount = parseFloat(installmentInfo.financed_amount) || 0;
    
    // Use financed_amount for installment orders if available
    const totalAmount = (isInstallment && financedAmount > 0) 
        ? financedAmount 
        : (parseFloat(order.total_amount) || 0);
    
    const paidAmount = parseFloat(order.paid_amount) || 0;
    const pendingAmount = parseFloat(order.paid_amount_pending) || 0;
    const remainingAmount = parseFloat(order.remaining_amount) || Math.max(0, totalAmount - paidAmount);
    const percentage = totalAmount > 0 ? Math.min(100, (paidAmount / totalAmount) * 100) : 0;
    const pendingPercentage = totalAmount > 0 ? Math.min(100 - percentage, (pendingAmount / totalAmount) * 100) : 0;

    // Determine progress bar color based on percentage
    let progressColor = '#e5e7eb'; // gray
    let statusIcon = '○';
    let statusText = 'ยังไม่ชำระ';

    if (percentage >= 100) {
        progressColor = '#22c55e'; // green - paid
        statusIcon = '✓';
        statusText = 'ชำระครบแล้ว';
    } else if (percentage > 0) {
        progressColor = '#f59e0b'; // orange - partial
        statusIcon = '◐';
        statusText = 'ชำระบางส่วน';
    }

    // Build pending amount indicator if there are pending payments
    const pendingHtml = pendingAmount > 0 ? `
        <div style="margin-top: 0.5rem; padding: 0.5rem 0.75rem; background: #fef3c7; border-radius: 6px; display: flex; align-items: center; gap: 0.5rem;">
            <span style="color: #d97706;">⏳</span>
            <span style="font-size: 0.8rem; color: #92400e;">
                รอตรวจสอบ: <strong>฿${formatNumber(pendingAmount)}</strong>
                ${order.payments?.filter(p => p.status === 'pending' || p.status === 'verifying').length || 0} รายการ
            </span>
        </div>
    ` : '';

    // Label for total - show "ยอดผ่อน" for installment, "ยอดรวม" for others
    const totalLabel = (isInstallment && financedAmount > 0) ? 'ยอดผ่อน' : 'ยอดรวม';

    return `
        <div class="payment-progress-container" style="margin-bottom: 1rem; padding: 1rem; background: #f9fafb; border-radius: 8px; border: 1px solid #e5e7eb;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span style="font-size: 0.9rem; font-weight: 500; color: #374151;">
                    ${statusIcon} ${statusText}
                </span>
                <span style="font-weight: 700; font-size: 1.1rem; color: ${percentage >= 100 ? '#22c55e' : '#374151'};">${percentage.toFixed(1)}%</span>
            </div>
            <div style="height: 12px; background: #e5e7eb; border-radius: 6px; overflow: hidden; position: relative;">
                <div style="position: absolute; height: 100%; width: ${percentage + pendingPercentage}%; background: #fcd34d; border-radius: 6px;"></div>
                <div style="position: absolute; height: 100%; width: ${percentage}%; background: ${progressColor}; transition: width 0.3s ease; border-radius: 6px;"></div>
            </div>
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem; margin-top: 0.75rem; font-size: 0.85rem;">
                <div style="text-align: center; padding: 0.5rem; background: #f0fdf4; border-radius: 6px;">
                    <div style="color: #9ca3af; font-size: 0.75rem;">ชำระแล้ว</div>
                    <div style="font-weight: 600; color: #22c55e;">฿${formatNumber(paidAmount)}</div>
                </div>
                <div style="text-align: center; padding: 0.5rem; background: ${remainingAmount > 0 ? '#fef3c7' : '#f0fdf4'}; border-radius: 6px;">
                    <div style="color: #9ca3af; font-size: 0.75rem;">คงเหลือ</div>
                    <div style="font-weight: 600; color: ${remainingAmount > 0 ? '#d97706' : '#22c55e'};">฿${formatNumber(remainingAmount)}</div>
                </div>
                <div style="text-align: center; padding: 0.5rem; background: #eff6ff; border-radius: 6px;">
                    <div style="color: #9ca3af; font-size: 0.75rem;">${totalLabel}</div>
                    <div style="font-weight: 600; color: #3b82f6;">฿${formatNumber(totalAmount)}</div>
                </div>
            </div>
            ${pendingHtml}
        </div>
    `;
}

/**
 * Get Shipping Method Label
 * แปลงช่องทางรับสินค้าเป็นข้อความภาษาไทย
 */
function getShippingMethodLabel(shippingMethod) {
    const methodMap = {
        'pickup': '🏪 รับที่ร้าน',
        'ems': '📦 จัดส่ง EMS',
        'grab': '🚗 Grab Express',
        'delivery': '🚚 จัดส่ง',
        'lalamove': '🛵 Lalamove'
    };
    return methodMap[shippingMethod] || shippingMethod || '🏪 รับที่ร้าน';
}

/**
 * Get Payment Type Label
 */
function getPaymentTypeLabel(order) {
    const paymentType = order.payment_type || order.order_type || 'full';

    if (paymentType === 'full' || paymentType === 'full_payment') {
        return '💳 ชำระเต็มจำนวน';
    } else if (paymentType === 'installment') {
        return '📅 ผ่อนชำระ ' + (order.installment_months || 0) + ' งวด';
    } else if (paymentType === 'deposit') {
        const depositAmt = parseFloat(order.deposit_amount) || 0;
        if (depositAmt > 0) {
            return `💎 มัดจำ ฿${formatNumber(depositAmt)}`;
        }
        return '💎 มัดจำ';
    } else if (paymentType === 'savings' || paymentType === 'savings_completion') {
        return '🐷 ออมครบ';
    }
    return '💳 ' + paymentType;
}

/**
 * Get Payment Status Class
 */
function getPaymentStatusClass(status) {
    const statusMap = {
        'paid': 'delivered',
        'partial': 'processing',
        'unpaid': 'pending',
        'refunded': 'cancelled'
    };
    return statusMap[status] || 'pending';
}

/**
 * Get Payment Status Label
 */
function getPaymentStatusLabel(status) {
    const statusMap = {
        'paid': '✓ ชำระครบแล้ว',
        'partial': '⏳ ชำระบางส่วน',
        'unpaid': '○ ยังไม่ชำระ',
        'refunded': '↩ คืนเงินแล้ว'
    };
    return statusMap[status] || status || 'ไม่ระบุ';
}

/**
 * Build Payment History Section
 * แสดงรายการชำระเงินทั้งหมดใน Order
 */
function buildPaymentHistorySection(order) {
    if (!order.payments || order.payments.length === 0) {
        return `
            <div style="margin-top: 1rem; padding: 0.75rem; background: #fef3c7; border-radius: 8px; text-align: center; color: #92400e; font-size: 0.85rem;">
                <i class="fas fa-info-circle"></i> ยังไม่มีรายการชำระเงิน
            </div>
        `;
    }

    return `
        <div style="margin-top: 1rem;">
            <div style="font-size: 0.8rem; color: #6b7280; margin-bottom: 0.5rem; font-weight: 500;">ประวัติการชำระเงิน (${order.payments.length} รายการ)</div>
            <div style="max-height: 200px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 8px;">
                ${order.payments.map((p, idx) => `
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; ${idx > 0 ? 'border-top: 1px solid #e5e7eb;' : ''} background: ${p.status === 'verified' ? '#f0fdf4' : p.status === 'pending' ? '#fffbeb' : '#fff'};">
                        <div>
                            <div style="font-weight: 500; color: #374151; font-size: 0.9rem;">${p.payment_no || '#' + p.id}</div>
                            <div style="font-size: 0.75rem; color: #9ca3af;">${formatDateTime(p.created_at)}</div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-weight: 600; color: #059669;">฿${formatNumber(p.amount)}</div>
                            <div style="font-size: 0.75rem;">
                                <span style="padding: 0.15rem 0.5rem; border-radius: 4px; font-size: 0.7rem; font-weight: 500; ${getPaymentItemStatusStyle(p.status)}">${getPaymentItemStatusText(p.status)}</span>
                            </div>
                        </div>
                    </div>
                `).join('')}
            </div>
        </div>
    `;
}

/**
 * Get Payment Item Status Style
 */
function getPaymentItemStatusStyle(status) {
    const styles = {
        'verified': 'background: #dcfce7; color: #166534;',
        'pending': 'background: #fef3c7; color: #92400e;',
        'rejected': 'background: #fee2e2; color: #dc2626;',
        'cancelled': 'background: #f3f4f6; color: #6b7280;'
    };
    return styles[status] || 'background: #f3f4f6; color: #6b7280;';
}

/**
 * Get Payment Item Status Text
 */
function getPaymentItemStatusText(status) {
    const texts = {
        'verified': '✓ อนุมัติ',
        'pending': '⏳ รอตรวจ',
        'rejected': '✗ ปฏิเสธ',
        'cancelled': '✗ ยกเลิก'
    };
    return texts[status] || status || '-';
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

            // Call Product Search API v1 (POST method)
            const apiUrl = (typeof API_ENDPOINTS !== 'undefined' && API_ENDPOINTS.PRODUCTS_SEARCH_V1)
                ? API_ENDPOINTS.PRODUCTS_SEARCH_V1
                : '/api/v1/products/search';

            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    keyword: query.trim(),
                    page: { limit: 10 }
                })
            });
            const result = await response.json();

            // v1 API returns { data: [...] }
            if (result && result.data && result.data.length > 0) {
                renderProductSearchResults(result.data);
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
                    <p>⚠️ ไม่สามารถค้นหาได้</p>
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
        // ✅ FIX: Also check thumbnail_url (from v1 API)
        const image = product.image_url || product.thumbnail_url || product.thumbnail || product.images?.[0] || placeholderImg;
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
    // ✅ FIX: Also check thumbnail_url (from v1 API)
    const image = product.image_url || product.thumbnail_url || product.thumbnail || product.images?.[0] || placeholderImg;

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

    // ✅ FIX: Also set product image URL for saving to order
    const productImageUrlEl = document.getElementById('productImageUrl');
    if (productImageUrlEl) {
        productImageUrlEl.value = image !== placeholderImg ? image : '';
    }

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
    const depositFields = document.getElementById('depositFields');

    if (installmentFields) {
        installmentFields.style.display = paymentType === 'installment' ? 'block' : 'none';
    }

    if (depositFields) {
        depositFields.style.display = paymentType === 'deposit' ? 'block' : 'none';
    }

    // Auto-calculate deposit amount (10% of total)
    if (paymentType === 'deposit') {
        autoCalculateDeposit();
    }

    // Auto-calculate installment
    if (paymentType === 'installment') {
        calculateInstallment();
    }
}

/**
 * Auto-calculate deposit amount (10% of total)
 */
function autoCalculateDeposit() {
    const totalAmount = parseFloat(document.getElementById('totalAmount')?.value) || 0;
    const depositInput = document.getElementById('depositAmount');
    const expiryInput = document.getElementById('depositExpiry');

    if (depositInput && !depositInput.value) {
        // Default 10% deposit
        depositInput.value = Math.ceil(totalAmount * 0.1);
    }

    if (expiryInput && !expiryInput.value) {
        // Default 14 days from now
        const expiry = new Date();
        expiry.setDate(expiry.getDate() + 14);
        expiryInput.value = expiry.toISOString().split('T')[0];
    }
}

/**
 * Calculate installment breakdown (3 periods, 3% service fee TOTAL - not per month)
 * 
 * นโยบายร้าน: ไม่มีดาวน์ - ผ่อนเต็มจำนวนเลย
 * 
 * Spec:
 * - ผ่อน 3 งวด ภายใน 60 วัน
 * - ค่าธรรมเนียม 3% รวมในงวดแรก
 * 
 * ตัวอย่าง 10,000 บาท:
 * - งวด 1: 3,333 + 300 (3% fee) = 3,633 บาท (วันนี้)
 * - งวด 2: 3,333 บาท (+30 วัน)
 * - งวด 3: 3,334 บาท (+60 วัน รับของ)
 */
function calculateInstallment() {
    const totalAmount = parseFloat(document.getElementById('totalAmount')?.value) || 0;
    const summaryDiv = document.getElementById('installmentSummary');
    const calcDiv = document.getElementById('installmentCalc');

    if (!summaryDiv || !calcDiv || totalAmount <= 0) {
        if (summaryDiv) summaryDiv.style.display = 'none';
        return;
    }

    // ไม่มีดาวน์ - ผ่อนเต็มจำนวน
    const remaining = totalAmount;

    // Service fee: 3% TOTAL (not per month)
    const serviceFeeRate = 0.03;
    const serviceFee = Math.round(remaining * serviceFeeRate);

    // Calculate installment amounts following the spec:
    // งวด 1 = floor(ราคา/3) + ค่าธรรมเนียม
    // งวด 2 = floor(ราคา/3)
    // งวด 3 = floor(ราคา/3) + เศษ
    const baseAmount = Math.floor(remaining / 3);
    const remainder = remaining - (baseAmount * 3);

    let p1 = baseAmount;
    let p2 = baseAmount;
    let p3 = baseAmount + remainder;

    // Period 1 includes service fee
    const period1Total = p1 + serviceFee;
    const grandTotal = remaining + serviceFee;

    calcDiv.innerHTML = `
        <table style="width: 100%; font-size: 0.9rem;">
            <tr><td>ราคาสินค้า:</td><td style="text-align: right; font-weight: 600;">${formatNumber(totalAmount)} บาท</td></tr>
            <tr><td>ค่าดำเนินการ (3%):</td><td style="text-align: right; color: #c00;">+${formatNumber(serviceFee)} บาท</td></tr>
            <tr style="border-top: 1px solid #ddd;"><td colspan="2" style="padding-top: 0.5rem;"><strong>📅 แบ่งจ่าย 3 งวด (ภายใน 60 วัน):</strong></td></tr>
            <tr><td>• งวดที่ 1 (วันนี้):</td><td style="text-align: right;">${formatNumber(p1)} + ${formatNumber(serviceFee)} = <strong style="color: #059669;">${formatNumber(period1Total)} บาท</strong></td></tr>
            <tr><td>• งวดที่ 2 (+30 วัน):</td><td style="text-align: right;"><strong>${formatNumber(p2)} บาท</strong></td></tr>
            <tr><td>• งวดที่ 3 (+60 วัน รับของ):</td><td style="text-align: right;"><strong>${formatNumber(p3)} บาท</strong></td></tr>
            <tr style="border-top: 1px solid #ddd; font-weight: 600;"><td style="padding-top: 0.5rem;">ยอดรวมทั้งหมด:</td><td style="text-align: right; padding-top: 0.5rem; color: #007bff;">${formatNumber(grandTotal)} บาท</td></tr>
        </table>
        <p style="font-size: 0.8rem; color: #666; margin-top: 0.5rem;">
            ⚠️ <strong>เงื่อนไข:</strong> ค่าดำเนินการ 3% ไม่สามารถคืนได้ทุกกรณี | ต้องชำระครบภายใน 60 วัน | สินค้าไม่สามารถเปลี่ยนเป็นชิ้นอื่นได้
        </p>
    `;
    summaryDiv.style.display = 'block';
}

/**
 * Toggle shipping address fields visibility
 */
function toggleShippingFields() {
    const shippingMethod = document.getElementById('shippingMethod')?.value || 'pickup';
    const addressFields = document.getElementById('shippingAddressFields');

    if (addressFields) {
        // Show address fields for post and grab delivery
        addressFields.style.display = (shippingMethod === 'post' || shippingMethod === 'grab') ? 'block' : 'none';
    }
}

// Add event listeners for auto-calculation
document.addEventListener('DOMContentLoaded', function () {
    const totalAmountInput = document.getElementById('totalAmount');
    const downPaymentInput = document.getElementById('downPayment');

    if (totalAmountInput) {
        totalAmountInput.addEventListener('input', function () {
            const paymentType = document.querySelector('input[name="payment_type"]:checked')?.value;
            if (paymentType === 'deposit') autoCalculateDeposit();
            if (paymentType === 'installment') calculateInstallment();
        });
    }

    if (downPaymentInput) {
        downPaymentInput.addEventListener('input', calculateInstallment);
    }
});

/**
 * Update message template when bank account is selected
 * ✅ Templates แยกตาม payment type: full, installment, deposit, savings
 */
function updateMessageTemplate() {
    const select = document.getElementById('bankAccount');
    const textarea = document.getElementById('customerMessage');
    const customerName = document.getElementById('customerName')?.value?.trim() || 'ลูกค้า';
    const totalAmount = document.getElementById('totalAmount')?.value || '0';
    const productName = document.getElementById('productName')?.value?.trim() || 'สินค้า';
    const paymentType = document.querySelector('input[name="payment_type"]:checked')?.value || 'full';

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
    const formattedAmount = formatNumber(parseFloat(totalAmount) || 0);

    let template = '';

    // ✅ Template ตามประเภทการชำระ
    switch (paymentType) {
        case 'installment':
            // ผ่อน 3 งวด (ตาม spec ร้าน ฮ.เฮง เฮง)
            // งวด 1 = floor(ยอด/3) + 3% fee
            // งวด 2 = floor(ยอด/3)
            // งวด 3 = เศษที่เหลือ
            const totalNum = parseFloat(totalAmount) || 0;
            const fee = Math.round(totalNum * 0.03);
            const basePerPeriod = Math.floor(totalNum / 3);
            const remainder = totalNum - (basePerPeriod * 3);
            const period1 = basePerPeriod + fee;  // รวม 3% fee
            const period2 = basePerPeriod;
            const period3 = basePerPeriod + remainder;
            const period1Due = new Date().toLocaleDateString('th-TH');
            const period2Due = new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toLocaleDateString('th-TH');
            const period3Due = new Date(Date.now() + 60 * 24 * 60 * 60 * 1000).toLocaleDateString('th-TH');

            template = `🛒 สร้างคำสั่งซื้อผ่อนชำระเรียบร้อยแล้วค่ะ

ขอบพระคุณค่ะ คุณ${customerName} 🙏
ที่ไว้วางใจเลือกซื้อ ${productName} จากร้าน ฮ.เฮง เฮง 💎

� เลขที่: {{ORDER_NUMBER}}

�💰 ยอดรวม: ${formattedAmount} บาท
📝 ค่าดำเนินการ 3%: ${formatNumber(fee)} บาท

📅 ผ่อน 3 งวด:
▫️ งวดที่ 1: ${formatNumber(period1)} บาท (ครบกำหนด ${period1Due})
▫️ งวดที่ 2: ${formatNumber(period2)} บาท (ครบกำหนด ${period2Due})
▫️ งวดที่ 3: ${formatNumber(period3)} บาท (ครบกำหนด ${period3Due} - รับของ)

🏦 ข้อมูลการโอนเงิน
ธนาคาร: ${bankName}
ชื่อบัญชี: ${accountName}
เลขบัญชี: ${accountNumber}

💳 กรุณาชำระงวดแรกภายในวันที่กำหนดค่ะ
เมื่อชำระแล้ว ส่งสลิปมาได้เลยค่ะ 🙏`;
            break;

        case 'deposit':
            // มัดจำ 10%
            const depositNum = parseFloat(totalAmount) || 0;
            const depositAmount = Math.round(depositNum * 0.1);
            const depositExpiry = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toLocaleDateString('th-TH');

            template = `🛒 สร้างคำสั่งซื้อมัดจำเรียบร้อยแล้วค่ะ

ขอบพระคุณค่ะ คุณ${customerName} 🙏
ที่ไว้วางใจเลือกซื้อ ${productName} จากร้าน ฮ.เฮง เฮง 💎

📋 เลขที่: {{ORDER_NUMBER}}

💰 ราคาสินค้า: ${formattedAmount} บาท
🎯 มัดจำ 10%: ${formatNumber(depositAmount)} บาท
⏰ หมดอายุ: ${depositExpiry}

🏦 ข้อมูลการโอนเงิน
ธนาคาร: ${bankName}
ชื่อบัญชี: ${accountName}
เลขบัญชี: ${accountNumber}

เมื่อมัดจำแล้ว ส่งสลิปมาได้เลยค่ะ 🙏
พร้อมชำระส่วนที่เหลือเมื่อไหร่ แจ้งได้เลยนะคะ ✨`;
            break;

        case 'savings':
            // ออมสินค้า
            template = `🛒 สร้างคำสั่งออมสินค้าเรียบร้อยแล้วค่ะ

ขอบพระคุณค่ะ คุณ${customerName} 🙏
ที่เลือกออมสินค้า ${productName} กับร้าน ฮ.เฮง เฮง 💎

📋 เลขที่: {{ORDER_NUMBER}}

🎯 เป้าหมาย: ${formattedAmount} บาท
💰 ยอดปัจจุบัน: 0 บาท

🏦 ข้อมูลการโอนเงิน
ธนาคาร: ${bankName}
ชื่อบัญชี: ${accountName}
เลขบัญชี: ${accountNumber}

ออมได้ตามสะดวกค่ะ พอครบเป้าก็รับสินค้าได้เลย 🙏`;
            break;

        default:
            // โอนเต็ม (full)
            template = `🛒 สร้างคำสั่งซื้อเรียบร้อยแล้วค่ะ

ขอบพระคุณค่ะ คุณ${customerName} 🙏
ที่ไว้วางใจเลือกซื้อ ${productName} จากร้าน ฮ.เฮง เฮง 💎

📋 เลขที่: {{ORDER_NUMBER}}

💰 ยอดชำระ: ${formattedAmount} บาท

🏦 ข้อมูลการโอนเงิน
ธนาคาร: ${bankName}
ชื่อบัญชี: ${accountName}
เลขบัญชี: ${accountNumber}

หากคุณ${customerName} ชำระแล้ว แจ้งสลิปให้แอดมินได้เลยนะคะ 🙏`;
    }

    textarea.value = template;
}

/**
 * ✅ Update template when payment type changes
 */
function onPaymentTypeChange() {
    const bankSelect = document.getElementById('bankAccount');
    // Only update if bank is already selected
    if (bankSelect && bankSelect.value) {
        updateMessageTemplate();
    }
}

/**
 * ✅ Update submit button text based on send message checkbox
 */
function updateSubmitButtonText() {
    const sendMessageChecked = document.getElementById('sendMessageCheckbox')?.checked || false;
    const submitBtn = document.getElementById('submitOrderBtn');
    const warningEl = document.getElementById('sendMessageWarning');
    const externalUserId = document.getElementById('externalUserId')?.value || '';

    if (submitBtn) {
        if (sendMessageChecked) {
            submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> บันทึก & ส่งข้อความ';
        } else {
            submitBtn.innerHTML = '<i class="fas fa-save"></i> บันทึกเท่านั้น';
        }
    }

    // แสดง warning ถ้าติ๊กส่งข้อความแต่ไม่มี external_user_id
    if (warningEl) {
        if (sendMessageChecked && !externalUserId) {
            warningEl.style.display = 'block';
        } else {
            warningEl.style.display = 'none';
        }
    }
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

    // ✅ Validate: ถ้าติ๊ก send_message ต้องเลือกลูกค้าจากระบบ, บัญชี, และมีข้อความ
    const sendMessageChecked = document.getElementById('sendMessageCheckbox')?.checked || false;
    const bankAccountValue = document.getElementById('bankAccount')?.value || '';
    const customerMessageValue = document.getElementById('customerMessage')?.value?.trim() || '';
    const externalUserIdForValidate = document.getElementById('externalUserId')?.value || '';

    if (sendMessageChecked) {
        // ต้องเลือกลูกค้าจากระบบ (มี LINE/Facebook ID)
        if (!externalUserIdForValidate) {
            showToast('⚠️ ต้องเลือกลูกค้าจากระบบก่อนส่งข้อความ (ลูกค้าต้องมี LINE/Facebook)', 'error');
            document.getElementById('customerSearch').focus();
            return false;
        }
        if (!bankAccountValue) {
            showToast('กรุณาเลือกบัญชีรับโอนก่อนส่งข้อความ', 'error');
            document.getElementById('bankAccount').focus();
            return false;
        }
        if (!customerMessageValue) {
            showToast('กรุณากรอกข้อความแจ้งลูกค้าก่อนส่ง', 'error');
            document.getElementById('customerMessage').focus();
            return false;
        }
    }

    // ✅ Validate: ถ้าเป็น deposit ต้องมียอดมัดจำ
    const paymentTypeSelected = document.querySelector('input[name="payment_type"]:checked')?.value || 'full';
    if (paymentTypeSelected === 'deposit') {
        const depositAmountVal = parseFloat(document.getElementById('depositAmount')?.value) || 0;
        if (depositAmountVal <= 0) {
            showToast('กรุณาระบุยอดมัดจำ', 'error');
            document.getElementById('depositAmount').focus();
            return false;
        }
        // ตรวจสอบยอดมัดจำ ≤ ยอดรวม
        if (depositAmountVal >= parseFloat(totalAmount)) {
            showToast('ยอดมัดจำต้องน้อยกว่ายอดรวม', 'error');
            document.getElementById('depositAmount').focus();
            return false;
        }
    }

    // Disable submit button
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังบันทึก...';

    try {
        // Build order data
        const paymentType = document.querySelector('input[name="payment_type"]:checked')?.value || 'full';

        // Get external_user_id from hidden field
        const externalUserIdValue = document.getElementById('externalUserId')?.value || null;
        const productImageValue = document.getElementById('productImageUrl')?.value || null;

        // Get from_case from hidden field (NOT URL params - URL is cleared after prefill)
        const fromCaseId = document.getElementById('fromCaseId')?.value || null;

        console.log('[submitCreateOrder] external_user_id:', externalUserIdValue);
        console.log('[submitCreateOrder] product_image:', productImageValue);
        console.log('[submitCreateOrder] from_case:', fromCaseId);

        const orderData = {
            product_name: productName,
            product_code: document.getElementById('productCode').value.trim(),
            product_id: document.getElementById('selectedProductId').value || null,
            product_image: productImageValue,
            quantity: parseInt(quantity) || 1,
            total_amount: parseFloat(totalAmount),
            payment_type: paymentType,
            source: document.getElementById('customerSource').value,
            customer_name: document.getElementById('customerName').value.trim() || null,
            customer_phone: document.getElementById('customerPhone').value.trim() || null,
            customer_id: document.getElementById('selectedCustomerId').value || null,
            // ✅ Add external_user_id for linking with customer_profiles
            external_user_id: externalUserIdValue,
            platform: document.getElementById('customerSource')?.value || null,
            // ✅ Add from_case so backend can query cases table for external_user_id
            from_case: fromCaseId,
            notes: document.getElementById('orderNotes').value.trim() || null,
            // Push message fields
            bank_account: document.getElementById('bankAccount')?.value || null,
            customer_message: document.getElementById('customerMessage')?.value?.trim() || null,
            send_message: document.getElementById('sendMessageCheckbox')?.checked || false,
            // ✅ FIX: Add shipping fields - these were missing!
            shipping_method: document.getElementById('shippingMethod')?.value || 'pickup',
            shipping_address: document.getElementById('shippingAddress')?.value?.trim() || null,
            shipping_fee: parseFloat(document.getElementById('shippingFee')?.value) || 0,
            tracking_number: document.getElementById('trackingNumber')?.value?.trim() || null
        };

        console.log('[submitCreateOrder] Full orderData:', JSON.stringify(orderData, null, 2));

        // Add installment fields if applicable (no down payment - full installment)
        if (paymentType === 'installment') {
            orderData.installment_months = 3;  // Fixed at 3 periods
            orderData.down_payment = 0;  // No down payment
        }

        // Add deposit fields if applicable
        if (paymentType === 'deposit') {
            orderData.deposit_amount = parseFloat(document.getElementById('depositAmount')?.value) || 0;
            orderData.deposit_expiry = document.getElementById('depositExpiry')?.value || null;
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

            // If created from case, redirect back to cases page after delay
            const fromCaseId = document.getElementById('fromCaseId')?.value;
            if (fromCaseId) {
                setTimeout(() => {
                    window.location.href = '/cases.php';
                }, 1500);
            } else {
                // Normal flow - reload orders list and show detail
                await loadOrders();

                // Open the new order detail if we have the ID
                if (result.data && result.data.id) {
                    setTimeout(() => {
                        viewOrderDetail(result.data.id);
                    }, 500);
                }
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

    // ✅ Set external_user_id for push message (LINE/Facebook ID)
    const externalId = customer.platform_user_id || customer.external_user_id || customer.line_user_id || '';
    document.getElementById('externalUserId').value = externalId;

    // ✅ Update submit button text (hide warning if customer has platform ID)
    updateSubmitButtonText();

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
    // ✅ Also clear external_user_id
    const externalField = document.getElementById('externalUserId');
    if (externalField) externalField.value = '';
    // ✅ Update warning display
    updateSubmitButtonText();
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
 * Confirm and Cancel Order
 */
async function confirmCancelOrder(orderId, orderNo) {
    const reason = prompt(`❌ ยืนยันยกเลิกคำสั่งซื้อ #${orderNo}\n\nกรุณาระบุเหตุผล (ถ้ามี):`, 'ลูกค้าแจ้งยกเลิก');
    
    // User clicked Cancel
    if (reason === null) return;
    
    await cancelOrder(orderId, reason || 'ลูกค้าแจ้งยกเลิก');
}

/**
 * Copy Order Number to Clipboard
 */
function copyOrderNo(orderNo) {
    navigator.clipboard.writeText(orderNo).then(() => {
        showToast('📋 คัดลอกเลขที่คำสั่งซื้อแล้ว: ' + orderNo, 'success');
    }).catch(() => {
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = orderNo;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        showToast('📋 คัดลอกเลขที่คำสั่งซื้อแล้ว: ' + orderNo, 'success');
    });
}

/**
 * Copy Tracking Number to Clipboard
 */
function copyTrackingNo(trackingNo) {
    navigator.clipboard.writeText(trackingNo).then(() => {
        showToast('📦 คัดลอกเลข Tracking แล้ว: ' + trackingNo, 'success');
    }).catch(() => {
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = trackingNo;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        showToast('📦 คัดลอกเลข Tracking แล้ว: ' + trackingNo, 'success');
    });
}

/**
 * Cancel Order API Call
 */
async function cancelOrder(orderId, reason) {
    try {
        const formData = new FormData();
        formData.append('id', orderId);
        formData.append('action', 'update');
        formData.append('status', 'cancelled');
        formData.append('notes', `[ยกเลิก] ${reason}`);

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
            showToast('✅ ยกเลิกคำสั่งซื้อเรียบร้อย', 'success');
            closeOrderModal();
            await loadOrders(currentPage);
        } else {
            showToast('❌ ' + (result?.message || 'ไม่สามารถยกเลิกได้'), 'error');
        }
    } catch (error) {
        console.error('Cancel order error:', error);
        showToast('❌ เกิดข้อผิดพลาดในการยกเลิก', 'error');
    }
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
