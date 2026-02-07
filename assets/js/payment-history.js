// Payment History Page JavaScript - Enhanced with Pagination, Search, Error Handling
let allPayments = [];
let filteredPayments = [];
let currentPage = 1;
const ITEMS_PER_PAGE = 20;
let searchQuery = '';
let currentFilter = '';
let dateRangeFilter = { start: null, end: null }; // Date range filter
let targetOrderNoFromQuery = '';
let targetPaymentIdFromQuery = null; // URL param: ?id=xxx
let targetCustomerIdFromQuery = null; // URL param: ?customer_id=xxx
let urlParamStatus = ''; // URL param: ?status=pending|verified|rejected
let urlParamType = ''; // URL param: ?type=full|installment|savings
let orderSearchModalTimeout = null; // Debounce timeout for order search

/**
 * Position a fixed dropdown relative to its input element
 * This allows dropdowns to escape parent overflow:hidden containers
 */
function positionFixedDropdown(inputElement, dropdownElement) {
    if (!inputElement || !dropdownElement) return;

    const rect = inputElement.getBoundingClientRect();
    dropdownElement.style.position = 'fixed';
    dropdownElement.style.top = `${rect.bottom + 2}px`;
    dropdownElement.style.left = `${rect.left}px`;
    dropdownElement.style.width = `${rect.width}px`;
    dropdownElement.style.minWidth = '300px';
    dropdownElement.style.maxWidth = '400px';
    dropdownElement.style.zIndex = '999999';
}

function getQueryParam(name) {
    try {
        return new URLSearchParams(window.location.search).get(name);
    } catch {
        return null;
    }
}

function pageUrlSafe(pageWithExt) {
    try {
        if (typeof PATH !== 'undefined' && typeof PATH.page === 'function') return PATH.page(pageWithExt);
    } catch { /* ignore */ }
    return `/${String(pageWithExt).replace(/^\/+/, '')}`;
}

// Load payments on page load
document.addEventListener('DOMContentLoaded', () => {
    // Parse URL params for filtering
    parseUrlParams();

    loadPayments();
    setupSearchAndFilters();
    setupKeyboardShortcuts();
    setupDateFilter();
});

/**
 * Parse URL parameters for filtering and deep-linking
 * Supported params:
 *   - id: Open specific payment detail (e.g., ?id=57)
 *   - order_no: Filter by order number (deep link from orders page)
 *   - status: Filter by status (pending, verified, rejected)
 *   - type: Filter by payment type (full, installment, savings)
 *   - customer_id: Filter by customer profile ID
 *   - start_date, end_date: Date range filter (YYYY-MM-DD format)
 *   - action=create: Auto-open Add Payment modal (from pawns page)
 *   - pawn_id, pawn_no, amount, description: Pre-fill values for create
 */
function parseUrlParams() {
    // Payment ID - will auto-open modal after loading
    targetPaymentIdFromQuery = getQueryParam('id') ? parseInt(getQueryParam('id'), 10) : null;

    // Order number - filter and search
    targetOrderNoFromQuery = getQueryParam('order_no') || '';
    if (targetOrderNoFromQuery) {
        const searchInput = document.getElementById('paymentSearch');
        if (searchInput) searchInput.value = targetOrderNoFromQuery;
        searchQuery = String(targetOrderNoFromQuery).trim().toLowerCase();
    }

    // Customer ID - filter by customer
    targetCustomerIdFromQuery = getQueryParam('customer_id') ? parseInt(getQueryParam('customer_id'), 10) : null;

    // Status filter from URL (overrides default)
    urlParamStatus = getQueryParam('status') || '';
    if (urlParamStatus && ['pending', 'verified', 'rejected'].includes(urlParamStatus)) {
        // Set as current filter if it matches status-based filter
        if (urlParamStatus === 'pending') {
            currentFilter = 'pending';
            activateFilterChip('pending');
        }
    }

    // Type filter from URL
    urlParamType = getQueryParam('type') || '';
    if (urlParamType && ['full', 'installment', 'savings'].includes(urlParamType)) {
        currentFilter = urlParamType;
        activateFilterChip(urlParamType);
    }

    // Date range from URL
    const startDateParam = getQueryParam('start_date');
    const endDateParam = getQueryParam('end_date');
    if (startDateParam || endDateParam) {
        const startDateInput = document.getElementById('startDate');
        const endDateInput = document.getElementById('endDate');

        if (startDateParam && startDateInput) {
            startDateInput.value = startDateParam;
            dateRangeFilter.start = startDateParam;
        }
        if (endDateParam && endDateInput) {
            endDateInput.value = endDateParam;
            dateRangeFilter.end = endDateParam;
        }
    }

    console.log('📍 URL Params parsed:', {
        id: targetPaymentIdFromQuery,
        order_no: targetOrderNoFromQuery,
        customer_id: targetCustomerIdFromQuery,
        status: urlParamStatus,
        type: urlParamType,
        dateRange: dateRangeFilter
    });

    // Handle action=create from pawns page (auto-open create modal)
    const actionParam = getQueryParam('action');
    if (actionParam === 'create') {
        // Delay to ensure DOM is ready
        setTimeout(() => {
            openAddPaymentModalWithPrefill({
                type: getQueryParam('type') || 'deposit_interest',
                pawn_id: getQueryParam('pawn_id') || '',
                pawn_no: getQueryParam('pawn_no') || '',
                amount: getQueryParam('amount') || '',
                description: getQueryParam('description') || '',
                customer_profile_id: getQueryParam('customer_profile_id') || ''
            });
        }, 500);
    }
}

/**
 * Activate a filter chip by its data-filter value
 */
function activateFilterChip(filterValue) {
    document.querySelectorAll('.filter-tab, .filter-chip').forEach(tab => tab.classList.remove('active'));
    const chip = document.querySelector(`.filter-chip[data-filter="${filterValue}"]`)
        || document.querySelector(`.filter-tab[data-filter="${filterValue}"]`);
    if (chip) chip.classList.add('active');
}

// Load payments from API
async function loadPayments() {
    const tableBody = document.getElementById('paymentsTableBody');
    const mobileContainer = document.getElementById('paymentsMobileContainer');

    // Show loading state
    const loadingHtml = `
        <div class="loading-state" style="text-align:center;padding:2rem;">
            <div class="spinner" style="margin:0 auto 1rem;"></div>
            <p>กำลังโหลดประวัติการชำระเงิน...</p>
        </div>
    `;

    if (tableBody) {
        tableBody.innerHTML = `<tr><td colspan="7">${loadingHtml}</td></tr>`;
    }
    if (mobileContainer) {
        mobileContainer.innerHTML = loadingHtml;
    }

    try {
        const result = await apiCall(API_ENDPOINTS.CUSTOMER_PAYMENTS);

        if (result && result.success) {
            allPayments = (result.data && Array.isArray(result.data.payments))
                ? result.data.payments
                : (Array.isArray(result.data) ? result.data : []);

            // Data is already sorted by id DESC from API (newest first)
            // No need to re-sort here

            filteredPayments = allPayments;
            currentPage = 1;

            // Apply filters (including URL params like order_no)
            applyAllFilters();

            // Auto-open modal if ?id=xxx is in URL
            if (targetPaymentIdFromQuery) {
                const paymentExists = allPayments.some(p => parseInt(p.id, 10) === targetPaymentIdFromQuery);
                if (paymentExists) {
                    console.log('📍 Auto-opening payment detail for ID:', targetPaymentIdFromQuery);
                    // Small delay to ensure DOM is ready
                    setTimeout(() => {
                        viewPaymentDetail(targetPaymentIdFromQuery);
                        // Scroll to and highlight the row
                        highlightPaymentRow(targetPaymentIdFromQuery);
                    }, 100);
                } else {
                    console.warn('⚠️ Payment ID not found:', targetPaymentIdFromQuery);
                    showToast(`ไม่พบรายการชำระเงิน ID: ${targetPaymentIdFromQuery}`, 'warning');
                }
            }
        } else {
            showError('ไม่สามารถโหลดข้อมูลได้', result?.message || 'Unknown error', true);
        }
    } catch (error) {
        console.error('Error loading payments:', error);
        showError('เกิดข้อผิดพลาดในการโหลดข้อมูล', error.message, true);
    }
}

// Render payments as table with pagination
function renderPayments() {
    const tableBody = document.getElementById('paymentsTableBody');
    const mobileContainer = document.getElementById('paymentsMobileContainer');

    // Empty state
    if (!filteredPayments || filteredPayments.length === 0) {
        const emptyMessage = searchQuery
            ? `ไม่พบรายการที่ตรงกับ "${searchQuery}"`
            : currentFilter
                ? 'ไม่พบรายการในหมวดนี้'
                : 'ไม่พบรายการชำระเงิน';

        const emptyHtml = `
            <div class="payments-empty-state">
                <div class="empty-icon">💰</div>
                <p class="empty-title">${emptyMessage}</p>
                ${searchQuery || currentFilter ? `
                    <button class="btn btn-outline" onclick="clearFilters()">
                        ล้างการค้นหา/ตัวกรอง
                    </button>
                ` : ''}
            </div>
        `;

        if (tableBody) {
            tableBody.innerHTML = `<tr><td colspan="7">${emptyHtml}</td></tr>`;
        }
        if (mobileContainer) {
            mobileContainer.innerHTML = emptyHtml;
        }

        // Hide pagination
        const paginationEl = document.getElementById('paymentPagination');
        if (paginationEl) paginationEl.style.display = 'none';

        return;
    }

    // Calculate pagination
    const totalItems = filteredPayments.length;
    const totalPages = Math.ceil(totalItems / ITEMS_PER_PAGE);
    const startIndex = (currentPage - 1) * ITEMS_PER_PAGE;
    const endIndex = Math.min(startIndex + ITEMS_PER_PAGE, totalItems);
    const currentItems = filteredPayments.slice(startIndex, endIndex);

    // Render Desktop Table
    if (tableBody) {
        tableBody.innerHTML = currentItems.map(payment => {
            const statusClass = payment.status === 'verified' ? 'verified' :
                payment.status === 'pending' ? 'pending' : 'rejected';
            const statusText = payment.status === 'verified' ? 'อนุมัติแล้ว' :
                payment.status === 'pending' ? 'รอตรวจสอบ' : 'ปฏิเสธ';

            const typeClass = payment.payment_type === 'full' ? 'full' :
                payment.payment_type === 'savings' ? 'savings' :
                    payment.payment_type === 'deposit' ? 'deposit' :
                        payment.payment_type === 'deposit_interest' ? 'deposit-interest' : 'installment';
            const typeIcon = payment.payment_type === 'full' ? '💳' :
                payment.payment_type === 'savings' ? '🐷' :
                    payment.payment_type === 'deposit' ? '📦' :
                        payment.payment_type === 'deposit_interest' ? '💵' : '📅';
            const typeText = payment.payment_type === 'full' ? 'จ่ายเต็ม' :
                payment.payment_type === 'savings' ? 'ออมเงิน' :
                    payment.payment_type === 'deposit' ? 'มัดจำ' :
                        payment.payment_type === 'deposit_interest' ? 'ต่อดอกฝาก' :
                            `งวด ${payment.current_period || 1}/${payment.installment_period || 1}`;

            const orderNo = payment.order_no || '';
            const isHighlighted = targetOrderNoFromQuery && String(orderNo) === String(targetOrderNoFromQuery);
            const highlightClass = isHighlighted ? 'highlighted' : '';

            // Customer profile
            const customerName = payment.customer_name || 'ลูกค้า';
            const customerPlatform = payment.customer_platform || 'web';
            const customerAvatar = validatePaymentAvatarUrl(payment.customer_avatar);
            const platformIcon = getPaymentPlatformIcon(customerPlatform);

            const avatarHtml = customerAvatar
                ? `<img src="${customerAvatar}" alt="${customerName}" class="customer-avatar-sm" onerror="this.outerHTML='<div class=\\'customer-avatar-placeholder-sm\\'>${customerName.charAt(0).toUpperCase()}</div>'">`
                : `<div class="customer-avatar-placeholder-sm">${customerName.charAt(0).toUpperCase()}</div>`;

            return `
                <tr class="${highlightClass}" onclick="viewPaymentDetail(${payment.id})" tabindex="0" role="button">
                    <td>
                        <span class="payment-no-link">${payment.payment_no}</span>
                        ${payment.order_no ? `<div class="order-ref-sm" style="font-size:0.75rem;color:#6b7280;margin-top:2px;"><i class="fas fa-shopping-cart" style="font-size:0.65rem;margin-right:3px;"></i>${payment.order_no}</div>` : ''}
                    </td>
                    <td class="payment-date-cell">${formatDate(payment.payment_date)}</td>
                    <td>
                        <div class="customer-cell">
                            ${avatarHtml}
                            <div class="customer-info-sm">
                                <span class="customer-name-sm">
                                    ${customerName}
                                    ${platformIcon}
                                </span>
                            </div>
                        </div>
                    </td>
                    <td class="amount-cell">฿${formatNumber(payment.amount)}</td>
                    <td><span class="type-badge type-${typeClass}">${typeIcon} ${typeText}</span></td>
                    <td><span class="status-badge-sm status-${statusClass}">${statusText}</span></td>
                    <td><button class="view-btn" onclick="event.stopPropagation(); viewPaymentDetail(${payment.id})">ดู</button></td>
                </tr>
            `;
        }).join('');
    }

    // Render Mobile Cards
    if (mobileContainer) {
        mobileContainer.innerHTML = currentItems.map(payment => {
            const statusClass = payment.status === 'verified' ? 'verified' :
                payment.status === 'pending' ? 'pending' : 'rejected';
            const statusText = payment.status === 'verified' ? 'อนุมัติแล้ว' :
                payment.status === 'pending' ? 'รอตรวจสอบ' : 'ปฏิเสธ';

            const typeClass = payment.payment_type === 'full' ? 'full' :
                payment.payment_type === 'savings' ? 'savings' :
                    payment.payment_type === 'deposit' ? 'deposit' :
                        payment.payment_type === 'deposit_interest' ? 'deposit-interest' : 'installment';
            const typeIcon = payment.payment_type === 'full' ? '💳' :
                payment.payment_type === 'savings' ? '🐷' :
                    payment.payment_type === 'deposit' ? '📦' :
                        payment.payment_type === 'deposit_interest' ? '💵' : '📅';
            const typeText = payment.payment_type === 'full' ? 'จ่ายเต็ม' :
                payment.payment_type === 'savings' ? 'ออมเงิน' :
                    payment.payment_type === 'deposit' ? 'มัดจำ' :
                        payment.payment_type === 'deposit_interest' ? 'ต่อดอกฝาก' :
                            `งวด ${payment.current_period || 1}/${payment.installment_period || 1}`;

            // Customer profile
            const customerName = payment.customer_name || 'ลูกค้า';
            const customerPlatform = payment.customer_platform || 'web';
            const customerAvatar = validatePaymentAvatarUrl(payment.customer_avatar);
            const platformIcon = getPaymentPlatformIcon(customerPlatform);

            const avatarHtml = customerAvatar
                ? `<img src="${customerAvatar}" alt="${customerName}" class="customer-avatar-sm" onerror="this.outerHTML='<div class=\\'customer-avatar-placeholder-sm\\'>${customerName.charAt(0).toUpperCase()}</div>'">`
                : `<div class="customer-avatar-placeholder-sm">${customerName.charAt(0).toUpperCase()}</div>`;

            return `
                <div class="payment-mobile-card" onclick="viewPaymentDetail(${payment.id})">
                    <div class="payment-mobile-header">
                        <div>
                            <span class="payment-mobile-no">${payment.payment_no}</span>
                            ${payment.order_no ? `<div style="font-size:0.7rem;color:#6b7280;margin-top:1px;"><i class="fas fa-shopping-cart" style="font-size:0.6rem;margin-right:2px;"></i>${payment.order_no}</div>` : ''}
                        </div>
                        <span class="payment-mobile-amount">฿${formatNumber(payment.amount)}</span>
                    </div>
                    <div class="payment-mobile-customer">
                        ${avatarHtml}
                        <span class="customer-name-sm">${customerName} ${platformIcon}</span>
                    </div>
                    <div class="payment-mobile-meta">
                        <span class="payment-date-cell">${formatDate(payment.payment_date)}</span>
                        <span class="type-badge type-${typeClass}">${typeIcon} ${typeText}</span>
                        <span class="status-badge-sm status-${statusClass}">${statusText}</span>
                    </div>
                </div>
            `;
        }).join('');
    }

    // Render pagination
    renderPaginationPayment(totalItems, totalPages, startIndex, endIndex);
}

