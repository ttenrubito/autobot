/**
 * Authentication JavaScript
 * Handles login with JWT token storage
 */

// Import constants (ensure constants.js is loaded before this file)
// const BASE_URL, PAGES, API_ENDPOINTS, API_BASE_URL are available from constants.js

// NOTE: Auto-redirect disabled - login pages have their own inline scripts
// This prevents conflicts with inline login handlers
// Check if already logged in
// if (localStorage.getItem('auth_token') && window.location.pathname.includes('login.html')) {
//     window.location.href = 'dashboard.html';
// }

// NOTE: Login form handler disabled - login pages use inline scripts
// This duplicate handler was causing conflicts (e.g., premature spinner display)
// Login Form Handler
// document.getElementById('loginForm')?.addEventListener('submit', async (e) => {
//     e.preventDefault();
//
//     const email = document.getElementById('email').value.trim();
//     const password = document.getElementById('password').value;
//     const remember = document.getElementById('remember').checked;
//
//     // Reset errors
//     hideErrors();
//
//     // Validate
//     if (!email || !password) {
//         showLoginError('กรุณากรอกอีเมลและรหัสผ่าน');
//         return;
//     }
//
//     // Show loading
//     setLoginLoading(true);
//
//     console.log('Attempting login...', { email });
//
//     try {
//         const result = await login(email, password, remember);
//
//         if (result.success) {
//             console.log('Login successful, redirecting...');
//             // Redirect to dashboard is handled within the login function
//         } else {
//             console.error('Login failed:', result.message);
//             showLoginError(result.message || 'เข้าสู่ระบบไม่สำเร็จ');
//         }
//     } catch (error) {
//         console.error('Login error:', error);
//         showLoginError('เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง');
//     } finally {
//         setLoginLoading(false);
//     }
// });

async function login(email, password, remember) {
    try {
        // Use API_ENDPOINTS from path-config.js
        const response = await fetch(API_ENDPOINTS.AUTH_LOGIN, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ email, password })
        });

        console.log('Response status:', response.status);
        const data = await response.json();
        console.log('Response data:', data);

        if (data.success) {
            // Always use localStorage for simplicity
            const storage = localStorage;

            // Store user data and token
            if (data.data && data.data.user) {
                storage.setItem('user_data', JSON.stringify(data.data.user));
                if (data.data.token) {
                    storage.setItem('auth_token', data.data.token);
                }
            } else if (data.user) {
                // Fallback for different response format
                storage.setItem('user_data', JSON.stringify(data.user));
                if (data.token) {
                    storage.setItem('auth_token', data.token);
                }
            }

            console.log('Token saved:', storage.getItem('auth_token'));

            // Redirect to dashboard using PAGES constant
            window.location.href = PAGES.USER_DASHBOARD;
            return { success: true };
        } else {
            return {
                success: false,
                message: data.message || 'Login failed'
            };
        }
    } catch (error) {
        console.error('Login error:', error);
        return {
            success: false,
            message: 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง'
        };
    }
}

// Logout Handler
function logout() {
    // Clear both customer and admin tokens
    localStorage.removeItem('auth_token');
    localStorage.removeItem('user_data');
    localStorage.removeItem('admin_token');
    localStorage.removeItem('admin_data');
    sessionStorage.removeItem('auth_token');
    sessionStorage.removeItem('user_data');
    sessionStorage.removeItem('admin_token');
    sessionStorage.removeItem('admin_data');

    // Redirect based on current location using PAGES constants
    try {
        if (window.location.pathname.includes('/admin/')) {
            window.location.href = (typeof PAGES !== 'undefined' && PAGES.ADMIN_LOGIN) ? PAGES.ADMIN_LOGIN : 'admin/login.html';
        } else {
            window.location.href = (typeof PAGES !== 'undefined' && PAGES.USER_LOGIN) ? PAGES.USER_LOGIN : 'login.html';
        }
    } catch (e) {
        // Fallback
        window.location.href = window.location.pathname.includes('/admin/') ? 'admin/login.html' : 'login.html';
    }
}

// Get stored token
function getAuthToken() {
    // Check for admin_token first (for admin panel), then auth_token (for customer portal)
    return localStorage.getItem('admin_token') ||
        sessionStorage.getItem('admin_token') ||
        localStorage.getItem('auth_token') ||
        sessionStorage.getItem('auth_token');
}

// Get stored user data
function getUserData() {
    // Check for admin data first, then user data
    const adminData = localStorage.getItem('admin_data') || sessionStorage.getItem('admin_data');
    const userData = localStorage.getItem('user_data') || sessionStorage.getItem('user_data');

    const data = adminData || userData;
    return data ? JSON.parse(data) : null;
}

