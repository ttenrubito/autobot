/**
 * Payment Management JavaScript
 * Handles payment methods and Omise integration
 * FIXED: Robust init even if DOMContentLoaded already fired
 * FIXED: Guard getUserData() + add deep logs
 */

// ============ DEBUG: Verify file loading ============
console.log('🔧 [payment.js] File loaded - top of script');
console.log('🔧 [payment.js] Document readyState at load:', document.readyState);
console.log('🔧 [payment.js] location:', window.location?.href);
// ===================================================

// Ensure auth is loaded before using requireAuth
try {
    if (typeof requireAuth === 'function') {
        console.log('✅ [payment.js] requireAuth found, calling it...');
        requireAuth();
    } else {
        console.warn('⚠️ [payment.js] requireAuth function NOT found!');
    }
} catch (e) {
    console.error('❌ [payment.js] requireAuth crashed:', e);
}

// ---------------------------------------------------------------------------
// Safe fallback helpers (ONLY if missing) - prevent crash from missing globals
// ---------------------------------------------------------------------------
if (typeof window.showToast !== 'function') {
    window.showToast = function (msg, type = 'info') {
        console.log(`🔔 [toast:${type}]`, msg);
    };
}

if (typeof window.showLoading !== 'function') {
    window.showLoading = function () {
        console.log('⏳ [loading] showLoading() (fallback)');
    };
}

if (typeof window.hideLoading !== 'function') {
    window.hideLoading = function () {
        console.log('✅ [loading] hideLoading() (fallback)');
    };
}

if (typeof window.formatCurrency !== 'function') {
    window.formatCurrency = function (amount) {
        const n = Number(amount || 0);
        try {
            return n.toLocaleString('th-TH', { style: 'currency', currency: 'THB' });
        } catch {
            return `฿${n.toFixed(2)}`;
        }
    };
}

if (typeof window.formatDate !== 'function') {
    window.formatDate = function (d) {
        try {
            const dt = new Date(d);
            if (Number.isNaN(dt.getTime())) return String(d || '');
            return dt.toLocaleString('th-TH', { year: 'numeric', month: 'short', day: '2-digit' });
        } catch {
            return String(d || '');
        }
    };
}

// ---------------------------------------------------------------------------
// Omise Configuration (test key, safe to keep for sandbox)
// ---------------------------------------------------------------------------
const OMISE_PUBLIC_KEY = 'pkey_test_654hy8uu12f7afruxgn';

// Centralized payment endpoint paths (relative to /api)
// Map directly to existing PHP files under api/payment/
const PAYMENT_API_PATHS = {
    // Prefer unified endpoints from path-config.js (includes BASE_PATH)
    METHODS: (window.API_ENDPOINTS && API_ENDPOINTS.PAYMENT_METHODS) ? API_ENDPOINTS.PAYMENT_METHODS : '/api/payment/methods.php',
    ADD_CARD: (window.API_ENDPOINTS && API_ENDPOINTS.PAYMENT_ADD_CARD) ? API_ENDPOINTS.PAYMENT_ADD_CARD : '/api/payment/add-card.php',
    REMOVE_CARD: (window.API_ENDPOINTS && API_ENDPOINTS.PAYMENT_REMOVE_CARD) ? API_ENDPOINTS.PAYMENT_REMOVE_CARD : '/api/payment/remove-card.php',
    SET_DEFAULT: (window.API_ENDPOINTS && API_ENDPOINTS.PAYMENT_SET_DEFAULT) ? API_ENDPOINTS.PAYMENT_SET_DEFAULT : '/api/payment/set-default-card.php',
};

// Helper to build full API URL when needed
function getPaymentApiPath(key) {
    return PAYMENT_API_PATHS[key] || '';
}

// Unified helper: use apiCall() with absolute endpoints from API_ENDPOINTS when present
async function callPaymentApi(pathKey, options = {}) {
    const endpoint = getPaymentApiPath(pathKey);
    if (!endpoint) {
        throw new Error('Invalid payment API path key: ' + pathKey);
    }

    const opts = { ...options };
    opts.headers = {
        'Content-Type': 'application/json',
        ...(options.headers || {}),
    };

    if (typeof apiCall !== 'function') {
        console.error('❌ [payment.js] apiCall is NOT a function. Cannot call API:', endpoint);
        throw new Error('apiCall is not defined');
    }

    return apiCall(endpoint, opts);
}

// ============================================================================
// Loading State Management (prevent duplicate API calls)
// ============================================================================
const loadState = {
    invoices: false,      // โหลดบิลแล้วหรือยัง
    promptpay: false,     // โหลด promptpay แล้วหรือยัง (ใช้ข้อมูลเดียวกับ invoices)
    cards: false,         // โหลดบัตรแล้วหรือยัง
    subscription: false,  // โหลด subscription แล้วหรือยัง
    invoicesData: null,   // เก็บข้อมูลบิลไว้ใช้ซ้ำ
};

const loadingPromises = {
    invoices: null,
    cards: null,
    subscription: null,
    promptpay: null,
    card: null,
};