// Helper: Validate avatar URL for payments
function validatePaymentAvatarUrl(url) {
    if (!url) return null;
    if (url.includes('default_avatar') || url.includes('placeholder')) return null;
    if (!url.startsWith('http')) return null;
    return url;
}

// Helper: Get platform SVG icon for payments
function getPaymentPlatformIcon(platform) {
    const p = String(platform || '').toLowerCase();

    if (p === 'line') {
        return `<svg class="platform-icon" viewBox="0 0 24 24" fill="#06C755" width="14" height="14">
            <path d="M12 2C6.48 2 2 5.88 2 10.54c0 3.77 3.02 6.96 7.12 7.93.28.06.66.19.75.44.09.23.06.59.03.83l-.12.74c-.04.23-.18.91.79.5.97-.42 5.22-3.07 7.12-5.26C19.42 13.69 22 12.26 22 10.54 22 5.88 17.52 2 12 2zm-4.5 11.5h-2a.5.5 0 01-.5-.5v-4a.5.5 0 011 0v3.5h1.5a.5.5 0 010 1zm2-4.5a.5.5 0 011 0v4a.5.5 0 01-1 0v-4zm5.5 4a.5.5 0 01-.5.5h-2a.5.5 0 01-.5-.5v-4a.5.5 0 011 0v3.5h1.5a.5.5 0 01.5.5zm3.35.35a.5.5 0 01-.7 0L16 11.71V13a.5.5 0 01-1 0V9a.5.5 0 011 0v1.29l1.65-1.64a.5.5 0 01.7.7L16.71 11l1.64 1.65a.5.5 0 010 .7z"/>
        </svg>`;
    }

    if (p === 'facebook' || p === 'messenger') {
        return `<svg class="platform-icon" viewBox="0 0 24 24" fill="#1877F2" width="14" height="14">
            <path d="M12 2C6.477 2 2 6.145 2 11.259c0 2.913 1.454 5.512 3.726 7.21V22l3.405-1.869c.909.252 1.871.388 2.869.388 5.523 0 10-4.145 10-9.259S17.523 2 12 2zm1.008 12.476l-2.548-2.72-4.973 2.72 5.47-5.806 2.612 2.72 4.909-2.72-5.47 5.806z"/>
        </svg>`;
    }

    if (p === 'instagram') {
        return `<svg class="platform-icon" viewBox="0 0 24 24" width="14" height="14">
            <defs>
                <linearGradient id="ig-grad" x1="0%" y1="100%" x2="100%" y2="0%">
                    <stop offset="0%" style="stop-color:#FFDC80"/>
                    <stop offset="25%" style="stop-color:#F77737"/>
                    <stop offset="50%" style="stop-color:#E1306C"/>
                    <stop offset="75%" style="stop-color:#C13584"/>
                    <stop offset="100%" style="stop-color:#833AB4"/>
                </linearGradient>
            </defs>
            <path fill="url(#ig-grad)" d="M12 2c2.717 0 3.056.01 4.122.06 1.065.05 1.79.217 2.428.465.66.254 1.216.598 1.772 1.153.509.5.902 1.105 1.153 1.772.247.637.415 1.363.465 2.428.047 1.066.06 1.405.06 4.122s-.013 3.056-.06 4.122c-.05 1.065-.218 1.79-.465 2.428a4.883 4.883 0 01-1.153 1.772c-.5.508-1.105.902-1.772 1.153-.637.247-1.363.415-2.428.465-1.066.047-1.405.06-4.122.06s-3.056-.013-4.122-.06c-1.065-.05-1.79-.218-2.428-.465a4.89 4.89 0 01-1.772-1.153 4.904 4.904 0 01-1.153-1.772c-.248-.637-.415-1.363-.465-2.428C2.013 15.056 2 14.717 2 12s.01-3.056.06-4.122c.05-1.066.217-1.79.465-2.428a4.88 4.88 0 011.153-1.772A4.897 4.897 0 015.45 2.525c.638-.248 1.362-.415 2.428-.465C8.944 2.013 9.283 2 12 2zm0 5a5 5 0 100 10 5 5 0 000-10zm0 8.25a3.25 3.25 0 110-6.5 3.25 3.25 0 010 6.5zm5.25-8.5a1 1 0 11-2 0 1 1 0 012 0z"/>
        </svg>`;
    }

    // Default web/other
    return `<svg class="platform-icon" viewBox="0 0 24 24" fill="#6B7280" width="14" height="14">
        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/>
    </svg>`;
}

// Filter payments
function filterPayments(type, evt) {
    currentFilter = type;

    // Update active tab - support both .filter-tab and .filter-chip
    document.querySelectorAll('.filter-tab, .filter-chip').forEach(tab => tab.classList.remove('active'));

    const target = (evt && (evt.currentTarget || evt.target)) ? (evt.currentTarget || evt.target) : null;
    const btn = target ? target.closest('.filter-tab, .filter-chip') :
        (document.querySelector(`.filter-chip[data-filter="${type}"]`) || document.querySelector(`.filter-tab[data-filter="${type}"]`));
    if (btn) btn.classList.add('active');

    applyAllFilters(); // Use unified filter function
}

// Legacy function - now redirects to applyAllFilters()
function applyFilters() {
    applyAllFilters();
}

// Render pagination controls
function renderPaginationPayment(totalItems, totalPages, startIndex, endIndex) {
    const paginationEl = document.getElementById('paymentPagination');
    if (!paginationEl) return;

    if (totalPages <= 1) {
        paginationEl.style.display = 'none';
        return;
    }

    paginationEl.style.display = 'flex';

    const prevDisabled = currentPage === 1 ? 'disabled' : '';
    const nextDisabled = currentPage === totalPages ? 'disabled' : '';

    paginationEl.innerHTML = `
        <div class="pagination-info">
            แสดง ${startIndex + 1}-${endIndex} จาก ${totalItems} รายการ
        </div>
        <div class="pagination-controls">
            <button class="btn-pagination" onclick="goToPagePayment(1)" ${prevDisabled} aria-label="หน้าแรก">
                <i class="fas fa-angle-double-left"></i>
            </button>
            <button class="btn-pagination" onclick="goToPagePayment(${currentPage - 1})" ${prevDisabled} aria-label="หน้าก่อน">
                <i class="fas fa-angle-left"></i>
            </button>
            <span class="page-indicator">หน้า ${currentPage} / ${totalPages}</span>
            <button class="btn-pagination" onclick="goToPagePayment(${currentPage + 1})" ${nextDisabled} aria-label="หน้าถัดไป">
                <i class="fas fa-angle-right"></i>
            </button>
            <button class="btn-pagination" onclick="goToPagePayment(${totalPages})" ${nextDisabled} aria-label="หน้าสุดท้าย">
                <i class="fas fa-angle-double-right"></i>
            </button>
        </div>
    `;
}

// Go to specific page
function goToPagePayment(page) {
    const totalPages = Math.ceil(filteredPayments.length / ITEMS_PER_PAGE);
    if (page < 1 || page > totalPages) return;

    currentPage = page;
    renderPayments();

    // Scroll to top
    document.getElementById('paymentsContainer').scrollIntoView({ behavior: 'smooth' });
}

// Setup search and filters
function setupSearchAndFilters() {
    const searchInput = document.getElementById('paymentSearch');

    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            searchQuery = e.target.value.trim().toLowerCase();
            applyAllFilters(); // Use unified filter function
        });
    }
}

// Clear all filters
function clearFilters() {
    const searchInput = document.getElementById('paymentSearch');
    if (searchInput) searchInput.value = '';
    searchQuery = '';
    currentFilter = '';

    // Clear date range
    clearDateFilter();

    // Reset active tab to "all"
    document.querySelectorAll('.filter-tab').forEach(tab => tab.classList.remove('active'));
    const allTab = document.querySelector('.filter-tab[data-filter=""]');
    if (allTab) allTab.classList.add('active');

    applyAllFilters(); // Use unified filter function
}

// Setup keyboard shortcuts
function setupKeyboardShortcuts() {
    document.addEventListener('keydown', (e) => {
        // ESC - Close modal
        if (e.key === 'Escape') {
            const modal = document.getElementById('paymentModal');
            if (modal && modal.style.display === 'flex') {
                closePaymentModal();
            }
        }

        // Ctrl/Cmd + K - Focus search
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            const searchInput = document.getElementById('paymentSearch');
            if (searchInput) searchInput.focus();
        }

        // Arrow keys for pagination (when not in input)
        if (!['INPUT', 'TEXTAREA'].includes(e.target.tagName)) {
            if (e.key === 'ArrowLeft') {
                goToPagePayment(currentPage - 1);
            } else if (e.key === 'ArrowRight') {
                goToPagePayment(currentPage + 1);
            }
        }
    });
}

// View payment detail
async function viewPaymentDetail(paymentId) {
    console.log('🔍 Opening payment detail for ID:', paymentId);
    const modal = document.getElementById('paymentModal');
    const content = document.getElementById('paymentDetailsContent');

    if (!modal || !content) {
        console.error('❌ Modal elements not found!', { modal, content });
        return;
    }

    // Open modal (CSS handles centering)
    modal.style.display = 'flex';
    modal.classList.add('is-open');
    content.innerHTML = '<p style="text-align:center;padding:2rem;">กำลังโหลด...</p>';

    console.log('✅ Modal opened, loading payment details...');

    try {
        // Try to load from API first
        const result = await apiCall(API_ENDPOINTS.CUSTOMER_PAYMENT_DETAIL(paymentId));

        if (result && result.success) {
            console.log('✅ Payment data loaded from API:', result.data);
            const payment = result.data;
            content.innerHTML = renderPaymentDetails(payment);
        } else {
            console.warn('⚠️ API failed, using fallback data');
            // If API fails, check in-memory (for newly loaded list)
            const fallback = allPayments.find(p => String(p.id) === String(paymentId));
            if (fallback) {
                console.log('✅ Using fallback payment data:', fallback);
                content.innerHTML = renderPaymentDetails(fallback);
            } else {
                console.error('❌ No payment data found for ID:', paymentId);
                content.innerHTML = '<p style="color:var(--color-danger);text-align:center;">ไม่สามารถโหลดข้อมูลได้</p>';
            }
        }
    } catch (error) {
        console.error('❌ Error loading payment:', error);
        // Fallback to in-memory if API call fails
        const fallback = allPayments.find(p => String(p.id) === String(paymentId));
        if (fallback) {
            console.log('✅ Using fallback payment data (after error):', fallback);
            content.innerHTML = renderPaymentDetails(fallback);
        } else {
            console.error('❌ No fallback data available');
            content.innerHTML = '<p style="color:var(--color-danger);text-align:center;">เกิดข้อผิดพลาด</p>';
        }
    }
}