// API call with JWT token
async function apiCall(endpoint, options = {}) {
    const token = getAuthToken();

    if (!token) {
        logout();
        return;
    }

    const headers = {
        // Only set JSON content-type by default; allow callers to override.
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`,
        ...options.headers
    };

    // Prepare fetch options
    const fetchOptions = {
        method: options.method || 'GET',
        headers
    };

    // Body handling
    if (options.body !== undefined) {
        // If caller already provided a JSON string, send as-is.
        if (typeof options.body === 'string') {
            fetchOptions.body = options.body;
        }
        // If caller provided FormData, send as-is and let browser set content-type boundary.
        else if (typeof FormData !== 'undefined' && options.body instanceof FormData) {
            fetchOptions.body = options.body;
            // Remove Content-Type so fetch can set multipart boundary.
            if (fetchOptions.headers && fetchOptions.headers['Content-Type']) {
                delete fetchOptions.headers['Content-Type'];
            }
        }
        // Otherwise JSON-encode objects/arrays.
        else {
            fetchOptions.body = JSON.stringify(options.body);
        }
    }

    // Endpoint can be either:
    // 1. An absolute path (starts with /) - already properly formatted from API_ENDPOINTS
    // 2. A relative path - needs PATH.api() to add base path
    let url;
    if (endpoint.startsWith('/')) {
        url = endpoint;
    } else {
        url = PATH.api(endpoint);
    }

    try {
        const response = await fetch(url, fetchOptions);

        // Handle unauthorized (token expired or invalid)
        if (response.status === 401) {
            logout();
            return null;
        }

        // Read as text first to avoid "Unexpected end of JSON" masking server errors
        const contentType = response.headers.get('content-type') || '';
        const text = await response.text();

        if (!text) {
            console.error('API call error: Empty response body', { url, status: response.status });
            return { success: false, message: 'Empty response from server', status: response.status };
        }

        // If not JSON, return a helpful error payload
        if (!contentType.includes('application/json')) {
            console.error('API call error: Non-JSON response', {
                url,
                status: response.status,
                contentType,
                snippet: text.slice(0, 200)
            });
            return {
                success: false,
                message: 'Server returned non-JSON response',
                status: response.status,
                contentType,
                raw: text
            };
        }

        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('API call error: JSON parse failed', { url, status: response.status, error: String(e), snippet: text.slice(0, 200) });
            return { success: false, message: 'Invalid JSON from server', status: response.status };
        }
    } catch (error) {
        console.error('API call error:', error);
        throw error;
    }
}

// Check authentication
function requireAuth() {
    const token = getAuthToken();
    if (!token) {
        console.warn('No auth token, redirecting to login...');
        // Try with PAGES first, fallback to direct path
        try {
            window.location.href = (typeof PAGES !== 'undefined' && PAGES.USER_LOGIN) ? PAGES.USER_LOGIN : 'login.html';
        } catch (e) {
            window.location.href = 'login.html';
        }
        return false;
    }
    return true;
}

// UI Helper Functions
function setLoginLoading(loading) {
    const btn = document.getElementById('loginBtn');
    const btnText = document.getElementById('loginBtnText');
    const spinner = document.getElementById('loginSpinner');

    if (loading) {
        btn.disabled = true;
        btnText.classList.add('hidden');
        spinner.classList.remove('hidden');
    } else {
        btn.disabled = false;
        btnText.classList.remove('hidden');
        spinner.classList.add('hidden');
    }
}

function showLoginError(message) {
    const errorDiv = document.getElementById('loginError');
    errorDiv.textContent = message;
    errorDiv.classList.remove('hidden');
}

function hideErrors() {
    document.querySelectorAll('.form-error').forEach(el => {
        el.classList.add('hidden');
        el.textContent = '';
    });
}

// Format currency (Thai Baht)
function formatCurrency(amount) {
    return new Intl.NumberFormat('th-TH', {
        style: 'currency',
        currency: 'THB'
    }).format(amount);
}

// Format number with commas
function formatNumber(num) {
    return new Intl.NumberFormat('th-TH').format(num);
}

// Format date
function formatDate(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString);
    if (isNaN(date.getTime())) return '-';
    return new Intl.DateTimeFormat('th-TH', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    }).format(date);
}

// Format relative time
function formatRelativeTime(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now - date;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return 'เมื่อสักครู่';
    if (diffMins < 60) return `${diffMins} นาทีที่แล้ว`;
    if (diffHours < 24) return `${diffHours} ชั่วโมงที่แล้ว`;
    if (diffDays < 30) return `${diffDays} วันที่แล้ว`;
    return formatDate(dateString);
}

// Show loading overlay
function showLoading() {
    const overlay = document.createElement('div');
    overlay.id = 'loadingOverlay';
    overlay.className = 'loading-overlay';
    overlay.innerHTML = '<div class="loading-spinner"></div>';
    document.body.appendChild(overlay);
}

// Hide loading overlay
function hideLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) {
        overlay.remove();
    }
}

// Show toast notification
function showToast(message, type = 'success') {
    // Simple alert for now, can be enhanced with a toast library
    alert(message);
}

/**
 * Admin API call - wrapper for apiCall that uses admin_token
 * Used by public/admin pages (admin ร้าน)
 */
async function adminApiCall(endpoint, options = {}) {
    const token = localStorage.getItem('admin_token');
    
    if (!token) {
        console.error('No admin token found');
        window.location.href = 'login.html';
        return { success: false, message: 'Unauthorized' };
    }

    const headers = {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`,
        ...options.headers
    };

    const fetchOptions = {
        method: options.method || 'GET',
        headers
    };

    if (options.body !== undefined) {
        if (typeof options.body === 'string') {
            fetchOptions.body = options.body;
        } else if (typeof FormData !== 'undefined' && options.body instanceof FormData) {
            fetchOptions.body = options.body;
            delete fetchOptions.headers['Content-Type'];
        } else {
            fetchOptions.body = JSON.stringify(options.body);
        }
    }

    try {
        const response = await fetch(endpoint, fetchOptions);
        const data = await response.json();
        
        if (response.status === 401) {
            console.error('Admin session expired');
            localStorage.removeItem('admin_token');
            window.location.href = 'login.html';
            return { success: false, message: 'Session expired' };
        }
        
        return data;
    } catch (error) {
        console.error('Admin API call error:', error);
        return { success: false, message: error.message };
    }
}