// Helper: ตรวจสอบและโหลดข้อมูล (โหลดครั้งเดียว, return Promise)
async function ensureLoaded(section) {
    // ถ้ากำลังโหลดอยู่ รอให้เสร็จ
    if (loadingPromises[section]) {
        console.log(`⏳ [ensureLoaded] ${section} is loading, waiting...`);
        return await loadingPromises[section];
    }

    // ถ้าโหลดเสร็จแล้ว
    if (loadState[section]) {
        console.log(`✅ [ensureLoaded] ${section} already loaded`);
        return;
    }

    console.log(`🔄 [ensureLoaded] Loading ${section}...`);

    // สร้าง Promise และเก็บไว้เพื่อป้องกันการโหลดซ้ำ
    const loadPromise = (async () => {
        try {
            if (section === 'invoices') {
                await loadInvoicesData();
            } else if (section === 'cards') {
                await loadPaymentMethods();
            } else if (section === 'subscription') {
                await loadSubscription();
            } else {
                // card / promptpay tabs use invoicesData already
                console.log(`ℹ️ [ensureLoaded] No direct loader for section="${section}" (ok)`);
            }

            loadState[section] = true;
            console.log(`✅ [ensureLoaded] ${section} loaded successfully`);
        } catch (error) {
            console.error(`❌ [ensureLoaded] Failed to load ${section}:`, error);
            loadState[section] = false;
            throw error;
        } finally {
            loadingPromises[section] = null; // ล้าง Promise
        }
    })();

    loadingPromises[section] = loadPromise;
    return await loadPromise;
}

// ============================================================================
// Robust Page Initialization (fix DOMContentLoaded missed)
// ============================================================================
async function initPaymentPage() {
    console.log('🚀 [payment.js] initPaymentPage() START');
    console.log('🔧 [payment.js] readyState in initPaymentPage:', document.readyState);

    // sanity check: required containers
    console.log('🔎 [payment.js] DOM check:', {
        pendingInvoicesContainer: !!document.getElementById('pendingInvoicesContainer'),
        invoicesSection: !!document.getElementById('invoicesSection'),
        promptpaySection: !!document.getElementById('promptpaySection'),
        cardSection: !!document.getElementById('cardSection'),
        subscriptionInfo: !!document.getElementById('subscriptionInfo'),
    });

    try {
        // 1) user info (must not crash whole flow)
        console.log('🔧 [payment.js] Step 1: loadUserInfo()');
        await loadUserInfo();

        // 2) invoices first
        console.log('🔧 [payment.js] Step 2: ensureLoaded("invoices")');
        await ensureLoaded('invoices');

        // 3) cards & subscription parallel
        console.log('🔧 [payment.js] Step 3: ensureLoaded("cards") + ensureLoaded("subscription")');
        await Promise.all([
            ensureLoaded('cards'),
            ensureLoaded('subscription'),
        ]);

        // 4) default tab
        console.log('🔧 [payment.js] Step 4: setActiveTab("invoices")');
        setActiveTab('invoices');

        // 5) init Omise
        console.log('🔧 [payment.js] Step 5: init Omise');
        if (typeof Omise !== 'undefined' && OMISE_PUBLIC_KEY) {
            Omise.setPublicKey(OMISE_PUBLIC_KEY);
            console.log('✅ [payment.js] Omise public key set');
        } else {
            console.warn('⚠️ [payment.js] Omise is undefined OR missing OMISE_PUBLIC_KEY');
        }

        console.log('✅ [payment.js] initPaymentPage() DONE');
    } catch (error) {
        console.error('❌ [payment.js] initPaymentPage() FAILED:', error);
    }
}

// ✅ Fix: If DOMContentLoaded already fired, init immediately
(function bootPaymentInit() {
    console.log('🔧 [payment.js] bootPaymentInit()');
    if (document.readyState === 'loading') {
        console.log('🔧 [payment.js] DOM still loading -> wait DOMContentLoaded');
        document.addEventListener('DOMContentLoaded', () => {
            console.log('✅ [payment.js] DOMContentLoaded fired -> initPaymentPage()');
            initPaymentPage();
        });
    } else {
        console.log('✅ [payment.js] DOM already ready -> initPaymentPage() immediately');
        initPaymentPage();
    }
})();

// ============================================================================
// UI helpers
// ============================================================================
async function loadUserInfo() {
    try {
        if (typeof getUserData !== 'function') {
            console.warn('⚠️ [loadUserInfo] getUserData() not found -> skip');
            return;
        }

        const userData = getUserData();
        if (!userData) {
            console.warn('⚠️ [loadUserInfo] getUserData() returned null/undefined');
            return;
        }

        // These elements may not exist on all pages, so check before updating
        const userNameEl = document.getElementById('userName');
        const userEmailEl = document.getElementById('userEmail');
        const userAvatarEl = document.getElementById('userAvatar');

        if (userNameEl) userNameEl.textContent = userData.full_name || userData.email;
        if (userEmailEl) userEmailEl.textContent = userData.email;
        if (userAvatarEl) {
            const initial = (userData.full_name || userData.email).charAt(0).toUpperCase();
            userAvatarEl.textContent = initial;
        }

        console.log('✅ [loadUserInfo] loaded:', { email: userData.email, full_name: userData.full_name });
    } catch (e) {
        console.error('❌ [loadUserInfo] crashed:', e);
    }
}

async function loadPaymentMethods() {
    console.log('💳 [loadPaymentMethods] start');
    try {
        const response = await callPaymentApi('METHODS');
        console.log('💳 [loadPaymentMethods] response:', response);

        if (response && response.success) {
            displayPaymentMethods(response.data);
        } else {
            displayPaymentError(response?.message || 'ไม่สามารถโหลดข้อมูลได้');
        }
    } catch (error) {
        console.error('❌ [loadPaymentMethods] Failed:', error);
        displayPaymentError('ไม่สามารถโหลดข้อมูลได้');
    }
}