function normalizeSlipUrl(url) {
    if (!url) return '';

    // If already absolute URL (http/https), return as-is
    if (/^https?:\/\//i.test(url)) return url;

    let u = String(url).trim();

    // Remove any /autobot or /public prefix from database (legacy data)
    u = u.replace(/^\/autobot/, '');
    u = u.replace(/^\/public/, '');

    // Handle mock slip images (slip-kbank.svg, slip-scb.svg, slip-promptpay.svg)
    const mockSlipPattern = /^(slip-.*\.svg|receipt-mock\.svg)$/i;
    const filenameOnly = u.split('/').pop();

    if (mockSlipPattern.test(filenameOnly)) {
        return (typeof PATH !== 'undefined' && PATH.image)
            ? PATH.image(filenameOnly)
            : `/images/${filenameOnly}`;
    }

    // Real uploaded files: normalize to /uploads/slips/...
    if (u.startsWith('/uploads/')) {
        // Already correct format, just use PATH helper
        console.log('🖼️ Loading slip from:', u);
        return u; // Apache Alias will handle this
    }

    // Fallback: assume it's a filename only
    const cleanPath = '/uploads/slips/' + filenameOnly;
    console.log('🖼️ Fallback slip path:', cleanPath);
    return cleanPath;
}

// Render payment details
function renderPaymentDetails(payment) {
    const statusClass = payment.status === 'verified' ? 'verified' :
        (payment.status === 'pending' || payment.status === 'verifying') ? 'pending' : 'rejected';
    const statusText = payment.status === 'verified' ? '✅ อนุมัติแล้ว' :
        (payment.status === 'pending' || payment.status === 'verifying') ? '⏳ รอตรวจสอบ' : '❌ ปฏิเสธ';
    const statusEmoji = payment.status === 'verified' ? '✅' :
        (payment.status === 'pending' || payment.status === 'verifying') ? '⏳' : '❌';

    const reviewHint = payment.status === 'pending' || payment.status === 'verifying'
        ? 'ระบบกำลังตรวจสอบสลิปของคุณ (OCR/ตรวจความถูกต้อง) โดยปกติใช้เวลาไม่กี่นาที'
        : payment.status === 'rejected'
            ? 'สลิปอาจไม่ชัดเจน/ยอดเงินไม่ตรง กรุณาอัปโหลดใหม่ หรือแจ้งเจ้าหน้าที่'
            : 'ชำระเงินสำเร็จและได้รับการยืนยันเรียบร้อย';

    const canModerate = true; // allow all logged-in users to approve/reject from this view

    // Extract customer profile - use API-provided customer_name/customer_avatar
    let customerName = payment.customer_name || payment.sender_name || 'ลูกค้า';
    let customerAvatar = validatePaymentAvatarUrl(payment.customer_avatar);
    let customerPlatform = payment.customer_platform || 'web';
    let customerPhone = payment.customer_phone || '';

    // Generate initials for placeholder
    const initials = customerName.split(' ').map(n => n.charAt(0)).join('').substr(0, 2).toUpperCase() || 'U';

    // Build platform icon
    const platformIcon = getPaymentPlatformIcon(customerPlatform);
    const platformLabel = customerPlatform === 'line' ? 'LINE' :
        customerPlatform === 'facebook' ? 'Facebook' :
            customerPlatform === 'instagram' ? 'Instagram' : 'Web';

    // Payment type label
    const typeLabel = payment.payment_type === 'full' ? 'จ่ายเต็ม' :
        payment.payment_type === 'installment' ? `ผ่อน งวด ${payment.current_period || 1}/${payment.installment_period || 1}` :
            payment.payment_type === 'savings' ? 'ออมเงิน' : 'ทั่วไป';

    let html = `
        <div class="slip-chat-layout">
            <!-- ✅ NEW: Summary Card (Key Info at Top) -->
            <div class="payment-summary-card">
                <div class="summary-main">
                    <div class="summary-amount">฿${formatNumber(payment.amount || 0)}</div>
                    <div class="summary-status status-${statusClass}">${statusEmoji} ${statusText.replace(/^[✅⏳❌]\s*/, '')}</div>
                </div>
                <div class="summary-meta">
                    <span class="summary-meta-item">
                        <i class="fas fa-receipt"></i> ${payment.payment_no || '-'}
                    </span>
                    <span class="summary-meta-item">
                        <i class="fas fa-calendar"></i> ${formatDate(payment.payment_date)}
                    </span>
                    <span class="summary-meta-item">
                        <i class="fas fa-tag"></i> ${typeLabel}
                    </span>
                </div>
            </div>
            
            <!-- ✅ Compact Customer Info Row -->
            <div class="customer-info-row">
                <div class="customer-mini-avatar">
                    ${customerAvatar
            ? `<img src="${customerAvatar}" alt="${customerName}" onerror="this.outerHTML='<div class=\\'avatar-placeholder-mini\\'>${initials}</div>'">`
            : `<div class="avatar-placeholder-mini">${initials}</div>`
        }
                </div>
                <div class="customer-mini-info">
                    <span class="customer-mini-name">${customerName}</span>
                    <span class="customer-mini-meta">${platformIcon} ${platformLabel}${customerPhone ? ' • ' + customerPhone : ''}</span>
                </div>
                ${payment.order_no ? `
                    <a class="order-link-badge" href="#" onclick="event.preventDefault(); goToOrderFromPayment('${String(payment.order_no).replace(/'/g, "\\'")}');">
                        <i class="fas fa-shopping-cart"></i> ${payment.order_no}
                    </a>
                ` : ''}
                ${payment.repair_no ? `
                    <a class="repair-link-badge" href="${pageUrlSafe('repairs.php')}?id=${payment.repair_id}">
                        <i class="fas fa-tools"></i> ${payment.repair_no}
                    </a>
                ` : ''}
            </div>
            
            <!-- PRODUCT INFO (if linked to order) -->
            ${payment.order_id && payment.product_name ? `
            <div class="product-info-section" style="display: flex; align-items: center; gap: 1rem; padding: 0.75rem; background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); border-radius: 8px; margin-bottom: 1rem; border: 1px solid #bae6fd;">
                ${payment.product_image_url ? `
                <div class="product-thumb" style="flex-shrink: 0;">
                    <img src="${payment.product_image_url}" alt="${payment.product_name}" 
                         style="width: 60px; height: 60px; object-fit: cover; border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.1);"
                         onerror="this.style.display='none';">
                </div>
                ` : `
                <div class="product-thumb-placeholder" style="flex-shrink: 0; width: 60px; height: 60px; background: #e0f2fe; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                    <i class="fas fa-box" style="color: #0284c7; font-size: 1.5rem;"></i>
                </div>
                `}
                <div class="product-details" style="flex: 1; min-width: 0;">
                    <div style="font-weight: 600; color: #0369a1; font-size: 0.95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                        ${payment.product_name}
                    </div>
                    <div style="font-size: 0.8rem; color: #64748b; margin-top: 2px;">
                        ${payment.product_code ? `<span style="background: #e2e8f0; padding: 2px 6px; border-radius: 4px; font-family: monospace;">${payment.product_code}</span>` : ''}
                        ${payment.order_quantity > 1 ? `<span style="margin-left: 0.5rem;">x${payment.order_quantity}</span>` : ''}
                    </div>
                    <div style="font-size: 0.85rem; color: #0369a1; margin-top: 4px; font-weight: 500;">
                        ยอดสั่งซื้อ: ฿${formatNumber(payment.order_total_amount || 0)}
                        ${payment.order_paid_amount > 0 ? `<span style="color: #16a34a; margin-left: 0.5rem;">(ชำระแล้ว ฿${formatNumber(payment.order_paid_amount)})</span>` : ''}
                    </div>
                </div>
            </div>
            ` : ''}
            
            <!-- SLIP IMAGE (Most Important) -->
            ${payment.slip_image ? (() => {
            const slipSrc = normalizeSlipUrl(payment.slip_image);
            return `
                    <div class="detail-section">
                        <h3>🖼️ ใบเสร็จ/สลิปที่แนบมา</h3>
                        <div class="slip-image-container">
                            <img 
                                src="${slipSrc}" 
                                class="slip-image" 
                                alt="Payment Slip" 
                                onclick="toggleSlipZoom(this)"
                                onerror="handleSlipImageError(this)"
                                loading="lazy"
                            >
                            <p class="slip-caption">💡 คลิกที่รูปเพื่อซูม</p>
                        </div>
                    </div>
                `;
        })() : `
                <div class="detail-section">
                    <h3>🖼️ ใบเสร็จ/สลิป</h3>
                    <div class="slip-image-container" style="text-align: center; padding: 3rem 1.5rem;">
                        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" style="margin: 0 auto; color: var(--color-gray); opacity: 0.5;">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2" stroke-width="2"/>
                            <circle cx="8.5" cy="8.5" r="1.5" stroke-width="2"/>
                            <polyline points="21 15 16 10 5 21" stroke-width="2"/>
                        </svg>
                        <p style="color:var(--color-gray); margin-top: 1rem;">ยังไม่มีไฟล์สลิปที่แนบมา</p>
                    </div>
                </div>
            `}

            <!-- Link to Order with Autocomplete -->
            <div class="detail-section">
                <h3>🔗 อ้างอิงคำสั่งซื้อ</h3>
                <div class="order-reference-container">
                    ${payment.order_no ? `
                        <div class="current-order-link">
                            <span class="order-badge">
                                <i class="fas fa-shopping-cart"></i> ${payment.order_no}
                            </span>
                            <a class="btn btn-outline btn-sm" href="${pageUrlSafe('orders.php')}?order_no=${encodeURIComponent(payment.order_no)}" onclick="event.preventDefault(); goToOrderFromPayment('${String(payment.order_no).replace(/'/g, "\\'")}');">
                                <i class="fas fa-external-link-alt"></i> ดูคำสั่งซื้อ
                            </a>
                            <button class="btn btn-outline btn-sm" onclick="clearOrderMapping(${payment.id})" title="ยกเลิกการเชื่อมโยง">
                                <i class="fas fa-unlink"></i>
                            </button>
                        </div>
                    ` : `
                        <div class="order-search-inline">
                            <div class="autocomplete-wrapper" style="flex:1;">
                                <input type="text" id="orderSearchModal-${payment.id}" class="form-control" 
                                       placeholder="ค้นหาเลขคำสั่งซื้อเพื่อเชื่อมโยง..." 
                                       autocomplete="off"
                                       oninput="searchOrdersForPayment(${payment.id}, this.value)">
                                <div id="orderSuggestionsModal-${payment.id}" class="autocomplete-suggestions"></div>
                            </div>
                            <button class="btn btn-primary btn-sm" onclick="linkOrderToPayment(${payment.id})" id="linkOrderBtn-${payment.id}" disabled>
                                <i class="fas fa-link"></i> เชื่อมโยง
                            </button>
                        </div>
                        <input type="hidden" id="selectedOrderId-${payment.id}" value="">
                    `}
                </div>
            </div>

            <!-- Link to Repair with Autocomplete -->
            <div class="detail-section">
                <h3>🔧 อ้างอิงงานซ่อม</h3>
                <div class="order-reference-container">
                    ${payment.repair_id && payment.repair_no ? `
                        <div class="current-order-link">
                            <span class="order-badge" style="background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);">
                                <i class="fas fa-tools"></i> ${payment.repair_no}
                            </span>
                            <a class="btn btn-outline btn-sm" href="${pageUrlSafe('repairs.php')}?id=${payment.repair_id}">
                                <i class="fas fa-external-link-alt"></i> ดูงานซ่อม
                            </a>
                            <button class="btn btn-outline btn-sm" onclick="unlinkRepairFromPayment(${payment.id})" title="ยกเลิกการเชื่อมโยง">
                                <i class="fas fa-unlink"></i>
                            </button>
                        </div>
                    ` : `
                        <div class="order-search-inline">
                            <div class="autocomplete-wrapper" style="flex:1;">
                                <input type="text" id="repairSearchModal-${payment.id}" class="form-control" 
                                       placeholder="ค้นหาเลขงานซ่อมเพื่อเชื่อมโยง..." 
                                       autocomplete="off"
                                       oninput="searchRepairsForPayment(${payment.id}, this.value)">
                                <div id="repairSuggestionsModal-${payment.id}" class="autocomplete-suggestions"></div>
                            </div>
                            <button class="btn btn-primary btn-sm" onclick="linkRepairToPayment(${payment.id})" id="linkRepairBtn-${payment.id}" disabled>
                                <i class="fas fa-link"></i> เชื่อมโยง
                            </button>
                        </div>
                        <input type="hidden" id="selectedRepairId-${payment.id}" value="">
                    `}
                </div>
            </div>

            <!-- ✅ Link to Pawn with Autocomplete -->
            <div class="detail-section">
                <h3>💎 อ้างอิงจำนำ</h3>
                <div class="order-reference-container">
                    ${payment.pawn_id && payment.pawn_no ? `
                        <div class="current-order-link">
                            <span class="order-badge" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);">
                                <i class="fas fa-gem"></i> ${payment.pawn_no}
                            </span>
                            <a class="btn btn-outline btn-sm" href="${pageUrlSafe('pawns.php')}?id=${payment.pawn_id}">
                                <i class="fas fa-external-link-alt"></i> ดูจำนำ
                            </a>
                            <button class="btn btn-outline btn-sm" onclick="unlinkPawnFromPayment(${payment.id})" title="ยกเลิกการเชื่อมโยง">
                                <i class="fas fa-unlink"></i>
                            </button>
                        </div>
                    ` : `
                        <div class="order-search-inline">
                            <div class="autocomplete-wrapper" style="flex:1;">
                                <input type="text" id="pawnSearchModal-${payment.id}" class="form-control" 
                                       placeholder="ค้นหาเลขจำนำเพื่อเชื่อมโยง (เช่น PWN-xxx, ค่าดอก)..." 
                                       autocomplete="off"
                                       oninput="searchPawnsForPayment(${payment.id}, this.value)">
                                <div id="pawnSuggestionsModal-${payment.id}" class="autocomplete-suggestions"></div>
                            </div>
                            <button class="btn btn-primary btn-sm" onclick="linkPawnToPayment(${payment.id})" id="linkPawnBtn-${payment.id}" disabled>
                                <i class="fas fa-link"></i> เชื่อมโยง
                            </button>
                        </div>
                        <input type="hidden" id="selectedPawnId-${payment.id}" value="">
                    `}
                </div>
            </div>

            <div class="detail-section">
                <h3>📄 ข้อมูลการชำระเงิน</h3>
                <div class="detail-grid">
                    <div class="detail-item">
                        <div class="detail-label">เลขที่การชำระ</div>
                        <div class="detail-value">${payment.payment_no || '-'}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">สถานะ</div>
                        <div class="detail-value">
                            <span class="payment-status status-${statusClass}">${statusText}</span>
                        </div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">จำนวนเงิน</div>
                        <div class="detail-value" style="color:var(--color-primary);font-size:1.25rem;">฿${formatNumber(payment.amount || 0)}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">วันที่ชำระ</div>
                        <div class="detail-value">${formatDateTime(payment.payment_date)}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">วิธีชำระ</div>
                        <div class="detail-value">${getPaymentMethodText(payment.payment_method)}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">ธนาคาร</div>
                        <div class="detail-value">${payment.bank_name || '-'}</div>
                    </div>
                </div>
            </div>

            ${payment.source !== 'manual' ? `
            <div class="detail-section slip-chat-box" style="display: none;">
                <h3>💬 ประวัติการสนทนา</h3>
                <div class="slip-chat-bubbles">
                    ${renderChatMessages(payment.chat_messages, customerName, payment, reviewHint)}
                </div>
            </div>
            ` : `
            <div class="detail-section">
                <h3>📝 แหล่งที่มา</h3>
                <div style="padding: 1rem; background: #f0f9ff; border-radius: 8px; color: #0369a1;">
                    <i class="fas fa-user-edit"></i> รายการนี้สร้างจากหน้าเว็บโดยผู้ใช้งาน
                </div>
            </div>
            `}

            ${canModerate ? `
            <div class="detail-section slip-approve-panel">
                <h3>✅ ตรวจสอบและจัดประเภทการชำระเงิน</h3>
                
                ${payment.status === 'verified' ? `
                <div class="classification-done" style="padding: 1rem; background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%); border-radius: 8px; margin-bottom: 1rem; border: 1px solid #86efac;">
                    <div style="display: flex; align-items: center; gap: 0.75rem; color: #166534; font-weight: 600;">
                        <i class="fas fa-check-circle" style="font-size: 1.5rem;"></i>
                        <span>อนุมัติแล้ว</span>
                    </div>
                    <div style="margin-top: 0.5rem; color: #15803d; font-size: 0.9rem;">
                        ประเภท: ${getPaymentTypeText(payment.payment_type)}
                        ${payment.payment_type === 'installment' ? ` (งวดที่ ${payment.current_period || 1}/${payment.installment_period || 3})` : ''}
                    </div>
                </div>
                ` : payment.status === 'rejected' ? `
                <div class="classification-done" style="padding: 1rem; background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); border-radius: 8px; margin-bottom: 1rem; border: 1px solid #fca5a5;">
                    <div style="display: flex; align-items: center; gap: 0.75rem; color: #991b1b; font-weight: 600;">
                        <i class="fas fa-times-circle" style="font-size: 1.5rem;"></i>
                        <span>ปฏิเสธแล้ว</span>
                    </div>
                </div>
                ` : `
                <!-- Classification Section (only for pending payments) -->
                <div class="classification-section">
                    <div class="classify-row">
                        <label for="paymentType-${payment.id}">ประเภทการชำระ</label>
                        <select id="paymentType-${payment.id}" class="classify-select" onchange="onPaymentTypeChange(${payment.id})">
                            <option value="">-- เลือกประเภท --</option>
                            <option value="full" ${payment.payment_type === 'full' ? 'selected' : ''}>💳 จ่ายเต็ม (Full Payment)</option>
                            <option value="deposit" ${payment.payment_type === 'deposit' ? 'selected' : ''}>💎 มัดจำ (Deposit)</option>
                            <option value="installment" ${payment.payment_type === 'installment' ? 'selected' : ''}>📅 ผ่อนชำระ (Installment)</option>
                            <option value="savings_deposit" ${payment.payment_type === 'savings_deposit' ? 'selected' : ''}>🐷 ฝากออม (Savings)</option>
                        </select>
                    </div>
                    
                    <!-- Installment Period (shown only when installment selected) -->
                    <div id="periodSection-${payment.id}" class="reference-section" style="display: ${payment.payment_type === 'installment' ? 'block' : 'none'};">
                        <label>งวดที่ชำระ</label>
                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.5rem;">
                            <input type="number" id="currentPeriod-${payment.id}" class="period-input" 
                                   value="${payment.current_period || 1}" min="1" max="12" placeholder="งวดที่">
                            <span>/</span>
                            <input type="number" id="totalPeriod-${payment.id}" class="period-input" 
                                   value="${payment.installment_period || 3}" min="1" max="12" placeholder="รวม">
                            <span>งวด</span>
                        </div>
                    </div>
                    
                    <!-- Note -->
                    <div class="classify-row" style="margin-top: 1rem;">
                        <label for="classifyNote-${payment.id}">หมายเหตุ (ถ้ามี)</label>
                        <input type="text" id="classifyNote-${payment.id}" class="classify-input" 
                               placeholder="เช่น ลูกค้าโอนเกิน, ตรวจสอบกับบัญชี..."
                               value="">
                    </div>
                </div>
                
                <div class="action-row">
                    <button class="btn btn-success" onclick="classifyAndApprovePayment(${payment.id})">
                        ✅ อนุมัติและบันทึก
                    </button>
                    <button class="btn btn-danger" onclick="rejectPayment(${payment.id})">
                        ❌ ปฏิเสธสลิป
                    </button>
                </div>
                `}
            </div>` : ''}
        </div>
    `;

    return html;
}

