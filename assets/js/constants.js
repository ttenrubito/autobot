/**
 * Application Constants
 * Centralized configuration for URLs, paths, and API endpoints
 */

// BASE_PATH: base path of the app on server
// - Cloud Run / production: '' (web root = public/)
// - Local (XAMPP): '/autobot' (project under /autobot)
// Can be overridden by setting window.BASE_PATH_OVERRIDE before loading this script
const BASE_PATH = (typeof window !== 'undefined' && window.BASE_PATH_OVERRIDE)
    ? window.BASE_PATH_OVERRIDE
    : '';

// Normalize trailing slash
const BASE_URL = BASE_PATH.replace(/\/$/, '');

// API base URL derived from BASE_URL
const API_BASE_URL = BASE_URL + '/api';

// Static assets base (CSS/JS/images)
const ASSETS_BASE_URL = BASE_URL + '/assets';

// User-facing pages
const PAGES = {
    // User pages
    USER_LOGIN: BASE_URL + '/login.html',
    USER_DASHBOARD: BASE_URL + '/dashboard.html',
    USER_PROFILE: BASE_URL + '/profile.html',
    USER_BILLING: BASE_URL + '/billing.html',
    USER_PAYMENT: BASE_URL + '/payment.html',
    USER_SERVICES: BASE_URL + '/services.html',
    USER_USAGE: BASE_URL + '/usage.html',
    USER_API_DOCS: BASE_URL + '/api-docs.html',

    // Admin pages
    ADMIN_LOGIN: BASE_URL + '/admin/login.html',
    ADMIN_DASHBOARD: BASE_URL + '/admin/index.html',
    ADMIN_CUSTOMERS: BASE_URL + '/admin/customers.html',
    ADMIN_SERVICES: BASE_URL + '/admin/services.html',
    ADMIN_INVOICES: BASE_URL + '/admin/invoices.html',
    ADMIN_PACKAGES: BASE_URL + '/admin/packages.html',
    ADMIN_REPORTS: BASE_URL + '/admin/reports.html',
    ADMIN_SETTINGS: BASE_URL + '/admin/settings.html',
};

// API Endpoints (clean URLs - no .php extension)
const API_ENDPOINTS = {
    // User authentication
    AUTH_LOGIN: API_BASE_URL + '/auth/login',
    AUTH_LOGOUT: API_BASE_URL + '/auth/logout',
    AUTH_REGISTER: API_BASE_URL + '/auth/register',
    AUTH_ME: API_BASE_URL + '/auth/me',

    // Admin authentication
    ADMIN_LOGIN: API_BASE_URL + '/admin/login',
    ADMIN_LOGOUT: API_BASE_URL + '/admin/logout',

    // Admin - Customers
    ADMIN_CUSTOMERS: API_BASE_URL + '/admin/customers',
    ADMIN_SERVICES: API_BASE_URL + '/admin/services',
    ADMIN_INVOICES: API_BASE_URL + '/admin/invoices',
    ADMIN_STATS: API_BASE_URL + '/admin/stats',
    ADMIN_TRIGGER_BILLING: API_BASE_URL + '/admin/trigger-billing',

    // User data
    USER_PROFILE: API_BASE_URL + '/user/profile',
    USER_SERVICES: API_BASE_URL + '/services/list',
    USER_USAGE: API_BASE_URL + '/services/usage',

    // Billing & Payment
    BILLING_TRANSACTIONS: API_BASE_URL + '/billing/transactions',
    BILLING_INVOICE: API_BASE_URL + '/billing/invoice-details',
    PAYMENT_METHODS: API_BASE_URL + '/payment/methods',
    PAYMENT_ADD_CARD: API_BASE_URL + '/payment/add-card',
    PAYMENT_REMOVE_CARD: API_BASE_URL + '/payment/remove-card',
    PAYMENT_SUBSCRIPTION_STATUS: API_BASE_URL + '/payment/subscription-status',

    // Dashboard
    DASHBOARD_STATS: API_BASE_URL + '/dashboard/stats',

    // Profile
    PROFILE_PASSWORD: API_BASE_URL + '/auth/change-password',
};

// Storage keys for localStorage
const STORAGE_KEYS = {
    // User authentication
    AUTH_TOKEN: 'auth_token',
    USER_DATA: 'user_data',

    // Admin authentication
    ADMIN_TOKEN: 'admin_token',
    ADMIN_DATA: 'admin_data',
};

// Helper functions
const AppUtils = {
    /**
     * Get base URL for application
     */
    getBaseURL() {
        return BASE_URL;
    },

    /**
     * Get full page URL
     */
    getPageURL(pageKey) {
        return PAGES[pageKey] || '/';
    },

    /**
     * Get API endpoint URL
     */
    getAPIURL(endpointKey) {
        return API_ENDPOINTS[endpointKey] || API_BASE_URL;
    },

    /**
     * Check if user is authenticated (user)
     */
    isUserAuthenticated() {
        return !!localStorage.getItem(STORAGE_KEYS.AUTH_TOKEN);
    },

    /**
     * Check if admin is authenticated
     */
    isAdminAuthenticated() {
        return !!localStorage.getItem(STORAGE_KEYS.ADMIN_TOKEN);
    },

    /**
     * Redirect to page
     */
    redirectTo(pageKey) {
        const url = PAGES[pageKey];
        if (url) {
            window.location.href = url;
        } else {
            console.error('Invalid page key:', pageKey);
        }
    }
};