function displayPaymentMethods(methods) {
    const container = document.getElementById('paymentMethodsContainer');
    if (!container) {
        console.warn('⚠️ [displayPaymentMethods] paymentMethodsContainer not found');
        return;
    }

    if (!methods || methods.length === 0) {
        container.innerHTML = `
      <div class="text-center" style="padding: 2rem;">
        <div style="font-size: 3rem; margin-bottom: 1rem;">💳</div>
        <h4>ยังไม่มีบัตรเครดิต</h4>
        <p style="color: var(--color-gray); margin-top: 0.5rem;">เพิ่มบัตรเครดิตหรือเดบิตเพื่อชำระเงินอัตโนมัติ</p>
        <button class="btn btn-primary mt-3" onclick="showAddCardForm()">เพิ่มบัตรใหม่</button>
      </div>
    `;
        return;
    }

    container.innerHTML = '';

    methods.forEach((method) => {
        const cardDiv = document.createElement('div');
        cardDiv.className = 'saved-card-item';
        cardDiv.innerHTML = `
      <div class="saved-card-main">
        <div class="saved-card-icon">${getCardIcon(method.card_brand)}</div>
        <div class="saved-card-info">
          <div class="saved-card-title-row">
            <span class="saved-card-label">${String(method.card_brand || '').toUpperCase()} •••• ${method.card_last4}</span>
            ${method.is_default ? '<span class="badge badge-success saved-card-badge">บัตรหลัก</span>' : ''}
          </div>
          <div class="saved-card-meta">
            <span><i class="far fa-calendar"></i> หมดอายุ ${String(method.card_expiry_month).padStart(2, '0')}/${method.card_expiry_year}</span>
          </div>
        </div>
      </div>
      <div class="saved-card-actions">
        ${!method.is_default ? `<button class="btn btn-sm saved-card-btn" onclick="setDefaultCard(${method.id})"><i class="fas fa-star"></i> ตั้งเป็นบัตรหลัก</button>` : ''}
        <button class="btn btn-sm saved-card-btn saved-card-btn--danger remove-card-btn" data-id="${method.id}" onclick="removeCard(${method.id})"><i class="fas fa-trash"></i> ลบบัตร</button>
      </div>
    `;
        container.appendChild(cardDiv);
    });

    console.log('✅ [displayPaymentMethods] rendered:', methods.length);
}

function getCardIcon(brand) {
    const icons = {
        visa: '<div style="width: 50px; height: 32px; background: white; border-radius: 4px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border: 1px solid #e0e0e0;"><div style="font-weight: 800; font-size: 1.1rem; color: #1A1F71; font-family: Arial, sans-serif; letter-spacing: -1px;">VISA</div></div>',
        mastercard: '<div style="width: 50px; height: 32px; background: white; border-radius: 4px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border: 1px solid #e0e0e0; position: relative;"><div style="position: absolute; width: 16px; height: 16px; background: #EB001B; border-radius: 50%; left: 14px;"></div><div style="position: absolute; width: 16px; height: 16px; background: #FF5F00; border-radius: 50%; left: 20px;"></div></div>',
        amex: '<div style="width: 50px; height: 32px; background: #006FCF; border-radius: 4px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1);"><div style="font-weight: 700; font-size: 0.7rem; color: white; font-family: Arial, sans-serif; letter-spacing: 0.5px;">AMEX</div></div>',
        jcb: '<div style="width: 50px; height: 32px; background: white; border-radius: 4px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border: 1px solid #e0e0e0;"><div style="font-weight: 800; font-size: 0.85rem; color: #0E4C96; font-family: Arial, sans-serif;">JCB</div></div>',
    };
    const key = String(brand || '').toLowerCase();
    return icons[key] || '<i class="fas fa-credit-card" style="font-size: 2rem; color: var(--color-gray);"></i>';
}

async function loadSubscription() {
    console.log('📦 [loadSubscription] start');
    try {
        if (typeof apiCall !== 'function') throw new Error('apiCall is not defined');

        if (!window.API_ENDPOINTS?.AUTH_ME) {
            console.warn('⚠️ [loadSubscription] API_ENDPOINTS.AUTH_ME missing, fallback to /api/auth/me.php');
        }

        const endpoint = window.API_ENDPOINTS?.AUTH_ME || '/api/auth/me.php';
        const response = await apiCall(endpoint);
        console.log('📦 [loadSubscription] response:', response);

        if (response && response.success && response.data?.subscription) {
            displaySubscription(response.data.subscription);
        } else {
            displayNoSubscription();
        }
    } catch (error) {
        console.error('❌ [loadSubscription] Failed:', error);
        displayNoSubscription();
    }
}

function displaySubscription(subscription) {
    const container = document.getElementById('subscriptionInfo');
    if (!container) return;

    const statusBadge =
        subscription.status === 'active'
            ? '<span class="badge badge-success">Active</span>'
            : '<span class="badge badge-warning">Paused</span>';

    // Calculate billing cycle progress
    let progressHtml = '';
    if (subscription.current_period_start && subscription.current_period_end) {
        const start = new Date(subscription.current_period_start);
        const end = new Date(subscription.current_period_end);
        const now = new Date();

        const totalMs = end - start;
        const usedMs = Math.min(Math.max(now - start, 0), totalMs);
        const remainingMs = Math.max(end - now, 0);

        const remainingDays = remainingMs > 0 ? Math.ceil(remainingMs / 86400000) : 0;
        const usedPercent = totalMs > 0 ? Math.round((usedMs / totalMs) * 100) : 0;

        progressHtml = `
      <div style="margin-top: 1rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
          <span style="color: var(--color-gray); font-size: 0.8rem;">รอบบิลปัจจุบัน</span>
          <span style="font-size: 0.8rem; color: var(--color-primary); font-weight: 600;">
            เหลืออีก <strong>${remainingDays}</strong> วัน
          </span>
        </div>
        <div style="position: relative; height: 6px; border-radius: 999px; background: var(--color-light-2); overflow: hidden;">
          <div style="width: ${usedPercent}%; height: 100%; background: linear-gradient(90deg, #7c3aed, #ec4899);"></div>
        </div>
        <div style="display: flex; justify-content: space-between; margin-top: 0.35rem; font-size: 0.75rem; color: var(--color-gray);">
          <span>${formatDate(subscription.current_period_start)}</span>
          <span>${formatDate(subscription.current_period_end)}</span>
        </div>
      </div>
    `;
    }

    container.innerHTML = `
    <div style="text-align: center; padding-bottom: 1rem; border-bottom: 1px solid var(--color-light-3);">
      <div style="font-size: 2.25rem; font-weight: 700; color: var(--color-primary); margin-bottom: 0.25rem;">
        ${formatCurrency(subscription.monthly_price)}
      </div>
      <div style="font-weight: 600; margin-bottom: 0.35rem; font-size: 0.95rem;">${subscription.plan_name}</div>
      ${statusBadge}
    </div>
    <div style="margin-top: 0.75rem; font-size: 0.875rem;">
      <div style="display: flex; justify-content: space-between; margin-bottom: 0.35rem;">
        <span style="color: var(--color-gray);">รอบบิลถัดไป:</span>
        <strong>${formatDate(subscription.current_period_end)}</strong>
      </div>
      <div style="display: flex; justify-content: space-between;">
        <span style="color: var(--color-gray);">ต่ออายุอัตโนมัติ:</span>
        <strong>${subscription.auto_renew ? 'เปิด' : 'ปิด'}</strong>
      </div>
      ${progressHtml}
    </div>
  `;
}