// Approve / Reject via admin API (used by all roles for demo)
async function approvePayment(paymentId) {
    if (!confirm('ยืนยันการอนุมัติสลิปนี้หรือไม่?')) return;

    showToast('กำลังดำเนินการ...', 'info');

    try {
        const url = (typeof API_ENDPOINTS !== 'undefined' && API_ENDPOINTS.ADMIN_PAYMENT_APPROVE)
            ? API_ENDPOINTS.ADMIN_PAYMENT_APPROVE(paymentId)
            : `/api/admin/payments/${paymentId}/approve`;
        const result = await apiCall(url, { method: 'PUT' });
        if (result && result.success) {
            showToast('✅ อนุมัติสลิปเรียบร้อย', 'success');
            closePaymentModal(); // ปิด popup
            await loadPayments();
        } else {
            showToast('❌ ไม่สามารถอนุมัติได้: ' + (result && result.message ? result.message : 'ไม่ทราบสาเหตุ'), 'error');
        }
    } catch (e) {
        console.error(e);
        showToast('❌ เกิดข้อผิดพลาดในการอนุมัติ', 'error');
    }
}

/**
 * ✅ Toggle installment period section visibility
 */
function onPaymentTypeChange(paymentId) {
    const typeSelect = document.getElementById(`paymentType-${paymentId}`);
    const periodSection = document.getElementById(`periodSection-${paymentId}`);

    if (typeSelect && periodSection) {
        periodSection.style.display = typeSelect.value === 'installment' ? 'block' : 'none';
    }
}

/**
 * ✅ Classify and approve payment with type selection
 */
async function classifyAndApprovePayment(paymentId) {
    console.log('[classifyAndApprovePayment] Called with paymentId:', paymentId);

    const typeSelect = document.getElementById(`paymentType-${paymentId}`);
    const noteInput = document.getElementById(`classifyNote-${paymentId}`);
    const currentPeriodInput = document.getElementById(`currentPeriod-${paymentId}`);
    const totalPeriodInput = document.getElementById(`totalPeriod-${paymentId}`);

    console.log('[classifyAndApprovePayment] typeSelect:', typeSelect);
    console.log('[classifyAndApprovePayment] typeSelect.value:', typeSelect ? typeSelect.value : 'N/A');

    const paymentType = typeSelect ? typeSelect.value : '';
    const note = noteInput ? noteInput.value.trim() : '';
    const currentPeriod = currentPeriodInput ? parseInt(currentPeriodInput.value) || 1 : 1;
    const totalPeriod = totalPeriodInput ? parseInt(totalPeriodInput.value) || 3 : 3;

    // Validate
    if (!paymentType) {
        console.warn('[classifyAndApprovePayment] No payment type selected!');
        alert('⚠️ กรุณาเลือกประเภทการชำระก่อน');
        showToast('⚠️ กรุณาเลือกประเภทการชำระก่อน', 'error');
        if (typeSelect) typeSelect.focus();
        return;
    }

    // Confirm
    const typeLabels = {
        'full': 'จ่ายเต็ม',
        'installment': `ผ่อนชำระ งวดที่ ${currentPeriod}/${totalPeriod}`,
        'savings_deposit': 'ฝากออม'
    };

    const confirmMsg = `ยืนยันอนุมัติการชำระเงินนี้เป็น "${typeLabels[paymentType] || paymentType}"?`;
    if (!confirm(confirmMsg)) return;

    showToast('กำลังบันทึก...', 'info');

    try {
        // Build request body
        const body = {
            payment_id: paymentId,
            payment_type: paymentType,
            classification_notes: note
        };

        // Add installment info if applicable
        if (paymentType === 'installment') {
            body.current_period = currentPeriod;
            body.installment_period = totalPeriod;
        }

        // Call classify API
        const url = (typeof API_ENDPOINTS !== 'undefined' && API_ENDPOINTS.CUSTOMER_PAYMENTS)
            ? `${API_ENDPOINTS.CUSTOMER_PAYMENTS}?action=classify`
            : `/api/customer/payments.php?action=classify`;

        const result = await apiCall(url, {
            method: 'POST',
            body: body
        });

        if (result && result.success) {
            showToast('✅ บันทึกและอนุมัติเรียบร้อย', 'success');
            await loadPayments();
            const updated = allPayments.find(p => String(p.id) === String(paymentId));
            if (updated) {
                document.getElementById('paymentDetailsContent').innerHTML = renderPaymentDetails(updated);
            }
        } else {
            showToast('❌ ไม่สามารถบันทึกได้: ' + (result && result.message ? result.message : 'ไม่ทราบสาเหตุ'), 'error');
        }
    } catch (e) {
        console.error('Classify error:', e);
        showToast('❌ เกิดข้อผิดพลาด', 'error');
    }
}

async function rejectPayment(paymentId) {
    const reason = prompt('กรุณาระบุเหตุผลการปฏิเสธสลิป');
    if (reason === null || reason.trim() === '') return;

    showToast('กำลังดำเนินการ...', 'info');

    try {
        const url = (typeof API_ENDPOINTS !== 'undefined' && API_ENDPOINTS.ADMIN_PAYMENT_REJECT)
            ? API_ENDPOINTS.ADMIN_PAYMENT_REJECT(paymentId)
            : `/api/admin/payments/${paymentId}/reject`;

        const result = await apiCall(url, { method: 'PUT', body: { reason } });
        if (result && result.success) {
            showToast('✅ บันทึกการปฏิเสธสลิปแล้ว', 'success');
            closePaymentModal(); // ปิด popup
            await loadPayments();
        } else if (result && result.status === 401) {
            // Token expired or invalid
            showToast('❌ Token หมดอายุ กรุณา Login ใหม่', 'error');
        } else {
            showToast('❌ ไม่สามารถปฏิเสธได้: ' + (result && result.message ? result.message : 'ไม่ทราบสาเหตุ'), 'error');
        }
    } catch (e) {
        console.error(e);
        showToast('❌ เกิดข้อผิดพลาดในการปฏิเสธ', 'error');
    }
}

// Toast notification helper
function showToast(message, type = 'info') {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = `toast ${type} show`;
    setTimeout(() => {
        toast.classList.remove('show');
    }, 3000);
}

// Close modal
function closePaymentModal() {
    const modal = document.getElementById('paymentModal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.style.display = 'none';
}

function toggleSlipZoom(imgElement) {
    if (!imgElement) return;

    const isZoomed = imgElement.classList.contains('zoomed');

    const ensureBackdrop = () => {
        let bd = document.querySelector('.slip-zoom-backdrop');
        if (!bd) {
            bd = document.createElement('div');
            bd.className = 'slip-zoom-backdrop';
            bd.addEventListener('click', () => {
                const z = document.querySelector('.slip-image.zoomed');
                if (z) toggleSlipZoom(z);
            });
            document.body.appendChild(bd);
        }
        return bd;
    };

    const removeBackdrop = () => {
        const bd = document.querySelector('.slip-zoom-backdrop');
        if (bd) bd.remove();
    };

    if (isZoomed) {
        imgElement.classList.remove('zoomed');
        removeBackdrop();
        document.body.style.overflow = '';
    } else {
        // create backdrop first, then put image above it
        ensureBackdrop();
        imgElement.classList.add('zoomed');
        document.body.style.overflow = 'hidden';
    }
}

// Handle slip image loading error - show empty placeholder (no mock images)
function handleSlipImageError(imgElement) {
    if (!imgElement) return;

    // Replace with empty placeholder directly (no mock fallback)
    const container = imgElement.parentElement;
    if (container) {
        container.innerHTML = `
            <div style="text-align: center; padding: 3rem 1.5rem; background: #f9fafb; border-radius: 8px;">
                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" style="margin: 0 auto; color: #9ca3af;">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2" stroke-width="2"/>
                    <circle cx="8.5" cy="8.5" r="1.5" stroke-width="2"/>
                    <polyline points="21 15 16 10 5 21" stroke-width="2"/>
                </svg>
                <p style="color: #6b7280; margin-top: 1rem; font-size: 0.9rem;">ไม่สามารถแสดงภาพสลิปได้</p>
            </div>
        `;
    }
}

// Close zoomed image when clicking outside or pressing ESC
document.addEventListener('click', (e) => {
    const zoomedImg = document.querySelector('.slip-image.zoomed');
    if (zoomedImg && e.target === zoomedImg) {
        toggleSlipZoom(zoomedImg);
    }
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        const zoomedImg = document.querySelector('.slip-image.zoomed');
        if (zoomedImg) {
            toggleSlipZoom(zoomedImg);
        } else {
            closePaymentModal();
        }
    }
});

