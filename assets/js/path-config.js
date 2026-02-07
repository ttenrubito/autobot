/**
 * Universal Path Configuration for Autobot
 * 
 * Auto-detects environment (localhost vs Cloud Run) and provides correct paths
 * for assets (CSS, JS, images) and API endpoints.
 * 
 * Usage:
 *   - Load this file FIRST before any other scripts
 *   - Use PATH.api(), PATH.asset(), PATH.image() to generate paths
 *   - Or use API_ENDPOINTS, PAGES constants directly
 */

(function () {
    'use strict';

    /**
     * Auto-detect base path
     * - Prefer window.BASE_PATH_OVERRIDE from PHP (single source of truth)
     * - Fallback: infer '/autobot' from pathname when hosted under /autobot
     * - Otherwise: ''
     */
    const BASE_PATH = (() => {
        if (typeof window !== 'undefined' && typeof window.BASE_PATH_OVERRIDE === 'string') {
            // normalize to '' or '/xxx' (no trailing slash)
            const v = window.BASE_PATH_OVERRIDE.trim();
            if (!v) return '';
            return v.endsWith('/') ? v.slice(0, -1) : v;
        }

        // Infer from URL path (works for localhost and any host where app is under /autobot)
        const p = window.location.pathname || '/';
        if (p === '/autobot' || p.startsWith('/autobot/')) return '/autobot';

        return '';
    })();

    /**
     * Path Helper Functions
     */
    window.PATH = {
        /**
         * Get base path
         */
        base: () => BASE_PATH,

        /**
         * Generate API endpoint path
         * @param {string} endpoint - API endpoint (e.g., '/api/auth/login.php')
         * @returns {string} Full path
         */
        api: (endpoint) => {
            const cleanEndpoint = String(endpoint || '').startsWith('/') ? String(endpoint || '').substring(1) : String(endpoint || '');
            return BASE_PATH ? `${BASE_PATH}/${cleanEndpoint}` : `/${cleanEndpoint}`;
        },

        /**
         * Generate asset path (CSS, JS)
         * @param {string} asset - Asset path (e.g., '/assets/css/style.css')
         * @returns {string} Full path
         */
        asset: (asset) => {
            const cleanAsset = String(asset || '').startsWith('/') ? String(asset || '').substring(1) : String(asset || '');
            return BASE_PATH ? `${BASE_PATH}/${cleanAsset}` : `/${cleanAsset}`;
        },

        /**
         * Generate image path
         * @param {string} image - Image path relative to images root (e.g., 'logo.png', 'icons/x.svg')
         * @returns {string} Full path
         */
        image: (image) => {
            const cleanImage = String(image || '').replace(/^\/*/, '');

            // Images are served from /images (Cloud Run/production) or /autobot/public/images (localhost)
            // Production (BASE_PATH=''): /images/<file>
            if (!BASE_PATH) return `/images/${cleanImage}`;

            // Localhost /autobot: images are under /autobot/public/images
            if (BASE_PATH === '/autobot') return `${BASE_PATH}/public/images/${cleanImage}`;

            // Any other base path: assume /<base>/images
            return `${BASE_PATH}/images/${cleanImage}`;
        },

        /**
         * Generate page path
         * @param {string} page - Page path (e.g., '/public/dashboard.php')
         * @returns {string} Full path
         */
        page: (page) => {
            const cleanPage = String(page || '').startsWith('/') ? String(page || '').substring(1) : String(page || '');
            return BASE_PATH ? `${BASE_PATH}/${cleanPage}` : `/${cleanPage}`;
        },

        // Aliases (helps standardize usage across all pages)
        apiUrl: (endpoint) => window.PATH.api(endpoint),
        assetUrl: (asset) => window.PATH.asset(asset),
        imageUrl: (image) => window.PATH.image(image),
        pageUrl: (page) => window.PATH.page(page),
    };

    /**
     * API Endpoints - Auto-adjusts based on environment
     */
    window.API_ENDPOINTS = {
        // Auth
        AUTH_LOGIN: PATH.api('api/auth/login.php'),
        AUTH_LOGOUT: PATH.api('api/auth/logout.php'),
        AUTH_ME: PATH.api('api/auth/me.php'),
        AUTH_FORGOT_PASSWORD: PATH.api('api/auth/forgot-password.php'),

        // Admin Auth
        ADMIN_LOGIN: PATH.api('api/admin/login.php'),
        ADMIN_LOGOUT: PATH.api('api/admin/logout.php'),

        // Dashboard
        DASHBOARD_STATS: PATH.api('api/dashboard/stats.php'),

        // User
        USER_PROFILE: PATH.api('api/user/profile.php'),

        // Profile
        PROFILE_UPDATE: PATH.api('api/profile/update.php'),
        PROFILE_PASSWORD: PATH.api('api/profile/change-password.php'),

        // Billing
        BILLING_INVOICES: PATH.api('api/billing/invoices.php'),
        BILLING_TRANSACTIONS: PATH.api('api/billing/transactions.php'),
        BILLING_INVOICE_LIST: PATH.api('api/billing/invoices.php'), // Alias for compatibility

        // Payment (single definitive mapping)
        PAYMENT_METHODS: PATH.api('api/payment/methods.php'),
        PAYMENT_ADD_CARD: PATH.api('api/payment/add-card.php'),
        PAYMENT_REMOVE_CARD: PATH.api('api/payment/remove-card.php'),
        PAYMENT_SET_DEFAULT: PATH.api('api/payment/set-default-card.php'),
        PAYMENT_PENDING_INVOICES: PATH.api('api/payment/pending-invoices.php'),
        PAYMENT_CREATE_PROMPTPAY: PATH.api('api/payment/create-promptpay-charge.php'),
        PAYMENT_PAY_INVOICE_CARD: PATH.api('api/payment/pay-invoice-with-card.php'),
        PAYMENT_CHECK_STATUS: PATH.api('api/payment/check-charge-status.php'),
        PAYMENT_SUBSCRIPTION_STATUS: PATH.api('api/payment/subscription-status.php'),

        // Services
        SERVICES_LIST: PATH.api('api/services/list.php'),
        SERVICES_DETAILS: PATH.api('api/services/details.php'),
        SERVICES_USAGE: PATH.api('api/services/usage.php'),

        // Usage
        USAGE_HISTORY: PATH.api('api/usage/history.php'),

        // User API Key Management
        USER_API_KEY: PATH.api('api/user/api-key.php'),
        USER_REGENERATE_KEY: PATH.api('api/user/regenerate-key.php'),

        //ADMIN APIs
        ADMIN_CUSTOMERS: PATH.api('api/admin/customers.php'),
        ADMIN_PACKAGES: PATH.api('api/admin/packages.php'),
        ADMIN_SERVICES: PATH.api('api/admin/services.php'),
        ADMIN_INVOICES: PATH.api('api/admin/invoices.php'),
        ADMIN_BILLING: PATH.api('api/admin/billing.php'),
        ADMIN_PACKAGES_LIST: PATH.api('api/admin/packages/list.php'),
        ADMIN_SUBSCRIPTIONS_ASSIGN: PATH.api('api/admin/subscriptions/assign.php'),
        ADMIN_SUBSCRIPTIONS_EXTEND: PATH.api('api/admin/subscriptions/extend.php'),
        ADMIN_CUSTOMER_CHANNELS: PATH.api('api/admin/customer-channels.php'),
        ADMIN_CUSTOMER_INTEGRATIONS: PATH.api('api/admin/customer-integrations.php'),
        ADMIN_BOT_PROFILES: PATH.api('api/admin/customer-bot-profiles.php'),
        ADMIN_DASHBOARD_STATS: PATH.api('api/admin/dashboard/stats.php'),
        ADMIN_TRIGGER_BILLING: PATH.api('api/admin/billing/process.php'),
        ADMIN_STATS: PATH.api('api/admin/stats.php'), // Legacy alias

        // Gateway
        GATEWAY_MESSAGE: PATH.api('api/gateway/message.php'),
        GATEWAY_WEBHOOK: PATH.api('api/gateway/webhook.php'),

        // Customer APIs (Chat History, Addresses, Orders, Payments)
        // NOTE: Prefer *.php endpoints for maximum compatibility across Apache/Cloud Run deployments.
        CUSTOMER_CONVERSATIONS: PATH.api('api/customer/conversations.php'),
        CUSTOMER_CONVERSATION_DETAIL: (conversationId) => PATH.api(`api/customer/conversations.php?id=${encodeURIComponent(String(conversationId))}`),
        CUSTOMER_CONVERSATION_MESSAGES: (conversationId) => PATH.api(`api/customer/conversations.php?id=${encodeURIComponent(String(conversationId))}&action=messages`),

        CUSTOMER_ADDRESSES: PATH.api('api/customer/addresses.php'),
        CUSTOMER_ADDRESS_DETAIL: (addressId) => PATH.api(`api/customer/addresses.php?id=${encodeURIComponent(String(addressId))}`),
        CUSTOMER_ADDRESS_SET_DEFAULT: (addressId) => PATH.api(`api/customer/addresses.php?id=${encodeURIComponent(String(addressId))}&action=set_default`),

        CUSTOMER_ORDERS: PATH.api('api/customer/orders.php'),
        CUSTOMER_ORDER_DETAIL: (orderId) => PATH.api(`api/customer/orders.php?id=${encodeURIComponent(String(orderId))}`),

        CUSTOMER_PAYMENTS: PATH.api('api/customer/payments.php'),
        CUSTOMER_PAYMENTS_CREATE: PATH.api('api/customer/payments.php?action=create'),
        CUSTOMER_PAYMENT_DETAIL: (paymentId) => PATH.api(`api/customer/payments.php?id=${encodeURIComponent(String(paymentId))}`),
        CUSTOMER_PAYMENT_REFERENCES: (paymentId) => PATH.api(`api/customer/payments.php?id=${encodeURIComponent(String(paymentId))}&references=1`),
        CUSTOMER_PAYMENT_CLASSIFY: PATH.api('api/customer/payments.php?action=classify'),
        CUSTOMER_PAYMENT_APPROVE: PATH.api('api/customer/payments.php?action=approve'),
        CUSTOMER_PAYMENT_REJECT: PATH.api('api/customer/payments.php?action=reject'),
        
        // Search endpoints for autocomplete
        SEARCH_CUSTOMERS: PATH.api('api/customer/search-customers.php'),
        SEARCH_ORDERS: PATH.api('api/customer/search-orders.php'),
        SEARCH_INSTALLMENTS: PATH.api('api/customer/search-installments.php'),
        SEARCH_SAVINGS: PATH.api('api/customer/search-savings.php'),

        CUSTOMER_CASES: PATH.api('api/customer/cases.php'),
        CUSTOMER_CASE_DETAIL: (caseId) => PATH.api(`api/customer/cases.php?id=${encodeURIComponent(String(caseId))}`),

        CUSTOMER_SAVINGS: PATH.api('api/customer/savings.php'),
        CUSTOMER_SAVINGS_DETAIL: (savingsId) => PATH.api(`api/customer/savings.php?id=${encodeURIComponent(String(savingsId))}`),
        CUSTOMER_SAVINGS_DEPOSIT: PATH.api('api/customer/savings.php?action=deposit'),

        CUSTOMER_INSTALLMENTS: PATH.api('api/customer/installments.php'),
        CUSTOMER_INSTALLMENT_DETAIL: (installmentId) => PATH.api(`api/customer/installments.php?id=${encodeURIComponent(String(installmentId))}`),
        CUSTOMER_INSTALLMENT_PAY: PATH.api('api/customer/installments.php?action=pay'),

        ADMIN_PAYMENT_APPROVE: (paymentId) => PATH.api(`api/admin/payments/${paymentId}/approve`),
        ADMIN_PAYMENT_REJECT: (paymentId) => PATH.api(`api/admin/payments/${paymentId}/reject`),

        // Admin Menu System APIs
        ADMIN_USERS: PATH.api('api/admin/users.php'),
        ADMIN_USER_MENU_CONFIG: PATH.api('api/admin/user-menu-config.php'),
        USER_MENU_CONFIG: PATH.api('api/user/menu-config.php'),

        // Admin Orders & Payments Pages APIs
        ADMIN_ORDERS_API: PATH.api('api/admin/orders.php'),
        ADMIN_PAYMENTS_API: PATH.api('api/admin/payments.php'),
        
        // Unified Payment Management (Classification + Sync)
        ADMIN_UNIFIED_PAYMENTS: PATH.api('api/admin/payments/unified'),
        ADMIN_UNIFIED_PAYMENT_DETAIL: (paymentId) => PATH.api(`api/admin/payments/unified/${paymentId}`),
        ADMIN_UNIFIED_PAYMENT_CLASSIFY: (paymentId) => PATH.api(`api/admin/payments/unified/${paymentId}/classify`),
        ADMIN_UNIFIED_PAYMENT_REJECT: (paymentId) => PATH.api(`api/admin/payments/unified/${paymentId}/reject`),
        
        // Commerce System APIs
        ADMIN_CASES_API: PATH.api('api/admin/cases'),
        ADMIN_SAVINGS_API: PATH.api('api/admin/savings'),
        ADMIN_INSTALLMENTS_API: PATH.api('api/admin/installments'),
        
        // Admin Payment Actions with Push Notification
        ADMIN_PAYMENT_VERIFY: (paymentId) => PATH.api(`api/admin/payments/${paymentId}/verify`),
        ADMIN_PAYMENT_REJECT_NEW: (paymentId) => PATH.api(`api/admin/payments/${paymentId}/reject`),
        ADMIN_PAYMENT_MANUAL: PATH.api('api/admin/payments/manual'),
        
        // Admin Installment Actions
        ADMIN_INSTALLMENT_APPROVE: (contractId) => PATH.api(`api/admin/installments/${contractId}/approve`),
        ADMIN_INSTALLMENT_VERIFY_PAYMENT: (contractId) => PATH.api(`api/admin/installments/${contractId}/verify-payment`),
        ADMIN_INSTALLMENT_REJECT_PAYMENT: (contractId) => PATH.api(`api/admin/installments/${contractId}/reject-payment`),
        ADMIN_INSTALLMENT_MANUAL_PAYMENT: (contractId) => PATH.api(`api/admin/installments/${contractId}/manual-payment`),
        ADMIN_INSTALLMENT_UPDATE_DUE: (contractId) => PATH.api(`api/admin/installments/${contractId}/update-due-date`),
        ADMIN_INSTALLMENT_CANCEL: (contractId) => PATH.api(`api/admin/installments/${contractId}/cancel`),
        
        // Admin Savings Actions  
        ADMIN_SAVINGS_APPROVE_DEPOSIT: (savingsId) => PATH.api(`api/admin/savings/${savingsId}/approve-deposit`),
        ADMIN_SAVINGS_CANCEL: (savingsId) => PATH.api(`api/admin/savings/${savingsId}/cancel`),
        ADMIN_SAVINGS_COMPLETE: (savingsId) => PATH.api(`api/admin/savings/${savingsId}/complete`),
        
        // Push Notification API
        PUSH_NOTIFY_SEND: PATH.api('api/webhook/push-notify/send'),
        PUSH_NOTIFY_QUEUE: PATH.api('api/webhook/push-notify/queue'),
        PUSH_NOTIFY_PROCESS: PATH.api('api/webhook/push-notify/process'),
        PUSH_NOTIFY_STATS: PATH.api('api/webhook/push-notify/stats'),
    };

    /**
     * Page URLs
     */
    window.PAGES = {
        // User Pages (no /public/ prefix - DocumentRoot is already /public)
        USER_LOGIN: PATH.page('login.html'),
        USER_DASHBOARD: PATH.page('dashboard.php'),
        USER_PROFILE: PATH.page('profile.php'),
        USER_PAYMENT: PATH.page('payment.php'),
        USER_BILLING: PATH.page('billing.php'),

        // Admin Pages (admin/ subfolder within /public)
        ADMIN_LOGIN: PATH.page('admin/login.html'),
        ADMIN_DASHBOARD: PATH.page('admin/index.php'),
        ADMIN_CUSTOMERS: PATH.page('admin/customers.php'),
        ADMIN_PACKAGES: PATH.page('admin/packages.php'),
        ADMIN_SERVICES_PAGE: PATH.page('admin/services.php'),
        ADMIN_INVOICES_PAGE: PATH.page('admin/invoices.php'),
        ADMIN_KNOWLEDGE_BASE: PATH.page('admin/knowledge-base.php'),
        ADMIN_REPORTS: PATH.page('admin/reports.php'),
        ADMIN_CHAT_LOGS: PATH.page('admin/chat-logs.php'),
        ADMIN_ORDERS: PATH.page('admin/orders.php'),
        ADMIN_PAYMENTS: PATH.page('admin/payments.php'),
        ADMIN_MENU_CONFIG: PATH.page('admin/menu-manager.php'),
        ADMIN_SETTINGS: PATH.page('admin/settings.php'),
        // Commerce System Pages
        ADMIN_CASES: PATH.page('admin/cases.php'),
        ADMIN_SAVINGS: PATH.page('admin/savings.php'),
        ADMIN_INSTALLMENTS: PATH.page('admin/installments.php'),
    };

    /**
     * Asset paths
     */
    window.ASSETS = {
        CSS: PATH.asset('assets/css'),
        JS: PATH.asset('assets/js'),
        IMAGES: PATH.image(''),
    };

    /**
     * Storage Keys
     */
    window.STORAGE_KEYS = {
        AUTH_TOKEN: 'auth_token',
        USER_DATA: 'user_data',
        ADMIN_TOKEN: 'admin_token',
        ADMIN_DATA: 'admin_data',
    };

    /**
     * Helper: Load CSS dynamically
     * @param {string} href - CSS file path (relative, e.g., 'assets/css/style.css')
     */
    window.loadCSS = function (href) {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        // Add cache-busting parameter
        const cacheBuster = '?v=' + Date.now();
        link.href = PATH.asset(href) + cacheBuster;
        document.head.appendChild(link);
    };

    /**
     * Helper: Load JS dynamically
     * @param {string} src - JS file path (relative, e.g., 'assets/js/auth.js')
     * @returns {Promise} Promise that resolves when script loads
     */
    window.loadJS = function (src) {
        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            // Add cache-busting parameter to force reload
            const cacheBuster = '?v=' + Date.now();
            script.src = PATH.asset(src) + cacheBuster;
            script.onload = resolve;
            script.onerror = reject;
            document.body.appendChild(script);
        });
    };

    // Debug info
    console.log('🚀 Path Config Loaded');
    console.log('📍 Base Path:', BASE_PATH || '(root)');
    console.log('🌍 Environment:', BASE_PATH ? 'Localhost (/autobot/)' : 'Production (root)');
    console.log('🔗 API Endpoints:', window.API_ENDPOINTS);
    console.log('📄 Pages:', window.PAGES);

})();