function displayNoSubscription() {
    const container = document.getElementById('subscriptionInfo');
    if (!container) return;
    container.innerHTML = `
    <div class="text-center" style="padding: 1rem;">
      <p style="color: var(--color-gray);">ไม่มีแพ็คเกจที่ใช้งานอยู่</p>
    </div>
  `;
}

function displayPaymentError(message) {
    const container = document.getElementById('paymentMethodsContainer');
    if (!container) return;
    container.innerHTML = `
    <div class="text-center" style="padding: 2rem;">
      <div style="font-size: 3rem; margin-bottom: 1rem;">⚠️</div>
      <p style="color: var(--color-danger);">${message}</p>
    </div>
  `;
}

// ============================================================================
// Tab Switching (FIXED: map tabs -> real sections to load)
// ============================================================================
function switchPaymentMethod(method) {
    console.log('🧭 [switchPaymentMethod] ->', method);

    setActiveTab(method);

    // ✅ FIX: ensure the correct data loads when user clicks tab (even if init failed)
    if (method === 'invoices') ensureLoaded('invoices');
    else if (method === 'promptpay') ensureLoaded('invoices'); // same invoicesData
    else if (method === 'card') ensureLoaded('cards');
}

// แยกฟังก์ชันเปลี่ยน UI ออกมา (ไม่โหลดข้อมูล)
function setActiveTab(method) {
    const cardSection = document.getElementById('cardSection');
    const invoicesSection = document.getElementById('invoicesSection');
    const promptpaySection = document.getElementById('promptpaySection');
    const tabs = document.querySelectorAll('.payment-tab');

    if (!cardSection || !invoicesSection || !promptpaySection) {
        console.warn('⚠️ [setActiveTab] sections missing', {
            cardSection: !!cardSection,
            invoicesSection: !!invoicesSection,
            promptpaySection: !!promptpaySection,
        });
        return;
    }

    // ซ่อนทุก section
    cardSection.classList.add('hidden');
    invoicesSection.classList.add('hidden');
    promptpaySection.classList.add('hidden');

    // แสดง section ที่เลือก
    if (method === 'card') {
        cardSection.classList.remove('hidden');
    } else if (method === 'invoices') {
        invoicesSection.classList.remove('hidden');
    } else if (method === 'promptpay') {
        promptpaySection.classList.remove('hidden');
    }

    // อัพเดท active tab
    tabs.forEach((tab) => {
        if (tab.dataset.method === method) tab.classList.add('active');
        else tab.classList.remove('active');
    });
}