// Helper functions
function formatNumber(num) {
    return parseFloat(num).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatDate(date) {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('th-TH', { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatDateTime(date) {
    if (!date) return '-';
    return new Date(date).toLocaleString('th-TH');
}

function getPaymentMethodText(method) {
    const methods = {
        'bank_transfer': 'โอนเงิน',
        'promptpay': 'พร้อมเพย์',
        'credit_card': 'บัตรเครดิต',
        'cash': 'เงินสด'
    };
    return methods[method] || method;
}

function getPaymentTypeText(type) {
    const types = {
        'full': '💳 จ่ายเต็ม',
        'deposit': '💎 มัดจำ',
        'installment': '📅 ผ่อนชำระ',
        'savings_deposit': '🐷 ฝากออม'
    };
    return types[type] || type || '-';
}

/**
 * ✅ Render chat messages from database or show fallback
 */
function renderChatMessages(chatMessages, customerName, payment, reviewHint) {
    // If we have real chat messages from database, render them
    if (chatMessages && Array.isArray(chatMessages) && chatMessages.length > 0) {
        let html = '';

        // Keep track if we've shown slip image already
        let slipImageShown = false;
        const slipImageUrl = payment.slip_image ? normalizeSlipUrl(payment.slip_image) : '';

        chatMessages.forEach(msg => {
            const isBot = msg.sender_type === 'bot' || msg.direction === 'outgoing' || msg.role === 'assistant' || msg.role === 'system';
            const bubbleClass = isBot ? 'bubble-bot' : 'bubble-user';
            const label = isBot ? 'Bot' : customerName;

            let content = '';
            const msgText = msg.message_text || msg.text || '';

            // Handle different message types
            if (msg.message_type === 'image') {
                // Check for image URL in message_data
                let imageUrl = '';
                if (msg.message_data && msg.message_data.attachments && msg.message_data.attachments[0]) {
                    imageUrl = msg.message_data.attachments[0].url;
                }

                if (imageUrl) {
                    content = `<img src="${imageUrl}" alt="รูปภาพ" style="max-width: 200px; border-radius: 8px; cursor: pointer;" onclick="window.open('${imageUrl}', '_blank')">`;
                } else {
                    content = '<em>[รูปภาพ]</em>';
                }
            } else if (msgText.startsWith('[image]')) {
                // Handle [image] with optional URL following it
                // Format: "[image] https://..." or just "[image]"
                const urlMatch = msgText.match(/\[image\]\s*(https?:\/\/\S+)/i);
                if (urlMatch && urlMatch[1]) {
                    // Has URL after [image]
                    const imageUrl = urlMatch[1].trim();
                    content = `<img src="${imageUrl}" alt="รูปภาพ" style="max-width: 200px; border-radius: 8px; cursor: pointer;" onclick="window.open('${imageUrl}', '_blank')"><br><small style="color:#999;">📷 รูปภาพที่ส่ง</small>`;
                } else if (slipImageUrl && !slipImageShown) {
                    // Just [image] - show slip image if available
                    content = `<img src="${slipImageUrl}" alt="สลิปโอนเงิน" style="max-width: 200px; border-radius: 8px; cursor: pointer;" onclick="window.open('${slipImageUrl}', '_blank')"><br><small style="color:#999;">📷 สลิปโอนเงิน</small>`;
                    slipImageShown = true;
                } else {
                    content = '<em>📷 [รูปภาพ]</em>';
                }
            } else {
                content = escapeHtml(msgText);
            }

            const timestamp = msg.sent_at ? formatDateTime(msg.sent_at) : '';

            html += `
                <div class="bubble ${bubbleClass}">
                    <div class="bubble-label">${label} ${timestamp ? `<small style="color:#999;font-weight:normal;">${timestamp}</small>` : ''}</div>
                    <div class="bubble-text">${content}</div>
                </div>
            `;
        });

        // Add system status message at the end
        html += `
            <div class="bubble bubble-bot">
                <div class="bubble-label">System</div>
                <div class="bubble-text">${reviewHint}</div>
            </div>
        `;

        return html;
    }

    // Fallback: Show mock data if no real messages
    return `
        <div class="bubble bubble-bot">
            <div class="bubble-label">Bot</div>
            <div class="bubble-text">
                ตรวจพบการชำระยอด <strong>฿${formatNumber(payment.amount || 0)}</strong> สำหรับคำสั่งซื้อ <strong>${payment.order_no || '-'}</strong><br>
                ช่องทางชำระ: ${getPaymentMethodText(payment.payment_method)}
            </div>
        </div>
        <div class="bubble bubble-user">
            <div class="bubble-label">${customerName}</div>
            <div class="bubble-text">
                ส่งสลิปการโอนเพื่อยืนยันการชำระเงินเรียบร้อยแล้วค่ะ
            </div>
        </div>
        <div class="bubble bubble-bot">
            <div class="bubble-label">System</div>
            <div class="bubble-text">${reviewHint}</div>
        </div>
    `;
}

/**
 * Helper: Escape HTML to prevent XSS
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Search orders for payment modal autocomplete
 */
async function searchOrdersForPayment(paymentId, query) {
    const input = document.getElementById(`orderSearchModal-${paymentId}`);
    const suggestions = document.getElementById(`orderSuggestionsModal-${paymentId}`);
    const linkBtn = document.getElementById(`linkOrderBtn-${paymentId}`);

    if (!suggestions) return;

    // Clear previous timeout
    clearTimeout(orderSearchModalTimeout);

    // Hide if query is too short
    if (!query || query.length < 2) {
        suggestions.classList.remove('show');
        suggestions.innerHTML = '';
        if (linkBtn) linkBtn.disabled = true;
        return;
    }

    // Get payment to find customer's platform_user_id for filtering
    const payment = allPayments.find(p => String(p.id) === String(paymentId));
    const platformUserId = payment?.customer_platform_id || payment?.platform_user_id || '';

    // Debounce
    orderSearchModalTimeout = setTimeout(async () => {
        try {
            // Build URL with optional platform_user_id filter
            let url = `${API_ENDPOINTS.SEARCH_ORDERS}?q=${encodeURIComponent(query)}`;
            if (platformUserId) {
                url += `&platform_user_id=${encodeURIComponent(platformUserId)}`;
            }

            const result = await apiCall(url);

            if (result.success && result.data && result.data.length > 0) {
                suggestions.innerHTML = result.data.map(order => `
                    <div class="autocomplete-item" onclick="selectOrderForPayment(${paymentId}, ${JSON.stringify(order).replace(/"/g, '&quot;')})">
                        <div class="autocomplete-item-info">
                            <div class="autocomplete-item-name">
                                <i class="fas fa-shopping-cart" style="color:#6366f1;"></i>
                                ${escapeHtml(order.order_no || order.order_number || '#' + order.id)}
                            </div>
                            <div class="autocomplete-item-meta">
                                ${order.customer_name ? escapeHtml(order.customer_name) + ' • ' : ''}
                                ${order.total_amount ? '฿' + formatNumber(order.total_amount) : ''}
                                ${order.status ? ` • ${order.status}` : ''}
                            </div>
                        </div>
                    </div>
                `).join('');
                // Position dropdown using fixed positioning to escape overflow:hidden
                positionFixedDropdown(input, suggestions);
                suggestions.classList.add('show');
            } else {
                suggestions.innerHTML = '<div class="autocomplete-item"><em style="color:#9ca3af;">ไม่พบคำสั่งซื้อ</em></div>';
                positionFixedDropdown(input, suggestions);
                suggestions.classList.add('show');
            }
        } catch (error) {
            console.error('Search orders error:', error);
            suggestions.classList.remove('show');
        }
    }, 300);
}

/**
 * Select order from autocomplete for payment
 */
function selectOrderForPayment(paymentId, order) {
    const input = document.getElementById(`orderSearchModal-${paymentId}`);
    const suggestions = document.getElementById(`orderSuggestionsModal-${paymentId}`);
    const hiddenInput = document.getElementById(`selectedOrderId-${paymentId}`);
    const linkBtn = document.getElementById(`linkOrderBtn-${paymentId}`);

    if (input) input.value = order.order_no || order.order_number || '#' + order.id;
    if (suggestions) suggestions.classList.remove('show');
    if (hiddenInput) hiddenInput.value = order.id;
    if (linkBtn) linkBtn.disabled = false;
}

/**
 * Link order to payment (API call)
 */
async function linkOrderToPayment(paymentId) {
    const orderId = document.getElementById(`selectedOrderId-${paymentId}`)?.value;

    if (!orderId) {
        showToast('กรุณาเลือกคำสั่งซื้อก่อน', 'error');
        return;
    }

    try {
        showToast('กำลังเชื่อมโยง...', 'info');

        const result = await apiCall(API_ENDPOINTS.CUSTOMER_PAYMENTS + '?action=link_order', {
            method: 'POST',
            body: JSON.stringify({
                payment_id: paymentId,
                order_id: orderId
            })
        });

        if (result.success) {
            showToast('✅ เชื่อมโยงคำสั่งซื้อเรียบร้อย', 'success');
            // Reload payment detail
            await viewPaymentDetail(paymentId);
            // Refresh list
            loadPayments();
        } else {
            showToast('❌ ' + (result.message || 'ไม่สามารถเชื่อมโยงได้'), 'error');
        }
    } catch (error) {
        console.error('Link order error:', error);
        showToast('❌ เกิดข้อผิดพลาด', 'error');
    }
}

/**
 * Clear order mapping
 */
async function clearOrderMapping(paymentId) {
    if (!confirm('ยกเลิกการเชื่อมโยงคำสั่งซื้อนี้หรือไม่?')) return;

    try {
        showToast('กำลังยกเลิก...', 'info');

        const result = await apiCall(API_ENDPOINTS.CUSTOMER_PAYMENTS + '?action=unlink_order', {
            method: 'POST',
            body: JSON.stringify({
                payment_id: paymentId
            })
        });

        if (result.success) {
            showToast('✅ ยกเลิกการเชื่อมโยงเรียบร้อย', 'success');
            await viewPaymentDetail(paymentId);
            loadPayments();
        } else {
            showToast('❌ ' + (result.message || 'ไม่สามารถยกเลิกได้'), 'error');
        }
    } catch (error) {
        console.error('Unlink order error:', error);
        showToast('❌ เกิดข้อผิดพลาด', 'error');
    }
}

function showError(message, details, canRetry = false) {
    const tableBody = document.getElementById('paymentsTableBody');
    const mobileContainer = document.getElementById('paymentsMobileContainer');

    const errorHtml = `
        <div class="error-state" style="text-align:center;padding:2rem;">
            <div class="error-icon" style="font-size:2.5rem;margin-bottom:1rem;">⚠️</div>
            <h3 class="error-title" style="color:#dc2626;margin-bottom:0.5rem;">${message}</h3>
            ${details ? `<p class="error-details" style="color:#6b7280;margin-bottom:1rem;">${details}</p>` : ''}
            ${canRetry ? `
                <button class="btn btn-primary" onclick="loadPayments()">
                    <i class="fas fa-redo"></i> ลองใหม่อีกครั้ง
                </button>
            ` : ''}
        </div>
    `;

    if (tableBody) {
        tableBody.innerHTML = `<tr><td colspan="7">${errorHtml}</td></tr>`;
    }
    if (mobileContainer) {
        mobileContainer.innerHTML = errorHtml;
    }

    // Hide pagination
    const paginationEl = document.getElementById('paymentPagination');
    if (paginationEl) paginationEl.style.display = 'none';
}

// Close modal on overlay click
document.addEventListener('click', (e) => {
    const modal = document.getElementById('paymentModal');
    const overlay = document.querySelector('.payment-modal-overlay');
    const zoomedImg = document.querySelector('.slip-image.zoomed');

    // Close zoomed image first if clicked
    if (zoomedImg && e.target === zoomedImg) {
        toggleSlipZoom(zoomedImg);
        return;
    }

    // Close modal if overlay is clicked
    if (e.target === modal || e.target === overlay) {
        closePaymentModal();
    }
});

// Close modal on ESC
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        const zoomedImg = document.querySelector('.slip-image.zoomed');
        if (zoomedImg) {
            toggleSlipZoom(zoomedImg);
        } else {
            closePaymentModal();
        }
    }
});

function isDemoUser() {
    try {
        const raw = localStorage.getItem('user_data') || sessionStorage.getItem('user_data');
        const u = raw ? JSON.parse(raw) : null;
        const email = (u && (u.email || u.user_email)) ? String(u.email || u.user_email).toLowerCase() : '';
        return email === 'test1@gmail.com';
    } catch { return false; }
}

function isOwnerUser() {
    try {
        const raw = localStorage.getItem('user_data') || sessionStorage.getItem('user_data');
        const u = raw ? JSON.parse(raw) : null;
        const email = (u && (u.email || u.user_email)) ? String(u.email || u.user_email).toLowerCase() : '';
        // Treat these accounts as "shop owners" for richer UI hints.
        const ownerEmails = [
            'test1@gmail.com',
            'demo@aiautomation.com'
        ];
        return ownerEmails.includes(email);
    } catch { return false; }
}

function getMockPayments() {
    const now = new Date();
    const d1 = new Date(now.getTime() - 2 * 24 * 60 * 60 * 1000);  // เมื่อสองวันก่อน
    const d2 = new Date(now.getTime() - 12 * 60 * 60 * 1000);      // เมื่อวานช่วงเย็น
    const d3 = new Date(now.getTime() - 30 * 60 * 1000);           // ครึ่งชั่วโมงที่แล้ว

    // ใช้ภาพสลิปจริงจาก 3 ธนาคารต่างกัน
    const slipKBank = (typeof PATH !== 'undefined' && PATH.image)
        ? PATH.image('slip-kbank.svg')
        : '/images/slip-kbank.svg';

    const slipSCB = (typeof PATH !== 'undefined' && PATH.image)
        ? PATH.image('slip-scb.svg')
        : '/images/slip-scb.svg';

    const slipPromptPay = (typeof PATH !== 'undefined' && PATH.image)
        ? PATH.image('slip-promptpay.svg')
        : '/images/slip-promptpay.svg';

    return [
        {
            id: 9001,
            payment_no: 'PAY-DEMO-0001',
            order_no: 'ORDER-CHAT-00123',
            amount: 1490.00,
            payment_type: 'full',
            payment_method: 'bank_transfer',
            status: 'verified',
            payment_date: d1.toISOString(),
            slip_image: slipKBank,
            current_period: null,
            installment_period: null,
        },
        {
            id: 9002,
            payment_no: 'PAY-DEMO-0002',
            order_no: 'ORDER-CHAT-00124',
            amount: 499.00,
            payment_type: 'installment',
            payment_method: 'promptpay',
            status: 'pending',
            payment_date: d2.toISOString(),
            slip_image: slipPromptPay,
            current_period: 1,
            installment_period: 3,
        },
        {
            id: 9003,
            payment_no: 'PAY-DEMO-0003',
            order_no: 'ORDER-CHAT-00124',
            amount: 499.00,
            payment_type: 'installment',
            payment_method: 'bank_transfer',
            status: 'rejected',
            payment_date: d3.toISOString(),
            slip_image: slipSCB,
            current_period: 2,
            installment_period: 3,
        }
    ];
}

// ============================================
// Date Range Filter Functions
// ============================================

function setupDateFilter() {
    const startDate = document.getElementById('startDate');
    const endDate = document.getElementById('endDate');

    if (startDate && endDate) {
        // Set default max date to today
        const today = new Date().toISOString().split('T')[0];
        startDate.setAttribute('max', today);
        endDate.setAttribute('max', today);

        // Handle Enter key on date inputs
        startDate.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') applyDateFilter();
        });
        endDate.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') applyDateFilter();
        });
    }
}

