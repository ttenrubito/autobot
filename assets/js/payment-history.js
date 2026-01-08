// Payment History Page JavaScript - Enhanced with Pagination, Search, Error Handling
let allPayments = [];
let filteredPayments = [];
let currentPage = 1;
const ITEMS_PER_PAGE = 20;
let searchQuery = '';
let currentFilter = '';
let dateRangeFilter = { start: null, end: null }; // NEW: Date range filter
let targetOrderNoFromQuery = '';

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
    // Deep-link from orders -> payment-history
    targetOrderNoFromQuery = getQueryParam('order_no') || '';
    if (targetOrderNoFromQuery) {
        // Push into search box + filter logic after DOM is ready
        const searchInput = document.getElementById('paymentSearch');
        if (searchInput) searchInput.value = targetOrderNoFromQuery;
        searchQuery = String(targetOrderNoFromQuery).trim().toLowerCase();
    }

    loadPayments();
    setupSearchAndFilters();
    setupKeyboardShortcuts();
    setupDateFilter(); // NEW: Setup date filter
});

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

            // Sort by payment date (newest first)
            allPayments.sort((a, b) => {
                const dateA = new Date(a.payment_date || a.created_at);
                const dateB = new Date(b.payment_date || b.created_at);
                return dateB - dateA;
            });

            filteredPayments = allPayments;
            currentPage = 1;
            renderPayments();
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
                payment.payment_type === 'savings' ? 'savings' : 'installment';
            const typeIcon = payment.payment_type === 'full' ? '💳' : 
                payment.payment_type === 'savings' ? '🐷' : '📅';
            const typeText = payment.payment_type === 'full' ? 'จ่ายเต็ม' :
                payment.payment_type === 'savings' ? 'ออมเงิน' :
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
                    <td><span class="payment-no-link">${payment.payment_no}</span></td>
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
                payment.payment_type === 'savings' ? 'savings' : 'installment';
            const typeIcon = payment.payment_type === 'full' ? '💳' : 
                payment.payment_type === 'savings' ? '🐷' : '📅';
            const typeText = payment.payment_type === 'full' ? 'จ่ายเต็ม' :
                payment.payment_type === 'savings' ? 'ออมเงิน' :
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
                        <span class="payment-mobile-no">${payment.payment_no}</span>
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

    // Update active tab
    document.querySelectorAll('.filter-tab').forEach(tab => tab.classList.remove('active'));

    const target = (evt && (evt.currentTarget || evt.target)) ? (evt.currentTarget || evt.target) : null;
    const btn = target ? target.closest('.filter-tab') : document.querySelector(`.filter-tab[data-filter="${type}"]`);
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

    const reviewHint = payment.status === 'pending' || payment.status === 'verifying'
        ? 'ระบบกำลังตรวจสอบสลิปของคุณ (OCR/ตรวจความถูกต้อง) โดยปกติใช้เวลาไม่กี่นาที'
        : payment.status === 'rejected'
            ? 'สลิปอาจไม่ชัดเจน/ยอดเงินไม่ตรง กรุณาอัปโหลดใหม่ หรือแจ้งเจ้าหน้าที่'
            : 'ชำระเงินสำเร็จและได้รับการยืนยันเรียบร้อย';

    const canModerate = true; // allow all logged-in users to approve/reject from this view

    // Extract customer profile from conversation metadata (from API JOIN)
    let customerName = payment.platform_user_name || 'ลูกค้า';
    let metadata = payment.conversation_metadata || {};
    let profileUrl = metadata.line_profile_url || '';
    let userPhone = metadata.user_phone || '';
    
    // Fallback: check payment_details for conversation info
    if (!customerName || customerName === 'ลูกค้า') {
        const pd = payment.payment_details || {};
        if (pd.line_user) customerName = pd.line_user;
    }
    
    // Generate initials for placeholder
    const initials = customerName.split(' ').map(n => n.charAt(0)).join('').substr(0, 2).toUpperCase();
    
    let html = `
        <div class="slip-chat-layout">
            <!-- SLIP IMAGE FIRST (Most Important) -->
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

            <!-- Link to Order -->
            <div class="detail-section" style="margin-top: -0.5rem;">
                <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
                    <span style="font-weight:600;">🔗 อ้างอิงคำสั่งซื้อ:</span>
                    ${payment.order_no ? `
                        <a class="btn btn-outline" style="padding:.4rem .75rem;" href="${pageUrlSafe('orders.php')}?order_no=${encodeURIComponent(payment.order_no)}" onclick="event.preventDefault(); goToOrderFromPayment('${String(payment.order_no).replace(/'/g, "\\'")}');">
                            <i class=\"fas fa-external-link-alt\"></i> ${payment.order_no}
                        </a>
                    ` : `<span>-</span>`}
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
                        <div class="detail-label">อ้างอิงคำสั่งซื้อ</div>
                        <div class="detail-value">${payment.order_no || '-'}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">วิธีชำระ</div>
                        <div class="detail-value">${getPaymentMethodText(payment.payment_method)}</div>
                    </div>
                </div>
            </div>

            <div class="detail-section slip-chat-box">
                <h3>💬 ประวัติการสนทนา</h3>
                <div class="slip-chat-bubbles">
                    ${renderChatMessages(payment.chat_messages, customerName, payment, reviewHint)}
                </div>
            </div>

            ${canModerate ? `
            <div class="detail-section slip-approve-panel">
                <h3>✅ ตรวจสอบและจัดประเภทการชำระเงิน</h3>
                
                <!-- Classification Section -->
                <div class="classification-section">
                    <div class="classify-row">
                        <label for="paymentType-${payment.id}">ประเภทการชำระ</label>
                        <select id="paymentType-${payment.id}" class="classify-select" onchange="onPaymentTypeChange(${payment.id})">
                            <option value="">-- เลือกประเภท --</option>
                            <option value="full" ${payment.payment_type === 'full' ? 'selected' : ''}>💳 จ่ายเต็ม (Full Payment)</option>
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
                    <button class="btn btn-success" onclick="classifyAndApprovePayment(${payment.id})" ${payment.status === 'verified' ? 'disabled' : ''}>
                        ✅ อนุมัติและบันทึก
                    </button>
                    <button class="btn btn-danger" onclick="rejectPayment(${payment.id})" ${payment.status === 'rejected' ? 'disabled' : ''}>
                        ❌ ปฏิเสธสลิป
                    </button>
                </div>
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
            await loadPayments();
            const updated = allPayments.find(p => String(p.id) === String(paymentId));
            if (updated) {
                document.getElementById('paymentDetailsContent').innerHTML = renderPaymentDetails(updated);
            }
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
    const typeSelect = document.getElementById(`paymentType-${paymentId}`);
    const noteInput = document.getElementById(`classifyNote-${paymentId}`);
    const currentPeriodInput = document.getElementById(`currentPeriod-${paymentId}`);
    const totalPeriodInput = document.getElementById(`totalPeriod-${paymentId}`);
    
    const paymentType = typeSelect ? typeSelect.value : '';
    const note = noteInput ? noteInput.value.trim() : '';
    const currentPeriod = currentPeriodInput ? parseInt(currentPeriodInput.value) || 1 : 1;
    const totalPeriod = totalPeriodInput ? parseInt(totalPeriodInput.value) || 3 : 3;
    
    // Validate
    if (!paymentType) {
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
            await loadPayments();
            const updated = allPayments.find(p => String(p.id) === String(paymentId));
            if (updated) {
                document.getElementById('paymentDetailsContent').innerHTML = renderPaymentDetails(updated);
            }
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

// Handle slip image loading error
function handleSlipImageError(imgElement) {
    if (!imgElement) return;
    
    // Try fallback to a default slip image
    const fallbackImages = [
        PATH.image ? PATH.image('slip-kbank.svg') : '/public/images/slip-kbank.svg',
        PATH.image ? PATH.image('receipt-mock.svg') : '/public/images/receipt-mock.svg'
    ];
    
    const currentSrc = imgElement.src;
    
    // If not already trying a fallback, try the first one
    if (!fallbackImages.some(fb => currentSrc.includes(fb))) {
        imgElement.src = fallbackImages[0];
        imgElement.title = 'ภาพสลิปตัวอย่าง (ไม่สามารถโหลดภาพจริงได้)';
        return;
    }
    
    // If first fallback failed, replace with placeholder
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

/**
 * ✅ Render chat messages from database or show fallback
 */
function renderChatMessages(chatMessages, customerName, payment, reviewHint) {
    // If we have real chat messages from database, render them
    if (chatMessages && Array.isArray(chatMessages) && chatMessages.length > 0) {
        let html = '';
        
        chatMessages.forEach(msg => {
            const isBot = msg.sender_type === 'bot' || msg.direction === 'outgoing';
            const bubbleClass = isBot ? 'bubble-bot' : 'bubble-user';
            const label = isBot ? 'Bot' : customerName;
            
            let content = '';
            
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
            } else {
                content = escapeHtml(msg.message_text || '');
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
                payment.status
            ].filter(Boolean);
            
            return searchFields.some(field =>
                String(field).toLowerCase().includes(query)
            );
        });
    }
    
    // Apply payment type filter
    if (currentFilter) {
        if (currentFilter === 'full') {
            result = result.filter(p => p.payment_type === 'full');
        } else if (currentFilter === 'installment') {
            result = result.filter(p => p.payment_type === 'installment');
        } else if (currentFilter === 'pending') {
            result = result.filter(p => p.status === 'pending');
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

function goToOrderFromPayment(orderNo) {
    if (!orderNo) return;
    const q = encodeURIComponent(String(orderNo));
    window.location.href = `${pageUrlSafe('orders.php')}?order_no=${q}`;
}