// Helper: แสดง invoices tab และ scroll
function showPendingInvoices() {
    switchPaymentMethod('invoices');
    const section = document.getElementById('invoicesSection');
    if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ============================================================================
// Invoice Loading (load once, display in multiple places)
// ============================================================================

async function loadInvoicesData() {
    console.log('📋 [loadInvoicesData] START');

    try {
        // Use unified API endpoint (includes BASE_PATH)
        const url = (window.API_ENDPOINTS && API_ENDPOINTS.BILLING_INVOICES)
            ? (API_ENDPOINTS.BILLING_INVOICES + '?status=pending')
            : '/api/billing/invoices.php?status=pending';

        console.log('📋 [loadInvoicesData] calling:', url);
        const response = await apiCall(url);
        console.log('📋 [loadInvoicesData] response:', response);

        if (response && response.success) {
            // Accept both shapes: {data: [...]} or {data: {invoices: [...]}}
            const invoices = Array.isArray(response.data)
                ? response.data
                : (response.data && Array.isArray(response.data.invoices) ? response.data.invoices : []);

            loadState.invoicesData = invoices;
            console.log(`✅ [loadInvoicesData] Loaded ${loadState.invoicesData.length} pending invoices`);
            renderInvoices(loadState.invoicesData);
            loadState.invoices = true;
            return;
        }

        console.warn('⚠️ [loadInvoicesData] API failed or returned success=false:', response);
        loadState.invoicesData = [];
        renderInvoices([]);
        loadState.invoices = true;
    } catch (error) {
        console.error('❌ [loadInvoicesData] Failed:', error);
        loadState.invoicesData = [];
        renderInvoices([]);
        loadState.invoices = true;
    }
}

// ============================================================================
// Invoice Rendering (MISSING BEFORE: called by loadInvoicesData)
// ============================================================================
function renderInvoices(invoices) {
    try {
        const list = Array.isArray(invoices) ? invoices : [];

        // Update top alert + both sections (invoices + promptpay)
        updatePendingInvoicesAlert(list);
        displayInvoicesInSection(list);
        displayInvoicesInPromptPaySection(list);

        // Update any counters if present
        const pendingCountEl = document.getElementById('pendingCount');
        if (pendingCountEl) pendingCountEl.textContent = String(list.length);

        console.log('[32m%s[0m', `✅ [renderInvoices] rendered ${list.length} invoices`);
    } catch (e) {
        console.error('❌ [renderInvoices] crashed:', e);
    }
}

function updatePendingInvoicesAlert(invoices) {
    const alert = document.getElementById('pendingInvoicesAlert');
    const countEl = document.getElementById('pendingCount');
    const totalEl = document.getElementById('pendingTotal');

    if (!alert || !countEl || !totalEl) {
        console.warn('⚠️ [updatePendingInvoicesAlert] elements missing');
        return;
    }

    if (invoices && invoices.length > 0) {
        const total = invoices.reduce((sum, inv) => sum + parseFloat(inv.total || 0), 0);
        countEl.textContent = invoices.length;
        totalEl.textContent = formatCurrency(total);
        alert.classList.remove('hidden');
    } else {
        alert.classList.add('hidden');
    }
}

function displayInvoicesInSection(invoices) {
    const container = document.getElementById('pendingInvoicesContainer');
    if (!container) return;

    if (!invoices || invoices.length === 0) {
        container.innerHTML = `
      <div class="text-center" style="padding: 2rem;">
        <div style="font-size: 3rem; margin-bottom: 1rem;">✅</div>
        <h4>ไม่มีบิลค้างชำระ</h4>
        <p style="color: var(--color-gray); margin-top: 0.5rem;">คุณไม่มีบิลที่รอชำระเงินในขณะนี้</p>
      </div>
    `;
        return;
    }

    displayPendingInvoicesWithPaymentOptions(invoices);
}

function displayInvoicesInPromptPaySection(invoices) {
    const container = document.getElementById('promptpayInvoicesContainer');
    if (!container) return;

    if (!invoices || invoices.length === 0) {
        container.innerHTML = `
      <div class="text-center" style="padding: 2rem;">
        <div style="font-size: 3rem; margin-bottom: 1rem;">✅</div>
        <h4>ไม่มีบิลค้างชำระ</h4>
        <p style="color: var(--color-gray); margin-top: 0.5rem;">คุณไม่มีบิลที่รอชำระเงินในขณะนี้</p>
      </div>
    `;
        return;
    }

    displayPromptPayInvoices(invoices);
}

// Display pending invoices with payment method selection (2 buttons: QR + Card)
function displayPendingInvoicesWithPaymentOptions(invoices) {
    console.log('🎨 [displayPendingInvoicesWithPaymentOptions] Rendering', invoices.length, 'invoices');
    const container = document.getElementById('pendingInvoicesContainer');
    if (!container) {
        console.warn('⚠️ [displayPendingInvoicesWithPaymentOptions] Container not found');
        return;
    }

    const invoicesHTML = invoices.map((invoice) => `
    <div class="card" style="margin-bottom: 1rem; border: 1px solid var(--color-light-3);">
      <div class="card-body" style="padding: 1rem;">
        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 1rem;">
          <div>
            <div style="font-weight: 600; font-size: 1rem; margin-bottom: 0.25rem;">
              ${invoice.invoice_number}
            </div>
            <div style="font-size: 0.875rem; color: var(--color-gray);">
              ครบกำหนด: ${invoice.due_date ? formatDate(invoice.due_date).split(' ')[0] : 'ไม่ระบุ'}
            </div>
          </div>
          <div style="text-align: right;">
            <div style="font-size: 1.25rem; font-weight: 700; color: var(--color-primary);">
              ${formatCurrency(invoice.total)}
            </div>
            <span class="badge badge-warning">รอชำระ</span>
          </div>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
          <button class="btn btn-primary" onclick="payWithPromptPay(${invoice.id})">
            <i class="fas fa-qrcode"></i> ชำระผ่าน QR Code
          </button>
          <button class="btn btn-outline" onclick="payWithCard(${invoice.id})">
            <i class="fas fa-credit-card"></i> ชำระด้วยบัตร
          </button>
        </div>
      </div>
    </div>
  `).join('');

    container.innerHTML = invoicesHTML;
    console.log('✅ [displayPendingInvoicesWithPaymentOptions] Rendered');
}

// Display invoices in PromptPay section (QR code button only)
function displayPromptPayInvoices(invoices) {
    console.log('🎨 [displayPromptPayInvoices] Rendering', invoices.length, 'invoices');
    const container = document.getElementById('promptpayInvoicesContainer');
    if (!container) {
        console.warn('⚠️ [displayPromptPayInvoices] Container not found');
        return;
    }

    const invoicesHTML = invoices.map((invoice) => `
    <div class="card" style="margin-bottom: 1rem; border: 1px solid var(--color-light-3);">
      <div class="card-body" style="padding: 1rem;">
        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.75rem;">
          <div>
            <div style="font-weight: 600; font-size: 1rem; margin-bottom: 0.25rem;">
              ${invoice.invoice_number}
            </div>
            <div style="font-size: 0.875rem; color: var(--color-gray);">
              ครบกำหนด: ${invoice.due_date ? formatDate(invoice.due_date).split(' ')[0] : 'ไม่ระบุ'}
            </div>
          </div>
          <div style="text-align: right;">
            <div style="font-size: 1.25rem; font-weight: 700; color: var(--color-primary);">
              ${formatCurrency(invoice.total)}
            </div>
            <span class="badge badge-warning">รอชำระ</span>
          </div>
        </div>
        <button class="btn btn-primary btn-block" onclick="payWithPromptPay(${invoice.id})">
          <i class="fas fa-qrcode"></i> ชำระผ่าน QR Code
        </button>
      </div>
    </div>
  `).join('');

    container.innerHTML = invoicesHTML;
    console.log('✅ [displayPromptPayInvoices] Rendered');
}

// ============================================================================
// Modal (Add Card)
// ============================================================================
function showAddCardForm() {
    const modal = document.getElementById('addCardModal');
    if (!modal) return;

    modal.classList.remove('hidden');
    const form = document.getElementById('cardForm');
    if (form) form.reset();

    const cardNumberInput = document.getElementById('cardNumber');
    if (cardNumberInput) setTimeout(() => cardNumberInput.focus(), 0);

    document.body.style.overflow = 'hidden';

    document.removeEventListener('keydown', handleEscKey);
    modal.removeEventListener('click', handleBackdropClick);

    document.addEventListener('keydown', handleEscKey);

    setTimeout(() => {
        modal.addEventListener('click', handleBackdropClick);
    }, 100);
}

function hideAddCardForm() {
    const modal = document.getElementById('addCardModal');
    if (!modal) return;

    modal.classList.add('hidden');
    document.body.style.overflow = '';

    document.removeEventListener('keydown', handleEscKey);
    modal.removeEventListener('click', handleBackdropClick);
}

function handleBackdropClick(e) {
    const modal = document.getElementById('addCardModal');
    if (!modal) return;
    if (e.target === modal) hideAddCardForm();
}

function handleEscKey(e) {
    if (e.key === 'Escape') hideAddCardForm();
}

async function removeCard(paymentMethodId) {
    if (!confirm('ต้องการลบบัตรใบนี้ใช่หรือไม่?\n\nหากบัตรถูกใช้ทำรายการไปแล้ว ระบบจะไม่อนุญาตให้ลบ')) {
        return;
    }

    const btn = document.querySelector(`.remove-card-btn[data-id="${paymentMethodId}"]`);
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    }

    try {
        const res = await apiCall(PAYMENT_API_PATHS.REMOVE_CARD, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: { payment_method_id: paymentMethodId },
        });

        if (!res?.success) {
            if (res?.code === 'PAYMENT_METHOD_IN_USE') {
                alert(res.message || 'ไม่สามารถลบบัตรที่มีประวัติการชำระเงินได้');
            } else {
                alert(res?.message || 'ไม่สามารถลบบัตรได้ กรุณาลองใหม่อีกครั้ง');
            }
            return;
        }

        alert('ลบบัตรสำเร็จ');
        await loadPaymentMethods();
    } catch (error) {
        console.error('Error removing card:', error);
        alert('เกิดข้อผิดพลาดในการลบบัตร กรุณาลองใหม่อีกครั้ง');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash"></i>';
        }
    }
}