function applyDateFilter() {
    const startDateInput = document.getElementById('startDate');
    const endDateInput = document.getElementById('endDate');

    const startDateValue = startDateInput?.value;
    const endDateValue = endDateInput?.value;

    // Validate date range
    if (startDateValue && endDateValue) {
        const start = new Date(startDateValue);
        const end = new Date(endDateValue);

        if (start > end) {
            showToast('❌ วันเริ่มต้นต้องไม่เกินวันสิ้นสุด', 'error');
            return;
        }
    }

    // Set date range filter
    dateRangeFilter.start = startDateValue || null;
    dateRangeFilter.end = endDateValue || null;

    // Apply filter
    applyAllFilters();

    // Show toast
    if (startDateValue || endDateValue) {
        const rangeText = startDateValue && endDateValue
            ? `${formatDate(startDateValue)} - ${formatDate(endDateValue)}`
            : startDateValue
                ? `ตั้งแต่ ${formatDate(startDateValue)}`
                : `ถึง ${formatDate(endDateValue)}`;
        showToast(`🗓️ กรองตามวันที่: ${rangeText}`, 'success');
    }
}

function clearDateFilter() {
    const startDateInput = document.getElementById('startDate');
    const endDateInput = document.getElementById('endDate');

    if (startDateInput) startDateInput.value = '';
    if (endDateInput) endDateInput.value = '';

    dateRangeFilter.start = null;
    dateRangeFilter.end = null;

    applyAllFilters();
    showToast('🗓️ ล้างการกรองตามวันที่', 'info');
}

function formatDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString('th-TH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

function applyAllFilters() {
    // Start with all payments
    let result = [...allPayments];

    // Apply search filter
    if (searchQuery) {
        const query = searchQuery.toLowerCase();
        result = result.filter(payment => {
            const searchFields = [
                payment.payment_no,
                payment.order_no,
                payment.amount?.toString(),
                payment.payment_method,
                payment.status,
                payment.customer_name // Add customer name to search
            ].filter(Boolean);

            return searchFields.some(field =>
                String(field).toLowerCase().includes(query)
            );
        });
    }

    // Apply customer_id filter from URL param
    if (targetCustomerIdFromQuery) {
        result = result.filter(p => {
            const profileId = parseInt(p.customer_profile_id, 10);
            return profileId === targetCustomerIdFromQuery;
        });
    }

    // Apply status filter from URL param (if not already set by currentFilter)
    if (urlParamStatus && !currentFilter) {
        result = result.filter(p => p.status === urlParamStatus);
    }

    // Apply payment type filter (from chips or URL param)
    if (currentFilter) {
        if (currentFilter === 'full') {
            result = result.filter(p => p.payment_type === 'full');
        } else if (currentFilter === 'installment') {
            result = result.filter(p => p.payment_type === 'installment');
        } else if (currentFilter === 'savings') {
            result = result.filter(p => p.payment_type === 'savings');
        } else if (currentFilter === 'pending') {
            result = result.filter(p => p.status === 'pending');
        } else if (currentFilter === 'verified') {
            result = result.filter(p => p.status === 'verified');
        } else if (currentFilter === 'rejected') {
            result = result.filter(p => p.status === 'rejected');
        }
    }

    // Apply date range filter
    if (dateRangeFilter.start || dateRangeFilter.end) {
        result = result.filter(payment => {
            const paymentDate = new Date(payment.payment_date || payment.created_at);
            paymentDate.setHours(0, 0, 0, 0); // Reset time for date comparison

            if (dateRangeFilter.start) {
                const startDate = new Date(dateRangeFilter.start);
                startDate.setHours(0, 0, 0, 0);
                if (paymentDate < startDate) return false;
            }

            if (dateRangeFilter.end) {
                const endDate = new Date(dateRangeFilter.end);
                endDate.setHours(23, 59, 59, 999);
                if (paymentDate > endDate) return false;
            }

            return true;
        });
    }

    filteredPayments = result;
    currentPage = 1;
    renderPayments();
}

/**
 * Highlight and scroll to a specific payment row by ID
 */
function highlightPaymentRow(paymentId) {
    // Find the row in the table
    const rows = document.querySelectorAll('#paymentsTableBody tr');
    for (const row of rows) {
        // Check if this row contains the payment ID
        if (row.getAttribute('onclick')?.includes(`viewPaymentDetail(${paymentId})`)) {
            row.classList.add('highlighted');
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });

            // Flash effect
            row.style.transition = 'background-color 0.3s ease';
            row.style.backgroundColor = '#eef2ff';
            setTimeout(() => {
                row.style.backgroundColor = '';
            }, 2000);
            break;
        }
    }

    // Also check mobile cards
    const cards = document.querySelectorAll('.payment-mobile-card');
    for (const card of cards) {
        if (card.getAttribute('onclick')?.includes(`viewPaymentDetail(${paymentId})`)) {
            card.classList.add('highlighted');
            card.scrollIntoView({ behavior: 'smooth', block: 'center' });

            card.style.transition = 'box-shadow 0.3s ease';
            card.style.boxShadow = '0 0 0 3px rgba(99, 102, 241, 0.5)';
            setTimeout(() => {
                card.style.boxShadow = '';
            }, 2000);
            break;
        }
    }
}

/**
 * Update URL with current filter state (without page reload)
 */
function updateUrlWithFilters() {
    const url = new URL(window.location);

    // Clear old params
    url.searchParams.delete('status');
    url.searchParams.delete('type');
    url.searchParams.delete('start_date');
    url.searchParams.delete('end_date');

    // Add current filters
    if (currentFilter) {
        if (['full', 'installment', 'savings'].includes(currentFilter)) {
            url.searchParams.set('type', currentFilter);
        } else if (['pending', 'verified', 'rejected'].includes(currentFilter)) {
            url.searchParams.set('status', currentFilter);
        }
    }

    if (dateRangeFilter.start) {
        url.searchParams.set('start_date', dateRangeFilter.start);
    }
    if (dateRangeFilter.end) {
        url.searchParams.set('end_date', dateRangeFilter.end);
    }

    // Update URL without reload
    window.history.replaceState({}, '', url);
}

function goToOrderFromPayment(orderNo) {
    if (!orderNo) return;
    const q = encodeURIComponent(String(orderNo));
    window.location.href = `${pageUrlSafe('orders.php')}?order_no=${q}`;
}

// =========================================================
// ADD PAYMENT MODAL FUNCTIONS
// =========================================================

let customerSearchTimeout = null;
let orderSearchTimeout = null;
let addPaymentEventsSetup = false; // Flag to prevent duplicate listeners

function openAddPaymentModal() {
    const modal = document.getElementById('addPaymentModal');
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        // Reset form
        const form = document.getElementById('addPaymentForm');
        if (form) form.reset();

        // Reset selections
        document.getElementById('selectedCustomer').style.display = 'none';
        document.getElementById('selectedOrder').style.display = 'none';
        document.getElementById('customerSearch').value = '';
        document.getElementById('orderSearch').value = '';
        document.getElementById('customerProfileId').value = '';
        document.getElementById('referenceId').value = '';
        document.getElementById('customerSuggestions').classList.remove('show');
        document.getElementById('orderSuggestions').classList.remove('show');
        
        // Reset pawn selection
        const pawnSearch = document.getElementById('pawnSearch');
        const pawnId = document.getElementById('pawnId');
        const selectedPawn = document.getElementById('selectedPawn');
        const pawnSuggestions = document.getElementById('pawnSuggestions');
        const pawnSearchGroup = document.getElementById('pawnSearchGroup');
        const orderSearchGroup = document.getElementById('orderSearchGroup');
        
        if (pawnSearch) pawnSearch.value = '';
        if (pawnId) pawnId.value = '';
        if (selectedPawn) selectedPawn.style.display = 'none';
        if (pawnSuggestions) pawnSuggestions.classList.remove('show');
        if (pawnSearchGroup) pawnSearchGroup.style.display = 'none';
        if (orderSearchGroup) orderSearchGroup.style.display = 'block';
        
        removeSlipPreview();

        // Setup event listeners only once
        if (!addPaymentEventsSetup) {
            setupAddPaymentEvents();
            addPaymentEventsSetup = true;
        }
    }
}

/**
 * Open Add Payment Modal with pre-filled data (from pawns page)
 * @param {Object} prefill - Prefill data {type, pawn_id, pawn_no, amount, description}
 */
function openAddPaymentModalWithPrefill(prefill = {}) {
    // First open modal normally
    openAddPaymentModal();

    setTimeout(() => {
        // Select payment type
        if (prefill.type) {
            const typeRadio = document.querySelector(`input[name="payment_type"][value="${prefill.type}"]`);
            if (typeRadio) {
                typeRadio.checked = true;
                typeRadio.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        // Set amount
        if (prefill.amount) {
            const amountInput = document.getElementById('paymentAmount');
            if (amountInput) {
                amountInput.value = prefill.amount;
            }
        }

        // Set description/note
        if (prefill.description) {
            const noteInput = document.getElementById('paymentNote');
            if (noteInput) {
                noteInput.value = prefill.description;
            }
        }

        // For deposit_interest or pawn_redemption, show pawn section and prefill
        if ((prefill.type === 'deposit_interest' || prefill.type === 'pawn_redemption') && prefill.pawn_id) {
            const pawnSearchGroup = document.getElementById('pawnSearchGroup');
            const orderSearchGroup = document.getElementById('orderSearchGroup');
            const pawnId = document.getElementById('pawnId');
            const selectedPawn = document.getElementById('selectedPawn');
            const pawnSearch = document.getElementById('pawnSearch');

            if (pawnSearchGroup) pawnSearchGroup.style.display = 'block';
            if (orderSearchGroup) orderSearchGroup.style.display = 'none';
            if (pawnId) pawnId.value = prefill.pawn_id;
            
            // Show selected pawn info
            if (selectedPawn && prefill.pawn_no) {
                selectedPawn.innerHTML = `
                    <div class="selected-item-content">
                        <i class="fas fa-store"></i>
                        <span>รายการจำนำ: <strong>${prefill.pawn_no}</strong></span>
                        <button type="button" class="remove-selection" onclick="clearPawnSelection()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                `;
                selectedPawn.style.display = 'block';
                if (pawnSearch) pawnSearch.style.display = 'none';
            }

            console.log('📝 Pre-filled pawn data:', { pawn_id: prefill.pawn_id, pawn_no: prefill.pawn_no });
        }

        // Auto-fill customer if customer_profile_id provided
        if (prefill.customer_profile_id) {
            loadAndSelectCustomer(prefill.customer_profile_id);
        }

        console.log('📝 Modal opened with prefill:', prefill);
    }, 100);
}

/**
 * Clear pawn selection
 */
function clearPawnSelection() {
    const pawnId = document.getElementById('pawnId');
    const selectedPawn = document.getElementById('selectedPawn');
    const pawnSearch = document.getElementById('pawnSearch');
    
    if (pawnId) pawnId.value = '';
    if (selectedPawn) selectedPawn.style.display = 'none';
    if (pawnSearch) {
        pawnSearch.style.display = 'block';
        pawnSearch.value = '';
        pawnSearch.focus();
    }
}

/**
 * Load customer by ID and auto-select
 */
async function loadAndSelectCustomer(customerId) {
    if (!customerId) return;
    
    try {
        const response = await fetch(`/api/customer-profiles.php?id=${customerId}`, {
            headers: { 'Authorization': 'Bearer ' + getToken() }
        });
        const data = await response.json();
        
        if (data.success && data.data) {
            const customer = data.data;
            selectCustomer({
                id: customer.id,
                display_name: customer.display_name || customer.name || 'ลูกค้า',
                phone: customer.phone || '',
                platform_user_id: customer.platform_user_id || ''
            });
            console.log('📝 Auto-selected customer:', customer.id, customer.display_name);
        }
    } catch (error) {
        console.error('Error loading customer:', error);
    }
}

// Inline handler for customer search input
function handleCustomerSearchInput(query) {
    clearTimeout(customerSearchTimeout);
    const suggestions = document.getElementById('customerSuggestions');
    
    if (!query || query.trim().length < 2) {
        suggestions.classList.remove('show');
        return;
    }
    
    customerSearchTimeout = setTimeout(() => {
        searchCustomers(query.trim());
    }, 300);
}

// Inline handler for customer search focus
function handleCustomerSearchFocus(query) {
    if (query && query.trim().length >= 2) {
        searchCustomers(query.trim());
    }
}

// Inline handler for order search input
function handleOrderSearchInput(query) {
    clearTimeout(orderSearchTimeout);
    const suggestions = document.getElementById('orderSuggestions');
    
    if (!query || query.trim().length < 2) {
        suggestions.classList.remove('show');
        return;
    }
    
    orderSearchTimeout = setTimeout(() => {
        searchOrders(query.trim());
    }, 300);
}

// Inline handler for pawn search input
let pawnSearchTimeout = null;
function handlePawnSearchInput(query) {
    clearTimeout(pawnSearchTimeout);
    const suggestions = document.getElementById('pawnSuggestions');
    
    if (!query || query.trim().length < 2) {
        suggestions.classList.remove('show');
        return;
    }
    
    pawnSearchTimeout = setTimeout(() => {
        searchPawnsForAddPayment(query.trim());
    }, 300);
}

// Search pawns for Add Payment modal
async function searchPawnsForAddPayment(query) {
    const suggestions = document.getElementById('pawnSuggestions');
    const inputEl = document.getElementById('pawnSearch');
    
    try {
        const url = (typeof API_ENDPOINTS !== 'undefined' && API_ENDPOINTS.CUSTOMER_PAWNS)
            ? `${API_ENDPOINTS.CUSTOMER_PAWNS}?action=search&q=${encodeURIComponent(query)}&limit=10`
            : `/api/customer/pawns.php?action=search&q=${encodeURIComponent(query)}&limit=10`;
        
        const result = await apiCall(url);
        
        if (result.success && result.data && result.data.length > 0) {
            suggestions.innerHTML = result.data.map(pawn => `
                <div class="autocomplete-item" onclick="selectPawnForAddPayment(${JSON.stringify(pawn).replace(/"/g, '&quot;')})">
                    <div class="autocomplete-item-avatar" style="background:#8b5cf6;color:white;">
                        <i class="fas fa-gem"></i>
                    </div>
                    <div class="autocomplete-item-info">
                        <div class="autocomplete-item-name">${escapeHtml(pawn.pawn_no)}</div>
                        <div class="autocomplete-item-meta">
                            ${escapeHtml(pawn.item_name || 'ไม่ระบุ')} • 
                            ${pawn.loan_amount ? formatCurrency(pawn.loan_amount) : ''} • 
                            ดอกเบี้ย ${pawn.interest_rate || 0}%
                        </div>
                        <div class="autocomplete-item-meta" style="color:#6b7280;">
                            ${escapeHtml(pawn.customer_name || '')}
                        </div>
                    </div>
                </div>
            `).join('');
            positionFixedDropdown(inputEl, suggestions);
            suggestions.classList.add('show');
        } else {
            suggestions.innerHTML = '<div class="autocomplete-item"><em>ไม่พบรายการจำนำ</em></div>';
            positionFixedDropdown(inputEl, suggestions);
            suggestions.classList.add('show');
        }
    } catch (error) {
        console.error('Search pawns error:', error);
        suggestions.classList.remove('show');
    }
}

// Select pawn for Add Payment
function selectPawnForAddPayment(pawn) {
    const pawnSearch = document.getElementById('pawnSearch');
    const pawnIdInput = document.getElementById('pawnId');
    const selectedPawn = document.getElementById('selectedPawn');
    const suggestions = document.getElementById('pawnSuggestions');
    
    pawnIdInput.value = pawn.id;
    pawnSearch.value = '';
    suggestions.classList.remove('show');
    
    // Calculate interest amount suggestion
    const interestAmount = pawn.loan_amount && pawn.interest_rate 
        ? (parseFloat(pawn.loan_amount) * parseFloat(pawn.interest_rate) / 100).toFixed(2)
        : '';
    
    selectedPawn.innerHTML = `
        <div class="autocomplete-item-avatar" style="background:#8b5cf6;color:white;">
            <i class="fas fa-gem"></i>
        </div>
        <div class="autocomplete-item-info">
            <div class="autocomplete-item-name">${escapeHtml(pawn.pawn_no)}</div>
            <div class="autocomplete-item-meta">
                ${escapeHtml(pawn.item_name || 'ไม่ระบุ')} • 
                ยอดจำนำ ${formatCurrency(pawn.loan_amount || 0)} • 
                ดอกเบี้ย ${pawn.interest_rate || 0}%
            </div>
        </div>
        <span class="remove-btn" onclick="removeSelectedPawn()"><i class="fas fa-times"></i></span>
    `;
    selectedPawn.style.display = 'flex';
    
    // Auto-fill interest amount
    if (interestAmount) {
        const amountInput = document.getElementById('paymentAmount');
        if (amountInput && !amountInput.value) {
            amountInput.value = interestAmount;
            amountInput.placeholder = `ดอกเบี้ย ${interestAmount} บาท/เดือน`;
        }
    }
    
    // Also set customer if available
    if (pawn.customer_profile_id) {
        document.getElementById('customerProfileId').value = pawn.customer_profile_id;
        // Update customer display
        const selectedCustomer = document.getElementById('selectedCustomer');
        selectedCustomer.innerHTML = `
            <div class="autocomplete-item-avatar">
                <i class="fas fa-user"></i>
            </div>
            <div class="autocomplete-item-info">
                <div class="autocomplete-item-name">${escapeHtml(pawn.customer_name || 'ลูกค้า')}</div>
                <div class="autocomplete-item-meta">เชื่อมโยงจากรายการจำนำ</div>
            </div>
            <span class="remove-btn" onclick="removeSelectedCustomer()"><i class="fas fa-times"></i></span>
        `;
        selectedCustomer.style.display = 'flex';
        document.getElementById('customerSearch').value = '';
    }
}

// Remove selected pawn
function removeSelectedPawn() {
    document.getElementById('pawnId').value = '';
    document.getElementById('selectedPawn').style.display = 'none';
    document.getElementById('pawnSearch').value = '';
    document.getElementById('paymentAmount').placeholder = '0.00';
}

function closeAddPaymentModal() {
    const modal = document.getElementById('addPaymentModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
        // Hide suggestions
        const customerSuggestions = document.getElementById('customerSuggestions');
        const orderSuggestions = document.getElementById('orderSuggestions');
        const pawnSuggestions = document.getElementById('pawnSuggestions');
        if (customerSuggestions) customerSuggestions.classList.remove('show');
        if (orderSuggestions) orderSuggestions.classList.remove('show');
        if (pawnSuggestions) pawnSuggestions.classList.remove('show');
    }
}

function setupAddPaymentEvents() {
    // Note: Customer and order search are now handled via inline oninput/onfocus
    // No need for addEventListener here for those inputs
    
    const customerSuggestions = document.getElementById('customerSuggestions');
    const orderSuggestions = document.getElementById('orderSuggestions');

    // Payment type change - update reference label
    const paymentTypeInputs = document.querySelectorAll('input[name="payment_type"]');
    paymentTypeInputs.forEach(input => {
        input.addEventListener('change', (e) => {
            updateReferenceFieldLabel(e.target.value);
        });
    });

    // Slip image upload
    setupSlipUpload();

    // Form submit
    const form = document.getElementById('addPaymentForm');
    form.addEventListener('submit', handleAddPaymentSubmit);

    // Close suggestions when clicking outside
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.autocomplete-wrapper')) {
            if (customerSuggestions) customerSuggestions.classList.remove('show');
            if (orderSuggestions) orderSuggestions.classList.remove('show');
        }
    });
}

async function searchCustomers(query) {
    const suggestions = document.getElementById('customerSuggestions');
    const inputEl = document.getElementById('customerSearch');

    try {
        // Search customer profiles
        const result = await apiCall(`${API_ENDPOINTS.SEARCH_CUSTOMERS}?q=${encodeURIComponent(query)}`);

        if (result.success && result.data && result.data.length > 0) {
            suggestions.innerHTML = result.data.map(customer => `
                <div class="autocomplete-item" onclick="selectCustomer(${JSON.stringify(customer).replace(/"/g, '&quot;')})">
                    <div class="autocomplete-item-avatar">
                        ${customer.avatar_url
                    ? `<img src="${escapeHtml(customer.avatar_url)}" alt="">`
                    : `<i class="fas fa-user"></i>`}
                    </div>
                    <div class="autocomplete-item-info">
                        <div class="autocomplete-item-name">${escapeHtml(customer.display_name || customer.full_name || 'ไม่ระบุชื่อ')}</div>
                        <div class="autocomplete-item-meta">
                            ${customer.platform ? `<i class="fab fa-${customer.platform === 'line' ? 'line' : 'facebook-messenger'}"></i> ` : ''}
                            ${customer.phone || customer.platform_user_id?.substring(0, 12) + '...' || ''}
                        </div>
                    </div>
                </div>
            `).join('');
            // Position fixed dropdown to escape modal overflow
            positionFixedDropdown(inputEl, suggestions);
            suggestions.classList.add('show');
        } else {
            suggestions.innerHTML = '<div class="autocomplete-item"><em>ไม่พบลูกค้า</em></div>';
            positionFixedDropdown(inputEl, suggestions);
            suggestions.classList.add('show');
        }
    } catch (error) {
        console.error('Search customers error:', error);
        suggestions.classList.remove('show');
    }
}

function selectCustomer(customer) {
    const customerSearch = document.getElementById('customerSearch');
    const customerProfileId = document.getElementById('customerProfileId');
    const selectedCustomer = document.getElementById('selectedCustomer');
    const suggestions = document.getElementById('customerSuggestions');

    customerProfileId.value = customer.id;
    customerSearch.value = '';
    suggestions.classList.remove('show');

    selectedCustomer.innerHTML = `
        <div class="autocomplete-item-avatar">
            ${customer.avatar_url
            ? `<img src="${escapeHtml(customer.avatar_url)}" alt="">`
            : `<i class="fas fa-user"></i>`}
        </div>
        <div class="autocomplete-item-info">
            <div class="autocomplete-item-name">${escapeHtml(customer.display_name || customer.full_name || 'ไม่ระบุชื่อ')}</div>
            <div class="autocomplete-item-meta">
                ${customer.platform ? `<i class="fab fa-${customer.platform === 'line' ? 'line' : 'facebook-messenger'}"></i> ` : ''}
                ${customer.phone || ''}
            </div>
        </div>
        <span class="remove-btn" onclick="removeSelectedCustomer()"><i class="fas fa-times"></i></span>
    `;
    selectedCustomer.style.display = 'flex';
}

function removeSelectedCustomer() {
    document.getElementById('customerProfileId').value = '';
    document.getElementById('selectedCustomer').style.display = 'none';
    document.getElementById('customerSearch').value = '';
}

async function searchOrders(query) {
    const suggestions = document.getElementById('orderSuggestions');
    const inputEl = document.getElementById('orderSearch');
    const paymentType = document.querySelector('input[name="payment_type"]:checked')?.value || 'full';

    try {
        let endpoint = API_ENDPOINTS.SEARCH_ORDERS;
        let referenceType = 'order';

        // Determine reference type based on payment type
        if (paymentType === 'installment') {
            endpoint = API_ENDPOINTS.SEARCH_INSTALLMENTS || endpoint;
            referenceType = 'installment_contract';
        } else if (paymentType === 'savings') {
            endpoint = API_ENDPOINTS.SEARCH_SAVINGS || endpoint;
            referenceType = 'savings_account';
        }

        const result = await apiCall(`${endpoint}?q=${encodeURIComponent(query)}`);

        if (result.success && result.data && result.data.length > 0) {
            suggestions.innerHTML = result.data.map(item => `
                <div class="autocomplete-item" onclick="selectOrder(${JSON.stringify({ ...item, reference_type: referenceType }).replace(/"/g, '&quot;')})">
                    <div class="autocomplete-item-info">
                        <div class="autocomplete-item-name">${escapeHtml(item.order_no || item.contract_no || item.account_no || '#' + item.id)}</div>
                        <div class="autocomplete-item-meta">
                            ${item.product_name ? escapeHtml(item.product_name) + ' • ' : ''}
                            ${item.total_amount ? formatCurrency(item.total_amount) : ''}
                        </div>
                    </div>
                </div>
            `).join('');
            positionFixedDropdown(inputEl, suggestions);
            suggestions.classList.add('show');
        } else {
            suggestions.innerHTML = '<div class="autocomplete-item"><em>ไม่พบรายการ</em></div>';
            positionFixedDropdown(inputEl, suggestions);
            suggestions.classList.add('show');
        }
    } catch (error) {
        console.error('Search orders error:', error);
        suggestions.classList.remove('show');
    }
}

function selectOrder(item) {
    const orderSearch = document.getElementById('orderSearch');
    const referenceId = document.getElementById('referenceId');
    const referenceType = document.getElementById('referenceType');
    const selectedOrder = document.getElementById('selectedOrder');
    const suggestions = document.getElementById('orderSuggestions');

    referenceId.value = item.id;
    referenceType.value = item.reference_type || 'order';
    orderSearch.value = '';
    suggestions.classList.remove('show');

    selectedOrder.innerHTML = `
        <div class="autocomplete-item-info">
            <div class="autocomplete-item-name">${escapeHtml(item.order_no || item.contract_no || item.account_no || '#' + item.id)}</div>
            <div class="autocomplete-item-meta">
                ${item.product_name ? escapeHtml(item.product_name) : ''}
                ${item.total_amount ? ' • ' + formatCurrency(item.total_amount) : ''}
            </div>
        </div>
        <span class="remove-btn" onclick="removeSelectedOrder()"><i class="fas fa-times"></i></span>
    `;
    selectedOrder.style.display = 'flex';

    // Auto-fill amount if available
    if (item.total_amount) {
        document.getElementById('paymentAmount').value = item.total_amount;
    }
}

function removeSelectedOrder() {
    document.getElementById('referenceId').value = '';
    document.getElementById('selectedOrder').style.display = 'none';
    document.getElementById('orderSearch').value = '';
}

function updateReferenceFieldLabel(paymentType) {
    const orderGroup = document.getElementById('orderSearchGroup');
    const pawnGroup = document.getElementById('pawnSearchGroup');
    const label = document.querySelector('#orderSearchGroup .form-label');
    const input = document.getElementById('orderSearch');

    // Show/hide pawn search for deposit_interest or pawn_redemption
    if (paymentType === 'deposit_interest' || paymentType === 'pawn_redemption') {
        // Show pawn search, hide order search
        if (orderGroup) orderGroup.style.display = 'none';
        if (pawnGroup) pawnGroup.style.display = 'block';
        removeSelectedOrder();
        return;
    } else {
        // Show order search, hide pawn search
        if (orderGroup) orderGroup.style.display = 'block';
        if (pawnGroup) pawnGroup.style.display = 'none';
        removeSelectedPawn();
    }

    switch (paymentType) {
        case 'installment':
            label.textContent = 'สัญญาผ่อนชำระ';
            input.placeholder = 'พิมพ์เลขที่สัญญา...';
            break;
        case 'savings':
            label.textContent = 'บัญชีออมเงิน';
            input.placeholder = 'พิมพ์เลขบัญชีออม...';
            break;
        case 'deposit':
            label.textContent = 'รายการมัดจำ';
            input.placeholder = 'พิมพ์เลขที่มัดจำ...';
            break;
        default:
            label.textContent = 'คำสั่งซื้อ/สัญญา';
            input.placeholder = 'พิมพ์เลขที่คำสั่งซื้อ/สัญญา...';
    }

    // Clear selection when type changes
    removeSelectedOrder();
}