// Helper to show form validation error
function showCardFormError(message) {
    const el = document.getElementById('cardError');
    if (!el) return;
    el.textContent = message;
    el.classList.remove('hidden');
}

function clearCardFormError() {
    const el = document.getElementById('cardError');
    if (!el) return;
    el.textContent = '';
    el.classList.add('hidden');
}

// Basic Luhn check
function isValidCardNumber(num) {
    const cleaned = num.replace(/\D/g, '');
    if (cleaned.length < 13 || cleaned.length > 19) return false;
    let sum = 0;
    let shouldDouble = false;
    for (let i = cleaned.length - 1; i >= 0; i--) {
        let digit = parseInt(cleaned.charAt(i), 10);
        if (shouldDouble) {
            digit *= 2;
            if (digit > 9) digit -= 9;
        }
        sum += digit;
        shouldDouble = !shouldDouble;
    }
    return sum % 10 === 0;
}

function isValidExpiry(expiry) {
    if (!/^\d{2}\/\d{2}$/.test(expiry)) return false;
    const [mStr, yStr] = expiry.split('/');
    const month = parseInt(mStr, 10);
    if (month < 1 || month > 12) return false;

    const year = 2000 + parseInt(yStr, 10); // YY -> 20YY
    const now = new Date();
    const expDate = new Date(year, month); // first day of month after expiry month
    return expDate > now;
}

function isValidCVV(cvv) {
    return /^\d{3,4}$/.test(cvv);
}