function setupSlipUpload() {
    const uploadArea = document.getElementById('slipUploadArea');
    const fileInput = document.getElementById('slipImage');

    // Drag and drop
    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.classList.add('dragover');
    });

    uploadArea.addEventListener('dragleave', () => {
        uploadArea.classList.remove('dragover');
    });

    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.classList.remove('dragover');

        const files = e.dataTransfer.files;
        if (files.length > 0 && files[0].type.startsWith('image/')) {
            fileInput.files = files;
            previewSlipImage(files[0]);
        }
    });

    // File input change
    fileInput.addEventListener('change', (e) => {
        if (e.target.files.length > 0) {
            previewSlipImage(e.target.files[0]);
        }
    });
}

function previewSlipImage(file) {
    const preview = document.getElementById('slipPreview');
    const previewImg = document.getElementById('slipPreviewImg');
    const placeholder = document.querySelector('.upload-placeholder');

    const reader = new FileReader();
    reader.onload = (e) => {
        previewImg.src = e.target.result;
        preview.style.display = 'block';
        placeholder.style.display = 'none';
    };
    reader.readAsDataURL(file);
}

function removeSlipPreview() {
    const preview = document.getElementById('slipPreview');
    const placeholder = document.querySelector('.upload-placeholder');
    const fileInput = document.getElementById('slipImage');

    preview.style.display = 'none';
    placeholder.style.display = 'flex';
    fileInput.value = '';
}

async function handleAddPaymentSubmit(e) {
    e.preventDefault();

    const submitBtn = document.getElementById('submitPaymentBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังบันทึก...';
    submitBtn.disabled = true;

    try {
        const formData = new FormData(document.getElementById('addPaymentForm'));

        // Validate required fields
        const amount = parseFloat(formData.get('amount'));
        if (!amount || amount <= 0) {
            showToast('กรุณาระบุจำนวนเงิน', 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            return;
        }

        // Add source as manual
        formData.append('source', 'manual');

        // Call API
        const result = await apiCallFormData(API_ENDPOINTS.CUSTOMER_PAYMENTS_CREATE, formData);

        if (result.success) {
            showToast('บันทึกรายการชำระเงินเรียบร้อย', 'success');
            closeAddPaymentModal();
            loadPayments(); // Refresh list
        } else {
            showToast(result.message || 'ไม่สามารถบันทึกข้อมูลได้', 'error');
        }
    } catch (error) {
        console.error('Submit payment error:', error);
        showToast('เกิดข้อผิดพลาด: ' + error.message, 'error');
    } finally {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
}

// API call with FormData (for file uploads)
async function apiCallFormData(url, formData) {
    const token = localStorage.getItem('auth_token');

    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${token}`
        },
        body: formData
    });

    return response.json();
}

function formatCurrency(amount) {
    return new Intl.NumberFormat('th-TH', {
        style: 'currency',
        currency: 'THB'
    }).format(amount);
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ============================================
// REPAIR LINK FUNCTIONS
// ============================================

let repairSearchTimeout = null;

async function searchRepairsForPayment(paymentId, query) {
    const inputEl = document.getElementById(`repairSearchModal-${paymentId}`);
    const suggestionsEl = document.getElementById(`repairSuggestionsModal-${paymentId}`);
    if (!suggestionsEl) return;

    // Clear previous timeout
    if (repairSearchTimeout) clearTimeout(repairSearchTimeout);

    if (!query || query.length < 1) {
        suggestionsEl.classList.remove('show');
        suggestionsEl.innerHTML = '';
        return;
    }

    // Debounce
    repairSearchTimeout = setTimeout(async () => {
        try {
            const url = (typeof API_ENDPOINTS !== 'undefined' && API_ENDPOINTS.CUSTOMER_REPAIRS)
                ? `${API_ENDPOINTS.CUSTOMER_REPAIRS}?action=search&q=${encodeURIComponent(query)}&limit=10`
                : `/api/customer/repairs.php?action=search&q=${encodeURIComponent(query)}&limit=10`;

            const result = await apiCall(url);

            if (result && result.success && result.data && result.data.length > 0) {
                suggestionsEl.innerHTML = result.data.map(repair => `
                    <div class="autocomplete-item" onclick="selectRepairForPayment(${paymentId}, ${repair.id}, '${escapeHtml(repair.repair_no)}', '${escapeHtml(repair.item_name || '')}')">
                        <div class="autocomplete-item-avatar" style="background:#f97316;color:white;">
                            <i class="fas fa-tools"></i>
                        </div>
                        <div class="autocomplete-item-info">
                            <div class="autocomplete-item-name">${repair.repair_no}</div>
                            <div class="autocomplete-item-meta">${repair.item_name || 'งานซ่อม'} - ${repair.customer_name || ''}</div>
                        </div>
                    </div>
                `).join('');
                // Position dropdown using fixed positioning to escape overflow:hidden
                positionFixedDropdown(inputEl, suggestionsEl);
                suggestionsEl.classList.add('show');
            } else {
                suggestionsEl.innerHTML = '<div class="autocomplete-item"><span style="color:#9ca3af;">ไม่พบงานซ่อม</span></div>';
                positionFixedDropdown(inputEl, suggestionsEl);
                suggestionsEl.classList.add('show');
            }
        } catch (e) {
            console.error('Error searching repairs:', e);
        }
    }, 300);
}

function selectRepairForPayment(paymentId, repairId, repairNo, itemName) {
    const inputEl = document.getElementById(`repairSearchModal-${paymentId}`);
    const hiddenEl = document.getElementById(`selectedRepairId-${paymentId}`);
    const btnEl = document.getElementById(`linkRepairBtn-${paymentId}`);
    const suggestionsEl = document.getElementById(`repairSuggestionsModal-${paymentId}`);

    if (inputEl) inputEl.value = `${repairNo} - ${itemName || 'งานซ่อม'}`;
    if (hiddenEl) hiddenEl.value = repairId;
    if (btnEl) btnEl.disabled = false;
    if (suggestionsEl) {
        suggestionsEl.classList.remove('show');
        suggestionsEl.innerHTML = '';
    }
}

async function linkRepairToPayment(paymentId) {
    const hiddenEl = document.getElementById(`selectedRepairId-${paymentId}`);
    const repairId = hiddenEl ? hiddenEl.value : null;

    if (!repairId) {
        showToast('⚠️ กรุณาเลือกงานซ่อมก่อน', 'error');
        return;
    }

    showToast('กำลังเชื่อมโยง...', 'info');

    try {
        const url = (typeof API_ENDPOINTS !== 'undefined' && API_ENDPOINTS.CUSTOMER_PAYMENTS)
            ? `${API_ENDPOINTS.CUSTOMER_PAYMENTS}?action=link_repair`
            : `/api/customer/payments.php?action=link_repair`;

        const result = await apiCall(url, {
            method: 'POST',
            body: JSON.stringify({
                payment_id: paymentId,
                repair_id: parseInt(repairId)
            })
        });

        if (result && result.success) {
            showToast('✅ ' + (result.message || 'เชื่อมโยงงานซ่อมเรียบร้อย'), 'success');
            await loadPayments();
            // Reload the modal
            viewPaymentDetail(paymentId);
        } else {
            showToast('❌ ' + (result.message || 'ไม่สามารถเชื่อมโยงได้'), 'error');
        }
    } catch (e) {
        console.error('Error linking repair:', e);
        showToast('❌ เกิดข้อผิดพลาด', 'error');
    }
}

async function unlinkRepairFromPayment(paymentId) {
    if (!confirm('ต้องการยกเลิกการเชื่อมโยงกับงานซ่อมนี้ใช่หรือไม่?')) return;

    showToast('กำลังยกเลิก...', 'info');

    try {
        const url = (typeof API_ENDPOINTS !== 'undefined' && API_ENDPOINTS.CUSTOMER_PAYMENTS)
            ? `${API_ENDPOINTS.CUSTOMER_PAYMENTS}?action=unlink_repair`
            : `/api/customer/payments.php?action=unlink_repair`;

        const result = await apiCall(url, {
            method: 'POST',
            body: JSON.stringify({ payment_id: paymentId })
        });

        if (result && result.success) {
            showToast('✅ ' + (result.message || 'ยกเลิกการเชื่อมโยงเรียบร้อย'), 'success');
            await loadPayments();
            // Reload the modal
            viewPaymentDetail(paymentId);
        } else {
            showToast('❌ ' + (result.message || 'ไม่สามารถยกเลิกได้'), 'error');
        }
    } catch (e) {
        console.error('Error unlinking repair:', e);
        showToast('❌ เกิดข้อผิดพลาด', 'error');
    }
}

// ============================================
// ✅ PAWN SEARCH & LINK FUNCTIONS
// ============================================

// pawnSearchTimeout is declared at top of ADD PAYMENT section

async function searchPawnsForPayment(paymentId, query) {
    const inputEl = document.getElementById(`pawnSearchModal-${paymentId}`);
    const suggestionsEl = document.getElementById(`pawnSuggestionsModal-${paymentId}`);
    if (!suggestionsEl) return;

    // Clear previous timeout
    if (pawnSearchTimeout) clearTimeout(pawnSearchTimeout);

    if (!query || query.length < 1) {
        suggestionsEl.classList.remove('show');
        suggestionsEl.innerHTML = '';
        return;
    }

    // Debounce
    pawnSearchTimeout = setTimeout(async () => {
        try {
            const url = (typeof API_ENDPOINTS !== 'undefined' && API_ENDPOINTS.CUSTOMER_PAWNS)
                ? `${API_ENDPOINTS.CUSTOMER_PAWNS}?action=search&q=${encodeURIComponent(query)}&limit=10`
                : `/api/customer/pawns?action=search&q=${encodeURIComponent(query)}&limit=10`;

            const result = await apiCall(url);

            if (result && result.success && result.data && result.data.length > 0) {
                suggestionsEl.innerHTML = result.data.map(pawn => `
                    <div class="autocomplete-item" onclick="selectPawnForPayment(${paymentId}, ${pawn.id}, '${escapeHtml(pawn.pawn_no)}', '${escapeHtml(pawn.item_name || '')}')">
                        <div class="autocomplete-item-avatar" style="background:linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);color:white;">
                            <i class="fas fa-gem"></i>
                        </div>
                        <div class="autocomplete-item-info">
                            <div class="autocomplete-item-name">${pawn.pawn_no}</div>
                            <div class="autocomplete-item-meta">${pawn.item_name || 'สินค้าจำนำ'} - ฿${formatNumber(pawn.loan_amount || 0)}</div>
                        </div>
                    </div>
                `).join('');
                // Position dropdown using fixed positioning to escape overflow:hidden
                positionFixedDropdown(inputEl, suggestionsEl);
                suggestionsEl.classList.add('show');
            } else {
                suggestionsEl.innerHTML = '<div class="autocomplete-item"><span style="color:#9ca3af;">ไม่พบรายการจำนำ</span></div>';
                positionFixedDropdown(inputEl, suggestionsEl);
                suggestionsEl.classList.add('show');
            }
        } catch (e) {
            console.error('Error searching pawns:', e);
        }
    }, 300);
}

function selectPawnForPayment(paymentId, pawnId, pawnNo, itemName) {
    const inputEl = document.getElementById(`pawnSearchModal-${paymentId}`);
    const hiddenEl = document.getElementById(`selectedPawnId-${paymentId}`);
    const btnEl = document.getElementById(`linkPawnBtn-${paymentId}`);
    const suggestionsEl = document.getElementById(`pawnSuggestionsModal-${paymentId}`);

    if (inputEl) inputEl.value = `${pawnNo} - ${itemName || 'สินค้าจำนำ'}`;
    if (hiddenEl) hiddenEl.value = pawnId;
    if (btnEl) btnEl.disabled = false;
    if (suggestionsEl) {
        suggestionsEl.classList.remove('show');
        suggestionsEl.innerHTML = '';
    }
}

async function linkPawnToPayment(paymentId) {
    const hiddenEl = document.getElementById(`selectedPawnId-${paymentId}`);
    const pawnId = hiddenEl ? hiddenEl.value : null;

    if (!pawnId) {
        showToast('⚠️ กรุณาเลือกรายการจำนำก่อน', 'error');
        return;
    }

    showToast('กำลังเชื่อมโยง...', 'info');

    try {
        const url = (typeof API_ENDPOINTS !== 'undefined' && API_ENDPOINTS.CUSTOMER_PAYMENTS)
            ? `${API_ENDPOINTS.CUSTOMER_PAYMENTS}?action=link_pawn`
            : `/api/customer/payments.php?action=link_pawn`;

        const result = await apiCall(url, {
            method: 'POST',
            body: JSON.stringify({
                payment_id: paymentId,
                pawn_id: parseInt(pawnId)
            })
        });

        if (result && result.success) {
            showToast('✅ ' + (result.message || 'เชื่อมโยงจำนำเรียบร้อย'), 'success');
            await loadPayments();
            // Reload the modal
            viewPaymentDetail(paymentId);
        } else {
            showToast('❌ ' + (result.message || 'ไม่สามารถเชื่อมโยงได้'), 'error');
        }
    } catch (e) {
        console.error('Error linking pawn:', e);
        showToast('❌ เกิดข้อผิดพลาด', 'error');
    }
}

async function unlinkPawnFromPayment(paymentId) {
    if (!confirm('ต้องการยกเลิกการเชื่อมโยงกับจำนำนี้ใช่หรือไม่?')) return;

    showToast('กำลังยกเลิก...', 'info');

    try {
        const url = (typeof API_ENDPOINTS !== 'undefined' && API_ENDPOINTS.CUSTOMER_PAYMENTS)
            ? `${API_ENDPOINTS.CUSTOMER_PAYMENTS}?action=unlink_pawn`
            : `/api/customer/payments.php?action=unlink_pawn`;

        const result = await apiCall(url, {
            method: 'POST',
            body: JSON.stringify({ payment_id: paymentId })
        });

        if (result && result.success) {
            showToast('✅ ' + (result.message || 'ยกเลิกการเชื่อมโยงเรียบร้อย'), 'success');
            await loadPayments();
            // Reload the modal
            viewPaymentDetail(paymentId);
        } else {
            showToast('❌ ' + (result.message || 'ไม่สามารถยกเลิกได้'), 'error');
        }
    } catch (e) {
        console.error('Error unlinking pawn:', e);
        showToast('❌ เกิดข้อผิดพลาด', 'error');
    }
}