// Handle card form submission with real Omise token creation
document.getElementById('cardForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();

    const cardNumberInput = document.getElementById('cardNumber');
    const cardNameInput = document.getElementById('cardName');
    const cardExpiryInput = document.getElementById('cardExpiry');
    const cardCVVInput = document.getElementById('cardCVV');
    const setDefaultInput = document.getElementById('setDefault');

    const cardNumber = cardNumberInput.value.replace(/\s/g, '');
    const cardName = cardNameInput.value.trim();
    const cardExpiry = cardExpiryInput.value.trim();
    const cardCVV = cardCVVInput.value.trim();
    const setDefault = setDefaultInput ? setDefaultInput.checked : true;

    clearCardFormError();

    if (!cardNumber || !cardName || !cardExpiry || !cardCVV) {
        showCardFormError('กรุณากรอกข้อมูลให้ครบถ้วน');
        return;
    }
    if (!isValidCardNumber(cardNumber)) {
        showCardFormError('หมายเลขบัตรไม่ถูกต้อง');
        return;
    }
    if (!isValidExpiry(cardExpiry)) {
        showCardFormError('วันหมดอายุไม่ถูกต้อง');
        return;
    }
    if (!isValidCVV(cardCVV)) {
        showCardFormError('รหัส CVV ต้องเป็นตัวเลข 3-4 หลัก');
        return;
    }

    const [expMonthStr, expYearStr] = cardExpiry.split('/');
    const expMonth = parseInt(expMonthStr, 10);
    const expYear = 2000 + parseInt(expYearStr, 10);

    if (typeof Omise === 'undefined') {
        console.error('❌ Omise.js is not loaded');
        showCardFormError('ไม่สามารถโหลดระบบชำระเงินได้ กรุณารีเฟรชหน้านี้');
        return;
    }

    const submitButton = e.target.querySelector('button[type="submit"]');
    if (submitButton) submitButton.disabled = true;

    showLoading();

    try {
        if (OMISE_PUBLIC_KEY) Omise.setPublicKey(OMISE_PUBLIC_KEY);

        const tokenResponse = await new Promise((resolve, reject) => {
            Omise.createToken('card', {
                name: cardName,
                number: cardNumber,
                expiration_month: expMonth,
                expiration_year: expYear,
                security_code: cardCVV,
            }, (statusCode, response) => {
                if (statusCode === 200) resolve(response);
                else reject(response);
            });
        });

        const omiseToken = tokenResponse.id;

        const response = await callPaymentApi('ADD_CARD', {
            method: 'POST',
            body: {
                omise_token: omiseToken,
                set_default: setDefault,
            },
        });

        console.log('✅ [ADD_CARD] response:', response);

        if (response && response.success) {
            hideAddCardForm();
            await loadPaymentMethods();

            const hasInvoices = loadState.invoicesData && loadState.invoicesData.length > 0;
            if (hasInvoices) {
                const total = loadState.invoicesData.reduce((s, inv) => s + parseFloat(inv.total || 0), 0);
                const confirmPay = confirm(
                    `✅ เพิ่มบัตรสำเร็จ!\n\n` +
                    `คุณมีบิลค้างชำระ ${loadState.invoicesData.length} บิล (${formatCurrency(total)})\n\n` +
                    `ต้องการชำระเงินทันทีด้วยบัตรนี้หรือไม่?`
                );

                if (confirmPay) {
                    switchPaymentMethod('invoices');
                    setTimeout(() => {
                        document.getElementById('invoicesSection')?.scrollIntoView({ behavior: 'smooth' });
                    }, 100);
                } else {
                    showToast('บันทึกบัตรแล้ว สามารถชำระบิลได้ทุกเมื่อ', 'success');
                }
            } else {
                showToast('✅ เพิ่มบัตรสำเร็จ!\n\nบัตรนี้จะถูกใช้สำหรับชำระเงินรอบถัดไปอัตโนมัติ', 'success');
            }
        } else {
            const msg = response?.message || 'เพิ่มบัตรไม่สำเร็จ';
            showCardFormError(msg);
            showToast(msg, 'error');
        }
    } catch (error) {
        console.error('❌ Add card error:', error);
        if (error && error.object === 'error') showCardFormError(error.message || 'เกิดข้อผิดพลาดในการสร้างโทเค็นบัตร');
        else showCardFormError('เกิดข้อผิดพลาดระหว่างเพิ่มบัตร');
    } finally {
        hideLoading();
        if (submitButton) submitButton.disabled = false;
    }
});

// Clear error on input change
['cardNumber', 'cardName', 'cardExpiry', 'cardCVV'].forEach((id) => {
    const el = document.getElementById(id);
    el?.addEventListener('input', clearCardFormError);
});

// Auto-format card number with brand detection
document.getElementById('cardNumber')?.addEventListener('input', (e) => {
    let value = e.target.value.replace(/\s/g, '');

    const brandIcon = document.getElementById('cardBrandIcon');
    if (brandIcon) {
        const brand = detectCardBrand(value);
        brandIcon.innerHTML = getCardIconHTML(brand);
    }

    value = value.match(/.{1,4}/g)?.join(' ') || value;
    e.target.value = value;
});

// Auto-format expiry
document.getElementById('cardExpiry')?.addEventListener('input', (e) => {
    let value = e.target.value.replace(/\D/g, '');
    if (value.length >= 2) value = value.substring(0, 2) + '/' + value.substring(2, 4);
    e.target.value = value;
});

// Detect card brand from number
function detectCardBrand(number) {
    number = number.replace(/\s/g, '');
    if (/^4/.test(number)) return 'visa';
    if (/^(5[1-5]|2(2(2[1-9]|[3-9])|[3-6]|7([0-1]|20)))/.test(number)) return 'mastercard';
    if (/^3[47]/.test(number)) return 'amex';
    if (/^35/.test(number)) return 'jcb';
    return 'unknown';
}

function getCardIconHTML(brand) {
    const icons = {
        visa: '<div style="width: 40px; height: 26px; background: white; border-radius: 3px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border: 1px solid #e0e0e0;"><div style="font-weight: 800; font-size: 0.9rem; color: #1A1F71; font-family: Arial, sans-serif; letter-spacing: -1px;">VISA</div></div>',
        mastercard: '<div style="width: 40px; height: 26px; background: white; border-radius: 3px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border: 1px solid #e0e0e0; position: relative;"><div style="position: absolute; width: 13px; height: 13px; background: #EB001B; border-radius: 50%; left: 11px;"></div><div style="position: absolute; width: 13px; height: 13px; background: #FF5F00; border-radius: 50%; left: 16px;"></div></div>',
        amex: '<div style="width: 40px; height: 26px; background: #006FCF; border-radius: 3px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1);"><div style="font-weight: 700; font-size: 0.6rem; color: white; font-family: Arial, sans-serif; letter-spacing: 0.5px;">AMEX</div></div>',
        jcb: '<div style="width: 40px; height: 26px; background: white; border-radius: 3px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border: 1px solid #e0e0e0;"><div style="font-weight: 800; font-size: 0.7rem; color: #0E4C96; font-family: Arial, sans-serif;">JCB</div></div>',
        unknown: '<i class="fas fa-credit-card" style="color: var(--color-gray);"></i>',
    };
    return icons[brand] || icons.unknown;
}

// ============================================================================
// Payment Functions
// ============================================================================

// Pay invoice with Card - Use default card for payment
async function payWithCard(invoiceId) {
    if (!confirm('ต้องการชำระด้วยบัตรเครดิตหลักใช่หรือไม่?')) return;

    try {
        showLoading();

        const response = await apiCall('/api/payment/pay-invoice-with-card.php', {
            method: 'POST',
            body: { invoice_id: invoiceId },
        });

        hideLoading();

        if (!response || !response.success) {
            // ✅ UX Improvement: If no card found, open add card modal instead of just alert
            if (response?.error_code === 'NOT_FOUND' ||
                (response?.message && response.message.toLowerCase().includes('no default payment method'))) {

                if (confirm('⚠️ คุณยังไม่มีบัตรเครดิต\n\nต้องการเพิ่มบัตรเพื่อชำระเงินเลยไหม?')) {
                    showAddCardForm();
                }
                return;
            }

            // Other errors
            alert(response?.message || 'ไม่สามารถชำระเงินได้');
            return;
        }

        alert('✅ ชำระเงินสำเร็จ!\n\nขอบคุณที่ใช้บริการ');

        // ✅ reload invoices safely
        await loadInvoicesData();
    } catch (error) {
        hideLoading();
        console.error('❌ Card payment error:', error);
        alert('เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง');
    }
}

// Pay invoice with PromptPay - Real implementation with Omise
async function payWithPromptPay(invoiceId) {
    try {
        showLoading();

        const response = await apiCall('/api/payment/create-promptpay-charge.php', {
            method: 'POST',
            body: { invoice_id: invoiceId },
        });

        if (!response || !response.success) {
            hideLoading();
            alert(response?.message || 'ไม่สามารถสร้าง QR Code ได้');
            return;
        }

        const { qr_code_url, amount, charge_id, expires_at } = response.data || {};
        if (!qr_code_url) {
            hideLoading();
            alert('ไม่พบ QR Code กรุณาลองใหม่อีกครั้ง');
            return;
        }

        hideLoading();

        showPromptPayQR(qr_code_url, amount, charge_id, expires_at);
        startPaymentStatusPolling(charge_id, invoiceId);
    } catch (error) {
        hideLoading();
        console.error('❌ PromptPay payment error:', error);
        alert('เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง');
    }
}

// Show PromptPay QR Code modal
function showPromptPayQR(qrCodeUrl, amount, chargeId, expiresAt) {
    const modal = document.getElementById('promptpayQRModal');
    const qrImage = document.getElementById('qrCodeImage');
    const qrAmountEl = document.getElementById('qrAmount');

    if (!modal || !qrImage || !qrAmountEl) return;

    qrImage.src = qrCodeUrl;
    qrAmountEl.textContent = formatCurrency(amount);

    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    if (expiresAt) startQRCountdown(expiresAt);
}

// Hide PromptPay QR modal
function hidePromptPayQR() {
    const modal = document.getElementById('promptpayQRModal');
    if (!modal) return;

    modal.classList.add('hidden');
    document.body.style.overflow = '';

    if (window.paymentPollingInterval) {
        clearInterval(window.paymentPollingInterval);
        window.paymentPollingInterval = null;
    }

    if (window.qrCountdownInterval) {
        clearInterval(window.qrCountdownInterval);
        window.qrCountdownInterval = null;
    }
}

// Start QR code countdown timer
function startQRCountdown(expiresAt) {
    const countdownEl = document.getElementById('qrCountdown');
    if (!countdownEl) return;

    const updateCountdown = () => {
        const now = new Date().getTime();
        const expiry = new Date(expiresAt).getTime();
        const remaining = expiry - now;

        if (remaining <= 0) {
            countdownEl.textContent = 'QR Code หมดอายุแล้ว';
            countdownEl.style.color = 'var(--color-danger)';
            return;
        }

        const minutes = Math.floor(remaining / 60000);
        const seconds = Math.floor((remaining % 60000) / 1000);
        countdownEl.textContent = `หมดอายุใน ${minutes}:${seconds.toString().padStart(2, '0')} นาที`;
    };

    updateCountdown();
    const interval = setInterval(updateCountdown, 1000);
    window.qrCountdownInterval = interval;
}

// Poll payment status
function startPaymentStatusPolling(chargeId, invoiceId) {
    console.log('🔁 [polling] startPaymentStatusPolling:', { chargeId, invoiceId });

    let pollCount = 0;
    const maxPolls = 60; // ~5 minutes (every 5 seconds)

    const pollStatus = async () => {
        try {
            const response = await apiCall(`/api/payment/check-charge-status.php?charge_id=${chargeId}`);

            if (response && response.success) {
                const { status, paid } = response.data || {};
                console.log('🔁 [polling] status:', { status, paid, pollCount });

                if (paid && status === 'successful') {
                    clearInterval(window.paymentPollingInterval);
                    window.paymentPollingInterval = null;
                    hidePromptPayQR();
                    alert('✅ ชำระเงินสำเร็จ!\n\nขอบคุณที่ใช้บริการ');
                    await loadInvoicesData();
                    return;
                } else if (status === 'failed' || status === 'expired') {
                    clearInterval(window.paymentPollingInterval);
                    window.paymentPollingInterval = null;
                    hidePromptPayQR();
                    alert('❌ การชำระเงินล้มเหลว\n\nกรุณาลองใหม่อีกครั้ง');
                    return;
                }
            }

            pollCount++;
            if (pollCount >= maxPolls) {
                clearInterval(window.paymentPollingInterval);
                window.paymentPollingInterval = null;

                const statusEl = document.getElementById('qrStatusMessage');
                if (statusEl) {
                    statusEl.textContent = 'หมดเวลารอชำระเงิน กรุณาปิดหน้าต่างนี้และลองใหม่อีกครั้ง';
                    statusEl.classList.remove('hidden');
                    statusEl.style.color = 'var(--color-warning)';
                }
            }
        } catch (error) {
            console.error('❌ [polling] error:', error);
        }
    };

    window.paymentPollingInterval = setInterval(pollStatus, 5000);
    pollStatus();
}
